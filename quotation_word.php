<?php
// quotation_word.php - Teklif Word çıktısı oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// PHPWord kütüphanesini dahil et
require_once 'vendor/autoload.php';

// Kullanıcı girişi gerekli
requireLogin();

// Word oluşturmak için ID kontrol edilir
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Geçersiz teklif ID'si.");
}

$quotation_id = intval($_GET['id']);

// Veritabanı bağlantısı
$conn = getDbConnection();

// Teklif bilgilerini al
try {
    $stmt = $conn->prepare("
        SELECT q.*, c.name as customer_name, c.contact_person, c.email as customer_email, 
               c.phone as customer_phone, c.address as customer_address, c.tax_office, c.tax_number,
               u.full_name as user_name, u.email as user_email
        FROM quotations q
        JOIN customers c ON q.customer_id = c.id
        JOIN users u ON q.user_id = u.id
        WHERE q.id = :id
    ");
    $stmt->bindParam(':id', $quotation_id);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        die("Teklif bulunamadı.");
    }
    
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Teklif kalemlerini al
    $stmt = $conn->prepare("
        SELECT qi.*, 
            CASE 
                WHEN qi.item_type = 'product' THEN p.name
                WHEN qi.item_type = 'service' THEN s.name
                ELSE NULL
            END as item_name,
            CASE 
                WHEN qi.item_type = 'product' THEN p.code
                WHEN qi.item_type = 'service' THEN s.code
                ELSE NULL
            END as item_code
        FROM quotation_items qi
        LEFT JOIN products p ON qi.item_type = 'product' AND qi.item_id = p.id
        LEFT JOIN services s ON qi.item_type = 'service' AND qi.item_id = s.id
        WHERE qi.quotation_id = :quotation_id
        ORDER BY qi.id ASC
    ");
    $stmt->bindParam(':quotation_id', $quotation_id);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

// PHPWord nesnesi oluştur
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Table;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Cell;

$phpWord = new PhpWord();

// Stil tanımlamaları
$phpWord->addTitleStyle(1, ['size' => 18, 'bold' => true, 'color' => '000000'], ['alignment' => 'center']);
$phpWord->addTitleStyle(2, ['size' => 14, 'bold' => true]);
$phpWord->addFontStyle('bold', ['bold' => true]);
$phpWord->addFontStyle('right', ['bold' => false], ['alignment' => 'right']);

// Türkçe karakter sorunu çözümü için fontlar
$phpWord->setDefaultFontName('DejaVu Sans');
$phpWord->setDefaultFontSize(10);

// Bölüm oluştur
$section = $phpWord->addSection();

// Başlık alanı
if (file_exists('assets/img/logo.png')) {
    $section->addImage('assets/img/logo.png', [
        'width' => 100,
        'height' => 50,
        'alignment' => 'left',
    ]);
}

// Teklif başlığı
$section->addTitle('TEKLİF', 1);
$section->addTextBreak(1);

// Teklif ve müşteri bilgileri tablosu
$table = $section->addTable([
    'borderSize' => 6, 
    'borderColor' => '999999',
    'width' => 100 * 50,
    'unit' => 'pct',
    'alignment' => 'center'
]);

// Firma bilgileri
$table->addRow();
$cell = $table->addCell(5000, ['bgColor' => 'F2F2F2']);
$textrun = $cell->addTextRun();
$textrun->addText('FİRMA BİLGİLERİ', ['bold' => true, 'size' => 12]);
$cell->addTextBreak(1);
$textrun = $cell->addTextRun();
$textrun->addText('Firma Adı: ', ['bold' => true]);
$textrun->addText('Şirketiniz');
$textrun = $cell->addTextRun();
$textrun->addText('Adres: ', ['bold' => true]);
$textrun->addText('Firma Adresi');
$textrun = $cell->addTextRun();
$textrun->addText('Telefon: ', ['bold' => true]);
$textrun->addText('Firma Telefonu');
$textrun = $cell->addTextRun();
$textrun->addText('E-posta: ', ['bold' => true]);
$textrun->addText($quotation['user_email']);
$textrun = $cell->addTextRun();
$textrun->addText('Vergi Dairesi / No: ', ['bold' => true]);
$textrun->addText('Firma Vergi Bilgileri');

// Müşteri bilgileri
$cell = $table->addCell(5000, ['bgColor' => 'F2F2F2']);
$textrun = $cell->addTextRun();
$textrun->addText('MÜŞTERİ BİLGİLERİ', ['bold' => true, 'size' => 12]);
$cell->addTextBreak(1);
$textrun = $cell->addTextRun();
$textrun->addText('Firma Adı: ', ['bold' => true]);
$textrun->addText($quotation['customer_name']);
$textrun = $cell->addTextRun();
$textrun->addText('İlgili Kişi: ', ['bold' => true]);
$textrun->addText($quotation['contact_person']);
$textrun = $cell->addTextRun();
$textrun->addText('Adres: ', ['bold' => true]);
$textrun->addText($quotation['customer_address']);
$textrun = $cell->addTextRun();
$textrun->addText('Telefon: ', ['bold' => true]);
$textrun->addText($quotation['customer_phone']);
$textrun = $cell->addTextRun();
$textrun->addText('E-posta: ', ['bold' => true]);
$textrun->addText($quotation['customer_email']);
$textrun = $cell->addTextRun();
$textrun->addText('Vergi Dairesi / No: ', ['bold' => true]);
$textrun->addText($quotation['tax_office'] . ' / ' . $quotation['tax_number']);

$section->addTextBreak(1);

// Teklif bilgileri tablosu
$table = $section->addTable([
    'borderSize' => 6, 
    'borderColor' => '999999',
    'width' => 100 * 50,
    'unit' => 'pct',
    'alignment' => 'center'
]);

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Teklif No:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText($quotation['reference_no']);
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Teklif Tarihi:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText(date('d.m.Y', strtotime($quotation['date'])));

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Hazırlayan:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText($quotation['user_name']);
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Geçerlilik Tarihi:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText(date('d.m.Y', strtotime($quotation['valid_until'])));

$section->addTextBreak(1);

// Teklif kalemleri başlığı
$section->addTitle('TEKLİF KALEMLERİ', 2);

// Teklif kalemleri tablosu
$tableStyle = [
    'borderSize' => 6, 
    'borderColor' => '999999',
    'width' => 100 * 50,
    'unit' => 'pct',
    'alignment' => 'center'
];
$firstRowStyle = ['bgColor' => 'F2F2F2'];
$table = $section->addTable($tableStyle);

// Başlık satırı
$table->addRow();
$cellStyle = array_merge(['alignment' => 'center'], $firstRowStyle);
$table->addCell(400, $cellStyle)->addText('S.No', ['bold' => true]);
$table->addCell(800, $cellStyle)->addText('Tür', ['bold' => true]);
$table->addCell(800, $cellStyle)->addText('Kod', ['bold' => true]);
$table->addCell(2500, $cellStyle)->addText('Açıklama', ['bold' => true]);
$table->addCell(600, $cellStyle)->addText('Miktar', ['bold' => true]);
$table->addCell(1000, $cellStyle)->addText('Birim Fiyat', ['bold' => true], ['alignment' => 'right']);
$table->addCell(400, $cellStyle)->addText('İnd. %', ['bold' => true], ['alignment' => 'center']);
$table->addCell(1000, $cellStyle)->addText('Ara Toplam', ['bold' => true], ['alignment' => 'right']);
$table->addCell(400, $cellStyle)->addText('KDV %', ['bold' => true], ['alignment' => 'center']);
$table->addCell(1000, $cellStyle)->addText('KDV Dahil', ['bold' => true], ['alignment' => 'right']);

// Kalem satırları
$counter = 1;
foreach ($items as $item) {
    $item_type = $item['item_type'] == 'product' ? 'Ürün' : 'Hizmet';
    $unit_price = $item['unit_price'];
    $discount_percent = $item['discount_percent'];
    $quantity = $item['quantity'];
    $item_discount = $unit_price * ($discount_percent / 100);
    $unit_price_after_discount = $unit_price - $item_discount;
    $subtotal = $quantity * $unit_price_after_discount;
    $tax_rate = $item['tax_rate'];
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_with_tax = $subtotal + $tax_amount;
    
    $rowStyle = ($counter % 2 == 0) ? ['bgColor' => 'F9F9F9'] : [];
    
    $table->addRow();
    $table->addCell(400, $rowStyle)->addText($counter, [], ['alignment' => 'center']);
    $table->addCell(800, $rowStyle)->addText($item_type, [], ['alignment' => 'center']);
    $table->addCell(800, $rowStyle)->addText($item['item_code'], [], ['alignment' => 'center']);
    $table->addCell(2500, $rowStyle)->addText($item['description']);
    $table->addCell(600, $rowStyle)->addText($quantity, [], ['alignment' => 'center']);
    $table->addCell(1000, $rowStyle)->addText(number_format($unit_price, 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);
    $table->addCell(400, $rowStyle)->addText($discount_percent . '%', [], ['alignment' => 'center']);
    $table->addCell(1000, $rowStyle)->addText(number_format($subtotal, 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);
    $table->addCell(400, $rowStyle)->addText($tax_rate . '%', [], ['alignment' => 'center']);
    $table->addCell(1000, $rowStyle)->addText(number_format($total_with_tax, 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);
    
    $counter++;
}

$section->addTextBreak(1);

// Toplam tablosu
$table = $section->addTable([
    'borderSize' => 6, 
    'borderColor' => '999999',
    'width' => 35 * 50,
    'unit' => 'pct',
    'alignment' => 'right'
]);

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Ara Toplam:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText(number_format($quotation['subtotal'], 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('İndirim:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText(number_format($quotation['discount_amount'], 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('KDV:', ['bold' => true]);
$cell = $table->addCell(2500);
$cell->addText(number_format($quotation['tax_amount'], 2, ',', '.') . ' ₺', [], ['alignment' => 'right']);

$table->addRow();
$cell = $table->addCell(2500, ['bgColor' => 'F2F2F2']);
$cell->addText('Genel Toplam:', ['bold' => true]);
$cell = $table->addCell(2500, ['bgColor' => 'E6F2FF']);
$cell->addText(number_format($quotation['total_amount'], 2, ',', '.') . ' ₺', ['bold' => true], ['alignment' => 'right']);

$section->addTextBreak(2);

// Notlar ve şartlar alanı
if (!empty($quotation['notes']) || !empty($quotation['terms_conditions'])) {
    $table = $section->addTable([
        'borderSize' => 6, 
        'borderColor' => '999999',
        'width' => 100 * 50,
        'unit' => 'pct',
        'alignment' => 'center'
    ]);
    
    if (!empty($quotation['notes'])) {
        $table->addRow();
        $cell = $table->addCell(2000, ['bgColor' => 'F2F2F2']);
        $cell->addText('Notlar:', ['bold' => true]);
        $cell = $table->addCell(8000);
        $paragraphs = explode("\n", $quotation['notes']);
        foreach ($paragraphs as $paragraph) {
            $cell->addText($paragraph);
        }
    }
    
    if (!empty($quotation['terms_conditions'])) {
        $table->addRow();
        $cell = $table->addCell(2000, ['bgColor' => 'F2F2F2']);
        $cell->addText('Şartlar ve Koşullar:', ['bold' => true]);
        $cell = $table->addCell(8000);
        $paragraphs = explode("\n", $quotation['terms_conditions']);
        foreach ($paragraphs as $paragraph) {
            $cell->addText($paragraph);
        }
    }
    
    $section->addTextBreak(2);
}

// İmza alanları
$table = $section->addTable([
    'width' => 100 * 50,
    'unit' => 'pct',
    'alignment' => 'center'
]);

$table->addRow();
$cell = $table->addCell(4500, ['borderTopSize' => 1, 'borderTopColor' => '000000']);
$cell->addText('Teklifi Veren', ['bold' => true], ['alignment' => 'center']);
$table->addCell(1000);
$cell = $table->addCell(4500, ['borderTopSize' => 1, 'borderTopColor' => '000000']);
$cell->addText('Teklifi Kabul Eden', ['bold' => true], ['alignment' => 'center']);

$table->addRow(900);
$cell = $table->addCell(4500);
$cell->addText($quotation['user_name'], [], ['alignment' => 'center']);
$table->addCell(1000);
$cell = $table->addCell(4500);
$cell->addText($quotation['contact_person'], [], ['alignment' => 'center']);

// Word dosyasını oluştur ve indirmeye hazırla
$filename = 'Teklif_' . $quotation['reference_no'] . '.docx';

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
$objWriter->save('php://output');
exit;
?>