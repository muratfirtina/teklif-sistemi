<?php
// reports.php - Raporlar ana sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Özet istatistikleri al
try {
    // Toplam müşteri sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM customers");
    $customerCount = $stmt->fetchColumn() ?: 0;
    
    // Toplam ürün sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM products");
    $productCount = $stmt->fetchColumn() ?: 0;
    
    // Toplam hizmet sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM services");
    $serviceCount = $stmt->fetchColumn() ?: 0;
    
    // Toplam teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations");
    $quotationCount = $stmt->fetchColumn() ?: 0;
    
    // Toplam tutar
    $stmt = $conn->query("SELECT SUM(total_amount) FROM quotations");
    $totalAmount = $stmt->fetchColumn() ?: 0;
    
    // Kabul edilen teklifler tutarı
    $stmt = $conn->query("SELECT SUM(total_amount) FROM quotations WHERE status = 'accepted'");
    $acceptedAmount = $stmt->fetchColumn() ?: 0;
    
    // İçinde bulunulan aydaki teklif sayısı
    $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
    $currentMonthQuotationCount = $stmt->fetchColumn() ?: 0;
    
    // İçinde bulunulan aydaki teklif tutarı
    $stmt = $conn->query("SELECT SUM(total_amount) FROM quotations WHERE MONTH(date) = MONTH(CURRENT_DATE()) AND YEAR(date) = YEAR(CURRENT_DATE())");
    $currentMonthAmount = $stmt->fetchColumn() ?: 0;
    
} catch(PDOException $e) {
    setMessage('error', 'İstatistikler alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Raporlar';
$currentPage = 'reports';
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
            
            <h1 class="h2 mb-4">Raporlar</h1>
            
            <!-- Özet İstatistikler -->
            <div class="row mb-4">
                <div class="col-md-3 mb-3">
                    <div class="card stats-card stats-card-primary p-3">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Toplam Teklif</h6>
                                <div class="stats-number"><?php echo $quotationCount; ?></div>
                            </div>
                            <div class="text-primary">
                                <i class="bi bi-file-earmark-text fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stats-card stats-card-success p-3">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Toplam Tutar</h6>
                                <div class="stats-number"><?php echo number_format($totalAmount, 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                            <div class="text-success">
                                <i class="bi bi-cash-stack fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stats-card stats-card-info p-3">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Kabul Edilen Tutarlar</h6>
                                <div class="stats-number"><?php echo number_format($acceptedAmount, 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                            <div class="text-info">
                                <i class="bi bi-check2-circle fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-3 mb-3">
                    <div class="card stats-card stats-card-warning p-3">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="card-subtitle mb-2 text-muted">Bu Ayki Teklifler</h6>
                                <div class="stats-number"><?php echo $currentMonthQuotationCount; ?></div>
                                <small class="text-muted"><?php echo number_format($currentMonthAmount, 2, ',', '.') . ' ₺'; ?></small>
                            </div>
                            <div class="text-warning">
                                <i class="bi bi-calendar-check fs-1"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Rapor Türleri -->
            <div class="row">
                <!-- Teklif Raporları -->
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-primary">
                                <i class="bi bi-file-earmark-bar-graph"></i>
                            </div>
                            <h5 class="card-title">Teklif Raporları</h5>
                            <p class="card-text">Teklif durumları, başarı oranları, tarih bazlı analizler ve daha fazlası.</p>
                            <a href="report_quotations.php" class="btn btn-primary mt-3">Raporu Görüntüle</a>
                        </div>
                    </div>
                </div>
                
                <!-- Müşteri Raporları -->
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-success">
                                <i class="bi bi-people"></i>
                            </div>
                            <h5 class="card-title">Müşteri Raporları</h5>
                            <p class="card-text">En aktif müşteriler, teklif başarı oranları, müşteri analizleri.</p>
                            <a href="report_customers.php" class="btn btn-success mt-3">Raporu Görüntüle</a>
                        </div>
                    </div>
                </div>
                
                <!-- Ürün/Hizmet Raporları -->
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-info">
                                <i class="bi bi-boxes"></i>
                            </div>
                            <h5 class="card-title">Ürün/Hizmet Raporları</h5>
                            <p class="card-text">En çok teklif edilen ürünler, teklif detayları ve stok durumları.</p>
                            <a href="report_products.php" class="btn btn-info mt-3">Raporu Görüntüle</a>
                        </div>
                    </div>
                </div>
                
                <!-- Satış Performansı -->
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-danger">
                                <i class="bi bi-graph-up-arrow"></i>
                            </div>
                            <h5 class="card-title">Satış Performansı</h5>
                            <p class="card-text">Aylık/yıllık satış trendleri, başarı oranları ve hedef gerçekleştirme analizleri.</p>
                            <a href="report_sales.php" class="btn btn-danger mt-3">Raporu Görüntüle</a>
                        </div>
                    </div>
                </div>
                
                <!-- Kullanıcı Performansı (Sadece Admin) -->
                <?php if (isAdmin()): ?>
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-warning">
                                <i class="bi bi-person-lines-fill"></i>
                            </div>
                            <h5 class="card-title">Kullanıcı Performansı</h5>
                            <p class="card-text">Kullanıcı bazlı teklif analizleri, başarı oranları ve aktivite raporları.</p>
                            <a href="report_users.php" class="btn btn-warning mt-3">Raporu Görüntüle</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Özel Rapor Oluşturma -->
                <div class="col-md-4 mb-4">
                    <div class="card report-card h-100">
                        <div class="card-body text-center">
                            <div class="report-icon text-secondary">
                                <i class="bi bi-gear-wide-connected"></i>
                            </div>
                            <h5 class="card-title">Özel Rapor Oluştur</h5>
                            <p class="card-text">İhtiyacınıza göre özelleştirilmiş raporlar ve analizler oluşturun.</p>
                            <a href="report_custom.php" class="btn btn-secondary mt-3">Rapor Oluştur</a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Excel Export Açıklaması -->
            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> Tüm raporlar Excel formatında dışa aktarılabilir ve yazdırılabilir. Ayrıntılı filtreleme seçenekleri rapor sayfalarında mevcuttur.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>