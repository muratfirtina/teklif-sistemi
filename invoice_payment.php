<?php
// invoice_payment.php - Fatura ödeme ekleme sayfası
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
        SELECT i.*, q.reference_no as quotation_reference, c.name as customer_name
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
    
    // İptal edilmiş faturaya ödeme eklenemez
    if ($invoice['status'] == 'cancelled') {
        setMessage('error', 'İptal edilmiş faturaya ödeme eklenemez.');
        header("Location: view_invoice.php?id=" . $invoice_id);
        exit;
    }
    
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
    
    // Tam ödenmiş faturaya ödeme eklenemez
    if ($remainingAmount <= 0 && $invoice['status'] == 'paid') {
        setMessage('error', 'Bu fatura zaten tam ödenmiş.');
        header("Location: view_invoice.php?id=" . $invoice_id);
        exit;
    }
    
} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: invoices.php");
    exit;
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $paymentDate = $_POST['payment_date'];
    $paymentAmount = floatval(str_replace(',', '.', $_POST['payment_amount']));
    $paymentMethod = $_POST['payment_method'];
    $referenceNo = trim($_POST['reference_no']);
    $notes = trim($_POST['notes']);
    
    // Basit doğrulama
    $errors = [];
    
    if (empty($paymentDate)) {
        $errors[] = "Ödeme tarihi zorunludur.";
    }
    
    if ($paymentAmount <= 0) {
        $errors[] = "Ödeme tutarı sıfırdan büyük olmalıdır.";
    }
    
    if ($paymentAmount > $remainingAmount) {
        $errors[] = "Ödeme tutarı kalan tutardan büyük olamaz.";
    }
    
    if (empty($paymentMethod)) {
        $errors[] = "Ödeme yöntemi zorunludur.";
    }
    
    // Hata yoksa ödemeyi ekle
    if (empty($errors)) {
        try {
            // İşlemi başlat
            $conn->beginTransaction();
            
            // Ödemeyi ekle
            $stmt = $conn->prepare("
                INSERT INTO invoice_payments (
                    invoice_id, date, amount, payment_method, reference_no, notes
                ) VALUES (
                    :invoice_id, :date, :amount, :payment_method, :reference_no, :notes
                )
            ");
            
            $stmt->bindParam(':invoice_id', $invoice_id);
            $stmt->bindParam(':date', $paymentDate);
            $stmt->bindParam(':amount', $paymentAmount);
            $stmt->bindParam(':payment_method', $paymentMethod);
            $stmt->bindParam(':reference_no', $referenceNo);
            $stmt->bindParam(':notes', $notes);
            
            $stmt->execute();
            
            // Toplam ödenen tutarı güncelle
            $newTotalPaid = $totalPaid + $paymentAmount;
            
            // Fatura durumunu güncelle
            if ($newTotalPaid >= $invoice['total_amount']) {
                // Tam ödeme
                $newStatus = 'paid';
            } elseif ($newTotalPaid > 0) {
                // Kısmi ödeme
                $newStatus = 'partially_paid';
            } else {
                // Hiç ödeme yok
                $newStatus = 'unpaid';
            }
            
            $stmt = $conn->prepare("
                UPDATE invoices 
                SET paid_amount = :paid_amount, status = :status
                WHERE id = :id
            ");
            
            $stmt->bindParam(':paid_amount', $newTotalPaid);
            $stmt->bindParam(':status', $newStatus);
            $stmt->bindParam(':id', $invoice_id);
            
            $stmt->execute();
            
            // İşlemi tamamla
            $conn->commit();
            
            setMessage('success', 'Ödeme başarıyla eklendi.');
            header("Location: view_invoice.php?id=" . $invoice_id);
            exit;
            
        } catch(PDOException $e) {
            // Hata durumunda geri al
            $conn->rollBack();
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}
$pageTitle = 'Ödeme Ekle';
$currentPage = 'invoices';
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
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Ödeme Ekle</h1>
                <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Faturaya Dön
                </a>
            </div>
            
            <div class="row">
                <!-- Sol Sütun - Fatura Bilgileri -->
                <div class="col-md-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Fatura Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Fatura No:</label>
                                <div><?php echo htmlspecialchars($invoice['invoice_no']); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Müşteri:</label>
                                <div><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Fatura Tarihi:</label>
                                <div><?php echo date('d.m.Y', strtotime($invoice['date'])); ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Vade Tarihi:</label>
                                <div><?php echo date('d.m.Y', strtotime($invoice['due_date'])); ?></div>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Toplam Tutar:</label>
                                <div class="fs-5"><?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Ödenen Tutar:</label>
                                <div class="text-success"><?php echo number_format($totalPaid, 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Kalan Tutar:</label>
                                <div class="fs-5 text-danger"><?php echo number_format($remainingAmount, 2, ',', '.') . ' ₺'; ?></div>
                            </div>
                            
                            <?php if (count($payments) > 0): ?>
                                <hr>
                                <h6 class="mb-3">Önceki Ödemeler</h6>
                                <?php foreach ($payments as $payment): ?>
                                    <div class="card mb-2 bg-light">
                                        <div class="card-body py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small><?php echo date('d.m.Y', strtotime($payment['date'])); ?></small>
                                                <span class="badge bg-success">
                                                    <?php echo number_format($payment['amount'], 2, ',', '.') . ' ₺'; ?>
                                                </span>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($payment['payment_method']); ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Sağ Sütun - Ödeme Formu -->
                <div class="col-md-8">
                    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $invoice_id); ?>" id="paymentForm">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Ödeme Bilgileri</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="payment_date" class="form-label">Ödeme Tarihi <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="payment_date" name="payment_date" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="payment_amount" class="form-label">Ödeme Tutarı <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="payment_amount" name="payment_amount" required value="<?php echo number_format($remainingAmount, 2, ',', ''); ?>">
                                            <span class="input-group-text">₺</span>
                                        </div>
                                        <div class="form-text">
                                            <a href="#" onclick="setFullAmount(); return false;">Tam tutarı gir</a>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Ödeme Yöntemi <span class="text-danger">*</span></label>
                                    <input type="hidden" id="payment_method" name="payment_method" required>
                                    
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <div class="card payment-method-card h-100 text-center" onclick="selectPaymentMethod(this, 'Nakit')">
                                                <div class="card-body">
                                                    <div class="payment-icon">
                                                        <i class="bi bi-cash"></i>
                                                    </div>
                                                    <h6 class="card-title">Nakit</h6>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card payment-method-card h-100 text-center" onclick="selectPaymentMethod(this, 'Banka Transferi')">
                                                <div class="card-body">
                                                    <div class="payment-icon">
                                                        <i class="bi bi-bank"></i>
                                                    </div>
                                                    <h6 class="card-title">Banka Transferi</h6>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card payment-method-card h-100 text-center" onclick="selectPaymentMethod(this, 'Kredi Kartı')">
                                                <div class="card-body">
                                                    <div class="payment-icon">
                                                        <i class="bi bi-credit-card"></i>
                                                    </div>
                                                    <h6 class="card-title">Kredi Kartı</h6>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="card payment-method-card h-100 text-center" onclick="selectPaymentMethod(this, 'Çek')">
                                                <div class="card-body">
                                                    <div class="payment-icon">
                                                        <i class="bi bi-file-earmark-text"></i>
                                                    </div>
                                                    <h6 class="card-title">Çek</h6>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="reference_no" class="form-label">Referans / İşlem No</label>
                                    <input type="text" class="form-control" id="reference_no" name="reference_no" placeholder="Banka işlem no, çek no, vs.">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Notlar</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Ödeme ile ilgili ek bilgiler..."></textarea>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-between">
                                <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-secondary">İptal</a>
                                <button type="submit" class="btn btn-success" id="submitBtn" disabled>Ödemeyi Kaydet</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Ödeme tutarı formatla ve nokta/virgül kontrolü
        document.getElementById('payment_amount').addEventListener('input', function(e) {
            let value = e.target.value;
            
            // Sadece rakam ve virgül izin ver
            value = value.replace(/[^0-9,]/g, '');
            
            // Birden fazla virgül varsa, sadece ilkini kabul et
            const commaCount = (value.match(/,/g) || []).length;
            if (commaCount > 1) {
                const parts = value.split(',');
                value = parts[0] + ',' + parts.slice(1).join('');
            }
            
            e.target.value = value;
        });
        
        // Kalan tutarı otomatik doldur
        function setFullAmount() {
            document.getElementById('payment_amount').value = '<?php echo number_format($remainingAmount, 2, ',', ''); ?>';
        }
        
        // Ödeme yöntemi seçme
        function selectPaymentMethod(element, method) {
            // Önceki seçimi kaldır
            document.querySelectorAll('.payment-method-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Yeni seçimi işaretle
            element.classList.add('selected');
            
            // Gizli alana ödeme yöntemini kaydet
            document.getElementById('payment_method').value = method;
            
            // Gönder butonunu etkinleştir
            document.getElementById('submitBtn').disabled = false;
        }
        
        // Form gönderim kontrolü
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            const paymentMethod = document.getElementById('payment_method').value;
            const paymentAmount = document.getElementById('payment_amount').value;
            
            if (!paymentMethod) {
                e.preventDefault();
                alert('Lütfen bir ödeme yöntemi seçin.');
                return false;
            }
            
            if (!paymentAmount || parseFloat(paymentAmount.replace(',', '.')) <= 0) {
                e.preventDefault();
                alert('Lütfen geçerli bir ödeme tutarı girin.');
                return false;
            }
            
            // Maksimum ödeme kontrolü
            const maxAmount = <?php echo $remainingAmount; ?>;
            const enteredAmount = parseFloat(paymentAmount.replace(',', '.'));
            
            if (enteredAmount > maxAmount) {
                e.preventDefault();
                alert('Ödeme tutarı kalan tutardan büyük olamaz.');
                return false;
            }
        });
    </script>
</body>
</html>