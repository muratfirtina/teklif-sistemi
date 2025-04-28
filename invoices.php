<?php
// invoices.php - Fatura listesi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Faturalar tablosu kontrolü
try {
    $tableExists = $conn->query("SHOW TABLES LIKE 'invoices'")->rowCount() > 0;
    
    if (!$tableExists) {
        // Faturalar tablosunu oluştur
        $conn->exec("CREATE TABLE invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(50) NOT NULL UNIQUE,
            quotation_id INT NOT NULL,
            date DATE NOT NULL,
            due_date DATE NOT NULL,
            status ENUM('unpaid', 'paid', 'partially_paid', 'cancelled') NOT NULL DEFAULT 'unpaid',
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes TEXT,
            payment_details TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Fatura ödemeleri tablosunu oluştur
        $conn->exec("CREATE TABLE invoice_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            date DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            reference_no VARCHAR(100),
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        setMessage('success', 'Fatura tabloları başarıyla oluşturuldu.');
    }
} catch(PDOException $e) {
    setMessage('error', 'Tablo kontrolü sırasında bir hata oluştu: ' . $e->getMessage());
}

// Fatura silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    try {
        // Önce fatura ödemelerini sil
        $deletePaymentsStmt = $conn->prepare("DELETE FROM invoice_payments WHERE invoice_id = :id");
        $deletePaymentsStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deletePaymentsStmt->execute();
        
        // Sonra faturayı sil
        $deleteInvoiceStmt = $conn->prepare("DELETE FROM invoices WHERE id = :id");
        $deleteInvoiceStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleteInvoiceStmt->execute();
        
        setMessage('success', 'Fatura başarıyla silindi.');
    } catch(PDOException $e) {
        setMessage('error', 'Fatura silinirken bir hata oluştu: ' . $e->getMessage());
    }
    
    header("Location: invoices.php");
    exit;
}

// Fatura listesini al
$invoices = [];
try {
    $stmt = $conn->query("
        SELECT i.*, q.reference_no as quotation_reference, c.name as customer_name
        FROM invoices i
        JOIN quotations q ON i.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        ORDER BY i.date DESC
    ");
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Fatura listesi alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Faturalar';
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
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Faturalar</h1>
                <a href="create_invoice.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Yeni Fatura Oluştur
                </a>
            </div>
            
            <!-- Fatura Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($invoices) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Fatura No</th>
                                        <th>Teklif No</th>
                                        <th>Müşteri</th>
                                        <th>Tarih</th>
                                        <th>Vade Tarihi</th>
                                        <th>Tutar</th>
                                        <th>Ödenen</th>
                                        <th>Durum</th>
                                        <th width="180">İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($invoice['invoice_no']); ?></td>
                                            <td><?php echo htmlspecialchars($invoice['quotation_reference']); ?></td>
                                            <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($invoice['date'])); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($invoice['due_date'])); ?></td>
                                            <td><?php echo number_format($invoice['total_amount'], 2, ',', '.') . ' ₺'; ?></td>
                                            <td><?php echo number_format($invoice['paid_amount'], 2, ',', '.') . ' ₺'; ?></td>
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
                                                <div class="btn-group">
                                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-info action-btn" title="Görüntüle">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="edit_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-warning action-btn" title="Düzenle">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="invoice_payment.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-success action-btn" title="Ödeme Ekle">
                                                        <i class="bi bi-cash"></i>
                                                    </a>
                                                    <a href="invoice_pdf.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-primary action-btn" target="_blank" title="PDF">
                                                        <i class="bi bi-file-pdf"></i>
                                                    </a>
                                                    <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars(addslashes($invoice['invoice_no'])); ?>')" class="btn btn-sm btn-danger action-btn" title="Sil">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz fatura bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Fatura Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu faturayı silmek istediğinizden emin misiniz?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Silme onay fonksiyonu
        function confirmDelete(id, invoiceNo) {
            document.getElementById('deleteConfirmText').textContent = '"' + invoiceNo + '" faturasını silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'invoices.php?delete=' + id;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>