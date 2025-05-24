<?php
// /setup/setup-ai-features.php
// Run this script once to set up AI quiz generation features
// Access it at: https://www.iseraj.com/online/setup/setup-ai-features.php

require_once '../config/database.php';

$messages = [];
$errors = [];

try {
    // Start transaction
    $pdo->beginTransaction();

    // API Settings Table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS api_settings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider ENUM('openai', 'claude') NOT NULL,
            api_key TEXT NOT NULL,
            model VARCHAR(50) NOT NULL DEFAULT 'gpt-4',
            is_active TINYINT(1) DEFAULT 1,
            usage_count INT UNSIGNED DEFAULT 0,
            last_used TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_provider (provider, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
        $messages[] = "✓ Created api_settings table";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            $messages[] = "Table api_settings already exists (skipped)";
        } else {
            throw $e;
        }
    }

    // Quiz Texts Table for Reading Comprehension
    try {
        $sql = "CREATE TABLE IF NOT EXISTS quiz_texts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED,
            text_title VARCHAR(255) NOT NULL,
            text_content LONGTEXT NOT NULL,
            source ENUM('manual', 'ai_generated') DEFAULT 'manual',
            reading_time INT UNSIGNED DEFAULT 0 COMMENT 'Estimated reading time in seconds',
            created_by INT UNSIGNED,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id),
            INDEX idx_quiz (quiz_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
        $messages[] = "✓ Created quiz_texts table";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            $messages[] = "Table quiz_texts already exists (skipped)";
        } else {
            throw $e;
        }
    }

    // AI Generation Logs
    try {
        // Use LONGTEXT instead of JSON for older MySQL versions
        $sql = "CREATE TABLE IF NOT EXISTS ai_generation_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL,
            prompt_type ENUM('general', 'text_based', 'custom') NOT NULL,
            subject_id INT UNSIGNED,
            grade TINYINT UNSIGNED,
            difficulty VARCHAR(10),
            questions_generated INT UNSIGNED DEFAULT 0,
            tokens_used INT UNSIGNED DEFAULT 0,
            cost_estimate DECIMAL(10,4) DEFAULT 0.0000,
            request_data LONGTEXT,
            response_data LONGTEXT,
            success TINYINT(1) DEFAULT 1,
            error_message TEXT,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (teacher_id) REFERENCES users(id),
            FOREIGN KEY (subject_id) REFERENCES subjects(id),
            INDEX idx_teacher (teacher_id),
            INDEX idx_generated (generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
        $messages[] = "✓ Created ai_generation_logs table";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            $messages[] = "Table ai_generation_logs already exists (skipped)";
        } else {
            throw $e;
        }
    }

    // AI Prompt Templates
    try {
        $sql = "CREATE TABLE IF NOT EXISTS ai_prompt_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            prompt_template TEXT NOT NULL,
            subject_id INT UNSIGNED,
            grade_range_start TINYINT UNSIGNED,
            grade_range_end TINYINT UNSIGNED,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subject_id) REFERENCES subjects(id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec($sql);
        $messages[] = "✓ Created ai_prompt_templates table";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            $messages[] = "Table ai_prompt_templates already exists (skipped)";
        } else {
            throw $e;
        }
    }

    // Add columns to existing tables
    // Check if columns exist before adding them
    $columnChecks = [
        ['quizzes', 'has_text', "ALTER TABLE quizzes ADD COLUMN has_text TINYINT(1) DEFAULT 0 AFTER is_practice"],
        ['quizzes', 'text_id', "ALTER TABLE quizzes ADD COLUMN text_id INT UNSIGNED NULL AFTER has_text"],
        ['quizzes', 'ai_generated', "ALTER TABLE quizzes ADD COLUMN ai_generated TINYINT(1) DEFAULT 0 AFTER text_id"],
        ['questions', 'is_text_based', "ALTER TABLE questions ADD COLUMN is_text_based TINYINT(1) DEFAULT 0 AFTER question_type"],
        ['questions', 'ai_generated', "ALTER TABLE questions ADD COLUMN ai_generated TINYINT(1) DEFAULT 0 AFTER is_text_based"]
    ];

    foreach ($columnChecks as $check) {
        $table = $check[0];
        $column = $check[1];
        $query = $check[2];

        // Check if column exists
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);

        if ($stmt->fetchColumn() == 0) {
            try {
                $pdo->exec($query);
                $messages[] = "✓ Added column $column to $table";
            } catch (PDOException $e) {
                $errors[] = "Failed to add column $column to $table: " . $e->getMessage();
            }
        } else {
            $messages[] = "Column $column already exists in $table (skipped)";
        }
    }

    // Add AI settings
    $settings = [
        ['ai_enabled', 'true', 'boolean'],
        ['ai_default_provider', 'openai', 'string'],
        ['ai_monthly_limit', '1000', 'number'],
        ['ai_cost_per_question', '0.05', 'number'],
        ['ai_max_questions_per_request', '10', 'number'],
        ['text_editor_enabled', 'true', 'boolean']
    ];

    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, setting_type)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = setting_value
    ");

    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }
    $messages[] = "✓ AI settings configured";

    // Insert sample prompt templates
    $templates = [
        [
            'عام - أسئلة متنوعة',
            'قالب عام لتوليد أسئلة في أي موضوع',
            'أنشئ {count} سؤال اختيار من متعدد باللغة العربية للصف {grade} في مادة {subject}...',
            null
        ],
        [
            'فهم المقروء',
            'قالب لأسئلة الفهم القرائي',
            'بناءً على النص التالي:\n{text}\n\nأنشئ {count} سؤال فهم واستيعاب...',
            null
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO ai_prompt_templates (name, description, prompt_template, subject_id)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($templates as $template) {
        $stmt->execute($template);
    }
    $messages[] = "✓ Prompt templates added";

    // Create directories
    $directories = [
        '../teacher',
        '../teacher/ajax',
        '../teacher/quizzes'
    ];

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (@mkdir($dir, 0755, true)) {
                $messages[] = "✓ Created directory: $dir";
            } else {
                $errors[] = "Failed to create directory: $dir (may already exist)";
            }
        } else {
            $messages[] = "Directory already exists: $dir";
        }
    }

    // Check if we're still in a transaction before committing
    if ($pdo->inTransaction()) {
        $pdo->commit();
        $messages[] = "✅ AI features setup completed successfully!";
    } else {
        $messages[] = "✅ Setup completed (no transaction to commit)";
    }

} catch (Exception $e) {
    // Only rollback if transaction is active
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errors[] = "Setup failed: " . $e->getMessage();
}

