<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

// Handle achievement actions
$message = '';
$messageType = '';

// Handle DELETE
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $achievementId = (int) $_GET['delete'];

    try {
        $pdo->beginTransaction();

        // Delete user achievements
        $pdo->prepare("DELETE FROM user_achievements WHERE achievement_id = ?")->execute([$achievementId]);
        // Delete achievement
        $pdo->prepare("DELETE FROM achievements WHERE id = ?")->execute([$achievementId]);

        $pdo->commit();

        $message = 'تم حذف الإنجاز بنجاح';
        $messageType = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'فشل حذف الإنجاز';
        $messageType = 'error';
    }
}

// Handle ADD/EDIT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $achievementId = $_POST['achievement_id'] ?? null;
    $nameAr = trim($_POST['name_ar']);
    $descriptionAr = trim($_POST['description_ar']);
    $icon = $_POST['icon'] ?? 'fas fa-trophy';
    $color = $_POST['color'] ?? 'gold';
    $pointsValue = (int) $_POST['points_value'];
    $criteriaType = $_POST['criteria_type'];
    $criteriaValue = (int) $_POST['criteria_value'];
    $gradeGroup = $_POST['grade_group'];

    // Validation
    $errors = [];
    if (empty($nameAr))
        $errors[] = 'اسم الإنجاز مطلوب';
    if ($pointsValue < 0)
        $errors[] = 'النقاط يجب أن تكون صفر أو أكثر';
    if ($criteriaValue < 0)
        $errors[] = 'قيمة المعيار يجب أن تكون صفر أو أكثر';

    if (empty($errors)) {
        try {
            if ($achievementId) {
                // Update existing achievement
                $stmt = $pdo->prepare("UPDATE achievements SET name_ar = ?, description_ar = ?, icon = ?, color = ?, 
                                      points_value = ?, criteria_type = ?, criteria_value = ?, grade_group = ? WHERE id = ?");
                $stmt->execute([$nameAr, $descriptionAr, $icon, $color, $pointsValue, $criteriaType, $criteriaValue, $gradeGroup, $achievementId]);

                $message = 'تم تحديث الإنجاز بنجاح';
                $messageType = 'success';
            } else {
                // Create new achievement
                $stmt = $pdo->prepare("INSERT INTO achievements (name_ar, description_ar, icon, color, points_value, 
                                      criteria_type, criteria_value, grade_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nameAr, $descriptionAr, $icon, $color, $pointsValue, $criteriaType, $criteriaValue, $gradeGroup]);

                $message = 'تم إضافة الإنجاز بنجاح';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'حدث خطأ في حفظ البيانات';
            $messageType = 'error';
        }
    } else {
        $message = implode('<br>', $errors);
        $messageType = 'error';
    }
}

// Get all achievements with stats
$sql = "SELECT a.*, 
        (SELECT COUNT(*) FROM user_achievements WHERE achievement_id = a.id) as earned_count,
        (SELECT COUNT(DISTINCT user_id) FROM user_achievements WHERE achievement_id = a.id) as unique_users
        FROM achievements a
        ORDER BY a.points_value DESC, a.name_ar";

$achievements = $pdo->query($sql)->fetchAll();

// Get stats
$totalAchievements = count($achievements);
$totalAwarded = $pdo->query("SELECT COUNT(*) FROM user_achievements")->fetchColumn();
$totalPoints = array_sum(array_column($achievements, 'points_value'));

// Available icons
$availableIcons = [
    'fas fa-trophy' => 'كأس',
    'fas fa-medal' => 'ميدالية',
    'fas fa-award' => 'جائزة',
    'fas fa-star' => 'نجمة',
    'fas fa-crown' => 'تاج',
    'fas fa-gem' => 'جوهرة',
    'fas fa-fire' => 'نار',
    'fas fa-bolt' => 'برق',
    'fas fa-rocket' => 'صاروخ',
    'fas fa-brain' => 'دماغ',
    'fas fa-graduation-cap' => 'قبعة تخرج',
    'fas fa-book-reader' => 'قارئ',
    'fas fa-user-graduate' => 'خريج',
    'fas fa-certificate' => 'شهادة',
    'fas fa-ribbon' => 'شريط'
];

