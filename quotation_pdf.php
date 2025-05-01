<?php
// quotation_pdf.php - Geliştirilmiş Teklif PDF Çıktısı
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

// --- Teklif ID Kontrolü ---
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Hata: Geçersiz teklif ID'si.");
}
$quotation_id = intval($_GET['id']);

// --- Veritabanı Bağlantısı ve Veri Çekme ---
$conn = getDbConnection();
$quotation = null;
$items = [];
$customer = null;
$user = null;
$companySettings = [];
$bankAccounts = []; // Artık tekil değil, tüm banka hesapları için dizi

try {
    // 1. Şirket Ayarlarını Doğrudan Ayarlar Tablosundan Al
    $stmtSettings = $conn->prepare("SELECT setting_key, setting_value FROM settings");
    $stmtSettings->execute();
    $settingsRows = $stmtSettings->fetchAll(PDO::FETCH_ASSOC);

    // Varsayılan değerler (veritabanında eksik ayar varsa kullanılır)
    $defaultCompanySettings = [
        'company_name' => 'Şirketiniz A.Ş.',
        'company_slogan' => '', // Slogan PDF'te kullanılmayacak
        'company_address' => 'Örnek Adres, No: 1, İlçe, Şehir',
        'company_phone' => '0212 123 45 67',
        'company_email' => 'info@sirketiniz.com',
        'company_website' => 'www.sirketiniz.com',
        'company_tax_office' => 'Örnek Vergi Dairesi',
        'company_tax_number' => '1234567890',
        'company_logo_path' => 'assets/img/logo.png', // Logo yolu ayarı
        'primary_color' => '#4A6EAA',
        'quotation_prefix' => 'TEK',
        'quotation_terms' => "1. Fiyatlarımıza KDV dahil değildir.\n2. Ödeme şekli: Peşin.\n3. Teslim süresi: Siparişi takiben X iş günü."
    ];

    $companySettings = $defaultCompanySettings; // Önce varsayılanları ata

    // Veritabanından gelen değerlerle güncelle
    foreach ($settingsRows as $row) {
        if (array_key_exists($row['setting_key'], $companySettings)) {
            $companySettings[$row['setting_key']] = $row['setting_value'];
        }
    }

    // 1.1. TÜM Aktif Banka Hesap Bilgilerini Çek (is_default şartı kaldırıldı)
    $stmtBank = $conn->prepare("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY is_default DESC");
    $stmtBank->execute();
    if ($stmtBank->rowCount() > 0) {
        $bankAccounts = $stmtBank->fetchAll(PDO::FETCH_ASSOC); // Tüm hesapları al
    }

    // 2. Teklif, Müşteri ve Kullanıcı Bilgilerini Al (JOIN ile)
    $stmt = $conn->prepare("
        SELECT
            q.*,
            c.id as cust_id, c.name as customer_name, c.contact_person, c.email as customer_email,
            c.phone as customer_phone, c.address as customer_address, c.tax_office as customer_tax_office,
            c.tax_number as customer_tax_number,
            u.id as user_id_alias, u.full_name as user_name, u.email as user_email
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        die("Hata: Teklif bulunamadı (ID: {$quotation_id}).");
    }
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Müşteri ve Kullanıcı verilerini ayrı dizilere alalım (daha temiz)
    $customer = [
        'id' => $quotation['cust_id'],
        'name' => $quotation['customer_name'],
        'contact_person' => $quotation['contact_person'],
        'email' => $quotation['customer_email'],
        'phone' => $quotation['customer_phone'],
        'address' => $quotation['customer_address'],
        'tax_office' => $quotation['customer_tax_office'], 
        'tax_number' => $quotation['customer_tax_number']  
    ];
    $user = [
        'id' => $quotation['user_id_alias'],
        'name' => $quotation['user_name'],
        'email' => $quotation['user_email']
    ];

    // 3. Teklif Kalemlerini Al (JOIN ile Ürün/Hizmet Adı ve Kodu)
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
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id ASC
    ");
    $stmtItems->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Veritabanı hatası (quotation_pdf.php): " . $e->getMessage());
    die("Veritabanı bağlantı hatası oluştu. Lütfen sistem yöneticisi ile iletişime geçin.");
} catch (Exception $e) {
    die("Bir hata oluştu: " . $e->getMessage());
}

