<?php
// /auth/login.php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (hasRole('admin')) {
        redirect('/admin/');
    } elseif (hasRole('teacher')) {
        redirect('/teacher/');
    } elseif (hasRole('student')) {
        redirect('/student/');
    }
}

$error = '';
$success = $_GET['success'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || empty($password)) {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    } else {
        // Get user by email
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            // Remember me cookie
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/');

                // Store token in database (you might need to add a remember_tokens table)
                // For now, we'll skip this part
            }

            // Redirect based on role
            if ($user['role'] === 'admin') {
                redirect('/admin/');
            } elseif ($user['role'] === 'teacher') {
                redirect('/teacher/');
            } elseif ($user['role'] === 'student') {
                redirect('/student/');
            }
        } else {
            $error = 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
        }
    }
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .blob {
            position: absolute;
            border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%;
            filter: blur(40px);
            opacity: 0.7;
            animation: blob 15s infinite;
        }

        @keyframes blob {

            0%,
            100% {
                transform: translate(0, 0) scale(1);
            }

            33% {
                transform: translate(30px, -50px) scale(1.1);
            }

            66% {
                transform: translate(-20px, 20px) scale(0.9);
            }
        }

        .login-card {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
    </style>
</head>

<body class="flex items-center justify-center p-4">
    <!-- Background Blobs -->
    <div class="blob w-96 h-96 bg-purple-400 top-0 left-0"></div>
    <div class="blob w-96 h-96 bg-pink-400 bottom-0 right-0"></div>

    <div class="relative z-10 w-full max-w-md" x-data="{ showPassword: false }">
        <!-- Logo/Header -->
        <div class="text-center mb-8 animate__animated animate__fadeInDown">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full shadow-lg mb-4">
                <i class="fas fa-graduation-cap text-3xl text-purple-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-white"><?= e(getSetting('site_name')) ?></h1>
            <p class="text-white/80 mt-2">سجل دخولك للمتابعة</p>
        </div>

        <!-- Login Card -->
        <div class="login-card card shadow-2xl animate__animated animate__fadeInUp">
            <div class="card-body">
                <!-- Success Message -->
                <?php if ($success === 'registered'): ?>
                    <div class="alert alert-success mb-4">
                        <i class="fas fa-check-circle"></i>
                        <span>تم إنشاء حسابك بنجاح! يمكنك الآن تسجيل الدخول.</span>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="alert alert-error mb-4 animate__animated animate__shakeX">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <!-- Email Input -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">البريد الإلكتروني</span>
                        </label>
                        <div class="relative">
                            <input type="email" name="email" placeholder="example@email.com"
                                class="input input-bordered w-full pl-10" value="<?= e($_POST['email'] ?? '') ?>"
                                required>
                            <span class="absolute left-3 top-3.5 text-gray-400">
                                <i class="fas fa-envelope"></i>
                            </span>
                        </div>
                    </div>

                    <!-- Password Input -->
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">كلمة المرور</span>
                            <a href="forgot-password.php" class="label-text-alt link link-primary">
                                نسيت كلمة المرور؟
                            </a>
                        </label>
                        <div class="relative">
                            <input :type="showPassword ? 'text' : 'password'" name="password" placeholder="••••••••"
                                class="input input-bordered w-full pl-10 pr-10" required>
                            <span class="absolute left-3 top-3.5 text-gray-400">
                                <i class="fas fa-lock"></i>
                            </span>
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute right-3 top-3.5 text-gray-400 hover:text-gray-600">
                                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me -->
                    <div class="form-control">
                        <label class="label cursor-pointer">
                            <span class="label-text">تذكرني</span>
                            <input type="checkbox" name="remember" class="checkbox checkbox-primary">
                        </label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-primary w-full">
                        <i class="fas fa-sign-in-alt ml-2"></i>
                        تسجيل الدخول
                    </button>
                </form>

                <!-- Divider -->
                <div class="divider">أو</div>

                <!-- Alternative Actions -->
                <div class="space-y-3">
                    <!-- Quick Quiz Access -->
                    <button onclick="quickQuizModal.showModal()" class="btn btn-outline w-full">
                        <i class="fas fa-gamepad ml-2"></i>
                        دخول سريع للاختبار
                    </button>

                    <!-- Register Link -->
                    <div class="text-center">
                        <span class="text-sm">ليس لديك حساب؟</span>
                        <a href="register.php" class="link link-primary font-bold">
                            سجل الآن
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="text-center mt-6">
            <a href="<?= BASE_URL ?>" class="link link-hover text-white">
                <i class="fas fa-arrow-right ml-2"></i>
                العودة للصفحة الرئيسية
            </a>
        </div>
    </div>

    <!-- Quick Quiz Modal -->
    <dialog id="quickQuizModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg">دخول سريع للاختبار</h3>
            <p class="py-4">أدخل رمز الاختبار للدخول بدون تسجيل</p>

            <form method="GET" action="<?= BASE_URL ?>/quiz/join.php">
                <input type="text" name="pin" placeholder="رمز الاختبار (6 أرقام)" maxlength="6" pattern="[0-9]{6}"
                    class="input input-bordered w-full text-center text-2xl tracking-widest mb-4" required>
                <div class="modal-action">
                    <button type="button" class="btn" onclick="quickQuizModal.close()">إلغاء</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play ml-2"></i>
                        دخول الاختبار
                    </button>
                </div>
            </form>
        </div>
    </dialog>
</body>

</html>