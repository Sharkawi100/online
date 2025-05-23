<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

// Handle quiz actions
$message = '';
$messageType = '';

// Handle DELETE
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $quizId = (int) $_GET['delete'];

    try {
        $pdo->beginTransaction();

        // Delete related data
        $pdo->prepare("DELETE FROM answers WHERE attempt_id IN (SELECT id FROM attempts WHERE quiz_id = ?)")->execute([$quizId]);
        $pdo->prepare("DELETE FROM attempts WHERE quiz_id = ?")->execute([$quizId]);
        $pdo->prepare("DELETE FROM options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = ?)")->execute([$quizId]);
        $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?")->execute([$quizId]);
        $pdo->prepare("DELETE FROM quizzes WHERE id = ?")->execute([$quizId]);

        $pdo->commit();

        $message = 'تم حذف الاختبار بنجاح';
        $messageType = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'فشل حذف الاختبار';
        $messageType = 'error';
    }
}

// Handle TOGGLE STATUS
if (isset($_GET['toggle'])) {
    $quizId = (int) $_GET['toggle'];
    $stmt = $pdo->prepare("UPDATE quizzes SET is_active = NOT is_active WHERE id = ?");
    if ($stmt->execute([$quizId])) {
        $message = 'تم تحديث حالة الاختبار';
        $messageType = 'success';
    }
}

// Handle REGENERATE PIN
if (isset($_GET['regenerate_pin'])) {
    $quizId = (int) $_GET['regenerate_pin'];
    $newPin = generatePIN();
    $stmt = $pdo->prepare("UPDATE quizzes SET pin_code = ? WHERE id = ?");
    if ($stmt->execute([$newPin, $quizId])) {
        $message = 'تم تجديد رمز الاختبار: ' . $newPin;
        $messageType = 'success';
    }
}

// Get filter parameters
$gradeFilter = $_GET['grade'] ?? '';
$subjectFilter = $_GET['subject'] ?? '';
$teacherFilter = $_GET['teacher'] ?? '';
$searchQuery = $_GET['search'] ?? '';

