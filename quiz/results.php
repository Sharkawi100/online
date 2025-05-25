<?php
// quiz/results.php - Modern Professional Results Interface
require_once '../config/database.php';
require_once '../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

$attemptId = $_GET['attempt_id'] ?? $_SESSION['attempt_id'] ?? 0;

// Get attempt details
$stmt = $pdo->prepare("
    SELECT a.*, q.title as quiz_title, q.grade, q.time_limit, q.language,
           q.subject_id, q.difficulty, q.is_practice, q.show_results,
           s.name_ar as subject_name, s.icon as subject_icon, s.color as subject_color,
           COALESCE(u.name, a.guest_name) as participant_name,
           u.id as user_id, u.email as user_email
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

// Check if user can view detailed results
$can_view_details = false;
if ($attempt['user_id']) {
    // Registered user can see their own results
    if (isLoggedIn() && $_SESSION['user_id'] == $attempt['user_id']) {
        $can_view_details = true;
    }
}
// Teachers and admins can always see details
if (isLoggedIn() && (hasRole('teacher') || hasRole('admin'))) {
    $can_view_details = true;
}

// Get questions and answers
$stmt = $pdo->prepare("
    SELECT q.*, ans.option_id as selected_option_id, ans.is_correct as answer_correct, ans.points_earned,
           GROUP_CONCAT(o.id ORDER BY o.order_index) as option_ids,
           GROUP_CONCAT(o.option_text ORDER BY o.order_index SEPARATOR '|||') as option_texts,
           GROUP_CONCAT(o.is_correct ORDER BY o.order_index) as correct_flags
    FROM questions q
    LEFT JOIN answers ans ON q.id = ans.question_id AND ans.attempt_id = ?
    LEFT JOIN options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    GROUP BY q.id
    ORDER BY q.order_index, q.id
");
$stmt->execute([$attemptId, $attempt['quiz_id']]);
$questions = $stmt->fetchAll();

// Process questions data
$processedQuestions = [];
$answeredCount = 0;
$correctCount = 0;
$totalPoints = 0;
$earnedPoints = 0;

foreach ($questions as $question) {
    $options = [];
    if ($question['option_texts']) {
        $optionIds = explode(',', $question['option_ids']);
        $optionTexts = explode('|||', $question['option_texts']);
        $correctFlags = explode(',', $question['correct_flags']);

        for ($i = 0; $i < count($optionIds); $i++) {
            $options[] = [
                'id' => $optionIds[$i],
                'text' => $optionTexts[$i],
                'is_correct' => $correctFlags[$i] == '1',
                'is_selected' => $question['selected_option_id'] == $optionIds[$i]
            ];
        }
    }

    $processedQuestions[] = [
        'id' => $question['id'],
        'text' => $question['question_text'],
        'points' => $question['points'],
        'options' => $options,
        'answered' => !is_null($question['selected_option_id']),
        'correct' => $question['answer_correct'] == 1,
        'points_earned' => $question['points_earned'] ?? 0,
        'ai_generated' => $question['ai_generated']
    ];

    $totalPoints += $question['points'];
    if (!is_null($question['selected_option_id'])) {
        $answeredCount++;
        if ($question['answer_correct'] == 1) {
            $correctCount++;
            $earnedPoints += $question['points_earned'];
        }
    }
}

// Calculate percentage score
$percentageScore = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 1) : 0;

// Get user's other attempts for comparison
$previousAttempts = [];
if ($attempt['user_id']) {
    $stmt = $pdo->prepare("
        SELECT score, completed_at, time_taken
        FROM attempts 
        WHERE user_id = ? AND quiz_id = ? AND id != ? AND completed_at IS NOT NULL
        ORDER BY completed_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$attempt['user_id'], $attempt['quiz_id'], $attemptId]);
    $previousAttempts = $stmt->fetchAll();
}

// Performance analysis
$performance = [
    'level' => $percentageScore >= 90 ? 'excellent' :
        ($percentageScore >= 80 ? 'great' :
            ($percentageScore >= 70 ? 'good' :
                ($percentageScore >= 60 ? 'fair' : 'needs_improvement'))),
    'stars' => $percentageScore >= 90 ? 5 :
        ($percentageScore >= 80 ? 4 :
            ($percentageScore >= 70 ? 3 :
                ($percentageScore >= 60 ? 2 : 1))),
    'speed_rating' => $attempt['time_limit'] > 0 ?
        min(100, round(($attempt['time_limit'] * 60 - $attempt['time_taken']) / ($attempt['time_limit'] * 60) * 100)) : 100
];

