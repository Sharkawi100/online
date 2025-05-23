<?php
// /admin/ai-settings.php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/ai_functions.php';

// Check admin access
if (!isLoggedIn() || !hasRole('admin')) {
    redirect('/admin/login.php');
}

$success = '';
$error = '';

// Get current API settings
$stmt = $pdo->query("SELECT * FROM api_settings ORDER BY is_active DESC, provider");
$api_settings = $stmt->fetchAll();

// Get AI-related settings
$ai_settings = [
    'ai_enabled' => getSetting('ai_enabled', true),
    'ai_default_provider' => getSetting('ai_default_provider', 'openai'),
    'ai_monthly_limit' => getSetting('ai_monthly_limit', 1000),
    'ai_cost_per_question' => getSetting('ai_cost_per_question', 0.05),
    'ai_max_questions_per_request' => getSetting('ai_max_questions_per_request', 10)
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'خطأ في الحماية. يرجى المحاولة مرة أخرى.';
    } else {
        // Add new API key
        if (isset($_POST['add_api_key'])) {
            try {
                $provider = $_POST['provider'];
                $api_key = $_POST['api_key'];
                $model = $_POST['model'];

                // Test the API key
                $test_prompt = "Test connection. Reply with 'OK' only.";

                if ($provider === 'openai') {
                    $encrypted_key = encryptApiKey($api_key);
                    // Temporarily set for testing
                    $stmt = $pdo->prepare("INSERT INTO api_settings (provider, api_key, model) VALUES (?, ?, ?)");
                    $stmt->execute([$provider, $encrypted_key, $model]);
                    $test_id = $pdo->lastInsertId();

                    try {
                        callOpenAI($test_prompt, $model, 0.1, 10);
                        $success = 'تمت إضافة مفتاح OpenAI بنجاح';
                    } catch (Exception $e) {
                        $pdo->exec("DELETE FROM api_settings WHERE id = $test_id");
                        throw new Exception('فشل اختبار المفتاح: ' . $e->getMessage());
                    }
                } else if ($provider === 'claude') {
                    $encrypted_key = encryptApiKey($api_key);
                    $stmt = $pdo->prepare("INSERT INTO api_settings (provider, api_key, model) VALUES (?, ?, ?)");
                    $stmt->execute([$provider, $encrypted_key, $model]);
                    $test_id = $pdo->lastInsertId();

                    try {
                        callClaude($test_prompt, $model, 0.1, 10);
                        $success = 'تمت إضافة مفتاح Claude بنجاح';
                    } catch (Exception $e) {
                        $pdo->exec("DELETE FROM api_settings WHERE id = $test_id");
                        throw new Exception('فشل اختبار المفتاح: ' . $e->getMessage());
                    }
                }

                // Deactivate other keys of same provider
                $stmt = $pdo->prepare("UPDATE api_settings SET is_active = 0 WHERE provider = ? AND id != ?");
                $stmt->execute([$provider, $test_id]);

            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // Update API key status
        if (isset($_POST['update_api_status'])) {
            $api_id = (int) $_POST['api_id'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($is_active) {
                // Deactivate other keys of same provider
                $stmt = $pdo->prepare("SELECT provider FROM api_settings WHERE id = ?");
                $stmt->execute([$api_id]);
                $provider = $stmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE api_settings SET is_active = 0 WHERE provider = ?");
                $stmt->execute([$provider]);
            }

            $stmt = $pdo->prepare("UPDATE api_settings SET is_active = ? WHERE id = ?");
            $stmt->execute([$is_active, $api_id]);
            $success = 'تم تحديث حالة المفتاح';
        }

        // Delete API key
        if (isset($_POST['delete_api_key'])) {
            $api_id = (int) $_POST['api_id'];
            $stmt = $pdo->prepare("DELETE FROM api_settings WHERE id = ?");
            $stmt->execute([$api_id]);
            $success = 'تم حذف المفتاح';
        }

        // Update general AI settings
        if (isset($_POST['update_settings'])) {
            updateSetting('ai_enabled', isset($_POST['ai_enabled']) ? 'true' : 'false', 'boolean');
            updateSetting('ai_default_provider', $_POST['ai_default_provider'], 'string');
            updateSetting('ai_monthly_limit', (int) $_POST['ai_monthly_limit'], 'number');
            updateSetting('ai_cost_per_question', (float) $_POST['ai_cost_per_question'], 'number');
            updateSetting('ai_max_questions_per_request', (int) $_POST['ai_max_questions_per_request'], 'number');
            $success = 'تم تحديث الإعدادات بنجاح';

            // Refresh settings
            $ai_settings = [
                'ai_enabled' => getSetting('ai_enabled', true),
                'ai_default_provider' => getSetting('ai_default_provider', 'openai'),
                'ai_monthly_limit' => getSetting('ai_monthly_limit', 1000),
                'ai_cost_per_question' => getSetting('ai_cost_per_question', 0.05),
                'ai_max_questions_per_request' => getSetting('ai_max_questions_per_request', 10)
            ];
        }

        // Refresh API settings
        $stmt = $pdo->query("SELECT * FROM api_settings ORDER BY is_active DESC, provider");
        $api_settings = $stmt->fetchAll();
    }
}

// Get usage statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT teacher_id) as unique_teachers,
        COUNT(*) as total_generations,
        SUM(questions_generated) as total_questions,
        SUM(tokens_used) as total_tokens,
        SUM(cost_estimate) as total_cost,
        AVG(questions_generated) as avg_questions_per_request
    FROM ai_generation_logs
    WHERE MONTH(generated_at) = MONTH(CURRENT_DATE())
    AND YEAR(generated_at) = YEAR(CURRENT_DATE())
    AND success = 1
");
$usage_stats = $stmt->fetch();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعدادات الذكاء الاصطناعي - لوحة الإدارة</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.4.19/dist/full.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>

<body class="bg-gray-50" x-data="{ showAddKey: false, addProvider: 'openai' }">
    <div class="flex">
        <!-- Sidebar -->
        <aside class="w-64 min-h-screen bg-base-200">
            <div class="p-4">
                <h2 class="text-xl font-bold mb-6">لوحة الإدارة</h2>
                <ul class="menu bg-base-200 w-full">
                    <li><a href="<?= BASE_URL ?>/admin/"><i class="fas fa-home"></i> الرئيسية</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/users.php"><i class="fas fa-users"></i> المستخدمون</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/quizzes.php"><i class="fas fa-question-circle"></i>
                            الاختبارات</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/subjects.php"><i class="fas fa-book"></i> المواد</a></li>
                    <li><a href="<?= BASE_URL ?>/admin/settings.php"><i class="fas fa-cog"></i> الإعدادات</a></li>
                    <li class="active"><a href="<?= BASE_URL ?>/admin/ai-settings.php"><i class="fas fa-robot"></i>
                            الذكاء الاصطناعي</a></li>
                    <li><a href="<?= BASE_URL ?>/auth/logout.php"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-8">
            <h1 class="text-3xl font-bold mb-8">
                <i class="fas fa-robot text-purple-600 ml-2"></i>
                إعدادات الذكاء الاصطناعي
            </h1>

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

            <!-- Usage Statistics -->
            <div class="stats shadow mb-8 w-full">
                <div class="stat">
                    <div class="stat-figure text-primary">
                        <i class="fas fa-users text-3xl"></i>
                    </div>
                    <div class="stat-title">المعلمون النشطون</div>
                    <div class="stat-value"><?= $usage_stats['unique_teachers'] ?? 0 ?></div>
                    <div class="stat-desc">هذا الشهر</div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-secondary">
                        <i class="fas fa-magic text-3xl"></i>
                    </div>
                    <div class="stat-title">مرات التوليد</div>
                    <div class="stat-value"><?= $usage_stats['total_generations'] ?? 0 ?></div>
                    <div class="stat-desc">إجمالي هذا الشهر</div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-accent">
                        <i class="fas fa-question-circle text-3xl"></i>
                    </div>
                    <div class="stat-title">الأسئلة المولدة</div>
                    <div class="stat-value"><?= $usage_stats['total_questions'] ?? 0 ?></div>
                    <div class="stat-desc">متوسط <?= round($usage_stats['avg_questions_per_request'] ?? 0, 1) ?> لكل طلب
                    </div>
                </div>

                <div class="stat">
                    <div class="stat-figure text-info">
                        <i class="fas fa-dollar-sign text-3xl"></i>
                    </div>
                    <div class="stat-title">التكلفة التقديرية</div>
                    <div class="stat-value">$<?= number_format($usage_stats['total_cost'] ?? 0, 2) ?></div>
                    <div class="stat-desc">هذا الشهر</div>
                </div>
            </div>

            <!-- API Keys Management -->
            <div class="card bg-base-100 shadow-xl mb-8">
                <div class="card-body">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="card-title">
                            <i class="fas fa-key text-primary"></i>
                            مفاتيح API
                        </h2>
                        <button @click="showAddKey = true" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة مفتاح
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>المزود</th>
                                    <th>النموذج</th>
                                    <th>المفتاح</th>
                                    <th>الاستخدام</th>
                                    <th>آخر استخدام</th>
                                    <th>الحالة</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($api_settings as $api): ?>
                                    <tr>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <?php if ($api['provider'] === 'openai'): ?>
                                                    <i class="fas fa-brain text-green-600"></i>
                                                    <span>OpenAI</span>
                                                <?php else: ?>
                                                    <i class="fas fa-robot text-purple-600"></i>
                                                    <span>Claude</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= e($api['model']) ?></td>
                                        <td>
                                            <code
                                                class="text-xs">****<?= substr(decryptApiKey($api['api_key']), -4) ?></code>
                                        </td>
                                        <td><?= $api['usage_count'] ?> مرة</td>
                                        <td><?= $api['last_used'] ? timeAgo($api['last_used']) : 'لم يستخدم' ?></td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="update_api_status" value="1">
                                                <input type="hidden" name="api_id" value="<?= $api['id'] ?>">
                                                <input type="checkbox" name="is_active" class="toggle toggle-success"
                                                    <?= $api['is_active'] ? 'checked' : '' ?> onchange="this.form.submit()">
                                            </form>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                <input type="hidden" name="delete_api_key" value="1">
                                                <input type="hidden" name="api_id" value="<?= $api['id'] ?>">
                                                <button type="submit" class="btn btn-error btn-xs"
                                                    onclick="return confirm('هل أنت متأكد من حذف هذا المفتاح؟')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if (empty($api_settings)): ?>
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-key text-4xl mb-4"></i>
                                <p>لم تتم إضافة أي مفاتيح API بعد</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- General Settings -->
            <div class="card bg-base-100 shadow-xl">
                <div class="card-body">
                    <h2 class="card-title mb-4">
                        <i class="fas fa-cog text-primary"></i>
                        الإعدادات العامة
                    </h2>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="update_settings" value="1">

                        <div class="form-control">
                            <label class="label cursor-pointer">
                                <span class="label-text text-lg">تفعيل الذكاء الاصطناعي</span>
                                <input type="checkbox" name="ai_enabled" class="checkbox checkbox-primary"
                                    <?= $ai_settings['ai_enabled'] ? 'checked' : '' ?>>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">المزود الافتراضي</span>
                                </label>
                                <select name="ai_default_provider" class="select select-bordered">
                                    <option value="openai" <?= $ai_settings['ai_default_provider'] === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                                    <option value="claude" <?= $ai_settings['ai_default_provider'] === 'claude' ? 'selected' : '' ?>>Claude</option>
                                </select>
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">الحد الشهري لكل معلم</span>
                                </label>
                                <input type="number" name="ai_monthly_limit"
                                    value="<?= $ai_settings['ai_monthly_limit'] ?>" class="input input-bordered" min="1"
                                    max="10000">
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">التكلفة التقديرية لكل سؤال ($)</span>
                                </label>
                                <input type="number" name="ai_cost_per_question"
                                    value="<?= $ai_settings['ai_cost_per_question'] ?>" class="input input-bordered"
                                    min="0.01" max="1" step="0.01">
                            </div>

                            <div class="form-control">
                                <label class="label">
                                    <span class="label-text">الحد الأقصى للأسئلة في الطلب الواحد</span>
                                </label>
                                <input type="number" name="ai_max_questions_per_request"
                                    value="<?= $ai_settings['ai_max_questions_per_request'] ?>"
                                    class="input input-bordered" min="1" max="50">
                            </div>
                        </div>

                        <div class="card-actions justify-end mt-6">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save ml-2"></i>
                                حفظ الإعدادات
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- Add API Key Modal -->
    <div x-show="showAddKey" x-transition class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black opacity-50" @click="showAddKey = false"></div>

            <div class="relative bg-white rounded-lg max-w-md w-full p-6">
                <button @click="showAddKey = false" class="absolute top-4 left-4 text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>

                <h3 class="text-2xl font-bold mb-4">إضافة مفتاح API</h3>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="add_api_key" value="1">

                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">المزود</span>
                        </label>
                        <select name="provider" x-model="addProvider" class="select select-bordered" required>
                            <option value="openai">OpenAI</option>
                            <option value="claude">Claude (Anthropic)</option>
                        </select>
                    </div>

                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">مفتاح API</span>
                        </label>
                        <input type="text" name="api_key" class="input input-bordered"
                            :placeholder="addProvider === 'openai' ? 'sk-...' : 'sk-ant-...'" required>
                    </div>

                    <div class="form-control">
                        <label class="label">
                            <span class="label-text">النموذج</span>
                        </label>
                        <select name="model" class="select select-bordered" required>
                            <template x-if="addProvider === 'openai'">
                                <optgroup label="OpenAI Models">
                                    <option value="gpt-4">GPT-4</option>
                                    <option value="gpt-4-turbo-preview">GPT-4 Turbo</option>
                                    <option value="gpt-3.5-turbo">GPT-3.5 Turbo</option>
                                </optgroup>
                            </template>
                            <template x-if="addProvider === 'claude'">
                                <optgroup label="Claude Models">
                                    <option value="claude-3-opus-20240229">Claude 3 Opus</option>
                                    <option value="claude-3-sonnet-20240229">Claude 3 Sonnet</option>
                                    <option value="claude-3-haiku-20240307">Claude 3 Haiku</option>
                                </optgroup>
                            </template>
                        </select>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span>سيتم اختبار المفتاح قبل الحفظ للتأكد من صحته</span>
                    </div>

                    <div class="modal-action">
                        <button type="button" @click="showAddKey = false" class="btn btn-ghost">إلغاء</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus ml-2"></i>
                            إضافة المفتاح
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>