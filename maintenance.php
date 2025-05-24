<?php
/**
 * Maintenance Mode Page
 * /maintenance.php
 */

// Set appropriate headers
header('HTTP/1.1 503 Service Unavailable');
header('Retry-After: 3600'); // 1 hour
header('Content-Type: text/html; charset=UTF-8');

// Get maintenance message if set
$maintenance_message = getSetting('maintenance_message', 'الموقع تحت الصيانة حالياً. نعتذر عن الإزعاج.');
$estimated_time = getSetting('maintenance_estimated_time', '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>الموقع تحت الصيانة - <?= SITE_NAME ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .maintenance-container {
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }

        .gear {
            animation: spin 4s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body class="flex items-center justify-center p-4">
    <div class="maintenance-container rounded-3xl shadow-2xl p-8 md:p-12 max-w-2xl w-full text-center">
        <div class="mb-8">
            <div class="inline-flex items-center justify-center w-32 h-32 bg-purple-100 rounded-full mb-6">
                <i class="fas fa-cog gear text-6xl text-purple-600"></i>
            </div>
            <h1 class="text-4xl font-bold text-gray-800 mb-4">الموقع تحت الصيانة</h1>
            <div class="w-24 h-1 bg-purple-600 mx-auto mb-6"></div>
        </div>

        <p class="text-xl text-gray-600 mb-8"><?= e($maintenance_message) ?></p>

        <?php if ($estimated_time): ?>
            <div class="bg-purple-50 rounded-lg p-4 mb-8">
                <p class="text-purple-800">
                    <i class="fas fa-clock ml-2"></i>
                    الوقت المتوقع للعودة: <?= e($estimated_time) ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-gray-50 rounded-lg p-4">
                <i class="fas fa-shield-alt text-3xl text-green-600 mb-2"></i>
                <h3 class="font-bold mb-1">تحديثات أمنية</h3>
                <p class="text-sm text-gray-600">نقوم بتحسين الحماية</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <i class="fas fa-rocket text-3xl text-blue-600 mb-2"></i>
                <h3 class="font-bold mb-1">تحسين الأداء</h3>
                <p class="text-sm text-gray-600">سرعة أفضل للموقع</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
                <i class="fas fa-magic text-3xl text-purple-600 mb-2"></i>
                <h3 class="font-bold mb-1">ميزات جديدة</h3>
                <p class="text-sm text-gray-600">إضافات مفيدة قادمة</p>
            </div>
        </div>

        <p class="text-gray-500 mb-6">نعتذر عن أي إزعاج وسنعود قريباً بشكل أفضل!</p>

        <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="mailto:<?= e(getSetting('contact_email', 'support@iseraj.com')) ?>"
                class="inline-flex items-center justify-center px-6 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                <i class="fas fa-envelope ml-2"></i>
                تواصل معنا
            </a>
            <button onclick="location.reload()"
                class="inline-flex items-center justify-center px-6 py-3 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition">
                <i class="fas fa-redo ml-2"></i>
                تحديث الصفحة
            </button>
        </div>

        <div class="mt-8 pt-8 border-t border-gray-200">
            <p class="text-sm text-gray-500">
                &copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. جميع الحقوق محفوظة.
            </p>
        </div>
    </div>

    <script>
        // Auto-refresh every 5 minutes
        setTimeout(() => {
            location.reload();
        }, 300000);
    </script>
</body>

</html>