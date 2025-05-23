<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$error = '';
$pin = $_GET['pin'] ?? $_POST['pin_code'] ?? '';

// If PIN is provided, validate it
if ($pin) {
    $stmt = $pdo->prepare("
        SELECT q.*, u.name as teacher_name, s.name_ar as subject_name 
        FROM quizzes q
        JOIN users u ON q.teacher_id = u.id
        LEFT JOIN subjects s ON q.subject_id = s.id
        WHERE q.pin_code = ? AND q.is_active = 1
    ");
    $stmt->execute([$pin]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        $error = 'رمز الاختبار غير صحيح أو غير مفعل';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_quiz']) && $quiz) {
    // Store participant info in session
    $_SESSION['quiz_participant'] = [
        'quiz_id' => $quiz['id'],
        'name' => sanitize($_POST['participant_name'] ?? 'مجهول'),
        'is_guest' => !isLoggedIn(),
        'user_id' => $_SESSION['user_id'] ?? null,
        'started_at' => time()
    ];

    // Create attempt record
    $stmt = $pdo->prepare("
        INSERT INTO attempts (quiz_id, user_id, guest_name, started_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([
        $quiz['id'],
        $_SESSION['user_id'] ?? null,
        !isLoggedIn() ? $_SESSION['quiz_participant']['name'] : null
    ]);

    $_SESSION['attempt_id'] = $pdo->lastInsertId();

    // Redirect to play page
    redirect('/quiz/play.php?id=' . $quiz['id']);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الانضمام للاختبار - <?= e(getSetting('site_name')) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Animate.css -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .pin-input {
            letter-spacing: 0.5em;
            text-align: center;
            font-size: 2rem;
            font-weight: bold;
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
    </style>
</head>

<body class="flex items-center justify-center p-4">
    <!-- Background Blobs -->
    <div class="blob w-96 h-96 bg-purple-400 top-0 left-0"></div>
    <div class="blob w-96 h-96 bg-pink-400 bottom-0 right-0"></div>

    <div class="relative z-10 w-full max-w-lg">
        <?php if (!$quiz): ?>
            <!-- PIN Entry Form -->
            <div class="card bg-white shadow-2xl animate__animated animate__bounceIn">
                <div class="card-body text-center">
                    <div class="mb-6">
                        <div
                            class="w-24 h-24 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full mx-auto flex items-center justify-center mb-4">
                            <i class="fas fa-gamepad text-white text-4xl"></i>
                        </div>
                        <h1 class="text-3xl font-bold text-gray-800">انضم للاختبار</h1>
                        <p class="text-gray-600 mt-2">أدخل رمز الاختبار المكون من 6 أرقام</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-error mb-4 animate__animated animate__shakeX">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= e($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" x-data="{ pin: '<?= e($pin) ?>' }">
                        <input type="text" name="pin_code" x-model="pin" maxlength="6" pattern="[0-9]{6}"
                            placeholder="000000" class="input input-bordered input-lg w-full pin-input mb-6" autofocus
                            required>

                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="pin.length !== 6">
                            <i class="fas fa-arrow-left ml-2"></i>
                            متابعة
                        </button>
                    </form>

                    <div class="divider">أو</div>

                    <div class="space-y-3">
                        <?php if (!isLoggedIn()): ?>
                            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline btn-primary w-full">
                                <i class="fas fa-user ml-2"></i>
                                تسجيل الدخول
                            </a>
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>/student/" class="btn btn-outline btn-primary w-full">
                                <i class="fas fa-home ml-2"></i>
                                لوحة التحكم
                            </a>
                        <?php endif; ?>

                        <a href="<?= BASE_URL ?>" class="btn btn-ghost w-full">
                            <i class="fas fa-arrow-right ml-2"></i>
                            الصفحة الرئيسية
                        </a>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Quiz Details -->
            <div class="card bg-white shadow-2xl animate__animated animate__fadeIn">
                <div class="card-body">
                    <!-- Quiz Header -->
                    <div class="text-center mb-6">
                        <div
                            class="w-20 h-20 bg-gradient-to-br from-<?= getGradeColor($quiz['grade']) ?>-400 to-<?= getGradeColor($quiz['grade']) ?>-600 rounded-full mx-auto flex items-center justify-center mb-4">
                            <i class="fas fa-clipboard-list text-white text-3xl"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800"><?= e($quiz['title']) ?></h2>
                        <p class="text-gray-600 mt-2"><?= e($quiz['description']) ?></p>
                    </div>

                    <!-- Quiz Info -->
                    <div class="bg-gray-50 rounded-lg p-4 mb-6">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="text-center">
                                <i class="fas fa-user-tie text-gray-400 text-2xl mb-1"></i>
                                <p class="text-sm text-gray-600">المعلم</p>
                                <p class="font-medium"><?= e($quiz['teacher_name']) ?></p>
                            </div>
                            <div class="text-center">
                                <i class="fas fa-book text-gray-400 text-2xl mb-1"></i>
                                <p class="text-sm text-gray-600">المادة</p>
                                <p class="font-medium"><?= e($quiz['subject_name'] ?? 'عام') ?></p>
                            </div>
                            <div class="text-center">
                                <i class="fas fa-graduation-cap text-gray-400 text-2xl mb-1"></i>
                                <p class="text-sm text-gray-600">المرحلة</p>
                                <p class="font-medium"><?= e(getGradeName($quiz['grade'])) ?></p>
                            </div>
                            <div class="text-center">
                                <i class="fas fa-tachometer-alt text-gray-400 text-2xl mb-1"></i>
                                <p class="text-sm text-gray-600">الصعوبة</p>
                                <p class="font-medium">
                                    <?php
                                    $difficulties = [
                                        'easy' => 'سهل',
                                        'medium' => 'متوسط',
                                        'hard' => 'صعب',
                                        'mixed' => 'متنوع'
                                    ];
                                    echo $difficulties[$quiz['difficulty']] ?? $quiz['difficulty'];
                                    ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($quiz['time_limit'] > 0): ?>
                            <div class="mt-4 text-center border-t pt-4">
                                <i class="fas fa-clock text-orange-500 ml-2"></i>
                                <span class="text-gray-700">الوقت المحدد: <strong><?= $quiz['time_limit'] ?>
                                        دقيقة</strong></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Start Form -->
                    <form method="POST">
                        <input type="hidden" name="pin_code" value="<?= e($pin) ?>">
                        <input type="hidden" name="start_quiz" value="1">

                        <?php if (!isLoggedIn()): ?>
                            <div class="form-control mb-4">
                                <label class="label">
                                    <span class="label-text">اسمك</span>
                                </label>
                                <input type="text" name="participant_name" placeholder="أدخل اسمك هنا"
                                    class="input input-bordered" required>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-info-circle"></i>
                                <span>مرحباً <?= e($_SESSION['user_name']) ?>، أنت جاهز للبدء!</span>
                            </div>
                        <?php endif; ?>

                        <div class="flex gap-3">
                            <button type="submit" class="btn btn-primary flex-1">
                                <i class="fas fa-play ml-2"></i>
                                ابدأ الاختبار
                            </button>

                            <?php if ($quiz['is_practice']): ?>
                                <button type="button" class="btn btn-info flex-1">
                                    <i class="fas fa-graduation-cap ml-2"></i>
                                    وضع التدريب
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="text-center mt-4">
                        <a href="<?= BASE_URL ?>/quiz/join.php" class="text-gray-600 hover:text-gray-800">
                            <i class="fas fa-arrow-right ml-2"></i>
                            إدخال رمز آخر
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-submit when 6 digits are entered
        document.querySelector('.pin-input')?.addEventListener('input', function (e) {
            if (e.target.value.length === 6) {
                e.target.form.submit();
            }
        });
    </script>
</body>

</html>