// --- Durum Haritası Tanımı ---
$statusMap = [
    'draft' => ['text' => 'Taslak', 'class' => 'secondary'],
    'sent' => ['text' => 'Gönderildi', 'class' => 'primary'],
    'accepted' => ['text' => 'Kabul Edildi', 'class' => 'success'],
    'rejected' => ['text' => 'Reddedildi', 'class' => 'danger'],
    'expired' => ['text' => 'Süresi Doldu', 'class' => 'warning']
];

// --- PDF için Özel Sınıf Tanımı ---
class MYPDF_Quotation extends TCPDF
{
    public $companySettings = []; // Şirket ayarları için dizi
    public $isLastPage = false; // Son sayfa kontrolü (Footer'daki IBAN için)

    // Header Metodu - Üst Bilgi
    public function Header()
    {
        // Logo
        $logoPathSetting = $this->companySettings['company_logo_path'] ?? 'assets/img/logo.png';
        $image_file = __DIR__ . '/' . ltrim($logoPathSetting, '/'); // Göreceli yolu tam yola çevir

        $logoX = 15;       // Sol kenar boşluğu
        $logoY = 10;       // Üst kenar boşluğu
        $logoTargetW = 45; // Hedeflenen logo genişliği
        $logoMaxH = 18;    // Maksimum izin verilen yükseklik

        $logoDrawW = $logoTargetW; // Image() fonksiyonuna gönderilecek gerçek genişlik
        $logoDrawH = 0;       // Image() fonksiyonuna gönderilecek gerçek yükseklik (0 = oranı koru)

        if (file_exists($image_file)) {
            // PHP'nin getimagesize fonksiyonu ile orijinal boyutları al
            $imageSize = getimagesize($image_file);
            if ($imageSize !== false) {
                $originalW = $imageSize[0];
                $originalH = $imageSize[1];

                if ($originalW > 0 && $originalH > 0) { // Geçerli boyutlar mı?
                    // Hedef genişliğe göre potansiyel yüksekliği hesapla
                    $potentialH = ($originalH / $originalW) * $logoTargetW;

                    // Eğer potansiyel yükseklik maksimum yüksekliği aşıyorsa,
                    // yüksekliğe göre ölçekle
                    if ($potentialH > $logoMaxH) {
                        $logoDrawH = $logoMaxH; // Yüksekliği sabitle
                        $logoDrawW = 0;       // Genişliği otomatik hesaplat
                    } else {
                        // Yükseklik sınırı aşmıyorsa, hedef genişliği kullan
                        $logoDrawW = $logoTargetW;
                        $logoDrawH = 0; // Yüksekliği otomatik hesaplat
                    }
                }
            }
            // Resmi çizdir
            $this->Image($image_file, $logoX, $logoY, $logoDrawW, $logoDrawH, '', '', 'T', true, 300, '', false, false, 0, false, false, false);

        } else {
            // Logo yoksa veya bulunamazsa, Şirket Adını Yaz
            $this->SetFont('dejavusansb', 'B', 10);
            $this->SetXY($logoX, $logoY + 5);
            $this->Cell(50, 10, $this->companySettings['company_name'], 0, 0, 'L');
        }

        // Header Ayırıcı Çizgi
        $separatorY = $this->GetY() + 12; // Sağdaki metnin hemen altı
        $this->SetY($separatorY);
        $this->SetLineStyle(['width' => 0.2, 'color' => [200, 200, 200]]); // İnce gri çizgi
        $this->Line($this->original_lMargin, $this->GetY(), $this->getPageWidth() - $this->original_rMargin, $this->GetY());
        
    }
    
