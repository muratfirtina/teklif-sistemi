<?php
// create_invoice.php - Fatura oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Kabul edilen teklifleri getir
$acceptedQuotations = [];
try {
    $stmt = $conn->query("
        SELECT q.id, q.reference_no, q.date, q.total_amount, c.name as customer_name
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        WHERE q.status = 'accepted'
        AND q.id NOT IN (SELECT quotation_id FROM invoices)
        ORDER BY q.date DESC
    ");
    $acceptedQuotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Teklif listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $quotationId = intval($_POST['quotation_id']);
    $invoiceDate = $_POST['invoice_date'];
    $dueDate = $_POST['due_date'];
    $notes = trim($_POST['notes']);
    $applyDiscount = isset($_POST['apply_discount']) ? true : false;
    
    // Basit doğrulama
    $errors = [];
    
    if ($quotationId <= 0) {
        $errors[] = "Geçerli bir teklif seçmelisiniz.";
    }
    
    if (empty($invoiceDate)) {
        $errors[] = "Fatura tarihi zorunludur.";
    }
    
    if (empty($dueDate)) {
        $errors[] = "Vade tarihi zorunludur.";
    } elseif (strtotime($dueDate) < strtotime($invoiceDate)) {
        $errors[] = "Vade tarihi, fatura tarihinden önce olamaz.";
    }
    
    // Hata yoksa faturayı oluştur
    if (empty($errors)) {
        try {
            // Teklifin bilgilerini al
            $stmt = $conn->prepare("
                SELECT * FROM quotations WHERE id = :id AND status = 'accepted'
            ");
            $stmt->bindParam(':id', $quotationId);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                throw new Exception("Teklif bulunamadı veya kabul edilmemiş.");
            }
            
            $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Fatura numarası oluştur (FTR-Yıl-Ay-Sıra No)
            $year = date('Y');
            $month = date('m');
            
            $stmt = $conn->query("SELECT COUNT(*) FROM invoices WHERE YEAR(date) = $year AND MONTH(date) = $month");
            $count = $stmt->fetchColumn();
            $sequence = $count + 1;
            
            $invoiceNo = sprintf("FTR-%s-%s-%03d", $year, $month, $sequence);
            
            // Faturanın tutarlarını belirle
            $subtotal = $quotation['subtotal'];
            $taxAmount = $quotation['tax_amount'];
            $discountAmount = $applyDiscount ? $quotation['discount_amount'] : 0;
            $totalAmount = $subtotal - $discountAmount + $taxAmount;
            
            // Faturayı oluştur
            $stmt = $conn->prepare("
                INSERT INTO invoices (
                    invoice_no, quotation_id, date, due_date, status,
                    subtotal, tax_amount, discount_amount, total_amount, notes
                ) VALUES (
                    :invoice_no, :quotation_id, :date, :due_date, 'unpaid',
                    :subtotal, :tax_amount, :discount_amount, :total_amount, :notes
                )
            ");
            
            $stmt->bindParam(':invoice_no', $invoiceNo);
            $stmt->bindParam(':quotation_id', $quotationId);
            $stmt->bindParam(':date', $invoiceDate);
            $stmt->bindParam(':due_date', $dueDate);
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':tax_amount', $taxAmount);
            $stmt->bindParam(':discount_amount', $discountAmount);
            $stmt->bindParam(':total_amount', $totalAmount);
            $stmt->bindParam(':notes', $notes);
            
            $stmt->execute();
            
            $invoiceId = $conn->lastInsertId();
            
            setMessage('success', 'Fatura başarıyla oluşturuldu: ' . $invoiceNo);
            header("Location: view_invoice.php?id=" . $invoiceId);
            exit;
        } catch(Exception $e) {
            $errors[] = "Fatura oluşturulurken bir hata oluştu: " . $e->getMessage();
        }
    }
}

// Son oluşturulan 5 faturayı getir
$recentInvoices = [];
try {
    $stmt = $conn->query("
        SELECT i.*, q.reference_no as quotation_reference, c.name as customer_name
        FROM invoices i
        JOIN quotations q ON i.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        ORDER BY i.created_at DESC
        LIMIT 5
    ");
    $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // İsteğe bağlı, bu nedenle hata göstermeye gerek yok
}
$pageTitle = 'Fatura Oluştur';
$currentPage = 'create_invoice';
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
                <h1 class="h2">Fatura Oluştur</h1>
                <a href="invoices.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Faturalara Dön
                </a>
            </div>
            
            <?php if (count($acceptedQuotations) > 0): ?>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="invoiceForm">
                    <div class="row">
                        <!-- Sol Sütun - Teklif Seçimi -->
                        <div class="col-md-8 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Faturası Oluşturulacak Teklifi Seçin</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($acceptedQuotations as $quotation): ?>
                                            <div class="col-md-6 mb-3">
                                                <div class="card quotation-card" onclick="selectQuotation(this, <?php echo $quotation['id']; ?>)">
                                                    <div class="card-body">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($quotation['reference_no']); ?></h5>
                                                            <span class="badge bg-success">Kabul Edildi</span>
                                                        </div>
                                                        <p class="card-text mb-2"><strong>Müşteri:</strong> <?php echo htmlspecialchars($quotation['customer_name']); ?></p>
                                                        <p class="card-text mb-2"><strong>Tarih:</strong> <?php echo date('d.m.Y', strtotime($quotation['date'])); ?></p>
                                                        <p class="card-text mb-0"><strong>Tutar:</strong> <?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></p>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" id="quotation_id" name="quotation_id" value="">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun - Fatura Bilgileri -->
                        <div class="col-md-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">Fatura Bilgileri</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="invoice_date" class="form-label">Fatura Tarihi <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="invoice_date" name="invoice_date" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="due_date" class="form-label">Vade Tarihi <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="due_date" name="due_date" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                    </div>
                                    
                                    <div class="mb-3 form-check">
                                        <input type="checkbox" class="form-check-input" id="apply_discount" name="apply_discount" checked>
                                        <label class="form-check-label" for="apply_discount">Teklifteki indirimi uygula</label>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Notlar</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary" id="submitBtn" disabled>Fatura Oluştur</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                
                <?php if (count($recentInvoices) > 0): ?>
                    <!-- Son Oluşturulan Faturalar -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Son Oluşturulan Faturalar</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Fatura No</th>
                                            <th>Teklif No</th>
                                            <th>Müşteri</th>
                                            <th>Tarih</th>
                                            <th>Tutar</th>
                                            <th>Durum</th>
                                            <th>İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentInvoices as $invoice): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($invoice['invoice_no']); ?></td>
                                                <td><?php echo htmlspecialchars($invoice['quotation_reference']); ?></td>
                                                <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                                                <td><?php echo date('d.m.Y', strtotime($invoice['date'])); ?></td>
                                                <td><?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                                <td>
                                                    <?php
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
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info" title="Görüntüle">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Fatura oluşturmak için kabul edilmiş teklif bulunmamaktadır. Önce bir teklif oluşturun ve kabul edildi olarak işaretleyin.
                    <div class="mt-3">
                        <a href="quotations.php" class="btn btn-primary">Tekliflere Git</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Teklif seçme işlevi
        function selectQuotation(element, quotationId) {
            // Önceki seçimi kaldır
            document.querySelectorAll('.quotation-card.selected').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Yeni seçimi işaretle
            element.classList.add('selected');
            
            // Gizli alana teklif ID'sini kaydet
            document.getElementById('quotation_id').value = quotationId;
            
            // Gönder butonunu etkinleştir
            document.getElementById('submitBtn').disabled = false;
        }
        
        // Form gönderim kontrolü
        document.getElementById('invoiceForm').addEventListener('submit', function(e) {
            const quotationId = document.getElementById('quotation_id').value;
            
            if (!quotationId) {
                e.preventDefault();
                alert('Lütfen bir teklif seçin.');
            }
        });
    </script>