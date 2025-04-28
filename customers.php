<?php
// customers.php - Müşteri listesi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Müşteri silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Önce bu müşteriye ait tekliflerin olup olmadığını kontrol et
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM quotations WHERE customer_id = :id");
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setMessage('error', 'Bu müşteriye ait teklifler olduğu için silinemez.');
        } else {
            $deleteStmt = $conn->prepare("DELETE FROM customers WHERE id = :id");
            $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $deleteStmt->execute();
            
            setMessage('success', 'Müşteri başarıyla silindi.');
        }
    } catch(PDOException $e) {
        setMessage('error', 'Müşteri silinirken bir hata oluştu: ' . $e->getMessage());
    }
    
    header("Location: customers.php");
    exit;
}

// Müşteri listesini al
$customers = [];
try {
    $stmt = $conn->query("SELECT * FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Sayfa başlığını ve aktif sayfayı belirle
$pageTitle = 'Müşteriler';
$currentPage = 'customers';

// Header'ı dahil et
include 'includes/header.php';
// Navbar'ı dahil et
include 'includes/navbar.php';
// Sidebar'ı dahil et
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
                <h1 class="h2">Müşteriler</h1>
                <a href="add_customer.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Yeni Müşteri Ekle
                </a>
            </div>
            
            <!-- Müşteri Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($customers) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Firma Adı</th>
                                        <th>İlgili Kişi</th>
                                        <th>E-posta</th>
                                        <th>Telefon</th>
                                        <th>Vergi Dairesi/No</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($customers as $customer): ?>
                                        <tr>
                                            <td><?php echo $customer['id']; ?></td>
                                            <td><?php echo htmlspecialchars($customer['name']); ?></td>
                                            <td><?php echo htmlspecialchars($customer['contact_person']); ?></td>
                                            <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                            <td><?php echo htmlspecialchars($customer['phone']); ?></td>
                                            <td>
                                                <?php 
                                                    echo htmlspecialchars($customer['tax_office']); 
                                                    if (!empty($customer['tax_office']) && !empty($customer['tax_number'])) {
                                                        echo ' / ';
                                                    }
                                                    echo htmlspecialchars($customer['tax_number']); 
                                                ?>
                                            </td>
                                            <td>
                                                <a href="view_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit_customer.php?id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-warning" title="Düzenle">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars(addslashes($customer['name'])); ?>')" class="btn btn-sm btn-danger" title="Sil">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz müşteri kaydı bulunmamaktadır.</p>
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
                    <h5 class="modal-title" id="deleteModalLabel">Müşteri Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu müşteriyi silmek istediğinizden emin misiniz?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Sayfa özel JavaScript kodları -->
    <script>
        // Silme onay fonksiyonu
        function confirmDelete(id, name) {
            document.getElementById('deleteConfirmText').textContent = '"' + name + '" müşterisini silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'customers.php?delete=' + id;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>