<?php
// edit_product.php - Ürün düzenleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz ürün ID\'si.');
    header("Location: products.php");
    exit;
}

$product_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Ürün bilgilerini al
try {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->bindParam(':id', $product_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Ürün bulunamadı.');
        header("Location: products.php");
        exit;
    }
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: products.php");
    exit;
}

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
    
    // Kod benzersiz mi kontrol et (bu ürün hariç)
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE code = :code AND id != :id");
            $stmt->bindParam(':code', $code);
            $stmt->bindParam(':id', $product_id);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Bu ürün kodu zaten kullanılmaktadır. Lütfen farklı bir kod giriniz.";
            }
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    
    // Hata yoksa veritabanına güncelle
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            
            // Mevcut stok miktarını al
            $stmt = $conn->prepare("SELECT stock_quantity FROM products WHERE id = :id");
            $stmt->bindParam(':id', $product_id);
            $stmt->execute();
            $current_stock = $stmt->fetchColumn();
            
            // Ürünü güncelle
            $stmt = $conn->prepare("UPDATE products SET 
                                code = :code, 
                                name = :name, 
                                description = :description, 
                                price = :price, 
                                tax_rate = :tax_rate, 
                                stock_quantity = :stock_quantity
                                WHERE id = :id");
            
            $stmt->bindParam(':code', $code);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':tax_rate', $tax_rate);
            $stmt->bindParam(':stock_quantity', $stock_quantity);
            $stmt->bindParam(':id', $product_id);
            
            $stmt->execute();
            
            // Stok değişimi için hareket oluştur
            if ($stock_quantity != $current_stock) {
                $stock_difference = $stock_quantity - $current_stock;
                $movement_type = ($stock_difference > 0) ? 'in' : 'out';
                $movement_quantity = abs($stock_difference);
                $user_id = $_SESSION['user_id'];
                
                $stmt = $conn->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, notes, user_id) 
                                        VALUES (:product_id, :movement_type, :quantity, 'manual', 'Ürün düzenleme sırasında stok güncellendi', :user_id)");
                
                $stmt->bindParam(':product_id', $product_id);
                $stmt->bindParam(':movement_type', $movement_type);
                $stmt->bindParam(':quantity', $movement_quantity);
                $stmt->bindParam(':user_id', $user_id);
                
                $stmt->execute();
            }
            
            setMessage('success', 'Ürün başarıyla güncellendi.');
            header("Location: view_product.php?id=" . $product_id);
            exit;
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Ürün Düzenle';
$currentPage = 'products';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Ürün Düzenle</h1>
            <a href="view_product.php?id=<?php echo $product_id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Ürüne Dön
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
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $product_id); ?>">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="code" class="form-label">Ürün Kodu <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="code" name="code" required 
                                   value="<?php echo htmlspecialchars($product['code']); ?>">
                            <div class="form-text">Benzersiz bir ürün kodu giriniz.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="name" class="form-label">Ürün Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   value="<?php echo htmlspecialchars($product['name']); ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Açıklama</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($product['description']); ?></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="price" class="form-label">Fiyat (₺) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="price" name="price" required 
                                   value="<?php echo htmlspecialchars($product['price']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="tax_rate" class="form-label">KDV Oranı (%) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tax_rate" name="tax_rate" required
                                   value="<?php echo htmlspecialchars($product['tax_rate']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="stock_quantity" class="form-label">Stok Miktarı <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" min="0" required
                                   value="<?php echo htmlspecialchars($product['stock_quantity']); ?>">
                            <div class="form-text text-warning">
                                <i class="bi bi-exclamation-triangle"></i> 
                                Stok miktarını değiştirirseniz, fark için otomatik stok hareketi oluşturulacaktır.
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="reset" class="btn btn-secondary me-md-2">Sıfırla</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>