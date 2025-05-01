<?php
// settings.php - Sistem ayarları sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece admin erişebilir
requireAdmin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Hata ayıklama
$debugLog = [];
$errorOccurred = false;

// Ayarlar tablosunu oluştur (yoksa)
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT,
        setting_description VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Banka hesapları tablosunu oluştur (yoksa)
    $conn->exec("CREATE TABLE IF NOT EXISTS bank_accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bank_name VARCHAR(100) NOT NULL,
        account_holder VARCHAR(100) NOT NULL,
        iban VARCHAR(50) NOT NULL,
        branch_name VARCHAR(100),
        branch_code VARCHAR(50),
        account_number VARCHAR(50),
        swift_code VARCHAR(50),
        currency VARCHAR(10) DEFAULT 'TRY',
        is_default BOOLEAN DEFAULT FALSE,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Varsayılan ayarları ekle (eğer yoksa)
    $defaults = [
        'company_name' => ['value' => 'Şirketiniz', 'description' => 'Şirket adı'],
        'company_address' => ['value' => 'Şirket Adresi', 'description' => 'Şirket adresi'],
        'company_phone' => ['value' => 'Şirket Telefonu', 'description' => 'Şirket telefon numarası'],
        'company_email' => ['value' => 'info@sirketiniz.com', 'description' => 'Şirket e-posta adresi'],
        'company_website' => ['value' => 'www.sirketiniz.com', 'description' => 'Şirket web sitesi'],
        'company_tax_office' => ['value' => 'Vergi Dairesi', 'description' => 'Şirket vergi dairesi'],
        'company_tax_number' => ['value' => 'Vergi Numarası', 'description' => 'Şirket vergi numarası'],
        'default_vat' => ['value' => '18', 'description' => 'Varsayılan KDV oranı (%)'],
        'default_currency' => ['value' => '₺', 'description' => 'Varsayılan para birimi'],
        'quotation_prefix' => ['value' => 'TEK', 'description' => 'Teklif numarası öneki'],
        'quotation_terms' => ['value' => "1. Bu teklif 30 gün geçerlidir.\n2. Fiyatlara KDV dahildir.\n3. Ödeme şartları: %50 peşin, %50 teslimat öncesi.\n4. Teslimat süresi: Sipariş onayından itibaren 10 iş günüdür.", 'description' => 'Varsayılan teklif şartları ve koşulları'],
        'show_bank_accounts' => ['value' => '1', 'description' => 'Tekliflerde banka hesap bilgilerini göster']
    ];
    
    foreach ($defaults as $key => $data) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = :key");
        $stmt->bindParam(':key', $key);
        $stmt->execute();
        
        if ($stmt->fetchColumn() == 0) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value, setting_description) VALUES (:key, :value, :description)");
            $stmt->bindParam(':key', $key);
            $stmt->bindParam(':value', $data['value']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->execute();
        }
    }
} catch(PDOException $e) {
    $errorOccurred = true;
    $debugLog[] = "Ayarlar tablosu hatası: " . $e->getMessage();
    setMessage('error', 'Ayarlar tablosu oluşturulurken bir hata oluştu: ' . $e->getMessage());
}

// Ayarları yükle
$settings = [];
try {
    $stmt = $conn->query("SELECT * FROM settings ORDER BY setting_key ASC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row;
    }
} catch(PDOException $e) {
    $errorOccurred = true;
    $debugLog[] = "Ayarlar yükleme hatası: " . $e->getMessage();
    setMessage('error', 'Ayarlar yüklenirken bir hata oluştu: ' . $e->getMessage());
}

// Banka hesaplarını yükle
$bankAccounts = [];
try {
    $stmt = $conn->query("SELECT * FROM bank_accounts ORDER BY is_default DESC, bank_name ASC");
    $bankAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $errorOccurred = true;
    $debugLog[] = "Banka hesapları yükleme hatası: " . $e->getMessage();
    setMessage('error', 'Banka hesapları yüklenirken bir hata oluştu: ' . $e->getMessage());
}

