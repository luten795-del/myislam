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
$passed = $percent >= 50;

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
        * { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #f5f7f5;
            --surface: #ffffff;
            --surface-2: #f9fafb;
            --line: #e2e5e2;
            --text: #1f2937;
            --muted: #6b7280;

            --brand: #0E4A34;
            --brand-dark: #0A3626;
            --brand-soft: rgba(14, 74, 52, 0.08);

            --gold: #9C7A2E;
            --gold-soft: rgba(156, 122, 46, 0.10);

            --white: #ffffff;
            --danger: #b91c1c;
            --danger-soft: rgba(185, 28, 28, 0.08);

            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.08);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
        }

        body.dark-mode {
            --bg: #0f1720;
            --surface: #18212b;
            --surface-2: #1d2833;
            --line: #304150;
            --text: #edf2f7;
            --muted: #94a3b8;

            --brand: #4C9172;
            --brand-dark: #6bb590;
            --brand-soft: rgba(76, 145, 114, 0.16);

            --gold: #C9A85E;
            --gold-soft: rgba(201, 168, 94, 0.16);

            --danger: #ef4444;
            --danger-soft: rgba(239, 68, 68, 0.14);
        }

        body {
            font-family: system-ui, -apple-system, 'Segoe UI', 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            padding-bottom: 32px;
            transition: background-color .2s, color .2s;
        }

        a { text-decoration: none; color: inherit; }
        button { font: inherit; border: none; background: none; cursor: pointer; }

        /* ===== Top bar ===== */
        .app-header {
            position: sticky; top: 0; z-index: 100;
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            box-shadow: var(--shadow-sm);
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 24px;
        }
        .brand-box { font-size: 20px; font-weight: 700; color: var(--brand); }
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .icon-btn {
            height: 40px; min-width: 40px; padding: 0 12px; border-radius: 10px;
            background: var(--surface-2); border: 1px solid var(--line);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; color: var(--text); font-weight: 600; transition: all .2s;
        }
        .icon-btn:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); border-color: var(--gold); color: var(--gold); }

        .wrapper {
            max-width: 980px;
            margin: 24px auto 0;
            padding: 0 20px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: clamp(16px, 5vw, 24px);
            margin-bottom: 18px;
            transition: all .2s;
        }

        .meta {
            color: var(--muted);
            font-size: 14px;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(5,1fr);
            gap: 14px;
            margin-top: 18px;
        }

        .stat {
            background: var(--surface-2);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 16px;
            text-align: center;
        }

        .stat h3 {
            margin: 0 0 6px 0;
            font-size: 26px;
            color: var(--brand);
        }
        .stat div { color: var(--muted); font-size: 13px; font-weight: 600; }

        .pass-banner {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 18px; border-radius: var(--radius-md);
            margin-top: 16px; font-weight: 700; font-size: 15px;
        }
        .pass-banner.pass { background: var(--brand-soft); color: var(--brand); border: 1px solid var(--brand); }
        .pass-banner.fail { background: var(--danger-soft); color: var(--danger); border: 1px solid var(--danger); }

        .question {
            margin-bottom: 16px;
        }
        .question h3 { font-size: 16px; line-height: 1.5; }

        .option {
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            margin: 8px 0;
            background: var(--surface-2);
            font-size: 14.5px;
        }

        .correct {
            border-color: var(--brand);
            background: var(--brand-soft);
        }

        .selected-wrong {
            border-color: var(--danger);
            background: var(--danger-soft);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .badge.correct {
            background: var(--brand-soft);
            color: var(--brand);
        }

        .badge.wrong {
            background: var(--danger-soft);
            color: var(--danger);
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
            background: var(--accent-soft, rgba(62,110,145,.1));
            color: #3E6E91;
        }

        .mode-pill.competition {
            background: var(--gold-soft);
            color: var(--gold);
        }

        .competition-box {
            margin-top: 16px;
            padding: 16px;
            border-radius: var(--radius-md);
            border: 1px solid var(--gold);
            background: var(--gold-soft);
        }

        .competition-box h3 {
            margin: 0 0 8px 0;
            font-size: 15px;
            color: var(--gold);
        }

        .competition-box p {
            margin: 4px 0;
            color: var(--text);
            font-size: 14px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .actions a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            background: var(--brand);
            color: var(--white);
            padding: 12px 18px;
            border-radius: var(--radius-md);
            font-weight: 700;
            font-size: 14px;
            transition: all .2s;
        }
        .actions a:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); filter: brightness(1.05); }

        .actions a.secondary {
            background: var(--surface-2);
            color: var(--text);
            border: 1px solid var(--line);
        }
        .actions a.secondary:hover { border-color: var(--gold); color: var(--gold); }

        @media (max-width: 900px) {
            .stat-grid {
                grid-template-columns: repeat(2,1fr);
            }
        }

        @media (max-width: 500px) {
            .stat-grid {
                grid-template-columns: 1fr;
            }
            .app-header { padding: 12px 16px; }
        }
    </style>
</head>
<body>
<header class="app-header">
    <a href="../user_dashboard.php" class="brand-box">MyIslam</a>
    <div class="header-actions">
        <button type="button" class="icon-btn" id="themeToggle" title="Toggle dark mode">🌙</button>
        <a href="../quizzes.php" class="icon-btn">← Quizzes</a>
    </div>
</header>

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

        <div class="pass-banner <?php echo $passed ? 'pass' : 'fail'; ?>">
            <?php echo $passed ? '🎉 Well done — solid result!' : '📘 Keep practicing — you\'ll get there.'; ?>
        </div>

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

            <p class="meta" style="margin-top:8px;">
                Points: <?php echo (int)($question['points'] ?? 0); ?>
                <?php if ((int)($question['negative_points'] ?? 0) > 0): ?>
                    | Negative: <?php echo (int)$question['negative_points']; ?>
                <?php endif; ?>
                | <span class="badge <?php echo $isCorrectAnswer ? 'correct' : 'wrong'; ?>">
                    <?php echo $isCorrectAnswer ? 'Correct' : 'Wrong'; ?>
                  </span>
            </p>

            <div style="margin-top:12px;">
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
                        <strong> — Your Choice</strong>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card actions">
        <a href="../quizzes.php">← Back to Quizzes</a>
        <a class="secondary" href="../leaderboard.php?mode=<?php echo urlencode($quizMode === 'competition' ? 'competition' : 'lesson'); ?>">🏆 View Matching Leaderboard</a>
    </div>
</div>

<script>
(function() {
    const body = document.body;
    const themeToggle = document.getElementById('themeToggle');

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'dark') {
        body.classList.add('dark-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            const isDark = body.classList.contains('dark-mode');
            themeToggle.textContent = isDark ? '☀' : '🌙';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }
})();
</script>
</body>
</html>