<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

$success = '';
$error = '';

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCSRF($csrf)) {
        $error = 'انتهت صلاحية الجلسة. يرجى إعادة المحاولة.';
    } else {
        try {
            switch ($action) {
                case 'create':
                    // Create new user
                    $name = trim($_POST['name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $password = $_POST['password'] ?? '';
                    $role = $_POST['role'] ?? 'student';
                    $grade = $_POST['grade'] ?? null;

                    if (empty($name) || empty($email) || empty($password)) {
                        throw new Exception('جميع الحقول مطلوبة');
                    }

                    // Check if email exists
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('البريد الإلكتروني مستخدم بالفعل');
                    }

                    // Insert user
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, password, role, grade, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $name,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $role,
                        $grade
                    ]);

                    $success = 'تم إنشاء المستخدم بنجاح';
                    break;

                case 'update':
                    // Update user
                    $userId = intval($_POST['user_id'] ?? 0);
                    $name = trim($_POST['name'] ?? '');
                    $email = trim($_POST['email'] ?? '');
                    $role = $_POST['role'] ?? 'student';
                    $grade = $_POST['grade'] ?? null;
                    $is_active = isset($_POST['is_active']) ? 1 : 0;

                    if ($userId <= 0 || empty($name) || empty($email)) {
                        throw new Exception('البيانات غير صحيحة');
                    }

                    // Check if email exists for another user
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $userId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('البريد الإلكتروني مستخدم بالفعل');
                    }

                    // Update user
                    $sql = "UPDATE users SET name = ?, email = ?, role = ?, grade = ?, is_active = ?";
                    $params = [$name, $email, $role, $grade, $is_active];

                    // Update password if provided
                    if (!empty($_POST['password'])) {
                        $sql .= ", password = ?";
                        $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    }

                    $sql .= " WHERE id = ?";
                    $params[] = $userId;

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);

                    $success = 'تم تحديث المستخدم بنجاح';
                    break;

                case 'delete':
                    // Delete user
                    $userId = intval($_POST['user_id'] ?? 0);

                    if ($userId <= 0) {
                        throw new Exception('معرف المستخدم غير صحيح');
                    }

                    // Prevent deleting yourself
                    if ($userId == $_SESSION['user_id']) {
                        throw new Exception('لا يمكنك حذف حسابك الخاص');
                    }

                    // Delete user (cascade will handle related records)
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);

                    $success = 'تم حذف المستخدم بنجاح';
                    break;

                case 'toggle_status':
                    // Toggle active status
                    $userId = intval($_POST['user_id'] ?? 0);

                    if ($userId <= 0) {
                        throw new Exception('معرف المستخدم غير صحيح');
                    }

                    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$userId]);

                    $success = 'تم تحديث حالة المستخدم';
                    break;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get filter parameters
$filterRole = $_GET['role'] ?? '';
$filterGrade = $_GET['grade'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Build query
$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM attempts WHERE user_id = u.id) as quiz_count,
        (SELECT COUNT(*) FROM user_achievements WHERE user_id = u.id) as achievement_count
        FROM users u WHERE 1=1";
$params = [];

// Apply filters
if ($filterRole) {
    $sql .= " AND u.role = ?";
    $params[] = $filterRole;
}

if ($filterGrade) {
    $sql .= " AND u.grade = ?";
    $params[] = $filterGrade;
}

if ($searchTerm) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

// Sorting
$allowedSorts = ['name', 'email', 'role', 'created_at', 'total_points', 'grade'];
$allowedOrders = ['ASC', 'DESC'];

if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'created_at';
}
if (!in_array($sortOrder, $allowedOrders)) {
    $sortOrder = 'DESC';
}

$sql .= " ORDER BY u.$sortBy $sortOrder";

// Execute query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$roleStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get grades for filter
$grades = getSetting('grades', []);

