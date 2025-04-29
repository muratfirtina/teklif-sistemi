<?php
// report_products.php - Ürün raporları sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

$conn = getDbConnection();

// Filtreler
$minStock = isset($_GET['min_stock']) && is_numeric($_GET['min_stock']) ? intval($_GET['min_stock']) : null;
$maxStock = isset($_GET['max_stock']) && is_numeric($_GET['max_stock']) ? intval($_GET['max_stock']) : null;
$minPrice = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? floatval($_GET['min_price']) : null;
$maxPrice = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? floatval($_GET['max_price']) : null;

// Rapor verilerini çek (Ürünler için)
$productReports = [];
try {
    $sql = "SELECT
                p.id,
                p.code,
                p.name,
                p.price,
                p.stock_quantity,
                (p.price * p.stock_quantity) AS stock_value,
                COUNT(DISTINCT qi.quotation_id) AS times_quoted,
                SUM(qi.quantity) AS total_quoted_quantity,
                SUM(qi.subtotal) AS total_quoted_value
            FROM products p
            LEFT JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product' ";

    $conditions = [];
    $params = [];

    if ($minStock !== null) {
        $conditions[] = "p.stock_quantity >= :min_stock";
        $params[':min_stock'] = $minStock;
    }
    if ($maxStock !== null) {
        $conditions[] = "p.stock_quantity <= :max_stock";
        $params[':max_stock'] = $maxStock;
    }
    
    if ($minPrice !== null) {
        $conditions[] = "p.price >= :min_price";
        $params[':min_price'] = $minPrice;
    }
    
    if ($maxPrice !== null) {
        $conditions[] = "p.price <= :max_price";
        $params[':max_price'] = $maxPrice;
    }

    if(!empty($conditions)){
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }


    $sql .= " GROUP BY p.id, p.code, p.name, p.price, p.stock_quantity ";
    $sql .= " ORDER BY total_quoted_value DESC, times_quoted DESC, p.name ASC";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $productReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    setMessage('error', 'Ürün rapor verileri alınırken hata oluştu: ' . $e->getMessage());
}

// Stokta olmayan ürünleri getir
$outOfStockCount = 0;
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= 0");
    $outOfStockCount = $stmt->fetchColumn();
} catch(PDOException $e) {
    // Hata olursa atlayıp devam et
}

// Kritik stok seviyesindeki (5'ten az) ürünleri getir
$lowStockCount = 0;
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity < 5");
    $lowStockCount = $stmt->fetchColumn();
} catch(PDOException $e) {
    // Hata olursa atlayıp devam et
}

// Toplam stok değeri
$totalStockValue = 0;
try {
    $stmt = $conn->query("SELECT SUM(price * stock_quantity) FROM products");
    $totalStockValue = $stmt->fetchColumn() ?: 0;
} catch(PDOException $e) {
    // Hata olursa atlayıp devam et
}

// En çok satılan 10 ürün (grafikler için)
$topSellingProducts = [];
$productLabels = [];
$productQuantities = [];
$productAmounts = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            p.code,
            p.name,
            SUM(qi.quantity) as total_quantity,
            SUM(qi.subtotal) as total_amount
        FROM products p
        JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product'
        JOIN quotations q ON qi.quotation_id = q.id AND q.status = 'accepted'
        GROUP BY p.id, p.code, p.name
        ORDER BY total_quantity DESC
        LIMIT 10
    ");
    
    $stmt->execute();
    $topSellingProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($topSellingProducts as $product) {
        $productLabels[] = $product['code'] . ' - ' . $product['name'];
        $productQuantities[] = $product['total_quantity'];
        $productAmounts[] = $product['total_amount'];
    }
    
} catch(PDOException $e) {
    // Grafik verileri alınamazsa hata gösterme, boş grafikle devam et
}

