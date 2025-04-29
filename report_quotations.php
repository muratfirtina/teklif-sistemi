<?php
// report_quotations.php - Teklif raporları sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Varsayılan tarih aralığı: Son 30 gün
$defaultStartDate = date('Y-m-d', strtotime('-30 days'));
$defaultEndDate = date('Y-m-d');

// Filtre parametreleri
$startDate = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : $defaultStartDate;
$endDate = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : $defaultEndDate;
$customerID = isset($_GET['customer_id']) && is_numeric($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
$status = isset($_GET['status']) && !empty($_GET['status']) ? $_GET['status'] : '';
$userID = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$minAmount = isset($_GET['min_amount']) && is_numeric($_GET['min_amount']) ? floatval($_GET['min_amount']) : 0;
$maxAmount = isset($_GET['max_amount']) && is_numeric($_GET['max_amount']) ? floatval($_GET['max_amount']) : 0;

// Müşterileri getir (filtre için)
$customers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Kullanıcıları getir (filtre için)
$users = [];
try {
    $stmt = $conn->query("SELECT id, username, full_name FROM users ORDER BY username ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Kullanıcı listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Teklif verilerini getir
$quotations = [];
$totalCount = 0;
$totalAmount = 0;
$acceptedCount = 0;
$acceptedAmount = 0;
$rejectedCount = 0;
$rejectedAmount = 0;
$pendingCount = 0;
$pendingAmount = 0;
$expiredCount = 0;
$expiredAmount = 0;

try {
    // SQL sorgusu oluştur
    $sql = "SELECT q.*, c.name as customer_name, u.username as user_username, u.full_name as user_fullname
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            JOIN users u ON q.user_id = u.id
            WHERE q.date BETWEEN :start_date AND :end_date";
    
    $params = [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ];
    
    // Müşteri filtresi
    if ($customerID > 0) {
        $sql .= " AND q.customer_id = :customer_id";
        $params[':customer_id'] = $customerID;
    }
    
    // Durum filtresi
    if (!empty($status)) {
        $sql .= " AND q.status = :status";
        $params[':status'] = $status;
    }
    
    // Kullanıcı filtresi
    if ($userID > 0) {
        $sql .= " AND q.user_id = :user_id";
        $params[':user_id'] = $userID;
    }
    
    // Tutar filtresi
    if ($minAmount > 0) {
        $sql .= " AND q.total_amount >= :min_amount";
        $params[':min_amount'] = $minAmount;
    }
    
    if ($maxAmount > 0) {
        $sql .= " AND q.total_amount <= :max_amount";
        $params[':max_amount'] = $maxAmount;
    }
    
    // Sıralama
    $sql .= " ORDER BY q.date DESC";
    
    // Sorguyu çalıştır
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // İstatistikler için
    $totalCount = count($quotations);
    
    foreach ($quotations as $quotation) {
        $totalAmount += $quotation['total_amount'];
        
        switch ($quotation['status']) {
            case 'accepted':
                $acceptedCount++;
                $acceptedAmount += $quotation['total_amount'];
                break;
            case 'rejected':
                $rejectedCount++;
                $rejectedAmount += $quotation['total_amount'];
                break;
            case 'expired':
                $expiredCount++;
                $expiredAmount += $quotation['total_amount'];
                break;
            default: // draft veya sent
                $pendingCount++;
                $pendingAmount += $quotation['total_amount'];
                break;
        }
    }
    
    // Aylık teklif sayıları ve tutarları
    $monthlyStats = [];
    $sql = "SELECT 
                DATE_FORMAT(date, '%Y-%m') as month,
                COUNT(*) as total_count,
                SUM(total_amount) as total_amount,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'accepted' THEN total_amount ELSE 0 END) as accepted_amount,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
            FROM quotations
            WHERE date BETWEEN :start_date AND :end_date
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY month ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':start_date', $startDate);
    $stmt->bindValue(':end_date', $endDate);
    $stmt->execute();
    $monthlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Durum dağılımı
    $statusDistribution = [
        'draft' => 0,
        'sent' => 0,
        'accepted' => 0,
        'rejected' => 0,
        'expired' => 0
    ];
    
    $sql = "SELECT status, COUNT(*) as count
            FROM quotations
            WHERE date BETWEEN :start_date AND :end_date";
    
    // Ek filtreler
    if ($customerID > 0) {
        $sql .= " AND customer_id = :customer_id";
    }
    
    if ($userID > 0) {
        $sql .= " AND user_id = :user_id";
    }
    
    $sql .= " GROUP BY status";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':start_date', $startDate);
    $stmt->bindValue(':end_date', $endDate);
    
    if ($customerID > 0) {
        $stmt->bindValue(':customer_id', $customerID);
    }
    
    if ($userID > 0) {
        $stmt->bindValue(':user_id', $userID);
    }
    
    $stmt->execute();
    $statusResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($statusResults as $result) {
        $statusDistribution[$result['status']] = $result['count'];
    }
    
} catch(PDOException $e) {
    setMessage('error', 'Teklif verileri alınırken bir hata oluştu: ' . $e->getMessage());
}

