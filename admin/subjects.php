<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

$success = '';
$error = '';

// Handle subject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCSRF($csrf)) {
        $error = 'انتهت صلاحية الجلسة. يرجى إعادة المحاولة.';
    } else {
        try {
            switch ($action) {
                case 'create':
                    // Create new subject
                    $name_ar = trim($_POST['name_ar'] ?? '');
                    $name_en = trim($_POST['name_en'] ?? '');
                    $icon = $_POST['icon'] ?? 'fas fa-book';
                    $color = $_POST['color'] ?? 'blue';

                    if (empty($name_ar)) {
                        throw new Exception('اسم المادة بالعربية مطلوب');
                    }

                    // Insert subject
                    $stmt = $pdo->prepare("
                        INSERT INTO subjects (name_ar, name_en, icon, color, is_active) 
                        VALUES (?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([$name_ar, $name_en, $icon, $color]);

                    $success = 'تمت إضافة المادة بنجاح';
                    break;

                case 'update':
                    // Update subject
                    $subjectId = intval($_POST['subject_id'] ?? 0);
                    $name_ar = trim($_POST['name_ar'] ?? '');
                    $name_en = trim($_POST['name_en'] ?? '');
                    $icon = $_POST['icon'] ?? 'fas fa-book';
                    $color = $_POST['color'] ?? 'blue';
                    $is_active = isset($_POST['is_active']) ? 1 : 0;

                    if ($subjectId <= 0 || empty($name_ar)) {
                        throw new Exception('البيانات غير صحيحة');
                    }

                    // Update subject
                    $stmt = $pdo->prepare("
                        UPDATE subjects 
                        SET name_ar = ?, name_en = ?, icon = ?, color = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$name_ar, $name_en, $icon, $color, $is_active, $subjectId]);

                    $success = 'تم تحديث المادة بنجاح';
                    break;

                case 'delete':
                    // Delete subject
                    $subjectId = intval($_POST['subject_id'] ?? 0);

                    if ($subjectId <= 0) {
                        throw new Exception('معرف المادة غير صحيح');
                    }

                    // Check if subject has quizzes
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE subject_id = ?");
                    $stmt->execute([$subjectId]);
                    if ($stmt->fetchColumn() > 0) {
                        throw new Exception('لا يمكن حذف المادة لوجود اختبارات مرتبطة بها');
                    }

                    // Delete subject
                    $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
                    $stmt->execute([$subjectId]);

                    $success = 'تم حذف المادة بنجاح';
                    break;

                case 'toggle_status':
                    // Toggle active status
                    $subjectId = intval($_POST['subject_id'] ?? 0);

                    if ($subjectId <= 0) {
                        throw new Exception('معرف المادة غير صحيح');
                    }

                    $stmt = $pdo->prepare("UPDATE subjects SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$subjectId]);

                    $success = 'تم تحديث حالة المادة';
                    break;
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get all subjects with quiz count
$stmt = $pdo->query("
    SELECT s.*, 
           COUNT(DISTINCT q.id) as quiz_count,
           COUNT(DISTINCT a.id) as attempt_count
    FROM subjects s
    LEFT JOIN quizzes q ON s.id = q.subject_id
    LEFT JOIN attempts a ON q.id = a.quiz_id
    GROUP BY s.id
    ORDER BY s.id
");
$subjects = $stmt->fetchAll();

// Generate CSRF token
$csrfToken = generateCSRF();

// Icon options
$iconOptions = [
    'fas fa-calculator' => 'آلة حاسبة',
    'fas fa-flask' => 'علوم',
    'fas fa-book' => 'كتاب',
    'fas fa-language' => 'لغة',
    'fas fa-mosque' => 'مسجد',
    'fas fa-globe' => 'كرة أرضية',
    'fas fa-laptop' => 'حاسوب',
    'fas fa-atom' => 'ذرة',
    'fas fa-paint-brush' => 'فرشاة',
    'fas fa-music' => 'موسيقى',
    'fas fa-dumbbell' => 'رياضة',
    'fas fa-history' => 'تاريخ',
    'fas fa-microscope' => 'مجهر',
    'fas fa-brain' => 'دماغ',
    'fas fa-heart' => 'قلب'
];

// Color options
$colorOptions = [
    'blue' => 'أزرق',
    'green' => 'أخضر',
    'red' => 'أحمر',
    'yellow' => 'أصفر',
    'purple' => 'بنفسجي',
    'pink' => 'وردي',
    'orange' => 'برتقالي',
    'teal' => 'فيروزي',
    'indigo' => 'نيلي',
    'gray' => 'رمادي'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة المواد الدراسية - <?= e(getSetting('site_name')) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Alpine.js (for sidebar only) -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .subject-card {
            transition: all 0.3s ease;
        }

        .subject-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        .icon-preview {
            font-size: 3rem;
        }

        .icon-selector {
            cursor: pointer;
            transition: all 0.2s;
        }

        .icon-selector:hover {
            background-color: #f3f4f6;
            transform: scale(1.1);
        }

        .icon-selector.selected {
            background-color: #ddd6fe;
            /* Replace ring properties with box-shadow */
            box-shadow: 0 0 0 2px #8b5cf6;
            /* OR use outline: */
            /* outline: 2px solid #8b5cf6;
            outline-offset: 0; */
        }

        .color-selector {
            cursor: pointer;
            transition: all 0.2s;
        }

        .color-selector:hover {
            transform: scale(1.05);
        }

        .color-selector.selected {
            /* Replace ring properties with box-shadow */
            box-shadow: 0 0 0 2px #374151;
            /* OR use outline: */
            /* outline: 2px solid #374151;
            outline-offset: 0; */
        }

        .selected-ring-primary {
            box-shadow: 0 0 0 2px #3b82f6;
        }

        .selected-ring-dark {
            box-shadow: 0 0 0 2px #1f2937;
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
                    <a href="users.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-users w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المستخدمون</span>
                    </a>
                    <a href="quizzes.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-question-circle w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الاختبارات</span>
                    </a>
                    <a href="subjects.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
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
                            <h1 class="text-2xl font-bold text-gray-800">المواد الدراسية</h1>
                            <p class="text-gray-600 mt-1">إدارة المواد الدراسية المتاحة في المنصة</p>
                        </div>
                        <button onclick="openCreateModal()" class="btn btn-primary">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة مادة
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

                <!-- Subjects Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($subjects as $subject): ?>
                        <div
                            class="subject-card card bg-base-100 shadow-xl border-t-4 border-<?= e($subject['color']) ?>-500">
                            <div class="card-body">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-center gap-4">
                                        <div
                                            class="w-16 h-16 rounded-full bg-<?= e($subject['color']) ?>-100 flex items-center justify-center">
                                            <i
                                                class="<?= e($subject['icon']) ?> text-<?= e($subject['color']) ?>-600 text-2xl"></i>
                                        </div>
                                        <div>
                                            <h3 class="text-xl font-bold"><?= e($subject['name_ar']) ?></h3>
                                            <?php if ($subject['name_en']): ?>
                                                <p class="text-sm text-gray-500"><?= e($subject['name_en']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dropdown dropdown-end">
                                        <label tabindex="0" class="btn btn-ghost btn-sm">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </label>
                                        <ul tabindex="0"
                                            class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-52">
                                            <li>
                                                <a onclick="openEditModal(<?= htmlspecialchars(json_encode($subject)) ?>)"
                                                    href="javascript:void(0)">
                                                    <i class="fas fa-edit"></i>
                                                    تعديل
                                                </a>
                                            </li>
                                            <li>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="subject_id" value="<?= $subject['id'] ?>">
                                                    <button type="submit" class="w-full text-right">
                                                        <i
                                                            class="fas fa-toggle-<?= $subject['is_active'] ? 'on' : 'off' ?>"></i>
                                                        <?= $subject['is_active'] ? 'تعطيل' : 'تفعيل' ?>
                                                    </button>
                                                </form>
                                            </li>
                                            <?php if ($subject['quiz_count'] == 0): ?>
                                                <li>
                                                    <form method="POST" class="inline"
                                                        onsubmit="return confirm('هل أنت متأكد من حذف هذه المادة؟');">
                                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="subject_id" value="<?= $subject['id'] ?>">
                                                        <button type="submit" class="w-full text-right text-error">
                                                            <i class="fas fa-trash"></i>
                                                            حذف
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </div>

                                <div class="mt-6 pt-4 border-t">
                                    <div class="grid grid-cols-2 gap-4 text-center">
                                        <div>
                                            <div class="text-2xl font-bold text-primary">
                                                <?= number_format($subject['quiz_count']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">اختبار</div>
                                        </div>
                                        <div>
                                            <div class="text-2xl font-bold text-secondary">
                                                <?= number_format($subject['attempt_count']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500">محاولة</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="badge <?= $subject['is_active'] ? 'badge-success' : 'badge-error' ?> gap-2">
                                        <i class="fas fa-circle text-xs"></i>
                                        <?= $subject['is_active'] ? 'مفعلة' : 'معطلة' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($subjects)): ?>
                        <div class="col-span-full text-center py-16">
                            <div class="text-gray-500">
                                <i class="fas fa-book text-6xl mb-4"></i>
                                <p class="text-xl">لا توجد مواد دراسية</p>
                                <p class="mt-2">ابدأ بإضافة المواد الدراسية لمنصتك</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Create Subject Modal -->
    <div id="createModal" style="display:none"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-lg">إضافة مادة دراسية جديدة</h3>
                    <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="icon" id="createIcon" value="fas fa-book">
                    <input type="hidden" name="color" id="createColor" value="blue">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="label">
                                <span class="label-text">اسم المادة بالعربية</span>
                            </label>
                            <input type="text" name="name_ar" class="input input-bordered w-full" required>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">اسم المادة بالإنجليزية</span>
                            </label>
                            <input type="text" name="name_en" class="input input-bordered w-full">
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">اختر أيقونة</span>
                        </label>
                        <div class="grid grid-cols-5 gap-2">
                            <?php foreach ($iconOptions as $icon => $label): ?>
                                <button type="button" onclick="selectIcon('create', '<?= $icon ?>')"
                                    data-icon="<?= $icon ?>" class="icon-selector btn btn-ghost btn-square"
                                    title="<?= e($label) ?>">
                                    <i class="<?= $icon ?> text-xl"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">اختر لون</span>
                        </label>
                        <div class="grid grid-cols-5 gap-2">
                            <?php foreach ($colorOptions as $color => $label): ?>
                                <button type="button" onclick="selectColor('create', '<?= $color ?>')"
                                    data-color="<?= $color ?>"
                                    class="color-selector btn bg-<?= $color ?>-500 hover:bg-<?= $color ?>-600 text-white border-0"
                                    title="<?= e($label) ?>">
                                    <?= e($label) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">معاينة</span>
                        </label>
                        <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg">
                            <div id="createPreviewBg"
                                class="w-20 h-20 rounded-full bg-blue-100 flex items-center justify-center">
                                <i id="createPreviewIcon" class="icon-preview fas fa-book text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-lg font-bold">اسم المادة</p>
                                <p class="text-sm text-gray-500">Subject Name</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="closeCreateModal()" class="btn">إلغاء</button>
                        <button type="submit" class="btn btn-primary">إضافة المادة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editModal" style="display:none"
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-lg">تعديل المادة الدراسية</h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="subject_id" id="editSubjectId">
                    <input type="hidden" name="icon" id="editIcon">
                    <input type="hidden" name="color" id="editColor">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="label">
                                <span class="label-text">اسم المادة بالعربية</span>
                            </label>
                            <input type="text" name="name_ar" id="editNameAr" class="input input-bordered w-full"
                                required>
                        </div>

                        <div>
                            <label class="label">
                                <span class="label-text">اسم المادة بالإنجليزية</span>
                            </label>
                            <input type="text" name="name_en" id="editNameEn" class="input input-bordered w-full">
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">اختر أيقونة</span>
                        </label>
                        <div class="grid grid-cols-5 gap-2">
                            <?php foreach ($iconOptions as $icon => $label): ?>
                                <button type="button" onclick="selectIcon('edit', '<?= $icon ?>')" data-icon="<?= $icon ?>"
                                    class="icon-selector btn btn-ghost btn-square" title="<?= e($label) ?>">
                                    <i class="<?= $icon ?> text-xl"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">اختر لون</span>
                        </label>
                        <div class="grid grid-cols-5 gap-2">
                            <?php foreach ($colorOptions as $color => $label): ?>
                                <button type="button" onclick="selectColor('edit', '<?= $color ?>')"
                                    data-color="<?= $color ?>"
                                    class="color-selector btn bg-<?= $color ?>-500 hover:bg-<?= $color ?>-600 text-white border-0"
                                    title="<?= e($label) ?>">
                                    <?= e($label) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="label">
                            <span class="label-text">معاينة</span>
                        </label>
                        <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg">
                            <div id="editPreviewBg"
                                class="w-20 h-20 rounded-full bg-blue-100 flex items-center justify-center">
                                <i id="editPreviewIcon" class="icon-preview fas fa-book text-blue-600"></i>
                            </div>
                            <div>
                                <p class="text-lg font-bold" id="editPreviewNameAr">اسم المادة</p>
                                <p class="text-sm text-gray-500" id="editPreviewNameEn">Subject Name</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="label cursor-pointer">
                            <span class="label-text">المادة مفعلة</span>
                            <input type="checkbox" name="is_active" id="editIsActive" value="1" class="checkbox">
                        </label>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <button type="button" onclick="closeEditModal()" class="btn">إلغاء</button>
                        <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let currentEditSubject = null;
        let selectedIcon = { create: 'fas fa-book', edit: 'fas fa-book' };
        let selectedColor = { create: 'blue', edit: 'blue' };

        // Open Create Modal
        function openCreateModal() {
            selectedIcon.create = 'fas fa-book';
            selectedColor.create = 'blue';
            document.getElementById('createIcon').value = selectedIcon.create;
            document.getElementById('createColor').value = selectedColor.create;
            updatePreview('create');
            document.getElementById('createModal').style.display = 'flex';

            // Reset selections
            document.querySelectorAll('#createModal .icon-selector').forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-purple-600');
                if (btn.dataset.icon === selectedIcon.create) {
                    btn.classList.add('selected', 'ring-2', 'ring-purple-600');
                }
            });

            document.querySelectorAll('#createModal .color-selector').forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-gray-800');
                if (btn.dataset.color === selectedColor.create) {
                    btn.classList.add('selected', 'ring-2', 'ring-gray-800');
                }
            });
        }

        function closeCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }

        // Open Edit Modal
        function openEditModal(subject) {
            currentEditSubject = subject;
            selectedIcon.edit = subject.icon;
            selectedColor.edit = subject.color;

            document.getElementById('editSubjectId').value = subject.id;
            document.getElementById('editNameAr').value = subject.name_ar;
            document.getElementById('editNameEn').value = subject.name_en || '';
            document.getElementById('editIcon').value = subject.icon;
            document.getElementById('editColor').value = subject.color;
            document.getElementById('editIsActive').checked = subject.is_active == 1;

            updatePreview('edit');

            // Update selections
            document.querySelectorAll('#editModal .icon-selector').forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-purple-600');
                if (btn.dataset.icon === selectedIcon.edit) {
                    btn.classList.add('selected', 'ring-2', 'ring-purple-600');
                }
            });

            document.querySelectorAll('#editModal .color-selector').forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-gray-800');
                if (btn.dataset.color === selectedColor.edit) {
                    btn.classList.add('selected', 'ring-2', 'ring-gray-800');
                }
            });

            document.getElementById('editModal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Select Icon
        function selectIcon(mode, icon) {
            selectedIcon[mode] = icon;
            document.getElementById(mode + 'Icon').value = icon;

            // Update selection visual
            document.querySelectorAll(`#${mode}Modal .icon-selector`).forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-purple-600');
                if (btn.dataset.icon === icon) {
                    btn.classList.add('selected', 'ring-2', 'ring-purple-600');
                }
            });

            updatePreview(mode);
        }

        // Select Color
        function selectColor(mode, color) {
            selectedColor[mode] = color;
            document.getElementById(mode + 'Color').value = color;

            // Update selection visual
            document.querySelectorAll(`#${mode}Modal .color-selector`).forEach(btn => {
                btn.classList.remove('selected', 'ring-2', 'ring-gray-800');
                if (btn.dataset.color === color) {
                    btn.classList.add('selected', 'ring-2', 'ring-gray-800');
                }
            });

            updatePreview(mode);
        }

        // Update Preview
        function updatePreview(mode) {
            const icon = selectedIcon[mode];
            const color = selectedColor[mode];

            const previewBg = document.getElementById(mode + 'PreviewBg');
            const previewIcon = document.getElementById(mode + 'PreviewIcon');

            // Update background
            previewBg.className = `w-20 h-20 rounded-full flex items-center justify-center bg-${color}-100`;

            // Update icon
            previewIcon.className = `icon-preview ${icon} text-${color}-600`;

            // Update text preview for edit mode
            if (mode === 'edit') {
                document.getElementById('editPreviewNameAr').textContent =
                    document.getElementById('editNameAr').value || 'اسم المادة';
                document.getElementById('editPreviewNameEn').textContent =
                    document.getElementById('editNameEn').value || 'Subject Name';
            }
        }

        // Update preview on name change
        document.getElementById('editNameAr')?.addEventListener('input', function () {
            document.getElementById('editPreviewNameAr').textContent = this.value || 'اسم المادة';
        });

        document.getElementById('editNameEn')?.addEventListener('input', function () {
            document.getElementById('editPreviewNameEn').textContent = this.value || 'Subject Name';
        });

        // Close modal when clicking outside
        window.onclick = function (event) {
            if (event.target.id === 'createModal' || event.target.id === 'editModal') {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>

</html>