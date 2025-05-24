<?php
// /teacher/dashboard.php - Enhanced Teacher Dashboard
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/ai_functions.php';

// Check authentication
if (!isLoggedIn() || !hasAnyRole(['admin', 'teacher'])) {
    redirect('/auth/login.php');
}

// Get teacher stats
$teacher_id = $_SESSION['user_id'];

// Get AI usage if available
$ai_usage = null;
$ai_enabled = getSetting('ai_enabled', false);
if ($ai_enabled) {
    try {
        $ai_usage = getTeacherAIUsage($teacher_id);
    } catch (Exception $e) {
        // AI not configured
    }
}

// Get recent quizzes
$stmt = $pdo->prepare("
    SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon,
           COUNT(DISTINCT qt.id) as question_count,
           COUNT(DISTINCT a.id) as attempt_count
    FROM quizzes q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN questions qt ON q.id = qt.quiz_id
    LEFT JOIN attempts a ON q.id = a.quiz_id
    WHERE q.teacher_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
    LIMIT 5
");
$stmt->execute([$teacher_id]);
$recent_quizzes = $stmt->fetchAll();

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT q.id) as total_quizzes,
        COUNT(DISTINCT a.id) as total_attempts,
        COUNT(DISTINCT a.user_id) as unique_students,
        AVG(a.score) as avg_score
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id
    WHERE q.teacher_id = ?
