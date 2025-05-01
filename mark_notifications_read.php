<?php
// mark_notifications_read.php - Bildirimleri okundu olarak işaretleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Bildirim ID belirtilmişse sadece o bildirimi işaretle
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $notification_id = intval($_GET['id']);
    
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->bindParam(':id', $notification_id);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        setMessage('success', 'Bildirim okundu olarak işaretlendi.');
    } catch (PDOException $e) {
        setMessage('error', 'Bildirim işaretlenirken bir hata oluştu: ' . $e->getMessage());
    }
} 
// Tüm bildirimleri işaretle
else {
    try {
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = :user_id AND is_read = 0
        ");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        // Kaç bildirim işaretlendiğini kontrol et
        $count = $stmt->rowCount();
        if ($count > 0) {
            setMessage('success', $count . ' bildirim okundu olarak işaretlendi.');
        } else {
            setMessage('info', 'Okunmamış bildirim bulunamadı.');
        }
        
    } catch (PDOException $e) {
        setMessage('error', 'Bildirimler işaretlenirken bir hata oluştu: ' . $e->getMessage());
    }
}

// Yönlendirme
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
header("Location: " . $referer);
exit;
?>