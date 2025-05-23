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
    if ($row['setting_type'] === 'boolean') {
        $value = $value === 'true';
    } elseif ($row['setting_type'] === 'number') {
        $value = is_numeric($value) ? (strpos($value, '.') !== false ? (float) $value : (int) $value) : $value;
    }
    $settingsData[$row['setting_key']] = $value;
}

// Default values for essential settings only
$defaults = [
    // Site Information
    'site_name' => 'منصة الاختبارات التفاعلية',
    'site_description' => 'منصة تعليمية تفاعلية لجميع المراحل الدراسية',
    'admin_email' => 'admin@example.com',
    'timezone' => 'Asia/Riyadh'
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
    <!-- Note: For production, install Tailwind CSS via npm/PostCSS instead of CDN -->
    <!-- See: https://tailwindcss.com/docs/installation -->
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

        /* Section animation */
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
    </style>
</head>

<body class="bg-gray-50" x-data="{ 
    sidebarOpen: window.innerWidth > 768,
    unsavedChanges: false,
    saveSettings() {
        this.unsavedChanges = false;
        document.getElementById('settingsForm').submit();
    }
}">
    <div class="min-h-screen flex">
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
                            <p class="text-gray-600 mt-1">إدارة معلومات الموقع الأساسية</p>
                        </div>
                        <a href="../" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt ml-2"></i>
                            عرض الموقع
                        </a>
                    </div>
                </div>
            </header>

            <!-- Settings Content -->
            <div class="p-6 max-w-4xl mx-auto">
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
                <div x-show="unsavedChanges" x-transition class="fixed bottom-6 left-6 z-50">
                    <button @click="saveSettings()" class="btn btn-primary shadow-lg">
                        <i class="fas fa-save ml-2"></i>
                        حفظ التغييرات
                    </button>
                </div>

                <!-- Settings Form -->
                <form method="POST" @change="unsavedChanges = true" id="settingsForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <!-- Site Information -->
                    <div class="card bg-base-100 shadow-xl">
                        <div class="card-body">
                            <h2 class="card-title mb-6">
                                <i class="fas fa-info-circle text-blue-600"></i>
                                معلومات الموقع
                            </h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="label">
                                        <span class="label-text font-medium">اسم الموقع</span>
                                    </label>
                                    <input type="text" name="site_name" value="<?= e($settingsData['site_name']) ?>" 
                                           class="input input-bordered w-full" required>
                                </div>

                                <div>
                                    <label class="label">
                                        <span class="label-text font-medium">البريد الإلكتروني للإدارة</span>
                                    </label>
                                    <input type="email" name="admin_email" value="<?= e($settingsData['admin_email']) ?>" 
                                           class="input input-bordered w-full" dir="ltr" required>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="label">
                                        <span class="label-text font-medium">وصف الموقع</span>
                                    </label>
                                    <textarea name="site_description" rows="3" 
                                              class="textarea textarea-bordered w-full"><?= e($settingsData['site_description']) ?></textarea>
                                </div>

                                <div>
                                    <label class="label">
                                        <span class="label-text font-medium">المنطقة الزمنية</span>
                                    </label>
                                    <select name="timezone" class="select select-bordered w-full">
                                        <option value="Asia/Jerusalem" <?= $settingsData['timezone'] === 'Asia/Jerusalem' ? 'selected' : '' ?>>القدس (GMT+2/+3)</option>
                                        <option value="Asia/Riyadh" <?= $settingsData['timezone'] === 'Asia/Riyadh' ? 'selected' : '' ?>>الرياض (GMT+3)</option>
                                        <option value="Asia/Dubai" <?= $settingsData['timezone'] === 'Asia/Dubai' ? 'selected' : '' ?>>دبي (GMT+4)</option>
                                        <option value="Africa/Cairo" <?= $settingsData['timezone'] === 'Africa/Cairo' ? 'selected' : '' ?>>القاهرة (GMT+2)</option>
                                        <option value="Europe/Istanbul" <?= $settingsData['timezone'] === 'Europe/Istanbul' ? 'selected' : '' ?>>إسطنبول (GMT+3)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', function(e) {
            const unsavedChanges = Alpine.$data(document.body).unsavedChanges;
            if (unsavedChanges) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>

</html>