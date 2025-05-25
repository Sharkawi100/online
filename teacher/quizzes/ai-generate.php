<?php
// teacher/quizzes/ai-generate.php - Improved with better flow
require_once '../../config/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ai_functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check authentication
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Initialize variables
$success = '';
$error = '';
$generated_questions = [];
$generated_text = '';
$quiz_data = [];

// Check if coming from create.php with quiz data
if (isset($_SESSION['quiz_draft'])) {
    $quiz_data = $_SESSION['quiz_draft'];
}

// Get AI usage stats
try {
    $ai_usage = getTeacherAIUsage($_SESSION['user_id']);
} catch (Exception $e) {
    $ai_usage = null;
}

// Check if AI is enabled
$ai_enabled = getSetting('ai_enabled', false);
if (!$ai_enabled) {
    $_SESSION['error'] = 'ميزة الذكاء الاصطناعي غير مفعلة حالياً';
    redirect('/teacher/quizzes/create.php');
}

// Handle AJAX text generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_generate_text'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $topic = sanitize($_POST['text_topic'] ?? '');
        $grade = (int) ($_POST['grade'] ?? 0);
        $length = (int) ($_POST['text_length'] ?? 250);

        if (empty($topic) || empty($grade)) {
            throw new Exception('يرجى ملء جميع الحقول المطلوبة');
        }

        $result = generateReadingText([
            'topic' => $topic,
            'grade' => $grade,
            'length' => $length
        ]);

        echo json_encode([
            'success' => true,
            'text' => $result['text'],
            'tokens' => $result['tokens_used']
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// Handle question generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_questions'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            // Collect all quiz data
            $quiz_data = [
                'title' => sanitize($_POST['title'] ?? ''),
                'description' => $_POST['description'] ?? '',
                'subject_id' => (int) ($_POST['subject_id'] ?? 0),
                'grade' => (int) ($_POST['grade'] ?? 0),
                'difficulty' => sanitize($_POST['difficulty'] ?? 'medium'),
                'time_limit' => (int) ($_POST['time_limit'] ?? 0),
                'is_practice' => isset($_POST['is_practice']) ? 1 : 0,
                'shuffle_questions' => isset($_POST['shuffle_questions']) ? 1 : 0,
                'shuffle_answers' => isset($_POST['shuffle_answers']) ? 1 : 0,
                'show_results' => isset($_POST['show_results']) ? 1 : 0,
                'generation_type' => sanitize($_POST['generation_type'] ?? 'general'),
                'question_count' => (int) ($_POST['question_count'] ?? 5),
                'text_content' => $_POST['text_content'] ?? '',
                'topic' => sanitize($_POST['topic'] ?? '')
            ];

            // Validate
            if (empty($quiz_data['subject_id']))
                throw new Exception('يرجى اختيار المادة');
            if (empty($quiz_data['grade']))
                throw new Exception('يرجى اختيار الصف');
            if (empty($quiz_data['title'])) {
                // Auto-generate title if not provided
                $subject = $pdo->query("SELECT name_ar FROM subjects WHERE id = {$quiz_data['subject_id']}")->fetchColumn();
                $quiz_data['title'] = "اختبار {$subject} - " . getGradeName($quiz_data['grade']);
            }

            // Prepare AI parameters
            $ai_params = [
                'teacher_id' => $_SESSION['user_id'],
                'subject' => $quiz_data['subject_id'],
                'subject_id' => $quiz_data['subject_id'],
                'grade' => $quiz_data['grade'],
                'difficulty' => $quiz_data['difficulty'],
                'count' => $quiz_data['question_count'],
                'type' => $quiz_data['generation_type'],
                'topic' => $quiz_data['topic']
            ];

            if ($quiz_data['generation_type'] === 'text_based' && !empty($quiz_data['text_content'])) {
                $ai_params['text'] = $quiz_data['text_content'];
            }

            // Generate questions
            $result = generateQuizQuestions($ai_params);
            $generated_questions = $result['questions'];

            // Format questions for session storage
            $formatted_questions = array_map(function ($q) {
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

            // Store everything in session
            $_SESSION['quiz_creation'] = [
                'quiz_data' => $quiz_data,
                'questions' => $formatted_questions,
                'text_content' => $quiz_data['text_content'], // Include the text
                'ai_generated' => true,
                'generated_at' => date('Y-m-d H:i:s')
            ];

            $success = sprintf(
                'تم توليد %d سؤال بنجاح! جاري الانتقال لإنشاء الاختبار...',
                count($generated_questions)
            );

            // Auto-redirect after 2 seconds
            header("Refresh: 2; url=create.php");

        } catch (Exception $e) {
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

// Get subjects
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
        body { font-family: 'Tajawal', sans-serif; }
        .text-content {
            white-space: pre-wrap;
            line-height: 1.8;
            font-size: 1.05rem;
        }
        .text-ltr {
            direction: ltr;
            text-align: left;
            font-family: 'Arial', sans-serif;
        }
        .progress-step {
            transition: all 0.3s ease;
        }
        .progress-step.active {
            background-color: #570df8;
            color: white;
        }
        .progress-step.completed {
            background-color: #22c55e;
            color: white;
        }
    </style>
</head>
<body class="bg-gray-50" x-data="aiGenerator()">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none">
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle">
                    <i class="fas fa-user"></i>
                </label>
                <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-50 p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="/teacher/"><i class="fas fa-home ml-2"></i>الرئيسية</a></li>
                    <li><a href="/teacher/quizzes/"><i class="fas fa-list ml-2"></i>اختباراتي</a></li>
                    <li><a href="/auth/logout.php"><i class="fas fa-sign-out-alt ml-2"></i>تسجيل الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <!-- Progress Steps -->
        <div class="flex justify-center mb-8">
            <ul class="steps steps-horizontal">
                <li class="step step-primary">
                    <span class="text-xs">معلومات الاختبار</span>
                </li>
                <li class="step step-primary">
                    <span class="text-xs">توليد الأسئلة</span>
                </li>
                <li class="step">
                    <span class="text-xs">المراجعة والحفظ</span>
                </li>
            </ul>
        </div>

        <h1 class="text-3xl font-bold mb-2 text-center">
            <i class="fas fa-magic text-primary ml-2"></i>
            توليد الأسئلة بالذكاء الاصطناعي
        </h1>
        <p class="text-center text-gray-600 mb-8">أنشئ أسئلة تعليمية عالية الجودة في ثوانٍ</p>

        <!-- AI Usage Stats -->
        <?php if ($ai_usage): ?>
                <div class="stats shadow mb-6 w-full bg-gradient-to-r from-purple-500 to-pink-500 text-white">
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-chart-line text-3xl"></i>
                        </div>
                        <div class="stat-title text-white/80">الاستخدام الشهري</div>
                        <div class="stat-value"><?= $ai_usage['total_generations'] ?> / <?= $ai_usage['monthly_limit'] ?></div>
                        <div class="stat-desc text-white/70">
                            <progress class="progress progress-warning w-56" 
                                      value="<?= $ai_usage['percentage_used'] ?>" max="100"></progress>
                        </div>
                    </div>
                
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-question-circle text-3xl"></i>
                        </div>
                        <div class="stat-title text-white/80">الأسئلة المولدة</div>
                        <div class="stat-value"><?= $ai_usage['total_questions'] ?? 0 ?></div>
                        <div class="stat-desc text-white/70">هذا الشهر</div>
                    </div>
                
                    <div class="stat">
                        <div class="stat-figure">
                            <i class="fas fa-bolt text-3xl"></i>
                        </div>
                        <div class="stat-title text-white/80">المتبقي</div>
                        <div class="stat-value"><?= $ai_usage['remaining'] ?></div>
                        <div class="stat-desc text-white/70">توليد متاح</div>
                    </div>
                </div>
        <?php endif; ?>

        <!-- Messages -->
        <?php if ($error): ?>
                <div class="alert alert-error mb-6">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
        <?php endif; ?>

        <?php if ($success): ?>
                <div class="alert alert-success mb-6">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <p><?= e($success) ?></p>
                        <p class="text-sm opacity-75">سيتم نقلك تلقائياً...</p>
                    </div>
                    <progress class="progress progress-success w-56"></progress>
                </div>
        <?php endif; ?>

        <form method="POST" @submit="handleSubmit">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="generate_questions" value="1">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Quiz Settings -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Quiz Information -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-info-circle text-info"></i>
                                معلومات الاختبار
                            </h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control md:col-span-2">
                                    <label class="label">
                                        <span class="label-text">عنوان الاختبار</span>
                                        <span class="label-text-alt">اختياري - سيتم توليده تلقائياً</span>
                                    </label>
                                    <input type="text" name="title" 
                                           value="<?= e($quiz_data['title'] ?? '') ?>"
                                           class="input input-bordered" 
                                           placeholder="مثال: اختبار الرياضيات - الفصل الأول">
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">المادة *</span>
                                    </label>
                                    <select name="subject_id" class="select select-bordered" required>
                                        <option value="">اختر المادة</option>
                                        <?php foreach ($subjects as $subject): ?>
                                                <option value="<?= $subject['id'] ?>" 
                                                        <?= ($quiz_data['subject_id'] ?? '') == $subject['id'] ? 'selected' : '' ?>>
                                                    <i class="<?= $subject['icon'] ?>"></i>
                                                    <?= e($subject['name_ar']) ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">الصف الدراسي *</span>
                                    </label>
                                    <select name="grade" x-model="selectedGrade" class="select select-bordered" required>
                                        <option value="">اختر الصف</option>
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                                <option value="<?= $i ?>" 
                                                        <?= ($quiz_data['grade'] ?? '') == $i ? 'selected' : '' ?>>
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
                                        <option value="easy" <?= ($quiz_data['difficulty'] ?? '') == 'easy' ? 'selected' : '' ?>>سهل</option>
                                        <option value="medium" <?= ($quiz_data['difficulty'] ?? 'medium') == 'medium' ? 'selected' : '' ?>>متوسط</option>
                                        <option value="hard" <?= ($quiz_data['difficulty'] ?? '') == 'hard' ? 'selected' : '' ?>>صعب</option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">المدة الزمنية (دقائق)</span>
                                    </label>
                                    <input type="number" name="time_limit" 
                                           value="<?= $quiz_data['time_limit'] ?? 0 ?>"
                                           class="input input-bordered" 
                                           min="0" placeholder="0 = غير محدد">
                                </div>

                                <div class="form-control md:col-span-2">
                                    <label class="label">
                                        <span class="label-text">وصف الاختبار</span>
                                    </label>
                                    <textarea name="description" 
                                              class="textarea textarea-bordered" 
                                              rows="2"
                                              placeholder="وصف مختصر للاختبار (اختياري)"><?= e($quiz_data['description'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Question Generation Settings -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-cog text-warning"></i>
                                إعدادات التوليد
                            </h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">نوع الأسئلة</span>
                                    </label>
                                    <select name="generation_type" x-model="generationType" class="select select-bordered">
                                        <option value="general">أسئلة عامة في المنهج</option>
                                        <option value="text_based">أسئلة فهم المقروء</option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">عدد الأسئلة</span>
                                    </label>
                                    <input type="number" name="question_count" 
                                           value="<?= $quiz_data['question_count'] ?? 5 ?>"
                                           class="input input-bordered" 
                                           min="1" max="20" required>
                                </div>

                                <div class="form-control md:col-span-2" x-show="generationType === 'general'">
                                    <label class="label">
                                        <span class="label-text">الموضوع المحدد</span>
                                        <span class="label-text-alt">اختياري - لتخصيص الأسئلة</span>
                                    </label>
                                    <input type="text" name="topic" 
                                           value="<?= e($quiz_data['topic'] ?? '') ?>"
                                           class="input input-bordered" 
                                           placeholder="مثال: الكسور العشرية، الحرب العالمية الثانية">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Text Input Section -->
                    <div class="card bg-base-100 shadow-xl" x-show="generationType === 'text_based'" x-transition>
                        <div class="card-body">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="card-title">
                                    <i class="fas fa-file-alt text-success"></i>
                                    النص للقراءة
                                </h2>
                                <button type="button" @click="showTextGenerator = true" 
                                        class="btn btn-primary btn-sm">
                                    <i class="fas fa-magic ml-2"></i>
                                    توليد نص
                                </button>
                            </div>

                            <div class="form-control">
                                <textarea name="text_content" 
                                          x-model="textContent" 
                                          @input="detectLanguage()"
                                          :class="textDirection === 'ltr' ? 'text-ltr' : ''"
                                          class="textarea textarea-bordered text-content" 
                                          rows="10" 
                                          placeholder="الصق النص هنا أو استخدم زر التوليد..."
                                          :required="generationType === 'text_based'"></textarea>
                                <label class="label">
                                    <span class="label-text-alt">
                                        <span x-show="textContent.length > 0">
                                            <i class="fas fa-file-word ml-1"></i>
                                            <span x-text="wordCount"></span> كلمة
                                        </span>
                                    </span>
                                    <span class="label-text-alt" x-show="textDirection === 'ltr'">
                                        <i class="fas fa-globe ml-1"></i> English Text
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Settings & Tips -->
                <div class="space-y-6">
                    <!-- Quiz Settings -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-sliders-h text-secondary"></i>
                                خيارات الاختبار
                            </h2>
                            
                            <div class="space-y-3">
                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">وضع التدريب</span>
                                        <input type="checkbox" name="is_practice" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['is_practice'] ?? 0) ? 'checked' : '' ?>>
                                    </label>
                                    <p class="text-xs text-gray-500 mr-6">عرض الإجابات الصحيحة مباشرة</p>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">خلط الأسئلة</span>
                                        <input type="checkbox" name="shuffle_questions" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['shuffle_questions'] ?? 0) ? 'checked' : '' ?>>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">خلط الإجابات</span>
                                        <input type="checkbox" name="shuffle_answers" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['shuffle_answers'] ?? 0) ? 'checked' : '' ?>>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">عرض النتائج للطلاب</span>
                                        <input type="checkbox" name="show_results" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['show_results'] ?? 1) ? 'checked' : '' ?>>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tips -->
                    <div class="card bg-gradient-to-br from-blue-50 to-purple-50 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title text-primary mb-4">
                                <i class="fas fa-lightbulb"></i>
                                نصائح للحصول على أفضل النتائج
                            </h2>
                            <ul class="space-y-2 text-sm">
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-success mt-0.5"></i>
                                    <span>كن دقيقاً في اختيار المادة والصف</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-success mt-0.5"></i>
                                    <span>حدد الموضوع للحصول على أسئلة متخصصة</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-success mt-0.5"></i>
                                    <span>للنصوص: اختر نصوصاً واضحة ومناسبة للعمر</span>
                                </li>
                                <li class="flex items-start gap-2">
                                    <i class="fas fa-check-circle text-success mt-0.5"></i>
                                    <span>راجع الأسئلة قبل حفظ الاختبار</span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="space-y-3">
                        <button type="submit" class="btn btn-primary btn-lg w-full" :disabled="isLoading">
                            <span x-show="!isLoading">
                                <i class="fas fa-magic ml-2"></i>
                                توليد الأسئلة
                            </span>
                            <span x-show="isLoading">
                                <span class="loading loading-spinner"></span>
                                جاري التوليد...
                            </span>
                        </button>
                        
                        <a href="create.php" class="btn btn-ghost w-full">
                            <i class="fas fa-pencil ml-2"></i>
                            إنشاء يدوي
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Text Generator Modal -->
    <div x-show="showTextGenerator" x-transition 
         class="fixed inset-0 z-50 overflow-y-auto" 
         style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black opacity-50" @click="showTextGenerator = false"></div>
            
            <div class="relative bg-white rounded-lg max-w-lg w-full p-6 animate__animated animate__fadeInUp">
                <button @click="showTextGenerator = false" 
                        class="absolute top-4 left-4 text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
                
                <h3 class="text-2xl font-bold mb-6">
                    <i class="fas fa-magic text-primary ml-2"></i>
                    توليد نص تعليمي
                </h3>
                
                <div class="space-y-4">
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">موضوع النص *</span>
                        </label>
                        <input type="text" x-model="textTopic" 
                               class="input input-bordered w-full" 
                               placeholder="مثال: دورة الماء في الطبيعة، الثورة الصناعية">
                    </div>
                    
                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">طول النص</span>
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" 
                                    @click="textLength = 150" 
                                    :class="textLength === 150 ? 'btn-primary' : 'btn-outline'"
                                    class="btn btn-sm">
                                قصير (150)
                            </button>
                            <button type="button" 
                                    @click="textLength = 250" 
                                    :class="textLength === 250 ? 'btn-primary' : 'btn-outline'"
                                    class="btn btn-sm">
                                متوسط (250)
                            </button>
                            <button type="button" 
                                    @click="textLength = 400" 
                                    :class="textLength === 400 ? 'btn-primary' : 'btn-outline'"
                                    class="btn btn-sm">
                                طويل (400)
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span>سيتم توليد نص تعليمي مناسب للصف المحدد</span>
                    </div>
                </div>
                
                <div class="modal-action">
                    <button @click="showTextGenerator = false" class="btn btn-ghost">إلغاء</button>
                    <button @click="generateText()" class="btn btn-primary" :disabled="generatingText">
                        <span x-show="!generatingText">
                            <i class="fas fa-magic ml-2"></i>
                            توليد النص
                        </span>
                        <span x-show="generatingText">
                            <span class="loading loading-spinner loading-sm"></span>
                            جاري التوليد...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    function aiGenerator() {
        return {
            generationType: '<?= $quiz_data['generation_type'] ?? 'general' ?>',
            textContent: '<?= str_replace(["\r\n", "\r", "\n"], "\\n", $quiz_data['text_content'] ?? '') ?>',
            textDirection: 'rtl',
            selectedGrade: '<?= $quiz_data['grade'] ?? '' ?>',
            showTextGenerator: false,
            textTopic: '',
            textLength: 250,
            generatingText: false,
            isLoading: false,
            
            get wordCount() {
                return this.textContent.trim().split(/\s+/).filter(word => word.length > 0).length;
            },
            
            detectLanguage() {
                const arabicPattern = /[\u0600-\u06FF]/;
                const hasArabic = arabicPattern.test(this.textContent);
                const englishPattern = /[a-zA-Z]/;
                const hasEnglish = englishPattern.test(this.textContent);
                
                if (hasEnglish && !hasArabic) {
                    this.textDirection = 'ltr';
                } else {
                    this.textDirection = 'rtl';
                }
            },
            
            async generateText() {
                if (!this.textTopic || !this.selectedGrade) {
                    alert('يرجى إدخال الموضوع واختيار الصف');
                    return;
                }
                
                this.generatingText = true;
                
                try {
                    const formData = new FormData();
                    formData.append('ajax_generate_text', '1');
                    formData.append('text_topic', this.textTopic);
                    formData.append('grade', this.selectedGrade);
                    formData.append('text_length', this.textLength);
                    formData.append('csrf_token', '<?= $csrf_token ?>');
                    
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        this.textContent = data.text;
                        this.detectLanguage();
                        this.showTextGenerator = false;
                        this.textTopic = '';
                        
                        // Show success message
                        const alert = document.createElement('div');
                        alert.className = 'alert alert-success fixed top-4 right-4 z-50 animate__animated animate__fadeInDown';
                        alert.innerHTML = '<i class="fas fa-check-circle"></i><span>تم توليد النص بنجاح!</span>';
                        document.body.appendChild(alert);
                        setTimeout(() => alert.remove(), 3000);
                    } else {
                        alert(data.error || 'حدث خطأ في توليد النص');
                    }
                } catch (error) {
                    alert('حدث خطأ في الاتصال');
                } finally {
                    this.generatingText = false;
                }
            },
            
            handleSubmit(e) {
                if (!this.isLoading) {
                    this.isLoading = true;
                }
            },
            
            init() {
                this.detectLanguage();
            }
        }
    }
    </script>
</body>
</html>