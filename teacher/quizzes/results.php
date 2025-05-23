<?php
// /teacher/quizzes/results.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$quiz_id = $_GET['quiz_id'] ?? null;
$teacher_id = $_SESSION['user_id'];

if ($quiz_id) {
    // Verify quiz belongs to teacher
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$quiz_id, $teacher_id]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        redirect('/teacher/quizzes/results.php');
    }

    // Get detailed results for specific quiz
    $stmt = $pdo->prepare("
        SELECT a.*, 
               COALESCE(u.name, a.guest_name) as participant_name,
               u.email,
               u.role,
               COUNT(DISTINCT ans.question_id) as questions_answered,
               SUM(CASE WHEN ans.is_correct = 1 THEN 1 ELSE 0 END) as correct_answers
        FROM attempts a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN answers ans ON a.id = ans.attempt_id
        WHERE a.quiz_id = ? AND a.completed_at IS NOT NULL
        GROUP BY a.id
        ORDER BY a.completed_at DESC
    ");
    $stmt->execute([$quiz_id]);
    $attempts = $stmt->fetchAll();

    // Calculate statistics
    $total_attempts = count($attempts);
    $avg_score = $total_attempts > 0 ? array_sum(array_column($attempts, 'score')) / $total_attempts : 0;
    $highest_score = $total_attempts > 0 ? max(array_column($attempts, 'score')) : 0;
    $lowest_score = $total_attempts > 0 ? min(array_column($attempts, 'score')) : 0;
    $avg_time = $total_attempts > 0 ? array_sum(array_column($attempts, 'time_taken')) / $total_attempts : 0;

    // Get question-wise performance
    $stmt = $pdo->prepare("
        SELECT q.question_text, q.order_index,
               COUNT(DISTINCT a.id) as total_attempts,
               SUM(CASE WHEN ans.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
               AVG(CASE WHEN ans.is_correct = 1 THEN 100 ELSE 0 END) as success_rate
        FROM questions q
        LEFT JOIN answers ans ON q.id = ans.question_id
        LEFT JOIN attempts a ON ans.attempt_id = a.id AND a.completed_at IS NOT NULL
        WHERE q.quiz_id = ?
        GROUP BY q.id
        ORDER BY q.order_index
    ");
    $stmt->execute([$quiz_id]);
    $question_stats = $stmt->fetchAll();

    // Get total questions count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
    $stmt->execute([$quiz_id]);
    $total_questions = $stmt->fetchColumn();

} else {
    // Get all quizzes for teacher
    $stmt = $pdo->prepare("
        SELECT q.*, 
               COUNT(DISTINCT a.id) as attempt_count,
               AVG(a.score) as avg_score,
               MAX(a.completed_at) as last_attempt
        FROM quizzes q
        LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
        WHERE q.teacher_id = ?
        GROUP BY q.id
        ORDER BY q.created_at DESC
    ");
    $stmt->execute([$teacher_id]);
    $quizzes = $stmt->fetchAll();

    // Get overall statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT q.id) as total_quizzes,
            COUNT(DISTINCT a.id) as total_attempts,
            COUNT(DISTINCT CASE WHEN a.user_id IS NOT NULL THEN a.user_id END) as unique_students,
            AVG(a.score) as overall_avg_score
        FROM quizzes q
        LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
        WHERE q.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $overall_stats = $stmt->fetch();
}

// Handle export request
if (isset($_GET['export']) && $quiz_id) {
    require_once '../../includes/quiz-import-export.php';

    $format = $_GET['export'];
    if ($format === 'csv') {
        // Export results to CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="results_' . $quiz['title'] . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

        // Headers
        fputcsv($output, ['اسم المشارك', 'البريد الإلكتروني', 'النتيجة (%)', 'الإجابات الصحيحة', 'الوقت المستغرق', 'تاريخ المحاولة']);

        // Data
        foreach ($attempts as $attempt) {
            fputcsv($output, [
                $attempt['participant_name'],
                $attempt['email'] ?? 'ضيف',
                $attempt['score'],
                $attempt['correct_answers'] . '/' . $total_questions,
                gmdate("i:s", $attempt['time_taken']),
                date('Y-m-d H:i', strtotime($attempt['completed_at']))
            ]);
        }

        fclose($output);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتائج الاختبارات - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .stat-card {
            transition: transform 0.2s;
        }

        .stat-card:hover {
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
        <?php if ($quiz_id): ?>
            <!-- Specific Quiz Results -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold mb-2">نتائج الاختبار: <?= e($quiz['title']) ?></h1>
                <div class="breadcrumbs text-sm">
                    <ul>
                        <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                        <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                        <li><a href="<?= BASE_URL ?>/teacher/quizzes/results.php">النتائج</a></li>
                        <li><?= e($quiz['title']) ?></li>
                    </ul>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                <div class="stat-card card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure text-primary">
                                <i class="fas fa-users text-3xl"></i>
                            </div>
                            <div class="stat-title">إجمالي المحاولات</div>
                            <div class="stat-value text-primary"><?= $total_attempts ?></div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure text-success">
                                <i class="fas fa-percentage text-3xl"></i>
                            </div>
                            <div class="stat-title">متوسط النتيجة</div>
                            <div class="stat-value text-success"><?= round($avg_score, 1) ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure text-info">
                                <i class="fas fa-trophy text-3xl"></i>
                            </div>
                            <div class="stat-title">أعلى نتيجة</div>
                            <div class="stat-value text-info"><?= round($highest_score, 1) ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure text-warning">
                                <i class="fas fa-arrow-down text-3xl"></i>
                            </div>
                            <div class="stat-title">أقل نتيجة</div>
                            <div class="stat-value text-warning"><?= round($lowest_score, 1) ?>%</div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-base-100 shadow">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure text-secondary">
                                <i class="fas fa-clock text-3xl"></i>
                            </div>
                            <div class="stat-title">متوسط الوقت</div>
                            <div class="stat-value text-secondary"><?= gmdate("i:s", $avg_time) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Score Distribution Chart -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-chart-bar text-primary"></i>
                            توزيع النتائج
                        </h2>
                        <div style="position: relative; height: 300px;">
                            <canvas id="scoreChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Question Performance -->
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-question-circle text-primary"></i>
                            أداء الأسئلة
                        </h2>
                        <div style="position: relative; height: 300px;">
                            <canvas id="questionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attempts Table -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-list text-primary"></i>
                            تفاصيل المحاولات
                        </h2>
                        <div class="flex gap-2">
                            <a href="?quiz_id=<?= $quiz_id ?>&export=csv" class="btn btn-sm btn-outline">
                                <i class="fas fa-download ml-2"></i>
                                تصدير CSV
                            </a>
                            <a href="<?= BASE_URL ?>/teacher/quizzes/edit.php?id=<?= $quiz_id ?>"
                                class="btn btn-sm btn-primary">
                                <i class="fas fa-edit ml-2"></i>
                                تعديل الاختبار
                            </a>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>اسم المشارك</th>
                                    <th>النوع</th>
                                    <th>النتيجة</th>
                                    <th>الإجابات الصحيحة</th>
                                    <th>الوقت المستغرق</th>
                                    <th>التاريخ</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attempts as $index => $attempt): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <div class="avatar placeholder">
                                                    <div class="bg-neutral-focus text-neutral-content rounded-full w-8">
                                                        <span
                                                            class="text-xs"><?= mb_substr($attempt['participant_name'], 0, 1) ?></span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="font-bold"><?= e($attempt['participant_name']) ?></div>
                                                    <?php if ($attempt['email']): ?>
                                                        <div class="text-sm opacity-50"><?= e($attempt['email']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($attempt['user_id']): ?>
                                                <span class="badge badge-primary badge-sm">
                                                    <?= $attempt['role'] == 'student' ? 'طالب' : 'معلم' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost badge-sm">ضيف</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <progress
                                                    class="progress progress-<?= $attempt['score'] >= 80 ? 'success' : ($attempt['score'] >= 60 ? 'warning' : 'error') ?> w-16"
                                                    value="<?= $attempt['score'] ?>" max="100"></progress>
                                                <span class="font-bold"><?= round($attempt['score'], 1) ?>%</span>
                                            </div>
                                        </td>
                                        <td><?= $attempt['correct_answers'] ?>/<?= $total_questions ?></td>
                                        <td><?= gmdate("i:s", $attempt['time_taken']) ?></td>
                                        <td><?= timeAgo($attempt['completed_at']) ?></td>
                                        <td>
                                            <button class="btn btn-ghost btn-xs" onclick="viewDetails(<?= $attempt['id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (empty($attempts)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-4"></i>
                                <p>لا توجد محاولات لهذا الاختبار بعد</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- All Quizzes Overview -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold mb-2">نتائج جميع الاختبارات</h1>
                <div class="breadcrumbs text-sm">
                    <ul>
                        <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                        <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                        <li>النتائج</li>
                    </ul>
                </div>
            </div>

            <!-- Overall Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
                <div class="stat-card card bg-primary text-primary-content">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure">
                                <i class="fas fa-clipboard-list text-3xl opacity-70"></i>
                            </div>
                            <div class="stat-title text-primary-content">إجمالي الاختبارات</div>
                            <div class="stat-value"><?= $overall_stats['total_quizzes'] ?></div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-secondary text-secondary-content">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure">
                                <i class="fas fa-pencil-alt text-3xl opacity-70"></i>
                            </div>
                            <div class="stat-title text-secondary-content">إجمالي المحاولات</div>
                            <div class="stat-value"><?= $overall_stats['total_attempts'] ?></div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-accent text-accent-content">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure">
                                <i class="fas fa-user-graduate text-3xl opacity-70"></i>
                            </div>
                            <div class="stat-title text-accent-content">الطلاب المشاركون</div>
                            <div class="stat-value"><?= $overall_stats['unique_students'] ?></div>
                        </div>
                    </div>
                </div>

                <div class="stat-card card bg-info text-info-content">
                    <div class="card-body p-4">
                        <div class="stat">
                            <div class="stat-figure">
                                <i class="fas fa-percentage text-3xl opacity-70"></i>
                            </div>
                            <div class="stat-title text-info-content">متوسط النتائج</div>
                            <div class="stat-value"><?= round($overall_stats['overall_avg_score'] ?? 0, 1) ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quizzes Table -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-list text-primary"></i>
                        جميع الاختبارات
                    </h2>

                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>عنوان الاختبار</th>
                                    <th>الرمز</th>
                                    <th>الصف</th>
                                    <th>المحاولات</th>
                                    <th>متوسط النتيجة</th>
                                    <th>آخر محاولة</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quizzes as $quiz): ?>
                                    <tr>
                                        <td>
                                            <div class="font-bold"><?= e($quiz['title']) ?></div>
                                            <div class="text-sm opacity-50"><?= e($quiz['description']) ?></div>
                                        </td>
                                        <td>
                                            <code class="text-lg font-bold"><?= $quiz['pin_code'] ?></code>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= getGradeColor($quiz['grade']) ?>">
                                                <?= getGradeName($quiz['grade']) ?>
                                            </span>
                                        </td>
                                        <td><?= $quiz['attempt_count'] ?></td>
                                        <td>
                                            <?php if ($quiz['avg_score']): ?>
                                                <div class="flex items-center gap-2">
                                                    <progress
                                                        class="progress progress-<?= $quiz['avg_score'] >= 80 ? 'success' : ($quiz['avg_score'] >= 60 ? 'warning' : 'error') ?> w-16"
                                                        value="<?= $quiz['avg_score'] ?>" max="100"></progress>
                                                    <span><?= round($quiz['avg_score'], 1) ?>%</span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $quiz['last_attempt'] ? timeAgo($quiz['last_attempt']) : 'لا يوجد' ?>
                                        </td>
                                        <td>
                                            <?php if ($quiz['is_active']): ?>
                                                <span class="badge badge-success badge-sm">مفعل</span>
                                            <?php else: ?>
                                                <span class="badge badge-ghost badge-sm">معطل</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="flex gap-1">
                                                <a href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $quiz['id'] ?>"
                                                    class="btn btn-ghost btn-xs">
                                                    <i class="fas fa-chart-bar"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>/teacher/quizzes/edit.php?id=<?= $quiz['id'] ?>"
                                                    class="btn btn-ghost btn-xs">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $quiz['pin_code'] ?>"
                                                    target="_blank" class="btn btn-ghost btn-xs">
                                                    <i class="fas fa-external-link-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (empty($quizzes)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-clipboard-list text-4xl mb-4"></i>
                                <p>لم تقم بإنشاء أي اختبارات بعد</p>
                                <a href="<?= BASE_URL ?>/teacher/quizzes/create.php" class="btn btn-primary mt-4">
                                    <i class="fas fa-plus ml-2"></i>
                                    إنشاء أول اختبار
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($quiz_id): ?>
        <script>
            // Score Distribution Chart
            const scoreCtx = document.getElementById('scoreChart').getContext('2d');
            const scoreData = {
                labels: ['0-20%', '21-40%', '41-60%', '61-80%', '81-100%'],
                datasets: [{
                    label: 'عدد المحاولات',
                    data: [
                        <?= count(array_filter($attempts, fn($a) => $a['score'] <= 20)) ?>,
                        <?= count(array_filter($attempts, fn($a) => $a['score'] > 20 && $a['score'] <= 40)) ?>,
                        <?= count(array_filter($attempts, fn($a) => $a['score'] > 40 && $a['score'] <= 60)) ?>,
                        <?= count(array_filter($attempts, fn($a) => $a['score'] > 60 && $a['score'] <= 80)) ?>,
                        <?= count(array_filter($attempts, fn($a) => $a['score'] > 80)) ?>
                    ],
                    backgroundColor: ['#ef4444', '#f59e0b', '#eab308', '#3b82f6', '#10b981'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            };

            new Chart(scoreCtx, {
                type: 'bar',
                data: scoreData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });

            // Question Performance Chart
            const questionCtx = document.getElementById('questionChart').getContext('2d');
            const questionData = {
                labels: [<?= implode(',', array_map(fn($i) => "'س" . ($i + 1) . "'", range(0, count($question_stats) - 1))) ?>],
                datasets: [{
                    label: 'نسبة النجاح',
                    data: [<?= implode(',', array_column($question_stats, 'success_rate')) ?>],
                    backgroundColor: 'rgba(59, 130, 246, 0.2)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 2,
                    tension: 0.3
                }]
            };

            new Chart(questionCtx, {
                type: 'line',
                data: questionData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function (value) {
                                    return value + '%';
                                }
                            }
                        }
                    }
                }
            });

            function viewDetails(attemptId) {
                // This could open a modal with detailed answers
                window.open('<?= BASE_URL ?>/teacher/quizzes/attempt-details.php?id=' + attemptId, '_blank');
            }
        </script>
    <?php endif; ?>
</body>

</html>