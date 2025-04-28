<?php
// settings.php - Sistem ayarları sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece admin erişebilir
requireAdmin();

// Veritabanı bağlantısı
$conn = getDbConnection();

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
        'quotation_terms' => ['value' => "1. Bu teklif 30 gün geçerlidir.\n2. Fiyatlara KDV dahil değildir.\n3. Ödeme şartları: %50 peşin, %50 teslimat öncesi.\n4. Teslimat süresi: Sipariş onayından itibaren 10 iş günüdür.", 'description' => 'Varsayılan teklif şartları ve koşulları']
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
    setMessage('error', 'Ayarlar yüklenirken bir hata oluştu: ' . $e->getMessage());
}

// Ayarları güncelle
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $conn->beginTransaction();
        
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
        setMessage('success', 'Ayarlar başarıyla güncellendi.');
        header("Location: settings.php");
        exit;
    } catch(PDOException $e) {
        $conn->rollBack();
        setMessage('error', 'Ayarlar güncellenirken bir hata oluştu: ' . $e->getMessage());
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
            
            <h1 class="h2 mb-4">Sistem Ayarları</h1>
            
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                <!-- Şirket Bilgileri -->
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
                
                <!-- Teklif Ayarları -->
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
                    </div>
                </div>
                
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                    <button type="reset" class="btn btn-secondary me-md-2">Sıfırla</button>
                    <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
                </div>
            </form>
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
    </script>
</body>
</html>