<?php
// edit_quotation.php - Teklif düzenleme sayfası (Tavsiye Edilen Yaklaşım)
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
$id = intval($_GET['id']); // $quotation_id yerine $id kullanalım (daha kısa)

$conn = getDbConnection();

// Teklif sahibi mi kontrol et
try {
    $stmt = $conn->prepare("SELECT user_id FROM quotations WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }

    $quotationCheck = $stmt->fetch(PDO::FETCH_ASSOC);

    // Teklifi sadece sahibi veya admin düzenleyebilir
    if ($quotationCheck['user_id'] != $_SESSION['user_id'] && !isAdmin()) {
        setMessage('error', 'Bu teklifi düzenleme yetkiniz bulunmamaktadır.');
        header("Location: quotations.php");
        exit;
    }
} catch (PDOException $e) {
    setMessage('error', 'Yetki kontrolünde hata: ' . $e->getMessage());
    header("Location: quotations.php");
    exit;
}

// Teklif ve kalemlerini çek
$quotation = null;
$items = [];
try {
    $stmt = $conn->prepare("SELECT * FROM quotations WHERE id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sadece taslak veya gönderilmiş teklifler düzenlenebilir mi kontrolü (isteğe bağlı)
    // if (!in_array($quotation['status'], ['draft', 'sent'])) {
    //     setMessage('error', 'Sadece taslak veya gönderilmiş durumdaki teklifler düzenlenebilir.');
    //     header("Location: view_quotation.php?id=" . $id);
    //     exit;
    // }

    $stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = :id ORDER BY id ASC");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    setMessage('error', 'Teklif bilgileri alınırken hata oluştu: ' . $e->getMessage());
    header("Location: quotations.php");
    exit;
}

// Müşterileri, Ürünleri, Hizmetleri getir (dropdown'lar için)
$customers = [];
$products = [];
$services = [];
try {
    $customers = $conn->query("SELECT id, name FROM customers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $products = $conn->query("SELECT id, code, name, price, tax_rate FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Hizmetler geçici olarak devre dışı bırakıldı
    // $services = $conn->query("SELECT id, code, name, price, tax_rate FROM services ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    setMessage('error', 'Dropdown verileri alınırken hata oluştu: ' . $e->getMessage());
    // Hata durumunda boş dizilerle devam edebilir veya işlemi durdurabiliriz.
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Teklif temel bilgileri
    $customer_id = $_POST['customer_id'];
    $date = $_POST['date'];
    $valid_until = $_POST['valid_until'];
    $notes = $_POST['notes'];
    $terms_conditions = $_POST['terms_conditions'];
    $status = $_POST['status']; // Durumu da alalım

    // Kalemler
    $item_types = $_POST['item_type'] ?? [];
    $item_ids = $_POST['item_id'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_prices = $_POST['unit_price'] ?? []; // Virgüllü gelebilir
    $discount_percents = $_POST['discount_percent'] ?? []; // Virgüllü gelebilir
    $tax_rates = $_POST['tax_rate'] ?? []; // Virgüllü gelebilir

    // Doğrulama
    $errors = [];
    if (empty($customer_id))
        $errors[] = "Müşteri seçilmelidir.";
    if (empty($date))
        $errors[] = "Teklif tarihi girilmelidir.";
    if (empty($valid_until))
        $errors[] = "Geçerlilik tarihi girilmelidir.";
    if (empty($status))
        $errors[] = "Durum seçilmelidir."; // Durum zorunlu olsun
    if (empty($item_types))
        $errors[] = "En az bir kalem eklenmelidir.";
    // Diğer kalem bazlı doğrulamalar eklenebilir (örn. miktar > 0)

    // Hata yoksa güncelle
    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            // Toplamları hesapla (virgülleri noktaya çevirerek)
            $subtotal = 0;
            $tax_amount = 0;
            $discount_amount = 0;
            $total_amount = 0;
            for ($i = 0; $i < count($item_types); $i++) {
                // Virgülü noktaya çevir
                $q = floatval(str_replace(',', '.', $quantities[$i] ?? '0'));
                $up = floatval(str_replace(',', '.', $unit_prices[$i] ?? '0'));
                $dp = floatval(str_replace(',', '.', $discount_percents[$i] ?? '0'));
                $tr = floatval(str_replace(',', '.', $tax_rates[$i] ?? '0'));

                if ($q <= 0 || $up < 0)
                    continue; // Geçersiz miktar veya fiyatı atla

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
            $stmt = $conn->prepare("UPDATE quotations SET
                                    customer_id = :customer_id,
                                    date = :date,
                                    valid_until = :valid_until,
                                    status = :status, -- Durumu da güncelle
                                    subtotal = :subtotal,
                                    tax_amount = :tax_amount,
                                    discount_amount = :discount_amount,
                                    total_amount = :total_amount,
                                    notes = :notes,
                                    terms_conditions = :terms_conditions
                                WHERE id = :id");
            $stmt->bindParam(':customer_id', $customer_id);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':valid_until', $valid_until);
            $stmt->bindParam(':status', $status); // Status parametresi eklendi
            $stmt->bindParam(':subtotal', $subtotal);
            $stmt->bindParam(':tax_amount', $tax_amount);
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':total_amount', $total_amount);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':terms_conditions', $terms_conditions);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            // Eski kalemleri sil
            $stmt = $conn->prepare("DELETE FROM quotation_items WHERE quotation_id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            // Yeni kalemleri ekle (virgülleri noktaya çevirerek)
            for ($i = 0; $i < count($item_types); $i++) {
                $item_type = $item_types[$i];
                $item_id = $item_ids[$i];
                $description = $descriptions[$i];
                // Virgülü noktaya çevir
                $quantity = floatval(str_replace(',', '.', $quantities[$i] ?? '0'));
                $unit_price = floatval(str_replace(',', '.', $unit_prices[$i] ?? '0'));
                $discount_percent = floatval(str_replace(',', '.', $discount_percents[$i] ?? '0'));
                $tax_rate = floatval(str_replace(',', '.', $tax_rates[$i] ?? '0'));

                // Sadece geçerli verileri ekle
                if (empty($item_type) || empty($item_id) || $quantity <= 0 || $unit_price < 0) {
                    continue;
                }

                $line_subtotal = ($quantity * $unit_price) * (1 - ($discount_percent / 100));

                $stmt = $conn->prepare("INSERT INTO quotation_items
                                        (quotation_id, item_type, item_id, description,
                                         quantity, unit_price, discount_percent, tax_rate, subtotal)
                                        VALUES
                                        (:quotation_id, :item_type, :item_id, :description,
                                         :quantity, :unit_price, :discount_percent, :tax_rate, :subtotal)");

                $stmt->bindParam(':quotation_id', $id); // Burası önemli: $id
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


            $conn->commit();
            setMessage('success', 'Teklif başarıyla güncellendi.');
            header("Location: view_quotation.php?id=" . $id);
            exit;

        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    // Hata varsa veya POST değilse, formda gösterilecek değerleri ayarla (mevcut değerler)
    $quotation['customer_id'] = $_POST['customer_id'] ?? $quotation['customer_id'];
    $quotation['date'] = $_POST['date'] ?? $quotation['date'];
    $quotation['valid_until'] = $_POST['valid_until'] ?? $quotation['valid_until'];
    $quotation['notes'] = $_POST['notes'] ?? $quotation['notes'];
    $quotation['terms_conditions'] = $_POST['terms_conditions'] ?? $quotation['terms_conditions'];
    $quotation['status'] = $_POST['status'] ?? $quotation['status'];
    // Kalemler hata durumunda tekrar doldurulamaz, mevcutlar PHP ile basılacak.
}


$pageTitle = 'Teklif Düzenle';
$currentPage = 'quotations';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    /* Kalem ayırma stili */
    .item-row {
        border: 1px solid #dee2e6;
        padding: 15px;
        margin-bottom: 15px;
        border-radius: 0.375rem;
        background-color: #f8f9fa;
    }

    .item-row .remove-item {
        margin-top: 5px;
    }

    .item-row .form-label {
        margin-bottom: 0.25rem;
    }
</style>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Teklif Düzenle: <?php echo htmlspecialchars($quotation['reference_no']); ?></h1>
            <a href="view_quotation.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Teklife Dön
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

        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $id); ?>">
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
                                        <option value="<?php echo $customer['id']; ?>" <?php echo ($quotation['customer_id'] == $customer['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="date" class="form-label">Teklif Tarihi <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="date" name="date" required
                                    value="<?php echo $quotation['date']; ?>">
                            </div>
                            <div class="mb-3">
                                <label for="valid_until" class="form-label">Geçerlilik Tarihi <span
                                        class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="valid_until" name="valid_until" required
                                    value="<?php echo $quotation['valid_until']; ?>">
                            </div>
                            <div class="mb-3">
                                <label for="status" class="form-label">Durum <span class="text-danger">*</span></label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="draft" <?php echo $quotation['status'] == 'draft' ? 'selected' : ''; ?>>Taslak</option>
                                    <option value="sent" <?php echo $quotation['status'] == 'sent' ? 'selected' : ''; ?>>
                                        Gönderildi</option>
                                    <option value="accepted" <?php echo $quotation['status'] == 'accepted' ? 'selected' : ''; ?>>Kabul Edildi</option>
                                    <option value="rejected" <?php echo $quotation['status'] == 'rejected' ? 'selected' : ''; ?>>Reddedildi</option>
                                    <option value="expired" <?php echo $quotation['status'] == 'expired' ? 'selected' : ''; ?>>Süresi Doldu</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notlar</label>
                                <textarea class="form-control" id="notes" name="notes"
                                    rows="3"><?php echo htmlspecialchars($quotation['notes']); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="terms_conditions" class="form-label">Şartlar ve Koşullar</label>
                                <textarea class="form-control" id="terms_conditions" name="terms_conditions"
                                    rows="5"><?php echo htmlspecialchars($quotation['terms_conditions']); ?></textarea>
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
                                <!-- Mevcut kalemler PHP ile basılacak -->
                                <?php foreach ($items as $index => $item): ?>
                                    <div class="item-row">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label">Kalem Tipi <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-select item-type" name="item_type[]" required>
                                                    <option value="">Seçin</option>
                                                    <option value="product" <?php echo $item['item_type'] == 'product' ? 'selected' : ''; ?>>Ürün</option>
                                                    <!-- <option value="service" <?php echo $item['item_type'] == 'service' ? 'selected' : ''; ?>>Hizmet</option> -->
                                                    <!-- Hizmet seçeneği geçici olarak kapalı -->
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Ürün/Hizmet <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-select item-id" name="item_id[]" required>
                                                    <option value="">Önce tür seçin</option>
                                                    <!-- Seçenekler JS ile doldurulacak -->
                                                </select>
                                                <!-- Seçili ID'yi saklamak için gizli alan -->
                                                <input type="hidden" class="selected-item-id"
                                                    value="<?php echo $item['item_id']; ?>">
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-12">
                                                <label class="form-label">Açıklama</label>
                                                <textarea class="form-control item-description" name="description[]"
                                                    rows="2"><?php echo htmlspecialchars($item['description']); ?></textarea>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-3">
                                                <label class="form-label">Miktar <span class="text-danger">*</span></label>
                                                <!-- Adım "any" ondalıklar için, value PHP'den virgüllü -->
                                                <input type="text" class="form-control item-quantity numeric-input"
                                                    name="quantity[]"
                                                    value="<?php echo number_format($item['quantity'], 2, ',', ''); ?>"
                                                    required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Birim Fiyat <span
                                                        class="text-danger">*</span></label>
                                                <!-- PHP'den virgüllü, step any -->
                                                <input type="text" class="form-control item-price numeric-input"
                                                    name="unit_price[]"
                                                    value="<?php echo number_format($item['unit_price'], 2, ',', '.'); ?>"
                                                    required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">İndirim %</label>
                                                <input type="text" class="form-control item-discount numeric-input"
                                                    name="discount_percent[]"
                                                    value="<?php echo number_format($item['discount_percent'], 2, ',', ''); ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">KDV %</label>
                                                <input type="text" class="form-control item-tax numeric-input"
                                                    name="tax_rate[]"
                                                    value="<?php echo number_format($item['tax_rate'], 2, ',', ''); ?>">
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button type="button"
                                                    class="btn btn-danger w-100 remove-item">Kaldır</button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <!-- Yeni eklenecekler için template -->
                                <template id="item-template">
                                    <div class="item-row">
                                        <div class="row mb-2">
                                            <div class="col-md-6">
                                                <label class="form-label">Kalem Tipi <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-select item-type" name="item_type[]" required>
                                                    <option value="">Seçin</option>
                                                    <option value="product">Ürün</option>
                                                    <option value="service">Hizmet</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Ürün/Hizmet <span
                                                        class="text-danger">*</span></label>
                                                <select class="form-select item-id" name="item_id[]" required disabled>
                                                    <option value="">Önce tür seçin</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-12">
                                                <label class="form-label">Açıklama</label>
                                                <textarea class="form-control item-description" name="description[]"
                                                    rows="2"></textarea>
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-md-3">
                                                <label class="form-label">Miktar <span
                                                        class="text-danger">*</span></label>
                                                <input type="text" class="form-control item-quantity numeric-input"
                                                    name="quantity[]" value="1,00" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Birim Fiyat <span
                                                        class="text-danger">*</span></label>
                                                <input type="text" class="form-control item-price numeric-input"
                                                    name="unit_price[]" value="0,00" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">İndirim %</label>
                                                <input type="text" class="form-control item-discount numeric-input"
                                                    name="discount_percent[]" value="0,00">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label">KDV %</label>
                                                <input type="text" class="form-control item-tax numeric-input"
                                                    name="tax_rate[]" value="18,00">
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button type="button"
                                                    class="btn btn-danger w-100 remove-item">Kaldır</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Toplamlar -->
                            <div class="row mt-4">
                                <div class="col-md-7 offset-md-5">
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>Ara Toplam:</strong> <span id="subtotal">0,00 ₺</span>
                                    </div>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>İndirim:</strong> <span id="discount">0,00 ₺</span>
                                    </div>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong>KDV:</strong> <span id="tax">0,00 ₺</span>
                                    </div>
                                    <hr>
                                    <div class="mb-2 d-flex justify-content-between">
                                        <strong class="fs-5">Genel Toplam:</strong> <span id="total"
                                            class="fw-bold fs-5">0,00 ₺</span>
                                    </div>
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

<?php include 'includes/footer.php'; ?>

<script>
    // Ürün ve Hizmet verileri
    const products = <?php echo json_encode($products); ?>;
    const services = <?php echo json_encode($services); ?>;

    // Para formatı (Türkçe)
    function formatCurrency(value) {
        return new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', minimumFractionDigits: 2 }).format(value);
    }

    // Sayı formatı (PHP'ye göndermek için noktaya çevirir)
    function formatNumberForServer(value) {
        // Sadece rakam ve virgül/nokta al, sonra virgülü noktaya çevir
        let numStr = String(value).replace(/[^0-9.,]/g, '').replace(',', '.');
        // Birden fazla noktayı engelle
        let parts = numStr.split('.');
        if (parts.length > 2) {
            numStr = parts[0] + '.' + parts.slice(1).join('');
        }
        return parseFloat(numStr || 0); // Sayıya çevir, olmazsa 0
    }

    // Sayı formatı (Inputta göstermek için virgüllü)
    function formatNumberForInput(value) {
        const num = parseFloat(value || 0);
        return num.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }


    // Toplamları güncelle
    function updateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;

        document.querySelectorAll('#items-container .item-row').forEach(row => {
            const quantity = formatNumberForServer(row.querySelector('.item-quantity').value);
            const price = formatNumberForServer(row.querySelector('.item-price').value);
            const discountPercent = formatNumberForServer(row.querySelector('.item-discount').value);
            const taxPercent = formatNumberForServer(row.querySelector('.item-tax').value);

            if (quantity <= 0 || price < 0) return; // Geçersizse atla

            const lineSubtotal = quantity * price;
            const lineDiscount = lineSubtotal * (discountPercent / 100);
            const lineSubtotalAfterDiscount = lineSubtotal - lineDiscount;
            const lineTax = lineSubtotalAfterDiscount * (taxPercent / 100);

            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;
        });

        const total = subtotal - totalDiscount + totalTax;

        document.getElementById('subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('discount').textContent = formatCurrency(totalDiscount);
        document.getElementById('tax').textContent = formatCurrency(totalTax);
        document.getElementById('total').textContent = formatCurrency(total);
    }


    // Ürünleri yükle
    function loadProducts(selectElement) {
        products.forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = `${product.code} - ${product.name} (${formatNumberForInput(product.price)} ₺)`;
            option.dataset.price = product.price;
            option.dataset.tax = product.tax_rate;
            option.dataset.description = product.name;
            selectElement.appendChild(option);
        });
    }

    // Hizmetleri yükle - Geçici olarak devre dışı bırakıldı
    function loadServices(selectElement) {
        // Geçici olarak devre dışı bırakıldı
        const option = document.createElement('option');
        option.value = "";
        option.textContent = "Hizmet seçimi şu an kullanılamaz";
        selectElement.appendChild(option);
        /*services.forEach(service => {
            const option = document.createElement('option');
            option.value = service.id;
            option.textContent = `${service.code} - ${service.name} (${formatNumberForInput(service.price)} ₺)`;
            option.dataset.price = service.price;
            option.dataset.tax = service.tax_rate;
            option.dataset.description = service.name;
            selectElement.appendChild(option);
        });*/
    }

    // Kalem tipi değiştiğinde ürün/hizmet listesini yükle
    function handleItemTypeChange(selectElement) {
        const itemType = selectElement.value;
        const row = selectElement.closest('.item-row');
        const itemIdSelect = row.querySelector('.item-id');
        const selectedItemIdHidden = row.querySelector('.selected-item-id'); // Hidden input'u al

        // Mevcut seçili ID'yi al (varsa)
        const previouslySelectedId = selectedItemIdHidden ? selectedItemIdHidden.value : null;

        itemIdSelect.innerHTML = '<option value="">Seçin</option>'; // Temizle

        const itemsToLoad = (itemType === 'product') ? products : (itemType === 'service' ? services : []);

        if (itemType === 'product') {
            loadProducts(itemIdSelect);
        } else if (itemType === 'service') {
            loadServices(itemIdSelect);
        }

        itemIdSelect.disabled = (itemType === '');

        // Eğer önceden seçili bir ID varsa ve yeni listede bulunuyorsa, onu seçili yap
        if (previouslySelectedId && itemsToLoad.some(item => item.id == previouslySelectedId)) {
            itemIdSelect.value = previouslySelectedId;
            // Seçim değiştiği için ilgili alanları güncelle
            handleItemIdChange(itemIdSelect);
        } else {
            // Tür değiştiğinde veya eşleşme olmadığında fiyat vb. sıfırla veya varsayılan yap
            row.querySelector('.item-price').value = formatNumberForInput(0);
            row.querySelector('.item-tax').value = formatNumberForInput(18); // Veya varsayılan ayardan al
            row.querySelector('.item-description').value = '';
            updateTotals(); // Tür değişince de toplam güncellensin
        }

        // Hidden input'un değerini temizle (artık dropdown'dan okunacak)
        if (selectedItemIdHidden) {
            selectedItemIdHidden.value = '';
        }
    }


    // Ürün/Hizmet seçildiğinde fiyat/vergi/açıklama doldur
    function handleItemIdChange(selectElement) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        const row = selectElement.closest('.item-row');
        const priceInput = row.querySelector('.item-price');
        const taxInput = row.querySelector('.item-tax');
        const descInput = row.querySelector('.item-description');

        if (selectedOption && selectedOption.value) {
            priceInput.value = formatNumberForInput(selectedOption.dataset.price);
            taxInput.value = formatNumberForInput(selectedOption.dataset.tax);
            // Açıklama boşsa doldur, doluysa elle girilene dokunma
            if (!descInput.value.trim()) {
                descInput.value = selectedOption.dataset.description;
            }
        } else {
            // Seçim kaldırılırsa sıfırla
            priceInput.value = formatNumberForInput(0);
            taxInput.value = formatNumberForInput(18);
            descInput.value = '';
        }
        updateTotals();
    }

    // Yeni kalem ekle
    function addNewItem() {
        const template = document.getElementById('item-template');
        const container = document.getElementById('items-container');
        const clone = document.importNode(template.content, true);

        // Event listener'ları yeni klona ekle
        attachEventListenersToRow(clone.querySelector('.item-row'));

        container.appendChild(clone);
        updateTotals(); // Yeni item eklenince toplam güncellensin
    }

    // Bir satıra event listener ekleyen yardımcı fonksiyon
    function attachEventListenersToRow(rowElement) {
        const itemTypeSelect = rowElement.querySelector('.item-type');
        const itemIdSelect = rowElement.querySelector('.item-id');
        const numericInputs = rowElement.querySelectorAll('.numeric-input'); // Sınıfı ekledik
        const removeButton = rowElement.querySelector('.remove-item');

        if (itemTypeSelect) {
            itemTypeSelect.addEventListener('change', function () { handleItemTypeChange(this); });
        }
        if (itemIdSelect) {
            itemIdSelect.addEventListener('change', function () { handleItemIdChange(this); });
        }

        numericInputs.forEach(input => {
            // Odaklanınca noktaya çevir
            input.addEventListener('focus', (e) => {
                e.target.value = String(e.target.value).replace(',', '.');
            });
            // Odak kaybedince virgülle formatla
            input.addEventListener('blur', (e) => {
                e.target.value = formatNumberForInput(e.target.value);
                updateTotals(); // Değer değişmiş olabilir, toplamı güncelle
            });
            // Giriş sırasında sadece sayı ve bir virgül/nokta izin ver (isteğe bağlı, gelişmiş)
            input.addEventListener('input', (e) => {
                // Basit bir filtreleme yapılabilir veya regex kullanılabilir
                // e.target.value = e.target.value.replace(/[^0-9.,]/g, '');
                updateTotals(); // Her girişte toplamı güncelle
            });
        });

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                this.closest('.item-row').remove();
                updateTotals();
            });
        }
    }


    document.addEventListener('DOMContentLoaded', function () {
        // Mevcut kalemler için event listener'ları ve ilk yüklemeyi yap
        document.querySelectorAll('#items-container .item-row').forEach(row => {
            const itemTypeSelect = row.querySelector('.item-type');
            // İlk yükleme için tür listesini doldur ve seçili olanı ayarla
            handleItemTypeChange(itemTypeSelect);
            // Bu satıra event listener'ları ekle
            attachEventListenersToRow(row);
        });

        // Yeni kalem ekleme düğmesi
        document.getElementById('addItemBtn').addEventListener('click', addNewItem);

        // Sayfa yüklendiğinde toplamları hesapla
        updateTotals();
    });

</script>