<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verifyCSRF($csrf)) {
        $error = 'انتهت صلاحية الجلسة. يرجى إعادة المحاولة.';
    } else {
        try {
            // Handle logo upload
            if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/logos/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $imageInfo = getimagesize($_FILES['site_logo']['tmp_name']);
                if ($imageInfo !== false) {
                    $extension = image_type_to_extension($imageInfo[2]);
                    $filename = 'logo_' . time() . $extension;
                    $uploadPath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['site_logo']['tmp_name'], $uploadPath)) {
                        $_POST['site_logo'] = 'uploads/logos/' . $filename;
                    }
                }
            }

            // Handle favicon upload
            if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/logos/';
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }

                $imageInfo = getimagesize($_FILES['site_favicon']['tmp_name']);
                if ($imageInfo !== false) {
                    $extension = image_type_to_extension($imageInfo[2]);
                    $filename = 'favicon_' . time() . $extension;
                    $uploadPath = $uploadDir . $filename;

                    if (move_uploaded_file($_FILES['site_favicon']['tmp_name'], $uploadPath)) {
                        $_POST['site_favicon'] = 'uploads/logos/' . $filename;
                    }
                }
            }

            // Get all POST data except CSRF token and files
            $settings = $_POST;
            unset($settings['csrf_token']);

            // Process special fields
            if (isset($settings['allowed_file_types'])) {
                $settings['allowed_file_types'] = json_encode(array_map('trim', explode(',', $settings['allowed_file_types'])));
            }

            if (isset($settings['blocked_ips'])) {
                $settings['blocked_ips'] = json_encode(array_filter(array_map('trim', explode("\n", $settings['blocked_ips']))));
            }

            if (isset($settings['custom_css'])) {
                $settings['custom_css'] = trim($settings['custom_css']);
            }

            if (isset($settings['custom_js'])) {
                $settings['custom_js'] = trim($settings['custom_js']);
            }

            // Update each setting
            foreach ($settings as $key => $value) {
                // Skip empty file inputs
                if (strpos($key, 'site_logo') !== false || strpos($key, 'site_favicon') !== false) {
                    if (empty($value))
                        continue;
                }

                // Determine setting type
                $type = 'string';
                if (is_numeric($value) && !in_array($key, ['smtp_port', 'analytics_id'])) {
                    $type = 'number';
                } elseif ($value === 'true' || $value === 'false') {
                    $type = 'boolean';
                } elseif (is_array($value) || (is_string($value) && json_decode($value) !== null && json_last_error() === JSON_ERROR_NONE)) {
                    $type = 'json';
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                }

                // Insert or update setting
                $stmt = $pdo->prepare("
                    INSERT INTO settings (setting_key, setting_value, setting_type) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    setting_type = VALUES(setting_type)
                ");
                $stmt->execute([$key, $value, $type]);
            }

            // Clear any caches if needed
            if (function_exists('opcache_reset') && ini_get('opcache.enable')) {
                opcache_reset();
            }
            $success = 'تم حفظ الإعدادات بنجاح';
        } catch (Exception $e) {
            $error = 'حدث خطأ أثناء حفظ الإعدادات: ' . $e->getMessage();
        }
    }
}

// Get all current settings
$stmt = $pdo->query("SELECT * FROM settings");
$settingsData = [];
while ($row = $stmt->fetch()) {
    $value = $row['setting_value'];
    if ($row['setting_type'] === 'json') {
        $value = json_decode($value, true);
    } elseif ($row['setting_type'] === 'boolean') {
        $value = $value === 'true';
    } elseif ($row['setting_type'] === 'number') {
        $value = is_numeric($value) ? (strpos($value, '.') !== false ? (float) $value : (int) $value) : $value;
    }
    $settingsData[$row['setting_key']] = $value;
}

