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
                    $is_active = isset($_POST['is_active']) ? 1 : 0;

                    // Validation
                    if (empty($name)) {
                        throw new Exception('الاسم مطلوب');
                    }

                    // Email is optional but must be unique if provided
                    if (!empty($email)) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('البريد الإلكتروني غير صحيح');
                        }

                        // Check if email exists
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception('البريد الإلكتروني مستخدم بالفعل');
                        }
                    } else {
                        $email = null; // Set to NULL if empty
                    }

                    // Password is optional
                    $hashedPassword = null;
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    }

                    // Grade is only for students
                    if ($role !== 'student') {
                        $grade = null;
                    }

                    // Insert user
                    $stmt = $pdo->prepare("
                        INSERT INTO users (name, email, password, role, grade, is_active, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $name,
                        $email,
                        $hashedPassword,
                        $role,
                        $grade,
                        $is_active
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

                    if ($userId <= 0 || empty($name)) {
                        throw new Exception('البيانات غير صحيحة');
                    }

                    // Prevent admin from deactivating themselves
                    if ($userId == $_SESSION['user_id'] && $is_active == 0) {
                        throw new Exception('لا يمكنك تعطيل حسابك الخاص');
                    }

                    // Prevent admin from changing their own role
                    if ($userId == $_SESSION['user_id'] && $role !== 'admin') {
                        throw new Exception('لا يمكنك تغيير صلاحيتك الخاصة');
                    }

                    // Email validation
                    if (!empty($email)) {
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            throw new Exception('البريد الإلكتروني غير صحيح');
                        }

                        // Check if email exists for another user
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                        $stmt->execute([$email, $userId]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception('البريد الإلكتروني مستخدم بالفعل');
                        }
                    } else {
                        $email = null;
                    }

                    // Grade is only for students
                    if ($role !== 'student') {
                        $grade = null;
                    }

                    // Build update query
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

                    // Check if user has related data
                    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();

                    if (!$user) {
                        throw new Exception('المستخدم غير موجود');
                    }

                    // If teacher, check for quizzes
                    if ($user['role'] === 'teacher') {
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE teacher_id = ?");
                        $stmt->execute([$userId]);
                        if ($stmt->fetchColumn() > 0) {
                            throw new Exception('لا يمكن حذف المعلم لوجود اختبارات مرتبطة به');
                        }
                    }

                    // Delete user (this will cascade delete attempts, answers, user_achievements)
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

                    // Prevent disabling yourself
                    if ($userId == $_SESSION['user_id']) {
                        throw new Exception('لا يمكنك تعطيل حسابك الخاص');
                    }

                    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$userId]);

                    $success = 'تم تحديث حالة المستخدم';
                    break;

                case 'reset_password':
                    // Reset password to default
                    $userId = intval($_POST['user_id'] ?? 0);
                    $newPassword = $_POST['new_password'] ?? '123456';

                    if ($userId <= 0) {
                        throw new Exception('معرف المستخدم غير صحيح');
                    }

                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

                    $success = 'تم إعادة تعيين كلمة المرور إلى: ' . $newPassword;
                    break;

                case 'bulk_action':
                    // Bulk actions
                    $userIds = $_POST['selected_users'] ?? [];
                    $bulkAction = $_POST['bulk_action'] ?? '';

                    if (empty($userIds) || empty($bulkAction)) {
                        throw new Exception('الرجاء تحديد المستخدمين والإجراء');
                    }

                    // Remove current user from list
                    $userIds = array_filter($userIds, fn($id) => $id != $_SESSION['user_id']);

                    if (empty($userIds)) {
                        throw new Exception('لا يوجد مستخدمين لتطبيق الإجراء عليهم');
                    }

                    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

                    switch ($bulkAction) {
                        case 'activate':
                            $stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE id IN ($placeholders)");
                            $stmt->execute($userIds);
                            $success = 'تم تفعيل المستخدمين المحددين';
                            break;

                        case 'deactivate':
                            $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id IN ($placeholders)");
                            $stmt->execute($userIds);
                            $success = 'تم تعطيل المستخدمين المحددين';
                            break;

                        case 'delete':
                            // Check for teachers with quizzes
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) 
                                FROM users u 
                                JOIN quizzes q ON u.id = q.teacher_id 
                                WHERE u.id IN ($placeholders)
                            ");
                            $stmt->execute($userIds);
                            if ($stmt->fetchColumn() > 0) {
                                throw new Exception('لا يمكن حذف بعض المستخدمين لوجود اختبارات مرتبطة بهم');
                            }

                            $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                            $stmt->execute($userIds);
                            $success = 'تم حذف المستخدمين المحددين';
                            break;
                    }
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
$filterStatus = $_GET['status'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

// Build query
$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM attempts WHERE user_id = u.id) as quiz_count,
        (SELECT COUNT(*) FROM user_achievements WHERE user_id = u.id) as achievement_count,
        (SELECT COUNT(*) FROM quizzes WHERE teacher_id = u.id) as created_quizzes_count
        FROM users u WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM users u WHERE 1=1";
