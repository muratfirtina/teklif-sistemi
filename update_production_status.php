<?php
// update_production_status.php - Üretim durumu güncelleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece üretim rolü için erişim
requireProduction();

// POST işlemi kontrolü
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Gerekli verileri al
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $status_notes = isset($_POST['status_notes']) ? trim($_POST['status_notes']) : '';
    $notify_quotation_owner = isset($_POST['notify_quotation_owner']) ? true : false;

    // Verileri doğrula
    $errors = [];
    if ($order_id <= 0) {
        $errors[] = "Geçersiz sipariş ID.";
    }
    if (!in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'])) {
        $errors[] = "Geçersiz durum değeri.";
    }

    // Hata yoksa güncelleme yap
    if (empty($errors)) {
        try {
            $conn = getDbConnection();
            $conn->beginTransaction();

            // Mevcut siparişi ve durumunu kontrol et
            $stmt = $conn->prepare("SELECT * FROM production_orders WHERE id = :id");
            $stmt->bindParam(':id', $order_id);
            $stmt->execute();
            
            if ($stmt->rowCount() == 0) {
                throw new Exception("Üretim siparişi bulunamadı.");
            }
            
            $currentOrder = $stmt->fetch(PDO::FETCH_ASSOC);
            $oldStatus = $currentOrder['status'];
            
            if ($oldStatus == $status) {
                // Durum değişmemişse sadece not ekle
                if (!empty($status_notes)) {
                    $notes = $currentOrder['production_notes'];
                    $notes .= "\n\n" . date('d.m.Y H:i') . " - Durum Notu: " . $status_notes;
                    
                    $stmt = $conn->prepare("UPDATE production_orders SET production_notes = :notes WHERE id = :id");
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':id', $order_id);
                    $stmt->execute();
                }
            } else {
                // Durum değişmişse güncelle
                $notes = $currentOrder['production_notes'];
                $notePrefix = "\n\n" . date('d.m.Y H:i') . " - Durum değişti: " . getStatusText($oldStatus) . " → " . getStatusText($status);
                if (!empty($status_notes)) {
                    $notePrefix .= "\nNot: " . $status_notes;
                }
                $notes .= $notePrefix;
                
                // Eğer durum "tamamlandı" olarak değişmişse, tüm kalemleri de tamamla
                if ($status == 'completed' && $oldStatus != 'completed') {
                    // Tüm kalemlerin tamamlanma miktarlarını toplam miktara eşitle
                    $stmt = $conn->prepare("
                        UPDATE production_order_items 
                        SET completed_quantity = quantity, status = 'completed'
                        WHERE production_order_id = :order_id AND status != 'completed'
                    ");
                    $stmt->bindParam(':order_id', $order_id);
                    $stmt->execute();
                    
                    // Üretim siparişinin tamamlanma miktarını güncelle
                    $stmt = $conn->prepare("
                        UPDATE production_orders 
                        SET status = :status, production_notes = :notes,
                            completed_quantity = total_quantity
                        WHERE id = :id
                    ");
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':id', $order_id);
                    $stmt->execute();
                } else {
                    // Normal durum değişikliği
                    $stmt = $conn->prepare("
                        UPDATE production_orders 
                        SET status = :status, production_notes = :notes
                        WHERE id = :id
                    ");
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':notes', $notes);
                    $stmt->bindParam(':id', $order_id);
                    $stmt->execute();
                }
            }
            
            // Teklif sahibine bildirim gönder
            if ($notify_quotation_owner) {
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
                    $message = "Üretim Durum Güncellemesi: " . $reference_no . " (" . $customer_name . ") ";
                    $message .= "siparişinin durumu " . getStatusText($status) . " olarak güncellendi.";
                    if (!empty($status_notes)) {
                        $message .= " Not: " . $status_notes;
                    }
                    
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
            setMessage('success', 'Üretim durumu başarıyla güncellendi.');
            
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

// GET işlemi (form üzerinden gelme durumu)
elseif (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $order_id = intval($_GET['id']);
    header("Location: view_production_order.php?id=" . $order_id);
    exit;
}

// Geçersiz istek
else {
    setMessage('error', 'Geçersiz istek.');
    header("Location: production_orders.php");
    exit;
}

// Durum metni dönüştürme yardımcı fonksiyonu
function getStatusText($statusCode) {
    switch ($statusCode) {
        case 'pending':
            return 'Bekliyor';
        case 'in_progress':
            return 'Devam Ediyor';
        case 'completed':
            return 'Tamamlandı';
        case 'cancelled':
            return 'İptal Edildi';
        default:
            return 'Bilinmiyor';
    }
}
?>