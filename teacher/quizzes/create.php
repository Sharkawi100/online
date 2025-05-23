<?php
// /teacher/quizzes/create.php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is a teacher
if (!isLoggedIn() || (!hasRole('teacher') && !hasRole('admin'))) {
    redirect('/auth/login.php');
}

$error = '';
$success = '';

// Get subjects for dropdown
$stmt = $pdo->query("SELECT * FROM subjects ORDER BY name_ar");
$subjects = $stmt->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_quiz'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Generate PIN code
            $pin_code = generatePIN();
            
            // Get existing columns in quizzes table
            $stmt = $pdo->query("SHOW COLUMNS FROM quizzes");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Build dynamic insert query based on existing columns
            $insertColumns = ['teacher_id', 'title', 'description', 'subject_id', 'grade', 'difficulty', 'time_limit', 'pin_code', 'is_active', 'created_at'];
            $insertValues = [
                $_SESSION['user_id'],
                sanitize($_POST['title']),
                sanitize($_POST['description']),
                $_POST['subject_id'] ?: null,
                (int)$_POST['grade'],
                $_POST['difficulty'],
                (int)$_POST['time_limit'],
                $pin_code,
                1,
                date('Y-m-d H:i:s')
            ];
            
            // Add optional columns if they exist
            if (in_array('language', $columns)) {
                $insertColumns[] = 'language';
                $insertValues[] = $_POST['language'] ?? 'ar';
            }
            if (in_array('shuffle_questions', $columns)) {
                $insertColumns[] = 'shuffle_questions';
                $insertValues[] = isset($_POST['shuffle_questions']) ? 1 : 0;
            }
            if (in_array('shuffle_answers', $columns)) {
                $insertColumns[] = 'shuffle_answers';
                $insertValues[] = isset($_POST['shuffle_answers']) ? 1 : 0;
            }
            if (in_array('show_results', $columns)) {
                $insertColumns[] = 'show_results';
                $insertValues[] = isset($_POST['show_results']) ? 1 : 0;
            }
            if (in_array('is_practice', $columns)) {
                $insertColumns[] = 'is_practice';
                $insertValues[] = isset($_POST['is_practice']) ? 1 : 0;
            }
            
            // Build and execute query
            $placeholders = array_fill(0, count($insertValues), '?');
            $stmt = $pdo->prepare("
                INSERT INTO quizzes (" . implode(', ', $insertColumns) . ")
                VALUES (" . implode(', ', $placeholders) . ")
            ");
            
            $stmt->execute($insertValues);
            
            $quiz_id = $pdo->lastInsertId();
            
            // Insert questions
            $questions = $_POST['questions'] ?? [];
            foreach ($questions as $q_index => $question) {
                if (empty($question['text'])) continue;
                
                $stmt = $pdo->prepare("
                    INSERT INTO questions (quiz_id, question_text, points, order_index)
                    VALUES (?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $quiz_id,
                    sanitize($question['text']),
                    (int)($question['points'] ?? 1),
                    $q_index + 1
                ]);
                
                $question_id = $pdo->lastInsertId();
                
                // Insert options
                $options = $question['options'] ?? [];
                $correct_count = 0;
                
                foreach ($options as $o_index => $option) {
                    if (empty($option['text'])) continue;
                    
                    $is_correct = isset($option['is_correct']) ? 1 : 0;
                    if ($is_correct) $correct_count++;
                    
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
                
                // Validate at least one correct answer
                if ($correct_count == 0) {
                    throw new Exception("السؤال رقم " . ($q_index + 1) . " يجب أن يحتوي على إجابة صحيحة واحدة على الأقل");
                }
            }
            
            $pdo->commit();
            $success = "تم إنشاء الاختبار بنجاح! رمز الاختبار: $pin_code";
            
            // Redirect to edit page after 2 seconds
            header("Refresh: 2; url=edit.php?id=$quiz_id");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "فشل إنشاء الاختبار: " . $e->getMessage();
        }
    }
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إنشاء اختبار جديد - <?= e(getSetting('site_name')) ?></title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    
    <style>
        body { font-family: 'Tajawal', sans-serif; }
        .question-card { transition: all 0.3s ease; }
        .question-card:hover { transform: translateY(-2px); }
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
                <ul tabindex="0" class="menu menu-sm dropdown-content mt-3 z-[1] p-2 shadow bg-base-100 rounded-box w-52">
                    <li><a href="<?= BASE_URL ?>/teacher/profile.php"><i class="fas fa-user ml-2"></i> الملف الشخصي</a></li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt ml-2"></i> تسجيل الخروج</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8" x-data="quizBuilder()">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold mb-2">إنشاء اختبار جديد</h1>
                    <div class="breadcrumbs text-sm">
                        <ul>
                            <li><a href="<?= BASE_URL ?>/teacher/"><i class="fas fa-home ml-2"></i> الرئيسية</a></li>
                            <li><a href="<?= BASE_URL ?>/teacher/quizzes/">الاختبارات</a></li>
                            <li>إنشاء اختبار جديد</li>
                        </ul>
                    </div>
                </div>
                <?php if (getSetting('ai_enabled', true)): ?>
                    <a href="<?= BASE_URL ?>/teacher/quizzes/ai-generate.php" class="btn btn-secondary">
                        <i class="fas fa-magic ml-2"></i>
                        توليد بالذكاء الاصطناعي
                    </a>
                <?php endif; ?>
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
        
        <?php if ($ai_generated): ?>
            <div class="alert alert-info mb-6">
                <i class="fas fa-magic"></i>
                <span>تم تحميل <?= count($pre_filled_questions) ?> سؤال من الذكاء الاصطناعي. يمكنك تعديلها أو إضافة المزيد.</span>
            </div>
        <?php endif; ?>

        <form method="POST" @submit="validateForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="create_quiz" value="1">

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
                            <input type="text" name="title" class="input input-bordered" required>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">المادة</span>
                            </label>
                            <select name="subject_id" class="select select-bordered">
                                <option value="">اختر المادة</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" 
                                            <?= (isset($pre_filled_params) && $pre_filled_params['subject_id'] == $subject['id']) ? 'selected' : '' ?>>
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
                                            <?= (isset($pre_filled_params) && $pre_filled_params['grade'] == $i) ? 'selected' : '' ?>>
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
                                <option value="easy" <?= (isset($pre_filled_params) && $pre_filled_params['difficulty'] == 'easy') ? 'selected' : '' ?>>سهل</option>
                                <option value="medium" <?= (!isset($pre_filled_params) || $pre_filled_params['difficulty'] == 'medium') ? 'selected' : '' ?>>متوسط</option>
                                <option value="hard" <?= (isset($pre_filled_params) && $pre_filled_params['difficulty'] == 'hard') ? 'selected' : '' ?>>صعب</option>
                                <option value="mixed">متنوع</option>
                            </select>
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">الوقت المحدد (بالدقائق)</span>
                                <span class="label-text-alt">اتركه فارغاً لعدم تحديد وقت</span>
                            </label>
                            <input type="number" name="time_limit" class="input input-bordered" min="0" max="180" placeholder="0">
                        </div>

                        <div class="form-control">
                            <label class="label">
                                <span class="label-text">لغة الاختبار</span>
                            </label>
                            <select name="language" class="select select-bordered">
                                <option value="ar" selected>العربية</option>
                                <option value="en">English</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-control mt-4">
                        <label class="label">
                            <span class="label-text">وصف الاختبار</span>
                        </label>
                        <textarea name="description" class="textarea textarea-bordered" rows="3" 
                                  placeholder="وصف مختصر للاختبار..."></textarea>
                    </div>

                    <!-- Quiz Options -->
                    <div class="divider">خيارات الاختبار</div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الأسئلة</span>
                            <input type="checkbox" name="shuffle_questions" class="checkbox checkbox-primary" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">خلط ترتيب الإجابات</span>
                            <input type="checkbox" name="shuffle_answers" class="checkbox checkbox-primary" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">إظهار النتائج للطلاب</span>
                            <input type="checkbox" name="show_results" class="checkbox checkbox-primary" checked>
                        </label>

                        <label class="label cursor-pointer">
                            <span class="label-text">وضع التدريب (مع تغذية راجعة فورية)</span>
                            <input type="checkbox" name="is_practice" class="checkbox checkbox-primary">
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
                                            <button type="button" @click="moveQuestion(qIndex, -1)" 
                                                    x-show="qIndex > 0" class="btn btn-ghost btn-sm">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>
                                            <button type="button" @click="moveQuestion(qIndex, 1)" 
                                                    x-show="qIndex < questions.length - 1" class="btn btn-ghost btn-sm">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>
                                            <button type="button" @click="removeQuestion(qIndex)" class="btn btn-error btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-control mb-4">
                                        <label class="label">
                                            <span class="label-text">نص السؤال *</span>
                                        </label>
                                        <textarea :name="'questions[' + qIndex + '][text]'" 
                                                  x-model="question.text"
                                                  class="textarea textarea-bordered" 
                                                  rows="2" required></textarea>
                                    </div>

                                    <div class="form-control mb-4">
                                        <label class="label">
                                            <span class="label-text">النقاط</span>
                                        </label>
                                        <input type="number" :name="'questions[' + qIndex + '][points]'" 
                                               x-model="question.points"
                                               class="input input-bordered input-sm w-24" 
                                               min="1" max="10" required>
                                    </div>

                                    <!-- Options -->
                                    <div class="space-y-2">
                                        <div class="flex justify-between items-center mb-2">
                                            <label class="label">
                                                <span class="label-text">خيارات الإجابة</span>
                                            </label>
                                            <button type="button" @click="addOption(qIndex)" 
                                                    x-show="question.options.length < 6"
                                                    class="btn btn-ghost btn-xs">
                                                <i class="fas fa-plus ml-1"></i>
                                                إضافة خيار
                                            </button>
                                        </div>

                                        <template x-for="(option, oIndex) in question.options" :key="oIndex">
                                            <div class="flex items-center gap-2">
                                                <input type="checkbox" 
                                                       :name="'questions[' + qIndex + '][options][' + oIndex + '][is_correct]'"
                                                       x-model="option.is_correct"
                                                       class="checkbox checkbox-success">
                                                <input type="text" 
                                                       :name="'questions[' + qIndex + '][options][' + oIndex + '][text]'"
                                                       x-model="option.text"
                                                       class="input input-bordered input-sm flex-1" 
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
            <div class="flex justify-end gap-3 mt-6">
                <a href="<?= BASE_URL ?>/teacher/quizzes/" class="btn btn-ghost">إلغاء</a>
                <button type="submit" class="btn btn-primary" :disabled="questions.length === 0">
                    <i class="fas fa-save ml-2"></i>
                    إنشاء الاختبار
                </button>
            </div>
        </form>
    </div>

    <script>
        function quizBuilder() {
            return {
                questions: <?= isset($pre_filled_questions) ? json_encode(array_map(function($q) {
                    return [
                        'text' => $q['question_text'],
                        'points' => 1,
                        'options' => array_map(function($opt, $idx) use ($q) {
                            return [
                                'text' => $opt,
                                'is_correct' => $idx === $q['correct_index']
                            ];
                        }, $q['options'], array_keys($q['options']))
                    ];
                }, $pre_filled_questions)) : '[{
                    text: "",
                    points: 1,
                    options: [
                        { text: "", is_correct: false },
                        { text: "", is_correct: false },
                        { text: "", is_correct: false },
                        { text: "", is_correct: false }
                    ]
                }]' ?>,

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
                    // Check each question has at least one correct answer
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
    </script>
</body>
</html>