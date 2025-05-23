<?php
require_once 'config/database.php';

$siteName = getSetting('site_name', 'منصة الاختبارات التفاعلية');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?></title>

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

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .hover-float {
            transition: transform 0.3s ease;
        }

        .hover-float:hover {
            transform: translateY(-5px);
        }

        .blob {
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            animation: blob 8s infinite;
        }

        @keyframes blob {

            0%,
            100% {
                border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            }

            25% {
                border-radius: 70% 30% 30% 70% / 70% 70% 30% 30%;
            }

            50% {
                border-radius: 30% 70% 70% 30% / 70% 30% 30% 70%;
            }

            75% {
                border-radius: 70% 30% 30% 70% / 30% 70% 70% 30%;
            }
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Hero Section -->
    <div class="gradient-bg min-h-screen relative overflow-hidden">
        <!-- Animated Background Elements -->
        <div class="absolute inset-0">
            <div
                class="absolute top-20 left-10 w-72 h-72 bg-purple-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 blob">
            </div>
            <div
                class="absolute top-40 right-10 w-72 h-72 bg-yellow-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 blob animation-delay-2000">
            </div>
            <div
                class="absolute -bottom-8 left-20 w-72 h-72 bg-pink-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 blob animation-delay-4000">
            </div>
        </div>

        <!-- Navigation -->
        <nav class="relative z-10 p-6">
            <div class="container mx-auto flex justify-between items-center">
                <div class="text-white text-2xl font-bold">
                    <i class="fas fa-graduation-cap ml-2"></i>
                    <?= e($siteName) ?>
                </div>
                <div class="flex gap-4">
                    <a href="admin/login.php" class="btn btn-ghost text-white hover:bg-white/20">
                        <i class="fas fa-user-shield ml-2"></i>
                        دخول المدير
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
                        <a href="auth/login.php"
                            class="btn btn-primary bg-white text-purple-700 hover:bg-gray-100 border-0">
                            <i class="fas fa-sign-in-alt ml-2"></i>
                            تسجيل الدخول
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <!-- Hero Content -->
        <div class="relative z-10 container mx-auto px-6 pt-20 pb-32">
            <div class="text-center text-white">
                <h1 class="text-5xl md:text-7xl font-bold mb-6 animate__animated animate__fadeInDown">
                    تعلم بطريقة ممتعة!
                </h1>
                <p class="text-xl md:text-2xl mb-12 opacity-90 animate__animated animate__fadeInUp">
                    اختبارات تفاعلية لجميع المراحل الدراسية
                </p>

                <!-- Quick Join -->
                <div class="max-w-md mx-auto animate__animated animate__fadeInUp animate__delay-1s">
                    <div class="bg-white/20 backdrop-blur-md rounded-2xl p-8 shadow-xl">
                        <h3 class="text-2xl font-bold mb-4">انضم باستخدام رمز الاختبار</h3>
                        <form action="quiz/join.php" method="POST" class="space-y-4">
                            <input type="text" name="pin_code" placeholder="أدخل رمز الاختبار" maxlength="6"
                                class="input input-lg w-full text-center text-gray-800 text-2xl tracking-widest"
                                pattern="[0-9]{6}" required>
                            <button type="submit"
                                class="btn btn-primary btn-lg w-full bg-purple-600 hover:bg-purple-700 border-0">
                                <i class="fas fa-play ml-2"></i>
                                ابدأ الاختبار
                            </button>
                        </form>

                        <div class="divider">أو</div>

                        <?php if (!isLoggedIn()): ?>
                            <a href="auth/register.php" class="btn btn-outline btn-white w-full">
                                <i class="fas fa-user-plus ml-2"></i>
                                إنشاء حساب جديد
                            </a>
                        <?php else: ?>
                            <form action="quiz/join.php" method="POST">
                                <input type="hidden" name="practice_mode" value="1">
                                <button type="submit" class="btn btn-outline btn-white w-full">
                                    <i class="fas fa-graduation-cap ml-2"></i>
                                    وضع التدريب
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scroll Indicator -->
        <div class="absolute bottom-8 left-1/2 transform -translate-x-1/2 text-white animate-bounce">
            <i class="fas fa-chevron-down text-2xl"></i>
        </div>
    </div>

    <!-- Features Section -->
    <section class="py-20" x-data="{ activeTab: 'students' }">
        <div class="container mx-auto px-6">
            <h2 class="text-4xl font-bold text-center mb-16 text-gray-800">
                منصة تعليمية متكاملة
            </h2>

            <!-- Tab Navigation -->
            <div class="flex justify-center mb-12">
                <div class="btn-group">
                    <button @click="activeTab = 'students'" :class="activeTab === 'students' ? 'btn-active' : ''"
                        class="btn btn-lg">
                        <i class="fas fa-user-graduate ml-2"></i>
                        للطلاب
                    </button>
                    <button @click="activeTab = 'teachers'" :class="activeTab === 'teachers' ? 'btn-active' : ''"
                        class="btn btn-lg">
                        <i class="fas fa-chalkboard-teacher ml-2"></i>
                        للمعلمين
                    </button>
                    <button @click="activeTab = 'schools'" :class="activeTab === 'schools' ? 'btn-active' : ''"
                        class="btn btn-lg">
                        <i class="fas fa-school ml-2"></i>
                        للمدارس
                    </button>
                </div>
            </div>

            <!-- Tab Content -->
            <div class="max-w-6xl mx-auto">
                <!-- Students Tab -->
                <div x-show="activeTab === 'students'" x-transition>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-purple-600 mb-4">
                                    <i class="fas fa-gamepad"></i>
                                </div>
                                <h3 class="card-title justify-center">تعلم ممتع</h3>
                                <p>اختبارات تفاعلية مع رسوم متحركة وجوائز</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-green-600 mb-4">
                                    <i class="fas fa-trophy"></i>
                                </div>
                                <h3 class="card-title justify-center">إنجازات وشارات</h3>
                                <p>اجمع النقاط واحصل على شارات مميزة</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-blue-600 mb-4">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h3 class="card-title justify-center">تتبع التقدم</h3>
                                <p>شاهد تحسنك مع الوقت وتنافس مع زملائك</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Teachers Tab -->
                <div x-show="activeTab === 'teachers'" x-transition>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-orange-600 mb-4">
                                    <i class="fas fa-magic"></i>
                                </div>
                                <h3 class="card-title justify-center">إنشاء سريع</h3>
                                <p>أنشئ اختبارات في دقائق بواجهة سهلة</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-red-600 mb-4">
                                    <i class="fas fa-chart-pie"></i>
                                </div>
                                <h3 class="card-title justify-center">تقارير مفصلة</h3>
                                <p>احصل على تحليلات دقيقة لأداء طلابك</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-indigo-600 mb-4">
                                    <i class="fas fa-share-alt"></i>
                                </div>
                                <h3 class="card-title justify-center">مشاركة سهلة</h3>
                                <p>شارك الاختبارات برمز بسيط من 6 أرقام</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schools Tab -->
                <div x-show="activeTab === 'schools'" x-transition>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-teal-600 mb-4">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3 class="card-title justify-center">إدارة مركزية</h3>
                                <p>أدر جميع المعلمين والطلاب من مكان واحد</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-pink-600 mb-4">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <h3 class="card-title justify-center">آمن وموثوق</h3>
                                <p>حماية كاملة للبيانات وخصوصية الطلاب</p>
                            </div>
                        </div>

                        <div class="card bg-base-100 shadow-xl hover-float">
                            <div class="card-body text-center">
                                <div class="text-5xl text-gray-600 mb-4">
                                    <i class="fas fa-cogs"></i>
                                </div>
                                <h3 class="card-title justify-center">تخصيص كامل</h3>
                                <p>خصص المنصة بشعار وألوان مدرستك</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Stats Section -->
    <section class="py-20 bg-gray-100">
        <div class="container mx-auto px-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
                <div class="stat">
                    <div class="stat-value text-primary">12</div>
                    <div class="stat-title">مرحلة دراسية</div>
                </div>
                <div class="stat">
                    <div class="stat-value text-secondary">7</div>
                    <div class="stat-title">مواد دراسية</div>
                </div>
                <div class="stat">
                    <div class="stat-value text-accent">∞</div>
                    <div class="stat-title">اختبارات</div>
                </div>
                <div class="stat">
                    <div class="stat-value text-info">100%</div>
                    <div class="stat-title">مجاني</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer footer-center p-10 bg-base-200 text-base-content">
        <div>
            <div class="text-4xl mb-4">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <p class="font-bold">
                <?= e($siteName) ?>
            </p>
            <p>حقوق النشر © <?= date('Y') ?> - جميع الحقوق محفوظة</p>
        </div>
    </footer>
</body>

</html>