// Extended default values with more options
$defaults = [
    // General Settings
    'site_name' => 'منصة الاختبارات التفاعلية',
    'site_description' => 'منصة تعليمية تفاعلية لجميع المراحل الدراسية',
    'site_keywords' => 'اختبارات, تعليم, مدرسة, تفاعلي',
    'site_author' => 'اسم المؤسسة',
    'admin_email' => 'admin@example.com',
    'support_email' => 'support@example.com',
    'site_url' => 'https://www.iseraj.com/online',
    'site_logo' => '',
    'site_favicon' => '',
    'copyright_text' => '© 2024 جميع الحقوق محفوظة',
    'contact_phone' => '',
    'contact_address' => '',
    'social_facebook' => '',
    'social_twitter' => '',
    'social_instagram' => '',
    'social_youtube' => '',
    'social_linkedin' => '',
    'timezone' => 'Asia/Riyadh',
    'default_language' => 'ar',
    'multi_language' => false,
    'maintenance_mode' => false,
    'maintenance_message' => 'الموقع تحت الصيانة. سنعود قريباً!',
    'maintenance_end_date' => '',

    // User Settings
    'allow_registration' => true,
    'allow_teacher_registration' => false,
    'allow_guests' => true,
    'require_email_verification' => false,
    'auto_approve_teachers' => false,
    'default_user_role' => 'student',
    'min_password_length' => 6,
    'require_strong_password' => false,
    'max_login_attempts' => 5,
    'lockout_duration' => 30, // minutes
    'session_lifetime' => 120, // minutes
    'remember_me_duration' => 30, // days
    'allow_social_login' => false,
    'google_client_id' => '',
    'google_client_secret' => '',
    'facebook_app_id' => '',
    'facebook_app_secret' => '',

    // Quiz Settings
    'quiz_per_page' => 12,
    'results_per_page' => 20,
    'max_questions_per_quiz' => 100,
    'min_questions_per_quiz' => 1,
    'max_options_per_question' => 6,
    'min_options_per_question' => 2,
    'default_quiz_time' => 30,
    'max_quiz_time' => 180,
    'enable_quiz_codes' => true,
    'quiz_code_length' => 6,
    'quiz_code_expiry_days' => 0,
    'auto_submit_on_timeout' => true,
    'show_timer' => true,
    'allow_quiz_pause' => false,
    'randomize_questions' => true,
    'randomize_options' => true,
    'show_question_numbers' => true,
    'allow_question_navigation' => true,
    'show_progress_bar' => true,
    'enable_calculator' => false,
    'enable_formula_sheet' => false,
    'prevent_copy_paste' => false,
    'detect_tab_switch' => false,
    'fullscreen_mode' => false,
    'webcam_monitoring' => false,

    // Results Settings
    'show_results_immediately' => true,
    'show_correct_answers' => true,
    'show_explanations' => true,
    'allow_result_download' => true,
    'result_validity_days' => 0, // 0 = forever
    'certificate_enabled' => false,
    'certificate_template' => 'default',
    'passing_score' => 60,
    'excellence_score' => 90,
    'show_rank' => true,
    'show_percentile' => false,
    'detailed_analytics' => true,
    'parent_access' => false,

    // Gamification Settings
    'enable_gamification' => true,
    'points_per_correct_answer' => 10,
    'points_per_quiz_completion' => 50,
    'speed_bonus_enabled' => true,
    'speed_bonus_percentage' => 10,
    'streak_bonus_enabled' => true,
    'streak_bonus_points' => 20,
    'enable_achievements' => true,
    'enable_leaderboard' => true,
    'leaderboard_size' => 10,
    'leaderboard_type' => 'all', // all, grade, school
    'reset_leaderboard' => 'never', // never, weekly, monthly
    'enable_badges' => true,
    'enable_levels' => true,
    'points_for_level_up' => 1000,
    'enable_virtual_rewards' => false,
    'enable_real_rewards' => false,

    // Content Settings
    'enable_practice_mode' => true,
    'allow_quiz_retake' => true,
    'retake_delay_minutes' => 0,
    'max_retake_attempts' => 0, // 0 = unlimited
    'question_bank_enabled' => true,
    'ai_quiz_generation' => false,
    'openai_api_key' => '',
    'content_moderation' => true,
    'inappropriate_words' => '',
    'allow_user_submissions' => false,
    'require_approval' => true,
    'allow_comments' => false,
    'allow_ratings' => true,
    'enable_discussions' => false,

    // Email Settings
    'email_from_name' => 'منصة الاختبارات',
    'email_from_address' => 'noreply@example.com',
    'email_method' => 'mail', // mail, smtp, sendmail
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls', // none, ssl, tls
    'smtp_username' => '',
    'smtp_password' => '',
    'email_welcome' => true,
    'email_quiz_complete' => true,
    'email_achievement' => true,
    'email_weekly_report' => false,
    'email_parent_report' => false,

    // Security Settings
    'enable_captcha' => false,
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
    'enable_2fa' => false,
    'force_ssl' => false,
    'enable_csrf' => true,
    'enable_rate_limiting' => true,
    'rate_limit_requests' => 60,
    'rate_limit_window' => 60, // seconds
    'blocked_ips' => [],
    'allowed_ips' => [],
    'enable_audit_log' => true,
    'log_retention_days' => 90,
    'enable_encryption' => false,
    'encryption_key' => '',

    // Performance Settings
    'enable_cache' => true,
    'cache_driver' => 'file', // file, redis, memcached
    'cache_lifetime' => 60, // minutes
    'enable_cdn' => false,
    'cdn_url' => '',
    'optimize_images' => true,
    'lazy_load_images' => true,
    'minify_assets' => false,
    'enable_compression' => true,
    'database_optimization' => 'weekly',
    'enable_queue' => false,
    'queue_driver' => 'database',

    // Analytics Settings
    'enable_analytics' => false,
    'google_analytics_id' => '',
    'facebook_pixel_id' => '',
    'custom_tracking_code' => '',
    'track_quiz_events' => true,
    'track_user_behavior' => false,
    'anonymous_analytics' => true,
    'export_analytics' => true,

    // Integration Settings
    'enable_api' => false,
    'api_rate_limit' => 1000,
    'webhook_url' => '',
    'webhook_events' => [],
    'lms_integration' => 'none', // none, moodle, canvas, blackboard
    'lms_url' => '',
    'lms_token' => '',
    'google_classroom_enabled' => false,
    'microsoft_teams_enabled' => false,
    'zoom_integration' => false,
    'zoom_api_key' => '',
    'zoom_api_secret' => '',

    // Payment Settings (for premium features)
    'enable_payments' => false,
    'payment_gateway' => 'stripe', // stripe, paypal, local
    'stripe_public_key' => '',
    'stripe_secret_key' => '',
    'paypal_client_id' => '',
    'paypal_secret' => '',
    'currency' => 'SAR',
    'premium_monthly_price' => 50,
    'premium_yearly_price' => 500,
    'trial_days' => 7,

    // Appearance Settings
    'theme_mode' => 'light', // light, dark, auto
    'primary_color' => '#667eea',
    'secondary_color' => '#764ba2',
    'success_color' => '#48bb78',
    'danger_color' => '#f56565',
    'warning_color' => '#ed8936',
    'info_color' => '#4299e1',
    'font_family' => 'Tajawal',
    'font_size' => 'medium', // small, medium, large
    'border_radius' => 'medium', // none, small, medium, large
    'enable_animations' => true,
    'animation_speed' => 'normal', // slow, normal, fast
    'enable_sound_effects' => false,
    'enable_background_music' => false,
    'custom_css' => '',
    'custom_js' => '',
    'header_code' => '',
    'footer_code' => '',

    // File Upload Settings
    'max_upload_size' => 5, // MB
    'allowed_file_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf'],
    'image_quality' => 85, // 1-100
    'auto_resize_images' => true,
    'max_image_width' => 1920,
    'max_image_height' => 1080,
    'generate_thumbnails' => true,
    'thumbnail_width' => 300,
    'thumbnail_height' => 300,

    // Backup Settings
    'auto_backup' => false,
    'backup_frequency' => 'daily', // daily, weekly, monthly
    'backup_time' => '03:00',
    'backup_retention' => 30, // days
    'backup_location' => 'local', // local, s3, ftp
    's3_bucket' => '',
    's3_key' => '',
    's3_secret' => '',
    'ftp_host' => '',
    'ftp_username' => '',
    'ftp_password' => '',

    // Advanced Settings
    'debug_mode' => false,
    'show_errors' => false,
    'error_reporting_level' => 'E_ALL',
    'enable_dev_tools' => false,
    'api_documentation' => false,
    'system_status_page' => false,
    'enable_feature_flags' => false,
    'experimental_features' => false,
    'beta_features' => []
];

