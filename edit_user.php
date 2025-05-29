<?php
// edit_user.php - Kullanıcı düzenleme sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Admin girişi gerekli
requireAdmin();

// --- YENİ: İmza Yükleme Yolu ---
define('SIGNATURE_UPLOAD_DIR', 'uploads/signatures/'); // Bu klasörün var olduğundan ve yazılabilir olduğundan emin olun
// Ana dizinde uploads/signatures klasörü oluşturun.

// ID kontrolü
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    setMessage('error', 'Geçersiz kullanıcı ID\'si.');
    header("Location: users.php");
    exit;
}

$user_id = intval($_GET['id']);
$conn = getDbConnection();

// Kullanıcı bilgilerini al (imza alanları dahil)
try {
    // --- GÜNCELLEME: SQL sorgusuna imza alanları eklendi ---
    $stmt = $conn->prepare("SELECT id, username, email, full_name, role, email_signature, signature_image FROM users WHERE id = :id");
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();

    if ($stmt->rowCount() == 0) {
        setMessage('error', 'Kullanıcı bulunamadı.');
        header("Location: users.php");
        exit;
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    setMessage('error', 'Kullanıcı bilgileri alınırken hata oluştu: ' . $e->getMessage());
    header("Location: users.php");
    exit;
}

// Form gönderildi mi kontrol et
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Form verilerini al
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    $password = trim($_POST['password']);
    $password_confirm = trim($_POST['password_confirm']);
    // --- YENİ: İmza verilerini al ---
    $email_signature = trim($_POST['email_signature']);
    $delete_signature_image = isset($_POST['delete_signature_image']) ? true : false;

    $errors = [];

    // --- Doğrulamalar (önceki kod aynı) ---
    if (empty($username))
        $errors[] = "Kullanıcı adı zorunludur.";
    if (empty($email))
        $errors[] = "E-posta adresi zorunludur.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = "Geçerli bir e-posta adresi giriniz.";
    if (empty($full_name))
        $errors[] = "Ad Soyad zorunludur.";
    if (!in_array($role, ['admin', 'user', 'production']))
        $errors[] = "Geçerli bir kullanıcı rolü seçiniz.";
    if (!empty($password)) {
        if (strlen($password) < 6)
            $errors[] = "Şifre en az 6 karakter olmalıdır.";
        elseif ($password !== $password_confirm)
            $errors[] = "Şifreler eşleşmiyor.";
    }
    // --- Benzersizlik kontrolleri (önceki kod aynı) ---
    if (empty($errors)) {
        try {
            $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND id != :id");
            $stmtCheck->bindParam(':username', $username);
            $stmtCheck->bindParam(':id', $user_id);
            $stmtCheck->execute();
            if ($stmtCheck->fetchColumn() > 0)
                $errors[] = "Bu kullanıcı adı zaten kullanılıyor.";

            $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
            $stmtCheck->bindParam(':email', $email);
            $stmtCheck->bindParam(':id', $user_id);
            $stmtCheck->execute();
            if ($stmtCheck->fetchColumn() > 0)
                $errors[] = "Bu e-posta adresi zaten kullanılıyor.";
        } catch (PDOException $e) {
            $errors[] = "Veritabanı hatası (benzersizlik kontrolü): " . $e->getMessage();
        }
    }
    // --- YENİ: İmza Resmi İşlemleri ---
    $new_signature_filename = $user['signature_image']; // Varsayılan olarak mevcut resmi tut
    $old_signature_image = $user['signature_image']; // Eski resmi silmek için sakla

    // Resim silme isteği varsa
    if ($delete_signature_image && !empty($old_signature_image)) {
        $old_path = SIGNATURE_UPLOAD_DIR . $old_signature_image;
        if (file_exists($old_path)) {
            unlink($old_path); // Eski dosyayı sil
        }
        $new_signature_filename = null; // Veritabanından da kaldır
        $old_signature_image = null; // Artık eski resim yok
    }

    // Yeni resim yüklendiyse
    if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['signature_image'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 2 * 1024 * 1024; // 2 MB

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Geçersiz dosya türü. Sadece JPG, PNG, GIF yükleyebilirsiniz.";
        } elseif ($file['size'] > $max_size) {
            $errors[] = "Dosya boyutu çok büyük. Maksimum 2MB.";
        } else {
            // Benzersiz dosya adı oluştur
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename_base = 'signature_' . $user_id . '_' . time();
            $new_signature_filename = $filename_base . '.' . $extension;
            $upload_path = SIGNATURE_UPLOAD_DIR . $new_signature_filename;

            // Yükleme klasörünü kontrol et/oluştur
            if (!is_dir(SIGNATURE_UPLOAD_DIR)) {
                if (!mkdir(SIGNATURE_UPLOAD_DIR, 0755, true)) {
                    $errors[] = "İmza yükleme klasörü oluşturulamadı.";
                }
            }

            if (empty($errors)) {
                // Eski resmi sil (yeni yükleniyorsa)
                if (!empty($old_signature_image) && $old_signature_image !== $new_signature_filename) { // $old_signature_image null değilse ve yeni dosya adı farklıysa
                    $old_path = SIGNATURE_UPLOAD_DIR . $old_signature_image;
                    if (file_exists($old_path)) {
                        unlink($old_path);
                    }
                }
                // Yeni resmi taşı
                if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $errors[] = "İmza resmi yüklenirken bir hata oluştu.";
                    $new_signature_filename = $user['signature_image']; // Hata olursa eski resme geri dön
                }
                // Başarılı yüklemede $new_signature_filename zaten set edilmişti.
            }
        }
    }

    // Hata yoksa veritabanını güncelle
    if (empty($errors)) {
        try {
            $sql = "UPDATE users SET
                    username = :username,
                    email = :email,
                    full_name = :full_name,
                    role = :role,
                    email_signature = :email_signature,
                    signature_image = :signature_image"; // İmza alanları eklendi

            // Şifre güncellenecekse SQL'e ekle
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $sql .= ", password = :password ";
            }

            $sql .= " WHERE id = :id";

            $stmt = $conn->prepare($sql);

            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':role', $role);
            // --- YENİ: İmza parametreleri ---
            $stmt->bindParam(':email_signature', $email_signature);
            $stmt->bindParam(':signature_image', $new_signature_filename); // Yeni dosya adını veya null'ı kaydet
            $stmt->bindParam(':id', $user_id);

            if (!empty($password)) {
                $stmt->bindParam(':password', $hashed_password);
            }

            $stmt->execute();

            setMessage('success', 'Kullanıcı başarıyla güncellendi.');
            header("Location: users.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }

    // Hata varsa veya POST değilse, formda gösterilecek değerleri ayarla
    // (Bu kısım hata durumunda formun tekrar doldurulması için)
    $user['username'] = $_POST['username'] ?? $user['username'];
    $user['email'] = $_POST['email'] ?? $user['email'];
    $user['full_name'] = $_POST['full_name'] ?? $user['full_name'];
    $user['role'] = $_POST['role'] ?? $user['role'];
    $user['email_signature'] = $_POST['email_signature'] ?? $user['email_signature'];
    // Resim hata durumunda tekrar yüklenemez, mevcut olan gösterilir
}