// Check if BASE_URL is defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/online');
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد ميزات الذكاء الاصطناعي</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="card bg-base-100 shadow-xl max-w-3xl w-full">
        <div class="card-body">
            <h1 class="card-title text-2xl mb-4">
                <i class="fas fa-magic text-purple-600 ml-2"></i>
                إعداد ميزات الذكاء الاصطناعي
            </h1>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error mb-4">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <h3 class="font-bold">حدثت أخطاء:</h3>
                        <ul class="mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($messages)): ?>
                <div class="bg-base-200 rounded-lg p-4">
                    <h3 class="font-bold mb-2">سجل العمليات:</h3>
                    <ul class="space-y-1">
                        <?php foreach ($messages as $message): ?>
                            <li class="flex items-center gap-2">
                                <?php if (strpos($message, '✓') !== false || strpos($message, '✅') !== false): ?>
                                    <i class="fas fa-check-circle text-success"></i>
                                <?php else: ?>
                                    <i class="fas fa-info-circle text-info"></i>
                                <?php endif; ?>
                                <span><?= htmlspecialchars($message) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="divider"></div>

            <div class="prose max-w-none">
                <h3>الخطوات التالية:</h3>
                <ol>
                    <li>اذهب إلى <a href="<?= BASE_URL ?>/admin/ai-settings.php" class="link link-primary">إعدادات
                            الذكاء الاصطناعي</a> في لوحة الإدارة</li>
                    <li>أضف مفاتيح API الخاصة بك (OpenAI أو Claude)</li>
                    <li>اختبر التوليد من <a href="<?= BASE_URL ?>/teacher/quizzes/ai-generate.php"
                            class="link link-primary">صفحة توليد الاختبارات</a></li>
                </ol>

                <div class="alert alert-warning mt-4">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>تنبيه أمني:</strong> يُنصح بحذف هذا الملف بعد الاستخدام
                    </div>
                </div>
            </div>

            <div class="card-actions justify-end mt-6">
                <a href="<?= BASE_URL ?>/admin/" class="btn btn-ghost">لوحة الإدارة</a>
                <a href="<?= BASE_URL ?>/admin/ai-settings.php" class="btn btn-primary">
                    <i class="fas fa-cog ml-2"></i>
                    إعدادات الذكاء الاصطناعي
                </a>
            </div>
        </div>
    </div>
</body>

</html>