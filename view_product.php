<?php
// view_product.php - Ürün görüntüleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz ürün ID\'si.');
    header("Location: products.php");
    exit;
}

$product_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Ürün bilgilerini al
try {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->bindParam(':id', $product_id);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Ürün bulunamadı.');
        header("Location: products.php");
        exit;
    }

    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ürüne ait teklif kalemlerini getir
    $stmt = $conn->prepare("
        SELECT qi.*, q.reference_no, q.date, q.status,
               c.name as customer_name, c.contact_person
        FROM quotation_items qi
        JOIN quotations q ON qi.quotation_id = q.id
        JOIN customers c ON q.customer_id = c.id
        WHERE qi.item_type = 'product' AND qi.item_id = :product_id
        ORDER BY q.date DESC
    ");
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    $quotation_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stok hareketlerini getir
    $stmt = $conn->prepare("
        SELECT im.*, u.username
        FROM inventory_movements im
        JOIN users u ON im.user_id = u.id
        WHERE im.product_id = :product_id
        ORDER BY im.created_at DESC
    ");
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    $inventory_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    setMessage('error', 'Veritabanı hatası: ' . $e->getMessage());
    header("Location: products.php");
    exit;
}

$pageTitle = 'Ürün: ' . htmlspecialchars($product['name']);
$currentPage = 'products';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<style>
    .card-hover:hover {
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        transition: box-shadow 0.3s ease-in-out;
    }
    .info-label {
        font-weight: 600;
        color: #495057;
    }
    .product-info {
        background-color: #f8f9fa;
        border-radius: 0.5rem;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }
    .movement-card {
        border-left: 4px solid #28a745;
        margin-bottom: 10px;
        transition: transform 0.2s;
    }
    .movement-card.out {
        border-left-color: #dc3545;
    }
    .movement-card.adjustment {
        border-left-color: #17a2b8;
    }
    .movement-card:hover {
        transform: translateY(-3px);
    }
    /* YENİ: Renk kutusu için stil */
    .color-swatch {
        width: 25px;
        height: 25px;
        border-radius: 4px;
        border: 1px solid #dee2e6;
        display: inline-block;
        vertical-align: middle;
    }
</style>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <!-- Bildirimler -->
        <?php if ($successMessage = getMessage('success')): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <?php if ($errorMessage = getMessage('error')): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Ürün Detayı</h1>
            <div>
                <a href="edit_product.php?id=<?php echo $product_id; ?>" class="btn btn-warning me-2">
                    <i class="bi bi-pencil"></i> Düzenle
                </a>
                <a href="products.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Ürünlere Dön
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Ürün Bilgileri -->
            <div class="col-md-4">
                <div class="product-info">
                    <h4 class="mb-3"><?php echo htmlspecialchars($product['name']); ?></h4>

                    <div class="mb-3">
                        <div class="info-label">Ürün Kodu</div>
                        <div><?php echo htmlspecialchars($product['code']); ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="info-label">Fiyat</div>
                        <div class="fw-bold"><?php echo number_format($product['price'], 2, ',', '.') . ' ₺'; ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="info-label">KDV Oranı</div>
                        <div><?php echo '%' . number_format($product['tax_rate'], 2, ',', '.'); ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="info-label">Stok Miktarı</div>
                        <div>
                            <?php
                                if ($product['stock_quantity'] <= 0) {
                                    echo '<span class="badge bg-danger">Stok Yok</span>';
                                } elseif ($product['stock_quantity'] < 5) { // Stok kritik seviyesi örneği
                                    echo '<span class="badge bg-warning text-dark">Kritik: ' . $product['stock_quantity'] . '</span>';
                                } else {
                                    echo '<span class="badge bg-success">' . $product['stock_quantity'] . '</span>';
                                }
                            ?>
                        </div>
                    </div>

                    <!-- YENİ: Ürün Rengi Gösterimi -->
                    <?php if (!empty($product['color_hex']) && preg_match('/^#([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/', $product['color_hex'])): ?>
                        <div class="mb-3">
                            <div class="info-label">Ürün Rengi</div>
                            <div>
                                <span class="color-swatch" style="background-color: <?php echo htmlspecialchars($product['color_hex']); ?>;"></span>
                                <span class="ms-2 align-middle"><?php echo htmlspecialchars($product['color_hex']); ?></span>
                            </div>
                        </div>
                    <?php elseif (!empty($product['color_hex'])): // Eğer color_hex dolu ama geçersizse bilgi verilebilir. İsteğe bağlı.?>
                        <div class="mb-3">
                            <div class="info-label">Ürün Rengi</div>
                            <div class="text-muted">Geçersiz renk kodu: <?php echo htmlspecialchars($product['color_hex']); ?></div>
                        </div>
                    <?php endif; ?>
                    <!-- YENİ: Ürün Rengi Gösterimi Bitişi -->

                    <?php if (!empty($product['description'])): ?>
                        <div class="mb-3">
                            <div class="info-label">Açıklama</div>
                            <div><?php echo nl2br(htmlspecialchars($product['description'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <div class="info-label">Kayıt Tarihi</div>
                        <div><?php echo date('d.m.Y H:i', strtotime($product['created_at'])); ?></div>
                    </div>
                </div>

                <!-- Hızlı İşlemler Kartı -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Hızlı İşlemler</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="inventory.php?product_id=<?php echo $product_id; ?>" class="btn btn-primary">
                                <i class="bi bi-clipboard-data"></i> Stok Hareketleri
                            </a>
                            <a href="#" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStockModal">
                                <i class="bi bi-plus-circle"></i> Stok Ekle/Çıkar
                            </a>
                            <a href="new_quotation.php?add_product_id=<?php echo $product_id; ?>" class="btn btn-info"> <!-- Örnek: Ürünü doğrudan yeni teklife ekleme linki -->
                                <i class="bi bi-file-earmark-plus"></i> Yeni Teklife Ekle
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ürün Teklifleri -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Tekliflerde Kullanım</h5>
                        <span class="badge bg-primary"><?php echo count($quotation_items); ?> Kalem</span>
                    </div>
                    <div class="card-body">
                        <?php if (count($quotation_items) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Teklif No</th>
                                            <th>Tarih</th>
                                            <th>Müşteri</th>
                                            <th class="text-center">Miktar</th>
                                            <th class="text-end">Birim Fiyat</th>
                                            <th class="text-end">Toplam</th>
                                            <th>Durum</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($quotation_items as $item): ?>
                                            <?php
                                                $statusClass = 'secondary';
                                                $statusText = 'Taslak';

                                                switch($item['status']) {
                                                    case 'sent':
                                                        $statusClass = 'primary';
                                                        $statusText = 'Gönderildi';
                                                        break;
                                                    case 'accepted':
                                                        $statusClass = 'success';
                                                        $statusText = 'Kabul Edildi';
                                                        break;
                                                    case 'rejected':
                                                        $statusClass = 'danger';
                                                        $statusText = 'Reddedildi';
                                                        break;
                                                    case 'expired':
                                                        $statusClass = 'warning text-dark';
                                                        $statusText = 'Süresi Doldu';
                                                        break;
                                                }

                                                // Hesaplamalar
                                                $unit_price = $item['unit_price'];
                                                $quantity = $item['quantity'];
                                                $discount_percent = $item['discount_percent'];
                                                $discount_amount = $unit_price * ($discount_percent / 100);
                                                $unit_price_after_discount = $unit_price - $discount_amount;
                                                $subtotal = $quantity * $unit_price_after_discount;
                                            ?>
                                            <tr>
                                                <td>
                                                    <a href="view_quotation.php?id=<?php echo $item['quotation_id']; ?>">
                                                        <?php echo htmlspecialchars($item['reference_no']); ?>
                                                    </a>
                                                </td>
                                                <td><?php echo date('d.m.Y', strtotime($item['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($item['customer_name']); ?></td>
                                                <td class="text-center"><?php echo $item['quantity']; ?></td>
                                                <td class="text-end"><?php echo number_format($item['unit_price'], 2, ',', '.') . ' ₺'; ?></td>
                                                <td class="text-end"><?php echo number_format($subtotal, 2, ',', '.') . ' ₺'; ?></td>
                                                <td>
                                                    <span class="badge bg-<?php echo $statusClass; ?> status-badge">
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Bu ürün henüz hiçbir teklifte kullanılmamış.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stok Hareketleri -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Son Stok Hareketleri</h5>
                        <a href="inventory.php?product_id=<?php echo $product_id; ?>" class="btn btn-sm btn-primary">
                            Tüm Hareketler
                        </a>
                    </div>
                    <div class="card-body">
                        <?php if (count($inventory_movements) > 0): ?>
                            <div class="inventory-movements">
                                <?php foreach (array_slice($inventory_movements, 0, 5) as $movement): ?>
                                    <?php
                                        $movementClass = '';
                                        $movementIcon = '';
                                        $movementText = '';

                                        switch($movement['movement_type']) {
                                            case 'in':
                                                $movementClass = '';
                                                $movementIcon = 'bi-arrow-down-circle-fill text-success';
                                                $movementText = 'Giriş';
                                                break;
                                            case 'out':
                                                $movementClass = 'out';
                                                $movementIcon = 'bi-arrow-up-circle-fill text-danger';
                                                $movementText = 'Çıkış';
                                                break;
                                            case 'adjustment':
                                                $movementClass = 'adjustment';
                                                $movementIcon = 'bi-gear-fill text-info';
                                                $movementText = 'Düzeltme';
                                                break;
                                        }
                                    ?>
                                    <div class="card movement-card <?php echo $movementClass; ?> mb-2">
                                        <div class="card-body py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi <?php echo $movementIcon; ?> me-2"></i>
                                                    <strong><?php echo $movementText; ?>:</strong>
                                                    <?php echo $movement['quantity']; ?> adet
                                                    <small class="text-muted ms-2">
                                                        (<?php echo date('d.m.Y H:i', strtotime($movement['created_at'])); ?>)
                                                    </small>
                                                </div>
                                                <div>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($movement['username']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <?php if (!empty($movement['notes'])): ?>
                                                <div class="small text-muted mt-1">
                                                    <?php echo htmlspecialchars($movement['notes']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (count($inventory_movements) > 5): ?>
                                    <div class="text-center mt-3">
                                        <a href="inventory.php?product_id=<?php echo $product_id; ?>" class="btn btn-sm btn-outline-primary">
                                            Tüm Stok Hareketlerini Görüntüle (<?php echo count($inventory_movements); ?>)
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="bi bi-info-circle"></i> Bu ürüne ait stok hareketi bulunmamaktadır.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stok Ekleme Modal -->
<div class="modal fade" id="addStockModal" tabindex="-1" aria-labelledby="addStockModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addStockModalLabel">Stok Hareketi Ekle/Çıkar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="add_inventory_movement.php" method="post"> <!-- Form action'ı uygun bir dosyaya yönlendirilmeli -->
                <div class="modal-body">
                    <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                    <input type="hidden" name="user_id" value="<?php echo $_SESSION['user_id']; // Kullanıcı ID'si session'dan alınmalı ?>">
                    <input type="hidden" name="reference_type" value="manual">


                    <div class="mb-3">
                        <label for="movement_type" class="form-label">Hareket Türü <span class="text-danger">*</span></label>
                        <select class="form-select" id="movement_type" name="movement_type" required>
                            <option value="in">Stok Girişi</option>
                            <option value="out">Stok Çıkışı</option>
                            <option value="adjustment">Stok Düzeltme (Yeni Miktar)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Miktar <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity" name="quantity" min="0" value="1" required>
                        <small id="quantity_help" class="form-text text-muted">Stok düzeltme için yeni toplam stok miktarını girin.</small>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notlar</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Örn: Manuel sayım sonucu düzeltme"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="add_movement_submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
// Footer ve script'ler
include 'includes/footer_scripts.php'; // Eğer varsa, yoksa doğrudan script'leri ekleyin
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const movementTypeSelect = document.getElementById('movement_type');
    const quantityInput = document.getElementById('quantity');
    const quantityHelp = document.getElementById('quantity_help');

    if (movementTypeSelect && quantityHelp) {
        function toggleQuantityHelp() {
            if (movementTypeSelect.value === 'adjustment') {
                quantityHelp.style.display = 'block';
                quantityInput.min = "0"; // Düzeltme için stok 0 olabilir.
            } else {
                quantityHelp.style.display = 'none';
                quantityInput.min = "1"; // Giriş/Çıkış için en az 1
            }
        }
        movementTypeSelect.addEventListener('change', toggleQuantityHelp);
        toggleQuantityHelp(); // Sayfa yüklendiğinde de kontrol et
    }
});
</script>
</body>
</html>