// Ayarları güncelle
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Debug için POST verilerini kaydet
    $debugLog[] = "POST verileri: " . print_r($_POST, true);
    
    try {
        $conn->beginTransaction();
        
        // Banka hesabı işlemleri
        if (isset($_POST['action'])) {
            // Yeni banka hesabı ekle
            if ($_POST['action'] == 'add_bank_account') {
                $debugLog[] = "Banka hesabı ekleme işlemi başlatıldı";
                
                // Zorunlu alanları kontrol et
                if (empty($_POST['bank_name']) || empty($_POST['account_holder']) || empty($_POST['iban'])) {
                    throw new Exception("Banka adı, hesap sahibi ve IBAN alanları zorunludur.");
                }
                
                // Eski varsayılan hesabı güncelle (eğer yeni hesap varsayılan olarak işaretlendiyse)
                if (isset($_POST['is_default']) && $_POST['is_default'] == 1) {
                    $stmt = $conn->prepare("UPDATE bank_accounts SET is_default = 0");
                    $stmt->execute();
                    $debugLog[] = "Tüm hesapların varsayılan değeri sıfırlandı";
                }
                
                $stmt = $conn->prepare("INSERT INTO bank_accounts 
                    (bank_name, account_holder, iban, branch_name, branch_code, account_number, swift_code, currency, is_default, is_active) 
                    VALUES (:bank_name, :account_holder, :iban, :branch_name, :branch_code, :account_number, :swift_code, :currency, :is_default, :is_active)");
                
                $default = isset($_POST['is_default']) ? 1 : 0;
                $active = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt->bindParam(':bank_name', $_POST['bank_name']);
                $stmt->bindParam(':account_holder', $_POST['account_holder']);
                $stmt->bindParam(':iban', $_POST['iban']);
                $stmt->bindParam(':branch_name', $_POST['branch_name']);
                $stmt->bindParam(':branch_code', $_POST['branch_code']);
                $stmt->bindParam(':account_number', $_POST['account_number']);
                $stmt->bindParam(':swift_code', $_POST['swift_code']);
                $stmt->bindParam(':currency', $_POST['currency']);
                $stmt->bindParam(':is_default', $default, PDO::PARAM_INT);
                $stmt->bindParam(':is_active', $active, PDO::PARAM_INT);
                
                $result = $stmt->execute();
                $debugLog[] = "SQL sorgusu çalıştırıldı. Sonuç: " . ($result ? "Başarılı" : "Başarısız");
                $debugLog[] = "Son eklenen ID: " . $conn->lastInsertId();
                
                $conn->commit();
                $debugLog[] = "Transaction commit edildi";
                
                setMessage('success', 'Yeni banka hesabı başarıyla eklendi.');
                header("Location: settings.php#bank-accounts");
                exit;
            }
            
            // Banka hesabını düzenle
            if ($_POST['action'] == 'edit_bank_account' && isset($_POST['bank_id'])) {
                $debugLog[] = "Banka hesabı düzenleme işlemi başlatıldı";
                
                // Zorunlu alanları kontrol et
                if (empty($_POST['bank_name']) || empty($_POST['account_holder']) || empty($_POST['iban'])) {
                    throw new Exception("Banka adı, hesap sahibi ve IBAN alanları zorunludur.");
                }
                
                // Eski varsayılan hesabı güncelle (eğer bu hesap varsayılan olarak işaretlendiyse)
                if (isset($_POST['is_default']) && $_POST['is_default'] == 1) {
                    $stmt = $conn->prepare("UPDATE bank_accounts SET is_default = 0");
                    $stmt->execute();
                    $debugLog[] = "Tüm hesapların varsayılan değeri sıfırlandı";
                }
                
                $stmt = $conn->prepare("UPDATE bank_accounts SET 
                    bank_name = :bank_name,
                    account_holder = :account_holder,
                    iban = :iban,
                    branch_name = :branch_name,
                    branch_code = :branch_code,
                    account_number = :account_number,
                    swift_code = :swift_code,
                    currency = :currency,
                    is_default = :is_default,
                    is_active = :is_active
                    WHERE id = :id");
                
                $default = isset($_POST['is_default']) ? 1 : 0;
                $active = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt->bindParam(':bank_name', $_POST['bank_name']);
                $stmt->bindParam(':account_holder', $_POST['account_holder']);
                $stmt->bindParam(':iban', $_POST['iban']);
                $stmt->bindParam(':branch_name', $_POST['branch_name']);
                $stmt->bindParam(':branch_code', $_POST['branch_code']);
                $stmt->bindParam(':account_number', $_POST['account_number']);
                $stmt->bindParam(':swift_code', $_POST['swift_code']);
                $stmt->bindParam(':currency', $_POST['currency']);
                $stmt->bindParam(':is_default', $default, PDO::PARAM_INT);
                $stmt->bindParam(':is_active', $active, PDO::PARAM_INT);
                $stmt->bindParam(':id', $_POST['bank_id'], PDO::PARAM_INT);
                
                $result = $stmt->execute();
                $debugLog[] = "SQL sorgusu çalıştırıldı. Sonuç: " . ($result ? "Başarılı" : "Başarısız");
                
                $conn->commit();
                $debugLog[] = "Transaction commit edildi";
                
                setMessage('success', 'Banka hesabı başarıyla güncellendi.');
                header("Location: settings.php#bank-accounts");
                exit;
            }
            
            // Banka hesabını sil
            if ($_POST['action'] == 'delete_bank_account' && isset($_POST['bank_id'])) {
                $debugLog[] = "Banka hesabı silme işlemi başlatıldı";
                
                $stmt = $conn->prepare("DELETE FROM bank_accounts WHERE id = :id");
                $stmt->bindParam(':id', $_POST['bank_id'], PDO::PARAM_INT);
                $result = $stmt->execute();
                $debugLog[] = "SQL sorgusu çalıştırıldı. Sonuç: " . ($result ? "Başarılı" : "Başarısız");
                $debugLog[] = "Etkilenen satır sayısı: " . $stmt->rowCount();
                
                $conn->commit();
                $debugLog[] = "Transaction commit edildi";
                
                setMessage('success', 'Banka hesabı başarıyla silindi.');
                header("Location: settings.php#bank-accounts");
                exit;
            }
        }
        // Genel ayarları güncelle
        else {
            $debugLog[] = "Genel ayarlar güncelleme işlemi başlatıldı";
            
            foreach ($_POST as $key => $value) {
                if (substr($key, 0, 8) === 'setting_' && strlen($key) > 8) {
                    $setting_key = substr($key, 8);
                    
                    $stmt = $conn->prepare("UPDATE settings SET setting_value = :value WHERE setting_key = :key");
                    $stmt->bindParam(':value', $value);
                    $stmt->bindParam(':key', $setting_key);
                    $stmt->execute();
                }
            }
            
            // Logo yükleme işlemi
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                $filename = $_FILES['company_logo']['name'];
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                
                if (in_array(strtolower($ext), $allowed)) {
                    // assets/img dizinini oluştur (yoksa)
                    if (!file_exists('assets/img')) {
                        mkdir('assets/img', 0755, true);
                    }
                    
                    // Eski logoyu kaldır (varsa)
                    if (file_exists('assets/img/logo.png')) {
                        unlink('assets/img/logo.png');
                    }
                    
                    // Yeni logoyu yükle
                    move_uploaded_file($_FILES['company_logo']['tmp_name'], 'assets/img/logo.png');
                }
            }
            
            $conn->commit();
            $debugLog[] = "Transaction commit edildi";
            
            setMessage('success', 'Ayarlar başarıyla güncellendi.');
            header("Location: settings.php");
            exit;
        }
    } catch(Exception $e) {
        $conn->rollBack();
        $errorOccurred = true;
        $debugLog[] = "HATA: " . $e->getMessage();
        setMessage('error', 'İşlem sırasında bir hata oluştu: ' . $e->getMessage());
    }
}

