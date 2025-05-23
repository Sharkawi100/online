<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

$siteName = getSetting('site_name', 'منصة الاختبارات التفاعلية');

// Get live statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT a.id) as total_attempts_today,
        COUNT(DISTINCT a.user_id) as active_students_today,
        COUNT(DISTINCT q.id) as total_quizzes
    FROM attempts a
    LEFT JOIN quizzes q ON a.quiz_id = q.id
    WHERE DATE(a.started_at) = CURDATE()
");
$stats = $stmt->fetch();

// Get recent activity for feed
$stmt = $pdo->query("
    SELECT 
        COALESCE(u.name, a.guest_name) as student_name,
        q.title as quiz_title,
        a.score,
        a.completed_at,
        s.name_ar as subject_name
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE a.completed_at IS NOT NULL
    ORDER BY a.completed_at DESC
    LIMIT 10
");
$recentActivity = $stmt->fetchAll();

// Get top students today
$stmt = $pdo->query("
    SELECT 
        u.name,
        SUM(a.total_points) as points_today,
        COUNT(DISTINCT a.id) as quizzes_completed
    FROM users u
    JOIN attempts a ON u.id = a.user_id
    WHERE DATE(a.completed_at) = CURDATE()
    GROUP BY u.id
    ORDER BY points_today DESC
    LIMIT 5
");
$topStudents = $stmt->fetchAll();

// Get subjects with quiz counts
$stmt = $pdo->query("
    SELECT 
        s.*,
        COUNT(DISTINCT q.id) as quiz_count,
        COUNT(DISTINCT CASE WHEN q.difficulty = 'easy' THEN q.id END) as easy_count,
        COUNT(DISTINCT CASE WHEN q.difficulty = 'medium' THEN q.id END) as medium_count,
        COUNT(DISTINCT CASE WHEN q.difficulty = 'hard' THEN q.id END) as hard_count
    FROM subjects s
    LEFT JOIN quizzes q ON s.id = q.subject_id AND q.is_active = 1
    GROUP BY s.id
    ORDER BY s.order_index
");
$subjects = $stmt->fetchAll();

// Check if user just logged out
$justLoggedOut = isset($_GET['logout']) && $_GET['logout'] === 'success';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?> - تعلم بطريقة ممتعة!</title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="منصة تعليمية تفاعلية للطلاب من جميع المراحل. اختبارات ممتعة، مسابقات، وجوائز!">
    <meta property="og:title" content="<?= e($siteName) ?>">
    <meta property="og:description" content="انضم لآلاف الطلاب في رحلة تعليمية ممتعة">
    <meta property="og:image" content="<?= BASE_URL ?>/assets/images/og-image.png">

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
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

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .hover-scale {
            transition: all 0.3s ease;
        }

        .hover-scale:hover {
            transform: scale(1.05);
        }

        .subject-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .subject-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .activity-feed {
            animation: scroll-up 20s linear infinite;
        }

        @keyframes scroll-up {
            0% {
                transform: translateY(0);
            }

            100% {
                transform: translateY(-100%);
            }
        }

        .activity-feed:hover {
            animation-play-state: paused;
        }

        .floating-pin {
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        .number-counter {
            animation: count-up 2s ease-out;
        }

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

        /* Animated background shapes */
        .shape {
            position: absolute;
            opacity: 0.1;
            animation: float-shape 20s infinite ease-in-out;
        }

        @keyframes float-shape {

            0%,
            100% {
                transform: translate(0, 0) rotate(0deg);
            }

            33% {
                transform: translate(30px, -30px) rotate(120deg);
            }

            66% {
                transform: translate(-20px, 20px) rotate(240deg);
            }
        }

        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            .dark-mode-toggle {
                display: block;
            }
        }
    </style>
</head>

<body class="bg-gray-50" x-data="homepage()">
    <!-- Toast Notification -->
    <?php if ($justLoggedOut): ?>
        <div class="toast toast-top toast-center z-50">
            <div class="alert alert-success animate__animated animate__bounceIn">
                <i class="fas fa-check-circle"></i>
                <span>تم تسجيل الخروج بنجاح!</span>
            </div>
        </div>
        <script>setTimeout(() => document.querySelector('.toast').remove(), 3000)</script>
    <?php endif; ?>

    <!-- Floating PIN Entry Widget -->
    <div class="fixed bottom-8 left-8 z-40 floating-pin" x-show="!hidePinWidget">
        <div class="card bg-white shadow-2xl animate__animated animate__bounceIn animate__delay-2s">
            <div class="card-body p-4">
                <button @click="hidePinWidget = true" class="btn btn-ghost btn-xs absolute top-1 right-1">
                    <i class="fas fa-times"></i>
                </button>
                <h3 class="font-bold text-sm mb-2">لديك رمز اختبار؟</h3>
                <form action="quiz/join.php" method="POST" class="flex gap-2">
                    <input type="text" name="pin_code" placeholder="000000" maxlength="6" pattern="[0-9]{6}" required
                        class="input input-bordered input-sm w-32 text-center tracking-widest"
                        @input="$el.value = $el.value.replace(/\D/g, '')">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-play"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Hero Section with Enhanced Background -->
    <div class="gradient-bg min-h-screen relative overflow-hidden">
        <!-- Animated Background Shapes -->
        <div class="absolute inset-0 overflow-hidden">
            <div class="shape w-64 h-64 bg-white/10 rounded-full top-10 left-10">
                <i
                    class="fas fa-brain text-8xl text-white/20 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2"></i>
            </div>
            <div class="shape w-48 h-48 bg-white/10 rounded-full bottom-20 right-20">
                <i
                    class="fas fa-graduation-cap text-6xl text-white/20 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2"></i>
            </div>
            <div class="shape w-32 h-32 bg-white/10 rounded-full top-40 right-10">
                <i
                    class="fas fa-lightbulb text-4xl text-white/20 absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2"></i>
            </div>
        </div>

        <!-- Navigation -->
        <nav class="relative z-10 p-6">
            <div class="container mx-auto flex justify-between items-center">
                <div class="text-white text-2xl font-bold flex items-center gap-3">
                    <i
                        class="fas fa-graduation-cap text-3xl animate__animated animate__rubberBand animate__delay-1s"></i>
                    <span><?= e($siteName) ?></span>
                </div>
                <div class="flex gap-4 items-center">
                    <!-- Live Students Counter -->
                    <div class="text-white/90 text-sm hidden md:flex items-center gap-2">
                        <span class="relative flex h-3 w-3">
                            <span
                                class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500"></span>
                        </span>
                        <span><?= formatArabicNumber($stats['active_students_today']) ?> طالب نشط الآن</span>
                    </div>

                    <a href="admin/login.php" class="btn btn-ghost text-white hover:bg-white/20">
                        <i class="fas fa-user-shield ml-2"></i>
                        <span class="hidden sm:inline">دخول المدير</span>
                    </a>

                    <?php if (isLoggedIn()): ?>
                        <?php if (hasRole('student')): ?>
                                                    <a href="student/" class="btn btn-primary bg-white text-purple-700 hover:bg-gray-100 border-0">
                                                        <i class="fas fa-home ml-2"></i>
                                                        لوحة التحكم
                                                    </a>
                                    <?php elseif (hasRole('teacher')): ?>
                                                    <a href="teacher/" class="btn btn-primary bg-white text-purple-700 hover:bg-gray-100 border-0">
                                                        <i class="fas fa-chalkboard-teacher ml-2"></i>
                                                        لوحة المعلم
                                                    </a>
                                    <?php endif; ?>
                    <?php else: ?>
                                    <a href="auth/login.php" class="btn btn-primary bg-white text-purple-700 hover:bg-gray-100 border-0">
                                        <i class="fas fa-sign-in-alt ml-2"></i>
                                        دخول
                                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <!-- Hero Content -->
        <div class="relative z-10 container mx-auto px-6 pt-10 pb-20">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Left Content -->
                <div class="text-white">
                    <h1 class="text-5xl md:text-7xl font-bold mb-6 animate__animated animate__fadeInRight">
                        تعلم بطريقة 
                        <span class="text-yellow-300">ممتعة!</span>
                    </h1>
                    <p class="text-xl md:text-2xl mb-8 opacity-90 animate__animated animate__fadeInRight animate__delay-1s">
                        اختبارات تفاعلية، مسابقات مثيرة، وجوائز رائعة!
                    </p>
                    
                    <!-- Quick Stats -->
                    <div class="grid grid-cols-3 gap-4 mb-8 animate__animated animate__fadeInUp animate__delay-2s">
                        <div class="text-center">
                            <div class="text-3xl font-bold number-counter"><?= formatArabicNumber($stats['total_quizzes']) ?></div>
                            <div class="text-sm opacity-75">اختبار متاح</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold number-counter"><?= formatArabicNumber($stats['active_students_today']) ?></div>
                            <div class="text-sm opacity-75">طالب اليوم</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold number-counter"><?= formatArabicNumber($stats['total_attempts_today']) ?></div>
                            <div class="text-sm opacity-75">اختبار تم حله</div>
                        </div>
                    </div>

                    <!-- CTA Buttons -->
                    <div class="flex flex-wrap gap-4 animate__animated animate__fadeInUp animate__delay-3s">
                        <?php if (!isLoggedIn()): ?>
                                        <a href="auth/register.php" class="btn btn-warning btn-lg hover-scale">
                                            <i class="fas fa-rocket ml-2"></i>
                                            ابدأ مجاناً
                                        </a>
                        <?php endif; ?>
                        <button @click="showDemoQuiz = true" class="btn btn-outline btn-white btn-lg hover-scale">
                            <i class="fas fa-play ml-2"></i>
                            جرب اختبار تجريبي
                        </button>
                    </div>
                </div>

                <!-- Right Content - Live Activity Feed -->
                <div class="hidden lg:block">
                    <div class="bg-white/10 backdrop-blur-md rounded-2xl p-6 shadow-2xl animate__animated animate__fadeInLeft animate__delay-1s">
                        <h3 class="text-white text-xl font-bold mb-4 flex items-center gap-2">
                            <span class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                            </span>
                            نشاط مباشر
                        </h3>
                        <div class="h-96 overflow-hidden relative">
                            <div class="activity-feed space-y-3">
                                <?php foreach ($recentActivity as $activity): ?>
                                                <div class="bg-white/20 backdrop-blur rounded-lg p-3 text-white">
                                                    <div class="flex items-center justify-between">
                                                        <span class="font-medium"><?= e($activity['student_name']) ?></span>
                                                        <span class="text-xs opacity-75"><?= timeAgo($activity['completed_at']) ?></span>
                                                    </div>
                                                    <div class="text-sm mt-1">
                                                        حصل على <span class="font-bold text-yellow-300"><?= round($activity['score']) ?>%</span>
                                                        في <?= e($activity['quiz_title']) ?>
                                                        <?php if ($activity['score'] >= 90): ?>
                                                                        <i class="fas fa-trophy text-yellow-300 mr-1"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                <?php endforeach; ?>
                                <!-- Duplicate for continuous scroll -->
                                <?php foreach ($recentActivity as $activity): ?>
                                                <div class="bg-white/20 backdrop-blur rounded-lg p-3 text-white">
                                                    <div class="flex items-center justify-between">
                                                        <span class="font-medium"><?= e($activity['student_name']) ?></span>
                                                        <span class="text-xs opacity-75"><?= timeAgo($activity['completed_at']) ?></span>
                                                    </div>
                                                    <div class="text-sm mt-1">
                                                        حصل على <span class="font-bold text-yellow-300"><?= round($activity['score']) ?>%</span>
                                                        في <?= e($activity['quiz_title']) ?>
                                                    </div>
                                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 text-white animate-bounce">
            <i class="fas fa-chevron-down text-2xl"></i>
        </div>
    </div>

    <!-- Subject Quick Launch Section -->
    <section class="py-16 bg-white" id="subjects">
        <div class="container mx-auto px-6">
            <div class="text-center mb-12">
                <h2 class="text-4xl font-bold mb-4 animate__animated animate__fadeInUp">
                    اختر المادة وابدأ التحدي!
                </h2>
                <p class="text-xl text-gray-600 animate__animated animate__fadeInUp animate__delay-1s">
                    اختبارات متنوعة في جميع المواد الدراسية
                </p>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                <?php foreach ($subjects as $index => $subject): ?>
                                <div class="subject-card" @click="browseSubject(<?= $subject['id'] ?>)" 
                                     style="animation-delay: <?= $index * 0.1 ?>s">
                                    <div class="card bg-base-100 shadow-xl hover:shadow-2xl">
                                        <div class="card-body text-center">
                                            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gradient-to-br 
                                            from-<?= $subject['color'] ?>-400 to-<?= $subject['color'] ?>-600 
                                            flex items-center justify-center">
                                                <i class="<?= $subject['icon'] ?> text-white text-3xl"></i>
                                            </div>
                                            <h3 class="card-title justify-center text-lg"><?= e($subject['name_ar']) ?></h3>
                                            <div class="text-sm text-gray-600 mt-2">
                                                <div><?= formatArabicNumber($subject['quiz_count']) ?> اختبار</div>
                                            </div>
                                            <?php if ($subject['quiz_count'] > 0): ?>
                                                            <div class="flex justify-center gap-1 mt-2">
                                                                <span class="badge badge-success badge-sm"><?= $subject['easy_count'] ?> سهل</span>
                                                                <span class="badge badge-warning badge-sm"><?= $subject['medium_count'] ?> متوسط</span>
                                                                <span class="badge badge-error badge-sm"><?= $subject['hard_count'] ?> صعب</span>
                                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Top Students Leaderboard -->
    <section class="py-16 bg-gradient-to-br from-purple-50 to-pink-50">
        <div class="container mx-auto px-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- Leaderboard -->
                <div class="order-2 lg:order-1">
                    <div class="card bg-white shadow-2xl">
                        <div class="card-body">
                            <h3 class="text-2xl font-bold mb-6 flex items-center gap-3">
                                <i class="fas fa-trophy text-yellow-500 text-3xl"></i>
                                أبطال اليوم
                            </h3>
                            
                            <?php if (empty($topStudents)): ?>
                                            <div class="text-center py-8 text-gray-500">
                                                <i class="fas fa-crown text-6xl mb-4 opacity-20"></i>
                                                <p>كن أول الأبطال اليوم!</p>
                                            </div>
                            <?php else: ?>
                                            <div class="space-y-4">
                                                <?php foreach ($topStudents as $index => $student): ?>
                                                                <div class="flex items-center gap-4 p-4 rounded-lg hover:bg-gray-50 transition-colors
                                                    <?= $index === 0 ? 'bg-gradient-to-r from-yellow-50 to-yellow-100' : '' ?>">
                                                                    <div class="text-3xl font-bold <?= $index === 0 ? 'text-yellow-600' : 'text-gray-400' ?>">
                                                                        <?= $index + 1 ?>
                                                                    </div>
                                                                    <div class="avatar placeholder">
                                                                        <div class="bg-neutral-focus text-neutral-content rounded-full w-12">
                                                                            <span><?= mb_substr($student['name'], 0, 1) ?></span>
                                                                        </div>
                                                                    </div>
                                                                    <div class="flex-1">
                                                                        <div class="font-bold"><?= e($student['name']) ?></div>
                                                                        <div class="text-sm text-gray-600">
                                                                            <?= formatArabicNumber($student['quizzes_completed']) ?> اختبار
                                                                        </div>
                                                                    </div>
                                                                    <div class="text-left">
                                                                        <div class="text-2xl font-bold text-primary">
                                                                            <?= formatArabicNumber($student['points_today']) ?>
                                                                        </div>
                                                                        <div class="text-xs text-gray-600">نقطة</div>
                                                                    </div>
                                                                    <?php if ($index === 0): ?>
                                                                                    <i class="fas fa-crown text-yellow-500 text-2xl animate__animated animate__bounce animate__infinite"></i>
                                                                    <?php endif; ?>
                                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                            <?php endif; ?>
                            
                            <div class="card-actions justify-center mt-6">
                                <a href="auth/register.php" class="btn btn-primary">
                                    <i class="fas fa-medal ml-2"></i>
                                    انضم للمنافسة
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Call to Action -->
                <div class="order-1 lg:order-2 text-center lg:text-right">
                    <h2 class="text-4xl font-bold mb-6">
                        تنافس مع أصدقائك
                        <span class="text-primary">واربح الجوائز!</span>
                    </h2>
                    <p class="text-xl text-gray-600 mb-8">
                        اجمع النقاط، احصل على الشارات، وكن في قمة قائمة الأبطال!
                    </p>
                    <div class="flex flex-wrap gap-4 justify-center lg:justify-start">
                        <div class="stat bg-white rounded-xl shadow">
                            <div class="stat-figure text-primary">
                                <i class="fas fa-fire text-3xl"></i>
                            </div>
                            <div class="stat-value">7</div>
                            <div class="stat-title">أيام متتالية</div>
                        </div>
                        <div class="stat bg-white rounded-xl shadow">
                            <div class="stat-figure text-secondary">
                                <i class="fas fa-star text-3xl"></i>
                            </div>
                            <div class="stat-value">15</div>
                            <div class="stat-title">شارة مميزة</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-16 bg-white">
        <div class="container mx-auto px-6">
            <h2 class="text-4xl font-bold text-center mb-12">كيف تبدأ؟</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="text-center group">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-purple-100 flex items-center justify-center
                                group-hover:bg-purple-600 group-hover:text-white transition-all duration-300">
                        <span class="text-3xl font-bold">1</span>
                    </div>
                    <h3 class="text-xl font-bold mb-2">سجل حسابك</h3>
                    <p class="text-gray-600">أنشئ حساب مجاني في ثوانٍ</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center
                                group-hover:bg-blue-600 group-hover:text-white transition-all duration-300">
                        <span class="text-3xl font-bold">2</span>
                    </div>
                    <h3 class="text-xl font-bold mb-2">اختر المادة</h3>
                    <p class="text-gray-600">تصفح المواد واختر ما يناسبك</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-green-100 flex items-center justify-center
                                group-hover:bg-green-600 group-hover:text-white transition-all duration-300">
                        <span class="text-3xl font-bold">3</span>
                    </div>
                    <h3 class="text-xl font-bold mb-2">ابدأ الاختبار</h3>
                    <p class="text-gray-600">أجب على الأسئلة بسرعة ودقة</p>
                </div>
                
                <div class="text-center group">
                    <div class="w-24 h-24 mx-auto mb-4 rounded-full bg-yellow-100 flex items-center justify-center
                                group-hover:bg-yellow-600 group-hover:text-white transition-all duration-300">
                        <span class="text-3xl font-bold">4</span>
                    </div>
                    <h3 class="text-xl font-bold mb-2">احصل على النقاط</h3>
                    <p class="text-gray-600">اربح نقاط وشارات مميزة</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Grid -->
    <section class="py-16 bg-gray-100">
        <div class="container mx-auto px-6">
            <h2 class="text-4xl font-bold text-center mb-12">لماذا نحن مختلفون؟</h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-500 to-pink-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-gamepad text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">تعلم كاللعب</h3>
                        <p>نحول الدراسة إلى لعبة ممتعة مع نقاط وجوائز</p>
                    </div>
                </div>
                
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-blue-500 to-cyan-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-bolt text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">نتائج فورية</h3>
                        <p>احصل على نتيجتك وتقييمك فور انتهاء الاختبار</p>
                    </div>
                </div>
                
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-green-500 to-teal-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-chart-line text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">تتبع التقدم</h3>
                        <p>شاهد تحسنك يوماً بعد يوم مع إحصائيات مفصلة</p>
                    </div>
                </div>
                
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-orange-500 to-red-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-users text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">تحديات جماعية</h3>
                        <p>تنافس مع زملائك في اختبارات مباشرة</p>
                    </div>
                </div>
                
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-indigo-500 to-purple-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-mobile-alt text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">متاح دائماً</h3>
                        <p>ادرس من أي جهاز وفي أي وقت</p>
                    </div>
                </div>
                
                <div class="card bg-white shadow-xl hover-scale">
                    <div class="card-body">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-pink-500 to-rose-500 
                                    flex items-center justify-center mb-4">
                            <i class="fas fa-shield-alt text-white text-2xl"></i>
                        </div>
                        <h3 class="card-title">آمن وموثوق</h3>
                        <p>بياناتك محمية ونتائجك خاصة بك</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 bg-gradient-to-r from-purple-600 to-pink-600 text-white">
        <div class="container mx-auto px-6 text-center">
            <h2 class="text-4xl md:text-5xl font-bold mb-6">جاهز لبدء رحلة التعلم الممتعة؟</h2>
            <p class="text-xl mb-8 opacity-90">انضم لآلاف الطلاب الذين يتعلمون بطريقة مختلفة</p>
            <div class="flex flex-wrap gap-4 justify-center">
                <a href="auth/register.php" class="btn btn-warning btn-lg hover-scale">
                    <i class="fas fa-rocket ml-2"></i>
                    ابدأ الآن مجاناً
                </a>
                <a href="auth/login.php" class="btn btn-outline btn-white btn-lg hover-scale">
                    <i class="fas fa-sign-in-alt ml-2"></i>
                    لدي حساب بالفعل
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer footer-center p-10 bg-base-200 text-base-content">
        <div>
            <div class="grid grid-flow-col gap-4">
                <a href="#" class="link link-hover">عن المنصة</a>
                <a href="#" class="link link-hover">اتصل بنا</a>
                <a href="#" class="link link-hover">الشروط والأحكام</a>
                <a href="#" class="link link-hover">سياسة الخصوصية</a>
            </div>
        </div>
        <div>
            <div class="grid grid-flow-col gap-4">
                <a href="#" class="hover-scale">
                    <i class="fab fa-twitter text-2xl"></i>
                </a>
                <a href="#" class="hover-scale">
                    <i class="fab fa-youtube text-2xl"></i>
                </a>
                <a href="#" class="hover-scale">
                    <i class="fab fa-facebook text-2xl"></i>
                </a>
            </div>
        </div>
        <div>
            <p>حقوق النشر © <?= date('Y') ?> - جميع الحقوق محفوظة</p>
            <p class="text-sm mt-1">صنع بـ ❤️ للطلاب العرب</p>
        </div>
    </footer>

    <!-- Demo Quiz Modal -->
    <dialog id="demoQuizModal" class="modal" x-show="showDemoQuiz" @close="showDemoQuiz = false">
        <div class="modal-box max-w-2xl">
            <h3 class="font-bold text-lg mb-4">اختبار تجريبي سريع</h3>
            
            <div x-show="!demoCompleted">
                <!-- Question 1 -->
                <div x-show="currentDemoQuestion === 0">
                    <p class="text-lg mb-4">س1: ما هو ناتج 8 × 7؟</p>
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="checkDemoAnswer(0, 54)" class="btn btn-outline">54</button>
                        <button @click="checkDemoAnswer(0, 56)" class="btn btn-outline">56</button>
                        <button @click="checkDemoAnswer(0, 58)" class="btn btn-outline">58</button>
                        <button @click="checkDemoAnswer(0, 60)" class="btn btn-outline">60</button>
                    </div>
                </div>
                
                <!-- Question 2 -->
                <div x-show="currentDemoQuestion === 1">
                    <p class="text-lg mb-4">س2: ما هي عاصمة المملكة العربية السعودية؟</p>
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="checkDemoAnswer(1, 'جدة')" class="btn btn-outline">جدة</button>
                        <button @click="checkDemoAnswer(1, 'الرياض')" class="btn btn-outline">الرياض</button>
                        <button @click="checkDemoAnswer(1, 'مكة')" class="btn btn-outline">مكة</button>
                        <button @click="checkDemoAnswer(1, 'المدينة')" class="btn btn-outline">المدينة</button>
                    </div>
                </div>
                
                <!-- Question 3 -->
                <div x-show="currentDemoQuestion === 2">
                    <p class="text-lg mb-4">س3: أي من التالي من الثدييات؟</p>
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="checkDemoAnswer(2, 'النسر')" class="btn btn-outline">النسر</button>
                        <button @click="checkDemoAnswer(2, 'السمكة')" class="btn btn-outline">السمكة</button>
                        <button @click="checkDemoAnswer(2, 'الدولفين')" class="btn btn-outline">الدولفين</button>
                        <button @click="checkDemoAnswer(2, 'العقرب')" class="btn btn-outline">العقرب</button>
                    </div>
                </div>
                
                <!-- Progress -->
                <div class="mt-6">
                    <progress class="progress progress-primary" :value="currentDemoQuestion + 1" max="3"></progress>
                </div>
            </div>
            
            <!-- Results -->
            <div x-show="demoCompleted" class="text-center py-8">
                <i class="fas fa-trophy text-6xl text-yellow-500 mb-4"></i>
                <h3 class="text-2xl font-bold mb-2">أحسنت!</h3>
                <p class="text-lg mb-4">حصلت على <span x-text="demoScore"></span> من 3</p>
                <div class="flex gap-3 justify-center">
                    <a href="auth/register.php" class="btn btn-primary">
                        <i class="fas fa-rocket ml-2"></i>
                        سجل الآن لمزيد من الاختبارات
                    </a>
                    <button class="btn btn-ghost" onclick="demoQuizModal.close()">إغلاق</button>
                </div>
            </div>
            
            <div class="modal-action" x-show="!demoCompleted">
                <button class="btn" onclick="demoQuizModal.close()">إلغاء</button>
            </div>
        </div>
    </dialog>

    <script>
        function homepage() {
            return {
                hidePinWidget: false,
                showDemoQuiz: false,
                currentDemoQuestion: 0,
                demoScore: 0,
                demoCompleted: false,
                
                init() {
                    // Show confetti on high scores
                    <?php foreach ($recentActivity as $activity): ?>
                                    <?php if ($activity['score'] >= 95): ?>
                                                    setTimeout(() => {
                                                        confetti({
                                                            particleCount: 50,
                                                            spread: 70,
                                                            origin: { y: 0.6 }
                                                        });
                                                    }, Math.random() * 5000);
                                    <?php endif; ?>
                    <?php endforeach; ?>
                },
                
                browseSubject(subjectId) {
                    window.location.href = 'browse/subject.php?id=' + subjectId;
                },
                
                checkDemoAnswer(questionIndex, answer) {
                    const correctAnswers = [56, 'الرياض', 'الدولفين'];
                    
                    if (answer === correctAnswers[questionIndex]) {
                        this.demoScore++;
                        confetti({
                            particleCount: 30,
                            spread: 50,
                            origin: { y: 0.7 }
                        });
                    }
                    
                    if (this.currentDemoQuestion < 2) {
                        this.currentDemoQuestion++;
                    } else {
                        this.demoCompleted = true;
                        if (this.demoScore === 3) {
                            confetti({
                                particleCount: 100,
                                spread: 70,
                                origin: { y: 0.6 }
                            });
                        }
                    }
                }
            }
        }
        
        // Auto-focus PIN input when typing numbers
        document.addEventListener('keypress', function(e) {
            if (e.key >= '0' && e.key <= '9' && !e.target.matches('input')) {
                const pinInput = document.querySelector('input[name="pin_code"]');
                if (pinInput) {
                    pinInput.focus();
                    pinInput.value = e.key;
                }
            }
        });
    </script>
</body>
</html>