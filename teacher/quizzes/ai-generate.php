<?php
// teacher/quizzes/ai-generate.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Initialize variables
$success = '';
$error = '';
$generated_questions = [];
$ai_usage = null;

// Get teacher's AI usage stats
try {
    $ai_usage = getTeacherAIUsage($_SESSION['user_id']);
} catch (Exception $e) {
    // AI might not be configured
}

// Check if AI is enabled
$ai_enabled = getSetting('ai_enabled', false);
if (!$ai_enabled) {
    $error = 'ميزة الذكاء الاصطناعي غير مفعلة حالياً';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ai_enabled) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        // Validate inputs
        $errors = [];
        $subject_id = (int) ($_POST['subject_id'] ?? 0);
        $grade = (int) ($_POST['grade'] ?? 0);
        $difficulty = sanitize($_POST['difficulty'] ?? 'medium');
        $question_count = (int) ($_POST['question_count'] ?? 5);
        $generation_type = sanitize($_POST['generation_type'] ?? 'general');
        $topic = sanitize($_POST['topic'] ?? '');
        $text_content = $_POST['text_content'] ?? '';

        if (empty($subject_id))
            $errors[] = 'يرجى اختيار المادة';
        if (empty($grade))
            $errors[] = 'يرجى اختيار الصف';
        if ($question_count < 1 || $question_count > 20)
            $errors[] = 'عدد الأسئلة يجب أن يكون بين 1 و 20';

        if ($generation_type === 'text_based' && empty($text_content)) {
            $errors[] = 'يرجى إدخال النص للقراءة';
        }

        if (empty($errors)) {
            try {
                // Prepare parameters for AI generation
                $params = [
                    'teacher_id' => $_SESSION['user_id'],
                    'subject' => $subject_id,  // Important: use 'subject' not 'subject_id'
                    'subject_id' => $subject_id,
                    'grade' => $grade,
                    'difficulty' => $difficulty,
                    'count' => $question_count,
                    'type' => $generation_type,
                    'topic' => $topic
                ];

                if ($generation_type === 'text_based') {
                    $params['text'] = $text_content;
                }

                // Generate questions
                $result = generateQuizQuestions($params);
                $generated_questions = $result['questions'];

                // Store in session to pass to create.php
                $_SESSION['ai_questions'] = array_map(function ($q) {
                    return [
                        'text' => $q['question_text'],
                        'options' => array_map(function ($opt) {
                            return ['text' => $opt];
                        }, $q['options']),
                        'correct' => $q['correct_index'],
                        'points' => 10,
                        'ai_generated' => true
                    ];
                }, $generated_questions);

                $success = sprintf(
                    'تم توليد %d سؤال بنجاح! (استخدم %d رمز)',
                    count($generated_questions),
                    $result['tokens_used']
                );

                // Update usage stats
                $ai_usage = getTeacherAIUsage($_SESSION['user_id']);

            } catch (Exception $e) {
                $error = 'خطأ في توليد الأسئلة: ' . $e->getMessage();
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

// Get subjects for dropdown
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY order_index")->fetchAll();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>توليد الأسئلة بالذكاء الاصطناعي - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .loading-dots::after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes dots {

            0%,
            20% {
                content: '';
            }

            40% {
                content: '.';
            }

            60% {
                content: '..';
            }

            80%,
            100% {
                content: '...';
            }
        }
    </style>
</head>

<body class="bg-gray-50" x-data="aiGenerator">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none">
            <a href="create.php" class="btn btn-ghost">
                <i class="fas fa-arrow-right ml-2"></i>
                العودة لإنشاء الاختبار
            </a>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <h1 class="text-3xl font-bold mb-8">
            <i class="fas fa-magic text-primary ml-2"></i>
            توليد الأسئلة بالذكاء الاصطناعي
        </h1>

        <?php if ($ai_usage && $ai_enabled): ?>
            <!-- Usage Stats -->
            <div class="stats shadow mb-6 w-full">
                <div class="stat">
                    <div class="stat-figure text-primary">
                        <i class="fas fa-chart-line text-3xl"></i>
                    </div>
                    <div class="stat-title">الاستخدام الشهري</div>
                    <div class="stat-value"><?= $ai_usage['total_generations'] ?> / <?= $ai_usage['monthly_limit'] ?></div>
                    <div class="stat-desc">
                        <progress class="progress progress-primary w-56" value="<?= $ai_usage['percentage_used'] ?>"
                            max="100"></progress>
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-secondary">
                        <i class="fas fa-question-circle text-3xl"></i>
                    </div>
                    <div class="stat-title">الأسئلة المولدة</div>
                    <div class="stat-value"><?= $ai_usage['total_questions'] ?? 0 ?></div>
                    <div class="stat-desc">هذا الشهر</div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-accent">
                        <i class="fas fa-bolt text-3xl"></i>
                    </div>
                    <div class="stat-title">المتبقي</div>
                    <div class="stat-value text-accent"><?= $ai_usage['remaining'] ?></div>
                    <div class="stat-desc">توليد</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success mb-6">
                <i class="fas fa-check-circle"></i>
                <span><?= $success ?></span>
                <a href="create.php" class="btn btn-sm btn-ghost">
                    الذهاب لإنشاء الاختبار
                    <i class="fas fa-arrow-left mr-2"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($ai_enabled): ?>
            <form method="POST" @submit="handleSubmit">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                <!-- Generation Settings -->
                <div class="card bg-base-100 shadow-xl mb-6">
                    <div class="card-body">
                        <h2 class="card-title mb-4">
                            <i class="fas fa-cog text-primary"></i>
                            إعدادات التوليد
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">نوع التوليد</span>
                                </label>
                                <select name="generation_type" x-model="generationType" class="select select-bordered">
                                    <option value="general">أسئلة عامة</option>
                                    <option value="text_based">أسئلة فهم المقروء</option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">عدد الأسئلة</span>
                                </label>
                                <input type="number" name="question_count" value="5" class="input input-bordered" min="1"
                                    max="20" required>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">المادة *</span>
                                </label>
                                <select name="subject_id" class="select select-bordered" required>
                                    <option value="">اختر المادة</option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?= $subject['id'] ?>">
                                            <?= e($subject['name_ar']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">الصف الدراسي *</span>
                                </label>
                                <select name="grade" class="select select-bordered" required>
                                    <option value="">اختر الصف</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?= $i ?>">
                                            <?= getGradeName($i) ?>
                                        </option>
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
                                </select>
                            </div>

                            <div class="form-control" x-show="generationType === 'general'">
                                <label class="label">
                                    <span class="label-text">الموضوع (اختياري)</span>
                                </label>
                                <input type="text" name="topic" class="input input-bordered"
                                    placeholder="مثال: الكسور العشرية">
                            </div>
                        </div>

                        <!-- Text Input for Reading Comprehension -->
                        <div class="form-control mt-4" x-show="generationType === 'text_based'" x-transition>
                            <label class="label">
                                <span class="label-text">النص للقراءة *</span>
                            </label>
                            <textarea name="text_content" class="textarea textarea-bordered" rows="8"
                                placeholder="الصق أو اكتب النص هنا..."
                                x-bind:required="generationType === 'text_based'"></textarea>
                            <label class="label">
                                <span class="label-text-alt">سيتم توليد أسئلة فهم واستيعاب بناءً على هذا النص</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Preview Generated Questions -->
                <?php if (!empty($generated_questions)): ?>
                    <div class="card bg-base-100 shadow-xl mb-6">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-eye text-info"></i>
                                معاينة الأسئلة المولدة
                            </h2>

                            <?php foreach ($generated_questions as $index => $question): ?>
                                <div class="border rounded-lg p-4 mb-3 bg-base-200">
                                    <h3 class="font-bold mb-2">السؤال <?= $index + 1 ?>:</h3>
                                    <p class="mb-3"><?= e($question['question_text']) ?></p>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                        <?php foreach ($question['options'] as $oIndex => $option): ?>
                                            <div
                                                class="flex items-center gap-2 <?= $oIndex == $question['correct_index'] ? 'text-success font-bold' : '' ?>">
                                                <span><?= ['أ', 'ب', 'ج', 'د'][$oIndex] ?>)</span>
                                                <span><?= e($option) ?></span>
                                                <?php if ($oIndex == $question['correct_index']): ?>
                                                    <i class="fas fa-check-circle text-success"></i>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="card-actions justify-end mt-4">
                                <a href="create.php" class="btn btn-primary">
                                    <i class="fas fa-check ml-2"></i>
                                    استخدام هذه الأسئلة
                                </a>
                                <button type="submit" name="regenerate" value="1" class="btn btn-ghost">
                                    <i class="fas fa-redo ml-2"></i>
                                    إعادة التوليد
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Actions -->
                <div class="flex justify-between">
                    <a href="create.php" class="btn btn-ghost">
                        <i class="fas fa-times ml-2"></i>
                        إلغاء
                    </a>
                    <button type="submit" class="btn btn-primary" :disabled="isLoading">
                        <span x-show="!isLoading">
                            <i class="fas fa-magic ml-2"></i>
                            توليد الأسئلة
                        </span>
                        <span x-show="isLoading" class="loading-dots">
                            <i class="fas fa-spinner fa-spin ml-2"></i>
                            جاري التوليد
                        </span>
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <h3 class="font-bold">ميزة الذكاء الاصطناعي غير متاحة</h3>
                    <p>يرجى التواصل مع الإدارة لتفعيل هذه الميزة</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Tips -->
        <div class="card bg-info text-info-content mt-6">
            <div class="card-body">
                <h2 class="card-title">
                    <i class="fas fa-lightbulb"></i>
                    نصائح للحصول على أفضل النتائج
                </h2>
                <ul class="list-disc list-inside space-y-1">
                    <li>كن دقيقاً في اختيار المادة والصف للحصول على أسئلة مناسبة</li>
                    <li>عند استخدام فهم المقروء، اختر نصوصاً واضحة ومناسبة للفئة العمرية</li>
                    <li>يمكنك تحديد موضوع معين للحصول على أسئلة أكثر تخصصاً</li>
                    <li>راجع الأسئلة المولدة وعدّلها حسب الحاجة قبل حفظ الاختبار</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('aiGenerator', () => ({
                generationType: 'general',
                isLoading: false,

                handleSubmit(e) {
                    if (!this.isLoading) {
                        this.isLoading = true;
                        // Form will submit normally
                    }
                }
            }));
        });
    </script>
</body>

</html>