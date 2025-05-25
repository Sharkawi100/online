<?php
// quiz/play.php - Modern Professional Quiz Interface
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Initialize variables
$error = '';
$quiz = null;
$attempt = null;
$questions = [];
$is_guest = false;
$user_name = '';

// Get attempt ID
$attempt_id = (int) ($_GET['attempt'] ?? $_SESSION['current_attempt_id'] ?? $_SESSION['attempt_id'] ?? 0);

if (!$attempt_id) {
    $_SESSION['error'] = 'لم يتم العثور على جلسة الاختبار';
    redirect('/');
}

// Get attempt details with quiz information
$stmt = $pdo->prepare("
    SELECT a.*, q.*, s.name_ar as subject_name, s.icon as subject_icon, s.color as subject_color
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE a.id = ? AND (a.completed_at IS NULL OR q.is_practice = 1)
");
$stmt->execute([$attempt_id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    $_SESSION['error'] = 'جلسة الاختبار غير صالحة أو منتهية';
    redirect('/');
}

$quiz = $attempt;
$is_guest = empty($attempt['user_id']);
$user_name = $is_guest ? $attempt['guest_name'] : $_SESSION['user_name'] ?? '';

// Check access permissions
if (!$is_guest && isLoggedIn() && $attempt['user_id'] != $_SESSION['user_id']) {
    $_SESSION['error'] = 'ليس لديك صلاحية للوصول لهذا الاختبار';
    redirect('/');
}

// Get quiz text if exists
$quiz_text = null;
if ($quiz['has_text'] && $quiz['text_id']) {
    $stmt = $pdo->prepare("SELECT * FROM quiz_texts WHERE id = ?");
    $stmt->execute([$quiz['text_id']]);
    $quiz_text = $stmt->fetch();
}

// Get all questions with their options
$stmt = $pdo->prepare("
    SELECT q.*, 
           GROUP_CONCAT(o.id ORDER BY o.order_index) as option_ids,
           GROUP_CONCAT(o.option_text ORDER BY o.order_index SEPARATOR '|||') as option_texts,
           GROUP_CONCAT(o.is_correct ORDER BY o.order_index) as correct_flags
    FROM questions q
    LEFT JOIN options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    GROUP BY q.id
    ORDER BY " . ($quiz['shuffle_questions'] ? "RAND()" : "q.order_index")
);
$stmt->execute([$quiz['id']]);
$questions = $stmt->fetchAll();

// Process questions and shuffle options if needed
foreach ($questions as &$question) {
    $option_ids = explode(',', $question['option_ids']);
    $option_texts = explode('|||', $question['option_texts']);
    $correct_flags = explode(',', $question['correct_flags']);

    $question['options'] = [];
    for ($i = 0; $i < count($option_ids); $i++) {
        $question['options'][] = [
            'id' => $option_ids[$i],
            'text' => $option_texts[$i],
            'is_correct' => $correct_flags[$i] == '1'
        ];
    }

    if ($quiz['shuffle_answers']) {
        shuffle($question['options']);
    }
}

// Store in session
$_SESSION['current_attempt_id'] = $attempt_id;

// Handle AJAX answer submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_POST['action'] === 'submit_answer') {
        $question_id = (int) $_POST['question_id'];
        $option_id = (int) $_POST['option_id'];

        try {
            // Check if answer exists
            $stmt = $pdo->prepare("SELECT id FROM answers WHERE attempt_id = ? AND question_id = ?");
            $stmt->execute([$attempt_id, $question_id]);
            $existing = $stmt->fetch();

            // Find correct answer
            $is_correct = false;
            $points = 0;
            foreach ($questions as $q) {
                if ($q['id'] == $question_id) {
                    foreach ($q['options'] as $opt) {
                        if ($opt['id'] == $option_id && $opt['is_correct']) {
                            $is_correct = true;
                            $points = $q['points'];
                            break;
                        }
                    }
                    break;
                }
            }

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE answers SET option_id = ?, is_correct = ?, answered_at = NOW() WHERE attempt_id = ? AND question_id = ?");
                $stmt->execute([$option_id, $is_correct ? 1 : 0, $attempt_id, $question_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO answers (attempt_id, question_id, option_id, is_correct, points_earned) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$attempt_id, $question_id, $option_id, $is_correct ? 1 : 0, $points]);
            }

            echo json_encode(['success' => true, 'is_correct' => $is_correct, 'points' => $points]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'complete_quiz') {
        try {
            $stmt = $pdo->prepare("
                UPDATE attempts 
                SET completed_at = NOW(),
                    time_taken = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                    score = (SELECT (SUM(points_earned) / ?) * 100 FROM answers WHERE attempt_id = ?),
                    total_points = ?
                WHERE id = ? AND completed_at IS NULL
            ");

            $total_points = array_sum(array_column($questions, 'points'));
            $stmt->execute([$total_points, $attempt_id, $total_points, $attempt_id]);

            echo json_encode(['success' => true, 'redirect' => BASE_URL . '/quiz/results.php?attempt_id=' . $attempt_id]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Get user's previous answers
$user_answers = [];
$stmt = $pdo->prepare("SELECT question_id, option_id, is_correct FROM answers WHERE attempt_id = ?");
$stmt->execute([$attempt_id]);
while ($row = $stmt->fetch()) {
    $user_answers[$row['question_id']] = [
        'option_id' => $row['option_id'],
        'is_correct' => $row['is_correct']
    ];
}

$answered_count = count($user_answers);
$total_questions = count($questions);
$progress_percentage = $total_questions > 0 ? round(($answered_count / $total_questions) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= e($quiz['title']) ?> - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            overscroll-behavior: none;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            user-select: none;
        }

        /* Smooth transitions */
        .question-slide {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
        }

        .question-enter {
            transform: translateX(100%);
            opacity: 0;
        }

        .question-leave {
            transform: translateX(-100%);
            opacity: 0;
        }

        /* Option cards */
        .option-card {
            transition: all 0.2s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .option-card::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(102, 126, 234, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .option-card.selected::before {
            width: 100%;
            height: 100%;
        }

        .option-card:active {
            transform: scale(0.98);
        }

        /* Progress bar */
        .progress-smooth {
            transition: width 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Timer pulse */
        @keyframes pulse-warning {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        .timer-warning {
            animation: pulse-warning 1s ease-in-out infinite;
        }

        /* Mobile optimizations */
        @media (max-width: 768px) {
            .question-nav-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 0.5rem;
            }

            .mobile-swipe-hint {
                position: absolute;
                bottom: 2rem;
                left: 50%;
                transform: translateX(-50%);
                opacity: 0.5;
                pointer-events: none;
            }
        }

        /* Reading text */
        .reading-text {
            white-space: pre-wrap;
            line-height: 2;
            font-size: 1.1rem;
        }

        /* Floating action button */
        .fab {
            position: fixed;
            bottom: 2rem;
            left: 2rem;
            z-index: 40;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .fab:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
        }

        /* Better scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        /* Skeleton loading */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }

            100% {
                background-position: -200% 0;
            }
        }

        /* Success animation */
        @keyframes success-check {
            0% {
                transform: scale(0) rotate(45deg);
                opacity: 0;
            }

            50% {
                transform: scale(1.2) rotate(45deg);
            }

            100% {
                transform: scale(1) rotate(45deg);
                opacity: 1;
            }
        }

        .success-check {
            animation: success-check 0.5s ease-out;
        }
    </style>
</head>

<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen" x-data="quizApp">
    <!-- Top Navigation -->
    <div class="navbar bg-white/90 backdrop-blur-lg shadow-lg sticky top-0 z-50 px-4 lg:px-8">
        <div class="navbar-start">
            <div class="flex items-center gap-3">
                <div class="avatar placeholder">
                    <div class="bg-gradient-to-br from-purple-500 to-pink-500 text-white rounded-full w-10 h-10">
                        <span class="text-lg font-bold"><?= mb_substr($user_name ?: 'ض', 0, 1) ?></span>
                    </div>
                </div>
                <div class="hidden sm:block">
                    <p class="text-xs text-gray-500">مشارك</p>
                    <p class="font-semibold text-sm"><?= e($user_name ?: 'ضيف') ?></p>
                </div>
            </div>
        </div>

        <div class="navbar-center">
            <div class="flex flex-col items-center">
                <h1 class="text-lg font-bold text-gray-800 hidden md:block"><?= e($quiz['title']) ?></h1>
                <div class="flex items-center gap-2 text-xs">
                    <span class="badge badge-sm"
                        style="background-color: <?= e($quiz['subject_color']) ?>20; color: <?= e($quiz['subject_color']) ?>">
                        <?= e($quiz['subject_name']) ?>
                    </span>
                    <span class="badge badge-sm badge-ghost"><?= getGradeName($quiz['grade']) ?></span>
                </div>
            </div>
        </div>

        <div class="navbar-end">
            <!-- Timer -->
            <?php if ($quiz['time_limit'] > 0): ?>
                <div class="stat p-2 bg-base-100 rounded-lg shadow-sm ml-3"
                    :class="{ 'bg-error text-error-content timer-warning': timeRemaining < 60 }">
                    <div class="stat-value text-lg" x-text="formatTime(timeRemaining)"></div>
                </div>
            <?php endif; ?>

            <!-- Exit Button -->
            <button @click="confirmExit" class="btn btn-ghost btn-circle">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="w-full bg-gray-200 h-1">
        <div class="h-full bg-gradient-to-r from-purple-500 to-pink-500 progress-smooth"
            :style="`width: ${Math.round((currentQuestionIndex + 1) / totalQuestions * 100)}%`"></div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-6 max-w-6xl">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Questions Panel -->
            <div class="lg:col-span-3">
                <!-- Reading Text (Initial) -->
                <div x-show="showReadingText && hasReadingText" x-transition class="mb-6">
                    <div class="card bg-white shadow-xl">
                        <div class="card-body">
                            <h2 class="text-2xl font-bold mb-4 text-center">
                                <i class="fas fa-book-open text-info ml-2"></i>
                                اقرأ النص التالي بعناية
                            </h2>
                            <div class="divider"></div>
                            <div class="reading-text text-gray-700 max-h-[60vh] overflow-y-auto px-4">
                                <?= nl2br(e($quiz_text['text_content'] ?? '')) ?>
                            </div>
                            <div class="divider"></div>
                            <div class="text-center">
                                <p class="text-sm text-gray-500 mb-4">
                                    <i class="fas fa-clock ml-1"></i>
                                    وقت القراءة المقترح: <?= ceil(($quiz_text['reading_time'] ?? 180) / 60) ?> دقائق
                                </p>
                                <button @click="startQuiz" class="btn btn-primary btn-lg">
                                    <i class="fas fa-play ml-2"></i>
                                    ابدأ الأسئلة
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Question Display -->
                <div x-show="!showReadingText" x-transition>
                    <template x-for="(question, index) in questions" :key="question.id">
                        <div x-show="currentQuestionIndex === index" x-transition:enter="question-slide question-enter"
                            x-transition:leave="question-slide question-leave" class="card bg-white shadow-xl">
                            <div class="card-body p-6 lg:p-8">
                                <!-- Question Header -->
                                <div class="flex justify-between items-center mb-6">
                                    <div>
                                        <h2 class="text-xl lg:text-2xl font-bold text-gray-800">
                                            السؤال <span x-text="index + 1"></span> من <span
                                                x-text="totalQuestions"></span>
                                        </h2>
                                        <p class="text-sm text-gray-500 mt-1" x-show="question.is_text_based">
                                            <i class="fas fa-book-reader ml-1"></i>
                                            سؤال متعلق بالنص
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <div class="badge badge-lg badge-primary">
                                            <span x-text="question.points"></span> نقطة
                                        </div>
                                    </div>
                                </div>

                                <!-- Question Text -->
                                <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-6 mb-6">
                                    <p class="text-lg lg:text-xl leading-relaxed text-gray-800"
                                        x-html="question.question_text"></p>
                                </div>

                                <!-- Options -->
                                <div class="space-y-3">
                                    <template x-for="(option, optIndex) in question.options" :key="option.id">
                                        <div @click="selectAnswer(question.id, option.id, index, optIndex)" :class="{
                                                 'ring-2 ring-primary bg-primary/5': isSelected(question.id, option.id),
                                                 'bg-success/10 ring-2 ring-success': showResult(question.id, option.id) && option.is_correct,
                                                 'bg-error/10 ring-2 ring-error': showResult(question.id, option.id) && !option.is_correct && isSelected(question.id, option.id)
                                             }"
                                            class="option-card card bg-base-100 border-2 border-gray-200 hover:border-primary hover:shadow-md cursor-pointer">
                                            <div class="card-body p-4">
                                                <div class="flex items-center gap-4">
                                                    <div class="flex-none">
                                                        <div :class="{
                                                                'bg-primary text-white': isSelected(question.id, option.id),
                                                                'bg-success text-white': showResult(question.id, option.id) && option.is_correct,
                                                                'bg-error text-white': showResult(question.id, option.id) && !option.is_correct && isSelected(question.id, option.id)
                                                            }"
                                                            class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center font-bold transition-all">
                                                            <span
                                                                x-text="['أ', 'ب', 'ج', 'د', 'هـ', 'و'][optIndex]"></span>
                                                        </div>
                                                    </div>
                                                    <div class="flex-1">
                                                        <p class="text-base lg:text-lg" x-text="option.text"></p>
                                                    </div>
                                                    <div class="flex-none" x-show="showResult(question.id, option.id)">
                                                        <i x-show="option.is_correct"
                                                            class="fas fa-check-circle text-success text-2xl success-check"></i>
                                                        <i x-show="!option.is_correct && isSelected(question.id, option.id)"
                                                            class="fas fa-times-circle text-error text-2xl"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>

                                <!-- Navigation -->
                                <div class="flex justify-between items-center mt-8">
                                    <button @click="previousQuestion" :disabled="currentQuestionIndex === 0"
                                        class="btn btn-outline btn-lg">
                                        <i class="fas fa-chevron-right ml-2"></i>
                                        السابق
                                    </button>

                                    <div class="text-center text-sm text-gray-500 hidden md:block">
                                        <p x-show="!hasAnswered(question.id)">اختر إجابة للمتابعة</p>
                                        <p x-show="hasAnswered(question.id)" class="text-success">
                                            <i class="fas fa-check-circle ml-1"></i>
                                            تم الحفظ
                                        </p>
                                    </div>

                                    <button @click="nextQuestion" x-show="currentQuestionIndex < totalQuestions - 1"
                                        class="btn btn-primary btn-lg">
                                        التالي
                                        <i class="fas fa-chevron-left mr-2"></i>
                                    </button>

                                    <button @click="showCompletionModal"
                                        x-show="currentQuestionIndex === totalQuestions - 1"
                                        :disabled="answeredCount < totalQuestions" class="btn btn-success btn-lg">
                                        <i class="fas fa-flag-checkered ml-2"></i>
                                        إنهاء الاختبار
                                    </button>
                                </div>

                                <!-- Mobile swipe hint -->
                                <div class="mobile-swipe-hint md:hidden text-center text-xs text-gray-400">
                                    <i class="fas fa-hand-point-left mx-2"></i>
                                    اسحب للتنقل
                                    <i class="fas fa-hand-point-right mx-2"></i>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="lg:col-span-1 space-y-4">
                <!-- Question Navigation -->
                <div class="card bg-white shadow-xl sticky top-24">
                    <div class="card-body p-4">
                        <h3 class="font-bold text-lg mb-4">
                            <i class="fas fa-th text-primary ml-2"></i>
                            الأسئلة
                        </h3>
                        <div class="grid question-nav-grid grid-cols-4 lg:grid-cols-3 xl:grid-cols-4 gap-2">
                            <template x-for="(q, index) in questions" :key="q.id">
                                <button @click="goToQuestion(index)" :class="{
                                            'btn-primary': currentQuestionIndex === index,
                                            'btn-success': hasAnswered(q.id) && currentQuestionIndex !== index,
                                            'btn-outline': !hasAnswered(q.id) && currentQuestionIndex !== index
                                        }" class="btn btn-sm">
                                    <span x-text="index + 1"></span>
                                </button>
                            </template>
                        </div>

                        <!-- Legend -->
                        <div class="mt-4 space-y-2 text-xs">
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-primary rounded"></div>
                                <span>السؤال الحالي</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-success rounded"></div>
                                <span>تمت الإجابة</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 border-2 border-gray-300 rounded"></div>
                                <span>لم تتم الإجابة</span>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="divider my-2"></div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-primary" x-text="answeredCount"></div>
                            <div class="text-sm text-gray-500">من <span x-text="totalQuestions"></span> سؤال</div>
                            <div class="mt-2">
                                <progress class="progress progress-primary w-full" :value="answeredCount"
                                    :max="totalQuestions"></progress>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Help -->
                <div class="card bg-info/10 border-2 border-info/20 hidden lg:block">
                    <div class="card-body p-4">
                        <h4 class="font-bold text-sm text-info mb-2">
                            <i class="fas fa-lightbulb"></i>
                            نصائح
                        </h4>
                        <ul class="text-xs space-y-1 text-gray-600">
                            <li>• اقرأ السؤال جيداً قبل الإجابة</li>
                            <li>• يمكنك تغيير إجابتك قبل الإنهاء</li>
                            <li>• تأكد من الإجابة على جميع الأسئلة</li>
                            <?php if ($quiz['is_practice']): ?>
                                <li>• ستظهر النتيجة فور اختيار الإجابة</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button for Reading Text -->
    <?php if ($quiz_text): ?>
        <button @click="showTextModal" x-show="!showReadingText" class="fab btn btn-circle btn-primary btn-lg">
            <i class="fas fa-book-open text-xl"></i>
        </button>
    <?php endif; ?>

    <!-- Modals -->
    <!-- Reading Text Modal -->
    <dialog id="textModal" class="modal">
        <div class="modal-box w-11/12 max-w-4xl">
            <h3 class="font-bold text-xl mb-4">
                <i class="fas fa-book-open text-info ml-2"></i>
                نص القراءة
            </h3>
            <div class="reading-text text-gray-700 max-h-[70vh] overflow-y-auto">
                <?= nl2br(e($quiz_text['text_content'] ?? '')) ?>
            </div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn">إغلاق</button>
                </form>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <!-- Completion Modal -->
    <dialog id="completionModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-xl mb-4">
                <i class="fas fa-flag-checkered text-success ml-2"></i>
                إنهاء الاختبار
            </h3>
            <div class="py-4">
                <div x-show="answeredCount < totalQuestions" class="alert alert-warning mb-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>لديك <span x-text="totalQuestions - answeredCount"></span> أسئلة لم تجب عليها!</span>
                </div>
                <p class="text-lg">هل أنت متأكد من إنهاء الاختبار؟</p>
                <p class="text-sm text-gray-500 mt-2">لن تتمكن من العودة لتغيير إجاباتك.</p>
            </div>
            <div class="modal-action">
                <button class="btn btn-ghost" onclick="completionModal.close()">
                    مراجعة الأسئلة
                </button>
                <button @click="completeQuiz" class="btn btn-success">
                    <i class="fas fa-check ml-2"></i>
                    إنهاء الاختبار
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <!-- Exit Modal -->
    <dialog id="exitModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-xl mb-4">
                <i class="fas fa-sign-out-alt text-warning ml-2"></i>
                الخروج من الاختبار
            </h3>
            <p>هل أنت متأكد من الخروج؟</p>
            <p class="text-sm text-gray-500 mt-2">سيتم حفظ إجاباتك الحالية.</p>
            <div class="modal-action">
                <button class="btn btn-ghost" onclick="exitModal.close()">البقاء</button>
                <a href="<?= BASE_URL ?>/" class="btn btn-warning">
                    <i class="fas fa-sign-out-alt ml-2"></i>
                    خروج
                </a>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button>close</button>
        </form>
    </dialog>

    <script>
        // Initialize Alpine.js data
        document.addEventListener('alpine:init', () => {
            Alpine.data('quizApp', () => ({
                // Data
                questions: <?= json_encode(array_values($questions)) ?>,
                userAnswers: <?= json_encode($user_answers) ?>,
                currentQuestionIndex: 0,
                totalQuestions: <?= $total_questions ?>,
                answeredCount: <?= $answered_count ?>,
                showReadingText: <?= ($quiz_text && $answered_count === 0) ? 'true' : 'false' ?>,
                hasReadingText: <?= $quiz_text ? 'true' : 'false' ?>,
                timeRemaining: <?= $quiz['time_limit'] > 0 ? $quiz['time_limit'] * 60 : 0 ?>,
                timerInterval: null,
                isPracticeMode: <?= $quiz['is_practice'] ? 'true' : 'false' ?>,
                attemptId: <?= $attempt_id ?>,
                isSubmitting: false,

                // Initialize
                init() {
                    // Start timer if needed
                    if (this.timeRemaining > 0) {
                        this.startTimer();
                    }

                    // Add keyboard navigation
                    this.setupKeyboardNav();

                    // Add swipe gestures for mobile
                    this.setupSwipeGestures();

                    // Load saved position
                    this.loadSavedPosition();
                },

                // Timer functions
                startTimer() {
                    this.timerInterval = setInterval(() => {
                        this.timeRemaining--;
                        if (this.timeRemaining <= 0) {
                            clearInterval(this.timerInterval);
                            this.autoSubmit();
                        }
                    }, 1000);
                },

                formatTime(seconds) {
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.floor((seconds % 3600) / 60);
                    const secs = seconds % 60;

                    if (hours > 0) {
                        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                    }
                    return `${minutes}:${secs.toString().padStart(2, '0')}`;
                },

                // Question navigation
                startQuiz() {
                    this.showReadingText = false;
                    this.savePosition();
                },

                goToQuestion(index) {
                    if (index >= 0 && index < this.totalQuestions) {
                        this.currentQuestionIndex = index;
                        this.savePosition();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                },

                nextQuestion() {
                    if (this.currentQuestionIndex < this.totalQuestions - 1) {
                        this.currentQuestionIndex++;
                        this.savePosition();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                },

                previousQuestion() {
                    if (this.currentQuestionIndex > 0) {
                        this.currentQuestionIndex--;
                        this.savePosition();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }
                },

                // Answer handling
                async selectAnswer(questionId, optionId, questionIndex, optionIndex) {
                    // Visual feedback
                    const wasAnswered = this.hasAnswered(questionId);

                    // Update local state
                    this.userAnswers[questionId] = {
                        option_id: optionId,
                        is_correct: this.questions[questionIndex].options[optionIndex].is_correct
                    };

                    if (!wasAnswered) {
                        this.answeredCount++;
                    }

                    // Submit to server
                    try {
                        const formData = new FormData();
                        formData.append('ajax', '1');
                        formData.append('action', 'submit_answer');
                        formData.append('question_id', questionId);
                        formData.append('option_id', optionId);

                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.success) {
                            // Auto advance after short delay (non-practice mode)
                            if (!this.isPracticeMode && this.currentQuestionIndex < this.totalQuestions - 1) {
                                setTimeout(() => this.nextQuestion(), 800);
                            }
                        }
                    } catch (error) {
                        console.error('Error submitting answer:', error);
                    }
                },

                // Answer state checks
                hasAnswered(questionId) {
                    return this.userAnswers.hasOwnProperty(questionId);
                },

                isSelected(questionId, optionId) {
                    return this.userAnswers[questionId]?.option_id == optionId;
                },

                showResult(questionId, optionId) {
                    return this.isPracticeMode && this.hasAnswered(questionId);
                },

                // Quiz completion
                showCompletionModal() {
                    document.getElementById('completionModal').showModal();
                },

                async completeQuiz() {
                    if (this.isSubmitting) return;
                    this.isSubmitting = true;

                    try {
                        const formData = new FormData();
                        formData.append('ajax', '1');
                        formData.append('action', 'complete_quiz');

                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.success) {
                            window.location.href = data.redirect;
                        }
                    } catch (error) {
                        console.error('Error completing quiz:', error);
                        this.isSubmitting = false;
                    }
                },

                autoSubmit() {
                    alert('انتهى الوقت! سيتم إرسال الاختبار تلقائياً.');
                    this.completeQuiz();
                },

                // UI helpers
                confirmExit() {
                    document.getElementById('exitModal').showModal();
                },

                showTextModal() {
                    document.getElementById('textModal').showModal();
                },

                // Save/load position
                savePosition() {
                    sessionStorage.setItem(`quiz_position_${this.attemptId}`, this.currentQuestionIndex);
                },

                loadSavedPosition() {
                    const saved = sessionStorage.getItem(`quiz_position_${this.attemptId}`);
                    if (saved !== null && !this.showReadingText) {
                        this.currentQuestionIndex = parseInt(saved);
                    }
                },

                // Keyboard navigation
                setupKeyboardNav() {
                    document.addEventListener('keydown', (e) => {
                        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                        switch (e.key) {
                            case 'ArrowRight':
                                e.preventDefault();
                                this.previousQuestion();
                                break;
                            case 'ArrowLeft':
                                e.preventDefault();
                                this.nextQuestion();
                                break;
                            case '1':
                            case '2':
                            case '3':
                            case '4':
                            case '5':
                            case '6':
                                const optionIndex = parseInt(e.key) - 1;
                                const currentQuestion = this.questions[this.currentQuestionIndex];
                                if (currentQuestion && currentQuestion.options[optionIndex]) {
                                    this.selectAnswer(
                                        currentQuestion.id,
                                        currentQuestion.options[optionIndex].id,
                                        this.currentQuestionIndex,
                                        optionIndex
                                    );
                                }
                                break;
                        }
                    });
                },

                // Touch/swipe gestures
                setupSwipeGestures() {
                    let touchStartX = 0;
                    let touchEndX = 0;

                    document.addEventListener('touchstart', (e) => {
                        touchStartX = e.changedTouches[0].screenX;
                    });

                    document.addEventListener('touchend', (e) => {
                        touchEndX = e.changedTouches[0].screenX;
                        this.handleSwipe();
                    });

                    this.handleSwipe = () => {
                        const swipeThreshold = 50;
                        const diff = touchStartX - touchEndX;

                        if (Math.abs(diff) > swipeThreshold) {
                            if (diff > 0) {
                                // Swipe left - next question
                                this.nextQuestion();
                            } else {
                                // Swipe right - previous question
                                this.previousQuestion();
                            }
                        }
                    };
                }
            }));
        });
    </script>
</body>

</html>