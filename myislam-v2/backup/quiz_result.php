<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/includes/quiz_engine.php';

$userId = requireUserAuth();
$conn = db();

if (!$conn instanceof PDO) {
    exit('Database connection failed.');
}

$attemptId = isset($_GET['attempt_id']) ? (int)$_GET['attempt_id'] : 0;
if ($attemptId <= 0) {
    exit('Invalid attempt ID.');
}

$review = getAttemptReview($conn, $attemptId, $userId);
if (!$review) {
    exit('Result not found.');
}

$attempt = $review['attempt'];
$quiz = $review['quiz'];
$questions = $review['questions'];
$quizMode = strtolower((string)($review['mode'] ?? getQuizMode($quiz)));
$competitionWindow = $review['competition_window'] ?? null;

$score = (int)($attempt['score'] ?? 0);
$totalQuestions = (int)($attempt['total_questions'] ?? count($questions));
$correctAnswers = (int)($attempt['correct_answers'] ?? 0);
$wrongAnswers = (int)($attempt['wrong_answers'] ?? 0);
$durationSeconds = (int)($attempt['duration_seconds'] ?? ($attempt['time_spent_seconds'] ?? 0));

$percent = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0;

function resultBadge(bool $isCorrect): string
{
    return $isCorrect ? 'Correct' : 'Wrong';
}

function formatDurationValue(int $seconds): string
{
    if ($seconds <= 0) {
        return '0s';
    }

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm ' . $secs . 's';
    }

    if ($minutes > 0) {
        return $minutes . 'm ' . $secs . 's';
    }

    return $secs . 's';
}

function getQuestionCorrectOptions(array $question): array
{
    $correct = [];

    foreach (($question['options'] ?? []) as $option) {
        if (isset($option['is_correct']) && (int)$option['is_correct'] === 1) {
            $correct[] = $option;
        }
    }

    return $correct;
}

function getSelectedOption(array $question, int $selectedOptionId): ?array
{
    if ($selectedOptionId <= 0) {
        return null;
    }

    foreach (($question['options'] ?? []) as $option) {
        if ((int)($option['id'] ?? 0) === $selectedOptionId) {
            return $option;
        }
    }

    return null;
}

