<?php
// quotation_pdf.php - Teklif PDF çıktısı oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Composer autoload dosyasını dahil et
require_once 'vendor/autoload.php';

// Hata raporlamasını geçici olarak kapat (PDF oluşturma sırasında)
error_reporting(0);
ini_set('display_errors', 0);

// Kullanıcı girişi gerekli
requireLogin();

// PDF oluşturmak için ID kontrol edilir
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Geçersiz teklif ID'si.");
}

$quotation_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Varsayılan şirket bilgileri (settings tablosu mevcut değilse)
$company_settings = [
    'company_name' => 'Şirketiniz',
    'company_slogan' => 'Şirket Sloganı',
    'address' => 'Şirket Adresi',
    'phone' => 'Şirket Telefonu',
    'email' => 'info@sirketiniz.com',
    'website' => 'www.sirketiniz.com',
    'tax_office' => 'Vergi Dairesi',
    'tax_number' => 'Vergi No',
    'primary_color' => '#4a6eaa', // Varsayılan mavi tonu
    'bank_name' => 'Banka Adı',
    'account_holder' => 'Hesap Sahibi',
    'iban' => 'TR00 0000 0000 0000 0000 0000 00'
];

// Şirket bilgilerini settings tablosundan çekmeyi dene
try {
    // Önce tablo var mı kontrol et
    $stmt = $conn->prepare("SHOW TABLES LIKE 'settings'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        // Tablo mevcut, veri çekmeyi dene
        $stmt = $conn->prepare("SELECT * FROM settings WHERE id = 1");
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $db_settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Mevcut sütun adlarını tespit et
            $columns = [];
            foreach ($db_settings as $key => $value) {
                $columns[$key] = $value;
            }
            
            // Sütun adlarını kontrol et ve eşleştirme yap
            // Burada veritabanındaki gerçek sütun adlarını kullanıyoruz
            if (isset($columns['sirket_adi'])) {
                $company_settings['company_name'] = $columns['sirket_adi'];
            } elseif (isset($columns['company_name'])) {
                $company_settings['company_name'] = $columns['company_name'];
            }
            
            if (isset($columns['slogan'])) {
                $company_settings['company_slogan'] = $columns['slogan'];
            } elseif (isset($columns['company_slogan'])) {
                $company_settings['company_slogan'] = $columns['company_slogan'];
            }
            
            if (isset($columns['adres'])) {
                $company_settings['address'] = $columns['adres'];
            } elseif (isset($columns['address'])) {
                $company_settings['address'] = $columns['address'];
            }
            
            if (isset($columns['telefon'])) {
                $company_settings['phone'] = $columns['telefon'];
            } elseif (isset($columns['phone'])) {
                $company_settings['phone'] = $columns['phone'];
            }
            
            if (isset($columns['eposta'])) {
                $company_settings['email'] = $columns['eposta'];
            } elseif (isset($columns['email'])) {
                $company_settings['email'] = $columns['email'];
            }
            
            if (isset($columns['website'])) {
                $company_settings['website'] = $columns['website'];
            }
            
            if (isset($columns['vergi_dairesi'])) {
                $company_settings['tax_office'] = $columns['vergi_dairesi'];
            } elseif (isset($columns['tax_office'])) {
                $company_settings['tax_office'] = $columns['tax_office'];
            }
            
            if (isset($columns['vergi_no'])) {
                $company_settings['tax_number'] = $columns['vergi_no'];
            } elseif (isset($columns['tax_number'])) {
                $company_settings['tax_number'] = $columns['tax_number'];
            }
            
            if (isset($columns['primary_color'])) {
                $company_settings['primary_color'] = $columns['primary_color'];
            } elseif (isset($columns['tema_rengi'])) {
                $company_settings['primary_color'] = $columns['tema_rengi'];
            }
            
            if (isset($columns['banka_adi'])) {
                $company_settings['bank_name'] = $columns['banka_adi'];
            } elseif (isset($columns['bank_name'])) {
                $company_settings['bank_name'] = $columns['bank_name'];
            }
            
            if (isset($columns['hesap_sahibi'])) {
                $company_settings['account_holder'] = $columns['hesap_sahibi'];
            } elseif (isset($columns['account_holder'])) {
                $company_settings['account_holder'] = $columns['account_holder'];
            }
            
            if (isset($columns['iban'])) {
                $company_settings['iban'] = $columns['iban'];
            }
        }
    }
} catch(PDOException $e) {
    // Hata durumunda varsayılan değerleri kullan (zaten yukarıda tanımlandı)
}

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
    // Şirket ayarları için değişken
    protected $company;
    
    // Şirket ayarlarını ayarla
    public function setCompanySettings($settings) {
        $this->company = $settings;
    }
    
    // Sayfa başlığı
    public function Header() {
        // Logo
        $image_file = 'assets/img/logo.png';
        if (file_exists($image_file)) {
            $this->Image($image_file, 10, 10, 50, '', 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        }
        
        // Başlık
        $this->SetFont('dejavusans', 'B', 20);
        $this->SetY(15);
        $this->Cell(0, 15, 'TEKLİF', 0, false, 'R', 0, '', 0, false, 'M', 'M');
        
        // Alt başlık
        $this->SetFont('dejavusans', 'I', 10);
        $this->SetY(23);
        $this->Cell(0, 10, $this->company['company_slogan'], 0, false, 'R', 0, '', 0, false, 'M', 'M');
        
        // Şirket bilgisi
        $this->SetFont('dejavusans', '', 8);
        $this->SetY(30);
        $company_info = $this->company['company_name'] . ' | ' . $this->company['address'] . ' | Tel: ' . $this->company['phone'];
        $this->Cell(0, 10, $company_info, 0, false, 'R', 0, '', 0, false, 'M', 'M');
        
        // Çizgi
        $this->Line(10, 40, $this->getPageWidth() - 10, 40);
    }

    // Sayfa altlığı
    public function Footer() {
        // Çizgi
        $this->Line(10, $this->getPageHeight() - 20, $this->getPageWidth() - 10, $this->getPageHeight() - 20);
        
        // Footer bilgileri
        $this->SetY(-18);
        $this->SetFont('dejavusans', '', 8);
        $this->Cell(0, 10, $this->company['company_name'] . ' - ' . $this->company['tax_office'] . ' / ' . $this->company['tax_number'], 0, false, 'L', 0, '', 0, false, 'T', 'M');
        
        // Web sitesi ve e-posta
        $this->SetY(-14);
        $this->Cell(0, 10, $this->company['website'] . ' | ' . $this->company['email'], 0, false, 'L', 0, '', 0, false, 'T', 'M');
        
        // Sayfa numarası
        $this->SetY(-18);
        $this->SetFont('dejavusans', 'I', 8);
        $this->Cell(0, 10, 'Sayfa '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
    }
}

// PDF dokümanı oluştur
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Şirket ayarlarını sınıfa aktar
$pdf->setCompanySettings($company_settings);

// Doküman bilgilerini ayarla
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($company_settings['company_name']);
$pdf->SetTitle('Teklif: ' . $quotation['reference_no']);
$pdf->SetSubject('Teklif');
$pdf->SetKeywords('Teklif, PDF, ' . $company_settings['company_name']);

// Başlık ve altlık ayarlarını etkinleştir
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

// Kenar boşluklarını ayarla
$pdf->SetMargins(PDF_MARGIN_LEFT, 45, PDF_MARGIN_RIGHT);
$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
$pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

// Otomatik sayfa kesmelerini ayarla
$pdf->SetAutoPageBreak(TRUE, 25);

// Ölçeklendirme faktörlerini ayarla
$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

// Türkçe karakterler için yazı tipini ayarla
$pdf->SetFont('dejavusans', '', 10);

// Yeni sayfa ekle
$pdf->AddPage();

// Referans numarası ve tarih bilgileri için üst kutucuk
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%; margin-top: 5px; margin-bottom: 15px;">
    <tr>
        <td width="40%" style="background-color: ' . $company_settings['primary_color'] . '; color: #fff; border-radius: 5px 0 0 5px; padding: 12px; font-size: 16px; font-weight: bold;">
            TEKLİF NO: ' . $quotation['reference_no'] . '
        </td>
        <td width="60%" style="background-color: #f9f9f9; border-radius: 0 5px 5px 0; text-align: right; padding: 12px; font-size: 14px;">
            <strong>Tarih:</strong> ' . date('d.m.Y', strtotime($quotation['date'])) . ' | 
            <strong>Geçerlilik:</strong> ' . date('d.m.Y', strtotime($quotation['valid_until'])) . '
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Teklif ve müşteri bilgileri tablosu oluştur - Modern görünüm
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%;">
    <tr>
        <td width="48%" style="background-color: #f9f9f9; border-radius: 5px; padding: 10px; vertical-align: top;">
            <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">FİRMA BİLGİLERİ</h3>
            <p style="margin: 5px 0; line-height: 1.5em;">
                <strong>' . $company_settings['company_name'] . '</strong><br>
                ' . nl2br($company_settings['address']) . '<br>
                <strong>Telefon:</strong> ' . $company_settings['phone'] . '<br>
                <strong>E-posta:</strong> ' . $company_settings['email'] . '<br>
                <strong>Vergi D./No:</strong> ' . $company_settings['tax_office'] . ' / ' . $company_settings['tax_number'] . '
            </p>
        </td>
        <td width="4%">&nbsp;</td>
        <td width="48%" style="background-color: #f9f9f9; border-radius: 5px; padding: 10px; vertical-align: top;">
            <h3 style="margin: 0 0 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">MÜŞTERİ BİLGİLERİ</h3>
            <p style="margin: 5px 0; line-height: 1.5em;">
                <strong>' . $quotation['customer_name'] . '</strong><br>
                <strong>İlgili Kişi:</strong> ' . $quotation['contact_person'] . '<br>
                <strong>Adres:</strong> ' . nl2br($quotation['customer_address']) . '<br>
                <strong>Telefon:</strong> ' . $quotation['customer_phone'] . '<br>
                <strong>E-posta:</strong> ' . $quotation['customer_email'] . '<br>
                <strong>Vergi D./No:</strong> ' . $quotation['tax_office'] . ' / ' . $quotation['tax_number'] . '
            </p>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Teklifi hazırlayan bilgisi
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%; margin-top: 15px;">
    <tr>
        <td width="100%" style="background-color: #f9f9f9; border-radius: 5px; padding: 10px;">
            <strong>Teklifi Hazırlayan:</strong> ' . $quotation['user_name'] . 
            ' | <strong>E-posta:</strong> ' . $quotation['user_email'] . '
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Biraz boşluk ekle
$pdf->Ln(5);

// Teklif kalemleri tablosu oluştur - Geliştirilmiş görünüm
$html = '<h3 style="margin: 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">TEKLİF KALEMLERİ</h3>
<table cellspacing="0" cellpadding="8" border="0" style="width: 100%; border-collapse: collapse;">
    <tr style="background-color: ' . $company_settings['primary_color'] . '; color: #fff; font-weight: bold;">
        <th width="5%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">S.No</th>
        <th width="10%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Tür</th>
        <th width="10%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Kod</th>
        <th width="30%" align="left" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Açıklama</th>
        <th width="7%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Miktar</th>
        <th width="10%" align="right" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Birim Fiyat</th>
        <th width="7%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">İnd. %</th>
        <th width="10%" align="right" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">Ara Toplam</th>
        <th width="5%" align="center" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">KDV %</th>
        <th width="10%" align="right" style="border: 1px solid #ddd; border-bottom: 2px solid #ddd;">KDV Dahil</th>
    </tr>';

$counter = 1;
$total_items = count($items);

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
    
    // Son satır için alt kenar vurgusu
    $last_row_style = ($counter == $total_items) ? 'border-bottom: 2px solid #ddd;' : '';

    $html .= '<tr' . ($counter % 2 == 0 ? ' style="background-color: #f9f9f9;"' : '') . '>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $counter . '</td>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $item_type . '</td>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $item['item_code'] . '</td>
        <td style="border: 1px solid #ddd; ' . $last_row_style . '">' . $item['description'] . '</td>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $quantity . '</td>
        <td align="right" style="border: 1px solid #ddd; ' . $last_row_style . '">' . number_format($unit_price, 2, ',', '.') . ' ₺</td>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $discount_percent . '%</td>
        <td align="right" style="border: 1px solid #ddd; ' . $last_row_style . '">' . number_format($subtotal, 2, ',', '.') . ' ₺</td>
        <td align="center" style="border: 1px solid #ddd; ' . $last_row_style . '">' . $tax_rate . '%</td>
        <td align="right" style="border: 1px solid #ddd; ' . $last_row_style . '">' . number_format($total_with_tax, 2, ',', '.') . ' ₺</td>
    </tr>';
    $counter++;
}