// Merge defaults with saved settings
foreach ($defaults as $key => $value) {
    if (!isset($settingsData[$key])) {
        $settingsData[$key] = $value;
    }
}

// Generate CSRF token
$csrfToken = generateCSRF();

// Get system information
$phpVersion = phpversion();
$mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
$diskSpace = disk_free_space("/") / 1024 / 1024 / 1024; // GB
$diskTotal = disk_total_space("/") / 1024 / 1024 / 1024; // GB
$diskUsed = $diskTotal - $diskSpace;
$diskPercentage = round(($diskUsed / $diskTotal) * 100);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الإعدادات - <?= e($settingsData['site_name']) ?></title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- DaisyUI -->
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Arabic Font -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Code Mirror for code editing -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }

        /* Tab styles */
        .tab-button {
            position: relative;
            transition: all 0.3s ease;
        }

        .tab-button::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #667eea;
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .tab-button.active::after {
            transform: scaleX(1);
        }

        /* Switch animation */
        .switch-input:checked~.switch-slider {
            background-color: #10b981;
        }

        .switch-input:checked~.switch-slider .switch-thumb {
            transform: translateX(-1.25rem);
        }

        /* Color picker styling */
        input[type="color"] {
            -webkit-appearance: none;
            appearance: none;
            border: none;
            width: 50px;
            height: 50px;
            cursor: pointer;
            border-radius: 8px;
        }

        input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: 8px;
        }

        /* CodeMirror RTL fix */
        .CodeMirror {
            direction: ltr;
            text-align: left;
            height: 200px;
        }

        /* Smooth transitions for sections */
        .settings-section {
            animation: fadeIn 0.3s ease-in-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Custom scrollbar */
        .settings-content::-webkit-scrollbar {
            width: 8px;
        }

        .settings-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .settings-content::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }

        .settings-content::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>

