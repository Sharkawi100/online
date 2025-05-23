<?php
// /teacher/quizzes/ai-generate.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Check if user is logged in and is a teacher or admin
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

// Check if AI is enabled
if (!getSetting('ai_enabled', true)) {
    $_SESSION['error'] = 'خدمة الذكاء الاصطناعي غير مفعلة حالياً';
    redirect('/teacher/quizzes/create.php');
}

$teacher_id = $_SESSION['user_id'];
$error = '';
$success = '';
$generated_questions = [];

// Get subjects for dropdown
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY name_ar");
$subjects = $stmt->fetchAll();

// Get teacher's AI usage
$usage = getTeacherAIUsage($teacher_id);

// Handle AI generation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            $params = [
                'teacher_id' => $teacher_id,
                'type' => $_POST['generation_type'],
                'subject_id' => $_POST['subject_id'] ?: null,
                'subject' => $_POST['subject_id'] ? null : sanitize($_POST['custom_subject']),
                'grade' => (int) $_POST['grade'],
                'difficulty' => $_POST['difficulty'],
                'count' => min((int) $_POST['question_count'], getSetting('ai_max_questions_per_request', 10)),
                'topic' => sanitize($_POST['topic'] ?? ''),
                'text' => $_POST['generation_type'] === 'text_based' ? sanitize($_POST['quiz_text']) : null
            ];

            $result = generateQuizQuestions($params);
            $generated_questions = $result['questions'];

            if (empty($generated_questions)) {
                $error = 'لم يتم توليد أي أسئلة. يرجى المحاولة مرة أخرى.';
            } else {
                $_SESSION['ai_generated_questions'] = $generated_questions;
                $_SESSION['ai_generation_params'] = $params;
                $success = 'تم توليد ' . count($generated_questions) . ' سؤال بنجاح!';
            }

        } catch (Exception $e) {
            $error = 'خطأ في توليد الأسئلة: ' . $e->getMessage();
        }
    }
}

// Handle saving to quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_to_quiz'])) {
    if (isset($_SESSION['ai_generated_questions'])) {
        $_SESSION['quiz_creation_data'] = [
            'questions' => $_SESSION['ai_generated_questions'],
            'params' => $_SESSION['ai_generation_params']
        ];
        redirect('/teacher/quizzes/create.php?ai_generated=1');
    }
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>توليد اختبار بالذكاء الاصطناعي - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- TinyMCE for rich text editing -->
    <script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .ai-glow {
            animation: pulse 2s infinite;
            box-shadow: 0 0 20px rgba(139, 92, 246, 0.5);
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.8;
            }
        }
    </style>
</head>

