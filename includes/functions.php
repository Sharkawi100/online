<?php
/**
 * Generate a random PIN code for quizzes
 * @param int $length Length of PIN (default 6)
 * @return string Generated PIN
 */
function generatePIN($length = 6)
{
    global $pdo;

    do {
        $pin = '';
        for ($i = 0; $i < $length; $i++) {
            $pin .= random_int(0, 9);
        }

        // Check if PIN already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM quizzes WHERE pin_code = ?");
        $stmt->execute([$pin]);
        $exists = $stmt->fetchColumn() > 0;

    } while ($exists);

    return $pin;
}

/**
 * Get grade name in Arabic
 * @param int $grade Grade number (1-12)
 * @return string Grade name in Arabic
 */
function getGradeName($grade)
{
    $grades = getSetting('grades', []);
    return isset($grades[$grade - 1]) ? $grades[$grade - 1] : "الصف $grade";
}

/**
 * Get grade color group
 * @param int $grade Grade number
 * @return string Color name (green, yellow, blue)
 */
function getGradeColor($grade)
{
    if ($grade >= 1 && $grade <= 6)
        return 'green';
    if ($grade >= 7 && $grade <= 9)
        return 'yellow';
    if ($grade >= 10 && $grade <= 12)
        return 'blue';
    return 'gray';
}

/**
 * Get grade group name
 * @param int $grade Grade number
 * @return string Group name in Arabic
 */
function getGradeGroup($grade)
{
    if ($grade >= 1 && $grade <= 6)
        return 'elementary';
    if ($grade >= 7 && $grade <= 9)
        return 'middle';
    if ($grade >= 10 && $grade <= 12)
        return 'high';
    return 'unknown';
}

/**
 * Calculate time ago in Arabic
 * @param string $datetime DateTime string
 * @return string Time ago in Arabic
 */
function timeAgo($datetime)
{
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) {
        return $diff->y == 1 ? 'منذ سنة' : "منذ {$diff->y} سنوات";
    }
    if ($diff->m > 0) {
        return $diff->m == 1 ? 'منذ شهر' : "منذ {$diff->m} شهور";
    }
    if ($diff->d > 0) {
        if ($diff->d == 1)
            return 'منذ يوم';
        if ($diff->d == 2)
            return 'منذ يومين';
        return "منذ {$diff->d} أيام";
    }
    if ($diff->h > 0) {
        if ($diff->h == 1)
            return 'منذ ساعة';
        if ($diff->h == 2)
            return 'منذ ساعتين';
        return "منذ {$diff->h} ساعات";
    }
    if ($diff->i > 0) {
        if ($diff->i == 1)
            return 'منذ دقيقة';
        if ($diff->i == 2)
            return 'منذ دقيقتين';
        return "منذ {$diff->i} دقائق";
    }

    return 'الآن';
}

/**
 * Format number in Arabic
 * @param int $number Number to format
 * @return string Formatted number
 */
function formatArabicNumber($number)
{
    return number_format($number, 0, ',', '،');
}

/**
 * Calculate quiz score with speed bonus
 * @param int $correctAnswers Number of correct answers
 * @param int $totalQuestions Total questions
 * @param int $timeTaken Time taken in seconds
 * @param int $timeLimit Time limit in minutes (0 = no limit)
 * @return array Score details
 */
function calculateScore($correctAnswers, $totalQuestions, $timeTaken, $timeLimit = 0)
{
    // Base score
    $baseScore = ($correctAnswers / $totalQuestions) * 100;

    // Speed bonus (only if there's a time limit)
    $speedBonus = 0;
    if ($timeLimit > 0) {
        $timeLimitSeconds = $timeLimit * 60;
        if ($timeTaken < $timeLimitSeconds) {
            // 10% bonus for finishing in half the time, scaling down
            $timeRatio = $timeTaken / $timeLimitSeconds;
            if ($timeRatio < 0.5) {
                $speedBonus = 10;
            } else {
                $speedBonus = (1 - $timeRatio) * 20; // Max 10% bonus
            }
        }
    }

    $finalScore = min(100, $baseScore + $speedBonus);

    return [
        'base_score' => round($baseScore, 2),
        'speed_bonus' => round($speedBonus, 2),
        'final_score' => round($finalScore, 2),
        'points_earned' => round($finalScore / 10) // 10 points per 10% score
    ];
}

/**
 * Check if user has achievement
 * @param int $userId User ID
 * @param int $achievementId Achievement ID
 * @return bool
 */
