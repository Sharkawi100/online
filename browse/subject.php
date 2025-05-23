<?php
// /browse/subject.php
require_once '../config/database.php';
require_once '../includes/functions.php';

$subject_id = $_GET['id'] ?? 0;
$grade_filter = $_GET['grade'] ?? '';
$difficulty_filter = $_GET['difficulty'] ?? '';

// Get subject details
$stmt = $pdo->prepare("SELECT * FROM subjects WHERE id = ?");
$stmt->execute([$subject_id]);
$subject = $stmt->fetch();

if (!$subject) {
    redirect('/');
}

// Build query for quizzes
$query = "
    SELECT q.*, 
           u.name as teacher_name,
           COUNT(DISTINCT a.id) as attempt_count,
           AVG(a.score) as avg_score
    FROM quizzes q
    JOIN users u ON q.teacher_id = u.id
    LEFT JOIN attempts a ON q.id = a.quiz_id AND a.completed_at IS NOT NULL
    WHERE q.subject_id = ? AND q.is_active = 1
";

$params = [$subject_id];

// Add filters
if ($grade_filter) {
    $query .= " AND q.grade = ?";
    $params[] = $grade_filter;
}

if ($difficulty_filter) {
    $query .= " AND q.difficulty = ?";
    $params[] = $difficulty_filter;
}

$query .= " GROUP BY q.id ORDER BY q.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$quizzes = $stmt->fetchAll();

// Get available grades for this subject
$stmt = $pdo->prepare("
    SELECT DISTINCT grade 
    FROM quizzes 
    WHERE subject_id = ? AND is_active = 1 
    ORDER BY grade
");
$stmt->execute([$subject_id]);
$available_grades = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get quiz count by difficulty
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN difficulty = 'easy' THEN 1 END) as easy_count,
        COUNT(CASE WHEN difficulty = 'medium' THEN 1 END) as medium_count,
        COUNT(CASE WHEN difficulty = 'hard' THEN 1 END) as hard_count
    FROM quizzes 
    WHERE subject_id = ? AND is_active = 1
");
$stmt->execute([$subject_id]);
$difficulty_stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>اختبارات <?= e($subject['name_ar']) ?> - <?= e(getSetting('site_name')) ?></title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        .quiz-card {
            transition: all 0.3s ease;
        }

        .quiz-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        .subject-hero {
            background: linear-gradient(135deg,
                    var(--subject-color-1) 0%,
                    var(--subject-color-2) 100%);
        }
    </style>
</head>

