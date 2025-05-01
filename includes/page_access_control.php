<?php
/**
 * page_access_control.php - Sayfa erişim kontrolü
 * Bu dosya tüm sayfalara dahil edilmeli ve sayfa başında çağrılmalıdır
 */

// Şu anki sayfanın adını al
$currentPage = basename($_SERVER['PHP_SELF']);

// Üretim rolüne sahip kullanıcıların erişebileceği sayfalar
$productionAccessiblePages = [
    'production.php',
    'production_orders.php',
    'view_production_order.php',
    'update_production_status.php',
    'update_production_items.php',
    'update_production_notes.php',
    'logout.php',
    'profile.php', // Eğer kullanıcı profil sayfası varsa
    'change_password.php', // Eğer şifre değiştirme sayfası varsa
    'mark_notifications_read.php'
];

// Kullanıcı oturumu açmışsa ve üretim rolüne sahipse ve izin verilmeyen bir sayfaya erişmeye çalışıyorsa
if (
    isset($_SESSION['user_id']) && 
    isset($_SESSION['user_role']) && 
    $_SESSION['user_role'] == 'production' &&
    !in_array($currentPage, $productionAccessiblePages)
) {
    // Hata mesajı ve yönlendirme
    $_SESSION['error'] = "Bu sayfaya erişim yetkiniz bulunmamaktadır.";
    header("Location: production.php");
    exit;
}
?>