<?php
// quotations.php - Teklif listesi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Teklif silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Önce kullanıcının yetkisini kontrol et
        $stmt = $conn->prepare("SELECT user_id FROM quotations WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            setMessage('error', 'Teklif bulunamadı.');
            header("Location: quotations.php");
            exit;
        }
        
        $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Teklifi sadece sahibi veya admin silebilir
        if ($quotation['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
            setMessage('error', 'Bu teklifi silme yetkiniz bulunmamaktadır.');
            header("Location: quotations.php");
            exit;
        }
        
        // İşlem başlat
        $conn->beginTransaction();
        
        // Önce teklif kalemlerini sil
        $deleteItemsStmt = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = :id");
        $deleteItemsStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleteItemsStmt->execute();
        
        // Sonra teklifi sil
        $deleteQuotationStmt = $conn->prepare("DELETE FROM quotations WHERE id = :id");
        $deleteQuotationStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleteQuotationStmt->execute();
        
        // İşlemi tamamla
        $conn->commit();
        
        setMessage('success', 'Teklif başarıyla silindi.');
    } catch(PDOException $e) {
        // Hata durumunda geri al
        $conn->rollBack();
        setMessage('error', 'Teklif silinirken bir hata oluştu: ' . $e->getMessage());
    }
    
    header("Location: quotations.php");
    exit;
}