// Excel export işlemi
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    require_once 'vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Ürün Raporu');

    $sheet->setCellValue('A1', 'ID');
    $sheet->setCellValue('B1', 'Kod');
    $sheet->setCellValue('C1', 'Ürün Adı');
    $sheet->setCellValue('D1', 'Fiyat (₺)');
    $sheet->setCellValue('E1', 'Stok Miktarı');
    $sheet->setCellValue('F1', 'Stok Değeri (₺)');
    $sheet->setCellValue('G1', 'Teklif Sayısı');
    $sheet->setCellValue('H1', 'Teklif Edilen Miktar');
    $sheet->setCellValue('I1', 'Teklif Edilen Değer (₺)');
    $sheet->getStyle('A1:I1')->getFont()->setBold(true);

    $row = 2;
    foreach ($productReports as $report) {
        $sheet->setCellValue('A' . $row, $report['id']);
        $sheet->setCellValue('B' . $row, $report['code']);
        $sheet->setCellValue('C' . $row, $report['name']);
        $sheet->setCellValue('D' . $row, $report['price']);
        $sheet->setCellValue('E' . $row, $report['stock_quantity']);
        $sheet->setCellValue('F' . $row, $report['stock_value']);
        $sheet->setCellValue('G' . $row, $report['times_quoted']);
        $sheet->setCellValue('H' . $row, $report['total_quoted_quantity'] ?: 0);
        $sheet->setCellValue('I' . $row, $report['total_quoted_value'] ?: 0);
        $row++;
    }

    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet->getStyle('D2:D'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');
    $sheet->getStyle('F2:F'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');
    $sheet->getStyle('I2:I'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');


    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Urun_Raporu_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}


$pageTitle = 'Ürün Raporları';
$currentPage = 'reports';
$needsChartJS = true; // Enable Chart.js
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<style>
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
    .product-stats-card {
        transition: transform 0.3s ease;
        border-left: 4px solid;
    }
    .product-stats-card:hover {
        transform: translateY(-5px);
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
            <h1 class="h2">Ürün Raporları</h1>
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
                    <label for="min_stock" class="form-label">Minimum Stok</label>
                    <input type="number" class="form-control" id="min_stock" name="min_stock" min="0" value="<?php echo $minStock; ?>">
                </div>
                <div class="col-md-3">
                    <label for="max_stock" class="form-label">Maksimum Stok</label>
                    <input type="number" class="form-control" id="max_stock" name="max_stock" min="0" value="<?php echo $maxStock; ?>">
                </div>
                <div class="col-md-3">
                    <label for="min_price" class="form-label">Minimum Fiyat (₺)</label>
                    <input type="number" class="form-control" id="min_price" name="min_price" min="0" step="0.01" value="<?php echo $minPrice; ?>">
                </div>
                <div class="col-md-3">
                    <label for="max_price" class="form-label">Maksimum Fiyat (₺)</label>
                    <input type="number" class="form-control" id="max_price" name="max_price" min="0" step="0.01" value="<?php echo $maxPrice; ?>">
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                    <a href="report_products.php" class="btn btn-secondary">Sıfırla</a>
                </div>
            </form>
        </div>
        
        <!-- Özet İstatistikler -->
        <div class="row mb-4">
            <?php 
            $totalProducts = count($productReports);
            $totalQuotations = 0;
            $totalQuotedQuantity = 0;
            $totalQuotedValue = 0;
            
            foreach ($productReports as $report) {
                $totalQuotations += $report['times_quoted'];
                $totalQuotedQuantity += $report['total_quoted_quantity'] ?: 0;
                $totalQuotedValue += $report['total_quoted_value'] ?: 0;
            }
            ?>
            
            <div class="col-md-3 mb-3">
                <div class="card product-stats-card" style="border-left-color: #007bff;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Toplam Ürün</h6>
                                <h3><?php echo $totalProducts; ?></h3>
                            </div>
                            <i class="bi bi-box fs-1 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card product-stats-card" style="border-left-color: #28a745;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Stok Değeri</h6>
                                <h3><?php echo number_format($totalStockValue, 0, ',', '.'); ?> ₺</h3>
                            </div>
                            <i class="bi bi-cash-stack fs-1 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card product-stats-card" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Kritik Stok</h6>
                                <h3><?php echo $lowStockCount; ?></h3>
                                <small>5'ten az stoklu ürünler</small>
                            </div>
                            <i class="bi bi-exclamation-triangle fs-1 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card product-stats-card" style="border-left-color: #dc3545;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Stokta Yok</h6>
                                <h3><?php echo $outOfStockCount; ?></h3>
                                <small>0 stoklu ürünler</small>
                            </div>
                            <i class="bi bi-x-circle fs-1 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Grafikler -->
        <div class="row mb-4">
            <!-- En Çok Satan Ürünler Grafiği (Miktar) -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En Çok Satan 10 Ürün (Miktar)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="topProductsQuantityChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- En Çok Satan Ürünler Grafiği (Tutar) -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En Çok Satan 10 Ürün (Tutar)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="topProductsAmountChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ürün Tablosu -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Ürün Analizi</h5>
                <span>Toplam: <?php echo count($productReports); ?> ürün</span>
            </div>
            <div class="card-body p-0">
                <?php if (count($productReports) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Kod</th>
                                    <th>Ürün Adı</th>
                                    <th class="text-end">Fiyat (₺)</th>
                                    <th class="text-center">Stok</th>
                                    <th class="text-end">Stok Değeri (₺)</th>
                                    <th class="text-center">Teklif Sayısı</th>
                                    <th class="text-center">Teklif Edilen Miktar</th>
                                    <th class="text-end">Teklif Edilen Değer (₺)</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productReports as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['code']); ?></td>
                                        <td><?php echo htmlspecialchars($report['name']); ?></td>
                                        <td class="text-end"><?php echo number_format($report['price'], 2, ',', '.'); ?></td>
                                        <td class="text-center">
                                            <?php
                                            if ($report['stock_quantity'] <= 0) {
                                                echo '<span class="badge bg-danger">Stok Yok</span>';
                                            } elseif ($report['stock_quantity'] < 5) {
                                                echo '<span class="badge bg-warning">Kritik: ' . $report['stock_quantity'] . '</span>';
                                            } else {
                                                echo $report['stock_quantity'];
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format($report['stock_value'], 2, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo $report['times_quoted']; ?></td>
                                        <td class="text-center"><?php echo $report['total_quoted_quantity'] ?: 0; ?></td>
                                        <td class="text-end"><?php echo number_format($report['total_quoted_value'] ?: 0, 2, ',', '.'); ?></td>
                                        <td>
                                            <a href="view_product.php?id=<?php echo $report['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="inventory.php?product_id=<?php echo $report['id']; ?>" class="btn btn-sm btn-primary" title="Stok Hareketleri">
                                                <i class="bi bi-clipboard-data"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-3 text-center">
                        <p>Seçilen kriterlere uygun ürün raporu bulunamadı.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Top Products by Quantity Chart
        const quantityCtx = document.getElementById('topProductsQuantityChart').getContext('2d');
        const quantityChart = new Chart(quantityCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($productLabels); ?>,
                datasets: [{
                    label: 'Satış Miktarı',
                    data: <?php echo json_encode($productQuantities); ?>,
                    backgroundColor: 'rgba(0, 123, 255, 0.7)',
                    borderColor: 'rgba(0, 123, 255, 1)',
                    borderWidth: 1
                }]
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
                    },
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
        
        // Top Products by Amount Chart
        const amountCtx = document.getElementById('topProductsAmountChart').getContext('2d');
        const amountChart = new Chart(amountCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($productLabels); ?>,
                datasets: [{
                    label: 'Satış Tutarı (₺)',
                    data: <?php echo json_encode($productAmounts); ?>,
                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                }]
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
                    },
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + 
                                       parseFloat(context.raw).toLocaleString('tr-TR') + ' ₺';
                            }
                        }
                    }
                }
            }
        });
    });
</script>