$params = [];

// Apply filters
if ($filterRole) {
    $sql .= " AND u.role = ?";
    $countSql .= " AND u.role = ?";
    $params[] = $filterRole;
}

if ($filterGrade) {
    $sql .= " AND u.grade = ?";
    $countSql .= " AND u.grade = ?";
    $params[] = $filterGrade;
}

if ($filterStatus !== '') {
    $sql .= " AND u.is_active = ?";
    $countSql .= " AND u.is_active = ?";
    $params[] = $filterStatus;
}

if ($searchTerm) {
    $sql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $countSql .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

// Get total count for pagination
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalUsers = $stmt->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

// Sorting
$allowedSorts = ['name', 'email', 'role', 'created_at', 'total_points', 'grade', 'is_active'];
$allowedOrders = ['ASC', 'DESC'];

if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'created_at';
}
if (!in_array($sortOrder, $allowedOrders)) {
    $sortOrder = 'DESC';
}

$sql .= " ORDER BY u.$sortBy $sortOrder";

// Pagination
$offset = ($page - 1) * $perPage;
$sql .= " LIMIT $perPage OFFSET $offset";

// Execute query
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$roleStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get total active/inactive
$stmt = $pdo->query("SELECT is_active, COUNT(*) as count FROM users GROUP BY is_active");
$statusStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Get grades for filter
$grades = getSetting('grades', []);
if (empty($grades)) {
    $grades = [];
    for ($i = 1; $i <= 12; $i++) {
        $grades[] = "الصف $i";
    }
}

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
    <!-- Note: For production, install Tailwind CSS properly via npm/PostCSS -->
    <!-- See: https://tailwindcss.com/docs/installation -->

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <script>
        // Debug Alpine.js
        document.addEventListener('alpine:init', () => {
            console.log('Alpine.js initialized');
        });

        // Fallback if Alpine doesn't load
        window.addEventListener('load', () => {
            if (typeof Alpine === 'undefined') {
                console.error('Alpine.js failed to load');
                // Load from alternative CDN
                const script = document.createElement('script');
                script.src = 'https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js';
                script.defer = true;
                document.head.appendChild(script);
            }
        });
    </script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        /* Hide arrows for number inputs */
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            appearance: none;
            margin: 0;
        }

        input[type=number] {
            -moz-appearance: textfield;
            -webkit-appearance: textfield;
            appearance: textfield;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: window.innerWidth > 768 }">
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
                        <button onclick="document.getElementById('createModal').style.display='flex'"
                            class="btn btn-primary">
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
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">إجمالي المستخدمين</div>
                        <div class="stat-value text-primary"><?= number_format($totalUsers) ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">المدراء</div>
                        <div class="stat-value text-purple-600"><?= number_format($roleStats['admin'] ?? 0) ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">المعلمون</div>
                        <div class="stat-value text-blue-600"><?= number_format($roleStats['teacher'] ?? 0) ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">الطلاب</div>
                        <div class="stat-value text-green-600"><?= number_format($roleStats['student'] ?? 0) ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">الحسابات النشطة</div>
                        <div class="stat-value text-success"><?= number_format($statusStats[1] ?? 0) ?></div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card bg-base-100 shadow-xl mb-6">
                    <div class="card-body">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-4" id="filterForm">
                            <div class="md:col-span-2">
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
                                    <span class="label-text">الحالة</span>
                                </label>
                                <select name="status" class="select select-bordered w-full">
                                    <option value="">جميع الحالات</option>
                                    <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>نشط</option>
                                    <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>معطل</option>
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

                            <input type="hidden" name="order" value="<?= $sortOrder ?>">

                            <div class="md:col-span-full flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search ml-2"></i>
                                    بحث
                                </button>
                                <a href="users.php" class="btn btn-ghost">
                                    <i class="fas fa-redo ml-2"></i>
                                    إعادة تعيين
                                </a>
                                <button type="button" onclick="toggleSortOrder()" class="btn btn-ghost">
                                    <i class="fas fa-sort ml-2"></i>
                                    <?= $sortOrder === 'ASC' ? 'تصاعدي' : 'تنازلي' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bulk Actions -->
                <div id="bulkActions" style="display:none" class="mb-4">
                    <form method="POST" class="flex items-center gap-4" id="bulkForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="bulk_action">

                        <span class="text-sm text-gray-600">
                            تم تحديد <span id="selectedCount">0</span> مستخدم
                        </span>

                        <select name="bulk_action" class="select select-sm select-bordered">
                            <option value="">اختر إجراء...</option>
                            <option value="activate">تفعيل</option>
                            <option value="deactivate">تعطيل</option>
                            <option value="delete">حذف</option>
                        </select>

                        <button type="submit" class="btn btn-sm btn-primary"
                            onclick="return confirm('هل أنت متأكد من تطبيق هذا الإجراء على المستخدمين المحددين؟')">
                            تطبيق
                        </button>

                        <button type="button" onclick="clearSelection()" class="btn btn-sm btn-ghost">
                            إلغاء التحديد
                        </button>
                    </form>
                </div>

                <!-- Users Table -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>
                                            <label>
                                                <input type="checkbox" class="checkbox" id="selectAll">
                                            </label>
                                        </th>
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
                                            <th>
                                                <label>
                                                    <input type="checkbox" class="checkbox user-checkbox"
                                                        name="selected_users[]" value="<?= $user['id'] ?>"
                                                        <?= $user['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                                                </label>
                                            </th>
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
                                            <td>
                                                <?= $user['email'] ? e($user['email']) : '<span class="text-gray-400">غير محدد</span>' ?>
                                            </td>
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
                                                    <?php if ($user['role'] === 'student'): ?>
                                                        <div class="flex items-center gap-2">
                                                            <i class="fas fa-clipboard-check text-blue-500"></i>
                                                            <?= $user['quiz_count'] ?> اختبار
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <i class="fas fa-trophy text-yellow-500"></i>
                                                            <?= $user['achievement_count'] ?> إنجاز
                                                        </div>
                                                    <?php elseif ($user['role'] === 'teacher'): ?>
                                                        <div class="flex items-center gap-2">
                                                            <i class="fas fa-file-alt text-green-500"></i>
                                                            <?= $user['created_quizzes_count'] ?> اختبار
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit"
                                                        class="btn btn-xs <?= $user['is_active'] ? 'btn-success' : 'btn-error' ?>"
                                                        <?= $user['id'] == $_SESSION['user_id'] ? 'disabled' : '' ?>>
                                                        <?= $user['is_active'] ? 'نشط' : 'معطل' ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <div class="flex gap-1">
                                                    <button
                                                        onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)"
                                                        class="btn btn-sm btn-ghost" title="تعديل">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button
                                                        onclick="openPasswordModal(<?= htmlspecialchars(json_encode($user)) ?>)"
                                                        class="btn btn-sm btn-ghost" title="تغيير كلمة المرور">
                                                        <i class="fas fa-key"></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <form method="POST" class="inline"
                                                            onsubmit="return confirm('هل أنت متأكد من حذف هذا المستخدم؟');">
                                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-ghost text-error"
                                                                title="حذف">
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
                                            <td colspan="9" class="text-center py-8">
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

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="flex justify-center mt-6">
                                <div class="join">
                                    <?php
                                    // Build query string for pagination links
                                    $queryParams = $_GET;
                                    unset($queryParams['page']);
                                    $queryString = http_build_query($queryParams);
                                    ?>

                                    <?php if ($page > 1): ?>
                                        <a href="?<?= $queryString ?>&page=<?= $page - 1 ?>" class="join-item btn">«</a>
                                    <?php endif; ?>

                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);

                                    for ($i = $startPage; $i <= $endPage; $i++):
                                        ?>
                                        <a href="?<?= $queryString ?>&page=<?= $i ?>"
                                            class="join-item btn <?= $i === $page ? 'btn-active' : '' ?>">
                                            <?= $i ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($page < $totalPages): ?>
                                        <a href="?<?= $queryString ?>&page=<?= $page + 1 ?>" class="join-item btn">»</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Create User Modal -->
    <div id="createModal" style="display:none"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg w-full max-w-md animate__animated animate__fadeIn">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg">إضافة مستخدم جديد</h3>
                    <button onclick="document.getElementById('createModal').style.display='none'"
                        class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="space-y-4">
                        <div>
                            <label class="label">
                                <span class="label-text">الاسم</span>
                                <span class="label-text-alt text-error">*</span>
                            </label>
                            <input type="text" name="name" class="input input-bordered w-full" required>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">البريد الإلكتروني</span>
                                <span class="label-text-alt">اختياري</span>
                            </label>
                            <input type="email" name="email" class="input input-bordered w-full">
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">كلمة المرور</span>
                                <span class="label-text-alt">اتركها فارغة لإنشاء المستخدم بدون كلمة مرور</span>
                            </label>
                            <input type="password" name="password" class="input input-bordered w-full">
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">الصلاحية</span>
                            </label>
                            <select name="role" id="roleSelect" class="select select-bordered w-full" required
                                onchange="document.getElementById('gradeSelect').disabled = this.value !== 'student'">
                                <option value="student">طالب</option>
                                <option value="teacher">معلم</option>
                                <option value="admin">مدير</option>
                            </select>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">الصف (للطلاب فقط)</span>
                            </label>
                            <select name="grade" id="gradeSelect" class="select select-bordered w-full">
                                <option value="">-- اختر الصف --</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>">
                                        <?= e($grades[$i - 1] ?? "الصف $i") ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label class="label cursor-pointer">
                                <span class="label-text">حساب نشط</span>
                                <input type="checkbox" name="is_active" value="1" class="checkbox" checked>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="document.getElementById('createModal').style.display='none'"
                            class="btn">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إضافة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div id="editModal" style="display:none"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg w-full max-w-md animate__animated animate__fadeIn">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg">تعديل المستخدم</h3>
                    <button onclick="document.getElementById('editModal').style.display='none'"
                        class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="user_id" id="editUserId">

                    <div class="space-y-4">
                        <div>
                            <label class="label">
                                <span class="label-text">الاسم</span>
                                <span class="label-text-alt text-error">*</span>
                            </label>
                            <input type="text" name="name" id="editName" class="input input-bordered w-full" required>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">البريد الإلكتروني</span>
                                <span class="label-text-alt">اختياري</span>
                            </label>
                            <input type="email" name="email" id="editEmail" class="input input-bordered w-full">
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
                            <select name="role" id="editRole" class="select select-bordered w-full" required>
                                <option value="student">طالب</option>
                                <option value="teacher">معلم</option>
                                <option value="admin">مدير</option>
                            </select>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">الصف (للطلاب فقط)</span>
                            </label>
                            <select name="grade" id="editGrade" class="select select-bordered w-full">
                                <option value="">-- اختر الصف --</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>">
                                        <?= e($grades[$i - 1] ?? "الصف $i") ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label class="label cursor-pointer">
                                <span class="label-text">حساب نشط</span>
                                <input type="checkbox" name="is_active" value="1" id="editActive" class="checkbox">
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="document.getElementById('editModal').style.display='none'"
                            class="btn">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div id="passwordModal" style="display:none"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg w-full max-w-md animate__animated animate__fadeIn">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-lg">إعادة تعيين كلمة المرور</h3>
                    <button onclick="document.getElementById('passwordModal').style.display='none'"
                        class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="passwordUserId">

                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle"></i>
                        <span>إعادة تعيين كلمة المرور للمستخدم: <strong id="passwordUserName"></strong></span>
                    </div>

                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">كلمة المرور الجديدة</span>
                        </label>
                        <input type="text" name="new_password" value="123456" class="input input-bordered w-full"
                            required>
                        <label class="label">
                            <span class="label-text-alt">سيتم إرسال كلمة المرور للمستخدم</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="document.getElementById('passwordModal').style.display='none'"
                            class="btn">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إعادة تعيين</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    <script>
        // Modal management functions
        let currentEditUser = null;

        function openEditModal(user) {
            currentEditUser = user;
            document.getElementById('editUserId').value = user.id;
            document.getElementById('editName').value = user.name;
            document.getElementById('editEmail').value = user.email || '';
            document.getElementById('editRole').value = user.role;
            document.getElementById('editGrade').value = user.grade || '';
            document.getElementById('editActive').checked = user.is_active == 1;

            // Disable fields if editing self
            const isSelf = user.id == <?= $_SESSION['user_id'] ?>;
            document.getElementById('editRole').disabled = isSelf;
            document.getElementById('editActive').disabled = isSelf;

            document.getElementById('editModal').style.display = 'flex';
        }

        function openPasswordModal(user) {
            currentEditUser = user;
            document.getElementById('passwordUserId').value = user.id;
            document.getElementById('passwordUserName').textContent = user.name;
            document.getElementById('passwordModal').style.display = 'flex';
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target.classList.contains('fixed')) {
                event.target.style.display = 'none';
            }
        }

        // Handle bulk selection
        document.addEventListener('DOMContentLoaded', function () {
            const selectAllCheckbox = document.getElementById('selectAll');
            const userCheckboxes = document.querySelectorAll('.user-checkbox');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            const bulkForm = document.getElementById('bulkForm');

            function updateBulkActions() {
                const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
                const count = checkedBoxes.length;

                if (count > 0) {
                    bulkActions.style.display = 'block';
                    selectedCount.textContent = count;

                    // Clear existing hidden inputs
                    const oldInputs = bulkForm.querySelectorAll('input[name="selected_users[]"]');
                    oldInputs.forEach(input => input.remove());

                    // Add new hidden inputs for selected users
                    checkedBoxes.forEach(checkbox => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selected_users[]';
                        input.value = checkbox.value;
                        bulkForm.appendChild(input);
                    });
                } else {
                    bulkActions.style.display = 'none';
                }

                // Update select all checkbox state
                if (selectAllCheckbox) {
                    const enabledCheckboxes = document.querySelectorAll('.user-checkbox:not(:disabled)');
                    const checkedEnabledBoxes = document.querySelectorAll('.user-checkbox:not(:disabled):checked');
                    selectAllCheckbox.checked = enabledCheckboxes.length > 0 && enabledCheckboxes.length === checkedEnabledBoxes.length;
                }
            }

            // Select all handler
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function () {
                    userCheckboxes.forEach(checkbox => {
                        if (!checkbox.disabled) {
                            checkbox.checked = this.checked;
                        }
                    });
                    updateBulkActions();
                });
            }

            // Individual checkbox handlers
            userCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkActions);
            });

            // Initialize on load
            updateBulkActions();
        });

        function clearSelection() {
            document.querySelectorAll('.user-checkbox').forEach(checkbox => {
                checkbox.checked = false;
            });
            document.getElementById('selectAll').checked = false;
            document.getElementById('bulkActions').style.display = 'none';
        }

        function toggleSortOrder() {
            const orderInput = document.querySelector('[name=order]');
            const form = document.getElementById('filterForm');
            orderInput.value = orderInput.value === 'ASC' ? 'DESC' : 'ASC';
            form.submit();
        }
    </script>
</body>

</html>