<?php
// send_quotation_email.php - Teklif e-posta gönderme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/email.php';
require_once 'vendor/autoload.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz teklif ID\'si.');
    header("Location: quotations.php");
    exit;
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
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Müşteri ve kullanıcı bilgilerini ayır
    $customer = [
        'name' => $quotation['customer_name'],
        'contact_person' => $quotation['contact_person'],
        'email' => $quotation['customer_email'],
        'phone' => $quotation['customer_phone'],
        'address' => $quotation['customer_address'],
        'tax_office' => $quotation['tax_office'],
        'tax_number' => $quotation['tax_number']
    ];
    
    $user = [
        'name' => $quotation['user_name'],
        'email' => $quotation['user_email']
    ];
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: quotations.php");
    exit;
}

// E-posta gönderme işlemi
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $to = trim($_POST['to_email']);
    $cc = isset($_POST['cc_email']) ? trim($_POST['cc_email']) : '';
    $bcc = isset($_POST['bcc_email']) ? trim($_POST['bcc_email']) : '';
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    $attachPdf = isset($_POST['attach_pdf']) && $_POST['attach_pdf'] == 1;
    
    // E-posta validasyonu
    $errors = [];
    
    if (empty($to)) {
        $errors[] = "Alıcı e-posta adresi zorunludur.";
    } elseif (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Geçersiz alıcı e-posta adresi.";
    }
    
    if (!empty($cc)) {
        $ccEmails = explode(',', $cc);
        foreach ($ccEmails as $ccEmail) {
            if (!filter_var(trim($ccEmail), FILTER_VALIDATE_EMAIL)) {
                $errors[] = "CC'de geçersiz e-posta adresi: " . $ccEmail;
                break;
            }
        }
    }
    
    if (!empty($bcc)) {
        $bccEmails = explode(',', $bcc);
        foreach ($bccEmails as $bccEmail) {
            if (!filter_var(trim($bccEmail), FILTER_VALIDATE_EMAIL)) {
                $errors[] = "BCC'de geçersiz e-posta adresi: " . $bccEmail;
                break;
            }
        }
    }
    
    if (empty($subject)) {
        $errors[] = "E-posta konusu zorunludur.";
    }
    
    // Hata yoksa e-postayı gönder
    if (empty($errors)) {
        // E-posta şablonunu hazırla
        $emailBody = prepareQuotationEmailTemplate($quotation, $customer, $user, $message);
        
        // Ekler
        $attachments = [];
        
        // PDF ekle
        if ($attachPdf) {
            // Geçici bir PDF dosyası oluştur
            $pdfTempFile = tempnam(sys_get_temp_dir(), 'quotation_');
            $pdfFileName = 'Teklif_' . $quotation['reference_no'] . '.pdf';
            
            // PDF'i oluştur ve kaydet
            // TCPDF'yi dahil et
            require_once('tcpdf/tcpdf.php');
            
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

            // Şirket bilgilerini al
            $settings = getCompanySettings();

            // Teklif ve müşteri bilgileri tablosu oluştur
            $html = '<table cellspacing="0" cellpadding="5" border="0" style="width: 100%;">
                <tr>
                    <td width="50%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
                        <h4 style="margin: 0;">FİRMA BİLGİLERİ</h4>
                        <p style="margin: 5px 0;">
                            <strong>Firma Adı:</strong> ' . $settings['company_name'] . '<br>
                            <strong>Adres:</strong> ' . $settings['company_address'] . '<br>
                            <strong>Telefon:</strong> ' . $settings['company_phone'] . '<br>
                            <strong>E-posta:</strong> ' . $settings['company_email'] . '<br>
                            <strong>Vergi Dairesi / No:</strong> ' . $settings['company_tax_office'] . ' / ' . $settings['company_tax_number'] . '
                        </p>
                    </td>
                    <td width="50%" style="background-color: #f2f2f2; border: 1px solid #ddd;">
                        <h4 style="margin: 0;">MÜŞTERİ BİLGİLERİ</h4>
                        <p style="margin: 5px 0;">
                            <strong>Firma Adı:</strong> ' . $customer['name'] . '<br>
                            <strong>İlgili Kişi:</strong> ' . $customer['contact_person'] . '<br>
                            <strong>Adres:</strong> ' . $customer['address'] . '<br>
                            <strong>Telefon:</strong> ' . $customer['phone'] . '<br>
                            <strong>E-posta:</strong> ' . $customer['email'] . '<br>
                            <strong>Vergi Dairesi / No:</strong> ' . $customer['tax_office'] . ' / ' . $customer['tax_number'] . '
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
                        ' . $user['name'] . '
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
                    <td align="center">' . $user['name'] . '</td>
                    <td>&nbsp;</td>
                    <td align="center">' . $customer['contact_person'] . '</td>
                </tr>
            </table>';

            $pdf->writeHTML($html, true, false, true, false, '');

            // PDF dosyasını kaydet
            $pdf->Output($pdfTempFile, 'F');
            
            // Ek olarak ekle
            $attachments[] = [
                'path' => $pdfTempFile, 
                'name' => $pdfFileName
            ];
        }
        
        // E-postayı gönder
        $result = sendEmail($to, $subject, $emailBody, $attachments, $cc, $bcc);
        
        // Ekleri temizle
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['path'])) {
                unlink($attachment['path']);
            }
        }
        
        if ($result['success']) {
            // Teklif durumunu güncelle
            if ($quotation['status'] == 'draft') {
                $stmt = $conn->prepare("UPDATE quotations SET status = 'sent' WHERE id = :id");
                $stmt->bindParam(':id', $quotation_id);
                $stmt->execute();
            }
            
            setMessage('success', 'Teklif e-postası başarıyla gönderildi.');
            header("Location: view_quotation.php?id=" . $quotation_id);
            exit;
        } else {
            $errors[] = "E-posta gönderme hatası: " . $result['message'];
        }
    }
}
$pageTitle = 'Teklif E-posta Gönder';
$currentPage = 'quotations';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">
                    Teklif E-posta Gönder:
                    <span class="text-primary"><?php echo htmlspecialchars($quotation['reference_no']); ?></span>
                    <?php
                    $statusClass = 'secondary';
                    $statusText = 'Taslak';
                    
                    switch($quotation['status']) {
                        case 'sent':
                            $statusClass = 'primary';
                            $statusText = 'Gönderildi';
                            break;
                        case 'accepted':
                            $statusClass = 'success';
                            $statusText = 'Kabul Edildi';
                            break;
                        case 'rejected':
                            $statusClass = 'danger';
                            $statusText = 'Reddedildi';
                            break;
                        case 'expired':
                            $statusClass = 'warning';
                            $statusText = 'Süresi Doldu';
                            break;
                    }
                    ?>
                    <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                        <?php echo $statusText; ?>
                    </span>
                </h1>
                <a href="view_quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Teklife Dön
                </a>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <!-- Teklif Bilgileri -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Teklif No:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($quotation['reference_no']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Müşteri:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($quotation['customer_name']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">İlgili Kişi:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($quotation['contact_person']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Tarih:</div>
                                <div class="col-sm-8"><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Geçerlilik:</div>
                                <div class="col-sm-8"><?php echo date('d.m.Y', strtotime($quotation['valid_until'])); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Toplam Tutar:</div>
                                <div class="col-sm-8"><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Müşteri Bilgileri -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Müşteri Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Firma Adı:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($customer['name']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">İlgili Kişi:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($customer['contact_person']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">E-posta:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($customer['email']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Telefon:</div>
                                <div class="col-sm-8"><?php echo htmlspecialchars($customer['phone']); ?></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4 fw-bold">Adres:</div>
                                <div class="col-sm-8"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <!-- E-posta Gönderme Formu -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">E-posta Gönder</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $quotation_id); ?>">
                                <div class="mb-3">
                                    <label for="to_email" class="form-label">Alıcı <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="to_email" name="to_email" required 
                                           value="<?php echo htmlspecialchars($customer['email']); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="cc_email" class="form-label">CC</label>
                                    <input type="text" class="form-control" id="cc_email" name="cc_email" 
                                           placeholder="aaa@example.com, bbb@example.com">
                                    <div class="form-text">Birden fazla e-posta adresini virgülle ayırın.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="bcc_email" class="form-label">BCC</label>
                                    <input type="text" class="form-control" id="bcc_email" name="bcc_email"
                                           value="<?php echo htmlspecialchars($user['email']); ?>">
                                    <div class="form-text">Birden fazla e-posta adresini virgülle ayırın.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="subject" class="form-label">Konu <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="subject" name="subject" required
                                           value="Teklif: <?php echo htmlspecialchars($quotation['reference_no']); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="message" class="form-label">Mesaj</label>
                                    <textarea class="form-control" id="message" name="message" rows="5"
                                              placeholder="Müşteriye iletmek istediğiniz ek bilgileri buraya yazabilirsiniz."><?php echo isset($message) ? htmlspecialchars($message) : ''; ?></textarea>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="attach_pdf" name="attach_pdf" value="1" checked>
                                    <label class="form-check-label" for="attach_pdf">Teklif PDF dosyasını ekle</label>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Teklif bilgileri, şirket bilgileri ve teklif durumu e-posta içeriğinde otomatik olarak yer alacaktır.
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="view_quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-secondary me-md-2">İptal</a>
                                    <button type="submit" class="btn btn-primary">E-posta Gönder</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>