// Check for achievements (if user is logged in)
$newAchievements = [];
if ($attempt['user_id'] && function_exists('checkAchievements')) {
    checkAchievements($attempt['user_id'], 'quiz_complete', [
        'score' => $percentageScore,
        'time_taken' => $attempt['time_taken'],
        'quiz_id' => $attempt['quiz_id']
    ]);
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نتائج الاختبار - <?= e($attempt['quiz_title']) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        /* Score circle animation */
        .score-circle {
            width: 250px;
            height: 250px;
            position: relative;
        }

        @media (max-width: 768px) {
            .score-circle {
                width: 200px;
                height: 200px;
            }
        }

        .score-circle svg {
            transform: rotate(-90deg);
        }

        .score-circle circle {
            fill: none;
            stroke-width: 15;
        }

        .score-circle .progress-ring {
            stroke-dasharray: 628.32;
            stroke-dashoffset: 628.32;
            transition: stroke-dashoffset 2s cubic-bezier(0.4, 0, 0.2, 1);
            stroke-linecap: round;
        }

        /* Star animation */
        @keyframes star-pop {
            0% {
                transform: scale(0) rotate(0deg);
                opacity: 0;
            }

            50% {
                transform: scale(1.2) rotate(180deg);
            }

            100% {
                transform: scale(1) rotate(360deg);
                opacity: 1;
            }
        }

        .star-animate {
            animation: star-pop 0.5s ease-out forwards;
            animation-delay: var(--delay);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(to right, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Option styles */
        .option-correct {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .option-incorrect {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .option-missed {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
        }

        /* Smooth reveal animation */
        @keyframes reveal {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .reveal {
            animation: reveal 0.6s ease-out forwards;
            animation-delay: var(--delay);
            opacity: 0;
        }

        /* Performance badge gradient */
        .performance-excellent {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .performance-great {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .performance-good {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .performance-fair {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .performance-needs_improvement {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        /* Chart container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }

        /* Print styles */
        @media print {
            body {
                background: white !important;
            }

            .no-print {
                display: none !important;
            }

            .card {
                box-shadow: none !important;
                border: 1px solid #e5e7eb !important;
            }
        }
    </style>
</head>

<body x-data="resultsApp">
    <!-- Header -->
    <div class="navbar bg-white/95 backdrop-blur-lg shadow-lg sticky top-0 z-50 no-print">
        <div class="navbar-start">
            <a href="<?= BASE_URL ?>/" class="btn btn-ghost">
                <i class="fas fa-home text-xl"></i>
                <span class="hidden sm:inline">الرئيسية</span>
            </a>
        </div>
        <div class="navbar-center">
            <h1 class="text-lg font-bold">نتائج الاختبار</h1>
        </div>
        <div class="navbar-end">
            <button onclick="window.print()" class="btn btn-ghost btn-circle" title="طباعة">
                <i class="fas fa-print text-xl"></i>
            </button>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Main Results Card -->
        <div class="card bg-white shadow-2xl mb-8 animate__animated animate__fadeIn">
            <div class="card-body p-8">
                <!-- Header Section -->
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bold mb-2"><?= e($attempt['quiz_title']) ?></h2>
                    <div class="flex flex-wrap justify-center gap-2 mb-4">
                        <span class="badge badge-lg"
                            style="background-color: <?= e($attempt['subject_color']) ?>20; color: <?= e($attempt['subject_color']) ?>">
                            <i class="<?= e($attempt['subject_icon']) ?> ml-1"></i>
                            <?= e($attempt['subject_name']) ?>
                        </span>
                        <span class="badge badge-lg badge-ghost"><?= getGradeName($attempt['grade']) ?></span>
                        <span class="badge badge-lg badge-ghost">
                            <?= ['easy' => 'سهل', 'medium' => 'متوسط', 'hard' => 'صعب', 'mixed' => 'متنوع'][$attempt['difficulty']] ?>
                        </span>
                    </div>
                    <p class="text-gray-600">
                        <i class="fas fa-user ml-2"></i>
                        <?= e($attempt['participant_name']) ?>
                        <span class="text-gray-400 mx-2">•</span>
                        <i class="fas fa-calendar ml-2"></i>
                        <?= date('Y/m/d - H:i', strtotime($attempt['completed_at'])) ?>
                    </p>
                </div>

                <!-- Score Display -->
                <div class="flex flex-col items-center mb-8">
                    <div class="score-circle mb-6">
                        <svg viewBox="0 0 200 200">
                            <circle cx="100" cy="100" r="90" stroke="#e5e7eb" stroke-width="15" />
                            <circle cx="100" cy="100" r="90" class="progress-ring"
                                :stroke="getScoreColor(<?= $percentageScore ?>)"
                                :style="`stroke-dashoffset: ${628.32 - (628.32 * <?= $percentageScore ?> / 100)}`" />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div class="text-6xl font-bold gradient-text"><?= round($percentageScore) ?>%</div>
                            <div class="text-gray-600 text-lg">النتيجة النهائية</div>
                        </div>
                    </div>

                    <!-- Stars -->
                    <div class="flex gap-2 mb-4">
                        <?php for ($i = 0; $i < 5; $i++): ?>
                            <i class="fas fa-star text-3xl <?= $i < $performance['stars'] ? 'text-yellow-400 star-animate' : 'text-gray-300' ?>"
                                style="--delay: <?= $i * 0.1 ?>s"></i>
                        <?php endfor; ?>
                    </div>

                    <!-- Performance Badge -->
                    <div class="badge badge-lg text-white performance-<?= $performance['level'] ?> px-6 py-4 text-lg">
                        <?php
                        $messages = [
                            'excellent' => 'أداء ممتاز! 🌟',
                            'great' => 'أداء رائع! 👏',
                            'good' => 'أداء جيد! 👍',
                            'fair' => 'أداء مقبول',
                            'needs_improvement' => 'يحتاج تحسين'
                        ];
                        echo $messages[$performance['level']];
                        ?>
                    </div>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                    <div class="stat bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-4">
                        <div class="stat-figure text-success">
                            <i class="fas fa-check-circle text-3xl"></i>
                        </div>
                        <div class="stat-title">إجابات صحيحة</div>
                        <div class="stat-value text-success"><?= $correctCount ?></div>
                        <div class="stat-desc">من <?= count($questions) ?> سؤال</div>
                    </div>

                    <div class="stat bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-4">
                        <div class="stat-figure text-primary">
                            <i class="fas fa-trophy text-3xl"></i>
                        </div>
                        <div class="stat-title">النقاط المكتسبة</div>
                        <div class="stat-value text-primary"><?= $earnedPoints ?></div>
                        <div class="stat-desc">من <?= $totalPoints ?> نقطة</div>
                    </div>

                    <div class="stat bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-4">
                        <div class="stat-figure text-purple-600">
                            <i class="fas fa-clock text-3xl"></i>
                        </div>
                        <div class="stat-title">الوقت المستغرق</div>
                        <div class="stat-value text-purple-600"><?= gmdate("i:s", $attempt['time_taken']) ?></div>
                        <div class="stat-desc">دقيقة</div>
                    </div>

                    <div class="stat bg-gradient-to-br from-orange-50 to-orange-100 rounded-xl p-4">
                        <div class="stat-figure text-orange-600">
                            <i class="fas fa-bolt text-3xl"></i>
                        </div>
                        <div class="stat-title">سرعة الأداء</div>
                        <div class="stat-value text-orange-600"><?= $performance['speed_rating'] ?>%</div>
                        <div class="stat-desc">معدل السرعة</div>
                    </div>
                </div>

                <!-- Previous Attempts Chart (if any) -->
                <?php if (!empty($previousAttempts)): ?>
                    <div class="mb-8 reveal" style="--delay: 0.6s">
                        <h3 class="text-xl font-bold mb-4">تطور الأداء</h3>
                        <div class="chart-container">
                            <canvas id="progressChart"></canvas>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Detailed Results (for registered users) -->
        <?php if ($can_view_details && $attempt['show_results']): ?>
            <div class="card bg-white shadow-2xl mb-8 animate__animated animate__fadeInUp">
                <div class="card-body p-8">
                    <h3 class="text-2xl font-bold mb-6">
                        <i class="fas fa-list-check text-primary ml-2"></i>
                        مراجعة تفصيلية للإجابات
                    </h3>

                    <!-- Summary Stats -->
                    <div class="bg-base-200 rounded-xl p-4 mb-6">
                        <div class="flex flex-wrap gap-4 justify-center text-sm">
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-success"></div>
                                <span>إجابة صحيحة (<?= $correctCount ?>)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-error"></div>
                                <span>إجابة خاطئة (<?= $answeredCount - $correctCount ?>)</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 rounded bg-warning"></div>
                                <span>لم تتم الإجابة (<?= count($questions) - $answeredCount ?>)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Questions Review -->
                    <div class="space-y-6">
                        <?php foreach ($processedQuestions as $index => $question): ?>
                            <div class="question-review reveal" style="--delay: <?= 0.1 * $index ?>s">
                                <div class="border-2 border-gray-200 rounded-xl p-6 hover:shadow-lg transition-shadow">
                                    <!-- Question Header -->
                                    <div class="flex justify-between items-start mb-4">
                                        <div class="flex items-center gap-3">
                                            <div
                                                class="flex items-center justify-center w-10 h-10 rounded-full font-bold text-white
                                        <?= $question['correct'] ? 'bg-success' : (!$question['answered'] ? 'bg-warning' : 'bg-error') ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                            <div>
                                                <h4 class="text-lg font-semibold">السؤال <?= $index + 1 ?></h4>
                                                <?php if ($question['ai_generated']): ?>
                                                    <span class="badge badge-sm badge-primary">
                                                        <i class="fas fa-robot ml-1"></i> AI
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div
                                                class="badge badge-lg <?= $question['correct'] ? 'badge-success' : 'badge-error' ?>">
                                                <?= $question['points_earned'] ?> / <?= $question['points'] ?> نقطة
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Question Text -->
                                    <div class="bg-gray-50 rounded-lg p-4 mb-4">
                                        <p class="text-lg leading-relaxed"><?= nl2br(e($question['text'])) ?></p>
                                    </div>

                                    <!-- Options -->
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <?php foreach ($question['options'] as $optIndex => $option): ?>
                                            <div class="option-item p-3 rounded-lg border-2 transition-all
                                    <?php
                                    if ($option['is_correct'] && $option['is_selected']) {
                                        echo 'option-correct border-success';
                                    } elseif ($option['is_selected'] && !$option['is_correct']) {
                                        echo 'option-incorrect border-error';
                                    } elseif ($option['is_correct'] && !$option['is_selected']) {
                                        echo 'option-missed border-warning';
                                    } else {
                                        echo 'bg-gray-50 border-gray-200';
                                    }
                                    ?>">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex-none">
                                                        <div
                                                            class="w-8 h-8 rounded-full flex items-center justify-center font-bold
                                                <?= $option['is_selected'] || $option['is_correct'] ? 'text-white' : 'bg-gray-200' ?>">
                                                            <?= ['أ', 'ب', 'ج', 'د', 'هـ', 'و'][$optIndex] ?>
                                                        </div>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p
                                                            class="<?= $option['is_selected'] || $option['is_correct'] ? 'text-white' : '' ?>">
                                                            <?= e($option['text']) ?>
                                                        </p>
                                                    </div>
                                                    <div class="flex-none">
                                                        <?php if ($option['is_correct'] && $option['is_selected']): ?>
                                                            <i class="fas fa-check-circle text-white text-xl"></i>
                                                        <?php elseif ($option['is_selected'] && !$option['is_correct']): ?>
                                                            <i class="fas fa-times-circle text-white text-xl"></i>
                                                        <?php elseif ($option['is_correct'] && !$option['is_selected']): ?>
                                                            <i class="fas fa-check-circle text-white text-xl"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Result Message -->
                                    <div class="mt-4 text-sm">
                                        <?php if ($question['correct']): ?>
                                            <p class="text-success font-semibold">
                                                <i class="fas fa-check-circle ml-1"></i>
                                                إجابة صحيحة! أحسنت
                                            </p>
                                        <?php elseif (!$question['answered']): ?>
                                            <p class="text-warning font-semibold">
                                                <i class="fas fa-exclamation-circle ml-1"></i>
                                                لم تتم الإجابة على هذا السؤال
                                            </p>
                                        <?php else: ?>
                                            <p class="text-error font-semibold">
                                                <i class="fas fa-times-circle ml-1"></i>
                                                إجابة خاطئة - الإجابة الصحيحة موضحة باللون الأصفر
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Action Buttons -->
        <div class="card bg-white shadow-2xl animate__animated animate__fadeInUp">
            <div class="card-body p-8">
                <div class="flex flex-wrap gap-4 justify-center">
                    <?php if ($attempt['user_id']): ?>
                        <a href="<?= BASE_URL ?>/student/" class="btn btn-primary btn-lg">
                            <i class="fas fa-home ml-2"></i>
                            لوحة التحكم
                        </a>
                    <?php endif; ?>

                    <button onclick="shareResults()" class="btn btn-success btn-lg no-print">
                        <i class="fas fa-share-alt ml-2"></i>
                        مشاركة النتيجة
                    </button>

                    <a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $attempt['pin_code'] ?? '' ?>"
                        class="btn btn-info btn-lg">
                        <i class="fas fa-redo ml-2"></i>
                        إعادة المحاولة
                    </a>

                    <button onclick="window.print()" class="btn btn-outline btn-lg no-print">
                        <i class="fas fa-print ml-2"></i>
                        طباعة النتائج
                    </button>

                    <a href="<?= BASE_URL ?>/" class="btn btn-ghost btn-lg">
                        <i class="fas fa-arrow-right ml-2"></i>
                        الصفحة الرئيسية
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Alpine.js data
        document.addEventListener('alpine:init', () => {
            Alpine.data('resultsApp', () => ({
                getScoreColor(score) {
                    if (score >= 90) return '#10b981';
                    if (score >= 80) return '#3b82f6';
                    if (score >= 70) return '#f59e0b';
                    if (score >= 60) return '#8b5cf6';
                    return '#ef4444';
                }
            }));
        });

        // Confetti for good scores
        <?php if ($percentageScore >= 80): ?>
            window.addEventListener('load', () => {
                const duration = 3000;
                const animationEnd = Date.now() + duration;
                const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 };

                function randomInRange(min, max) {
                    return Math.random() * (max - min) + min;
                }

                const interval = setInterval(function () {
                    const timeLeft = animationEnd - Date.now();

                    if (timeLeft <= 0) {
                        return clearInterval(interval);
                    }

                    const particleCount = 50 * (timeLeft / duration);

                    confetti(Object.assign({}, defaults, {
                        particleCount,
                        origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 }
                    }));
                    confetti(Object.assign({}, defaults, {
                        particleCount,
                        origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 }
                    }));
                }, 250);
            });
        <?php endif; ?>

        // Progress Chart
        <?php if (!empty($previousAttempts)): ?>
            const ctx = document.getElementById('progressChart').getContext('2d');
            const attempts = <?= json_encode(array_merge($previousAttempts, [['score' => $percentageScore, 'completed_at' => $attempt['completed_at']]])) ?>;

            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: attempts.map((a, i) => i === attempts.length - 1 ? 'هذه المحاولة' : `محاولة ${attempts.length - i}`),
                    datasets: [{
                        label: 'النتيجة',
                        data: attempts.map(a => a.score),
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            padding: 12,
                            cornerRadius: 8,
                            titleFont: {
                                size: 14,
                                family: 'Tajawal'
                            },
                            bodyFont: {
                                size: 16,
                                family: 'Tajawal'
                            },
                            callbacks: {
                                label: function (context) {
                                    return context.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function (value) {
                                    return value + '%';
                                },
                                font: {
                                    family: 'Tajawal'
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        x: {
                            ticks: {
                                font: {
                                    family: 'Tajawal'
                                }
                            },
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        // Share functionality
        function shareResults() {
            const text = `حصلت على <?= round($percentageScore) ?>% في اختبار "${<?= json_encode($attempt['quiz_title']) ?>}" على ${<?= json_encode(getSetting('site_name')) ?>}! 🎉`;

            if (navigator.share) {
                navigator.share({
                    title: 'نتائج الاختبار',
                    text: text,
                    url: window.location.href
                });
            } else {
                // Fallback
                navigator.clipboard.writeText(text + '\n' + window.location.href);

                // Show toast
                const toast = document.createElement('div');
                toast.className = 'toast toast-top toast-center z-50';
                toast.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>تم نسخ رابط النتائج!</span>
                </div>
            `;
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3000);
            }
        }
    </script>
</body>

</html>