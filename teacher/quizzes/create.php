<?php
// teacher/quizzes/create.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}

if (!isLoggedIn() || (!hasRole('admin') && !hasRole('teacher'))) {
    redirect('/auth/login.php');
}

// Initialize all variables to prevent undefined warnings
$success = '';
$error = '';
$quiz_id = null;
$title = '';
$description = '';
$subject_id = '';
$grade = '';
$difficulty = 'medium';
$time_limit = 0;
$is_practice = false;
$has_text = false;
$text_id = null;
$ai_generated = false;
$shuffle_questions = false;
$shuffle_answers = false;
$show_results = true;
$questions = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        // Get form data
        $title = sanitize($_POST['title'] ?? '');
        $description = $_POST['description'] ?? '';
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $grade = (int)($_POST['grade'] ?? 0);
        $difficulty = sanitize($_POST['difficulty'] ?? 'medium');
        $time_limit = (int)($_POST['time_limit'] ?? 0);
        $is_practice = isset($_POST['is_practice']) ? 1 : 0;
        $has_text = isset($_POST['has_text']) ? 1 : 0;
        $ai_generated = isset($_POST['ai_generated']) ? 1 : 0;
        $shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;
        $shuffle_answers = isset($_POST['shuffle_answers']) ? 1 : 0;
        $show_results = isset($_POST['show_results']) ? 1 : 0;
        
        // Validate required fields
        $errors = [];
        if (empty($title)) $errors[] = 'عنوان الاختبار مطلوب';
        if (empty($subject_id)) $errors[] = 'يرجى اختيار المادة';
        if (empty($grade)) $errors[] = 'يرجى اختيار الصف';
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // Generate PIN code
                $pin_code = generatePIN();
                
                // Insert quiz
                $stmt = $pdo->prepare("
                    INSERT INTO quizzes (
                        title, description, subject_id, teacher_id, grade, 
                        difficulty, time_limit, pin_code, is_practice, has_text,
                        ai_generated, shuffle_questions, shuffle_answers, show_results
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $title, $description, $subject_id, $_SESSION['user_id'], $grade,
                    $difficulty, $time_limit, $pin_code, $is_practice, $has_text,
                    $ai_generated, $shuffle_questions, $shuffle_answers, $show_results
                ]);
                
                $quiz_id = $pdo->lastInsertId();
                
                // Process questions if any
                if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                    $question_order = 0;
                    
                    foreach ($_POST['questions'] as $q) {
                        if (empty($q['text'])) continue;
                        
                        // Insert question
                        $stmt = $pdo->prepare("
                            INSERT INTO questions (
                                quiz_id, question_text, question_type, points, 
                                order_index, ai_generated
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $quiz_id,
                            $q['text'],
                            'multiple_choice',
                            (int)($q['points'] ?? 10),
                            $question_order++,
                            isset($q['ai_generated']) ? 1 : 0
                        ]);
                        
                        $question_id = $pdo->lastInsertId();
                        
                        // Insert options
                        if (isset($q['options']) && is_array($q['options'])) {
                            $option_order = 0;
                            foreach ($q['options'] as $idx => $option_text) {
                                if (empty($option_text)) continue;
                                
                                $stmt = $pdo->prepare("
                                    INSERT INTO options (
                                        question_id, option_text, is_correct, order_index
                                    ) VALUES (?, ?, ?, ?)
                                ");
                                
                                $is_correct = (int)($q['correct'] ?? 0) === $idx ? 1 : 0;
                                
                                $stmt->execute([
                                    $question_id,
                                    $option_text,
                                    $is_correct,
                                    $option_order++
                                ]);
                            }
                        }
                    }
                }
                
                $pdo->commit();
                
                // Redirect to quiz management
                $_SESSION['success'] = 'تم إنشاء الاختبار بنجاح! رمز PIN: ' . $pin_code;
                redirect('/teacher/quizzes/manage.php?id=' . $quiz_id);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'خطأ في إنشاء الاختبار: ' . $e->getMessage();
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

// Get subjects for dropdown
$subjects = $pdo->query("SELECT * FROM subjects WHERE is_active = 1 ORDER BY order_index")->fetchAll();

