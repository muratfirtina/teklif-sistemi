<?php
/**
 * navbar.php - Üst menü bileşeni - Bildirim özelliği eklenmiş hali
 * Tüm sayfalarda ortak kullanılacak navbar elemanı
 */

// Bildirimleri getir
require_once 'includes/notifications.php';
$unreadNotificationCount = 0;
$unreadNotifications = [];

if (isLoggedIn()) {
    $unreadNotificationCount = getUnreadNotificationCount($_SESSION['user_id']);
    if ($unreadNotificationCount > 0) {
        $unreadNotifications = getUnreadNotifications($_SESSION['user_id'], 5); // Son 5 bildirimi göster
    }
}
?>
<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">Teklif Yönetim Sistemi</a>
        <div class="d-flex align-items-center">
            <!-- Bildirim Dropdown -->
            <div class="dropdown me-3">
                <a class="btn btn-dark position-relative" href="#" role="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadNotificationCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount; ?>
                            <span class="visually-hidden">okunmamış bildirim</span>
                        </span>
                    <?php endif; ?>
                </a>
                
                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                    <div class="d-flex justify-content-between align-items-center p-2 border-bottom">
                        <h6 class="dropdown-header p-0 m-0">Bildirimler</h6>
                        <?php if ($unreadNotificationCount > 0): ?>
                            <a href="mark_notifications_read.php" class="btn btn-sm btn-link text-decoration-none">Tümünü Okundu İşaretle</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (count($unreadNotifications) > 0): ?>
                        <div class="notifications-container">
                            <?php foreach ($unreadNotifications as $notification): ?>
                                <a href="mark_notifications_read.php?id=<?php echo $notification['id']; ?>&redirect=<?php 
                                    if ($notification['related_type'] == 'production_order') {
                                        echo 'view_production_order.php?id=' . $notification['related_id'];
                                    } else {
                                        echo '#';
                                    }
                                ?>" class="dropdown-item d-flex align-items-center p-2 border-bottom notification-item" style="white-space: normal;">
                                    <div class="flex-shrink-0 me-2">
                                        <?php if (strpos($notification['message'], 'Üretim') !== false): ?>
                                            <i class="bi bi-gear-fill text-primary"></i>
                                        <?php else: ?>
                                            <i class="bi bi-bell-fill text-warning"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="small text-truncate"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="small text-muted"><?php echo date('d.m.Y H:i', strtotime($notification['created_at'])); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-3 text-center text-muted">
                            <i class="bi bi-bell-slash"></i> Yeni bildiriminiz yok
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($unreadNotificationCount > count($unreadNotifications)): ?>
                        <div class="dropdown-item text-center p-2 border-top">
                            <small>Daha fazla bildirim görmek için tıklayın</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <span class="navbar-text me-3">
                <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['user_fullname']); ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Çıkış</a>
        </div>
    </div>
</nav>

<!-- Bildirim Sesi için Audio Elementi -->
<audio id="notification-sound" src="assets/notification.mp3" preload="auto"></audio>

<script>
// Yeni bildirim geldiğinde ses çalma
document.addEventListener('DOMContentLoaded', function() {
    const notificationCount = <?php echo $unreadNotificationCount; ?>;
    const notificationSound = document.getElementById('notification-sound');
    
    // Sayfa yüklendiğinde okunmamış bildirim varsa ve önceden görüntülenmemişse ses çal
    if (notificationCount > 0) {
        // Local storage'dan son bildirimi kontrol et
        const lastNotificationCount = localStorage.getItem('lastNotificationCount') || 0;
        
        if (notificationCount > parseInt(lastNotificationCount)) {
            // Ses çal
            if (notificationSound) {
                notificationSound.play().catch(e => console.log('Ses otomatik çalınamadı:', e));
            }
        }
        
        // Son bildirimi güncelle
        localStorage.setItem('lastNotificationCount', notificationCount);
    }
});
</script>