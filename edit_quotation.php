<?php
// edit_quotation.php - Teklif düzenleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz teklif ID\'si.');
    header("Location: quotations.php");
    exit;
}
$id = intval($_GET['id']);

$conn = getDbConnection();

// Teklif sahibi mi kontrol et
try {
    $stmtCheckOwner = $conn->prepare("SELECT user_id, status FROM quotations WHERE id = :id");
    $stmtCheckOwner->bindParam(':id', $id);
    $stmtCheckOwner->execute();

    if ($stmtCheckOwner->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    $quotationCheck = $stmtCheckOwner->fetch(PDO::FETCH_ASSOC);

    if ($quotationCheck['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        setMessage('error', 'Bu teklifi düzenleme yetkiniz bulunmamaktadır.');
        header("Location: view_quotation.php?id=" . $id); // Görüntüleme sayfasına yönlendir
        exit;
    }
    // İsteğe bağlı: Sadece belirli durumlardaki teklifler düzenlenebilir
    // if (!in_array($quotationCheck['status'], ['draft', 'sent'])) {
    //    setMessage('error', 'Sadece taslak veya gönderilmiş durumdaki teklifler düzenlenebilir.');
    //    header("Location: view_quotation.php?id=" . $id);
    //    exit;
    // }

} catch (PDOException $e) {
    setMessage('error', 'Yetki kontrolünde hata: ' . $e->getMessage());
    header("Location: quotations.php");
    exit;
}


// Teklif ve kalemlerini çek
$quotation = null;
$items = [];
$quotation_terms = null; // Teklif şartlarını da çekelim

try {
    $stmtQuotation = $conn->prepare("SELECT * FROM quotations WHERE id = :id");
    $stmtQuotation->bindParam(':id', $id);
    $stmtQuotation->execute();
    if ($stmtQuotation->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    $quotation = $stmtQuotation->fetch(PDO::FETCH_ASSOC);

    // Teklif kalemlerini ve ürün renklerini al
    $stmtItems = $conn->prepare("
        SELECT qi.*, p.color_hex AS product_color_hex, p.name AS product_name
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        WHERE qi.quotation_id = :id ORDER BY qi.id ASC
    ");
    $stmtItems->bindParam(':id', $id);
    $stmtItems->execute();
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Teklif şartlarını al (eğer varsa)
    $stmtQuotationTerms = $conn->prepare("SELECT * FROM quotation_terms WHERE quotation_id = :id");
    $stmtQuotationTerms->bindParam(':id', $id);
    $stmtQuotationTerms->execute();
    if ($stmtQuotationTerms->rowCount() > 0) {
        $quotation_terms = $stmtQuotationTerms->fetch(PDO::FETCH_ASSOC);
    }


} catch (PDOException $e) {
    setMessage('error', 'Teklif bilgileri alınırken hata oluştu: ' . $e->getMessage());
    header("Location: quotations.php");
    exit;
}

// Müşterileri, Ürünleri (renkleriyle), Hizmetleri getir
$customers = [];
$products = []; // productsData olarak JS'e gidecek
$services = []; // servicesData olarak JS'e gidecek
try {
    $customers = $conn->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $products = $conn->query("SELECT id, code, name, price, tax_rate, stock_quantity, color_hex FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // $services = $conn->query("SELECT id, code, name, price, tax_rate FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Dropdown verileri alınırken hata oluştu: ' . $e->getMessage());
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Teklif temel bilgileri
    $customer_id = $_POST['customer_id'];
    $date = $_POST['date'];
    $valid_until = $_POST['valid_until'];
    $notes = $_POST['notes'];
    $terms_conditions_text = $_POST['terms_conditions']; // Bu, JS tarafından oluşturulan metin
    $status = $_POST['status'];

    // Dinamik şartlar
    $payment_terms = $_POST['payment_terms'] ?? 'partial_payment';
    $payment_percentage = intval($_POST['payment_percentage'] ?? 50);
    $delivery_days = intval($_POST['delivery_days'] ?? 10);
    $warranty_period = $_POST['warranty_period'] ?? '12 Ay';
    $installation_included = isset($_POST['installation_included']) ? 1 : 0;
    $transportation_included = isset($_POST['transportation_included']) ? 1 : 0;
    $custom_terms = $_POST['custom_terms'] ?? '';


    // Kalemler
    $item_types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $discount_percents = $_POST['discount_percent'] ?? [];
    $tax_rates = $_POST['tax_rate'] ?? [];

    // Doğrulama
    $errors = [];
    if (empty($customer_id)) $errors[] = "Müşteri seçilmelidir.";
    if (empty($date)) $errors[] = "Teklif tarihi girilmelidir.";
    if (empty($valid_until)) $errors[] = "Geçerlilik tarihi girilmelidir.";
    if (empty($status)) $errors[] = "Durum seçilmelidir.";
    if (empty($item_types)) $errors[] = "En az bir kalem eklenmelidir.";


    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Toplamları hesapla (virgülleri noktaya çevirerek)
            $subtotal = 0; $tax_amount = 0; $discount_amount = 0; $total_amount = 0;
            for ($i = 0; $i < count($item_types); $i++) {
                $q = floatval(str_replace(['.', ','], ['', '.'], $quantities[$i] ?? '0'));
                $up = floatval(str_replace(['.', ','], ['', '.'], $unit_prices[$i] ?? '0'));
                $dp = floatval(str_replace(['.', ','], ['', '.'], $discount_percents[$i] ?? '0'));
                $tr = floatval(str_replace(['.', ','], ['', '.'], $tax_rates[$i] ?? '0'));

                if ($q <= 0 || $up < 0) continue;

                $line_subtotal = $q * $up;
                $line_discount = $line_subtotal * ($dp / 100);
                $line_subtotal_after_discount = $line_subtotal - $line_discount;
                $line_tax = $line_subtotal_after_discount * ($tr / 100);

                $subtotal += $line_subtotal;
                $discount_amount += $line_discount;
                $tax_amount += $line_tax;
            }
            $total_amount = $subtotal - $discount_amount + $tax_amount;

            // Teklif ana tablosunu güncelle
            $stmtUpdateQuotation = $conn->prepare("UPDATE quotations SET
                                    customer_id = :customer_id, date = :date, valid_until = :valid_until,
                                    status = :status, subtotal = :subtotal, tax_amount = :tax_amount,
                                    discount_amount = :discount_amount, total_amount = :total_amount,
                                    notes = :notes, terms_conditions = :terms_conditions_text
                                WHERE id = :id");
            $stmtUpdateQuotation->bindParam(':customer_id', $customer_id);
            $stmtUpdateQuotation->bindParam(':date', $date);
            $stmtUpdateQuotation->bindParam(':valid_until', $valid_until);
            $stmtUpdateQuotation->bindParam(':status', $status);
            $stmtUpdateQuotation->bindParam(':subtotal', $subtotal);
            $stmtUpdateQuotation->bindParam(':tax_amount', $tax_amount);
            $stmtUpdateQuotation->bindParam(':discount_amount', $discount_amount);
            $stmtUpdateQuotation->bindParam(':total_amount', $total_amount);
            $stmtUpdateQuotation->bindParam(':notes', $notes);
            $stmtUpdateQuotation->bindParam(':terms_conditions_text', $terms_conditions_text);
            $stmtUpdateQuotation->bindParam(':id', $id);
            $stmtUpdateQuotation->execute();

            // Eski kalemleri sil
            $stmtDeleteItems = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = :id");
            $stmtDeleteItems->bindParam(':id', $id);
            $stmtDeleteItems->execute();

            // Yeni kalemleri ekle
            for ($i = 0; $i < count($item_types); $i++) {
                $item_type = $item_types[$i];
                $item_id_val = $item_ids[$i]; // item_id -> item_id_val to avoid conflict with $id
                $description = $descriptions[$i];
                $quantity = floatval(str_replace(['.', ','], ['', '.'], $quantities[$i] ?? '0'));
                $unit_price = floatval(str_replace(['.', ','], ['', '.'], $unit_prices[$i] ?? '0'));
                $discount_percent = floatval(str_replace(['.', ','], ['', '.'], $discount_percents[$i] ?? '0'));
                $tax_rate = floatval(str_replace(['.', ','], ['', '.'], $tax_rates[$i] ?? '0'));

                if (empty($item_type) || empty($item_id_val) || $quantity <= 0 || $unit_price < 0) continue;

                $line_item_subtotal_val = ($quantity * $unit_price) * (1 - ($discount_percent / 100)); // line_subtotal -> line_item_subtotal_val

                $stmtInsertItem = $conn->prepare("INSERT INTO quotation_items
                                        (quotation_id, item_type, item_id, description,
                                         quantity, unit_price, discount_percent, tax_rate, subtotal)
                                        VALUES
                                        (:quotation_id, :item_type, :item_id, :description,
                                         :quantity, :unit_price, :discount_percent, :tax_rate, :subtotal)");
                $stmtInsertItem->bindParam(':quotation_id', $id);
                $stmtInsertItem->bindParam(':item_type', $item_type);
                $stmtInsertItem->bindParam(':item_id', $item_id_val);
                $stmtInsertItem->bindParam(':description', $description);
                $stmtInsertItem->bindParam(':quantity', $quantity);
                $stmtInsertItem->bindParam(':unit_price', $unit_price);
                $stmtInsertItem->bindParam(':discount_percent', $discount_percent);
                $stmtInsertItem->bindParam(':tax_rate', $tax_rate);
                $stmtInsertItem->bindParam(':subtotal', $line_item_subtotal_val);
                $stmtInsertItem->execute();
            }

            // Teklif şartlarını güncelle veya ekle
            $stmtCheckTerms = $conn->prepare("SELECT id FROM quotation_terms WHERE quotation_id = :quotation_id");
            $stmtCheckTerms->bindParam(':quotation_id', $id);
            $stmtCheckTerms->execute();

            if ($stmtCheckTerms->rowCount() > 0) { // Güncelle
                $stmtUpdateTerms = $conn->prepare("UPDATE quotation_terms SET
                                        payment_terms = :payment_terms, payment_percentage = :payment_percentage,
                                        delivery_days = :delivery_days, warranty_period = :warranty_period,
                                        installation_included = :installation_included,
                                        transportation_included = :transportation_included, custom_terms = :custom_terms
                                      WHERE quotation_id = :quotation_id");
                $stmtUpdateTerms->bindParam(':quotation_id', $id);
                // ... (diğer bindParam'lar)
            } else { // Ekle
                $stmtUpdateTerms = $conn->prepare("INSERT INTO quotation_terms
                                      (quotation_id, payment_terms, payment_percentage, delivery_days,
                                       warranty_period, installation_included, transportation_included, custom_terms)
                                      VALUES
                                      (:quotation_id, :payment_terms, :payment_percentage, :delivery_days,
                                       :warranty_period, :installation_included, :transportation_included, :custom_terms)");
                $stmtUpdateTerms->bindParam(':quotation_id', $id);
                 // ... (diğer bindParam'lar)
            }
            $stmtUpdateTerms->bindParam(':payment_terms', $payment_terms);
            $stmtUpdateTerms->bindParam(':payment_percentage', $payment_percentage);
            $stmtUpdateTerms->bindParam(':delivery_days', $delivery_days);
            $stmtUpdateTerms->bindParam(':warranty_period', $warranty_period);
            $stmtUpdateTerms->bindParam(':installation_included', $installation_included);
            $stmtUpdateTerms->bindParam(':transportation_included', $transportation_included);
            $stmtUpdateTerms->bindParam(':custom_terms', $custom_terms);
            $stmtUpdateTerms->execute();


            $conn->commit();
            setMessage('success', 'Teklif başarıyla güncellendi.');
            header("Location: view_quotation.php?id=" . $id);
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    // Hata varsa veya POST değilse, formda gösterilecek değerleri ayarla
    // Bu kısım, $quotation ve $items dizilerini POST verileriyle günceller.
    // $quotation dizisi zaten başta DB'den çekiliyor, hata durumunda POST'tan gelenle üzerine yazılır.
    $quotation['customer_id'] = $_POST['customer_id'] ?? $quotation['customer_id'];
    $quotation['date'] = $_POST['date'] ?? $quotation['date'];
    $quotation['valid_until'] = $_POST['valid_until'] ?? $quotation['valid_until'];
    $quotation['notes'] = $_POST['notes'] ?? $quotation['notes'];
    $quotation['terms_conditions'] = $_POST['terms_conditions'] ?? $quotation['terms_conditions']; // JS'den gelen text
    $quotation['status'] = $_POST['status'] ?? $quotation['status'];

    // $quotation_terms için POST'tan gelenleri ayarla (eğer formda bu alanlar varsa ve POST edildiyse)
    if ($quotation_terms) { // Eğer DB'den terms gelmişse, üzerine yaz
        $quotation_terms['payment_terms'] = $_POST['payment_terms'] ?? $quotation_terms['payment_terms'];
        $quotation_terms['payment_percentage'] = $_POST['payment_percentage'] ?? $quotation_terms['payment_percentage'];
        $quotation_terms['delivery_days'] = $_POST['delivery_days'] ?? $quotation_terms['delivery_days'];
        $quotation_terms['warranty_period'] = $_POST['warranty_period'] ?? $quotation_terms['warranty_period'];
        $quotation_terms['installation_included'] = isset($_POST['installation_included']) ? 1 : ($quotation_terms['installation_included'] ?? 0);
        $quotation_terms['transportation_included'] = isset($_POST['transportation_included']) ? 1 : ($quotation_terms['transportation_included'] ?? 0);
        $quotation_terms['custom_terms'] = $_POST['custom_terms'] ?? $quotation_terms['custom_terms'];
    } else { // DB'den terms gelmemişse, POST'tan gelenlerle yeni bir array oluştur
         $quotation_terms = [
            'payment_terms' => $_POST['payment_terms'] ?? 'partial_payment',
            'payment_percentage' => $_POST['payment_percentage'] ?? 50,
            'delivery_days' => $_POST['delivery_days'] ?? 10,
            'warranty_period' => $_POST['warranty_period'] ?? '12 Ay',
            'installation_included' => isset($_POST['installation_included']) ? 1 : 0,
            'transportation_included' => isset($_POST['transportation_included']) ? 1 : 0,
            'custom_terms' => $_POST['custom_terms'] ?? ''
        ];
    }


    // Kalemler POST sonrası hata durumunda yeniden oluşturulacaksa, $items dizisini POST'tan gelenle doldur.
    // Bu, JS'in DOM'u yeniden çizmesiyle çakışabilir. Genelde JS ile DOM manipülasyonu tercih edilir.
    // Şimdilik, $items PHP tarafında DB'den gelen orijinal haliyle kalıyor, JS bunu güncelleyecek.
    // Eğer POST'tan gelen kalemleri PHP ile basmak isterseniz, new_quotation.php'deki gibi bir mantık kurmalısınız.
}


$pageTitle = 'Teklif Düzenle';
$currentPage = 'quotations';
include 'includes/header.php';
// Select2 CSS ekliyoruz
echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet" />';
echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-5-theme/1.3.0/select2-bootstrap-5-theme.min.css" />';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    .item-row { border: 1px solid #dee2e6; padding: 15px; margin-bottom: 15px; border-radius: 0.375rem; background-color: #f8f9fa; }
    .item-row .form-label { margin-bottom: 0.25rem; }
    .item-color-swatch { padding: 0.375rem 0.5rem; display: none; background-color: transparent; border-right:0; align-items: center; }
    .item-color-swatch span { display: block; width: 20px; height: 20px; border: 1px solid #ccc; }
    .dropdown-color-swatch { display: inline-block; width: 15px; height: 15px; border: 1px solid #ccc; margin-right: 5px; vertical-align: middle;}
    
    /* Select2 için renk kutucuğu stilleri */
    .select2-color-swatch {
        display: inline-block;
        width: 1em;
        height: 1em;
        border: 1px solid #adb5bd;
        margin-right: 7px;
        vertical-align: middle;
        border-radius: 2px;
    }
    .select2-container--bootstrap-5 .select2-results__option--highlighted { 
        background-color: #e9ecef;
        color: #212529;
    }
    .select2-container--bootstrap-5 .select2-dropdown {
        border-color: #dee2e6;
    }
    .input-group .select2-container--bootstrap-5 {
        flex: 1 1 auto;
    }
    .input-group .select2-container--bootstrap-5 .select2-selection--single {
        height: calc(1.5em + 0.75rem + 2px);
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }
    .input-group > .select2-container--bootstrap-5:not(:last-child) .select2-selection--single {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    
    /* Çoklu ürün seçimi modalı için ek stiller */
    .product-table th {
        position: sticky;
        top: 0;
        background-color: #f8f9fa;
        z-index: 10;
    }
    .product-table-container {
        max-height: 60vh;
        overflow-y: auto;
    }
    .color-preview {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 1px solid #ddd;
        border-radius: 3px;
        vertical-align: middle;
        margin-right: 5px;
    }
    .bulk-action-row {
        background: #f8f9fa;
        padding: 10px;
        margin-bottom: 10px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
    }
    
    /* Modalın input alanları için ek stiller */
    .product-table .form-control-sm {
        padding: 0.25rem 0.5rem;
        height: calc(1.5em + 0.5rem + 2px);
        font-size: 0.875rem;
    }

    .product-table tbody tr:hover {
        background-color: rgba(0, 123, 255, 0.05);
    }

    .product-table tbody tr.selected {
        background-color: rgba(0, 123, 255, 0.15);
    }

    /* Modal toplam badge için stil */
    .modal-footer .badge {
        font-size: 0.9rem;
        padding: 0.5rem 0.75rem;
    }

    /* Toplu düzenleme panel stilleri */
    .card-header.bg-light {
        background-color: #f8f9fa;
    }

    #bulkPrice:disabled {
        background-color: #e9ecef;
    }

    /* Form-check-inline düzeltme */
    .form-check-inline .form-check-input {
        margin-right: 0.25rem;
    }

    /* Küçük ekranlar için responsive ayarlar */
    @media (max-width: 992px) {
        .bulk-action-row .row {
            flex-direction: column;
        }
        
        .bulk-action-row .col-md-6 {
            width: 100%;
            margin-bottom: 10px;
        }
    }
</style>

<!-- Gelişmiş Çoklu Ürün Seçim Modalı -->
<div class="modal fade" id="bulkSelectModal" tabindex="-1" aria-labelledby="bulkSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkSelectModalLabel">Çoklu Ürün Seçimi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <!-- Arama ve Toplu Seçim Satırı -->
                <div class="bulk-action-row mb-3">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllProducts">
                                <label class="form-check-label" for="selectAllProducts">
                                    <strong>Tümünü Seç</strong>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" class="form-control" id="productSearchInput" placeholder="Ürün ara...">
                                <button class="btn btn-outline-secondary" type="button" id="clearSearchBtn">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Toplu Düzenleme Paneli -->
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Seçilen Ürünleri Toplu Düzenle</h6>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-end">
                            <div class="col-md-2">
                                <label for="bulkQuantity" class="form-label">Miktar</label>
                                <input type="text" class="form-control numeric-input" id="bulkQuantity" placeholder="1,00">
                            </div>
                            <div class="col-md-3">
                                <label for="bulkPrice" class="form-label">Birim Fiyat (₺)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control numeric-input" id="bulkPrice" placeholder="Mevcut fiyatları kullan">
                                    <div class="input-group-text">
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" id="useOriginalPrices" checked>
                                            <label class="form-check-label small" for="useOriginalPrices">Ürün fiyatlarını kullan</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label for="bulkDiscount" class="form-label">İndirim %</label>
                                <input type="text" class="form-control numeric-input" id="bulkDiscount" placeholder="0,00">
                            </div>
                            <div class="col-md-2">
                                <label for="bulkTax" class="form-label">KDV %</label>
                                <input type="text" class="form-control numeric-input" id="bulkTax" placeholder="20,00">
                            </div>
                            <div class="col-md-3">
                                <button type="button" class="btn btn-primary w-100" id="applyBulkSettings">
                                    <i class="bi bi-check2-all"></i> Seçili Ürünlere Uygula
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Ürün Tablosu -->
                <div class="product-table-container">
                    <table class="table table-hover product-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"></th>
                                <th style="width: 80px;">Kod</th>
                                <th>Ürün Adı</th>
                                <th style="width: 50px;">Renk</th>
                                <th style="width: 90px;">Miktar</th>
                                <th style="width: 120px;">Birim Fiyat (₺)</th>
                                <th style="width: 90px;">İndirim %</th>
                                <th style="width: 80px;">KDV %</th>
                                <th style="width: 130px;" class="text-end">Toplam (₺)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                            <tr data-product-id="<?php echo $product['id']; ?>" class="product-row">
                                <td class="text-center align-middle">
                                    <input type="checkbox" class="product-checkbox form-check-input" 
                                           data-id="<?php echo $product['id']; ?>"
                                           data-code="<?php echo htmlspecialchars($product['code']); ?>"
                                           data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                           data-original-price="<?php echo $product['price']; ?>"
                                           data-tax="<?php echo $product['tax_rate']; ?>"
                                           data-color="<?php echo htmlspecialchars($product['color_hex'] ?? ''); ?>">
                                </td>
                                <td class="align-middle"><?php echo htmlspecialchars($product['code']); ?></td>
                                <td class="align-middle"><?php echo htmlspecialchars($product['name']); ?></td>
                                <td class="text-center align-middle">
                                    <?php if (!empty($product['color_hex'])): ?>
                                    <span class="color-preview" style="background-color: <?php echo htmlspecialchars($product['color_hex']); ?>;"></span>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm numeric-input product-quantity" 
                                           value="1,00" data-default="1,00">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm numeric-input product-price" 
                                           value="<?php echo number_format($product['price'], 2, ',', '.'); ?>" 
                                           data-default="<?php echo number_format($product['price'], 2, ',', '.'); ?>">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm numeric-input product-discount" 
                                           value="0,00" data-default="0,00">
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm numeric-input product-tax" 
                                           value="<?php echo number_format($product['tax_rate'], 2, ',', '.'); ?>" 
                                           data-default="<?php echo number_format($product['tax_rate'], 2, ',', '.'); ?>">
                                </td>
                                <td class="text-end align-middle product-total">
                                    <?php echo number_format($product['price'], 2, ',', '.'); ?> ₺
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <div class="d-flex justify-content-between w-100">
                    <div>
                        <span class="badge bg-primary">
                            <span id="selectedCount">0</span> ürün seçildi
                        </span>
                        <span class="ms-2 badge bg-success">
                            Toplam: <span id="modalTotalAmount">0,00</span> ₺
                        </span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="button" class="btn btn-primary" id="addSelectedProductsBtn">Seçilen Ürünleri Ekle</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Mevcut Teklif Kalemlerini Toplu Düzenleme Araç Kutusu -->
<div class="modal fade" id="bulkEditExistingModal" tabindex="-1" aria-labelledby="bulkEditExistingModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkEditExistingModalLabel">Mevcut Kalemleri Toplu Düzenle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">Tüm Kalemler İçin Değerleri Güncelle</h6>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="bulkExistingQuantity" class="form-label">Miktar</label>
                                <div class="input-group">
                                    <input type="text" class="form-control numeric-input" id="bulkExistingQuantity" placeholder="Miktar girin">
                                    <div class="input-group-text">
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" id="updateExistingQuantity">
                                            <label class="form-check-label small" for="updateExistingQuantity">Uygula</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="bulkExistingPrice" class="form-label">Birim Fiyat (₺)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control numeric-input" id="bulkExistingPrice" placeholder="Fiyat girin">
                                    <div class="input-group-text">
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" id="updateExistingPrice">
                                            <label class="form-check-label small" for="updateExistingPrice">Uygula</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label for="bulkExistingDiscount" class="form-label">İndirim %</label>
                                <div class="input-group">
                                    <input type="text" class="form-control numeric-input" id="bulkExistingDiscount" placeholder="İndirim girin">
                                    <div class="input-group-text">
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" id="updateExistingDiscount">
                                            <label class="form-check-label small" for="updateExistingDiscount">Uygula</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="bulkExistingTax" class="form-label">KDV %</label>
                                <div class="input-group">
                                    <input type="text" class="form-control numeric-input" id="bulkExistingTax" placeholder="KDV girin">
                                    <div class="input-group-text">
                                        <div class="form-check form-check-inline m-0">
                                            <input class="form-check-input" type="checkbox" id="updateExistingTax">
                                            <label class="form-check-label small" for="updateExistingTax">Uygula</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                <button type="button" class="btn btn-primary" id="applyBulkEditExistingBtn">Değişiklikleri Uygula</button>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Teklif Düzenle: <?php echo htmlspecialchars($quotation['reference_no']); ?></h1>
            <div>
                <button type="button" class="btn btn-warning me-2" id="editExistingItemsBtn">
                    <i class="bi bi-pencil-square"></i> Kalemleri Toplu Düzenle
                </button>
                <a href="view_quotation.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Teklife Dön
                </a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $id); ?>">
            <div class="row">
                <!-- Sol Sütun - Temel Bilgiler -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="card-title mb-0">Teklif Bilgileri</h5></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="customer_id" class="form-label">Müşteri <span class="text-danger">*</span></label>
                                <select class="form-select" id="customer_id" name="customer_id" required>
                                    <option value="">Müşteri Seçin</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>" <?php echo ($quotation['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="date" class="form-label">Teklif Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" required value="<?php echo htmlspecialchars($quotation['date']); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="valid_until" class="form-label">Geçerlilik Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required value="<?php echo htmlspecialchars($quotation['valid_until']); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Durum <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="draft" <?php echo $quotation['status'] == 'draft' ? 'selected' : ''; ?>>Taslak</option>
                                    <option value="sent" <?php echo $quotation['status'] == 'sent' ? 'selected' : ''; ?>>Gönderildi</option>
                                    <option value="accepted" <?php echo $quotation['status'] == 'accepted' ? 'selected' : ''; ?>>Kabul Edildi</option>
                                    <option value="rejected" <?php echo $quotation['status'] == 'rejected' ? 'selected' : ''; ?>>Reddedildi</option>
                                    <option value="expired" <?php echo $quotation['status'] == 'expired' ? 'selected' : ''; ?>>Süresi Doldu</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($quotation['notes']); ?></textarea>
                            </div>
                             <div class="mb-3">
                                <label for="terms_conditions_display" class="form-label">Şartlar ve Koşullar</label>
                                <div class="card mb-3">
                                    <div class="card-header"><h6 class="mb-0">Standart Şartlar</h6></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_terms" class="form-label">Ödeme Koşulları</label>
                                                <select class="form-select" id="payment_terms" name="payment_terms">
                                                    <option value="advance_payment" <?php echo (($quotation_terms['payment_terms'] ?? '') == 'advance_payment') ? 'selected' : ''; ?>>Peşin Ödeme</option>
                                                    <option value="partial_payment" <?php echo (($quotation_terms['payment_terms'] ?? 'partial_payment') == 'partial_payment') ? 'selected' : ''; ?>>Kısmi Ödeme</option>
                                                    <option value="payment_on_delivery" <?php echo (($quotation_terms['payment_terms'] ?? '') == 'payment_on_delivery') ? 'selected' : ''; ?>>Teslimat Sonrası Ödeme</option>
                                                    <option value="installment" <?php echo (($quotation_terms['payment_terms'] ?? '') == 'installment') ? 'selected' : ''; ?>>Taksitli Ödeme</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_percentage" class="form-label">Peşinat Yüzdesi (%)</label>
                                                <input type="number" class="form-control" id="payment_percentage" name="payment_percentage" min="0" max="100" value="<?php echo htmlspecialchars($quotation_terms['payment_percentage'] ?? 50); ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="delivery_days" class="form-label">Teslimat Süresi (Gün)</label>
                                                <input type="number" class="form-control" id="delivery_days" name="delivery_days" min="1" value="<?php echo htmlspecialchars($quotation_terms['delivery_days'] ?? 10); ?>">
                                            </div>
                                            <!-- <div class="col-md-6 mb-3">
                                                <label for="warranty_period" class="form-label">Garanti Süresi</label>
                                                <input type="text" class="form-control" id="warranty_period" name="warranty_period" value="<?php echo htmlspecialchars($quotation_terms['warranty_period'] ?? '12 Ay'); ?>">
                                            </div> -->
                                        </div>
                                        <!-- <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="installation_included" name="installation_included" value="1" <?php echo !empty($quotation_terms['installation_included']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="installation_included">Kurulum Dahil</label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="transportation_included" name="transportation_included" value="1" <?php echo !empty($quotation_terms['transportation_included']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="transportation_included">Nakliye Dahil</label>
                                                </div>
                                            </div>
                                        </div> -->
                                    </div>
                                </div>
                                <div class="alert alert-info"><i class="bi bi-info-circle"></i> Aşağıdaki metin, standart şartlar dışında teklif şartlarını belirtmek için kullanılabilir.</div>
                                <textarea class="form-control" id="custom_terms" name="custom_terms" rows="4"><?php echo htmlspecialchars($quotation_terms['custom_terms'] ?? ''); ?></textarea>
                                <input type="hidden" id="terms_conditions" name="terms_conditions" value="<?php echo htmlspecialchars($quotation['terms_conditions']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sağ Sütun - Teklif Kalemleri -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Teklif Kalemleri</h5>
                            <div>
                                <button type="button" class="btn btn-primary btn-sm me-2" id="openBulkSelectBtn">
                                    <i class="bi bi-list-check"></i> Çoklu Ürün Seç
                                </button>
                                <button type="button" class="btn btn-success btn-sm" id="addItemBtn">
                                    <i class="bi bi-plus-circle"></i> Tek Ürün Ekle
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="items-container">
                                <?php foreach ($items as $index => $item): ?>
                                    <div class="item-row">
                                        <div class="row mb-2">
                                            <!-- Kalem tipi artık gizli bir input olarak düzenlendi -->
                                            <input type="hidden" name="item_type[]" value="product" class="item-type">
                                            <div class="col-md-12">
                                                <label class="form-label">Ürün <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-text item-color-swatch" style="<?php echo ($item['item_type'] == 'product' && !empty($item['product_color_hex'])) ? 'display: inline-flex;' : 'display: none;'; ?>">
                                                        <span style="background-color: <?php echo ($item['item_type'] == 'product' && !empty($item['product_color_hex'])) ? htmlspecialchars($item['product_color_hex']) : 'transparent'; ?>;"></span>
                                                    </span>
                                                    <select class="form-select item-id item-id-select" name="item_id[]" required>
                                                        <option value="">Ürün Seçin</option>
                                                        <?php
                                                        foreach ($products as $product) {
                                                            $isSelected = ($product['id'] == $item['item_id']) ? 'selected' : '';
                                                            // Ürün seçeneğindeki data attribute'ları, fiyat ve kdv için ÜRÜNDEKİ değil, TEKLİFTEKİ değerlerle dolduruyoruz
                                                            // Bu şekilde ürünün fiyatını değiştirsek bile, teklifteki orijinal fiyatlar korunur
                                                            $item_price = $item['unit_price']; // Teklifte kayıtlı fiyat
                                                            $item_tax = $item['tax_rate']; // Teklifte kayıtlı KDV
                                                            echo "<option value='{$product['id']}' data-price='{$item_price}' data-tax='{$item_tax}' data-description='" . htmlspecialchars($product['name']) . "' data-color-hex='" . htmlspecialchars($product['color_hex'] ?? '') . "' {$isSelected}>" . htmlspecialchars($product['code'] . " - " . $product['name']) . " (" . number_format($product['price'], 2, ',', '.') . " ₺)</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <!-- Orijinal item id ve değerlerini saklıyoruz - ürün değişirse kullanılacak -->
                                                <input type="hidden" class="original-item-id" value="<?php echo $item['item_id']; ?>">
                                                <input type="hidden" class="original-item-price" value="<?php echo $item['unit_price']; ?>">
                                                <input type="hidden" class="original-item-tax" value="<?php echo $item['tax_rate']; ?>">
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-12"><label class="form-label">Açıklama</label><textarea class="form-control item-description" name="description[]" rows="2"><?php echo htmlspecialchars($item['description']); ?></textarea></div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-3"><label class="form-label">Miktar <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control item-quantity numeric-input" name="quantity[]" value="<?php echo number_format($item['quantity'], 2, ',', '.'); ?>" required>
                                            </div>
                                            <div class="col-md-3"><label class="form-label">Birim Fiyat <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control item-price numeric-input" name="unit_price[]" value="<?php echo number_format($item['unit_price'], 2, ',', '.'); ?>" required>
                                            </div>
                                            <div class="col-md-2"><label class="form-label">İndirim %</label>
                                                <input type="text" class="form-control item-discount numeric-input" name="discount_percent[]" value="<?php echo number_format($item['discount_percent'], 2, ',', '.'); ?>">
                                            </div>
                                            <div class="col-md-2"><label class="form-label">KDV %</label>
                                                <input type="text" class="form-control item-tax numeric-input" name="tax_rate[]" value="<?php echo number_format($item['tax_rate'], 2, ',', '.'); ?>">
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-danger w-100 remove-item">Kaldır</button></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                             <!-- Yeni eklenecekler için template -->
                            <template id="item-template">
                                <div class="item-row">
                                    <div class="row mb-2">
                                        <!-- Kalem tipi gizli input olarak -->
                                        <input type="hidden" name="item_type[]" value="product" class="item-type">
                                        <div class="col-md-12">
                                            <label class="form-label">Ürün <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text item-color-swatch"><span></span></span>
                                                <select class="form-select item-id item-id-select" name="item_id[]" required>
                                                    <option value="">Ürün Seçin</option>
                                                    <?php foreach ($products as $product): ?>
                                                        <option value="<?php echo $product['id']; ?>" 
                                                            data-price="<?php echo $product['price']; ?>" 
                                                            data-tax="<?php echo $product['tax_rate']; ?>"
                                                            data-description="<?php echo htmlspecialchars($product['name']); ?>"
                                                            data-color-hex="<?php echo htmlspecialchars($product['color_hex'] ?? ''); ?>">
                                                            <?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?> (<?php echo number_format($product['price'], 2, ',', '.'); ?> ₺)
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-md-12"><label class="form-label">Açıklama</label><textarea class="form-control item-description" name="description[]" rows="2"></textarea></div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-md-3"><label class="form-label">Miktar <span class="text-danger">*</span></label><input type="text" class="form-control item-quantity numeric-input" name="quantity[]" value="1,00" required></div>
                                        <div class="col-md-3"><label class="form-label">Birim Fiyat <span class="text-danger">*</span></label><input type="text" class="form-control item-price numeric-input" name="unit_price[]" value="0,00" required></div>
                                        <div class="col-md-2"><label class="form-label">İndirim %</label><input type="text" class="form-control item-discount numeric-input" name="discount_percent[]" value="0,00"></div>
                                        <div class="col-md-2"><label class="form-label">KDV %</label><input type="text" class="form-control item-tax numeric-input" name="tax_rate[]" value="18,00"></div>
                                        <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-danger w-100 remove-item">Kaldır</button></div>
                                    </div>
                                </div>
                            </template>
                            <!-- Toplamlar -->
                            <div class="row mt-4">
                                <div class="col-md-7 offset-md-5">
                                    <div class="mb-2 d-flex justify-content-between"><strong>Ara Toplam:</strong> <span id="subtotal">0,00 ₺</span></div>
                                    <div class="mb-2 d-flex justify-content-between"><strong>İndirim:</strong> <span id="discount">0,00 ₺</span></div>
                                    <div class="mb-2 d-flex justify-content-between"><strong>KDV:</strong> <span id="tax">0,00 ₺</span></div>
                                    <hr class="my-1"><div class="mb-2 d-flex justify-content-between"><strong class="fs-5">Genel Toplam:</strong> <span id="total" class="fw-bold fs-5">0,00 ₺</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <a href="view_quotation.php?id=<?php echo $id; ?>" class="btn btn-secondary me-2">İptal</a>
                            <button type="submit" class="btn btn-primary">Teklifi Güncelle</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ÖNEMLİ: Bu scriptler sayfanın en altında olmalı ve bu sırayla yüklenmeli -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<script>
    // Ürün verilerini JS'de kullanmak üzere
    const productsData = <?php echo json_encode($products); ?>;
    const servicesData = <?php echo json_encode($services); ?>; // Kullanılmıyor ama kalsın
    let bulkSelectModal;
    let bulkEditExistingModal;
    let currentItems = <?php echo json_encode($items); ?>;

    // Select2 için seçenek formatlama fonksiyonu
    function formatProductOption(product) {
        if (!product.id) { return product.text; } // Arama kutusu için

        var colorHex = $(product.element).data('color-hex');
        var colorSwatchHtml = '';
        if (colorHex && /^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/.test(colorHex)) {
            colorSwatchHtml = `<span class="select2-color-swatch" style="background-color: ${colorHex};"></span>`;
        }
        // Ürün bilgilerini (kod, ad, fiyat) ve renk kutucuğunu içeren HTML
        var $option = $(
            `<span>${colorSwatchHtml}${product.text}</span>`
        );
        return $option;
    }

    function initializeSelect2(selectElement) {
        $(selectElement).select2({
            theme: "bootstrap-5", // Bootstrap 5 teması
            placeholder: "Ürün seçin",
            templateResult: formatProductOption, // Dropdown listesindeki seçenekler için
            templateSelection: formatProductOption, // Seçili olan seçenek için
            escapeMarkup: function(markup) { return markup; } // HTML'i olduğu gibi işle
        }).on('select2:select', function (e) {
            // Select2'den seçim yapıldığında handleItemIdChange'i tetikle
            handleItemIdChange(this);
        });
    }

    // Para formatı (Türkçe)
    function formatCurrency(value) {
        const num = parseFloat(String(value).replace(/[^0-9.-]/g, '').replace(',', '.') || 0);
        return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 2 }).format(num);
    }
    
    // DÜZELTME: Sayı formatı (Inputta göstermek için virgüllü) - virgül karakteri regex'e eklendi
    function formatNumberForInput(value) {
        const num = parseFloat(String(value).replace(/[^0-9.,-]/g, '').replace(',', '.') || 0);
        return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    // Sayıyı sunucuya göndermeden önce noktaya çevirir
    function prepareNumberForServer(value) {
        return String(value).replace(/\./g, '').replace(',', '.');
    }

    // Yardımcı fonksiyon: Element'e event listener eklenmişmi kontrol et
    function hasNumericListeners(element) {
        return element.hasAttribute('data-numeric-initialized');
    }

    // Yardımcı fonksiyon: Numeric input davranışını ekle
    function addNumericInputBehavior(input, updateCallback = null) {
        // Eğer zaten eklenmiş ise tekrar ekleme
        if (hasNumericListeners(input)) {
            return;
        }
        
        input.setAttribute('data-numeric-initialized', 'true');
        
        input.addEventListener('focus', (e) => { 
            e.target.value = String(e.target.value).replace(/\./g, '').replace(',', '.'); 
        });
        
        input.addEventListener('blur', (e) => { 
            e.target.value = formatNumberForInput(e.target.value);
            if (updateCallback) updateCallback();
        });
        
        input.addEventListener('input', (e) => {
            const caretPosition = e.target.selectionStart; 
            const originalValue = e.target.value;
            let value = e.target.value.replace(/[^0-9,.]/g, '');
            let commaCount = (value.match(/,/g) || []).length;
            
            if (commaCount > 1) { 
                const firstCommaIndex = value.indexOf(','); 
                value = value.substring(0, firstCommaIndex + 1) + value.substring(firstCommaIndex + 1).replace(/,/g, '');
            }
            
            e.target.value = value;
            
            if (e.target.value !== originalValue) { 
                if (caretPosition > 0) e.target.setSelectionRange(caretPosition -1, caretPosition -1); 
            }
            
            if (updateCallback) updateCallback();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const itemsContainer = document.getElementById('items-container');

        // Bootstrap modallarını başlat
        if (typeof bootstrap !== 'undefined') {
            const bulkSelectModalElement = document.getElementById('bulkSelectModal');
            if (bulkSelectModalElement) {
                bulkSelectModal = new bootstrap.Modal(bulkSelectModalElement);
            }
            
            const bulkEditExistingModalElement = document.getElementById('bulkEditExistingModal');
            if (bulkEditExistingModalElement) {
                bulkEditExistingModal = new bootstrap.Modal(bulkEditExistingModalElement);
            }
        }

        // Mevcut kalemler için event listener'ları ve ilk yüklemeyi yap
        itemsContainer.querySelectorAll('.item-row').forEach(row => {
            // Mevcut satırlardaki select2'leri başlat
            const itemIdSelect = row.querySelector('.item-id-select');
            if (itemIdSelect) {
                initializeSelect2(itemIdSelect);
            }
            
            attachEventListenersToRow(row);
        });

        // Yeni kalem ekleme düğmesi
        document.getElementById('addItemBtn').addEventListener('click', () => addNewItem(itemsContainer));
        
        // Çoklu ürün seçme butonunu bağla
        const openBulkSelectBtn = document.getElementById('openBulkSelectBtn');
        if (openBulkSelectBtn) {
            openBulkSelectBtn.addEventListener('click', function() {
                if (bulkSelectModal) {
                    // YENİ: Mevcut teklif verilerini modal'a yükle
                    loadCurrentQuotationDataToModal();
                    bulkSelectModal.show();
                }
            });
        }
        
        // Mevcut kalemleri toplu düzenleme butonunu bağla
        const editExistingItemsBtn = document.getElementById('editExistingItemsBtn');
        if (editExistingItemsBtn) {
            editExistingItemsBtn.addEventListener('click', function() {
                if (bulkEditExistingModal) {
                    // Modal formunu sıfırla
                    document.getElementById('bulkExistingQuantity').value = '';
                    document.getElementById('bulkExistingPrice').value = '';
                    document.getElementById('bulkExistingDiscount').value = '';
                    document.getElementById('bulkExistingTax').value = '';
                    
                    document.getElementById('updateExistingQuantity').checked = false;
                    document.getElementById('updateExistingPrice').checked = false;
                    document.getElementById('updateExistingDiscount').checked = false;
                    document.getElementById('updateExistingTax').checked = false;
                    
                    bulkEditExistingModal.show();
                }
            });
        }
        
        // Mevcut kalemleri toplu düzenleme butonunu bağla
        const applyBulkEditExistingBtn = document.getElementById('applyBulkEditExistingBtn');
        if (applyBulkEditExistingBtn) {
            applyBulkEditExistingBtn.addEventListener('click', function() {
                applyBulkEditToExistingItems();
                bulkEditExistingModal.hide();
            });
        }

        // Modal içi event listener'ları bağla
        setupModalEventListeners();
        setupBulkEditFeatures();
        
        updateTotals();
        updateTermsAndConditions(); // Şartlar için

        const termsRelatedFields = ['payment_terms', 'payment_percentage', 'delivery_days', 'warranty_period', 'installation_included', 'transportation_included', 'custom_terms'];
        termsRelatedFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                const eventType = (field.type === 'checkbox' || field.tagName === 'SELECT') ? 'change' : 'input';
                field.addEventListener(eventType, updateTermsAndConditions);
            }
        });
    });

    // YENİ FONKSİYON: Mevcut teklif verilerini modal'a yükle
    function loadCurrentQuotationDataToModal() {
        // Önce tüm checkbox'ları temizle
        document.querySelectorAll('.product-checkbox').forEach(checkbox => {
            checkbox.checked = false;
            const row = checkbox.closest('.product-row');
            if (row) {
                row.classList.remove('selected');
                // Değerleri default'lara sıfırla
                row.querySelector('.product-quantity').value = row.querySelector('.product-quantity').dataset.default || '1,00';
                row.querySelector('.product-price').value = row.querySelector('.product-price').dataset.default || '0,00';
                row.querySelector('.product-discount').value = row.querySelector('.product-discount').dataset.default || '0,00';
                row.querySelector('.product-tax').value = row.querySelector('.product-tax').dataset.default || '0,00';
                calculateRowTotal(row);
            }
        });

        // Arama inputunu temizle
        const searchInput = document.getElementById('productSearchInput');
        if (searchInput) searchInput.value = '';
        
        // Tüm satırları görünür yap
        document.querySelectorAll('.product-row').forEach(row => {
            row.style.display = '';
        });

        // Mevcut teklif kalemlerini kontrol et ve modal'da işaretle
        const currentItemRows = document.querySelectorAll('#items-container .item-row');
        
        currentItemRows.forEach(itemRow => {
            const itemSelect = itemRow.querySelector('.item-id-select');
            if (!itemSelect || !itemSelect.value) return;
            
            const itemId = itemSelect.value;
            const quantity = itemRow.querySelector('.item-quantity').value;
            const price = itemRow.querySelector('.item-price').value;
            const discount = itemRow.querySelector('.item-discount').value;
            const tax = itemRow.querySelector('.item-tax').value;
            
            // Modal'da bu ürünü bul ve işaretle
            const modalCheckbox = document.querySelector(`.product-checkbox[data-id="${itemId}"]`);
            if (modalCheckbox) {
                modalCheckbox.checked = true;
                
                const modalRow = modalCheckbox.closest('.product-row');
                if (modalRow) {
                    modalRow.classList.add('selected');
                    
                    // Modal satırındaki değerleri teklifteki değerlerle güncelle
                    modalRow.querySelector('.product-quantity').value = quantity;
                    modalRow.querySelector('.product-price').value = price;
                    modalRow.querySelector('.product-discount').value = discount;
                    modalRow.querySelector('.product-tax').value = tax;
                    
                    // Satır toplamını hesapla
                    calculateRowTotal(modalRow);
                }
            }
        });

        // Seçili sayısını ve toplamı güncelle
        updateSelectedCount();
        calculateModalTotalAmount();
        
        // Tümünü seç checkbox'ını güncelle
        const selectAllCheckbox = document.getElementById('selectAllProducts');
        if (selectAllCheckbox) {
            const totalVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"])').length;
            const selectedVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"]) .product-checkbox:checked').length;
            selectAllCheckbox.checked = totalVisibleProducts > 0 && totalVisibleProducts === selectedVisibleProducts;
        }
    }

    function setupModalEventListeners() {
        // Tümünü seç checkbox'ı
        const selectAllCheckbox = document.getElementById('selectAllProducts');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                let visibleCheckboxCount = 0;
                
                document.querySelectorAll('.product-row').forEach(row => {
                    if (row.style.display !== 'none') {
                        const checkbox = row.querySelector('.product-checkbox');
                        if (checkbox) {
                            checkbox.checked = isChecked;
                            visibleCheckboxCount++;
                        }
                    }
                });
                
                updateSelectedCount();
                calculateModalTotalAmount();
            });
        }
        
        // Tek tek checkbox'ları seçme
        document.querySelectorAll('.product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedCount();
                calculateModalTotalAmount();
                
                // Checkbox işaretlendiğinde satırı seçili olarak işaretle
                const row = this.closest('.product-row');
                if (row) {
                    if (this.checked) {
                        row.classList.add('selected');
                    } else {
                        row.classList.remove('selected');
                    }
                }
            });
        });
        
        // Arama işlevi
        const searchInput = document.getElementById('productSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                document.querySelectorAll('.product-row').forEach(row => {
                    const codeCell = row.querySelector('td:nth-child(2)');
                    const nameCell = row.querySelector('td:nth-child(3)');
                    
                    if (codeCell && nameCell) {
                        const productCode = codeCell.textContent.toLowerCase();
                        const productName = nameCell.textContent.toLowerCase();
                        
                        if (productCode.includes(searchTerm) || productName.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
                
                // Tümünü seç checkbox'ını güncelle
                const totalVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"])').length;
                const selectedVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"]) .product-checkbox:checked').length;
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = totalVisibleProducts > 0 && totalVisibleProducts === selectedVisibleProducts;
                }
                updateSelectedCount();
                calculateModalTotalAmount();
            });
        }
        
        // Arama temizleme butonu
        const clearSearchBtn = document.getElementById('clearSearchBtn');
        if (clearSearchBtn && searchInput) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                
                document.querySelectorAll('.product-row').forEach(row => {
                    row.style.display = '';
                });
                
                // Tümünü seç checkbox'ını güncelle
                const totalVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"])').length;
                const selectedVisibleProducts = document.querySelectorAll('.product-row:not([style*="display: none"]) .product-checkbox:checked').length;
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = totalVisibleProducts > 0 && totalVisibleProducts === selectedVisibleProducts;
                }
                updateSelectedCount();
                calculateModalTotalAmount();
            });
        }
        
        // Seçilen ürünleri ekle butonu
        const addSelectedProductsBtn = document.getElementById('addSelectedProductsBtn');
        if (addSelectedProductsBtn) {
            addSelectedProductsBtn.addEventListener('click', function() {
                const selectedProducts = [];
                
                document.querySelectorAll('.product-checkbox:checked').forEach(checkbox => {
                    // Checkbox'ın bulunduğu satırı al
                    const row = checkbox.closest('.product-row');
                    
                    // Satırdaki değerleri al
                    const quantity = row.querySelector('.product-quantity').value;
                    const price = row.querySelector('.product-price').value;
                    const discount = row.querySelector('.product-discount').value;
                    const tax = row.querySelector('.product-tax').value;
                    
                    selectedProducts.push({
                        id: checkbox.dataset.id,
                        code: checkbox.dataset.code,
                        name: checkbox.dataset.name,
                        color: checkbox.dataset.color,
                        quantity: prepareNumberForServer(quantity),
                        price: prepareNumberForServer(price),
                        discount: prepareNumberForServer(discount),
                        tax: prepareNumberForServer(tax)
                    });
                });
                
                if (selectedProducts.length > 0) {
                    // YENİ: Önce mevcut kalemleri temizle, sonra seçilenleri ekle
                    replaceAllItemsWithSelected(selectedProducts);
                    if (bulkSelectModal) bulkSelectModal.hide();
                } else {
                    alert('Lütfen en az bir ürün seçin.');
                }
            });
        }
    }

    function setupBulkEditFeatures() {
        // Toplu Düzenleme butonuna tıklanınca
        const applyBulkSettingsBtn = document.getElementById('applyBulkSettings');
        if (applyBulkSettingsBtn) {
            applyBulkSettingsBtn.addEventListener('click', function() {
                const bulkQuantity = document.getElementById('bulkQuantity').value;
                const bulkPrice = document.getElementById('bulkPrice').value;
                const bulkDiscount = document.getElementById('bulkDiscount').value;
                const bulkTax = document.getElementById('bulkTax').value;
                const useOriginalPrices = document.getElementById('useOriginalPrices').checked;
                
                // Seçili ürünleri bul
                document.querySelectorAll('.product-checkbox:checked').forEach(checkbox => {
                    const row = checkbox.closest('.product-row');
                    
                    // Miktar değerini güncelle
                    if (bulkQuantity) {
                        row.querySelector('.product-quantity').value = bulkQuantity;
                    }
                    
                    // Birim fiyat değerini güncelle
                    if (!useOriginalPrices && bulkPrice) {
                        row.querySelector('.product-price').value = bulkPrice;
                    }
                    
                    // İndirim değerini güncelle
                    if (bulkDiscount) {
                        row.querySelector('.product-discount').value = bulkDiscount;
                    }
                    
                    // KDV değerini güncelle
                    if (bulkTax) {
                        row.querySelector('.product-tax').value = bulkTax;
                    }
                    
                    // Satır toplamını güncelle
                    calculateRowTotal(row);
                });
                
                // Modal toplamını güncelle
                calculateModalTotalAmount();
            });
        }
        
        // "Ürün fiyatlarını kullan" checkbox'ı değişince
        const useOriginalPricesCheckbox = document.getElementById('useOriginalPrices');
        const bulkPriceInput = document.getElementById('bulkPrice');
        
        if (useOriginalPricesCheckbox && bulkPriceInput) {
            useOriginalPricesCheckbox.addEventListener('change', function() {
                bulkPriceInput.disabled = this.checked;
                if (this.checked) {
                    bulkPriceInput.placeholder = "Mevcut fiyatları kullan";
                    bulkPriceInput.value = '';
                } else {
                    bulkPriceInput.placeholder = "Toplu fiyat girin";
                }
            });
            
            // Sayfa yüklendiğinde durumu ayarla
            bulkPriceInput.disabled = useOriginalPricesCheckbox.checked;
        }
        
        // Modal içindeki bulk edit input'larına numeric behavior ekle
        const bulkInputs = ['bulkQuantity', 'bulkPrice', 'bulkDiscount', 'bulkTax'];
        bulkInputs.forEach(inputId => {
            const input = document.getElementById(inputId);
            if (input) {
                addNumericInputBehavior(input);
            }
        });
        
        // Modal içindeki product table numeric input'larına behavior ekle
        document.querySelectorAll('#bulkSelectModal .product-table .numeric-input').forEach(input => {
            addNumericInputBehavior(input, () => {
                const row = input.closest('.product-row');
                calculateRowTotal(row);
                calculateModalTotalAmount();
            });
        });
    }
    
    function applyBulkEditToExistingItems() {
        const bulkQuantity = document.getElementById('bulkExistingQuantity').value;
        const bulkPrice = document.getElementById('bulkExistingPrice').value;
        const bulkDiscount = document.getElementById('bulkExistingDiscount').value;
        const bulkTax = document.getElementById('bulkExistingTax').value;
        
        const updateQuantity = document.getElementById('updateExistingQuantity').checked;
        const updatePrice = document.getElementById('updateExistingPrice').checked;
        const updateDiscount = document.getElementById('updateExistingDiscount').checked;
        const updateTax = document.getElementById('updateExistingTax').checked;
        
        // Tüm mevcut kalemleri bul
        document.querySelectorAll('#items-container .item-row').forEach(row => {
            // Miktar değerini güncelle
            if (updateQuantity && bulkQuantity) {
                row.querySelector('.item-quantity').value = bulkQuantity;
            }
            
            // Birim fiyat değerini güncelle
            if (updatePrice && bulkPrice) {
                row.querySelector('.item-price').value = bulkPrice;
            }
            
            // İndirim değerini güncelle
            if (updateDiscount && bulkDiscount) {
                row.querySelector('.item-discount').value = bulkDiscount;
            }
            
            // KDV değerini güncelle
            if (updateTax && bulkTax) {
                row.querySelector('.item-tax').value = bulkTax;
            }
        });
        
        // Toplamları güncelle
        updateTotals();
    }

    function calculateRowTotal(row) {
        // Satır değerlerini al
        const quantity = parseFloat(prepareNumberForServer(row.querySelector('.product-quantity').value)) || 0;
        const price = parseFloat(prepareNumberForServer(row.querySelector('.product-price').value)) || 0;
        const discount = parseFloat(prepareNumberForServer(row.querySelector('.product-discount').value)) || 0;
        const tax = parseFloat(prepareNumberForServer(row.querySelector('.product-tax').value)) || 0;
        
        // Hesaplamalar
        const subtotal = quantity * price;
        const discountAmount = subtotal * (discount / 100);
        const afterDiscount = subtotal - discountAmount;
        const taxAmount = afterDiscount * (tax / 100);
        const total = afterDiscount + taxAmount;
        
        // Toplamı güncelle
        const totalCell = row.querySelector('.product-total');
        if (totalCell) {
            totalCell.textContent = formatCurrency(total);
        }
        
        return total;
    }

    function calculateModalTotalAmount() {
        let totalAmount = 0;
        
        // Seçili satırların toplamlarını hesapla
        document.querySelectorAll('.product-checkbox:checked').forEach(checkbox => {
            const row = checkbox.closest('.product-row');
            totalAmount += calculateRowTotal(row);
        });
        
        // Modal toplamını güncelle
        const modalTotalElement = document.getElementById('modalTotalAmount');
        if (modalTotalElement) {
            modalTotalElement.textContent = formatCurrency(totalAmount).replace(' ₺', '');
        }
    }
    
    function updateSelectedCount() {
        const selectedCount = document.querySelectorAll('.product-checkbox:checked').length;
        const selectedCountSpan = document.getElementById('selectedCount');
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedCount;
        }
    }

    function attachEventListenersToRow(rowElement) {
        const numericInputs = rowElement.querySelectorAll('.numeric-input');
        const removeButton = rowElement.querySelector('.remove-item');
        
        // Numeric input'lar için davranışı ekle
        numericInputs.forEach(input => {
            addNumericInputBehavior(input, updateTotals);
        });
        
        // Sil butonu için event listener
        if (removeButton) {
            removeButton.addEventListener('click', function () {
                // Select2'yi kaldır (destroy)
                const select2Element = this.closest('.item-row').querySelector('.item-id-select');
                if (select2Element && $(select2Element).data('select2')) {
                    $(select2Element).select2('destroy');
                }
                
                this.closest('.item-row').remove();
                updateTotals();
            });
        }
        
        // Ürün değişimi için event listener - select2 için ayrıca yaptık
        const itemIdSelect = rowElement.querySelector('.item-id-select');
        if (itemIdSelect) {
            itemIdSelect.addEventListener('change', function() {
                // Eğer select2 tarafından tetiklenmişse, o event listener'ını kullanacak
                // Burada manuel değişimler için ekliyoruz
                handleItemIdChange(this);
            });
        }
    }

    function addNewItem(container) {
        const template = document.getElementById('item-template');
        if (!template) {
            console.error('Item template not found!');
            return;
        }
        
        const clone = document.importNode(template.content, true);
        const newRow = clone.querySelector('.item-row');

        attachEventListenersToRow(newRow); // Önce event listener'ları ata
        container.appendChild(clone);

        // Yeni eklenen satırdaki Select2'yi başlat
        const newItemIdSelect = newRow.querySelector('.item-id-select');
        if (newItemIdSelect) {
            initializeSelect2(newItemIdSelect);
        }

        // Sayısal değerleri formatla
        newRow.querySelectorAll('.numeric-input').forEach(input => {
            input.value = formatNumberForInput(input.value);
        });
        
        updateTotals();
    }

    function handleItemIdChange(itemIdSelectElement) {
        const selectedOption = itemIdSelectElement.options[itemIdSelectElement.selectedIndex];
        const row = itemIdSelectElement.closest('.item-row');
        const priceInput = row.querySelector('.item-price');
        const taxInput = row.querySelector('.item-tax');
        const descInput = row.querySelector('.item-description');
        const colorSwatchContainer = row.querySelector('.item-color-swatch');
        const colorSwatchSpan = colorSwatchContainer ? colorSwatchContainer.querySelector('span') : null;
        
        // Orijinal değerlere erişim - eğer mevcutsa
        const originalItemId = row.querySelector('.original-item-id');
        const originalItemPrice = row.querySelector('.original-item-price');
        const originalItemTax = row.querySelector('.original-item-tax');

        if (selectedOption && selectedOption.value) {
            // ÖNEMLİ DEĞİŞİKLİK: Aynı ürünü seçtiyse ve orijinal değerler varsa, 
            // onları kullan (ürünün veritabanındaki güncel değerleri yerine)
            if (originalItemId && originalItemPrice && originalItemTax && 
                originalItemId.value === selectedOption.value) {
                priceInput.value = formatNumberForInput(originalItemPrice.value);
                taxInput.value = formatNumberForInput(originalItemTax.value);
            } else {
                // Farklı bir ürün seçtiyse, seçilen ürünün fiyat ve vergisini kullan
                priceInput.value = formatNumberForInput(selectedOption.dataset.price);
                taxInput.value = formatNumberForInput(selectedOption.dataset.tax);
            }
            
            // Açıklama kısmını güncelle
            descInput.value = selectedOption.dataset.description;

            // Renk kutusu için
            if (colorSwatchContainer && colorSwatchSpan && selectedOption.dataset.colorHex && 
                /^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/.test(selectedOption.dataset.colorHex)) {
                colorSwatchSpan.style.backgroundColor = selectedOption.dataset.colorHex;
                colorSwatchContainer.style.display = 'inline-flex';
            } else if (colorSwatchContainer) {
                colorSwatchContainer.style.display = 'none';
            }
        } else { // Seçim yoksa
            priceInput.value = formatNumberForInput(0);
            taxInput.value = formatNumberForInput(18); // Varsayılan KDV
            descInput.value = '';
            if (colorSwatchContainer) colorSwatchContainer.style.display = 'none';
        }
        
        updateTotals();
    }
    
    // YENİ FONKSİYON: Mevcut tüm kalemleri sil ve seçilenleri ekle
    function replaceAllItemsWithSelected(selectedProducts) {
        const itemsContainer = document.getElementById('items-container');
        if (!itemsContainer) {
            console.error('Items container not found!');
            return;
        }
        
        // Önce mevcut tüm kalemleri sil
        itemsContainer.querySelectorAll('.item-row').forEach(row => {
            // Select2'yi temizle
            const select2Element = row.querySelector('.item-id-select');
            if (select2Element && $(select2Element).data('select2')) {
                $(select2Element).select2('destroy');
            }
            row.remove();
        });
        
        // Sonra seçilen ürünleri ekle
        selectedProducts.forEach(product => {
            const template = document.getElementById('item-template');
            if (!template) {
                console.error('Item template not found!');
                return;
            }
            
            const clone = document.importNode(template.content, true);
            const newRow = clone.querySelector('.item-row');
            
            // Ürün bilgilerini doldur
            const itemIdSelect = newRow.querySelector('.item-id-select');
            const priceInput = newRow.querySelector('.item-price');
            const taxInput = newRow.querySelector('.item-tax');
            const descInput = newRow.querySelector('.item-description');
            const quantityInput = newRow.querySelector('.item-quantity');
            const discountInput = newRow.querySelector('.item-discount');
            const colorSwatchContainer = newRow.querySelector('.item-color-swatch');
            const colorSwatchSpan = colorSwatchContainer ? colorSwatchContainer.querySelector('span') : null;
            
            // Event listener'ları ekle
            attachEventListenersToRow(newRow);
            
            // Önce Select2'yi başlat
            initializeSelect2(itemIdSelect);
            
            // Sonra değerleri ayarla
            $(itemIdSelect).val(product.id).trigger('change'); // Select2 için
            
            // Modal'dan gelen değerleri kullan
            priceInput.value = formatNumberForInput(product.price);
            taxInput.value = formatNumberForInput(product.tax);
            quantityInput.value = formatNumberForInput(product.quantity);
            discountInput.value = formatNumberForInput(product.discount);
            descInput.value = product.name;
            
            // Renk kutucuğunu ayarla
            if (colorSwatchContainer && colorSwatchSpan && product.color && /^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/.test(product.color)) {
                colorSwatchSpan.style.backgroundColor = product.color;
                colorSwatchContainer.style.display = 'inline-flex';
            } else if (colorSwatchContainer) {
                colorSwatchContainer.style.display = 'none';
            }
            
            // Satırı ekle
            itemsContainer.appendChild(newRow);
        });
        
        updateTotals();
    }

    function updateTotals() {
        let subtotal = 0; let totalDiscount = 0; let totalTax = 0;
        
        document.querySelectorAll('#items-container .item-row').forEach(row => {
            const quantity = parseFloat(prepareNumberForServer(row.querySelector('.item-quantity').value)) || 0;
            const price = parseFloat(prepareNumberForServer(row.querySelector('.item-price').value)) || 0;
            const discountPercent = parseFloat(prepareNumberForServer(row.querySelector('.item-discount').value)) || 0;
            const taxPercent = parseFloat(prepareNumberForServer(row.querySelector('.item-tax').value)) || 0;
            
            const lineSubtotal = quantity * price;
            const lineDiscount = lineSubtotal * (discountPercent / 100);
            const lineSubtotalAfterDiscount = lineSubtotal - lineDiscount;
            const lineTax = lineSubtotalAfterDiscount * (taxPercent / 100);
            
            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;
        });
        
        const total = subtotal - totalDiscount + totalTax;
        
        const subtotalElement = document.getElementById('subtotal');
        const discountElement = document.getElementById('discount');
        const taxElement = document.getElementById('tax');
        const totalElement = document.getElementById('total');
        
        if (subtotalElement) subtotalElement.textContent = formatCurrency(subtotal);
        if (discountElement) discountElement.textContent = formatCurrency(totalDiscount);
        if (taxElement) taxElement.textContent = formatCurrency(totalTax);
        if (totalElement) totalElement.textContent = formatCurrency(total);
    }

    // Şartlar ve Koşullar Güncelleme Fonksiyonu
    function updateTermsAndConditions() {
        const paymentTermsSelect = document.getElementById('payment_terms');
        const paymentTerms = paymentTermsSelect.options[paymentTermsSelect.selectedIndex].text;
        const paymentPercentage = document.getElementById('payment_percentage').value;
        const deliveryDays = document.getElementById('delivery_days').value;
        // const warrantyPeriod = document.getElementById('warranty_period').value;
        const installationIncluded = document.getElementById('installation_included') ? document.getElementById('installation_included').checked : false;
        const transportationIncluded = document.getElementById('transportation_included') ? document.getElementById('transportation_included').checked : false;
        const customTerms = document.getElementById('custom_terms').value;

        let termsText = '';
        const validUntilDate = document.getElementById('valid_until').value;
        if (validUntilDate) {
            const dateParts = validUntilDate.split('-');
            const formattedDate = `${dateParts[2]}.${dateParts[1]}.${dateParts[0]}`;
            termsText += `Teklif Geçerlilik Tarihi: ${formattedDate}\n`;
        } else {
            termsText += 'Teklif Geçerlilik Tarihi: Belirtilmemiş\n';
        }

        if (document.getElementById('payment_terms').value === 'advance_payment') {
            termsText += `Ödeme Şartları: ${paymentTerms} (%100).\n`;
        } else if (document.getElementById('payment_terms').value === 'partial_payment') {
            termsText += `Ödeme Şartları: ${paymentTerms} (%${paymentPercentage} peşin, %${100 - parseInt(paymentPercentage)} teslimat öncesi).\n`;
        } else if (document.getElementById('payment_terms').value === 'payment_on_delivery') {
            termsText += `Ödeme Şartları: ${paymentTerms}.\n`;
        } else if (document.getElementById('payment_terms').value === 'installment') {
            termsText += `Ödeme Şartları: ${paymentTerms} (%${paymentPercentage} peşin, kalanı taksitlendirilecektir).\n`;
        }

        termsText += `Teslimat Süresi: Sipariş onayından itibaren ${deliveryDays} iş günüdür.\n`;
        // if (warrantyPeriod.trim() !== '') termsText += `Garanti Süresi: ${warrantyPeriod}\n`;

        let additionalServices = [];
        // if (installationIncluded) additionalServices.push('Kurulum');
        // if (transportationIncluded) additionalServices.push('Nakliye');
        if (additionalServices.length > 0) termsText += `Fiyata Dahil Olan Hizmetler: ${additionalServices.join(' ve ')}\n`;
        else termsText += `Fiyata Nakliye dahil değildir.\n`;

        if (customTerms.trim() !== '') termsText += '\nDiğer Şartlar:\n' + customTerms;
        document.getElementById('terms_conditions').value = termsText.trim();
    }
</script>

<?php include 'includes/footer_scripts_bottom.php'; // Varsa ?>
</body>
</html>