function hasAchievement($userId, $achievementId)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_achievements WHERE user_id = ? AND achievement_id = ?");
    $stmt->execute([$userId, $achievementId]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Award achievement to user
 * @param int $userId User ID
 * @param int $achievementId Achievement ID
 * @return bool Success
 */
function awardAchievement($userId, $achievementId)
{
    global $pdo;

    if (hasAchievement($userId, $achievementId)) {
        return false; // Already has it
    }

    try {
        // Get achievement details
        $stmt = $pdo->prepare("SELECT points_value FROM achievements WHERE id = ?");
        $stmt->execute([$achievementId]);
        $achievement = $stmt->fetch();

        if (!$achievement)
            return false;

        // Start transaction
        $pdo->beginTransaction();

        // Award achievement
        $stmt = $pdo->prepare("INSERT INTO user_achievements (user_id, achievement_id) VALUES (?, ?)");
        $stmt->execute([$userId, $achievementId]);

        // Add points
        $stmt = $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?");
        $stmt->execute([$achievement['points_value'], $userId]);

        $pdo->commit();
        return true;

    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Check and award achievements based on user activity
 * @param int $userId User ID
 * @param string $trigger Trigger type (quiz_complete, perfect_score, etc.)
 * @param array $data Additional data
 */
function checkAchievements($userId, $trigger, $data = [])
{
    global $pdo;

    // Get user stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM attempts WHERE user_id = ? AND completed_at IS NOT NULL) as quiz_count,
            current_streak,
            total_points
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$userId, $userId]);
    $stats = $stmt->fetch();

    // Get all achievements
    $stmt = $pdo->query("SELECT * FROM achievements");
    $achievements = $stmt->fetchAll();

    foreach ($achievements as $achievement) {
        $shouldAward = false;

        switch ($achievement['criteria_type']) {
            case 'count':
                if ($stats['quiz_count'] >= $achievement['criteria_value']) {
                    $shouldAward = true;
                }
                break;

            case 'streak':
                if ($stats['current_streak'] >= $achievement['criteria_value']) {
                    $shouldAward = true;
                }
                break;

            case 'perfect':
                if ($trigger === 'quiz_complete' && isset($data['score']) && $data['score'] == 100) {
                    $shouldAward = true;
                }
                break;

            case 'speed':
                if ($trigger === 'quiz_complete' && isset($data['time_taken']) && $data['time_taken'] <= $achievement['criteria_value']) {
                    $shouldAward = true;
                }
                break;
        }

        if ($shouldAward) {
            awardAchievement($userId, $achievement['id']);
        }
    }
}

/**
 * Update user streak
 * @param int $userId User ID
 */
function updateStreak($userId)
{
    global $pdo;

    // Get last attempt date
    $stmt = $pdo->prepare("
        SELECT DATE(MAX(completed_at)) as last_date 
        FROM attempts 
        WHERE user_id = ? AND completed_at IS NOT NULL
    ");
    $stmt->execute([$userId]);
    $lastDate = $stmt->fetchColumn();

    if (!$lastDate)
        return;

    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($lastDate == $today) {
        // Already played today, no change
        return;
    } elseif ($lastDate == $yesterday) {
        // Played yesterday, increment streak
        $stmt = $pdo->prepare("UPDATE users SET current_streak = current_streak + 1 WHERE id = ?");
        $stmt->execute([$userId]);
    } else {
        // Streak broken, reset to 1
        $stmt = $pdo->prepare("UPDATE users SET current_streak = 1 WHERE id = ?");
        $stmt->execute([$userId]);
    }
}

/**
 * Sanitize input
 * @param string $input Input to sanitize
 * @return string Sanitized input
 */
function sanitize($input)
{
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF token
 * @return string CSRF token
 */
function generateCSRF()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool Valid or not
 */
function verifyCSRF($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Create alert message
 * @param string $message Message text
 * @param string $type Alert type (success, error, warning, info)
 * @return string HTML alert
 */
function createAlert($message, $type = 'info')
{
    $icons = [
        'success' => 'fa-check-circle',
        'error' => 'fa-exclamation-circle',
        'warning' => 'fa-exclamation-triangle',
        'info' => 'fa-info-circle'
    ];

    $icon = $icons[$type] ?? $icons['info'];

    return '
        <div class="alert alert-' . $type . ' shadow-lg mb-4">
            <i class="fas ' . $icon . '"></i>
            <span>' . e($message) . '</span>
        </div>
    ';
}
/**
 * Update or insert a setting
 * @param string $key Setting key
 * @param mixed $value Setting value
 * @param string $type Setting type
 * @return bool Success
 */
function updateSetting($key, $value, $type = 'string')
{
    global $pdo;

    // Convert value based on type
    if ($type === 'boolean') {
        $value = $value ? 'true' : 'false';
    } elseif ($type === 'json') {
        $value = json_encode($value);
    } else {
        $value = (string) $value;
    }

    // Check if setting exists
    $stmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $exists = $stmt->fetch();

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, setting_type = ? WHERE setting_key = ?");
        return $stmt->execute([$value, $type, $key]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)");
        return $stmt->execute([$key, $value, $type]);
    }
}