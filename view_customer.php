<?php
// view_customer.php - Müşteri görüntüleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz müşteri ID\'si.');
    header("Location: customers.php");
    exit;
}

$customer_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Müşteri bilgilerini al
try {
    $stmt = $conn->prepare("SELECT * FROM customers WHERE id = :id");
    $stmt->bindParam(':id', $customer_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Müşteri bulunamadı.');
        header("Location: customers.php");
        exit;
    }
    
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Müşteriye ait teklifleri getir
    $stmt = $conn->prepare("
        SELECT q.*, 
               CASE 
                   WHEN q.status = 'draft' THEN 'Taslak'
                   WHEN q.status = 'sent' THEN 'Gönderildi'
                   WHEN q.status = 'accepted' THEN 'Kabul Edildi'
                   WHEN q.status = 'rejected' THEN 'Reddedildi'
                   WHEN q.status = 'expired' THEN 'Süresi Doldu'
               END as status_text
        FROM quotations q
        WHERE q.customer_id = :customer_id
        ORDER BY q.date DESC
    ");
    $stmt->bindParam(':customer_id', $customer_id);
    $stmt->execute();
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: customers.php");
    exit;
}

$pageTitle = 'Müşteri: ' . htmlspecialchars($customer['name']);
$currentPage = 'customers';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<style>
    .card-hover:hover {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        transition: box-shadow 0.3s ease-in-out;
    }
    .info-label {
        font-weight: 600;
        color: #495057;
    }
    .customer-info {
        background-color: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
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
            <h1 class="h2">Müşteri Detayı</h1>
            <div>
                <a href="edit_customer.php?id=<?php echo $customer_id; ?>" class="btn btn-warning me-2">
                    <i class="bi bi-pencil"></i> Düzenle
                </a>
                <a href="customers.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Müşterilere Dön
                </a>
            </div>
        </div>
        
        <div class="row">
            <!-- Müşteri Bilgileri -->
            <div class="col-md-5">
                <div class="customer-info">
                    <h4 class="mb-4"><?php echo htmlspecialchars($customer['name']); ?></h4>
                    
                    <?php if (!empty($customer['contact_person'])): ?>
                        <div class="mb-3">
                            <div class="info-label">İlgili Kişi</div>
                            <div><?php echo htmlspecialchars($customer['contact_person']); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['email'])): ?>
                        <div class="mb-3">
                            <div class="info-label">E-posta</div>
                            <div>
                                <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                                    <?php echo htmlspecialchars($customer['email']); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['phone'])): ?>
                        <div class="mb-3">
                            <div class="info-label">Telefon</div>
                            <div>
                                <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>">
                                    <?php echo htmlspecialchars($customer['phone']); ?>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['address'])): ?>
                        <div class="mb-3">
                            <div class="info-label">Adres</div>
                            <div><?php echo nl2br(htmlspecialchars($customer['address'])); ?></div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($customer['tax_office']) || !empty($customer['tax_number'])): ?>
                        <div class="mb-3">
                            <div class="info-label">Vergi Bilgileri</div>
                            <div>
                                <?php 
                                    if (!empty($customer['tax_office'])) {
                                        echo htmlspecialchars($customer['tax_office']);
                                    }
                                    if (!empty($customer['tax_office']) && !empty($customer['tax_number'])) {
                                        echo ' / ';
                                    }
                                    if (!empty($customer['tax_number'])) {
                                        echo htmlspecialchars($customer['tax_number']);
                                    }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <div class="info-label">Kayıt Tarihi</div>
                        <div><?php echo date('d.m.Y H:i', strtotime($customer['created_at'])); ?></div>
                    </div>
                </div>
                
                <!-- Hızlı İşlemler Kartı -->
                <!-- Hızlı İşlemler Kartı -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Hızlı İşlemler</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="new_quotation.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-primary">
                                <i class="bi bi-file-earmark-plus"></i> Yeni Teklif Oluştur
                            </a>
                            <?php
                                // --- YENİ: Giriş yapan kullanıcının imzasını çek ---
                                $currentUserSignatureText = ''; // Varsayılan boş imza
                                $loggedInUserId = $_SESSION['user_id'] ?? 0; // Giriş yapan kullanıcı ID'si

                                if ($loggedInUserId > 0) {
                                    try {
                                        $stmtSig = $conn->prepare("SELECT email_signature FROM users WHERE id = :user_id");
                                        $stmtSig->bindParam(':user_id', $loggedInUserId);
                                        $stmtSig->execute();
                                        if ($stmtSig->rowCount() > 0) {
                                            $currentUserSignatureText = $stmtSig->fetchColumn();
                                        }
                                    } catch (PDOException $e) {
                                        // Hata olursa logla ama işleme devam et
                                        error_log("Kullanıcı imzası alınamadı (User ID: $loggedInUserId): " . $e->getMessage());
                                    }
                                }
                                // --- İmza Çekme Sonu ---

                                // Bu müşteri için mailto linki oluştur
                                $customerMailTo = $customer['email'] ?? '';
                                $customerMailSubject = "Bilgi Talebi - " . htmlspecialchars($customer['name']); // Örnek konu
                                $customerMailBody = "Sayın " . htmlspecialchars($customer['contact_person'] ?: $customer['name']) . ",\n\n"; // Örnek gövde
                                // $customerMailBody .= "[Buraya mesajınızı yazabilirsiniz]\n\n"; // Kullanıcı mesajını kendi ekler

                                // --- GÜNCELLEME: Giriş yapan kullanıcının imzasını veya varsayılan bilgiyi ekle ---
                                if (!empty($currentUserSignatureText)) {
                                    $customerMailBody .= "--\n"; // Ayraç
                                    $customerMailBody .= trim($currentUserSignatureText); // Kullanıcının imza metni (trim ile başta/sonda boşluk varsa kaldır)
                                } else {
                                    // Eğer kullanıcının imzası yoksa, gönderenin adını ekle
                                    $customerMailBody .= "Saygılarımızla,\n" . htmlspecialchars($_SESSION['user_fullname']); // Giriş yapan kullanıcı adı
                                }
                                // --- İmza Ekleme Sonu ---


                                $customerMailtoLink = "mailto:" . rawurlencode($customerMailTo)
                                                    . "?subject=" . rawurlencode($customerMailSubject)
                                                    . "&body=" . rawurlencode($customerMailBody);
                            ?>
                            <a href="<?php echo $customerMailtoLink; ?>" class="btn btn-info" title="Varsayılan e-posta programınızı açar.">
                                <i class="bi bi-envelope"></i> E-posta Gönder
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Müşteri Teklifleri -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Teklifler</h5>
                        <a href="new_quotation.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Yeni Teklif
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (count($quotations) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Teklif No</th>
                                            <th>Tarih</th>
                                            <th>Tutar</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($quotations as $quotation): ?>
                                            <?php
                                                $statusClass = 'secondary';
                                                
                                                switch($quotation['status']) {
                                                    case 'sent':
                                                        $statusClass = 'primary';
                                                        break;
                                                    case 'accepted':
                                                        $statusClass = 'success';
                                                        break;
                                                    case 'rejected':
                                                        $statusClass = 'danger';
                                                        break;
                                                    case 'expired':
                                                        $statusClass = 'warning';
                                                        break;
                                                }
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="view_quotation.php?id=<?php echo $quotation['id']; ?>">
                                                        <?php echo htmlspecialchars($quotation['reference_no']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></td>
                                                <td><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                                                        <?php echo $quotation['status_text']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view_quotation.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="edit_quotation.php?id=<?php echo $quotation['id']; ?>" class="btn btn-sm btn-warning" title="Düzenle">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Bu müşteriye ait teklif bulunamadı.
                                <div class="mt-3">
                                    <a href="new_quotation.php?customer_id=<?php echo $customer_id; ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-plus-circle"></i> Yeni Teklif Oluştur
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- İstatistikler -->
                <div class="row mt-4">
                    <div class="col-md-6 mb-3">
                        <div class="card card-dashboard h-100">
                            <div class="card-body stats-card stats-card-primary">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-muted">Toplam Teklif</h6>
                                        <h2 class="card-title stats-number"><?php echo count($quotations); ?></h2>
                                    </div>
                                    <div class="stats-icon text-primary">
                                        <i class="bi bi-file-earmark-text"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <div class="card card-dashboard h-100">
                            <div class="card-body stats-card stats-card-success">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h6 class="card-subtitle mb-2 text-muted">Toplam Tutar</h6>
                                        <?php
                                            $totalAmount = 0;
                                            foreach ($quotations as $quotation) {
                                                $totalAmount += $quotation['total_amount'];
                                            }
                                        ?>
                                        <h2 class="card-title stats-number"><?php echo number_format($totalAmount, 2, ',', '.') . ' ₺'; ?></h2>
                                    </div>
                                    <div class="stats-icon text-success">
                                        <i class="bi bi-cash-stack"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>