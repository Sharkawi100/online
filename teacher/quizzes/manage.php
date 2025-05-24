<?php
// teacher/quizzes/manage.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Get quiz ID
$quiz_id = (int) ($_GET['id'] ?? 0);
if (!$quiz_id) {
    redirect('/teacher/quizzes/');
}

// Get quiz details
$stmt = $pdo->prepare("
    SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon,
           (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
           (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) as attempt_count
    FROM quizzes q
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE q.id = ? AND q.teacher_id = ?
");
$stmt->execute([$quiz_id, $_SESSION['user_id']]);
$quiz = $stmt->fetch();

if (!$quiz) {
    $_SESSION['error'] = 'الاختبار غير موجود';
    redirect('/teacher/quizzes/');
}

// Get success message if any
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

// Get questions
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(o.id) as option_count,
           SUM(CASE WHEN o.is_correct = 1 THEN 1 ELSE 0 END) as has_correct
    FROM questions q
    LEFT JOIN options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    GROUP BY q.id
    ORDER BY q.order_index
");
$stmt->execute([$quiz_id]);
$questions = $stmt->fetchAll();

// Calculate quiz statistics
$total_points = array_sum(array_column($questions, 'points'));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الاختبار - <?= e($quiz['title']) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none">
            <a href="/teacher/quizzes/" class="btn btn-ghost">
                <i class="fas fa-arrow-right ml-2"></i>
                قائمة الاختبارات
            </a>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <?php if ($success): ?>
            <div class="alert alert-success mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= e($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- Quiz Header -->
        <div class="card bg-base-100 shadow-xl mb-6">
            <div class="card-body">
                <div class="flex justify-between items-start">
                    <div>
                        <h1 class="text-3xl font-bold mb-2"><?= e($quiz['title']) ?></h1>
                        <p class="text-gray-600"><?= e($quiz['description']) ?></p>

                        <div class="flex flex-wrap gap-2 mt-4">
                            <div class="badge badge-lg badge-primary">
                                <i class="<?= $quiz['subject_icon'] ?> ml-1"></i>
                                <?= e($quiz['subject_name']) ?>
                            </div>
                            <div class="badge badge-lg">
                                <?= getGradeName($quiz['grade']) ?>
                            </div>
                            <div class="badge badge-lg">
                                <?= ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب', 'mixed' => 'متنوع'][$quiz['difficulty']] ?>
                            </div>
                            <?php if ($quiz['ai_generated']): ?>
                                <div class="badge badge-lg badge-secondary">
                                    <i class="fas fa-robot ml-1"></i>
                                    مولد بالذكاء الاصطناعي
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-center">
                        <div class="stat">
                            <div class="stat-title">رمز PIN</div>
                            <div class="stat-value text-primary"><?= $quiz['pin_code'] ?></div>
                            <div class="stat-desc">
                                <button onclick="copyPIN('<?= $quiz['pin_code'] ?>')" class="btn btn-xs btn-ghost">
                                    <i class="fas fa-copy"></i>
                                    نسخ
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats shadow mb-6 w-full">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <i class="fas fa-question-circle text-3xl"></i>
                </div>
                <div class="stat-title">عدد الأسئلة</div>
                <div class="stat-value"><?= $quiz['question_count'] ?></div>
                <div class="stat-desc">سؤال</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-secondary">
                    <i class="fas fa-star text-3xl"></i>
                </div>
                <div class="stat-title">مجموع النقاط</div>
                <div class="stat-value"><?= $total_points ?></div>
                <div class="stat-desc">نقطة</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-accent">
                    <i class="fas fa-users text-3xl"></i>
                </div>
                <div class="stat-title">المحاولات</div>
                <div class="stat-value"><?= $quiz['attempt_count'] ?></div>
                <div class="stat-desc">محاولة</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-info">
                    <i class="fas fa-clock text-3xl"></i>
                </div>
                <div class="stat-title">المدة الزمنية</div>
                <div class="stat-value"><?= $quiz['time_limit'] ?: '∞' ?></div>
                <div class="stat-desc"><?= $quiz['time_limit'] ? 'دقيقة' : 'غير محدد' ?></div>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="edit.php?id=<?= $quiz_id ?>" class="btn btn-primary">
                <i class="fas fa-edit ml-2"></i>
                تعديل الاختبار
            </a>
            <a href="../results.php?quiz_id=<?= $quiz_id ?>" class="btn btn-info">
                <i class="fas fa-chart-bar ml-2"></i>
                عرض النتائج
            </a>
            <button onclick="shareQuiz()" class="btn btn-success">
                <i class="fas fa-share-alt ml-2"></i>
                مشاركة
            </button>
            <button onclick="toggleQuiz()" class="btn btn-warning">
                <i class="fas fa-<?= $quiz['is_active'] ? 'pause' : 'play' ?> ml-2"></i>
                <?= $quiz['is_active'] ? 'إيقاف' : 'تفعيل' ?>
            </button>
        </div>

        <!-- Questions Preview -->
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h2 class="card-title mb-4">
                    <i class="fas fa-list-ol text-primary"></i>
                    الأسئلة (<?= count($questions) ?>)
                </h2>

                <?php if (empty($questions)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-exclamation-triangle text-4xl text-warning mb-2"></i>
                        <p>لا توجد أسئلة في هذا الاختبار</p>
                        <a href="edit.php?id=<?= $quiz_id ?>" class="btn btn-primary mt-4">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة أسئلة
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($questions as $index => $question): ?>
                            <div
                                class="border rounded-lg p-4 <?= $question['has_correct'] ? 'border-gray-300' : 'border-error' ?>">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <h3 class="font-bold mb-2">
                                            السؤال <?= $index + 1 ?>:
                                            <span class="font-normal"><?= e($question['question_text']) ?></span>
                                        </h3>
                                        <div class="flex gap-4 text-sm text-gray-600">
                                            <span>
                                                <i class="fas fa-list ml-1"></i>
                                                <?= $question['option_count'] ?> خيار
                                            </span>
                                            <span>
                                                <i class="fas fa-star ml-1"></i>
                                                <?= $question['points'] ?> نقطة
                                            </span>
                                            <?php if ($question['ai_generated']): ?>
                                                <span class="text-primary">
                                                    <i class="fas fa-robot ml-1"></i>
                                                    مولد بالذكاء الاصطناعي
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if (!$question['has_correct']): ?>
                                        <div class="badge badge-error">
                                            <i class="fas fa-exclamation-triangle ml-1"></i>
                                            لا توجد إجابة صحيحة
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function copyPIN(pin) {
            navigator.clipboard.writeText(pin).then(() => {
                alert('تم نسخ رمز PIN: ' + pin);
            });
        }

        function shareQuiz() {
            const url = `<?= BASE_URL ?>/quiz.php?pin=<?= $quiz['pin_code'] ?>`;
            const text = `شارك في اختبار: <?= $quiz['title'] ?>\nرمز PIN: <?= $quiz['pin_code'] ?>`;

            if (navigator.share) {
                navigator.share({
                    title: '<?= $quiz['title'] ?>',
                    text: text,
                    url: url
                });
            } else {
                prompt('انسخ هذا الرابط:', url);
            }
        }

        function toggleQuiz() {
            if (confirm('هل أنت متأكد من <?= $quiz['is_active'] ? 'إيقاف' : 'تفعيل' ?> الاختبار؟')) {
                // Add AJAX call to toggle quiz status
                window.location.href = 'toggle-status.php?id=<?= $quiz_id ?>';
            }
        }
    </script>
</body>

</html>