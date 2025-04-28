<?php
// backup_restore.php - Veri yedekleme ve geri yükleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Sadece admin erişebilir
requireAdmin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Yedekleme dizini kontrolü
$backupDir = 'backups';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// .htaccess dosyası oluştur (eğer yoksa)
$htaccessFile = $backupDir . '/.htaccess';
if (!file_exists($htaccessFile)) {
    $htaccessContent = "Order deny,allow\nDeny from all";
    file_put_contents($htaccessFile, $htaccessContent);
}

// Tablo listesini al
$tables = [];
try {
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
} catch(PDOException $e) {
    setMessage('error', 'Tablo listesi alınırken bir hata oluştu: ' . $e->getMessage());
}

// Mevcut yedekleri al
$backups = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && $file != '.htaccess' && pathinfo($file, PATHINFO_EXTENSION) == 'sql') {
            $backupFile = $backupDir . '/' . $file;
            $backups[] = [
                'file' => $file,
                'path' => $backupFile,
                'size' => filesize($backupFile),
                'date' => date('d.m.Y H:i:s', filemtime($backupFile))
            ];
        }
    }
    
    // Tarihe göre sırala (en yeni en üstte)
    usort($backups, function($a, $b) {
        return filemtime($b['path']) - filemtime($a['path']);
    });
}

// Yedekleme işlemi
if (isset($_POST['action']) && $_POST['action'] == 'backup') {
    $tables = isset($_POST['tables']) ? $_POST['tables'] : [];
    
    if (empty($tables)) {
        setMessage('error', 'Lütfen en az bir tablo seçin.');
    } else {
        try {
            $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.sql';
            $fileHandle = fopen($backupFile, 'w');
            
            if ($fileHandle === false) {
                throw new Exception('Yedekleme dosyası oluşturulamadı.');
            }
            
            // Yedekleme bilgisi ekle
            fwrite($fileHandle, "-- Teklif Yönetim Sistemi - Veritabanı Yedeği\n");
            fwrite($fileHandle, "-- Oluşturma Tarihi: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fileHandle, "-- Veritabanı: " . DB_NAME . "\n\n");
            
            // Her tablo için
            foreach ($tables as $table) {
                // Tablo yapısını al
                $stmt = $conn->query("SHOW CREATE TABLE `$table`");
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $createTable = $row[1];
                
                fwrite($fileHandle, "-- Tablo Yapısı: `$table`\n");
                fwrite($fileHandle, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($fileHandle, "$createTable;\n\n");
                
                // Tablo verilerini al
                $rows = $conn->query("SELECT * FROM `$table`");
                $numFields = $rows->columnCount();
                
                fwrite($fileHandle, "-- Tablo Verileri: `$table`\n");
                
                $rowCount = 0;
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $rowCount++;
                    
                    // Her 100 satırda bir INSERT oluştur
                    if ($rowCount == 1) {
                        fwrite($fileHandle, "INSERT INTO `$table` VALUES\n");
                    }
                    
                    fwrite($fileHandle, "(");
                    for ($i = 0; $i < $numFields; $i++) {
                        if (is_null($row[$i])) {
                            fwrite($fileHandle, "NULL");
                        } else {
                            $row[$i] = addslashes($row[$i]);
                            $row[$i] = str_replace("\n", "\\n", $row[$i]);
                            fwrite($fileHandle, "'" . $row[$i] . "'");
                        }
                        
                        if ($i < ($numFields - 1)) {
                            fwrite($fileHandle, ",");
                        }
                    }
                    
                    if ($rowCount % 100 == 0 || $rowCount == $rows->rowCount()) {
                        fwrite($fileHandle, ");\n");
                        $rowCount = 0;
                    } else {
                        fwrite($fileHandle, "),\n");
                    }
                }
                
                fwrite($fileHandle, "\n\n");
            }
            
            fclose($fileHandle);
            
            setMessage('success', 'Veritabanı yedeği başarıyla oluşturuldu: ' . basename($backupFile));
            header("Location: backup_restore.php");
            exit;
        } catch(Exception $e) {
            setMessage('error', 'Yedekleme işlemi sırasında bir hata oluştu: ' . $e->getMessage());
        }
    }
}

// Yedek silme işlemi
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $fileName = basename($_GET['delete']);
    $filePath = $backupDir . '/' . $fileName;
    
    if (file_exists($filePath) && unlink($filePath)) {
        setMessage('success', 'Yedek dosyası başarıyla silindi: ' . $fileName);
    } else {
        setMessage('error', 'Yedek dosyası silinemedi: ' . $fileName);
    }
    
    header("Location: backup_restore.php");
    exit;
}

