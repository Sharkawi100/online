<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

// Get date range
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Today
$reportType = $_GET['report_type'] ?? 'overview';

// Validate dates
if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// Get overview stats
$overviewStats = [];
if ($reportType === 'overview' || $reportType === 'all') {
    // Total users by role
    $stmt = $pdo->prepare("
        SELECT role, COUNT(*) as count 
        FROM users 
        WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY role
    ");
    $stmt->execute([$startDate, $endDate]);
    $overviewStats['new_users'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Total attempts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total, 
               COUNT(DISTINCT user_id) as unique_users,
               COUNT(DISTINCT quiz_id) as unique_quizzes,
               AVG(score) as avg_score
        FROM attempts 
        WHERE completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
    ");
    $stmt->execute([$startDate, $endDate]);
    $overviewStats['attempts'] = $stmt->fetch();

    // Activity by day
    $stmt = $pdo->prepare("
        SELECT DATE(completed_at) as date, COUNT(*) as count
        FROM attempts
        WHERE completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(completed_at)
        ORDER BY date
    ");
    $stmt->execute([$startDate, $endDate]);
    $overviewStats['daily_activity'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Get quiz performance
$quizPerformance = [];
if ($reportType === 'quizzes' || $reportType === 'all') {
    $stmt = $pdo->prepare("
        SELECT q.id, q.title, q.grade, s.name_ar as subject_name,
               COUNT(DISTINCT a.id) as attempt_count,
               COUNT(DISTINCT a.user_id) as unique_users,
               AVG(a.score) as avg_score,
               MIN(a.score) as min_score,
               MAX(a.score) as max_score,
               AVG(a.time_taken) as avg_time
        FROM quizzes q
        LEFT JOIN subjects s ON q.subject_id = s.id
        LEFT JOIN attempts a ON q.id = a.quiz_id 
            AND a.completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY q.id
        HAVING attempt_count > 0
        ORDER BY attempt_count DESC
        LIMIT 20
    ");
    $stmt->execute([$startDate, $endDate]);
    $quizPerformance = $stmt->fetchAll();
}

// Get teacher activity
$teacherActivity = [];
if ($reportType === 'teachers' || $reportType === 'all') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email,
               COUNT(DISTINCT q.id) as quiz_count,
               COUNT(DISTINCT a.id) as attempt_count,
               COUNT(DISTINCT a.user_id) as student_count,
               AVG(a.score) as avg_score
        FROM users u
        LEFT JOIN quizzes q ON u.id = q.teacher_id
        LEFT JOIN attempts a ON q.id = a.quiz_id 
            AND a.completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        WHERE u.role = 'teacher'
        GROUP BY u.id
        ORDER BY attempt_count DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $teacherActivity = $stmt->fetchAll();
}

// Get student performance
$studentPerformance = [];
if ($reportType === 'students' || $reportType === 'all') {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.grade, u.total_points,
               COUNT(DISTINCT a.id) as attempt_count,
               COUNT(DISTINCT a.quiz_id) as quiz_count,
               AVG(a.score) as avg_score,
               SUM(a.total_points) as period_points
        FROM users u
        LEFT JOIN attempts a ON u.id = a.user_id 
            AND a.completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        WHERE u.role = 'student' AND a.id IS NOT NULL
        GROUP BY u.id
        ORDER BY period_points DESC
        LIMIT 50
    ");
    $stmt->execute([$startDate, $endDate]);
    $studentPerformance = $stmt->fetchAll();
}

// Get subject statistics
$subjectStats = [];
if ($reportType === 'subjects' || $reportType === 'all') {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name_ar, s.icon, s.color,
               COUNT(DISTINCT q.id) as quiz_count,
               COUNT(DISTINCT a.id) as attempt_count,
               COUNT(DISTINCT a.user_id) as student_count,
               AVG(a.score) as avg_score
        FROM subjects s
        LEFT JOIN quizzes q ON s.id = q.subject_id
        LEFT JOIN attempts a ON q.id = a.quiz_id 
            AND a.completed_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY s.id
        ORDER BY attempt_count DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $subjectStats = $stmt->fetchAll();
}

// Prepare chart data
$chartLabels = [];
$chartData = [];
if (!empty($overviewStats['daily_activity'])) {
    $currentDate = strtotime($startDate);
    $endTimestamp = strtotime($endDate);

    while ($currentDate <= $endTimestamp) {
        $dateStr = date('Y-m-d', $currentDate);
        $chartLabels[] = date('d/m', $currentDate);
        $chartData[] = $overviewStats['daily_activity'][$dateStr] ?? 0;
        $currentDate = strtotime('+1 day', $currentDate);
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>التقارير والإحصائيات - <?= e(getSetting('site_name')) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .chart-container {
            position: relative;
            height: 300px;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: window.innerWidth > 768 }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
            class="bg-gray-800 text-white transition-all duration-300 no-print">
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
                    <a href="achievements.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-trophy w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإنجازات</span>
                    </a>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
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
            <header class="bg-white shadow-sm border-b no-print">
                <div class="px-6 py-4 flex justify-between items-center">
                    <h1 class="text-2xl font-bold text-gray-800">التقارير والإحصائيات</h1>
                    <div class="flex gap-2">
                        <button onclick="window.print()" class="btn btn-ghost">
                            <i class="fas fa-print ml-2"></i>
                            طباعة
                        </button>
                        <button onclick="exportToExcel()" class="btn btn-success">
                            <i class="fas fa-file-excel ml-2"></i>
                            تصدير Excel
                        </button>
                    </div>
                </div>
            </header>

            <div class="p-6">
                <!-- Filters -->
                <div class="card bg-white shadow-xl mb-6 no-print">
                    <div class="card-body">
                        <form method="GET" class="flex flex-wrap gap-4">
                            <div class="form-control">
                                <label class="label">من تاريخ</label>
                                <input type="date" name="start_date" value="<?= $startDate ?>"
                                    class="input input-bordered">
                            </div>
                            <div class="form-control">
                                <label class="label">إلى تاريخ</label>
                                <input type="date" name="end_date" value="<?= $endDate ?>" class="input input-bordered">
                            </div>
                            <div class="form-control">
                                <label class="label">نوع التقرير</label>
                                <select name="report_type" class="select select-bordered">
                                    <option value="overview" <?= $reportType === 'overview' ? 'selected' : '' ?>>نظرة عامة
                                    </option>
                                    <option value="quizzes" <?= $reportType === 'quizzes' ? 'selected' : '' ?>>أداء
                                        الاختبارات</option>
                                    <option value="teachers" <?= $reportType === 'teachers' ? 'selected' : '' ?>>نشاط
                                        المعلمين</option>
                                    <option value="students" <?= $reportType === 'students' ? 'selected' : '' ?>>أداء
                                        الطلاب</option>
                                    <option value="subjects" <?= $reportType === 'subjects' ? 'selected' : '' ?>>إحصائيات
                                        المواد</option>
                                    <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>تقرير شامل</option>
                                </select>
                            </div>
                            <div class="form-control flex items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter ml-2"></i>
                                    تطبيق
                                </button>
                            </div>
                        </form>

                        <!-- Quick Date Ranges -->
                        <div class="flex gap-2 mt-4">
                            <a href="?start_date=<?= date('Y-m-d') ?>&end_date=<?= date('Y-m-d') ?>&report_type=<?= $reportType ?>"
                                class="btn btn-sm btn-outline">اليوم</a>
                            <a href="?start_date=<?= date('Y-m-d', strtotime('-7 days')) ?>&end_date=<?= date('Y-m-d') ?>&report_type=<?= $reportType ?>"
                                class="btn btn-sm btn-outline">آخر 7 أيام</a>
                            <a href="?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-d') ?>&report_type=<?= $reportType ?>"
                                class="btn btn-sm btn-outline">هذا الشهر</a>
                            <a href="?start_date=<?= date('Y-m-01', strtotime('-1 month')) ?>&end_date=<?= date('Y-m-t', strtotime('-1 month')) ?>&report_type=<?= $reportType ?>"
                                class="btn btn-sm btn-outline">الشهر الماضي</a>
                        </div>
                    </div>
                </div>

                <!-- Report Header -->
                <div class="text-center mb-6 print:block hidden">
                    <h2 class="text-2xl font-bold"><?= e(getSetting('site_name')) ?></h2>
                    <p class="text-gray-600">تقرير الفترة من <?= $startDate ?> إلى <?= $endDate ?></p>
                </div>

                <!-- Overview Report -->
                <?php if ($reportType === 'overview' || $reportType === 'all'): ?>
                    <div class="mb-8">
                        <h3 class="text-xl font-bold mb-4">نظرة عامة</h3>

                        <!-- Summary Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                            <div class="stat bg-white rounded-lg shadow">
                                <div class="stat-title">إجمالي المحاولات</div>
                                <div class="stat-value text-primary">
                                    <?= number_format($overviewStats['attempts']['total'] ?? 0) ?>
                                </div>
                            </div>
                            <div class="stat bg-white rounded-lg shadow">
                                <div class="stat-title">الطلاب النشطون</div>
                                <div class="stat-value text-green-600">
                                    <?= number_format($overviewStats['attempts']['unique_users'] ?? 0) ?>
                                </div>
                            </div>
                            <div class="stat bg-white rounded-lg shadow">
                                <div class="stat-title">الاختبارات المستخدمة</div>
                                <div class="stat-value text-blue-600">
                                    <?= number_format($overviewStats['attempts']['unique_quizzes'] ?? 0) ?>
                                </div>
                            </div>
                            <div class="stat bg-white rounded-lg shadow">
                                <div class="stat-title">متوسط النتائج</div>
                                <div class="stat-value text-purple-600">
                                    <?= round($overviewStats['attempts']['avg_score'] ?? 0) ?>%
                                </div>
                            </div>
                        </div>

                        <!-- Activity Chart -->
                        <?php if (!empty($chartData)): ?>
                            <div class="card bg-white shadow-xl">
                                <div class="card-body">
                                    <h4 class="font-bold mb-4">النشاط اليومي</h4>
                                    <div class="chart-container">
                                        <canvas id="activityChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Quiz Performance Report -->
                <?php if (($reportType === 'quizzes' || $reportType === 'all') && !empty($quizPerformance)): ?>
                    <div class="mb-8 page-break">
                        <h3 class="text-xl font-bold mb-4">أداء الاختبارات</h3>
                        <div class="card bg-white shadow-xl">
                            <div class="card-body">
                                <div class="overflow-x-auto">
                                    <table class="table table-zebra">
                                        <thead>
                                            <tr>
                                                <th>الاختبار</th>
                                                <th>المادة</th>
                                                <th>الصف</th>
                                                <th>المحاولات</th>
                                                <th>الطلاب</th>
                                                <th>متوسط النتيجة</th>
                                                <th>أعلى/أقل</th>
                                                <th>متوسط الوقت</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($quizPerformance as $quiz): ?>
                                                <tr>
                                                    <td class="font-medium"><?= e($quiz['title']) ?></td>
                                                    <td><?= e($quiz['subject_name'] ?? 'عام') ?></td>
                                                    <td><?= getGradeName($quiz['grade']) ?></td>
                                                    <td><?= number_format($quiz['attempt_count']) ?></td>
                                                    <td><?= number_format($quiz['unique_users']) ?></td>
                                                    <td>
                                                        <span
                                                            class="badge <?= $quiz['avg_score'] >= 80 ? 'badge-success' : ($quiz['avg_score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                                            <?= round($quiz['avg_score']) ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-sm">
                                                        <span class="text-green-600"><?= round($quiz['max_score']) ?>%</span> /
                                                        <span class="text-red-600"><?= round($quiz['min_score']) ?>%</span>
                                                    </td>
                                                    <td><?= gmdate("i:s", $quiz['avg_time']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Teacher Activity Report -->
                <?php if (($reportType === 'teachers' || $reportType === 'all') && !empty($teacherActivity)): ?>
                    <div class="mb-8 page-break">
                        <h3 class="text-xl font-bold mb-4">نشاط المعلمين</h3>
                        <div class="card bg-white shadow-xl">
                            <div class="card-body">
                                <div class="overflow-x-auto">
                                    <table class="table table-zebra">
                                        <thead>
                                            <tr>
                                                <th>المعلم</th>
                                                <th>البريد الإلكتروني</th>
                                                <th>الاختبارات</th>
                                                <th>المحاولات</th>
                                                <th>الطلاب</th>
                                                <th>متوسط النتائج</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($teacherActivity as $teacher): ?>
                                                <tr>
                                                    <td class="font-medium"><?= e($teacher['name']) ?></td>
                                                    <td><?= e($teacher['email']) ?></td>
                                                    <td><?= number_format($teacher['quiz_count']) ?></td>
                                                    <td><?= number_format($teacher['attempt_count']) ?></td>
                                                    <td><?= number_format($teacher['student_count']) ?></td>
                                                    <td>
                                                        <?php if ($teacher['avg_score']): ?>
                                                            <span
                                                                class="badge badge-primary"><?= round($teacher['avg_score']) ?>%</span>
                                                        <?php else: ?>
                                                            <span class="text-gray-400">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Student Performance Report -->
                <?php if (($reportType === 'students' || $reportType === 'all') && !empty($studentPerformance)): ?>
                    <div class="mb-8 page-break">
                        <h3 class="text-xl font-bold mb-4">أداء الطلاب المتميزين</h3>
                        <div class="card bg-white shadow-xl">
                            <div class="card-body">
                                <div class="overflow-x-auto">
                                    <table class="table table-zebra">
                                        <thead>
                                            <tr>
                                                <th>الترتيب</th>
                                                <th>الطالب</th>
                                                <th>الصف</th>
                                                <th>المحاولات</th>
                                                <th>الاختبارات</th>
                                                <th>متوسط النتيجة</th>
                                                <th>النقاط المكتسبة</th>
                                                <th>إجمالي النقاط</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($studentPerformance as $index => $student): ?>
                                                <tr>
                                                    <td>
                                                        <div class="badge <?= $index < 3 ? 'badge-primary' : '' ?>">
                                                            <?= $index + 1 ?>
                                                        </div>
                                                    </td>
                                                    <td class="font-medium"><?= e($student['name']) ?></td>
                                                    <td><?= getGradeName($student['grade']) ?></td>
                                                    <td><?= number_format($student['attempt_count']) ?></td>
                                                    <td><?= number_format($student['quiz_count']) ?></td>
                                                    <td>
                                                        <span
                                                            class="badge <?= $student['avg_score'] >= 80 ? 'badge-success' : ($student['avg_score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                                            <?= round($student['avg_score']) ?>%
                                                        </span>
                                                    </td>
                                                    <td class="text-green-600 font-bold">
                                                        +<?= number_format($student['period_points']) ?></td>
                                                    <td class="font-bold"><?= number_format($student['total_points']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Subject Statistics -->
                <?php if (($reportType === 'subjects' || $reportType === 'all') && !empty($subjectStats)): ?>
                    <div class="mb-8">
                        <h3 class="text-xl font-bold mb-4">إحصائيات المواد الدراسية</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($subjectStats as $subject): ?>
                                <div class="card bg-white shadow">
                                    <div class="card-body">
                                        <div class="flex items-center justify-between mb-4">
                                            <h4 class="font-bold"><?= e($subject['name_ar']) ?></h4>
                                            <i class="<?= $subject['icon'] ?> text-<?= $subject['color'] ?>-500 text-2xl"></i>
                                        </div>
                                        <div class="stats stats-vertical shadow">
                                            <div class="stat">
                                                <div class="stat-title">الاختبارات</div>
                                                <div class="stat-value text-2xl"><?= number_format($subject['quiz_count']) ?>
                                                </div>
                                            </div>
                                            <div class="stat">
                                                <div class="stat-title">المحاولات</div>
                                                <div class="stat-value text-2xl"><?= number_format($subject['attempt_count']) ?>
                                                </div>
                                            </div>
                                            <div class="stat">
                                                <div class="stat-title">متوسط النتائج</div>
                                                <div class="stat-value text-2xl"><?= round($subject['avg_score'] ?? 0) ?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Activity Chart
        <?php if (!empty($chartData)): ?>
            const activityCtx = document.getElementById('activityChart').getContext('2d');
            new Chart(activityCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chartLabels) ?>,
                    datasets: [{
                        label: 'المحاولات',
                        data: <?= json_encode($chartData) ?>,
                        borderColor: 'rgb(99, 102, 241)',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        <?php endif; ?>

        // Export to Excel (simplified)
        function exportToExcel() {
            // In a real implementation, you would generate actual Excel file
            alert('سيتم تنزيل ملف Excel قريباً...');
            // You could redirect to a PHP script that generates Excel using PHPSpreadsheet
            // window.location.href = 'export_excel.php?start_date=<?= $startDate ?>&end_date=<?= $endDate ?>&report_type=<?= $reportType ?>';
        }
    </script>
</body>

</html>