    // Footer Metodu - Alt Bilgi
    public function Footer()
    {
        $footerY = -20; // Sayfanın altından başlama noktası
        $this->SetY($footerY);

        // Footer Ayırıcı Çizgi
        $this->SetLineStyle(['width' => 0.2, 'color' => [200, 200, 200]]);
        $this->Line($this->original_lMargin, $this->GetY(), $this->getPageWidth() - $this->original_rMargin, $this->GetY());
        $this->SetY($this->GetY() + 1); // Çizgiden sonra biraz boşluk

        // Footer İçeriği
        $this->SetTextColor(100, 100, 100); // Gri yazı rengi
        $this->SetFont('dejavusans', '', 7.5); // Biraz daha küçük font

        // Sol Taraf: Şirket Adı | Vergi Dairesi/No
        $footerLeft = htmlspecialchars($this->companySettings['company_name'] ?? '');
        if (!empty($this->companySettings['company_tax_office']) || !empty($this->companySettings['company_tax_number'])) {
            $footerLeft .= ' | Vergi D.: ' . htmlspecialchars($this->companySettings['company_tax_office'] ?? '-') . ' / No: ' . htmlspecialchars($this->companySettings['company_tax_number'] ?? '-');
        }

        // Sağ Taraf: Sayfa Numarası
        $footerRight = 'Sayfa ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();

        // Alt Sol Taraf: Web | E-posta
        $footerLeft2 = '';
        if (!empty($this->companySettings['company_website'])) {
            $footerLeft2 .= htmlspecialchars($this->companySettings['company_website']);
        }
        if (!empty($this->companySettings['company_email'])) {
            if (!empty($footerLeft2))
                $footerLeft2 .= ' | ';
            $footerLeft2 .= htmlspecialchars($this->companySettings['company_email']);
        }

        // Footer'ı iki satırda yazdır
        $this->Cell(0, 5, $footerLeft, 0, 0, 'L');
        $this->Cell(0, 5, $footerRight, 0, 1, 'R'); // 1: Satır sonu
        if (!empty($footerLeft2)) {
            $this->Cell(0, 5, $footerLeft2, 0, 1, 'L');
        }
    }
}
// --- Özel PDF Sınıfı Tanımı Sonu ---

