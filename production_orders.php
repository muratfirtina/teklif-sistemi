<?php
// production_orders.php - Üretim siparişleri listesi
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece üretim rolü için erişim
requireProduction();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Filtreler
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$filterType = isset($_GET['filter']) ? $_GET['filter'] : '';
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';

// SQL sorgusu
$sql = "
    SELECT po.*, q.reference_no, q.date, c.name as customer_name
    FROM production_orders po
    JOIN quotations q ON po.quotation_id = q.id
    JOIN customers c ON q.customer_id = c.id
    WHERE 1=1
";

$params = [];

// Durum filtresi
if (!empty($statusFilter)) {
    $sql .= " AND po.status = :status";
    $params[':status'] = $statusFilter;
}

// Özel filtreler
if ($filterType == 'upcoming') {
    $sql .= " AND po.delivery_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filterType == 'overdue') {
    $sql .= " AND po.delivery_deadline < CURDATE() AND po.status != 'completed'";
}

// Arama filtresi
if (!empty($searchQuery)) {
    $sql .= " AND (q.reference_no LIKE :search OR c.name LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

// Sıralama
$sql .= " ORDER BY ";
if ($filterType == 'upcoming' || $filterType == 'overdue') {
    $sql .= "po.delivery_deadline ASC";
} else {
    $sql .= "po.created_at DESC";
}

// Üretim siparişlerini al
$orders = [];
try {
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Üretim siparişleri alınırken bir hata oluştu: ' . $e->getMessage());
}

$pageTitle = 'Üretim Siparişleri';
$currentPage = 'production';
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
            <h1 class="h2">Üretim Siparişleri</h1>
            <a href="production.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Üretim Paneline Dön
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
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tüm Durumlar</option>
                            <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Bekliyor</option>
                            <option value="in_progress" <?php echo $statusFilter == 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                            <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                            <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="filter" class="form-label">Özel Filtreler</label>
                        <select class="form-select" id="filter" name="filter">
                            <option value="">Filtre Seçin</option>
                            <option value="upcoming" <?php echo $filterType == 'upcoming' ? 'selected' : ''; ?>>Yaklaşan Teslimler (7 gün)</option>
                            <option value="overdue" <?php echo $filterType == 'overdue' ? 'selected' : ''; ?>>Gecikmiş Teslimler</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label for="search" class="form-label">Arama</label>
                        <input type="text" class="form-control" id="search" name="search" placeholder="Teklif No veya Müşteri Adı" value="<?php echo htmlspecialchars($searchQuery); ?>">
                    </div>
                    <div class="col-md-2 mb-3 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary">Filtrele</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Üretim Siparişleri Tablosu -->
        <div class="card">
            <div class="card-body">
                <?php if (count($orders) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Sipariş ID</th>
                                    <th>Teklif No</th>
                                    <th>Müşteri</th>
                                    <th>Teslim Tarihi</th>
                                    <th>Tamamlanma</th>
                                    <th>Durum</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <?php 
                                    $completion = 0;
                                    if ($order['total_quantity'] > 0) {
                                        $completion = round(($order['completed_quantity'] / $order['total_quantity']) * 100);
                                    }
                                    
                                    $rowClass = '';
                                    if ($order['delivery_deadline'] < date('Y-m-d') && $order['status'] != 'completed') {
                                        $rowClass = 'table-danger'; // Gecikmiş
                                    } elseif (strtotime($order['delivery_deadline']) <= strtotime('+3 days') && $order['status'] != 'completed') {
                                        $rowClass = 'table-warning'; // Yaklaşan
                                    }
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td>#<?php echo $order['id']; ?></td>
                                        <td><?php echo htmlspecialchars($order['reference_no']); ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                        <td>
                                            <?php echo date('d.m.Y', strtotime($order['delivery_deadline'])); ?>
                                            <?php if ($order['delivery_deadline'] < date('Y-m-d') && $order['status'] != 'completed'): ?>
                                                <span class="badge bg-danger">Gecikmiş</span>
                                            <?php elseif (strtotime($order['delivery_deadline']) <= strtotime('+3 days') && $order['status'] != 'completed'): ?>
                                                <span class="badge bg-warning">Yakın</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar <?php echo ($completion < 50) ? 'bg-warning' : 'bg-success'; ?>" 
                                                     role="progressbar" style="width: <?php echo $completion; ?>%;" 
                                                     aria-valuenow="<?php echo $completion; ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <?php echo $completion; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            switch ($order['status']) {
                                                case 'pending':
                                                    echo '<span class="badge bg-secondary">Bekliyor</span>';
                                                    break;
                                                case 'in_progress':
                                                    echo '<span class="badge bg-primary">Devam Ediyor</span>';
                                                    break;
                                                case 'completed':
                                                    echo '<span class="badge bg-success">Tamamlandı</span>';
                                                    break;
                                                case 'cancelled':
                                                    echo '<span class="badge bg-danger">İptal</span>';
                                                    break;
                                                default:
                                                    echo '<span class="badge bg-secondary">Bilinmiyor</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <a href="view_production_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-primary" title="Görüntüle">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                                                <a href="update_production_status.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-success" title="Durumu Güncelle">
                                                    <i class="bi bi-arrow-clockwise"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-center">Kriterlere uygun üretim siparişi bulunamadı.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>