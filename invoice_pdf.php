<?php
// invoice_pdf.php - Fatura PDF Çıktısı
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'vendor/autoload.php'; // Composer autoload

// Kullanıcı girişi gerekli
requireLogin();

// --- Yardımcı Fonksiyonlar ---
function formatCurrencyTR($amount, $symbol = '₺')
{
    if (!is_numeric($amount))
        $amount = 0;
    // Negatif indirimleri de doğru göstermek için
    $isNegative = $amount < 0;
    $amount = abs($amount);
    $formatted = number_format((float) $amount, 2, ',', '.') . ' ' . $symbol;
    return $isNegative ? '-' . $formatted : $formatted;
}

function formatDateTR($dateString)
{
    if (empty($dateString) || $dateString == '0000-00-00')
        return '-';
    try {
        $date = new DateTime($dateString);
        return $date->format('d.m.Y');
    } catch (Exception $e) {
        return '-'; // Geçersiz tarih formatı durumunda
    }
}
// --- Yardımcı Fonksiyonlar Sonu ---

// --- Fatura ID Kontrolü ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Hata: Geçersiz fatura ID'si.");
}
$invoice_id = intval($_GET['id']);

// --- Veritabanı Bağlantısı ve Veri Çekme ---
$conn = getDbConnection();
$invoice = null;
$items = [];
$customer = null;
$user = null; // Teklifi oluşturan kullanıcı bilgisi
$companySettings = [];
$bankAccounts = [];

