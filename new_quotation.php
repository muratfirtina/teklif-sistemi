<?php
// new_quotation.php - Yeni teklif oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/page_access_control.php';

requireLogin();
$conn = getDbConnection();

$preselected_customer_id = 0;
if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
    $preselected_customer_id = intval($_GET['customer_id']);
}

$customers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

$products = [];
try {
    $stmt = $conn->query("SELECT id, code, name, price, tax_rate, stock_quantity, color_hex FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Ürün listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

$services = []; // Hizmetler şu an için kullanılmıyor

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $customer_id = $_POST['customer_id'];
    $date = $_POST['date'];
    $valid_until = $_POST['valid_until'];
    $notes = $_POST['notes'];
    $terms_conditions = $_POST['terms_conditions'];

    $payment_terms = $_POST['payment_terms'] ?? 'partial_payment';
    $payment_percentage = intval($_POST['payment_percentage'] ?? 50);
    $delivery_days = intval($_POST['delivery_days'] ?? 10);
    // Garanti süresi yorum satırına alındı
    // $warranty_period = $_POST['warranty_period'] ?? '12 Ay';
    // Kurulum dahil yorum satırına alındı
    // $installation_included = isset($_POST['installation_included']) ? 1 : 0;
    $transportation_included = isset($_POST['transportation_included']) ? 1 : 0;
    $custom_terms = $_POST['custom_terms'] ?? '';

    $item_types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? [];
    $discount_percents = $_POST['discount_percent'] ?? [];
    $tax_rates = $_POST['tax_rate'] ?? [];

    $errors = [];
    if (empty($customer_id)) $errors[] = "Müşteri seçilmelidir.";
    if (empty($date)) $errors[] = "Teklif tarihi girilmelidir.";
    if (empty($valid_until)) $errors[] = "Geçerlilik tarihi girilmelidir.";
    if (empty($item_types)) {
        $errors[] = "En az bir kalem eklenmelidir.";
    } else {
        for ($i = 0; $i < count($item_types); $i++) {
            if (empty($item_ids[$i])) $errors[] = ($i + 1) . ". kalem için Ürün/Hizmet seçilmelidir.";
            if (empty($quantities[$i]) || !is_numeric(str_replace(',', '.', $quantities[$i])) || floatval(str_replace(',', '.', $quantities[$i])) <= 0) $errors[] = ($i + 1) . ". kalem için geçerli bir miktar girilmelidir.";
            if (!isset($unit_prices[$i]) || !is_numeric(str_replace(',', '.', $unit_prices[$i])) || floatval(str_replace(',', '.', $unit_prices[$i])) < 0) $errors[] = ($i + 1) . ". kalem için geçerli bir birim fiyat girilmelidir.";
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            $year = date('Y', strtotime($date));
            $month = date('m', strtotime($date));
            $stmtRef = $conn->prepare("SELECT COUNT(*) FROM quotations WHERE YEAR(date) = :year AND MONTH(date) = :month");
            $stmtRef->bindParam(':year', $year);
            $stmtRef->bindParam(':month', $month);
            $stmtRef->execute();
            $count = $stmtRef->fetchColumn();
            $sequence = $count + 1;
            $prefix = "TEK"; // Varsayılan
            // ... (prefix ayarını alma kodu) ...
            $reference_no = sprintf("%s-%s%s-%03d", $prefix, $year, $month, $sequence);

            // ... (toplam hesaplama kodu) ...
            $subtotal_calc = 0; $tax_amount_calc = 0; $discount_amount_calc = 0; $total_amount_calc = 0;
             for ($i = 0; $i < count($item_types); $i++) {
                $quantity_calc = floatval(str_replace(',', '.', $quantities[$i] ?? '0'));
                $unit_price_calc = floatval(str_replace(',', '.', $unit_prices[$i] ?? '0'));
                $discount_percent_calc = floatval(str_replace(',', '.', $discount_percents[$i] ?? '0'));
                $tax_rate_calc = floatval(str_replace(',', '.', $tax_rates[$i] ?? '0'));
                $line_subtotal_calc = $quantity_calc * $unit_price_calc;
                $line_discount_calc = $line_subtotal_calc * ($discount_percent_calc / 100);
                $line_subtotal_after_discount_calc = $line_subtotal_calc - $line_discount_calc;
                $line_tax_calc = $line_subtotal_after_discount_calc * ($tax_rate_calc / 100);
                $subtotal_calc += $line_subtotal_calc;
                $discount_amount_calc += $line_discount_calc;
                $tax_amount_calc += $line_tax_calc;
            }
            $total_amount_calc = $subtotal_calc - $discount_amount_calc + $tax_amount_calc;


            $stmtQuotation = $conn->prepare("INSERT INTO quotations (reference_no, customer_id, user_id, date, valid_until, status, subtotal, tax_amount, discount_amount, total_amount, notes, terms_conditions) VALUES (:reference_no, :customer_id, :user_id, :date, :valid_until, 'draft', :subtotal, :tax_amount, :discount_amount, :total_amount, :notes, :terms_conditions)");
            $user_id = $_SESSION['user_id'];
            $stmtQuotation->bindParam(':reference_no', $reference_no);
            $stmtQuotation->bindParam(':customer_id', $customer_id);
            $stmtQuotation->bindParam(':user_id', $user_id);
            $stmtQuotation->bindParam(':date', $date);
            $stmtQuotation->bindParam(':valid_until', $valid_until);
            $stmtQuotation->bindParam(':subtotal', $subtotal_calc);
            $stmtQuotation->bindParam(':tax_amount', $tax_amount_calc);
            $stmtQuotation->bindParam(':discount_amount', $discount_amount_calc);
            $stmtQuotation->bindParam(':total_amount', $total_amount_calc);
            $stmtQuotation->bindParam(':notes', $notes);
            $stmtQuotation->bindParam(':terms_conditions', $terms_conditions);
            $stmtQuotation->execute();
            $quotation_id = $conn->lastInsertId();

            for ($i = 0; $i < count($item_types); $i++) {
                // ... (kalem ekleme kodu) ...
                $item_type_val = $item_types[$i]; $item_id_val = $item_ids[$i]; $description_val = $descriptions[$i];
                $quantity_val = floatval(str_replace(',', '.', $quantities[$i] ?? '0'));
                $unit_price_val = floatval(str_replace(',', '.', $unit_prices[$i] ?? '0'));
                $discount_percent_val = floatval(str_replace(',', '.', $discount_percents[$i] ?? '0'));
                $tax_rate_val = floatval(str_replace(',', '.', $tax_rates[$i] ?? '0'));
                $line_item_subtotal_val = ($quantity_val * $unit_price_val) * (1 - ($discount_percent_val / 100));

                $stmtItem = $conn->prepare("INSERT INTO quotation_items (quotation_id, item_type, item_id, description, quantity, unit_price, discount_percent, tax_rate, subtotal) VALUES (:quotation_id, :item_type, :item_id, :description, :quantity, :unit_price, :discount_percent, :tax_rate, :subtotal)");
                $stmtItem->bindParam(':quotation_id', $quotation_id);
                $stmtItem->bindParam(':item_type', $item_type_val);
                $stmtItem->bindParam(':item_id', $item_id_val);
                $stmtItem->bindParam(':description', $description_val);
                $stmtItem->bindParam(':quantity', $quantity_val);
                $stmtItem->bindParam(':unit_price', $unit_price_val);
                $stmtItem->bindParam(':discount_percent', $discount_percent_val);
                $stmtItem->bindParam(':tax_rate', $tax_rate_val);
                $stmtItem->bindParam(':subtotal', $line_item_subtotal_val);
                $stmtItem->execute();
            }

            // ... (teklif şartları ekleme kodu) ...
             try {
                // ... (quotation_terms tablo kontrolü ve oluşturma) ...
                // Veritabanı INSERT sorgusunda warranty_period ve installation_included parametreleri yorum satırına alındı
                $stmtTerms = $conn->prepare("INSERT INTO quotation_terms (quotation_id, payment_terms, payment_percentage, delivery_days, transportation_included, custom_terms) VALUES (:quotation_id, :payment_terms, :payment_percentage, :delivery_days, :transportation_included, :custom_terms)");
                $stmtTerms->bindParam(':quotation_id', $quotation_id);
                $stmtTerms->bindParam(':payment_terms', $payment_terms);
                $stmtTerms->bindParam(':payment_percentage', $payment_percentage);
                $stmtTerms->bindParam(':delivery_days', $delivery_days);
                // $stmtTerms->bindParam(':warranty_period', $warranty_period);
                // $stmtTerms->bindParam(':installation_included', $installation_included, PDO::PARAM_BOOL);
                $stmtTerms->bindParam(':transportation_included', $transportation_included, PDO::PARAM_BOOL);
                $stmtTerms->bindParam(':custom_terms', $custom_terms);
                $stmtTerms->execute();
            } catch (PDOException $e) {
                error_log("Teklif şartları eklenirken hata: " . $e->getMessage());
            }


            $conn->commit();
            setMessage('success', 'Teklif başarıyla oluşturuldu: ' . $reference_no);
            header("Location: view_quotation.php?id=" . $quotation_id);
            exit;
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

$pageTitle = 'Yeni Teklif Oluştur';
$currentPage = 'quotations';
include 'includes/header.php';
// YENİ: Select2 CSS (CDN'den) - Projenize uygun şekilde ekleyin
echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />'; // Bootstrap 5 teması
// -----
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    .item-row {
        border: 1px solid #dee2e6;
        padding: 15px;
        margin-bottom: 15px;
        border-radius: 0.375rem;
        background-color: #f8f9fa;
    }
    .item-row .form-label {
        margin-bottom: 0.25rem;
        font-size: 0.875em;
    }
    .input-group-text.item-color-swatch {
        padding: 0.375rem 0.5rem;
        display: none;
        background-color: transparent;
        border-right: 0;
        align-items: center;
    }
    .input-group-text.item-color-swatch span {
        display: block;
        width: 20px;
        height: 20px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }
    /* YENİ: Select2 için renk kutucuğu stilleri */
    .select2-color-swatch {
        display: inline-block;
        width: 1em; /* Font boyutuna göre ayarlanır */
        height: 1em;
        border: 1px solid #adb5bd;
        margin-right: 7px;
        vertical-align: middle;
        border-radius: 2px;
    }
    .select2-container--bootstrap-5 .select2-results__option--highlighted { /* Vurgulanan seçeneğin arkaplan rengi */
        background-color: #e9ecef;
        color: #212529;
    }
     .select2-container--bootstrap-5 .select2-dropdown {
        border-color: #dee2e6; /* Dropdown border rengi */
    }
    /* Select2'nin input-group içinde doğru görünmesi için */
    .input-group .select2-container--bootstrap-5 {
        flex: 1 1 auto;
    }
    .input-group .select2-container--bootstrap-5 .select2-selection--single {
        height: calc(1.5em + 0.75rem + 2px); /* Bootstrap input yüksekliği */
        border-top-left-radius: 0; /* Eğer solda span varsa */
        border-bottom-left-radius: 0; /* Eğer solda span varsa */
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

<!-- Gelişmiş Çoklu Ürün Seçim Modalı - Sayfanın üstüne taşındı -->
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

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Yeni Teklif Oluştur</h1>
            <a href="quotations.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Tekliflere Dön
            </a>
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

        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . ($preselected_customer_id > 0 ? '?customer_id='.$preselected_customer_id : '')); ?>">
            <div class="row">
                <!-- Sol Sütun -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header"><h5 class="card-title mb-0">Teklif Bilgileri</h5></div>
                        <div class="card-body">
                            <!-- Müşteri, Tarih, Geçerlilik, Notlar -->
                             <div class="mb-3">
                                <label for="customer_id" class="form-label">Müşteri <span class="text-danger">*</span></label>
                                <select class="form-select" id="customer_id" name="customer_id" required>
                                    <option value="">Müşteri Seçin</option>
                                    <?php foreach ($customers as $customer):
                                        $isSelected = ($preselected_customer_id > 0 && $customer['id'] == $preselected_customer_id) || (isset($_POST['customer_id']) && $customer['id'] == $_POST['customer_id']);
                                    ?>
                                        <option value="<?php echo $customer['id']; ?>" <?php echo $isSelected ? 'selected' : ''; ?>><?php echo htmlspecialchars($customer['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="date" class="form-label">Teklif Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" required value="<?php echo isset($_POST['date']) ? htmlspecialchars($_POST['date']) : date('Y-m-d'); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="valid_until" class="form-label">Geçerlilik Tarihi <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required value="<?php echo isset($_POST['valid_until']) ? htmlspecialchars($_POST['valid_until']) : date('Y-m-d', strtotime('+30 days')); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                            </div>
                            <!-- Şartlar ve Koşullar -->
                            <div class="mb-3">
                                <label class="form-label">Şartlar ve Koşullar</label>
                                <!-- ... (standart şartlar HTML'i burada) ... -->
                                 <div class="card mb-3">
                                    <div class="card-header"><h6 class="mb-0">Standart Şartlar</h6></div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_terms" class="form-label">Ödeme Koşulları</label>
                                                <select class="form-select" id="payment_terms" name="payment_terms">
                                                    <option value="advance_payment" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == 'advance_payment') ? 'selected' : ((!isset($_POST['payment_terms'])) ? 'selected' : '');; ?>>Peşin Ödeme</option>
                                                    <option value="partial_payment" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == 'partial_payment') ? 'selected' : '' ?>>Kısmi Ödeme</option>
                                                    <option value="payment_on_delivery" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == 'payment_on_delivery') ? 'selected' : ''; ?>>Teslimat Sonrası Ödeme</option>
                                                    <option value="installment" <?php echo (isset($_POST['payment_terms']) && $_POST['payment_terms'] == 'installment') ? 'selected' : ''; ?>>Taksitli Ödeme</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_percentage" class="form-label">Peşinat Yüzdesi (%)</label>
                                                <input type="number" class="form-control" id="payment_percentage" name="payment_percentage" min="0" max="100" value="<?php echo isset($_POST['payment_percentage']) ? intval($_POST['payment_percentage']) : '100'; ?>">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="delivery_days" class="form-label">Teslimat Süresi (Gün)</label>
                                                <input type="number" class="form-control" id="delivery_days" name="delivery_days" min="1" value="<?php echo isset($_POST['delivery_days']) ? intval($_POST['delivery_days']) : '10'; ?>">
                                            </div>
                                            <!-- Garanti süresi kısmı yorum satırına alındı -->
                                            <!--
                                            <div class="col-md-6 mb-3">
                                                <label for="warranty_period" class="form-label">Garanti Süresi</label>
                                                <input type="text" class="form-control" id="warranty_period" name="warranty_period" value="<?php echo isset($_POST['warranty_period']) ? htmlspecialchars($_POST['warranty_period']) : '12 Ay'; ?>">
                                            </div>
                                            -->
                                        </div>
                                        <div class="row">
                                            <!-- Kurulum dahil kısmı yorum satırına alındı -->
                                            <!--
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="installation_included" name="installation_included" value="1" <?php echo (isset($_POST['installation_included']) && $_POST['installation_included'] == '1') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="installation_included">Kurulum Dahil</label>
                                                </div>
                                            </div>
                                            -->
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="transportation_included" name="transportation_included" value="1" <?php echo (isset($_POST['transportation_included']) && $_POST['transportation_included'] == '1') ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="transportation_included">Nakliye Dahil</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="alert alert-info"><i class="bi bi-info-circle"></i> Aşağıdaki metin şartlar ve koşullar bölümüne eklenecektir.</div>
                                <textarea class="form-control" id="custom_terms" name="custom_terms" rows="4"><?php echo isset($_POST['custom_terms']) ? htmlspecialchars($_POST['custom_terms']) : ''; ?></textarea>
                                <input type="hidden" id="terms_conditions" name="terms_conditions" value="<?php echo isset($_POST['terms_conditions']) ? htmlspecialchars($_POST['terms_conditions']) : ''; ?>">
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
                                <?php
                                if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($item_types)) {
                                    for ($i = 0; $i < count($item_types); $i++) {
                                        $current_item_type = $item_types[$i] ?? '';
                                        $current_item_id = $item_ids[$i] ?? '';
                                        // ... (diğer POST değişkenlerini alma)
                                        $current_description = $descriptions[$i] ?? '';
                                        $current_quantity = $quantities[$i] ?? '1,00';
                                        $current_unit_price = $unit_prices[$i] ?? '0,00';
                                        $current_discount_percent = $discount_percents[$i] ?? '0,00';
                                        $current_tax_rate = $tax_rates[$i] ?? '0,00';


                                        $currentItemColorHexForSwatch = '';
                                        $currentItemColorSwatchDisplay = 'none';
                                        if ($current_item_type == 'product' && !empty($current_item_id)) {
                                            foreach ($products as $p_check) {
                                                if ($p_check['id'] == $current_item_id && !empty($p_check['color_hex']) && preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $p_check['color_hex'])) {
                                                    $currentItemColorHexForSwatch = $p_check['color_hex'];
                                                    $currentItemColorSwatchDisplay = 'inline-flex';
                                                    break;
                                                }
                                            }
                                        }
                                ?>
                                <div class="item-row">
                                    <div class="row mb-2">
                                        <!-- Kalem tipi artık gizli bir input olarak düzenlendi -->
                                        <input type="hidden" name="item_type[]" value="product" class="item-type">
                                        <div class="col-md-12">
                                            <label class="form-label">Ürün <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text item-color-swatch" style="display: <?php echo $currentItemColorSwatchDisplay; ?>;">
                                                    <span style="background-color: <?php echo htmlspecialchars($currentItemColorHexForSwatch); ?>;"></span>
                                                </span>
                                                <!-- YENİ: item-id-select sınıfı eklendi Select2 için -->
                                                <select class="form-select item-id item-id-select" name="item_id[]" required>
                                                    <option value="">Ürün Seçin</option>
                                                    <?php
                                                        foreach ($products as $product) {
                                                            $isSelected = ($product['id'] == $current_item_id) ? 'selected' : '';
                                                            // data-color-hex attribute'u Select2 tarafından kullanılacak
                                                            echo "<option value='{$product['id']}' data-price='{$product['price']}' data-tax='{$product['tax_rate']}' data-description='" . htmlspecialchars($product['name']) . "' data-color-hex='" . htmlspecialchars($product['color_hex'] ?? '') . "' {$isSelected}>" . htmlspecialchars($product['code'] . " - " . $product['name']) . " (" . number_format($product['price'], 2, ',', '.') . " ₺)</option>";
                                                        }
                                                    ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Açıklama, Miktar, Fiyat vs. -->
                                     <div class="row mb-2">
                                        <div class="col-md-12">
                                            <label class="form-label">Açıklama</label>
                                            <textarea class="form-control item-description" name="description[]" rows="2"><?php echo htmlspecialchars($current_description); ?></textarea>
                                        </div>
                                    </div>
                                    <div class="row mb-2">
                                        <div class="col-md-3">
                                            <label class="form-label">Miktar <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control item-quantity numeric-input" name="quantity[]" value="<?php echo htmlspecialchars($current_quantity); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Birim Fiyat <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control item-price numeric-input" name="unit_price[]" value="<?php echo htmlspecialchars($current_unit_price); ?>" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">İndirim %</label>
                                            <input type="text" class="form-control item-discount numeric-input" name="discount_percent[]" value="<?php echo htmlspecialchars($current_discount_percent); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">KDV %</label>
                                            <input type="text" class="form-control item-tax numeric-input" name="tax_rate[]" value="<?php echo htmlspecialchars($current_tax_rate); ?>">
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <button type="button" class="btn btn-danger w-100 remove-item">Kaldır</button>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                    }
                                }
                                ?>
                            </div>
                            <!-- Toplamlar -->
                            <div class="row mt-4">
                                <div class="col-md-7 offset-md-5">
                                    <div class="mb-2 d-flex justify-content-between"><strong>Ara Toplam:</strong> <span id="subtotal">0,00 ₺</span></div>
                                    <div class="mb-2 d-flex justify-content-between"><strong>İndirim:</strong> <span id="discount">0,00 ₺</span></div>
                                    <div class="mb-2 d-flex justify-content-between"><strong>KDV:</strong> <span id="tax">0,00 ₺</span></div>
                                    <hr class="my-1">
                                    <div class="mb-2 d-flex justify-content-between"><strong class="fs-5">Genel Toplam:</strong> <span id="total" class="fw-bold fs-5">0,00 ₺</span></div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="button" class="btn btn-secondary me-2" onclick="window.location.href='quotations.php'">İptal</button>
                            <button type="submit" class="btn btn-primary">Teklif Oluştur</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Item Template (hidden) -->
<template id="item-template">
    <div class="item-row">
        <div class="row mb-2">
            <!-- Kalem tipi artık gizli bir input olarak düzenlendi -->
            <input type="hidden" name="item_type[]" value="product" class="item-type">
            <div class="col-md-12">
                <label class="form-label">Ürün <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text item-color-swatch"><span></span></span>
                    <!-- YENİ: item-id-select sınıfı eklendi Select2 için -->
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
        <!-- Açıklama, Miktar, Fiyat vs. -->
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

<!-- ÖNEMLİ: Bu scriptler sayfanın en altında olmalı ve bu sırayla yüklenmeli -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    // Ürün verilerini JS'de kullanmak üzere
    const productsData = <?php echo json_encode($products); ?>;
    const servicesData = <?php echo json_encode($services); ?>; // Kullanılmıyor ama kalsın
    let bulkSelectModal;

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

    function formatCurrency(value) {
        const num = parseFloat(String(value).replace(/[^0-9.-]/g, '').replace(',', '.') || 0);
        return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 2 }).format(num);
    }
    
    function formatNumberForInput(value) {
        const num = parseFloat(String(value).replace(/[^0-9.-]/g, '').replace(',', '.') || 0);
        return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    
    function prepareNumberForServer(value) {
        return String(value).replace(/\./g, '').replace(',', '.');
    }

    document.addEventListener('DOMContentLoaded', function () {
        console.log("DOM fully loaded, setting up event handlers");
        
        // Bootstrap kontrolü
        if (typeof bootstrap === 'undefined') {
            console.error('Bootstrap JS yüklenemedi! Modal çalışmayacak.');
        } else {
            console.log('Bootstrap yüklendi: ', bootstrap.version);
            
            // Modal elementini al
            const modalElement = document.getElementById('bulkSelectModal');
            if (modalElement) {
                bulkSelectModal = new bootstrap.Modal(modalElement);
                console.log('Modal başarıyla başlatıldı');
            } else {
                console.error('Modal elementi bulunamadı!');
            }
        }
        
        const itemsContainer = document.getElementById('items-container');
        
        // Tek ürün ekleme butonunu bağla
        const addItemBtn = document.getElementById('addItemBtn');
        if (addItemBtn) {
            addItemBtn.addEventListener('click', function() {
                console.log('Tek ürün ekle butonuna tıklandı');
                addNewItem(itemsContainer);
            });
        }
        
        // Çoklu ürün seçme butonunu bağla
        const openBulkSelectBtn = document.getElementById('openBulkSelectBtn');
        if (openBulkSelectBtn) {
            openBulkSelectBtn.addEventListener('click', function() {
                console.log('Çoklu ürün seç butonuna tıklandı');
                
                if (bulkSelectModal) {
                    // Modalı açmadan önce seçimleri temizle
                    document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    
                    const selectAllCheckbox = document.getElementById('selectAllProducts');
                    if (selectAllCheckbox) selectAllCheckbox.checked = false;
                    
                    const selectedCountSpan = document.getElementById('selectedCount');
                    if (selectedCountSpan) selectedCountSpan.textContent = '0';
                    
                    const searchInput = document.getElementById('productSearchInput');
                    if (searchInput) searchInput.value = '';
                    
                    // Filtreyi temizle
                    document.querySelectorAll('.product-row').forEach(row => {
                        row.style.display = '';
                    });
                    
                    bulkSelectModal.show();
                } else {
                    console.error('Modal henüz başlatılmadı!');
                    alert('Çoklu ürün seçim modalı yüklenemedi. Lütfen sayfayı yenileyin veya yönetici ile iletişime geçin.');
                }
            });
        }
        
        // Mevcut item-row'lara event listener'ları bağla
        if (itemsContainer) {
            itemsContainer.querySelectorAll('.item-row').forEach(row => {
                attachEventListenersToRow(row);
                
                // Mevcut satırlardaki select2'leri başlat
                const itemIdSelect = row.querySelector('.item-id-select');
                if (itemIdSelect) {
                    initializeSelect2(itemIdSelect);
                }
            });
            
            // ÖNEMLİ DEĞİŞİKLİK: Başlangıçta otomatik item row eklemeyi kaldırıyoruz
            // Sadece form submitten sonra hata varsa item-row'ları tekrar gösteriyoruz
            // Bu, boş item-row eklenmemesini sağlar
        }

        updateTotals();
        updateTermsAndConditions();
        
        // Şartlar ve koşullar alanları için event listener'ları
        const termsRelatedFields = [
            'payment_terms', 'payment_percentage', 'delivery_days',
            // 'warranty_period', 'installation_included', // Bu alanlar kaldırıldı
            'transportation_included', 'custom_terms',
            'date', 'valid_until'
        ];
        
        termsRelatedFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                const eventType = (field.type === 'checkbox' || field.tagName === 'SELECT' || field.type === 'date') ? 'change' : 'input';
                field.addEventListener(eventType, updateTermsAndConditions);
            }
        });
        
        // Modal içi event listener'ları bağla
        setupModalEventListeners();
        setupBulkEditFeatures();
        
        // Her satırdaki miktar, fiyat, indirim, kdv değişiklikleri için event listener'ları ekle
        document.querySelectorAll('.product-table .product-row').forEach(row => {
            attachRowCalculationListeners(row);
        });
    });

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
                
                // Tümünü seç checkbox'ını sıfırla
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
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
                
                // Tümünü seç checkbox'ını sıfırla
                if (selectAllCheckbox) selectAllCheckbox.checked = false;
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
                    addSelectedProductsWithSettings(selectedProducts);
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
        
        // Numerik giriş kontrollerini ekle
        document.querySelectorAll('#bulkSelectModal .numeric-input').forEach(input => {
            input.addEventListener('focus', (e) => { 
                e.target.value = String(e.target.value).replace(/\./g, '').replace(',', '.'); 
            });
            
            input.addEventListener('blur', (e) => { 
                e.target.value = formatNumberForInput(e.target.value); 
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
            });
        });
    }

    function attachRowCalculationListeners(row) {
        // Satırdaki tüm sayısal giriş alanlarını bul
        const numericInputs = row.querySelectorAll('.numeric-input');
        
        // Her girişe değişiklik izleyicileri ekle
        numericInputs.forEach(input => {
            input.addEventListener('focus', (e) => { 
                e.target.value = String(e.target.value).replace(/\./g, '').replace(',', '.'); 
            });
            
            input.addEventListener('blur', (e) => { 
                e.target.value = formatNumberForInput(e.target.value);
                calculateRowTotal(row);
                calculateModalTotalAmount();
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
            });
        });
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

    function attachEventListenersToRow(rowElement) {
        const numericInputs = rowElement.querySelectorAll('.numeric-input');
        const removeButton = rowElement.querySelector('.remove-item');

        // İnput'lar için event listener'ları
        numericInputs.forEach(input => {
            input.addEventListener('focus', (e) => { e.target.value = String(e.target.value).replace(/\./g, '').replace(',', '.'); });
            input.addEventListener('blur', (e) => { e.target.value = formatNumberForInput(e.target.value); updateTotals(); });
            input.addEventListener('input', (e) => {
                const caretPosition = e.target.selectionStart; const originalValue = e.target.value;
                let value = e.target.value.replace(/[^0-9,.]/g, '');
                let commaCount = (value.match(/,/g) || []).length;
                if (commaCount > 1) { const firstCommaIndex = value.indexOf(','); value = value.substring(0, firstCommaIndex + 1) + value.substring(firstCommaIndex + 1).replace(/,/g, '');}
                e.target.value = value;
                if (e.target.value !== originalValue) { if (caretPosition > 0) e.target.setSelectionRange(caretPosition -1, caretPosition -1); }
                updateTotals();
            });
        });
        
        // Sil butonu için event listener
        if (removeButton) removeButton.addEventListener('click', function () {
            // Select2'yi kaldır (destroy)
            const select2Element = this.closest('.item-row').querySelector('.item-id-select');
            if (select2Element && $(select2Element).data('select2')) {
                $(select2Element).select2('destroy');
            }
            this.closest('.item-row').remove();
            
            const itemsContainer = document.getElementById('items-container');
            if (itemsContainer && itemsContainer.children.length === 0) { 
                // Kalemlerin hepsi silinirse bir şey yapma
                // Otomatik yenisini eklemeyi kaldırıyoruz
            }
            updateTotals();
        });
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

    function handleItemIdChange(itemIdSelectElement) { // itemIdSelectElement artık DOM select elementi
        const selectedOption = itemIdSelectElement.options[itemIdSelectElement.selectedIndex];
        const row = itemIdSelectElement.closest('.item-row');
        const priceInput = row.querySelector('.item-price');
        const taxInput = row.querySelector('.item-tax');
        const descInput = row.querySelector('.item-description');
        const colorSwatchContainer = row.querySelector('.item-color-swatch');
        const colorSwatchSpan = colorSwatchContainer ? colorSwatchContainer.querySelector('span') : null;

        if (selectedOption && selectedOption.value) {
            priceInput.value = formatNumberForInput(selectedOption.dataset.price);
            taxInput.value = formatNumberForInput(selectedOption.dataset.tax);
            descInput.value = selectedOption.dataset.description;

            if (colorSwatchContainer && colorSwatchSpan && selectedOption.dataset.colorHex && /^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/.test(selectedOption.dataset.colorHex)) {
                colorSwatchSpan.style.backgroundColor = selectedOption.dataset.colorHex;
                colorSwatchContainer.style.display = 'inline-flex';
            } else if (colorSwatchContainer) {
                colorSwatchContainer.style.display = 'none';
            }
        } else { // Seçim yoksa veya "Önce tür seçin" seçiliyse
            priceInput.value = formatNumberForInput(0);
            taxInput.value = formatNumberForInput(18); // Varsayılan KDV
            descInput.value = '';
            if (colorSwatchContainer) colorSwatchContainer.style.display = 'none';
        }
        updateTotals();
    }

    function updateSelectedCount() {
        const selectedCount = document.querySelectorAll('.product-checkbox:checked').length;
        const selectedCountSpan = document.getElementById('selectedCount');
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedCount;
        }
    }
    
    function addSelectedProductsWithSettings(selectedProducts) {
        const itemsContainer = document.getElementById('items-container');
        if (!itemsContainer) {
            console.error('Items container not found!');
            return;
        }
        
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
        
        document.querySelectorAll('.item-row').forEach(row => {
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
    
    function updateTermsAndConditions() {
        const paymentTermsSelect = document.getElementById('payment_terms');
        if (!paymentTermsSelect) return;
        
        const paymentTermsText = paymentTermsSelect.options[paymentTermsSelect.selectedIndex].text;
        const paymentPercentage = document.getElementById('payment_percentage').value;
        const deliveryDays = document.getElementById('delivery_days').value;
        // Garanti süresi ve kurulum dahil alanlarını kaldırdık
        // const warrantyPeriod = document.getElementById('warranty_period').value;
        // const installationIncluded = document.getElementById('installation_included').checked;
        const transportationIncluded = document.getElementById('transportation_included').checked;
        const customTerms = document.getElementById('custom_terms').value;
        
        let termsText = '';
        const validUntilDateInput = document.getElementById('valid_until').value;
        
        if (validUntilDateInput) {
            const dateParts = validUntilDateInput.split('-');
            const formattedDate = `${dateParts[2]}.${dateParts[1]}.${dateParts[0]}`;
            termsText += `Teklif Geçerlilik Tarihi: ${formattedDate}\n`;
        }
        
        const paymentTermValue = paymentTermsSelect.value;
        if (paymentTermValue === 'advance_payment') {
            termsText += `Ödeme Şartları: ${paymentTermsText} (%100).\n`;
        } else if (paymentTermValue === 'partial_payment') {
            termsText += `Ödeme Şartları: ${paymentTermsText} (%${paymentPercentage} peşin, %${100 - parseInt(paymentPercentage)} teslimat öncesi).\n`;
        } else {
            termsText += `Ödeme Şartları: ${paymentTermsText}.\n`;
            if (paymentTermValue === 'installment' && paymentPercentage > 0 && paymentPercentage < 100) {
                termsText = termsText.trim() + ` (%${paymentPercentage} peşin, kalanı taksitlendirilecektir).\n`;
            }
        }
        
        termsText += `Teslimat Süresi: Sipariş onayından itibaren ${deliveryDays} iş günüdür.\n`;
        
        // Garanti süresi kısmını kaldırdık
        /*
        if (warrantyPeriod.trim() !== '') {
            termsText += `Garanti Süresi: ${warrantyPeriod}\n`;
        }
        */
        
        let additionalServices = [];
        // Kurulum dahil kısmını kaldırdık
        // if (installationIncluded) additionalServices.push('Kurulum');
        if (transportationIncluded) additionalServices.push('Nakliye');
        
        if (additionalServices.length > 0) {
            termsText += `Fiyata Dahil Olan Hizmetler: ${additionalServices.join(' ve ')}\n`;
        } else {
            termsText += `Fiyata Nakliye dahil değildir.\n`;
        }
        
        if (customTerms.trim() !== '') {
            termsText += '\nDiğer Şartlar:\n' + customTerms;
        }
        
        const termsConditionsInput = document.getElementById('terms_conditions');
        if (termsConditionsInput) {
            termsConditionsInput.value = termsText.trim();
        }
    }
</script>

</body>
</html>