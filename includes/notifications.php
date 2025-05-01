<?php
// includes/notifications.php - Bildirim işlevleri
require_once 'config/database.php';

/**
 * Kullanıcının okunmamış bildirimlerini getirir
 * 
 * @param int $user_id Kullanıcı ID'si
 * @param int $limit Maksimum bildirim sayısı (varsayılan: 10)
 * @return array Bildirimler dizisi
 */
function getUnreadNotifications($user_id, $limit = 10) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = :user_id 
            AND is_read = 0
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Bildirimler alınırken hata oluştu: " . $e->getMessage());
        return [];
    }
}

/**
 * Bildirim ekler
 * 
 * @param int $user_id Bildirim gönderilecek kullanıcı ID'si
 * @param string $message Bildirim mesajı
 * @param int $related_id İlgili içeriğin ID'si (opsiyonel)
 * @param string $related_type İlgili içeriğin türü (opsiyonel)
 * @return bool İşlem başarısı
 */
function addNotification($user_id, $message, $related_id = null, $related_type = null) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, related_id, related_type, message)
            VALUES (:user_id, :related_id, :related_type, :message)
        ");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':related_id', $related_id, PDO::PARAM_INT);
        $stmt->bindParam(':related_type', $related_type, PDO::PARAM_STR);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        
        return $stmt->execute();
        
    } catch (PDOException $e) {
        error_log("Bildirim eklenirken hata oluştu: " . $e->getMessage());
        return false;
    }
}

/**
 * Bir veya birden fazla kullanıcıya aynı bildirimi gönderir
 * 
 * @param array $user_ids Bildirim gönderilecek kullanıcı ID'leri dizisi
 * @param string $message Bildirim mesajı
 * @param int $related_id İlgili içeriğin ID'si (opsiyonel)
 * @param string $related_type İlgili içeriğin türü (opsiyonel)
 * @return int Başarıyla gönderilen bildirim sayısı
 */
function notifyMultipleUsers($user_ids, $message, $related_id = null, $related_type = null) {
    if (empty($user_ids) || !is_array($user_ids)) {
        return 0;
    }
    
    $success_count = 0;
    
    foreach ($user_ids as $user_id) {
        if (addNotification($user_id, $message, $related_id, $related_type)) {
            $success_count++;
        }
    }
    
    return $success_count;
}

/**
 * Özel bir role sahip tüm kullanıcılara bildirim gönderir
 * 
 * @param string $role Kullanıcı rolü (admin, production, user)
 * @param string $message Bildirim mesajı
 * @param int $related_id İlgili içeriğin ID'si (opsiyonel)
 * @param string $related_type İlgili içeriğin türü (opsiyonel)
 * @return int Başarıyla gönderilen bildirim sayısı
 */
function notifyUsersByRole($role, $message, $related_id = null, $related_type = null) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id FROM users WHERE role = :role");
        $stmt->bindParam(':role', $role, PDO::PARAM_STR);
        $stmt->execute();
        
        $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return notifyMultipleUsers($user_ids, $message, $related_id, $related_type);
        
    } catch (PDOException $e) {
        error_log("Rol bazlı bildirim gönderilirken hata oluştu: " . $e->getMessage());
        return 0;
    }
}

/**
 * Okunmamış bildirim sayısını getirir
 * 
 * @param int $user_id Kullanıcı ID'si
 * @return int Okunmamış bildirim sayısı
 */
function getUnreadNotificationCount($user_id) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE user_id = :user_id AND is_read = 0
        ");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        return (int)$stmt->fetchColumn();
        
    } catch (PDOException $e) {
        error_log("Bildirim sayısı alınırken hata oluştu: " . $e->getMessage());
        return 0;
    }
}

/**
 * Bir bildirimi okundu olarak işaretler
 * 
 * @param int $notification_id Bildirim ID'si
 * @param int $user_id Kullanıcı ID'si (güvenlik için)
 * @return bool İşlem başarısı
 */
function markNotificationRead($notification_id, $user_id) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->bindParam(':id', $notification_id, PDO::PARAM_INT);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        return ($stmt->rowCount() > 0);
        
    } catch (PDOException $e) {
        error_log("Bildirim işaretlenirken hata oluştu: " . $e->getMessage());
        return false;
    }
}

/**
 * Bir kullanıcının tüm bildirimlerini okundu olarak işaretler
 * 
 * @param int $user_id Kullanıcı ID'si
 * @return int İşaretlenen bildirim sayısı
 */
function markAllNotificationsRead($user_id) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = :user_id AND is_read = 0
        ");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->rowCount();
        
    } catch (PDOException $e) {
        error_log("Tüm bildirimler işaretlenirken hata oluştu: " . $e->getMessage());
        return 0;
    }
}

?>