// Build query
$sql = "SELECT q.*, u.name as teacher_name, s.name_ar as subject_name,
        (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
        (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) as attempt_count,
        (SELECT AVG(score) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as avg_score
        FROM quizzes q
        LEFT JOIN users u ON q.teacher_id = u.id
        LEFT JOIN subjects s ON q.subject_id = s.id
        WHERE 1=1";
$params = [];

if ($gradeFilter) {
    $sql .= " AND q.grade = ?";
    $params[] = $gradeFilter;
}

if ($subjectFilter) {
    $sql .= " AND q.subject_id = ?";
    $params[] = $subjectFilter;
}

if ($teacherFilter) {
    $sql .= " AND q.teacher_id = ?";
    $params[] = $teacherFilter;
}

if ($searchQuery) {
    $sql .= " AND (q.title LIKE ? OR q.description LIKE ? OR q.pin_code = ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = $searchQuery;
}

$sql .= " ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$quizzes = $stmt->fetchAll();

// Get subjects for filter
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// Get teachers for filter
$teachers = $pdo->query("SELECT id, name FROM users WHERE role = 'teacher' AND is_active = 1 ORDER BY name")->fetchAll();

// Get stats
$totalQuizzes = $pdo->query("SELECT COUNT(*) FROM quizzes")->fetchColumn();
$activeQuizzes = $pdo->query("SELECT COUNT(*) FROM quizzes WHERE is_active = 1")->fetchColumn();
$totalAttempts = $pdo->query("SELECT COUNT(*) FROM attempts WHERE completed_at IS NOT NULL")->fetchColumn();
$avgQuizScore = $pdo->query("SELECT AVG(score) FROM attempts WHERE completed_at IS NOT NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الاختبارات - <?= e(getSetting('site_name')) ?></title>

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

        .pin-code {
            font-family: monospace;
            letter-spacing: 0.2em;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: window.innerWidth > 768, showQuizDetails: null }">
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
                    <a href="quizzes.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
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
                    <h1 class="text-2xl font-bold text-gray-800">إدارة الاختبارات</h1>
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
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">إجمالي الاختبارات</div>
                        <div class="stat-value text-primary"><?= $totalQuizzes ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">الاختبارات النشطة</div>
                        <div class="stat-value text-green-600"><?= $activeQuizzes ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">إجمالي المحاولات</div>
                        <div class="stat-value text-blue-600"><?= $totalAttempts ?></div>
                    </div>
                    <div class="stat bg-white rounded-lg shadow">
                        <div class="stat-title">متوسط النتائج</div>
                        <div class="stat-value text-purple-600"><?= round($avgQuizScore) ?>%</div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card bg-white shadow-xl mb-6">
                    <div class="card-body">
                        <form method="GET" class="flex flex-wrap gap-4">
                            <div class="form-control flex-1">
                                <input type="text" name="search" placeholder="البحث بالعنوان أو الرمز..."
                                    value="<?= e($searchQuery) ?>" class="input input-bordered">
                            </div>
                            <select name="grade" class="select select-bordered">
                                <option value="">جميع الصفوف</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= $gradeFilter == $i ? 'selected' : '' ?>>
                                        <?= getGradeName($i) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="subject" class="select select-bordered">
                                <option value="">جميع المواد</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $subjectFilter == $subject['id'] ? 'selected' : '' ?>>
                                        <?= e($subject['name_ar']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="teacher" class="select select-bordered">
                                <option value="">جميع المعلمين</option>
                                <?php foreach ($teachers as $teacher): ?>
                                    <option value="<?= $teacher['id'] ?>" <?= $teacherFilter == $teacher['id'] ? 'selected' : '' ?>>
                                        <?= e($teacher['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search ml-2"></i>
                                بحث
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Quizzes Table -->
                <div class="card bg-white shadow-xl">
                    <div class="card-body">
                        <div class="overflow-x-auto">
                            <table class="table table-zebra">
                                <thead>
                                    <tr>
                                        <th>الاختبار</th>
                                        <th>رمز الدخول</th>
                                        <th>المعلم</th>
                                        <th>المادة</th>
                                        <th>الصف</th>
                                        <th>الأسئلة</th>
                                        <th>المحاولات</th>
                                        <th>المتوسط</th>
                                        <th>الحالة</th>
                                        <th>الإجراءات</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quizzes as $quiz): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <div class="font-bold"><?= e($quiz['title']) ?></div>
                                                    <div class="text-sm opacity-50">
                                                        <?= e(mb_substr($quiz['description'], 0, 50)) ?>...
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="flex items-center gap-2">
                                                    <code class="pin-code text-lg font-bold bg-gray-100 px-2 py-1 rounded">
                                                            <?= $quiz['pin_code'] ?>
                                                        </code>
                                                    <button onclick="copyToClipboard('<?= $quiz['pin_code'] ?>')"
                                                        class="btn btn-ghost btn-xs">
                                                        <i class="fas fa-copy"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td><?= e($quiz['teacher_name']) ?></td>
                                            <td><?= e($quiz['subject_name'] ?? 'عام') ?></td>
                                            <td>
                                                <span class="badge badge-<?= getGradeColor($quiz['grade']) ?>">
                                                    <?= getGradeName($quiz['grade']) ?>
                                                </span>
                                            </td>
                                            <td><?= $quiz['question_count'] ?></td>
                                            <td><?= $quiz['attempt_count'] ?></td>
                                            <td>
                                                <?php if ($quiz['avg_score']): ?>
                                                    <span
                                                        class="badge <?= $quiz['avg_score'] >= 80 ? 'badge-success' : ($quiz['avg_score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                                        <?= round($quiz['avg_score']) ?>%
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="badge <?= $quiz['is_active'] ? 'badge-success' : 'badge-error' ?>">
                                                    <?= $quiz['is_active'] ? 'نشط' : 'معطل' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="flex gap-1">
                                                    <button @click='showQuizDetails = <?= json_encode($quiz) ?>'
                                                        class="btn btn-ghost btn-xs text-blue-600" title="عرض التفاصيل">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="../teacher/quizzes/edit.php?id=<?= $quiz['id'] ?>"
                                                        target="_blank" class="btn btn-ghost btn-xs text-green-600"
                                                        title="تعديل">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?toggle=<?= $quiz['id'] ?>"
                                                        class="btn btn-ghost btn-xs text-orange-600" title="تغيير الحالة">
                                                        <i class="fas fa-power-off"></i>
                                                    </a>
                                                    <a href="?regenerate_pin=<?= $quiz['id'] ?>"
                                                        class="btn btn-ghost btn-xs text-purple-600" title="تجديد الرمز">
                                                        <i class="fas fa-sync"></i>
                                                    </a>
                                                    <a href="?delete=<?= $quiz['id'] ?>&confirm=1"
                                                        onclick="return confirm('هل أنت متأكد من حذف هذا الاختبار؟ سيتم حذف جميع المحاولات المرتبطة به.')"
                                                        class="btn btn-ghost btn-xs text-red-600" title="حذف">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Quiz Details Modal -->
    <div x-show="showQuizDetails" x-cloak
        class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto"
            @click.away="showQuizDetails = null">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">تفاصيل الاختبار</h3>
                <button @click="showQuizDetails = null" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <template x-if="showQuizDetails">
                <div>
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <strong>العنوان:</strong> <span x-text="showQuizDetails.title"></span>
                        </div>
                        <div>
                            <strong>رمز الدخول:</strong>
                            <code class="pin-code bg-gray-100 px-2 py-1 rounded"
                                x-text="showQuizDetails.pin_code"></code>
                        </div>
                        <div>
                            <strong>المعلم:</strong> <span x-text="showQuizDetails.teacher_name"></span>
                        </div>
                        <div>
                            <strong>المادة:</strong> <span x-text="showQuizDetails.subject_name || 'عام'"></span>
                        </div>
                        <div>
                            <strong>الصف:</strong> <span x-text="'الصف ' + showQuizDetails.grade"></span>
                        </div>
                        <div>
                            <strong>الصعوبة:</strong>
                            <span
                                x-text="{'easy': 'سهل', 'medium': 'متوسط', 'hard': 'صعب', 'mixed': 'متنوع'}[showQuizDetails.difficulty]"></span>
                        </div>
                        <div>
                            <strong>المدة:</strong>
                            <span
                                x-text="showQuizDetails.time_limit > 0 ? showQuizDetails.time_limit + ' دقيقة' : 'غير محدد'"></span>
                        </div>
                        <div>
                            <strong>اللغة:</strong>
                            <span x-text="showQuizDetails.language === 'ar' ? 'عربي' : 'إنجليزي'"></span>
                        </div>
                    </div>

                    <div class="mb-4">
                        <strong>الوصف:</strong>
                        <p class="mt-1" x-text="showQuizDetails.description"></p>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div class="stat bg-gray-50 rounded">
                            <div class="stat-title">الأسئلة</div>
                            <div class="stat-value text-primary" x-text="showQuizDetails.question_count"></div>
                        </div>
                        <div class="stat bg-gray-50 rounded">
                            <div class="stat-title">المحاولات</div>
                            <div class="stat-value text-green-600" x-text="showQuizDetails.attempt_count"></div>
                        </div>
                        <div class="stat bg-gray-50 rounded">
                            <div class="stat-title">المتوسط</div>
                            <div class="stat-value text-blue-600"
                                x-text="(showQuizDetails.avg_score || 0).toFixed(0) + '%'"></div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-6">
                        <a :href="'../quiz/join.php?pin=' + showQuizDetails.pin_code" target="_blank"
                            class="btn btn-primary">
                            <i class="fas fa-play ml-2"></i>
                            تجربة الاختبار
                        </a>
                        <button @click="showQuizDetails = null" class="btn">إغلاق</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('تم نسخ الرمز: ' + text);
            });
        }
    </script>

    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
</body>

</html>