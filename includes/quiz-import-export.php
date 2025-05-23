<?php
// includes/quiz-import-export.php

/**
 * Export quiz to JSON format
 * @param int $quizId Quiz ID
 * @return string JSON string
 */
function exportQuizToJSON($quizId)
{
    global $pdo;

    // Get quiz details
    $stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        throw new Exception('Quiz not found');
    }

    // Get questions
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY order_index");
    $stmt->execute([$quizId]);
    $questions = $stmt->fetchAll();

    // Get options for each question
    foreach ($questions as &$question) {
        $stmt = $pdo->prepare("SELECT * FROM options WHERE question_id = ? ORDER BY order_index");
        $stmt->execute([$question['id']]);
        $question['options'] = $stmt->fetchAll();
    }

    // Build export data
    $exportData = [
        'version' => '1.0',
        'exported_at' => date('c'),
        'quiz' => [
            'title' => $quiz['title'],
            'description' => $quiz['description'],
            'subject_id' => $quiz['subject_id'],
            'grade' => $quiz['grade'],
            'difficulty' => $quiz['difficulty'],
            'time_limit' => $quiz['time_limit'],
            'language' => $quiz['language'],
            'shuffle_questions' => $quiz['shuffle_questions'],
            'shuffle_answers' => $quiz['shuffle_answers'],
            'show_results' => $quiz['show_results'],
            'is_practice' => $quiz['is_practice']
        ],
        'questions' => array_map(function ($q) {
            return [
                'text' => $q['question_text'],
                'type' => $q['question_type'],
                'points' => $q['points'],
                'image' => $q['question_image'],
                'options' => array_map(function ($o) {
                    return [
                        'text' => $o['option_text'],
                        'is_correct' => (bool) $o['is_correct']
                    ];
                }, $q['options'])
            ];
        }, $questions)
    ];

    return json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Import quiz from JSON
 * @param string $jsonData JSON string
 * @param int $teacherId Teacher ID
 * @return int New quiz ID
 */
function importQuizFromJSON($jsonData, $teacherId)
{
    global $pdo;

    $data = json_decode($jsonData, true);

    if (!$data || !isset($data['version']) || !isset($data['quiz']) || !isset($data['questions'])) {
        throw new Exception('Invalid quiz format');
    }

    $pdo->beginTransaction();

    try {
        // Create quiz
        $quiz = $data['quiz'];
        $pin_code = generatePIN();

        $stmt = $pdo->prepare("
            INSERT INTO quizzes (
                teacher_id, title, description, subject_id, grade, 
                difficulty, time_limit, language, pin_code,
                shuffle_questions, shuffle_answers, show_results, is_practice
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $teacherId,
            $quiz['title'] . ' (مستورد)',
            $quiz['description'],
            $quiz['subject_id'],
            $quiz['grade'],
            $quiz['difficulty'],
            $quiz['time_limit'],
            $quiz['language'],
            $pin_code,
            $quiz['shuffle_questions'] ?? 0,
            $quiz['shuffle_answers'] ?? 0,
            $quiz['show_results'] ?? 1,
            $quiz['is_practice'] ?? 0
        ]);

        $quizId = $pdo->lastInsertId();

        // Add questions
        foreach ($data['questions'] as $qIndex => $question) {
            $stmt = $pdo->prepare("
                INSERT INTO questions (quiz_id, question_text, question_type, points, order_index)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $quizId,
                $question['text'],
                $question['type'] ?? 'multiple_choice',
                $question['points'] ?? 1,
                $qIndex + 1
            ]);

            $questionId = $pdo->lastInsertId();

            // Add options
            if (isset($question['options'])) {
                foreach ($question['options'] as $oIndex => $option) {
                    $stmt = $pdo->prepare("
                        INSERT INTO options (question_id, option_text, is_correct, order_index)
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $questionId,
                        $option['text'],
                        $option['is_correct'] ? 1 : 0,
                        $oIndex + 1
                    ]);
                }
            }
        }

        $pdo->commit();
        return $quizId;

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Export quiz to CSV format (questions only)
 * @param int $quizId Quiz ID
 * @return string CSV content
 */
