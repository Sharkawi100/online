<?php
// teacher/quizzes/edit.php - Professional Edit Quiz Interface
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

// Check authentication
if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Get quiz ID
$quiz_id = (int) ($_GET['id'] ?? 0);
if (!$quiz_id) {
    $_SESSION['error'] = 'معرف الاختبار غير صحيح';
    redirect('/teacher/quizzes/');
}

// Initialize variables
$success = '';
$error = '';
$quiz = null;
$questions = [];
$text_content = '';
$quiz_text_data = null;
$attempts_count = 0;

// Load quiz data
try {
    // Get quiz with subject info
    $stmt = $pdo->prepare("
        SELECT q.*, s.name_ar as subject_name, s.icon as subject_icon, s.color as subject_color,
               (SELECT COUNT(*) FROM attempts WHERE quiz_id = q.id) as attempt_count,
               (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as question_count,
               (SELECT AVG(score) FROM attempts WHERE quiz_id = q.id AND completed_at IS NOT NULL) as avg_score
        FROM quizzes q
        LEFT JOIN subjects s ON q.subject_id = s.id
        WHERE q.id = ? AND q.teacher_id = ?
    ");
    $stmt->execute([$quiz_id, $_SESSION['user_id']]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        $_SESSION['error'] = 'الاختبار غير موجود أو ليس لديك صلاحية لتعديله';
        redirect('/teacher/quizzes/');
    }

    $attempts_count = $quiz['attempt_count'];

    // Load questions with options
    $stmt = $pdo->prepare("
        SELECT q.id, q.question_text, q.points, q.order_index, q.ai_generated, q.is_text_based,
               GROUP_CONCAT(o.id ORDER BY o.order_index) as option_ids,
               GROUP_CONCAT(o.option_text ORDER BY o.order_index SEPARATOR '|||') as option_texts,
               GROUP_CONCAT(o.is_correct ORDER BY o.order_index) as correct_flags
        FROM questions q
        LEFT JOIN options o ON q.id = o.question_id
        WHERE q.quiz_id = ?
        GROUP BY q.id
        ORDER BY q.order_index
    ");
    $stmt->execute([$quiz_id]);
    $db_questions = $stmt->fetchAll();

    // Format questions for frontend
    foreach ($db_questions as $q) {
        $options = $q['option_texts'] ? explode('|||', $q['option_texts']) : [];
        $correct_flags = $q['correct_flags'] ? explode(',', $q['correct_flags']) : [];
        $correct_index = array_search('1', $correct_flags);

        $questions[] = [
            'id' => $q['id'],
            'text' => $q['question_text'],
            'options' => array_map(function ($opt) {
                return ['text' => $opt];
            }, $options),
            'correct' => $correct_index !== false ? $correct_index : 0,
            'points' => $q['points'],
            'ai_generated' => (bool) $q['ai_generated'],
            'is_text_based' => (bool) $q['is_text_based']
        ];
    }

    // Load text content if exists
    if ($quiz['has_text'] && $quiz['text_id']) {
        $stmt = $pdo->prepare("SELECT * FROM quiz_texts WHERE id = ?");
        $stmt->execute([$quiz['text_id']]);
        $quiz_text_data = $stmt->fetch();
        $text_content = $quiz_text_data['text_content'] ?? '';
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'خطأ في تحميل بيانات الاختبار';
    redirect('/teacher/quizzes/');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quiz'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            $pdo->beginTransaction();

            // Collect form data
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
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $has_text = !empty($_POST['text_content']) ? 1 : 0;

            // Validate
            if (empty($title))
                throw new Exception('عنوان الاختبار مطلوب');
            if (empty($subject_id))
                throw new Exception('يرجى اختيار المادة');
            if (empty($grade))
                throw new Exception('يرجى اختيار الصف');
            if (!isset($_POST['questions']) || empty($_POST['questions'])) {
                throw new Exception('يجب أن يحتوي الاختبار على سؤال واحد على الأقل');
            }

            // Handle text content update
            $text_id = $quiz['text_id'];
            if ($has_text && !empty($_POST['text_content'])) {
                if ($text_id) {
                    // Update existing text
                    $stmt = $pdo->prepare("
                        UPDATE quiz_texts SET
                            text_content = ?,
                            reading_time = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $_POST['text_content'],
                        calculateReadingTime($_POST['text_content']),
                        $text_id
                    ]);
                } else {
                    // Create new text
                    $stmt = $pdo->prepare("
                        INSERT INTO quiz_texts (quiz_id, text_title, text_content, source, reading_time, created_by)
                        VALUES (?, ?, ?, 'manual', ?, ?)
                    ");
                    $stmt->execute([
                        $quiz_id,
                        $title . ' - نص القراءة',
                        $_POST['text_content'],
                        calculateReadingTime($_POST['text_content']),
                        $_SESSION['user_id']
                    ]);
                    $text_id = $pdo->lastInsertId();
                }
            } elseif (!$has_text && $text_id) {
                // Remove text reference
                $text_id = null;
            }

            // Update quiz
            $stmt = $pdo->prepare("
                UPDATE quizzes SET
                    title = ?, description = ?, subject_id = ?, grade = ?,
                    difficulty = ?, time_limit = ?, is_practice = ?, 
                    has_text = ?, text_id = ?, shuffle_questions = ?,
                    shuffle_answers = ?, show_results = ?, is_active = ?,
                    updated_at = NOW()
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
                $shuffle_questions,
                $shuffle_answers,
                $show_results,
                $is_active,
                $quiz_id,
                $_SESSION['user_id']
            ]);

            // Handle questions update
            $existing_question_ids = array_column($questions, 'id');
            $submitted_question_ids = [];

            // Process submitted questions
            $question_order = 0;
            foreach ($_POST['questions'] as $q) {
                if (empty($q['text']))
                    continue;

                $question_id = isset($q['id']) && $q['id'] > 0 ? (int) $q['id'] : null;

                if ($question_id && in_array($question_id, $existing_question_ids)) {
                    // Update existing question
                    $stmt = $pdo->prepare("
                        UPDATE questions SET
                            question_text = ?, points = ?, order_index = ?
                        WHERE id = ? AND quiz_id = ?
                    ");
                    $stmt->execute([
                        $q['text'],
                        (int) ($q['points'] ?? 10),
                        $question_order++,
                        $question_id,
                        $quiz_id
                    ]);

                    $submitted_question_ids[] = $question_id;

                    // Delete old options
                    $pdo->prepare("DELETE FROM options WHERE question_id = ?")->execute([$question_id]);
                } else {
                    // Insert new question
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
                }

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

            // Delete removed questions
            $questions_to_delete = array_diff($existing_question_ids, $submitted_question_ids);
            if (!empty($questions_to_delete)) {
                $placeholders = str_repeat('?,', count($questions_to_delete) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($placeholders) AND quiz_id = ?");
                $stmt->execute(array_merge($questions_to_delete, [$quiz_id]));
            }

            $pdo->commit();

            $_SESSION['success'] = 'تم تحديث الاختبار بنجاح!';
            redirect('/teacher/quizzes/manage.php?id=' . $quiz_id);

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'خطأ في التحديث: ' . $e->getMessage();
        }
    }
}

// Handle quiz duplication
if (isset($_GET['action']) && $_GET['action'] === 'duplicate') {
    try {
        $pdo->beginTransaction();

        // Duplicate quiz
        $new_pin = generatePIN();
        $stmt = $pdo->prepare("
            INSERT INTO quizzes (
                title, description, subject_id, teacher_id, grade,
                difficulty, time_limit, pin_code, is_practice, has_text,
                text_id, ai_generated, shuffle_questions, shuffle_answers,
                show_results, is_active, created_at
            )
            SELECT 
                CONCAT(title, ' (نسخة)'), description, subject_id, teacher_id, grade,
                difficulty, time_limit, ?, is_practice, has_text,
                text_id, ai_generated, shuffle_questions, shuffle_answers,
                show_results, 1, NOW()
            FROM quizzes WHERE id = ? AND teacher_id = ?
        ");
        $stmt->execute([$new_pin, $quiz_id, $_SESSION['user_id']]);
        $new_quiz_id = $pdo->lastInsertId();

        // Duplicate questions and options
        $stmt = $pdo->prepare("
            INSERT INTO questions (quiz_id, question_text, question_type, is_text_based, ai_generated, points, order_index)
            SELECT ?, question_text, question_type, is_text_based, ai_generated, points, order_index
            FROM questions WHERE quiz_id = ?
        ");
        $stmt->execute([$new_quiz_id, $quiz_id]);

        // Get mapping of old to new question IDs
        $stmt = $pdo->prepare("
            SELECT q1.id as old_id, q2.id as new_id
            FROM questions q1
            JOIN questions q2 ON q1.question_text = q2.question_text 
                AND q1.order_index = q2.order_index
            WHERE q1.quiz_id = ? AND q2.quiz_id = ?
        ");
        $stmt->execute([$quiz_id, $new_quiz_id]);
        $question_mapping = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Duplicate options
        foreach ($question_mapping as $old_id => $new_id) {
            $stmt = $pdo->prepare("
                INSERT INTO options (question_id, option_text, is_correct, order_index)
                SELECT ?, option_text, is_correct, order_index
                FROM options WHERE question_id = ?
            ");
            $stmt->execute([$new_id, $old_id]);
        }

        $pdo->commit();

        $_SESSION['success'] = 'تم نسخ الاختبار بنجاح! رمز PIN الجديد: ' . $new_pin;
        redirect('/teacher/quizzes/edit.php?id=' . $new_quiz_id);

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'خطأ في نسخ الاختبار';
        redirect('/teacher/quizzes/');
    }
}

// Get subjects for dropdown
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY order_index")->fetchAll();

// Calculate stats
$total_points = array_sum(array_column($questions, 'points'));
$avg_points = count($questions) > 0 ? round($total_points / count($questions)) : 0;

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل: <?= e($quiz['title']) ?> - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>
    
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
        .question-item:hover {
            border-color: #570df8;
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .text-content {
            white-space: pre-wrap;
            line-height: 1.8;
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            max-height: 300px;
            overflow-y: auto;
            font-size: 1.05rem;
        }
        .drag-handle {
            cursor: move;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .animate-pulse-slow {
            animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
    </style>
</head>
<body class="bg-gray-50" x-data="quizEditor">
    <!-- Header -->
    <div class="navbar bg-base-100 shadow-lg sticky top-0 z-40">
        <div class="flex-1">
            <a href="/teacher/dashboard.php" class="btn btn-ghost text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name', 'منصة الاختبارات')) ?>
            </a>
        </div>
        <div class="flex-none gap-2">
            <!-- Save Indicator -->
            <div x-show="hasChanges" class="badge badge-warning animate-pulse">
                <i class="fas fa-exclamation-triangle ml-1"></i>
                تغييرات غير محفوظة
            </div>
            
            <!-- Action Buttons -->
            <div class="dropdown dropdown-end">
                <label tabindex="0" class="btn btn-ghost btn-circle">
                    <i class="fas fa-ellipsis-v"></i>
                </label>
                <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-50 p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="manage.php?id=<?= $quiz_id ?>">
                        <i class="fas fa-chart-bar ml-2"></i>إدارة الاختبار
                    </a></li>
                    <li><a href="?id=<?= $quiz_id ?>&action=duplicate">
                        <i class="fas fa-copy ml-2"></i>نسخ الاختبار
                    </a></li>
                    <li><a href="/quiz.php?pin=<?= $quiz['pin_code'] ?>" target="_blank">
                        <i class="fas fa-eye ml-2"></i>معاينة كطالب
                    </a></li>
                    <li class="text-error"><a onclick="confirmDelete()">
                        <i class="fas fa-trash ml-2"></i>حذف الاختبار
                    </a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-8 max-w-7xl">
        <!-- Quiz Header Info -->
        <div class="mb-8 fade-in-up">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold mb-2">
                        <i class="fas fa-edit text-primary ml-2"></i>
                        تعديل الاختبار
                    </h1>
                    <div class="flex flex-wrap gap-2">
                        <div class="badge badge-lg" style="background-color: <?= $quiz['subject_color'] ?>20; color: <?= $quiz['subject_color'] ?>">
                            <i class="<?= $quiz['subject_icon'] ?> ml-1"></i>
                            <?= e($quiz['subject_name']) ?>
                        </div>
                        <div class="badge badge-lg badge-outline">
                            <?= getGradeName($quiz['grade']) ?>
                        </div>
                        <div class="badge badge-lg badge-outline">
                            <i class="fas fa-fingerprint ml-1"></i>
                            PIN: <?= $quiz['pin_code'] ?>
                        </div>
                        <?php if ($quiz['ai_generated']): ?>
                                <div class="badge badge-lg badge-primary">
                                    <i class="fas fa-robot ml-1"></i>
                                    مولد بالذكاء الاصطناعي
                                </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="stats shadow">
                    <div class="stat">
                        <div class="stat-figure text-primary">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div class="stat-title">المحاولات</div>
                        <div class="stat-value text-primary"><?= $attempts_count ?></div>
                    </div>
                    <div class="stat">
                        <div class="stat-figure text-secondary">
                            <i class="fas fa-percentage text-2xl"></i>
                        </div>
                        <div class="stat-title">متوسط النتيجة</div>
                        <div class="stat-value text-secondary"><?= round($quiz['avg_score'] ?? 0) ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($attempts_count > 0): ?>
                <div class="alert alert-warning mb-6">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <h3 class="font-bold">تنبيه: هذا الاختبار له <?= $attempts_count ?> محاولة</h3>
                        <p>تعديل الأسئلة قد يؤثر على دقة النتائج السابقة</p>
                    </div>
                </div>
        <?php endif; ?>

        <?php if ($error): ?>
                <div class="alert alert-error mb-6">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= e($error) ?></span>
                </div>
        <?php endif; ?>

        <form method="POST" @submit="handleSubmit" @change="markAsChanged">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="update_quiz" value="1">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Basic Information -->
                    <div class="card bg-base-100 shadow-xl fade-in-up">
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
                                           value="<?= e($quiz['title']) ?>" 
                                           class="input input-bordered input-primary" 
                                           required>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">المادة *</span>
                                    </label>
                                    <select name="subject_id" class="select select-bordered" required>
                                        <?php foreach ($subjects as $subject): ?>
                                                <option value="<?= $subject['id'] ?>" 
                                                        <?= $quiz['subject_id'] == $subject['id'] ? 'selected' : '' ?>>
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
                                        <?php for ($i = 1; $i <= 12; $i++): ?>
                                                <option value="<?= $i ?>" 
                                                        <?= $quiz['grade'] == $i ? 'selected' : '' ?>>
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
                                        <option value="easy" <?= $quiz['difficulty'] == 'easy' ? 'selected' : '' ?>>سهل</option>
                                        <option value="medium" <?= $quiz['difficulty'] == 'medium' ? 'selected' : '' ?>>متوسط</option>
                                        <option value="hard" <?= $quiz['difficulty'] == 'hard' ? 'selected' : '' ?>>صعب</option>
                                        <option value="mixed" <?= $quiz['difficulty'] == 'mixed' ? 'selected' : '' ?>>متنوع</option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="label">
                                        <span class="label-text">المدة الزمنية</span>
                                        <span class="label-text-alt">بالدقائق</span>
                                    </label>
                                    <input type="number" name="time_limit" 
                                           value="<?= $quiz['time_limit'] ?>" 
                                           class="input input-bordered" 
                                           min="0" max="180">
                                </div>

                                <div class="form-control md:col-span-2">
                                    <label class="label">
                                        <span class="label-text">وصف الاختبار</span>
                                    </label>
                                    <textarea name="description" 
                                              class="textarea textarea-bordered" 
                                              rows="2"><?= e($quiz['description']) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Text Content -->
                    <div class="card bg-base-100 shadow-xl fade-in-up" 
                         x-show="hasText || '<?= $quiz['has_text'] ?>' === '1'" 
                         x-transition>
                        <div class="card-body">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="card-title">
                                    <i class="fas fa-file-alt text-success"></i>
                                    نص القراءة
                                    <?php if ($quiz_text_data && $quiz_text_data['source'] === 'ai_generated'): ?>
                                            <div class="badge badge-primary">مولد بالذكاء الاصطناعي</div>
                                    <?php endif; ?>
                                </h2>
                                <div class="flex gap-2">
                                    <button type="button" @click="toggleText()" class="btn btn-ghost btn-sm">
                                        <i class="fas" :class="hasText ? 'fa-times' : 'fa-plus'"></i>
                                        <span x-text="hasText ? 'إزالة النص' : 'إضافة نص'"></span>
                                    </button>
                                    <?php if (!empty($text_content)): ?>
                                            <button type="button" onclick="previewText()" class="btn btn-primary btn-sm">
                                                <i class="fas fa-expand"></i>
                                                عرض كامل
                                            </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="form-control">
                                <textarea name="text_content" 
                                          x-model="textContent"
                                          @input="detectLanguage()"
                                          :dir="textDirection"
                                          class="textarea textarea-bordered text-content" 
                                          rows="10"
                                          placeholder="اكتب أو الصق النص هنا..."
                                          x-show="hasText"><?= e($text_content) ?></textarea>
                                          
                                <div x-show="!hasText && '<?= $quiz['has_text'] ?>' === '1'" 
                                     class="text-content opacity-50">
                                    <?= nl2br(e($text_content)) ?>
                                </div>
                                
                                <label class="label" x-show="textContent.length > 0">
                                    <span class="label-text-alt">
                                        <i class="fas fa-clock ml-1"></i>
                                        وقت القراءة: <span x-text="Math.ceil(wordCount / 200)"></span> دقيقة
                                    </span>
                                    <span class="label-text-alt">
                                        <i class="fas fa-file-word ml-1"></i>
                                        <span x-text="wordCount"></span> كلمة
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Questions Section -->
                    <div class="card bg-base-100 shadow-xl fade-in-up">
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
                                        </label>
                                        <ul tabindex="0" class="dropdown-content z-50 menu p-2 shadow bg-base-100 rounded-box w-52">
                                            <li><a onclick="addAIQuestions()">
                                                <i class="fas fa-plus-circle"></i>
                                                إضافة أسئلة جديدة
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
                                <template x-for="(question, qIndex) in questions" :key="question.id || 'new_' + qIndex">
                                    <div class="question-item border-2 rounded-lg p-4 bg-base-200 hover:bg-base-300">
                                        <input type="hidden" :name="'questions[' + qIndex + '][id]'" x-model="question.id">
                                        
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
                                                        class="btn btn-ghost btn-xs" title="نسخ السؤال">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <button type="button" @click="removeQuestion(qIndex)" 
                                                        class="btn btn-error btn-xs">
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
                                                           x-model.number="question.points"
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
                                    <button type="button" @click="addQuestion()" class="btn btn-primary mt-4">
                                        <i class="fas fa-plus ml-2"></i>
                                        إضافة أول سؤال
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Quiz Status & Settings -->
                    <div class="card bg-base-100 shadow-xl fade-in-up">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-cog text-warning"></i>
                                الحالة والإعدادات
                            </h2>

                            <div class="space-y-3">
                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text font-bold">حالة الاختبار</span>
                                        <input type="checkbox" name="is_active" 
                                               class="toggle toggle-success"
                                               <?= $quiz['is_active'] ? 'checked' : '' ?>>
                                    </label>
                                    <p class="text-xs text-gray-500 mr-6">
                                        <?= $quiz['is_active'] ? 'الاختبار مفعل ويمكن للطلاب الدخول' : 'الاختبار معطل' ?>
                                    </p>
                                </div>

                                <div class="divider my-2"></div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">وضع التدريب</span>
                                        <input type="checkbox" name="is_practice" 
                                               class="checkbox checkbox-primary"
                                               <?= $quiz['is_practice'] ? 'checked' : '' ?>>
                                    </label>
                                    <p class="text-xs text-gray-500 mr-6">إظهار الإجابات الصحيحة فوراً</p>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">خلط الأسئلة</span>
                                        <input type="checkbox" name="shuffle_questions" 
                                               class="checkbox checkbox-primary"
                                               <?= $quiz['shuffle_questions'] ? 'checked' : '' ?>>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">خلط الإجابات</span>
                                        <input type="checkbox" name="shuffle_answers" 
                                               class="checkbox checkbox-primary"
                                               <?= $quiz['shuffle_answers'] ? 'checked' : '' ?>>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="label cursor-pointer">
                                        <span class="label-text">إظهار النتائج للطلاب</span>
                                        <input type="checkbox" name="show_results" 
                                               class="checkbox checkbox-primary"
                                               <?= $quiz['show_results'] ? 'checked' : '' ?>>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quiz Summary -->
                    <div class="card bg-gradient-to-br from-purple-50 to-pink-50 shadow-xl fade-in-up">
                        <div class="card-body">
                            <h2 class="card-title mb-4">
                                <i class="fas fa-chart-pie text-primary"></i>
                                ملخص الاختبار
                            </h2>

                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center">
                                    <span>عدد الأسئلة:</span>
                                    <span class="font-bold text-lg" x-text="questions.length"></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span>مجموع الدرجات:</span>
                                    <span class="font-bold text-lg" x-text="totalPoints"></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span>متوسط درجة السؤال:</span>
                                    <span class="font-bold" x-text="averagePoints"></span>
                                </div>
                                <div class="flex justify-between items-center" x-show="aiQuestionCount > 0">
                                    <span>أسئلة مولدة بالذكاء:</span>
                                    <span class="font-bold text-primary" x-text="aiQuestionCount + ' من ' + questions.length"></span>
                                </div>
                                
                                <div class="divider my-2"></div>
                                
                                <!-- PIN Code Display -->
                                <div class="bg-base-100 rounded-lg p-3 text-center">
                                    <p class="text-xs text-gray-500 mb-1">رمز الدخول</p>
                                    <p class="text-2xl font-bold tracking-wider"><?= $quiz['pin_code'] ?></p>
                                    <button type="button" onclick="copyPIN('<?= $quiz['pin_code'] ?>')" 
                                            class="btn btn-ghost btn-xs mt-1">
                                        <i class="fas fa-copy"></i>
                                        نسخ
                                    </button>
                                </div>
                            </div>

                            <div class="divider"></div>

                            <div class="space-y-3">
                                <button type="submit" class="btn btn-primary w-full">
                                    <i class="fas fa-save ml-2"></i>
                                    حفظ التغييرات
                                </button>
                                
                                <button type="button" @click="previewQuiz()" class="btn btn-outline w-full">
                                    <i class="fas fa-eye ml-2"></i>
                                    معاينة الاختبار
                                </button>
                                
                                <a href="manage.php?id=<?= $quiz_id ?>" class="btn btn-ghost w-full">
                                    <i class="fas fa-arrow-right ml-2"></i>
                                    العودة للإدارة
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card bg-info text-info-content fade-in-up">
                        <div class="card-body">
                            <h2 class="card-title text-sm">
                                <i class="fas fa-bolt"></i>
                                إجراءات سريعة
                            </h2>
                            <div class="space-y-2">
                                <a href="../results.php?quiz_id=<?= $quiz_id ?>" class="btn btn-sm btn-ghost w-full justify-start">
                                    <i class="fas fa-chart-line ml-2"></i>
                                    عرض النتائج
                                </a>
                                <button type="button" onclick="shareQuiz()" class="btn btn-sm btn-ghost w-full justify-start">
                                    <i class="fas fa-share-alt ml-2"></i>
                                    مشاركة الاختبار
                                </button>
                                <a href="?id=<?= $quiz_id ?>&action=duplicate" class="btn btn-sm btn-ghost w-full justify-start">
                                    <i class="fas fa-copy ml-2"></i>
                                    إنشاء نسخة
                                </a>
                            </div>
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
            <div id="previewContent" class="max-h-[70vh] overflow-y-auto"></div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn">إغلاق</button>
                </form>
            </div>
        </div>
    </dialog>

    <!-- Text Preview Modal -->
    <dialog id="textModal" class="modal">
        <div class="modal-box w-11/12 max-w-4xl">
            <h3 class="font-bold text-lg mb-4">نص القراءة الكامل</h3>
            <div class="text-content" style="max-height: 70vh;">
                <?= nl2br(e($text_content)) ?>
            </div>
            <div class="modal-action">
                <form method="dialog">
                    <button class="btn">إغلاق</button>
                </form>
            </div>
        </div>
    </dialog>

    <script>
    function copyPIN(pin) {
        navigator.clipboard.writeText(pin).then(() => {
            // Show toast notification
            const toast = document.createElement('div');
            toast.className = 'toast toast-top toast-center';
            toast.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span>تم نسخ رمز PIN</span>
                </div>
            `;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        });
    }

    function shareQuiz() {
        const url = `<?= BASE_URL ?>/quiz.php?pin=<?= $quiz['pin_code'] ?>`;
        const text = `انضم لاختبار: <?= $quiz['title'] ?>\nرمز الدخول: <?= $quiz['pin_code'] ?>`;
        
        if (navigator.share) {
            navigator.share({
                title: '<?= $quiz['title'] ?>',
                text: text,
                url: url
            });
        } else {
            prompt('انسخ هذا الرابط:', url);
        }
    }

    function confirmDelete() {
        if (confirm('هل أنت متأكد من حذف هذا الاختبار؟ سيتم حذف جميع الأسئلة والنتائج المرتبطة.')) {
            window.location.href = 'delete.php?id=<?= $quiz_id ?>&csrf=<?= $csrf_token ?>';
        }
    }

    function previewText() {
        document.getElementById('textModal').showModal();
    }

    function addAIQuestions() {
        // Save current state and redirect to AI generation
        alert('جاري الانتقال لصفحة توليد الأسئلة بالذكاء الاصطناعي...');
        // Implementation would save current state to session and redirect
    }

    function improveQuestions() {
        alert('هذه الميزة قيد التطوير - ستتيح تحسين صياغة الأسئلة الحالية');
    }

    document.addEventListener('alpine:init', () => {
        Alpine.data('quizEditor', () => ({
            questions: <?= json_encode(array_values($questions)) ?>,
            hasChanges: false,
            hasText: <?= $quiz['has_text'] ? 'true' : 'false' ?>,
            textContent: `<?= str_replace(["\r\n", "\r", "\n", "`"], ["\\n", "\\n", "\\n", "\\`"], $text_content) ?>`,
            textDirection: 'rtl',
            
            get totalPoints() {
                return this.questions.reduce((sum, q) => sum + parseInt(q.points || 10), 0);
            },
            
            get averagePoints() {
                return this.questions.length > 0 ? 
                    Math.round(this.totalPoints / this.questions.length) : 0;
            },
            
            get aiQuestionCount() {
                return this.questions.filter(q => q.ai_generated).length;
            },
            
            get wordCount() {
                return this.textContent.trim().split(/\s+/).filter(word => word.length > 0).length;
            },
            
            markAsChanged() {
                this.hasChanges = true;
            },
            
            addQuestion() {
                this.questions.push({
                    id: null,
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
                this.markAsChanged();
                
                // Scroll to new question
                setTimeout(() => {
                    const lastQuestion = document.querySelector('.question-item:last-child');
                    if (lastQuestion) {
                        lastQuestion.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 100);
            },
            
            duplicateQuestion(index) {
                const original = this.questions[index];
                const duplicate = JSON.parse(JSON.stringify(original));
                duplicate.id = null; // New question
                duplicate.text = duplicate.text + ' (نسخة)';
                this.questions.splice(index + 1, 0, duplicate);
                this.markAsChanged();
            },
            
            removeQuestion(index) {
                if (confirm('هل أنت متأكد من حذف هذا السؤال؟')) {
                    this.questions.splice(index, 1);
                    this.markAsChanged();
                }
            },
            
            toggleText() {
                this.hasText = !this.hasText;
                if (!this.hasText) {
                    this.textContent = '';
                }
                this.markAsChanged();
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
            
            handleSubmit(e) {
                if (this.questions.length === 0) {
                    e.preventDefault();
                    alert('يجب إضافة سؤال واحد على الأقل');
                    return false;
                }
                
                // Reset hasChanges on successful submit
                this.hasChanges = false;
            },
            
            previewQuiz() {
                const modal = document.getElementById('previewModal');
                const content = document.getElementById('previewContent');
                
                let html = '<div class="space-y-4">';
                
                // Add text if exists
                if (this.hasText && this.textContent) {
                    html += `
                        <div class="card bg-base-200">
                            <div class="card-body">
                                <h4 class="font-bold mb-2">نص القراءة:</h4>
                                <div class="whitespace-pre-wrap">${this.escapeHtml(this.textContent)}</div>
                            </div>
                        </div>
                    `;
                }
                
                // Add questions
                this.questions.forEach((q, i) => {
                    html += `
                        <div class="card">
                            <div class="card-body">
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
                this.detectLanguage();
                
                // Warn before leaving with unsaved changes
                window.addEventListener('beforeunload', (e) => {
                    if (this.hasChanges) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
                
                // Initialize Sortable for drag & drop
                if (typeof Sortable !== 'undefined') {
                    new Sortable(document.getElementById('questions-container'), {
                        handle: '.drag-handle',
                        animation: 150,
                        onEnd: (evt) => {
                            const item = this.questions.splice(evt.oldIndex, 1)[0];
                            this.questions.splice(evt.newIndex, 0, item);
                            this.markAsChanged();
                        }
                    });
                }
            }
        }));
    });

    // Add keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        // Ctrl/Cmd + S to save
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            document.querySelector('form').submit();
        }
    });
    </script>
</body>
</html>