// Generate CSRF token
$csrfToken = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المستخدمين - <?= e(getSetting('site_name')) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ 
        sidebarOpen: window.innerWidth > 768,
        showCreateModal: false,
        showEditModal: false,
        editingUser: null
    }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
            class="bg-gray-800 text-white transition-all duration-300 ease-in-out">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" x-transition class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen"
                        class="text-gray-400 hover:text-white focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>

                <nav class="space-y-2">
                    <a href="./" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-tachometer-alt w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الرئيسية</span>
                    </a>
                    <a href="users.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
                        <i class="fas fa-users w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المستخدمون</span>
                    </a>
                    <a href="quizzes.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-question-circle w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الاختبارات</span>
                    </a>
                    <a href="subjects.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-book w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المواد الدراسية</span>
                    </a>
                    <a href="achievements.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-trophy w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإنجازات</span>
                    </a>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-chart-bar w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">التقارير</span>
                    </a>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-cog w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإعدادات</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 w-full p-4 border-t border-gray-700">
                <a href="logout.php"
                    class="flex items-center p-2 rounded hover:bg-gray-700 transition-colors text-red-400">
                    <i class="fas fa-sign-out-alt w-6"></i>
                    <span x-show="sidebarOpen" x-transition class="mr-3">تسجيل الخروج</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-x-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">إدارة المستخدمين</h1>
                            <p class="text-gray-600 mt-1">إدارة حسابات المستخدمين والصلاحيات</p>
                        </div>
                        <button @click="showCreateModal = true" class="btn btn-primary">
                            <i class="fas fa-user-plus ml-2"></i>
                            إضافة مستخدم
                        </button>
                    </div>
                </div>
            </header>

            <!-- Content -->
            <div class="p-6">
                <!-- Success/Error Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success mb-6">
                        <i class="fas fa-check-circle"></i>
                        <span><?= e($success) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error mb-6">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="card bg-blue-100 border-blue-500 border">
                        <div class="card-body">
                            <h3 class="card-title text-blue-700">المعلمون</h3>
                            <p class="text-3xl font-bold text-blue-800"><?= number_format($roleStats['teacher'] ?? 0) ?>
                            </p>
                        </div>
                    </div>
                    <div class="card bg-green-100 border-green-500 border">
                        <div class="card-body">
                            <h3 class="card-title text-green-700">الطلاب</h3>
                            <p class="text-3xl font-bold text-green-800">
                                <?= number_format($roleStats['student'] ?? 0) ?>
                            </p>
                        </div>
                    </div>
                    <div class="card bg-purple-100 border-purple-500 border">
                        <div class="card-body">
                            <h3 class="card-title text-purple-700">المدراء</h3>
                            <p class="text-3xl font-bold text-purple-800"><?= number_format($roleStats['admin'] ?? 0) ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card bg-base-100 shadow-xl mb-6">
                    <div class="card-body">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="label">
                                    <span class="label-text">البحث</span>
                                </label>
                                <input type="text" name="search" value="<?= e($searchTerm) ?>"
                                    placeholder="اسم أو بريد إلكتروني..." class="input input-bordered w-full">
                            </div>

                            <div>
                                <label class="label">
                                    <span class="label-text">الصلاحية</span>
                                </label>
                                <select name="role" class="select select-bordered w-full">
                                    <option value="">جميع الصلاحيات</option>
                                    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>مدير</option>
                                    <option value="teacher" <?= $filterRole === 'teacher' ? 'selected' : '' ?>>معلم
                                    </option>
                                    <option value="student" <?= $filterRole === 'student' ? 'selected' : '' ?>>طالب
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="label">
                                    <span class="label-text">الصف</span>
                                </label>
                                <select name="grade" class="select select-bordered w-full">
                                    <option value="">جميع الصفوف</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?= $i ?>" <?= $filterGrade == $i ? 'selected' : '' ?>>
                                            <?= e($grades[$i - 1] ?? "الصف $i") ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div>
                                <label class="label">
                                    <span class="label-text">ترتيب حسب</span>
                                </label>
                                <select name="sort" class="select select-bordered w-full">
                                    <option value="created_at" <?= $sortBy === 'created_at' ? 'selected' : '' ?>>تاريخ
                                        التسجيل</option>
                                    <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>الاسم</option>
                                    <option value="total_points" <?= $sortBy === 'total_points' ? 'selected' : '' ?>>النقاط
                                    </option>
                                    <option value="role" <?= $sortBy === 'role' ? 'selected' : '' ?>>الصلاحية</option>
                                </select>
                            </div>

                            <div class="md:col-span-4 flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search ml-2"></i>
                                    بحث
                                </button>
                                <a href="users.php" class="btn btn-ghost">
                                    <i class="fas fa-redo ml-2"></i>
                                    إعادة تعيين
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>المستخدم</th>
                                        <th>البريد الإلكتروني</th>
                                        <th>الصلاحية</th>
                                        <th>الصف</th>
                                        <th>النقاط</th>
                                        <th>النشاط</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="flex items-center gap-3">
                                                    <div class="avatar">
                                                        <div
                                                            class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                                                            <?= mb_substr($user['name'], 0, 1) ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <div class="font-bold"><?= e($user['name']) ?></div>
                                                        <div class="text-sm opacity-50">
                                                            <?= timeAgo($user['created_at']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= e($user['email']) ?></td>
                                            <td>
                                                <?php
                                                $roleColors = [
                                                    'admin' => 'badge-primary',
                                                    'teacher' => 'badge-info',
                                                    'student' => 'badge-success'
                                                ];
                                                $roleNames = [
                                                    'admin' => 'مدير',
                                                    'teacher' => 'معلم',
                                                    'student' => 'طالب'
                                                ];
                                                ?>
                                                <span class="badge <?= $roleColors[$user['role']] ?? 'badge-ghost' ?>">
                                                    <?= $roleNames[$user['role']] ?? $user['role'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['grade']): ?>
                                                    <span class="badge badge-outline">
                                                        <?= e($grades[$user['grade'] - 1] ?? "الصف {$user['grade']}") ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <i class="fas fa-coins text-yellow-500"></i>
                                                    <?= number_format($user['total_points']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-sm">
                                                    <div class="flex items-center gap-2">
                                                        <i class="fas fa-clipboard-check text-blue-500"></i>
                                                        <?= $user['quiz_count'] ?> اختبار
                                                    </div>
                                                    <div class="flex items-center gap-2">
                                                        <i class="fas fa-trophy text-yellow-500"></i>
                                                        <?= $user['achievement_count'] ?> إنجاز
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit"
                                                        class="btn btn-xs <?= $user['is_active'] ? 'btn-success' : 'btn-error' ?>">
                                                        <?= $user['is_active'] ? 'نشط' : 'معطل' ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="flex gap-2">
                                                    <button
                                                        @click="editingUser = <?= htmlspecialchars(json_encode($user)) ?>; showEditModal = true"
                                                        class="btn btn-sm btn-ghost">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <form method="POST" class="inline"
                                                            onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم؟');">
                                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-ghost text-error">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-8">
                                                <div class="text-gray-500">
                                                    <i class="fas fa-users text-4xl mb-3"></i>
                                                    <p>لا توجد نتائج</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create User Modal -->
    <dialog x-show="showCreateModal" x-transition class="modal" :class="showCreateModal && 'modal-open'">
        <div class="modal-box">
            <h3 class="font-bold text-lg mb-4">إضافة مستخدم جديد</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="create">

                <div class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text">الاسم</span>
                        </label>
                        <input type="text" name="name" class="input input-bordered w-full" required>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">البريد الإلكتروني</span>
                        </label>
                        <input type="email" name="email" class="input input-bordered w-full" required>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">كلمة المرور</span>
                        </label>
                        <input type="password" name="password" class="input input-bordered w-full" required>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">الصلاحية</span>
                        </label>
                        <select name="role" class="select select-bordered w-full" required>
                            <option value="student">طالب</option>
                            <option value="teacher">معلم</option>
                            <option value="admin">مدير</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">الصف (للطلاب)</span>
                        </label>
                        <select name="grade" class="select select-bordered w-full">
                            <option value="">-- اختر الصف --</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>">
                                    <?= e($grades[$i - 1] ?? "الصف $i") ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-action">
                    <button type="button" @click="showCreateModal = false" class="btn">إلغاء</button>
                    <button type="submit" class="btn btn-primary">إضافة</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop" @click="showCreateModal = false">
            <button>close</button>
        </form>
    </dialog>

    <!-- Edit User Modal -->
    <dialog x-show="showEditModal" x-transition class="modal" :class="showEditModal && 'modal-open'">
        <div class="modal-box">
            <h3 class="font-bold text-lg mb-4">تعديل المستخدم</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" :value="editingUser?.id">

                <div class="space-y-4">
                    <div>
                        <label class="label">
                            <span class="label-text">الاسم</span>
                        </label>
                        <input type="text" name="name" :value="editingUser?.name" class="input input-bordered w-full"
                            required>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">البريد الإلكتروني</span>
                        </label>
                        <input type="email" name="email" :value="editingUser?.email" class="input input-bordered w-full"
                            required>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">كلمة المرور الجديدة</span>
                            <span class="label-text-alt">اتركه فارغاً إذا لم ترد التغيير</span>
                        </label>
                        <input type="password" name="password" class="input input-bordered w-full">
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">الصلاحية</span>
                        </label>
                        <select name="role" class="select select-bordered w-full" required>
                            <option value="student" :selected="editingUser?.role === 'student'">طالب</option>
                            <option value="teacher" :selected="editingUser?.role === 'teacher'">معلم</option>
                            <option value="admin" :selected="editingUser?.role === 'admin'">مدير</option>
                        </select>
                    </div>

                    <div>
                        <label class="label">
                            <span class="label-text">الصف (للطلاب)</span>
                        </label>
                        <select name="grade" class="select select-bordered w-full">
                            <option value="">-- اختر الصف --</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" :selected="editingUser?.grade == <?= $i ?>">
                                    <?= e($grades[$i - 1] ?? "الصف $i") ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label class="label cursor-pointer">
                            <span class="label-text">حساب نشط</span>
                            <input type="checkbox" name="is_active" value="1" :checked="editingUser?.is_active == 1"
                                class="checkbox">
                        </label>
                    </div>
                </div>

                <div class="modal-action">
                    <button type="button" @click="showEditModal = false" class="btn">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop" @click="showEditModal = false">
            <button>close</button>
        </form>
    </dialog>
</body>

</html>