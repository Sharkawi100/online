<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user has valid session
if (!isset($_SESSION['quiz_participant']) || !isset($_SESSION['attempt_id'])) {
    redirect('/quiz/join.php');
}

$quizId = $_GET['id'] ?? $_SESSION['quiz_participant']['quiz_id'] ?? 0;

// Get quiz details
$stmt = $pdo->prepare("
    SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon 
    FROM quizzes q
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE q.id = ? AND q.is_active = 1
");
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    redirect('/quiz/join.php');
}

// Get questions
$sql = "SELECT * FROM questions WHERE quiz_id = ? ORDER BY ";
$sql .= $quiz['shuffle_questions'] ? "RAND()" : "order_index, id";

$stmt = $pdo->prepare($sql);
$stmt->execute([$quizId]);
$questions = $stmt->fetchAll();

// Get options for each question
foreach ($questions as &$question) {
    $sql = "SELECT * FROM options WHERE question_id = ? ORDER BY ";
    $sql .= $quiz['shuffle_answers'] ? "RAND()" : "order_index, id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$question['id']]);
    $question['options'] = $stmt->fetchAll();
}

// Handle quiz submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $answers = $_POST['answers'] ?? [];
    $timeTaken = time() - $_SESSION['quiz_participant']['started_at'];

    $correctCount = 0;
    $totalPoints = 0;

    // Process answers
    foreach ($questions as $question) {
        $selectedOption = $answers[$question['id']] ?? null;
        $isCorrect = false;
        $pointsEarned = 0;

        if ($selectedOption) {
            // Check if answer is correct
            foreach ($question['options'] as $option) {
                if ($option['id'] == $selectedOption && $option['is_correct']) {
                    $isCorrect = true;
                    $correctCount++;
                    $pointsEarned = $question['points'];
                    $totalPoints += $pointsEarned;
                    break;
                }
            }

            // Save answer
            $stmt = $pdo->prepare("
                INSERT INTO answers (attempt_id, question_id, option_id, is_correct, points_earned)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['attempt_id'],
                $question['id'],
                $selectedOption,
                $isCorrect ? 1 : 0,
                $pointsEarned
            ]);
        }
    }

    // Calculate score
    $scoreData = calculateScore($correctCount, count($questions), $timeTaken, $quiz['time_limit']);

    // Update attempt
    $stmt = $pdo->prepare("
        UPDATE attempts 
        SET score = ?, total_points = ?, time_taken = ?, completed_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $scoreData['final_score'],
        $totalPoints,
        $timeTaken,
        $_SESSION['attempt_id']
    ]);

    // Update user points and streak if logged in
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
        $stmt->execute([$scoreData['points_earned'], $_SESSION['user_id']]);

        updateStreak($_SESSION['user_id']);
        checkAchievements($_SESSION['user_id'], 'quiz_complete', [
            'score' => $scoreData['final_score'],
            'time_taken' => $timeTaken
        ]);
    }

    // Clear session and redirect to results
    unset($_SESSION['quiz_participant']);
    redirect('/quiz/results.php?attempt_id=' . $_SESSION['attempt_id']);
}
?>
<!DOCTYPE html>
<html lang="<?= $quiz['language'] ?>" dir="<?= $quiz['language'] == 'ar' ? 'rtl' : 'ltr' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($quiz['title']) ?> - <?= e(getSetting('site_name')) ?></title>

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

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: #f3f4f6;
        }

        .question-card {
            transition: all 0.3s ease;
        }

        .option-card {
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .option-card:hover {
            transform: translateX(-5px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .option-card.selected {
            background: #dbeafe;
            border-color: #3b82f6;
        }
    </style>
</head>

<body x-data="quizApp()" x-init="startQuiz()">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-sm sticky top-0 z-50">
        <div class="flex-1">
            <div class="flex items-center gap-4">
                <div class="avatar">
                    <div
                        class="w-10 h-10 rounded-full bg-<?= getGradeColor($quiz['grade']) ?>-100 flex items-center justify-center">
                        <i
                            class="<?= e($quiz['subject_icon'] ?? 'fas fa-book') ?> text-<?= getGradeColor($quiz['grade']) ?>-600"></i>
                    </div>
                </div>
                <div>
                    <h1 class="text-lg font-bold"><?= e($quiz['title']) ?></h1>
                    <p class="text-sm text-gray-600"><?= e($quiz['subject_name'] ?? 'اختبار عام') ?></p>
                </div>
            </div>
        </div>
        <div class="flex-none">
            <!-- Timer -->
            <?php if ($quiz['time_limit'] > 0): ?>
                <div class="flex items-center gap-2 ml-4 text-lg">
                    <i class="fas fa-clock text-orange-500"></i>
                    <span x-text="formatTime(timeRemaining)" class="font-mono font-bold"
                        :class="timeRemaining < 60 ? 'text-red-600' : ''"></span>
                </div>
            <?php endif; ?>

            <!-- Progress -->
            <div class="ml-4">
                <span class="text-sm text-gray-600">السؤال</span>
                <span class="font-bold mx-1" x-text="currentQuestion + 1"></span>
                <span class="text-sm text-gray-600">من</span>
                <span class="font-bold mx-1"><?= count($questions) ?></span>
            </div>
        </div>
    </div>

    <!-- Quiz Content -->
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <form method="POST" @submit="submitQuiz">
            <input type="hidden" name="submit_quiz" value="1">

            <!-- Progress Bar -->
            <div class="mb-8">
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 h-3 rounded-full transition-all duration-500"
                        :style="`width: ${((currentQuestion + 1) / <?= count($questions) ?>) * 100}%`"></div>
                </div>
            </div>

            <!-- Questions -->
            <?php foreach ($questions as $index => $question): ?>
                <div class="question-card" x-show="currentQuestion === <?= $index ?>" x-transition>
                    <div class="card bg-base-100 shadow-xl mb-8">
                        <div class="card-body">
                            <!-- Question Header -->
                            <div class="flex items-start justify-between mb-4">
                                <h2 class="text-xl font-bold flex-1">
                                    <?= e($question['question_text']) ?>
                                </h2>
                                <div class="badge badge-primary badge-lg">
                                    <?= $question['points'] ?> نقطة
                                </div>
                            </div>

                            <!-- Question Image if exists -->
                            <?php if ($question['question_image']): ?>
                                <div class="mb-6">
                                    <img src="<?= BASE_URL ?>/uploads/questions/<?= e($question['question_image']) ?>"
                                        alt="صورة السؤال" class="rounded-lg shadow-md max-h-64 mx-auto">
                                </div>
                            <?php endif; ?>

                            <!-- Options -->
                            <div class="space-y-3">
                                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                    <label class="option-card block">
                                        <div class="card bg-base-100 border-2 border-gray-200 cursor-pointer hover:border-primary"
                                            :class="{ 'selected': answers[<?= $question['id'] ?>] == <?= $option['id'] ?> }">
                                            <div class="card-body p-4">
                                                <div class="flex items-center">
                                                    <input type="radio" name="answers[<?= $question['id'] ?>]"
                                                        value="<?= $option['id'] ?>" x-model="answers[<?= $question['id'] ?>]"
                                                        class="radio radio-primary ml-3">
                                                    <span class="flex-1 text-lg"><?= e($option['option_text']) ?></span>
                                                    <div
                                                        class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center font-bold">
                                                        <?= chr(65 + $optionIndex) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation -->
                    <div class="flex justify-between items-center">
                        <button type="button" @click="previousQuestion()" x-show="currentQuestion > 0"
                            class="btn btn-outline">
                            <i class="fas fa-arrow-right ml-2"></i>
                            السابق
                        </button>

                        <div class="flex-1 text-center">
                            <div class="join">
                                <?php for ($i = 0; $i < count($questions); $i++): ?>
                                    <button type="button" @click="goToQuestion(<?= $i ?>)" class="join-item btn btn-sm" :class="{
                                        'btn-primary': currentQuestion === <?= $i ?>,
                                        'btn-success': answers[<?= $questions[$i]['id'] ?>] !== undefined
                                    }">
                                        <?= $i + 1 ?>
                                    </button>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <button type="button" @click="nextQuestion()"
                            x-show="currentQuestion < <?= count($questions) - 1 ?>" class="btn btn-primary">
                            التالي
                            <i class="fas fa-arrow-left mr-2"></i>
                        </button>

                        <button type="submit" x-show="currentQuestion === <?= count($questions) - 1 ?>"
                            class="btn btn-success" @click.prevent="confirmSubmit()">
                            إنهاء الاختبار
                            <i class="fas fa-check mr-2"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
    </div>

    <!-- Confirm Dialog -->
    <dialog id="confirmModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg">تأكيد إنهاء الاختبار</h3>
            <p class="py-4">
                لقد أجبت على <span x-text="Object.keys(answers).length"></span> من <?= count($questions) ?> سؤال.
                هل أنت متأكد من إنهاء الاختبار؟
            </p>
            <div class="modal-action">
                <button type="button" class="btn" onclick="confirmModal.close()">مراجعة الإجابات</button>
                <button type="button" class="btn btn-primary" @click="document.querySelector('form').submit()">إنهاء
                    الاختبار</button>
            </div>
        </div>
    </dialog>

    <script>
        function quizApp() {
            return {
                currentQuestion: 0,
                answers: {},
                timeRemaining: <?= $quiz['time_limit'] * 60 ?>,
                timer: null,

                startQuiz() {
                    <?php if ($quiz['time_limit'] > 0): ?>
                        this.timer = setInterval(() => {
                            this.timeRemaining--;
                            if (this.timeRemaining <= 0) {
                                clearInterval(this.timer);
                                document.querySelector('form').submit();
                            }
                        }, 1000);
                    <?php endif; ?>
                },

                formatTime(seconds) {
                    const minutes = Math.floor(seconds / 60);
                    const secs = seconds % 60;
                    return `${minutes}:${secs.toString().padStart(2, '0')}`;
                },

                nextQuestion() {
                    if (this.currentQuestion < <?= count($questions) - 1 ?>) {
                        this.currentQuestion++;
                    }
                },

                previousQuestion() {
                    if (this.currentQuestion > 0) {
                        this.currentQuestion--;
                    }
                },

                goToQuestion(index) {
                    this.currentQuestion = index;
                },

                confirmSubmit() {
                    confirmModal.showModal();
                },

                submitQuiz(e) {
                    if (this.timer) {
                        clearInterval(this.timer);
                    }
                }
            }
        }
    </script>
</body>

</html>