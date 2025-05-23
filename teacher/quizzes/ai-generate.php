<?php
// teacher/quizzes/ai-generate.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is teacher/admin
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$error = '';
$generatedQuiz = null;

// Handle AI generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $topic = sanitize($_POST['topic']);
    $subject_id = $_POST['subject_id'];
    $grade = (int) $_POST['grade'];
    $difficulty = $_POST['difficulty'];
    $num_questions = (int) $_POST['num_questions'];
    $language = $_POST['language'] ?? 'ar';

    // Here you would integrate with OpenAI/Claude API
    // For now, we'll create a mock generator
    $generatedQuiz = generateMockQuiz($topic, $subject_id, $grade, $difficulty, $num_questions, $language);
}

// Handle saving generated quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    $quizData = json_decode($_POST['quiz_data'], true);

    try {
        $pdo->beginTransaction();

        // Create quiz
        $pin_code = generatePIN();
        $stmt = $pdo->prepare("
            INSERT INTO quizzes (
                teacher_id, title, description, subject_id, grade, 
                difficulty, time_limit, language, pin_code,
                shuffle_questions, shuffle_answers, show_results
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1)
        ");

        $stmt->execute([
            $_SESSION['user_id'],
            $quizData['title'],
            $quizData['description'],
            $quizData['subject_id'],
            $quizData['grade'],
            $quizData['difficulty'],
            $quizData['time_limit'],
            $quizData['language'],
            $pin_code
        ]);

        $quiz_id = $pdo->lastInsertId();

        // Add questions
        foreach ($quizData['questions'] as $qIndex => $question) {
            $stmt = $pdo->prepare("
                INSERT INTO questions (quiz_id, question_text, question_type, points, order_index)
                VALUES (?, ?, 'multiple_choice', ?, ?)
            ");
            $stmt->execute([$quiz_id, $question['text'], $question['points'], $qIndex + 1]);

            $question_id = $pdo->lastInsertId();

            // Add options
            foreach ($question['options'] as $oIndex => $option) {
                $stmt = $pdo->prepare("
                    INSERT INTO options (question_id, option_text, is_correct, order_index)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $question_id,
                    $option['text'],
                    $option['is_correct'] ? 1 : 0,
                    $oIndex + 1
                ]);
            }
        }

        $pdo->commit();
        redirect("/teacher/quizzes/view.php?id=$quiz_id&created=1");

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'فشل حفظ الاختبار: ' . $e->getMessage();
    }
}

// Mock quiz generator function
function generateMockQuiz($topic, $subject_id, $grade, $difficulty, $num_questions, $language)
{
    // This is a mock function. In production, you would call AI API here
    $quiz = [
        'title' => "اختبار في $topic",
        'description' => "اختبار تم إنشاؤه بواسطة الذكاء الاصطناعي حول موضوع $topic",
        'subject_id' => $subject_id,
        'grade' => $grade,
        'difficulty' => $difficulty,
        'language' => $language,
        'time_limit' => $num_questions * 2, // 2 minutes per question
        'questions' => []
    ];

    // Generate mock questions
    for ($i = 1; $i <= $num_questions; $i++) {
        $question = [
            'text' => "سؤال رقم $i حول $topic",
            'points' => $difficulty === 'hard' ? 2 : 1,
            'options' => []
        ];

        // Generate 4 options
        for ($j = 0; $j < 4; $j++) {
            $question['options'][] = [
                'text' => "الخيار " . chr(65 + $j),
                'is_correct' => $j === 0 // First option is correct (would be randomized in real AI)
            ];
        }

        // Shuffle options
        shuffle($question['options']);
        $quiz['questions'][] = $question;
    }

    return $quiz;
}

// Get subjects
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء اختبار بالذكاء الاصطناعي - <?= e(getSetting('site_name')) ?></title>

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
        }

        .ai-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .loading-dots {
            display: inline-block;
            animation: loading 1.4s infinite;
        }

        @keyframes loading {
            0% {
                content: '.';
            }

            33% {
                content: '..';
            }

            66% {
                content: '...';
            }
        }

        .typewriter {
            animation: typing 2s steps(40, end);
        }

        @keyframes typing {
            from {
                width: 0;
            }

            to {
                width: 100%;
            }
        }
    </style>
</head>

