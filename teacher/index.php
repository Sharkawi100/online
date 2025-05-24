<?php
// /teacher/index.php - Combined Teacher Dashboard
require_once '../config/database.php';
require_once '../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check authentication - support both admin and teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];

// Check if AI features are available
$ai_enabled = false;
$ai_usage = null;
if (file_exists('../includes/ai_functions.php')) {
    require_once '../includes/ai_functions.php';
    $ai_enabled = getSetting('ai_enabled', false);
    if ($ai_enabled) {
        try {
            $ai_usage = getTeacherAIUsage($teacher_id);
        } catch (Exception $e) {
            // AI not configured
        }
    }
}

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT q.id) as total_quizzes,
        COUNT(DISTINCT a.id) as total_attempts,
        COUNT(DISTINCT CASE WHEN a.user_id IS NOT NULL THEN a.user_id END) as unique_students,
        AVG(a.score) as avg_score,
        COUNT(DISTINCT CASE WHEN q.ai_generated = 1 THEN q.id END) as ai_quizzes
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    WHERE q.teacher_id = ?
");
$stmt->execute([$teacher_id]);
$stats = $stmt->fetch();

// Get recent quizzes with more details
$stmt = $pdo->prepare("
    SELECT q.*, 
           s.name_ar as subject_name,
           s.icon as subject_icon,
           COUNT(DISTINCT qt.id) as question_count,
           COUNT(DISTINCT a.id) as attempt_count,
           MAX(a.completed_at) as last_attempt
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

// Get recent attempts
$stmt = $pdo->prepare("
    SELECT a.*, q.title as quiz_title,
           COALESCE(u.name, a.guest_name) as participant_name
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN users u ON a.user_id = u.id
    WHERE q.teacher_id = ? AND a.completed_at IS NOT NULL
    ORDER BY a.completed_at DESC
    LIMIT 10
");
$stmt->execute([$teacher_id]);
$recent_attempts = $stmt->fetchAll();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المعلم - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>

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
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,0.15);
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
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="<?= BASE_URL ?>/" class="btn btn-ghost normal-case text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none gap-2">
            <!-- AI Credit Badge -->
            <?php if ($ai_enabled && $ai_usage): ?>
                        <div class="badge badge-primary badge-lg gap-2">
                            <i class="fas fa-robot"></i>
                            <span>رصيد AI: <?= $ai_usage['remaining'] ?></span>
                        </div>
            <?php endif; ?>
            
            <!-- User Menu -->
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle avatar">
                    <div class="w-10 rounded-full bg-primary text-white flex items-center justify-center">
                        <i class="fas fa-user"></i>
                    </div>
                </label>
                <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li class="menu-title">
                        <span><?= e($teacher_name) ?></span>
                    </li>
                    <li><a href="<?= BASE_URL ?>/teacher/profile.php"><i class="fas fa-user-edit ml-2"></i>الملف الشخصي</a></li>
                    <li><a href="<?= BASE_URL ?>/teacher/settings.php"><i class="fas fa-cog ml-2"></i>الإعدادات</a></li>
                    <?php if (hasRole('admin')): ?>
                                <li><a href="<?= BASE_URL ?>/admin/"><i class="fas fa-shield-alt ml-2"></i>لوحة الإدارة</a></li>
                    <?php endif; ?>
                    <li class="divider"></li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php" class="text-error"><i class="fas fa-sign-out-alt ml-2"></i>تسجيل الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="gradient-bg text-white py-12">
        <div class="container mx-auto px-4">
            <h1 class="text-4xl font-bold mb-4">مرحباً، <?= e($teacher_name) ?> 👋</h1>
            <p class="text-xl opacity-90">
                <?php if ($stats['total_quizzes'] > 0): ?>
                            لديك <?= $stats['total_quizzes'] ?> اختبار نشط و <?= $stats['unique_students'] ?> طالب مشارك
                <?php else: ?>
                            ابدأ بإنشاء اختبارك الأول وشاركه مع طلابك
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 -mt-16">
            <!-- Create Quiz Card -->
            <div class="card bg-base-100 shadow-xl hover-scale cursor-pointer" onclick="showCreateOptions()">
                <div class="card-body text-center">
                    <div class="text-5xl mb-4 text-primary">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h2 class="card-title justify-center">إنشاء اختبار جديد</h2>
                    <p class="text-gray-600">أنشئ اختباراً جديداً لطلابك</p>
                    <?php if ($ai_enabled && $ai_usage): ?>
                                <div class="badge badge-success badge-sm gap-1">
                                    <i class="fas fa-robot"></i>
                                    AI متاح
                                </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- My Quizzes Card -->
            <a href="<?= BASE_URL ?>/teacher/quizzes/" class="card bg-base-100 shadow-xl hover-scale">
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
            <a href="<?= BASE_URL ?>/teacher/results/" class="card bg-base-100 shadow-xl hover-scale">
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

        <!-- Statistics -->
        <div class="stats shadow w-full mb-8">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <i class="fas fa-clipboard-list text-3xl"></i>
                </div>
                <div class="stat-title">إجمالي الاختبارات</div>
                <div class="stat-value text-primary"><?= $stats['total_quizzes'] ?></div>
                <div class="stat-desc">
                    <?php if ($stats['ai_quizzes'] > 0): ?>
                                منها <?= $stats['ai_quizzes'] ?> بالذكاء الاصطناعي
                    <?php else: ?>
                                اختبار منشور
                    <?php endif; ?>
                </div>
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
                    <i class="fas fa-pencil-alt text-3xl"></i>
                </div>
                <div class="stat-title">إجمالي المحاولات</div>
                <div class="stat-value text-accent"><?= $stats['total_attempts'] ?></div>
                <div class="stat-desc">محاولة مكتملة</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-info">
                    <i class="fas fa-percentage text-3xl"></i>
                </div>
                <div class="stat-title">متوسط الدرجات</div>
                <div class="stat-value text-info"><?= round($stats['avg_score'] ?? 0, 1) ?>%</div>
                <div class="stat-desc">معدل النجاح</div>
            </div>

            <?php if ($ai_usage): ?>
                        <div class="stat">
                            <div class="stat-figure text-warning">
                                <i class="fas fa-robot text-3xl"></i>
                            </div>
                            <div class="stat-title">رصيد AI</div>
                            <div class="stat-value text-warning"><?= $ai_usage['remaining'] ?></div>
                            <div class="stat-desc">من <?= $ai_usage['monthly_limit'] ?> شهرياً</div>
                        </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Quizzes -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-clock text-primary"></i>
                            أحدث الاختبارات
                        </h2>
                        <?php if (!empty($recent_quizzes)): ?>
                                    <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-ghost btn-sm">
                                        عرض الكل
                                        <i class="fas fa-arrow-left mr-2"></i>
                                    </a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($recent_quizzes)): ?>
                                <div class="text-center py-8">
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
                    <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($recent_quizzes as $quiz): ?>
                                                <div class="border rounded-lg p-4 hover:bg-base-200 transition-colors">
                                                    <div class="flex items-start justify-between">
                                                        <div class="flex-1">
                                                            <div class="flex items-center gap-2">
                                                                <h3 class="font-bold"><?= e($quiz['title']) ?></h3>
                                                                <?php if ($quiz['ai_generated']): ?>
                                                                            <div class="badge badge-primary badge-sm">
                                                                                <i class="fas fa-robot ml-1 text-xs"></i>
                                                                                AI
                                                                            </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex flex-wrap items-center gap-2 mt-2 text-sm text-gray-600">
                                                                <?php if (isset($quiz['subject_name'])): ?>
                                                                            <span class="badge badge-ghost badge-sm">
                                                                                <i class="<?= $quiz['subject_icon'] ?? 'fas fa-book' ?> ml-1"></i>
                                                                                <?= e($quiz['subject_name']) ?>
                                                                            </span>
                                                                <?php endif; ?>
                                                                <span class="badge badge-ghost badge-sm"><?= getGradeName($quiz['grade']) ?></span>
                                                                <span>•</span>
                                                                <span><?= $quiz['question_count'] ?? 0 ?> سؤال</span>
                                                                <span>•</span>
                                                                <span><?= $quiz['attempt_count'] ?> محاولة</span>
                                                                <?php if ($quiz['last_attempt']): ?>
                                                                            <span>•</span>
                                                                            <span>آخر محاولة <?= timeAgo($quiz['last_attempt']) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="flex items-center gap-3">
                                                            <div class="text-center">
                                                                <div class="text-xs text-gray-500">PIN</div>
                                                                <code class="text-lg font-bold text-primary"><?= $quiz['pin_code'] ?></code>
                                                            </div>
                                                            <div class="dropdown dropdown-end">
                                                                <label tabindex="0" class="btn btn-ghost btn-sm btn-square">
                                                                    <i class="fas fa-ellipsis-v"></i>
                                                                </label>
                                                                <ul tabindex="0" class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-52">
                                                                    <li><a href="<?= BASE_URL ?>/teacher/quizzes/manage.php?id=<?= $quiz['id'] ?>">
                                                                        <i class="fas fa-cog"></i> إدارة الاختبار
                                                                    </a></li>
                                                                    <li><a href="<?= BASE_URL ?>/teacher/quizzes/edit.php?id=<?= $quiz['id'] ?>">
                                                                        <i class="fas fa-edit"></i> تعديل الأسئلة
                                                                    </a></li>
                                                                    <li><a href="<?= BASE_URL ?>/teacher/results/quiz.php?id=<?= $quiz['id'] ?>">
                                                                        <i class="fas fa-chart-bar"></i> عرض النتائج
                                                                    </a></li>
                                                                    <li class="divider"></li>
                                                                    <li><a href="<?= BASE_URL ?>/quiz/start.php?pin=<?= $quiz['pin_code'] ?>" target="_blank">
                                                                        <i class="fas fa-play"></i> معاينة الاختبار
                                                                    </a></li>
                                                                </ul>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                    <?php endforeach; ?>
                                </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Attempts -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-history text-primary"></i>
                            آخر المحاولات
                        </h2>
                        <?php if (!empty($recent_attempts)): ?>
                                    <a href="<?= BASE_URL ?>/teacher/results/" class="btn btn-ghost btn-sm">
                                        عرض الكل
                                        <i class="fas fa-arrow-left mr-2"></i>
                                    </a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($recent_attempts)): ?>
                                <div class="text-center py-8">
                                    <div class="text-6xl text-gray-300 mb-4">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <p class="text-gray-600">لا توجد محاولات حتى الآن</p>
                                    <p class="text-sm text-gray-500 mt-2">شارك رموز الاختبارات مع طلابك</p>
                                </div>
                    <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($recent_attempts as $attempt): ?>
                                                <div class="flex items-center justify-between p-3 bg-base-200 rounded-lg">
                                                    <div class="flex items-center gap-3">
                                                        <div class="avatar placeholder">
                                                            <div class="bg-neutral-focus text-neutral-content rounded-full w-10">
                                                                <span class="text-sm"><?= mb_substr($attempt['participant_name'], 0, 1) ?></span>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium"><?= e($attempt['participant_name']) ?></div>
                                                            <div class="text-xs text-gray-600"><?= e($attempt['quiz_title']) ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="text-left">
                                                        <div class="flex items-center gap-2">
                                                            <progress
                                                                class="progress progress-<?= $attempt['score'] >= 80 ? 'success' : ($attempt['score'] >= 60 ? 'warning' : 'error') ?> w-20"
                                                                value="<?= $attempt['score'] ?>" max="100">
                                                            </progress>
                                                            <span class="font-bold text-lg"><?= round($attempt['score']) ?>%</span>
                                                        </div>
                                                        <div class="text-xs text-gray-500 mt-1">
                                                            <i class="fas fa-clock ml-1"></i>
                                                            <?= timeAgo($attempt['completed_at']) ?>
                                                        </div>
                                                    </div>
                                                </div>
                                    <?php endforeach; ?>
                                </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <?php if ($stats['total_quizzes'] == 0): ?>
                    <div class="card bg-info text-info-content mt-8">
                        <div class="card-body">
                            <h2 class="card-title">
                                <i class="fas fa-lightbulb"></i>
                                نصائح للبدء
                            </h2>
                            <ul class="list-disc list-inside space-y-2">
                                <li>أنشئ اختبارك الأول بنقرة واحدة</li>
                                <li>استخدم الذكاء الاصطناعي لتوليد أسئلة احترافية</li>
                                <li>شارك رمز PIN مع طلابك ليتمكنوا من الدخول</li>
                                <li>تابع النتائج والإحصائيات في الوقت الفعلي</li>
                            </ul>
                        </div>
                    </div>
        <?php endif; ?>
    </div>

    <!-- Create Options Modal -->
    <dialog id="createModal" class="modal">
        <div class="modal-box max-w-2xl">
            <h3 class="font-bold text-2xl mb-6">كيف تريد إنشاء الاختبار؟</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if ($ai_enabled): ?>
                            <!-- AI Creation Option -->
                            <a href="<?= BASE_URL ?>/teacher/quizzes/ai-generate.php" 
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
                <?php endif; ?>

                <!-- Manual Creation Option -->
                <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" 
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
            <?php if ($ai_enabled): ?>
                        document.getElementById('createModal').showModal();
            <?php else: ?>
                        // If AI is not enabled, go directly to manual creation
                        window.location.href = '<?= BASE_URL ?>/teacher/quizzes/create.php';
            <?php endif; ?>
        }
    </script>
</body>
</html>