$modeLabel = ucfirst($quizMode);
$modeClass = $quizMode === 'competition' ? 'competition' : 'lesson';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo quizE((string)($quiz['title'] ?? 'Quiz Result')); ?> - Result</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f7f5;
            color: #1f2937;
            margin: 0;
            padding: 24px;
        }

        .wrapper {
            max-width: 980px;
            margin: 0 auto;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 18px;
        }

        .meta {
            color: #6b7280;
            font-size: 14px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(5,1fr);
            gap: 16px;
            margin-top: 16px;
        }

        .stat {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 16px;
            text-align: center;
        }

        .stat h3 {
            margin: 0 0 6px 0;
            font-size: 26px;
            color: #166534;
        }

        .question {
            margin-bottom: 18px;
        }

        .option {
            padding: 10px 12px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            margin: 8px 0;
            background: #fafafa;
        }

        .correct {
            border-color: #16a34a;
            background: #f0fdf4;
        }

        .selected-wrong {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge.correct {
            background: #dcfce7;
            color: #166534;
        }

        .badge.wrong {
            background: #fee2e2;
            color: #991b1b;
        }

        .mode-pill {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .mode-pill.lesson {
            background: #e0f2fe;
            color: #0369a1;
        }

        .mode-pill.competition {
            background: #fef3c7;
            color: #b45309;
        }

        .competition-box {
            margin-top: 14px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid #f59e0b;
            background: #fffbeb;
        }

        .competition-box h3 {
            margin: 0 0 8px 0;
            font-size: 15px;
            color: #92400e;
        }

        .competition-box p {
            margin: 4px 0;
            color: #78350f;
            font-size: 14px;
        }

        .review-box {
            margin-top: 14px;
            padding: 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .review-title {
            font-weight: 700;
            margin-bottom: 8px;
            color: #0f172a;
        }

        .review-line {
            margin: 6px 0;
            font-size: 14px;
        }

        .actions a {
            display: inline-block;
            margin-right: 10px;
            text-decoration: none;
            background: #166534;
            color: #fff;
            padding: 12px 16px;
            border-radius: 12px;
        }

        .actions a.secondary {
            background: #f9fafb;
            color: #1f2937;
            border: 1px solid #d1d5db;
        }

        @media (max-width: 900px) {
            .stat-grid {
                grid-template-columns: repeat(2,1fr);
            }
        }

        @media (max-width: 500px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="card">
        <div class="mode-pill <?php echo quizE($modeClass); ?>">
            <?php echo quizE($modeLabel); ?> Mode
        </div>

        <h1><?php echo quizE((string)($quiz['title'] ?? 'Quiz Result')); ?></h1>
        <p class="meta">
            Subject: <?php echo quizE((string)($quiz['subject_name'] ?? 'Unknown')); ?>
            <?php if (!empty($quiz['category_name'])): ?>
                | Category: <?php echo quizE((string)$quiz['category_name']); ?>
            <?php endif; ?>
            | Difficulty: <?php echo quizE(ucfirst((string)($quiz['difficulty'] ?? ''))); ?>
        </p>

        <?php if ($quizMode === 'competition' && !empty($competitionWindow['label'])): ?>
            <div class="competition-box">
                <h3>🏁 Weekly Competition Result</h3>
                <p><strong>Competition Week:</strong> <?php echo quizE((string)$competitionWindow['label']); ?></p>
                <p><strong>Mode:</strong> Competition leaderboard result recorded</p>
            </div>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat">
                <h3><?php echo $score; ?></h3>
                <div>Score</div>
            </div>
            <div class="stat">
                <h3><?php echo $correctAnswers; ?>/<?php echo $totalQuestions; ?></h3>
                <div>Correct</div>
            </div>
            <div class="stat">
                <h3><?php echo $wrongAnswers; ?></h3>
                <div>Wrong</div>
            </div>
            <div class="stat">
                <h3><?php echo $percent; ?>%</h3>
                <div>Percentage</div>
            </div>
            <div class="stat">
                <h3><?php echo quizE(formatDurationValue($durationSeconds)); ?></h3>
                <div>Time Spent</div>
            </div>
        </div>
    </div>

    <?php foreach ($questions as $index => $question): ?>
        <?php
        $attemptAnswer = $question['attempt_answer'] ?? null;
        $selectedOptionId = isset($attemptAnswer['selected_option_id']) ? (int)$attemptAnswer['selected_option_id'] : 0;
        $isCorrectAnswer = isset($attemptAnswer['is_correct']) && (int)$attemptAnswer['is_correct'] === 1;
        $selectedOption = getSelectedOption($question, $selectedOptionId);
        $correctOptions = getQuestionCorrectOptions($question);

        $correctAnswerText = [];
        foreach ($correctOptions as $correctOption) {
            $correctAnswerText[] = (string)($correctOption['option_text'] ?? '');
        }

        $yourAnswerText = $selectedOption ? (string)($selectedOption['option_text'] ?? '') : 'No answer selected';
        ?>
        <div class="card question">
            <h3>
                Q<?php echo $index + 1; ?>.
                <?php echo quizE((string)($question['question_text'] ?? '')); ?>
            </h3>

            <p class="meta">
                Points: <?php echo (int)($question['points'] ?? 0); ?>
                <?php if ((int)($question['negative_points'] ?? 0) > 0): ?>
                    | Negative: <?php echo (int)$question['negative_points']; ?>
                <?php endif; ?>
                | <span class="badge <?php echo $isCorrectAnswer ? 'correct' : 'wrong'; ?>">
                    <?php echo $isCorrectAnswer ? 'Correct' : 'Wrong'; ?>
                  </span>
            </p>

            <?php foreach (($question['options'] ?? []) as $option): ?>
                <?php
                $optionId = (int)($option['id'] ?? 0);
                $classes = 'option';

                if (isset($option['is_correct']) && (int)$option['is_correct'] === 1) {
                    $classes .= ' correct';
                }

                if ($selectedOptionId === $optionId && (!isset($option['is_correct']) || (int)$option['is_correct'] !== 1)) {
                    $classes .= ' selected-wrong';
                }
                ?>
                <div class="<?php echo $classes; ?>">
                    <?php echo quizE((string)($option['option_text'] ?? '')); ?>
                    <?php if ($selectedOptionId === $optionId): ?>
                        <strong> — Your choice</strong>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

          
               

           
        </div>
    <?php endforeach; ?>

    <div class="card actions">
        <a href="../quizzes.php">Back to Quizzes</a>
        <a class="secondary" href="../leaderboard.php?mode=<?php echo urlencode($quizMode === 'competition' ? 'competition' : 'lesson'); ?>">View Matching Leaderboard</a>
    </div>
</div>
</body>
</html>