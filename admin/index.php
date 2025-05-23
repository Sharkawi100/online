<?php
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

// Get statistics
$stats = [];

// Total users by role
$stmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$usersByRole = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Total quizzes
$stmt = $pdo->query("SELECT COUNT(*) FROM quizzes");
$stats['total_quizzes'] = $stmt->fetchColumn();

// Total attempts
$stmt = $pdo->query("SELECT COUNT(*) FROM attempts");
$stats['total_attempts'] = $stmt->fetchColumn();

// Recent activity
$stmt = $pdo->query("
    SELECT a.*, q.title as quiz_title, 
           COALESCE(u.name, a.guest_name) as participant_name
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN users u ON a.user_id = u.id
    ORDER BY a.started_at DESC
    LIMIT 10
");
$recentAttempts = $stmt->fetchAll();

// Top performing students
$stmt = $pdo->query("
    SELECT u.name, u.total_points, u.current_streak, u.grade
    FROM users u
    WHERE u.role = 'student'
    ORDER BY u.total_points DESC
    LIMIT 5
");
$topStudents = $stmt->fetchAll();

// Popular quizzes
$stmt = $pdo->query("
    SELECT q.*, COUNT(a.id) as attempt_count, AVG(a.score) as avg_score
    FROM quizzes q
    LEFT JOIN attempts a ON q.id = a.quiz_id
    GROUP BY q.id
    ORDER BY attempt_count DESC
    LIMIT 5
");
$popularQuizzes = $stmt->fetchAll();
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ sidebarOpen: true }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'" class="bg-gray-800 text-white transition-all duration-300">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white">
                        <i class="fas" :class="sidebarOpen ? 'fa-times' : 'fa-bars'"></i>
                    </button>
                </div>

                <nav class="space-y-2">
                    <a href="/admin/" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
                        <i class="fas fa-tachometer-alt w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">الرئيسية</span>
                    </a>
                    <a href="/admin/users.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-users w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">المستخدمون</span>
                    </a>
                    <a href="/admin/quizzes.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-question-circle w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">الاختبارات</span>
                    </a>
                    <a href="/admin/subjects.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-book w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">المواد الدراسية</span>
                    </a>
                    <a href="/admin/achievements.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-trophy w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">الإنجازات</span>
                    </a>
                    <a href="/admin/settings.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-cog w-6"></i>
                        <span x-show="sidebarOpen" class="mr-3">الإعدادات</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 w-full p-4 border-t border-gray-700">
                <div x-show="sidebarOpen" class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-gray-600 rounded-full flex items-center justify-center">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="mr-3">
                        <p class="font-medium"><?= e($_SESSION['user_name']) ?></p>
                        <p class="text-sm text-gray-400">مدير النظام</p>
                    </div>
                </div>
                <a href="/admin/logout.php"
                    class="flex items-center p-2 rounded hover:bg-gray-700 transition-colors text-red-400">
                    <i class="fas fa-sign-out-alt w-6"></i>
                    <span x-show="sidebarOpen" class="mr-3">تسجيل الخروج</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-x-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm">
                <div class="px-6 py-4">
                    <h1 class="text-2xl font-bold text-gray-800">مرحباً، <?= e($_SESSION['user_name']) ?> 👋</h1>
                    <p class="text-gray-600">هذه نظرة عامة على منصتك التعليمية</p>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Teachers Card -->
                    <div class="card bg-gradient-to-br from-blue-500 to-blue-600 text-white">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-blue-100">المعلمون</p>
                                    <p class="text-3xl font-bold"><?= $usersByRole['teacher'] ?? 0 ?></p>
                                </div>
                                <div class="text-4xl opacity-50">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Students Card -->
                    <div class="card bg-gradient-to-br from-green-500 to-green-600 text-white">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-green-100">الطلاب</p>
                                    <p class="text-3xl font-bold"><?= $usersByRole['student'] ?? 0 ?></p>
                                </div>
                                <div class="text-4xl opacity-50">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quizzes Card -->
                    <div class="card bg-gradient-to-br from-purple-500 to-purple-600 text-white">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-purple-100">الاختبارات</p>
                                    <p class="text-3xl font-bold"><?= $stats['total_quizzes'] ?></p>
                                </div>
                                <div class="text-4xl opacity-50">
                                    <i class="fas fa-question-circle"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Attempts Card -->
                    <div class="card bg-gradient-to-br from-orange-500 to-orange-600 text-white">
                        <div class="card-body">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-orange-100">المحاولات</p>
                                    <p class="text-3xl font-bold"><?= $stats['total_attempts'] ?></p>
                                </div>
                                <div class="text-4xl opacity-50">
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
                            <h2 class="card-title">النشاط خلال الأسبوع</h2>
                            <canvas id="activityChart" height="150"></canvas>
                        </div>
                    </div>

                    <!-- Grade Distribution -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title">توزيع المراحل الدراسية</h2>
                            <canvas id="gradeChart" height="150"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Tables Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Recent Activity -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">آخر النشاطات</h2>
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
                                                <td><?= e($attempt['participant_name']) ?></td>
                                                <td><?= e($attempt['quiz_title']) ?></td>
                                                <td>
                                                    <span
                                                        class="badge <?= $attempt['score'] >= 80 ? 'badge-success' : ($attempt['score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                                        <?= $attempt['score'] ?>%
                                                    </span>
                                                </td>
                                                <td class="text-xs"><?= date('H:i', strtotime($attempt['started_at'])) ?>
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
                            <h2 class="card-title mb-4">الطلاب المتميزون</h2>
                            <div class="space-y-3">
                                <?php foreach ($topStudents as $index => $student): ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex items-center">
                                            <div
                                                class="w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-bold ml-3">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div>
                                                <p class="font-medium"><?= e($student['name']) ?></p>
                                                <p class="text-sm text-gray-500">الصف <?= $student['grade'] ?></p>
                                            </div>
                                        </div>
                                        <div class="text-left">
                                            <p class="font-bold text-lg"><?= number_format($student['total_points']) ?></p>
                                            <p class="text-xs text-gray-500">نقطة</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Activity Chart
        const activityCtx = document.getElementById('activityChart').getContext('2d');
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'],
                datasets: [{
                    label: 'المحاولات',
                    data: [12, 19, 15, 25, 22, 30, 18],
                    borderColor: 'rgb(99, 102, 241)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Grade Distribution Chart
        const gradeCtx = document.getElementById('gradeChart').getContext('2d');
        new Chart(gradeCtx, {
            type: 'doughnut',
            data: {
                labels: ['الابتدائية', 'المتوسطة', 'الثانوية'],
                datasets: [{
                    data: [45, 30, 25],
                    backgroundColor: [
                        'rgb(34, 197, 94)',
                        'rgb(250, 204, 21)',
                        'rgb(59, 130, 246)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    </script>
</body>

</html>