<body class="bg-gray-50" x-data="{ 
    generating: false,
    showPreview: false,
    editMode: false,
    selectedQuestion: null
}">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="/teacher/quizzes/" class="btn btn-ghost btn-sm">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <h1 class="text-xl font-bold">
                        <i class="fas fa-robot ml-2 text-purple-600"></i>
                        إنشاء اختبار بالذكاء الاصطناعي
                    </h1>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Generation Form -->
            <div>
                <div class="card bg-base-100 shadow-xl">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-magic ml-2"></i>
                            إعدادات الإنشاء
                        </h2>

                        <?php if ($error): ?>
                            <div class="alert alert-error mb-4">
                                <i class="fas fa-exclamation-circle"></i>
                                <span><?= e($error) ?></span>
                            </div>
                        <?php endif; ?>

                        <form method="POST" @submit="generating = true">
                            <div class="space-y-4">
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">الموضوع أو الوحدة الدراسية</span>
                                    </label>
                                    <textarea name="topic" rows="3"
                                        placeholder="مثال: الكسور العشرية، الحرب العالمية الثانية، قواعد اللغة العربية..."
                                        class="textarea textarea-bordered" required></textarea>
                                    <label class="label">
                                        <span class="label-text-alt">كن محدداً للحصول على أفضل النتائج</span>
                                    </label>
                                </div>

                                <div class="grid grid-cols-2 gap-4">
                                    <div class="form-control">
                                        <label class="label">
                                            <span class="label-text">المادة</span>
                                        </label>
                                        <select name="subject_id" class="select select-bordered" required>
                                            <option value="">اختر المادة</option>
                                            <?php foreach ($subjects as $subject): ?>
                                                <option value="<?= $subject['id'] ?>"><?= e($subject['name_ar']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-control">
                                        <label class="label">
                                            <span class="label-text">الصف</span>
                                        </label>
                                        <select name="grade" class="select select-bordered" required>
                                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                                <option value="<?= $i ?>"><?= e(getGradeName($i)) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <div class="form-control">
                                        <label class="label">
                                            <span class="label-text">الصعوبة</span>
                                        </label>
                                        <select name="difficulty" class="select select-bordered">
                                            <option value="easy">سهل</option>
                                            <option value="medium" selected>متوسط</option>
                                            <option value="hard">صعب</option>
                                        </select>
                                    </div>

                                    <div class="form-control">
                                        <label class="label">
                                            <span class="label-text">عدد الأسئلة</span>
                                        </label>
                                        <input type="number" name="num_questions" value="10" min="5" max="30"
                                            class="input input-bordered">
                                    </div>
                                </div>

                                <div class="divider">خيارات متقدمة</div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">تضمين صور توضيحية</span>
                                        <input type="checkbox" name="include_images" class="checkbox">
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">أسئلة تطبيقية</span>
                                        <input type="checkbox" name="practical_questions" class="checkbox" checked>
                                    </label>
                                </div>

                                <button type="submit" name="generate" value="1" class="btn btn-primary w-full"
                                    :disabled="generating">
                                    <i class="fas fa-sparkles ml-2" x-show="!generating"></i>
                                    <span class="loading loading-spinner" x-show="generating"></span>
                                    <span x-show="!generating">إنشاء الاختبار</span>
                                    <span x-show="generating">جاري الإنشاء<span class="loading-dots">...</span></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- AI Features -->
                <div class="card bg-gradient-to-br from-purple-100 to-pink-100 mt-6">
                    <div class="card-body">
                        <h3 class="font-bold text-purple-800 mb-3">
                            <i class="fas fa-lightbulb ml-2"></i>
                            مميزات الذكاء الاصطناعي
                        </h3>
                        <ul class="space-y-2 text-purple-700">
                            <li class="flex items-start">
                                <i class="fas fa-check-circle ml-2 mt-1 text-green-600"></i>
                                أسئلة متنوعة ومناسبة للمستوى الدراسي
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle ml-2 mt-1 text-green-600"></i>
                                خيارات ذكية ومنطقية للإجابات
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle ml-2 mt-1 text-green-600"></i>
                                تغطية شاملة للموضوع المحدد
                            </li>
                            <li class="flex items-start">
                                <i class="fas fa-check-circle ml-2 mt-1 text-green-600"></i>
                                إمكانية التعديل قبل الحفظ
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Preview Section -->
            <div>
                <?php if ($generatedQuiz): ?>
                    <div class="card bg-base-100 shadow-xl animate__animated animate__fadeIn">
                        <div class="card-body">
                            <div class="flex items-center justify-between mb-4">
                                <h2 class="card-title">
                                    <i class="fas fa-eye ml-2"></i>
                                    معاينة الاختبار
                                </h2>
                                <div class="flex gap-2">
                                    <button @click="editMode = !editMode" class="btn btn-sm btn-ghost">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="quiz_data" value='<?= json_encode($generatedQuiz) ?>'>
                                        <button type="submit" name="save_quiz" value="1" class="btn btn-sm btn-success">
                                            <i class="fas fa-save ml-2"></i>
                                            حفظ الاختبار
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Quiz Info -->
                            <div class="bg-gray-50 rounded-lg p-4 mb-4">
                                <h3 class="font-bold text-lg mb-2" contenteditable x-show="editMode">
                                    <?= e($generatedQuiz['title']) ?>
                                </h3>
                                <h3 class="font-bold text-lg mb-2" x-show="!editMode">
                                    <?= e($generatedQuiz['title']) ?>
                                </h3>
                                <p class="text-gray-600" contenteditable x-show="editMode">
                                    <?= e($generatedQuiz['description']) ?>
                                </p>
                                <p class="text-gray-600" x-show="!editMode">
                                    <?= e($generatedQuiz['description']) ?>
                                </p>
                                <div class="flex gap-4 mt-3 text-sm">
                                    <span class="badge badge-primary">
                                        <?= count($generatedQuiz['questions']) ?> سؤال
                                    </span>
                                    <span class="badge badge-info">
                                        <?= $generatedQuiz['time_limit'] ?> دقيقة
                                    </span>
                                </div>
                            </div>

                            <!-- Questions Preview -->
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($generatedQuiz['questions'] as $qIndex => $question): ?>
                                    <div class="border rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                        <div class="flex items-start justify-between mb-2">
                                            <h4 class="font-medium">
                                                س<?= $qIndex + 1 ?>:
                                                <span contenteditable x-show="editMode">
                                                    <?= e($question['text']) ?>
                                                </span>
                                                <span x-show="!editMode">
                                                    <?= e($question['text']) ?>
                                                </span>
                                            </h4>
                                            <span class="badge badge-sm"><?= $question['points'] ?> نقطة</span>
                                        </div>
                                        <div class="space-y-1 mr-4">
                                            <?php foreach ($question['options'] as $oIndex => $option): ?>
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center text-xs">
                                                        <?= chr(65 + $oIndex) ?>
                                                    </span>
                                                    <span class="<?= $option['is_correct'] ? 'text-green-600 font-medium' : '' ?>">
                                                        <span contenteditable x-show="editMode">
                                                            <?= e($option['text']) ?>
                                                        </span>
                                                        <span x-show="!editMode">
                                                            <?= e($option['text']) ?>
                                                        </span>
                                                    </span>
                                                    <?php if ($option['is_correct']): ?>
                                                        <i class="fas fa-check text-green-600"></i>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="alert alert-info mt-4">
                                <i class="fas fa-info-circle"></i>
                                <span>يمكنك تعديل الأسئلة والإجابات قبل الحفظ</span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Empty State -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body text-center py-16">
                            <div class="text-gray-300 mb-4">
                                <i class="fas fa-robot text-8xl"></i>
                            </div>
                            <h3 class="text-xl text-gray-500 mb-2">لم يتم إنشاء اختبار بعد</h3>
                            <p class="text-gray-400">استخدم النموذج لإنشاء اختبار بالذكاء الاصطناعي</p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Tips -->
                <div class="card bg-yellow-50 border border-yellow-200 mt-6">
                    <div class="card-body">
                        <h3 class="font-bold text-yellow-800 mb-2">
                            <i class="fas fa-lightbulb ml-2"></i>
                            نصائح للحصول على أفضل النتائج
                        </h3>
                        <ul class="text-sm text-yellow-700 space-y-1">
                            <li>• كن محدداً في وصف الموضوع</li>
                            <li>• اذكر المفاهيم الأساسية المطلوب تغطيتها</li>
                            <li>• حدد نوع الأسئلة المفضل (تطبيقية، نظرية، تحليلية)</li>
                            <li>• راجع الأسئلة وعدّلها حسب احتياجاتك</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>