<body class="bg-gray-50" x-data="aiGenerator()">
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="<?= BASE_URL ?>/teacher/" class="btn btn-ghost normal-case text-xl">
                <i class="fas fa-chalkboard-teacher ml-2"></i>
                لوحة المعلم
            </a>
        </div>
        <div class="flex-none">
            <!-- AI Usage Badge -->
            <div class="badge badge-lg badge-primary gap-2 ml-4">
                <i class="fas fa-robot"></i>
                <span><?= $usage['remaining'] ?>/<?= $usage['monthly_limit'] ?> متبقي</span>
            </div>

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

    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">
                <i class="fas fa-magic text-purple-600 ml-2"></i>
                توليد اختبار بالذكاء الاصطناعي
            </h1>
            <div class="breadcrumbs text-sm">
                <ul>
                    <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                    <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                    <li>توليد بالذكاء الاصطناعي</li>
                </ul>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= e($success) ?></span>
            </div>
        <?php endif; ?>

        <!-- AI Usage Stats -->
        <div class="stats shadow mb-8 w-full">
            <div class="stat">
                <div class="stat-figure text-primary">
                    <i class="fas fa-robot text-3xl"></i>
                </div>
                <div class="stat-title">استخدامك الشهري</div>
                <div class="stat-value text-primary"><?= $usage['total_generations'] ?></div>
                <div class="stat-desc">من <?= $usage['monthly_limit'] ?> توليد</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-secondary">
                    <i class="fas fa-question-circle text-3xl"></i>
                </div>
                <div class="stat-title">الأسئلة المولدة</div>
                <div class="stat-value text-secondary"><?= $usage['total_questions'] ?? 0 ?></div>
                <div class="stat-desc">هذا الشهر</div>
            </div>

            <div class="stat">
                <div class="stat-figure text-accent">
                    <i class="fas fa-coins text-3xl"></i>
                </div>
                <div class="stat-title">التكلفة التقديرية</div>
                <div class="stat-value text-accent">$<?= number_format($usage['total_cost'] ?? 0, 2) ?></div>
                <div class="stat-desc">هذا الشهر</div>
            </div>
        </div>

        <!-- Generation Form -->
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="generate" value="1">

            <!-- Generation Type Selection -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-cog text-primary"></i>
                        نوع التوليد
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="card bg-base-200 cursor-pointer hover:shadow-lg transition-shadow"
                            :class="{ 'ring-2 ring-primary ai-glow': generationType === 'general' }">
                            <div class="card-body">
                                <div class="flex items-start gap-4">
                                    <input type="radio" name="generation_type" value="general" x-model="generationType"
                                        class="radio radio-primary">
                                    <div>
                                        <h3 class="font-bold text-lg">أسئلة عامة</h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            توليد أسئلة في أي موضوع دراسي
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </label>

                        <label class="card bg-base-200 cursor-pointer hover:shadow-lg transition-shadow"
                            :class="{ 'ring-2 ring-primary ai-glow': generationType === 'text_based' }">
                            <div class="card-body">
                                <div class="flex items-start gap-4">
                                    <input type="radio" name="generation_type" value="text_based"
                                        x-model="generationType" class="radio radio-primary">
                                    <div>
                                        <h3 class="font-bold text-lg">فهم المقروء</h3>
                                        <p class="text-sm text-gray-600 mt-1">
                                            أسئلة بناءً على نص معين
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Quiz Parameters -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-sliders-h text-primary"></i>
                        إعدادات الاختبار
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Subject -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المادة *</span>
                            </label>
                            <select name="subject_id" class="select select-bordered" x-model="subjectId" required>
                                <option value="">اختر المادة</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>"><?= e($subject['name_ar']) ?></option>
                                <?php endforeach; ?>
                                <option value="0">مادة أخرى...</option>
                            </select>
                        </div>

                        <!-- Custom Subject -->
                        <div class="form-control" x-show="subjectId === '0'" x-transition>
                            <label class="label">
                                <span class="label-text">اسم المادة *</span>
                            </label>
                            <input type="text" name="custom_subject" class="input input-bordered"
                                placeholder="أدخل اسم المادة">
                        </div>

                        <!-- Grade -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الصف الدراسي *</span>
                            </label>
                            <select name="grade" class="select select-bordered" required>
                                <option value="">اختر الصف</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>"><?= getGradeName($i) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <!-- Difficulty -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">مستوى الصعوبة *</span>
                            </label>
                            <select name="difficulty" class="select select-bordered" required>
                                <option value="easy">سهل</option>
                                <option value="medium" selected>متوسط</option>
                                <option value="hard">صعب</option>
                            </select>
                        </div>

                        <!-- Question Count -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">عدد الأسئلة *</span>
                                <span class="label-text-alt">الحد الأقصى:
                                    <?= getSetting('ai_max_questions_per_request', 10) ?></span>
                            </label>
                            <input type="number" name="question_count" class="input input-bordered" min="1"
                                max="<?= getSetting('ai_max_questions_per_request', 10) ?>" value="5" required>
                        </div>

                        <!-- Topic -->
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الموضوع (اختياري)</span>
                            </label>
                            <input type="text" name="topic" class="input input-bordered"
                                placeholder="مثال: الكسور، الخلية، القواعد النحوية">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Text Input for Text-Based Questions -->
            <div class="card bg-base-100 shadow-xl" x-show="generationType === 'text_based'" x-transition>
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-file-alt text-primary"></i>
                        النص المقروء
                    </h2>

                    <div class="space-y-4">
                        <!-- Text Input Options -->
                        <div class="flex gap-4 mb-4">
                            <button type="button" @click="textInputMethod = 'manual'"
                                :class="{ 'btn-primary': textInputMethod === 'manual' }" class="btn btn-outline">
                                <i class="fas fa-keyboard ml-2"></i>
                                إدخال يدوي
                            </button>
                            <button type="button" @click="textInputMethod = 'generate'"
                                :class="{ 'btn-primary': textInputMethod === 'generate' }" class="btn btn-outline">
                                <i class="fas fa-magic ml-2"></i>
                                توليد نص بالذكاء الاصطناعي
                            </button>
                        </div>

                        <!-- Manual Text Input -->
                        <div x-show="textInputMethod === 'manual'">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">النص *</span>
                                    <span class="label-text-alt">200-1000 كلمة</span>
                                </label>
                                <textarea name="quiz_text" id="quiz_text" rows="10"></textarea>
                            </div>
                        </div>

                        <!-- AI Text Generation -->
                        <div x-show="textInputMethod === 'generate'" class="space-y-4">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">موضوع النص</span>
                                </label>
                                <input type="text" x-model="textTopic" class="input input-bordered"
                                    placeholder="مثال: الماء، الصحراء، التكنولوجيا">
                            </div>
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">طول النص</span>
                                </label>
                                <select x-model="textLength" class="select select-bordered">
                                    <option value="200">قصير (200 كلمة)</option>
                                    <option value="400" selected>متوسط (400 كلمة)</option>
                                    <option value="600">طويل (600 كلمة)</option>
                                </select>
                            </div>
                            <button type="button" @click="generateText()" :disabled="!textTopic || generatingText"
                                class="btn btn-secondary">
                                <i class="fas fa-magic ml-2" :class="{ 'fa-spin': generatingText }"></i>
                                <span x-text="generatingText ? 'جاري التوليد...' : 'توليد النص'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between">
                <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-ghost">
                    <i class="fas fa-arrow-right ml-2"></i>
                    رجوع
                </a>
                <button type="submit" class="btn btn-primary btn-lg" :disabled="<?= $usage['remaining'] ?> <= 0">
                    <i class="fas fa-magic ml-2"></i>
                    توليد الأسئلة
                </button>
            </div>
        </form>

        <?php if (!empty($generated_questions)): ?>
            <!-- Generated Questions Preview -->
            <div class="card bg-base-100 shadow-xl mt-8">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-eye text-primary"></i>
                        معاينة الأسئلة المولدة
                    </h2>

                    <div class="space-y-4">
                        <?php foreach ($generated_questions as $index => $question): ?>
                            <div class="card bg-base-200">
                                <div class="card-body">
                                    <h3 class="font-bold">السؤال <?= $index + 1 ?>: <?= e($question['question_text']) ?></h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mt-4">
                                        <?php foreach ($question['options'] as $optIndex => $option): ?>
                                            <div
                                                class="flex items-center gap-2 
                                                        <?= $optIndex == $question['correct_index'] ? 'text-success font-bold' : '' ?>">
                                                <span><?= chr(1571 + $optIndex) ?>)</span>
                                                <span><?= e($option) ?></span>
                                                <?php if ($optIndex == $question['correct_index']): ?>
                                                    <i class="fas fa-check-circle text-success"></i>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" class="mt-6">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="save_to_quiz" value="1">
                        <div class="flex gap-3 justify-end">
                            <button type="button" onclick="location.reload()" class="btn btn-ghost">
                                <i class="fas fa-redo ml-2"></i>
                                إعادة التوليد
                            </button>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save ml-2"></i>
                                حفظ وإنشاء اختبار
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function aiGenerator() {
            return {
                generationType: 'general',
                subjectId: '',
                textInputMethod: 'manual',
                textTopic: '',
                textLength: '400',
                generatingText: false,

                init() {
                    // Initialize TinyMCE
                    tinymce.init({
                        selector: '#quiz_text',
                        height: 400,
                        language: 'ar',
                        directionality: 'rtl',
                        plugins: 'lists link image table code help wordcount',
                        toolbar: 'undo redo | formatselect | bold italic | alignright aligncenter alignleft | bullist numlist | removeformat',
                        menubar: false,
                        content_style: 'body { font-family: Tajawal, Arial; font-size: 14pt; }'
                    });
                },

                async generateText() {
                    if (!this.textTopic) return;

                    this.generatingText = true;

                    try {
                        const formData = new FormData();
                        formData.append('action', 'generate_text');
                        formData.append('topic', this.textTopic);
                        formData.append('length', this.textLength);
                        formData.append('grade', document.querySelector('[name="grade"]').value);

                        const response = await fetch('<?= BASE_URL ?>/teacher/ajax/ai-generate-text.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (result.success) {
                            tinymce.get('quiz_text').setContent(result.text);
                        } else {
                            alert('خطأ: ' + result.error);
                        }
                    } catch (error) {
                        alert('حدث خطأ في توليد النص');
                    }

                    this.generatingText = false;
                }
            }
        }
    </script>
</body>

</html>