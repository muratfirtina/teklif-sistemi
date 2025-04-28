<?php
// users.php - Kullanıcı yönetimi sayfası
require_once 'config/database.php';
require_once 'includes/session.php';

// Admin girişi gerekli
requireAdmin();

// Veritabanı bağlantısı
$conn = getDbConnection();

// Kullanıcı silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Mevcut kullanıcıyı silmeye çalışıyorsa engelle
    if ($id == $_SESSION['user_id']) {
        setMessage('error', 'Kendi hesabınızı silemezsiniz.');
        header("Location: users.php");
        exit;
    }
    
    try {
        // Kullanıcının teklifleri var mı kontrol et
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM quotations WHERE user_id = :id");
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        
        if ($checkStmt->fetchColumn() > 0) {
            setMessage('error', 'Bu kullanıcıya ait teklifler olduğu için silinemez.');
        } else {
            // Kullanıcının stok hareketleri var mı kontrol et
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM inventory_movements WHERE user_id = :id");
            $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->fetchColumn() > 0) {
                setMessage('error', 'Bu kullanıcıya ait stok hareketleri olduğu için silinemez.');
            } else {
                $deleteStmt = $conn->prepare("DELETE FROM users WHERE id = :id");
                $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $deleteStmt->execute();
                
                setMessage('success', 'Kullanıcı başarıyla silindi.');
            }
        }
    } catch(PDOException $e) {
        setMessage('error', 'Kullanıcı silinirken bir hata oluştu: ' . $e->getMessage());
    }
    
    header("Location: users.php");
    exit;
}

// Yeni kullanıcı ekleme işlemi
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'add') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    
    $errors = [];
    
    // Basit doğrulama
    if (empty($username)) {
        $errors[] = "Kullanıcı adı zorunludur.";
    }
    
    if (empty($password)) {
        $errors[] = "Şifre zorunludur.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Şifre en az 6 karakter olmalıdır.";
    }
    
    if (empty($email)) {
        $errors[] = "E-posta adresi zorunludur.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Geçerli bir e-posta adresi giriniz.";
    }
    
    if (empty($full_name)) {
        $errors[] = "Ad Soyad zorunludur.";
    }
    
    if (!in_array($role, ['admin', 'user'])) {
        $errors[] = "Geçerli bir kullanıcı rolü seçiniz.";
    }
    
    // Kullanıcı adı ve e-posta benzersiz mi kontrol et
    if (empty($errors)) {
        try {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = :username");
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Bu kullanıcı adı zaten kullanılmaktadır.";
            } else {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Bu e-posta adresi zaten kullanılmaktadır.";
                }
            }
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
    
    // Hata yoksa kullanıcıyı ekle
    if (empty($errors)) {
        try {
            // Şifreyi hashle
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO users (username, password, email, full_name, role) 
                                    VALUES (:username, :password, :email, :full_name, :role)");
            
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':full_name', $full_name);
            $stmt->bindParam(':role', $role);
            
            $stmt->execute();
            
            setMessage('success', 'Kullanıcı başarıyla eklendi.');
            header("Location: users.php");
            exit;
        } catch(PDOException $e) {
            $errors[] = "Veritabanı hatası: " . $e->getMessage();
        }
    }
}

// Kullanıcı listesini al
$users = [];
try {
    $stmt = $conn->query("SELECT * FROM users ORDER BY username ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    setMessage('error', 'Kullanıcı listesi alınırken bir hata oluştu: ' . $e->getMessage());
}
$pageTitle = 'Kullanıcılar';
$currentPage = 'users';
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';
?>
    <style>
        .role-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
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
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h2">Kullanıcılar</h1>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-plus-circle"></i> Yeni Kullanıcı Ekle
                </button>
            </div>
            
            <!-- Kullanıcı Tablosu -->
            <div class="card">
                <div class="card-body">
                    <?php if (count($users) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Kullanıcı Adı</th>
                                        <th>Ad Soyad</th>
                                        <th>E-posta</th>
                                        <th>Rol</th>
                                        <th>Kayıt Tarihi</th>
                                        <th>İşlemler</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo $user['id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <?php if ($user['role'] == 'admin'): ?>
                                                    <span class="badge bg-danger role-badge">Yönetici</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info role-badge">Kullanıcı</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning" title="Düzenle">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                    <a href="javascript:void(0);" onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['username'])); ?>')" class="btn btn-sm btn-danger" title="Sil">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-center">Henüz kullanıcı bulunmamaktadır.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel">Yeni Kullanıcı Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Kullanıcı Adı <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>">
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Şifre <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <div class="form-text">En az 6 karakter olmalıdır.</div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">E-posta <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?php echo isset($email) ? htmlspecialchars($email) : ''; ?>">
                        </div>
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Ad Soyad <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required value="<?php echo isset($full_name) ? htmlspecialchars($full_name) : ''; ?>">
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Rol <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="user" <?php echo (isset($role) && $role == 'user') ? 'selected' : ''; ?>>Kullanıcı</option>
                                <option value="admin" <?php echo (isset($role) && $role == 'admin') ? 'selected' : ''; ?>>Yönetici</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Kullanıcı Silme Onayı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="deleteConfirmText">Bu kullanıcıyı silmek istediğinizden emin misiniz?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Sil</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Silme onay fonksiyonu
        function confirmDelete(id, username) {
            document.getElementById('deleteConfirmText').textContent = '"' + username + '" kullanıcısını silmek istediğinizden emin misiniz?';
            document.getElementById('confirmDeleteBtn').href = 'users.php?delete=' + id;
            
            var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
    </script>
</body>
</html>