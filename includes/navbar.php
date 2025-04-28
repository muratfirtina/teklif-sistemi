<?php
/**
 * navbar.php - Üst menü bileşeni
 * Tüm sayfalarda ortak kullanılacak navbar elemanı
 */
?>
<!-- Navbar -->
<nav class="navbar navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">Teklif Yönetim Sistemi</a>
        <div class="d-flex">
            <span class="navbar-text me-3">
                <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['user_fullname']); ?>
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Çıkış</a>
        </div>
    </div>
</nav>