// Available colors
$availableColors = [
    'gold' => 'ذهبي',
    'silver' => 'فضي',
    'bronze' => 'برونزي',
    'blue' => 'أزرق',
    'green' => 'أخضر',
    'red' => 'أحمر',
    'purple' => 'بنفسجي',
    'pink' => 'وردي',
    'orange' => 'برتقالي',
    'teal' => 'تركوازي'
];

// Criteria types
$criteriaTypes = [
    'score' => 'النتيجة',
    'streak' => 'سلسلة الأيام',
    'speed' => 'السرعة (ثانية)',
    'perfect' => 'نتيجة كاملة',
    'count' => 'عدد الاختبارات'
];

// Grade groups
$gradeGroups = [
    'all' => 'الجميع',
    'elementary' => 'الابتدائية (1-6)',
    'middle' => 'المتوسطة (7-9)',
    'high' => 'الثانوية (10-12)'
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الإنجازات - <?= e(getSetting('site_name')) ?></title>

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

        .achievement-icon {
            font-size: 3rem;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex"
        x-data="{ sidebarOpen: window.innerWidth > 768, showAddModal: false, editAchievement: null }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'" class="bg-gray-800 text-white transition-all duration-300">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" x-transition class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white">
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
                    <a href="subjects.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-book w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المواد الدراسية</span>
                    </a>
                    <a href="achievements.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
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
                <div class="px-6 py-4 flex justify-between items-center">
                    <h1 class="text-2xl font-bold text-gray-800">إدارة الإنجازات والشارات</h1>
                    <button @click="showAddModal = true; editAchievement = null" class="btn btn-primary">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة إنجاز جديد
                    </button>
                </div>
            </header>

            <div class="p-6">
                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> shadow-lg mb-6">
                        <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                        <span><?= $message ?></span>
                    </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">إجمالي الإنجازات</div>
                        <div class="stat-value text-primary"><?= $totalAchievements ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">الإنجازات الممنوحة</div>
                        <div class="stat-value text-green-600"><?= $totalAwarded ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">إجمالي النقاط</div>
                        <div class="stat-value text-purple-600"><?= $totalPoints ?></div>
                    </div>
                </div>

                <!-- Achievements Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($achievements as $achievement): ?>
                        <div class="card bg-white shadow-xl hover:shadow-2xl transition-all hover:-translate-y-1">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <i
                                        class="<?= $achievement['icon'] ?> achievement-icon text-<?= $achievement['color'] == 'gold' ? 'yellow' : ($achievement['color'] == 'silver' ? 'gray' : $achievement['color']) ?>-500"></i>
                                    <h3 class="font-bold text-xl mt-2"><?= e($achievement['name_ar']) ?></h3>
                                    <p class="text-sm text-gray-600 mt-1"><?= e($achievement['description_ar']) ?></p>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-sm mb-4">
                                    <div class="bg-gray-50 rounded p-2">
                                        <span class="text-gray-600">النوع:</span>
                                        <span
                                            class="font-medium"><?= $criteriaTypes[$achievement['criteria_type']] ?></span>
                                    </div>
                                    <div class="bg-gray-50 rounded p-2">
                                        <span class="text-gray-600">القيمة:</span>
                                        <span class="font-medium"><?= $achievement['criteria_value'] ?></span>
                                    </div>
                                    <div class="bg-gray-50 rounded p-2">
                                        <span class="text-gray-600">النقاط:</span>
                                        <span class="font-medium text-primary"><?= $achievement['points_value'] ?></span>
                                    </div>
                                    <div class="bg-gray-50 rounded p-2">
                                        <span class="text-gray-600">المرحلة:</span>
                                        <span class="font-medium"><?= $gradeGroups[$achievement['grade_group']] ?></span>
                                    </div>
                                </div>

                                <div class="stats shadow">
                                    <div class="stat">
                                        <div class="stat-title">حصل عليها</div>
                                        <div class="stat-value text-2xl"><?= $achievement['unique_users'] ?></div>
                                        <div class="stat-desc">طالب</div>
                                    </div>
                                    <div class="stat">
                                        <div class="stat-title">منحت</div>
                                        <div class="stat-value text-2xl"><?= $achievement['earned_count'] ?></div>
                                        <div class="stat-desc">مرة</div>
                                    </div>
                                </div>

                                <div class="card-actions justify-end mt-4">
                                    <button @click='editAchievement = <?= json_encode($achievement) ?>; showAddModal = true'
                                        class="btn btn-sm btn-ghost text-blue-600">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="?delete=<?= $achievement['id'] ?>&confirm=1"
                                        onclick="return confirm('هل أنت متأكد من حذف هذا الإنجاز؟')"
                                        class="btn btn-sm btn-ghost text-red-600">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Add New Achievement Card -->
                    <div @click="showAddModal = true; editAchievement = null"
                        class="card bg-gray-100 border-2 border-dashed border-gray-300 hover:border-gray-400 cursor-pointer transition-colors">
                        <div class="card-body flex items-center justify-center">
                            <div class="text-center">
                                <i class="fas fa-plus-circle text-5xl text-gray-400 mb-4"></i>
                                <p class="text-gray-600">إضافة إنجاز جديد</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Modal -->
    <div x-show="showAddModal" x-cloak
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto"
            @click.away="showAddModal = false">
            <h3 class="text-lg font-bold mb-4" x-text="editAchievement ? 'تعديل الإنجاز' : 'إضافة إنجاز جديد'"></h3>

            <form method="POST">
                <input type="hidden" name="achievement_id" x-model="editAchievement?.id">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-control">
                        <label class="label">اسم الإنجاز</label>
                        <input type="text" name="name_ar" x-model="editAchievement?.name_ar"
                            class="input input-bordered" required>
                    </div>

                    <div class="form-control">
                        <label class="label">الأيقونة</label>
                        <select name="icon" x-model="editAchievement ? editAchievement.icon : 'fas fa-trophy'"
                            class="select select-bordered">
                            <?php foreach ($availableIcons as $icon => $label): ?>
                                <option value="<?= $icon ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-control md:col-span-2">
                        <label class="label">الوصف</label>
                        <textarea name="description_ar" x-model="editAchievement?.description_ar"
                            class="textarea textarea-bordered" rows="2"></textarea>
                    </div>

                    <div class="form-control">
                        <label class="label">اللون</label>
                        <select name="color" x-model="editAchievement ? editAchievement.color : 'gold'"
                            class="select select-bordered">
                            <?php foreach ($availableColors as $color => $label): ?>
                                <option value="<?= $color ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label">النقاط</label>
                        <input type="number" name="points_value" x-model="editAchievement?.points_value"
                            class="input input-bordered" min="0" required>
                    </div>

                    <div class="form-control">
                        <label class="label">نوع المعيار</label>
                        <select name="criteria_type" x-model="editAchievement ? editAchievement.criteria_type : 'score'"
                            class="select select-bordered">
                            <?php foreach ($criteriaTypes as $type => $label): ?>
                                <option value="<?= $type ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label">قيمة المعيار</label>
                        <input type="number" name="criteria_value" x-model="editAchievement?.criteria_value"
                            class="input input-bordered" min="0" required>
                    </div>

                    <div class="form-control md:col-span-2">
                        <label class="label">المرحلة الدراسية</label>
                        <select name="grade_group" x-model="editAchievement ? editAchievement.grade_group : 'all'"
                            class="select select-bordered">
                            <?php foreach ($gradeGroups as $group => $label): ?>
                                <option value="<?= $group ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Preview -->
                <div class="mt-6 p-4 bg-gray-50 rounded-lg text-center">
                    <i :class="editAchievement ? editAchievement.icon : 'fas fa-trophy'" class="text-5xl mb-2"
                        :style="`color: var(--${editAchievement ? editAchievement.color : 'gold'})`"></i>
                    <p class="font-bold">معاينة الإنجاز</p>
                </div>

                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" @click="showAddModal = false" class="btn">إلغاء</button>
                    <button type="submit" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        [x-cloak] {
            display: none !important;
        }

        :root {
            --gold: #fbbf24;
            --silver: #9ca3af;
            --bronze: #d97706;
        }
    </style>
</body>

</html>