<?php
// teacher/quizzes/create.php - Improved with better flow
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
$quiz_data = [];
$questions = [];
$text_content = '';
$ai_generated = false;

// Check if coming from AI generation
if (isset($_SESSION['quiz_creation'])) {
    $session_data = $_SESSION['quiz_creation'];
    $quiz_data = $session_data['quiz_data'] ?? [];
    $questions = $session_data['questions'] ?? [];
    $text_content = $session_data['text_content'] ?? '';
    $ai_generated = $session_data['ai_generated'] ?? false;

    // Clear session after retrieving
    unset($_SESSION['quiz_creation']);
} elseif (isset($_GET['edit'])) {
    // Edit existing quiz
    $quiz_id = (int) $_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$quiz_id, $_SESSION['user_id']]);
    $quiz = $stmt->fetch();

    if ($quiz) {
        $quiz_data = $quiz;

        // Load questions
        $stmt = $pdo->prepare("
            SELECT q.*, GROUP_CONCAT(o.option_text ORDER BY o.order_index SEPARATOR '|||') as options,
                   GROUP_CONCAT(o.is_correct ORDER BY o.order_index) as correct_flags
            FROM questions q
            LEFT JOIN options o ON q.id = o.question_id
            WHERE q.quiz_id = ?
            GROUP BY q.id
            ORDER BY q.order_index
        ");
        $stmt->execute([$quiz_id]);
        $db_questions = $stmt->fetchAll();

        foreach ($db_questions as $q) {
            $options = explode('|||', $q['options']);
            $correct_flags = explode(',', $q['correct_flags']);
            $correct_index = array_search('1', $correct_flags);

            $questions[] = [
                'text' => $q['question_text'],
                'options' => array_map(function ($opt) {
                    return ['text' => $opt];
                }, $options),
                'correct' => $correct_index !== false ? $correct_index : 0,
                'points' => $q['points'],
                'ai_generated' => $q['ai_generated']
            ];
        }

        // Load text if exists
        if ($quiz['has_text'] && $quiz['text_id']) {
            $stmt = $pdo->prepare("SELECT text_content FROM quiz_texts WHERE id = ?");
            $stmt->execute([$quiz['text_id']]);
            $text_content = $stmt->fetchColumn();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_quiz'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            $pdo->beginTransaction();

            // Collect quiz data
            $title = sanitize($_POST['title'] ?? '');
            $description = $_POST['description'] ?? '';
            $subject_id = (int) ($_POST['subject_id'] ?? 0);
            $grade = (int) ($_POST['grade'] ?? 0);
            $difficulty = sanitize($_POST['difficulty'] ?? 'medium');
            $time_limit = (int) ($_POST['time_limit'] ?? 0);
            $is_practice = isset($_POST['is_practice']) ? 1 : 0;
            $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
            $shuffle_answers = isset($_POST['shuffle_answers']) ? 1 : 0;
            $show_results = isset($_POST['show_results']) ? 1 : 0;
            $has_text = !empty($_POST['text_content']) ? 1 : 0;
            $ai_generated = isset($_POST['ai_generated']) ? 1 : 0;

            // Validate
            if (empty($title))
                throw new Exception('عنوان الاختبار مطلوب');
            if (empty($subject_id))
                throw new Exception('يرجى اختيار المادة');
            if (empty($grade))
                throw new Exception('يرجى اختيار الصف');
            if (!isset($_POST['questions']) || empty($_POST['questions'])) {
                throw new Exception('يجب إضافة سؤال واحد على الأقل');
            }

            // Handle text content if exists
            $text_id = null;
            if ($has_text && !empty($_POST['text_content'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO quiz_texts (text_title, text_content, source, reading_time, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");

                $reading_time = calculateReadingTime($_POST['text_content']);
                $stmt->execute([
                    $title . ' - نص القراءة',
                    $_POST['text_content'],
                    $ai_generated ? 'ai_generated' : 'manual',
                    $reading_time,
                    $_SESSION['user_id']
                ]);

                $text_id = $pdo->lastInsertId();
            }

            // Generate PIN
            $pin_code = generatePIN();

            // Insert or update quiz
            if (isset($_POST['quiz_id']) && $_POST['quiz_id'] > 0) {
                // Update existing quiz
                $stmt = $pdo->prepare("
                    UPDATE quizzes SET
                        title = ?, description = ?, subject_id = ?, grade = ?,
                        difficulty = ?, time_limit = ?, is_practice = ?, has_text = ?,
                        text_id = ?, ai_generated = ?, shuffle_questions = ?,
                        shuffle_answers = ?, show_results = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ?
                ");

                $stmt->execute([
                    $title,
                    $description,
                    $subject_id,
                    $grade,
                    $difficulty,
                    $time_limit,
                    $is_practice,
                    $has_text,
                    $text_id,
                    $ai_generated,
                    $shuffle_questions,
                    $shuffle_answers,
                    $show_results,
                    $_POST['quiz_id'],
                    $_SESSION['user_id']
                ]);

                $quiz_id = $_POST['quiz_id'];

                // Delete old questions
                $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?")->execute([$quiz_id]);
            } else {
                // Insert new quiz
                $stmt = $pdo->prepare("
                    INSERT INTO quizzes (
                        title, description, subject_id, teacher_id, grade,
                        difficulty, time_limit, pin_code, is_practice, has_text,
                        text_id, ai_generated, shuffle_questions, shuffle_answers, show_results
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $title,
                    $description,
                    $subject_id,
                    $_SESSION['user_id'],
                    $grade,
                    $difficulty,
                    $time_limit,
                    $pin_code,
                    $is_practice,
                    $has_text,
                    $text_id,
                    $ai_generated,
                    $shuffle_questions,
                    $shuffle_answers,
                    $show_results
                ]);

                $quiz_id = $pdo->lastInsertId();
            }

            // Insert questions
            $question_order = 0;
            foreach ($_POST['questions'] as $q) {
                if (empty($q['text']))
                    continue;

                // Insert question
                $stmt = $pdo->prepare("
                    INSERT INTO questions (
                        quiz_id, question_text, question_type, is_text_based,
                        ai_generated, points, order_index
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $quiz_id,
                    $q['text'],
                    'multiple_choice',
                    $has_text ? 1 : 0,
                    isset($q['ai_generated']) ? 1 : 0,
                    (int) ($q['points'] ?? 10),
                    $question_order++
                ]);

                $question_id = $pdo->lastInsertId();

                // Insert options
                if (isset($q['options']) && is_array($q['options'])) {
                    $option_order = 0;
                    foreach ($q['options'] as $idx => $option) {
                        if (empty($option['text']))
                            continue;

                        $stmt = $pdo->prepare("
                            INSERT INTO options (
                                question_id, option_text, is_correct, order_index
                            ) VALUES (?, ?, ?, ?)
                        ");

                        $is_correct = (int) ($q['correct'] ?? 0) === $idx ? 1 : 0;

                        $stmt->execute([
                            $question_id,
                            $option['text'],
                            $is_correct,
                            $option_order++
                        ]);
                    }
                }
            }

            // Link quiz to text if updating
            if ($text_id && isset($_POST['quiz_id'])) {
                $stmt = $pdo->prepare("UPDATE quiz_texts SET quiz_id = ? WHERE id = ?");
                $stmt->execute([$quiz_id, $text_id]);
            }

            $pdo->commit();

            // Success - redirect to manage page
            $_SESSION['success'] = 'تم حفظ الاختبار بنجاح! رمز PIN: ' . $pin_code;
            redirect('/teacher/quizzes/manage.php?id=' . $quiz_id);

        } catch (Exception $e) {
            $pdo->rollBack();
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
    <title><?= isset($_GET['edit']) ? 'تعديل' : 'إنشاء' ?> اختبار - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        .question-item { 
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        /* AI Visual Indicators */
    .ai-badge {
        background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .ai-glow {
        box-shadow: 0 0 20px rgba(102, 126, 234, 0.3);
        border: 2px solid #667eea;
    }

    /* Smooth animations */
    .question-item {
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
        .question-item:hover {
            border-color: #570df8;
            transform: translateY(-2px);
        }
        .text-content {
            white-space: pre-wrap;
            line-height: 1.8;
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
        }
        .drag-handle {
            cursor: move;
        }
    </style>
</head>
<body class="bg-gray-50" x-data="quizCreator">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none">
            <ul class="menu menu-horizontal px-1">
                <li><a href="/teacher/quizzes/"><i class="fas fa-list ml-2"></i>اختباراتي</a></li>
                <li><a href="ai-generate.php" class="text-primary"><i class="fas fa-magic ml-2"></i>التوليد بالذكاء</a></li>
            </ul>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Progress Steps -->
        <div class="flex justify-center mb-8">
            <ul class="steps steps-horizontal">
                <li class="step <?= $ai_generated ? 'step-primary' : '' ?>">
                    <span class="text-xs">معلومات الاختبار</span>
                </li>
                <li class="step <?= $ai_generated ? 'step-primary' : '' ?>">
                    <span class="text-xs">الأسئلة</span>
                </li>
                <li class="step step-primary">
                    <span class="text-xs">المراجعة والحفظ</span>
                </li>
            </ul>
        </div>

        <h1 class="text-3xl font-bold mb-8 text-center">
            <i class="fas fa-<?= isset($_GET['edit']) ? 'edit' : 'plus-circle' ?> text-primary ml-2"></i>
            <?= isset($_GET['edit']) ? 'تعديل الاختبار' : 'إنشاء اختبار جديد' ?>
        </h1>

        <?php if ($ai_generated): ?>
                <div class="alert alert-info mb-6">
                    <i class="fas fa-robot"></i>
                    <div>
                        <h3 class="font-bold">اختبار مولد بالذكاء الاصطناعي</h3>
                        <p>يمكنك مراجعة وتعديل الأسئلة المولدة قبل الحفظ</p>
                    </div>
                </div>
        <?php endif; ?>

        <?php if ($error): ?>
                <div class="alert alert-error mb-6">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
        <?php endif; ?>

        <form method="POST" @submit="validateForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="save_quiz" value="1">
            <input type="hidden" name="ai_generated" value="<?= $ai_generated ? '1' : '0' ?>">
            <?php if (isset($_GET['edit'])): ?>
                    <input type="hidden" name="quiz_id" value="<?= (int) $_GET['edit'] ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Information -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-info-circle text-info"></i>
                                المعلومات الأساسية
                            </h2>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="form-control md:col-span-2">
                                    <label class="label">
                                        <span class="label-text">عنوان الاختبار *</span>
                                    </label>
                                    <input type="text" name="title" 
                                           value="<?= e($quiz_data['title'] ?? '') ?>" 
                                           class="input input-bordered input-primary" 
                                           placeholder="مثال: اختبار الفصل الأول - الرياضيات"
                                           required>
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
                                        <?php $diff = $quiz_data['difficulty'] ?? 'medium'; ?>
                                        <option value="easy" <?= $diff == 'easy' ? 'selected' : '' ?>>سهل</option>
                                        <option value="medium" <?= $diff == 'medium' ? 'selected' : '' ?>>متوسط</option>
                                        <option value="hard" <?= $diff == 'hard' ? 'selected' : '' ?>>صعب</option>
                                        <option value="mixed" <?= $diff == 'mixed' ? 'selected' : '' ?>>متنوع</option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">المدة الزمنية</span>
                                        <span class="label-text-alt">بالدقائق</span>
                                    </label>
                                    <input type="number" name="time_limit" 
                                           value="<?= $quiz_data['time_limit'] ?? 0 ?>" 
                                           class="input input-bordered" 
                                           min="0" max="180"
                                           placeholder="0 = غير محدد">
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

                    <!-- Text Content (if exists) -->
                    <?php if (!empty($text_content)): ?>
                            <div class="card bg-base-100 shadow-xl">
                                <div class="card-body">
                                    <h2 class="card-title mb-4">
                                        <i class="fas fa-file-alt text-success"></i>
                                        نص القراءة
                                        <?php if ($ai_generated): ?>
                                                <div class="badge badge-primary">مولد بالذكاء الاصطناعي</div>
                                        <?php endif; ?>
                                    </h2>
                                
                                    <div class="form-control">
                                        <div class="text-content">
                                            <?= nl2br(e($text_content)) ?>
                                        </div>
                                        <input type="hidden" name="text_content" value="<?= e($text_content) ?>">
                                        <label class="label">
                                            <span class="label-text-alt">
                                                <i class="fas fa-clock ml-1"></i>
                                                وقت القراءة المقدر: <?= ceil(str_word_count($text_content) / 200) ?> دقائق
                                            </span>
                                            <span class="label-text-alt">
                                                <i class="fas fa-file-word ml-1"></i>
                                                <?= str_word_count($text_content) ?> كلمة
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                    <?php endif; ?>

                    <!-- Questions Section -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                        <div class="flex justify-between items-center mb-4">
    <h2 class="card-title">
        <i class="fas fa-question-circle text-success"></i>
        الأسئلة
        <div class="badge badge-lg badge-primary">
            <span x-text="questions.length"></span> سؤال
        </div>
    </h2>
    <div class="flex gap-2">
        <button type="button" @click="addQuestion()" class="btn btn-success btn-sm">
            <i class="fas fa-plus"></i>
            إضافة سؤال
        </button>
        <div class="dropdown dropdown-end">
            <label tabindex="0" class="btn btn-primary btn-sm">
                <i class="fas fa-magic"></i>
                الذكاء الاصطناعي
                <i class="fas fa-chevron-down mr-1"></i>
            </label>
            <ul tabindex="0" class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-52">
                <li><a href="ai-generate.php">
                    <i class="fas fa-sparkles"></i>
                    توليد أسئلة جديدة
                </a></li>
                <li><a onclick="saveAndGoToAI()">
                    <i class="fas fa-plus-circle"></i>
                    إضافة المزيد من الأسئلة
                </a></li>
                <li><a onclick="improveQuestions()">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    تحسين الأسئلة الحالية
                </a></li>
            </ul>
        </div>
    </div>
</div>

                            <div id="questions-container" class="space-y-4">
                                <template x-for="(question, qIndex) in questions" :key="qIndex">
                                    <div class="question-item border-2 rounded-lg p-4 bg-base-200 hover:bg-base-300">
                                        <div class="flex justify-between items-start mb-3">
                                            <div class="flex items-center gap-3">
                                                <span class="drag-handle text-gray-400">
                                                    <i class="fas fa-grip-vertical"></i>
                                                </span>
                                                <h3 class="text-lg font-semibold">
                                                    السؤال <span x-text="qIndex + 1"></span>
                                                    <template x-if="question.ai_generated">
                                                        <span class="badge badge-primary badge-sm mr-2">
                                                            <i class="fas fa-robot ml-1"></i> AI
                                                        </span>
                                                    </template>
                                                </h3>
                                            </div>
                                            <div class="flex gap-2">
    <button type="button" @click="duplicateQuestion(qIndex)" 
            class="btn btn-info btn-sm" title="نسخ السؤال">
        <i class="fas fa-copy"></i>
    </button>
    <button type="button" @click="removeQuestion(qIndex)" 
            class="btn btn-error btn-sm" title="حذف السؤال">
        <i class="fas fa-trash"></i>
    </button>
</div>
                                        </div>

                                        <div class="form-control mb-3">
                                            <textarea :name="'questions[' + qIndex + '][text]'" 
                                                      x-model="question.text"
                                                      class="textarea textarea-bordered" 
                                                      rows="2"
                                                      placeholder="اكتب نص السؤال هنا..." 
                                                      required></textarea>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
                                            <template x-for="(option, oIndex) in question.options" :key="oIndex">
                                                <div class="flex items-center gap-2">
                                                    <input type="radio" 
                                                           :name="'questions[' + qIndex + '][correct]'" 
                                                           :value="oIndex"
                                                           x-model.number="question.correct"
                                                           class="radio radio-primary"
                                                           :id="'q' + qIndex + '_opt' + oIndex">
                                                    <label :for="'q' + qIndex + '_opt' + oIndex"
                                                           class="flex-1 flex items-center gap-2">
                                                        <span class="badge badge-outline" 
                                                              x-text="['أ', 'ب', 'ج', 'د'][oIndex]"></span>
                                                        <input type="text" 
                                                               :name="'questions[' + qIndex + '][options][' + oIndex + '][text]'" 
                                                               x-model="option.text"
                                                               class="input input-bordered input-sm flex-1" 
                                                               :placeholder="'الخيار ' + (oIndex + 1)" 
                                                               required>
                                                    </label>
                                                </div>
                                            </template>
                                        </div>

                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-4">
                                                <div class="form-control">
                                                    <label class="label p-0">
                                                        <span class="label-text text-xs">الدرجات</span>
                                                    </label>
                                                    <input type="number" 
                                                           :name="'questions[' + qIndex + '][points]'" 
                                                           x-model="question.points"
                                                           class="input input-bordered input-sm w-20" 
                                                           min="1" max="100">
                                                </div>
                                                <template x-if="question.ai_generated">
                                                    <input type="hidden" 
                                                           :name="'questions[' + qIndex + '][ai_generated]'" 
                                                           value="1">
                                                </template>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <template x-if="question.correct >= 0">
                                                    <span>
                                                        <i class="fas fa-check-circle text-success ml-1"></i>
                                                        الإجابة: <span x-text="['أ', 'ب', 'ج', 'د'][question.correct]"></span>
                                                    </span>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                <div x-show="questions.length === 0" 
                                     class="text-center py-12 text-gray-500">
                                    <i class="fas fa-clipboard-list text-6xl mb-4"></i>
                                    <p class="text-lg">لم تتم إضافة أي أسئلة بعد</p>
                                    <p class="text-sm mt-2">انقر على "إضافة سؤال" أو استخدم التوليد بالذكاء الاصطناعي</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Quiz Settings -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-cog text-warning"></i>
                                إعدادات الاختبار
                            </h2>

                            <div class="space-y-3">
                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">وضع التدريب</span>
                                        <input type="checkbox" name="is_practice" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['is_practice'] ?? 0) ? 'checked' : '' ?>>
                                    </label>
                                    <p class="text-xs text-gray-500 mr-6">إظهار الإجابات الصحيحة فوراً</p>
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
                                        <span class="label-text">إظهار النتائج للطلاب</span>
                                        <input type="checkbox" name="show_results" 
                                               class="checkbox checkbox-primary"
                                               <?= ($quiz_data['show_results'] ?? 1) ? 'checked' : '' ?>>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quiz Summary -->
                    <div class="card bg-gradient-to-br from-purple-50 to-pink-50 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-chart-pie text-primary"></i>
                                ملخص الاختبار
                            </h2>

                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between">
                                    <span>عدد الأسئلة:</span>
                                    <span class="font-bold" x-text="questions.length"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>مجموع الدرجات:</span>
                                    <span class="font-bold" x-text="totalPoints"></span>
                                </div>
                                <div class="flex justify-between">
                                    <span>متوسط درجة السؤال:</span>
                                    <span class="font-bold" x-text="averagePoints"></span>
                                </div>
                                <?php if ($ai_generated): ?>
                                        <div class="flex justify-between">
                                            <span>مولد بالذكاء الاصطناعي:</span>
                                            <span class="font-bold text-primary">نعم</span>
                                        </div>
                                <?php endif; ?>
                            </div>

                            <div class="divider"></div>

                            <div class="space-y-3">
                                <button type="submit" class="btn btn-primary w-full">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ الاختبار
                                </button>
                                
                                <button type="button" @click="previewQuiz()" class="btn btn-outline w-full">
                                    <i class="fas fa-eye ml-2"></i>
                                    معاينة
                                </button>
                                
                                <a href="/teacher/quizzes/" class="btn btn-ghost w-full">
                                    <i class="fas fa-times ml-2"></i>
                                    إلغاء
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Tips -->
                    <div class="card bg-info text-info-content">
                        <div class="card-body">
                            <h2 class="card-title text-sm">
                                <i class="fas fa-lightbulb"></i>
                                نصائح سريعة
                            </h2>
                            <ul class="text-xs space-y-1">
                                <li>• اختر الإجابة الصحيحة بالنقر على الدائرة</li>
                                <li>• يمكنك سحب الأسئلة لإعادة ترتيبها</li>
                                <li>• تأكد من ملء جميع الخيارات</li>
                                <li>• راجع الأسئلة قبل الحفظ</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Preview Modal -->
    <dialog id="previewModal" class="modal">
        <div class="modal-box w-11/12 max-w-5xl">
            <h3 class="font-bold text-lg mb-4">معاينة الاختبار</h3>
            <div id="previewContent"></div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn">إغلاق</button>
                </form>
            </div>
        </div>
    </dialog>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('quizCreator', () => ({
            questions: <?= json_encode(array_values($questions)) ?> || [],
            
            get totalPoints() {
                return this.questions.reduce((sum, q) => sum + parseInt(q.points || 10), 0);
            },
            
            get averagePoints() {
                return this.questions.length > 0 ? 
                    Math.round(this.totalPoints / this.questions.length) : 0;
            },
            
            addQuestion() {
                this.questions.push({
                    text: '',
                    options: [
                        { text: '' },
                        { text: '' },
                        { text: '' },
                        { text: '' }
                    ],
                    correct: 0,
                    points: 10,
                    ai_generated: false
                });
                
                // Scroll to new question
                setTimeout(() => {
                    const lastQuestion = document.querySelector('.question-item:last-child');
                    if (lastQuestion) {
                        lastQuestion.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            },
            
            removeQuestion(index) {
                if (confirm('هل أنت متأكد من حذف هذا السؤال؟')) {
                    this.questions.splice(index, 1);
                }
            },
            duplicateQuestion(index) {
    const original = this.questions[index];
    const duplicate = JSON.parse(JSON.stringify(original)); // Deep clone
    duplicate.text = duplicate.text + ' (نسخة)';
    this.questions.splice(index + 1, 0, duplicate);
    
    // Show success message
    const alert = document.createElement('div');
    alert.className = 'alert alert-success fixed top-4 right-4 z-50 animate__animated animate__fadeInDown';
    alert.innerHTML = '<i class="fas fa-check-circle"></i><span>تم نسخ السؤال</span>';
    document.body.appendChild(alert);
    setTimeout(() => alert.remove(), 2000);
},
            validateForm(e) {
                if (this.questions.length === 0) {
                    e.preventDefault();
                    alert('يجب إضافة سؤال واحد على الأقل');
                    return false;
                }
                
                // Validate each question
                for (let i = 0; i < this.questions.length; i++) {
                    const q = this.questions[i];
                    if (!q.text.trim()) {
                        e.preventDefault();
                        alert(`السؤال ${i + 1} فارغ`);
                        return false;
                    }
                    
                    let hasOptions = false;
                    for (let opt of q.options) {
                        if (opt.text.trim()) {
                            hasOptions = true;
                            break;
                        }
                    }
                    
                    if (!hasOptions) {
                        e.preventDefault();
                        alert(`السؤال ${i + 1} يحتاج خيار واحد على الأقل`);
                        return false;
                    }
                }
                
                return true;
            },
            
            previewQuiz() {
                const modal = document.getElementById('previewModal');
                const content = document.getElementById('previewContent');
                
                let html = '<div class="space-y-4">';
                this.questions.forEach((q, i) => {
                    html += `
                        <div class="border rounded-lg p-4">
                            <h4 class="font-bold mb-2">السؤال ${i + 1}: ${this.escapeHtml(q.text)}</h4>
                            <div class="space-y-2 mr-4">
                    `;
                    
                    q.options.forEach((opt, j) => {
                        if (opt.text) {
                            const isCorrect = q.correct === j;
                            html += `
                                <div class="${isCorrect ? 'text-success font-bold' : ''}">
                                    ${['أ', 'ب', 'ج', 'د'][j]}) ${this.escapeHtml(opt.text)}
                                    ${isCorrect ? '<i class="fas fa-check-circle mr-2"></i>' : ''}
                                </div>
                            `;
                        }
                    });
                    
                    html += `
                            </div>
                            <div class="text-sm text-gray-500 mt-2">الدرجة: ${q.points}</div>
                        </div>
                    `;
                });
                html += '</div>';
                
                content.innerHTML = html;
                modal.showModal();
            },
            
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },
            
            init() {
                // Initialize Sortable for drag & drop (if library is loaded)
                if (typeof Sortable !== 'undefined') {
                    new Sortable(document.getElementById('questions-container'), {
                        handle: '.drag-handle',
                        animation: 150,
                        onEnd: (evt) => {
                            const item = this.questions.splice(evt.oldIndex, 1)[0];
                            this.questions.splice(evt.newIndex, 0, item);
                        }
                    });
                }
                
                // If no questions and not AI generated, add one empty question
                if (this.questions.length === 0 && !<?= $ai_generated ? 'true' : 'false' ?>) {
                    this.addQuestion();
                }
            }
        }));
    });
    function saveAndGoToAI() {
    // Collect current form data
    const formData = new FormData(document.querySelector('form'));
    
    // Create object with quiz data
    const quizData = {
        title: formData.get('title'),
        description: formData.get('description'),
        subject_id: formData.get('subject_id'),
        grade: formData.get('grade'),
        difficulty: formData.get('difficulty'),
        time_limit: formData.get('time_limit'),
        is_practice: formData.has('is_practice'),
        shuffle_questions: formData.has('shuffle_questions'),
        shuffle_answers: formData.has('shuffle_answers'),
        show_results: formData.has('show_results')
    };
    
    // Save to session via AJAX
    fetch('ajax/save-draft.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            quiz_data: quizData,
            csrf_token: '<?= $csrf_token ?>'
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to AI generation
                        window.location.href = 'ai-generate.php';
                    }
                });
        }

        function improveQuestions() {
            // This could send current questions to AI for improvement suggestions
            alert('هذه الميزة قيد التطوير - ستتيح لك تحسين صياغة الأسئلة الحالية بالذكاء الاصطناعي');
        }

        // Add keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('form').submit();
            }

            // Ctrl/Cmd + N for new question
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                // Access Alpine component and add question
                const alpineComponent = document.querySelector('[x-data="quizCreator"]').__x.$data;
                if (alpineComponent && alpineComponent.addQuestion) {
                    alpineComponent.addQuestion();
                }
            }
        });
    </script>
    
</body>
</html>