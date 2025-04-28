<?php
// add_customer.php - Müşteri ekleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $name = trim($_POST['name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $tax_office = trim($_POST['tax_office']);
    $tax_number = trim($_POST['tax_number']);
    
    // Basit doğrulama
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Firma adı zorunludur.";
    }
    
    // Hata yoksa veritabanına ekle
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            
            $stmt = $conn->prepare("INSERT INTO customers (name, contact_person, email, phone, address, tax_office, tax_number) 
                                    VALUES (:name, :contact_person, :email, :phone, :address, :tax_office, :tax_number)");
            
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':contact_person', $contact_person);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':tax_office', $tax_office);
            $stmt->bindParam(':tax_number', $tax_number);
            
            $stmt->execute();
            
            setMessage('success', 'Müşteri başarıyla eklendi.');
            header("Location: customers.php");
            exit;
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}
$pageTitle = 'Yeni Müşteri Ekle';
$currentPage = 'customers';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Müşteri Ekle - Teklif Yönetim Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Yeni Müşteri Ekle</h1>
                <a href="customers.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Müşterilere Dön
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
            
            <div class="card">
                <div class="card-body">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Firma Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required 
                                       value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="contact_person" class="form-label">İlgili Kişi</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?php echo isset($contact_person) ? htmlspecialchars($contact_person) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="email" class="form-label">E-posta</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Telefon</label>
                                <input type="text" class="form-control" id="phone" name="phone" 
                                       value="<?php echo isset($phone) ? htmlspecialchars($phone) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label">Adres</label>
                            <textarea class="form-control" id="address" name="address" rows="3"><?php echo isset($address) ? htmlspecialchars($address) : ''; ?></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="tax_office" class="form-label">Vergi Dairesi</label>
                                <input type="text" class="form-control" id="tax_office" name="tax_office" 
                                       value="<?php echo isset($tax_office) ? htmlspecialchars($tax_office) : ''; ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="tax_number" class="form-label">Vergi Numarası</label>
                                <input type="text" class="form-control" id="tax_number" name="tax_number" 
                                       value="<?php echo isset($tax_number) ? htmlspecialchars($tax_number) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-secondary me-md-2">Temizle</button>
                            <button type="submit" class="btn btn-primary">Kaydet</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>