$pageTitle = 'Ayarlar';
$currentPage = 'settings';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
    <style>
        .settings-section {
            margin-bottom: 30px;
        }
        .company-logo-preview {
            max-width: 200px;
            max-height: 100px;
            margin-top: 10px;
            border: 1px solid #ddd;
            padding: 5px;
            border-radius: 5px;
        }
        .bank-account-card {
            margin-bottom: 15px;
            border-left: 5px solid #6c757d;
        }
        .bank-account-card.default {
            border-left: 5px solid #28a745;
        }
        .bank-account-actions {
            display: flex;
            gap: 5px;
        }
        /* Nav sekme renk düzeltmeleri - Yazı rengi sorunu için */
        .nav-tabs .nav-link {
            color: #212529 !important; /* Koyu metin rengi ve !important ile zorla uygula */
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            margin-right: 5px;
        }
        .nav-tabs .nav-link:hover {
            color: #0d6efd !important;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd !important;
            font-weight: 500;
            background-color: #fff;
            border-color: #dee2e6 #dee2e6 #fff;
        }
        /* Debug paneli */
        .debug-panel {
            margin-top: 30px;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            background-color: #f8d7da;
            padding: 15px;
        }
        .debug-panel h4 {
            color: #721c24;
            margin-bottom: 10px;
        }
        .debug-panel pre {
            background-color: #fff;
            padding: 10px;
            border-radius: 3px;
            border: 1px solid #ddd;
            max-height: 300px;
            overflow-y: auto;
        }
    </style>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Bildirimler -->
            <?php if ($successMessage = getMessage('success')): ?>
                <div class="alert alert-success"><?php echo $successMessage; ?></div>
            <?php endif; ?>
            
            <?php if ($errorMessage = getMessage('error')): ?>
                <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
            <?php endif; ?>
            
            <!-- Hata ayıklama paneli -->
            <?php if ($errorOccurred || !empty($debugLog)): ?>
            <div class="debug-panel">
                <h4>Hata Ayıklama Bilgileri</h4>
                <pre><?php echo implode("\n", $debugLog); ?></pre>
            </div>
            <?php endif; ?>
            
            <h1 class="h2 mb-4">Sistem Ayarları</h1>
            
            
            <!-- Ayarlar Sekmeleri -->
            <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link active" id="company-tab" data-bs-toggle="tab" href="#company" role="tab" aria-controls="company" aria-selected="true">Şirket Bilgileri</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="quotation-tab" data-bs-toggle="tab" href="#quotation" role="tab" aria-controls="quotation" aria-selected="false">Teklif Ayarları</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" id="bank-tab" data-bs-toggle="tab" href="#bank-accounts" role="tab" aria-controls="bank-accounts" aria-selected="false">Banka Hesapları</a>
                </li>
            </ul>
            
            <!-- Sekmeler İçeriği -->
            <div class="tab-content" id="settingsTabsContent">
                <!-- Şirket Bilgileri Sekmesi -->
                <div class="tab-pane fade show active" id="company" role="tabpanel" aria-labelledby="company-tab">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                        <div class="card settings-section">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">Şirket Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="setting_company_name" class="form-label">Şirket Adı</label>
                                        <input type="text" class="form-control" id="setting_company_name" name="setting_company_name" 
                                               value="<?php echo isset($settings['company_name']) ? htmlspecialchars($settings['company_name']['setting_value']) : ''; ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="company_logo" class="form-label">Şirket Logosu</label>
                                        <input type="file" class="form-control" id="company_logo" name="company_logo" accept="image/*">
                                        <div class="form-text">PNG, JPG veya GIF formatında olmalıdır. Maksimum boyut: 2MB.</div>
                                        <?php if (file_exists('assets/img/logo.png')): ?>
                                            <div class="mt-2">
                                                <label>Mevcut Logo:</label>
                                                <div>
                                                    <img src="assets/img/logo.png" class="company-logo-preview" alt="Şirket Logosu">
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="setting_company_address" class="form-label">Adres</label>
                                    <textarea class="form-control" id="setting_company_address" name="setting_company_address" rows="3"><?php echo isset($settings['company_address']) ? htmlspecialchars($settings['company_address']['setting_value']) : ''; ?></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="setting_company_phone" class="form-label">Telefon</label>
                                        <input type="text" class="form-control" id="setting_company_phone" name="setting_company_phone" 
                                               value="<?php echo isset($settings['company_phone']) ? htmlspecialchars($settings['company_phone']['setting_value']) : ''; ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="setting_company_email" class="form-label">E-posta</label>
                                        <input type="email" class="form-control" id="setting_company_email" name="setting_company_email" 
                                               value="<?php echo isset($settings['company_email']) ? htmlspecialchars($settings['company_email']['setting_value']) : ''; ?>">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="setting_company_website" class="form-label">Web Sitesi</label>
                                        <input type="text" class="form-control" id="setting_company_website" name="setting_company_website" 
                                               value="<?php echo isset($settings['company_website']) ? htmlspecialchars($settings['company_website']['setting_value']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="setting_company_tax_office" class="form-label">Vergi Dairesi</label>
                                        <input type="text" class="form-control" id="setting_company_tax_office" name="setting_company_tax_office" 
                                               value="<?php echo isset($settings['company_tax_office']) ? htmlspecialchars($settings['company_tax_office']['setting_value']) : ''; ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="setting_company_tax_number" class="form-label">Vergi Numarası</label>
                                        <input type="text" class="form-control" id="setting_company_tax_number" name="setting_company_tax_number" 
                                               value="<?php echo isset($settings['company_tax_number']) ? htmlspecialchars($settings['company_tax_number']['setting_value']) : ''; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <button type="reset" class="btn btn-secondary me-md-2">Sıfırla</button>
                            <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                        </div>
                    </form>
                </div>
                
                <!-- Teklif Ayarları Sekmesi -->
                <div class="tab-pane fade" id="quotation" role="tabpanel" aria-labelledby="quotation-tab">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="card settings-section">
                            <div class="card-header bg-primary text-white">
                                <h5 class="card-title mb-0">Teklif Ayarları</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="setting_default_vat" class="form-label">Varsayılan KDV Oranı (%)</label>
                                        <input type="number" class="form-control" id="setting_default_vat" name="setting_default_vat" min="0" max="100" step="0.01" 
                                               value="<?php echo isset($settings['default_vat']) ? htmlspecialchars($settings['default_vat']['setting_value']) : '18'; ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="setting_default_currency" class="form-label">Varsayılan Para Birimi</label>
                                        <input type="text" class="form-control" id="setting_default_currency" name="setting_default_currency" maxlength="3" 
                                               value="<?php echo isset($settings['default_currency']) ? htmlspecialchars($settings['default_currency']['setting_value']) : '₺'; ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="setting_quotation_prefix" class="form-label">Teklif Numarası Öneki</label>
                                        <input type="text" class="form-control" id="setting_quotation_prefix" name="setting_quotation_prefix" maxlength="10" 
                                               value="<?php echo isset($settings['quotation_prefix']) ? htmlspecialchars($settings['quotation_prefix']['setting_value']) : 'TEK'; ?>">
                                        <div class="form-text">Örnek: "TEK" öneki için teklif numarası "TEK-2025-04-001" olur.</div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="setting_quotation_terms" class="form-label">Varsayılan Teklif Şartları ve Koşulları</label>
                                    <textarea class="form-control" id="setting_quotation_terms" name="setting_quotation_terms" rows="5"><?php echo isset($settings['quotation_terms']) ? htmlspecialchars($settings['quotation_terms']['setting_value']) : ''; ?></textarea>
                                    <div class="form-text">Bu şartlar yeni oluşturulan tekliflerde otomatik olarak eklenecektir.</div>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="setting_show_bank_accounts" name="setting_show_bank_accounts" value="1" 
                                        <?php echo (isset($settings['show_bank_accounts']) && $settings['show_bank_accounts']['setting_value'] == '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="setting_show_bank_accounts">Tekliflerde banka hesap bilgilerini göster</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <button type="reset" class="btn btn-secondary me-md-2">Sıfırla</button>
                            <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                        </div>
                    </form>
                </div>
                
                <!-- Banka Hesapları Sekmesi -->
                <div class="tab-pane fade" id="bank-accounts" role="tabpanel" aria-labelledby="bank-tab">
                    <div class="card settings-section">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Banka Hesapları</h5>
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addBankAccountModal">
                                <i class="bi bi-plus-circle"></i> Yeni Hesap Ekle
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($bankAccounts)): ?>
                                <div class="alert alert-info">
                                    Henüz banka hesabı eklenmemiş. Yeni hesap eklemek için "Yeni Hesap Ekle" butonunu kullanabilirsiniz.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($bankAccounts as $account): ?>
                                        <div class="col-md-6">
                                            <div class="card bank-account-card <?php echo $account['is_default'] ? 'default' : ''; ?>">
                                                <div class="card-body">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <h5 class="card-title"><?php echo htmlspecialchars($account['bank_name']); ?></h5>
                                                        <div class="bank-account-actions">
                                                            <button type="button" class="btn btn-sm btn-outline-primary edit-bank-btn" 
                                                                    data-bank-id="<?php echo $account['id']; ?>"
                                                                    data-bank-name="<?php echo htmlspecialchars($account['bank_name']); ?>"
                                                                    data-account-holder="<?php echo htmlspecialchars($account['account_holder']); ?>"
                                                                    data-iban="<?php echo htmlspecialchars($account['iban']); ?>"
                                                                    data-branch-name="<?php echo htmlspecialchars($account['branch_name']); ?>"
                                                                    data-branch-code="<?php echo htmlspecialchars($account['branch_code']); ?>"
                                                                    data-account-number="<?php echo htmlspecialchars($account['account_number']); ?>"
                                                                    data-swift-code="<?php echo htmlspecialchars($account['swift_code']); ?>"
                                                                    data-currency="<?php echo htmlspecialchars($account['currency']); ?>"
                                                                    data-is-default="<?php echo $account['is_default']; ?>"
                                                                    data-is-active="<?php echo $account['is_active']; ?>"
                                                                    data-bs-toggle="modal" data-bs-target="#editBankAccountModal">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger delete-bank-btn"
                                                                    data-bank-id="<?php echo $account['id']; ?>"
                                                                    data-bank-name="<?php echo htmlspecialchars($account['bank_name']); ?>"
                                                                    data-bs-toggle="modal" data-bs-target="#deleteBankAccountModal">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($account['is_default']): ?>
                                                        <div class="badge bg-success mb-2">Varsayılan Hesap</div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!$account['is_active']): ?>
                                                        <div class="badge bg-warning mb-2">Pasif Hesap</div>
                                                    <?php endif; ?>
                                                    
                                                    <p class="card-text mb-1"><strong>Hesap Sahibi:</strong> <?php echo htmlspecialchars($account['account_holder']); ?></p>
                                                    <p class="card-text mb-1"><strong>IBAN:</strong> <?php echo htmlspecialchars($account['iban']); ?></p>
                                                    
                                                    <?php if (!empty($account['branch_name'])): ?>
                                                        <p class="card-text mb-1"><strong>Şube:</strong> <?php echo htmlspecialchars($account['branch_name']); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($account['account_number'])): ?>
                                                        <p class="card-text mb-1"><strong>Hesap No:</strong> <?php echo htmlspecialchars($account['account_number']); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($account['swift_code'])): ?>
                                                        <p class="card-text mb-1"><strong>SWIFT:</strong> <?php echo htmlspecialchars($account['swift_code']); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <p class="card-text"><strong>Para Birimi:</strong> <?php echo htmlspecialchars($account['currency']); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Yeni Banka Hesabı Ekleme Modal -->
    <div class="modal fade" id="addBankAccountModal" tabindex="-1" aria-labelledby="addBankAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="action" value="add_bank_account">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addBankAccountModalLabel">Yeni Banka Hesabı Ekle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="bank_name" class="form-label">Banka Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="account_holder" class="form-label">Hesap Sahibi <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="account_holder" name="account_holder" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="iban" class="form-label">IBAN <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="iban" name="iban" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="branch_name" class="form-label">Şube Adı</label>
                                <input type="text" class="form-control" id="branch_name" name="branch_name">
                            </div>
                            <div class="col-md-6">
                                <label for="branch_code" class="form-label">Şube Kodu</label>
                                <input type="text" class="form-control" id="branch_code" name="branch_code">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="account_number" class="form-label">Hesap Numarası</label>
                                <input type="text" class="form-control" id="account_number" name="account_number">
                            </div>
                            <div class="col-md-6">
                                <label for="swift_code" class="form-label">SWIFT Kodu</label>
                                <input type="text" class="form-control" id="swift_code" name="swift_code">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="currency" class="form-label">Para Birimi</label>
                            <select class="form-select" id="currency" name="currency">
                                <option value="TRY">TRY - Türk Lirası</option>
                                <option value="USD">USD - Amerikan Doları</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - İngiliz Sterlini</option>
                            </select>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_default" name="is_default" value="1">
                            <label class="form-check-label" for="is_default">Varsayılan hesap olarak ayarla</label>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="is_active">Hesap aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Banka Hesabı Düzenleme Modal -->
    <div class="modal fade" id="editBankAccountModal" tabindex="-1" aria-labelledby="editBankAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="action" value="edit_bank_account">
                    <input type="hidden" name="bank_id" id="edit_bank_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editBankAccountModalLabel">Banka Hesabını Düzenle</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_bank_name" class="form-label">Banka Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_bank_name" name="bank_name" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_account_holder" class="form-label">Hesap Sahibi <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="edit_account_holder" name="account_holder" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_iban" class="form-label">IBAN <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_iban" name="iban" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_branch_name" class="form-label">Şube Adı</label>
                                <input type="text" class="form-control" id="edit_branch_name" name="branch_name">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_branch_code" class="form-label">Şube Kodu</label>
                                <input type="text" class="form-control" id="edit_branch_code" name="branch_code">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_account_number" class="form-label">Hesap Numarası</label>
                                <input type="text" class="form-control" id="edit_account_number" name="account_number">
                            </div>
                            <div class="col-md-6">
                                <label for="edit_swift_code" class="form-label">SWIFT Kodu</label>
                                <input type="text" class="form-control" id="edit_swift_code" name="swift_code">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_currency" class="form-label">Para Birimi</label>
                            <select class="form-select" id="edit_currency" name="currency">
                                <option value="TRY">TRY - Türk Lirası</option>
                                <option value="USD">USD - Amerikan Doları</option>
                                <option value="EUR">EUR - Euro</option>
                                <option value="GBP">GBP - İngiliz Sterlini</option>
                            </select>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="edit_is_default" name="is_default" value="1">
                            <label class="form-check-label" for="edit_is_default">Varsayılan hesap olarak ayarla</label>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active" value="1">
                            <label class="form-check-label" for="edit_is_active">Hesap aktif</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Banka Hesabı Silme Modal -->
    <div class="modal fade" id="deleteBankAccountModal" tabindex="-1" aria-labelledby="deleteBankAccountModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="action" value="delete_bank_account">
                    <input type="hidden" name="bank_id" id="delete_bank_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteBankAccountModalLabel">Banka Hesabını Sil</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
                    </div>
                    <div class="modal-body">
                        <p>Bu banka hesabını silmek istediğinizden emin misiniz?</p>
                        <p><strong id="delete_bank_name"></strong></p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> Bu işlem geri alınamaz.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-danger">Sil</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Logo yükleme önizleme
        document.getElementById('company_logo').addEventListener('change', function(e) {
            if (e.target.files && e.target.files[0]) {
                var reader = new FileReader();
                
                reader.onload = function(event) {
                    var previewDiv = document.querySelector('.company-logo-preview');
                    
                    if (!previewDiv) {
                        // Önizleme alanı yoksa oluştur
                        var container = document.createElement('div');
                        container.className = 'mt-2';
                        
                        var label = document.createElement('label');
                        label.textContent = 'Yeni Logo:';
                        
                        var imgContainer = document.createElement('div');
                        
                        var img = document.createElement('img');
                        img.className = 'company-logo-preview';
                        img.alt = 'Şirket Logosu';
                        
                        imgContainer.appendChild(img);
                        container.appendChild(label);
                        container.appendChild(imgContainer);
                        
                        document.getElementById('company_logo').parentNode.appendChild(container);
                        
                        previewDiv = img;
                    }
                    
                    previewDiv.src = event.target.result;
                };
                
                reader.readAsDataURL(e.target.files[0]);
            }
        });
        
        // Banka hesabı düzenleme modali
        const editBankModal = document.getElementById('editBankAccountModal');
        if (editBankModal) {
            document.querySelectorAll('.edit-bank-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bankId = this.getAttribute('data-bank-id');
                    const bankName = this.getAttribute('data-bank-name');
                    const accountHolder = this.getAttribute('data-account-holder');
                    const iban = this.getAttribute('data-iban');
                    const branchName = this.getAttribute('data-branch-name');
                    const branchCode = this.getAttribute('data-branch-code');
                    const accountNumber = this.getAttribute('data-account-number');
                    const swiftCode = this.getAttribute('data-swift-code');
                    const currency = this.getAttribute('data-currency');
                    const isDefault = this.getAttribute('data-is-default') === '1';
                    const isActive = this.getAttribute('data-is-active') === '1';
                    
                    document.getElementById('edit_bank_id').value = bankId;
                    document.getElementById('edit_bank_name').value = bankName;
                    document.getElementById('edit_account_holder').value = accountHolder;
                    document.getElementById('edit_iban').value = iban;
                    document.getElementById('edit_branch_name').value = branchName;
                    document.getElementById('edit_branch_code').value = branchCode;
                    document.getElementById('edit_account_number').value = accountNumber;
                    document.getElementById('edit_swift_code').value = swiftCode;
                    document.getElementById('edit_currency').value = currency;
                    document.getElementById('edit_is_default').checked = isDefault;
                    document.getElementById('edit_is_active').checked = isActive;
                });
            });
        }
        
        // Banka hesabı silme modali
        const deleteBankModal = document.getElementById('deleteBankAccountModal');
        if (deleteBankModal) {
            document.querySelectorAll('.delete-bank-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bankId = this.getAttribute('data-bank-id');
                    const bankName = this.getAttribute('data-bank-name');
                    
                    document.getElementById('delete_bank_id').value = bankId;
                    document.getElementById('delete_bank_name').textContent = bankName;
                });
            });
        }
        
        // URL hash tabları aktif etme
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (hash) {
                const tab = document.querySelector(`a[href="${hash}"]`);
                if (tab) {
                    const bsTab = new bootstrap.Tab(tab);
                    bsTab.show();
                }
            }
        });
    </script>
</body>
</html>