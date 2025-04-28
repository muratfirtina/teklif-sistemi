<?php
// includes/email.php - E-posta gönderme fonksiyonları
// PHPMailer kütüphanesini dahil et
require_once 'vendor/autoload.php';
    
// PHPMailer sınıflarını kullan
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
/**
 * PHPMailer kütüphanesini kullanarak e-posta gönderir
 * 
 * @param string $to        Alıcı e-posta adresi
 * @param string $subject   E-posta konusu
 * @param string $body      E-posta içeriği (HTML)
 * @param array  $attachments E-postaya eklenecek dosyalar [['path' => 'dosya/yolu', 'name' => 'dosya_adi']]
 * @param string $cc        CC alıcıları (isteğe bağlı)
 * @param string $bcc       BCC alıcıları (isteğe bağlı)
 * @return array ['success' => true/false, 'message' => 'hata mesajı/başarı mesajı']
 */
function sendEmail($to, $subject, $body, $attachments = [], $cc = '', $bcc = '') {
    // E-posta ayarlarını al
    $settings = getEmailSettings();
    
    
    
    // Yeni bir PHPMailer örneği oluştur
    $mail = new PHPMailer(true);
    
    try {
        // Sunucu ayarları
        if ($settings['smtp_enabled'] == 1) {
            $mail->isSMTP();
            $mail->Host = $settings['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $settings['smtp_username'];
            $mail->Password = $settings['smtp_password'];
            $mail->SMTPSecure = $settings['smtp_encryption'];
            $mail->Port = $settings['smtp_port'];
        }
        
        // Genel e-posta ayarları
        $mail->setFrom($settings['from_email'], $settings['from_name']);
        $mail->addAddress($to);
        
        // CC ve BCC ekle (varsa)
        if (!empty($cc)) {
            $ccAddresses = explode(',', $cc);
            foreach ($ccAddresses as $ccAddress) {
                $mail->addCC(trim($ccAddress));
            }
        }
        
        if (!empty($bcc)) {
            $bccAddresses = explode(',', $bcc);
            foreach ($bccAddresses as $bccAddress) {
                $mail->addBCC(trim($bccAddress));
            }
        }
        
        // Ekleri ekle (varsa)
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment['path'])) {
                    $mail->addAttachment($attachment['path'], $attachment['name']);
                }
            }
        }
        
        // E-posta içeriği
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body));
        
        // E-postayı gönder
        $mail->send();
        
        return [
            'success' => true,
            'message' => 'E-posta başarıyla gönderildi.'
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'E-posta gönderilirken bir hata oluştu: ' . $mail->ErrorInfo
        ];
    }
}

/**
 * E-posta ayarlarını veritabanından alır
 * 
 * @return array E-posta ayarları
 */
function getEmailSettings() {
    // Veritabanı bağlantısı
    $conn = getDbConnection();
    
    // Varsayılan ayarlar
    $defaults = [
        'smtp_enabled' => 0,
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_username' => 'username@example.com',
        'smtp_password' => 'password',
        'smtp_encryption' => 'tls',
        'from_email' => 'info@example.com',
        'from_name' => 'Teklif Yönetim Sistemi',
        'email_signature' => '<p>Saygılarımızla,<br>Teklif Yönetim Sistemi</p>'
    ];
    
    // Ayarlar tablosu var mı kontrol et
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'settings'");
        if ($stmt->rowCount() == 0) {
            return $defaults;
        }
        
        // E-posta ayarlarını al
        $settings = [];
        foreach ($defaults as $key => $defaultValue) {
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
            $stmt->bindParam(':key', $key);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $settings[$key] = $stmt->fetchColumn();
            } else {
                $settings[$key] = $defaultValue;
            }
        }
        
        return $settings;
    } catch (PDOException $e) {
        // Hata durumunda varsayılan ayarları döndür
        return $defaults;
    }
}

/**
 * Teklif gönderme için e-posta şablonunu hazırlar
 * 
 * @param array $quotation Teklif bilgileri
 * @param array $customer Müşteri bilgileri
 * @param array $user Kullanıcı bilgileri
 * @param string $message Özel mesaj (isteğe bağlı)
 * @return string HTML formatında e-posta içeriği
 */
