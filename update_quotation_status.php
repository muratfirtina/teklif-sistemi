<?php
// update_quotation_status.php - Teklif durumunu güncelleme işleyici
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı girişi gerekli
requireLogin();

// Gerekli parametreleri al
$quotation_id = isset($_GET['id']) && is_numeric($_GET['id']) ? intval($_GET['id']) : 0;
$new_status = isset($_GET['status']) ? $_GET['status'] : '';
$origin = isset($_GET['origin']) ? $_GET['origin'] : 'list'; // Varsayılan olarak liste sayfasına dön

// Geçerli durumları tanımla
$validStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired'];

// --- Doğrulama ve Yetkilendirme ---
if ($quotation_id <= 0) {
    setMessage('error', 'Geçersiz Teklif ID\'si.');
} elseif (!in_array($new_status, $validStatuses)) {
    setMessage('error', 'Geçersiz teklif durumu.');
} else {
    // Durum geçerli, şimdi yetki kontrolü ve güncelleme yap
    try {
        $conn = getDbConnection();

        // Teklifin sahibini veya admin kontrolü
        $stmtCheck = $conn->prepare("SELECT user_id FROM quotations WHERE id = :id");
        $stmtCheck->bindParam(':id', $quotation_id, PDO::PARAM_INT);
        $stmtCheck->execute();

        if ($stmtCheck->rowCount() > 0) {
            $ownerId = $stmtCheck->fetchColumn();
            $isOwner = ($_SESSION['user_id'] == $ownerId);
            $isAdmin = isAdmin();

            if ($isOwner || $isAdmin) {
                // Yetki var, güncelleme yap
                $stmtUpdate = $conn->prepare("UPDATE quotations SET status = :status WHERE id = :id");
                $stmtUpdate->bindParam(':status', $new_status, PDO::PARAM_STR);
                $stmtUpdate->bindParam(':id', $quotation_id, PDO::PARAM_INT);
                $stmtUpdate->execute();

                // Başarı mesajı ayarla
                 setMessage('success', '#' . $quotation_id . ' numaralı teklifin durumu başarıyla "' . ucfirst($new_status) . '" olarak güncellendi.');

                 // Fatura veya Üretim Siparişi oluşturma kontrolü (Kabul Edildi ise)
                 if ($new_status == 'accepted') {
                     // Fatura kontrolü
                     $invoiceExists = false;
                     $tableCheckStmt = $conn->query("SHOW TABLES LIKE 'invoices'");
                     if ($tableCheckStmt->rowCount() > 0) {
                         $stmtCheckInvoice = $conn->prepare("SELECT id FROM invoices WHERE quotation_id = :quotation_id LIMIT 1");
                         $stmtCheckInvoice->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
                         $stmtCheckInvoice->execute();
                         $invoiceExists = ($stmtCheckInvoice->rowCount() > 0);
                     }

                     // Üretim siparişi kontrolü
                     $productionOrderExists = false;
                     $stmtCheckOrder = $conn->prepare("SELECT id FROM production_orders WHERE quotation_id = :quotation_id LIMIT 1");
                     $stmtCheckOrder->bindParam(':quotation_id', $quotation_id, PDO::PARAM_INT);
                     $stmtCheckOrder->execute();
                     $productionOrderExists = ($stmtCheckOrder->rowCount() > 0);

                     // Eğer fatura yoksa veya üretim siparişi yoksa ek bilgi mesajı ekle
                     $extraInfo = [];
                     if(!$invoiceExists) $extraInfo[] = 'fatura oluşturulabilir';
                     if(!$productionOrderExists) $extraInfo[] = 'üretim siparişi oluşturulabilir';

                     if (!empty($extraInfo)) {
                         setMessage('info_extra', 'Artık ' . implode(' ve ', $extraInfo) . '.');
                     }
                 }


            } else {
                setMessage('error', 'Bu teklifin durumunu değiştirme yetkiniz yok.');
            }
        } else {
            setMessage('error', 'Güncellenecek teklif bulunamadı.');
        }

    } catch(PDOException $e) {
        setMessage('error', 'Teklif durumu güncellenirken bir veritabanı hatası oluştu: ' . $e->getMessage());
        error_log("Status Update Error (ID: $quotation_id): " . $e->getMessage());
    }
}

// --- Yönlendirme ---
// Hangi sayfadan gelindiyse oraya geri dön
if ($origin == 'view' && $quotation_id > 0) {
    header("Location: view_quotation.php?id=" . $quotation_id);
} else {
    header("Location: quotations.php"); // Varsayılan veya origin=list ise
}
exit;
?>