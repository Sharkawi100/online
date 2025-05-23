<?php
// teacher/quizzes/create.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is teacher/admin
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCSRF($csrf)) {
        $error = 'انتهت صلاحية الجلسة. يرجى إعادة المحاولة.';
    } else {
        try {
            $pdo->beginTransaction();

            // Quiz data
            $title = sanitize($_POST['title']);
            $description = sanitize($_POST['description']);
            $subject_id = $_POST['subject_id'] ?: null;
            $grade = (int) $_POST['grade'];
            $difficulty = $_POST['difficulty'];
            $time_limit = (int) $_POST['time_limit'];
            $language = $_POST['language'] ?? 'ar';
            $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffle_answers = isset($_POST['shuffle_answers']) ? 1 : 0;
            $show_results = isset($_POST['show_results']) ? 1 : 0;
            $is_practice = isset($_POST['is_practice']) ? 1 : 0;

            // Generate PIN
            $pin_code = generatePIN();

            // Insert quiz
            $stmt = $pdo->prepare("
                INSERT INTO quizzes (
                    teacher_id, title, description, subject_id, grade, 
                    difficulty, time_limit, language, pin_code,
                    shuffle_questions, shuffle_answers, show_results, is_practice
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $_SESSION['user_id'],
                $title,
                $description,
                $subject_id,
                $grade,
                $difficulty,
                $time_limit,
                $language,
                $pin_code,
                $shuffle_questions,
                $shuffle_answers,
                $show_results,
                $is_practice
            ]);

            $quiz_id = $pdo->lastInsertId();

            // Process questions
            $questions = $_POST['questions'] ?? [];
            $question_order = 0;

            foreach ($questions as $qIndex => $questionData) {
                if (empty($questionData['text']))
                    continue;

                $question_order++;

                // Insert question
                $stmt = $pdo->prepare("
                    INSERT INTO questions (
                        quiz_id, question_text, question_type, points, order_index
                    ) VALUES (?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $quiz_id,
                    sanitize($questionData['text']),
                    'multiple_choice',
                    (int) ($questionData['points'] ?? 1),
                    $question_order
                ]);

                $question_id = $pdo->lastInsertId();

                // Handle question image upload
                if (
                    isset($_FILES['questions']['tmp_name'][$qIndex]['image']) &&
                    $_FILES['questions']['tmp_name'][$qIndex]['image']
                ) {

                    $uploadDir = '../../uploads/questions/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $imageInfo = getimagesize($_FILES['questions']['tmp_name'][$qIndex]['image']);
                    if ($imageInfo !== false) {
                        $extension = image_type_to_extension($imageInfo[2]);
                        $filename = 'q_' . $question_id . '_' . time() . $extension;
                        $uploadPath = $uploadDir . $filename;

                        if (move_uploaded_file($_FILES['questions']['tmp_name'][$qIndex]['image'], $uploadPath)) {
                            $stmt = $pdo->prepare("UPDATE questions SET question_image = ? WHERE id = ?");
                            $stmt->execute([$filename, $question_id]);
                        }
                    }
                }

                // Insert options
                $options = $questionData['options'] ?? [];
                $option_order = 0;
                $has_correct = false;

                foreach ($options as $oIndex => $optionText) {
                    if (empty($optionText))
                        continue;

                    $option_order++;
                    $is_correct = isset($questionData['correct']) &&
                        $questionData['correct'] == $oIndex ? 1 : 0;

                    if ($is_correct)
                        $has_correct = true;

                    $stmt = $pdo->prepare("
                        INSERT INTO options (
                            question_id, option_text, is_correct, order_index
                        ) VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $question_id,
                        sanitize($optionText),
                        $is_correct,
                        $option_order
                    ]);
                }

                // Validate at least one correct answer
                if (!$has_correct) {
                    throw new Exception('كل سؤال يجب أن يحتوي على إجابة صحيحة واحدة على الأقل');
                }
            }

            // Validate at least one question
            if ($question_order == 0) {
                throw new Exception('يجب إضافة سؤال واحد على الأقل');
            }

            $pdo->commit();
            $success = "تم إنشاء الاختبار بنجاح! رمز الدخول: <strong class='pin-code'>{$pin_code}</strong>";

            // Clear form data
            $_POST = [];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// Get subjects for dropdown
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY name_ar")->fetchAll();

// Generate CSRF token
$csrfToken = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء اختبار جديد - <?= e(getSetting('site_name')) ?></title>

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

        .pin-code {
            font-family: monospace;
            letter-spacing: 0.2em;
            font-size: 1.5rem;
            color: #3b82f6;
        }

        .sortable-ghost {
            opacity: 0.5;
        }

        .option-radio {
            appearance: none;
            -webkit-appearance: none;
            width: 1.25rem;
            height: 1.25rem;
            border: 2px solid #d1d5db;
            border-radius: 50%;
            margin-left: 0.5rem;
            cursor: pointer;
            position: relative;
        }

        .option-radio:checked {
            border-color: #10b981;
            background-color: #10b981;
        }

        .option-radio:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.75rem;
            font-weight: bold;
        }
    </style>
