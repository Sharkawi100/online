<?php
// /teacher/index.php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'];

// Get statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT q.id) as total_quizzes,
        COUNT(DISTINCT a.id) as total_attempts,
        COUNT(DISTINCT CASE WHEN a.user_id IS NOT NULL THEN a.user_id END) as unique_students,
        AVG(a.score) as avg_score
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    WHERE q.teacher_id = ?
");
$stmt->execute([$teacher_id]);
$stats = $stmt->fetch();

// Get recent quizzes
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(DISTINCT a.id) as attempt_count,
           MAX(a.completed_at) as last_attempt
    FROM quizzes q
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة المعلم - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .stat-card {
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="<?= BASE_URL ?>/teacher/" class="btn btn-ghost normal-case text-xl">
                <i class="fas fa-chalkboard-teacher ml-2"></i>
                لوحة المعلم
            </a>
        </div>
        <div class="flex-none">
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost">
                    <div class="flex items-center gap-2">
                        <span class="hidden md:inline"><?= e($teacher_name) ?></span>
                        <i class="fas fa-user-circle text-2xl"></i>
                    </div>
                </label>
                <ul tabindex="0"
                    class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="<?= BASE_URL ?>/teacher/profile.php"><i class="fas fa-user ml-2"></i> الملف الشخصي</a>
                    </li>
                    <li><a href="<?= BASE_URL ?>/teacher/settings.php"><i class="fas fa-cog ml-2"></i> الإعدادات</a>
                    </li>
                    <li class="divider"></li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php" class="text-error"><i
                                class="fas fa-sign-out-alt ml-2"></i> تسجيل الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <!-- Welcome Section -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">مرحباً، <?= e($teacher_name) ?>!</h1>
            <p class="text-gray-600">إليك نظرة عامة على نشاطك التعليمي</p>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" class="btn btn-primary btn-lg h-auto py-6">
                <i class="fas fa-plus-circle text-2xl mb-2"></i>
                <span>إنشاء اختبار جديد</span>
            </a>
            <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-outline btn-primary btn-lg h-auto py-6">
                <i class="fas fa-list text-2xl mb-2"></i>
                <span>إدارة الاختبارات</span>
            </a>
            <a href="<?= BASE_URL ?>/teacher/quizzes/results.php"
                class="btn btn-outline btn-primary btn-lg h-auto py-6">
                <i class="fas fa-chart-bar text-2xl mb-2"></i>
                <span>عرض النتائج</span>
            </a>
        </div>

        <!-- Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card card bg-primary text-primary-content shadow-xl">
                <div class="card-body">
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-clipboard-list text-4xl opacity-70"></i>
                        </div>
                        <div class="stat-title text-primary-content opacity-90">الاختبارات</div>
                        <div class="stat-value"><?= $stats['total_quizzes'] ?></div>
                        <div class="stat-desc text-primary-content opacity-70">اختبار منشور</div>
                    </div>
                </div>
            </div>

            <div class="stat-card card bg-secondary text-secondary-content shadow-xl">
                <div class="card-body">
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-pencil-alt text-4xl opacity-70"></i>
                        </div>
                        <div class="stat-title text-secondary-content opacity-90">المحاولات</div>
                        <div class="stat-value"><?= $stats['total_attempts'] ?></div>
                        <div class="stat-desc text-secondary-content opacity-70">إجمالي المحاولات</div>
                    </div>
                </div>
            </div>

            <div class="stat-card card bg-accent text-accent-content shadow-xl">
                <div class="card-body">
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-user-graduate text-4xl opacity-70"></i>
                        </div>
                        <div class="stat-title text-accent-content opacity-90">الطلاب</div>
                        <div class="stat-value"><?= $stats['unique_students'] ?></div>
                        <div class="stat-desc text-accent-content opacity-70">طالب مشارك</div>
                    </div>
                </div>
            </div>

            <div class="stat-card card bg-info text-info-content shadow-xl">
                <div class="card-body">
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-percentage text-4xl opacity-70"></i>
                        </div>
                        <div class="stat-title text-info-content opacity-90">المتوسط</div>
                        <div class="stat-value"><?= round($stats['avg_score'] ?? 0, 1) ?>%</div>
                        <div class="stat-desc text-info-content opacity-70">متوسط النتائج</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Recent Quizzes -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-clock text-primary"></i>
                        أحدث الاختبارات
                    </h2>

                    <?php if (empty($recent_quizzes)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-clipboard-list text-4xl mb-4"></i>
                            <p>لم تقم بإنشاء أي اختبارات بعد</p>
                            <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" class="btn btn-primary btn-sm mt-4">
                                <i class="fas fa-plus ml-2"></i>
                                إنشاء أول اختبار
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_quizzes as $quiz): ?>
                                <div class="flex items-center justify-between p-3 bg-base-200 rounded-lg">
                                    <div class="flex-1">
                                        <h3 class="font-bold"><?= e($quiz['title']) ?></h3>
                                        <div class="text-sm text-gray-600 mt-1">
                                            <span class="badge badge-sm"><?= getGradeName($quiz['grade']) ?></span>
                                            <span class="mx-2">•</span>
                                            <span><?= $quiz['attempt_count'] ?> محاولة</span>
                                            <?php if ($quiz['last_attempt']): ?>
                                                <span class="mx-2">•</span>
                                                <span>آخر محاولة <?= timeAgo($quiz['last_attempt']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <div class="text-center">
                                            <div class="text-xs text-gray-500">الرمز</div>
                                            <code class="text-lg font-bold"><?= $quiz['pin_code'] ?></code>
                                        </div>
                                        <div class="dropdown dropdown-end">
                                            <label tabindex="0" class="btn btn-ghost btn-sm">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </label>
                                            <ul tabindex="0"
                                                class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-40">
                                                <li><a href="<?= BASE_URL ?>/teacher/quizzes/edit.php?id=<?= $quiz['id'] ?>">
                                                        <i class="fas fa-edit"></i> تعديل
                                                    </a></li>
                                                <li><a
                                                        href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $quiz['id'] ?>">
                                                        <i class="fas fa-chart-bar"></i> النتائج
                                                    </a></li>
                                                <li><a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $quiz['pin_code'] ?>"
                                                        target="_blank">
                                                        <i class="fas fa-play"></i> معاينة
                                                    </a></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="card-actions justify-end mt-4">
                            <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-ghost btn-sm">
                                عرض الكل
                                <i class="fas fa-arrow-left mr-2"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Attempts -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-history text-primary"></i>
                        آخر المحاولات
                    </h2>

                    <?php if (empty($recent_attempts)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-users text-4xl mb-4"></i>
                            <p>لا توجد محاولات حتى الآن</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_attempts as $attempt): ?>
                                <div class="flex items-center justify-between p-3 bg-base-200 rounded-lg">
                                    <div class="flex items-center gap-3">
                                        <div class="avatar placeholder">
                                            <div class="bg-neutral-focus text-neutral-content rounded-full w-10">
                                                <span
                                                    class="text-sm"><?= mb_substr($attempt['participant_name'], 0, 1) ?></span>
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
                                                value="<?= $attempt['score'] ?>" max="100"></progress>
                                            <span class="font-bold"><?= round($attempt['score']) ?>%</span>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1"><?= timeAgo($attempt['completed_at']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="card-actions justify-end mt-4">
                            <a href="<?= BASE_URL ?>/teacher/quizzes/results.php" class="btn btn-ghost btn-sm">
                                عرض الكل
                                <i class="fas fa-arrow-left mr-2"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>

</html>