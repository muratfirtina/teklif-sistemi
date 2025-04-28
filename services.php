<?php
// services.php - Hizmet listesi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Hizmet silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Önce bu hizmete ait teklif kalemleri olup olmadığını kontrol et
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM quotation_items WHERE item_type = 'service' AND item_id = :id");
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setMessage('error', 'Bu hizmet tekliflerde kullanıldığı için silinemez.');
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM services WHERE id = :id");
            $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $deleteStmt->execute();
            
            setMessage('success', 'Hizmet başarıyla silindi.');
        }
    } catch(PDOException $e) {
        setMessage('error', 'Hizmet silinirken bir hata oluştu: ' . $e->getMessage());
    }
    
    header("Location: services.php");
    exit;
}

// Hizmet listesini al
$services = [];
try {
    $stmt = $conn->query("SELECT * FROM services ORDER BY name ASC");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Hizmet listesi alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Hizmetler';
$currentPage = 'services';
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
                <h1 class="h2">Hizmetler</h1>
                <a href="add_service.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Yeni Hizmet Ekle
                </a>
            </div>
            
            <!-- Hizmet Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($services) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Hizmet Kodu</th>
                                        <th>Hizmet Adı</th>
                                        <th>Fiyat</th>
                                        <th>KDV (%)</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($services as $service): ?>
                                        <tr>
                                            <td><?php echo $service['id']; ?></td>
                                            <td><?php echo htmlspecialchars($service['code']); ?></td>
                                            <td><?php echo htmlspecialchars($service['name']); ?></td>
                                            <td><?php echo number_format($service['price'], 2, ',', '.') . ' ₺'; ?></td>
                                            <td><?php echo number_format($service['tax_rate'], 2, ',', '.') . '%'; ?></td>
                                            <td>
                                                <a href="view_service.php?id=<?php echo $service['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_service.php?id=<?php echo $service['id']; ?>" class="btn btn-sm btn-warning" title="Düzenle">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $service['id']; ?>, '<?php echo htmlspecialchars(addslashes($service['name'])); ?>')" class="btn btn-sm btn-danger" title="Sil">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz hizmet kaydı bulunmamaktadır.</p>
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
                    <h5 class="modal-title" id="deleteModalLabel">Hizmet Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu hizmeti silmek istediğinizden emin misiniz?</p>
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
            document.getElementById('deleteConfirmText').textContent = '"' + name + '" hizmetini silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'services.php?delete=' + id;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>