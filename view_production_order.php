<?php
// view_production_order.php - Üretim siparişi görüntüleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin(); // requireProduction() kaldırıldı, requireLogin() kaldı

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz sipariş ID\'si.');
    header("Location: production_orders.php"); // Üretim listesine yönlendir
    exit;
}

$order_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Üretim siparişi detayları
$order = null;
$items = [];
// $quotation = null; // $order içinde zaten birleşiyor
// $customer = null; // $order içinde zaten birleşiyor

try {
    // Üretim siparişi ve ilişkili teklif/müşteri/kullanıcı bilgilerini al
    // Sorguya q.user_id eklendi (quotation_owner_id olarak)
    $stmt = $conn->prepare("
        SELECT po.*, q.reference_no, q.date, q.notes as quotation_notes, q.terms_conditions,
               q.user_id as quotation_owner_id, -- Teklifi oluşturan kullanıcının ID'si
               c.id as customer_id, c.name as customer_name, c.contact_person, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address, c.tax_office, c.tax_number,
               u.full_name as user_name, u.email as user_email -- Bu u.id teklifi hazırlayanın ID'si
        FROM production_orders po
        JOIN quotations q ON po.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id -- Bu JOIN teklifi hazırlayan kullanıcıyı getirir
        WHERE po.id = :id
    ");
    $stmt->bindParam(':id', $order_id);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Üretim siparişi bulunamadı.');
        header("Location: production_orders.php"); // Üretim listesine yönlendir
        exit;
    }

    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- YETKİ KONTROLÜ BAŞLANGICI ---
    $isAdmin = isAdmin(); // session.php'den
    $isProductionUser = isProduction(); // session.php'den
    // $order['quotation_owner_id'] teklifi oluşturan kullanıcının ID'si
    $isOwner = ($_SESSION['user_id'] == $order['quotation_owner_id']);

    if (!$isAdmin && !$isProductionUser && !$isOwner) {
        // Eğer admin değil, üretim değil VE teklifin sahibi de değilse erişimi engelle
        setMessage('error', 'Bu üretim siparişini görüntüleme yetkiniz bulunmamaktadır.');
        // Kullanıcıyı kendi teklif listesine veya ana sayfaya yönlendirebiliriz
        header("Location: quotations.php"); // Veya index.php
        exit;
    }
    // --- YETKİ KONTROLÜ SONU ---


    // Üretim kalemleri
    $stmtItems = $conn->prepare("
        SELECT poi.*,
            CASE WHEN qi.item_type = 'product' THEN p.name ELSE s.name END as item_name,
            CASE WHEN qi.item_type = 'product' THEN p.code ELSE s.code END as item_code
        FROM production_order_items poi
        JOIN quotation_items qi ON poi.item_id = qi.id -- Şemaya göre bu JOIN doğru varsayılıyor
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE poi.production_order_id = :order_id
        ORDER BY poi.id ASC
    ");
    $stmtItems->bindParam(':order_id', $order_id);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Toplam ve tamamlanan miktarları hesapla
    $totalQuantity = 0;
    $completedQuantity = 0;
    foreach ($items as $item) {
        $totalQuantity += $item['quantity'];
        $completedQuantity += $item['completed_quantity'];
    }

    // Tamamlanma yüzdesini hesapla
    $completionPercentage = 0;
    if ($totalQuantity > 0) {
        $completionPercentage = round(($completedQuantity / $totalQuantity) * 100);
    }

} catch (PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    // Hata durumunda $order null kalacak, aşağıdaki kontrol bunu yakalayacak
}

// Durum metinleri ve sınıfları
$statusMap = [
    'pending' => ['text' => 'Bekliyor', 'class' => 'secondary'],
    'in_progress' => ['text' => 'Devam Ediyor', 'class' => 'primary'],
    'completed' => ['text' => 'Tamamlandı', 'class' => 'success'],
    'cancelled' => ['text' => 'İptal Edildi', 'class' => 'danger']
];

// Durum bilgilerini al
$statusText = 'Bilinmiyor';
$statusClass = 'secondary';
if ($order && isset($statusMap[$order['status']])) {
    $statusText = $statusMap[$order['status']]['text'];
    $statusClass = $statusMap[$order['status']]['class'];
}

$pageTitle = 'Üretim Siparişi: #' . $order_id;
$currentPage = 'production'; // Veya ilgili sayfa
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
        padding: 0.25rem 0.5rem;
        vertical-align: middle;
    }
    .action-buttons {
        margin-bottom: 15px;
    }
    .action-buttons .btn,
    .action-buttons .btn-group {
        margin-right: 5px;
        margin-bottom: 5px;
    }
    .item-update-form .form-control {
        max-width: 100px;
    }
    .progress-bar-container {
        margin: 20px 0;
    }
    .progress-bar-large {
        height: 30px;
        font-size: 16px;
        font-weight: bold;
    }
    /* Bilgi Kartı etiket ve değer hizalaması */
    .info-card dt { font-weight: 600; }
    .info-card dd { word-break: break-word; }
    .table th, .table td { vertical-align: middle; }
