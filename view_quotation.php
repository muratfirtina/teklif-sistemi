<?php
// view_quotation.php - Teklif görüntüleme sayfası (Son Hali)
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/page_access_control.php'; // Üretim rolü kontrolü için

// getCompanySettings ve getEmailSettings fonksiyonları için
// Bu fonksiyonların bulunduğu dosyayı include edin.
// Eğer email.php sadece PHPMailer içeriyorsa, bu fonksiyonları
// settings.php'den veri çekecek şekilde yeniden yazın veya başka bir helper dosyasına taşıyın.
require_once 'includes/email.php'; // Varsayılan olarak burada olduğunu varsayıyoruz

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz teklif ID\'si.');
    header("Location: quotations.php");
    exit;
}

$quotation_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Gerekli tüm verileri çekmek için değişkenleri tanımla
$quotation = null;
$items = [];
$customer = null;
$user = null;         // Teklifi hazırlayan kullanıcı
$settings = [];
$currentUserSignatureText = ''; // Giriş yapan kullanıcının imzası
$productionOrderExists = false;
$productionOrderId = null;
$invoiceExists = false;
$invoiceId = null;

try {
    // 1. Teklif, Müşteri ve Hazırlayan Kullanıcı Bilgilerini Al
    $stmt = $conn->prepare("
        SELECT q.*,
               c.id as customer_id_alias, c.name as customer_name, c.contact_person, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address, c.tax_office, c.tax_number,
               u.id as user_id_alias, u.full_name as user_name, u.email as user_email
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Müşteri ve Hazırlayan Kullanıcı verilerini ayrı dizilere al
    $customer = [
        'id' => $quotation['customer_id_alias'], 'name' => $quotation['customer_name'],
        'contact_person' => $quotation['contact_person'], 'email' => $quotation['customer_email'],
        'phone' => $quotation['customer_phone'], 'address' => $quotation['customer_address'],
        'tax_office' => $quotation['tax_office'], 'tax_number' => $quotation['tax_number']
    ];
    $user = [ // Teklifi hazırlayan kullanıcı
        'id' => $quotation['user_id_alias'], 'name' => $quotation['user_name'],
        'email' => $quotation['user_email']
    ];

    // 2. Teklif Kalemlerini Al (Color_hex bilgisini de dahil et)
    $stmtItems = $conn->prepare("
        SELECT qi.*,
            CASE WHEN qi.item_type = 'product' THEN p.name ELSE s.name END as item_name,
            CASE WHEN qi.item_type = 'product' THEN p.code ELSE s.code END as item_code,
            CASE WHEN qi.item_type = 'product' THEN p.color_hex ELSE NULL END as color_hex
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE qi.quotation_id = :quotation_id ORDER BY qi.id ASC");
    $stmtItems->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 3. Şirket Ayarlarını Al
    if (function_exists('getCompanySettings')) $settings = getCompanySettings();
    else $settings = ['company_name' => 'Şirketiniz'];

    // 4. Giriş Yapan Kullanıcının İmzasını Al
    $loggedInUserId = $_SESSION['user_id'] ?? 0;
    if ($loggedInUserId > 0) {
        $stmtSig = $conn->prepare("SELECT email_signature FROM users WHERE id = :user_id");
        $stmtSig->bindParam(':user_id', $loggedInUserId, PDO::PARAM_INT);
        $stmtSig->execute();
        if ($stmtSig->rowCount() > 0) {
            $currentUserSignatureText = $stmtSig->fetchColumn();
        }
    }

    // 5. Üretim Siparişi ve Fatura Durumunu Kontrol Et (Sadece teklif kabul edilmişse)
    if ($quotation['status'] == 'accepted') {
        // Üretim Siparişi Kontrolü
        $stmtCheckOrder = $conn->prepare("SELECT id FROM production_orders WHERE quotation_id = :quotation_id LIMIT 1");
        $stmtCheckOrder->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
        $stmtCheckOrder->execute();
        if ($stmtCheckOrder->rowCount() > 0) {
            $productionOrderExists = true;
            $productionOrderId = $stmtCheckOrder->fetchColumn();
        }

        // Fatura Kontrolü
        $tableCheckStmt = $conn->query("SHOW TABLES LIKE 'invoices'");
        if ($tableCheckStmt->rowCount() > 0) {
            $stmtCheckInvoice = $conn->prepare("SELECT id FROM invoices WHERE quotation_id = :quotation_id LIMIT 1");
            $stmtCheckInvoice->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
            $stmtCheckInvoice->execute();
            if ($stmtCheckInvoice->rowCount() > 0) {
                $invoiceExists = true;
                $invoiceId = $stmtCheckInvoice->fetchColumn();
            }
        }
    }

} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    error_log("view_quotation.php DB Error (ID: $quotation_id): " . $e->getMessage());
    // Hata durumunda $quotation null kalacak ve sayfanın alt kısmı hata mesajı gösterecek.
}

// Durum metinleri ve sınıfları
$statusMap = [
    'draft' => ['text' => 'Taslak', 'class' => 'secondary'],
    'sent' => ['text' => 'Gönderildi', 'class' => 'primary'],
    'accepted' => ['text' => 'Kabul Edildi', 'class' => 'success'],
    'rejected' => ['text' => 'Reddedildi', 'class' => 'danger'],
    'expired' => ['text' => 'Süresi Doldu', 'class' => 'warning']
];

$statusText = 'Bilinmiyor';
$statusClass = 'secondary';
if ($quotation && isset($statusMap[$quotation['status']])) {
    $statusText = $statusMap[$quotation['status']]['text'];
    $statusClass = $statusMap[$quotation['status']]['class'];
} elseif ($quotation) {
    $statusText = $quotation['status'];
}

// Mailto linkini hazırlama
$mailtoLink = '#';
if ($customer && $quotation && $settings && $user) { // $user burada teklifi hazırlayan
    $mailTo = $customer['email'] ?? '';
    $mailSubject = "Teklif: " . $quotation['reference_no'];
    $mailBody = "Sayın " . htmlspecialchars($customer['contact_person'] ?: $customer['name']) . ",\n\n";
    $mailBody .= htmlspecialchars($settings['company_name']) . " olarak hazırladığımız " . htmlspecialchars($quotation['reference_no']) . " numaralı teklifimizi bilgilerinize sunarız.\n\n";
    $mailBody .= "*** Lütfen bu e-postaya teklifimizin PDF dosyasını eklemeyi unutmayınız. ***\n\n";
    $mailBody .= "İyi çalışmalar dileriz.\n\n";

    // Giriş yapan kullanıcının imzasını ekle
    if (!empty($currentUserSignatureText)) {
        $mailBody .= "--\n";
        $mailBody .= trim($currentUserSignatureText);
    } else {
        // İmza yoksa, giriş yapan kullanıcının adını ve şirket adını ekle
        $mailBody .= htmlspecialchars($_SESSION['user_fullname']) . "\n"; // GİRİŞ YAPAN KULLANICI
        $mailBody .= htmlspecialchars($settings['company_name']);
    }

    $mailtoLink = "mailto:" . rawurlencode($mailTo)
                . "?subject=" . rawurlencode($mailSubject)
                . "&body=" . rawurlencode($mailBody);
}


$pageTitle = 'Teklif: ' . ($quotation ? htmlspecialchars($quotation['reference_no']) : 'Bulunamadı');
$currentPage = 'quotations';
include 'includes/header.php';
include 'includes/navbar.php'; // Navbar bildirimleri gösterecek
include 'includes/sidebar.php';
?>
    <style>
        .info-card { background-color: #f8f9fa; border-radius: 5px; padding: 15px; margin-bottom: 15px; }
        .info-card h5 { margin-bottom: 10px; border-bottom: 1px solid #dee2e6; padding-bottom: 5px; color: #495057; }
        .status-badge { font-size: 0.9rem; padding: 0.25rem 0.5rem; vertical-align: middle; }
        .action-buttons { margin-bottom: 20px; /* Butonlar ve uyarı arası boşluk */ }
        .action-buttons .btn, .action-buttons .btn-group { margin-right: 5px; margin-bottom: 5px; }
        .table th, .table td { vertical-align: middle; }
        .info-card dt { font-weight: 600; }
        .info-card dd { word-break: break-word; }
        .alert .btn { vertical-align: middle; } /* Uyarı içindeki butonu hizala */
        
        /* Renk kutusu için stil */
        .color-box {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 1px solid #ddd;
            vertical-align: middle;
            border-radius: 3px;
        }
    </style>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Bildirimler -->
            <?php if ($successMessage = getMessage('success')): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $successMessage; ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage = getMessage('error')): ?>
                 <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $errorMessage; ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (!$quotation): // Teklif yüklenememişse ?>
                <div class="alert alert-danger">Teklif bilgileri yüklenemedi veya bulunamadı.</div>
                 <a href="quotations.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Tekliflere Dön</a>
            <?php else: // Teklif yüklendiyse ?>

                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                     <h1 class="h2 me-3 mb-2">
                        Teklif: <?php echo htmlspecialchars($quotation['reference_no']); ?>
                        <span class="badge bg-<?php echo $statusClass; ?> status-badge ms-2">
                            <?php echo $statusText; ?>
                        </span>
                    </h1>
                    <a href="quotations.php" class="btn btn-secondary mb-2">
                        <i class="bi bi-arrow-left"></i> Tekliflere Dön
                    </a>
                </div>

                <!-- İşlem Butonları -->
                <div class="action-buttons">
                    <?php if (in_array($quotation['status'], ['draft', 'sent'])): ?>
                         <a href="edit_quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-warning">
                            <i class="bi bi-pencil"></i> Düzenle
                        </a>
                    <?php else: ?>
                         <a href="#" class="btn btn-warning disabled" aria-disabled="true" title="Sadece taslak veya gönderilmiş teklifler düzenlenebilir.">
                            <i class="bi bi-pencil"></i> Düzenle
                        </a>
                    <?php endif; ?>
                    <a href="quotation_pdf.php?id=<?php echo $quotation_id; ?>" class="btn btn-success" target="_blank">
                        <i class="bi bi-file-pdf"></i> PDF İndir
                    </a>
                    <a href="quotation_word.php?id=<?php echo $quotation_id; ?>" class="btn btn-primary">
                        <i class="bi bi-file-word"></i> Word İndir
                    </a>
                    <a href="<?php echo $mailtoLink; ?>" class="btn btn-info" title="Varsayılan e-posta programınızı açar. PDF dosyasını manuel olarak eklemelisiniz.">
                        <i class="bi bi-envelope"></i> E-posta Taslağı Oluştur
                    </a>
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-arrow-down-up"></i> Durum Değiştir
                        </button>
                        <ul class="dropdown-menu">
                            <?php foreach ($statusMap as $statusCode => $statusInfo): ?>
                                <li>
                                    <a class="dropdown-item <?php echo $quotation['status'] == $statusCode ? 'active' : ''; ?>"
                                       href="update_quotation_status.php?id=<?php echo $quotation_id; ?>&status=<?php echo $statusCode; ?>&origin=view">
                                       <?php echo $statusInfo['text']; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Üretim Siparişi Butonu -->
                    <?php if ($quotation['status'] == 'accepted'): ?>
                        <?php if ($productionOrderExists): ?>
                            <a href="view_production_order.php?id=<?php echo $productionOrderId; ?>" class="btn btn-outline-success">
                                <i class="bi bi-check-circle"></i> Üretim Siparişi Görüntüle (#<?php echo $productionOrderId; ?>)
                            </a>
                        <?php else: ?>
                            <a href="create_production_order_endpoint.php?quotation_id=<?php echo $quotation_id; ?>" class="btn btn-success" onclick="return confirm('Bu teklif için üretim siparişi oluşturmak istediğinizden emin misiniz?');">
                                <i class="bi bi-gear"></i> Üretim Siparişi Oluştur
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <!-- Sil Butonu -->
                     <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $quotation['id']; ?>, '<?php echo htmlspecialchars(addslashes($quotation['reference_no'])); ?>')" class="btn btn-danger" title="Sil">
                        <i class="bi bi-trash"></i> Sil
                     </a>
                </div>

                <!-- Kabul Edildi Uyarısı ve Fatura Oluşturma Butonu -->
                <?php if ($quotation['status'] == 'accepted'): ?>
                    <div class="alert alert-success d-flex align-items-center mb-4" role="alert">
                        <div>
                            <i class="bi bi-check-circle-fill me-2"></i>
                            Bu teklif kabul edilmiştir.
                            <?php if (!$invoiceExists): ?>
                                Şimdi bir fatura oluşturabilirsiniz.
                            <?php else: ?>
                                 Bu teklife ait fatura zaten oluşturulmuş.
                            <?php endif; ?>
                        </div>
                        <?php if (!$invoiceExists): ?>
                            <a href="create_invoice.php?quotation_id=<?php echo $quotation_id; ?>" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-receipt"></i> Fatura Oluştur
                            </a>
                        <?php else: ?>
                            <a href="view_invoice.php?id=<?php echo $invoiceId; ?>" class="btn btn-sm btn-outline-primary">
                                 <i class="bi bi-eye"></i> Faturayı Görüntüle (#<?php echo $invoiceId; ?>)
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <!-- Uyarı Kutusu Sonu -->


                <div class="row">
                    <!-- Sol Sütun - Bilgiler -->
                    <div class="col-lg-4 col-md-5 order-md-1">
                        <!-- Teklif Bilgileri -->
                        <div class="info-card">
                            <h5>Teklif Bilgileri</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Teklif No:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($quotation['reference_no']); ?></dd>
                                <dt class="col-sm-5">Tarih:</dt><dd class="col-sm-7"><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></dd>
                                <dt class="col-sm-5">Geçerlilik:</dt><dd class="col-sm-7"><?php echo date('d.m.Y', strtotime($quotation['valid_until'])); ?></dd>
                                <dt class="col-sm-5">Hazırlayan:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($user['name']); ?></dd>
                                <dt class="col-sm-5">Oluşturma:</dt><dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($quotation['created_at'])); ?></dd>
                                <dt class="col-sm-5">Güncelleme:</dt><dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($quotation['updated_at'])); ?></dd>
                           </dl>
                        </div>
                        <!-- Müşteri Bilgileri -->
                        <div class="info-card">
                            <h5>Müşteri Bilgileri</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Firma Adı:</dt><dd class="col-sm-7"><a href="view_customer.php?id=<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></a></dd>
                                <dt class="col-sm-5">İlgili Kişi:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($customer['contact_person']); ?></dd>
                                <dt class="col-sm-5">E-posta:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($customer['email']); ?></dd>
                                <dt class="col-sm-5">Telefon:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($customer['phone']); ?></dd>
                                <dt class="col-sm-5">Adres:</dt><dd class="col-sm-7"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></dd>
                                <dt class="col-sm-5">Vergi D.:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($customer['tax_office']); ?></dd>
                                <dt class="col-sm-5">Vergi No:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($customer['tax_number']); ?></dd>
                             </dl>
                        </div>
                        <!-- Toplam Bilgileri -->
                         <div class="info-card">
                            <h5>Toplam Bilgileri</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-6">Ara Toplam:</dt><dd class="col-sm-6 text-end"><?php echo number_format($quotation['subtotal'], 2, ',', '.') . ' ₺'; ?></dd>
                                <dt class="col-sm-6">İndirim:</dt><dd class="col-sm-6 text-end"><?php echo number_format($quotation['discount_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                                <dt class="col-sm-6">KDV:</dt><dd class="col-sm-6 text-end"><?php echo number_format($quotation['tax_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                                <hr class="my-1">
                                <dt class="col-sm-6 fw-bold fs-5">Genel Toplam:</dt><dd class="col-sm-6 text-end fw-bold fs-5"><?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                            </dl>
                        </div>
                    </div>

                    <!-- Sağ Sütun - Teklif Kalemleri ve Notlar -->
                    <div class="col-lg-8 col-md-7 order-md-2">
                        <!-- Teklif Kalemleri -->
                        <div class="card mb-4">
                            <div class="card-header"><h5 class="card-title mb-0">Teklif Kalemleri</h5></div>
                            <div class="card-body p-0">
                                <?php if (count($items) > 0): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>#</th>
                                                    <th>Tür</th>
                                                    <th>Kod</th>
                                                    <th>Renk</th>
                                                    <th>Açıklama</th>
                                                    <th class="text-center">Miktar</th>
                                                    <th class="text-end">B.Fiyat</th>
                                                    <th class="text-center">İnd.%</th>
                                                    <th class="text-end">Tutar</th>
                                                    <th class="text-center">KDV%</th>
                                                    <th class="text-end">Toplam</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php $counter = 1; foreach ($items as $item):
                                                    $item_type = $item['item_type'] == 'product' ? 'Ürün' : 'Hizmet';
                                                    $unit_price = $item['unit_price']; $discount_percent = $item['discount_percent']; $quantity = $item['quantity'];
                                                    $item_discount = $unit_price * ($discount_percent / 100); $unit_price_after_discount = $unit_price - $item_discount;
                                                    $subtotal = $quantity * $unit_price_after_discount; $tax_rate = $item['tax_rate']; $tax_amount = $subtotal * ($tax_rate / 100); $total_with_tax = $subtotal + $tax_amount;
                                                ?>
                                                    <tr>
                                                        <td><?php echo $counter++; ?></td>
                                                        <td><?php echo $item_type; ?></td>
                                                        <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                        <td>
                                                            <?php if (!empty($item['color_hex'])): ?>
                                                                <span class="color-box" style="background-color: <?php echo htmlspecialchars($item['color_hex']); ?>;" 
                                                                      title="<?php echo htmlspecialchars($item['color_hex']); ?>"></span>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                        <td class="text-center"><?php echo $quantity; ?></td>
                                                        <td class="text-end"><?php echo number_format($unit_price, 2, ',', '.') . ' ₺'; ?></td>
                                                        <td class="text-center"><?php echo $discount_percent; ?>%</td>
                                                        <td class="text-end"><?php echo number_format($subtotal, 2, ',', '.') . ' ₺'; ?></td>
                                                        <td class="text-center"><?php echo $tax_rate; ?>%</td>
                                                        <td class="text-end fw-bold"><?php echo number_format($total_with_tax, 2, ',', '.') . ' ₺'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?> <p class="text-center p-3 m-0">Bu teklifte henüz kalem bulunmamaktadır.</p> <?php endif; ?>
                            </div>
                        </div>
                        <!-- Notlar ve Şartlar -->
                        <div class="row">
                             <?php if (!empty($quotation['notes'])): ?>
                                <div class="col-md-<?php echo !empty($quotation['terms_conditions']) ? '6' : '12'; ?>">
                                    <div class="card mb-4"><div class="card-header"><h5 class="card-title mb-0">Notlar</h5></div><div class="card-body"><?php echo nl2br(htmlspecialchars($quotation['notes'])); ?></div></div>
                                </div>
                            <?php endif; ?>
                             <?php if (!empty($quotation['terms_conditions'])): ?>
                                 <div class="col-md-<?php echo !empty($quotation['notes']) ? '6' : '12'; ?>">
                                    <div class="card mb-4"><div class="card-header"><h5 class="card-title mb-0">Şartlar ve Koşullar</h5></div><div class="card-body"><?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?></div></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php endif; // Teklif var mı kontrolü sonu ?>
        </div>
    </div>

     <!-- Delete Confirmation Modal -->
     <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="deleteModalLabel">Teklif Silme Onayı</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu teklifi silmek istediğinizden emin misiniz?</p>
                     <p class="text-danger small">Bu işlem geri alınamaz ve teklife ait tüm kalemler de silinir.</p>
                 </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a></div>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
     <script>
        // Silme onay fonksiyonu
        function confirmDelete(id, referenceNo) {
            // Kullanıcı arayüzünde referans no'yu güvenli bir şekilde göster
            const safeRefNo = String(referenceNo).replace(/</g, "<").replace(/>/g, ">");
            document.getElementById('deleteConfirmText').innerHTML = '<strong>"' + safeRefNo + '"</strong> teklifini silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'quotations.php?delete=' + id;
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>