function exportQuizToCSV($quizId)
{
    global $pdo;

    // Get quiz questions with options
    $stmt = $pdo->prepare("
        SELECT q.question_text, q.points,
               GROUP_CONCAT(
                   CONCAT(o.option_text, IF(o.is_correct, ' (صحيح)', ''))
                   ORDER BY o.order_index SEPARATOR '|||'
               ) as options
        FROM questions q
        LEFT JOIN options o ON q.id = o.question_id
        WHERE q.quiz_id = ?
        GROUP BY q.id
        ORDER BY q.order_index
    ");
    $stmt->execute([$quizId]);
    $questions = $stmt->fetchAll();

    // Build CSV
    $csv = "السؤال,النقاط,الخيار أ,الخيار ب,الخيار ج,الخيار د\n";

    foreach ($questions as $question) {
        $options = explode('|||', $question['options']);
        $row = [
            $question['question_text'],
            $question['points']
        ];

        // Add up to 4 options
        for ($i = 0; $i < 4; $i++) {
            $row[] = $options[$i] ?? '';
        }

        $csv .= '"' . implode('","', array_map('addslashes', $row)) . "\"\n";
    }

    return $csv;
}

/**
 * Import quiz from Excel/CSV file
 * @param array $file $_FILES array element
 * @param int $teacherId Teacher ID
 * @param array $quizInfo Basic quiz information
 * @return int New quiz ID
 */
function importQuizFromFile($file, $teacherId, $quizInfo)
{
    global $pdo;

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($extension === 'csv') {
        // Parse CSV
        $content = file_get_contents($file['tmp_name']);
        $lines = explode("\n", $content);
        $questions = [];

        // Skip header
        for ($i = 1; $i < count($lines); $i++) {
            $line = str_getcsv($lines[$i]);
            if (count($line) < 3)
                continue;

            $question = [
                'text' => $line[0],
                'points' => (int) ($line[1] ?? 1),
                'options' => []
            ];

            // Parse options (columns 2-5)
            for ($j = 2; $j < min(6, count($line)); $j++) {
                if (!empty($line[$j])) {
                    $isCorrect = strpos($line[$j], '(صحيح)') !== false;
                    $optionText = str_replace(' (صحيح)', '', $line[$j]);

                    $question['options'][] = [
                        'text' => $optionText,
                        'is_correct' => $isCorrect
                    ];
                }
            }

            if (!empty($question['options'])) {
                $questions[] = $question;
            }
        }

        // Create quiz
        $jsonData = json_encode([
            'version' => '1.0',
            'quiz' => $quizInfo,
            'questions' => $questions
        ]);

        return importQuizFromJSON($jsonData, $teacherId);

    } else {
        throw new Exception('Unsupported file format');
    }
}

// Usage example in teacher/quizzes/import.php:
/*
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['quiz_file'])) {
        try {
            $quizInfo = [
                'title' => $_POST['title'],
                'description' => $_POST['description'],
                'subject_id' => $_POST['subject_id'],
                'grade' => $_POST['grade'],
                'difficulty' => $_POST['difficulty'],
                'time_limit' => $_POST['time_limit'],
                'language' => 'ar'
            ];
            
            $newQuizId = importQuizFromFile($_FILES['quiz_file'], $_SESSION['user_id'], $quizInfo);
            redirect("/teacher/quizzes/edit.php?id=$newQuizId");
            
        } catch (Exception $e) {
            $error = 'فشل استيراد الاختبار: ' . $e->getMessage();
        }
    }
}
*/

// Usage example for export in teacher/quizzes/export.php:
/*
if (isset($_GET['id']) && isset($_GET['format'])) {
    $quizId = (int)$_GET['id'];
    $format = $_GET['format'];
    
    // Verify ownership
    $stmt = $pdo->prepare("SELECT title FROM quizzes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$quizId, $_SESSION['user_id']]);
    $quiz = $stmt->fetch();
    
    if ($quiz) {
        try {
            if ($format === 'json') {
                $content = exportQuizToJSON($quizId);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $quiz['title'] . '.json"');
                echo $content;
                exit;
                
            } elseif ($format === 'csv') {
                $content = exportQuizToCSV($quizId);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $quiz['title'] . '.csv"');
                echo "\xEF\xBB\xBF"; // UTF-8 BOM
                echo $content;
                exit;
            }
        } catch (Exception $e) {
            die('Export failed: ' . $e->getMessage());
        }
    }
}
*/