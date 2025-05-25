<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$siteName = getSetting('site_name', 'منصة الاختبارات التفاعلية');

// Get live statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT a.id) as total_attempts_today,
        COUNT(DISTINCT a.user_id) as active_students_today,
        COUNT(DISTINCT q.id) as total_quizzes,
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM attempts WHERE completed_at IS NOT NULL) as total_completed
    FROM attempts a
    LEFT JOIN quizzes q ON a.quiz_id = q.id
    WHERE DATE(a.started_at) = CURDATE()
");
$stats = $stmt->fetch();

// Get recent high scores
$stmt = $pdo->query("
    SELECT 
        COALESCE(u.name, a.guest_name) as student_name,
        q.title as quiz_title,
        a.score,
        a.completed_at,
        s.name_ar as subject_name,
        s.icon as subject_icon,
        s.color as subject_color
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE a.completed_at IS NOT NULL AND a.score >= 90
    ORDER BY a.completed_at DESC
    LIMIT 5
");
$highScores = $stmt->fetchAll();

// Get today's top students
$stmt = $pdo->query("
    SELECT 
        u.name,
        u.grade,
        SUM(a.total_points) as points_today,
        COUNT(DISTINCT a.id) as quizzes_completed,
        AVG(a.score) as avg_score
    FROM users u
    JOIN attempts a ON u.id = a.user_id
    WHERE DATE(a.completed_at) = CURDATE()
    GROUP BY u.id
    ORDER BY points_today DESC
    LIMIT 3
");
$topStudents = $stmt->fetchAll();

// Get subjects with stats
$stmt = $pdo->query("
    SELECT 
        s.*,
        COUNT(DISTINCT q.id) as quiz_count,
        COUNT(DISTINCT a.id) as attempt_count,
        COALESCE(AVG(a.score), 0) as avg_score
    FROM subjects s
    LEFT JOIN quizzes q ON s.id = q.subject_id AND q.is_active = 1
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    WHERE s.is_active = 1
    GROUP BY s.id
    ORDER BY s.order_index
");
$subjects = $stmt->fetchAll();

// Get featured quizzes
$stmt = $pdo->query("
    SELECT 
        q.*,
        s.name_ar as subject_name,
        s.icon as subject_icon,
        s.color as subject_color,
        u.name as teacher_name,
        COUNT(DISTINCT a.id) as play_count
    FROM quizzes q
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN users u ON q.teacher_id = u.id
    LEFT JOIN attempts a ON q.id = a.quiz_id
    WHERE q.is_active = 1
    GROUP BY q.id
    ORDER BY play_count DESC
    LIMIT 6
");
$featuredQuizzes = $stmt->fetchAll();

$justLoggedOut = isset($_GET['logout']) && $_GET['logout'] === 'success';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?> - منصة التعلم التفاعلي</title>

    <!-- SEO Meta Tags -->
    <meta name="description"
        content="منصة تعليمية تفاعلية متطورة للطلاب من جميع المراحل. اختبارات ذكية، تعلم ممتع، ومتابعة مستمرة للتقدم.">
    <meta property="og:title" content="<?= e($siteName) ?>">
    <meta property="og:description" content="انضم لآلاف الطلاب في رحلة تعليمية ممتعة ومثمرة">
    <meta property="og:image" content="<?= BASE_URL ?>/assets/images/og-image.png">
    <meta property="og:type" content="website">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap"
        rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <!-- Confetti -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        /* Hero gradient animation */
        .hero-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            background-size: 200% 200%;
            animation: gradient-shift 15s ease infinite;
        }

        @keyframes gradient-shift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        /* Smooth scroll behavior */
        html {
            scroll-behavior: smooth;
        }

        /* Floating elements */
        .float-animation {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            25% {
                transform: translateY(-20px) rotate(5deg);
            }

            75% {
                transform: translateY(10px) rotate(-5deg);
            }
        }

        /* Card hover effects */
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .hover-lift:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(to right, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        /* Stats counter animation */
        @keyframes count-up {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .counter-animation {
            animation: count-up 1s ease-out forwards;
        }

        /* Pulse animation for live indicator */
        .pulse-dot {
            animation: pulse-dot 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        @keyframes pulse-dot {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        /* Subject card gradient borders */
        .gradient-border {
            position: relative;
            background: white;
            background-clip: padding-box;
            border: 3px solid transparent;
        }

        .gradient-border::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 3px;
            background: linear-gradient(135deg, var(--color-from), var(--color-to));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
        }

        /* Mobile optimization */
        @media (max-width: 768px) {
            .hide-mobile {
                display: none;
            }

            .mobile-menu {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                z-index: 50;
            }
        }

        /* Glass morphism */
        .glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Loading skeleton */
        .skeleton-box {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Smooth transitions */
        * {
            transition-property: transform, opacity, background-color, border-color, color, fill, stroke;
            transition-duration: 200ms;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
        }
    </style>
</head>

<body class="bg-gray-50" x-data="app">
    <!-- Toast Notifications -->
    <?php if ($justLoggedOut): ?>
        <div class="toast toast-top toast-center z-50" x-data="{ show: true }" x-show="show"
            x-init="setTimeout(() => show = false, 3000)">
            <div class="alert alert-success shadow-lg animate__animated animate__bounceIn">
                <i class="fas fa-check-circle text-xl"></i>
                <span>تم تسجيل الخروج بنجاح!</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="navbar bg-white/95 backdrop-blur-lg shadow-lg sticky top-0 z-40 px-4 lg:px-8">
        <div class="navbar-start">
            <div class="flex items-center gap-3">
                <div
                    class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center text-white">
                    <i class="fas fa-graduation-cap text-xl"></i>
                </div>
                <span class="text-xl font-bold hidden sm:inline"><?= e($siteName) ?></span>
            </div>
        </div>

        <div class="navbar-center hidden lg:flex">
            <ul class="menu menu-horizontal px-1 gap-2">
                <li><a href="#features" class="rounded-lg">المميزات</a></li>
                <li><a href="#subjects" class="rounded-lg">المواد</a></li>
                <li><a href="#how-it-works" class="rounded-lg">كيف يعمل</a></li>
                <li><a href="#contact" class="rounded-lg">تواصل معنا</a></li>
            </ul>
        </div>

        <div class="navbar-end gap-2">
            <!-- Live Users Counter -->
            <div class="hidden md:flex items-center gap-2 text-sm">
                <span class="relative flex h-3 w-3">
                    <span
                        class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                </span>
                <span class="font-medium"><?= formatArabicNumber($stats['active_students_today']) ?> نشط</span>
            </div>

            <?php if (isLoggedIn()): ?>
                <?php if (hasRole('student')): ?>
                    <a href="student/" class="btn btn-primary btn-sm">
                        <i class="fas fa-home"></i>
                        <span class="hidden sm:inline">لوحتي</span>
                    </a>
                <?php elseif (hasRole('teacher')): ?>
                    <a href="teacher/" class="btn btn-primary btn-sm">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <span class="hidden sm:inline">لوحة المعلم</span>
                    </a>
                <?php elseif (hasRole('admin')): ?>
                    <a href="admin/" class="btn btn-primary btn-sm">
                        <i class="fas fa-cog"></i>
                        <span class="hidden sm:inline">الإدارة</span>
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="auth/login.php" class="btn btn-ghost btn-sm">
                    <i class="fas fa-sign-in-alt"></i>
                    <span class="hidden sm:inline">دخول</span>
                </a>
                <a href="auth/register.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-user-plus"></i>
                    <span class="hidden sm:inline">تسجيل</span>
                </a>
            <?php endif; ?>

            <!-- Mobile menu button -->
            <div class="dropdown dropdown-end lg:hidden">
                <label tabindex="0" class="btn btn-ghost btn-circle">
                    <i class="fas fa-bars text-xl"></i>
                </label>
                <ul tabindex="0" class="dropdown-content menu p-2 shadow-lg bg-base-100 rounded-box w-52">
                    <li><a href="#features">المميزات</a></li>
                    <li><a href="#subjects">المواد</a></li>
                    <li><a href="#how-it-works">كيف يعمل</a></li>
                    <li><a href="#contact">تواصل معنا</a></li>
                    <li class="divider"></li>
                    <li><a href="admin/login.php"><i class="fas fa-user-shield ml-2"></i>دخول المدير</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-gradient text-white relative overflow-hidden">
        <!-- Animated Background Elements -->
        <div class="absolute inset-0 opacity-10">
            <div
                class="absolute top-20 left-10 w-72 h-72 bg-white rounded-full mix-blend-multiply filter blur-xl animate-blob">
            </div>
            <div
                class="absolute top-40 right-20 w-72 h-72 bg-yellow-300 rounded-full mix-blend-multiply filter blur-xl animate-blob animation-delay-2000">
            </div>
            <div
                class="absolute -bottom-8 left-40 w-72 h-72 bg-pink-300 rounded-full mix-blend-multiply filter blur-xl animate-blob animation-delay-4000">
            </div>
        </div>

        <div class="relative container mx-auto px-4 py-16 lg:py-24">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div class="text-center lg:text-right space-y-6">
                    <h1 class="text-4xl lg:text-6xl font-bold animate__animated animate__fadeInRight">
                        تعلم بذكاء،
                        <span class="text-white font-black drop-shadow-lg">انجح بتميز!</span>
                    </h1>
                    <p
                        class="text-xl lg:text-2xl text-white/90 animate__animated animate__fadeInRight animate__delay-1s">
                        منصة تعليمية متكاملة تجعل التعلم متعة حقيقية مع اختبارات تفاعلية ونظام نقاط وجوائز
                    </p>

                    <!-- Quick Stats -->
                    <div
                        class="grid grid-cols-3 gap-4 max-w-md mx-auto lg:mx-0 animate__animated animate__fadeInUp animate__delay-2s">
                        <div class="text-center bg-white/20 backdrop-blur-md rounded-xl p-4 border border-white/30">
                            <div class="text-3xl font-bold text-white drop-shadow counter-animation">
                                <?= formatArabicNumber($stats['total_quizzes']) ?>
                            </div>
                            <div class="text-sm text-white/90">اختبار متاح</div>
                        </div>
                        <div class="text-center bg-white/20 backdrop-blur-md rounded-xl p-4 border border-white/30">
                            <div class="text-3xl font-bold text-white drop-shadow counter-animation">
                                <?= formatArabicNumber($stats['total_students']) ?>
                            </div>
                            <div class="text-sm text-white/90">طالب مسجل</div>
                        </div>
                        <div class="text-center bg-white/20 backdrop-blur-md rounded-xl p-4 border border-white/30">
                            <div class="text-3xl font-bold text-white drop-shadow counter-animation">
                                <?= formatArabicNumber($stats['total_completed']) ?>
                            </div>
                            <div class="text-sm text-white/90">اختبار مكتمل</div>
                        </div>
                    </div>

                    <!-- CTA Buttons -->
                    <div
                        class="flex flex-wrap gap-4 justify-center lg:justify-start animate__animated animate__fadeInUp animate__delay-3s">
                        <div class="w-full sm:w-auto">
                            <label for="pinModal"
                                class="btn btn-warning btn-lg w-full sm:w-auto shadow-xl hover:shadow-2xl text-gray-800 font-bold">
                                <i class="fas fa-key ml-2"></i>
                                لدي رمز اختبار
                            </label>
                        </div>
                        <?php if (!isLoggedIn()): ?>
                            <a href="auth/register.php"
                                class="btn bg-white text-purple-700 hover:bg-gray-100 border-0 btn-lg w-full sm:w-auto font-bold shadow-xl">
                                <i class="fas fa-rocket ml-2"></i>
                                ابدأ رحلتك مجاناً
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Content - Interactive Demo -->
                <div class="hidden lg:block">
                    <div
                        class="bg-white/10 backdrop-blur-md rounded-2xl p-6 shadow-2xl border border-white/20 float-animation">
                        <h3 class="text-xl font-bold mb-4 text-white">
                            <i class="fas fa-trophy text-yellow-300 ml-2"></i>
                            أحدث الإنجازات
                        </h3>
                        <div class="space-y-3 max-h-96 overflow-y-auto">
                            <?php if (empty($highScores)): ?>
                                <div class="text-center py-8 opacity-70">
                                    <i class="fas fa-medal text-6xl mb-4"></i>
                                    <p>كن أول من يحقق درجة عالية اليوم!</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($highScores as $score): ?>
                                    <div
                                        class="bg-white/20 backdrop-blur rounded-lg p-3 flex items-center gap-3 hover:bg-white/30 transition-all">
                                        <div class="avatar placeholder">
                                            <div
                                                class="bg-gradient-to-br from-yellow-400 to-orange-500 text-white rounded-full w-10">
                                                <span class="text-lg"><?= mb_substr($score['student_name'], 0, 1) ?></span>
                                            </div>
                                        </div>
                                        <div class="flex-1">
                                            <p class="font-semibold"><?= e($score['student_name']) ?></p>
                                            <p class="text-sm opacity-80">
                                                <?= round($score['score']) ?>% في <?= e($score['quiz_title']) ?>
                                            </p>
                                        </div>
                                        <div class="text-2xl">🏆</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 animate-bounce">
            <i class="fas fa-chevron-down text-2xl opacity-70"></i>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-16 lg:py-24 bg-white">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-bold mb-4 gradient-text">لماذا نحن الأفضل؟</h2>
                <p class="text-xl text-gray-600">منصة مصممة خصيصاً لتلبية احتياجات الطلاب العرب</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="card hover-lift cursor-pointer group">
                    <div class="card-body text-center">
                        <div
                            class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                            <i class="fas fa-brain text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">تعلم ذكي</h3>
                        <p class="text-gray-600">نظام ذكي يتكيف مع مستواك ويساعدك على التحسن</p>
                    </div>
                </div>

                <div class="card hover-lift cursor-pointer group">
                    <div class="card-body text-center">
                        <div
                            class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-green-400 to-green-600 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                            <i class="fas fa-gamepad text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">تعلم ممتع</h3>
                        <p class="text-gray-600">اختبارات تفاعلية ونظام نقاط يجعل التعلم كاللعب</p>
                    </div>
                </div>

                <div class="card hover-lift cursor-pointer group">
                    <div class="card-body text-center">
                        <div
                            class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                            <i class="fas fa-chart-line text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">تتبع مستمر</h3>
                        <p class="text-gray-600">تقارير مفصلة تساعدك على معرفة نقاط القوة والضعف</p>
                    </div>
                </div>

                <div class="card hover-lift cursor-pointer group">
                    <div class="card-body text-center">
                        <div
                            class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br from-orange-400 to-orange-600 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                            <i class="fas fa-trophy text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-bold mb-2">جوائز وتحديات</h3>
                        <p class="text-gray-600">احصل على شارات وجوائز وتنافس مع أصدقائك</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Subjects Section -->
    <section id="subjects" class="py-16 lg:py-24 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-bold mb-4">اختر المادة وابدأ التعلم</h2>
                <p class="text-xl text-gray-600">جميع المواد الدراسية متوفرة مع آلاف الأسئلة</p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                <?php foreach ($subjects as $subject): ?>
                    <div class="card hover-lift cursor-pointer gradient-border"
                        style="--color-from: <?= $subject['color'] ?>; --color-to: <?= $subject['color'] ?>aa;"
                        onclick="window.location.href='browse/subject.php?id=<?= $subject['id'] ?>'">
                        <div class="card-body text-center p-6">
                            <div class="w-16 h-16 mx-auto mb-3 rounded-full flex items-center justify-center"
                                style="background: linear-gradient(135deg, <?= $subject['color'] ?>33, <?= $subject['color'] ?>66);">
                                <i class="<?= $subject['icon'] ?> text-2xl" style="color: <?= $subject['color'] ?>"></i>
                            </div>
                            <h3 class="font-bold text-lg mb-2"><?= e($subject['name_ar']) ?></h3>
                            <div class="text-sm text-gray-600">
                                <div class="mb-1"><?= formatArabicNumber($subject['quiz_count']) ?> اختبار</div>
                                <?php if ($subject['attempt_count'] > 0): ?>
                                    <div class="text-xs">
                                        متوسط: <?= round($subject['avg_score']) ?>%
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Top Students & Featured Quizzes -->
    <section class="py-16 lg:py-24 bg-white">
        <div class="container mx-auto px-4">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                <!-- Top Students -->
                <div>
                    <h3 class="text-2xl font-bold mb-6 flex items-center">
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center text-white ml-3">
                            <i class="fas fa-crown"></i>
                        </div>
                        أبطال اليوم
                    </h3>

                    <div class="space-y-4">
                        <?php if (empty($topStudents)): ?>
                            <div class="card bg-gray-50">
                                <div class="card-body text-center py-12">
                                    <i class="fas fa-medal text-6xl text-gray-300 mb-4"></i>
                                    <p class="text-gray-500">لا يوجد أبطال بعد اليوم</p>
                                    <p class="text-sm text-gray-400">كن أول الأبطال!</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($topStudents as $index => $student): ?>
                                <div
                                    class="card hover-lift <?= $index === 0 ? 'bg-gradient-to-r from-yellow-50 to-orange-50 border-2 border-yellow-300' : 'bg-base-100' ?>">
                                    <div class="card-body p-4">
                                        <div class="flex items-center gap-4">
                                            <div
                                                class="text-3xl font-bold <?= ['text-yellow-600', 'text-gray-400', 'text-orange-600'][$index] ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div class="avatar placeholder">
                                                <div
                                                    class="bg-gradient-to-br from-purple-400 to-pink-400 text-white rounded-full w-12">
                                                    <span class="text-xl"><?= mb_substr($student['name'], 0, 1) ?></span>
                                                </div>
                                            </div>
                                            <div class="flex-1">
                                                <p class="font-bold"><?= e($student['name']) ?></p>
                                                <p class="text-sm text-gray-600">
                                                    <?= getGradeName($student['grade']) ?> •
                                                    <?= formatArabicNumber($student['quizzes_completed']) ?> اختبار
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-2xl font-bold text-primary">
                                                    <?= formatArabicNumber($student['points_today']) ?>
                                                </p>
                                                <p class="text-xs text-gray-600">نقطة</p>
                                            </div>
                                            <?php if ($index === 0): ?>
                                                <i
                                                    class="fas fa-crown text-yellow-500 text-2xl animate__animated animate__bounce animate__infinite"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Featured Quizzes -->
                <div>
                    <h3 class="text-2xl font-bold mb-6 flex items-center">
                        <div
                            class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-400 to-pink-400 flex items-center justify-center text-white ml-3">
                            <i class="fas fa-fire"></i>
                        </div>
                        الاختبارات الأكثر شعبية
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php foreach ($featuredQuizzes as $quiz): ?>
                            <div class="card hover-lift cursor-pointer" onclick="enterPin('<?= $quiz['pin_code'] ?>')">
                                <div class="card-body p-4">
                                    <div class="flex items-start gap-3">
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center"
                                            style="background: <?= $quiz['subject_color'] ?>20;">
                                            <i class="<?= $quiz['subject_icon'] ?> text-xl"
                                                style="color: <?= $quiz['subject_color'] ?>"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="font-bold text-sm mb-1"><?= e($quiz['title']) ?></h4>
                                            <p class="text-xs text-gray-600">
                                                <?= e($quiz['teacher_name']) ?> •
                                                <?= getGradeName($quiz['grade']) ?>
                                            </p>
                                            <div class="flex items-center gap-2 mt-2">
                                                <span class="badge badge-sm"><?= $quiz['pin_code'] ?></span>
                                                <span class="text-xs text-gray-500">
                                                    <i class="fas fa-play ml-1"></i>
                                                    <?= formatArabicNumber($quiz['play_count']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section id="how-it-works" class="py-16 lg:py-24 bg-gradient-to-br from-purple-50 to-pink-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h2 class="text-3xl lg:text-4xl font-bold mb-4">كيف تبدأ رحلتك التعليمية؟</h2>
                <p class="text-xl text-gray-600">خطوات بسيطة للبدء في التعلم الممتع</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="text-center group">
                    <div class="relative inline-block mb-4">
                        <div
                            class="w-24 h-24 rounded-full bg-white shadow-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                            <span class="text-3xl font-bold gradient-text">1</span>
                        </div>
                        <div
                            class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-purple-500 flex items-center justify-center text-white">
                            <i class="fas fa-user-plus text-sm"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold mb-2">سجل مجاناً</h3>
                    <p class="text-gray-600">أنشئ حسابك في ثوانٍ</p>
                </div>

                <div class="text-center group">
                    <div class="relative inline-block mb-4">
                        <div
                            class="w-24 h-24 rounded-full bg-white shadow-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                            <span class="text-3xl font-bold gradient-text">2</span>
                        </div>
                        <div
                            class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white">
                            <i class="fas fa-book text-sm"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold mb-2">اختر المادة</h3>
                    <p class="text-gray-600">تصفح المواد المتاحة</p>
                </div>

                <div class="text-center group">
                    <div class="relative inline-block mb-4">
                        <div
                            class="w-24 h-24 rounded-full bg-white shadow-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                            <span class="text-3xl font-bold gradient-text">3</span>
                        </div>
                        <div
                            class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-green-500 flex items-center justify-center text-white">
                            <i class="fas fa-play text-sm"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold mb-2">ابدأ الاختبار</h3>
                    <p class="text-gray-600">أجب على الأسئلة التفاعلية</p>
                </div>

                <div class="text-center group">
                    <div class="relative inline-block mb-4">
                        <div
                            class="w-24 h-24 rounded-full bg-white shadow-lg flex items-center justify-center group-hover:scale-110 transition-transform">
                            <span class="text-3xl font-bold gradient-text">4</span>
                        </div>
                        <div
                            class="absolute -bottom-2 -right-2 w-8 h-8 rounded-full bg-yellow-500 flex items-center justify-center text-white">
                            <i class="fas fa-trophy text-sm"></i>
                        </div>
                    </div>
                    <h3 class="text-xl font-bold mb-2">احصد النجاح</h3>
                    <p class="text-gray-600">اربح نقاط وشارات</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-16 lg:py-24 hero-gradient text-white">
        <div class="container mx-auto px-4 text-center">
            <h2 class="text-3xl lg:text-5xl font-bold mb-6">مستعد لتحويل التعلم إلى متعة؟</h2>
            <p class="text-xl mb-8 opacity-90">انضم لآلاف الطلاب الذين يحققون النجاح كل يوم</p>

            <div class="flex flex-wrap gap-4 justify-center">
                <?php if (!isLoggedIn()): ?>
                    <a href="auth/register.php" class="btn btn-warning btn-lg shadow-xl hover:shadow-2xl">
                        <i class="fas fa-rocket ml-2"></i>
                        ابدأ رحلتك المجانية
                    </a>
                <?php endif; ?>
                <label for="pinModal" class="btn btn-outline btn-white btn-lg">
                    <i class="fas fa-key ml-2"></i>
                    أدخل رمز الاختبار
                </label>
            </div>

            <div class="mt-12 grid grid-cols-2 md:grid-cols-4 gap-8 max-w-4xl mx-auto">
                <div class="text-center">
                    <i class="fas fa-infinity text-4xl mb-2"></i>
                    <p class="text-lg">وصول غير محدود</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-certificate text-4xl mb-2"></i>
                    <p class="text-lg">شهادات معتمدة</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-headset text-4xl mb-2"></i>
                    <p class="text-lg">دعم مستمر</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-shield-alt text-4xl mb-2"></i>
                    <p class="text-lg">آمن وموثوق</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer footer-center p-10 bg-base-200 text-base-content">
        <div class="grid grid-flow-col gap-4">
            <a href="#" class="link link-hover">عن المنصة</a>
            <a href="#" class="link link-hover">سياسة الخصوصية</a>
            <a href="#" class="link link-hover">الشروط والأحكام</a>
            <a href="#contact" class="link link-hover">تواصل معنا</a>
        </div>
        <div>
            <div class="grid grid-flow-col gap-4">
                <a href="#" class="hover:scale-110 transition-transform">
                    <i class="fab fa-twitter text-2xl text-blue-400"></i>
                </a>
                <a href="#" class="hover:scale-110 transition-transform">
                    <i class="fab fa-youtube text-2xl text-red-500"></i>
                </a>
                <a href="#" class="hover:scale-110 transition-transform">
                    <i class="fab fa-facebook text-2xl text-blue-600"></i>
                </a>
                <a href="#" class="hover:scale-110 transition-transform">
                    <i class="fab fa-instagram text-2xl text-pink-500"></i>
                </a>
            </div>
        </div>
        <div>
            <p>جميع الحقوق محفوظة © <?= date('Y') ?> - <?= e($siteName) ?></p>
            <p class="text-sm mt-1">صنع بـ ❤️ لطلابنا الأعزاء</p>
        </div>
    </footer>

    <!-- PIN Entry Modal -->
    <input type="checkbox" id="pinModal" class="modal-toggle" />
    <div class="modal modal-bottom sm:modal-middle">
        <div class="modal-box">
            <h3 class="font-bold text-xl mb-4">
                <i class="fas fa-key text-primary ml-2"></i>
                أدخل رمز الاختبار
            </h3>

            <form action="quiz/join.php" method="GET" onsubmit="return validatePin()">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">رمز الاختبار المكون من 6 أرقام</span>
                    </label>
                    <input type="text" name="pin" id="pinInput" maxlength="6" pattern="[0-9]{6}"
                        class="input input-bordered input-lg text-center text-2xl tracking-widest font-bold"
                        placeholder="000000" required autofocus
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                </div>

                <div class="modal-action">
                    <label for="pinModal" class="btn btn-ghost">إلغاء</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play ml-2"></i>
                        دخول الاختبار
                    </button>
                </div>
            </form>
        </div>
        <label class="modal-backdrop" for="pinModal">Close</label>
    </div>

    <!-- Mobile Bottom Navigation -->
    <div class="mobile-menu btm-nav btm-nav-lg lg:hidden glass">
        <a href="/" class="text-primary">
            <i class="fas fa-home text-xl"></i>
            <span class="btm-nav-label text-xs">الرئيسية</span>
        </a>
        <a href="#subjects">
            <i class="fas fa-book text-xl"></i>
            <span class="btm-nav-label text-xs">المواد</span>
        </a>
        <label for="pinModal" class="bg-primary text-white">
            <i class="fas fa-key text-xl"></i>
            <span class="btm-nav-label text-xs">رمز</span>
        </label>
        <?php if (isLoggedIn()): ?>
            <a href="<?= hasRole('student') ? 'student/' : (hasRole('teacher') ? 'teacher/' : 'admin/') ?>">
                <i class="fas fa-user text-xl"></i>
                <span class="btm-nav-label text-xs">حسابي</span>
            </a>
        <?php else: ?>
            <a href="auth/login.php">
                <i class="fas fa-sign-in-alt text-xl"></i>
                <span class="btm-nav-label text-xs">دخول</span>
            </a>
        <?php endif; ?>
    </div>

    <script>
        // Alpine.js data
        document.addEventListener('alpine:init', () => {
            Alpine.data('app', () => ({

            }));
        });

        // PIN validation
        function validatePin() {
            const pin = document.getElementById('pinInput').value;
            if (pin.length !== 6) {
                alert('رمز الاختبار يجب أن يكون 6 أرقام');
                return false;
            }
            return true;
        }

        // Enter PIN directly
        function enterPin(pin) {
            document.getElementById('pinInput').value = pin;
            document.getElementById('pinModal').checked = true;
        }

        // Auto-focus PIN input when modal opens
        document.getElementById('pinModal').addEventListener('change', function (e) {
            if (e.target.checked) {
                setTimeout(() => {
                    document.getElementById('pinInput').focus();
                }, 100);
            }
        });

        // Keyboard shortcut for PIN entry
        document.addEventListener('keypress', function (e) {
            if (e.key >= '0' && e.key <= '9' && !e.target.matches('input, textarea')) {
                document.getElementById('pinModal').checked = true;
                setTimeout(() => {
                    document.getElementById('pinInput').value = e.key;
                    document.getElementById('pinInput').focus();
                }, 100);
            }
        });

        // Smooth scroll for navigation links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Confetti for high scores
        <?php foreach ($highScores as $score): ?>
            <?php if ($score['score'] >= 95): ?>
                setTimeout(() => {
                    confetti({
                        particleCount: 30,
                        spread: 70,
                        origin: { y: 0.6 },
                        colors: ['#fbbf24', '#f59e0b', '#d97706']
                    });
                }, Math.random() * 5000);
            <?php endif; ?>
        <?php endforeach; ?>

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate__animated', 'animate__fadeInUp');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.card').forEach(el => {
            observer.observe(el);
        });
    </script>
</body>

</html>