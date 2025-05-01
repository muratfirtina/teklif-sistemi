<?php
// login.php - Kullanıcı giriş sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Kullanıcı zaten giriş yapmış ise ana sayfaya yönlendir
if (isLoggedIn()) {
    header("Location: index.php");
    exit;
}

$error = "";

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Basit doğrulama
    if (empty($username) || empty($password)) {
        $error = "Lütfen kullanıcı adı ve şifre giriniz.";
    } else {
        try {
            $conn = getDbConnection();
            
            // Kullanıcı adına göre kullanıcıyı bul
            $stmt = $conn->prepare("SELECT id, username, password, email, full_name, role FROM users WHERE username = :username");
            $stmt->bindParam(":username", $username);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Şifreyi doğrula
                if (password_verify($password, $user['password'])) {
                    // Oturum bilgilerini ayarla
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_fullname'] = $user['full_name'];
                    $_SESSION['user_role'] = $user['role'];
                    
                    // Kullanıcıyı eğer admin veya user rolüne sahipse ana sayfaya yönlendir. production rolüne sahipse production sayfasına yönlendir.
                    if ($user['role'] == 'admin' || $user['role'] == 'user') {
                        header("Location: index.php");
                    } elseif ($user['role'] == 'production') {
                        header("Location: production.php");
                    } else {
                        $error = "Geçersiz kullanıcı rolü.";
                    }
                    exit;
                } else {
                    $error = "Geçersiz kullanıcı adı veya şifre.";
                }
            } else {
                $error = "Geçersiz kullanıcı adı veya şifre.";
            }
        } catch(PDOException $e) {
            $error = "Hata: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş - Teklif Yönetim Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .login-logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .login-logo h1 {
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="login-logo">
                <h1>Teklif Sistemi</h1>
                <p>Giriş Yapın</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="mb-3">
                    <label for="username" class="form-label">Kullanıcı Adı</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Şifre</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Giriş Yap</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>