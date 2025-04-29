<?php
// report_custom.php - Özel rapor oluşturma sayfası
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Kullanıcı girişi gerekli
requireLogin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Rapor oluşturma işlemi
$reportGenerated = false;
$reportData = [];
$reportTitle = '';
$reportColumns = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $reportType = $_POST['report_type'];
    $dateFrom = $_POST['date_from'] ?? '';
    $dateTo = $_POST['date_to'] ?? '';
    $customerID = isset($_POST['customer_id']) && is_numeric($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
    $userID = isset($_POST['user_id']) && is_numeric($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $productID = isset($_POST['product_id']) && is_numeric($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $serviceID = isset($_POST['service_id']) && is_numeric($_POST['service_id']) ? intval($_POST['service_id']) : 0;
    $status = $_POST['status'] ?? '';
    $groupBy = $_POST['group_by'] ?? '';
    $minAmount = isset($_POST['min_amount']) && is_numeric($_POST['min_amount']) ? floatval($_POST['min_amount']) : 0;
    $maxAmount = isset($_POST['max_amount']) && is_numeric($_POST['max_amount']) ? floatval($_POST['max_amount']) : 0;
    
    // SQL sorgusu ve parametreleri oluştur
    $sql = '';
    $params = [];
    $reportTitle = '';
    
    switch($reportType) {
        case 'quotations':
            $reportTitle = 'Teklif Raporu';
            
            $sql = "
                SELECT q.id, q.reference_no, q.date, q.valid_until, q.status, 
                       q.subtotal, q.tax_amount, q.discount_amount, q.total_amount,
                       c.name as customer_name, c.contact_person, c.email as customer_email,
                       u.username, u.full_name as user_fullname
                FROM quotations q
                JOIN customers c ON q.customer_id = c.id
                JOIN users u ON q.user_id = u.id
                WHERE 1=1
            ";
            
            $reportColumns = [
                'reference_no' => 'Teklif No',
                'date' => 'Tarih',
                'valid_until' => 'Geçerlilik',
                'customer_name' => 'Müşteri',
                'status' => 'Durum',
                'total_amount' => 'Toplam Tutar',
                'user_fullname' => 'Oluşturan'
            ];
            
            break;
            
        case 'invoices':
            $reportTitle = 'Fatura Raporu';
            
            $sql = "
                SELECT i.id, i.invoice_no, i.date, i.due_date, i.status, 
                       i.subtotal, i.tax_amount, i.discount_amount, i.total_amount, i.paid_amount,
                       q.reference_no as quotation_reference,
                       c.name as customer_name, c.contact_person, c.email as customer_email
                FROM invoices i
                JOIN quotations q ON i.quotation_id = q.id
                JOIN customers c ON q.customer_id = c.id
                WHERE 1=1
            ";
            
            $reportColumns = [
                'invoice_no' => 'Fatura No',
                'date' => 'Tarih',
                'due_date' => 'Vade Tarihi',
                'customer_name' => 'Müşteri',
                'status' => 'Durum',
                'total_amount' => 'Toplam Tutar',
                'paid_amount' => 'Ödenen Tutar'
            ];
            
            break;
            
        case 'customers':
            $reportTitle = 'Müşteri Raporu';
            
            $sql = "
                SELECT c.id, c.name, c.contact_person, c.email, c.phone, c.address, c.tax_office, c.tax_number,
                       COUNT(q.id) as quotation_count,
                       SUM(q.total_amount) as total_quotation_amount,
                       SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                       SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) as accepted_amount
                FROM customers c
                LEFT JOIN quotations q ON c.id = q.customer_id
                WHERE 1=1
            ";
            
            $reportColumns = [
                'name' => 'Müşteri Adı',
                'contact_person' => 'İlgili Kişi',
                'email' => 'E-posta',
                'phone' => 'Telefon',
                'quotation_count' => 'Teklif Sayısı',
                'total_quotation_amount' => 'Toplam Teklif Tutarı',
                'accepted_count' => 'Kabul Edilen Teklif',
                'accepted_amount' => 'Kabul Edilen Tutar'
            ];
            
            break;
            
        case 'products':
            $reportTitle = 'Ürün Raporu';
            
            $sql = "
                SELECT p.id, p.code, p.name, p.price, p.tax_rate, p.stock_quantity,
                       COUNT(qi.id) as quotation_count,
                       SUM(qi.quantity) as total_quantity,
                       SUM(qi.subtotal) as total_amount
                FROM products p
                LEFT JOIN quotation_items qi ON p.id = qi.item_id AND qi.item_type = 'product'
                WHERE 1=1
            ";
            
            $reportColumns = [
                'code' => 'Ürün Kodu',
                'name' => 'Ürün Adı',
                'price' => 'Fiyat',
                'stock_quantity' => 'Stok',
                'quotation_count' => 'Teklif Sayısı',
                'total_quantity' => 'Toplam Satış Miktarı',
                'total_amount' => 'Toplam Satış Tutarı'
            ];
            
            break;
            
        case 'services':
            $reportTitle = 'Hizmet Raporu';
            
            $sql = "
                SELECT s.id, s.code, s.name, s.price, s.tax_rate,
                       COUNT(qi.id) as quotation_count,
                       SUM(qi.quantity) as total_quantity,
                       SUM(qi.subtotal) as total_amount
                FROM services s
                LEFT JOIN quotation_items qi ON s.id = qi.item_id AND qi.item_type = 'service'
                WHERE 1=1
            ";
            
            $reportColumns = [
                'code' => 'Hizmet Kodu',
                'name' => 'Hizmet Adı',
                'price' => 'Fiyat',
                'quotation_count' => 'Teklif Sayısı',
                'total_quantity' => 'Toplam Satış Miktarı',
                'total_amount' => 'Toplam Satış Tutarı'
            ];
            
            break;
            
        case 'users':
            $reportTitle = 'Kullanıcı Raporu';
            
            $sql = "
                SELECT u.id, u.username, u.full_name, u.email, u.role,
                       COUNT(q.id) as quotation_count,
                       SUM(q.total_amount) as total_amount,
                       SUM(CASE WHEN q.status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                       SUM(CASE WHEN q.status = 'accepted' THEN q.total_amount ELSE 0 END) as accepted_amount,
                       SUM(CASE WHEN q.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                       SUM(CASE WHEN q.status = 'rejected' THEN q.total_amount ELSE 0 END) as rejected_amount
                FROM users u
                LEFT JOIN quotations q ON u.id = q.user_id
                WHERE 1=1
            ";
            
            $reportColumns = [
                'username' => 'Kullanıcı Adı',
                'full_name' => 'Ad Soyad',
                'email' => 'E-posta',
                'role' => 'Rol',
                'quotation_count' => 'Teklif Sayısı',
                'total_amount' => 'Toplam Teklif Tutarı',
                'accepted_count' => 'Kabul Edilen',
                'accepted_amount' => 'Kabul Edilen Tutar'
            ];
            
            break;
    }
    
    // Gruplama ekle - Önce gruplama kontrolü yapılmalı
    if (!empty($groupBy)) {
        if (in_array($reportType, ['quotations', 'invoices']) && $groupBy == 'customer') {
            // Müşteriye göre gruplanmış raporlar için SELECT listesini değiştir
            if ($reportType == 'quotations') {
                $sql = "
                    SELECT c.id, c.name as customer_name, c.contact_person, c.email as customer_email,
                           COUNT(q.id) as count, 
                           SUM(q.total_amount) as total_amount,
                           MAX(q.date) as date,
                           GROUP_CONCAT(q.status) as status_list,
                           GROUP_CONCAT(DISTINCT u.full_name) as user_fullname
                    FROM quotations q 
                    JOIN customers c ON q.customer_id = c.id
                    JOIN users u ON q.user_id = u.id
                    WHERE 1=1
                ";
                
                $reportColumns = [
                    'customer_name' => 'Müşteri',
                    'count' => 'Teklif Sayısı',
                    'total_amount' => 'Toplam Tutar',
                    'user_fullname' => 'Sorumlu Kişi'
                ];
            } elseif ($reportType == 'invoices') {
                $sql = "
                    SELECT c.id, c.name as customer_name, c.contact_person, c.email as customer_email,
                           COUNT(i.id) as count, 
                           SUM(i.total_amount) as total_amount,
                           MAX(i.date) as date,
                           GROUP_CONCAT(i.status) as status_list
                    FROM invoices i 
                    JOIN quotations q ON i.quotation_id = q.id
                    JOIN customers c ON q.customer_id = c.id
                    WHERE 1=1
                ";
                
                $reportColumns = [
                    'customer_name' => 'Müşteri',
                    'count' => 'Fatura Sayısı',
                    'total_amount' => 'Toplam Tutar'
                ];
            }
        } elseif (in_array($reportType, ['quotations']) && $groupBy == 'user') {
            // Kullanıcıya göre gruplanmış raporlar için SELECT listesini değiştir
            $sql = "
                SELECT u.id, u.username, u.full_name as user_fullname, u.email,
                       COUNT(q.id) as count, 
                       SUM(q.total_amount) as total_amount,
                       MAX(q.date) as date,
                       GROUP_CONCAT(DISTINCT c.name) as customer_names,
                       GROUP_CONCAT(q.status) as status_list
                FROM quotations q 
                JOIN customers c ON q.customer_id = c.id
                JOIN users u ON q.user_id = u.id
                WHERE 1=1
            ";
            
            $reportColumns = [
                'user_fullname' => 'Kullanıcı',
                'count' => 'Teklif Sayısı',
                'total_amount' => 'Toplam Tutar',
                'customer_names' => 'Müşteriler'
            ];
        } elseif (in_array($reportType, ['quotations', 'invoices']) && $groupBy == 'month') {
            if ($reportType == 'quotations') {
                $sql = "
                    SELECT YEAR(q.date) as year, MONTH(q.date) as month, 
                           CONCAT(MONTHNAME(q.date), ' ', YEAR(q.date)) as period,
                           COUNT(q.id) as count, 
                           SUM(q.total_amount) as total_amount,
                           GROUP_CONCAT(q.status) as status_list,
                           COUNT(DISTINCT c.id) as customer_count,
                           COUNT(DISTINCT u.id) as user_count
                    FROM quotations q 
                    JOIN customers c ON q.customer_id = c.id
                    JOIN users u ON q.user_id = u.id
                    WHERE 1=1
                ";
                
                $reportColumns = [
                    'period' => 'Dönem',
                    'count' => 'Teklif Sayısı',
                    'total_amount' => 'Toplam Tutar',
                    'customer_count' => 'Müşteri Sayısı',
                    'user_count' => 'Kullanıcı Sayısı'
                ];
            } else {
                $sql = "
                    SELECT YEAR(i.date) as year, MONTH(i.date) as month, 
                           CONCAT(MONTHNAME(i.date), ' ', YEAR(i.date)) as period,
                           COUNT(i.id) as count, 
                           SUM(i.total_amount) as total_amount,
                           GROUP_CONCAT(i.status) as status_list,
                           COUNT(DISTINCT c.id) as customer_count
                    FROM invoices i 
                    JOIN quotations q ON i.quotation_id = q.id
                    JOIN customers c ON q.customer_id = c.id
                    WHERE 1=1
                ";
                
                $reportColumns = [
                    'period' => 'Dönem',
                    'count' => 'Fatura Sayısı',
                    'total_amount' => 'Toplam Tutar',
                    'customer_count' => 'Müşteri Sayısı'
                ];
            }
        }
    }
    
    // Tarih filtresi ekle
    if (!empty($dateFrom)) {
        if (in_array($reportType, ['quotations'])) {
            if ($groupBy == 'month' || $groupBy == 'user' || $groupBy == 'customer') {
                $sql .= " AND q.date >= :date_from";
            } else {
                $sql .= " AND q.date >= :date_from";
            }
            $params[':date_from'] = $dateFrom;
        } elseif (in_array($reportType, ['invoices'])) {
            if ($groupBy == 'month' || $groupBy == 'customer') {
                $sql .= " AND i.date >= :date_from";
            } else {
                $sql .= " AND i.date >= :date_from";
            }
            $params[':date_from'] = $dateFrom;
        }
    }
    
    if (!empty($dateTo)) {
        if (in_array($reportType, ['quotations'])) {
            if ($groupBy == 'month' || $groupBy == 'user' || $groupBy == 'customer') {
                $sql .= " AND q.date <= :date_to";
            } else {
                $sql .= " AND q.date <= :date_to";
            }
            $params[':date_to'] = $dateTo;
        } elseif (in_array($reportType, ['invoices'])) {
            if ($groupBy == 'month' || $groupBy == 'customer') {
                $sql .= " AND i.date <= :date_to";
            } else {
                $sql .= " AND i.date <= :date_to";
            }
            $params[':date_to'] = $dateTo;
        }
    }
    
    // Müşteri filtresi ekle
    if ($customerID > 0) {
        if (in_array($reportType, ['quotations'])) {
            $sql .= " AND q.customer_id = :customer_id";
            $params[':customer_id'] = $customerID;
        } elseif (in_array($reportType, ['invoices'])) {
            $sql .= " AND q.customer_id = :customer_id";
            $params[':customer_id'] = $customerID;
        } elseif (in_array($reportType, ['customers'])) {
            $sql .= " AND c.id = :customer_id";
            $params[':customer_id'] = $customerID;
        }
    }
    
    // Kullanıcı filtresi ekle
    if ($userID > 0) {
        if (in_array($reportType, ['quotations'])) {
            $sql .= " AND q.user_id = :user_id";
            $params[':user_id'] = $userID;
        } elseif (in_array($reportType, ['users'])) {
            $sql .= " AND u.id = :user_id";
            $params[':user_id'] = $userID;
        }
    }
    
    // Ürün filtresi ekle
    if ($productID > 0 && $reportType == 'products') {
        $sql .= " AND p.id = :product_id";
        $params[':product_id'] = $productID;
    }
    
    // Hizmet filtresi ekle
    if ($serviceID > 0 && $reportType == 'services') {
        $sql .= " AND s.id = :service_id";
        $params[':service_id'] = $serviceID;
    }
    
    // Durum filtresi ekle
    if (!empty($status)) {
        if (in_array($reportType, ['quotations'])) {
            $sql .= " AND q.status = :status";
            $params[':status'] = $status;
        } elseif (in_array($reportType, ['invoices'])) {
            $sql .= " AND i.status = :status";
            $params[':status'] = $status;
        }
    }
    
    // Tutar filtresi ekle
    if ($minAmount > 0) {
        if (in_array($reportType, ['quotations'])) {
            $sql .= " AND q.total_amount >= :min_amount";
            $params[':min_amount'] = $minAmount;
        } elseif (in_array($reportType, ['invoices'])) {
            $sql .= " AND i.total_amount >= :min_amount";
            $params[':min_amount'] = $minAmount;
        }
    }
    
    if ($maxAmount > 0) {
        if (in_array($reportType, ['quotations'])) {
            $sql .= " AND q.total_amount <= :max_amount";
            $params[':max_amount'] = $maxAmount;
        } elseif (in_array($reportType, ['invoices'])) {
            $sql .= " AND i.total_amount <= :max_amount";
            $params[':max_amount'] = $maxAmount;
        }
    }
    
    // Gruplama ekle - GROUP BY cümlecikleri
    if (!empty($groupBy)) {
        if (in_array($reportType, ['quotations', 'invoices']) && $groupBy == 'customer') {
            $sql .= " GROUP BY c.id, c.name, c.contact_person, c.email";
        } elseif (in_array($reportType, ['quotations']) && $groupBy == 'user') {
            $sql .= " GROUP BY u.id, u.username, u.full_name, u.email";
        } elseif (in_array($reportType, ['quotations', 'invoices']) && $groupBy == 'month') {
            if ($reportType == 'quotations') {
                $sql .= " GROUP BY YEAR(q.date), MONTH(q.date), period";
            } else {
                $sql .= " GROUP BY YEAR(i.date), MONTH(i.date), period";
            }
        } else {
            // Varsayılan gruplama
            if ($reportType == 'customers') {
                $sql .= " GROUP BY c.id";
            } elseif ($reportType == 'products') {
                $sql .= " GROUP BY p.id";
            } elseif ($reportType == 'services') {
                $sql .= " GROUP BY s.id";
            } elseif ($reportType == 'users') {
                $sql .= " GROUP BY u.id";
            }
        }
    } else {
        // Varsayılan gruplama
        if ($reportType == 'customers') {
            $sql .= " GROUP BY c.id";
        } elseif ($reportType == 'products') {
            $sql .= " GROUP BY p.id";
        } elseif ($reportType == 'services') {
            $sql .= " GROUP BY s.id";
        } elseif ($reportType == 'users') {
            $sql .= " GROUP BY u.id";
        }
    }
    
    // Sıralama ekle
    if (in_array($reportType, ['quotations'])) {
        if ($groupBy == 'customer') {
            $sql .= " ORDER BY customer_name ASC";
        } elseif ($groupBy == 'user') {
            $sql .= " ORDER BY user_fullname ASC";
        } elseif ($groupBy == 'month') {
            $sql .= " ORDER BY year DESC, month DESC";
        } else {
            $sql .= " ORDER BY q.date DESC";
        }
    } elseif (in_array($reportType, ['invoices'])) {
        if ($groupBy == 'customer') {
            $sql .= " ORDER BY customer_name ASC";
        } elseif ($groupBy == 'month') {
            $sql .= " ORDER BY year DESC, month DESC";
        } else {
            $sql .= " ORDER BY i.date DESC";
        }
    } elseif ($reportType == 'customers') {
        $sql .= " ORDER BY c.name ASC";
    } elseif ($reportType == 'products') {
        $sql .= " ORDER BY p.name ASC";
    } elseif ($reportType == 'services') {
        $sql .= " ORDER BY s.name ASC";
    } elseif ($reportType == 'users') {
        $sql .= " ORDER BY u.username ASC";
    }

    // Debug - SQL ve parametreleri göster
    // echo "SQL: " . $sql;
    // echo "<pre>";
    // print_r($params);
    // echo "</pre>";
    
    // Rapor verilerini al
    try {
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $reportGenerated = true;
    } catch(PDOException $e) {
        setMessage('error', 'Rapor verileri alınırken bir hata oluştu: ' . $e->getMessage());
    }
    
    // Excel export işlemi
    if (isset($_POST['export']) && $_POST['export'] == 'excel' && $reportGenerated) {
        // Yeni bir Excel dosyası oluştur
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Başlık satırı
        $column = 'A';
        foreach ($reportColumns as $key => $label) {
            $sheet->setCellValue($column . '1', $label);
            $column++;
        }
        
        // Başlık formatını ayarla
        $headerStyle = $sheet->getStyle('A1:' . chr(64 + count($reportColumns)) . '1');
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEEEEE');
        
        // Veri satırları
        $row = 2;
        foreach ($reportData as $data) {
            $column = 'A';
            foreach ($reportColumns as $key => $label) {
                // Para birimi formatı
                if (in_array($key, ['total_amount', 'paid_amount', 'subtotal', 'tax_amount', 'discount_amount', 'price', 'total_quotation_amount', 'accepted_amount', 'rejected_amount'])) {
                    $sheet->setCellValue($column . $row, $data[$key]);
                    // Para birimi formatı
                    $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('#,##0.00 ₺');
                } elseif (in_array($key, ['date', 'valid_until', 'due_date'])) {
                    // Tarih formatı
                    $date = DateTime::createFromFormat('Y-m-d', $data[$key]);
                    if ($date) {
                        $sheet->setCellValue($column . $row, $date);
                        $sheet->getStyle($column . $row)->getNumberFormat()->setFormatCode('dd.mm.yyyy');
                    } else {
                        $sheet->setCellValue($column . $row, $data[$key]);
                    }
                } elseif ($key == 'status') {
                    // Durum çevirisi
                    $statusText = '';
                    switch($data[$key]) {
                        case 'draft':
                            $statusText = 'Taslak';
                            break;
                        case 'sent':
                            $statusText = 'Gönderildi';
                            break;
                        case 'accepted':
                            $statusText = 'Kabul Edildi';
                            break;
                        case 'rejected':
                            $statusText = 'Reddedildi';
                            break;
                        case 'expired':
                            $statusText = 'Süresi Doldu';
                            break;
                        case 'unpaid':
                            $statusText = 'Ödenmedi';
                            break;
                        case 'partially_paid':
                            $statusText = 'Kısmi Ödendi';
                            break;
                        case 'paid':
                            $statusText = 'Ödendi';
                            break;
                        case 'cancelled':
                            $statusText = 'İptal Edildi';
                            break;
                        default:
                            $statusText = $data[$key];
                    }
                    $sheet->setCellValue($column . $row, $statusText);
                } else {
                    // Normal değer
                    $sheet->setCellValue($column . $row, $data[$key] ?? '');
                }
                $column++;
            }
            $row++;
        }
        
        // Sütun genişliklerini otomatik ayarla
        foreach (range('A', chr(64 + count($reportColumns))) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // İlk sayfayı adlandır
        $sheet->setTitle('Rapor');
        
        // Özet sayfası ekle
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Özet');
        
        $sheet->setCellValue('A1', 'Rapor Özeti');
        $sheet->setCellValue('A2', 'Rapor Türü:');
        $sheet->setCellValue('B2', $reportTitle);
        $sheet->setCellValue('A3', 'Oluşturma Tarihi:');
        $sheet->setCellValue('B3', date('d.m.Y H:i:s'));
        $sheet->setCellValue('A4', 'Toplam Kayıt Sayısı:');
        $sheet->setCellValue('B4', count($reportData));
        
        // Formatları ayarla
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A4')->getFont()->setBold(true);
        
        // Sütun genişliklerini ayarla
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(30);
        
        // HTTP başlıklarını ayarla ve dosyayı indir
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $reportTitle . '_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}

// Müşterileri getir (filtre için)
$customers = [];
try {
    $stmt = $conn->query("SELECT id, name FROM customers ORDER BY name ASC");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Müşteri listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Kullanıcıları getir (filtre için)
$users = [];
try {
    $stmt = $conn->query("SELECT id, username, full_name FROM users ORDER BY username ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Kullanıcı listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Ürünleri getir (filtre için)
$products = [];
try {
    $stmt = $conn->query("SELECT id, code, name FROM products ORDER BY name ASC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Ürün listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Hizmetleri getir (filtre için)
$services = [];
try {
    $stmt = $conn->query("SELECT id, code, name FROM services ORDER BY name ASC");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Hizmet listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

$pageTitle = 'Özel Rapor Oluştur';
$currentPage = 'reports';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<style>
    .filter-form {
        background-color: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }
    .report-card {
        transition: transform 0.3s ease-in-out;
    }
    .report-card:hover {
        transform: translateY(-5px);
    }
    .report-icon {
        font-size: 2rem;
        margin-bottom: 15px;
    }
</style>

<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <!-- Bildirimler -->
        <?php if ($successMessage = getMessage('success')): ?>
            <div class="alert alert-success"><?php echo $successMessage; ?></div>
        <?php endif; ?>
        
        <?php if ($errorMessage = getMessage('error')): ?>
            <div class="alert alert-danger"><?php echo $errorMessage; ?></div>
        <?php endif; ?>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Özel Rapor Oluştur</h1>
            <a href="reports.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Raporlara Dön
            </a>
        </div>
        
        <!-- Rapor türü seçimi -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Rapor Türü Seçin</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-primary">
                                            <i class="bi bi-file-earmark-text"></i>
                                        </div>
                                        <h5 class="card-title">Teklifler</h5>
                                        <button type="button" class="btn btn-sm btn-outline-primary mt-2 select-report-type" data-report-type="quotations">Seç</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-success">
                                            <i class="bi bi-receipt"></i>
                                        </div>
                                        <h5 class="card-title">Faturalar</h5>
                                        <button type="button" class="btn btn-sm btn-outline-success mt-2 select-report-type" data-report-type="invoices">Seç</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-info">
                                            <i class="bi bi-people"></i>
                                        </div>
                                        <h5 class="card-title">Müşteriler</h5>
                                        <button type="button" class="btn btn-sm btn-outline-info mt-2 select-report-type" data-report-type="customers">Seç</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-warning">
                                            <i class="bi bi-box"></i>
                                        </div>
                                        <h5 class="card-title">Ürünler</h5>
                                        <button type="button" class="btn btn-sm btn-outline-warning mt-2 select-report-type" data-report-type="products">Seç</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-danger">
                                            <i class="bi bi-tools"></i>
                                        </div>
                                        <h5 class="card-title">Hizmetler</h5>
                                        <button type="button" class="btn btn-sm btn-outline-danger mt-2 select-report-type" data-report-type="services">Seç</button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-2 mb-3">
                                <div class="card report-card h-100 text-center">
                                    <div class="card-body">
                                        <div class="report-icon text-secondary">
                                            <i class="bi bi-person-badge"></i>
                                        </div>
                                        <h5 class="card-title">Kullanıcılar</h5>
                                        <button type="button" class="btn btn-sm btn-outline-secondary mt-2 select-report-type" data-report-type="users">Seç</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Rapor filtresi -->
        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="reportForm">
            <input type="hidden" name="report_type" id="report_type" value="">
            <input type="hidden" name="export" id="export" value="">
            
            <div class="filter-form mb-4" id="filterContainer" style="display: none;">
                <h4 class="mb-3" id="reportFilterTitle">Rapor Filtreleri</h4>
                
                <div class="row">
                    <!-- Tarih aralığı filtreleri -->
                    <div class="col-md-3 mb-3 date-filter">
                        <label for="date_from" class="form-label">Başlangıç Tarihi</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="col-md-3 mb-3 date-filter">
                        <label for="date_to" class="form-label">Bitiş Tarihi</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <!-- Müşteri filtresi -->
                    <div class="col-md-3 mb-3 customer-filter">
                        <label for="customer_id" class="form-label">Müşteri</label>
                        <select class="form-select" id="customer_id" name="customer_id">
                            <option value="0">Tüm Müşteriler</option>
                            <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>">
                                    <?php echo htmlspecialchars($customer['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Kullanıcı filtresi -->
                    <div class="col-md-3 mb-3 user-filter">
                        <label for="user_id" class="form-label">Kullanıcı</label>
                        <select class="form-select" id="user_id" name="user_id">
                            <option value="0">Tüm Kullanıcılar</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Ürün filtresi -->
                    <div class="col-md-3 mb-3 product-filter" style="display: none;">
                        <label for="product_id" class="form-label">Ürün</label>
                        <select class="form-select" id="product_id" name="product_id">
                            <option value="0">Tüm Ürünler</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo $product['id']; ?>">
                                    <?php echo htmlspecialchars($product['code'] . ' - ' . $product['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Hizmet filtresi -->
                    <div class="col-md-3 mb-3 service-filter" style="display: none;">
                        <label for="service_id" class="form-label">Hizmet</label>
                        <select class="form-select" id="service_id" name="service_id">
                            <option value="0">Tüm Hizmetler</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo $service['id']; ?>">
                                    <?php echo htmlspecialchars($service['code'] . ' - ' . $service['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Durum filtresi -->
                    <div class="col-md-3 mb-3 status-filter">
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tüm Durumlar</option>
                            <optgroup label="Teklif Durumları" class="quotation-status">
                                <option value="draft">Taslak</option>
                                <option value="sent">Gönderildi</option>
                                <option value="accepted">Kabul Edildi</option>
                                <option value="rejected">Reddedildi</option>
                                <option value="expired">Süresi Doldu</option>
                            </optgroup>
                            <optgroup label="Fatura Durumları" class="invoice-status" style="display: none;">
                                <option value="unpaid">Ödenmedi</option>
                                <option value="partially_paid">Kısmi Ödendi</option>
                                <option value="paid">Ödendi</option>
                                <option value="cancelled">İptal Edildi</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <!-- Tutar filtresi -->
                    <div class="col-md-3 mb-3 amount-filter">
                        <label for="min_amount" class="form-label">Min. Tutar</label>
                        <input type="number" class="form-control" id="min_amount" name="min_amount" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="col-md-3 mb-3 amount-filter">
                        <label for="max_amount" class="form-label">Max. Tutar</label>
                        <input type="number" class="form-control" id="max_amount" name="max_amount" min="0" step="0.01" placeholder="0.00">
                    </div>
                    
                    <!-- Gruplama filtresi -->
                    <div class="col-md-3 mb-3 group-filter">
                        <label for="group_by" class="form-label">Gruplama</label>
                        <select class="form-select" id="group_by" name="group_by">
                            <option value="">Gruplanmasın</option>
                            <option value="customer" class="customer-group">Müşterilere Göre</option>
                            <option value="user" class="user-group">Kullanıcılara Göre</option>
                            <option value="month" class="month-group">Aylara Göre</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12 text-end">
                        <button type="button" class="btn btn-secondary me-2" id="resetBtn">Sıfırla</button>
                        <button type="button" class="btn btn-success me-2" id="exportBtn">
                            <i class="bi bi-file-excel"></i> Excel'e Aktar
                        </button>
                        <button type="submit" class="btn btn-primary" id="generateBtn">
                            <i class="bi bi-search"></i> Rapor Oluştur
                        </button>
                    </div>
                </div>
            </div>
        </form>
        
        <!-- Rapor Sonuçları -->
        <?php if ($reportGenerated): ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0"><?php echo $reportTitle; ?></h5>
                    <span>Toplam <?php echo count($reportData); ?> kayıt</span>
                </div>
                <div class="card-body">
                    <?php if (count($reportData) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                            <thead>
                                <tr>
                                    <?php foreach ($reportColumns as $key => $label): ?>
                                        <th><?php echo $label; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData as $data): ?>
                                    <tr>
                                        <?php foreach ($reportColumns as $key => $label): ?>
                                            <td>
                                                <?php 
                                                if (isset($data[$key])) {
                                                    if (in_array($key, ['total_amount', 'paid_amount', 'count'])) {
                                                        echo number_format($data[$key], 2, ',', '.');
                                                    } else {
                                                        echo htmlspecialchars($data[$key]);
                                                    }
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info">Hiç kayıt bulunamadı.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Rapor türü seçimi
    const reportTypeButtons = document.querySelectorAll('.select-report-type');
    const filterContainer = document.getElementById('filterContainer');
    const reportTypeInput = document.getElementById('report_type');
    const resetBtn = document.getElementById('resetBtn');
    const exportBtn = document.getElementById('exportBtn');
    const reportForm = document.getElementById('reportForm');
    
    reportTypeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const reportType = this.getAttribute('data-report-type');
            reportTypeInput.value = reportType;
            filterContainer.style.display = 'block';
            
            // Seçili rapor türüne göre filtreleri düzenle
            const customerFilter = document.querySelector('.customer-filter');
            const userFilter = document.querySelector('.user-filter');
            const productFilter = document.querySelector('.product-filter');
            const serviceFilter = document.querySelector('.service-filter');
            const statusFilter = document.querySelector('.status-filter');
            const amountFilter = document.querySelector('.amount-filter');
            const dateFilter = document.querySelector('.date-filter');
            const groupFilter = document.querySelector('.group-filter');
            const quotationStatus = document.querySelector('.quotation-status');
            const invoiceStatus = document.querySelector('.invoice-status');
            const customerGroup = document.querySelector('.customer-group');
            const userGroup = document.querySelector('.user-group');
            const monthGroup = document.querySelector('.month-group');
            
            // Tüm filtreleri sıfırla
            customerFilter.style.display = 'none';
            userFilter.style.display = 'none';
            productFilter.style.display = 'none';
            serviceFilter.style.display = 'none';
            statusFilter.style.display = 'none';
            amountFilter.style.display = 'none';
            dateFilter.style.display = 'none';
            groupFilter.style.display = 'none';
            quotationStatus.style.display = 'none';
            invoiceStatus.style.display = 'none';
            customerGroup.style.display = 'none';
            userGroup.style.display = 'none';
            monthGroup.style.display = 'none';
            
            // Rapor türüne göre filtreleri göster
            switch(reportType) {
                case 'quotations':
                    customerFilter.style.display = 'block';
                    userFilter.style.display = 'block';
                    statusFilter.style.display = 'block';
                    amountFilter.style.display = 'block';
                    dateFilter.style.display = 'block';
                    groupFilter.style.display = 'block';
                    quotationStatus.style.display = 'block';
                    customerGroup.style.display = 'block';
                    userGroup.style.display = 'block';
                    monthGroup.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Teklif Raporu Filtreleri';
                    break;
                    
                case 'invoices':
                    customerFilter.style.display = 'block';
                    statusFilter.style.display = 'block';
                    amountFilter.style.display = 'block';
                    dateFilter.style.display = 'block';
                    groupFilter.style.display = 'block';
                    invoiceStatus.style.display = 'block';
                    customerGroup.style.display = 'block';
                    monthGroup.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Fatura Raporu Filtreleri';
                    break;
                    
                case 'customers':
                    customerFilter.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Müşteri Raporu Filtreleri';
                    break;
                    
                case 'products':
                    productFilter.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Ürün Raporu Filtreleri';
                    break;
                    
                case 'services':
                    serviceFilter.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Hizmet Raporu Filtreleri';
                    break;
                    
                case 'users':
                    userFilter.style.display = 'block';
                    document.getElementById('reportFilterTitle').textContent = 'Kullanıcı Raporu Filtreleri';
                    break;
            }
        });
    });
    
    // Sıfırla butonu
    resetBtn.addEventListener('click', function() {
        document.getElementById('date_from').value = '';
        document.getElementById('date_to').value = '';
        document.getElementById('customer_id').value = '0';
        document.getElementById('user_id').value = '0';
        document.getElementById('product_id').value = '0';
        document.getElementById('service_id').value = '0';
        document.getElementById('status').value = '';
        document.getElementById('min_amount').value = '';
        document.getElementById('max_amount').value = '';
        document.getElementById('group_by').value = '';
    });
    
    // Excel'e Aktar butonu
    exportBtn.addEventListener('click', function() {
        document.getElementById('export').value = 'excel';
        reportForm.submit();
    });
});
</script>

<?php include 'includes/footer.php'; ?>