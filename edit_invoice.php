<?php
// edit_invoice.php - Fatura düzenleme sayfası
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
               q.customer_id, q.status as quotation_status,
               c.name as customer_name
        FROM invoices i
        JOIN quotations q ON i.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
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
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: invoices.php");
    exit;
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $invoice_date = $_POST['invoice_date'];
    $due_date = $_POST['due_date'];
    $status = $_POST['status'];
    $notes = trim($_POST['notes']);
    $apply_discount = isset($_POST['apply_discount']) ? true : false;
    
    // Basit doğrulama
    $errors = [];
    
    if (empty($invoice_date)) {
        $errors[] = "Fatura tarihi zorunludur.";
    }
    
    if (empty($due_date)) {
        $errors[] = "Vade tarihi zorunludur.";
    } elseif (strtotime($due_date) < strtotime($invoice_date)) {
        $errors[] = "Vade tarihi, fatura tarihinden önce olamaz.";
    }
    
    // Hata yoksa faturayı güncelle
    if (empty($errors)) {
        try {
            // Faturanın tutarlarını belirle
            $subtotal = $invoice['subtotal'];
            $taxAmount = $invoice['tax_amount'];
            $discountAmount = $apply_discount ? $invoice['discount_amount'] : 0;
            $totalAmount = $subtotal - $discountAmount + $taxAmount;
            
            // Faturayı güncelle
            $stmt = $conn->prepare("
                UPDATE invoices SET 
                date = :date, 
                due_date = :due_date, 
                status = :status,
                discount_amount = :discount_amount, 
                total_amount = :total_amount, 
                notes = :notes
                WHERE id = :id
            ");
            
            $stmt->bindParam(':date', $invoice_date);
            $stmt->bindParam(':due_date', $due_date);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':discount_amount', $discountAmount);
            $stmt->bindParam(':total_amount', $totalAmount);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':id', $invoice_id);
            
            $stmt->execute();
            
            setMessage('success', 'Fatura başarıyla güncellendi.');
            header("Location: view_invoice.php?id=" . $invoice_id);
            exit;
        } catch(Exception $e) {
            $errors[] = "Fatura güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Fatura Düzenle';
$currentPage = 'invoices';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Fatura Düzenle</h1>
            <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Faturaya Dön
            </a>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Fatura Bilgileri</h5>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $invoice_id); ?>">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fatura No:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['invoice_no']); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Teklif No:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['quotation_reference']); ?>" readonly>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Müşteri:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($invoice['customer_name']); ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="status" class="form-label">Durum:</label>
                            <select class="form-select" id="status" name="status">
                                <option value="unpaid" <?php echo $invoice['status'] == 'unpaid' ? 'selected' : ''; ?>>Ödenmedi</option>
                                <option value="partially_paid" <?php echo $invoice['status'] == 'partially_paid' ? 'selected' : ''; ?>>Kısmi Ödendi</option>
                                <option value="paid" <?php echo $invoice['status'] == 'paid' ? 'selected' : ''; ?>>Ödendi</option>
                                <option value="cancelled" <?php echo $invoice['status'] == 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="invoice_date" class="form-label">Fatura Tarihi <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="invoice_date" name="invoice_date" required value="<?php echo $invoice['date']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="due_date" class="form-label">Vade Tarihi <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="due_date" name="due_date" required value="<?php echo $invoice['due_date']; ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Ara Toplam:</label>
                            <input type="text" class="form-control" value="<?php echo number_format($invoice['subtotal'], 2, ',', '.') . ' ₺'; ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">KDV:</label>
                            <input type="text" class="form-control" value="<?php echo number_format($invoice['tax_amount'], 2, ',', '.') . ' ₺'; ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">İndirim:</label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="<?php echo number_format($invoice['discount_amount'], 2, ',', '.') . ' ₺'; ?>" readonly>
                                <div class="input-group-text">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="apply_discount" name="apply_discount" <?php echo $invoice['discount_amount'] > 0 ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="apply_discount">Uygula</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Toplam Tutar:</label>
                        <input type="text" class="form-control fw-bold" value="<?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notlar</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($invoice['notes']); ?></textarea>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary me-md-2">İptal</a>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Ödemeler -->
        <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Ödemeler</h5>
                <a href="invoice_payment.php?id=<?php echo $invoice_id; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-plus-circle"></i> Yeni Ödeme
                </a>
            </div>
            <div class="card-body">
                <?php if (count($payments) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped">
                            <thead>
                                <tr>
                                    <th>Tarih</th>
                                    <th>Tutar</th>
                                    <th>Ödeme Yöntemi</th>
                                    <th>Referans No</th>
                                    <th>Notlar</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('d.m.Y', strtotime($payment['date'])); ?></td>
                                        <td><?php echo number_format($payment['amount'], 2, ',', '.') . ' ₺'; ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['reference_no']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['notes']); ?></td>
                                        <td>
                                            <a href="edit_payment.php?id=<?php echo $payment['id']; ?>" class="btn btn-sm btn-warning">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_payment.php?id=<?php echo $payment['id']; ?>&invoice_id=<?php echo $invoice_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bu ödemeyi silmek istediğinizden emin misiniz?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-info">
                                    <th>Toplam Ödeme:</th>
                                    <th><?php echo number_format($totalPaid, 2, ',', '.') . ' ₺'; ?></th>
                                    <th colspan="4"></th>
                                </tr>
                                <tr class="table-warning">
                                    <th>Kalan Tutar:</th>
                                    <th><?php echo number_format($invoice['total_amount'] - $totalPaid, 2, ',', '.') . ' ₺'; ?></th>
                                    <th colspan="4"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Henüz ödeme kaydı bulunmamaktadır.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // İndirim uygulandığında toplam tutarı güncelle
    document.getElementById('apply_discount').addEventListener('change', function() {
        // Burada AJAX ile sunucuya istek gönderilerek anlık güncelleme yapılabilir
        // Şimdilik sadece sayfa yenileniyor
        document.querySelector('form').submit();
    });
</script>
</body>
</html>