function prepareQuotationEmailTemplate($quotation, $customer, $user, $message = '') {
    // Şirket bilgilerini al
    $settings = getCompanySettings();
    
    // E-posta imzasını al
    $emailSettings = getEmailSettings();
    $signature = $emailSettings['email_signature'];
    
    // Teklif durumu metni
    $statusText = [
        'draft' => 'Taslak',
        'sent' => 'Gönderildi',
        'accepted' => 'Kabul Edildi',
        'rejected' => 'Reddedildi',
        'expired' => 'Süresi Doldu'
    ];
    
    // E-posta şablonu
    $template = '
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Teklif: ' . $quotation['reference_no'] . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                margin: 0;
                padding: 0;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .logo {
                max-width: 200px;
                max-height: 80px;
            }
            .content {
                margin-bottom: 30px;
            }
            .quote-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .quote-info p {
                margin: 5px 0;
            }
            .message {
                background-color: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                border-left: 4px solid #007bff;
            }
            .footer {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
                font-size: 12px;
                color: #777;
            }
            .btn {
                display: inline-block;
                padding: 10px 15px;
                background-color: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                margin-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h2>' . $settings['company_name'] . '</h2>
            </div>
            
            <div class="content">
                <p>Sayın ' . $customer['name'] . ',</p>
                
                ' . (!empty($message) ? '<div class="message">' . nl2br($message) . '</div>' : '') . '
                
                <p>' . $settings['company_name'] . ' olarak hazırlamış olduğumuz teklifimizi ekte bulabilirsiniz.</p>
                
                <div class="quote-info">
                    <h3>Teklif Bilgileri</h3>
                    <p><strong>Teklif No:</strong> ' . $quotation['reference_no'] . '</p>
                    <p><strong>Tarih:</strong> ' . date('d.m.Y', strtotime($quotation['date'])) . '</p>
                    <p><strong>Son Geçerlilik:</strong> ' . date('d.m.Y', strtotime($quotation['valid_until'])) . '</p>
                    <p><strong>Toplam Tutar:</strong> ' . number_format($quotation['total_amount'], 2, ',', '.') . ' ₺</p>
                    <p><strong>Durum:</strong> ' . $statusText[$quotation['status']] . '</p>
                </div>
                
                <p>Teklifimiz hakkında herhangi bir sorunuz varsa, lütfen bizimle iletişime geçmekten çekinmeyin.</p>
                
                ' . $signature . '
            </div>
            
            <div class="footer">
                <p>' . $settings['company_name'] . ' - ' . $settings['company_address'] . '</p>
                <p>Tel: ' . $settings['company_phone'] . ' | E-posta: ' . $settings['company_email'] . ' | Web: ' . $settings['company_website'] . '</p>
            </div>
        </div>
    </body>
    </html>
    ';
    
    return $template;
}

/**
 * Şirket ayarlarını veritabanından alır
 * 
 * @return array Şirket ayarları
 */
function getCompanySettings() {
    // Veritabanı bağlantısı
    $conn = getDbConnection();
    
    // Varsayılan ayarlar
    $defaults = [
        'company_name' => 'Şirketiniz',
        'company_address' => 'Şirket Adresi',
        'company_phone' => 'Şirket Telefonu',
        'company_email' => 'info@sirketiniz.com',
        'company_website' => 'www.sirketiniz.com',
        'company_tax_office' => 'Vergi Dairesi',
        'company_tax_number' => 'Vergi Numarası'
    ];
    
    // Ayarlar tablosu var mı kontrol et
    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'settings'");
        if ($stmt->rowCount() == 0) {
            return $defaults;
        }
        
        // Şirket ayarlarını al
        $settings = [];
        foreach ($defaults as $key => $defaultValue) {
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
            $stmt->bindParam(':key', $key);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $settings[$key] = $stmt->fetchColumn();
            } else {
                $settings[$key] = $defaultValue;
            }
        }
        
        return $settings;
    } catch (PDOException $e) {
        // Hata durumunda varsayılan ayarları döndür
        return $defaults;
    }
}
?>