$pageTitle = 'Kullanıcı Düzenle';
$currentPage = 'users';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
<style>
    .signature-preview {
        max-width: 200px;
        max-height: 80px;
        border: 1px solid #ccc;
        padding: 5px;
        margin-top: 10px;
    }
</style>
<!-- Main Content -->
<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Kullanıcı Düzenle: <?php echo htmlspecialchars($user['username']); ?></h1>
            <a href="users.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Kullanıcılara Dön
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"] . '?id=' . $user_id); ?>"
                    enctype="multipart/form-data">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Kullanıcı Adı <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required
                                value="<?php echo htmlspecialchars($user['username']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="full_name" class="form-label">Ad Soyad <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required
                                value="<?php echo htmlspecialchars($user['full_name']); ?>">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label">E-posta <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required
                                value="<?php echo htmlspecialchars($user['email']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="role" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required <?php echo ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin') ? 'disabled' : ''; ?>>
                                <option value="user" <?php echo ($user['role'] == 'user') ? 'selected' : ''; ?>>Kullanıcı
                                </option>
                                <option value="production" <?php echo ($user['role'] == 'production') ? 'selected' : ''; ?>>Üretim</option>
                                <option value="admin" <?php echo ($user['role'] == 'admin') ? 'selected' : ''; ?>>Yönetici
                                </option>
                            </select>
                            <?php if ($user['id'] == $_SESSION['user_id'] && $user['role'] == 'admin'): ?>
                                <div class="form-text text-danger">Kendi rolünüzü değiştiremezsiniz.</div>
                                <input type="hidden" name="role" value="admin">
                                <!-- Form gönderildiğinde rolün değişmemesi için -->
                            <?php endif; ?>
                        </div>
                    </div>
                    <hr>
                    <h5 class="mb-3">Şifre Değiştir (İsteğe Bağlı)</h5>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label">Yeni Şifre</label>
                            <input type="password" class="form-control" id="password" name="password"
                                autocomplete="new-password">
                            <div class="form-text">Boş bırakırsanız şifre değişmez. En az 6 karakter.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="password_confirm" class="form-label">Yeni Şifre (Tekrar)</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                                autocomplete="new-password">
                        </div>
                    </div>

                    <!-- YENİ: İmza Ayarları -->
                    <hr>
                    <h5 class="mb-3">E-posta İmzası Ayarları</h5>
                    <div class="mb-3">
                        <label for="email_signature" class="form-label">İmza Metni</label>
                        <textarea class="form-control" id="email_signature" name="email_signature"
                            rows="4"><?php echo htmlspecialchars($user['email_signature'] ?? ''); ?></textarea>
                        <div class="form-text">E-postaların sonuna eklenecek metin. Stil (yazı tipi, renk) `mailto:` ile
                            kullanılamaz, sadece sunucu taraflı gönderimde uygulanır.</div>
                    </div>
                    <div class="mb-3">
                        <label for="signature_image" class="form-label">İmza Resmi</label>
                        <input class="form-control" type="file" id="signature_image" name="signature_image"
                            accept="image/png, image/jpeg, image/gif">
                        <div class="form-text">Mevcut resmi değiştirmek için yeni bir resim seçin (PNG, JPG, GIF - Maks
                            2MB).</div>
                    </div>

                    <?php if (!empty($user['signature_image'])): ?>
                        <div class="mb-3">
                            <label class="form-label">Mevcut İmza Resmi:</label>
                            <div>
                                <img src="<?php echo SIGNATURE_UPLOAD_DIR . htmlspecialchars($user['signature_image']); ?>?t=<?php echo time(); // Cache'i önlemek için ?>"
                                    alt="İmza Resmi" class="signature-preview">
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" value="1" id="delete_signature_image"
                                    name="delete_signature_image">
                                <label class="form-check-label" for="delete_signature_image">
                                    Mevcut resmi sil
                                </label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <!-- İmza Ayarları Sonu -->

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                        <a href="users.php" class="btn btn-secondary me-md-2">İptal</a>
                        <button type="submit" class="btn btn-primary">Kullanıcıyı Güncelle</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>