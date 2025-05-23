<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$attemptId = $_GET['attempt_id'] ?? $_SESSION['attempt_id'] ?? 0;

// Get attempt details
$stmt = $pdo->prepare("
    SELECT a.*, q.title as quiz_title, q.grade, q.time_limit, q.language,
           q.subject_id, s.name_ar as subject_name, s.icon as subject_icon,
           COALESCE(u.name, a.guest_name) as participant_name,
           u.id as user_id
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.id = ?
");
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();

if (!$attempt || !$attempt['completed_at']) {
    redirect('/');
}

// Get questions and answers
$stmt = $pdo->prepare("
    SELECT q.*, ans.option_id as selected_option, ans.is_correct, ans.points_earned
    FROM questions q
    LEFT JOIN answers ans ON q.id = ans.question_id AND ans.attempt_id = ?
    WHERE q.quiz_id = ?
    ORDER BY q.order_index, q.id
");
$stmt->execute([$attemptId, $attempt['quiz_id']]);
$questions = $stmt->fetchAll();

// Get total questions answered
$answeredCount = 0;
$correctCount = 0;
foreach ($questions as $question) {
    if ($question['selected_option']) {
        $answeredCount++;
        if ($question['is_correct']) {
            $correctCount++;
        }
    }
}

// Get user's other attempts for comparison
$previousAttempts = [];
if ($attempt['user_id']) {
    $stmt = $pdo->prepare("
        SELECT score, completed_at 
        FROM attempts 
        WHERE user_id = ? AND quiz_id = ? AND id != ? AND completed_at IS NOT NULL
        ORDER BY completed_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$attempt['user_id'], $attempt['quiz_id'], $attemptId]);
    $previousAttempts = $stmt->fetchAll();
}

// Check for new achievements
$newAchievements = [];
if ($attempt['user_id']) {
    // This would be populated by checkAchievements() in real implementation
}

// Calculate grade color
$gradeColor = getGradeColor($attempt['grade']);
?>
<!DOCTYPE html>
<html lang="<?= $attempt['language'] ?>" dir="<?= $attempt['language'] == 'ar' ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتائج الاختبار - <?= e(getSetting('site_name')) ?></title>

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

    <!-- Confetti -->
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .score-circle {
            width: 200px;
            height: 200px;
            position: relative;
        }

        .score-circle svg {
            transform: rotate(-90deg);
        }

        .score-circle circle {
            stroke-dasharray: 565.48;
            stroke-dashoffset: 565.48;
            animation: fillCircle 2s ease-out forwards;
        }

        @keyframes fillCircle {
            to {
                stroke-dashoffset:
                    <?= 565.48 * (1 - $attempt['score'] / 100) ?>
                ;
            }
        }

        .star {
            animation: sparkle 1.5s ease-in-out infinite;
        }

        @keyframes sparkle {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: 0.5;
                transform: scale(0.8);
            }
        }
    </style>
</head>

