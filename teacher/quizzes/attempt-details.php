<?php
// /teacher/quizzes/attempt-details.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}
// Check if user is logged in and is a teacher or admin
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$attempt_id = $_GET['id'] ?? 0;
$teacher_id = $_SESSION['user_id'];

// Get attempt details with verification that it belongs to teacher's quiz
$stmt = $pdo->prepare("
    SELECT a.*, 
           q.title as quiz_title, q.grade, q.difficulty, q.time_limit,
           q.language, q.subject_id, q.teacher_id,
           s.name_ar as subject_name, s.icon as subject_icon,
           COALESCE(u.name, a.guest_name) as participant_name,
           u.email, u.role as user_role,
           (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as total_questions
    FROM attempts a
    JOIN quizzes q ON a.quiz_id = q.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN users u ON a.user_id = u.id
    WHERE a.id = ? AND q.teacher_id = ?
");
$stmt->execute([$attempt_id, $teacher_id]);
$attempt = $stmt->fetch();

if (!$attempt || !$attempt['completed_at']) {
    redirect('/teacher/quizzes/results.php');
}

// Get all questions with student's answers
$stmt = $pdo->prepare("
    SELECT q.*, 
           ans.option_id as selected_option_id,
           ans.is_correct as answer_correct,
           ans.points_earned
    FROM questions q
    LEFT JOIN answers ans ON q.id = ans.question_id AND ans.attempt_id = ?
    WHERE q.quiz_id = ?
    ORDER BY q.order_index
");
$stmt->execute([$attempt_id, $attempt['quiz_id']]);
$questions = $stmt->fetchAll();

// Get options for each question
foreach ($questions as &$question) {
    $stmt = $pdo->prepare("
        SELECT * FROM options 
        WHERE question_id = ? 
        ORDER BY order_index
    ");
    $stmt->execute([$question['id']]);
    $question['options'] = $stmt->fetchAll();
}

// Calculate statistics
$totalPoints = array_sum(array_column($questions, 'points'));
$earnedPoints = array_sum(array_column($questions, 'points_earned'));
$answeredQuestions = count(array_filter($questions, fn($q) => $q['selected_option_id'] !== null));
$correctAnswers = count(array_filter($questions, fn($q) => $q['answer_correct'] == 1));
$skippedQuestions = count($questions) - $answeredQuestions;

// Get grade info
$gradeColor = getGradeColor($attempt['grade']);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تفاصيل المحاولة - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            .page-break {
                page-break-after: always;
            }

            body {
                background: white;
            }

            .card {
                box-shadow: none !important;
                border: 1px solid #ddd;
            }
        }

        .correct-answer {
            background-color: #d1fae5;
            border-color: #10b981;
        }

        .wrong-answer {
            background-color: #fee2e2;
            border-color: #ef4444;
        }

        .skipped-answer {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg no-print">
        <div class="flex-1">
            <a href="<?= BASE_URL ?>/teacher/" class="btn btn-ghost normal-case text-xl">
                <i class="fas fa-chalkboard-teacher ml-2"></i>
                لوحة المعلم
            </a>
        </div>
        <div class="flex-none">
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost">
                    <i class="fas fa-user-circle text-2xl"></i>
                </label>
                <ul tabindex="0"
                    class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="<?= BASE_URL ?>/teacher/profile.php"><i class="fas fa-user ml-2"></i> الملف الشخصي</a>
                    </li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt ml-2"></i> تسجيل
                            الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <!-- Header -->
        <div class="mb-8 no-print">
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-3xl font-bold mb-2">تفاصيل المحاولة</h1>
                    <div class="breadcrumbs text-sm">
                        <ul>
                            <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                            <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                            <li><a
                                    href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $attempt['quiz_id'] ?>">النتائج</a>
                            </li>
                            <li>تفاصيل المحاولة</li>
                        </ul>
                    </div>
                </div>
                <div class="flex gap-2">
                    <button onclick="window.print()" class="btn btn-ghost btn-sm">
                        <i class="fas fa-print"></i>
                        طباعة
                    </button>
                    <a href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $attempt['quiz_id'] ?>"
                        class="btn btn-ghost btn-sm">
                        <i class="fas fa-arrow-right"></i>
                        رجوع
                    </a>
                </div>
            </div>
        </div>

        <!-- Student Info Card -->
        <div class="card bg-base-100 shadow-xl mb-6">
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Student Details -->
                    <div>
                        <h2 class="text-lg font-bold mb-4">
                            <i class="fas fa-user-graduate text-primary ml-2"></i>
                            معلومات الطالب
                        </h2>
                        <div class="space-y-2">
                            <div class="flex items-center gap-3">
                                <div class="avatar placeholder">
                                    <div
                                        class="bg-<?= $gradeColor ?>-100 text-<?= $gradeColor ?>-700 rounded-full w-12">
                                        <span
                                            class="text-xl"><?= mb_substr($attempt['participant_name'], 0, 1) ?></span>
                                    </div>
                                </div>
                                <div>
                                    <div class="font-bold"><?= e($attempt['participant_name']) ?></div>
                                    <?php if ($attempt['email']): ?>
                                        <div class="text-sm text-gray-600"><?= e($attempt['email']) ?></div>
                                    <?php else: ?>
                                        <div class="text-sm text-gray-600">مشارك زائر</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quiz Details -->
                    <div>
                        <h2 class="text-lg font-bold mb-4">
                            <i class="fas fa-clipboard-list text-primary ml-2"></i>
                            معلومات الاختبار
                        </h2>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">الاختبار:</span>
                                <span class="font-medium"><?= e($attempt['quiz_title']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">المادة:</span>
                                <span class="font-medium"><?= e($attempt['subject_name'] ?: 'عام') ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">الصف:</span>
                                <span
                                    class="badge badge-<?= $gradeColor ?> badge-sm"><?= getGradeName($attempt['grade']) ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">التاريخ:</span>
                                <span
                                    class="font-medium"><?= date('Y/m/d - H:i', strtotime($attempt['completed_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Summary -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="card bg-primary text-primary-content">
                <div class="card-body p-4 text-center">
                    <div class="text-3xl font-bold"><?= round($attempt['score']) ?>%</div>
                    <div class="text-sm opacity-90">النتيجة النهائية</div>
                </div>
            </div>

            <div class="card bg-base-100 shadow">
                <div class="card-body p-4 text-center">
                    <div class="text-2xl font-bold text-success"><?= $correctAnswers ?></div>
                    <div class="text-sm">إجابات صحيحة</div>
                </div>
            </div>

            <div class="card bg-base-100 shadow">
                <div class="card-body p-4 text-center">
                    <div class="text-2xl font-bold text-error"><?= $answeredQuestions - $correctAnswers ?></div>
                    <div class="text-sm">إجابات خاطئة</div>
                </div>
            </div>

            <div class="card bg-base-100 shadow">
                <div class="card-body p-4 text-center">
                    <div class="text-2xl font-bold text-gray-500"><?= $skippedQuestions ?></div>
                    <div class="text-sm">أسئلة متروكة</div>
                </div>
            </div>

            <div class="card bg-base-100 shadow">
                <div class="card-body p-4 text-center">
                    <div class="text-2xl font-bold text-info"><?= gmdate("i:s", $attempt['time_taken']) ?></div>
                    <div class="text-sm">الوقت المستغرق</div>
                </div>
            </div>
        </div>

        <!-- Questions and Answers -->
        <div class="card bg-base-100 shadow-xl">
            <div class="card-body">
                <h2 class="text-xl font-bold mb-6">
                    <i class="fas fa-question-circle text-primary ml-2"></i>
                    الأسئلة والإجابات
                </h2>

                <div class="space-y-6">
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="border rounded-lg p-4 <?php
                        if ($question['selected_option_id'] === null) {
                            echo 'skipped-answer';
                        } elseif ($question['answer_correct']) {
                            echo 'correct-answer';
                        } else {
                            echo 'wrong-answer';
                        }
                        ?>">
                            <!-- Question Header -->
                            <div class="flex justify-between items-start mb-3">
                                <h3 class="font-bold">
                                    <span class="text-lg ml-2">س<?= $index + 1 ?>:</span>
                                    <?= e($question['question_text']) ?>
                                </h3>
                                <div class="flex items-center gap-2">
                                    <span class="badge badge-ghost"><?= $question['points'] ?> نقطة</span>
                                    <?php if ($question['selected_option_id'] === null): ?>
                                        <span class="badge badge-warning gap-1">
                                            <i class="fas fa-minus-circle text-xs"></i>
                                            متروك
                                        </span>
                                    <?php elseif ($question['answer_correct']): ?>
                                        <span class="badge badge-success gap-1">
                                            <i class="fas fa-check-circle text-xs"></i>
                                            صحيح
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-error gap-1">
                                            <i class="fas fa-times-circle text-xs"></i>
                                            خطأ
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Question Image if exists -->
                            <?php if ($question['question_image']): ?>
                                <div class="mb-4">
                                    <img src="<?= BASE_URL ?>/uploads/questions/<?= e($question['question_image']) ?>"
                                        alt="صورة السؤال" class="rounded-lg max-h-48 mx-auto">
                                </div>
                            <?php endif; ?>

                            <!-- Options -->
                            <div class="space-y-2">
                                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                    <div class="flex items-start gap-3 p-2 rounded <?php
                                    if ($option['id'] == $question['selected_option_id']) {
                                        echo $option['is_correct'] ? 'bg-success/20' : 'bg-error/20';
                                    } elseif ($option['is_correct'] && !$question['answer_correct']) {
                                        echo 'bg-success/10 border border-success';
                                    }
                                    ?>">
                                        <div class="flex items-center gap-2">
                                            <?php if ($option['id'] == $question['selected_option_id']): ?>
                                                <i class="fas fa-<?= $option['is_correct'] ? 'check' : 'times' ?>-circle 
                                                   text-<?= $option['is_correct'] ? 'success' : 'error' ?>"></i>
                                            <?php elseif ($option['is_correct'] && !$question['answer_correct']): ?>
                                                <i class="fas fa-check-circle text-success"></i>
                                            <?php else: ?>
                                                <span class="w-4"></span>
                                            <?php endif; ?>
                                            <span class="font-bold"><?= chr(65 + $optionIndex) ?>.</span>
                                        </div>
                                        <span class="flex-1"><?= e($option['option_text']) ?></span>

                                        <?php if ($option['id'] == $question['selected_option_id']): ?>
                                            <span class="text-sm text-gray-600">(إجابة الطالب)</span>
                                        <?php elseif ($option['is_correct'] && !$question['answer_correct'] && $question['selected_option_id'] !== null): ?>
                                            <span class="text-sm text-success font-medium">(الإجابة الصحيحة)</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Points Earned -->
                            <div class="mt-3 text-sm text-gray-600 text-left">
                                النقاط المكتسبة: <strong><?= $question['points_earned'] ?? 0 ?></strong> من
                                <?= $question['points'] ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Summary Footer -->
        <div class="card bg-base-100 shadow-xl mt-6">
            <div class="card-body">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-3xl font-bold text-primary"><?= $earnedPoints ?>/<?= $totalPoints ?></div>
                        <div class="text-sm text-gray-600">إجمالي النقاط</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold text-success">
                            <?= round(($correctAnswers / count($questions)) * 100) ?>%
                        </div>
                        <div class="text-sm text-gray-600">دقة الإجابات</div>
                    </div>
                    <div>
                        <div class="text-3xl font-bold text-info">
                            <?= round($attempt['time_taken'] / count($questions)) ?>
                            <span class="text-lg">ثانية</span>
                        </div>
                        <div class="text-sm text-gray-600">متوسط الوقت لكل سؤال</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-center gap-3 mt-6 no-print">
            <button onclick="window.print()" class="btn btn-outline">
                <i class="fas fa-print ml-2"></i>
                طباعة التقرير
            </button>
            <a href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $attempt['quiz_id'] ?>"
                class="btn btn-primary">
                <i class="fas fa-arrow-right ml-2"></i>
                العودة للنتائج
            </a>
        </div>
    </div>

    <script>
        // Add keyboard shortcut for printing
        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>

</html>