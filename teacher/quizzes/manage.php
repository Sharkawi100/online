<?php
// teacher/quizzes/manage.php - Professional Quiz Management
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check authentication
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Get quiz ID
$quiz_id = (int) ($_GET['id'] ?? 0);
if (!$quiz_id) {
    redirect('/teacher/quizzes/');
}

// Get quiz details with extended information
$stmt = $pdo->prepare("
    SELECT q.*, 
           s.name_ar as subject_name, 
           s.icon as subject_icon,
           s.color as subject_color,
           (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
           (SELECT SUM(points) FROM questions WHERE quiz_id = q.id) as total_points,
           (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) as total_attempts,
           (SELECT COUNT(DISTINCT user_id) FROM attempts WHERE quiz_id = q.id AND user_id IS NOT NULL) as unique_students,
           (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id AND completed_at IS NULL) as ongoing_attempts,
           (SELECT AVG(score) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as avg_score,
           (SELECT MAX(score) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as max_score,
           (SELECT MIN(score) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as min_score,
           (SELECT AVG(time_taken) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as avg_time
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

// Get success/error messages
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// Get recent attempts
$stmt = $pdo->prepare("
    SELECT a.*, 
           COALESCE(u.name, a.guest_name) as student_name,
           u.email as student_email,
           u.grade as student_grade
    FROM attempts a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.quiz_id = ?
    ORDER BY a.started_at DESC
    LIMIT 10
");
$stmt->execute([$quiz_id]);
$recent_attempts = $stmt->fetchAll();

// Get questions with details
$stmt = $pdo->prepare("
    SELECT q.*, 
           COUNT(DISTINCT o.id) as option_count,
           SUM(CASE WHEN o.is_correct = 1 THEN 1 ELSE 0 END) as correct_options
    FROM questions q
    LEFT JOIN options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    GROUP BY q.id
    ORDER BY q.order_index
");
$stmt->execute([$quiz_id]);
$questions = $stmt->fetchAll();

// Calculate additional statistics
$completion_rate = $quiz['total_attempts'] > 0 ?
    (($quiz['total_attempts'] - $quiz['ongoing_attempts']) / $quiz['total_attempts'] * 100) : 0;

$pass_rate = 0;
if ($quiz['total_attempts'] > 0) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM attempts 
        WHERE quiz_id = ? AND completed_at IS NOT NULL AND score >= 50
    ");
    $stmt->execute([$quiz_id]);
    $passed = $stmt->fetchColumn();
    $pass_rate = ($passed / ($quiz['total_attempts'] - $quiz['ongoing_attempts'])) * 100;
}

// Get quiz text if exists
$quiz_text = null;
if ($quiz['has_text'] && $quiz['text_id']) {
    $stmt = $pdo->prepare("SELECT * FROM quiz_texts WHERE id = ?");
    $stmt->execute([$quiz['text_id']]);
    $quiz_text = $stmt->fetch();
}

// Format average time
$avg_time_formatted = '';
if ($quiz['avg_time']) {
    $minutes = floor($quiz['avg_time'] / 60);
    $seconds = $quiz['avg_time'] % 60;
    $avg_time_formatted = sprintf('%d:%02d', $minutes, $seconds);
}

// Quiz URL for sharing
$quiz_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
    "://$_SERVER[HTTP_HOST]" . BASE_URL . "/quiz.php?pin=" . $quiz['pin_code'];
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
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .gradient-text {
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hover-scale {
            transition: transform 0.2s;
        }

        .hover-scale:hover {
            transform: scale(1.05);
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .subject-color-<?= $quiz['subject_color'] ?? 'blue' ?> {
            background-color: var(--color-<?= $quiz['subject_color'] ?? 'blue' ?>-100);
            color: var(--color-<?= $quiz['subject_color'] ?? 'blue' ?>-800);
        }

        :root {
            --color-blue-100: #dbeafe;
            --color-blue-800: #1e40af;
            --color-green-100: #d1fae5;
            --color-green-800: #065f46;
            --color-purple-100: #e9d5ff;
            --color-purple-800: #6b21a8;
            --color-red-100: #fee2e2;
            --color-red-800: #991b1b;
        }
    </style>
</head>

<body class="bg-gray-50" x-data="quizManager">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg sticky top-0 z-50 glass-effect">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none gap-2">
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle">
                    <i class="fas fa-ellipsis-v"></i>
                </label>
                <ul tabindex="0"
                    class="menu menu-sm dropdown-content mt-3 z-50 p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="/teacher/quizzes/"><i class="fas fa-list ml-2"></i>جميع الاختبارات</a></li>
                    <li><a href="create.php"><i class="fas fa-plus ml-2"></i>اختبار جديد</a></li>
                    <li><a href="ai-generate.php"><i class="fas fa-magic ml-2"></i>توليد بالذكاء</a></li>
                    <li class="divider"></li>
                    <li><a href="/auth/logout.php" class="text-error"><i class="fas fa-sign-out-alt ml-2"></i>تسجيل
                            الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success mb-6 animate__animated animate__fadeInDown">
                <i class="fas fa-check-circle"></i>
                <span><?= e($success) ?></span>
                <button onclick="this.parentElement.remove()" class="btn btn-sm btn-ghost">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6 animate__animated animate__shakeX">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= e($error) ?></span>
                <button onclick="this.parentElement.remove()" class="btn btn-sm btn-ghost">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Quiz Header -->
        <div class="mb-8">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                <div>
                    <h1 class="text-4xl font-bold mb-2 gradient-text"><?= e($quiz['title']) ?></h1>
                    <p class="text-gray-600"><?= e($quiz['description']) ?></p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <div class="badge badge-lg subject-color-<?= $quiz['subject_color'] ?? 'blue' ?>">
                            <i class="<?= $quiz['subject_icon'] ?> ml-1"></i>
                            <?= e($quiz['subject_name']) ?>
                        </div>
                        <div class="badge badge-lg badge-outline">
                            <i class="fas fa-graduation-cap ml-1"></i>
                            <?= getGradeName($quiz['grade']) ?>
                        </div>
                        <div class="badge badge-lg badge-outline">
                            <i class="fas fa-layer-group ml-1"></i>
                            <?= ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب', 'mixed' => 'متنوع'][$quiz['difficulty']] ?>
                        </div>
                        <?php if ($quiz['time_limit'] > 0): ?>
                            <div class="badge badge-lg badge-outline">
                                <i class="fas fa-clock ml-1"></i>
                                <?= $quiz['time_limit'] ?> دقيقة
                            </div>
                        <?php endif; ?>
                        <?php if ($quiz['ai_generated']): ?>
                            <div class="badge badge-lg badge-secondary">
                                <i class="fas fa-robot ml-1"></i>
                                مولد بالذكاء الاصطناعي
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- PIN Display -->
                <div class="card bg-gradient-to-br from-purple-500 to-pink-500 text-white shadow-xl hover-scale">
                    <div class="card-body p-6 text-center">
                        <p class="text-sm opacity-90">رمز الاختبار</p>
                        <p class="text-4xl font-bold tracking-widest"><?= $quiz['pin_code'] ?></p>
                        <div class="card-actions justify-center mt-2">
                            <button onclick="copyPIN('<?= $quiz['pin_code'] ?>')" class="btn btn-sm btn-ghost">
                                <i class="fas fa-copy"></i>
                                نسخ
                            </button>
                            <button onclick="sharePIN()" class="btn btn-sm btn-ghost">
                                <i class="fas fa-share"></i>
                                مشاركة
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <button onclick="window.location.href='edit.php?id=<?= $quiz_id ?>'"
                class="btn btn-outline btn-primary hover-scale">
                <i class="fas fa-edit ml-2"></i>
                تعديل الاختبار
            </button>
            <button onclick="window.location.href='results.php?quiz_id=<?= $quiz_id ?>'"
                class="btn btn-outline btn-info hover-scale">
                <i class="fas fa-chart-bar ml-2"></i>
                تقرير مفصل
            </button>
            <button onclick="toggleQuizStatus()"
                class="btn btn-outline <?= $quiz['is_active'] ? 'btn-warning' : 'btn-success' ?> hover-scale">
                <i class="fas fa-<?= $quiz['is_active'] ? 'pause' : 'play' ?> ml-2"></i>
                <?= $quiz['is_active'] ? 'إيقاف' : 'تفعيل' ?>
            </button>
            <button onclick="duplicateQuiz()" class="btn btn-outline hover-scale">
                <i class="fas fa-copy ml-2"></i>
                نسخ الاختبار
            </button>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Statistics Column -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Performance Stats -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-chart-line text-primary"></i>
                            إحصائيات الأداء
                        </h2>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="stat bg-base-200 rounded-lg">
                                <div class="stat-figure text-primary">
                                    <i class="fas fa-users text-2xl"></i>
                                </div>
                                <div class="stat-title">المشاركون</div>
                                <div class="stat-value"><?= $quiz['total_attempts'] ?></div>
                                <div class="stat-desc"><?= $quiz['unique_students'] ?> طالب</div>
                            </div>

                            <div class="stat bg-base-200 rounded-lg">
                                <div class="stat-figure text-success">
                                    <i class="fas fa-percentage text-2xl"></i>
                                </div>
                                <div class="stat-title">متوسط الدرجة</div>
                                <div class="stat-value"><?= round($quiz['avg_score'] ?? 0, 1) ?>%</div>
                                <div class="stat-desc">من <?= $quiz['total_points'] ?> درجة</div>
                            </div>

                            <div class="stat bg-base-200 rounded-lg">
                                <div class="stat-figure text-info">
                                    <i class="fas fa-clock text-2xl"></i>
                                </div>
                                <div class="stat-title">متوسط الوقت</div>
                                <div class="stat-value"><?= $avg_time_formatted ?: '--:--' ?></div>
                                <div class="stat-desc">دقيقة:ثانية</div>
                            </div>

                            <div class="stat bg-base-200 rounded-lg">
                                <div class="stat-figure text-warning">
                                    <i class="fas fa-check-circle text-2xl"></i>
                                </div>
                                <div class="stat-title">نسبة النجاح</div>
                                <div class="stat-value"><?= round($pass_rate) ?>%</div>
                                <div class="stat-desc">≥ 50%</div>
                            </div>
                        </div>

                        <!-- Score Distribution Chart -->
                        <div class="mt-6">
                            <h3 class="font-bold mb-3">توزيع الدرجات</h3>
                            <canvas id="scoreChart" width="400" height="150"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Attempts -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="card-title">
                                <i class="fas fa-history text-secondary"></i>
                                آخر المحاولات
                            </h2>
                            <a href="results.php?quiz_id=<?= $quiz_id ?>" class="btn btn-sm btn-ghost">
                                عرض الكل
                                <i class="fas fa-arrow-left mr-2"></i>
                            </a>
                        </div>

                        <?php if (empty($recent_attempts)): ?>
                            <div class="text-center py-12 text-gray-500">
                                <i class="fas fa-users-slash text-4xl mb-4"></i>
                                <p>لا توجد محاولات حتى الآن</p>
                                <p class="text-sm mt-2">شارك رمز الاختبار مع الطلاب للبدء</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="table table-zebra">
                                    <thead>
                                        <tr>
                                            <th>الطالب</th>
                                            <th>الدرجة</th>
                                            <th>الوقت</th>
                                            <th>التاريخ</th>
                                            <th>الحالة</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_attempts as $attempt): ?>
                                            <tr class="hover">
                                                <td>
                                                    <div class="flex items-center gap-3">
                                                        <div class="avatar placeholder">
                                                            <div class="bg-neutral text-neutral-content rounded-full w-8">
                                                                <span class="text-xs">
                                                                    <?= mb_substr($attempt['student_name'], 0, 1) ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div>
                                                            <div class="font-bold"><?= e($attempt['student_name']) ?></div>
                                                            <?php if ($attempt['student_grade']): ?>
                                                                <span class="text-sm opacity-50">
                                                                    <?= getGradeName($attempt['student_grade']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if ($attempt['completed_at']): ?>
                                                        <div class="flex items-center gap-2">
                                                            <progress class="progress progress-primary w-20"
                                                                value="<?= $attempt['score'] ?>" max="100"></progress>
                                                            <span class="font-bold"><?= round($attempt['score']) ?>%</span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning">جاري</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($attempt['time_taken']): ?>
                                                        <?= floor($attempt['time_taken'] / 60) ?>:<?= str_pad($attempt['time_taken'] % 60, 2, '0', STR_PAD_LEFT) ?>
                                                    <?php else: ?>
                                                        --:--
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= timeAgo($attempt['started_at']) ?></td>
                                                <td>
                                                    <?php if ($attempt['completed_at']): ?>
                                                        <span class="badge badge-success badge-sm">مكتمل</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-warning badge-sm">جاري</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Info Column -->
            <div class="space-y-6">
                <!-- Quiz Status -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-info-circle text-info"></i>
                            حالة الاختبار
                        </h2>

                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span>الحالة:</span>
                                <span
                                    class="badge <?= $quiz['is_active'] ? 'badge-success' : 'badge-error' ?> badge-lg">
                                    <?= $quiz['is_active'] ? 'نشط' : 'متوقف' ?>
                                </span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span>النوع:</span>
                                <span class="font-bold">
                                    <?= $quiz['is_practice'] ? 'تدريبي' : 'اختبار' ?>
                                </span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span>الأسئلة:</span>
                                <span class="font-bold"><?= $quiz['question_count'] ?> سؤال</span>
                            </div>

                            <div class="flex justify-between items-center">
                                <span>المحاولات الجارية:</span>
                                <span class="font-bold text-warning"><?= $quiz['ongoing_attempts'] ?></span>
                            </div>

                            <?php if ($quiz['shuffle_questions'] || $quiz['shuffle_answers']): ?>
                                <div class="divider"></div>
                                <div class="space-y-2 text-sm">
                                    <?php if ($quiz['shuffle_questions']): ?>
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-random text-primary"></i>
                                            <span>خلط الأسئلة</span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($quiz['shuffle_answers']): ?>
                                        <div class="flex items-center gap-2">
                                            <i class="fas fa-random text-primary"></i>
                                            <span>خلط الإجابات</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Share Options -->
                <div class="card bg-gradient-to-br from-blue-50 to-purple-50 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-share-alt text-primary"></i>
                            مشاركة الاختبار
                        </h2>

                        <div class="space-y-3">
                            <button onclick="shareViaWhatsApp()" class="btn btn-success btn-block">
                                <i class="fab fa-whatsapp ml-2"></i>
                                مشاركة عبر واتساب
                            </button>

                            <button onclick="shareViaEmail()" class="btn btn-primary btn-block">
                                <i class="fas fa-envelope ml-2"></i>
                                مشاركة عبر البريد
                            </button>

                            <button onclick="generateQR()" class="btn btn-outline btn-block">
                                <i class="fas fa-qrcode ml-2"></i>
                                رمز QR
                            </button>

                            <div class="divider">أو</div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text text-xs">رابط الاختبار</span>
                                </label>
                                <div class="input-group input-group-sm">
                                    <input type="text" value="<?= e($quiz_url) ?>"
                                        class="input input-bordered input-sm flex-1" id="quizUrl" readonly>
                                    <button onclick="copyURL()" class="btn btn-sm btn-square">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="stats stats-vertical shadow">
                    <div class="stat">
                        <div class="stat-figure text-secondary">
                            <i class="fas fa-trophy text-3xl"></i>
                        </div>
                        <div class="stat-title">أعلى درجة</div>
                        <div class="stat-value text-success"><?= round($quiz['max_score'] ?? 0) ?>%</div>
                    </div>

                    <div class="stat">
                        <div class="stat-figure text-primary">
                            <i class="fas fa-medal text-3xl"></i>
                        </div>
                        <div class="stat-title">أقل درجة</div>
                        <div class="stat-value text-error"><?= round($quiz['min_score'] ?? 0) ?>%</div>
                    </div>

                    <div class="stat">
                        <div class="stat-figure text-info">
                            <i class="fas fa-percentage text-3xl"></i>
                        </div>
                        <div class="stat-title">نسبة الإكمال</div>
                        <div class="stat-value"><?= round($completion_rate) ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Questions Preview -->
        <div class="card bg-base-100 shadow-xl mt-6">
            <div class="card-body">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="card-title">
                        <i class="fas fa-list-ol text-success"></i>
                        الأسئلة (<?= count($questions) ?>)
                    </h2>
                    <button onclick="toggleQuestions()" class="btn btn-sm btn-ghost">
                        <i class="fas fa-chevron-down" id="questionsToggle"></i>
                    </button>
                </div>

                <div id="questionsContainer" class="hidden">
                    <?php if ($quiz_text): ?>
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-file-alt"></i>
                            <div>
                                <h4 class="font-bold">نص القراءة</h4>
                                <p class="text-sm mt-1"><?= nl2br(e(mb_substr($quiz_text['text_content'], 0, 200))) ?>...
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

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
                        <div class="space-y-3">
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="collapse collapse-arrow bg-base-200">
                                    <input type="checkbox" />
                                    <div class="collapse-title font-medium">
                                        <div class="flex justify-between items-center">
                                            <span>
                                                السؤال <?= $index + 1 ?>:
                                                <?= e(mb_substr($question['question_text'], 0, 80)) ?>...
                                            </span>
                                            <div class="flex gap-2">
                                                <span class="badge badge-sm"><?= $question['points'] ?> نقطة</span>
                                                <?php if ($question['ai_generated']): ?>
                                                    <span class="badge badge-sm badge-primary">AI</span>
                                                <?php endif; ?>
                                                <?php if ($question['correct_options'] == 0): ?>
                                                    <span class="badge badge-sm badge-error">
                                                        <i class="fas fa-exclamation-triangle ml-1"></i>
                                                        لا توجد إجابة
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="collapse-content">
                                        <p class="mb-3"><?= e($question['question_text']) ?></p>
                                        <div class="text-sm text-gray-600">
                                            <span><?= $question['option_count'] ?> خيار</span>
                                            <?php if ($question['question_type'] !== 'multiple_choice'): ?>
                                                • <span>نوع: <?= e($question['question_type']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <dialog id="qrModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg mb-4">رمز QR للاختبار</h3>
            <div id="qrCode" class="flex justify-center mb-4"></div>
            <p class="text-center text-sm text-gray-600">
                امسح الرمز للوصول السريع للاختبار
            </p>
            <div class="modal-action">
                <button onclick="downloadQR()" class="btn btn-primary">
                    <i class="fas fa-download ml-2"></i>
                    تحميل
                </button>
                <form method="dialog">
                    <button class="btn">إغلاق</button>
                </form>
            </div>
        </div>
    </dialog>

    <!-- Delete Confirmation Modal -->
    <dialog id="deleteModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg text-error mb-4">
                <i class="fas fa-exclamation-triangle ml-2"></i>
                تأكيد الحذف
            </h3>
            <p>هل أنت متأكد من حذف هذا الاختبار؟</p>
            <p class="text-sm text-gray-600 mt-2">سيتم حذف جميع الأسئلة والنتائج المرتبطة.</p>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn btn-ghost">إلغاء</button>
                </form>
                <button onclick="confirmDelete()" class="btn btn-error">
                    <i class="fas fa-trash ml-2"></i>
                    حذف نهائياً
                </button>
            </div>
        </div>
    </dialog>

    <script>
        // Alpine.js Component
        document.addEventListener('alpine:init', () => {
            Alpine.data('quizManager', () => ({

            }));
        });

        // Chart.js - Score Distribution
        const ctx = document.getElementById('scoreChart');
        if (ctx) {
            // Get score distribution data via AJAX or PHP
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['0-20%', '21-40%', '41-60%', '61-80%', '81-100%'],
                    datasets: [{
                        label: 'عدد الطلاب',
                        data: [2, 5, 12, 18, 8], // Replace with actual data
                        backgroundColor: [
                            'rgba(239, 68, 68, 0.5)',
                            'rgba(245, 158, 11, 0.5)',
                            'rgba(251, 191, 36, 0.5)',
                            'rgba(59, 130, 246, 0.5)',
                            'rgba(34, 197, 94, 0.5)'
                        ],
                        borderColor: [
                            'rgb(239, 68, 68)',
                            'rgb(245, 158, 11)',
                            'rgb(251, 191, 36)',
                            'rgb(59, 130, 246)',
                            'rgb(34, 197, 94)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        // Functions
        function copyPIN(pin) {
            navigator.clipboard.writeText(pin).then(() => {
                showToast('تم نسخ رمز PIN', 'success');
            });
        }

        function copyURL() {
            const url = document.getElementById('quizUrl');
            url.select();
            document.execCommand('copy');
            showToast('تم نسخ الرابط', 'success');
        }

        function sharePIN() {
            const text = `الاختبار: <?= $quiz['title'] ?>\nرمز PIN: <?= $quiz['pin_code'] ?>\nالرابط: <?= $quiz_url ?>`;

            if (navigator.share) {
                navigator.share({
                    title: '<?= $quiz['title'] ?>',
                    text: text,
                    url: '<?= $quiz_url ?>'
                });
            } else {
                copyToClipboard(text);
                showToast('تم نسخ معلومات المشاركة', 'info');
            }
        }

        function shareViaWhatsApp() {
            const text = `🎯 *الاختبار: <?= $quiz['title'] ?>*\n\n` +
                `📍 رمز الدخول: *<?= $quiz['pin_code'] ?>*\n` +
                `🔗 الرابط: <?= $quiz_url ?>\n\n` +
                `⏰ المدة: <?= $quiz['time_limit'] ?: 'غير محدد' ?> دقيقة\n` +
                `📚 المادة: <?= $quiz['subject_name'] ?>\n` +
                `🎓 الصف: <?= getGradeName($quiz['grade']) ?>`;

            window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
        }

        function shareViaEmail() {
            const subject = 'دعوة لأداء اختبار: <?= $quiz['title'] ?>';
            const body = `مرحباً،\n\n` +
                `أدعوك لأداء الاختبار التالي:\n\n` +
                `الاختبار: <?= $quiz['title'] ?>\n` +
                `المادة: <?= $quiz['subject_name'] ?>\n` +
                `الصف: <?= getGradeName($quiz['grade']) ?>\n` +
                `رمز الدخول: <?= $quiz['pin_code'] ?>\n\n` +
                `يمكنك الدخول من خلال الرابط:\n<?= $quiz_url ?>\n\n` +
                `بالتوفيق!`;

            window.location.href = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
        }

        function generateQR() {
            // Using a simple QR code API
            const qrContainer = document.getElementById('qrCode');
            qrContainer.innerHTML = `<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent('<?= $quiz_url ?>')}" alt="QR Code">`;
            document.getElementById('qrModal').showModal();
        }

        function downloadQR() {
            const link = document.createElement('a');
            link.href = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent('<?= $quiz_url ?>')}`;
            link.download = 'quiz_<?= $quiz['pin_code'] ?>_qr.png';
            link.click();
        }

        function toggleQuizStatus() {
            if (confirm('هل أنت متأكد من <?= $quiz['is_active'] ? 'إيقاف' : 'تفعيل' ?> الاختبار؟')) {
                window.location.href = 'toggle-status.php?id=<?= $quiz_id ?>&csrf=<?= $csrf_token ?>';
            }
        }

        function duplicateQuiz() {
            if (confirm('هل تريد إنشاء نسخة من هذا الاختبار؟')) {
                window.location.href = 'duplicate.php?id=<?= $quiz_id ?>&csrf=<?= $csrf_token ?>';
            }
        }

        function deleteQuiz() {
            document.getElementById('deleteModal').showModal();
        }

        function confirmDelete() {
            window.location.href = 'delete.php?id=<?= $quiz_id ?>&csrf=<?= $csrf_token ?>';
        }

        function toggleQuestions() {
            const container = document.getElementById('questionsContainer');
            const icon = document.getElementById('questionsToggle');

            if (container.classList.contains('hidden')) {
                container.classList.remove('hidden');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            } else {
                container.classList.add('hidden');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
            }
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} fixed top-4 right-4 z-50 animate__animated animate__fadeInRight`;
            toast.innerHTML = `
            <div>
                <span>${message}</span>
            </div>
        `;
            document.body.appendChild(toast);

            setTimeout(() => {
                toast.classList.remove('animate__fadeInRight');
                toast.classList.add('animate__fadeOutRight');
                setTimeout(() => toast.remove(), 1000);
            }, 3000);
        }

        function copyToClipboard(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }

        // Auto-refresh ongoing attempts count
        setInterval(() => {
            // Could make an AJAX call to update ongoing attempts
        }, 30000); // Every 30 seconds
    </script>
</body>

</html>