// Excel export işlemi
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    // PHPExcel kütüphanesini yükleyin (composer ile yüklenmiş olmalı)
    require_once 'vendor/autoload.php';
    
    // Yeni bir Excel dosyası oluştur
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Başlık satırı
    $sheet->setCellValue('A1', 'Teklif No');
    $sheet->setCellValue('B1', 'Tarih');
    $sheet->setCellValue('C1', 'Geçerlilik');
    $sheet->setCellValue('D1', 'Müşteri');
    $sheet->setCellValue('E1', 'Durum');
    $sheet->setCellValue('F1', 'Toplam Tutar');
    $sheet->setCellValue('G1', 'Kullanıcı');
    
    // Başlık formatını ayarla
    $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    
    // Veri satırları
    $row = 2;
    foreach ($quotations as $quotation) {
        $status = '';
        switch ($quotation['status']) {
            case 'draft':
                $status = 'Taslak';
                break;
            case 'sent':
                $status = 'Gönderildi';
                break;
            case 'accepted':
                $status = 'Kabul Edildi';
                break;
            case 'rejected':
                $status = 'Reddedildi';
                break;
            case 'expired':
                $status = 'Süresi Doldu';
                break;
        }
        
        $sheet->setCellValue('A' . $row, $quotation['reference_no']);
        $sheet->setCellValue('B' . $row, $quotation['date']);
        $sheet->setCellValue('C' . $row, $quotation['valid_until']);
        $sheet->setCellValue('D' . $row, $quotation['customer_name']);
        $sheet->setCellValue('E' . $row, $status);
        $sheet->setCellValue('F' . $row, $quotation['total_amount']);
        $sheet->setCellValue('G' . $row, $quotation['user_fullname']);
        
        $row++;
    }
    
    // Sütun genişliklerini otomatik ayarla
    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // İlk sayfayı adlandır
    $sheet->setTitle('Teklif Raporu');
    
    // Özet sayfası ekle
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Özet');
    
    $sheet->setCellValue('A1', 'Rapor Özeti');
    $sheet->setCellValue('A2', 'Başlangıç Tarihi:');
    $sheet->setCellValue('B2', $startDate);
    $sheet->setCellValue('A3', 'Bitiş Tarihi:');
    $sheet->setCellValue('B3', $endDate);
    $sheet->setCellValue('A4', 'Toplam Teklif Sayısı:');
    $sheet->setCellValue('B4', $totalCount);
    $sheet->setCellValue('A5', 'Toplam Tutar:');
    $sheet->setCellValue('B5', $totalAmount);
    $sheet->setCellValue('A6', 'Kabul Edilen:');
    $sheet->setCellValue('B6', $acceptedCount);
    $sheet->setCellValue('C6', $acceptedAmount);
    $sheet->setCellValue('A7', 'Reddedilen:');
    $sheet->setCellValue('B7', $rejectedCount);
    $sheet->setCellValue('C7', $rejectedAmount);
    $sheet->setCellValue('A8', 'Bekleyen:');
    $sheet->setCellValue('B8', $pendingCount);
    $sheet->setCellValue('C8', $pendingAmount);
    $sheet->setCellValue('A9', 'Süresi Dolan:');
    $sheet->setCellValue('B9', $expiredCount);
    $sheet->setCellValue('C9', $expiredAmount);
    
    // Formatları ayarla
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2:A9')->getFont()->setBold(true);
    
    // Sütun genişliklerini ayarla
    $sheet->getColumnDimension('A')->setWidth(20);
    $sheet->getColumnDimension('B')->setWidth(15);
    $sheet->getColumnDimension('C')->setWidth(15);
    
    // HTTP başlıklarını ayarla ve dosyayı indir
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="Teklif_Raporu_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// Türkçe ay isimleri
function getTurkishMonth($monthNumber) {
    $months = [
        '01' => 'Ocak',
        '02' => 'Şubat',
        '03' => 'Mart',
        '04' => 'Nisan',
        '05' => 'Mayıs',
        '06' => 'Haziran',
        '07' => 'Temmuz',
        '08' => 'Ağustos',
        '09' => 'Eylül',
        '10' => 'Ekim',
        '11' => 'Kasım',
        '12' => 'Aralık'
    ];
    return $months[$monthNumber];
}

