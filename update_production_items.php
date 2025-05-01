<?php
// update_production_items.php - Üretim kalemleri güncelleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece üretim rolü için erişim
requireProduction();

// POST işlemi kontrolü
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Gerekli verileri al
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $item_ids = isset($_POST['item_id']) ? $_POST['item_id'] : [];
    $completed_quantities = isset($_POST['completed_quantity']) ? $_POST['completed_quantity'] : [];
    $item_statuses = isset($_POST['item_status']) ? $_POST['item_status'] : [];
    $item_notes = isset($_POST['item_notes']) ? $_POST['item_notes'] : [];
    $update_note = isset($_POST['update_note']) ? trim($_POST['update_note']) : '';
    $notify_items_update = isset($_POST['notify_items_update']) ? true : false;

    // Verileri doğrula
    $errors = [];
    if ($order_id <= 0) {
        $errors[] = "Geçersiz sipariş ID.";
    }
    if (empty($item_ids)) {
        $errors[] = "Güncellenecek kalem bulunamadı.";
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
            
            // Sadece bekleyen veya devam eden siparişler güncellenebilir
            if (!in_array($order['status'], ['pending', 'in_progress'])) {
                throw new Exception("Sadece 'Bekleyen' veya 'Devam Eden' durumdaki siparişler güncellenebilir.");
            }
            
            // Kalemleri güncelle ve toplam tamamlanma miktarını hesapla
            $total_completed = 0;
            $total_quantity = 0;
            $all_completed = true;
            
            // Her bir kalemi güncelle
            for ($i = 0; $i < count($item_ids); $i++) {
                $item_id = intval($item_ids[$i]);
                $completed_quantity = floatval(str_replace(',', '.', $completed_quantities[$i]));
                $item_status = $item_statuses[$i];
                $item_note = $item_notes[$i];
                
                // Mevcut kalemi kontrol et
                $stmt = $conn->prepare("SELECT * FROM production_order_items WHERE id = :id AND production_order_id = :order_id");
                $stmt->bindParam(':id', $item_id);
                $stmt->bindParam(':order_id', $order_id);
                $stmt->execute();
                
                if ($stmt->rowCount() == 0) {
                    continue; // Bu kalem bu siparişe ait değilse atla
                }
                
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                $original_quantity = $item['quantity'];
                
                // Tamamlanan miktar toplam miktardan büyük olamaz
                if ($completed_quantity > $original_quantity) {
                    $completed_quantity = $original_quantity;
                }
                
                // Eğer tamamlanan miktar toplam miktara eşitse, durumu "tamamlandı" yap
                if ($completed_quantity >= $original_quantity && $item_status != 'completed') {
                    $item_status = 'completed';
                }
                
                // Kalemi güncelle
                $stmt = $conn->prepare("
                    UPDATE production_order_items 
                    SET completed_quantity = :completed_quantity, 
                        status = :status, 
                        notes = :notes
                    WHERE id = :id
                ");
                $stmt->bindParam(':completed_quantity', $completed_quantity);
                $stmt->bindParam(':status', $item_status);
                $stmt->bindParam(':notes', $item_note);
                $stmt->bindParam(':id', $item_id);
                $stmt->execute();
                
                // Toplam ve tamamlanan miktarları hesapla
                $total_completed += $completed_quantity;
                $total_quantity += $original_quantity;
                
                // Tüm kalemlerin tamamlanıp tamamlanmadığını kontrol et
                if ($item_status != 'completed') {
                    $all_completed = false;
                }
            }
            
            // Sipariş durumunu güncelle
            $order_status = $order['status'];
            $notes = $order['production_notes'];
            
            // Tüm kalemler tamamlandıysa ve henüz tamamlanmamışsa
            if ($all_completed && $order_status != 'completed') {
                $order_status = 'completed';
                $notes .= "\n\n" . date('d.m.Y H:i') . " - Tüm kalemler tamamlandı, sipariş durumu 'Tamamlandı' olarak güncellendi.";
            } 
            // En az bir kalem devam ediyorsa ve henüz başlamamışsa
            elseif (!$all_completed && $total_completed > 0 && $order_status == 'pending') {
                $order_status = 'in_progress';
                $notes .= "\n\n" . date('d.m.Y H:i') . " - Üretim başladı, sipariş durumu 'Devam Ediyor' olarak güncellendi.";
            }
            
            // Üretim notu ekle
            if (!empty($update_note)) {
                $notes .= "\n\n" . date('d.m.Y H:i') . " - Kalem Güncelleme: " . $update_note;
            }
            
            // Siparişi güncelle
            $stmt = $conn->prepare("
                UPDATE production_orders 
                SET status = :status, 
                    completed_quantity = :completed_quantity,
                    production_notes = :notes
                WHERE id = :id
            ");
            $stmt->bindParam(':status', $order_status);
            $stmt->bindParam(':completed_quantity', $total_completed);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':id', $order_id);
            $stmt->execute();
            
            // Teklif sahibine bildirim gönder
            if ($notify_items_update) {
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
                    $completion_percentage = ($total_quantity > 0) ? round(($total_completed / $total_quantity) * 100) : 0;
                    $message = "Üretim İlerlemesi: " . $reference_no . " (" . $customer_name . ") ";
                    $message .= "siparişinin tamamlanma oranı %" . $completion_percentage . " olarak güncellendi.";
                    
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
            setMessage('success', 'Üretim kalemleri başarıyla güncellendi.');
            
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