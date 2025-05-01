<?php
// create_production_order_endpoint.php - Teklif için üretim siparişi oluşturma endpoint'i
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/notifications.php'; // Bildirim sistemi için

// Kullanıcı girişi gerekli
requireLogin();

// Hata ayıklama
error_log("Production order endpoint çalıştırıldı");

// Teklif ID kontrolü
if (!isset($_GET['quotation_id']) || !is_numeric($_GET['quotation_id'])) {
    setMessage('error', 'Geçersiz teklif ID\'si.');
    header("Location: quotations.php");
    exit;
}

$quotation_id = intval($_GET['quotation_id']);
error_log("Teklif ID: " . $quotation_id);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Teklif bilgilerini al
try {
    $stmt = $conn->prepare("
        SELECT q.*, q.user_id
        FROM quotations q
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Teklif bulunamadı.');
        header("Location: quotations.php");
        exit;
    }
    
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Teklifi sadece sahibi veya admin işleyebilir
    $isOwner = ($quotation['user_id'] == $_SESSION['user_id']);
    if (!$isOwner && !isAdmin()) {
        setMessage('error', 'Bu teklif için işlem yapma yetkiniz bulunmamaktadır.');
        header("Location: quotations.php");
        exit;
    }
    
    // Teklifin durumunu kontrol et
    if ($quotation['status'] != 'accepted') {
        setMessage('error', 'Sadece kabul edilmiş teklifler için üretim siparişi oluşturulabilir.');
        header("Location: view_quotation.php?id=" . $quotation_id);
        exit;
    }
    
    // Zaten üretim siparişi var mı kontrol et
    $stmt = $conn->prepare("SELECT COUNT(*) FROM production_orders WHERE quotation_id = :quotation_id");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    
    if ($stmt->fetchColumn() > 0) {
        setMessage('error', 'Bu teklif için zaten üretim siparişi oluşturulmuş.');
        header("Location: view_quotation.php?id=" . $quotation_id);
        exit;
    }
    
    // Teklif kalemlerini kontrol et
    $stmt = $conn->prepare("SELECT COUNT(*) FROM quotation_items WHERE quotation_id = :quotation_id");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    
    if ($stmt->fetchColumn() == 0) {
        setMessage('error', 'Teklifte üretilecek kalem bulunamadı.');
        header("Location: view_quotation.php?id=" . $quotation_id);
        exit;
    }
    
    // Teklif şartlarını kontrol et - teslimat süresi için
    $stmt = $conn->prepare("SELECT * FROM quotation_terms WHERE quotation_id = :quotation_id");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $terms = null;
    
    if ($stmt->rowCount() > 0) {
        $terms = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Teslimat tarihini belirle (teklif şartlarından veya varsayılan olarak)
    $deliveryDays = isset($terms['delivery_days']) ? intval($terms['delivery_days']) : 10;
    $deliveryDeadline = date('Y-m-d', strtotime('+' . $deliveryDays . ' days'));
    
    // Toplam ürün miktarını hesapla
    $stmt = $conn->prepare("
        SELECT SUM(quantity) as total_quantity 
        FROM quotation_items 
        WHERE quotation_id = :quotation_id
    ");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $totalQuantity = $stmt->fetchColumn() ?: 0;
    
    // Müşteri ve teklif bilgilerini al (bildirim için)
    $stmt = $conn->prepare("
        SELECT q.reference_no, c.name as customer_name
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        WHERE q.id = :quotation_id
    ");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $quoteInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // İşlemleri başlat
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
    $initial_notes .= "Teklif No: " . $quoteInfo['reference_no'] . "\n";
    $initial_notes .= "Teslim Tarihi: " . date('d.m.Y', strtotime($deliveryDeadline));
    
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->bindParam(':delivery_deadline', $deliveryDeadline);
    $stmt->bindParam(':production_notes', $initial_notes);
    $stmt->bindParam(':total_quantity', $totalQuantity);
    $stmt->execute();
    
    $production_order_id = $conn->lastInsertId();
    
    // Üretim kalemleri oluştur
    $stmt = $conn->prepare("
        SELECT * FROM quotation_items 
        WHERE quotation_id = :quotation_id
    ");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'production'");
    $stmt->execute();
    $production_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Her üretim kullanıcısına bildirim gönder
    foreach ($production_users as $user_id) {
        $notification_message = "Yeni Üretim Siparişi: " . $quoteInfo['reference_no'] . " (" . $quoteInfo['customer_name'] . ") ";
        $notification_message .= "teklifi kabul edildi. Teslim tarihi: " . date('d.m.Y', strtotime($deliveryDeadline));
        
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
    
    setMessage('success', 'Üretim siparişi başarıyla oluşturuldu.');
    header("Location: view_quotation.php?id=" . $quotation_id);
    exit;
    
} catch (PDOException $e) {
    // Hata varsa geri al
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    setMessage('error', 'Üretim siparişi oluşturulurken bir hata oluştu: ' . $e->getMessage());
    error_log("Üretim siparişi hatası: " . $e->getMessage());
    header("Location: view_quotation.php?id=" . $quotation_id);
    exit;
}

?>