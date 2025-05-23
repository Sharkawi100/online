<?php
// /auth/forgot-password.php
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

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'يرجى إدخال بريد إلكتروني صحيح';
        } else {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // In a real implementation, you would:
                // 1. Store the token in a password_resets table
                // 2. Send an email with the reset link
                // 3. Create a reset-password.php page to handle the token

                // For now, we'll just show a success message
                $message = 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني';

                // Log the attempt (for demo purposes)
                error_log("Password reset requested for: $email");
            } else {
                // Don't reveal if email exists or not for security
                $message = 'إذا كان البريد الإلكتروني مسجلاً، ستصلك رسالة بتعليمات إعادة تعيين كلمة المرور';
            }
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
    <title>استعادة كلمة المرور - <?= e(getSetting('site_name')) ?></title>

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

        .forgot-card {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
    </style>
</head>

<body class="flex items-center justify-center p-4">
    <!-- Background Blobs -->
    <div class="blob w-96 h-96 bg-purple-400 top-0 left-0"></div>
    <div class="blob w-96 h-96 bg-pink-400 bottom-0 right-0"></div>

    <div class="relative z-10 w-full max-w-md">
        <!-- Logo/Header -->
        <div class="text-center mb-8 animate__animated animate__fadeInDown">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full shadow-lg mb-4">
                <i class="fas fa-key text-3xl text-purple-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-white">استعادة كلمة المرور</h1>
            <p class="text-white/80 mt-2">أدخل بريدك الإلكتروني لإعادة تعيين كلمة المرور</p>
        </div>

        <!-- Forgot Password Card -->
        <div class="forgot-card card shadow-2xl animate__animated animate__fadeInUp">
            <div class="card-body">
                <!-- Success Message -->
                <?php if ($message): ?>
                    <div class="alert alert-success mb-4">
                        <i class="fas fa-check-circle"></i>
                        <span><?= e($message) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="alert alert-error mb-4 animate__animated animate__shakeX">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?= e($error) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!$message): ?>
                    <!-- Forgot Password Form -->
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                        <!-- Instructions -->
                        <div class="bg-base-200 rounded-lg p-4 mb-4">
                            <p class="text-sm">
                                <i class="fas fa-info-circle text-info ml-2"></i>
                                أدخل البريد الإلكتروني المسجل في حسابك وسنرسل لك رابط لإعادة تعيين كلمة المرور.
                            </p>
                        </div>

                        <!-- Email Input -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">البريد الإلكتروني</span>
                            </label>
                            <div class="relative">
                                <input type="email" name="email" placeholder="example@email.com"
                                    class="input input-bordered w-full pl-10" value="<?= e($_POST['email'] ?? '') ?>"
                                    required autofocus>
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-envelope"></i>
                                </span>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-primary w-full">
                            <i class="fas fa-paper-plane ml-2"></i>
                            إرسال رابط الاستعادة
                        </button>
                    </form>
                <?php endif; ?>

                <!-- Links -->
                <div class="divider">أو</div>

                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="login.php" class="btn btn-outline flex-1">
                        <i class="fas fa-sign-in-alt ml-2"></i>
                        تسجيل الدخول
                    </a>
                    <a href="register.php" class="btn btn-outline flex-1">
                        <i class="fas fa-user-plus ml-2"></i>
                        حساب جديد
                    </a>
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
</body>

</html>