<body class="flex items-center justify-center p-4">
    <div class="container mx-auto max-w-4xl">
        <!-- Results Card -->
        <div class="card bg-white shadow-2xl animate__animated animate__zoomIn">
            <div class="card-body p-8">
                <!-- Header -->
                <div class="text-center mb-8">
                    <h1 class="text-3xl font-bold mb-2">نتائج الاختبار</h1>
                    <h2 class="text-xl text-gray-600"><?= e($attempt['quiz_title']) ?></h2>
                    <p class="text-gray-500 mt-2">
                        <i class="fas fa-user ml-2"></i>
                        <?= e($attempt['participant_name']) ?>
                    </p>
                </div>

                <!-- Score Display -->
                <div class="flex justify-center mb-8">
                    <div class="score-circle relative">
                        <svg class="w-full h-full">
                            <circle cx="100" cy="100" r="90" stroke="#e5e7eb" stroke-width="20" fill="none" />
                            <circle cx="100" cy="100" r="90"
                                stroke="<?= $attempt['score'] >= 80 ? '#10b981' : ($attempt['score'] >= 60 ? '#f59e0b' : '#ef4444') ?>"
                                stroke-width="20" fill="none" class="transition-all duration-2000" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div class="text-5xl font-bold"><?= round($attempt['score']) ?>%</div>
                            <div class="text-gray-600">النتيجة</div>

                            <!-- Stars -->
                            <div class="flex mt-2">
                                <?php
                                $stars = $attempt['score'] >= 90 ? 3 : ($attempt['score'] >= 70 ? 2 : ($attempt['score'] >= 50 ? 1 : 0));
                                for ($i = 0; $i < 3; $i++):
                                    ?>
                                    <i
                                        class="fas fa-star text-2xl mx-1 <?= $i < $stars ? 'text-yellow-400 star' : 'text-gray-300' ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    <div class="stat bg-base-100 rounded-lg shadow text-center">
                        <div class="stat-title">الإجابات الصحيحة</div>
                        <div class="stat-value text-success"><?= $correctCount ?></div>
                        <div class="stat-desc">من <?= count($questions) ?></div>
                    </div>

                    <div class="stat bg-base-100 rounded-lg shadow text-center">
                        <div class="stat-title">النقاط المكتسبة</div>
                        <div class="stat-value text-primary"><?= $attempt['total_points'] ?></div>
                        <div class="stat-desc">نقطة</div>
                    </div>

                    <div class="stat bg-base-100 rounded-lg shadow text-center">
                        <div class="stat-title">الوقت المستغرق</div>
                        <div class="stat-value text-info"><?= gmdate("i:s", $attempt['time_taken']) ?></div>
                        <div class="stat-desc">دقيقة</div>
                    </div>

                    <div class="stat bg-base-100 rounded-lg shadow text-center">
                        <div class="stat-title">معدل الإجابة</div>
                        <div class="stat-value text-warning"><?= round($attempt['time_taken'] / count($questions)) ?>
                        </div>
                        <div class="stat-desc">ثانية/سؤال</div>
                    </div>
                </div>

                <!-- Performance Message -->
                <div
                    class="alert <?= $attempt['score'] >= 80 ? 'alert-success' : ($attempt['score'] >= 60 ? 'alert-warning' : 'alert-error') ?> shadow-lg mb-8">
                    <div>
                        <i
                            class="fas <?= $attempt['score'] >= 80 ? 'fa-trophy' : ($attempt['score'] >= 60 ? 'fa-thumbs-up' : 'fa-redo') ?> text-2xl"></i>
                        <div>
                            <h3 class="font-bold">
                                <?php
                                if ($attempt['score'] >= 90)
                                    echo "أداء ممتاز! 🌟";
                                elseif ($attempt['score'] >= 80)
                                    echo "أداء رائع! 👏";
                                elseif ($attempt['score'] >= 70)
                                    echo "أداء جيد! 👍";
                                elseif ($attempt['score'] >= 60)
                                    echo "أداء مقبول";
                                else
                                    echo "يمكنك التحسن!";
                                ?>
                            </h3>
                            <div class="text-sm">
                                <?php
                                if ($attempt['score'] >= 90)
                                    echo "أنت متميز! استمر في هذا المستوى الرائع.";
                                elseif ($attempt['score'] >= 80)
                                    echo "عمل رائع! أنت على الطريق الصحيح.";
                                elseif ($attempt['score'] >= 70)
                                    echo "أداء جيد، مع المزيد من التدريب ستصل للقمة.";
                                elseif ($attempt['score'] >= 60)
                                    echo "نجحت في الاختبار، حاول مراجعة الأخطاء.";
                                else
                                    echo "لا تيأس، التدريب يصنع الكمال. حاول مرة أخرى!";
                                ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Previous Attempts (if any) -->
                <?php if (!empty($previousAttempts)): ?>
                    <div class="mb-8">
                        <h3 class="text-lg font-bold mb-4">محاولاتك السابقة</h3>
                        <div class="flex gap-2">
                            <?php foreach ($previousAttempts as $prev): ?>
                                <div
                                    class="badge badge-lg <?= $prev['score'] >= 80 ? 'badge-success' : ($prev['score'] >= 60 ? 'badge-warning' : 'badge-error') ?>">
                                    <?= round($prev['score']) ?>%
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="flex flex-wrap gap-3 justify-center">
                    <?php if ($attempt['user_id']): ?>
                        <a href="<?= BASE_URL ?>/student/" class="btn btn-primary">
                            <i class="fas fa-home ml-2"></i>
                            لوحة التحكم
                        </a>
                    <?php endif; ?>

                    <button class="btn btn-success" onclick="shareResults()">
                        <i class="fas fa-share-alt ml-2"></i>
                        مشاركة النتيجة
                    </button>

                    <a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $attempt['pin_code'] ?? '' ?>" class="btn btn-info">
                        <i class="fas fa-redo ml-2"></i>
                        إعادة المحاولة
                    </a>

                    <a href="<?= BASE_URL ?>" class="btn btn-ghost">
                        <i class="fas fa-arrow-right ml-2"></i>
                        الصفحة الرئيسية
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Confetti for good scores
        <?php if ($attempt['score'] >= 80): ?>
            window.addEventListener('load', () => {
                confetti({
                    particleCount: 100,
                    spread: 70,
                    origin: { y: 0.6 }
                });
            });
        <?php endif; ?>

        // Share functionality
        function shareResults() {
            const text = `حصلت على ${<?= round($attempt['score']) ?>}% في اختبار "${<?= json_encode($attempt['quiz_title']) ?>}" على ${<?= json_encode(getSetting('site_name')) ?>}! 🎉`;

            if (navigator.share) {
                navigator.share({
                    title: 'نتائج الاختبار',
                    text: text,
                    url: window.location.href
                });
            } else {
                // Fallback to copy to clipboard
                navigator.clipboard.writeText(text + '\n' + window.location.href);
                alert('تم نسخ الرابط!');
            }
        }
    </script>
</body>

</html>