$html .= '</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Toplam tablosu - Geliştirilmiş görünüm
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 35%; margin-top: 15px; margin-left: 65%;">
    <tr>
        <td width="50%" style="background-color: #f9f9f9; border: 1px solid #ddd; font-weight: bold;">
            Ara Toplam:
        </td>
        <td width="50%" style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['subtotal'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: #f9f9f9; border: 1px solid #ddd; font-weight: bold;">
            İndirim:
        </td>
        <td style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['discount_amount'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: #f9f9f9; border: 1px solid #ddd; font-weight: bold;">
            KDV:
        </td>
        <td style="border: 1px solid #ddd;" align="right">
            ' . number_format($quotation['tax_amount'], 2, ',', '.') . ' ₺
        </td>
    </tr>
    <tr>
        <td style="background-color: ' . $company_settings['primary_color'] . '; color: #fff; border: 1px solid #ddd; font-weight: bold; font-size: 14px;">
            GENEL TOPLAM:
        </td>
        <td style="border: 1px solid #ddd; background-color: #f9f9f9; font-weight: bold; font-size: 14px;" align="right">
            ' . number_format($quotation['total_amount'], 2, ',', '.') . ' ₺
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// Biraz boşluk ekle
$pdf->Ln(10);

// Ödeme Bilgileri Ekle
if (!empty($company_settings['bank_account']) || !empty($company_settings['iban'])) {
    $html = '<h3 style="margin: 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">ÖDEME BİLGİLERİ</h3>
    <table cellspacing="0" cellpadding="8" border="0" style="width: 100%; background-color: #f9f9f9; border-radius: 5px;">
        <tr>
            <td>
                <strong>Banka:</strong> ' . $company_settings['bank_name'] . '<br>
                <strong>Hesap Sahibi:</strong> ' . $company_settings['account_holder'] . '<br>
                <strong>IBAN:</strong> ' . $company_settings['iban'] . '
            </td>
        </tr>
    </table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Biraz boşluk ekle
    $pdf->Ln(5);
}

// Notlar ve şartlar alanı - Geliştirilmiş görünüm
if (!empty($quotation['notes']) || !empty($quotation['terms_conditions'])) {
    if (!empty($quotation['notes'])) {
        $html = '<h3 style="margin: 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">NOTLAR</h3>
        <table cellspacing="0" cellpadding="8" border="0" style="width: 100%; background-color: #f9f9f9; border-radius: 5px;">
            <tr>
                <td>' . nl2br($quotation['notes']) . '</td>
            </tr>
        </table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Biraz boşluk ekle
        $pdf->Ln(5);
    }
    
    if (!empty($quotation['terms_conditions'])) {
        $html = '<h3 style="margin: 10px 0; padding-bottom: 5px; border-bottom: 2px solid ' . $company_settings['primary_color'] . ';">ŞARTLAR VE KOŞULLAR</h3>
        <table cellspacing="0" cellpadding="8" border="0" style="width: 100%; background-color: #f9f9f9; border-radius: 5px;">
            <tr>
                <td>' . nl2br($quotation['terms_conditions']) . '</td>
            </tr>
        </table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}

// İmza alanları - Geliştirilmiş görünüm
$html = '<table cellspacing="0" cellpadding="8" border="0" style="width: 100%; margin-top: 30px;">
    <tr>
        <td width="45%" style="border-top: 1px solid #000; padding-top: 10px;" align="center">
            <img src="assets/img/company_signature.png" style="max-height: 50px;"><br>
            <strong style="font-size: 14px;">' . $quotation['user_name'] . '</strong><br>
            <span style="font-size: 12px;">Teklifi Veren</span>
        </td>
        <td width="10%">&nbsp;</td>
        <td width="45%" style="border-top: 1px solid #000; padding-top: 10px;" align="center">
            <div style="height: 50px;"></div>
            <strong style="font-size: 14px;">' . $quotation['contact_person'] . '</strong><br>
            <span style="font-size: 12px;">Teklifi Kabul Eden</span>
        </td>
    </tr>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

// PDF dosyasını çıktıla
$pdf->Output('Teklif_' . $quotation['reference_no'] . '.pdf', 'I');
?>