<body class="bg-gray-50" x-data="{ showPinModal: false, selectedQuizPin: '' }">
    <!-- Navigation -->
    <div class="navbar bg-base-100 shadow-lg sticky top-0 z-40">
        <div class="flex-1">
            <a href="<?= BASE_URL ?>" class="btn btn-ghost normal-case text-xl">
                <i class="fas fa-graduation-cap ml-2"></i>
                <?= e(getSetting('site_name')) ?>
            </a>
        </div>
        <div class="flex-none">
            <?php if (isLoggedIn()): ?>
                <?php if (hasRole('student')): ?>
                    <a href="<?= BASE_URL ?>/student/" class="btn btn-primary">
                        <i class="fas fa-home ml-2"></i>
                        لوحة التحكم
                    </a>
                <?php elseif (hasRole('teacher')): ?>
                    <a href="<?= BASE_URL ?>/teacher/" class="btn btn-primary">
                        <i class="fas fa-chalkboard-teacher ml-2"></i>
                        لوحة المعلم
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt ml-2"></i>
                    تسجيل الدخول
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Subject Hero Section -->
    <div class="subject-hero text-white py-16" style="
        --subject-color-1: <?=
            $subject['color'] == 'blue' ? '#3b82f6' :
            ($subject['color'] == 'green' ? '#10b981' :
                ($subject['color'] == 'purple' ? '#8b5cf6' :
                    ($subject['color'] == 'red' ? '#ef4444' :
                        ($subject['color'] == 'orange' ? '#f97316' :
                            ($subject['color'] == 'teal' ? '#14b8a6' :
                                ($subject['color'] == 'indigo' ? '#6366f1' : '#6b7280'))))))
            ?>;
        --subject-color-2: <?=
            $subject['color'] == 'blue' ? '#1e40af' :
            ($subject['color'] == 'green' ? '#047857' :
                ($subject['color'] == 'purple' ? '#6d28d9' :
                    ($subject['color'] == 'red' ? '#b91c1c' :
                        ($subject['color'] == 'orange' ? '#c2410c' :
                            ($subject['color'] == 'teal' ? '#0f766e' :
                                ($subject['color'] == 'indigo' ? '#4338ca' : '#374151'))))))
            ?>;
    ">
        <div class="container mx-auto px-6">
            <div class="flex items-center gap-6">
                <div
                    class="w-24 h-24 bg-white/20 backdrop-blur-md rounded-full flex items-center justify-center animate__animated animate__bounceIn">
                    <i class="<?= e($subject['icon']) ?> text-5xl"></i>
                </div>
                <div>
                    <h1 class="text-4xl font-bold mb-2 animate__animated animate__fadeInRight">
                        اختبارات <?= e($subject['name_ar']) ?>
                    </h1>
                    <p class="text-xl opacity-90 animate__animated animate__fadeInRight animate__delay-1s">
                        اختبر معلوماتك وتحدى نفسك
                    </p>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 gap-4 mt-8 max-w-2xl">
                <div class="text-center bg-white/10 backdrop-blur-sm rounded-lg p-4">
                    <div class="text-3xl font-bold"><?= count($quizzes) ?></div>
                    <div class="text-sm opacity-75">اختبار متاح</div>
                </div>
                <div class="text-center bg-white/10 backdrop-blur-sm rounded-lg p-4">
                    <div class="text-3xl font-bold"><?= count($available_grades) ?></div>
                    <div class="text-sm opacity-75">مرحلة دراسية</div>
                </div>
                <div class="text-center bg-white/10 backdrop-blur-sm rounded-lg p-4">
                    <div class="text-3xl font-bold">
                        <?= array_sum(array_column($quizzes, 'attempt_count')) ?>
                    </div>
                    <div class="text-sm opacity-75">محاولة</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="bg-white shadow-sm py-4 sticky top-16 z-30">
        <div class="container mx-auto px-6">
            <div class="flex flex-wrap gap-4 items-center">
                <!-- Grade Filter -->
                <div class="form-control">
                    <select class="select select-bordered select-sm" onchange="filterQuizzes(this.value, 'grade')">
                        <option value="">جميع المراحل</option>
                        <?php foreach ($available_grades as $grade): ?>
                            <option value="<?= $grade ?>" <?= $grade_filter == $grade ? 'selected' : '' ?>>
                                <?= getGradeName($grade) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Difficulty Filter -->
                <div class="form-control">
                    <select class="select select-bordered select-sm" onchange="filterQuizzes(this.value, 'difficulty')">
                        <option value="">جميع المستويات</option>
                        <option value="easy" <?= $difficulty_filter == 'easy' ? 'selected' : '' ?>>
                            سهل (<?= $difficulty_stats['easy_count'] ?>)
                        </option>
                        <option value="medium" <?= $difficulty_filter == 'medium' ? 'selected' : '' ?>>
                            متوسط (<?= $difficulty_stats['medium_count'] ?>)
                        </option>
                        <option value="hard" <?= $difficulty_filter == 'hard' ? 'selected' : '' ?>>
                            صعب (<?= $difficulty_stats['hard_count'] ?>)
                        </option>
                    </select>
                </div>

                <!-- Clear Filters -->
                <?php if ($grade_filter || $difficulty_filter): ?>
                    <a href="?id=<?= $subject_id ?>" class="btn btn-ghost btn-sm">
                        <i class="fas fa-times ml-2"></i>
                        مسح الفلاتر
                    </a>
                <?php endif; ?>

                <div class="mr-auto text-sm text-gray-600">
                    عرض <?= count($quizzes) ?> اختبار
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-6 py-8">
        <?php if (empty($quizzes)): ?>
            <!-- No Quizzes -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body text-center py-16">
                    <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                    <h2 class="text-2xl font-bold mb-2">لا توجد اختبارات متاحة</h2>
                    <p class="text-gray-600 mb-6">
                        <?php if ($grade_filter || $difficulty_filter): ?>
                            جرب تغيير خيارات البحث
                        <?php else: ?>
                            لا توجد اختبارات في هذه المادة حالياً
                        <?php endif; ?>
                    </p>
                    <div class="flex gap-3 justify-center">
                        <?php if ($grade_filter || $difficulty_filter): ?>
                            <a href="?id=<?= $subject_id ?>" class="btn btn-primary">
                                <i class="fas fa-redo ml-2"></i>
                                إظهار الكل
                            </a>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>" class="btn btn-ghost">
                            <i class="fas fa-arrow-right ml-2"></i>
                            العودة للرئيسية
                        </a>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Quiz Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($quizzes as $quiz): ?>
                    <div class="quiz-card card bg-base-100 shadow-xl animate__animated animate__fadeInUp">
                        <div class="card-body">
                            <!-- Quiz Header -->
                            <div class="flex justify-between items-start mb-4">
                                <h3 class="card-title text-lg flex-1"><?= e($quiz['title']) ?></h3>
                                <div class="badge badge-<?= getGradeColor($quiz['grade']) ?>">
                                    <?= getGradeName($quiz['grade']) ?>
                                </div>
                            </div>

                            <!-- Quiz Description -->
                            <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                <?= e($quiz['description'] ?: 'اختبار في مادة ' . $subject['name_ar']) ?>
                            </p>

                            <!-- Quiz Info -->
                            <div class="space-y-2 mb-4">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-user-tie w-5 text-gray-400"></i>
                                    <span><?= e($quiz['teacher_name']) ?></span>
                                </div>
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-tachometer-alt w-5 text-gray-400"></i>
                                    <span>
                                        <?php
                                        $difficulties = [
                                            'easy' => ['سهل', 'text-success'],
                                            'medium' => ['متوسط', 'text-warning'],
                                            'hard' => ['صعب', 'text-error']
                                        ];
                                        $diff = $difficulties[$quiz['difficulty']] ?? ['متنوع', 'text-info'];
                                        ?>
                                        <span class="<?= $diff[1] ?> font-medium"><?= $diff[0] ?></span>
                                    </span>
                                </div>
                                <?php if ($quiz['time_limit'] > 0): ?>
                                    <div class="flex items-center text-sm">
                                        <i class="fas fa-clock w-5 text-gray-400"></i>
                                        <span><?= $quiz['time_limit'] ?> دقيقة</span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Quiz Stats -->
                            <?php if ($quiz['attempt_count'] > 0): ?>
                                <div class="stats shadow mb-4">
                                    <div class="stat p-3">
                                        <div class="stat-title text-xs">المحاولات</div>
                                        <div class="stat-value text-lg"><?= $quiz['attempt_count'] ?></div>
                                    </div>
                                    <div class="stat p-3">
                                        <div class="stat-title text-xs">المتوسط</div>
                                        <div class="stat-value text-lg"><?= round($quiz['avg_score'] ?? 0) ?>%</div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Action Buttons -->
                            <div class="card-actions">
                                <button @click="selectedQuizPin = '<?= $quiz['pin_code'] ?>'; showPinModal = true"
                                    class="btn btn-primary btn-block">
                                    <i class="fas fa-play ml-2"></i>
                                    ابدأ الاختبار
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PIN Entry Modal -->
    <div x-show="showPinModal" x-transition class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black opacity-50" @click="showPinModal = false"></div>

            <div class="relative bg-white rounded-lg max-w-md w-full p-6 animate__animated animate__zoomIn">
                <button @click="showPinModal = false" class="absolute top-4 left-4 text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>

                <div class="text-center">
                    <div
                        class="w-20 h-20 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full mx-auto flex items-center justify-center mb-4">
                        <i class="fas fa-key text-white text-3xl"></i>
                    </div>
                    <h3 class="text-2xl font-bold mb-2">رمز الاختبار</h3>
                    <p class="text-gray-600 mb-6">استخدم هذا الرمز للدخول إلى الاختبار</p>

                    <div class="text-5xl font-bold tracking-wider mb-6 text-primary" x-text="selectedQuizPin"></div>

                    <div class="flex gap-3">
                        <button @click="navigator.clipboard.writeText(selectedQuizPin); alert('تم نسخ الرمز!')"
                            class="btn btn-outline flex-1">
                            <i class="fas fa-copy ml-2"></i>
                            نسخ الرمز
                        </button>
                        <form action="<?= BASE_URL ?>/quiz/join.php" method="POST" class="flex-1">
                            <input type="hidden" name="pin_code" :value="selectedQuizPin">
                            <button type="submit" class="btn btn-primary w-full">
                                <i class="fas fa-arrow-left ml-2"></i>
                                دخول الاختبار
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function filterQuizzes(value, type) {
            const url = new URL(window.location);
            if (value) {
                url.searchParams.set(type, value);
            } else {
                url.searchParams.delete(type);
            }
            window.location = url.toString();
        }
    </script>
</body>

</html>