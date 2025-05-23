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
            // Get all POST data except CSRF token
            $settings = $_POST;
            unset($settings['csrf_token']);
            
            // Update each setting
            foreach ($settings as $key => $value) {
                // Determine setting type
                $type = 'string';
                if (is_numeric($value)) {
                    $type = 'number';
                } elseif ($value === 'true' || $value === 'false') {
                    $type = 'boolean';
                } elseif (is_array($value) || (is_string($value) && json_decode($value) !== null)) {
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
        $value = (int)$value;
    }
    $settingsData[$row['setting_key']] = $value;
}

// Default values
$defaults = [
    'site_name' => 'منصة الاختبارات التفاعلية',
    'site_description' => 'منصة تعليمية تفاعلية لجميع المراحل الدراسية',
    'admin_email' => 'admin@example.com',
    'allow_registration' => true,
    'allow_guests' => true,
    'require_email_verification' => false,
    'default_language' => 'ar',
    'maintenance_mode' => false,
    'maintenance_message' => 'الموقع تحت الصيانة. سنعود قريباً!',
    'quiz_per_page' => 12,
    'results_per_page' => 20,
    'max_options_per_question' => 6,
    'min_options_per_question' => 2,
    'default_quiz_time' => 30,
    'enable_quiz_codes' => true,
    'quiz_code_length' => 6,
    'quiz_code_expiry_days' => 0,
    'points_per_correct_answer' => 10,
    'speed_bonus_enabled' => true,
    'speed_bonus_percentage' => 10,
    'passing_score' => 60,
    'excellence_score' => 90,
    'enable_achievements' => true,
    'enable_leaderboard' => true,
    'leaderboard_size' => 10,
    'enable_practice_mode' => true,
    'show_correct_answers' => true,
    'allow_quiz_retake' => true,
    'retake_delay_minutes' => 0,
    'grades' => [
        'الصف الأول', 'الصف الثاني', 'الصف الثالث', 
        'الصف الرابع', 'الصف الخامس', 'الصف السادس',
        'الصف السابع', 'الصف الثامن', 'الصف التاسع',
        'الصف العاشر', 'الصف الحادي عشر', 'الصف الثاني عشر'
    ],
    'grade_colors' => [
        '1-6' => 'green',
        '7-9' => 'yellow', 
        '10-12' => 'blue'
    ],
    'theme_primary_color' => '#667eea',
    'theme_secondary_color' => '#764ba2',
    'enable_animations' => true,
    'enable_sound_effects' => false,
    'date_format' => 'd/m/Y',
    'time_format' => 'H:i'
];

// Merge defaults with saved settings
foreach ($defaults as $key => $value) {
    if (!isset($settingsData[$key])) {
        $settingsData[$key] = $value;
    }
}

