<?php
// /auth/register.php
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
$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        // Validate inputs
        $name = sanitize($_POST['name'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $role = $_POST['role'] ?? 'student';
        $grade = (int)($_POST['grade'] ?? 0);
        $school = sanitize($_POST['school'] ?? '');
        
        // Validation
        if (empty($name)) {
            $errors[] = 'يرجى إدخال الاسم';
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'يرجى إدخال بريد إلكتروني صحيح';
        }
        
        if (strlen($password) < 6) {
            $errors[] = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
        }
        
        if ($password !== $password_confirm) {
            $errors[] = 'كلمتا المرور غير متطابقتين';
        }
        
        if ($role === 'student' && ($grade < 1 || $grade > 12)) {
            $errors[] = 'يرجى اختيار الصف الدراسي';
        }
        
        // Check if email already exists
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'البريد الإلكتروني مسجل مسبقاً';
            }
        }
        
        // Create account if no errors
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user
                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password, role, grade, school, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                
                $stmt->execute([
                    $name,
                    $email,
                    $hashed_password,
                    $role,
                    $role === 'student' ? $grade : null,
                    !empty($school) ? $school : null
                ]);
                
                $user_id = $pdo->lastInsertId();
                
                // Award welcome achievement for students
                if ($role === 'student') {
                    // Check if welcome achievement exists
                    $stmt = $pdo->prepare("SELECT id FROM achievements WHERE criteria_type = 'welcome' LIMIT 1");
                    $stmt->execute();
                    $welcome_achievement = $stmt->fetch();
                    
                    if ($welcome_achievement) {
                        awardAchievement($user_id, $welcome_achievement['id']);
                    }
                }
                
                $pdo->commit();
                
                // Redirect to login with success message
                redirect('/auth/login.php?success=registered');
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'حدث خطأ أثناء إنشاء الحساب. يرجى المحاولة مرة أخرى.';
            }
        } else {
            $error = implode('<br>', $errors);
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
    <title>إنشاء حساب جديد - <?= e(getSetting('site_name')) ?></title>
    
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
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -50px) scale(1.1); }
            66% { transform: translate(-20px, 20px) scale(0.9); }
        }
        
        .register-card {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
    </style>
</head>
<body class="flex items-center justify-center p-4">
    <!-- Background Blobs -->
    <div class="blob w-96 h-96 bg-purple-400 top-0 left-0"></div>
    <div class="blob w-96 h-96 bg-pink-400 bottom-0 right-0"></div>
    
    <div class="relative z-10 w-full max-w-2xl" x-data="registerForm()">
        <!-- Logo/Header -->
        <div class="text-center mb-8 animate__animated animate__fadeInDown">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-white rounded-full shadow-lg mb-4">
                <i class="fas fa-graduation-cap text-3xl text-purple-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-white"><?= e(getSetting('site_name')) ?></h1>
            <p class="text-white/80 mt-2">أنشئ حسابك وابدأ رحلة التعلم</p>
        </div>
        
        <!-- Registration Card -->
        <div class="register-card card shadow-2xl animate__animated animate__fadeInUp">
            <div class="card-body">
                <!-- Role Selection Tabs -->
                <div class="tabs tabs-boxed mb-6">
                    <a class="tab" :class="{ 'tab-active': accountType === 'student' }" 
                       @click="accountType = 'student'">
                        <i class="fas fa-user-graduate ml-2"></i>
                        حساب طالب
                    </a>
                    <a class="tab" :class="{ 'tab-active': accountType === 'teacher' }" 
                       @click="accountType = 'teacher'">
                        <i class="fas fa-chalkboard-teacher ml-2"></i>
                        حساب معلم
                    </a>
                </div>
                
                <!-- Error Message -->
                <?php if ($error): ?>
                    <div class="alert alert-error mb-4 animate__animated animate__shakeX">
                        <i class="fas fa-exclamation-circle"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>
                
                <!-- Registration Form -->
                <form method="POST" class="space-y-4" @submit="validateForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="role" :value="accountType">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Name Input -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الاسم الكامل *</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="name" placeholder="أدخل اسمك الكامل" 
                                       class="input input-bordered w-full pl-10" 
                                       value="<?= e($_POST['name'] ?? '') ?>" required>
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-user"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Email Input -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">البريد الإلكتروني *</span>
                            </label>
                            <div class="relative">
                                <input type="email" name="email" placeholder="example@email.com" 
                                       class="input input-bordered w-full pl-10" 
                                       value="<?= e($_POST['email'] ?? '') ?>" required>
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-envelope"></i>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Password Input -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">كلمة المرور *</span>
                                <span class="label-text-alt">6 أحرف على الأقل</span>
                            </label>
                            <div class="relative">
                                <input :type="showPassword ? 'text' : 'password'" 
                                       name="password" placeholder="••••••••" 
                                       class="input input-bordered w-full pl-10 pr-10" 
                                       x-model="password" required>
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <button type="button" @click="showPassword = !showPassword" 
                                        class="absolute right-3 top-3.5 text-gray-400 hover:text-gray-600">
                                    <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
                                </button>
                            </div>
                            <!-- Password Strength Indicator -->
                            <div class="mt-2" x-show="password.length > 0">
                                <progress class="progress" :class="passwordStrengthClass" 
                                          :value="passwordStrength" max="4"></progress>
                                <span class="text-xs" x-text="passwordStrengthText"></span>
                            </div>
                        </div>
                        
                        <!-- Confirm Password -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">تأكيد كلمة المرور *</span>
                            </label>
                            <div class="relative">
                                <input :type="showPassword ? 'text' : 'password'" 
                                       name="password_confirm" placeholder="••••••••" 
                                       class="input input-bordered w-full pl-10" 
                                       x-model="passwordConfirm" required>
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-lock"></i>
                                </span>
                            </div>
                            <div x-show="passwordConfirm.length > 0 && password !== passwordConfirm" 
                                 class="text-error text-xs mt-1">
                                كلمتا المرور غير متطابقتين
                            </div>
                        </div>
                        
                        <!-- Grade Selection (Students Only) -->
                        <div class="form-control" x-show="accountType === 'student'">
                            <label class="label">
                                <span class="label-text">الصف الدراسي *</span>
                            </label>
                            <select name="grade" class="select select-bordered" 
                                    :required="accountType === 'student'">
                                <option value="">اختر الصف</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($_POST['grade'] ?? '') == $i ? 'selected' : '' ?>>
                                        <?= getGradeName($i) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <!-- School (Optional) -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المدرسة / المؤسسة التعليمية</span>
                                <span class="label-text-alt">اختياري</span>
                            </label>
                            <div class="relative">
                                <input type="text" name="school" placeholder="اسم المدرسة" 
                                       class="input input-bordered w-full pl-10" 
                                       value="<?= e($_POST['school'] ?? '') ?>">
                                <span class="absolute left-3 top-3.5 text-gray-400">
                                    <i class="fas fa-school"></i>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Terms & Conditions -->
                    <div class="form-control">
                        <label class="label cursor-pointer justify-start">
                            <input type="checkbox" class="checkbox checkbox-primary ml-3" 
                                   x-model="acceptTerms" required>
                            <span class="label-text">
                                أوافق على 
                                <a href="#" class="link link-primary">الشروط والأحكام</a>
                                و
                                <a href="#" class="link link-primary">سياسة الخصوصية</a>
                            </span>
                        </label>
                    </div>
                    
                    <!-- Submit Button -->
                    <button type="submit" class="btn btn-primary w-full" 
                            :disabled="!acceptTerms || password !== passwordConfirm">
                        <i class="fas fa-user-plus ml-2"></i>
                        إنشاء الحساب
                    </button>
                </form>
                
                <!-- Divider -->
                <div class="divider">أو</div>
                
                <!-- Alternative Actions -->
                <div class="text-center">
                    <span class="text-sm">لديك حساب بالفعل؟</span>
                    <a href="login.php" class="link link-primary font-bold">
                        سجل دخولك
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
    
    <script>
        function registerForm() {
            return {
                accountType: 'student',
                showPassword: false,
                password: '',
                passwordConfirm: '',
                acceptTerms: false,
                
                get passwordStrength() {
                    const password = this.password;
                    let strength = 0;
                    
                    if (password.length >= 6) strength++;
                    if (password.length >= 8) strength++;
                    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
                    if (/[0-9]/.test(password) && /[^a-zA-Z0-9]/.test(password)) strength++;
                    
                    return strength;
                },
                
                get passwordStrengthClass() {
                    const strength = this.passwordStrength;
                    if (strength <= 1) return 'progress-error';
                    if (strength === 2) return 'progress-warning';
                    if (strength === 3) return 'progress-primary';
                    return 'progress-success';
                },
                
                get passwordStrengthText() {
                    const strength = this.passwordStrength;
                    if (strength === 0) return 'ضعيفة جداً';
                    if (strength === 1) return 'ضعيفة';
                    if (strength === 2) return 'متوسطة';
                    if (strength === 3) return 'قوية';
                    return 'قوية جداً';
                },
                
                validateForm(e) {
                    if (this.password !== this.passwordConfirm) {
                        e.preventDefault();
                        alert('كلمتا المرور غير متطابقتين');
                        return false;
                    }
                    
                    if (this.password.length < 6) {
                        e.preventDefault();
                        alert('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
                        return false;
                    }
                    
                    return true;
                }
            }
        }
    </script>
</body>
</html>