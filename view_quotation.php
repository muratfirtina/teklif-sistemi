<?php
// view_quotation.php - Teklif görüntüleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';
// Eğer getCompanySettings ve getEmailSettings fonksiyonları
// includes/email.php içinde değilse ve başka bir yerde tanımlıysa
// o dosyayı da require_once ile ekleyin.
// Örnek: require_once 'includes/settings_helper.php';
// Eğer bu fonksiyonlar includes/email.php içindeyse ve
// o dosya sadece sunucu taraflı gönderme içinse,
// bu fonksiyonları bu sayfaya veya başka bir yardımcı dosyaya taşıyabilirsiniz.
require_once 'includes/email.php'; // getCompanySettings ve getEmailSettings için

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

// Teklif bilgilerini al
$quotation = null;
$items = [];
$customer = null;
$user = null;
$settings = [];
$emailSettings = [];
$isOwner = false; // Kullanıcının teklifin sahibi olup olmadığını kontrol etmek için

try {
    $stmt = $conn->prepare("
        SELECT q.*, c.name as customer_name, c.contact_person, c.email as customer_email,
               c.phone as customer_phone, c.address as customer_address, c.tax_office, c.tax_number,
               u.full_name as user_name, u.email as user_email
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }

    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Müşteri ve kullanıcı bilgilerini ayır
    $customer = [
        'id' => $quotation['customer_id'],
        'name' => $quotation['customer_name'],
        'contact_person' => $quotation['contact_person'],
        'email' => $quotation['customer_email'],
        'phone' => $quotation['customer_phone'],
        'address' => $quotation['customer_address'],
        'tax_office' => $quotation['tax_office'],
        'tax_number' => $quotation['tax_number']
    ];

    $user = [
        'id' => $quotation['user_id'],
        'name' => $quotation['user_name'],
        'email' => $quotation['user_email']
    ];

    // Kullanıcı admin mi veya teklifin sahibi mi kontrol et
    $isOwner = ($_SESSION['user_id'] == $quotation['user_id']);
    $isAdmin = isAdmin();

    // Teklif kalemlerini al
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
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ayarları al (Mailto linki için gerekli olabilir)
    // Bu fonksiyonların var olduğundan emin olun
    if (function_exists('getCompanySettings')) {
        $settings = getCompanySettings();
    } else {
        // Varsayılan değerler veya hata yönetimi
        $settings = ['company_name' => 'Şirketiniz'];
        // error_log("Uyarı: getCompanySettings fonksiyonu bulunamadı.");
    }
    if (function_exists('getEmailSettings')) {
        $emailSettings = getEmailSettings();
    } else {
        // Varsayılan değerler veya hata yönetimi
        $emailSettings = ['email_signature' => "Saygılarımızla,\n" . ($settings['company_name'] ?? 'Şirketiniz')];
        // error_log("Uyarı: getEmailSettings fonksiyonu bulunamadı.");
    }


} catch (PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    // header("Location: quotations.php"); // Hata durumunda yönlendirme yapılabilir
    // exit; // Veya hata detayını gösterip çıkılabilir: die("Veritabanı hatası: " . $e->getMessage());
}

// Durum metinleri ve sınıfları
$statusMap = [
    'draft' => ['text' => 'Taslak', 'class' => 'secondary'],
    'sent' => ['text' => 'Gönderildi', 'class' => 'primary'],
    'accepted' => ['text' => 'Kabul Edildi', 'class' => 'success'],
    'rejected' => ['text' => 'Reddedildi', 'class' => 'danger'],
    'expired' => ['text' => 'Süresi Doldu', 'class' => 'warning']
];

// Eğer $quotation null değilse durum bilgilerini al
$statusText = 'Bilinmiyor';
$statusClass = 'secondary';
if ($quotation && isset($statusMap[$quotation['status']])) {
    $statusText = $statusMap[$quotation['status']]['text'];
    $statusClass = $statusMap[$quotation['status']]['class'];
} elseif ($quotation) {
    $statusText = $quotation['status']; // Harita yoksa ham veriyi göster
}


