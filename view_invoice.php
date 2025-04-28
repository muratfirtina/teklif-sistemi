<?php
// view_invoice.php - Fatura görüntüleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz fatura ID\'si.');
    header("Location: invoices.php");
    exit;
}

$invoice_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Fatura bilgilerini al
try {
    $stmt = $conn->prepare("
        SELECT i.*, q.reference_no as quotation_reference, 
               q.customer_id, q.user_id, q.status as quotation_status,
               c.name as customer_name, c.contact_person, c.email as customer_email, 
               c.phone as customer_phone, c.address as customer_address,
               c.tax_office, c.tax_number,
               u.full_name as user_name, u.email as user_email
        FROM invoices i
        JOIN quotations q ON i.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE i.id = :id
    ");
    $stmt->bindParam(':id', $invoice_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Fatura bulunamadı.');
        header("Location: invoices.php");
        exit;
    }
    
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fatura durumu
    $statusClass = 'secondary';
    $statusText = 'Bilinmiyor';
    
    switch($invoice['status']) {
        case 'unpaid':
            $statusClass = 'danger';
            $statusText = 'Ödenmedi';
            break;
        case 'partially_paid':
            $statusClass = 'warning';
            $statusText = 'Kısmi Ödendi';
            break;
        case 'paid':
            $statusClass = 'success';
            $statusText = 'Ödendi';
            break;
        case 'cancelled':
            $statusClass = 'secondary';
            $statusText = 'İptal Edildi';
            break;
    }
    
    // Teklif kalemlerini al (fatura detayında kullanmak için)
    $stmt = $conn->prepare("
        SELECT qi.*, 
            CASE 
                WHEN qi.item_type = 'product' THEN p.name
                WHEN qi.item_type = 'service' THEN s.name
                ELSE NULL
            END as item_name,
            CASE 
                WHEN qi.item_type = 'product' THEN p.code
                WHEN qi.item_type = 'service' THEN s.code
                ELSE NULL
            END as item_code
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id ASC
    ");
    $stmt->bindParam(':quotation_id', $invoice['quotation_id']);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fatura ödemelerini al
    $stmt = $conn->prepare("
        SELECT * FROM invoice_payments
        WHERE invoice_id = :invoice_id
        ORDER BY date ASC
    ");
    $stmt->bindParam(':invoice_id', $invoice_id);
    $stmt->execute();
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Toplam ödenen tutarı hesapla
    $totalPaid = 0;
    foreach ($payments as $payment) {
        $totalPaid += $payment['amount'];
    }
    
    // Kalan tutarı hesapla
    $remainingAmount = $invoice['total_amount'] - $totalPaid;
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: invoices.php");
    exit;
}

// Fatura durumunu güncelleme
if (isset($_GET['updateStatus']) && !empty($_GET['updateStatus'])) {
    $newStatus = $_GET['updateStatus'];
    $validStatuses = ['unpaid', 'partially_paid', 'paid', 'cancelled'];
    
    if (in_array($newStatus, $validStatuses)) {
        try {
            $stmt = $conn->prepare("UPDATE invoices SET status = :status WHERE id = :id");
            $stmt->bindParam(':status', $newStatus);
            $stmt->bindParam(':id', $invoice_id);
            $stmt->execute();
            
            setMessage('success', 'Fatura durumu başarıyla güncellendi.');
            header("Location: view_invoice.php?id=" . $invoice_id);
            exit;
        } catch(PDOException $e) {
            setMessage('error', 'Durum güncellenirken bir hata oluştu: ' . $e->getMessage());
        }
    }
}
$pageTitle = 'Fatura Görüntüle';
$currentPage = 'invoices';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
    <style>
        .info-card {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .info-card h5 {
            margin-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 5px;
            color: #495057;
        }
        .status-badge {
            font-size: 0.9rem;
            padding: 0.3rem 0.6rem;
        }
        .action-buttons {
            margin-bottom: 15px;
        }
        .action-buttons .btn {
            margin-right: 5px;
        }
        .payment-card {
            border-left: 4px solid #28a745;
            margin-bottom: 10px;
            transition: transform 0.3s ease;
        }
        .payment-card:hover {
            transform: translateY(-3px);
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
                <h1 class="h2">
                    Fatura: <?php echo htmlspecialchars($invoice['invoice_no']); ?>
                    <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                        <?php echo $statusText; ?>
                    </span>
                </h1>
                <a href="invoices.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Faturalara Dön
                </a>
            </div>
            
            <!-- İşlem Butonları -->
            <div class="action-buttons">
                <a href="edit_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-warning">
                    <i class="bi bi-pencil"></i> Düzenle
                </a>
                <a href="invoice_payment.php?id=<?php echo $invoice_id; ?>" class="btn btn-success">
                    <i class="bi bi-cash"></i> Ödeme Ekle
                </a>
                <a href="invoice_pdf.php?id=<?php echo $invoice_id; ?>" class="btn btn-primary" target="_blank">
                    <i class="bi bi-file-pdf"></i> PDF İndir
                </a>
                <a href="send_invoice_email.php?id=<?php echo $invoice_id; ?>" class="btn btn-info">
                    <i class="bi bi-envelope"></i> E-posta Gönder
                </a>
                
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear"></i> Durum Değiştir
                    </button>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="view_invoice.php?id=<?php echo $invoice_id; ?>&updateStatus=unpaid">Ödenmedi</a></li>
                        <li><a class="dropdown-item" href="view_invoice.php?id=<?php echo $invoice_id; ?>&updateStatus=partially_paid">Kısmi Ödendi</a></li>
                        <li><a class="dropdown-item" href="view_invoice.php?id=<?php echo $invoice_id; ?>&updateStatus=paid">Ödendi</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="view_invoice.php?id=<?php echo $invoice_id; ?>&updateStatus=cancelled" onclick="return confirm('Bu faturayı iptal etmek istediğinizden emin misiniz?')">İptal Edildi</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="row">
                <!-- Sol Sütun - Bilgiler -->
                <div class="col-md-4">
                    <!-- Fatura Bilgileri -->
                    <div class="info-card">
                        <h5>Fatura Bilgileri</h5>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Fatura No:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Teklif No:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['quotation_reference']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Fatura Tarihi:</label>
                            <div class="col-7"><?php echo date('d.m.Y', strtotime($invoice['date'])); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Vade Tarihi:</label>
                            <div class="col-7"><?php echo date('d.m.Y', strtotime($invoice['due_date'])); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Durum:</label>
                            <div class="col-7">
                                <span class="badge bg-<?php echo $statusClass; ?>">
                                    <?php echo $statusText; ?>
                                </span>
                            </div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Oluşturulma:</label>
                            <div class="col-7"><?php echo date('d.m.Y H:i', strtotime($invoice['created_at'])); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Son Güncelleme:</label>
                            <div class="col-7"><?php echo date('d.m.Y H:i', strtotime($invoice['updated_at'])); ?></div>
                        </div>
                    </div>
                    
                    <!-- Müşteri Bilgileri -->
                    <div class="info-card">
                        <h5>Müşteri Bilgileri</h5>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Firma Adı:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">İlgili Kişi:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['contact_person']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">E-posta:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['customer_email']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Telefon:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['customer_phone']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Adres:</label>
                            <div class="col-7"><?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Vergi Dairesi:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['tax_office']); ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-5 fw-bold">Vergi No:</label>
                            <div class="col-7"><?php echo htmlspecialchars($invoice['tax_number']); ?></div>
                        </div>
                    </div>
                    
                    <!-- Ödeme Özeti -->
                    <div class="info-card">
                        <h5>Ödeme Özeti</h5>
                        <div class="mb-2 row">
                            <label class="col-6 fw-bold">Toplam Tutar:</label>
                            <div class="col-6 text-end fw-bold"><?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-6 fw-bold">Ödenen:</label>
                            <div class="col-6 text-end text-success"><?php echo number_format($totalPaid, 2, ',', '.') . ' ₺'; ?></div>
                        </div>
                        <div class="mb-2 row">
                            <label class="col-6 fw-bold">Kalan:</label>
                            <div class="col-6 text-end text-danger"><?php echo number_format($remainingAmount, 2, ',', '.') . ' ₺'; ?></div>
                        </div>
                        
                        <?php if ($remainingAmount > 0 && $invoice['status'] != 'cancelled'): ?>
                            <div class="d-grid gap-2 mt-3">
                                <a href="invoice_payment.php?id=<?php echo $invoice_id; ?>" class="btn btn-success">
                                    <i class="bi bi-cash"></i> Ödeme Ekle
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Sağ Sütun - Fatura Detayı ve Ödemeler -->
                <div class="col-md-8">
                    <!-- Fatura Detayı -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Fatura Detayı</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>S.No</th>
                                            <th>Tür</th>
                                            <th>Kod</th>
                                            <th>Açıklama</th>
                                            <th class="text-center">Miktar</th>
                                            <th class="text-end">Birim Fiyat</th>
                                            <th class="text-center">İnd.%</th>
                                            <th class="text-end">KDV%</th>
                                            <th class="text-end">Toplam</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $counter = 1;
                                        foreach ($items as $item): 
                                            $item_type = $item['item_type'] == 'product' ? 'Ürün' : 'Hizmet';
                                            $unit_price = $item['unit_price'];
                                            $discount_percent = $item['discount_percent'];
                                            $quantity = $item['quantity'];
                                            $tax_rate = $item['tax_rate'];
                                            
                                            // Hesaplamalar
                                            $discount_amount = $unit_price * ($discount_percent / 100);
                                            $unit_price_after_discount = $unit_price - $discount_amount;
                                            $line_subtotal = $quantity * $unit_price_after_discount;
                                            $line_tax_amount = $line_subtotal * ($tax_rate / 100);
                                            $line_total = $line_subtotal + $line_tax_amount;
                                        ?>
                                            <tr>
                                                <td><?php echo $counter++; ?></td>
                                                <td><?php echo $item_type; ?></td>
                                                <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                <td class="text-center"><?php echo $quantity; ?></td>
                                                <td class="text-end"><?php echo number_format($unit_price, 2, ',', '.') . ' ₺'; ?></td>
                                                <td class="text-center"><?php echo $discount_percent; ?>%</td>
                                                <td class="text-end"><?php echo $tax_rate; ?>%</td>
                                                <td class="text-end"><?php echo number_format($line_total, 2, ',', '.') . ' ₺'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="7"></td>
                                            <td class="fw-bold text-end">Ara Toplam:</td>
                                            <td class="text-end"><?php echo number_format($invoice['subtotal'], 2, ',', '.') . ' ₺'; ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="7"></td>
                                            <td class="fw-bold text-end">İndirim:</td>
                                            <td class="text-end"><?php echo number_format($invoice['discount_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="7"></td>
                                            <td class="fw-bold text-end">KDV:</td>
                                            <td class="text-end"><?php echo number_format($invoice['tax_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        </tr>
                                        <tr>
                                            <td colspan="7"></td>
                                            <td class="fw-bold text-end">Genel Toplam:</td>
                                            <td class="text-end fw-bold"><?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ödemeler -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Ödemeler</h5>
                            <a href="invoice_payment.php?id=<?php echo $invoice_id; ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus-circle"></i> Yeni Ödeme
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (count($payments) > 0): ?>
                                <?php 
                                $totalPayment = 0;
                                foreach ($payments as $payment): 
                                    $totalPayment += $payment['amount'];
                                ?>
                                    <div class="payment-card card mb-3">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="d-flex justify-content-between mb-2">
                                                        <span class="fw-bold">Tarih:</span>
                                                        <span><?php echo date('d.m.Y', strtotime($payment['date'])); ?></span>
                                                    </div>
                                                    <div class="d-flex justify-content-between mb-2">
                                                        <span class="fw-bold">Ödeme Yöntemi:</span>
                                                        <span><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                                                    </div>
                                                    <?php if (!empty($payment['reference_no'])): ?>
                                                        <div class="d-flex justify-content-between mb-2">
                                                            <span class="fw-bold">Referans No:</span>
                                                            <span><?php echo htmlspecialchars($payment['reference_no']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($payment['notes'])): ?>
                                                        <div class="d-flex justify-content-between mb-2">
                                                            <span class="fw-bold">Notlar:</span>
                                                            <span><?php echo htmlspecialchars($payment['notes']); ?></span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="h4 text-success mb-3">
                                                        <?php echo number_format($payment['amount'], 2, ',', '.') . ' ₺'; ?>
                                                    </div>
                                                    <a href="edit_payment.php?id=<?php echo $payment['id']; ?>" class="btn btn-sm btn-warning me-1">
                                                        <i class="bi bi-pencil"></i> Düzenle
                                                    </a>
                                                    <a href="delete_payment.php?id=<?php echo $payment['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu ödemeyi silmek istediğinizden emin misiniz?')">
                                                        <i class="bi bi-trash"></i> Sil
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="mt-3">
                                    <div class="card bg-light">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h5>Toplam Ödeme</h5>
                                                </div>
                                                <div class="col-md-6 text-end">
                                                    <h4 class="text-success mb-0">
                                                        <?php echo number_format($totalPaid, 2, ',', '.') . ' ₺'; ?>
                                                    </h4>
                                                    <small class="text-muted">
                                                        Kalan: <?php echo number_format($remainingAmount, 2, ',', '.') . ' ₺'; ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Henüz ödeme kaydı bulunmamaktadır.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Notlar -->
                    <?php if (!empty($invoice['notes'])): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Notlar</h5>
                            </div>
                            <div class="card-body">
                                <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>