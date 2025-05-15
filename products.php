<?php
// products.php - Ürün listesi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Ürün silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];

    try {
        // Önce bu ürüne ait teklif kalemleri olup olmadığını kontrol et
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM quotation_items WHERE item_type = 'product' AND item_id = :id");
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();

        if ($checkStmt->fetchColumn() > 0) {
            setMessage('error', 'Bu ürün tekliflerde kullanıldığı için silinemez.');
        } else {
            // Sonra stok hareketleri olup olmadığını kontrol et
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM inventory_movements WHERE product_id = :id");
            $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();

            if ($checkStmt->fetchColumn() > 0) {
                setMessage('error', 'Bu ürüne ait stok hareketleri olduğu için silinemez.');
            } else {
                $deleteStmt = $conn->prepare("DELETE FROM products WHERE id = :id");
                $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $deleteStmt->execute();

                setMessage('success', 'Ürün başarıyla silindi.');
            }
        }
    } catch(PDOException $e) {
        setMessage('error', 'Ürün silinirken bir hata oluştu: ' . $e->getMessage());
    }

    header("Location: products.php");
    exit;
}

// Ürün listesini al
$products = [];
try {
    // color_hex sütununu da seçiyoruz
    $stmt = $conn->query("SELECT id, code, name, description, price, tax_rate, stock_quantity, color_hex FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Ürün listesi alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Ürünler';
$currentPage = 'products';
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

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Ürünler</h1>
                <a href="add_product.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Yeni Ürün Ekle
                </a>
            </div>

            <!-- Ürün Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($products) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Renk</th> <!-- Yeni Sütun -->
                                        <th>Ürün Kodu</th>
                                        <th>Ürün Adı</th>
                                        <th>Fiyat</th>
                                        <th>KDV (%)</th>
                                        <th>Stok Miktarı</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><?php echo $product['id']; ?></td>
                                            <td> <!-- Renk Sütunu -->
                                                <?php if (!empty($product['color_hex'])): ?>
                                                    <span style="display: inline-block; width: 20px; height: 20px; background-color: <?php echo htmlspecialchars($product['color_hex']); ?>; border: 1px solid #ccc; vertical-align: middle;" title="<?php echo htmlspecialchars($product['color_hex']); ?>"></span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($product['code']); ?></td>
                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                            <td><?php echo number_format($product['price'], 2, ',', '.') . ' ₺'; ?></td>
                                            <td><?php echo number_format($product['tax_rate'], 2, ',', '.') . '%'; ?></td>
                                            <td><?php echo $product['stock_quantity']; ?></td>
                                            <td>
                                                <a href="view_product.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-warning" title="Düzenle">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['name'])); ?>')" class="btn btn-sm btn-danger" title="Sil">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz ürün kaydı bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Ürün Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu ürünü silmek istediğinizden emin misiniz?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Silme onay fonksiyonu
        function confirmDelete(id, name) {
            document.getElementById('deleteConfirmText').textContent = '"' + name + '" ürününü silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'products.php?delete=' + id;

            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>