<?php
/**
 * header.php - Sayfa başlığı ve genel HTML head bileşeni
 * Tüm sayfalarda ortak kullanılacak header elemanı
 * 
 * Kullanımı:
 * $pageTitle değişkeni ile sayfa başlığı belirlenir.
 * Örnek: $pageTitle = 'Müşteriler'; include 'includes/header.php';
 */

// Sayfa başlığı tanımlı değilse, varsayılan olarak ayarla
if (!isset($pageTitle)) {
    $pageTitle = 'Teklif Yönetim Sistemi';
} else {
    $pageTitle = $pageTitle . ' - Teklif Yönetim Sistemi';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php if (isset($needsChartJS) && $needsChartJS): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Z-index fix for dropdowns */
        .dropdown-menu {
            z-index: 1050 !important;
        }
        .table .dropdown-menu {
            min-width: 8rem;
        }
        .navbar {
            z-index: 1040;
        }
        .sidebar {
            z-index: 1030;
        }
        .modal {
            z-index: 1060;
        }
        .modal-backdrop {
            z-index: 1055;
        }
        .action-btn {
            position: relative;
        }
        .btn-group {
            position: relative;
        }
    </style>
    <?php if (isset($extraCSS)): echo $extraCSS; endif; ?>
</head>
<body>