<body class="bg-gray-50" x-data="{ 
    activeTab: localStorage.getItem('settingsTab') || 'general',
    unsavedChanges: false,
    showPreview: false,
    sidebarOpen: window.innerWidth > 768,
    saveSettings() {
        this.unsavedChanges = false;
        document.getElementById('settingsForm').submit();
    }
}" x-init="$watch('activeTab', value => localStorage.setItem('settingsTab', value))">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'"
            class="bg-gray-800 text-white transition-all duration-300 ease-in-out">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" x-transition class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen"
                        class="text-gray-400 hover:text-white focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>

                <nav class="space-y-2">
                    <a href="./" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-tachometer-alt w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الرئيسية</span>
                    </a>
                    <a href="users.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-users w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المستخدمون</span>
                    </a>
                    <a href="quizzes.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-question-circle w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الاختبارات</span>
                    </a>
                    <a href="subjects.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-book w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">المواد الدراسية</span>
                    </a>
                    <a href="achievements.php"
                        class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-trophy w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإنجازات</span>
                    </a>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
                        <i class="fas fa-chart-bar w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">التقارير</span>
                    </a>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg bg-gray-700 text-white">
                        <i class="fas fa-cog w-6"></i>
                        <span x-show="sidebarOpen" x-transition class="mr-3">الإعدادات</span>
                    </a>
                </nav>
            </div>

            <div class="absolute bottom-0 w-full p-4 border-t border-gray-700">
                <a href="logout.php"
                    class="flex items-center p-2 rounded hover:bg-gray-700 transition-colors text-red-400">
                    <i class="fas fa-sign-out-alt w-6"></i>
                    <span x-show="sidebarOpen" x-transition class="mr-3">تسجيل الخروج</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-x-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b sticky top-0 z-40">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">الإعدادات</h1>
                            <p class="text-gray-600 mt-1">إدارة إعدادات المنصة</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <?php if ($settingsData['maintenance_mode']): ?>
                                <div class="badge badge-warning gap-2">
                                    <i class="fas fa-tools"></i>
                                    وضع الصيانة مفعل
                                </div>
                            <?php endif; ?>
                            <button @click="showPreview = !showPreview" class="btn btn-sm btn-ghost" title="معاينة">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="../" target="_blank" class="btn btn-sm btn-primary">
                                <i class="fas fa-external-link-alt ml-2"></i>
                                عرض الموقع
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Settings Content -->
            <div class="flex">
                <!-- Settings Form -->
                <div class="flex-1 p-6" :class="showPreview ? 'w-2/3' : 'w-full'">
                    <!-- Success/Error Messages -->
                    <?php if ($success): ?>
                        <div class="alert alert-success mb-6">
                            <i class="fas fa-check-circle"></i>
                            <span><?= e($success) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error mb-6">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= e($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Save Button (Floating) -->
                    <div x-show="unsavedChanges" class="fixed bottom-6 left-6 z-50">
                        <button @click="saveSettings()" class="btn btn-primary shadow-lg">
                            <i class="fas fa-save ml-2"></i>
                            حفظ التغييرات
                        </button>
                    </div>

                    <!-- Settings Form -->
                    <form method="POST" enctype="multipart/form-data" @change="unsavedChanges = true" id="settingsForm">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                        <!-- Tab Navigation -->
                        <div class="bg-white rounded-lg shadow-sm mb-6">
                            <div class="border-b overflow-x-auto">
                                <nav class="flex">
                                    <button type="button" @click="activeTab = 'general'"
                                        :class="activeTab === 'general' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-cog ml-2"></i>
                                        عام
                                    </button>
                                    <button type="button" @click="activeTab = 'users'"
                                        :class="activeTab === 'users' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-users ml-2"></i>
                                        المستخدمون
                                    </button>
                                    <button type="button" @click="activeTab = 'quizzes'"
                                        :class="activeTab === 'quizzes' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-question-circle ml-2"></i>
                                        الاختبارات
                                    </button>
                                    <button type="button" @click="activeTab = 'results'"
                                        :class="activeTab === 'results' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-chart-line ml-2"></i>
                                        النتائج
                                    </button>
                                    <button type="button" @click="activeTab = 'gamification'"
                                        :class="activeTab === 'gamification' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-trophy ml-2"></i>
                                        التلعيب
                                    </button>
                                    <button type="button" @click="activeTab = 'email'"
                                        :class="activeTab === 'email' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-envelope ml-2"></i>
                                        البريد
                                    </button>
                                    <button type="button" @click="activeTab = 'security'"
                                        :class="activeTab === 'security' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-shield-alt ml-2"></i>
                                        الأمان
                                    </button>
                                    <button type="button" @click="activeTab = 'appearance'"
                                        :class="activeTab === 'appearance' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-palette ml-2"></i>
                                        المظهر
                                    </button>
                                    <button type="button" @click="activeTab = 'advanced'"
                                        :class="activeTab === 'advanced' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                        <i class="fas fa-tools ml-2"></i>
                                        متقدم
                                    </button>
                                </nav>
                            </div>
                        </div>

                        <!-- Tab Content -->
                        <div
                            class="bg-white rounded-lg shadow-sm p-6 settings-content max-h-[calc(100vh-200px)] overflow-y-auto">
                            <!-- General Settings -->
                            <div x-show="activeTab === 'general'" class="settings-section">
                                <h2 class="text-xl font-bold mb-6">الإعدادات العامة</h2>

                                <div class="space-y-6">
                                    <!-- Site Information -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-info-circle ml-2"></i>
                                            معلومات الموقع
                                        </h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">اسم الموقع</span>
                                                </label>
                                                <input type="text" name="site_name"
                                                    value="<?= e($settingsData['site_name']) ?>"
                                                    class="input input-bordered w-full" required>
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">رابط الموقع</span>
                                                </label>
                                                <input type="url" name="site_url"
                                                    value="<?= e($settingsData['site_url']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="label">
                                                    <span class="label-text">وصف الموقع</span>
                                                </label>
                                                <textarea name="site_description" rows="2"
                                                    class="textarea textarea-bordered w-full"><?= e($settingsData['site_description']) ?></textarea>
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الكلمات المفتاحية (SEO)</span>
                                                </label>
                                                <input type="text" name="site_keywords"
                                                    value="<?= e($settingsData['site_keywords']) ?>"
                                                    class="input input-bordered w-full" placeholder="مفصولة بفواصل">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">المؤلف/المؤسسة</span>
                                                </label>
                                                <input type="text" name="site_author"
                                                    value="<?= e($settingsData['site_author']) ?>"
                                                    class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Logo & Branding -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-image ml-2"></i>
                                            الشعار والهوية البصرية
                                        </h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">شعار الموقع</span>
                                                </label>
                                                <input type="file" name="site_logo" accept="image/*"
                                                    class="file-input file-input-bordered w-full">
                                                <?php if (!empty($settingsData['site_logo'])): ?>
                                                    <img src="../<?= e($settingsData['site_logo']) ?>" alt="Logo"
                                                        class="mt-2 h-20">
                                                <?php endif; ?>
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">أيقونة الموقع (Favicon)</span>
                                                </label>
                                                <input type="file" name="site_favicon" accept="image/*"
                                                    class="file-input file-input-bordered w-full">
                                                <?php if (!empty($settingsData['site_favicon'])): ?>
                                                    <img src="../<?= e($settingsData['site_favicon']) ?>" alt="Favicon"
                                                        class="mt-2 h-10">
                                                <?php endif; ?>
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="label">
                                                    <span class="label-text">نص حقوق النشر</span>
                                                </label>
                                                <input type="text" name="copyright_text"
                                                    value="<?= e($settingsData['copyright_text']) ?>"
                                                    class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Contact Information -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-address-card ml-2"></i>
                                            معلومات الاتصال
                                        </h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">البريد الإلكتروني للإدارة</span>
                                                </label>
                                                <input type="email" name="admin_email"
                                                    value="<?= e($settingsData['admin_email']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">بريد الدعم الفني</span>
                                                </label>
                                                <input type="email" name="support_email"
                                                    value="<?= e($settingsData['support_email']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">رقم الهاتف</span>
                                                </label>
                                                <input type="tel" name="contact_phone"
                                                    value="<?= e($settingsData['contact_phone']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">العنوان</span>
                                                </label>
                                                <input type="text" name="contact_address"
                                                    value="<?= e($settingsData['contact_address']) ?>"
                                                    class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Social Media -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-share-alt ml-2"></i>
                                            وسائل التواصل الاجتماعي
                                        </h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text"><i class="fab fa-facebook ml-1"></i>
                                                        Facebook</span>
                                                </label>
                                                <input type="url" name="social_facebook"
                                                    value="<?= e($settingsData['social_facebook']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text"><i class="fab fa-twitter ml-1"></i>
                                                        Twitter</span>
                                                </label>
                                                <input type="url" name="social_twitter"
                                                    value="<?= e($settingsData['social_twitter']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text"><i class="fab fa-instagram ml-1"></i>
                                                        Instagram</span>
                                                </label>
                                                <input type="url" name="social_instagram"
                                                    value="<?= e($settingsData['social_instagram']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text"><i class="fab fa-youtube ml-1"></i>
                                                        YouTube</span>
                                                </label>
                                                <input type="url" name="social_youtube"
                                                    value="<?= e($settingsData['social_youtube']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text"><i class="fab fa-linkedin ml-1"></i>
                                                        LinkedIn</span>
                                                </label>
                                                <input type="url" name="social_linkedin"
                                                    value="<?= e($settingsData['social_linkedin']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- System Settings -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-server ml-2"></i>
                                            إعدادات النظام
                                        </h3>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">المنطقة الزمنية</span>
                                                </label>
                                                <select name="timezone" class="select select-bordered w-full">
                                                    <option value="Asia/Riyadh"
                                                        <?= $settingsData['timezone'] === 'Asia/Riyadh' ? 'selected' : '' ?>>الرياض (GMT+3)</option>
                                                    <option value="Asia/Dubai"
                                                        <?= $settingsData['timezone'] === 'Asia/Dubai' ? 'selected' : '' ?>>دبي (GMT+4)</option>
                                                    <option value="Africa/Cairo"
                                                        <?= $settingsData['timezone'] === 'Africa/Cairo' ? 'selected' : '' ?>>القاهرة (GMT+2)</option>
                                                    <option value="Europe/Istanbul"
                                                        <?= $settingsData['timezone'] === 'Europe/Istanbul' ? 'selected' : '' ?>>إسطنبول (GMT+3)</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">اللغة الافتراضية</span>
                                                </label>
                                                <select name="default_language" class="select select-bordered w-full">
                                                    <option value="ar" <?= $settingsData['default_language'] === 'ar' ? 'selected' : '' ?>>العربية</option>
                                                    <option value="en" <?= $settingsData['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="mt-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">تعدد اللغات</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح للمستخدمين بتغيير لغة
                                                        الواجهة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="multi_language" value="false">
                                                    <input type="checkbox" name="multi_language" value="true"
                                                        <?= $settingsData['multi_language'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Maintenance Mode -->
                                    <div class="border border-orange-200 rounded-lg p-4 bg-orange-50">
                                        <h3 class="font-bold mb-4 text-orange-800">
                                            <i class="fas fa-tools ml-2"></i>
                                            وضع الصيانة
                                        </h3>

                                        <label
                                            class="flex items-center justify-between p-4 bg-white rounded-lg cursor-pointer mb-4">
                                            <div>
                                                <span class="font-medium">تفعيل وضع الصيانة</span>
                                                <p class="text-sm text-gray-600 mt-1">إيقاف الموقع مؤقتاً للصيانة</p>
                                            </div>
                                            <div class="relative">
                                                <input type="hidden" name="maintenance_mode" value="false">
                                                <input type="checkbox" name="maintenance_mode" value="true"
                                                    <?= $settingsData['maintenance_mode'] ? 'checked' : '' ?>
                                                    class="switch-input sr-only">
                                                <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                    onclick="this.previousElementSibling.click()">
                                                    <div
                                                        class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                    </div>
                                                </div>
                                            </div>
                                        </label>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div class="md:col-span-2">
                                                <label class="label">
                                                    <span class="label-text">رسالة الصيانة</span>
                                                </label>
                                                <textarea name="maintenance_message" rows="2"
                                                    class="textarea textarea-bordered w-full"><?= e($settingsData['maintenance_message']) ?></textarea>
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">تاريخ انتهاء الصيانة المتوقع</span>
                                                </label>
                                                <input type="datetime-local" name="maintenance_end_date"
                                                    value="<?= e($settingsData['maintenance_end_date']) ?>"
                                                    class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- User Settings -->
                            <div x-show="activeTab === 'users'" class="settings-section">
                                <h2 class="text-xl font-bold mb-6">إعدادات المستخدمين</h2>

                                <div class="space-y-6">
                                    <!-- Registration Settings -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-user-plus ml-2"></i>
                                            التسجيل
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بالتسجيل</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح للمستخدمين الجدد بإنشاء
                                                        حسابات</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_registration" value="false">
                                                    <input type="checkbox" name="allow_registration" value="true"
                                                        <?= $settingsData['allow_registration'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بتسجيل المعلمين</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح للمعلمين بالتسجيل
                                                        مباشرة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_teacher_registration"
                                                        value="false">
                                                    <input type="checkbox" name="allow_teacher_registration"
                                                        value="true" <?= $settingsData['allow_teacher_registration'] ? 'checked' : '' ?> class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بالدخول كضيف</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح بحل الاختبارات بدون
                                                        تسجيل</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_guests" value="false">
                                                    <input type="checkbox" name="allow_guests" value="true"
                                                        <?= $settingsData['allow_guests'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">التحقق من البريد الإلكتروني</span>
                                                    <p class="text-sm text-gray-600 mt-1">مطالبة المستخدمين بتأكيد
                                                        بريدهم الإلكتروني</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="require_email_verification"
                                                        value="false">
                                                    <input type="checkbox" name="require_email_verification"
                                                        value="true" <?= $settingsData['require_email_verification'] ? 'checked' : '' ?> class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">الموافقة التلقائية على المعلمين</span>
                                                    <p class="text-sm text-gray-600 mt-1">الموافقة تلقائياً على طلبات
                                                        تسجيل المعلمين</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="auto_approve_teachers" value="false">
                                                    <input type="checkbox" name="auto_approve_teachers" value="true"
                                                        <?= $settingsData['auto_approve_teachers'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الصلاحية الافتراضية للمستخدمين الجدد</span>
                                                </label>
                                                <select name="default_user_role" class="select select-bordered w-full">
                                                    <option value="student"
                                                        <?= $settingsData['default_user_role'] === 'student' ? 'selected' : '' ?>>طالب</option>
                                                    <option value="teacher"
                                                        <?= $settingsData['default_user_role'] === 'teacher' ? 'selected' : '' ?>>معلم</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Password Settings -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-key ml-2"></i>
                                            كلمات المرور
                                        </h3>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأدنى لطول كلمة المرور</span>
                                                </label>
                                                <input type="number" name="min_password_length"
                                                    value="<?= $settingsData['min_password_length'] ?>" min="4" max="32"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div class="flex items-end">
                                                <label
                                                    class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer w-full">
                                                    <div>
                                                        <span class="font-medium">كلمة مرور قوية</span>
                                                        <p class="text-sm text-gray-600 mt-1">طلب أحرف كبيرة وصغيرة
                                                            وأرقام ورموز</p>
                                                    </div>
                                                    <div class="relative">
                                                        <input type="hidden" name="require_strong_password"
                                                            value="false">
                                                        <input type="checkbox" name="require_strong_password"
                                                            value="true" <?= $settingsData['require_strong_password'] ? 'checked' : '' ?> class="switch-input sr-only">
                                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                            onclick="this.previousElementSibling.click()">
                                                            <div
                                                                class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Login Settings -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-sign-in-alt ml-2"></i>
                                            تسجيل الدخول
                                        </h3>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأقصى لمحاولات تسجيل الدخول</span>
                                                </label>
                                                <input type="number" name="max_login_attempts"
                                                    value="<?= $settingsData['max_login_attempts'] ?>" min="3" max="10"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مدة الحظر (دقائق)</span>
                                                </label>
                                                <input type="number" name="lockout_duration"
                                                    value="<?= $settingsData['lockout_duration'] ?>" min="5" max="1440"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مدة الجلسة (دقائق)</span>
                                                </label>
                                                <input type="number" name="session_lifetime"
                                                    value="<?= $settingsData['session_lifetime'] ?>" min="15" max="1440"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مدة "تذكرني" (أيام)</span>
                                                </label>
                                                <input type="number" name="remember_me_duration"
                                                    value="<?= $settingsData['remember_me_duration'] ?>" min="1"
                                                    max="365" class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Social Login -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-share-square ml-2"></i>
                                            تسجيل الدخول بالشبكات الاجتماعية
                                        </h3>

                                        <label
                                            class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer mb-4">
                                            <div>
                                                <span class="font-medium">تفعيل تسجيل الدخول بالشبكات الاجتماعية</span>
                                                <p class="text-sm text-gray-600 mt-1">السماح بتسجيل الدخول عبر Google و
                                                    Facebook</p>
                                            </div>
                                            <div class="relative">
                                                <input type="hidden" name="allow_social_login" value="false">
                                                <input type="checkbox" name="allow_social_login" value="true"
                                                    <?= $settingsData['allow_social_login'] ? 'checked' : '' ?>
                                                    class="switch-input sr-only">
                                                <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                    onclick="this.previousElementSibling.click()">
                                                    <div
                                                        class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                    </div>
                                                </div>
                                            </div>
                                        </label>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">Google Client ID</span>
                                                </label>
                                                <input type="text" name="google_client_id"
                                                    value="<?= e($settingsData['google_client_id']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">Google Client Secret</span>
                                                </label>
                                                <input type="password" name="google_client_secret"
                                                    value="<?= e($settingsData['google_client_secret']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">Facebook App ID</span>
                                                </label>
                                                <input type="text" name="facebook_app_id"
                                                    value="<?= e($settingsData['facebook_app_id']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">Facebook App Secret</span>
                                                </label>
                                                <input type="password" name="facebook_app_secret"
                                                    value="<?= e($settingsData['facebook_app_secret']) ?>"
                                                    class="input input-bordered w-full" dir="ltr">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quiz Settings -->
                            <div x-show="activeTab === 'quizzes'" class="settings-section">
                                <h2 class="text-xl font-bold mb-6">إعدادات الاختبارات</h2>

                                <div class="space-y-6">
                                    <!-- General Quiz Settings -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-clipboard-list ml-2"></i>
                                            إعدادات عامة
                                        </h3>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">عدد الاختبارات في الصفحة</span>
                                                </label>
                                                <input type="number" name="quiz_per_page"
                                                    value="<?= $settingsData['quiz_per_page'] ?>" min="6" max="50"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">عدد النتائج في الصفحة</span>
                                                </label>
                                                <input type="number" name="results_per_page"
                                                    value="<?= $settingsData['results_per_page'] ?>" min="10" max="100"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأقصى للأسئلة في الاختبار</span>
                                                </label>
                                                <input type="number" name="max_questions_per_quiz"
                                                    value="<?= $settingsData['max_questions_per_quiz'] ?>" min="10"
                                                    max="200" class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأدنى للأسئلة في الاختبار</span>
                                                </label>
                                                <input type="number" name="min_questions_per_quiz"
                                                    value="<?= $settingsData['min_questions_per_quiz'] ?>" min="1"
                                                    max="50" class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الوقت الافتراضي للاختبار (دقائق)</span>
                                                </label>
                                                <input type="number" name="default_quiz_time"
                                                    value="<?= $settingsData['default_quiz_time'] ?>" min="0" max="180"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأقصى للوقت (دقائق)</span>
                                                </label>
                                                <input type="number" name="max_quiz_time"
                                                    value="<?= $settingsData['max_quiz_time'] ?>" min="30" max="360"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأدنى للخيارات</span>
                                                </label>
                                                <input type="number" name="min_options_per_question"
                                                    value="<?= $settingsData['min_options_per_question'] ?>" min="2"
                                                    max="4" class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">الحد الأقصى للخيارات</span>
                                                </label>
                                                <input type="number" name="max_options_per_question"
                                                    value="<?= $settingsData['max_options_per_question'] ?>" min="4"
                                                    max="10" class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Quiz Access -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-key ml-2"></i>
                                            رموز الوصول
                                        </h3>

                                        <label
                                            class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer mb-4">
                                            <div>
                                                <span class="font-medium">تفعيل رموز الوصول</span>
                                                <p class="text-sm text-gray-600 mt-1">السماح بالوصول للاختبارات عبر رمز
                                                    PIN</p>
                                            </div>
                                            <div class="relative">
                                                <input type="hidden" name="enable_quiz_codes" value="false">
                                                <input type="checkbox" name="enable_quiz_codes" value="true"
                                                    <?= $settingsData['enable_quiz_codes'] ? 'checked' : '' ?>
                                                    class="switch-input sr-only">
                                                <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                    onclick="this.previousElementSibling.click()">
                                                    <div
                                                        class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                    </div>
                                                </div>
                                            </div>
                                        </label>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">طول رمز الوصول</span>
                                                </label>
                                                <input type="number" name="quiz_code_length"
                                                    value="<?= $settingsData['quiz_code_length'] ?>" min="4" max="10"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مدة صلاحية الرمز (أيام)</span>
                                                    <span class="label-text-alt">0 = دائم</span>
                                                </label>
                                                <input type="number" name="quiz_code_expiry_days"
                                                    value="<?= $settingsData['quiz_code_expiry_days'] ?>" min="0"
                                                    max="365" class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Quiz Taking Options -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-play-circle ml-2"></i>
                                            خيارات أداء الاختبار
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">الإرسال التلقائي عند انتهاء الوقت</span>
                                                    <p class="text-sm text-gray-600 mt-1">إرسال الإجابات تلقائياً عند
                                                        انتهاء الوقت</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="auto_submit_on_timeout" value="false">
                                                    <input type="checkbox" name="auto_submit_on_timeout" value="true"
                                                        <?= $settingsData['auto_submit_on_timeout'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض المؤقت</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض الوقت المتبقي أثناء
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_timer" value="false">
                                                    <input type="checkbox" name="show_timer" value="true"
                                                        <?= $settingsData['show_timer'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بإيقاف الاختبار مؤقتاً</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح للطلاب بإيقاف الاختبار
                                                        والعودة لاحقاً</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_quiz_pause" value="false">
                                                    <input type="checkbox" name="allow_quiz_pause" value="true"
                                                        <?= $settingsData['allow_quiz_pause'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">خلط الأسئلة</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض الأسئلة بترتيب عشوائي لكل
                                                        طالب</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="randomize_questions" value="false">
                                                    <input type="checkbox" name="randomize_questions" value="true"
                                                        <?= $settingsData['randomize_questions'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">خلط الخيارات</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض خيارات الإجابة بترتيب
                                                        عشوائي</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="randomize_options" value="false">
                                                    <input type="checkbox" name="randomize_options" value="true"
                                                        <?= $settingsData['randomize_options'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض أرقام الأسئلة</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض رقم السؤال الحالي من
                                                        إجمالي الأسئلة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_question_numbers" value="false">
                                                    <input type="checkbox" name="show_question_numbers" value="true"
                                                        <?= $settingsData['show_question_numbers'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بالتنقل بين الأسئلة</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح بالعودة للأسئلة السابقة
                                                    </p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_question_navigation" value="false">
                                                    <input type="checkbox" name="allow_question_navigation" value="true"
                                                        <?= $settingsData['allow_question_navigation'] ? 'checked' : '' ?> class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">شريط التقدم</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض شريط يوضح التقدم في
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_progress_bar" value="false">
                                                    <input type="checkbox" name="show_progress_bar" value="true"
                                                        <?= $settingsData['show_progress_bar'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Quiz Tools -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-tools ml-2"></i>
                                            أدوات مساعدة
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">تفعيل الآلة الحاسبة</span>
                                                    <p class="text-sm text-gray-600 mt-1">إتاحة آلة حاسبة للطلاب أثناء
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="enable_calculator" value="false">
                                                    <input type="checkbox" name="enable_calculator" value="true"
                                                        <?= $settingsData['enable_calculator'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">ورقة القوانين</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض ورقة بالقوانين والمعادلات
                                                        المهمة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="enable_formula_sheet" value="false">
                                                    <input type="checkbox" name="enable_formula_sheet" value="true"
                                                        <?= $settingsData['enable_formula_sheet'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Anti-Cheating -->
                                    <div class="border border-red-200 rounded-lg p-4 bg-red-50">
                                        <h3 class="font-bold mb-4 text-red-800">
                                            <i class="fas fa-user-shield ml-2"></i>
                                            منع الغش
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-white rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">منع النسخ واللصق</span>
                                                    <p class="text-sm text-gray-600 mt-1">تعطيل النسخ واللصق والنقر
                                                        بالزر الأيمن</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="prevent_copy_paste" value="false">
                                                    <input type="checkbox" name="prevent_copy_paste" value="true"
                                                        <?= $settingsData['prevent_copy_paste'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-white rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">كشف تبديل النوافذ</span>
                                                    <p class="text-sm text-gray-600 mt-1">تسجيل عدد مرات مغادرة نافذة
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="detect_tab_switch" value="false">
                                                    <input type="checkbox" name="detect_tab_switch" value="true"
                                                        <?= $settingsData['detect_tab_switch'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-white rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">وضع ملء الشاشة</span>
                                                    <p class="text-sm text-gray-600 mt-1">إجبار الطلاب على استخدام وضع
                                                        ملء الشاشة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="fullscreen_mode" value="false">
                                                    <input type="checkbox" name="fullscreen_mode" value="true"
                                                        <?= $settingsData['fullscreen_mode'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-white rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">مراقبة الكاميرا</span>
                                                    <p class="text-sm text-gray-600 mt-1">تفعيل المراقبة عبر كاميرا
                                                        الويب</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="webcam_monitoring" value="false">
                                                    <input type="checkbox" name="webcam_monitoring" value="true"
                                                        <?= $settingsData['webcam_monitoring'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Practice & Retakes -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-redo ml-2"></i>
                                            التدريب وإعادة المحاولة
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">وضع التدريب</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح بوضع التدريب مع عرض
                                                        الإجابات فوراً</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="enable_practice_mode" value="false">
                                                    <input type="checkbox" name="enable_practice_mode" value="true"
                                                        <?= $settingsData['enable_practice_mode'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بإعادة الاختبار</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح للطلاب بإعادة نفس
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_quiz_retake" value="false">
                                                    <input type="checkbox" name="allow_quiz_retake" value="true"
                                                        <?= $settingsData['allow_quiz_retake'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <label class="label">
                                                        <span class="label-text">فترة الانتظار بين المحاولات
                                                            (دقائق)</span>
                                                    </label>
                                                    <input type="number" name="retake_delay_minutes"
                                                        value="<?= $settingsData['retake_delay_minutes'] ?>" min="0"
                                                        max="1440" class="input input-bordered w-full">
                                                </div>

                                                <div>
                                                    <label class="label">
                                                        <span class="label-text">الحد الأقصى لعدد المحاولات</span>
                                                        <span class="label-text-alt">0 = غير محدود</span>
                                                    </label>
                                                    <input type="number" name="max_retake_attempts"
                                                        value="<?= $settingsData['max_retake_attempts'] ?>" min="0"
                                                        max="10" class="input input-bordered w-full">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Content Management -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-database ml-2"></i>
                                            إدارة المحتوى
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">بنك الأسئلة</span>
                                                    <p class="text-sm text-gray-600 mt-1">تفعيل نظام بنك الأسئلة لإعادة
                                                        استخدام الأسئلة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="question_bank_enabled" value="false">
                                                    <input type="checkbox" name="question_bank_enabled" value="true"
                                                        <?= $settingsData['question_bank_enabled'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">توليد الأسئلة بالذكاء الاصطناعي</span>
                                                    <p class="text-sm text-gray-600 mt-1">استخدام AI لتوليد أسئلة
                                                        تلقائياً</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="ai_quiz_generation" value="false">
                                                    <input type="checkbox" name="ai_quiz_generation" value="true"
                                                        <?= $settingsData['ai_quiz_generation'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مفتاح OpenAI API</span>
                                                </label>
                                                <input type="password" name="openai_api_key"
                                                    value="<?= e($settingsData['openai_api_key']) ?>"
                                                    class="input input-bordered w-full" dir="ltr" placeholder="sk-...">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Results Settings -->
                            <div x-show="activeTab === 'results'" class="settings-section">
                                <h2 class="text-xl font-bold mb-6">إعدادات النتائج</h2>

                                <div class="space-y-6">
                                    <!-- Display Options -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-chart-pie ml-2"></i>
                                            خيارات العرض
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض النتائج فوراً</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض النتيجة بمجرد انتهاء
                                                        الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_results_immediately" value="false">
                                                    <input type="checkbox" name="show_results_immediately" value="true"
                                                        <?= $settingsData['show_results_immediately'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض الإجابات الصحيحة</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض الإجابات الصحيحة بعد
                                                        انتهاء الاختبار</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_correct_answers" value="false">
                                                    <input type="checkbox" name="show_correct_answers" value="true"
                                                        <?= $settingsData['show_correct_answers'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض الشروحات</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض شرح للإجابات إن وجد</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_explanations" value="false">
                                                    <input type="checkbox" name="show_explanations" value="true"
                                                        <?= $settingsData['show_explanations'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">السماح بتحميل النتائج</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح بتحميل تقرير PDF
                                                        للنتيجة</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="allow_result_download" value="false">
                                                    <input type="checkbox" name="allow_result_download" value="true"
                                                        <?= $settingsData['allow_result_download'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">مدة صلاحية النتائج (أيام)</span>
                                                    <span class="label-text-alt">0 = دائمة</span>
                                                </label>
                                                <input type="number" name="result_validity_days"
                                                    value="<?= $settingsData['result_validity_days'] ?>" min="0"
                                                    max="365" class="input input-bordered w-full">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Grading -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-percentage ml-2"></i>
                                            التقييم والدرجات
                                        </h3>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="label">
                                                    <span class="label-text">درجة النجاح (%)</span>
                                                </label>
                                                <input type="number" name="passing_score"
                                                    value="<?= $settingsData['passing_score'] ?>" min="40" max="90"
                                                    class="input input-bordered w-full">
                                            </div>

                                            <div>
                                                <label class="label">
                                                    <span class="label-text">درجة التميز (%)</span>
                                                </label>
                                                <input type="number" name="excellence_score"
                                                    value="<?= $settingsData['excellence_score'] ?>" min="70" max="100"
                                                    class="input input-bordered w-full">
                                            </div>
                                        </div>

                                        <div class="mt-4 space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض الترتيب</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض ترتيب الطالب بين زملائه
                                                    </p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_rank" value="false">
                                                    <input type="checkbox" name="show_rank" value="true"
                                                        <?= $settingsData['show_rank'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">عرض النسبة المئوية</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض نسبة الطلاب الذين حصلوا
                                                        على درجة أقل</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="show_percentile" value="false">
                                                    <input type="checkbox" name="show_percentile" value="true"
                                                        <?= $settingsData['show_percentile'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Certificates -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-certificate ml-2"></i>
                                            الشهادات
                                        </h3>

                                        <label
                                            class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer mb-4">
                                            <div>
                                                <span class="font-medium">تفعيل الشهادات</span>
                                                <p class="text-sm text-gray-600 mt-1">منح شهادات للطلاب الناجحين</p>
                                            </div>
                                            <div class="relative">
                                                <input type="hidden" name="certificate_enabled" value="false">
                                                <input type="checkbox" name="certificate_enabled" value="true"
                                                    <?= $settingsData['certificate_enabled'] ? 'checked' : '' ?>
                                                    class="switch-input sr-only">
                                                <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                    onclick="this.previousElementSibling.click()">
                                                    <div
                                                        class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                    </div>
                                                </div>
                                            </div>
                                        </label>

                                        <div>
                                            <label class="label">
                                                <span class="label-text">قالب الشهادة</span>
                                            </label>
                                            <select name="certificate_template" class="select select-bordered w-full">
                                                <option value="default"
                                                    <?= $settingsData['certificate_template'] === 'default' ? 'selected' : '' ?>>افتراضي</option>
                                                <option value="modern"
                                                    <?= $settingsData['certificate_template'] === 'modern' ? 'selected' : '' ?>>عصري</option>
                                                <option value="classic"
                                                    <?= $settingsData['certificate_template'] === 'classic' ? 'selected' : '' ?>>كلاسيكي</option>
                                                <option value="simple"
                                                    <?= $settingsData['certificate_template'] === 'simple' ? 'selected' : '' ?>>بسيط</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Analytics -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray-700">
                                            <i class="fas fa-chart-bar ml-2"></i>
                                            التحليلات والتقارير
                                        </h3>

                                        <div class="space-y-4">
                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">التحليلات التفصيلية</span>
                                                    <p class="text-sm text-gray-600 mt-1">عرض إحصائيات مفصلة لكل سؤال
                                                    </p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="detailed_analytics" value="false">
                                                    <input type="checkbox" name="detailed_analytics" value="true"
                                                        <?= $settingsData['detailed_analytics'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>

                                            <label
                                                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg cursor-pointer">
                                                <div>
                                                    <span class="font-medium">وصول أولياء الأمور</span>
                                                    <p class="text-sm text-gray-600 mt-1">السماح لأولياء الأمور بمتابعة
                                                        نتائج أبنائهم</p>
                                                </div>
                                                <div class="relative">
                                                    <input type="hidden" name="parent_access" value="false">
                                                    <input type="checkbox" name="parent_access" value="true"
                                                        <?= $settingsData['parent_access'] ? 'checked' : '' ?>
                                                        class="switch-input sr-only">
                                                    <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                        onclick="this.previousElementSibling.click()">
                                                        <div
                                                            class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                        </div>
                                                    </div>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Gamification Settings -->
                            <div x-show="activeTab === 'gamification'" class="settings-section">
                                <h2 class="text-xl font-bold mb-6">إعدادات التلعيب</h2>

                                <div class="space-y-6">
                                    <!-- Enable/Disable -->
                                    <div class="border rounded-lg p-4">
                                        <label
                                            class="flex items-center justify-between p-4 bg-purple-50 rounded-lg cursor-pointer">
                                            <div>
                                                <span class="font-medium text-lg">تفعيل نظام التلعيب</span>
                                                <p class="text-sm text-gray-600 mt-1">تفعيل النقاط والإنجازات والمكافآت
                                                </p>
                                            </div>
                                            <div class="relative">
                                                <input type="hidden" name="enable_gamification" value="false">
                                                <input type="checkbox" name="enable_gamification" value="true"
                                                    <?= $settingsData['enable_gamification'] ? 'checked' : '' ?>
                                                    class="switch-input sr-only">
                                                <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                    onclick="this.previousElementSibling.click()">
                                                    <div
                                                        class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform">
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>

                                    <!-- Points System -->
                                    <div class="border rounded-lg p-4">
                                        <h3 class="font-bold mb-4 text-gray