// Check if we have AI-generated questions in session
if (isset($_SESSION['ai_questions'])) {
    $questions = $_SESSION['ai_questions'];
    $ai_generated = true;
    unset($_SESSION['ai_questions']); // Clear after use
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء اختبار جديد - <?= e(getSetting('site_name', 'منصة الاختبارات')) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        .question-item { transition: all 0.3s ease; }
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
            <a href="/teacher/dashboard.php" class="btn btn-ghost">
                <i class="fas fa-arrow-right ml-2"></i>
                العودة للوحة التحكم
            </a>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8 max-w-6xl">
        <h1 class="text-3xl font-bold mb-8">
            <i class="fas fa-plus-circle text-primary ml-2"></i>
            إنشاء اختبار جديد
        </h1>

        <?php if ($error): ?>
            <div class="alert alert-error mb-6">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" @submit="validateForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="ai_generated" value="<?= $ai_generated ? '1' : '0' ?>">

            <!-- Basic Information -->
            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-info-circle text-info"></i>
                        المعلومات الأساسية
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">عنوان الاختبار *</span>
                            </label>
                            <input type="text" name="title" value="<?= e($title) ?>" 
                                   class="input input-bordered" required>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المادة *</span>
                            </label>
                            <select name="subject_id" class="select select-bordered" required>
                                <option value="">اختر المادة</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" 
                                            <?= $subject_id == $subject['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= $i ?>" <?= $grade == $i ? 'selected' : '' ?>>
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
                                <option value="easy" <?= $difficulty == 'easy' ? 'selected' : '' ?>>سهل</option>
                                <option value="medium" <?= $difficulty == 'medium' ? 'selected' : '' ?>>متوسط</option>
                                <option value="hard" <?= $difficulty == 'hard' ? 'selected' : '' ?>>صعب</option>
                                <option value="mixed" <?= $difficulty == 'mixed' ? 'selected' : '' ?>>متنوع</option>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المدة الزمنية (بالدقائق)</span>
                            </label>
                            <input type="number" name="time_limit" value="<?= $time_limit ?>" 
                                   class="input input-bordered" min="0" placeholder="0 = بدون وقت محدد">
                        </div>
                    </div>

                    <div class="form-control mt-4">
                        <label class="label">
                            <span class="label-text">وصف الاختبار</span>
                        </label>
                        <textarea name="description" class="textarea textarea-bordered" 
                                  rows="3" placeholder="وصف مختصر للاختبار (اختياري)"><?= e($description) ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Quiz Settings -->
            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-cog text-warning"></i>
                        إعدادات الاختبار
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-control">
                            <label class="label cursor-pointer">
                                <span class="label-text">وضع التدريب</span>
                                <input type="checkbox" name="is_practice" class="checkbox checkbox-primary" 
                                       <?= $is_practice ? 'checked' : '' ?>>
                            </label>
                            <label class="label">
                                <span class="label-text-alt text-gray-500">إظهار الإجابات الصحيحة فوراً</span>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer">
                                <span class="label-text">خلط الأسئلة</span>
                                <input type="checkbox" name="shuffle_questions" class="checkbox checkbox-primary"
                                       <?= $shuffle_questions ? 'checked' : '' ?>>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer">
                                <span class="label-text">خلط الإجابات</span>
                                <input type="checkbox" name="shuffle_answers" class="checkbox checkbox-primary"
                                       <?= $shuffle_answers ? 'checked' : '' ?>>
                            </label>
                        </div>

                        <div class="form-control">
                            <label class="label cursor-pointer">
                                <span class="label-text">إظهار النتائج للطلاب</span>
                                <input type="checkbox" name="show_results" class="checkbox checkbox-primary"
                                       <?= $show_results ? 'checked' : '' ?>>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Questions Section -->
            <div class="card bg-base-100 shadow-xl mb-6">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-question-circle text-success"></i>
                            الأسئلة
                        </h2>
                        <div class="flex gap-2">
                            <button type="button" @click="addQuestion()" class="btn btn-success btn-sm">
                                <i class="fas fa-plus ml-2"></i>
                                إضافة سؤال
                            </button>
                            <a href="ai-generate.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-magic ml-2"></i>
                                توليد بالذكاء الاصطناعي
                            </a>
                        </div>
                    </div>

                    <div id="questions-container">
                        <template x-for="(question, qIndex) in questions" :key="qIndex">
                            <div class="question-item border rounded-lg p-4 mb-4 bg-base-200">
                                <div class="flex justify-between items-start mb-3">
                                    <h3 class="text-lg font-semibold" x-text="'السؤال ' + (qIndex + 1)"></h3>
                                    <button type="button" @click="removeQuestion(qIndex)" 
                                            class="btn btn-error btn-xs">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>

                                <div class="form-control mb-3">
                                    <input type="text" :name="'questions[' + qIndex + '][text]'" 
                                           x-model="question.text"
                                           class="input input-bordered w-full" 
                                           placeholder="نص السؤال" required>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-3">
                                    <template x-for="(option, oIndex) in question.options" :key="oIndex">
                                        <div class="flex items-center gap-2">
                                            <input type="radio" 
                                                   :name="'questions[' + qIndex + '][correct]'" 
                                                   :value="oIndex"
                                                   x-model="question.correct"
                                                   class="radio radio-primary">
                                            <input type="text" 
                                                   :name="'questions[' + qIndex + '][options][]'" 
                                                   x-model="option.text"
                                                   class="input input-bordered input-sm flex-1" 
                                                   :placeholder="'الخيار ' + (oIndex + 1)" required>
                                        </div>
                                    </template>
                                </div>

                                <div class="flex items-center gap-4">
                                    <div class="form-control">
                                        <label class="label">
                                            <span class="label-text text-sm">الدرجات</span>
                                        </label>
                                        <input type="number" 
                                               :name="'questions[' + qIndex + '][points]'" 
                                               x-model="question.points"
                                               class="input input-bordered input-sm w-20" 
                                               min="1" max="100">
                                    </div>
                                    <template x-if="question.ai_generated">
                                        <div class="badge badge-primary">
                                            <i class="fas fa-robot ml-1"></i>
                                            مولد بالذكاء الاصطناعي
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <div x-show="questions.length === 0" class="text-center py-8 text-gray-500">
                            <i class="fas fa-clipboard-list text-4xl mb-2"></i>
                            <p>لم تتم إضافة أي أسئلة بعد</p>
                            <p class="text-sm mt-2">انقر على "إضافة سؤال" أو "توليد بالذكاء الاصطناعي"</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex justify-between">
                <a href="/teacher/quizzes/" class="btn btn-ghost">
                    <i class="fas fa-times ml-2"></i>
                    إلغاء
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save ml-2"></i>
                    حفظ الاختبار
                </button>
            </div>
        </form>
    </div>

    <script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('quizCreator', () => ({
            questions: <?= json_encode($questions) ?> || [],
            
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
            },
            
            removeQuestion(index) {
                if (confirm('هل أنت متأكد من حذف هذا السؤال؟')) {
                    this.questions.splice(index, 1);
                }
            },
            
            validateForm(e) {
                if (this.questions.length === 0) {
                    e.preventDefault();
                    alert('يجب إضافة سؤال واحد على الأقل');
                    return false;
                }
                return true;
            }
        }));
    });
    </script>
</body>
</html>