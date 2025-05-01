<?php
/**
 * sidebar.php - Yan menü bileşeni (Basitleştirilmiş versiyon)
 * Tüm sayfalarda ortak kullanılacak sidebar elemanı
 * 
 * Kullanımı:
 * $currentPage değişkeni ile aktif sayfa belirlenir.
 * Örnek: $currentPage = 'customers'; include 'includes/sidebar.php';
 */

// Aktif sayfa değişkeni tanımlı değilse, varsayılan olarak boş ayarla
if (!isset($currentPage)) {
    $currentPage = '';
}

// Menü öğelerini tanımla
$menuItems = [
    'index' => ['icon' => 'bi-speedometer2', 'text' => 'Ana Sayfa', 'link' => 'index.php'],
    'customers' => ['icon' => 'bi-people', 'text' => 'Müşteriler', 'link' => 'customers.php'],
    'products' => ['icon' => 'bi-box', 'text' => 'Ürünler', 'link' => 'products.php'],
    // 'services' => ['icon' => 'bi-tools', 'text' => 'Hizmetler', 'link' => 'services.php'], // Geçici olarak devre dışı bırakıldı
    'quotations' => ['icon' => 'bi-file-earmark-text', 'text' => 'Teklifler', 'link' => 'quotations.php'],
    'invoices' => ['icon' => 'bi-receipt', 'text' => 'Faturalar', 'link' => 'invoices.php'],
    'inventory' => ['icon' => 'bi-clipboard-data', 'text' => 'Stok Takibi', 'link' => 'inventory.php'],
    'reports' => ['icon' => 'bi-bar-chart', 'text' => 'Raporlar', 'link' => 'reports.php']
];

// Sadece üretim kullanıcıları için menü öğeleri
$productionMenuItems = [
    'production' => ['icon' => 'bi-gear-wide-connected', 'text' => 'Üretim Paneli', 'link' => 'production.php'],
    'production_orders' => ['icon' => 'bi-list-check', 'text' => 'Üretim Siparişleri', 'link' => 'production_orders.php']
];

// Sadece admin kullanıcıları için menü öğeleri
$adminMenuItems = [
    'users' => ['icon' => 'bi-person-badge', 'text' => 'Kullanıcılar', 'link' => 'users.php'],
    'settings' => ['icon' => 'bi-gear', 'text' => 'Ayarlar', 'link' => 'settings.php'],
    'backup_restore' => ['icon' => 'bi-database', 'text' => 'Yedekleme', 'link' => 'backup_restore.php']
];
?>

<!-- Sidebar -->
<div class="sidebar" style="width: 240px;">
    <div class="sidebar-sticky">
        <ul class="nav flex-column">
            <?php 
            // Kullanıcının rolünü kontrol et (isProduction fonksiyonuna bağlı olmadan)
            $isProductionUser = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'production';
            $isAdminUser = isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin';
            
            // Üretim rolüne sahip bir kullanıcı ise sadece üretim menüsünü göster
            if ($isProductionUser): 
            ?>
                <?php foreach ($productionMenuItems as $page => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage == $page) ? 'active' : ''; ?>" href="<?php echo $item['link']; ?>">
                            <i class="bi <?php echo $item['icon']; ?> me-2"></i> <?php echo $item['text']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Normal menü öğeleri - tüm kullanıcılar için -->
                <?php foreach ($menuItems as $page => $item): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($currentPage == $page) ? 'active' : ''; ?>" href="<?php echo $item['link']; ?>">
                            <i class="bi <?php echo $item['icon']; ?> me-2"></i> <?php echo $item['text']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                
                <!-- Admin menüsü - sadece admin için göster -->
                <?php if ($isAdminUser): ?>
                    <?php foreach ($adminMenuItems as $page => $item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage == $page) ? 'active' : ''; ?>" href="<?php echo $item['link']; ?>">
                                <i class="bi <?php echo $item['icon']; ?> me-2"></i> <?php echo $item['text']; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    
                    <!-- Admin için üretim menüsünü de göster -->
                    <li class="nav-item mt-3">
                        <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 text-muted" style=" color: rgb(35 140 244 / 75%) !important;">
                            <span>--- Üretim Yönetimi ---</span>
                        </h>
                    </li>
                    <?php foreach ($productionMenuItems as $page => $item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo ($currentPage == $page) ? 'active' : ''; ?>" href="<?php echo $item['link']; ?>">
                                <i class="bi <?php echo $item['icon']; ?> me-2"></i> <?php echo $item['text']; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>