// Mailto linkini hazırlama
$mailtoLink = '#'; // Varsayılan olarak boş link
$currentUserSignatureText = ''; // Varsayılan boş imza
try {
    $stmtSig = $conn->prepare("SELECT email_signature FROM users WHERE id = :user_id");
    // GİRİŞ YAPAN KULLANICI ID'SİNİ KULLAN: $_SESSION['user_id']
    $loggedInUserId = $_SESSION['user_id'] ?? 0;
    $stmtSig->bindParam(':user_id', $loggedInUserId);
    $stmtSig->execute();
    if ($stmtSig->rowCount() > 0) {
        $currentUserSignatureText = $stmtSig->fetchColumn();
    }
} catch (PDOException $e) {
    // Hata olursa logla ama işleme devam et
    error_log("Kullanıcı imzası alınamadı (User ID: $loggedInUserId): " . $e->getMessage());
}
// --- İmza Çekme Sonu ---


if ($customer && $quotation && $settings) { // emailSettings kaldırıldı, imza kullanıcıdan geliyor
    $mailTo = $customer['email'] ?? '';
    $mailSubject = "Teklif: " . $quotation['reference_no'];
    $mailBody = "Sayın " . htmlspecialchars($customer['contact_person'] ?: $customer['name']) . ",\n\n";
    $mailBody .= htmlspecialchars($settings['company_name']) . " olarak hazırladığımız " . htmlspecialchars($quotation['reference_no']) . " numaralı teklifimizi bilgilerinize sunarız.\n\n";
    //$mailBody .= "*** Lütfen bu e-postaya teklifimizin PDF dosyasını eklemeyi unutmayınız. ***\n\n"; // Hatırlatma
    $mailBody .= "İyi çalışmalar dileriz.\n\n";

    // --- GÜNCELLEME: Kullanıcının imzasını ekle ---
    if (!empty($currentUserSignatureText)) {
        $mailBody .= "--\n"; // Ayraç
        $mailBody .= trim($currentUserSignatureText) . "\n\n"; // Kullanıcının imza metni
    } else {
        // Eğer kullanıcının imzası yoksa, varsayılan veya hazırlayanın adını ekle
        $mailBody .= htmlspecialchars($user['name']) . "\n"; // Hazırlayan kullanıcı adı
        $mailBody .= htmlspecialchars($settings['company_name']) . "\n";
    }
    // --- İmza Ekleme Sonu ---


    $mailtoLink = "mailto:" . rawurlencode($mailTo)
        . "?subject=" . rawurlencode($mailSubject)
        . "&body=" . rawurlencode($mailBody);
}


