<?php
// create_production_order.php - Kabul edilen teklifler için üretim siparişi oluşturma
require_once 'config/database.php';
require_once 'includes/session.php';

// Bu dosya üretim siparişini otomatik oluşturmak için diğer sayfalarca include edilir
// Doğrudan erişilirse, ana sayfaya yönlendir
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    header("Location: index.php");
    exit;
}

/**
 * Teklif ID'sine göre üretim siparişi oluşturur
 * @param int $quotation_id Teklif ID'si
 * @param string $delivery_deadline Teslim tarihi (varsayılan: +10 gün)
 * @return array Başarı durumu ve mesaj içeren dizi
 */
function createProductionOrder($quotation_id, $delivery_deadline = null) {
    try {
        $conn = getDbConnection();
        
        // Teklif bilgilerini al
        $stmt = $conn->prepare("
            SELECT q.*, t.delivery_days
            FROM quotations q
            LEFT JOIN quotation_terms t ON q.id = t.quotation_id
            WHERE q.id = :quotation_id
        ");
        $stmt->bindParam(':quotation_id', $quotation_id);
        $stmt->execute();
        
        if ($stmt->rowCount() == 0) {
            return ['success' => false, 'message' => 'Teklif bulunamadı.'];
        }
        
        $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Teklifin durumunu kontrol et
        if ($quotation['status'] != 'accepted') {
            return ['success' => false, 'message' => 'Sadece kabul edilmiş teklifler için üretim siparişi oluşturulabilir.'];
        }
        
        // Zaten üretim siparişi var mı kontrol et
        $stmt = $conn->prepare("SELECT COUNT(*) FROM production_orders WHERE quotation_id = :quotation_id");
        $stmt->bindParam(':quotation_id', $quotation_id);
        $stmt->execute();
        
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => 'Bu teklif için zaten üretim siparişi oluşturulmuş.'];
        }
        
        // Teklif kalemlerini al
        $stmt = $conn->prepare("
            SELECT * FROM quotation_items 
            WHERE quotation_id = :quotation_id
        ");
        $stmt->bindParam(':quotation_id', $quotation_id);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($items) == 0) {
            return ['success' => false, 'message' => 'Teklifte üretilecek kalem bulunamadı.'];
        }
        
        // Teslim tarihini belirle
        if ($delivery_deadline === null) {
            // Teklif şartlarındaki teslimat süresini kullan (varsayılan: 10 gün)
            $delivery_days = isset($quotation['delivery_days']) ? intval($quotation['delivery_days']) : 10;
            $delivery_deadline = date('Y-m-d', strtotime('+' . $delivery_days . ' days'));
        }
        
        // Toplam ürün miktarını hesapla
        $total_quantity = 0;
        foreach ($items as $item) {
            $total_quantity += $item['quantity'];
        }
        
        $conn->beginTransaction();
        
        // Üretim siparişi oluştur
        $stmt = $conn->prepare("
            INSERT INTO production_orders (
                quotation_id, status, delivery_deadline, 
                production_notes, completed_quantity, total_quantity
            ) VALUES (
                :quotation_id, 'pending', :delivery_deadline, 
                :production_notes, 0, :total_quantity
            )
        ");
        
        $initial_notes = "Sipariş oluşturuldu: " . date('d.m.Y H:i') . "\n";
        $initial_notes .= "Teklif No: " . $quotation['reference_no'] . "\n";
        $initial_notes .= "Teslim Tarihi: " . date('d.m.Y', strtotime($delivery_deadline));
        
        $stmt->bindParam(':quotation_id', $quotation_id);
        $stmt->bindParam(':delivery_deadline', $delivery_deadline);
        $stmt->bindParam(':production_notes', $initial_notes);
        $stmt->bindParam(':total_quantity', $total_quantity);
        $stmt->execute();
        
        $production_order_id = $conn->lastInsertId();
        
        // Üretim kalemleri oluştur
        foreach ($items as $item) {
            $stmt = $conn->prepare("
                INSERT INTO production_order_items (
                    production_order_id, item_id, item_type, 
                    quantity, completed_quantity, status
                ) VALUES (
                    :production_order_id, :item_id, :item_type, 
                    :quantity, 0, 'pending'
                )
            ");
            
            $stmt->bindParam(':production_order_id', $production_order_id);
            $stmt->bindParam(':item_id', $item['id']);
            $stmt->bindParam(':item_type', $item['item_type']);
            $stmt->bindParam(':quantity', $item['quantity']);
            $stmt->execute();
        }
        
        // Üretim departmanına bildirim gönder
        $stmt = $conn->prepare("
            SELECT u.id FROM users u 
            WHERE u.role = 'production'
        ");
        $stmt->execute();
        $production_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Teklif ve müşteri bilgisini al
        $stmt = $conn->prepare("
            SELECT q.reference_no, c.name as customer_name 
            FROM quotations q
            JOIN customers c ON q.customer_id = c.id
            WHERE q.id = :quotation_id
        ");
        $stmt->bindParam(':quotation_id', $quotation_id);
        $stmt->execute();
        $order_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Her üretim kullanıcısına bildirim gönder
        foreach ($production_users as $user_id) {
            $notification_message = "Yeni Üretim Siparişi: " . $order_info['reference_no'] . " (" . $order_info['customer_name'] . ") ";
            $notification_message .= "teklifi kabul edildi. Teslim tarihi: " . date('d.m.Y', strtotime($delivery_deadline));
            
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, related_id, related_type, message)
                VALUES (:user_id, :related_id, 'production_order', :message)
            ");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':related_id', $production_order_id);
            $stmt->bindParam(':message', $notification_message);
            $stmt->execute();
        }
        
        $conn->commit();
        
        return [
            'success' => true, 
            'message' => 'Üretim siparişi başarıyla oluşturuldu.', 
            'order_id' => $production_order_id
        ];
        
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['success' => false, 'message' => 'Hata: ' . $e->getMessage()];
    }
}
?>