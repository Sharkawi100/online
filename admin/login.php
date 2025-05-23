<?php
require_once '../config/database.php';

// If already logged in as admin, redirect to dashboard
if (isLoggedIn() && hasRole('admin')) {
    redirect('/admin/');
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        // Get user from database
        $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? AND role = 'admin' AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role'] = $user['role'];

            redirect('/admin/');
        } else {
            $error = 'بيانات الدخول غير صحيحة';
        }
    } else {
        $error = 'يرجى إدخال البريد الإلكتروني وكلمة المرور';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دخول المدير - <?= e(getSetting('site_name')) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .gradient-bg {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
    </style>
</head>

<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <!-- Background Pattern -->
    <div class="absolute inset-0 opacity-10">
        <div
            class="absolute top-0 -left-4 w-72 h-72 bg-white rounded-full mix-blend-multiply filter blur-3xl animate-pulse">
        </div>
        <div
            class="absolute top-0 -right-4 w-72 h-72 bg-yellow-300 rounded-full mix-blend-multiply filter blur-3xl animate-pulse animation-delay-2000">
        </div>
        <div
            class="absolute -bottom-8 left-20 w-72 h-72 bg-pink-300 rounded-full mix-blend-multiply filter blur-3xl animate-pulse animation-delay-4000">
        </div>
    </div>

    <!-- Login Card -->
    <div class="relative z-10 w-full max-w-md">
        <div class="glass-effect rounded-2xl shadow-2xl p-8 animate__animated animate__fadeInUp">
            <!-- Logo -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full shadow-lg mb-4">
                    <i class="fas fa-user-shield text-3xl text-blue-600"></i>
                </div>
                <h1 class="text-2xl font-bold text-white">لوحة تحكم المدير</h1>
                <p class="text-white/80 mt-2">يرجى تسجيل الدخول للمتابعة</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="alert alert-error mb-6 animate__animated animate__shakeX">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" class="space-y-6">
                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-white">البريد الإلكتروني</span>
                    </label>
                    <div class="relative">
                        <input type="email" name="email" placeholder="admin@example.com"
                            class="input input-bordered w-full pl-10" value="sharkawi@quiz.com" required>
                        <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <div class="form-control">
                    <label class="label">
                        <span class="label-text text-white">كلمة المرور</span>
                    </label>
                    <div class="relative">
                        <input type="password" name="password" placeholder="••••••••"
                            class="input input-bordered w-full pl-10" required>
                        <i class="fas fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <i class="fas fa-sign-in-alt ml-2"></i>
                    تسجيل الدخول
                </button>
            </form>

            <!-- Back Link -->
            <div class="text-center mt-6">
                <a href="<?= BASE_URL ?>" class="text-white/80 hover:text-white transition-colors">
                    <i class="fas fa-arrow-right ml-2"></i>
                    العودة للصفحة الرئيسية
                </a>
            </div>
        </div>

        <!-- Security Note -->
        <div class="text-center mt-6 text-white/60 text-sm">
            <i class="fas fa-shield-alt ml-2"></i>
            هذه الصفحة محمية وللمدراء فقط
        </div>
    </div>
</body>

</html>