// Yedek indirme işlemi
if (isset($_GET['download']) && !empty($_GET['download'])) {
    $fileName = basename($_GET['download']);
    $filePath = $backupDir . '/' . $fileName;
    
    if (file_exists($filePath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        flush();
        readfile($filePath);
        exit;
    } else {
        setMessage('error', 'Yedek dosyası bulunamadı: ' . $fileName);
        header("Location: backup_restore.php");
        exit;
    }
}

// Geri yükleme işlemi
if (isset($_POST['action']) && $_POST['action'] == 'restore') {
    if (isset($_POST['backup_file']) && !empty($_POST['backup_file'])) {
        $fileName = basename($_POST['backup_file']);
        $filePath = $backupDir . '/' . $fileName;
        
        if (file_exists($filePath)) {
            try {
                // Dosyayı oku
                $sqlContent = file_get_contents($filePath);
                
                // SQL komutlarını ayır
                $sqlCommands = explode(';', $sqlContent);
                
                // İşlem başlat
                $conn->beginTransaction();
                
                foreach ($sqlCommands as $command) {
                    $command = trim($command);
                    if (!empty($command)) {
                        $conn->exec($command . ';');
                    }
                }
                
                // İşlemi tamamla
                $conn->commit();
                
                setMessage('success', 'Veritabanı başarıyla geri yüklendi: ' . $fileName);
            } catch(PDOException $e) {
                // Hata durumunda geri al
                $conn->rollBack();
                setMessage('error', 'Geri yükleme işlemi sırasında bir hata oluştu: ' . $e->getMessage());
            }
        } else {
            setMessage('error', 'Yedek dosyası bulunamadı: ' . $fileName);
        }
    } else {
        setMessage('error', 'Lütfen bir yedek dosyası seçin.');
    }
    
    header("Location: backup_restore.php");
    exit;
}

// Yedek yükleme işlemi
if (isset($_POST['action']) && $_POST['action'] == 'upload') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == 0) {
        $fileName = $_FILES['backup_file']['name'];
        $fileSize = $_FILES['backup_file']['size'];
        $fileTmpName = $_FILES['backup_file']['tmp_name'];
        $fileType = $_FILES['backup_file']['type'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Sadece SQL dosyalarına izin ver
        if ($fileExtension == 'sql') {
            // Benzersiz dosya adı oluştur
            $newFileName = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $destination = $backupDir . '/' . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $destination)) {
                setMessage('success', 'Yedek dosyası başarıyla yüklendi: ' . $newFileName);
            } else {
                setMessage('error', 'Yedek dosyası yüklenirken bir hata oluştu.');
            }
        } else {
            setMessage('error', 'Sadece SQL dosyaları yüklenebilir.');
        }
    } else {
        setMessage('error', 'Lütfen bir dosya seçin.');
    }
    
    header("Location: backup_restore.php");
    exit;
}

