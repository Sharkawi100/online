<?php
// /setup/create-test-accounts.php
// Run this file once to create test accounts
// Access it at: https://www.iseraj.com/online/setup/create-test-accounts.php

require_once '../config/database.php';
require_once '../includes/functions.php';

// Security: Only allow this script to run if no users exist or in development
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'student')");
$userCount = $stmt->fetchColumn();

if ($userCount > 5) {
    die("Test accounts already exist or system has real users. This script is disabled for security.");
}

$created = [];
$errors = [];

try {
    $pdo->beginTransaction();

    // Create test teacher account
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['teacher@example.com']);

    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, is_active, created_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ");

        $stmt->execute([
            'أستاذ أحمد محمد',
            'teacher@example.com',
            password_hash('teacher123', PASSWORD_DEFAULT),
            'teacher'
        ]);

        $teacherId = $pdo->lastInsertId();
        $created[] = "Teacher account created: teacher@example.com (password: teacher123)";

        // Create a sample quiz for the teacher
        $pinCode = generatePIN();
        $stmt = $pdo->prepare("
            INSERT INTO quizzes (
                teacher_id, title, description, subject_id, grade, difficulty,
                time_limit, pin_code, language, shuffle_questions, shuffle_answers,
                show_results, is_practice, is_active, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $teacherId,
            'اختبار تجريبي في الرياضيات',
            'اختبار قصير لتجربة النظام',
            1, // Math
            7, // Grade 7
            'medium',
            10, // 10 minutes
            $pinCode,
            'ar',
            1, // shuffle questions
            1, // shuffle answers
            1, // show results
            0, // not practice mode
            1  // active
        ]);

        $quizId = $pdo->lastInsertId();

        // Add sample questions
        $questions = [
            ['text' => 'ما هو ناتج 5 × 6؟', 'points' => 1],
            ['text' => 'ما هو الجذر التربيعي للعدد 16؟', 'points' => 2],
            ['text' => 'أي من الأعداد التالية عدد أولي؟', 'points' => 2]
        ];

        $options = [
            [ // Question 1 options
                ['text' => '25', 'correct' => false],
                ['text' => '30', 'correct' => true],
                ['text' => '35', 'correct' => false],
                ['text' => '40', 'correct' => false]
            ],
            [ // Question 2 options
                ['text' => '2', 'correct' => false],
                ['text' => '3', 'correct' => false],
                ['text' => '4', 'correct' => true],
                ['text' => '5', 'correct' => false]
            ],
            [ // Question 3 options
                ['text' => '9', 'correct' => false],
                ['text' => '11', 'correct' => true],
                ['text' => '15', 'correct' => false],
                ['text' => '21', 'correct' => false]
            ]
        ];

        foreach ($questions as $qIndex => $question) {
            $stmt = $pdo->prepare("
                INSERT INTO questions (quiz_id, question_text, question_type, points, order_index)
                VALUES (?, ?, 'multiple_choice', ?, ?)
            ");
            $stmt->execute([$quizId, $question['text'], $question['points'], $qIndex + 1]);

            $questionId = $pdo->lastInsertId();

            foreach ($options[$qIndex] as $oIndex => $option) {
                $stmt = $pdo->prepare("
                    INSERT INTO options (question_id, option_text, is_correct, order_index)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $questionId,
                    $option['text'],
                    $option['correct'] ? 1 : 0,
                    $oIndex + 1
                ]);
            }
        }

        $created[] = "Sample quiz created with PIN: $pinCode";
    }

    // Create test student account
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['student@example.com']);

    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("
            INSERT INTO users (name, email, password, role, grade, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");

        $stmt->execute([
            'طالب محمد علي',
            'student@example.com',
            password_hash('student123', PASSWORD_DEFAULT),
            'student',
            7 // Grade 7
        ]);

        $created[] = "Student account created: student@example.com (password: student123)";
    }

    // Create sample achievements if they don't exist
    $achievements = [
        ['مرحباً بك!', 'انضم إلى المنصة', 'fa-door-open', 'welcome', 1, 10],
        ['أول اختبار', 'أكمل أول اختبار', 'fa-flag-checkered', 'count', 1, 20],
        ['متعلم نشط', 'أكمل 5 اختبارات', 'fa-fire', 'count', 5, 50],
        ['خبير', 'أكمل 10 اختبارات', 'fa-medal', 'count', 10, 100],
        ['درجة كاملة', 'احصل على 100% في اختبار', 'fa-star', 'perfect', 100, 30],
        ['سريع البرق', 'أكمل اختبار في أقل من دقيقة', 'fa-bolt', 'speed', 60, 25],
        ['متواصل', 'حافظ على نشاطك لمدة 7 أيام', 'fa-calendar-check', 'streak', 7, 40]
    ];

    foreach ($achievements as $achievement) {
        $stmt = $pdo->prepare("SELECT id FROM achievements WHERE name = ?");
        $stmt->execute([$achievement[0]]);

        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO achievements (name, description, icon, criteria_type, criteria_value, points_value, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute($achievement);
        }
    }

    $created[] = "Achievement system initialized";

    $pdo->commit();

} catch (Exception $e) {
    $pdo->rollBack();
    $errors[] = "Error creating test accounts: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد حسابات تجريبية</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="card bg-base-100 shadow-xl max-w-2xl w-full">
        <div class="card-body">
            <h1 class="card-title text-2xl mb-4">إعداد حسابات تجريبية</h1>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-4">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($created)): ?>
                <div class="alert alert-success mb-4">
                    <div>
                        <h3 class="font-bold">تم إنشاء الحسابات التجريبية بنجاح:</h3>
                        <ul class="mt-2">
                            <?php foreach ($created as $item): ?>
                                <li>✓ <?= htmlspecialchars($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
                    <div class="card bg-primary text-primary-content">
                        <div class="card-body">
                            <h2 class="card-title">حساب المعلم</h2>
                            <p>البريد: teacher@example.com</p>
                            <p>كلمة المرور: teacher123</p>
                            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-sm btn-primary-content mt-2">
                                تسجيل دخول المعلم
                            </a>
                        </div>
                    </div>

                    <div class="card bg-secondary text-secondary-content">
                        <div class="card-body">
                            <h2 class="card-title">حساب الطالب</h2>
                            <p>البريد: student@example.com</p>
                            <p>كلمة المرور: student123</p>
                            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-sm btn-secondary-content mt-2">
                                تسجيل دخول الطالب
                            </a>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning mt-4">
                    <p>
                        <strong>تنبيه:</strong> يُنصح بحذف هذا الملف بعد الاستخدام لأسباب أمنية.
                    </p>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <p>الحسابات التجريبية موجودة بالفعل أو تم تعطيل هذا السكريبت لأسباب أمنية.</p>
                </div>
            <?php endif; ?>

            <div class="card-actions justify-end mt-4">
                <a href="<?= BASE_URL ?>" class="btn btn-ghost">الصفحة الرئيسية</a>
                <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary">تسجيل الدخول</a>
            </div>
        </div>
    </div>
</body>

</html>