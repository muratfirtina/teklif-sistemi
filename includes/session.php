<?php
// includes/session.php - Oturum yönetimi
session_start();

// Kullanıcının giriş yapmış olup olmadığını kontrol eder
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Kullanıcının admin olup olmadığını kontrol eder
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] == 'admin';
}

// Giriş yapmamış kullanıcıları giriş sayfasına yönlendirir
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

// Sadece admin kullanıcıların erişebileceği sayfalar için
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $_SESSION['error'] = "Bu sayfaya erişim izniniz bulunmamaktadır.";
        header("Location: index.php");
        exit;
    }
}

// Hata ve bildirim mesajları için yardımcı fonksiyonlar
function setMessage($type, $message) {
    $_SESSION[$type] = $message;
}

function getMessage($type) {
    if (isset($_SESSION[$type])) {
        $message = $_SESSION[$type];
        unset($_SESSION[$type]);
        return $message;
    }
    return null;
}
?>