</head>

<body class="bg-gray-50" x-data="quizBuilder()">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <a href="/teacher/" class="btn btn-ghost btn-sm">
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <h1 class="text-xl font-bold">إنشاء اختبار جديد</h1>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="addQuestion()" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة سؤال
                    </button>
                    <button form="quiz-form" type="submit" class="btn btn-sm btn-success">
                        <i class="fas fa-save ml-2"></i>
                        حفظ الاختبار
                    </button>
                </div>
            </div>
        </div>
    </header>

    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success mb-6">
                <i class="fas fa-check-circle"></i>
                <div>
                    <h3 class="font-bold">تم إنشاء الاختبار بنجاح!</h3>
                    <div class="text-sm"><?= $success ?></div>
                    <button onclick="copyToClipboard('<?= $pin_code ?? '' ?>')" class="btn btn-sm btn-ghost mt-2">
                        <i class="fas fa-copy ml-2"></i>
                        نسخ الرمز
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form id="quiz-form" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <!-- Quiz Basic Info -->
            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-info-circle ml-2"></i>
                        معلومات الاختبار
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">عنوان الاختبار</span>
                            </label>
                            <input type="text" name="title" placeholder="مثال: اختبار الرياضيات - الفصل الأول"
                                class="input input-bordered" required>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المادة</span>
                            </label>
                            <select name="subject_id" class="select select-bordered">
                                <option value="">عام</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>"><?= e($subject['name_ar']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الصف الدراسي</span>
                            </label>
                            <select name="grade" class="select select-bordered" required>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>"><?= e(getGradeName($i)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">مستوى الصعوبة</span>
                            </label>
                            <select name="difficulty" class="select select-bordered">
                                <option value="easy">سهل</option>
                                <option value="medium" selected>متوسط</option>
                                <option value="hard">صعب</option>
                                <option value="mixed">متنوع</option>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الوقت المحدد (دقائق)</span>
                                <span class="label-text-alt">0 = غير محدد</span>
                            </label>
                            <input type="number" name="time_limit" value="30" min="0" max="180"
                                class="input input-bordered">
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">لغة الاختبار</span>
                            </label>
                            <select name="language" class="select select-bordered">
                                <option value="ar">العربية</option>
                                <option value="en">English</option>
                            </select>
                        </div>

                        <div class="form-control md:col-span-2">
                            <label class="label">
                                <span class="label-text">وصف الاختبار</span>
                            </label>
                            <textarea name="description" rows="2" placeholder="وصف مختصر للاختبار..."
                                class="textarea textarea-bordered"></textarea>
                        </div>
                    </div>

                    <!-- Quiz Options -->
                    <div class="divider">خيارات الاختبار</div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الأسئلة</span>
                            <input type="checkbox" name="shuffle_questions" value="1" class="checkbox" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الإجابات</span>
                            <input type="checkbox" name="shuffle_answers" value="1" class="checkbox" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">عرض النتائج بعد الانتهاء</span>
                            <input type="checkbox" name="show_results" value="1" class="checkbox" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">وضع التدريب (عرض الإجابات فوراً)</span>
                            <input type="checkbox" name="is_practice" value="1" class="checkbox">
                        </label>
                    </div>
                </div>
            </div>

            <!-- Questions Section -->
            <div class="space-y-4" x-ref="questionsContainer">
                <template x-for="(question, qIndex) in questions" :key="question.id">
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <div class="flex items-start justify-between mb-4">
                                <h3 class="card-title">
                                    <i class="fas fa-question-circle ml-2"></i>
                                    السؤال <span x-text="qIndex + 1"></span>
                                </h3>
                                <div class="flex items-center gap-2">
                                    <input type="number" :name="`questions[${qIndex}][points]`"
                                        x-model="question.points" min="1" max="10"
                                        class="input input-sm input-bordered w-20" placeholder="النقاط">
                                    <button type="button" @click="removeQuestion(qIndex)"
                                        class="btn btn-sm btn-ghost text-error">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Question Text -->
                            <div class="form-control mb-4">
                                <label class="label">
                                    <span class="label-text">نص السؤال</span>
                                </label>
                                <textarea :name="`questions[${qIndex}][text]`" rows="2" x-model="question.text"
                                    placeholder="اكتب السؤال هنا..." class="textarea textarea-bordered"
                                    required></textarea>
                            </div>

                            <!-- Question Image -->
                            <div class="form-control mb-4">
                                <label class="label">
                                    <span class="label-text">صورة السؤال (اختياري)</span>
                                </label>
                                <input type="file" :name="`questions[${qIndex}][image]`" accept="image/*"
                                    class="file-input file-input-bordered">
                            </div>

                            <!-- Options -->
                            <div class="space-y-2">
                                <label class="label">
                                    <span class="label-text">الخيارات (اختر الإجابة الصحيحة)</span>
                                </label>

                                <template x-for="(option, oIndex) in question.options" :key="oIndex">
                                    <div class="flex items-center gap-2">
                                        <input type="radio" :name="`questions[${qIndex}][correct]`" :value="oIndex"
                                            x-model="question.correct" class="option-radio" required>
                                        <input type="text" :name="`questions[${qIndex}][options][]`"
                                            x-model="option.text" placeholder="اكتب الخيار هنا..."
                                            class="input input-bordered flex-1" required>
                                        <span class="badge badge-lg">
                                            <span x-text="String.fromCharCode(65 + oIndex)"></span>
                                        </span>
                                        <button type="button" @click="removeOption(qIndex, oIndex)"
                                            x-show="question.options.length > 2"
                                            class="btn btn-sm btn-ghost text-error">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </template>

                                <button type="button" @click="addOption(qIndex)" x-show="question.options.length < 6"
                                    class="btn btn-sm btn-ghost">
                                    <i class="fas fa-plus ml-2"></i>
                                    إضافة خيار
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Empty State -->
                <div x-show="questions.length === 0" class="text-center py-16">
                    <i class="fas fa-clipboard-list text-6xl text-gray-300 mb-4"></i>
                    <p class="text-xl text-gray-500 mb-4">لم تقم بإضافة أي أسئلة بعد</p>
                    <button type="button" @click="addQuestion()" class="btn btn-primary">
                        <i class="fas fa-plus ml-2"></i>
                        إضافة السؤال الأول
                    </button>
                </div>
            </div>

            <!-- Add Question Button -->
            <div x-show="questions.length > 0" class="text-center mt-6">
                <button type="button" @click="addQuestion()" class="btn btn-primary">
                    <i class="fas fa-plus ml-2"></i>
                    إضافة سؤال آخر
                </button>
            </div>
        </form>
    </div>

    <script>
        function quizBuilder() {
            return {
                questions: [],
                questionIdCounter: 0,

                init() {
                    // Add first question by default
                    this.addQuestion();
                },

                addQuestion() {
                    this.questions.push({
                        id: ++this.questionIdCounter,
                        text: '',
                        points: 1,
                        correct: 0,
                        options: [
                            { text: '' },
                            { text: '' },
                            { text: '' },
                            { text: '' }
                        ]
                    });
                },

                removeQuestion(index) {
                    if (confirm('هل أنت متأكد من حذف هذا السؤال؟')) {
                        this.questions.splice(index, 1);
                    }
                },

                addOption(questionIndex) {
                    if (this.questions[questionIndex].options.length < 6) {
                        this.questions[questionIndex].options.push({ text: '' });
                    }
                },

                removeOption(questionIndex, optionIndex) {
                    if (this.questions[questionIndex].options.length > 2) {
                        this.questions[questionIndex].options.splice(optionIndex, 1);
                        // Adjust correct answer if needed
                        if (this.questions[questionIndex].correct >= optionIndex) {
                            this.questions[questionIndex].correct = Math.max(0, this.questions[questionIndex].correct - 1);
                        }
                    }
                }
            }
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('تم نسخ الرمز!');
            });
        }
    </script>
</body>

</html>