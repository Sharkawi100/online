<?php
// quiz/join.php - Improved Quiz Join Page
require_once '../config/database.php';
require_once '../includes/functions.php';

// Initialize variables
$error = '';
$quiz = null;
$pin = $_GET['pin'] ?? $_POST['pin'] ?? '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = sanitize($_POST['pin'] ?? '');
    $guest_name = sanitize($_POST['guest_name'] ?? '');

    if (empty($pin)) {
        $error = 'يرجى إدخال رمز الاختبار';
    } elseif (strlen($pin) !== 6 || !ctype_digit($pin)) {
        $error = 'رمز الاختبار يجب أن يكون 6 أرقام';
    } else {
        // Get quiz by PIN
        $stmt = $pdo->prepare("
            SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon,
                   u.name as teacher_name,
                   (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count
            FROM quizzes q
            LEFT JOIN subjects s ON q.subject_id = s.id
            LEFT JOIN users u ON q.teacher_id = u.id
            WHERE q.pin_code = ? AND q.is_active = 1
        ");
        $stmt->execute([$pin]);
        $quiz = $stmt->fetch();

        if (!$quiz) {
            $error = 'رمز الاختبار غير صحيح أو الاختبار غير متاح';
        } else {
            // Check if user already has an active attempt
            $user_id = $_SESSION['user_id'] ?? null;

            if ($user_id) {
                $stmt = $pdo->prepare("
                    SELECT id FROM attempts 
                    WHERE quiz_id = ? AND user_id = ? AND completed_at IS NULL
                ");
                $stmt->execute([$quiz['id'], $user_id]);
                $existing_attempt = $stmt->fetch();

                if ($existing_attempt) {
                    // Resume existing attempt
                    $_SESSION['attempt_id'] = $existing_attempt['id'];
                    redirect('/quiz/play.php');
                    exit;
                }
            }

            // Start new attempt
            try {
                if ($user_id) {
                    // Logged in user
                    $stmt = $pdo->prepare("
                        INSERT INTO attempts (quiz_id, user_id, started_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $stmt->execute([$quiz['id'], $user_id]);
                } else {
                    // Guest user
                    if (empty($guest_name)) {
                        $error = 'يرجى إدخال اسمك';
                        goto display;
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO attempts (quiz_id, guest_name, started_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $stmt->execute([$quiz['id'], $guest_name]);

                    // Store guest info in session
                    $_SESSION['guest_name'] = $guest_name;
                }

                $attempt_id = $pdo->lastInsertId();
                $_SESSION['attempt_id'] = $attempt_id;
                $_SESSION['quiz_id'] = $quiz['id'];

                // Redirect to play page
                redirect('/quiz/play.php');
                exit;

            } catch (Exception $e) {
                $error = 'حدث خطأ. يرجى المحاولة مرة أخرى';
                error_log("Quiz join error: " . $e->getMessage());
            }
        }
    }
} elseif (!empty($pin)) {
    // GET request with PIN - load quiz info
    $stmt = $pdo->prepare("
        SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon,
               u.name as teacher_name,
               (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count
        FROM quizzes q
        LEFT JOIN subjects s ON q.subject_id = s.id
        LEFT JOIN users u ON q.teacher_id = u.id
        WHERE q.pin_code = ? AND q.is_active = 1
    ");
    $stmt->execute([$pin]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        $error = 'رمز الاختبار غير صحيح أو الاختبار غير متاح';
    }
}

display:
$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الانضمام للاختبار - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>

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

        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .pin-input {
            font-size: 2rem;
            letter-spacing: 0.5rem;
            text-align: center;
            font-weight: bold;
        }

        .float-animation {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px);
            }

            50% {
                transform: translateY(-20px);
            }
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(40px);
            opacity: 0.6;
            animation: blob 8s infinite;
        }

        @keyframes blob {

            0%,
            100% {
                transform: translate(0px, 0px) scale(1);
            }

            33% {
                transform: translate(30px, -50px) scale(1.1);
            }

            66% {
                transform: translate(-20px, 20px) scale(0.9);
            }
        }
    </style>
</head>

<body class="flex items-center justify-center p-4" x-data="quizJoin">
    <!-- Background decoration -->
    <div class="blob w-64 h-64 bg-purple-400 top-10 left-10"></div>
    <div class="blob w-96 h-96 bg-pink-400 bottom-10 right-10"></div>

    <div class="relative z-10 w-full max-w-md">
        <!-- Logo/Header -->
        <div class="text-center mb-8 animate__animated animate__fadeInDown">
            <div
                class="inline-flex items-center justify-center w-24 h-24 glass rounded-full shadow-2xl mb-4 float-animation">
                <i class="fas fa-graduation-cap text-4xl text-purple-600"></i>
            </div>
            <h1 class="text-4xl font-bold text-white mb-2"><?= e(getSetting('site_name', 'منصة الاختبارات')) ?></h1>
            <p class="text-white/80">انضم للاختبار وابدأ التحدي!</p>
        </div>

        <!-- Main Card -->
        <div class="glass rounded-3xl shadow-2xl p-8 animate__animated animate__fadeInUp">
            <?php if ($error): ?>
                <div class="alert alert-error mb-6 animate__animated animate__shakeX">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$quiz): ?>
                <!-- PIN Entry Form -->
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                    <div class="text-center">
                        <h2 class="text-2xl font-bold mb-2">أدخل رمز الاختبار</h2>
                        <p class="text-gray-600">احصل على الرمز من معلمك</p>
                    </div>

                    <div class="form-control">
                        <input type="text" name="pin" maxlength="6" pattern="[0-9]{6}" inputmode="numeric"
                            class="input input-bordered input-lg w-full pin-input" placeholder="000000"
                            value="<?= e($pin) ?>" @input="formatPIN" required autofocus>
                    </div>

                    <?php if (!isLoggedIn()): ?>
                        <div class="form-control" x-show="showNameField" x-transition>
                            <label class="label">
                                <span class="label-text">اسمك</span>
                            </label>
                            <input type="text" name="guest_name" class="input input-bordered" placeholder="أدخل اسمك الكامل"
                                x-bind:required="showNameField">
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary btn-lg w-full">
                        <i class="fas fa-play ml-2"></i>
                        دخول الاختبار
                    </button>
                </form>

                <div class="divider my-6">أو</div>

                <?php if (!isLoggedIn()): ?>
                    <div class="text-center">
                        <p class="text-sm text-gray-600 mb-3">لحفظ نتائجك وتتبع تقدمك</p>
                        <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline btn-sm">
                            <i class="fas fa-sign-in-alt ml-2"></i>
                            تسجيل الدخول
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center">
                        <p class="text-sm text-gray-600">
                            مرحباً <strong><?= e($_SESSION['user_name']) ?></strong>
                        </p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- Quiz Info Display -->
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="pin" value="<?= e($pin) ?>">

                    <div class="text-center mb-6">
                        <div class="inline-flex items-center justify-center w-16 h-16 bg-primary/10 rounded-full mb-4">
                            <i class="<?= e($quiz['subject_icon'] ?? 'fas fa-book') ?> text-2xl text-primary"></i>
                        </div>
                        <h2 class="text-2xl font-bold mb-2"><?= e($quiz['title']) ?></h2>
                        <p class="text-gray-600"><?= e($quiz['subject_name']) ?> - <?= getGradeName($quiz['grade']) ?></p>
                    </div>

                    <!-- Quiz Details -->
                    <div class="bg-base-200 rounded-xl p-4 space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">
                                <i class="fas fa-user-tie ml-2"></i>
                                المعلم
                            </span>
                            <span class="font-semibold"><?= e($quiz['teacher_name']) ?></span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">
                                <i class="fas fa-question-circle ml-2"></i>
                                عدد الأسئلة
                            </span>
                            <span class="font-semibold"><?= $quiz['question_count'] ?> سؤال</span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">
                                <i class="fas fa-clock ml-2"></i>
                                المدة الزمنية
                            </span>
                            <span class="font-semibold">
                                <?= $quiz['time_limit'] > 0 ? $quiz['time_limit'] . ' دقيقة' : 'غير محدد' ?>
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">
                                <i class="fas fa-layer-group ml-2"></i>
                                مستوى الصعوبة
                            </span>
                            <span class="badge badge-primary">
                                <?= ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب', 'mixed' => 'متنوع'][$quiz['difficulty']] ?>
                            </span>
                        </div>

                        <?php if ($quiz['is_practice']): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <span>وضع التدريب: ستظهر الإجابات الصحيحة مباشرة</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!isLoggedIn()): ?>
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">اسمك *</span>
                            </label>
                            <input type="text" name="guest_name" class="input input-bordered" placeholder="أدخل اسمك الكامل"
                                required>
                        </div>
                    <?php endif; ?>

                    <!-- Rules -->
                    <div class="bg-warning/10 border border-warning/20 rounded-lg p-4">
                        <h3 class="font-bold mb-2 flex items-center">
                            <i class="fas fa-exclamation-triangle text-warning ml-2"></i>
                            تعليمات مهمة
                        </h3>
                        <ul class="text-sm space-y-1 mr-6">
                            <li>• بمجرد البدء، لا يمكنك إيقاف الاختبار</li>
                            <li>• تأكد من استقرار اتصالك بالإنترنت</li>
                            <li>• اقرأ كل سؤال بعناية قبل الإجابة</li>
                            <?php if ($quiz['time_limit'] > 0): ?>
                                <li>• سيبدأ العد التنازلي فور البدء</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="flex gap-3">
                        <a href="<?= BASE_URL ?>/quiz/join.php" class="btn btn-ghost flex-1">
                            <i class="fas fa-arrow-right ml-2"></i>
                            رجوع
                        </a>
                        <button type="submit" class="btn btn-primary flex-1">
                            <i class="fas fa-play ml-2"></i>
                            ابدأ الاختبار
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-6">
            <a href="<?= BASE_URL ?>" class="link link-hover text-white/70">
                <i class="fas fa-home ml-2"></i>
                الصفحة الرئيسية
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('quizJoin', () => ({
                showNameField: <?= !isLoggedIn() && strlen($pin) === 6 ? 'true' : 'false' ?>,

                formatPIN(e) {
                    // Remove non-digits
                    e.target.value = e.target.value.replace(/\D/g, '');

                    // Show name field when PIN is complete
                    if (e.target.value.length === 6 && !<?= isLoggedIn() ? 'true' : 'false' ?>) {
                        this.showNameField = true;
                    }
                }
            }));
        });
    </script>
</body>

</html>