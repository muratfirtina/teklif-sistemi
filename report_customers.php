<?php
// report_customers.php - Müşteri raporları sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

$conn = getDbConnection();

// Filtreler
$startDate = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-1 year'));
$endDate = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$minQuotation = isset($_GET['min_quotation']) && is_numeric($_GET['min_quotation']) ? intval($_GET['min_quotation']) : null;
$minAccepted = isset($_GET['min_accepted']) && is_numeric($_GET['min_accepted']) ? intval($_GET['min_accepted']) : null;

// Rapor verilerini çek
$customerReports = [];
try {
    $sql = "SELECT
                c.id,
                c.name,
                c.contact_person,
                c.email,
                c.phone,
                COUNT(DISTINCT q.id) AS total_quotations,
                SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_quotations,
                SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_quotations,
                SUM(q.total_amount) AS total_quotation_amount,
                SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) AS accepted_amount,
                MAX(q.date) AS last_quotation_date
            FROM customers c
            LEFT JOIN quotations q ON c.id = q.customer_id";

    $conditions = [];
    $params = [];

    if (!empty($startDate)) {
        $conditions[] = "q.date >= :start_date";
        $params[':start_date'] = $startDate;
    }
    if (!empty($endDate)) {
        $conditions[] = "q.date <= :end_date";
        $params[':end_date'] = $endDate;
    }

    if ($minQuotation !== null) {
        $conditions[] = "COUNT(DISTINCT q.id) >= :min_quotation";
        $params[':min_quotation'] = $minQuotation;
    }
    if ($minAccepted !== null) {
        $conditions[] = "SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) >= :min_accepted";
        $params[':min_accepted'] = $minAccepted;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " GROUP BY c.id, c.name, c.contact_person, c.email, c.phone ";
    $sql .= " ORDER BY accepted_amount DESC, total_quotations DESC";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $customerReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    setMessage('error', 'Müşteri rapor verileri alınırken hata oluştu: ' . $e->getMessage());
}

// Excel export işlemi
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    require_once 'vendor/autoload.php';
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Müşteri Raporu');

    $sheet->setCellValue('A1', 'ID');
    $sheet->setCellValue('B1', 'Müşteri Adı');
    $sheet->setCellValue('C1', 'İlgili Kişi');
    $sheet->setCellValue('D1', 'E-posta');
    $sheet->setCellValue('E1', 'Telefon');
    $sheet->setCellValue('F1', 'Teklif Sayısı');
    $sheet->setCellValue('G1', 'Kabul Edilen');
    $sheet->setCellValue('H1', 'Reddedilen');
    $sheet->setCellValue('I1', 'Başarı Oranı (%)');
    $sheet->setCellValue('J1', 'Teklif Tutarı (₺)');
    $sheet->setCellValue('K1', 'Kabul Edilen Tutar (₺)');
    $sheet->setCellValue('L1', 'Son Teklif Tarihi');
    
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);

    $row = 2;
    foreach ($customerReports as $report) {
        $successRate = ($report['total_quotations'] > 0) ? round(($report['accepted_quotations'] / $report['total_quotations']) * 100, 2) : 0;
        
        $sheet->setCellValue('A' . $row, $report['id']);
        $sheet->setCellValue('B' . $row, $report['name']);
        $sheet->setCellValue('C' . $row, $report['contact_person']);
        $sheet->setCellValue('D' . $row, $report['email']);
        $sheet->setCellValue('E' . $row, $report['phone']);
        $sheet->setCellValue('F' . $row, $report['total_quotations']);
        $sheet->setCellValue('G' . $row, $report['accepted_quotations']);
        $sheet->setCellValue('H' . $row, $report['rejected_quotations']);
        $sheet->setCellValue('I' . $row, $successRate);
        $sheet->setCellValue('J' . $row, $report['total_quotation_amount']);
        $sheet->setCellValue('K' . $row, $report['accepted_amount']);
        $sheet->setCellValue('L' . $row, $report['last_quotation_date']);
        
        $row++;
    }

    foreach (range('A', 'L') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    $sheet->getStyle('I2:I'.$row)->getNumberFormat()->setFormatCode('0.00"%"');
    $sheet->getStyle('J2:K'.$row)->getNumberFormat()->setFormatCode('#,##0.00" ₺"');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Musteri_Raporu_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
    exit;
}

