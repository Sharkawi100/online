<?php
// /teacher/quizzes/edit.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}
// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}
$quiz_id = $_GET['id'] ?? 0;
$error = '';
$success = '';

// Get quiz details
$stmt = $pdo->prepare("
    SELECT * FROM quizzes 
    WHERE id = ? AND teacher_id = ?
");
$stmt->execute([$quiz_id, $_SESSION['user_id']]);
$quiz = $stmt->fetch();

if (!$quiz) {
    redirect('/teacher/quizzes/');
}

// Set default values for missing columns
$quiz['language'] = $quiz['language'] ?? 'ar';
$quiz['shuffle_questions'] = $quiz['shuffle_questions'] ?? 1;
$quiz['shuffle_answers'] = $quiz['shuffle_answers'] ?? 1;
$quiz['show_results'] = $quiz['show_results'] ?? 1;
$quiz['is_practice'] = $quiz['is_practice'] ?? 0;

// Get subjects for dropdown
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY name_ar");
$subjects = $stmt->fetchAll();

// Get questions with options
$stmt = $pdo->prepare("
    SELECT q.*, 
           GROUP_CONCAT(
               CONCAT(o.id, ':', o.option_text, ':', o.is_correct) 
               ORDER BY o.order_index SEPARATOR '|||'
           ) as options_data
    FROM questions q
    LEFT JOIN options o ON q.id = o.question_id
    WHERE q.quiz_id = ?
    GROUP BY q.id
    ORDER BY q.order_index
");
$stmt->execute([$quiz_id]);
$questions = $stmt->fetchAll();

// Parse options data
foreach ($questions as &$question) {
    $question['options'] = [];
    if ($question['options_data']) {
        $options_raw = explode('|||', $question['options_data']);
        foreach ($options_raw as $option_raw) {
            list($id, $text, $is_correct) = explode(':', $option_raw);
            $question['options'][] = [
                'id' => $id,
                'text' => $text,
                'is_correct' => $is_correct == '1'
            ];
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            $pdo->beginTransaction();

            // Handle quiz deletion
            if (isset($_POST['delete_quiz'])) {
                // Delete all related data
                $stmt = $pdo->prepare("DELETE FROM answers WHERE attempt_id IN (SELECT id FROM attempts WHERE quiz_id = ?)");
                $stmt->execute([$quiz_id]);

                $stmt = $pdo->prepare("DELETE FROM attempts WHERE quiz_id = ?");
                $stmt->execute([$quiz_id]);

                $stmt = $pdo->prepare("DELETE FROM options WHERE question_id IN (SELECT id FROM questions WHERE quiz_id = ?)");
                $stmt->execute([$quiz_id]);

                $stmt = $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?");
                $stmt->execute([$quiz_id]);

                $stmt = $pdo->prepare("DELETE FROM quizzes WHERE id = ?");
                $stmt->execute([$quiz_id]);

                $pdo->commit();
                redirect('/teacher/quizzes/');
            }

            // Handle PIN regeneration
            if (isset($_POST['regenerate_pin'])) {
                $new_pin = generatePIN();
                $stmt = $pdo->prepare("UPDATE quizzes SET pin_code = ? WHERE id = ?");
                $stmt->execute([$new_pin, $quiz_id]);
                $pdo->commit();
                $success = "تم تجديد رمز الاختبار: $new_pin";
                $quiz['pin_code'] = $new_pin;
            }

            // Handle quiz update
            if (isset($_POST['update_quiz'])) {
                // Get existing columns
                $stmt = $pdo->query("SHOW COLUMNS FROM quizzes");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

                // Build dynamic update query
                $updateFields = [
                    'title = ?',
                    'description = ?',
                    'subject_id = ?',
                    'grade = ?',
                    'difficulty = ?',
                    'time_limit = ?'
                ];

                $updateValues = [
                    sanitize($_POST['title']),
                    sanitize($_POST['description']),
                    $_POST['subject_id'] ?: null,
                    (int) $_POST['grade'],
                    $_POST['difficulty'],
                    (int) $_POST['time_limit']
                ];

                // Add optional fields if columns exist
                if (in_array('language', $columns)) {
                    $updateFields[] = 'language = ?';
                    $updateValues[] = $_POST['language'] ?? 'ar';
                }
                if (in_array('shuffle_questions', $columns)) {
                    $updateFields[] = 'shuffle_questions = ?';
                    $updateValues[] = isset($_POST['shuffle_questions']) ? 1 : 0;
                }
                if (in_array('shuffle_answers', $columns)) {
                    $updateFields[] = 'shuffle_answers = ?';
                    $updateValues[] = isset($_POST['shuffle_answers']) ? 1 : 0;
                }
                if (in_array('show_results', $columns)) {
                    $updateFields[] = 'show_results = ?';
                    $updateValues[] = isset($_POST['show_results']) ? 1 : 0;
                }
                if (in_array('is_practice', $columns)) {
                    $updateFields[] = 'is_practice = ?';
                    $updateValues[] = isset($_POST['is_practice']) ? 1 : 0;
                }
                if (in_array('is_active', $columns)) {
                    $updateFields[] = 'is_active = ?';
                    $updateValues[] = isset($_POST['is_active']) ? 1 : 0;
                }
                if (in_array('updated_at', $columns)) {
                    $updateFields[] = 'updated_at = NOW()';
                }

                $updateValues[] = $quiz_id;

                // Update quiz details
                $stmt = $pdo->prepare("
                    UPDATE quizzes SET " . implode(', ', $updateFields) . "
                    WHERE id = ?
                ");

                $stmt->execute($updateValues);

                // Delete existing questions and options
                $stmt = $pdo->prepare("DELETE o FROM options o JOIN questions q ON o.question_id = q.id WHERE q.quiz_id = ?");
                $stmt->execute([$quiz_id]);

                $stmt = $pdo->prepare("DELETE FROM questions WHERE quiz_id = ?");
                $stmt->execute([$quiz_id]);

                // Insert new questions
                $questions = $_POST['questions'] ?? [];
                foreach ($questions as $q_index => $question) {
                    if (empty($question['text']))
                        continue;

                    $stmt = $pdo->prepare("
                        INSERT INTO questions (quiz_id, question_text, points, order_index)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $quiz_id,
                        sanitize($question['text']),
                        (int) ($question['points'] ?? 1),
                        $q_index + 1
                    ]);

                    $question_id = $pdo->lastInsertId();

                    // Insert options
                    $options = $question['options'] ?? [];
                    $correct_count = 0;

                    foreach ($options as $o_index => $option) {
                        if (empty($option['text']))
                            continue;

                        $is_correct = isset($option['is_correct']) ? 1 : 0;
                        if ($is_correct)
                            $correct_count++;

                        $stmt = $pdo->prepare("
                            INSERT INTO options (question_id, option_text, is_correct, order_index)
                            VALUES (?, ?, ?, ?)
                        ");

                        $stmt->execute([
                            $question_id,
                            sanitize($option['text']),
                            $is_correct,
                            $o_index + 1
                        ]);
                    }

                    if ($correct_count == 0) {
                        throw new Exception("السؤال رقم " . ($q_index + 1) . " يجب أن يحتوي على إجابة صحيحة واحدة على الأقل");
                    }
                }

                $pdo->commit();
                $success = "تم تحديث الاختبار بنجاح!";

                // Reload quiz data
                header("Refresh: 1; url=edit.php?id=$quiz_id");
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "فشل تحديث الاختبار: " . $e->getMessage();
        }
    }
}

// Get attempt count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE quiz_id = ?");
$stmt->execute([$quiz_id]);
$attempt_count = $stmt->fetchColumn();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تعديل الاختبار - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .question-card {
            transition: all 0.3s ease;
        }

        .question-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>

<body class="bg-gray-50">
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg">
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

    <div class="container mx-auto px-4 py-8" x-data="quizEditor()">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">تعديل الاختبار</h1>
            <div class="breadcrumbs text-sm">
                <ul>
                    <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                    <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                    <li>تعديل: <?= e($quiz['title']) ?></li>
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

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <!-- PIN Code Card -->
            <div class="card bg-primary text-primary-content">
                <div class="card-body">
                    <h3 class="card-title text-white">رمز الاختبار</h3>
                    <div class="text-3xl font-bold tracking-wider"><?= $quiz['pin_code'] ?></div>
                    <form method="POST" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <button type="submit" name="regenerate_pin" class="btn btn-sm btn-ghost">
                            <i class="fas fa-sync ml-2"></i>
                            تجديد الرمز
                        </button>
                    </form>
                </div>
            </div>

            <!-- Statistics Card -->
            <div class="card bg-base-100 shadow">
                <div class="card-body">
                    <h3 class="card-title">الإحصائيات</h3>
                    <div class="stat-value text-2xl"><?= $attempt_count ?></div>
                    <div class="stat-desc">محاولة حتى الآن</div>
                    <a href="<?= BASE_URL ?>/teacher/quizzes/results.php?quiz_id=<?= $quiz_id ?>"
                        class="btn btn-sm btn-ghost mt-2">
                        <i class="fas fa-chart-bar ml-2"></i>
                        عرض النتائج
                    </a>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="card bg-base-100 shadow">
                <div class="card-body">
                    <h3 class="card-title">إجراءات سريعة</h3>
                    <div class="space-y-2">
                        <a href="<?= BASE_URL ?>/quiz/join.php?pin=<?= $quiz['pin_code'] ?>" target="_blank"
                            class="btn btn-sm btn-outline w-full">
                            <i class="fas fa-play ml-2"></i>
                            معاينة الاختبار
                        </a>
                        <button onclick="shareQuiz()" class="btn btn-sm btn-outline w-full">
                            <i class="fas fa-share-alt ml-2"></i>
                            مشاركة الاختبار
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="POST" @submit="validateForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="update_quiz" value="1">

            <!-- Quiz Details Card -->
            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-info-circle text-primary"></i>
                        معلومات الاختبار
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">عنوان الاختبار *</span>
                            </label>
                            <input type="text" name="title" value="<?= e($quiz['title']) ?>"
                                class="input input-bordered" required>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المادة</span>
                            </label>
                            <select name="subject_id" class="select select-bordered">
                                <option value="">اختر المادة</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $quiz['subject_id'] == $subject['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= $i ?>" <?= $quiz['grade'] == $i ? 'selected' : '' ?>>
                                        <?= getGradeName($i) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">مستوى الصعوبة *</span>
                            </label>
                            <select name="difficulty" class="select select-bordered" required>
                                <option value="easy" <?= $quiz['difficulty'] == 'easy' ? 'selected' : '' ?>>سهل</option>
                                <option value="medium" <?= $quiz['difficulty'] == 'medium' ? 'selected' : '' ?>>متوسط
                                </option>
                                <option value="hard" <?= $quiz['difficulty'] == 'hard' ? 'selected' : '' ?>>صعب</option>
                                <option value="mixed" <?= $quiz['difficulty'] == 'mixed' ? 'selected' : '' ?>>متنوع
                                </option>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الوقت المحدد (بالدقائق)</span>
                                <span class="label-text-alt">اتركه فارغاً لعدم تحديد وقت</span>
                            </label>
                            <input type="number" name="time_limit" value="<?= $quiz['time_limit'] ?>"
                                class="input input-bordered" min="0" max="180" placeholder="0">
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">لغة الاختبار</span>
                            </label>
                            <select name="language" class="select select-bordered">
                                <option value="ar" <?= $quiz['language'] == 'ar' ? 'selected' : '' ?>>العربية</option>
                                <option value="en" <?= $quiz['language'] == 'en' ? 'selected' : '' ?>>English</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-control mt-4">
                        <label class="label">
                            <span class="label-text">وصف الاختبار</span>
                        </label>
                        <textarea name="description" class="textarea textarea-bordered" rows="3"
                            placeholder="وصف مختصر للاختبار..."><?= e($quiz['description']) ?></textarea>
                    </div>

                    <!-- Quiz Options -->
                    <div class="divider">خيارات الاختبار</div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الأسئلة</span>
                            <input type="checkbox" name="shuffle_questions" class="checkbox checkbox-primary"
                                <?= $quiz['shuffle_questions'] ? 'checked' : '' ?>>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الإجابات</span>
                            <input type="checkbox" name="shuffle_answers" class="checkbox checkbox-primary"
                                <?= $quiz['shuffle_answers'] ? 'checked' : '' ?>>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">إظهار النتائج للطلاب</span>
                            <input type="checkbox" name="show_results" class="checkbox checkbox-primary"
                                <?= $quiz['show_results'] ? 'checked' : '' ?>>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">وضع التدريب</span>
                            <input type="checkbox" name="is_practice" class="checkbox checkbox-primary"
                                <?= $quiz['is_practice'] ? 'checked' : '' ?>>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">الاختبار مفعل</span>
                            <input type="checkbox" name="is_active" class="checkbox checkbox-success"
                                <?= $quiz['is_active'] ? 'checked' : '' ?>>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Questions Section -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-question-circle text-primary"></i>
                            الأسئلة
                        </h2>
                        <button type="button" @click="addQuestion()" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة سؤال
                        </button>
                    </div>

                    <div class="space-y-4">
                        <template x-for="(question, qIndex) in questions" :key="qIndex">
                            <div class="question-card card bg-base-200 shadow">
                                <div class="card-body">
                                    <div class="flex justify-between items-start mb-4">
                                        <h3 class="text-lg font-bold" x-text="'السؤال ' + (qIndex + 1)"></h3>
                                        <div class="flex gap-2">
                                            <button type="button" @click="moveQuestion(qIndex, -1)" x-show="qIndex > 0"
                                                class="btn btn-ghost btn-sm">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                            <button type="button" @click="moveQuestion(qIndex, 1)"
                                                x-show="qIndex < questions.length - 1" class="btn btn-ghost btn-sm">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>
                                            <button type="button" @click="removeQuestion(qIndex)"
                                                class="btn btn-error btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-control mb-4">
                                        <label class="label">
                                            <span class="label-text">نص السؤال *</span>
                                        </label>
                                        <textarea :name="'questions[' + qIndex + '][text]'" x-model="question.text"
                                            class="textarea textarea-bordered" rows="2" required></textarea>
                                    </div>

                                    <div class="form-control mb-4">
                                        <label class="label">
                                            <span class="label-text">النقاط</span>
                                        </label>
                                        <input type="number" :name="'questions[' + qIndex + '][points]'"
                                            x-model="question.points" class="input input-bordered input-sm w-24" min="1"
                                            max="10" required>
                                    </div>

                                    <!-- Options -->
                                    <div class="space-y-2">
                                        <div class="flex justify-between items-center mb-2">
                                            <label class="label">
                                                <span class="label-text">خيارات الإجابة</span>
                                            </label>
                                            <button type="button" @click="addOption(qIndex)"
                                                x-show="question.options.length < 6" class="btn btn-ghost btn-xs">
                                                <i class="fas fa-plus ml-1"></i>
                                                إضافة خيار
                                            </button>
                                        </div>

                                        <template x-for="(option, oIndex) in question.options" :key="oIndex">
                                            <div class="flex items-center gap-2">
                                                <input type="checkbox"
                                                    :name="'questions[' + qIndex + '][options][' + oIndex + '][is_correct]'"
                                                    x-model="option.is_correct" class="checkbox checkbox-success">
                                                <input type="text"
                                                    :name="'questions[' + qIndex + '][options][' + oIndex + '][text]'"
                                                    x-model="option.text" class="input input-bordered input-sm flex-1"
                                                    :placeholder="'الخيار ' + String.fromCharCode(65 + oIndex)"
                                                    required>
                                                <button type="button" @click="removeOption(qIndex, oIndex)"
                                                    x-show="question.options.length > 2"
                                                    class="btn btn-ghost btn-sm text-error">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div x-show="questions.length === 0" class="text-center py-8 text-gray-500">
                            <i class="fas fa-clipboard-list text-4xl mb-4 block"></i>
                            <p>لم تتم إضافة أي أسئلة بعد</p>
                            <button type="button" @click="addQuestion()" class="btn btn-primary btn-sm mt-4">
                                <i class="fas fa-plus ml-2"></i>
                                إضافة أول سؤال
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-between mt-6">
                <button type="button" onclick="confirmDelete()" class="btn btn-error">
                    <i class="fas fa-trash ml-2"></i>
                    حذف الاختبار
                </button>

                <div class="flex gap-3">
                    <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-ghost">إلغاء</a>
                    <button type="submit" class="btn btn-primary" :disabled="questions.length === 0">
                        <i class="fas fa-save ml-2"></i>
                        حفظ التغييرات
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <dialog id="deleteModal" class="modal">
        <div class="modal-box">
            <h3 class="font-bold text-lg text-error">تأكيد الحذف</h3>
            <p class="py-4">
                هل أنت متأكد من حذف هذا الاختبار؟<br>
                <strong>تحذير:</strong> سيتم حذف جميع المحاولات والنتائج المرتبطة بهذا الاختبار نهائياً.
            </p>
            <div class="modal-action">
                <button type="button" class="btn" onclick="deleteModal.close()">إلغاء</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" name="delete_quiz" class="btn btn-error">
                        <i class="fas fa-trash ml-2"></i>
                        حذف نهائياً
                    </button>
                </form>
            </div>
        </div>
    </dialog>

    <script>
        function quizEditor() {
            return {
                questions: <?= json_encode(array_map(function ($q) {
                    return [
                        'text' => $q['question_text'],
                        'points' => $q['points'],
                        'options' => $q['options']
                    ];
                }, $questions)) ?>,

                addQuestion() {
                    this.questions.push({
                        text: '',
                        points: 1,
                        options: [
                            { text: '', is_correct: false },
                            { text: '', is_correct: false },
                            { text: '', is_correct: false },
                            { text: '', is_correct: false }
                        ]
                    });
                },

                removeQuestion(index) {
                    if (confirm('هل أنت متأكد من حذف هذا السؤال؟')) {
                        this.questions.splice(index, 1);
                    }
                },

                moveQuestion(index, direction) {
                    const newIndex = index + direction;
                    if (newIndex >= 0 && newIndex < this.questions.length) {
                        const temp = this.questions[index];
                        this.questions[index] = this.questions[newIndex];
                        this.questions[newIndex] = temp;
                    }
                },

                addOption(questionIndex) {
                    if (this.questions[questionIndex].options.length < 6) {
                        this.questions[questionIndex].options.push({
                            text: '',
                            is_correct: false
                        });
                    }
                },

                removeOption(questionIndex, optionIndex) {
                    this.questions[questionIndex].options.splice(optionIndex, 1);
                },

                validateForm(e) {
                    for (let i = 0; i < this.questions.length; i++) {
                        const hasCorrect = this.questions[i].options.some(o => o.is_correct);
                        if (!hasCorrect) {
                            e.preventDefault();
                            alert('السؤال رقم ' + (i + 1) + ' يجب أن يحتوي على إجابة صحيحة واحدة على الأقل');
                            return false;
                        }
                    }
                    return true;
                }
            }
        }

        function confirmDelete() {
            deleteModal.showModal();
        }

        function shareQuiz() {
            const url = '<?= BASE_URL ?>/quiz/join.php?pin=<?= $quiz['pin_code'] ?>';
            const text = 'انضم لاختبار "<?= e($quiz['title']) ?>" باستخدام الرمز: <?= $quiz['pin_code'] ?>';

            if (navigator.share) {
                navigator.share({
                    title: 'مشاركة الاختبار',
                    text: text,
                    url: url
                });
            } else {
                navigator.clipboard.writeText(text + '\n' + url);
                alert('تم نسخ رابط المشاركة!');
            }
        }
    </script>
</body>

</html>