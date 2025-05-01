<?php
// production.php - Üretim bölümü ana sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece üretim rolü için erişim
requireProduction();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Bekleyen üretim siparişleri
$pendingOrders = [];
try {
    $stmt = $conn->query("
        SELECT po.*, q.reference_no, q.date, c.name as customer_name
        FROM production_orders po
        JOIN quotations q ON po.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        WHERE po.status IN ('pending', 'in_progress')
        ORDER BY po.delivery_deadline ASC
    ");
    $pendingOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Üretim siparişleri alınırken bir hata oluştu: ' . $e->getMessage());
}

// Tamamlanan üretim siparişleri (son 10)
$completedOrders = [];
try {
    $stmt = $conn->query("
        SELECT po.*, q.reference_no, q.date, c.name as customer_name
        FROM production_orders po
        JOIN quotations q ON po.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        WHERE po.status = 'completed'
        ORDER BY po.updated_at DESC
        LIMIT 10
    ");
    $completedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Tamamlanan siparişler alınırken bir hata oluştu: ' . $e->getMessage());
}

// Yaklaşan teslim tarihleri
$upcomingDeadlines = [];
try {
    $stmt = $conn->query("
        SELECT po.*, q.reference_no, c.name as customer_name
        FROM production_orders po
        JOIN quotations q ON po.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        WHERE po.status IN ('pending', 'in_progress') 
        AND po.delivery_deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY po.delivery_deadline ASC
    ");
    $upcomingDeadlines = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Yaklaşan teslim tarihleri alınırken bir hata oluştu: ' . $e->getMessage());
}

// Okunmamış bildirimler
$unreadNotifications = [];
try {
    $stmt = $conn->prepare("
        SELECT * FROM notifications 
        WHERE user_id = :user_id 
        AND is_read = 0
        ORDER BY created_at DESC
    ");
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $unreadNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Bildirimler alınırken bir hata oluştu: ' . $e->getMessage());
}

$pageTitle = 'Üretim Departmanı';
$currentPage = 'production';
$needsChartJS = true;
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
        
        <!-- Yeni Üretim Bildirimleri -->
        <?php if (!empty($unreadNotifications)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <h4 class="alert-heading">Yeni Bildirimler!</h4>
                <ul class="mb-0">
                    <?php foreach($unreadNotifications as $notification): ?>
                        <li><?php echo $notification['message']; ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <hr>
                <a href="mark_notifications_read.php" class="btn btn-sm btn-light">Tümünü Okundu İşaretle</a>
            </div>
        <?php endif; ?>
        
        <h1 class="h2 mb-4">Üretim Departmanı Gösterge Paneli</h1>
        
        <!-- Özet İstatistikler -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card card-dashboard">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Bekleyen Üretim</h6>
                                <h2 class="card-title stats-number"><?php echo count($pendingOrders); ?></h2>
                            </div>
                            <div class="stats-icon text-primary">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="production_orders.php?status=pending" class="card-link">Bekleyen Siparişler <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card card-dashboard">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Devam Eden Üretim</h6>
                                <h2 class="card-title stats-number">
                                    <?php 
                                    $inProgressCount = 0;
                                    foreach($pendingOrders as $order) {
                                        if ($order['status'] == 'in_progress') $inProgressCount++;
                                    }
                                    echo $inProgressCount;
                                    ?>
                                </h2>
                            </div>
                            <div class="stats-icon text-warning">
                                <i class="bi bi-gear-wide-connected"></i>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="production_orders.php?status=in_progress" class="card-link">Devam Eden Üretimler <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card card-dashboard">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Yaklaşan Teslimler</h6>
                                <h2 class="card-title stats-number"><?php echo count($upcomingDeadlines); ?></h2>
                            </div>
                            <div class="stats-icon text-danger">
                                <i class="bi bi-calendar-check"></i>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="production_orders.php?filter=upcoming" class="card-link">Yaklaşan Teslimler <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card card-dashboard">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Tamamlanan Üretim</h6>
                                <h2 class="card-title stats-number"><?php echo count($completedOrders); ?></h2>
                            </div>
                            <div class="stats-icon text-success">
                                <i class="bi bi-check-circle"></i>
                            </div>
                        </div>
                        <div class="mt-3">
                            <a href="production_orders.php?status=completed" class="card-link">Tamamlanan Üretimler <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Yaklaşan Teslim Tarihleri -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Yaklaşan Teslim Tarihleri</h5>
                        <span class="badge bg-danger"><?php echo count($upcomingDeadlines); ?> teslim</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($upcomingDeadlines) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Teklif No</th>
                                            <th>Müşteri</th>
                                            <th>Teslim Tarihi</th>
                                            <th>Tamamlanma</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcomingDeadlines as $order): ?>
                                            <?php 
                                            $completion = 0;
                                            if ($order['total_quantity'] > 0) {
                                                $completion = round(($order['completed_quantity'] / $order['total_quantity']) * 100);
                                            }
                                            
                                            $deadlineClass = '';
                                            $deadlineDays = floor((strtotime($order['delivery_deadline']) - time()) / (60 * 60 * 24));
                                            
                                            if ($deadlineDays <= 1) {
                                                $deadlineClass = 'table-danger';
                                            } else if ($deadlineDays <= 3) {
                                                $deadlineClass = 'table-warning';
                                            }
                                            ?>
                                            <tr class="<?php echo $deadlineClass; ?>">
                                                <td><?php echo htmlspecialchars($order['reference_no']); ?></td>
                                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                                <td>
                                                    <strong><?php echo date('d.m.Y', strtotime($order['delivery_deadline'])); ?></strong>
                                                    <?php if ($deadlineDays <= 1): ?>
                                                        <span class="badge bg-danger">Acil</span>
                                                    <?php elseif ($deadlineDays <= 3): ?>
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
                                                    if ($order['status'] == 'pending') {
                                                        echo '<span class="badge bg-secondary">Bekliyor</span>';
                                                    } else if ($order['status'] == 'in_progress') {
                                                        echo '<span class="badge bg-primary">Devam Ediyor</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="view_production_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> Görüntüle
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info m-3">7 gün içinde yaklaşan teslim tarihi bulunmuyor.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bekleyen Üretim Siparişleri -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Bekleyen Üretim Siparişleri</h5>
                        <a href="production_orders.php" class="btn btn-sm btn-primary">Tüm Siparişler</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (count($pendingOrders) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
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
                                        <?php foreach ($pendingOrders as $order): ?>
                                            <?php 
                                            $completion = 0;
                                            if ($order['total_quantity'] > 0) {
                                                $completion = round(($order['completed_quantity'] / $order['total_quantity']) * 100);
                                            }
                                            ?>
                                            <tr>
                                                <td>#<?php echo $order['id']; ?></td>
                                                <td><?php echo htmlspecialchars($order['reference_no']); ?></td>
                                                <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                                <td><?php echo date('d.m.Y', strtotime($order['delivery_deadline'])); ?></td>
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
                                                    if ($order['status'] == 'pending') {
                                                        echo '<span class="badge bg-secondary">Bekliyor</span>';
                                                    } else if ($order['status'] == 'in_progress') {
                                                        echo '<span class="badge bg-primary">Devam Ediyor</span>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <a href="view_production_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-eye"></i> Görüntüle
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info m-3">Bekleyen üretim siparişi bulunmuyor.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Bildirim sesi için
function playNotificationSound() {
    const audio = new Audio('assets/notification.mp3');
    audio.play();
}

// Yeni bildirim varsa ses çal
document.addEventListener('DOMContentLoaded', function() {
    const notifications = <?php echo json_encode($unreadNotifications); ?>;
    if (notifications.length > 0) {
        playNotificationSound();
    }
});
</script>

<?php include 'includes/footer.php'; ?>