$pageTitle = 'Teklif: ' . ($quotation ? htmlspecialchars($quotation['reference_no']) : 'Bulunamadı');
$currentPage = 'quotations';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    .info-card {
        /* view_invoice.php'den alındı */
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
        /* Başlıkla aynı hizada durması için */
    }

    .action-buttons {
        margin-bottom: 15px;
    }

    .action-buttons .btn,
    .action-buttons .btn-group {
        margin-right: 5px;
        margin-bottom: 5px;
        /* Küçük ekranlarda alt alta gelince boşluk */
    }
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

        <?php if (!$quotation): // Teklif yüklenememişse ana içeriği gösterme ?>
            <div class="alert alert-danger">Teklif bilgileri yüklenemedi. Lütfen teklif listesine dönün.</div>
            <a href="quotations.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Tekliflere Dön
            </a>
        <?php else: // Teklif yüklendiyse içeriği göster ?>

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
                <?php if ((in_array($quotation['status'], ['draft', 'sent'])) && ($isOwner || $isAdmin)): // Sadece taslak/gönderildi düzenlenebilir ve kullanıcı sahibi veya admin ise ?>
                    <a href="edit_quotation.php?id=<?php echo $quotation_id; ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i> Düzenle
                    </a>
                <?php else: ?>
                    <a href="#" class="btn btn-warning disabled" aria-disabled="true"
                        title="Sadece taslak veya gönderilmiş teklifler düzenlenebilir.">
                        <i class="bi bi-pencil"></i> Düzenle
                    </a>
                <?php endif; ?>
                <a href="quotation_pdf.php?id=<?php echo $quotation_id; ?>" class="btn btn-success" target="_blank">
                    <i class="bi bi-file-pdf"></i> PDF İndir
                </a>
                <a href="quotation_word.php?id=<?php echo $quotation_id; ?>" class="btn btn-primary">
                    <i class="bi bi-file-word"></i> Word İndir
                </a>
                <a href="<?php echo $mailtoLink; ?>" class="btn btn-info"
                    title="Varsayılan e-posta programınızı açar. PDF dosyasını manuel olarak eklemelisiniz.">
                    <i class="bi bi-envelope"></i> E-posta Taslağı Oluştur
                </a>

                <?php if ($isOwner || $isAdmin): // Kullanıcı sahibi veya admin ise durum değiştirme ve silme butonlarını göster ?>
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="bi bi-arrow-down-up"></i> Durum Değiştir
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo $quotation['status'] == 'draft' ? 'active' : ''; ?>"
                                    href="quotations.php?updateStatus=<?php echo $quotation_id; ?>&status=draft">Taslak</a></li>
                            <li><a class="dropdown-item <?php echo $quotation['status'] == 'sent' ? 'active' : ''; ?>"
                                    href="quotations.php?updateStatus=<?php echo $quotation_id; ?>&status=sent">Gönderildi</a>
                            </li>
                            <li><a class="dropdown-item <?php echo $quotation['status'] == 'accepted' ? 'active' : ''; ?>"
                                    href="quotations.php?updateStatus=<?php echo $quotation_id; ?>&status=accepted">Kabul
                                    Edildi</a></li>
                            <li><a class="dropdown-item <?php echo $quotation['status'] == 'rejected' ? 'active' : ''; ?>"
                                    href="quotations.php?updateStatus=<?php echo $quotation_id; ?>&status=rejected">Reddedildi</a>
                            </li>
                            <li><a class="dropdown-item <?php echo $quotation['status'] == 'expired' ? 'active' : ''; ?>"
                                    href="quotations.php?updateStatus=<?php echo $quotation_id; ?>&status=expired">Süresi
                                    Doldu</a></li>
                        </ul>
                    </div>
                    <a href="javascript:void(0);"
                        onclick="confirmDelete(<?php echo $quotation['id']; ?>, '<?php echo htmlspecialchars(addslashes($quotation['reference_no'])); ?>')"
                        class="btn btn-danger" title="Sil">
                        <i class="bi bi-trash"></i> Sil
                    </a>
                <?php endif; ?>
            </div>
            <?php

            // First, check if the quotation has already been converted to an invoice
            $invoiceExists = false;
            try {
                $stmtCheckInvoice = $conn->prepare("SELECT id FROM invoices WHERE quotation_id = :quotation_id");
                $stmtCheckInvoice->bindParam(':quotation_id', $quotation_id);
                $stmtCheckInvoice->execute();
                $invoiceExists = ($stmtCheckInvoice->rowCount() > 0);

                // If there's an invoice, get its ID for linking
                $invoiceId = null;
                if ($invoiceExists) {
                    $invoiceId = $stmtCheckInvoice->fetchColumn();
                }
            } catch (PDOException $e) {
                // Silent error, just assume invoice doesn't exist
                $invoiceExists = false;
            }

            // Show fatura oluştur (create invoice) button or notification when the quotation is accepted
            if ($quotation['status'] == 'accepted'): ?>
                <div
                    class="alert <?php echo $invoiceExists ? 'alert-success' : 'alert-warning'; ?> d-flex align-items-center mb-4">
                    <i
                        class="bi <?php echo $invoiceExists ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill'; ?> me-2 fs-4"></i>
                    <div>
                        <?php if ($invoiceExists): ?>
                            <strong>Bu teklif için fatura oluşturulmuştur.</strong>
                            <a href="view_invoice.php?id=<?php echo $invoiceId; ?>" class="alert-link">Faturayı görüntülemek için
                                tıklayın.</a>
                        <?php else: ?>
                            <strong>Bu teklif kabul edilmiştir.</strong> Şimdi bir fatura oluşturabilirsiniz.
                            <a href="create_invoice.php?quotation_id=<?php echo $quotation_id; ?>"
                                class="btn btn-primary btn-sm ms-3">
                                <i class="bi bi-receipt"></i> Fatura Oluştur
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Sol Sütun - Bilgiler -->
                <div class="col-lg-4 col-md-5">
                    <!-- Teklif Bilgileri -->
                    <div class="info-card">
                        <h5>Teklif Bilgileri</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Teklif No:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($quotation['reference_no']); ?></dd>
                            <dt class="col-sm-5">Teklif Tarihi:</dt>
                            <dd class="col-sm-7"><?php echo date('d.m.Y', strtotime($quotation['date'])); ?></dd>
                            <dt class="col-sm-5">Geçerlilik Trh:</dt>
                            <dd class="col-sm-7"><?php echo date('d.m.Y', strtotime($quotation['valid_until'])); ?></dd>
                            <dt class="col-sm-5">Hazırlayan:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($user['name']); ?></dd>
                            <dt class="col-sm-5">Oluşturulma:</dt>
                            <dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($quotation['created_at'])); ?></dd>
                            <dt class="col-sm-5">Son Güncelleme:</dt>
                            <dd class="col-sm-7"><?php echo date('d.m.Y H:i', strtotime($quotation['updated_at'])); ?></dd>
                        </dl>
                    </div>

                    <!-- Müşteri Bilgileri -->
                    <div class="info-card">
                        <h5>Müşteri Bilgileri</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Firma Adı:</dt>
                            <dd class="col-sm-7"><a
                                    href="view_customer.php?id=<?php echo $customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></a>
                            </dd>
                            <dt class="col-sm-5">İlgili Kişi:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($customer['contact_person']); ?></dd>
                            <dt class="col-sm-5">E-posta:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($customer['email']); ?></dd>
                            <dt class="col-sm-5">Telefon:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($customer['phone']); ?></dd>
                            <dt class="col-sm-5">Adres:</dt>
                            <dd class="col-sm-7"><?php echo nl2br(htmlspecialchars($customer['address'])); ?></dd>
                            <dt class="col-sm-5">Vergi Dairesi:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($customer['tax_office']); ?></dd>
                            <dt class="col-sm-5">Vergi No:</dt>
                            <dd class="col-sm-7"><?php echo htmlspecialchars($customer['tax_number']); ?></dd>
                        </dl>
                    </div>

                    <!-- Toplam Bilgileri -->
                    <div class="info-card">
                        <h5>Toplam Bilgileri</h5>
                        <dl class="row mb-0">
                            <dt class="col-sm-6">Ara Toplam:</dt>
                            <dd class="col-sm-6 text-end">
                                <?php echo number_format($quotation['subtotal'], 2, ',', '.') . ' ₺'; ?></dd>
                            <dt class="col-sm-6">İndirim:</dt>
                            <dd class="col-sm-6 text-end">
                                <?php echo number_format($quotation['discount_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                            <dt class="col-sm-6">KDV:</dt>
                            <dd class="col-sm-6 text-end">
                                <?php echo number_format($quotation['tax_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                            <hr class="my-1">
                            <dt class="col-sm-6 fw-bold fs-5">Genel Toplam:</dt>
                            <dd class="col-sm-6 text-end fw-bold fs-5">
                                <?php echo number_format($quotation['total_amount'], 2, ',', '.') . ' ₺'; ?></dd>
                        </dl>
                    </div>
                </div>

                <!-- Sağ Sütun - Teklif Kalemleri ve Notlar -->
                <div class="col-lg-8 col-md-7">
                    <!-- Teklif Kalemleri -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Kalemleri</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($items) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th scope="col">#</th>
                                                <th scope="col">Tür</th>
                                                <th scope="col">Kod</th>
                                                <th scope="col">Açıklama</th>
                                                <th scope="col" class="text-center">Miktar</th>
                                                <th scope="col" class="text-end">Birim Fiyat</th>
                                                <th scope="col" class="text-center">İnd.%</th>
                                                <th scope="col" class="text-end">Tutar</th>
                                                <th scope="col" class="text-center">KDV%</th>
                                                <th scope="col" class="text-end">Toplam</th>
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
                                                $item_discount = $unit_price * ($discount_percent / 100);
                                                $unit_price_after_discount = $unit_price - $item_discount;
                                                $subtotal = $quantity * $unit_price_after_discount;
                                                $tax_rate = $item['tax_rate'];
                                                $tax_amount = $subtotal * ($tax_rate / 100);
                                                $total_with_tax = $subtotal + $tax_amount;
                                                ?>
                                                <tr>
                                                    <td><?php echo $counter++; ?></td>
                                                    <td><?php echo $item_type; ?></td>
                                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                                                    <td class="text-center"><?php echo $quantity; ?></td>
                                                    <td class="text-end">
                                                        <?php echo number_format($unit_price, 2, ',', '.') . ' ₺'; ?></td>
                                                    <td class="text-center"><?php echo $discount_percent; ?>%</td>
                                                    <td class="text-end"><?php echo number_format($subtotal, 2, ',', '.') . ' ₺'; ?>
                                                    </td>
                                                    <td class="text-center"><?php echo $tax_rate; ?>%</td>
                                                    <td class="text-end fw-bold">
                                                        <?php echo number_format($total_with_tax, 2, ',', '.') . ' ₺'; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-center p-3">Bu teklifte henüz kalem bulunmamaktadır.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notlar ve Şartlar -->
                    <?php if (!empty($quotation['notes']) || !empty($quotation['terms_conditions'])): ?>
                        <div class="row">
                            <?php if (!empty($quotation['notes'])): ?>
                                <div class="col-md-<?php echo !empty($quotation['terms_conditions']) ? '6' : '12'; ?>">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="card-title mb-0">Notlar</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php echo nl2br(htmlspecialchars($quotation['notes'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($quotation['terms_conditions'])): ?>
                                <div class="col-md-<?php echo !empty($quotation['notes']) ? '6' : '12'; ?>">
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h5 class="card-title mb-0">Şartlar ve Koşullar</h5>
                                        </div>
                                        <div class="card-body">
                                            <?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; // Teklif yüklenemediyse içeriği gösterme sonu ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">Teklif Silme Onayı</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="deleteConfirmText">Bu teklifi silmek istediğinizden emin misiniz?</p>
                <p class="text-danger small">Bu işlem geri alınamaz ve teklife ait tüm kalemler de silinir.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; // Varsa footer'ı dahil et ?>
<script>
    // Silme onay fonksiyonu
    function confirmDelete(id, referenceNo) {
        document.getElementById('deleteConfirmText').innerHTML = '<strong>"' + referenceNo + '"</strong> teklifini silmek istediğinizden emin misiniz?'; // İçeriği güvenli şekilde ayarla
        document.getElementById('confirmDeleteBtn').href = 'quotations.php?delete=' + id; // Silme işlemi hala quotations.php'de
        var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        deleteModal.show();
    }
</script>