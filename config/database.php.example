<?php
// config/database.php - Veritabanı bağlantı bilgileri
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'root');
define('DB_NAME', 'teklif_sistemi');
define('DB_PORT', 8889);

// Veritabanı bağlantısını oluştur
function getDbConnection() {
    $conn = null;
    try {
        $conn = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec("set names utf8");
    } catch(PDOException $e) {
        echo "Veritabanı bağlantı hatası: " . $e->getMessage();
    }
    return $conn;
}
?>