// Teklif durumunu güncelleme işlemi
if (isset($_GET['updateStatus']) && is_numeric($_GET['updateStatus']) && isset($_GET['status'])) {
    $id = $_GET['updateStatus'];
    $status = $_GET['status'];
    
    // Önce kullanıcının yetkisini kontrol et
    $stmt = $conn->prepare("SELECT user_id FROM quotations WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Teklif durumunu sadece sahibi veya admin değiştirebilir
    if ($quotation['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        setMessage('error', 'Bu teklifin durumunu değiştirme yetkiniz bulunmamaktadır.');
        header("Location: quotations.php");
        exit;
    }
    
    // Geçerli statüs kontrolü
    $validStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired'];
    if (in_array($status, $validStatuses)) {
        try {
            $stmt = $conn->prepare("UPDATE quotations SET status = :status WHERE id = :id");
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            setMessage('success', 'Teklif durumu başarıyla güncellendi.');
        } catch(PDOException $e) {
            setMessage('error', 'Teklif durumu güncellenirken bir hata oluştu: ' . $e->getMessage());
        }
    } else {
        setMessage('error', 'Geçersiz teklif durumu.');
    }
    
    header("Location: quotations.php");
    exit;
}

// Filtreler
$customerFilter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFromFilter = isset($_GET['dateFrom']) ? $_GET['dateFrom'] : '';
$dateToFilter = isset($_GET['dateTo']) ? $_GET['dateTo'] : '';

// Müşteri listesini getir (filtre için)
$customers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Teklif listesini al
$quotations = [];
try {
    $sql = "SELECT q.*, c.name as customer_name 
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            WHERE 1=1";
    
    $params = [];
    
    // Filtreler uygulanır
    if ($customerFilter > 0) {
        $sql .= " AND q.customer_id = :customer_id";
        $params[':customer_id'] = $customerFilter;
    }
    
    if (!empty($statusFilter)) {
        $sql .= " AND q.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    if (!empty($dateFromFilter)) {
        $sql .= " AND q.date >= :date_from";
        $params[':date_from'] = $dateFromFilter;
    }
    
    if (!empty($dateToFilter)) {
        $sql .= " AND q.date <= :date_to";
        $params[':date_to'] = $dateToFilter;
    }
    
    $sql .= " ORDER BY q.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Teklif listesi alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Teklifler';
$currentPage = 'quotations';
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
                <h1 class="h2">Teklifler</h1>
                <a href="new_quotation.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Yeni Teklif Oluştur
                </a>
            </div>
            
            <!-- Filtreleme -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Filtrele</h5>
                </div>
                <div class="card-body">
                    <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="row">
                        <div class="col-md-3 mb-3">
                            <label for="customer" class="form-label">Müşteri</label>
                            <select class="form-select" id="customer" name="customer">
                                <option value="0">Tüm Müşteriler</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" <?php echo $customerFilter == $customer['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($customer['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="status" class="form-label">Durum</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">Tümü</option>
                                <option value="draft" <?php echo $statusFilter == 'draft' ? 'selected' : ''; ?>>Taslak</option>
                                <option value="sent" <?php echo $statusFilter == 'sent' ? 'selected' : ''; ?>>Gönderildi</option>
                                <option value="accepted" <?php echo $statusFilter == 'accepted' ? 'selected' : ''; ?>>Kabul Edildi</option>
                                <option value="rejected" <?php echo $statusFilter == 'rejected' ? 'selected' : ''; ?>>Reddedildi</option>
                                <option value="expired" <?php echo $statusFilter == 'expired' ? 'selected' : ''; ?>>Süresi Doldu</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="dateFrom" class="form-label">Başlangıç Tarihi</label>
                            <input type="date" class="form-control" id="dateFrom" name="dateFrom" value="<?php echo $dateFromFilter; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="dateTo" class="form-label">Bitiş Tarihi</label>
                            <input type="date" class="form-control" id="dateTo" name="dateTo" value="<?php echo $dateToFilter; ?>">
                        </div>
                        <div class="col-12 text-end">
                            <a href="quotations.php" class="btn btn-secondary me-2">Sıfırla</a>
                            <button type="submit" class="btn btn-primary">Filtrele</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Teklif Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($quotations) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Teklif No</th>
                                        <th>Müşteri</th>
                                        <th>Tarih</th>
                                        <th>Geçerlilik</th>
                                        <th>Tutar</th>
                                        <th>Durum</th>
                                        <th width="260">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotations as $quotation): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($quotation['reference_no']); ?></td>
                                            <td><?php echo htmlspecialchars($quotation['customer_name']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($quotation['valid_until'])); ?></td>
                                            <td><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                            <td>
                                                <?php
                                                $statusClass = 'secondary';
                                                $statusText = 'Taslak';
                                                
                                                switch($quotation['status']) {
                                                    case 'sent':
                                                        $statusClass = 'primary';
                                                        $statusText = 'Gönderildi';
                                                        break;
                                                    case 'accepted':
                                                        $statusClass = 'success';
                                                        $statusText = 'Kabul Edildi';
                                                        break;
                                                    case 'rejected':
                                                        $statusClass = 'danger';
                                                        $statusText = 'Reddedildi';
                                                        break;
                                                    case 'expired':
                                                        $statusClass = 'warning';
                                                        $statusText = 'Süresi Doldu';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                                                    <?php echo $statusText; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="view_quotation.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-info action-btn" title="Görüntüle">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <?php if ($quotation['user_id'] == $_SESSION['user_id'] || isAdmin()): // Sadece sahibi veya admin ise düzenleme, durum değiştirme ve silme butonlarını göster ?>
                                                    <a href="edit_quotation.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-warning action-btn" title="Düzenle">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="quotation_pdf.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-success action-btn" target="_blank" title="PDF">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </a>
                                                    <a href="quotation_word.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-primary action-btn" title="Word">
                                                        <i class="bi bi-file-word"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-secondary action-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Durum Değiştir">
                                                        <i class="bi bi-arrow-down-up"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="quotations.php?updateStatus=<?php echo $quotation['id']; ?>&status=draft">Taslak</a></li>
                                                        <li><a class="dropdown-item" href="quotations.php?updateStatus=<?php echo $quotation['id']; ?>&status=sent">Gönderildi</a></li>
                                                        <li><a class="dropdown-item" href="quotations.php?updateStatus=<?php echo $quotation['id']; ?>&status=accepted">Kabul Edildi</a></li>
                                                        <li><a class="dropdown-item" href="quotations.php?updateStatus=<?php echo $quotation['id']; ?>&status=rejected">Reddedildi</a></li>
                                                        <li><a class="dropdown-item" href="quotations.php?updateStatus=<?php echo $quotation['id']; ?>&status=expired">Süresi Doldu</a></li>
                                                    </ul>
                                                    <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $quotation['id']; ?>, '<?php echo htmlspecialchars(addslashes($quotation['reference_no'])); ?>')" class="btn btn-sm btn-danger action-btn" title="Sil">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                    <?php else: // Sahibi değilse sadece PDF ve Word görüntüleme butonlarını göster ?>
                                                    <a href="quotation_pdf.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-success action-btn" target="_blank" title="PDF">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </a>
                                                    <a href="quotation_word.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-primary action-btn" title="Word">
                                                        <i class="bi bi-file-word"></i>
                                                    </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz teklif bulunmamaktadır.</p>
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
                    <h5 class="modal-title" id="deleteModalLabel">Teklif Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu teklifi silmek istediğinizden emin misiniz?</p>
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
        function confirmDelete(id, referenceNo) {
            document.getElementById('deleteConfirmText').textContent = '"' + referenceNo + '" teklifini silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'quotations.php?delete=' + id;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>