try {
    // 1. Şirket Ayarlarını Al
    $stmtSettings = $conn->prepare("SELECT setting_key, setting_value FROM settings");
    $stmtSettings->execute();
    $settingsRows = $stmtSettings->fetchAll(PDO::FETCH_ASSOC);

    // Varsayılan değerler
    $defaultCompanySettings = [
        'company_name' => 'Şirketiniz A.Ş.',
        'company_address' => 'Örnek Adres, No: 1, İlçe, Şehir',
        'company_phone' => '0212 123 45 67',
        'company_email' => 'info@sirketiniz.com',
        'company_website' => 'www.sirketiniz.com',
        'company_tax_office' => 'Örnek Vergi Dairesi',
        'company_tax_number' => '1234567890',
        'company_logo_path' => 'assets/img/logo.png',
        'primary_color' => '#CA271C'
        // 'invoice_terms' => 'Fatura şartları...' // Gerekirse eklenebilir
    ];
    $companySettings = $defaultCompanySettings;
    foreach ($settingsRows as $row) {
        if (array_key_exists($row['setting_key'], $companySettings)) {
            $companySettings[$row['setting_key']] = $row['setting_value'];
        }
    }

    // 2. Aktif Banka Hesaplarını Al
    $stmtBank = $conn->prepare("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC");
    $stmtBank->execute();
    $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fatura, Teklif, Müşteri ve (Teklifi oluşturan) Kullanıcı Bilgilerini Al
    $stmt = $conn->prepare("
        SELECT
            i.*,
            q.reference_no as quotation_reference, q.date as quotation_date,
            q.customer_id, q.user_id as quotation_user_id, /* Teklifi oluşturan user_id */
            c.name as customer_name, c.contact_person, c.email as customer_email,
            c.phone as customer_phone, c.address as customer_address,
            c.tax_office as customer_tax_office, c.tax_number as customer_tax_number,
            u.full_name as quotation_user_name, u.email as quotation_user_email /* Teklifi oluşturan kullanıcı bilgileri */
        FROM invoices i
        JOIN quotations q ON i.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id /* Teklifi oluşturan kullanıcıyı join et */
        WHERE i.id = :id
    ");
    $stmt->bindParam(':id', $invoice_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        die("Hata: Fatura bulunamadı (ID: {$invoice_id}).");
    }
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    // Müşteri ve Teklifi Oluşturan Kullanıcı bilgilerini ayır
    $customer = [
        'id' => $invoice['customer_id'],
        'name' => $invoice['customer_name'],
        'contact_person' => $invoice['contact_person'],
        'email' => $invoice['customer_email'],
        'phone' => $invoice['customer_phone'],
        'address' => $invoice['customer_address'],
        'tax_office' => $invoice['customer_tax_office'],
        'tax_number' => $invoice['customer_tax_number']
    ];
    $user = [ // Bu, teklifi oluşturan kullanıcıdır
        'id' => $invoice['quotation_user_id'],
        'name' => $invoice['quotation_user_name'],
        'email' => $invoice['quotation_user_email']
    ];


    // 4. Fatura Kalemlerini Al (Teklif Kalemlerinden)
    $stmtItems = $conn->prepare("
        SELECT
            qi.*,
            CASE
                WHEN qi.item_type = 'product' THEN p.name
                WHEN qi.item_type = 'service' THEN s.name
                ELSE qi.description
            END as item_name,
            CASE
                WHEN qi.item_type = 'product' THEN p.code
                WHEN qi.item_type = 'service' THEN s.code
                ELSE '-'
            END as item_code
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE qi.quotation_id = :quotation_id /* Faturanın ilişkili olduğu teklifin ID'si */
        ORDER BY qi.id ASC
    ");
    $stmtItems->bindParam(':quotation_id', $invoice['quotation_id'], PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 5. Ödemeleri al (PDF'te göstermek için değil, sadece durum için gerekebilir)
    // Gerekirse eklenebilir, ancak genellikle fatura PDF'inde ödeme listesi olmaz.

} catch (PDOException $e) {
    error_log("Veritabanı hatası (invoice_pdf.php): " . $e->getMessage());
    die("Veritabanı bağlantı hatası oluştu. Lütfen sistem yöneticisi ile iletişime geçin.");
} catch (Exception $e) {
    die("Bir hata oluştu: " . $e->getMessage());
}

// --- Fatura Durum Haritası Tanımı ---
$statusMap = [
    'unpaid' => ['text' => 'Ödenmedi', 'class' => 'danger'],
    'partially_paid' => ['text' => 'Kısmi Ödendi', 'class' => 'warning'],
    'paid' => ['text' => 'Ödendi', 'class' => 'success'],
    'cancelled' => ['text' => 'İptal Edildi', 'class' => 'secondary']
];

// --- PDF için Özel Sınıf Tanımı (Fatura için uyarlandı) ---
class MYPDF_Invoice extends TCPDF
{
    public $companySettings = [];
    public $isLastPage = false;

    // Header Metodu - Üst Bilgi
        // Header Metodu - Üst Bilgi (Filigran Eklendi)
        public function Header()
        {
            // --- FİLİGRAN BAŞLANGICI ---
            // Filigran olarak kullanılacak resim (logo ile aynı varsayalım)
            $watermark_image_file = __DIR__ . '/' . ltrim($this->companySettings['company_logo_path'] ?? 'assets/img/logo.png', '/');
    
            if (file_exists($watermark_image_file)) {
                // Sayfa boyutlarını al
                $pageW = $this->getPageWidth();
                $pageH = $this->getPageHeight();
                // Sayfa merkez koordinatları
                $pageCenterX = $pageW / 24;
                $pageCenterY = $pageH / 1.5;
    
                // Filigran ayarları
                $watermarkW = $pageW * 1.13; // Filigran genişliği (sayfanın %75'i, ayarlanabilir)
                $watermarkH = 0;           // Yükseklik otomatik (oranı koru)
                $watermarkAngle = 45;     // Döndürme açısı (saat yönünün tersi için pozitif, saat yönü için negatif)
                $watermarkOpacity = 0.08;  // Opaklık (0.0 = tam şeffaf, 1.0 = tam opak) - Çok düşük bir değer kullanın
    
                // Mevcut grafik durumunu kaydet (Alfa ayarı için)
                $this->SetAlpha($watermarkOpacity);
    
                // Dönüşümü başlat
                $this->StartTransform();
    
                // Sayfa merkezinin etrafında döndür
                $this->Rotate($watermarkAngle, $pageCenterX, $pageCenterY);
    
                // Resmi sayfanın ortasına çizdir
                // Image metoduna merkez koordinatları (X,Y) veriyoruz ve 'M' parametresi ile
                // bu koordinatların resmin orta-merkez referans noktası olduğunu belirtiyoruz.
                $this->Image(
                    $watermark_image_file,
                    $pageCenterX,    // Resmin ortalanacağı X koordinatı
                    $pageCenterY,    // Resmin ortalanacağı Y koordinatı
                    $watermarkW,     // Resim genişliği
                    $watermarkH,     // Resim yüksekliği (0 = otomatik)
                    '',              // Resim tipi (otomatik)
                    '',              // Link
                    'M',             // Hizalama: Orta-Merkez ('Middle-Center') referans noktası
                    false,           // Yeniden boyutlandırma (true yapılırsa w/h'ye zorlar, false oranı korur)
                    300,             // DPI
                    '',              // Palet
                    false,           // Maske mi?
                    false,           // Maske resmi
                    0                // Kenarlık
                );
    
                // Dönüşümü bitir
                $this->StopTransform();
    
                // Alfa (opaklık) ayarını normale döndür (sonraki çizimler için önemli)
                $this->SetAlpha(1);
            }
            // --- FİLİGRAN SONU ---
    
    
            // --- ORİJİNAL HEADER İÇERİĞİ BAŞLANGICI ---
            // Ana Logo (Opak)
            $logoPathSetting = $this->companySettings['company_logo_path'] ?? 'assets/img/logo.png';
            $image_file_header = __DIR__ . '/' . ltrim($logoPathSetting, '/'); // Farklı değişken ismi
    
            $logoX = 15; $logoY = 10; $logoTargetW = 45; $logoMaxH = 18;
            $logoDrawW = $logoTargetW; $logoDrawH = 0;
    
            if (file_exists($image_file_header)) {
                $imageSize = getimagesize($image_file_header);
                if ($imageSize !== false) {
                    $originalW = $imageSize[0]; $originalH = $imageSize[1];
                    if ($originalW > 0 && $originalH > 0) {
                        $potentialH = ($originalH / $originalW) * $logoTargetW;
                        if ($potentialH > $logoMaxH) { $logoDrawH = $logoMaxH; $logoDrawW = 0; }
                        else { $logoDrawW = $logoTargetW; $logoDrawH = 0; }
                    }
                }
                // Alfa normale döndükten sonra ana logoyu çiz
                $this->Image($image_file_header, $logoX, $logoY, $logoDrawW, $logoDrawH, '', '', 'T', true, 300, '', false, false, 0, false, false, false);
            } else {
                $this->SetFont('dejavusansb', 'B', 10);
                $this->SetXY($logoX, $logoY + 5);
                $this->Cell(50, 10, $this->companySettings['company_name'], 0, 0, 'L');
            }
    
            // Sağ Üst Alan - FATURA Başlığı ve Şirket İletişim Bilgileri
            $headerRightX = $this->getPageWidth() - $this->original_rMargin - 75;
            $headerRightWidth = 70;
    
            $this->SetFont('dejavusansb', 'B', 20);
            $this->SetTextColor(0, 0, 0);
            $this->SetXY($headerRightX, $logoY);
            $this->Cell($headerRightWidth, 9, 'FATURA', 0, 2, 'R');
    
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(80, 80, 80);
            $this->SetX($headerRightX);
            $this->Cell($headerRightWidth, 4, htmlspecialchars($this->companySettings['company_name'] ?? ''), 0, 2, 'R');
    
            $this->SetX($headerRightX);
            $this->MultiCell($headerRightWidth, 4, htmlspecialchars($this->companySettings['company_address'] ?? ''), 0, 'R', false, 1, $headerRightX, $this->GetY(), true);
    
            $this->SetX($headerRightX);
            $this->Cell($headerRightWidth, 4, 'Tel: ' . htmlspecialchars($this->companySettings['company_phone'] ?? ''), 0, 2, 'R');
    
            // Header Ayırıcı Çizgi
            // Ayırıcı çizginin Y konumunu hesapla (filigranı etkilememeli)
            $logoBottomY = $logoY + $logoMaxH + 5; // Logonun altını tahmin et
            $textBottomY = $this->GetY() + 3; // Sağdaki metnin altı
            $separatorY = max($textBottomY, $logoBottomY); // Metin veya logodan hangisi aşağıdaysa
    
            $this->SetY($separatorY);
            $this->SetLineStyle(['width' => 0.2, 'color' => [200, 200, 200]]);
            $this->Line($this->original_lMargin, $this->GetY(), $this->getPageWidth() - $this->original_rMargin, $this->GetY());
            // --- ORİJİNAL HEADER İÇERİĞİ SONU ---
        }

    // Footer Metodu - Alt Bilgi (Aynı kalabilir)
    public function Footer()
    {
        $footerY = -20;
        $this->SetY($footerY);

        $this->SetLineStyle(['width' => 0.2, 'color' => [200, 200, 200]]);
        $this->Line($this->original_lMargin, $this->GetY(), $this->getPageWidth() - $this->original_rMargin, $this->GetY());
        $this->SetY($this->GetY() + 1);

        $this->SetTextColor(100, 100, 100);
        $this->SetFont('dejavusans', '', 7.5);

        $footerLeft = htmlspecialchars($this->companySettings['company_name'] ?? '');
        if (!empty($this->companySettings['company_tax_office']) || !empty($this->companySettings['company_tax_number'])) {
            $footerLeft .= ' | Vergi D.: ' . htmlspecialchars($this->companySettings['company_tax_office'] ?? '-') . ' / No: ' . htmlspecialchars($this->companySettings['company_tax_number'] ?? '-');
        }
        $footerRight = 'Sayfa ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();
        $footerLeft2 = '';
        if (!empty($this->companySettings['company_website'])) { $footerLeft2 .= htmlspecialchars($this->companySettings['company_website']); }
        if (!empty($this->companySettings['company_email'])) { if (!empty($footerLeft2)) $footerLeft2 .= ' | '; $footerLeft2 .= htmlspecialchars($this->companySettings['company_email']); }

        $this->Cell(0, 5, $footerLeft, 0, 0, 'L');
        $this->Cell(0, 5, $footerRight, 0, 1, 'R');
        if (!empty($footerLeft2)) { $this->Cell(0, 5, $footerLeft2, 0, 1, 'L'); }
    }
}
// --- Özel PDF Sınıfı Tanımı Sonu ---

// --- PDF Nesnesi Oluşturma ---
$pdf = new MYPDF_Invoice(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);

// Şirket ayarlarını PDF sınıfına aktar
$pdf->companySettings = $companySettings;

// Doküman Meta Bilgileri
$pdf->SetCreator('Teklif Yönetim Sistemi');
$pdf->SetAuthor(htmlspecialchars($companySettings['company_name']));
$pdf->SetTitle('Fatura: ' . htmlspecialchars($invoice['invoice_no'])); // Fatura No kullanıldı
$pdf->SetSubject('Fatura');
$pdf->SetKeywords('Fatura, Invoice, ' . htmlspecialchars($companySettings['company_name']));

// Header ve Footer Ayarları
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// Kenar Boşlukları
$pdf->SetMargins(15, 40, 15); // Üst boşluk header'a göre ayarlandı
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(28);

// Otomatik Sayfa Kesme Ayarı
$pdf->SetAutoPageBreak(TRUE, 32);

// Yazı Tipi
$pdf->SetFont('dejavusans', '', 9);

// Yeni Sayfa Ekle
$pdf->AddPage();

// Renkler
$primaryColor = $companySettings['primary_color'] ?? '#4A6EAA';
list($r, $g, $b) = sscanf($primaryColor, "#%02x%02x%02x");
$primaryColorArray = [$r, $g, $b];
$tableHeaderBgColor = $primaryColorArray;
$tableHeaderTextColor = [255, 255, 255];
$tableBorderColor = [220, 220, 220];
$totalRowBgColor = $primaryColorArray;
$totalRowTextColor = [255, 255, 255];

// Stil Tanımları (Totals, Section Box vb. için)
$styles = <<<HTML
<style>
    .totals-table { width: 45%; float: right; margin-top: 5px; border: 1px solid rgb({$tableBorderColor[0]}, {$tableBorderColor[1]}, {$tableBorderColor[2]}); font-size: 9pt; }
    .totals-table td { padding: 5px 8px; border-bottom: 1px solid rgb({$tableBorderColor[0]}, {$tableBorderColor[1]}, {$tableBorderColor[2]}); vertical-align: middle; }
    .totals-table td.label { text-align: left; font-family: 'dejavusans'; font-weight: normal; }
    .totals-table td.value { text-align: right; font-family: 'dejavusansb'; font-weight: bold; }
    .totals-table tr.total-row td { font-size: 10.5pt; font-weight: bold; background-color: rgb({$totalRowBgColor[0]}, {$totalRowBgColor[1]}, {$totalRowBgColor[2]}); color: rgb({$totalRowTextColor[0]}, {$totalRowTextColor[1]}, {$totalRowTextColor[2]});}
    .section-box { border: 1px solid #E0E0E0; padding: 10px 12px; margin-top: 10px; font-size: 8.5pt; background-color: #FFFFFF; }
    .section-box .title { font-family: 'dejavusansb'; font-size: 10pt; font-weight: bold; color: rgb({$primaryColorArray[0]}, {$primaryColorArray[1]}, {$primaryColorArray[2]}); margin-bottom: 8px; display: block; padding-bottom: 5px; border-bottom: 2px solid {$primaryColor}; }
    .section-box table { width: 100%; border: none; }
    .section-box table td { padding: 2px 0; border: none; font-size: 8.5pt; }
</style>
HTML;
$pdf->writeHTML($styles, true, false, true, false, '');

// --- PDF İçeriği Oluşturma ---

// 1. Fatura No ve Tarih Kutusu
$html = '
<table cellpadding="0" cellspacing="0" border="0" style="width: 100%;">
    <tr>
        <td width="45%" style="background-color: rgb(' . implode(',', $primaryColorArray) . '); color: rgb(255,255,255); padding: 12px 12px 10px 12px; vertical-align: middle; border-radius: 4px;">
            <span style="font-family: \'dejavusansb\'; font-size: 13pt; font-weight: bold;">FATURA NO: ' . htmlspecialchars($invoice['invoice_no']) . '</span>
        </td>
        <td width="5%"> </td>
        <td width="50%" style="text-align: right; font-size: 9pt; vertical-align: middle; padding: 8px 0;">
            <strong>Fatura Tarihi:</strong> ' . formatDateTR($invoice['date']) . '<br/>
            <strong>Vade Tarihi:</strong> ' . formatDateTR($invoice['due_date']) . '<br/>
            <!-- <strong>Durum:</strong> '.($statusMap[$invoice['status']]['text'] ?? 'Bilinmiyor').'-->
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);

// 2. Firma ve Müşteri Bilgileri (Aynı kalabilir)
$html = '
<table cellspacing="0" cellpadding="8" border="0" style="width: 100%;">
    <tr>
        <td width="48%" style=" border: 1px solid #eeeeee; border-radius: 5px; padding: 10px; vertical-align: top;">
            <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . '; font-size: 11pt; color:'.$primaryColor.';">FİRMA BİLGİLERİ</h3>
            <p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;"><strong>' . htmlspecialchars($companySettings['company_name']) . '</strong></p>'
    . '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">' . nl2br(htmlspecialchars($companySettings['company_address'])) . '</p>'
    . '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">V.D./No: ' . htmlspecialchars($companySettings['company_tax_office'] ?? '-') . ' / ' . htmlspecialchars($companySettings['company_tax_number'] ?? '-') . '</p>'
    . '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">Tel: ' . htmlspecialchars($companySettings['company_phone'] ?? '-') . '</p>'
    . '</td>
        <td width="4%"> </td>
        <td width="48%" style=" border: 1px solid #eeeeee; border-radius: 5px; padding: 10px; vertical-align: top;">
            <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . '; font-size: 11pt; color:'.$primaryColor.';">MÜŞTERİ BİLGİLERİ</h3>
            <p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;"><strong>' . htmlspecialchars($customer['name']) . '</strong></p>';
if (!empty($customer['contact_person'])) {
    $html .= '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">İlgili: ' . htmlspecialchars($customer['contact_person']) . '</p>';
}
$html .= '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">' . nl2br(htmlspecialchars($customer['address'] ?? 'Adres belirtilmemiş')) . '</p>'
    . '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">V.D./No: ' . htmlspecialchars($customer['tax_office'] ?? '-') . ' / ' . htmlspecialchars($customer['tax_number'] ?? '-') . '</p>'
    . '<p style="margin: 3px 0; line-height: 1.4; font-size: 8.5pt;">Tel: ' . htmlspecialchars($customer['phone'] ?? '-') . '</p>';
$html .= '</td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);

// 3. Faturayı Hazırlayan Bilgisi (Teklifi Hazırlayan Kullanıcı)
// Bu bölüm faturada genellikle olmaz ama isterseniz kalabilir.
/*
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%;">
    <tr>
        <td width="100%" style="background-color: #f0f0f0; border: 1px solid #e0e0e0; border-radius: 5px; font-size: 8pt; padding: 6px 10px;">
            <strong>İlgili Personel:</strong> ' . htmlspecialchars($user['name']) .
            ' | <strong>E-posta:</strong> ' . htmlspecialchars($user['email']) . '
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);
*/

// 4. Fatura Kalemleri Tablosu (Teklif Kalemleri ile aynı yapı)
$pdf->SetFillColorArray($tableHeaderBgColor);
$pdf->SetTextColorArray($tableHeaderTextColor);
$pdf->SetDrawColorArray($primaryColorArray);
$pdf->SetFont('dejavusansb', 'B', 8);
$pdf->SetLineWidth(0.3);

// Başlıklar
$header = ['S.N', 'Kod', 'Açıklama', 'Miktar', 'Birim F.', 'İnd.%', 'Ara Top.', 'Toplam'];
// Genişlikleri ayarla - Tür sütununun genişliği Ara Toplam ve Toplam'a eklendi
$w = [8, 16, 48, 18, 22, 12, 28, 28]; // "Tür" sütunu (12) kaldırıldı, Ara Toplam ve Toplam +6 birim genişletildi
$num_headers = count($header);
for ($i = 0; $i < $num_headers; ++$i) {
    $align = 'C'; // Varsayılan orta
    if ($i == 2)
        $align = 'L'; // Açıklama sola (dizin değişti, artık 2. sırada)
    if ($i >= 4 && $i != 5)
        $align = 'R'; // Fiyatlar sağa (İndirim hariç) (dizinler değişti)
    $pdf->Cell($w[$i], 7, $header[$i], 1, 0, $align, 1);
}
$pdf->Ln();

// İçerik
//$pdf->SetFillColor(255, 255, 255); 
$pdf->SetTextColor(50, 50, 50); 
$pdf->SetDrawColorArray($tableBorderColor);
$pdf->SetFont('dejavusans', '', 8); 
$pdf->SetLineWidth(0.1); 
$fill = 0; 
$counter = 1;

foreach ($items as $item) { // $items dizisini kullanıyoruz
    $unit_price = (float) ($item['unit_price'] ?? 0);
    $discount_percent = (float) ($item['discount_percent'] ?? 0);
    $quantity = (float) ($item['quantity'] ?? 0);
    $tax_rate = (float) ($item['tax_rate'] ?? 0); // KDV oranını alıyoruz

    $unit_price_after_discount = $unit_price * (1 - ($discount_percent / 100));
    $line_subtotal_before_tax = $quantity * $unit_price_after_discount;
    $line_total_with_tax = $line_subtotal_before_tax * (1 + ($tax_rate / 100)); // Toplamı KDV dahil hesapla

    $row_height = $pdf->getStringHeight($w[2], htmlspecialchars($item['description'] ?? ($item['item_name'] ?? '')));
    $min_cell_height = 6; $row_height = max($row_height, $min_cell_height);

    $pdf->Cell($w[0], $row_height, $counter++, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
    $pdf->Cell($w[1], $row_height, htmlspecialchars($item['item_code'] ?? '-'), 1, 0, 'L', false, '', 0, false, 'T', 'M');
    $pdf->MultiCell($w[2], $row_height, htmlspecialchars($item['description'] ?? ($item['item_name'] ?? '')), 1, 'L', false, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->Cell($w[3], $row_height, number_format($quantity, 0, ',', '.'), 1, 0, 'C', false, '', 0, false, 'T', 'M');
    $pdf->Cell($w[4], $row_height, formatCurrencyTR($unit_price), 1, 0, 'R', false, '', 0, false, 'T', 'M');
    $pdf->Cell($w[5], $row_height, number_format($discount_percent, 0) . '%', 1, 0, 'C', false, '', 0, false, 'T', 'M');
    $pdf->Cell($w[6], $row_height, formatCurrencyTR($line_subtotal_before_tax), 1, 0, 'R', false, '', 0, false, 'T', 'M');
    $pdf->Cell($w[7], $row_height, formatCurrencyTR($line_total_with_tax), 1, 1, 'R', false, '', 0, false, 'T', 'M'); // Satır sonu

    //$fill = !$fill;
}
$pdf->Ln(1);

// 5. Toplamlar Tablosu (Fatura verilerini kullan)
$html = '
<table border="0" cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
        <td width="55%">';
// Fatura Notları (Varsa)
if (!empty($invoice['notes'])) {
    $html .= '<div style="font-size: 8pt; color: #555; border: 1px solid #eee; padding: 8px; margin-top: 5px;"><strong>Fatura Notları:</strong><br>' . nl2br(htmlspecialchars($invoice['notes'])) . '</div>';
}
$html = '
<table border="0" cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
        <td width="55%"></td>
        <td width="45%">
            <table class="totals-table" cellpadding="5" cellspacing="0">
                <tr>
                    <td class="label" width="60%">Ara Toplam:</td>
                    <td class="value" width="40%">' . formatCurrencyTR($invoice['subtotal']) . '</td>
                </tr>';
// İndirim varsa göster
if (isset($invoice['discount_amount']) && (float) $invoice['discount_amount'] != 0) {
    $html .= '<tr>
                    <td class="label">İndirim Tutarı:</td>
                    <td class="value">' . formatCurrencyTR((float) $invoice['discount_amount'] * -1) . '</td>
                  </tr>';
}
// KDV varsa göster
if (isset($invoice['tax_amount']) && (float) $invoice['tax_amount'] != 0) {
    // Ortalama KDV oranını göstermek için (isteğe bağlı, ilk üründen alınabilir veya hesaplanabilir)
    $first_item_tax_rate = $items[0]['tax_rate'] ?? 0; // Basitlik için ilk ürünün KDV'si
    $html .= '<tr>
                    <td class="label">KDV Toplamı (' . number_format($first_item_tax_rate, 0) . '%*):</td>
                    <td class="value">' . formatCurrencyTR($invoice['tax_amount']) . '</td>
                  </tr>';
}
$html .= '    <tr class="total-row" style="background-color: rgb(' . implode(',', $totalRowBgColor) . '); color: rgb(' . implode(',', $totalRowTextColor) . ');">
                    <td class="label">GENEL TOPLAM:</td>
                    <td class="value">' . formatCurrencyTR($invoice['total_amount']) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(5);


// 6. Ödeme Bilgileri - Banka Hesapları Tablosu (Aynı kalabilir)
$html_payment_title = '';
if (!empty($bankAccounts)) {
    $html_payment_title = '<div class="section-box">
    <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . ';">ÖDEME BİLGİLERİ</h3>';
    $pdf->writeHTML($html_payment_title, true, false, true, false, '');
    $pdf->Ln(0);

    $pdf->SetFillColorArray($tableHeaderBgColor);
    $pdf->SetTextColorArray($tableHeaderTextColor);
    $pdf->SetDrawColorArray($primaryColorArray); 
    $pdf->SetFont('dejavusansb', 'B', 8); 
    $pdf->SetLineWidth(0.3);
    $bank_header = ['Banka Adı', 'Hesap Sahibi', 'IBAN', 'Hesap No'];
    $w_bank = [30, 70, 55, 25];
    $num_bank_headers = count($bank_header);
    for ($i = 0; $i < $num_bank_headers; ++$i) { $align = 'L'; $pdf->Cell($w_bank[$i], 7, $bank_header[$i], 1, 0, $align, 1); }
    $pdf->Ln();

    //$pdf->SetFillColor(255, 255, 255);
    $pdf->SetTextColor(50, 50, 50); 
    $pdf->SetDrawColorArray($tableBorderColor);
    $pdf->SetFont('dejavusans', '', 7.5); 
    $pdf->SetLineWidth(0.1); 
    //$bank_fill = 0;

    foreach ($bankAccounts as $account) {
        $row_height = $pdf->getStringHeight($w_bank[2], htmlspecialchars($account['iban'] ?? '-'));
        $min_cell_height = 5.5; $row_height = max($row_height, $min_cell_height);
        $pdf->Cell($w_bank[0], $row_height, htmlspecialchars($account['bank_name'] ?? '-'), 1, 0, 'L', false, '', 0, false, 'T', 'M');
        $pdf->Cell($w_bank[1], $row_height, htmlspecialchars($account['account_holder'] ?? '-'), 1, 0, 'L', false, '', 0, false, 'T', 'M');
        $pdf->MultiCell($w_bank[2], $row_height, htmlspecialchars($account['iban'] ?? '-'), 1, 'L', false, 0, '', '', true, 0, false, true, $row_height, 'M');
        $pdf->Cell($w_bank[3], $row_height, htmlspecialchars($account['account_number'] ?? '-'), 1, 1, 'L', false, '', 0, false, 'T', 'M');
        $bank_fill = !$bank_fill;
    }
    $pdf->Ln(2); // Tablo sonrası boşluk
    $pdf->writeHTML('</div>', true, false, true, false, ''); // Section box'ı kapat
    $pdf->Ln(5); // Bölüm sonrası boşluk
}

// Fatura PDF'inde Şartlar ve İmza genellikle olmaz. Gerekirse eklenebilir.

// --- PDF Çıktısı ---
$safe_invoice_no = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice['invoice_no']);
$pdfFileName = 'Fatura_' . $safe_invoice_no . '_' . date('Ymd') . '.pdf';

ob_end_clean(); // Önceki çıktıları temizle
$pdf->Output($pdfFileName, 'I'); // Tarayıcıda göster
exit;
?>