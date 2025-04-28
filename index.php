<?php
// index.php - Geliştirilmiş ana sayfa / gösterge paneli
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Genel istatistikler
try {
    // Müşteri sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM customers");
    $customerCount = $stmt->fetchColumn();

    // Ürün sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM products");
    $productCount = $stmt->fetchColumn();

    // Hizmet sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM services");
    $serviceCount = $stmt->fetchColumn();

    // Teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations");
    $quotationCount = $stmt->fetchColumn();

    // Toplam teklif tutarı
    $stmt = $conn->query("SELECT SUM(total_amount) FROM quotations");
    $totalAmount = $stmt->fetchColumn() ?: 0;

    // Kabul edilen teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE status = 'accepted'");
    $acceptedCount = $stmt->fetchColumn();

    // Reddedilen teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE status = 'rejected'");
    $rejectedCount = $stmt->fetchColumn();

    // Bekleyen teklif sayısı (taslak veya gönderilmiş)
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE status IN ('draft', 'sent')");
    $pendingCount = $stmt->fetchColumn();

    // Süresi dolan teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE status = 'expired'");
    $expiredCount = $stmt->fetchColumn();

    // Stok değeri
    $stmt = $conn->query("SELECT SUM(price * stock_quantity) FROM products");
    $stockValue = $stmt->fetchColumn() ?: 0;

    // Son 5 teklif
    $stmt = $conn->query("
        SELECT q.id, q.reference_no, q.date, q.status, q.total_amount, c.name as customer_name 
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        ORDER BY q.created_at DESC
        LIMIT 5
    ");
    $recentQuotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Son 5 müşteri
    $stmt = $conn->query("
        SELECT id, name, contact_person, email, phone
        FROM customers
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Düşük stoklu ürünler (10'dan az)
    $stmt = $conn->query("
        SELECT id, code, name, stock_quantity
        FROM products
        WHERE stock_quantity < 10
        ORDER BY stock_quantity ASC
        LIMIT 5
    ");
    $lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Son 12 ay için teklif sayıları
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM quotations
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthlyQuotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Son 12 ay için teklif tutarları
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(total_amount) as total_amount,
            SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_amount
        FROM quotations
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month ASC
    ");
    $monthlyAmounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Durum dağılımı (pie chart için)
    $stmt = $conn->query("
        SELECT 
            status,
            COUNT(*) as count
        FROM quotations
        GROUP BY status
    ");
    $statusDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // En çok teklif verilen 5 müşteri
    $stmt = $conn->query("
        SELECT 
            c.id,
            c.name,
            COUNT(q.id) as quote_count,
            SUM(q.total_amount) as total_amount
        FROM customers c
        JOIN quotations q ON c.id = q.customer_id
        GROUP BY c.id, c.name
        ORDER BY quote_count DESC
        LIMIT 5
    ");
    $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // En çok teklif edilen 5 ürün
    $stmt = $conn->query("
        SELECT 
            p.id,
            p.code,
            p.name,
            COUNT(qi.id) as quote_count,
            SUM(qi.quantity) as total_quantity
        FROM products p
        JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product'
        GROUP BY p.id, p.code, p.name
        ORDER BY quote_count DESC
        LIMIT 5
    ");
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    setMessage('error', 'İstatistikler alınırken bir hata oluştu: ' . $e->getMessage());
}

// Türkçe ay isimleri
function getTurkishMonth($monthNumber)
{
    $months = [
        1 => 'Ocak',
        2 => 'Şubat',
        3 => 'Mart',
        4 => 'Nisan',
        5 => 'Mayıs',
        6 => 'Haziran',
        7 => 'Temmuz',
        8 => 'Ağustos',
        9 => 'Eylül',
        10 => 'Ekim',
        11 => 'Kasım',
        12 => 'Aralık'
    ];
    return $months[$monthNumber];
}

// Chart verileri hazırlama
$chartMonths = [];
$chartQuotationCounts = [];
$chartAcceptedCounts = [];
$chartRejectedCounts = [];

$chartAmountMonths = [];
$chartTotalAmounts = [];
$chartAcceptedAmounts = [];

// Son 12 ay için verileri hazırla
foreach ($monthlyQuotations as $monthData) {
    $parts = explode('-', $monthData['month']);
    $monthName = getTurkishMonth(intval($parts[1])) . ' ' . $parts[0];

    $chartMonths[] = $monthName;
    $chartQuotationCounts[] = $monthData['total'];
    $chartAcceptedCounts[] = $monthData['accepted'];
    $chartRejectedCounts[] = $monthData['rejected'];
}

foreach ($monthlyAmounts as $monthData) {
    $parts = explode('-', $monthData['month']);
    $monthName = getTurkishMonth(intval($parts[1])) . ' ' . $parts[0];

    $chartAmountMonths[] = $monthName;
    $chartTotalAmounts[] = $monthData['total_amount'];
    $chartAcceptedAmounts[] = $monthData['accepted_amount'];
}

// Pie chart verileri
$statusLabels = [
    'draft' => 'Taslak',
    'sent' => 'Gönderildi',
    'accepted' => 'Kabul Edildi',
    'rejected' => 'Reddedildi',
    'expired' => 'Süresi Doldu'
];

$statusColors = [
    'draft' => '#6c757d',    // secondary
    'sent' => '#007bff',     // primary
    'accepted' => '#28a745', // success
    'rejected' => '#dc3545', // danger
    'expired' => '#ffc107'   // warning
];

$pieLabels = [];
$pieData = [];
$pieColors = [];

foreach ($statusDistribution as $status) {
    $pieLabels[] = $statusLabels[$status['status']];
    $pieData[] = $status['count'];
    $pieColors[] = $statusColors[$status['status']];
}
$needsChartJS = true;
$pageTitle = 'Gösterge Paneli';
$currentPage = 'index';

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

            <h1 class="h2 mb-4">Gösterge Paneli</h1>

            <!-- Genel İstatistikler -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Toplam Teklif</h6>
                                    <h2 class="card-title stats-number"><?php echo $quotationCount; ?></h2>
                                </div>
                                <div class="stats-icon text-primary">
                                    <i class="bi bi-file-text"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="quotations.php" class="card-link">Tüm Teklifler <i
                                        class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Kabul Edilen Teklifler</h6>
                                    <h2 class="card-title stats-number"><?php echo $acceptedCount; ?></h2>
                                </div>
                                <div class="stats-icon text-success">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 10px;">
                                <div class="progress-bar bg-success" role="progressbar"
                                    style="width: <?php echo $quotationCount > 0 ? ($acceptedCount / $quotationCount * 100) : 0; ?>%"
                                    aria-valuenow="<?php echo $acceptedCount; ?>" aria-valuemin="0"
                                    aria-valuemax="<?php echo $quotationCount; ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Toplam Müşteri</h6>
                                    <h2 class="card-title stats-number"><?php echo $customerCount; ?></h2>
                                </div>
                                <div class="stats-icon text-info">
                                    <i class="bi bi-people"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="customers.php" class="card-link">Tüm Müşteriler <i
                                        class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Toplam Stok Değeri</h6>
                                    <h2 class="card-title stats-number">
                                        <?php echo number_format($stockValue, 2, ',', '.') . ' ₺'; ?></h2>
                                </div>
                                <div class="stats-icon text-warning">
                                    <i class="bi bi-box"></i>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="inventory.php" class="card-link">Stok Durumu <i
                                        class="bi bi-arrow-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Teklif Durumları -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body stats-card stats-card-primary">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Gönderilen</h6>
                                    <h2 class="card-title stats-number"><?php echo $pendingCount; ?></h2>
                                </div>
                                <div class="stats-icon text-primary">
                                    <i class="bi bi-send"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body stats-card stats-card-success">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Kabul Edilen</h6>
                                    <h2 class="card-title stats-number"><?php echo $acceptedCount; ?></h2>
                                </div>
                                <div class="stats-icon text-success">
                                    <i class="bi bi-check2-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body stats-card stats-card-danger">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Reddedilen</h6>
                                    <h2 class="card-title stats-number"><?php echo $rejectedCount; ?></h2>
                                </div>
                                <div class="stats-icon text-danger">
                                    <i class="bi bi-x-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="card card-dashboard">
                        <div class="card-body stats-card stats-card-warning">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="card-subtitle mb-2 text-muted">Süresi Dolan</h6>
                                    <h2 class="card-title stats-number"><?php echo $expiredCount; ?></h2>
                                </div>
                                <div class="stats-icon text-warning">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grafikler -->
            <div class="row mb-4">
                <!-- Teklif Sayısı Grafiği -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Aylık Teklif Sayıları</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="quotationCountChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teklif Tutarı Grafiği -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Aylık Teklif Tutarları</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="quotationAmountChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <!-- Teklif Durumu Pasta Grafiği -->
                <div class="col-md-4 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Durumları</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statusPieChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- En Çok Teklif Verilen Müşteriler -->
                <div class="col-md-4 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">En Çok Teklif Verilen Müşteriler</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($topCustomers) > 0): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($topCustomers as $customer): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <a href="view_customer.php?id=<?php echo $customer['id']; ?>"
                                                    class="text-decoration-none">
                                                    <?php echo htmlspecialchars($customer['name']); ?>
                                                </a>
                                            </div>
                                            <span class="badge bg-primary rounded-pill"><?php echo $customer['quote_count']; ?>
                                                Teklif</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-center p-3">Henüz veri bulunmamaktadır.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- En Çok Teklif Edilen Ürünler -->
                <div class="col-md-4 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">En Çok Teklif Edilen Ürünler</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($topProducts) > 0): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($topProducts as $product): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <a href="view_product.php?id=<?php echo $product['id']; ?>"
                                                    class="text-decoration-none">
                                                    <?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?>
                                                </a>
                                            </div>
                                            <span class="badge bg-info rounded-pill"><?php echo $product['total_quantity']; ?>
                                                Adet</span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-center p-3">Henüz veri bulunmamaktadır.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <!-- Son Teklifler -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Son Teklifler</h5>
                            <a href="quotations.php" class="btn btn-sm btn-primary">Tümünü Gör</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($recentQuotations) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Teklif No</th>
                                                <th>Müşteri</th>
                                                <th>Tarih</th>
                                                <th>Tutar</th>
                                                <th>Durum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentQuotations as $quotation): ?>
                                                <tr>
                                                    <td>
                                                        <a href="view_quotation.php?id=<?php echo $quotation['id']; ?>"
                                                            class="text-decoration-none">
                                                            <?php echo htmlspecialchars($quotation['reference_no']); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($quotation['customer_name']); ?></td>
                                                    <td><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></td>
                                                    <td><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $statusClass = 'secondary';
                                                        $statusText = 'Taslak';

                                                        switch ($quotation['status']) {
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
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-center p-3">Henüz teklif bulunmamaktadır.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Düşük Stoklu Ürünler -->
                <div class="col-md-6 mb-4">
                    <div class="card card-dashboard h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Düşük Stoklu Ürünler</h5>
                            <a href="inventory.php" class="btn btn-sm btn-primary">Stok Takibi</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($lowStockProducts) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Ürün Kodu</th>
                                                <th>Ürün Adı</th>
                                                <th>Stok</th>
                                                <th>Durum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($lowStockProducts as $product): ?>
                                                <tr>
                                                    <td>
                                                        <a href="view_product.php?id=<?php echo $product['id']; ?>"
                                                            class="text-decoration-none">
                                                            <?php echo htmlspecialchars($product['code']); ?>
                                                        </a>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                    <td><?php echo $product['stock_quantity']; ?></td>
                                                    <td>
                                                        <?php
                                                        if ($product['stock_quantity'] <= 0) {
                                                            echo '<span class="badge bg-danger">Stok Yok</span>';
                                                        } elseif ($product['stock_quantity'] < 5) {
                                                            echo '<span class="badge bg-warning">Kritik</span>';
                                                        } else {
                                                            echo '<span class="badge bg-info">Az</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success m-3">
                                    <i class="bi bi-check-circle"></i> Tüm ürünler yeterli stok seviyesinde.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hızlı Erişim -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card card-dashboard">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Hızlı Erişim</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <a href="new_quotation.php" class="btn btn-primary w-100 py-3">
                                        <i class="bi bi-file-earmark-plus fs-4 d-block mb-2"></i>
                                        Yeni Teklif Oluştur
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="create_invoice.php" class="btn btn-success w-100 py-3">
                                        <i class="bi bi-receipt fs-4 d-block mb-2"></i>
                                        Yeni Fatura Oluştur
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="add_customer.php" class="btn btn-info w-100 py-3">
                                        <i class="bi bi-person-plus fs-4 d-block mb-2"></i>
                                        Yeni Müşteri Ekle
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="add_product.php" class="btn btn-warning w-100 py-3">
                                        <i class="bi bi-box-seam fs-4 d-block mb-2"></i>
                                        Yeni Ürün Ekle
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Aylık Teklif Sayıları Grafiği
        const quotationCountCtx = document.getElementById('quotationCountChart').getContext('2d');
        const quotationCountChart = new Chart(quotationCountCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartMonths); ?>,
                datasets: [
                    {
                        label: 'Toplam Teklif',
                        data: <?php echo json_encode($chartQuotationCounts); ?>,
                        backgroundColor: 'rgba(0, 123, 255, 0.2)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Kabul Edilen',
                        data: <?php echo json_encode($chartAcceptedCounts); ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.2)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 2,
                        tension: 0.4
                    },
                    {
                        label: 'Reddedilen',
                        data: <?php echo json_encode($chartRejectedCounts); ?>,
                        backgroundColor: 'rgba(220, 53, 69, 0.2)',
                        borderColor: 'rgba(220, 53, 69, 1)',
                        borderWidth: 2,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    },
                    legend: {
                        position: 'top',
                    }
                }
            }
        });

        // Aylık Teklif Tutarları Grafiği
        const quotationAmountCtx = document.getElementById('quotationAmountChart').getContext('2d');
        const quotationAmountChart = new Chart(quotationAmountCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartAmountMonths); ?>,
                datasets: [
                    {
                        label: 'Toplam Tutar',
                        data: <?php echo json_encode($chartTotalAmounts); ?>,
                        backgroundColor: 'rgba(0, 123, 255, 0.7)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Kabul Edilen Tutar',
                        data: <?php echo json_encode($chartAcceptedAmounts); ?>,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: 'rgba(40, 167, 69, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value, index, values) {
                                return value.toLocaleString('tr-TR') + ' ₺';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' +
                                    parseFloat(context.raw).toLocaleString('tr-TR') + ' ₺';
                            }
                        }
                    },
                    legend: {
                        position: 'top',
                    }
                }
            }
        });

        // Teklif Durumları Pasta Grafiği
        const statusPieCtx = document.getElementById('statusPieChart').getContext('2d');
        const statusPieChart = new Chart(statusPieCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($pieLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($pieData); ?>,
                    backgroundColor: <?php echo json_encode($pieColors); ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((context.raw / total) * 100);
                                return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>