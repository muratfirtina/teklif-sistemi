<?php
// inventory.php - Stok takibi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Filtre parametreleri
$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
$movement_type = isset($_GET['movement_type']) ? $_GET['movement_type'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Yeni stok hareketi ekleme
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $product_id = intval($_POST['product_id']);
    $movement_type = $_POST['movement_type'];
    $quantity = intval($_POST['quantity']);
    $notes = trim($_POST['notes']);
    
    $errors = [];
    
    if ($product_id <= 0) {
        $errors[] = "Lütfen bir ürün seçin.";
    }
    
    if (!in_array($movement_type, ['in', 'out', 'adjustment'])) {
        $errors[] = "Geçersiz hareket türü.";
    }
    
    if ($quantity <= 0) {
        $errors[] = "Miktar pozitif bir sayı olmalıdır.";
    }
    
    // Çıkış hareketi için stok kontrolü
    if (empty($errors) && $movement_type == 'out') {
        try {
            $stmt = $conn->prepare("SELECT stock_quantity FROM products WHERE id = :product_id");
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            $current_stock = $stmt->fetchColumn();
            
            if ($quantity > $current_stock) {
                $errors[] = "Stokta yeterli ürün bulunmamaktadır. Mevcut stok: $current_stock";
            }
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    
    // Hata yoksa veritabanına ekle
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Stok hareketi ekle
            $user_id = $_SESSION['user_id'];
            
            $stmt = $conn->prepare("INSERT INTO inventory_movements (product_id, movement_type, quantity, reference_type, notes, user_id) 
                                    VALUES (:product_id, :movement_type, :quantity, 'manual', :notes, :user_id)");
            
            $stmt->bindParam(':product_id', $product_id);
            $stmt->bindParam(':movement_type', $movement_type);
            $stmt->bindParam(':quantity', $quantity);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':user_id', $user_id);
            
            $stmt->execute();
            
            // Ürün stok miktarını güncelle
            if ($movement_type == 'in' || $movement_type == 'adjustment') {
                $sql = "UPDATE products SET stock_quantity = stock_quantity + :quantity WHERE id = :product_id";
            } else { // out
                $sql = "UPDATE products SET stock_quantity = stock_quantity - :quantity WHERE id = :product_id";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':quantity', $quantity);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            
            $conn->commit();
            
            setMessage('success', 'Stok hareketi başarıyla eklendi.');
            header("Location: inventory.php");
            exit;
        } catch(PDOException $e) {
            $conn->rollBack();
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Tüm ürünleri getir (filtre dropdown için)
$products = [];
try {
    $stmt = $conn->query("SELECT id, code, name FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Ürün listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Stok hareketlerini getir
$inventory_movements = [];
try {
    $sql = "SELECT im.*, p.code as product_code, p.name as product_name, u.username 
            FROM inventory_movements im
            JOIN products p ON im.product_id = p.id
            JOIN users u ON im.user_id = u.id
            WHERE 1=1";
    
    $params = [];
    
    if ($product_id > 0) {
        $sql .= " AND im.product_id = :product_id";
        $params[':product_id'] = $product_id;
    }
    
    if (!empty($movement_type)) {
        $sql .= " AND im.movement_type = :movement_type";
        $params[':movement_type'] = $movement_type;
    }
    
    if (!empty($date_from)) {
        $sql .= " AND DATE(im.created_at) >= :date_from";
        $params[':date_from'] = $date_from;
    }
    
    if (!empty($date_to)) {
        $sql .= " AND DATE(im.created_at) <= :date_to";
        $params[':date_to'] = $date_to;
    }
    
    $sql .= " ORDER BY im.created_at DESC LIMIT 100";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $inventory_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Stok hareketleri alınırken bir hata oluştu: ' . $e->getMessage());
}

// Stok durumu özeti
$stock_summary = [];
try {
    $sql = "SELECT p.id, p.code, p.name, p.stock_quantity, 
                  (SELECT SUM(quantity) FROM inventory_movements WHERE product_id = p.id AND movement_type = 'in') as total_in,
                  (SELECT SUM(quantity) FROM inventory_movements WHERE product_id = p.id AND movement_type = 'out') as total_out,
                  (SELECT SUM(quantity) FROM inventory_movements WHERE product_id = p.id AND movement_type = 'adjustment') as total_adjustment
           FROM products p";
    
    if ($product_id > 0) {
        $sql .= " WHERE p.id = :product_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':product_id', $product_id);
    } else {
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $stock_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Stok özeti alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Stok Takibi';
$currentPage = 'inventory';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

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
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Stok Takibi</h1>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newMovementModal">
                    <i class="bi bi-plus-circle"></i> Yeni Stok Hareketi
                </button>
            </div>
            
            <!-- Filtreleme -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Filtrele</h5>
                </div>
                <div class="card-body">
                    <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="row">
                        <div class="col-md-3 mb-3">
                            <label for="product_id" class="form-label">Ürün</label>
                            <select class="form-select" id="product_id" name="product_id">
                                <option value="0">Tüm Ürünler</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" <?php echo $product_id == $product['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="movement_type" class="form-label">Hareket Türü</label>
                            <select class="form-select" id="movement_type" name="movement_type">
                                <option value="">Tümü</option>
                                <option value="in" <?php echo $movement_type == 'in' ? 'selected' : ''; ?>>Giriş</option>
                                <option value="out" <?php echo $movement_type == 'out' ? 'selected' : ''; ?>>Çıkış</option>
                                <option value="adjustment" <?php echo $movement_type == 'adjustment' ? 'selected' : ''; ?>>Düzeltme</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_from" class="form-label">Başlangıç Tarihi</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_to" class="form-label">Bitiş Tarihi</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-12 text-end">
                            <a href="inventory.php" class="btn btn-secondary me-2">Sıfırla</a>
                            <button type="submit" class="btn btn-primary">Filtrele</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Stok Özeti -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Stok Durumu</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php if (count($stock_summary) > 0): ?>
                            <?php foreach ($stock_summary as $stock): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="stock-info">
                                        <h5><?php echo htmlspecialchars($stock['code'] . ' - ' . $stock['name']); ?></h5>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Mevcut Stok:</span>
                                            <span class="fw-bold">
                                                <?php 
                                                $stock_class = 'success';
                                                if ($stock['stock_quantity'] <= 0) {
                                                    $stock_class = 'danger';
                                                } elseif ($stock['stock_quantity'] < 10) {
                                                    $stock_class = 'warning';
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $stock_class; ?> stock-badge">
                                                    <?php echo $stock['stock_quantity']; ?>
                                                </span>
                                            </span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Toplam Giriş:</span>
                                            <span class="badge bg-success stock-badge"><?php echo $stock['total_in'] ? $stock['total_in'] : '0'; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Toplam Çıkış:</span>
                                            <span class="badge bg-danger stock-badge"><?php echo $stock['total_out'] ? $stock['total_out'] : '0'; ?></span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span>Toplam Düzeltme:</span>
                                            <span class="badge bg-info stock-badge"><?php echo $stock['total_adjustment'] ? $stock['total_adjustment'] : '0'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12">
                                <p class="text-center">Stok bilgisi bulunamadı.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Stok Hareketleri Tablosu -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Stok Hareketleri</h5>
                </div>
                <div class="card-body">
                    <?php if (count($inventory_movements) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tarih</th>
                                        <th>Ürün</th>
                                        <th>Hareket Türü</th>
                                        <th>Miktar</th>
                                        <th>Referans</th>
                                        <th>Notlar</th>
                                        <th>Kullanıcı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo $movement['id']; ?></td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($movement['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($movement['product_code'] . ' - ' . $movement['product_name']); ?></td>
                                            <td>
                                                <?php
                                                $badgeClass = 'secondary';
                                                $movementText = 'Bilinmiyor';
                                                
                                                switch($movement['movement_type']) {
                                                    case 'in':
                                                        $badgeClass = 'success';
                                                        $movementText = 'Giriş';
                                                        break;
                                                    case 'out':
                                                        $badgeClass = 'danger';
                                                        $movementText = 'Çıkış';
                                                        break;
                                                    case 'adjustment':
                                                        $badgeClass = 'info';
                                                        $movementText = 'Düzeltme';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $badgeClass; ?> stock-badge">
                                                    <?php echo $movementText; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $movement['quantity']; ?></td>
                                            <td>
                                                <?php
                                                $referenceText = 'Manuel';
                                                if ($movement['reference_type'] == 'initial') {
                                                    $referenceText = 'Başlangıç Stoğu';
                                                } elseif ($movement['reference_type'] == 'quotation') {
                                                    $referenceText = 'Teklif: ' . $movement['reference_id'];
                                                }
                                                echo $referenceText;
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($movement['notes']); ?></td>
                                            <td><?php echo htmlspecialchars($movement['username']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Stok hareketi bulunamadı.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Yeni Stok Hareketi Modal -->
    <div class="modal fade" id="newMovementModal" tabindex="-1" aria-labelledby="newMovementModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="newMovementModalLabel">Yeni Stok Hareketi</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="product_id_modal" class="form-label">Ürün <span class="text-danger">*</span></label>
                            <select class="form-select" id="product_id_modal" name="product_id" required>
                                <option value="">Ürün Seçin</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="movement_type_modal" class="form-label">Hareket Türü <span class="text-danger">*</span></label>
                            <select class="form-select" id="movement_type_modal" name="movement_type" required>
                                <option value="">Seçin</option>
                                <option value="in">Giriş</option>
                                <option value="out">Çıkış</option>
                                <option value="adjustment">Düzeltme</option>
                            </select>
                            <div class="form-text">
                                <strong>Giriş:</strong> Stok miktarını artırır. <br>
                                <strong>Çıkış:</strong> Stok miktarını azaltır. <br>
                                <strong>Düzeltme:</strong> Stoktaki hataları düzeltmek için kullanılır.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="quantity" class="form-label">Miktar <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="1" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notlar</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>