// Grafik verileri
$chartLabels = [];
$chartTotalCounts = [];
$chartAcceptedCounts = [];
$chartRejectedCounts = [];
$chartTotalAmounts = [];
$chartAcceptedAmounts = [];

foreach ($monthlyStats as $stat) {
    $dateParts = explode('-', $stat['month']);
    $monthName = getTurkishMonth($dateParts[1]) . ' ' . $dateParts[0];
    
    $chartLabels[] = $monthName;
    $chartTotalCounts[] = $stat['total_count'];
    $chartAcceptedCounts[] = $stat['accepted_count'];
    $chartRejectedCounts[] = $stat['rejected_count'];
    $chartTotalAmounts[] = $stat['total_amount'];
    $chartAcceptedAmounts[] = $stat['accepted_amount'];
}

// Başarı oranı hesapla
$successRate = ($totalCount > 0) ? round(($acceptedCount / $totalCount) * 100, 2) : 0;

$needsChartJS = true; // Enable Chart.js
$pageTitle = 'Teklif Raporları';
$currentPage = 'reports';
include 'includes/header.php';
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
                <h1 class="h2">Teklif Raporları</h1>
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
                    <div class="col-md-2">
                        <label for="start_date" class="form-label">Başlangıç Tarihi</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="end_date" class="form-label">Bitiş Tarihi</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="customer_id" class="form-label">Müşteri</label>
                        <select class="form-select" id="customer_id" name="customer_id">
                            <option value="0">Tüm Müşteriler</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>" <?php echo $customerID == $customer['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tüm Durumlar</option>
                            <option value="draft" <?php echo $status == 'draft' ? 'selected' : ''; ?>>Taslak</option>
                            <option value="sent" <?php echo $status == 'sent' ? 'selected' : ''; ?>>Gönderildi</option>
                            <option value="accepted" <?php echo $status == 'accepted' ? 'selected' : ''; ?>>Kabul Edildi</option>
                            <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Reddedildi</option>
                            <option value="expired" <?php echo $status == 'expired' ? 'selected' : ''; ?>>Süresi Doldu</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="user_id" class="form-label">Kullanıcı</label>
                        <select class="form-select" id="user_id" name="user_id">
                            <option value="0">Tüm Kullanıcılar</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $userID == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="min_amount" class="form-label">Min. Tutar</label>
                        <input type="number" class="form-control" id="min_amount" name="min_amount" min="0" step="0.01" value="<?php echo $minAmount; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="max_amount" class="form-label">Max. Tutar</label>
                        <input type="number" class="form-control" id="max_amount" name="max_amount" min="0" step="0.01" value="<?php echo $maxAmount; ?>">
                    </div>
                    <div class="col-md-12 text-end">
                        <button type="submit" class="btn btn-primary">Filtrele</button>
                        <a href="report_quotations.php" class="btn btn-secondary">Sıfırla</a>
                    </div>
                </form>
            </div>
            
            <!-- Özet İstatistikler -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card stat-card bg-primary text-white">
                        <div class="card-body">
                            <h5 class="card-title">Toplam Teklif</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <h2 class="display-5 mb-0"><?php echo $totalCount; ?></h2>
                                <div class="text-white">
                                    <i class="bi bi-file-earmark-text fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title">Toplam Tutar</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <h2 class="display-6 mb-0"><?php echo number_format($totalAmount, 2, ',', '.') . ' ₺'; ?></h2>
                                <div class="text-white">
                                    <i class="bi bi-cash-stack fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title">Kabul Edilen</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h2 class="display-5 mb-0"><?php echo $acceptedCount; ?></h2>
                                    <small><?php echo number_format($acceptedAmount, 2, ',', '.') . ' ₺'; ?></small>
                                </div>
                                <div class="text-white">
                                    <i class="bi bi-check-circle fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stat-card bg-warning text-dark">
                        <div class="card-body">
                            <h5 class="card-title">Başarı Oranı</h5>
                            <div class="d-flex justify-content-between align-items-center">
                                <h2 class="display-5 mb-0">%<?php echo $successRate; ?></h2>
                                <div class="text-dark">
                                    <i class="bi bi-award fs-1"></i>
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
                    <div class="card">
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
                    <div class="card">
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
                
                <!-- Durum Dağılımı -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Durum Dağılımı</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="statusDistributionChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Başarı Oranı -->
                <div class="col-md-6 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Kabul/Red Oranı</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="successRateChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Teklif Tablosu -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Teklif Listesi</h5>
                    <span>Toplam: <?php echo $totalCount; ?> teklif</span>
                </div>
                <div class="card-body p-0">
                    <?php if (count($quotations) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Teklif No</th>
                                        <th>Tarih</th>
                                        <th>Geçerlilik</th>
                                        <th>Müşteri</th>
                                        <th>Durum</th>
                                        <th>Toplam Tutar</th>
                                        <th>Kullanıcı</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotations as $quotation): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($quotation['reference_no']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($quotation['valid_until'])); ?></td>
                                            <td><?php echo htmlspecialchars($quotation['customer_name']); ?></td>
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
                                            <td><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                            <td><?php echo htmlspecialchars($quotation['user_fullname']); ?></td>
                                            <td>
                                                <a href="view_quotation.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="p-3 text-center">
                            <p>Seçilen kriterlere uygun teklif bulunamadı.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Teklif Sayısı Grafiği
        const countCtx = document.getElementById('quotationCountChart').getContext('2d');
        const countChart = new Chart(countCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Toplam Teklif',
                        data: <?php echo json_encode($chartTotalCounts); ?>,
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
                }
            }
        });
        
        // Teklif Tutarı Grafiği
        const amountCtx = document.getElementById('quotationAmountChart').getContext('2d');
        const amountChart = new Chart(amountCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [
                    {
                        label: 'Toplam Tutar',
                        data: <?php echo json_encode($chartTotalAmounts); ?>,
                        backgroundColor: 'rgba(0, 123, 255, 0.7)',
                        borderColor: 'rgba(0, 123, 255, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Kabul Edilen',
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
                    }
                }
            }
        });
        
        // Durum Dağılımı Grafiği
        const statusCtx = document.getElementById('statusDistributionChart').getContext('2d');
        const statusChart = new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['Taslak', 'Gönderildi', 'Kabul Edildi', 'Reddedildi', 'Süresi Doldu'],
                datasets: [{
                    data: [
                        <?php echo $statusDistribution['draft']; ?>,
                        <?php echo $statusDistribution['sent']; ?>,
                        <?php echo $statusDistribution['accepted']; ?>,
                        <?php echo $statusDistribution['rejected']; ?>,
                        <?php echo $statusDistribution['expired']; ?>
                    ],
                    backgroundColor: [
                        '#6c757d', // secondary - draft
                        '#007bff', // primary - sent
                        '#28a745', // success - accepted
                        '#dc3545', // danger - rejected
                        '#ffc107'  // warning - expired
                    ],
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
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
        
        // Başarı Oranı Grafiği
        const successCtx = document.getElementById('successRateChart').getContext('2d');
        const successChart = new Chart(successCtx, {
            type: 'doughnut',
            data: {
                labels: ['Kabul Edilen', 'Reddedilen', 'Diğer (Taslak, Gönderildi, Süresi Doldu)'],
                datasets: [{
                    data: [
                        <?php echo $acceptedCount; ?>,
                        <?php echo $rejectedCount; ?>,
                        <?php echo $totalCount - $acceptedCount - $rejectedCount; ?>
                    ],
                    backgroundColor: [
                        '#28a745', // success - accepted
                        '#dc3545', // danger - rejected
                        '#6c757d'  // secondary - others
                    ],
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
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>