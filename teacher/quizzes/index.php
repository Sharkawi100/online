<?php
// /teacher/quizzes/index.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}
// Check if user is logged in and is a teacher or admin
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}


$teacher_id = $_SESSION['user_id'];

// Get all quizzes for the teacher
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(DISTINCT a.id) as attempt_count,
           AVG(a.score) as avg_score,
           s.name_ar as subject_name
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE q.teacher_id = ?
    GROUP BY q.id
    ORDER BY q.created_at DESC
");
$stmt->execute([$teacher_id]);
$quizzes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الاختبارات - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .quiz-card {
            transition: transform 0.2s;
        }

        .quiz-card:hover {
            transform: translateY(-2px);
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
                    <i class="fas fa-user-circle text-2xl"></i>
                </label>
                <ul tabindex="0"
                    class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="<?= BASE_URL ?>/teacher/profile.php"><i class="fas fa-user ml-2"></i> الملف الشخصي</a>
                    </li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt ml-2"></i> تسجيل
                            الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold mb-2">إدارة الاختبارات</h1>
                    <div class="breadcrumbs text-sm">
                        <ul>
                            <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                            <li>الاختبارات</li>
                        </ul>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" class="btn btn-primary">
                    <i class="fas fa-plus ml-2"></i>
                    إنشاء اختبار جديد
                </a>
            </div>
        </div>

        <!-- Quizzes Grid -->
        <?php if (empty($quizzes)): ?>
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body text-center py-16">
                    <i class="fas fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                    <h2 class="text-2xl font-bold mb-2">لم تقم بإنشاء أي اختبارات بعد</h2>
                    <p class="text-gray-600 mb-6">ابدأ بإنشاء أول اختبار لطلابك</p>
                    <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" class="btn btn-primary mx-auto">
                        <i class="fas fa-plus ml-2"></i>
                        إنشاء اختبار جديد
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($quizzes as $quiz): ?>
                    <div class="quiz-card card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <div class="flex justify-between items-start mb-4">
                                <h2 class="card-title text-lg"><?= e($quiz['title']) ?></h2>
                                <div class="badge badge-<?= $quiz['is_active'] ? 'success' : 'ghost' ?>">
                                    <?= $quiz['is_active'] ? 'مفعل' : 'معطل' ?>
                                </div>
                            </div>

                            <p class="text-gray-600 text-sm mb-4"><?= e($quiz['description'] ?: 'بدون وصف') ?></p>

                            <div class="space-y-2 mb-4">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-graduation-cap w-5 text-gray-400"></i>
                                    <span><?= getGradeName($quiz['grade']) ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-book w-5 text-gray-400"></i>
                                    <span><?= e($quiz['subject_name'] ?: 'عام') ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-key w-5 text-gray-400"></i>
                                    <code class="font-bold text-lg"><?= $quiz['pin_code'] ?></code>
                                </div>
                            </div>

                            <div class="stats stats-vertical lg:stats-horizontal shadow mb-4">
                                <div class="stat p-2">
                                    <div class="stat-title text-xs">المحاولات</div>
                                    <div class="stat-value text-xl"><?= $quiz['attempt_count'] ?></div>
                                </div>
                                <div class="stat p-2">
                                    <div class="stat-title text-xs">المتوسط</div>
                                    <div class="stat-value text-xl"><?= round($quiz['avg_score'] ?? 0) ?>%</div>
                                </div>
                            </div>

                            <div class="card-actions justify-end">
                                <a href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $quiz['id'] ?>"
                                    class="btn btn-ghost btn-sm">
                                    <i class="fas fa-chart-bar"></i>
                                </a>
                                <a href="<?= BASE_URL ?>/teacher/quizzes/edit.php?id=<?= $quiz['id'] ?>"
                                    class="btn btn-ghost btn-sm">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $quiz['pin_code'] ?>" target="_blank"
                                    class="btn btn-ghost btn-sm">
                                    <i class="fas fa-play"></i>
                                </a>
                                <button onclick="shareQuiz('<?= $quiz['pin_code'] ?>', '<?= e($quiz['title']) ?>')"
                                    class="btn btn-ghost btn-sm">
                                    <i class="fas fa-share-alt"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function shareQuiz(pin, title) {
            const url = '<?= BASE_URL ?>/quiz/join.php?pin=' + pin;
            const text = `انضم لاختبار "${title}" باستخدام الرمز: ${pin}`;

            if (navigator.share) {
                navigator.share({
                    title: 'مشاركة الاختبار',
                    text: text,
                    url: url
                });
            } else {
                navigator.clipboard.writeText(text + '\n' + url);
                alert('تم نسخ رابط المشاركة!');
            }
        }
    </script>
</body>

</html>