// Dosya boyutu formatla
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
$pageTitle = 'Veri Yedekleme ve Geri Yükleme';
$currentPage = 'backup_restore';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veri Yedekleme ve Geri Yükleme - Teklif Yönetim Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>

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
            
            <h1 class="h2 mb-4">Veri Yedekleme ve Geri Yükleme</h1>
            
            <div class="warning-box">
                <div class="d-flex align-items-center">
                    <div class="text-warning me-3">
                        <i class="bi bi-exclamation-triangle-fill fs-1"></i>
                    </div>
                    <div>
                        <h5 class="mb-1">Önemli Uyarı</h5>
                        <p class="mb-0">Geri yükleme işlemi mevcut veritabanınızın üzerine yazacaktır. Bu işlem geri alınamaz. İşleme devam etmeden önce yeni bir yedek almanız önerilir.</p>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <!-- Veritabanı Yedekleme -->
                <div class="col-md-6 mb-4">
                    <div class="card backup-card h-100">
                        <div class="backup-header">
                            <h5 class="card-title mb-0">Veritabanı Yedekleme</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="info-icon mb-3">
                                    <i class="bi bi-database-add"></i>
                                </div>
                                <h5>Veritabanı Yedeği Oluştur</h5>
                                <p>Tüm veritabanınızı veya seçtiğiniz tabloları yedekleyin.</p>
                            </div>
                            
                            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                <input type="hidden" name="action" value="backup">
                                
                                <div class="mb-3">
                                    <label class="form-label">Yedeklenecek Tablolar</label>
                                    <div class="row">
                                        <?php foreach ($tables as $table): ?>
                                            <div class="col-md-6 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="tables[]" value="<?php echo $table; ?>" id="table_<?php echo $table; ?>" checked>
                                                    <label class="form-check-label" for="table_<?php echo $table; ?>">
                                                        <?php echo $table; ?>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-link" onclick="toggleAllCheckboxes()">Tümünü Seç/Kaldır</button>
                                    <button type="submit" class="btn btn-primary">Yedek Oluştur</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Yedekten Geri Yükleme -->
                <div class="col-md-6 mb-4">
                    <div class="card backup-card h-100">
                        <div class="backup-header">
                            <h5 class="card-title mb-0">Geri Yükleme ve Yedek Yükleme</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-4">
                                <div class="info-icon mb-3">
                                    <i class="bi bi-database-arrow-up"></i>
                                </div>
                                <h5>Veritabanını Geri Yükle</h5>
                                <p>Mevcut bir yedekten veritabanınızı geri yükleyin.</p>
                            </div>
                            
                            <?php if (count($backups) > 0): ?>
                                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="mb-4">
                                    <input type="hidden" name="action" value="restore">
                                    
                                    <div class="mb-3">
                                        <label for="backup_file" class="form-label">Yedek Dosyası Seçin</label>
                                        <select class="form-select" id="backup_file" name="backup_file" required>
                                            <option value="">Seçiniz</option>
                                            <?php foreach ($backups as $backup): ?>
                                                <option value="<?php echo $backup['file']; ?>">
                                                    <?php echo $backup['file'] . ' (' . $backup['date'] . ', ' . formatFileSize($backup['size']) . ')'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-warning" onclick="return confirm('DİKKAT! Bu işlem mevcut veritabanının üzerine yazacak ve geri alınamayacaktır. Devam etmek istediğinizden emin misiniz?');">Geri Yükle</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    Henüz yedek bulunmamaktadır.
                                </div>
                            <?php endif; ?>
                            
                            <hr>
                            
                            <h5 class="mb-3">Yedek Yükle</h5>
                            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="upload">
                                
                                <div class="mb-3">
                                    <label for="backup_upload" class="form-label">SQL Dosyasını Seçin</label>
                                    <input type="file" class="form-control" id="backup_upload" name="backup_file" accept=".sql" required>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-info">Yedek Yükle</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Mevcut Yedekler -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Mevcut Yedekler</h5>
                </div>
                <div class="card-body">
                    <?php if (count($backups) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Dosya Adı</th>
                                        <th>Tarih</th>
                                        <th>Boyut</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($backup['file']); ?></td>
                                            <td><?php echo $backup['date']; ?></td>
                                            <td><?php echo formatFileSize($backup['size']); ?></td>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?download=' . urlencode($backup['file'])); ?>" class="btn btn-sm btn-primary action-btn" title="İndir">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?delete=' . urlencode($backup['file'])); ?>" class="btn btn-sm btn-danger action-btn" title="Sil" onclick="return confirm('Bu yedek dosyasını silmek istediğinizden emin misiniz?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center p-3">
                            <p>Henüz yedek bulunmamaktadır.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAllCheckboxes() {
            let checkboxes = document.querySelectorAll('input[name="tables[]"]');
            let allChecked = true;
            
            // Tüm checkbox'lar seçili mi kontrol et
            for (let i = 0; i < checkboxes.length; i++) {
                if (!checkboxes[i].checked) {
                    allChecked = false;
                    break;
                }
            }
            
            // Duruma göre seç veya kaldır
            for (let i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = !allChecked;
            }
        }
    </script>
</body>
</html>