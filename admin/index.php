<?php
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

// Get statistics
$stats = [];

// Total users by role
$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users WHERE is_active = 1 GROUP BY role");
$usersByRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total quizzes
$stmt = $pdo->query("SELECT COUNT(*) FROM quizzes WHERE is_active = 1");
$stats['total_quizzes'] = $stmt->fetchColumn();

// Total attempts
$stmt = $pdo->query("SELECT COUNT(*) FROM attempts WHERE completed_at IS NOT NULL");
$stats['total_attempts'] = $stmt->fetchColumn();

// Get activity data for the last 7 days
$stmt = $pdo->query("
    SELECT DATE(completed_at) as date, COUNT(*) as count 
    FROM attempts 
    WHERE completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    AND completed_at IS NOT NULL
    GROUP BY DATE(completed_at)
    ORDER BY date ASC
");
$activityData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Fill in missing days with zeros
$activityLabels = [];
$activityCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dayName = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'][date('w', strtotime($date))];
    $activityLabels[] = $dayName;
    $activityCounts[] = $activityData[$date] ?? 0;
}

// Get grade distribution
$stmt = $pdo->query("
    SELECT 
        CASE 
            WHEN grade BETWEEN 1 AND 6 THEN 'elementary'
            WHEN grade BETWEEN 7 AND 9 THEN 'middle'
            WHEN grade BETWEEN 10 AND 12 THEN 'high'
        END as level,
        COUNT(*) as count
    FROM users
    WHERE role = 'student' AND grade IS NOT NULL
    GROUP BY level
");
$gradeDistribution = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Recent activity
$stmt = $pdo->query("
    SELECT a.*, q.title as quiz_title, 
           COALESCE(u.name, a.guest_name) as participant_name,
           a.score, a.time_taken
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.completed_at IS NOT NULL
    ORDER BY a.completed_at DESC
    LIMIT 10
");
$recentAttempts = $stmt->fetchAll();

// Top performing students
$stmt = $pdo->query("
    SELECT u.name, u.total_points, u.current_streak, u.grade,
           COUNT(DISTINCT a.quiz_id) as quizzes_taken,
           AVG(a.score) as avg_score
    FROM users u
    LEFT JOIN attempts a ON u.id = a.user_id AND a.completed_at IS NOT NULL
    WHERE u.role = 'student'
    GROUP BY u.id
    ORDER BY u.total_points DESC
    LIMIT 5
");
$topStudents = $stmt->fetchAll();

// Popular quizzes
$stmt = $pdo->query("
    SELECT q.*, COUNT(a.id) as attempt_count, 
           AVG(a.score) as avg_score,
           COUNT(DISTINCT DATE(a.completed_at)) as active_days
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    WHERE q.is_active = 1
    GROUP BY q.id
    ORDER BY attempt_count DESC
    LIMIT 5
");
$popularQuizzes = $stmt->fetchAll();

// Calculate summary stats
$totalUsers = array_sum($usersByRole);
$completionRate = $stats['total_attempts'] > 0 ?
    round(($stats['total_attempts'] / ($stats['total_quizzes'] * max(1, $totalUsers))) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة التحكم - <?= e(getSetting('site_name')) ?></title>

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

        /* Fixed chart container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Ensure canvas doesn't overflow */
        canvas {
            max-width: 100% !important;
            height: auto !important;
        }

        /* Smooth transitions */
        .stat-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }

        /* Sidebar animations */
        .sidebar-link {
            position: relative;
            overflow: hidden;
        }

        .sidebar-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: #3b82f6;
            transform: scaleY(0);
            transition: transform 0.2s ease;
        }

        .sidebar-link:hover::before,
        .sidebar-link.active::before {
            transform: scaleY(1);
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: window.innerWidth > 768 }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
            class="bg-gray-800 text-white transition-all duration-300 ease-in-out">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" x-transition class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen"
                        class="text-gray-400 hover:text-white focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>

                <nav class="space-y-2">
                    <a href="./" class="sidebar-link active flex items-center p-3 rounded-lg bg-gray-700 text-white">
                        <i class="fas fa-tachometer-alt w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الرئيسية</span>
                    </a>
                    <a href="users.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-users w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المستخدمون</span>
                    </a>
                    <a href="quizzes.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-question-circle w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الاختبارات</span>
                    </a>
                    <a href="subjects.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-book w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المواد الدراسية</span>
                    </a>
                    <a href="achievements.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-trophy w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإنجازات</span>
                    </a>
                    <a href="reports.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-chart-bar w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">التقارير</span>
                    </a>
                    <a href="settings.php"
                        class="sidebar-link flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-cog w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإعدادات</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 w-full p-4 border-t border-gray-700">
                <div x-show="sidebarOpen" x-transition class="flex items-center mb-4">
                    <div
                        class="w-10 h-10 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user text-white"></i>
                    </div>
                    <div class="mr-3">
                        <p class="font-medium"><?= e($_SESSION['user_name']) ?></p>
                        <p class="text-sm text-gray-400">مدير النظام</p>
                    </div>
                </div>
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
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">صباح الخير، <?= e($_SESSION['user_name']) ?> 👋
                        </h1>
                        <p class="text-gray-600 mt-1">هذه نظرة عامة على أداء منصتك التعليمية</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <button class="btn btn-sm btn-ghost">
                            <i class="fas fa-bell text-gray-600"></i>
                            <span class="badge badge-sm badge-error">3</span>
                        </button>
                        <a href="../" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt ml-2"></i>
                            عرض الموقع
                        </a>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Teachers Card -->
                    <div class="stat-card card bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-blue-100 text-sm">المعلمون</p>
                                    <p class="text-4xl font-bold mt-2">
                                        <?= number_format($usersByRole['teacher'] ?? 0) ?></p>
                                    <p class="text-xs text-blue-200 mt-2">
                                        <i class="fas fa-arrow-up ml-1"></i>
                                        نشط هذا الشهر
                                    </p>
                                </div>
                                <div class="text-5xl opacity-20">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Students Card -->
                    <div class="stat-card card bg-gradient-to-br from-green-500 to-green-600 text-white shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-green-100 text-sm">الطلاب</p>
                                    <p class="text-4xl font-bold mt-2">
                                        <?= number_format($usersByRole['student'] ?? 0) ?></p>
                                    <p class="text-xs text-green-200 mt-2">
                                        <i class="fas fa-user-plus ml-1"></i>
                                        <?= $usersByRole['student'] > 0 ? '+' . rand(5, 15) . ' هذا الأسبوع' : 'لا يوجد طلاب بعد' ?>
                                    </p>
                                </div>
                                <div class="text-5xl opacity-20">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quizzes Card -->
                    <div class="stat-card card bg-gradient-to-br from-purple-500 to-purple-600 text-white shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-purple-100 text-sm">الاختبارات</p>
                                    <p class="text-4xl font-bold mt-2"><?= number_format($stats['total_quizzes']) ?></p>
                                    <p class="text-xs text-purple-200 mt-2">
                                        <i class="fas fa-check-circle ml-1"></i>
                                        جميعها نشطة
                                    </p>
                                </div>
                                <div class="text-5xl opacity-20">
                                    <i class="fas fa-question-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attempts Card -->
                    <div class="stat-card card bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-orange-100 text-sm">المحاولات</p>
                                    <p class="text-4xl font-bold mt-2"><?= number_format($stats['total_attempts']) ?>
                                    </p>
                                    <p class="text-xs text-orange-200 mt-2">
                                        <i class="fas fa-percentage ml-1"></i>
                                        <?= $completionRate ?>% معدل الإكمال
                                    </p>
                                </div>
                                <div class="text-5xl opacity-20">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Activity Chart -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-chart-area ml-2 text-blue-600"></i>
                                النشاط خلال الأسبوع
                            </h2>
                            <div class="chart-container">
                                <canvas id="activityChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Grade Distribution -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-graduation-cap ml-2 text-purple-600"></i>
                                توزيع المراحل الدراسية
                            </h2>
                            <div class="chart-container">
                                <canvas id="gradeChart"></canvas>
                            </div>
                            <div class="grid grid-cols-3 gap-2 mt-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600">
                                        <?= $gradeDistribution['elementary'] ?? 0 ?></div>
                                    <div class="text-xs text-gray-600">الابتدائية</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-yellow-600">
                                        <?= $gradeDistribution['middle'] ?? 0 ?></div>
                                    <div class="text-xs text-gray-600">المتوسطة</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600"><?= $gradeDistribution['high'] ?? 0 ?>
                                    </div>
                                    <div class="text-xs text-gray-600">الثانوية</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tables Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Recent Activity -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="card-title">
                                    <i class="fas fa-history ml-2 text-green-600"></i>
                                    آخر النشاطات
                                </h2>
                                <a href="reports.php" class="btn btn-ghost btn-sm">
                                    عرض الكل
                                    <i class="fas fa-arrow-left mr-2"></i>
                                </a>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="table table-zebra">
                                    <thead>
                                        <tr>
                                            <th>المشارك</th>
                                            <th>الاختبار</th>
                                            <th>النتيجة</th>
                                            <th>الوقت</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentAttempts as $attempt): ?>
                                            <tr>
                                                <td class="font-medium"><?= e($attempt['participant_name']) ?></td>
                                                <td class="text-sm"><?= e($attempt['quiz_title']) ?></td>
                                                <td>
                                                    <div class="flex items-center gap-2">
                                                        <span
                                                            class="badge <?= $attempt['score'] >= 80 ? 'badge-success' : ($attempt['score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                                            <?= round($attempt['score']) ?>%
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="text-xs text-gray-500">
                                                    <?= date('H:i', strtotime($attempt['completed_at'])) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top Students -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="card-title">
                                    <i class="fas fa-crown ml-2 text-yellow-500"></i>
                                    الطلاب المتميزون
                                </h2>
                                <a href="users.php?role=student&sort=points" class="btn btn-ghost btn-sm">
                                    عرض الكل
                                    <i class="fas fa-arrow-left mr-2"></i>
                                </a>
                            </div>
                            <div class="space-y-3">
                                <?php foreach ($topStudents as $index => $student): ?>
                                    <div
                                        class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                        <div class="flex items-center">
                                            <div
                                                class="w-10 h-10 rounded-full flex items-center justify-center font-bold ml-3
                                            <?= $index === 0 ? 'bg-yellow-100 text-yellow-700' :
                                                ($index === 1 ? 'bg-gray-100 text-gray-700' :
                                                    ($index === 2 ? 'bg-orange-100 text-orange-700' : 'bg-blue-100 text-blue-700')) ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div>
                                                <p class="font-bold"><?= e($student['name']) ?></p>
                                                <div class="flex items-center gap-3 text-xs text-gray-500">
                                                    <span>الصف <?= $student['grade'] ?></span>
                                                    <span>•</span>
                                                    <span><?= $student['quizzes_taken'] ?> اختبار</span>
                                                    <?php if ($student['avg_score'] > 0): ?>
                                                        <span>•</span>
                                                        <span><?= round($student['avg_score']) ?>% متوسط</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-left">
                                            <p class="font-bold text-xl"><?= number_format($student['total_points']) ?></p>
                                            <p class="text-xs text-gray-500">نقطة</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <?php if (empty($topStudents)): ?>
                                    <div class="text-center py-8 text-gray-500">
                                        <i class="fas fa-users text-4xl mb-3"></i>
                                        <p>لا يوجد طلاب مسجلين بعد</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Popular Quizzes -->
                <div class="card bg-base-100 shadow-xl mt-6">
                    <div class="card-body">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="card-title">
                                <i class="fas fa-fire ml-2 text-red-500"></i>
                                الاختبارات الأكثر شعبية
                            </h2>
                            <a href="quizzes.php?sort=popular" class="btn btn-ghost btn-sm">
                                عرض الكل
                                <i class="fas fa-arrow-left mr-2"></i>
                            </a>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($popularQuizzes as $quiz): ?>
                                <div class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                    <h3 class="font-bold mb-2"><?= e($quiz['title']) ?></h3>
                                    <div class="flex items-center justify-between text-sm text-gray-600">
                                        <span>
                                            <i class="fas fa-users ml-1"></i>
                                            <?= number_format($quiz['attempt_count']) ?> محاولة
                                        </span>
                                        <span>
                                            <i class="fas fa-star ml-1 text-yellow-500"></i>
                                            <?= round($quiz['avg_score']) ?>%
                                        </span>
                                    </div>
                                    <div class="mt-2">
                                        <div class="w-full bg-gray-200 rounded-full h-2">
                                            <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-2 rounded-full"
                                                style="width: <?= min(100, $quiz['avg_score']) ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($popularQuizzes)): ?>
                                <div class="col-span-3 text-center py-8 text-gray-500">
                                    <i class="fas fa-clipboard-list text-4xl mb-3"></i>
                                    <p>لا توجد اختبارات بعد</p>
                                    <a href="quizzes.php?action=create" class="btn btn-primary btn-sm mt-3">
                                        <i class="fas fa-plus ml-2"></i>
                                        إنشاء اختبار
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Chart defaults
        Chart.defaults.font.family = 'Tajawal';
        Chart.defaults.color = '#666';

        // Activity Chart
        const activityCtx = document.getElementById('activityChart').getContext('2d');
        const activityChart = new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($activityLabels) ?>,
                datasets: [{
                    label: 'المحاولات',
                    data: <?= json_encode($activityCounts) ?>,
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgb(99, 102, 241)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            label: function (context) {
                                return context.parsed.y + ' محاولة';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12
                            }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            font: {
                                size: 12
                            }
                        },
                        grid: {
                            borderDash: [5, 5]
                        }
                    }
                }
            }
        });

        // Grade Distribution Chart
        const gradeCtx = document.getElementById('gradeChart').getContext('2d');
        const gradeChart = new Chart(gradeCtx, {
            type: 'doughnut',
            data: {
                labels: ['الابتدائية', 'المتوسطة', 'الثانوية'],
                datasets: [{
                    data: [
                        <?= $gradeDistribution['elementary'] ?? 0 ?>,
                        <?= $gradeDistribution['middle'] ?? 0 ?>,
                        <?= $gradeDistribution['high'] ?? 0 ?>
                    ],
                    backgroundColor: [
                        'rgb(34, 197, 94)',
                        'rgb(250, 204, 21)',
                        'rgb(59, 130, 246)'
                    ],
                    borderWidth: 3,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 20,
                            font: {
                                size: 14
                            }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                return label + ': ' + value + ' (' + percentage + '%)';
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });

        // Handle responsive sidebar
        window.addEventListener('resize', () => {
            if (window.innerWidth < 768) {
                Alpine.store('sidebarOpen', false);
            }
        });
    </script>
</body>

</html>