// Generate CSRF token
$csrfToken = generateCSRF();
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
        .switch-input:checked ~ .switch-slider {
            background-color: #10b981;
        }
        
        .switch-input:checked ~ .switch-slider .switch-thumb {
            transform: translateX(-1.25rem);
        }
        
        /* Color picker styling */
        input[type="color"] {
        appearance: none;              /* ADD THIS LINE */
        -webkit-appearance: none;      /* This line already exists */
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
    </style>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex" x-data="{ 
        sidebarOpen: window.innerWidth > 768,
        activeTab: 'general',
        unsavedChanges: false
    }">
        <!-- Sidebar -->
        <aside :class="sidebarOpen ? 'w-64' : 'w-20'" class="bg-gray-800 text-white transition-all duration-300 ease-in-out">
            <div class="p-4">
                <div class="flex items-center justify-between mb-8">
                    <h2 x-show="sidebarOpen" x-transition class="text-xl font-bold">لوحة التحكم</h2>
                    <button @click="sidebarOpen = !sidebarOpen" class="text-gray-400 hover:text-white focus:outline-none">
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
                    <a href="achievements.php" class="flex items-center p-3 rounded-lg hover:bg-gray-700 transition-colors">
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
                <a href="logout.php" class="flex items-center p-2 rounded hover:bg-gray-700 transition-colors text-red-400">
                    <i class="fas fa-sign-out-alt w-6"></i>
                    <span x-show="sidebarOpen" x-transition class="mr-3">تسجيل الخروج</span>
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 overflow-x-hidden">
            <!-- Header -->
            <header class="bg-white shadow-sm border-b">
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
                            <a href="../" target="_blank" class="btn btn-sm btn-ghost">
                                <i class="fas fa-external-link-alt ml-2"></i>
                                عرض الموقع
                            </a>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Settings Content -->
            <div class="p-6">
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
                
                <!-- Settings Form -->
                <form method="POST" @change="unsavedChanges = true">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <!-- Tab Navigation -->
                    <div class="bg-white rounded-lg shadow-sm mb-6">
                        <div class="border-b">
                            <nav class="flex overflow-x-auto">
                                <button type="button" 
                                        @click="activeTab = 'general'"
                                        :class="activeTab === 'general' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-cog ml-2"></i>
                                    عام
                                </button>
                                <button type="button"
                                        @click="activeTab = 'users'"
                                        :class="activeTab === 'users' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-users ml-2"></i>
                                    المستخدمون
                                </button>
                                <button type="button"
                                        @click="activeTab = 'quizzes'"
                                        :class="activeTab === 'quizzes' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-question-circle ml-2"></i>
                                    الاختبارات
                                </button>
                                <button type="button"
                                        @click="activeTab = 'grades'"
                                        :class="activeTab === 'grades' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-graduation-cap ml-2"></i>
                                    المراحل الدراسية
                                </button>
                                <button type="button"
                                        @click="activeTab = 'gamification'"
                                        :class="activeTab === 'gamification' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-trophy ml-2"></i>
                                    التلعيب
                                </button>
                                <button type="button"
                                        @click="activeTab = 'appearance'"
                                        :class="activeTab === 'appearance' ? 'active' : ''"
                                        class="tab-button px-6 py-4 text-gray-700 hover:text-purple-600 font-medium whitespace-nowrap">
                                    <i class="fas fa-palette ml-2"></i>
                                    المظهر
                                </button>
                            </nav>
                        </div>
                    </div>
                    
                    <!-- Tab Content -->
                    <div class="bg-white rounded-lg shadow-sm p-6">
                        <!-- General Settings -->
                        <div x-show="activeTab === 'general'" x-transition>
                            <h2 class="text-xl font-bold mb-6">الإعدادات العامة</h2>
                            
                            <div class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="label">
                                            <span class="label-text">اسم الموقع</span>
                                        </label>
                                        <input type="text" name="site_name" value="<?= e($settingsData['site_name']) ?>" 
                                               class="input input-bordered w-full" required>
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">البريد الإلكتروني للإدارة</span>
                                        </label>
                                        <input type="email" name="admin_email" value="<?= e($settingsData['admin_email']) ?>" 
                                               class="input input-bordered w-full">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="label">
                                        <span class="label-text">وصف الموقع</span>
                                    </label>
                                    <textarea name="site_description" rows="3" 
                                              class="textarea textarea-bordered w-full"><?= e($settingsData['site_description']) ?></textarea>
                                </div>
                                
                                <div class="divider">وضع الصيانة</div>
                                
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
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
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <div x-show="document.querySelector('[name=maintenance_mode]:checked')" x-transition>
                                        <label class="label">
                                            <span class="label-text">رسالة الصيانة</span>
                                        </label>
                                        <textarea name="maintenance_message" rows="2" 
                                                  class="textarea textarea-bordered w-full"><?= e($settingsData['maintenance_message']) ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- User Settings -->
                        <div x-show="activeTab === 'users'" x-transition>
                            <h2 class="text-xl font-bold mb-6">إعدادات المستخدمين</h2>
                            
                            <div class="space-y-6">
                                <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="font-medium">السماح بالتسجيل</span>
                                        <p class="text-sm text-gray-600 mt-1">السماح للمستخدمين الجدد بإنشاء حسابات</p>
                                    </div>
                                    <div class="relative">
                                        <input type="hidden" name="allow_registration" value="false">
                                        <input type="checkbox" name="allow_registration" value="true" 
                                               <?= $settingsData['allow_registration'] ? 'checked' : '' ?>
                                               class="switch-input sr-only">
                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                             onclick="this.previousElementSibling.click()">
                                            <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="font-medium">السماح بالدخول كضيف</span>
                                        <p class="text-sm text-gray-600 mt-1">السماح بحل الاختبارات بدون تسجيل</p>
                                    </div>
                                    <div class="relative">
                                        <input type="hidden" name="allow_guests" value="false">
                                        <input type="checkbox" name="allow_guests" value="true" 
                                               <?= $settingsData['allow_guests'] ? 'checked' : '' ?>
                                               class="switch-input sr-only">
                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                             onclick="this.previousElementSibling.click()">
                                            <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="font-medium">التحقق من البريد الإلكتروني</span>
                                        <p class="text-sm text-gray-600 mt-1">مطالبة المستخدمين بتأكيد بريدهم الإلكتروني</p>
                                    </div>
                                    <div class="relative">
                                        <input type="hidden" name="require_email_verification" value="false">
                                        <input type="checkbox" name="require_email_verification" value="true" 
                                               <?= $settingsData['require_email_verification'] ? 'checked' : '' ?>
                                               class="switch-input sr-only">
                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                             onclick="this.previousElementSibling.click()">
                                            <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Quiz Settings -->
                        <div x-show="activeTab === 'quizzes'" x-transition>
                            <h2 class="text-xl font-bold mb-6">إعدادات الاختبارات</h2>
                            
                            <div class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="label">
                                            <span class="label-text">عدد الاختبارات في الصفحة</span>
                                        </label>
                                        <input type="number" name="quiz_per_page" value="<?= $settingsData['quiz_per_page'] ?>" 
                                               min="6" max="50" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">الوقت الافتراضي للاختبار (دقائق)</span>
                                        </label>
                                        <input type="number" name="default_quiz_time" value="<?= $settingsData['default_quiz_time'] ?>" 
                                               min="0" max="180" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">الحد الأدنى للخيارات</span>
                                        </label>
                                        <input type="number" name="min_options_per_question" value="<?= $settingsData['min_options_per_question'] ?>" 
                                               min="2" max="4" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">الحد الأقصى للخيارات</span>
                                        </label>
                                        <input type="number" name="max_options_per_question" value="<?= $settingsData['max_options_per_question'] ?>" 
                                               min="4" max="8" class="input input-bordered w-full">
                                    </div>
                                </div>
                                
                                <div class="divider">رموز الوصول</div>
                                
                                <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="font-medium">تفعيل رموز الوصول</span>
                                        <p class="text-sm text-gray-600 mt-1">السماح بالوصول للاختبارات عبر رمز PIN</p>
                                    </div>
                                    <div class="relative">
                                        <input type="hidden" name="enable_quiz_codes" value="false">
                                        <input type="checkbox" name="enable_quiz_codes" value="true" 
                                               <?= $settingsData['enable_quiz_codes'] ? 'checked' : '' ?>
                                               class="switch-input sr-only">
                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                             onclick="this.previousElementSibling.click()">
                                            <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                        </div>
                                    </div>
                                </label>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="label">
                                            <span class="label-text">طول رمز الوصول</span>
                                        </label>
                                        <input type="number" name="quiz_code_length" value="<?= $settingsData['quiz_code_length'] ?>" 
                                               min="4" max="8" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">مدة صلاحية الرمز (أيام)</span>
                                            <span class="label-text-alt">0 = دائم</span>
                                        </label>
                                        <input type="number" name="quiz_code_expiry_days" value="<?= $settingsData['quiz_code_expiry_days'] ?>" 
                                               min="0" max="365" class="input input-bordered w-full">
                                    </div>
                                </div>
                                
                                <div class="divider">خيارات النتائج</div>
                                
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">عرض الإجابات الصحيحة</span>
                                            <p class="text-sm text-gray-600 mt-1">عرض الإجابات الصحيحة بعد انتهاء الاختبار</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="show_correct_answers" value="false">
                                            <input type="checkbox" name="show_correct_answers" value="true" 
                                                   <?= $settingsData['show_correct_answers'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">السماح بإعادة الاختبار</span>
                                            <p class="text-sm text-gray-600 mt-1">السماح للطلاب بإعادة نفس الاختبار</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="allow_quiz_retake" value="false">
                                            <input type="checkbox" name="allow_quiz_retake" value="true" 
                                                   <?= $settingsData['allow_quiz_retake'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">وضع التدريب</span>
                                            <p class="text-sm text-gray-600 mt-1">السماح بوضع التدريب مع عرض الإجابات فوراً</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="enable_practice_mode" value="false">
                                            <input type="checkbox" name="enable_practice_mode" value="true" 
                                                   <?= $settingsData['enable_practice_mode'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Grade Settings -->
                        <div x-show="activeTab === 'grades'" x-transition>
                            <h2 class="text-xl font-bold mb-6">المراحل الدراسية</h2>
                            
                            <div class="space-y-6">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    <span>يمكنك تخصيص أسماء الصفوف الدراسية حسب نظامك التعليمي</span>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <?php for ($i = 0; $i < 12; $i++): ?>
                                    <div>
                                        <label class="label">
                                            <span class="label-text">الصف <?= $i + 1 ?></span>
                                            <span class="label-text-alt">
                                                <?php
                                                $color = 'أخضر';
                                                if ($i >= 6 && $i < 9) $color = 'أصفر';
                                                elseif ($i >= 9) $color = 'أزرق';
                                                ?>
                                                <span class="badge badge-sm badge-<?= $i < 6 ? 'success' : ($i < 9 ? 'warning' : 'info') ?>">
                                                    <?= $color ?>
                                                </span>
                                            </span>
                                        </label>
                                        <input type="text" name="grades[<?= $i ?>]" 
                                               value="<?= e($settingsData['grades'][$i] ?? "الصف " . ($i + 1)) ?>" 
                                               class="input input-bordered w-full">
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Gamification Settings -->
                        <div x-show="activeTab === 'gamification'" x-transition>
                            <h2 class="text-xl font-bold mb-6">إعدادات التلعيب</h2>
                            
                            <div class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="label">
                                            <span class="label-text">النقاط لكل إجابة صحيحة</span>
                                        </label>
                                        <input type="number" name="points_per_correct_answer" 
                                               value="<?= $settingsData['points_per_correct_answer'] ?>" 
                                               min="1" max="100" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">درجة النجاح (%)</span>
                                        </label>
                                        <input type="number" name="passing_score" 
                                               value="<?= $settingsData['passing_score'] ?>" 
                                               min="40" max="80" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">درجة التميز (%)</span>
                                        </label>
                                        <input type="number" name="excellence_score" 
                                               value="<?= $settingsData['excellence_score'] ?>" 
                                               min="80" max="100" class="input input-bordered w-full">
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">حجم لوحة المتصدرين</span>
                                        </label>
                                        <input type="number" name="leaderboard_size" 
                                               value="<?= $settingsData['leaderboard_size'] ?>" 
                                               min="5" max="50" class="input input-bordered w-full">
                                    </div>
                                </div>
                                
                                <div class="divider">مكافآت السرعة</div>
                                
                                <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <span class="font-medium">تفعيل مكافأة السرعة</span>
                                        <p class="text-sm text-gray-600 mt-1">منح نقاط إضافية للإنجاز السريع</p>
                                    </div>
                                    <div class="relative">
                                        <input type="hidden" name="speed_bonus_enabled" value="false">
                                        <input type="checkbox" name="speed_bonus_enabled" value="true" 
                                               <?= $settingsData['speed_bonus_enabled'] ? 'checked' : '' ?>
                                               class="switch-input sr-only">
                                        <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                             onclick="this.previousElementSibling.click()">
                                            <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                        </div>
                                    </div>
                                </label>
                                
                                <div>
                                    <label class="label">
                                        <span class="label-text">نسبة مكافأة السرعة (%)</span>
                                    </label>
                                    <input type="number" name="speed_bonus_percentage" 
                                           value="<?= $settingsData['speed_bonus_percentage'] ?>" 
                                           min="5" max="25" class="input input-bordered w-full">
                                </div>
                                
                                <div class="divider">الميزات</div>
                                
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">تفعيل الإنجازات</span>
                                            <p class="text-sm text-gray-600 mt-1">نظام الشارات والإنجازات</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="enable_achievements" value="false">
                                            <input type="checkbox" name="enable_achievements" value="true" 
                                                   <?= $settingsData['enable_achievements'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">تفعيل لوحة المتصدرين</span>
                                            <p class="text-sm text-gray-600 mt-1">عرض ترتيب الطلاب</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="enable_leaderboard" value="false">
                                            <input type="checkbox" name="enable_leaderboard" value="true" 
                                                   <?= $settingsData['enable_leaderboard'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Appearance Settings -->
                        <div x-show="activeTab === 'appearance'" x-transition>
                            <h2 class="text-xl font-bold mb-6">إعدادات المظهر</h2>
                            
                            <div class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="label">
                                            <span class="label-text">اللون الأساسي</span>
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <input type="color" name="theme_primary_color" 
                                                   value="<?= e($settingsData['theme_primary_color']) ?>" 
                                                   class="cursor-pointer">
                                            <input type="text" value="<?= e($settingsData['theme_primary_color']) ?>" 
                                                   class="input input-bordered flex-1" readonly>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">اللون الثانوي</span>
                                        </label>
                                        <div class="flex items-center gap-3">
                                            <input type="color" name="theme_secondary_color" 
                                                   value="<?= e($settingsData['theme_secondary_color']) ?>" 
                                                   class="cursor-pointer">
                                            <input type="text" value="<?= e($settingsData['theme_secondary_color']) ?>" 
                                                   class="input input-bordered flex-1" readonly>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">تنسيق التاريخ</span>
                                        </label>
                                        <select name="date_format" class="select select-bordered w-full">
                                            <option value="d/m/Y" <?= $settingsData['date_format'] === 'd/m/Y' ? 'selected' : '' ?>>
                                                31/12/2024
                                            </option>
                                            <option value="d-m-Y" <?= $settingsData['date_format'] === 'd-m-Y' ? 'selected' : '' ?>>
                                                31-12-2024
                                            </option>
                                            <option value="Y-m-d" <?= $settingsData['date_format'] === 'Y-m-d' ? 'selected' : '' ?>>
                                                2024-12-31
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="label">
                                            <span class="label-text">تنسيق الوقت</span>
                                        </label>
                                        <select name="time_format" class="select select-bordered w-full">
                                            <option value="H:i" <?= $settingsData['time_format'] === 'H:i' ? 'selected' : '' ?>>
                                                24 ساعة (15:30)
                                            </option>
                                            <option value="h:i A" <?= $settingsData['time_format'] === 'h:i A' ? 'selected' : '' ?>>
                                                12 ساعة (03:30 م)
                                            </option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="divider">التأثيرات</div>
                                
                                <div class="space-y-4">
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">تفعيل الحركات</span>
                                            <p class="text-sm text-gray-600 mt-1">الحركات والانتقالات المتحركة</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="enable_animations" value="false">
                                            <input type="checkbox" name="enable_animations" value="true" 
                                                   <?= $settingsData['enable_animations'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                    
                                    <label class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <span class="font-medium">تفعيل المؤثرات الصوتية</span>
                                            <p class="text-sm text-gray-600 mt-1">أصوات النجاح والفشل</p>
                                        </div>
                                        <div class="relative">
                                            <input type="hidden" name="enable_sound_effects" value="false">
                                            <input type="checkbox" name="enable_sound_effects" value="true" 
                                                   <?= $settingsData['enable_sound_effects'] ? 'checked' : '' ?>
                                                   class="switch-input sr-only">
                                            <div class="switch-slider w-14 h-7 bg-gray-300 rounded-full relative cursor-pointer"
                                                 onclick="this.previousElementSibling.click()">
                                                <div class="switch-thumb absolute right-1 top-1 bg-white w-5 h-5 rounded-full transition-transform"></div>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Save Button -->
                    <div class="mt-6 flex items-center justify-between">
                        <div x-show="unsavedChanges" class="text-amber-600">
                            <i class="fas fa-exclamation-triangle ml-2"></i>
                            لديك تغييرات غير محفوظة
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save ml-2"></i>
                            حفظ الإعدادات
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
    
    <script>
        // Update color input text values
        document.querySelectorAll('input[type="color"]').forEach(input => {
            input.addEventListener('change', function() {
                this.nextElementSibling.value = this.value;
            });
        });
        
        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (Alpine.store('unsavedChanges')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>