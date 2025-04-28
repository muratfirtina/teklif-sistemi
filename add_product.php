<?php
// add_product.php - Ürün ekleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $code = trim($_POST['code']);
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $price = str_replace(',', '.', trim($_POST['price'])); // Virgülü noktaya çevir
    $tax_rate = str_replace(',', '.', trim($_POST['tax_rate']));
    $stock_quantity = intval($_POST['stock_quantity']);
    
    // Basit doğrulama
    $errors = [];
    
    if (empty($code)) {
        $errors[] = "Ürün kodu zorunludur.";
    }
    
    if (empty($name)) {
        $errors[] = "Ürün adı zorunludur.";
    }
    
    if (!is_numeric($price) || $price < 0) {
        $errors[] = "Geçerli bir fiyat giriniz.";
    }
    
    if (!is_numeric($tax_rate) || $tax_rate < 0 || $tax_rate > 100) {
        $errors[] = "Geçerli bir KDV oranı giriniz (0-100 arası).";
    }
    
    if (!is_numeric($stock_quantity) || $stock_quantity < 0) {
        $errors[] = "Geçerli bir stok miktarı giriniz.";
    }
    
    // Kod benzersiz mi kontrol et
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            
            $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE code = :code");
            $stmt->bindParam(':code', $code);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Bu ürün kodu zaten kullanılmaktadır. Lütfen farklı bir kod giriniz.";
            }
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    
    // Hata yoksa veritabanına ekle
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            
            $stmt = $conn->prepare("INSERT INTO products (code, name, description, price, tax_rate, stock_quantity) 
                                    VALUES (:code, :name, :description, :price, :tax_rate, :stock_quantity)");
            
            $stmt->bindParam(':code', $code);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':tax_rate', $tax_rate);
            $stmt->bindParam(':stock_quantity', $stock_quantity);
            
            $stmt->execute();
            
            // Stok hareketi oluştur (Başlangıç stoku)
            if ($stock_quantity > 0) {
                $product_id = $conn->lastInsertId();
                $user_id = $_SESSION['user_id'];
                
                $stmt = $conn->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, notes, user_id) 
                                        VALUES (:product_id, 'in', :quantity, 'initial', 'Başlangıç stoğu', :user_id)");
                
                $stmt->bindParam(':product_id', $product_id);
                $stmt->bindParam(':quantity', $stock_quantity);
                $stmt->bindParam(':user_id', $user_id);
                
                $stmt->execute();
            }
            
            setMessage('success', 'Ürün başarıyla eklendi.');
            header("Location: products.php");
            exit;
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}
$pageTitle = 'Yeni Ürün Ekle';
$currentPage = 'products';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Yeni Ürün Ekle</h1>
                <a href="products.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Ürünlere Dön
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
                                <label for="code" class="form-label">Ürün Kodu <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="code" name="code" required 
                                       value="<?php echo isset($code) ? htmlspecialchars($code) : ''; ?>">
                                <div class="form-text">Benzersiz bir ürün kodu giriniz.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="name" class="form-label">Ürün Adı <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" required 
                                       value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Açıklama</label>
                            <textarea class="form-control" id="description" name="description" rows="3"><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="price" class="form-label">Fiyat (₺) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="price" name="price" required 
                                       value="<?php echo isset($price) ? htmlspecialchars($price) : '0.00'; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="tax_rate" class="form-label">KDV Oranı (%)</label>
                                <input type="text" class="form-control" id="tax_rate" name="tax_rate" 
                                       value="<?php echo isset($tax_rate) ? htmlspecialchars($tax_rate) : '18'; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="stock_quantity" class="form-label">Stok Miktarı</label>
                                <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" min="0" 
                                       value="<?php echo isset($stock_quantity) ? htmlspecialchars($stock_quantity) : '0'; ?>">
                                <div class="form-text">Başlangıç stok miktarı.</div>
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