// Top 10 müşterileri getir (grafik için)
$topCustomers = [];
$customerLabels = [];
$customerAmounts = [];
$customerQuotations = [];

try {
    $stmt = $conn->prepare("
        SELECT 
            c.name as customer_name,
            COUNT(q.id) as quotation_count,
            SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) as accepted_amount
        FROM customers c
        LEFT JOIN quotations q ON c.id = q.customer_id
        WHERE q.date BETWEEN :start_date AND :end_date
        GROUP BY c.id, c.name
        ORDER BY accepted_amount DESC
        LIMIT 10
    ");
    
    $stmt->bindParam(':start_date', $startDate);
    $stmt->bindParam(':end_date', $endDate);
    $stmt->execute();
    $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($topCustomers as $customer) {
        $customerLabels[] = $customer['customer_name'];
        $customerAmounts[] = $customer['accepted_amount'];
        $customerQuotations[] = $customer['quotation_count'];
    }
    
} catch(PDOException $e) {
    // Grafik verileri alınamazsa hata gösterme, boş grafikle devam et
}

$pageTitle = 'Müşteri Raporları';
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
    .customer-stats-card {
        transition: transform 0.3s ease;
        border-left: 4px solid;
    }
    .customer-stats-card:hover {
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
            <h1 class="h2">Müşteri Raporları</h1>
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
                    <label for="min_quotation" class="form-label">Min. Teklif Sayısı</label>
                    <input type="number" class="form-control" id="min_quotation" name="min_quotation" min="0" value="<?php echo $minQuotation; ?>">
                </div>
                <div class="col-md-3">
                    <label for="min_accepted" class="form-label">Min. Kabul Edilen Teklif</label>
                    <input type="number" class="form-control" id="min_accepted" name="min_accepted" min="0" value="<?php echo $minAccepted; ?>">
                </div>
                <div class="col-12 text-end">
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                    <a href="report_customers.php" class="btn btn-secondary">Sıfırla</a>
                </div>
            </form>
        </div>
        
        <!-- Özet İstatistikler -->
        <div class="row mb-4">
            <?php 
            $totalCustomers = count($customerReports);
            $totalQuotationCount = 0;
            $totalAcceptedCount = 0;
            $totalAmount = 0;
            $totalAcceptedAmount = 0;
            
            foreach ($customerReports as $report) {
                $totalQuotationCount += $report['total_quotations'];
                $totalAcceptedCount += $report['accepted_quotations'];
                $totalAmount += $report['total_quotation_amount'];
                $totalAcceptedAmount += $report['accepted_amount'];
            }
            
            $avgQuotationPerCustomer = ($totalCustomers > 0) ? round($totalQuotationCount / $totalCustomers, 2) : 0;
            $successRate = ($totalQuotationCount > 0) ? round(($totalAcceptedCount / $totalQuotationCount) * 100, 2) : 0;
            ?>
            
            <div class="col-md-3 mb-3">
                <div class="card customer-stats-card" style="border-left-color: #007bff;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Toplam Müşteri</h6>
                                <h3><?php echo $totalCustomers; ?></h3>
                            </div>
                            <i class="bi bi-people fs-1 text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card customer-stats-card" style="border-left-color: #28a745;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Ortalama Teklif</h6>
                                <h3><?php echo $avgQuotationPerCustomer; ?></h3>
                                <small>Müşteri başına</small>
                            </div>
                            <i class="bi bi-file-earmark-text fs-1 text-success"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card customer-stats-card" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Başarı Oranı</h6>
                                <h3>%<?php echo $successRate; ?></h3>
                                <small><?php echo $totalAcceptedCount; ?> / <?php echo $totalQuotationCount; ?> teklif</small>
                            </div>
                            <i class="bi bi-graph-up fs-1 text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <div class="card customer-stats-card" style="border-left-color: #dc3545;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Toplam Tutar</h6>
                                <h3><?php echo number_format($totalAcceptedAmount, 0, ',', '.'); ?> ₺</h3>
                                <small>Kabul edilen teklifler</small>
                            </div>
                            <i class="bi bi-cash-stack fs-1 text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Grafikler -->
        <div class="row mb-4">
            <!-- En İyi Müşteriler Grafiği -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En İyi 10 Müşteri (Kabul Edilen Tutar)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="topCustomersChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Teklif Sayıları Grafiği -->
            <div class="col-lg-6">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">En İyi 10 Müşteri (Teklif Sayısı)</h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="quotationCountChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Müşteri Tablosu -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Müşteri Performansı</h5>
                <span>Toplam: <?php echo count($customerReports); ?> müşteri</span>
            </div>
            <div class="card-body p-0">
                <?php if (count($customerReports) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Müşteri</th>
                                    <th>İlgili Kişi</th>
                                    <th class="text-center">Teklif Sayısı</th>
                                    <th class="text-center">Kabul Edilen</th>
                                    <th class="text-center">Reddedilen</th>
                                    <th class="text-center">Başarı Oranı</th>
                                    <th class="text-end">Toplam Tutar (₺)</th>
                                    <th class="text-end">Kabul Edilen (₺)</th>
                                    <th>Son Teklif</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customerReports as $report): 
                                    $successRate = ($report['total_quotations'] > 0) ? round(($report['accepted_quotations'] / $report['total_quotations']) * 100, 2) : 0;
                                ?>
                                    <tr>
                                        <td><a href="view_customer.php?id=<?php echo $report['id']; ?>"><?php echo htmlspecialchars($report['name']); ?></a></td>
                                        <td><?php echo htmlspecialchars($report['contact_person']); ?></td>
                                        <td class="text-center"><?php echo $report['total_quotations']; ?></td>
                                        <td class="text-center text-success"><?php echo $report['accepted_quotations']; ?></td>
                                        <td class="text-center text-danger"><?php echo $report['rejected_quotations']; ?></td>
                                        <td class="text-center"><?php echo $successRate; ?>%</td>
                                        <td class="text-end"><?php echo number_format($report['total_quotation_amount'], 2, ',', '.'); ?></td>
                                        <td class="text-end"><?php echo number_format($report['accepted_amount'], 2, ',', '.'); ?></td>
                                        <td><?php echo $report['last_quotation_date'] ? date('d.m.Y', strtotime($report['last_quotation_date'])) : '-'; ?></td>
                                        <td>
                                            <a href="view_customer.php?id=<?php echo $report['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="new_quotation.php?customer_id=<?php echo $report['id']; ?>" class="btn btn-sm btn-primary" title="Yeni Teklif">
                                                <i class="bi bi-file-earmark-plus"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-3 text-center">
                        <p>Seçilen kriterlere uygun müşteri raporu bulunamadı.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Top Customers Chart (Bar)
        const topCustomersCtx = document.getElementById('topCustomersChart').getContext('2d');
        const topCustomersChart = new Chart(topCustomersCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($customerLabels); ?>,
                datasets: [{
                    label: 'Kabul Edilen Tutar (₺)',
                    data: <?php echo json_encode($customerAmounts); ?>,
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
        
        // Quotation Count Chart (Horizontal Bar)
        const quotationCountCtx = document.getElementById('quotationCountChart').getContext('2d');
        const quotationCountChart = new Chart(quotationCountCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($customerLabels); ?>,
                datasets: [{
                    label: 'Teklif Sayısı',
                    data: <?php echo json_encode($customerQuotations); ?>,
                    backgroundColor: 'rgba(0, 123, 255, 0.7)',
                    borderColor: 'rgba(0, 123, 255, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
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
    });
</script>