");
$stmt->execute([$teacher_id]);
$stats = $stmt->fetch();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة تحكم المعلم - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .hover-scale {
            transition: transform 0.2s;
        }

        .hover-scale:hover {
            transform: scale(1.05);
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="/" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none">
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle avatar">
                    <div class="w-10 rounded-full bg-primary text-white flex items-center justify-center">
                        <i class="fas fa-user"></i>
                    </div>
                </label>
                <ul tabindex="0"
                    class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li class="menu-title">
                        <span><?= e($_SESSION['user_name']) ?></span>
                    </li>
                    <li><a href="profile.php"><i class="fas fa-user-edit ml-2"></i>الملف الشخصي</a></li>
                    <li><a href="settings.php"><i class="fas fa-cog ml-2"></i>الإعدادات</a></li>
                    <li><a href="/auth/logout.php"><i class="fas fa-sign-out-alt ml-2"></i>تسجيل الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="gradient-bg text-white py-12">
        <div class="container mx-auto px-4">
            <h1 class="text-4xl font-bold mb-4">مرحباً، <?= e($_SESSION['user_name']) ?> 👋</h1>
            <p class="text-xl opacity-90">ابدأ بإنشاء اختبار جديد أو إدارة اختباراتك الحالية</p>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 -mt-8">
            <!-- Create Quiz Card -->
            <div class="card bg-base-100 shadow-xl hover-scale cursor-pointer" onclick="showCreateOptions()">
                <div class="card-body text-center">
                    <div class="text-5xl mb-4 text-primary">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h2 class="card-title justify-center">إنشاء اختبار جديد</h2>
                    <p class="text-gray-600">أنشئ اختباراً باستخدام الذكاء الاصطناعي أو يدوياً</p>
                    <?php if ($ai_enabled && $ai_usage): ?>
                        <div class="badge badge-success badge-sm">
                            <i class="fas fa-robot ml-1"></i>
                            AI متاح
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Quizzes Card -->
            <a href="quizzes/" class="card bg-base-100 shadow-xl hover-scale">
                <div class="card-body text-center">
                    <div class="text-5xl mb-4 text-info">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <h2 class="card-title justify-center">اختباراتي</h2>
                    <p class="text-gray-600">عرض وإدارة جميع اختباراتك</p>
                    <div class="badge badge-info badge-outline"><?= $stats['total_quizzes'] ?> اختبار</div>
                </div>
            </a>

            <!-- Results Card -->
            <a href="quizzes/results.php" class="card bg-base-100 shadow-xl hover-scale">
                <div class="card-body text-center">
                    <div class="text-5xl mb-4 text-success">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h2 class="card-title justify-center">النتائج والتقارير</h2>
                    <p class="text-gray-600">تحليل أداء الطلاب</p>
                    <div class="badge badge-success badge-outline"><?= $stats['total_attempts'] ?> محاولة</div>
                </div>
            </a>
        </div>

        <!-- Statistics Overview -->
        <div class="stats shadow w-full mb-8">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <i class="fas fa-clipboard-list text-3xl"></i>
                </div>
                <div class="stat-title">إجمالي الاختبارات</div>
                <div class="stat-value text-primary"><?= $stats['total_quizzes'] ?></div>
                <div class="stat-desc">اختبار منشور</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-secondary">
                    <i class="fas fa-users text-3xl"></i>
                </div>
                <div class="stat-title">الطلاب المشاركون</div>
                <div class="stat-value text-secondary"><?= $stats['unique_students'] ?></div>
                <div class="stat-desc">طالب فريد</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-accent">
                    <i class="fas fa-percentage text-3xl"></i>
                </div>
                <div class="stat-title">متوسط الدرجات</div>
                <div class="stat-value text-accent"><?= round($stats['avg_score'] ?? 0, 1) ?>%</div>
                <div class="stat-desc">معدل النجاح</div>
            </div>

            <?php if ($ai_usage): ?>
                <div class="stat">
                    <div class="stat-figure text-info">
                        <i class="fas fa-robot text-3xl"></i>
                    </div>
                    <div class="stat-title">الذكاء الاصطناعي</div>
                    <div class="stat-value text-info"><?= $ai_usage['remaining'] ?></div>
                    <div class="stat-desc">رصيد متبقي هذا الشهر</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Quizzes -->
        <?php if (!empty($recent_quizzes)): ?>
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-clock text-primary"></i>
                            الاختبارات الأخيرة
                        </h2>
                        <a href="quizzes/" class="btn btn-ghost btn-sm">
                            عرض الكل
                            <i class="fas fa-arrow-left mr-2"></i>
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>الاختبار</th>
                                    <th>المادة</th>
                                    <th>PIN</th>
                                    <th>الأسئلة</th>
                                    <th>المحاولات</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_quizzes as $quiz): ?>
                                    <tr class="hover">
                                        <td>
                                            <div class="font-bold"><?= e($quiz['title']) ?></div>
                                            <div class="text-sm opacity-50"><?= getGradeName($quiz['grade']) ?></div>
                                        </td>
                                        <td>
                                            <div class="badge badge-ghost">
                                                <i class="<?= $quiz['subject_icon'] ?> ml-1"></i>
                                                <?= e($quiz['subject_name']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <code class="text-lg font-bold text-primary"><?= $quiz['pin_code'] ?></code>
                                        </td>
                                        <td><?= $quiz['question_count'] ?></td>
                                        <td><?= $quiz['attempt_count'] ?></td>
                                        <td>
                                            <div class="flex gap-2">
                                                <a href="quizzes/manage.php?id=<?= $quiz['id'] ?>" class="btn btn-ghost btn-xs">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="results/quiz.php?id=<?= $quiz['id'] ?>" class="btn btn-ghost btn-xs">
                                                    <i class="fas fa-chart-line"></i>
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
        <?php else: ?>
            <!-- Empty State -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body text-center py-12">
                    <div class="text-6xl text-gray-300 mb-4">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2">لا توجد اختبارات بعد</h3>
                    <p class="text-gray-600 mb-6">ابدأ بإنشاء أول اختبار لك</p>
                    <button onclick="showCreateOptions()" class="btn btn-primary">
                        <i class="fas fa-plus ml-2"></i>
                        إنشاء اختبار جديد
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Create Options Modal -->
    <dialog id="createModal" class="modal">
        <div class="modal-box max-w-2xl">
            <h3 class="font-bold text-2xl mb-6">كيف تريد إنشاء الاختبار؟</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- AI Creation Option -->
                <a href="quizzes/ai-generate.php"
                    class="card bg-gradient-to-br from-purple-500 to-pink-500 text-white hover-scale">
                    <div class="card-body text-center">
                        <div class="text-5xl mb-4">
                            <i class="fas fa-magic"></i>
                        </div>
                        <h4 class="text-xl font-bold mb-2">توليد بالذكاء الاصطناعي</h4>
                        <p class="opacity-90">دع الذكاء الاصطناعي يساعدك في إنشاء أسئلة احترافية</p>
                        <?php if ($ai_usage): ?>
                            <div class="badge badge-warning badge-sm mt-2">
                                <?= $ai_usage['remaining'] ?> رصيد متبقي
                            </div>
                        <?php endif; ?>
                    </div>
                </a>

                <!-- Manual Creation Option -->
                <a href="quizzes/create.php"
                    class="card bg-gradient-to-br from-blue-500 to-teal-500 text-white hover-scale">
                    <div class="card-body text-center">
                        <div class="text-5xl mb-4">
                            <i class="fas fa-edit"></i>
                        </div>
                        <h4 class="text-xl font-bold mb-2">إنشاء يدوي</h4>
                        <p class="opacity-90">أنشئ الأسئلة بنفسك مع التحكم الكامل</p>
                        <div class="badge badge-info badge-sm mt-2">
                            تحكم كامل
                        </div>
                    </div>
                </a>
            </div>

            <?php if ($ai_enabled): ?>
                <div class="alert alert-info mt-6">
                    <i class="fas fa-lightbulb"></i>
                    <span>نصيحة: يمكنك البدء بالذكاء الاصطناعي ثم تعديل الأسئلة حسب حاجتك</span>
                </div>
            <?php endif; ?>

            <div class="modal-action">
                <button class="btn" onclick="createModal.close()">إلغاء</button>
            </div>
        </div>
    </dialog>

    <script>
        function showCreateOptions() {
            document.getElementById('createModal').showModal();
        }
    </script>
</body>

</html>