// --- PDF Nesnesi Oluşturma ---
$pdf = new MYPDF_Quotation(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A4', true, 'UTF-8', false);

// Şirket ayarlarını PDF sınıfına aktar, artık çoklu banka hesabı var
$pdf->companySettings = $companySettings;

// Doküman Meta Bilgileri
$pdf->SetCreator('Teklif Yönetim Sistemi');
$pdf->SetAuthor(htmlspecialchars($companySettings['company_name']));
$pdf->SetTitle('Teklif: ' . htmlspecialchars($quotation['reference_no']));
$pdf->SetSubject('Fiyat Teklifi');
$pdf->SetKeywords('Teklif, Fiyat, Quotation, ' . htmlspecialchars($companySettings['company_name']));

// Header ve Footer Ayarları
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// Kenar Boşlukları (Sol, Üst, Sağ)
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(28);

// Otomatik Sayfa Kesme Ayarı
$pdf->SetAutoPageBreak(TRUE, 32);

// Yazı Tipi (Varsayılan)
$pdf->SetFont('dejavusans', '', 9);

// Yeni Sayfa Ekle
$pdf->AddPage();

// Renkler
$primaryColor = $companySettings['primary_color'] ?? '#4A6EAA';
// TCPDF için renkleri dönüştür
list($r, $g, $b) = sscanf($primaryColor, "#%02x%02x%02x");
$primaryColorArray = [$r, $g, $b];

$tableHeaderBgColor = $primaryColorArray;
$tableHeaderTextColor = [255, 255, 255];
$tableBorderColor = [220, 220, 220];
$infoBoxBgColor = [250, 250, 250];
$infoBoxBorderColor = [230, 230, 230];
$totalRowBgColor = $primaryColorArray;
$totalRowTextColor = [255, 255, 255];

// 1. Teklif No ve Tarih Kutusu
$pdf->Ln(5);
$html = '
<table cellpadding="0" cellspacing="4" border="0" style="width: 100%;">
    <tr>
        <td width="48%" style="background-color: rgb(' . implode(',', $primaryColorArray) . '); color: rgb(255,255,255); padding: 10px 12px; vertical-align: middle; border-radius: 4px 0 0 4px;">
            <span style="font-family: \'dejavusansb\'; font-size: 12pt; font-weight: bold;">TEKLİF NO: ' . htmlspecialchars($quotation['reference_no']) . '</span>
        </td>
        <td width="52%" style="text-align: right; font-size: 9pt; vertical-align: middle; padding: 8px 0;">
            <strong>Teklif Tarihi:</strong> ' . formatDateTR($quotation['date']) . '<br/>
            <strong>Geçerlilik Tarihi:</strong> ' . formatDateTR($quotation['valid_until']) . '<br/>
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(0);

// 2. Firma ve Müşteri Bilgileri (Yan Yana)
$html = '
<table cellspacing="0" cellpadding="8" border="0" style="width: 100%;">
    <tr>
        <td width="48%" style="background-color: #f9f9f9; border-radius: 5px; padding: 10px; vertical-align: top;">
                <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . ';">FİRMA BİLGİLERİ</h3>
                <p style="margin: 5px 0; line-height: 1.5em;"></p>
                <p><strong>' . htmlspecialchars($companySettings['company_name']) . '</strong></p>'
    . '<p>' . nl2br(htmlspecialchars($companySettings['company_address'])) . '</p>'
    . '<p>V.D./No: ' . htmlspecialchars($companySettings['company_tax_office'] ?? '-') . ' / ' . htmlspecialchars($companySettings['company_tax_number'] ?? '-') . '</p>'
    . '<p>Tel: ' . htmlspecialchars($companySettings['company_phone'] ?? '-') . '</p>'
    . '
        </td>
        <td width="4%">&nbsp;</td>
        <td width="48%" style="background-color: #f9f9f9; border-radius: 5px; padding: 10px; vertical-align: top;">
            
                <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . ';">MÜŞTERİ BİLGİLERİ</h3>
                <p style="margin: 5px 0; line-height: 1.5em;"></p>
                <p><strong>' . htmlspecialchars($customer['name']) . '</strong></p>';
if (!empty($customer['contact_person'])) {
    $html .= '<p>İlgili: ' . htmlspecialchars($customer['contact_person']) . '</p>';
}
$html .= '<p>' . nl2br(htmlspecialchars($customer['address'] ?? 'Adres belirtilmemiş')) . '</p>'
    . '<p>V.D./No: ' . htmlspecialchars($customer['tax_office'] ?? '-') . ' / ' . htmlspecialchars($customer['tax_number'] ?? '-') . '</p>'
    . '<p>Tel: ' . htmlspecialchars($customer['phone'] ?? '-') . '</p>';
$html .= '  
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(-5);

// 3. Teklifi Hazırlayan Bilgisi
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%;">
    <tr>
        <td width="100%" style="background-color: #f9f9f9; border-radius: 5px;">
            <strong>Teklifi Hazırlayan:</strong> ' . $quotation['user_name'] . 
            ' | <strong>E-posta:</strong> ' . $quotation['user_email'] . '
        </td>
    </tr>
</table>';
$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(-5);

// 4. Teklif Kalemleri Tablosu - Tür sütunu kaldırıldı
$pdf->SetFillColorArray($tableHeaderBgColor);
$pdf->SetTextColorArray($tableHeaderTextColor);
$pdf->SetDrawColorArray($primaryColorArray);
$pdf->SetFont('dejavusansb', 'B', 8);
$pdf->SetLineWidth(0.3);

// Başlık Hücreleri - "Tür" sütunu kaldırıldı
$header = ['S.N', 'Kod', 'Açıklama', 'Miktar', 'Birim F.', 'İnd.%', 'Ara Top.', 'Toplam'];
// Genişlikleri ayarla - Tür sütununun genişliği Ara Toplam ve Toplam'a eklendi
$w = [8, 16, 58, 14, 22, 10, 28, 28]; // "Tür" sütunu (12) kaldırıldı, Ara Toplam ve Toplam +6 birim genişletildi
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

// İçerik Stilleri
$pdf->SetFillColor(255, 255, 255);
$pdf->SetTextColor(50, 50, 50);
$pdf->SetDrawColorArray($tableBorderColor);
$pdf->SetFont('dejavusans', '', 8);
$pdf->SetLineWidth(0.1);
$fill = 0;

$counter = 1;
foreach ($items as $item) {
    $unit_price = (float) ($item['unit_price'] ?? 0);
    $discount_percent = (float) ($item['discount_percent'] ?? 0);
    $quantity = (float) ($item['quantity'] ?? 0);
    $tax_rate = (float) ($item['tax_rate'] ?? 0);

    // Hesaplamalar
    $unit_price_after_discount = $unit_price * (1 - ($discount_percent / 100));
    $line_subtotal_before_tax = $quantity * $unit_price_after_discount;
    $line_total_with_tax = $line_subtotal_before_tax * (1 + ($tax_rate / 100));

    // Satır yüksekliğini belirle
    $row_height = $pdf->getStringHeight($w[2], htmlspecialchars($item['description'] ?? ($item['item_name'] ?? '')));
    $min_cell_height = 6;
    $row_height = max($row_height, $min_cell_height);

    // Satırı yazdır - "Tür" sütunu kaldırıldı
    $pdf->Cell($w[0], $row_height, $counter++, 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
    // Tür sütunu kaldırıldı, sonraki sütunlar bir öne kaydırıldı
    $pdf->Cell($w[1], $row_height, htmlspecialchars($item['item_code'] ?? '-'), 1, 0, 'L', $fill, '', 0, false, 'T', 'M');
    $pdf->MultiCell($w[2], $row_height, htmlspecialchars($item['description'] ?? ($item['item_name'] ?? '')), 1, 'L', $fill, 0, '', '', true, 0, false, true, $row_height, 'M');
    $pdf->Cell($w[3], $row_height, number_format($quantity, 0, ',', '.'), 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
    $pdf->Cell($w[4], $row_height, formatCurrencyTR($unit_price), 1, 0, 'R', $fill, '', 0, false, 'T', 'M');
    $pdf->Cell($w[5], $row_height, number_format($discount_percent, 0) . '%', 1, 0, 'C', $fill, '', 0, false, 'T', 'M');
    $pdf->Cell($w[6], $row_height, formatCurrencyTR($line_subtotal_before_tax), 1, 0, 'R', $fill, '', 0, false, 'T', 'M');
    $pdf->Cell($w[7], $row_height, formatCurrencyTR($line_total_with_tax), 1, 1, 'R', $fill, '', 0, false, 'T', 'M');

    $fill = !$fill;
}
$pdf->Ln(1);

// 5. Toplamlar Tablosu
$html = '
<table border="0" cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
        <td width="55%"></td>
        <td width="45%">
            <table class="totals-table" cellpadding="5" cellspacing="0">
                <tr>
                    <td class="label" width="60%">Ara Toplam:</td>
                    <td class="value" width="40%">' . formatCurrencyTR($quotation['subtotal']) . '</td>
                </tr>';
// İndirim varsa göster
if (isset($quotation['discount_amount']) && (float) $quotation['discount_amount'] != 0) {
    $html .= '<tr>
                    <td class="label">İndirim Tutarı:</td>
                    <td class="value">' . formatCurrencyTR((float) $quotation['discount_amount'] * -1) . '</td>
                  </tr>';
}
// KDV varsa göster
if (isset($quotation['tax_amount']) && (float) $quotation['tax_amount'] != 0) {
    $html .= '<tr>
                    <td class="label">KDV Toplamı:</td>
                    <td class="value">' . formatCurrencyTR($quotation['tax_amount']) . '</td>
                  </tr>';
}
$html .= '    <tr class="total-row" style="background-color: rgb(' . implode(',', $totalRowBgColor) . '); color: rgb(' . implode(',', $totalRowTextColor) . ');">
                    <td class="label">GENEL TOPLAM:</td>
                    <td class="value">' . formatCurrencyTR($quotation['total_amount']) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');
$pdf->Ln(-3);

// 6. Ödeme Bilgileri - Tablo Formatında (Varsayılan ve Şube Yok)
$html_payment_title = '';
if (!empty($bankAccounts)) {
    // Sadece başlığı HTML olarak hazırla
    $html_payment_title = '<div class="section-box">
    <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . ';">ÖDEME BİLGİLERİ</h3>';

    // Başlığı yazdır ve Ln ekle ki tablo hemen altına başlasın
    $pdf->writeHTML($html_payment_title, true, false, true, false, '');
    $pdf->Ln(0); // Başlık sonrası boşluk

    // --- Banka Hesapları Tablosu (Cell/MultiCell ile) ---
    $pdf->SetFillColorArray($tableHeaderBgColor);
    $pdf->SetTextColorArray($tableHeaderTextColor);
    $pdf->SetDrawColorArray($primaryColorArray);
    $pdf->SetFont('dejavusansb', 'B', 8); // Başlık fontu
    $pdf->SetLineWidth(0.3); // Başlık border

    // Başlıklar (Varsayılan ve Şube kaldırıldı)
    $bank_header = ['Banka Adı', 'Hesap Sahibi', 'IBAN', 'Hesap No'];
    // Sütun Genişlikleri (Varsayılan(15) ve Şube(25) = 40 birim kaldırıldı, diğerlerine dağıtıldı)
    // Örn: Banka Adı +10, Hesap Sahibi +10, IBAN +10, Hesap No +10
    $w_bank = [30, 70, 55, 25]; // Toplam 180
    $num_bank_headers = count($bank_header);
    for ($i = 0; $i < $num_bank_headers; ++$i) {
        // Tüm başlıkları sola hizala
        $align = 'L';
        $pdf->Cell($w_bank[$i], 7, $bank_header[$i], 1, 0, $align, 1);
    }
    $pdf->Ln(); // Başlık sonrası yeni satır

    // İçerik Stilleri
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetTextColor(50, 50, 50);
    $pdf->SetDrawColorArray($tableBorderColor);
    $pdf->SetFont('dejavusans', '', 7.5); // İçerik fontu biraz daha küçük
    $pdf->SetLineWidth(0.1);
    $bank_fill = 0;

    foreach ($bankAccounts as $account) {
        // Satır yüksekliğini IBAN'a göre ayarla (yeni index 2)
        $row_height = $pdf->getStringHeight($w_bank[2], htmlspecialchars($account['iban'] ?? '-'));
        $min_cell_height = 5.5; // Minimum yükseklik
        $row_height = max($row_height, $min_cell_height);

        // Satırı Yazdır (Varsayılan ve Şube olmadan, yeni indexlerle)
        $pdf->Cell($w_bank[0], $row_height, htmlspecialchars($account['bank_name'] ?? '-'), 1, 0, 'L', $bank_fill, '', 0, false, 'T', 'M');         // Banka Adı (index 0)
        $pdf->Cell($w_bank[1], $row_height, htmlspecialchars($account['account_holder'] ?? '-'), 1, 0, 'L', $bank_fill, '', 0, false, 'T', 'M');    // Hesap Sahibi (index 1)
        // IBAN potansiyel olarak uzun olduğu için MultiCell (yeni index 2)
        $pdf->MultiCell($w_bank[2], $row_height, htmlspecialchars($account['iban'] ?? '-'), 1, 'L', $bank_fill, 0, '', '', true, 0, false, true, $row_height, 'M');
        $pdf->Cell($w_bank[3], $row_height, htmlspecialchars($account['account_number'] ?? '-'), 1, 1, 'L', $bank_fill, '', 0, false, 'T', 'M'); // Hesap No (index 3), satır sonu

        $bank_fill = !$bank_fill;
    }
    // Tablo sonrası boşluk bırakmak için Ln ekle
    $pdf->Ln(-10);
     // Section box div'ini kapatan HTML'i ekle
     $pdf->writeHTML('</div>', true, false, true, false, '');

} else {
    // Banka hesabı yoksa boşluk bırak veya mesaj yaz
    // $html_payment = ''; // Bu değişken artık kullanılmıyor
}
// --- Sayfa Sonu Elemanlarını Yazdır ---
// $html_payment artık Cell/MultiCell ile yazdırıldığı için burada tekrar yazdırılmaz.
// Sadece diğer bölümleri yazdır:
if (!empty($html_terms)) {
    $pdf->writeHTML($html_terms, true, false, true, false, '');
    $pdf->Ln(0); // Şartlar sonrası boşluk
}
$pdf->writeHTML($html_signature, true, false, true, false, '');

// 7. Şartlar ve Koşullar
$html_terms = '';
$terms_text = !empty(trim($quotation['terms_conditions'] ?? '')) ? $quotation['terms_conditions'] : ($companySettings['quotation_terms'] ?? '');

if (!empty(trim($terms_text))) {
    $html_terms = '<div class="section-box">
    <h3 style="margin: 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $primaryColor . ';">ŞARTLAR VE KOŞULLAR</h3>
    <ol style="margin-left: 20px; padding-left: 0; line-height: 1.4;">';
    
    $terms_lines = explode("\n", trim($terms_text));
    foreach ($terms_lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            $html_terms .= '<li>' . htmlspecialchars($line) . '</li>';
        }
    }
    
    $html_terms .= '</ol></div>';
}

// 8. İmza Alanları
$html_signature = '
<table border="0" cellpadding="0" cellspacing="0" class="signature-area">
    <tr>
        <td class="signature-box" width="48%" align="center">
            <strong>Teklifi Veren</strong>
            <br/>' . htmlspecialchars($user['name']) . '<br/>
            ' . htmlspecialchars($companySettings['company_name']) . '
        </td>
        <td width="4%"></td>
        <td class="signature-box" width="48%" align="center">
            <strong>Teklifi Onaylayan</strong><br/>
        </td>
    </tr>
</table>';

// Sayfa Sonu Elemanlarını Yazdır
if (!empty($html_payment)) {
    $pdf->writeHTML($html_payment, true, false, true, false, '');
}
if (!empty($html_terms)) {
    $pdf->writeHTML($html_terms, true, false, true, false, '');
}
$pdf->writeHTML($html_signature, true, false, true, false, '');

// Son sayfada olduğumuzu işaretle
if ($pdf->getPage() == $pdf->getNumPages()) {
    $pdf->isLastPage = true;
}

// PDF Çıktısı
$safe_ref_no = preg_replace('/[^A-Za-z0-9\-_]/', '_', $quotation['reference_no']);
$pdfFileName = 'Teklif_' . $safe_ref_no . '_' . date('Ymd') . '.pdf';

ob_end_clean();
$pdf->Output($pdfFileName, 'I');
exit;
?>