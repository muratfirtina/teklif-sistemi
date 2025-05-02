<?php
// new_quotation.php - Yeni teklif oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/page_access_control.php'; // Üretim rolü kontrolü için

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

$preselected_customer_id = 0; // Varsayılan olarak seçili değil
if (isset($_GET['customer_id']) && is_numeric($_GET['customer_id'])) {
    $preselected_customer_id = intval($_GET['customer_id']);
}
// Müşterileri getir
$customers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Ürünleri getir
$products = [];
try {
    $stmt = $conn->query("SELECT id, code, name, price, tax_rate, stock_quantity FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Ürün listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Hizmetleri getir - Geçici olarak devre dışı bırakıldı
$services = [];
/*try {
    $stmt = $conn->query("SELECT id, code, name, price, tax_rate FROM services ORDER BY name ASC");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Hizmet listesi alınırken bir hata oluştu: ' . $e->getMessage());
}*/

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Teklif temel bilgileri
    $customer_id = $_POST['customer_id'];
    $date = $_POST['date'];
    $valid_until = $_POST['valid_until'];
    $notes = $_POST['notes'];
    $terms_conditions = $_POST['terms_conditions'];

    // Dinamik şartlar için ek bilgiler
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

    // Basit doğrulama
    $errors = [];

    if (empty($customer_id)) {
        $errors[] = "Müşteri seçilmelidir.";
    }

    if (empty($date)) {
        $errors[] = "Teklif tarihi girilmelidir.";
    }

    if (empty($valid_until)) {
        $errors[] = "Geçerlilik tarihi girilmelidir.";
    }

    if (empty($item_types)) {
        $errors[] = "En az bir kalem eklenmelidir.";
    }

    // Hata yoksa veritabanına ekle
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Teklif referans numarası oluştur (Yıl-Ay-Sıra No)
            $year = date('Y');
            $month = date('m');

            $stmt = $conn->query("SELECT COUNT(*) FROM quotations WHERE YEAR(date) = $year AND MONTH(date) = $month");
            $count = $stmt->fetchColumn();
            $sequence = $count + 1;

            $prefix = "TEK"; // Varsayılan değer
            try {
                $prefixStmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'quotation_prefix'");
                if ($prefixStmt->rowCount() > 0) {
                    $prefix = $prefixStmt->fetchColumn();
                }
            } catch (PDOException $e) {
                error_log("Teklif öneki alınırken hata: " . $e->getMessage());
            }

            $reference_no = sprintf("%s-%s-%s-%03d", $prefix, $year, $month, $sequence);

            // Toplam tutarları hesapla
            $subtotal = 0;
            $tax_amount = 0;
            $discount_amount = 0;
            $total_amount = 0;

            for ($i = 0; $i < count($item_types); $i++) {
                $quantity = floatval($quantities[$i]);
                $unit_price = floatval($unit_prices[$i]);
                $discount_percent = floatval($discount_percents[$i]);
                $tax_rate = floatval($tax_rates[$i]);

                $line_subtotal = $quantity * $unit_price;
                $line_discount = $line_subtotal * ($discount_percent / 100);
                $line_subtotal_after_discount = $line_subtotal - $line_discount;
                $line_tax = $line_subtotal_after_discount * ($tax_rate / 100);

                $subtotal += $line_subtotal;
                $discount_amount += $line_discount;
                $tax_amount += $line_tax;
            }

            $total_amount = $subtotal - $discount_amount + $tax_amount;

            // Teklifi ekle
            $stmt = $conn->prepare("INSERT INTO quotations 
                                    (reference_no, customer_id, user_id, date, valid_until, 
                                     status, subtotal, tax_amount, discount_amount, total_amount, 
                                     notes, terms_conditions) 
                                    VALUES 
                                    (:reference_no, :customer_id, :user_id, :date, :valid_until, 
                                     'draft', :subtotal, :tax_amount, :discount_amount, :total_amount, 
                                     :notes, :terms_conditions)");

            $user_id = $_SESSION['user_id'];

            $stmt->bindParam(':reference_no', $reference_no);
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':tax_amount', $tax_amount);
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':total_amount', $total_amount);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':terms_conditions', $terms_conditions);

            $stmt->execute();

            $quotation_id = $conn->lastInsertId();

            // Teklif kalemlerini ekle
            for ($i = 0; $i < count($item_types); $i++) {
                $item_type = $item_types[$i];
                $item_id = $item_ids[$i];
                $description = $descriptions[$i];
                $quantity = floatval($quantities[$i]);
                $unit_price = floatval($unit_prices[$i]);
                $discount_percent = floatval($discount_percents[$i]);
                $tax_rate = floatval($tax_rates[$i]);

                $line_subtotal = ($quantity * $unit_price) * (1 - ($discount_percent / 100));

                $stmt = $conn->prepare("INSERT INTO quotation_items 
                                        (quotation_id, item_type, item_id, description, 
                                         quantity, unit_price, discount_percent, tax_rate, subtotal) 
                                        VALUES 
                                        (:quotation_id, :item_type, :item_id, :description, 
                                         :quantity, :unit_price, :discount_percent, :tax_rate, :subtotal)");

                $stmt->bindParam(':quotation_id', $quotation_id);
                $stmt->bindParam(':item_type', $item_type);
                $stmt->bindParam(':item_id', $item_id);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':quantity', $quantity);
                $stmt->bindParam(':unit_price', $unit_price);
                $stmt->bindParam(':discount_percent', $discount_percent);
                $stmt->bindParam(':tax_rate', $tax_rate);
                $stmt->bindParam(':subtotal', $line_subtotal);

                $stmt->execute();
            }

            // Teklif şartlarını ekle - Hata alınan yer
            try {
                // Tablo varlığını kontrol et
                $stmt = $conn->query("SHOW TABLES LIKE 'quotation_terms'");
                $tableExists = $stmt->rowCount() > 0;

                if (!$tableExists) {
                    // Tablo yoksa oluştur
                    $conn->exec("CREATE TABLE quotation_terms (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        quotation_id INT NOT NULL,
                        payment_terms VARCHAR(255),
                        payment_percentage INT DEFAULT 50,
                        delivery_days INT DEFAULT 10,
                        warranty_period VARCHAR(100),
                        installation_included BOOLEAN DEFAULT FALSE,
                        transportation_included BOOLEAN DEFAULT FALSE,
                        custom_terms TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                }

                // Teklif şartlarını ekle
                $stmt = $conn->prepare("INSERT INTO quotation_terms 
                                      (quotation_id, payment_terms, payment_percentage, delivery_days, 
                                       warranty_period, installation_included, transportation_included, 
                                       custom_terms)
                                      VALUES 
                                      (:quotation_id, :payment_terms, :payment_percentage, :delivery_days, 
                                       :warranty_period, :installation_included, :transportation_included, 
                                       :custom_terms)");

                $stmt->bindParam(':quotation_id', $quotation_id);
                $stmt->bindParam(':payment_terms', $payment_terms);
                $stmt->bindParam(':payment_percentage', $payment_percentage);
                $stmt->bindParam(':delivery_days', $delivery_days);
                $stmt->bindParam(':warranty_period', $warranty_period);
                $stmt->bindParam(':installation_included', $installation_included);
                $stmt->bindParam(':transportation_included', $transportation_included);
                $stmt->bindParam(':custom_terms', $custom_terms);
                $stmt->execute();
            } catch (PDOException $e) {
                // Şartlar eklenirken hata olursa logla ama işlemi durdurmayalım
                error_log("Teklif şartları eklenirken hata oluştu: " . $e->getMessage());
            }

            $conn->commit();

            setMessage('success', 'Teklif başarıyla oluşturuldu.');
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
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    .item-row {
        border: 1px solid #dee2e6;
        /* İnce bir kenarlık ekle */
        padding: 15px;
        /* İç boşluk ver */
        margin-bottom: 15px;
        /* Altına boşluk ekle (kalemler arasına) */
        border-radius: 0.375rem;
        /* Köşeleri hafif yuvarla (Bootstrap standardı) */
        background-color: #f8f9fa;
        /* Opsiyonel: Hafif bir arka plan rengi */
    }

    /* Opsiyonel: Kaldır butonunu biraz daha belirginleştirmek için */
    .item-row .remove-item {
        margin-top: 5px;
        /* Diğer inputlarla hizalamak için küçük bir üst boşluk */
    }

    /* Opsiyonel: Kalem satırı içindeki label'lara biraz alt boşluk vermek */
    .item-row .form-label {
        margin-bottom: 0.25rem;
    }
</style>

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
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="row">
                <!-- Sol Sütun - Temel Bilgiler -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Teklif Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="customer_id" class="form-label">Müşteri <span
                                        class="text-danger">*</span></label>
                                <select class="form-select" id="customer_id" name="customer_id" required>
                                    <option value="">Müşteri Seçin</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <?php
                                        // Seçili olup olmadığını kontrol et
                                        // Öncelik: URL parametresi, sonra POST'tan gelen (hata durumu)
                                        $isSelected = false;
                                        if ($preselected_customer_id > 0 && $customer['id'] == $preselected_customer_id) {
                                            $isSelected = true;
                                        } elseif (isset($customer_id) && $customer['id'] == $customer_id) { // Hata durumunda POST'tan geleni koru
                                            $isSelected = true;
                                        }
                                        ?>
                                        <option value="<?php echo $customer['id']; ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="date" class="form-label">Teklif Tarihi <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" required
                                    value="<?php echo isset($date) ? $date : date('Y-m-d'); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="valid_until" class="form-label">Geçerlilik Tarihi <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required
                                    value="<?php echo isset($valid_until) ? $valid_until : date('Y-m-d', strtotime('+30 days')); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes"
                                    rows="3"><?php echo isset($notes) ? htmlspecialchars($notes) : ''; ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="terms_conditions" class="form-label">Şartlar ve Koşullar</label>
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Standart Şartlar</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_terms" class="form-label">Ödeme Koşulları</label>
                                                <select class="form-select" id="payment_terms" name="payment_terms">
                                                    <option value="advance_payment">Peşin Ödeme</option>
                                                    <option value="partial_payment" selected>Kısmi Ödeme</option>
                                                    <option value="payment_on_delivery">Teslimat Sonrası Ödeme</option>
                                                    <option value="installment">Taksitli Ödeme</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="payment_percentage" class="form-label">Peşinat Yüzdesi
                                                    (%)</label>
                                                <input type="number" class="form-control" id="payment_percentage"
                                                    name="payment_percentage" min="0" max="100" value="50">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="delivery_days" class="form-label">Teslimat Süresi
                                                    (Gün)</label>
                                                <input type="number" class="form-control" id="delivery_days"
                                                    name="delivery_days" min="1" value="10">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="warranty_period" class="form-label">Garanti Süresi</label>
                                                <input type="text" class="form-control" id="warranty_period"
                                                    name="warranty_period" value="12 Ay">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                        id="installation_included" name="installation_included"
                                                        value="1">
                                                    <label class="form-check-label" for="installation_included">
                                                        Kurulum Dahil
                                                    </label>
                                                </div>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                        id="transportation_included" name="transportation_included"
                                                        value="1">
                                                    <label class="form-check-label" for="transportation_included">
                                                        Nakliye Dahil
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> Aşağıdaki metin, standart şartlar dışında teklif
                                    şartlarını belirtmek için kullanılabilir. Yukarıdaki ayarlar metne otomatik olarak
                                    eklenir.
                                </div>
                                <textarea class="form-control" id="custom_terms" name="custom_terms"
                                    rows="4"><?php echo isset($custom_terms) ? htmlspecialchars($custom_terms) : ''; ?></textarea>
                                <input type="hidden" id="terms_conditions" name="terms_conditions"
                                    value="<?php echo isset($terms_conditions) ? htmlspecialchars($terms_conditions) : ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sağ Sütun - Teklif Kalemleri -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Teklif Kalemleri</h5>
                            <button type="button" class="btn btn-success btn-sm" id="addItemBtn">
                                <i class="bi bi-plus-circle"></i> Kalem Ekle
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="items-container">
                                <!-- Javascript ile dinamik olarak kalemler eklenecek -->
                            </div>

                            <div class="row mt-4">
                                <div class="col-md-8">
                                    <!-- Boş alan -->
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>Ara Toplam:</strong>
                                        <span id="subtotal">0,00 ₺</span>
                                    </div>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>İndirim:</strong>
                                        <span id="discount">0,00 ₺</span>
                                    </div>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>KDV:</strong>
                                        <span id="tax">0,00 ₺</span>
                                    </div>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>Genel Toplam:</strong>
                                        <span id="total" class="fw-bold">0,00 ₺</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="button" class="btn btn-secondary me-md-2"
                                    onclick="window.location.href='quotations.php'">İptal</button>
                                <button type="submit" class="btn btn-primary">Teklif Oluştur</button>
                            </div>
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
            <div class="col-md-6">
                <label class="form-label">Kalem Tipi <span class="text-danger">*</span></label>
                <select class="form-select item-type" name="item_type[]" required>
                    <option value="">Seçin</option>
                    <option value="product">Ürün</option>
                    <!-- <option value="service">Hizmet</option> -->
                    <!-- Hizmet seçeneği geçici olarak kapalı -->
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Ürün/Hizmet <span class="text-danger">*</span></label>
                <select class="form-select item-id" name="item_id[]" required disabled>
                    <option value="">Önce tür seçin</option>
                </select>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-12">
                <label class="form-label">Açıklama</label>
                <textarea class="form-control item-description" name="description[]" rows="2"></textarea>
            </div>
        </div>
        <div class="row mb-2">
            <div class="col-md-3">
                <label class="form-label">Miktar <span class="text-danger">*</span></label>
                <input type="number" class="form-control item-quantity" name="quantity[]" min="1" value="1" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Birim Fiyat <span class="text-danger">*</span></label>
                <input type="number" class="form-control item-price" name="unit_price[]" step="0.01" min="0"
                    value="0.00" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">İndirim %</label>
                <input type="number" class="form-control item-discount" name="discount_percent[]" step="0.01" min="0"
                    max="100" value="0">
            </div>
            <div class="col-md-2">
                <label class="form-label">KDV %</label>
                <input type="number" class="form-control item-tax" name="tax_rate[]" step="0.01" min="0" value="18">
            </div>
            <div class="col-md-2">
                <label class="form-label">İşlem</label>
                <button type="button" class="btn btn-danger w-100 remove-item">Kaldır</button>
            </div>
        </div>
    </div>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Ürün ve Hizmet verileri
    const products = <?php echo json_encode($products); ?>;
    const services = <?php echo json_encode($services); ?>;

    document.addEventListener('DOMContentLoaded', function () {
        // Yeni kalem ekleme düğmesi
        document.getElementById('addItemBtn').addEventListener('click', addNewItem);

        // İlk kalemi otomatik ekle
        addNewItem();

        // Toplam hesaplama
        updateTotals();
    });

    // Yeni kalem ekle
    function addNewItem() {
        const template = document.getElementById('item-template');
        const container = document.getElementById('items-container');

        // Klon oluştur
        const clone = document.importNode(template.content, true);

        // Kalem türü değiştiğinde
        clone.querySelector('.item-type').addEventListener('change', function () {
            const itemType = this.value;
            const itemIdSelect = this.closest('.item-row').querySelector('.item-id');

            // Önceki seçenekleri temizle
            itemIdSelect.innerHTML = '<option value="">Seçin</option>';

            if (itemType === 'product') {
                // Ürünleri yükle
                products.forEach(product => {
                    const option = document.createElement('option');
                    option.value = product.id;
                    option.textContent = `${product.code} - ${product.name} (${formatCurrency(product.price)})`;
                    option.dataset.price = product.price;
                    option.dataset.tax = product.tax_rate;
                    option.dataset.description = product.name;
                    itemIdSelect.appendChild(option);
                });
                itemIdSelect.disabled = false;
            } else if (itemType === 'service') {
                // Hizmetleri yükle - Geçici olarak devre dışı bırakıldı
                /*services.forEach(service => {
                    const option = document.createElement('option');
                    option.value = service.id;
                    option.textContent = `${service.code} - ${service.name} (${formatCurrency(service.price)})`;
                    option.dataset.price = service.price;
                    option.dataset.tax = service.tax_rate;
                    option.dataset.description = service.name;
                    itemIdSelect.appendChild(option);
                });*/
                // Hizmet seçimi şu an kullanılamaz
                const option = document.createElement('option');
                option.value = "";
                option.textContent = "Hizmet seçimi şu an kullanılamaz";
                itemIdSelect.appendChild(option);
                itemIdSelect.disabled = false;
            } else {
                itemIdSelect.disabled = true;
            }
        });

        // Ürün/Hizmet seçildiğinde
        clone.querySelector('.item-id').addEventListener('change', function () {
            const selectedOption = this.options[this.selectedIndex];
            const row = this.closest('.item-row');

            if (selectedOption.value) {
                row.querySelector('.item-price').value = selectedOption.dataset.price;
                row.querySelector('.item-tax').value = selectedOption.dataset.tax;
                row.querySelector('.item-description').value = selectedOption.dataset.description;
            }

            updateTotals();
        });

        // Fiyat, miktar, indirim veya vergi değiştiğinde toplam hesapla
        const inputs = clone.querySelectorAll('.item-quantity, .item-price, .item-discount, .item-tax');
        inputs.forEach(input => {
            input.addEventListener('input', updateTotals);
        });

        // Kaldır düğmesi
        clone.querySelector('.remove-item').addEventListener('click', function () {
            const itemRow = this.closest('.item-row');
            itemRow.remove();
            updateTotals();
        });

        // Konteynere ekle
        container.appendChild(clone);
    }

    // Toplamları güncelle
    function updateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;

        // Tüm kalemleri dolaş
        document.querySelectorAll('.item-row').forEach(row => {
            const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
            const price = parseFloat(row.querySelector('.item-price').value) || 0;
            const discountPercent = parseFloat(row.querySelector('.item-discount').value) || 0;
            const taxPercent = parseFloat(row.querySelector('.item-tax').value) || 0;

            const lineSubtotal = quantity * price;
            const lineDiscount = lineSubtotal * (discountPercent / 100);
            const lineSubtotalAfterDiscount = lineSubtotal - lineDiscount;
            const lineTax = lineSubtotalAfterDiscount * (taxPercent / 100);

            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;
        });

        const total = subtotal - totalDiscount + totalTax;

        // Değerleri güncelle
        document.getElementById('subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('discount').textContent = formatCurrency(totalDiscount);
        document.getElementById('tax').textContent = formatCurrency(totalTax);
        document.getElementById('total').textContent = formatCurrency(total);
    }

    // Para formatı
    function formatCurrency(value) {
        return new Intl.NumberFormat('tr-TR', {
            style: 'currency',
            currency: 'TRY',
            minimumFractionDigits: 2
        }).format(value);
    }
    document.addEventListener('DOMContentLoaded', function () {
        // Şartlar ve koşullar dinamik güncelleme
        function updateTermsAndConditions() {
            const paymentTerms = document.getElementById('payment_terms').value;
            const paymentPercentage = document.getElementById('payment_percentage').value;
            const deliveryDays = document.getElementById('delivery_days').value;
            const warrantyPeriod = document.getElementById('warranty_period').value;
            const installationIncluded = document.getElementById('installation_included').checked;
            const transportationIncluded = document.getElementById('transportation_included').checked;
            const customTerms = document.getElementById('custom_terms').value;

            let termsText = ' Geçerlilik: Bu teklif 30 gün geçerlidir.\n';
            termsText += ' Fiyatlara KDV dahildir.\n';

            // Ödeme koşulları
            if (paymentTerms === 'advance_payment') {
                termsText += ' Ödeme şartları: %100 peşin ödeme.\n';
            } else if (paymentTerms === 'partial_payment') {
                termsText += ` Ödeme şartları: %${paymentPercentage} peşin, %${100 - paymentPercentage} teslimat öncesi.\n`;
            } else if (paymentTerms === 'payment_on_delivery') {
                termsText += ' Ödeme şartları: Teslimat sırasında tam ödeme.\n';
            } else if (paymentTerms === 'installment') {
                termsText += ` Ödeme şartları: %${paymentPercentage} peşin, kalan tutar 3 eşit taksitte.\n`;
            }

            // Teslimat süresi
            termsText += ` Teslimat süresi: Sipariş onayından itibaren ${deliveryDays} iş günüdür.\n`;

            // Garanti
            if (warrantyPeriod) {
                termsText += ` Garanti süresi: ${warrantyPeriod}\n`;
            }

            // Kurulum ve Nakliye
            let additionalServices = [];
            if (installationIncluded) additionalServices.push('kurulum');
            if (transportationIncluded) additionalServices.push('nakliye');

            if (additionalServices.length > 0) {
                termsText += ` Fiyata dahil olan hizmetler: ${additionalServices.join(' ve ')}\n`;
            }

            // Özel şartlar
            if (customTerms.trim() !== '') {
                termsText += '\n' + customTerms;
            }

            document.getElementById('terms_conditions').value = termsText;
        }

        // İlk yüklendiğinde şartları oluştur
        updateTermsAndConditions();

        // Form alanları değiştiğinde şartları güncelle
        document.getElementById('payment_terms').addEventListener('change', updateTermsAndConditions);
        document.getElementById('payment_percentage').addEventListener('input', updateTermsAndConditions);
        document.getElementById('delivery_days').addEventListener('input', updateTermsAndConditions);
        document.getElementById('warranty_period').addEventListener('input', updateTermsAndConditions);
        document.getElementById('installation_included').addEventListener('change', updateTermsAndConditions);
        document.getElementById('transportation_included').addEventListener('change', updateTermsAndConditions);
        document.getElementById('custom_terms').addEventListener('input', updateTermsAndConditions);
    });

</script>
</body>

</html>