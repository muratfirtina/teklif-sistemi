<?php
// report_sales.php - Satış performansı raporu
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Varsayılan tarih aralığı: Son 12 ay
$defaultEndDate = date('Y-m-d');
$defaultStartDate = date('Y-m-d', strtotime('-1 year'));

// Filtre parametreleri
$startDate = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : $defaultStartDate;
$endDate = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : $defaultEndDate;
$displayType = isset($_GET['display_type']) ? $_GET['display_type'] : 'monthly';
$compareLastYear = isset($_GET['compare_last_year']) ? true : false;

// Excel export işlemi
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // Excel ile ilgili işlemler burada olacak
    // ...
}

// Toplam satış verileri
$totalSales = [];
try {
    // Satış verilerini hesapla
    // Bu örnekte tekliflerden kabul edilenlerin toplamları alınıyor
    $stmt = $conn->prepare("
        SELECT 
            YEAR(date) as year,
            MONTH(date) as month,
            COUNT(*) as total_count,
            SUM(total_amount) as total_amount,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
            SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_amount,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN status = 'rejected' THEN total_amount ELSE 0 END) as rejected_amount
        FROM quotations
        WHERE date BETWEEN :start_date AND :end_date
        GROUP BY YEAR(date), MONTH(date)
        ORDER BY YEAR(date), MONTH(date) ASC
    ");
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Geçen yıl verileri (karşılaştırma için)
    if ($compareLastYear) {
        $lastYearStartDate = date('Y-m-d', strtotime('-1 year', strtotime($startDate)));
        $lastYearEndDate = date('Y-m-d', strtotime('-1 year', strtotime($endDate)));
        
        $stmt = $conn->prepare("
            SELECT 
                YEAR(date) as year,
                MONTH(date) as month,
                COUNT(*) as total_count,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_amount
            FROM quotations
            WHERE date BETWEEN :start_date AND :end_date
            GROUP BY YEAR(date), MONTH(date)
            ORDER BY YEAR(date), MONTH(date) ASC
        ");
        $stmt->bindParam(':start_date', $lastYearStartDate);
        $stmt->bindParam(':end_date', $lastYearEndDate);
        $stmt->execute();
        $lastYearSalesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Toplam satış rakamları
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_count,
            SUM(total_amount) as total_amount,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
            SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_amount,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
            SUM(CASE WHEN status = 'rejected' THEN total_amount ELSE 0 END) as rejected_amount
        FROM quotations
        WHERE date BETWEEN :start_date AND :end_date
    ");
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $totalSales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Müşteri bazlı satışlar
    $stmt = $conn->prepare("
        SELECT 
            c.id,
            c.name as customer_name,
            COUNT(q.id) as quotation_count,
            SUM(q.total_amount) as total_amount,
            SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
            SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) as accepted_amount
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        WHERE q.date BETWEEN :start_date AND :end_date
        GROUP BY c.id, c.name
        ORDER BY accepted_amount DESC
        LIMIT 10
    ");
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ürün bazlı satışlar
    $stmt = $conn->prepare("
        SELECT 
            p.id,
            p.code,
            p.name,
            COUNT(qi.id) as quote_count,
            SUM(qi.quantity) as total_quantity,
            SUM(qi.subtotal) as total_amount
        FROM products p
        JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product'
        JOIN quotations q ON qi.quotation_id = q.id AND q.status = 'accepted'
        WHERE q.date BETWEEN :start_date AND :end_date
        GROUP BY p.id, p.code, p.name
        ORDER BY total_amount DESC
        LIMIT 10
    ");
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    setMessage('error', 'Rapor verileri alınırken bir hata oluştu: ' . $e->getMessage());
}

// Grafik verileri hazırlama
$chartLabels = [];
$chartTotalAmounts = [];
$chartAcceptedAmounts = [];
$chartLastYearAmounts = [];

// Türkçe ay isimleri
function getTurkishMonth($monthNumber) {
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

// Aylık veriler için grafik hazırla
foreach ($salesData as $data) {
    $monthLabel = getTurkishMonth($data['month']) . ' ' . $data['year'];
    $chartLabels[] = $monthLabel;
    $chartTotalAmounts[] = $data['total_amount'];
    $chartAcceptedAmounts[] = $data['accepted_amount'];
}

// Geçen yıl verileri
if ($compareLastYear && isset($lastYearSalesData)) {
    foreach ($lastYearSalesData as $data) {
        $chartLastYearAmounts[] = $data['accepted_amount'];
    }
}

// Başarı oranı hesaplama
$successRate = 0;
if ($totalSales['total_count'] > 0) {
    $successRate = round(($totalSales['accepted_count'] / $totalSales['total_count']) * 100, 2);
}

$needsChartJS = true;
$pageTitle = 'Satış Performansı Raporu';
$currentPage = 'reports';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<style>
    .stats-card {
        transition: transform 0.3s ease-in-out;
    }
    .stats-card:hover {
        transform: translateY(-5px);
    }
    .filter-form {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }
    .chart-container {
        position: relative;
        height: 400px;
        margin-bottom: 20px;
    }
    .sales-table th {
        white-space: nowrap;
    }
</style>

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
            <h1 class="h2">Satış Performansı Raporu</h1>
            <div>
                <a href="reports.php" class="btn btn-secondary me-2">
                    <i class="bi bi-arrow-left"></i> Raporlara Dön
                </a>
                <a href="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=excel'); ?>" class="btn btn-success">
                    <i class="bi bi-file-excel"></i> Excel'e Aktar
                </a>
            </div>
        </div>
        
        <!-- Filtreler -->
        <div class="filter-form">
            <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="row g-3">
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Başlangıç Tarihi</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">Bitiş Tarihi</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                </div>
                <div class="col-md-3">
                    <label for="display_type" class="form-label">Görüntüleme Türü</label>
                    <select class="form-select" id="display_type" name="display_type">
                        <option value="monthly" <?php echo $displayType == 'monthly' ? 'selected' : ''; ?>>Aylık</option>
                        <option value="quarterly" <?php echo $displayType == 'quarterly' ? 'selected' : ''; ?>>Üç Aylık</option>
                        <option value="yearly" <?php echo $displayType == 'yearly' ? 'selected' : ''; ?>>Yıllık</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="compare_last_year" name="compare_last_year" <?php echo $compareLastYear ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="compare_last_year">
                            Geçen Yıl ile Karşılaştır
                        </label>
                    </div>
                </div>
                <div class="col-12 text-end">
                    <a href="report_sales.php" class="btn btn-secondary me-2">Sıfırla</a>
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                </div>
            </form>
        </div>
        
        <!-- Özet İstatistikler -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Toplam Teklif Sayısı</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="display-4 mb-0"><?php echo $totalSales['total_count']; ?></h2>
                            <div class="text-white">
                                <i class="bi bi-file-earmark-text fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Toplam Satış Tutarı</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="display-6 mb-0"><?php echo number_format($totalSales['accepted_amount'], 2, ',', '.') . ' ₺'; ?></h2>
                            <div class="text-white">
                                <i class="bi bi-cash-stack fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Kabul Edilen Teklifler</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="display-4 mb-0"><?php echo $totalSales['accepted_count']; ?></h2>
                                <small><?php echo number_format(($totalSales['total_count'] > 0 ? ($totalSales['accepted_count'] / $totalSales['total_count']) * 100 : 0), 2); ?>%</small>
                            </div>
                            <div class="text-white">
                                <i class="bi bi-check-circle fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card stats-card bg-warning text-dark">
                    <div class="card-body">
                        <h5 class="card-title">Başarı Oranı</h5>
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="display-4 mb-0">%<?php echo $successRate; ?></h2>
                            <div class="text-dark">
                                <i class="bi bi-award fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Grafik -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Aylık Satış Performansı</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <!-- Top Müşteriler -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En İyi 10 Müşteri</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($topCustomers) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover sales-table">
                                    <thead>
                                        <tr>
                                            <th>Müşteri</th>
                                            <th class="text-center">Teklif Sayısı</th>
                                            <th class="text-center">Kabul Edilen</th>
                                            <th class="text-end">Satış Tutarı</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topCustomers as $customer): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                                <td class="text-center"><?php echo $customer['quotation_count']; ?></td>
                                                <td class="text-center"><?php echo $customer['accepted_count']; ?></td>
                                                <td class="text-end"><?php echo number_format($customer['accepted_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Seçilen tarih aralığında müşteri verisi bulunamadı.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Top Ürünler -->
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En Çok Satan 10 Ürün</h5>
                    </div>
                    <div class="card-body">
                        <?php if (count($topProducts) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover sales-table">
                                    <thead>
                                        <tr>
                                            <th>Ürün</th>
                                            <th class="text-center">Satış Adedi</th>
                                            <th class="text-end">Satış Tutarı</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topProducts as $product): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?></td>
                                                <td class="text-center"><?php echo $product['total_quantity']; ?></td>
                                                <td class="text-end"><?php echo number_format($product['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Seçilen tarih aralığında ürün satış verisi bulunamadı.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Aylık Satış Verileri -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Aylık Satış Verileri</h5>
            </div>
            <div class="card-body">
                <?php if (count($salesData) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover sales-table">
                            <thead>
                                <tr>
                                    <th>Ay</th>
                                    <th class="text-center">Teklif Sayısı</th>
                                    <th class="text-end">Teklif Tutarı</th>
                                    <th class="text-center">Kabul Edilen</th>
                                    <th class="text-end">Kabul Edilen Tutar</th>
                                    <th class="text-center">Reddedilen</th>
                                    <th class="text-end">Reddedilen Tutar</th>
                                    <th class="text-center">Başarı Oranı</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($salesData as $data): ?>
                                    <tr>
                                        <td><?php echo getTurkishMonth($data['month']) . ' ' . $data['year']; ?></td>
                                        <td class="text-center"><?php echo $data['total_count']; ?></td>
                                        <td class="text-end"><?php echo number_format($data['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        <td class="text-center"><?php echo $data['accepted_count']; ?></td>
                                        <td class="text-end"><?php echo number_format($data['accepted_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        <td class="text-center"><?php echo $data['rejected_count']; ?></td>
                                        <td class="text-end"><?php echo number_format($data['rejected_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        <td class="text-center">
                                            <?php 
                                                $monthSuccessRate = ($data['total_count'] > 0) ? round(($data['accepted_count'] / $data['total_count']) * 100, 2) : 0;
                                                echo '%' . $monthSuccessRate;
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-dark">
                                    <th>TOPLAM</th>
                                    <th class="text-center"><?php echo $totalSales['total_count']; ?></th>
                                    <th class="text-end"><?php echo number_format($totalSales['total_amount'], 2, ',', '.') . ' ₺'; ?></th>
                                    <th class="text-center"><?php echo $totalSales['accepted_count']; ?></th>
                                    <th class="text-end"><?php echo number_format($totalSales['accepted_amount'], 2, ',', '.') . ' ₺'; ?></th>
                                    <th class="text-center"><?php echo $totalSales['rejected_count']; ?></th>
                                    <th class="text-end"><?php echo number_format($totalSales['rejected_amount'], 2, ',', '.') . ' ₺'; ?></th>
                                    <th class="text-center">%<?php echo $successRate; ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Seçilen tarih aralığında satış verisi bulunamadı.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Satış Grafiği
        const salesChartCtx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(salesChartCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Toplam Teklif Tutarı',
                        data: <?php echo json_encode($chartTotalAmounts); ?>,
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Kabul Edilen Tutar',
                        data: <?php echo json_encode($chartAcceptedAmounts); ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    }
                    <?php if ($compareLastYear && !empty($chartLastYearAmounts)): ?>,
                    {
                        label: 'Geçen Yıl',
                        data: <?php echo json_encode($chartLastYearAmounts); ?>,
                        type: 'line',
                        fill: false,
                        backgroundColor: 'rgba(255, 99, 132, 0.2)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        pointRadius: 3
                    }
                    <?php endif; ?>
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('tr-TR') + ' ₺';
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
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
    });
</script>
</body>
</html>