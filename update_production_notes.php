<?php
// update_production_notes.php - Üretim notları güncelleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece üretim rolü için erişim
requireProduction();

// POST işlemi kontrolü
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Gerekli verileri al
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $production_notes = isset($_POST['production_notes']) ? trim($_POST['production_notes']) : '';
    $notify_notes = isset($_POST['notify_notes']) ? true : false;

    // Verileri doğrula
    $errors = [];
    if ($order_id <= 0) {
        $errors[] = "Geçersiz sipariş ID.";
    }

    // Hata yoksa güncelleme yap
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            $conn->beginTransaction();

            // Üretim siparişini kontrol et
            $stmt = $conn->prepare("SELECT * FROM production_orders WHERE id = :id");
            $stmt->bindParam(':id', $order_id);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                throw new Exception("Üretim siparişi bulunamadı.");
            }
            
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            $oldNotes = $order['production_notes'];
            
            // Notları güncelle
            $stmt = $conn->prepare("
                UPDATE production_orders 
                SET production_notes = :notes
                WHERE id = :id
            ");
            $stmt->bindParam(':notes', $production_notes);
            $stmt->bindParam(':id', $order_id);
            $stmt->execute();
            
            // Teklif sahibine bildirim gönder
            if ($notify_notes && $oldNotes != $production_notes) {
                // Teklif sahibini ve ilgili bilgileri al
                $stmt = $conn->prepare("
                    SELECT q.user_id, q.reference_no, c.name as customer_name
                    FROM production_orders po
                    JOIN quotations q ON po.quotation_id = q.id
                    JOIN customers c ON q.customer_id = c.id
                    WHERE po.id = :order_id
                ");
                $stmt->bindParam(':order_id', $order_id);
                $stmt->execute();
                $quoteInfo = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($quoteInfo) {
                    $quotation_owner_id = $quoteInfo['user_id'];
                    $reference_no = $quoteInfo['reference_no'];
                    $customer_name = $quoteInfo['customer_name'];
                    
                    // Bildirim mesajı oluştur
                    $message = "Üretim Notu Güncellemesi: " . $reference_no . " (" . $customer_name . ") ";
                    $message .= "siparişinin üretim notları güncellendi.";
                    
                    // Bildirim ekle
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (user_id, related_id, related_type, message)
                        VALUES (:user_id, :related_id, 'production_order', :message)
                    ");
                    $stmt->bindParam(':user_id', $quotation_owner_id);
                    $stmt->bindParam(':related_id', $order_id);
                    $stmt->bindParam(':message', $message);
                    $stmt->execute();
                }
            }
            
            $conn->commit();
            setMessage('success', 'Üretim notları başarıyla güncellendi.');
            
        } catch (Exception $e) {
            if (isset($conn)) {
                $conn->rollBack();
            }
            setMessage('error', 'Hata: ' . $e->getMessage());
        }
    } else {
        foreach ($errors as $error) {
            setMessage('error', $error);
        }
    }
    
    // Sipariş görüntüleme sayfasına yönlendir
    header("Location: view_production_order.php?id=" . $order_id);
    exit;
}

// Geçersiz istek
else {
    setMessage('error', 'Geçersiz istek.');
    header("Location: production_orders.php");
    exit;
}
?>