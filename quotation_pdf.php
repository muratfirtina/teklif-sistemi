<?php
// quotation_pdf.php - Teklif PDF çıktısı oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Composer autoload dosyasını dahil et
require_once 'vendor/autoload.php';

// TCPDF kütüphanesini dahil et
//require_once('tcpdf/tcpdf.php');

// Kullanıcı girişi gerekli
requireLogin();

// PDF oluşturmak için ID kontrol edilir
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Geçersiz teklif ID'si.");
}

$quotation_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Teklif bilgilerini al
try {
    $stmt = $conn->prepare("
        SELECT q.*, c.name as customer_name, c.contact_person, c.email as customer_email, 
               c.phone as customer_phone, c.address as customer_address, c.tax_office, c.tax_number,
               u.full_name as user_name, u.email as user_email
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        die("Teklif bulunamadı.");
    }
    
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Teklif kalemlerini al
    $stmt = $conn->prepare("
        SELECT qi.*, 
            CASE 
                WHEN qi.item_type = 'product' THEN p.name
                WHEN qi.item_type = 'service' THEN s.name
                ELSE NULL
            END as item_name,
            CASE 
                WHEN qi.item_type = 'product' THEN p.code
                WHEN qi.item_type = 'service' THEN s.code
                ELSE NULL
            END as item_code
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id ASC
    ");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// Özel PDF sınıfı oluştur
class MYPDF extends TCPDF {
    // Sayfa başlığı
    public function Header() {
        // Logo
        $image_file = 'assets/img/logo.png';
        if (file_exists($image_file)) {
            $this->Image($image_file, 10, 10, 50, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Başlık
        $this->SetFont('dejavusans', 'B', 18);
        $this->SetY(15);
        $this->Cell(0, 15, 'TEKLİF', 0, false, 'R', 0, '', 0, false, 'M', 'M');
        
        // Çizgi
        $this->Line(10, 30, $this->getPageWidth() - 10, 30);
    }

    // Sayfa altlığı
    public function Footer() {
        // Sayfa numarası
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $this->Cell(0, 10, 'Sayfa '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// PDF dokümanı oluştur
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Doküman bilgilerini ayarla
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Teklif Yönetim Sistemi');
$pdf->SetTitle('Teklif: ' . $quotation['reference_no']);
$pdf->SetSubject('Teklif');
$pdf->SetKeywords('Teklif, PDF, Teklif Yönetim Sistemi');

// Varsayılan başlık ayarlarını devre dışı bırak
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// Varsayılan kenar boşluklarını ayarla
$pdf->SetMargins(PDF_MARGIN_LEFT, 40, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Otomatik sayfa kesmelerini ayarla
$pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

// Ölçeklendirme faktörlerini ayarla
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Türkçe karakterler için yazı tipini ayarla
$pdf->SetFont('dejavusans', '', 10);

// Yeni sayfa ekle
$pdf->AddPage();

// Teklif ve müşteri bilgileri tablosu oluştur
$html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 100%;">
    <tr>
        <td width="50%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <h4 style="margin: 0;">FİRMA BİLGİLERİ</h4>
            <p style="margin: 5px 0;">
                <strong>Firma Adı:</strong> Şirketiniz<br>
                <strong>Adres:</strong> Firma Adresi<br>
                <strong>Telefon:</strong> Firma Telefonu<br>
                <strong>E-posta:</strong> ' . $quotation['user_email'] . '<br>
                <strong>Vergi Dairesi / No:</strong> Firma Vergi Bilgileri
            </p>
        </td>
        <td width="50%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <h4 style="margin: 0;">MÜŞTERİ BİLGİLERİ</h4>
            <p style="margin: 5px 0;">
                <strong>Firma Adı:</strong> ' . $quotation['customer_name'] . '<br>
                <strong>İlgili Kişi:</strong> ' . $quotation['contact_person'] . '<br>
                <strong>Adres:</strong> ' . $quotation['customer_address'] . '<br>
                <strong>Telefon:</strong> ' . $quotation['customer_phone'] . '<br>
                <strong>E-posta:</strong> ' . $quotation['customer_email'] . '<br>
                <strong>Vergi Dairesi / No:</strong> ' . $quotation['tax_office'] . ' / ' . $quotation['tax_number'] . '
            </p>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Teklif bilgileri tablosu oluştur
$html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 100%; margin-top: 10px;">
    <tr>
        <td width="25%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Teklif No:</strong>
        </td>
        <td width="25%" style="border: 1px solid #ddd;">
            ' . $quotation['reference_no'] . '
        </td>
        <td width="25%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Teklif Tarihi:</strong>
        </td>
        <td width="25%" style="border: 1px solid #ddd;">
            ' . date('d.m.Y', strtotime($quotation['date'])) . '
        </td>
    </tr>
    <tr>
        <td style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Hazırlayan:</strong>
        </td>
        <td style="border: 1px solid #ddd;">
            ' . $quotation['user_name'] . '
        </td>
        <td style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Geçerlilik Tarihi:</strong>
        </td>
        <td style="border: 1px solid #ddd;">
            ' . date('d.m.Y', strtotime($quotation['valid_until'])) . '
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Biraz boşluk ekle
$pdf->Ln(5);

// Teklif kalemleri tablosu oluştur
$html = '<h4>TEKLİF KALEMLERİ</h4>
<table cellspacing="0" cellpadding="5" border="1" style="width: 100%;">
    <tr style="background-color: #f2f2f2; font-weight: bold;">
        <th width="5%" align="center">S.No</th>
        <th width="10%" align="center">Tür</th>
        <th width="10%" align="center">Kod</th>
        <th width="30%" align="left">Açıklama</th>
        <th width="10%" align="center">Miktar</th>
        <th width="10%" align="right">Birim Fiyat</th>
        <th width="5%" align="center">İnd. %</th>
        <th width="10%" align="right">Ara Toplam</th>
        <th width="5%" align="center">KDV %</th>
        <th width="10%" align="right">KDV Dahil</th>
    </tr>';

$counter = 1;
foreach ($items as $item) {
    $item_type = $item['item_type'] == 'product' ? 'Ürün' : 'Hizmet';
    $unit_price = $item['unit_price'];
    $discount_percent = $item['discount_percent'];
    $quantity = $item['quantity'];
    $item_discount = $unit_price * ($discount_percent / 100);
    $unit_price_after_discount = $unit_price - $item_discount;
    $subtotal = $quantity * $unit_price_after_discount;
    $tax_rate = $item['tax_rate'];
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_with_tax = $subtotal + $tax_amount;

    $html .= '<tr' . ($counter % 2 == 0 ? ' style="background-color: #f9f9f9;"' : '') . '>
        <td align="center">' . $counter . '</td>
        <td align="center">' . $item_type . '</td>
        <td align="center">' . $item['item_code'] . '</td>
        <td>' . $item['description'] . '</td>
        <td align="center">' . $quantity . '</td>
        <td align="right">' . number_format($unit_price, 2, ',', '.') . ' ₺</td>
        <td align="center">' . $discount_percent . '%</td>
        <td align="right">' . number_format($subtotal, 2, ',', '.') . ' ₺</td>
        <td align="center">' . $tax_rate . '%</td>
        <td align="right">' . number_format($total_with_tax, 2, ',', '.') . ' ₺</td>
    </tr>';
    $counter++;
}

$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Toplam tablo oluştur
$html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 35%; margin-top: 10px; margin-left: 65%;">
    <tr>
        <td width="50%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Ara Toplam:</strong>
        </td>
        <td width="50%" style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['subtotal'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>İndirim:</strong>
        </td>
        <td style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['discount_amount'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>KDV:</strong>
        </td>
        <td style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['tax_amount'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: #f2f2f2; border: 1px solid #ddd;">
            <strong>Genel Toplam:</strong>
        </td>
        <td style="border: 1px solid #ddd; background-color: #e6f2ff;" align="right">
            <strong>' . number_format($quotation['total_amount'], 2, ',', '.') . ' ₺</strong>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Biraz boşluk ekle
$pdf->Ln(10);

// Notlar ve şartlar alanı
if (!empty($quotation['notes']) || !empty($quotation['terms_conditions'])) {
    $html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 100%;">';
    
    if (!empty($quotation['notes'])) {
        $html .= '<tr>
            <td width="20%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
                <strong>Notlar:</strong>
            </td>
            <td width="80%" style="border: 1px solid #ddd;">
                ' . nl2br($quotation['notes']) . '
            </td>
        </tr>';
    }
    
    if (!empty($quotation['terms_conditions'])) {
        $html .= '<tr>
            <td width="20%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
                <strong>Şartlar ve Koşullar:</strong>
            </td>
            <td width="80%" style="border: 1px solid #ddd;">
                ' . nl2br($quotation['terms_conditions']) . '
            </td>
        </tr>';
    }
    
    $html .= '</table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
}

// İmza alanları
$html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 100%; margin-top: 30px;">
    <tr>
        <td width="45%" style="border-top: 1px solid #000;" align="center">
            <strong>Teklifi Veren</strong>
        </td>
        <td width="10%">&nbsp;</td>
        <td width="45%" style="border-top: 1px solid #000;" align="center">
            <strong>Teklifi Kabul Eden</strong>
        </td>
    </tr>
    <tr>
        <td align="center">' . $quotation['user_name'] . '</td>
        <td>&nbsp;</td>
        <td align="center">' . $quotation['contact_person'] . '</td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// PDF dosyasını çıktıla
$pdf->Output('Teklif_' . $quotation['reference_no'] . '.pdf', 'I');
?>