</style>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <!-- Bildirimler -->
        <?php if ($successMessage = getMessage('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $successMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage = getMessage('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $errorMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$order): // Sipariş yüklenememişse ?>
            <div class="alert alert-danger">Üretim siparişi bilgileri yüklenemedi veya erişim izniniz yok.</div>
            <a href="production_orders.php" class="btn btn-secondary"> <!-- Üretim listesine yönlendir -->
                <i class="bi bi-arrow-left"></i> Siparişlere Dön
            </a>
        <?php else: // Sipariş yüklendiyse ?>

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
                 <h1 class="h2 me-3 mb-2">
                    Üretim Siparişi: #<?php echo $order_id; ?>
                    <span class="badge bg-<?php echo $statusClass; ?> status-badge ms-2">
                        <?php echo $statusText; ?>
                    </span>
                </h1>
                <!-- Geri Dönüş Linki - Admin/Production ise listeye, sahibi ise teklife döner -->
                <?php if ($isAdmin || $isProductionUser): ?>
                    <a href="production_orders.php" class="btn btn-secondary mb-2">
                        <i class="bi bi-arrow-left"></i> Siparişlere Dön
                    </a>
                <?php else: // Sahibi ise ?>
                     <a href="view_quotation.php?id=<?php echo $order['quotation_id']; ?>" class="btn btn-secondary mb-2">
                        <i class="bi bi-arrow-left"></i> Teklife Dön
                    </a>
                <?php endif; ?>
            </div>

            <!-- İşlem Butonları - Sadece Admin veya Production görebilir -->
            <?php if ($isAdmin || $isProductionUser): ?>
                 <div class="action-buttons">
                    <?php if ($order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                        <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                            <i class="bi bi-arrow-clockwise"></i> Durumu Güncelle
                        </a>
                         <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#updateItemsModal">
                            <i class="bi bi-check2-square"></i> Üretimi Güncelle
                         </button>
                         <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#updateNotesModal">
                            <i class="bi bi-pencil"></i> Not Ekle/Düzenle
                        </button>
                    <?php endif; ?>
                     <a href="view_quotation.php?id=<?php echo $order['quotation_id']; ?>" class="btn btn-outline-secondary" target="_blank">
                        <i class="bi bi-file-earmark-text"></i> Teklifi Görüntüle
                    </a>
                    <?php if ($order['status'] == 'completed'): ?>
                        <span class="btn btn-success disabled"><i class="bi bi-check-circle"></i> Tamamlandı</span>
                    <?php endif; ?>
                    <?php if ($order['status'] == 'cancelled'): ?>
                        <span class="btn btn-danger disabled"><i class="bi bi-x-circle"></i> İptal Edildi</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <!-- Tamamlanma Yüzdesi -->
            <div class="progress-bar-container">
                 <h4>Tamamlanma Durumu: <?php echo $completionPercentage; ?>%</h4>
                 <div class="progress progress-bar-large">
                     <div class="progress-bar <?php echo ($completionPercentage < 50) ? 'bg-warning' : (($completionPercentage < 100) ? 'bg-info' : 'bg-success'); ?>"
                          role="progressbar" style="width: <?php echo $completionPercentage; ?>%;"
                          aria-valuenow="<?php echo $completionPercentage; ?>" aria-valuemin="0" aria-valuemax="100">
                         <?php echo $completionPercentage; ?>%
                     </div>
                 </div>
             </div>


            <div class="row">
                <!-- Sol Sütun - Bilgiler -->
                <div class="col-lg-4 col-md-5 order-md-1">
                    <!-- Üretim Bilgileri -->
                    <div class="info-card">
                        <h5>Üretim Sipariş Bilgileri</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Sipariş No:</dt><dd class="col-sm-7">#<?php echo $order_id; ?></dd>
                            <dt class="col-sm-5">Teklif No:</dt><dd class="col-sm-7"><a href="view_quotation.php?id=<?php echo $order['quotation_id']; ?>"><?php echo htmlspecialchars($order['reference_no']); ?></a></dd>
                            <dt class="col-sm-5">Teslim Tarihi:</dt>
                            <dd class="col-sm-7">
                                <?php echo date('d.m.Y', strtotime($order['delivery_deadline'])); ?>
                                <?php if ($order['delivery_deadline'] < date('Y-m-d') && !in_array($order['status'], ['completed', 'cancelled'])): ?>
                                    <span class="badge bg-danger">Gecikmiş</span>
                                <?php elseif (strtotime($order['delivery_deadline']) <= strtotime('+3 days') && !in_array($order['status'], ['completed', 'cancelled'])): ?>
                                    <span class="badge bg-warning">Yakın</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-5">Durum:</dt><dd class="col-sm-7"><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span></dd>
                            <dt class="col-sm-5">Oluşturulma:</dt><dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></dd>
                            <dt class="col-sm-5">Güncelleme:</dt><dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($order['updated_at'])); ?></dd>
                        </dl>
                    </div>
                     <!-- Müşteri Bilgileri -->
                    <div class="info-card">
                        <h5>Müşteri Bilgileri</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Firma Adı:</dt><dd class="col-sm-7"><a href="view_customer.php?id=<?php echo $order['customer_id']; ?>"><?php echo htmlspecialchars($order['customer_name']); ?></a></dd>
                            <dt class="col-sm-5">İlgili Kişi:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($order['contact_person']); ?></dd>
                            <dt class="col-sm-5">E-posta:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($order['customer_email']); ?></dd>
                            <dt class="col-sm-5">Telefon:</dt><dd class="col-sm-7"><?php echo htmlspecialchars($order['customer_phone']); ?></dd>
                        </dl>
                    </div>

                     <!-- Üretim Notları (Admin/Production görür) -->
                     <?php if ($isAdmin || $isProductionUser): ?>
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Üretim Notları</h5>
                             <?php if ($order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                                 <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateNotesModal"><i class="bi bi-pencil"></i></button>
                             <?php endif; ?>
                             </div>
                            <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                                <?php if (!empty($order['production_notes'])): ?>
                                    <pre style="white-space: pre-wrap;"><?php echo htmlspecialchars($order['production_notes']); ?></pre>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Henüz üretim notu girilmemiş.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                     <?php endif; ?>
                </div>

                <!-- Sağ Sütun - Üretim Kalemleri -->
                <div class="col-lg-8 col-md-7 order-md-2">
                    <div class="card mb-4">
                         <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Üretim Kalemleri</h5>
                             <?php if (($isAdmin || $isProductionUser) && $order['status'] != 'completed' && $order['status'] != 'cancelled'): ?>
                                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#updateItemsModal">
                                    <i class="bi bi-check2-square"></i> Üretimi Güncelle
                                </button>
                            <?php endif; ?>
                         </div>
                         <div class="card-body p-0">
                            <?php if (count($items) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover mb-0">
                                        <thead class="table-light"><tr><th>#</th><th>Tür</th><th>Kod</th><th>Ürün/Hizmet</th><th class="text-center">Miktar</th><th class="text-center">Tamamlanan</th><th class="text-center" style="min-width: 100px;">İlerleme</th><th class="text-center">Durum</th></tr></thead>
                                        <tbody>
                                            <?php $counter = 1; foreach ($items as $item):
                                                $itemCompletion = 0; if ($item['quantity'] > 0) $itemCompletion = round(($item['completed_quantity'] / $item['quantity']) * 100);
                                                $itemStatusClass = 'secondary'; $itemStatusText = 'Bekliyor';
                                                switch ($item['status']) { case 'in_progress': $itemStatusClass = 'primary'; $itemStatusText = 'Devam'; break; case 'completed': $itemStatusClass = 'success'; $itemStatusText = 'Tamam'; break; }
                                            ?>
                                                <tr>
                                                    <td><?php echo $counter++; ?></td>
                                                    <td><?php echo $item['item_type'] == 'product' ? 'Ürün' : 'Hizmet'; ?></td>
                                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                                    <td class="text-center"><?php echo number_format($item['quantity'], 2, ',', '.'); // Ondalık gösterim ?></td>
                                                    <td class="text-center"><?php echo number_format($item['completed_quantity'], 2, ',', '.'); // Ondalık gösterim ?></td>
                                                    <td>
                                                        <div class="progress" style="height: 18px;" title="<?php echo $itemCompletion; ?>%">
                                                            <div class="progress-bar <?php echo ($itemCompletion < 100) ? 'bg-warning' : 'bg-success'; ?>" role="progressbar" style="width: <?php echo $itemCompletion; ?>%; font-size: 0.75rem;" aria-valuenow="<?php echo $itemCompletion; ?>" aria-valuemin="0" aria-valuemax="100">
                                                                 <?php echo $itemCompletion; ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="text-center"><span class="badge bg-<?php echo $itemStatusClass; ?>"><?php echo $itemStatusText; ?></span></td>
                                                </tr>
                                                 <?php if (!empty($item['notes'])): // Kalem notunu göster (sadece admin/prod görebilir) ?>
                                                     <?php if ($isAdmin || $isProductionUser): ?>
                                                         <tr class="table-info"><td colspan="8"><small><strong>Not:</strong> <?php echo htmlspecialchars($item['notes']); ?></small></td></tr>
                                                     <?php endif; ?>
                                                 <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?> <p class="text-center p-3 m-0">Bu siparişte henüz kalem bulunmamaktadır.</p> <?php endif; ?>
                        </div>
                    </div>

                     <!-- Teklif Şartları ve Notlar (Kullanıcı da görebilir) -->
                     <div class="row">
                         <?php if (!empty($order['quotation_notes'])): ?>
                             <div class="col-md-<?php echo !empty($order['terms_conditions']) ? '6' : '12'; ?>">
                                 <div class="card mb-4"><div class="card-header"><h5 class="card-title mb-0">Teklif Notları</h5></div><div class="card-body"><pre style="white-space: pre-wrap;"><?php echo htmlspecialchars($order['quotation_notes']); ?></pre></div></div>
                             </div>
                         <?php endif; ?>
                         <?php if (!empty($order['terms_conditions'])): ?>
                              <div class="col-md-<?php echo !empty($order['quotation_notes']) ? '6' : '12'; ?>">
                                 <div class="card mb-4"><div class="card-header"><h5 class="card-title mb-0">Teklif Şartları</h5></div><div class="card-body"><pre style="white-space: pre-wrap;"><?php echo htmlspecialchars($order['terms_conditions']); ?></pre></div></div>
                             </div>
                         <?php endif; ?>
                     </div>

                 </div>
            </div>

        <?php endif; // Sipariş var mı kontrolü sonu ?>
    </div>
</div>

<!-- Modallar (Sadece Admin ve Production için gerekli ve sipariş tamamlanmadıysa/iptal edilmediyse) -->
<?php if (($isAdmin || $isProductionUser) && $order && !in_array($order['status'], ['completed', 'cancelled'])): ?>
    <!-- Durum Güncelleme Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="updateStatusModalLabel">Üretim Durumunu Güncelle</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="update_production_status.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <div class="mb-3">
                            <label for="modal_status" class="form-label">Durum</label>
                            <select class="form-select" id="modal_status" name="status" required>
                                <option value="pending" <?php echo $order['status'] == 'pending' ? 'selected' : ''; ?>>Bekliyor</option>
                                <option value="in_progress" <?php echo $order['status'] == 'in_progress' ? 'selected' : ''; ?>>Devam Ediyor</option>
                                <option value="completed" <?php echo $order['status'] == 'completed' ? 'selected' : ''; ?>>Tamamlandı</option>
                                <option value="cancelled" <?php echo $order['status'] == 'cancelled' ? 'selected' : ''; ?>>İptal Edildi</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="modal_status_notes" class="form-label">Durum Notu</label>
                            <textarea class="form-control" id="modal_status_notes" name="status_notes" rows="3" placeholder="Durum değişikliği ile ilgili not ekleyin"></textarea>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="modal_notify_owner_status" name="notify_quotation_owner" checked>
                            <label class="form-check-label" for="modal_notify_owner_status">Teklif sahibini bilgilendir</label>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Notlar Güncelleme Modal -->
    <div class="modal fade" id="updateNotesModal" tabindex="-1" aria-labelledby="updateNotesModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="updateNotesModalLabel">Üretim Notlarını Güncelle</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="update_production_notes.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <div class="mb-3">
                            <label for="modal_production_notes" class="form-label">Üretim Notları</label>
                            <textarea class="form-control" id="modal_production_notes" name="production_notes" rows="6"><?php echo htmlspecialchars($order['production_notes']); ?></textarea>
                        </div>
                         <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="modal_notify_notes" name="notify_notes" checked>
                            <label class="form-check-label" for="modal_notify_notes">Teklif sahibini bilgilendir</label>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Üretim Kalemleri Güncelleme Modal -->
    <div class="modal fade" id="updateItemsModal" tabindex="-1" aria-labelledby="updateItemsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl"> <!-- Daha geniş modal -->
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="updateItemsModalLabel">Üretim Kalemlerini Güncelle</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <form action="update_production_items.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="order_id" value="<?php echo $order_id; ?>">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead class="table-light"><tr><th>Ürün/Hizmet</th><th class="text-center">Toplam</th><th class="text-center" style="width: 100px;">Tamamlanan</th><th style="width: 150px;">Durum</th><th>Not</th></tr></thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['item_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($item['item_code']); ?></small></td>
                                        <td class="text-center align-middle"><?php echo number_format($item['quantity'], 2, ',', '.'); ?></td>
                                        <td>
                                            <input type="hidden" name="item_id[]" value="<?php echo $item['id']; // production_order_items.id ?>">
                                            <input type="text" class="form-control form-control-sm text-center numeric-input" name="completed_quantity[]" value="<?php echo number_format($item['completed_quantity'], 2, ',', '.'); ?>" required>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm" name="item_status[]">
                                                <option value="pending" <?php echo $item['status'] == 'pending' ? 'selected' : ''; ?>>Bekliyor</option>
                                                <option value="in_progress" <?php echo $item['status'] == 'in_progress' ? 'selected' : ''; ?>>Devam</option>
                                                <option value="completed" <?php echo $item['status'] == 'completed' ? 'selected' : ''; ?>>Tamam</option>
                                            </select>
                                        </td>
                                        <td><input type="text" class="form-control form-control-sm" name="item_notes[]" value="<?php echo htmlspecialchars($item['notes'] ?? ''); ?>" placeholder="Kalem notu"></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mb-3 mt-3">
                            <label for="modal_update_note" class="form-label">Genel Güncelleme Notu</label>
                            <textarea class="form-control" id="modal_update_note" name="update_note" rows="2" placeholder="Bu güncelleme ile ilgili genel not ekleyin"></textarea>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="modal_notify_items" name="notify_items_update" checked>
                            <label class="form-check-label" for="modal_notify_items">Teklif sahibini bilgilendir</label>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button><button type="submit" class="btn btn-primary">Kaydet</button></div>
                </form>
            </div>
        </div>
    </div>
<?php endif; // Modallar için yetki kontrolü sonu ?>


<?php include 'includes/footer.php'; ?>
<!-- Gerekirse modal içindeki numeric-input için JS eklenebilir -->
<script>
    // Eğer modal içinde de virgül/nokta dönüşümü isteniyorsa:
    document.querySelectorAll('#updateItemsModal .numeric-input').forEach(input => {
        // Odaklanınca noktaya çevir
        input.addEventListener('focus', (e) => {
            e.target.value = String(e.target.value).replace(',', '.');
        });
         // Odak kaybedince virgülle formatla
         input.addEventListener('blur', (e) => {
             const num = parseFloat(String(e.target.value).replace(/[^0-9.]/g, '') || 0);
             e.target.value = num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
         });
    });
</script>