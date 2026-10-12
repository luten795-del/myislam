<?php
declare(strict_types=1);
session_start();

// Set default language if not set
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

// ============================================================================
// ERROR REPORTING
// ============================================================================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================
$databaseConfigFile = __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

if (!file_exists($databaseConfigFile)) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('System configuration missing. Please contact administrator.');
}

require_once $databaseConfigFile;

// ============================================================================
// HELPERS
// ============================================================================
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'U';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }

    return $letters !== '' ? $letters : 'U';
}

function truncateText(string $text, int $length = 160): string
{
    $text = trim(strip_tags($text));
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length) . '...';
}

function formatDate(?string $date, string $format = 'F j, Y'): string
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '';
}

function getReadingTime(string $text): int
{
    $wordCount = str_word_count(strip_tags($text));
    $minutes = (int)ceil($wordCount / 200);
    return max(1, $minutes);
}

/**
 * The database is returning text_color = #1f2937 and background_color =
 * #ffffff for essentially every section, including ones the admin never
 * touched the color picker for - these are this theme's own light-mode
 * default --text/--surface values, saved as literal column values instead
 * of being left null. content.php has no way to tell "admin deliberately
 * picked this exact pair" apart from "this is just the untouched default",
 * so this treats that specific pair as NOT a real customization, letting
 * dark mode override it like any other uncolored section. Any other color
 * an admin actually picks is unaffected and still honored as authored.
 */
function isUntouchedDefaultColorPair(?string $textColor, ?string $bgColor): bool
{
    $normalize = static fn(?string $c) => $c ? strtolower(trim($c)) : '';
    return $normalize($textColor) === '#1f2937' && $normalize($bgColor) === '#ffffff';
}

/**
 * Whether this section/span has a text color that should be honored as
 * genuinely admin-chosen in both themes. False for no saved color at all,
 * and false for the untouched-default pair above (see its docblock).
 */
function hasCustomTextColor(?string $textColor, ?string $bgColor): bool
{
    if (!$textColor || $textColor === '') {
        return false;
    }
    if (isUntouchedDefaultColorPair($textColor, $bgColor)) {
        return false;
    }
    return true;
}

/**
 * A saved text color is only "self-contained" (safe to show as-authored in
 * both themes) when it was chosen alongside its own background color - the
 * pair travels together, so contrast is preserved no matter which theme the
 * reader is in. A dark text color saved WITHOUT a background sits on the
 * theme's own surface instead, which is light in light mode but dark in
 * dark mode - so a near-black color that was legible on the light surface
 * becomes invisible on the dark one. This flags exactly that case, so the
 * caller can fall back to the theme's readable text color in dark mode only,
 * while leaving every other saved color (colorful, light, or paired with a
 * background) untouched.
 */
function needsDarkModeTextFallback(?string $textColor, ?string $bgColor): bool
{
    if (!hasCustomTextColor($textColor, $bgColor)) {
        // Not a real customization - the .no-custom-text-color CSS path
        // already forces var(--text) on this element, so no extra flag needed.
        return false;
    }
    if ($bgColor && $bgColor !== '') {
        // Background travels with the text - contrast is self-contained.
        return false;
    }
    return isColorDark($textColor);
}

function isColorDark(string $color): bool
{
    $color = strtolower(trim($color));
    if ($color === '') {
        return false;
    }
    if ($color === 'black') {
        return true;
    }
    if ($color === 'white') {
        return false;
    }
    if ($color[0] !== '#') {
        // rgb()/hsl()/named colors we don't parse - leave the saved color alone.
        return false;
    }
    $hex = ltrim($color, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return false;
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance < 0.35;
}

// ============================================================================
// AUTH CHECK
// ============================================================================
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required&redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userId = (int)$_SESSION['user_id'];

// ============================================================================
// DB CONNECTION
// ============================================================================
try {
    $conn = db();
    if (!$conn instanceof PDO) {
        throw new RuntimeException('Database connection failed.');
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $ex) {
    error_log('Database connection error: ' . $ex->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    exit('Unable to connect to database. Please try again later.');
}

function getCurrentUser(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, username, full_name, email, profile_image, role, is_active, is_verified, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("Error getting user: " . $e->getMessage());
        return null;
    }
}

// ============================================================================
// INPUTS
// ============================================================================
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$part = isset($_GET['part']) ? max(1, (int)$_GET['part']) : 1;
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';

if (empty($slug) && empty($id)) {
    header('Location: subjects.php?error=no_content_specified');
    exit;
}

// ============================================================================
// FETCH CONTENT
// ============================================================================
$content = null;

try {
    if (!empty($slug)) {
        $stmt = $conn->prepare("
            SELECT
                c.*,
                cat.name AS category_name,
                cat.slug AS category_slug,
                s.name AS subject_name,
                s.slug AS subject_slug,
                s.icon AS subject_icon,
                u.full_name AS author_name
            FROM contents c
            LEFT JOIN categories cat
                ON cat.id = c.category_id
               AND (cat.is_active = 1 OR cat.is_active IS NULL)
            LEFT JOIN subjects s
                ON s.id = c.subject_id
               AND (s.is_active = 1 OR s.is_active IS NULL)
            LEFT JOIN users u
                ON u.id = c.created_by
            WHERE c.slug = ?
              AND c.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$slug]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$content && !empty($id)) {
        $stmt = $conn->prepare("
            SELECT
                c.*,
                cat.name AS category_name,
                cat.slug AS category_slug,
                s.name AS subject_name,
                s.slug AS subject_slug,
                s.icon AS subject_icon,
                u.full_name AS author_name
            FROM contents c
            LEFT JOIN categories cat
                ON cat.id = c.category_id
               AND (cat.is_active = 1 OR cat.is_active IS NULL)
            LEFT JOIN subjects s
                ON s.id = c.subject_id
               AND (s.is_active = 1 OR s.is_active IS NULL)
            LEFT JOIN users u
                ON u.id = c.created_by
            WHERE c.id = ?
              AND c.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $ex) {
    error_log('Error fetching content: ' . $ex->getMessage());
    exit('Error loading content. Please try again.');
}

if (!$content) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Content Not Found</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: Arial, sans-serif;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 20px;
            }
            .card {
                background: #fff;
                border-radius: 20px;
                padding: 40px;
                text-align: center;
                max-width: 520px;
                box-shadow: 0 20px 40px rgba(0,0,0,.15);
            }
            .card h1 { margin-bottom: 12px; color: #1f2937; }
            .card p { color: #6b7280; margin-bottom: 20px; line-height: 1.6; }
            .btn {
                display: inline-block;
                background: #166534;
                color: #fff;
                text-decoration: none;
                padding: 12px 20px;
                border-radius: 10px;
                font-weight: 600;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Content Not Found</h1>
            <p>The requested content does not exist, has not been published, or was removed.</p>
            <a href="subjects.php" class="btn">Browse Subjects</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================================
// FETCH CONTENT PARTS
// ============================================================================
$contentParts = [];
$hasParts = false;

try {
    $stmt = $conn->prepare("
        SELECT id, content_id, part_number, title, 
               arabic_body, arabic_body_html,
               swahili_body, swahili_body_html,
               section_order, audio_path, video_path, video_url, sort_order, display_mode
        FROM content_parts
        WHERE content_id = ?
        ORDER BY part_number ASC
    ");
    $stmt->execute([(int)$content['id']]);
    $contentParts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasParts = !empty($contentParts);
    
    foreach ($contentParts as &$partData) {
        if (!empty($partData['arabic_body'])) {
            $partData['arabic_body'] = html_entity_decode($partData['arabic_body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!empty($partData['arabic_body_html'])) {
            $partData['arabic_body_html'] = html_entity_decode($partData['arabic_body_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!empty($partData['swahili_body'])) {
            $partData['swahili_body'] = html_entity_decode($partData['swahili_body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!empty($partData['swahili_body_html'])) {
            $partData['swahili_body_html'] = html_entity_decode($partData['swahili_body_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!isset($partData['display_mode'])) {
            $partData['display_mode'] = 'sections';
        }
    }
    unset($partData);
    
} catch (Throwable $ex) {
    error_log('Error fetching content parts: ' . $ex->getMessage());
}

// ============================================================================
// FETCH PART SECTIONS
// ============================================================================
function getPartSections(PDO $conn, int $partId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT id, part_id, section_number, 
                   content, content_html,
                   language, direction, sort_order,
                   text_color, background_color
            FROM part_sections
            WHERE part_id = ?
            ORDER BY sort_order ASC, section_number ASC
        ");
        $stmt->execute([$partId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($sections as &$section) {
            if (!empty($section['content'])) {
                $section['content'] = html_entity_decode($section['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!empty($section['content_html'])) {
                $section['content_html'] = html_entity_decode($section['content_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        
        return $sections;
    } catch (PDOException $e) {
        error_log("getPartSections: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// FETCH LESSON QUIZ
// ============================================================================
function getLessonQuiz(PDO $conn, int $contentId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT q.*
            FROM quizzes q
            WHERE q.content_id = ?
              AND q.mode = 'lesson'
              AND q.status = 'published'
            ORDER BY q.id DESC
            LIMIT 1
        ");
        $stmt->execute([$contentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log("getLessonQuiz: " . $e->getMessage());
        return null;
    }
}

function getQuizQuestions(PDO $conn, int $quizId): array
{
    try {
        $stmt = $conn->prepare("
            SELECT
                qq.id,
                qq.quiz_id,
                qq.question_text,
                qq.question_type,
                qq.points,
                qq.negative_points,
                qq.difficulty,
                qq.explanation,
                qq.image_path,
                qq.sort_order,
                qq.expected_answer,
                qq.keywords_json
            FROM quiz_questions qq
            WHERE qq.quiz_id = ?
            ORDER BY qq.sort_order ASC, qq.id ASC
        ");
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Options are only relevant for choice-based questions — 'explanation'
        // questions are graded from expected_answer/keywords_json instead and
        // have no rows in quiz_options at all.
        $nonExplanationIds = [];
        foreach ($questions as $q) {
            if ($q['question_type'] !== 'explanation') {
                $nonExplanationIds[] = (int)$q['id'];
            }
        }

        if (!empty($nonExplanationIds)) {
            $placeholders = implode(',', array_fill(0, count($nonExplanationIds), '?'));
            $stmt = $conn->prepare("
                SELECT
                    qo.id,
                    qo.question_id,
                    qo.option_text,
                    qo.image_path,
                    qo.is_correct,
                    qo.sort_order
                FROM quiz_options qo
                WHERE qo.question_id IN ($placeholders)
                ORDER BY qo.question_id ASC, qo.sort_order ASC, qo.id ASC
            ");
            $stmt->execute($nonExplanationIds);
            $allOptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $optionsByQuestion = [];
            foreach ($allOptions as $opt) {
                $optionsByQuestion[(int)$opt['question_id']][] = $opt;
            }

            foreach ($questions as &$question) {
                $qid = (int)$question['id'];
                $question['options'] = ($question['question_type'] !== 'explanation')
                    ? ($optionsByQuestion[$qid] ?? [])
                    : [];
            }
            unset($question);
        } else {
            foreach ($questions as &$question) {
                $question['options'] = [];
            }
            unset($question);
        }

        return $questions;
    } catch (PDOException $e) {
        error_log("getQuizQuestions: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// BUILD CONTENT PARTS WITH SECTIONS
// ============================================================================
$totalParts = count($contentParts);
$currentPart = min(max($part, 1), $totalParts);

// If no parts exist, create a default part (legacy support)
if (!$hasParts) {
    $contentParts = [[
        'id' => 0,
        'part_number' => 1,
        'title' => $content['title'] ?? 'Lesson',
        'arabic_body' => $content['arabic_text'] ?? '',
        'arabic_body_html' => null,
        'swahili_body' => $content['body'] ?? '',
        'swahili_body_html' => $content['body_html'] ?? '',
        'section_order' => 'swahili_first',
        'audio_path' => $content['audio_path'] ?? null,
        'video_path' => $content['video_path'] ?? null,
        'video_url' => $content['video_url'] ?? null,
        'display_mode' => 'sections',
    ]];
    $totalParts = 1;
    $currentPart = 1;
}

$currentPartData = $contentParts[$currentPart - 1];

// Get display mode (default: 'sections')
$displayMode = $currentPartData['display_mode'] ?? 'sections';

// Get sections for the current part
$partSections = [];
$partId = (int)($currentPartData['id'] ?? 0);

if ($partId > 0) {
    $partSections = getPartSections($conn, $partId);
}

// If no sections found, create a default section from the part data
if (empty($partSections)) {
    $defaultContent = '';
    $defaultDirection = 'ltr';
    $defaultLanguage = 'swahili';
    $defaultTextColor = null;
    $defaultBgColor = null;
    
    // Check if we have Arabic or Swahili content in the part
    if (!empty($currentPartData['arabic_body_html'])) {
        $defaultContent = $currentPartData['arabic_body_html'];
        $defaultDirection = 'rtl';
        $defaultLanguage = 'arabic';
    } elseif (!empty($currentPartData['swahili_body_html'])) {
        $defaultContent = $currentPartData['swahili_body_html'];
        $defaultDirection = 'ltr';
        $defaultLanguage = 'swahili';
    } elseif (!empty($currentPartData['arabic_body'])) {
        $defaultContent = nl2br(e($currentPartData['arabic_body']));
        $defaultDirection = 'rtl';
        $defaultLanguage = 'arabic';
    } elseif (!empty($currentPartData['swahili_body'])) {
        $defaultContent = nl2br(e($currentPartData['swahili_body']));
        $defaultDirection = 'ltr';
        $defaultLanguage = 'swahili';
    }
    
    $partSections[] = [
        'id' => 0,
        'section_number' => 1,
        'content' => $defaultContent,
        'content_html' => $defaultContent,
        'language' => $defaultLanguage,
        'direction' => $defaultDirection,
        'sort_order' => 1,
        'text_color' => $defaultTextColor,
        'background_color' => $defaultBgColor
    ];
}

// Fallback for audio/video paths
if (empty($currentPartData['audio_path'])) {
    $currentPartData['audio_path'] = $content['audio_path'] ?? null;
}
if (empty($currentPartData['video_path'])) {
    $currentPartData['video_path'] = $content['video_path'] ?? null;
}
if (empty($currentPartData['video_url'])) {
    $currentPartData['video_url'] = $content['video_url'] ?? null;
}

// Additional videos for this part, beyond the single primary
// video_path/video_url above - see part_videos.
$currentPartExtraVideos = [];
if ((int)$currentPartData['id'] > 0) {
    try {
        $stmt = $conn->prepare("
            SELECT id, video_path, video_url, caption, sort_order
            FROM part_videos
            WHERE part_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$currentPartData['id']]);
        $currentPartExtraVideos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        error_log('Error fetching part_videos: ' . $ex->getMessage());
    }
}

// ============================================================================
// PREPARE RENDERED CONTENT BASED ON DISPLAY MODE
// ============================================================================

$renderedSections = [];
$combinedContent = '';
$hasRTL = false;
$hasLTR = false;

foreach ($partSections as $section) {
    $direction = $section['direction'] ?? 'ltr';
    $contentHtml = !empty($section['content_html']) ? $section['content_html'] : nl2br(e($section['content'] ?? ''));
    $textColor = $section['text_color'] ?? null;
    $bgColor = $section['background_color'] ?? null;
    
    if ($direction === 'rtl') {
        $hasRTL = true;
    } else {
        $hasLTR = true;
    }
    
    $renderedSections[] = [
        'direction' => $direction,
        'content' => $contentHtml,
        'language' => $section['language'] ?? 'swahili',
        'section_number' => $section['section_number'] ?? 0,
        'raw_content' => $section['content'] ?? '',
        'text_color' => $textColor,
        'background_color' => $bgColor
    ];
}

// Build combined content for mixed RTL/LTR display
foreach ($renderedSections as $renderedSection) {
    $dir = $renderedSection['direction'];
    $sectionContent = $renderedSection['content'];
    $textColor = $renderedSection['text_color'] ?? null;
    $bgColor = $renderedSection['background_color'] ?? null;
    
    $style = '';
    if ($textColor && $textColor !== '') $style .= 'color: ' . $textColor . ';';
    // The untouched-default pair (#1f2937/#ffffff) is saved on essentially every
    // section regardless of whether the admin touched the color picker (see
    // isUntouchedDefaultColorPair() docblock). Inlining that white background
    // literally would pin the section to white even in dark mode, since an
    // inline style can't be swapped by the theme. Any other background an
    // admin actually picked is still honored as authored.
    if ($bgColor && $bgColor !== '' && !isUntouchedDefaultColorPair($textColor, $bgColor)) {
        $style .= 'background-color: ' . $bgColor . ';';
    }
    $styleAttr = $style ? ' style="' . $style . '"' : '';
    $hasTextColor = hasCustomTextColor($textColor, $bgColor);
    // Explicit flag instead of CSS sniffing the style string: "background-color"
    // itself contains the substring "color", so a [style*="color"] selector
    // would wrongly treat a bg-only span as having its own text color. Also
    // excludes the untouched #1f2937/#ffffff default pair - see
    // isUntouchedDefaultColorPair() - since the inline style keeps that
    // default in the markup regardless, the .no-custom-text-color CSS rule's
    // !important is what actually overrides it in dark mode.
    $colorFlagClass = $hasTextColor ? ' has-custom-text-color' : ' no-custom-text-color';
    $darkModeFallbackClass = needsDarkModeTextFallback($textColor, $bgColor) ? ' dark-mode-safe-text' : '';
    
    // Wrap each section in a span with proper direction
    if ($dir === 'rtl') {
        $combinedContent .= '<span class="mixed-rtl' . $colorFlagClass . $darkModeFallbackClass . '" dir="rtl" lang="ar"' . $styleAttr . '>' . $sectionContent . '</span> ';
    } else {
        $combinedContent .= '<span class="mixed-ltr' . $colorFlagClass . $darkModeFallbackClass . '" dir="ltr" lang="sw"' . $styleAttr . '>' . $sectionContent . '</span> ';
    }
}

// Calculate reading time from all sections
$allContentText = '';
foreach ($partSections as $section) {
    $allContentText .= ($section['content'] ?? '') . ' ';
}
$readingTime = getReadingTime($allContentText ?: ($currentPartData['swahili_body'] ?? $content['body'] ?? ''));

// ============================================================================
// USER PROGRESS
// ============================================================================
$userProgress = null;
$completionStatus = false;
$currentUserPart = 1;

try {
    $stmt = $conn->prepare("
        SELECT *
        FROM user_content_progress
        WHERE user_id = ?
          AND content_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, (int)$content['id']]);
    $userProgress = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($userProgress) {
        $completionStatus = (int)$userProgress['is_completed'] === 1;
        $currentUserPart = max(1, (int)($userProgress['current_part'] ?? 1));

        // Allow user to go to any part by clicking the progress steps
        // Only restrict if trying to skip ahead more than 1 part
        if ($currentPart > ($currentUserPart + 1) && !$completionStatus) {
            $redirectUrl = 'content.php?slug=' . urlencode($content['slug']) . '&part=' . $currentUserPart;
            header('Location: ' . $redirectUrl);
            exit;
        }
    }
} catch (Throwable $ex) {
    error_log('Error fetching user progress: ' . $ex->getMessage());
}

// Update progress for current part
try {
    if ($userProgress) {
        $stmt = $conn->prepare("
            UPDATE user_content_progress
            SET
                is_started = 1,
                current_part = ?,
                last_accessed_at = NOW(),
                updated_at = NOW()
            WHERE user_id = ?
              AND content_id = ?
        ");
        $stmt->execute([$currentPart, $userId, (int)$content['id']]);
    } else {
        // user_content_progress.id has no AUTO_INCREMENT/default in the
        // schema, so we compute it ourselves (same fix applied earlier to
        // quiz_attempts and leaderboard_stats). Without this, every user's
        // very first visit to a lesson silently failed to create a progress
        // row here, which is why completion tracking never worked.
        $nextProgressIdStmt = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 FROM user_content_progress");
        $nextProgressId = (int)$nextProgressIdStmt->fetchColumn();

        $stmt = $conn->prepare("
            INSERT INTO user_content_progress (
                id,
                user_id,
                content_id,
                subject_id,
                is_started,
                is_completed,
                current_part,
                progress_percent,
                started_at,
                last_accessed_at,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, 1, 0, ?, 0, NOW(), NOW(), NOW(), NOW())
        ");
        $stmt->execute([
            $nextProgressId,
            $userId,
            (int)$content['id'],
            !empty($content['subject_id']) ? (int)$content['subject_id'] : null,
            $currentPart
        ]);
    }
} catch (Throwable $ex) {
    error_log('Error updating progress: ' . $ex->getMessage());
}

// ============================================================================
// FETCH USER
// ============================================================================
$user = null;

try {
    $stmt = $conn->prepare("
        SELECT id, username, full_name, email, profile_image, role, created_at
        FROM users
        WHERE id = ?
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $ex) {
    error_log('Error fetching user: ' . $ex->getMessage());
}

$userDisplayName = $user['username'] ?? $user['full_name'] ?? 'User';
if (empty($userDisplayName) && !empty($user['email'])) {
    $emailParts = explode('@', $user['email']);
    $userDisplayName = $emailParts[0] ?? 'User';
}

// ============================================================================
// FETCH LESSON QUIZ
// ============================================================================
$quiz = null;
$quizQuestions = [];
$hasQuiz = false;
$quizPassed = false;
$attemptExists = false;
$latestAttemptData = null;
$completedAttemptCount = 0;
$remainingAttempts = null;
$canTakeQuiz = false;
$quizError = '';

try {
    // Get the lesson quiz bound directly to this content item
    $contentId = (int)$content['id'];
    $quiz = getLessonQuiz($conn, $contentId);

    if ($quiz) {
        $hasQuiz = true;
        $quizQuestions = getQuizQuestions($conn, (int)$quiz['id']);

        // Get user's quiz attempts
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_attempts
            FROM quiz_attempts
            WHERE user_id = ?
              AND quiz_id = ?
              AND status = 'completed'
        ");
        $stmt->execute([$userId, (int)$quiz['id']]);
        $completedAttemptCount = (int)$stmt->fetchColumn();

        $stmt = $conn->prepare("
            SELECT *
            FROM quiz_attempts
            WHERE user_id = ?
              AND quiz_id = ?
              AND status = 'completed'
            ORDER BY attempt_number DESC, completed_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, (int)$quiz['id']]);
        $latestAttempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($latestAttempt) {
            $attemptExists = true;
            $quizPassed = (int)$latestAttempt['passed'] === 1;

            $savedResults = [];
            if (!empty($latestAttempt['answers_json'])) {
                $decoded = json_decode((string)$latestAttempt['answers_json'], true);
                if (is_array($decoded)) {
                    $savedResults = $decoded;
                }
            }

            $latestAttemptData = [
                'attempt_id' => (int)$latestAttempt['id'],
                'attempt_number' => (int)$latestAttempt['attempt_number'],
                'score' => (int)$latestAttempt['score'],
                'total' => (int)$latestAttempt['total_points'],
                'percentage' => (float)$latestAttempt['percentage'],
                'passed' => $quizPassed,
                'correct_count' => (int)$latestAttempt['correct_answers'],
                'total_questions' => (int)$latestAttempt['total_questions'],
                'results' => $savedResults,
            ];
        }

        $unlimitedAttempts = (int)($quiz['unlimited_attempts'] ?? 0) === 1;
        $maxAttempts = max(1, (int)($quiz['max_attempts'] ?? 1));

        if ($unlimitedAttempts) {
            $remainingAttempts = null;
            $canTakeQuiz = !$quizPassed;
        } else {
            $remainingAttempts = max(0, $maxAttempts - $completedAttemptCount);
            $canTakeQuiz = !$quizPassed && $remainingAttempts > 0;
        }
    }
} catch (Throwable $ex) {
    error_log('Error fetching lesson quiz: ' . $ex->getMessage());
    $quizError = $ex->getMessage();
    $hasQuiz = false;
}

$unreadCount = 0;
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $unreadCount = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    // Table might not exist yet – ignore
}

try {
    $dbUser = getCurrentUser($conn, $userId);

    if (!$dbUser) {
        session_destroy();
        header('Location: login.php?error=account_disabled');
        exit;
    }
} catch (Throwable $e) {
    error_log("Error fetching user: " . $e->getMessage());
    die("Unable to load user data. Please try again later.");
}

$userInfo = [
    'name' => $userDisplayName,
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];

// ============================================================================
// PREPARE PAGE META
// ============================================================================
$pageTitle = e(($currentPartData['title'] ?? 'Lesson') . ' - ' . ($content['title'] ?? 'Content') . ' - MyIslam');
$hasAudio = !empty($currentPartData['audio_path']);
$hasVideo = !empty($currentPartData['video_path']) || !empty($currentPartData['video_url']) || !empty($currentPartExtraVideos);
$nextPart = $currentPart < $totalParts ? $currentPart + 1 : null;
$prevPart = $currentPart > 1 ? $currentPart - 1 : null;
$isLastPart = $currentPart === $totalParts;

// Admin-controlled completion lock (see admin_contents.php "Completion Lock"
// settings). Both default to enabled (1) if the columns aren't present yet
// (e.g. migration not run), so existing behavior is unaffected until an
// admin explicitly disables one.
$allowAutoCompletion = !array_key_exists('allow_auto_completion', $content) || (int)$content['allow_auto_completion'] === 1;
$allowManualCompletion = !array_key_exists('allow_manual_completion', $content) || (int)$content['allow_manual_completion'] === 1;

// Completion is only reachable when the relevant lock is open:
//   - quiz lessons complete automatically once passed, gated by allowAutoCompletion
//   - non-quiz lessons complete via the manual button, gated by allowManualCompletion
$completionBlocked = $hasQuiz ? !$allowAutoCompletion : !$allowManualCompletion;
$canComplete = $isLastPart && !$completionBlocked && (!$hasQuiz || $quizPassed);

// Count sections for display
$sectionCount = count($partSections);

// Determine display mode label
$modeLabel = ($displayMode === 'combined') ? 'Combined Paragraph' : 'Separate Sections';
$modeIcon = ($displayMode === 'combined') ? '📄' : '📋';

// Build content URL helper - ensure slug is always used and is a string
$contentSlug = !empty($content['slug']) ? (string)$content['slug'] : '';

// Determine the next lessons (up to 5) in the same subject so the user
// can be redirected to the next available lesson after completing this one.
// Include video fields and lock status. Fall back to the subject page or
// subjects list when no next lesson exists.
$nextContents = [];
$afterCompleteUrl = 'subjects.php';
$nextDebug = [];
try {
    if (!empty($content['subject_id'])) {
        $currentSort = isset($content['sort_order']) ? (int)$content['sort_order'] : 0;
        // Prefer ordering by explicit sort_order when available, fallback to id.
        $sql1 = "SELECT id, slug, title, category_id, video_path, video_url, COALESCE(sort_order, 0) AS sort_order
             FROM contents
             WHERE subject_id = ?
               AND status = 'published'
               AND (COALESCE(sort_order, 0) > ? OR (COALESCE(sort_order, 0) = ? AND id > ?))
             ORDER BY COALESCE(sort_order, 0) ASC, id ASC
             LIMIT 5";
        $nextDebug[] = ['sql' => $sql1, 'params' => [(int)$content['subject_id'], $currentSort, $currentSort, (int)$content['id']]];
        $stmt = $conn->prepare($sql1);
        $stmt->execute([(int)$content['subject_id'], $currentSort, $currentSort, (int)$content['id']]);
        $nextContents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $nextDebug[] = ['fetched' => count($nextContents)];

        // Fallback: if none found after current by sort_order, fetch up to 5 other contents in the subject
        // so the sidebar isn't empty. This still excludes the current content.
        if (empty($nextContents)) {
            // If nothing found strictly after current sort/id, return up to 5 other contents
            // in the same subject regardless of status so the sidebar can show them for
            // navigation/preview. These may include locked or unpublished items.
            $sql2 = "SELECT id, slug, title, category_id, video_path, video_url, COALESCE(sort_order, 0) AS sort_order
                 FROM contents
                 WHERE subject_id = ?
                   AND id != ?
                 ORDER BY COALESCE(sort_order, 0) ASC, id ASC
                 LIMIT 5";
            $nextDebug[] = ['sql' => $sql2, 'params' => [(int)$content['subject_id'], (int)$content['id']]];
            $stmt = $conn->prepare($sql2);
            $stmt->execute([(int)$content['subject_id'], (int)$content['id']]);
            $nextContents = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $nextDebug[] = ['fetched' => count($nextContents)];
        }

        // Compute lock status per next content based on category.lock_previous and user's progress
        foreach ($nextContents as &$nc) {
            $nc['locked'] = false;
            $nc['has_video'] = !empty($nc['video_path']) || !empty($nc['video_url']);

            // Default lock policy: require completion of the immediately previous content
            // in the same subject. If the previous content has not been completed by the
            // current user, mark this content as locked. If there is no previous content,
            // consider it unlocked.
            try {
                $prevStmt = $conn->prepare(
                    "SELECT id FROM contents WHERE subject_id = ? AND (COALESCE(sort_order,0) < ? OR (COALESCE(sort_order,0) = ? AND id < ?)) ORDER BY COALESCE(sort_order,0) DESC, id DESC LIMIT 1"
                );
                $prevStmt->execute([(int)$content['subject_id'], (int)$nc['sort_order'], (int)$nc['sort_order'], (int)$nc['id']]);
                $prev = $prevStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($prev && isset($prev['id'])) {
                    $progStmt = $conn->prepare("SELECT is_completed FROM user_content_progress WHERE user_id = ? AND content_id = ? LIMIT 1");
                    $progStmt->execute([$userId, (int)$prev['id']]);
                    $prog = $progStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$prog || (int)($prog['is_completed'] ?? 0) !== 1) {
                        $nc['locked'] = true;
                    }
                }
            } catch (PDOException $e) {
                error_log('Error checking previous content progress: ' . $e->getMessage());
            }
        }
        unset($nc);
    }
} catch (PDOException $e) {
    error_log("Error fetching next contents: " . $e->getMessage());
    $nextDebug[] = ['error' => $e->getMessage()];
}

// Additional debug counts for contents in this subject
$nextCountPublished = 0;
$nextCountAll = 0;
try {
    if (!empty($content['subject_id'])) {
        $countStmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE subject_id = ? AND status = 'published'");
        $countStmt->execute([(int)$content['subject_id']]);
        $nextCountPublished = (int)$countStmt->fetchColumn();

        $countAllStmt = $conn->prepare("SELECT COUNT(*) FROM contents WHERE subject_id = ?");
        $countAllStmt->execute([(int)$content['subject_id']]);
        $nextCountAll = (int)$countAllStmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("Error counting next contents: " . $e->getMessage());
}

// Choose the first unlocked next content for redirect (if any)
$firstAvailable = null;
foreach ($nextContents as $nc) {
    if (empty($nc['is_locked']) || (int)$nc['is_locked'] === 0) {
        $firstAvailable = $nc;
        break;
    }
}

if (!empty($firstAvailable)) {
    if (!empty($firstAvailable['slug'])) {
        $afterCompleteUrl = 'content.php?slug=' . urlencode((string)$firstAvailable['slug']);
    } else {
        $afterCompleteUrl = 'content.php?id=' . (int)$firstAvailable['id'];
    }
} elseif (!empty($content['subject_slug'])) {
    $afterCompleteUrl = 'subject.php?slug=' . urlencode((string)$content['subject_slug']);
} elseif (!empty($content['subject_id'])) {
    $afterCompleteUrl = 'subject.php?id=' . (int)$content['subject_id'];
} else {
    $afterCompleteUrl = 'subjects.php';
}

$contentIdParam = !empty($content['id']) ? (int)$content['id'] : 0;

$mainNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];
?>
<?php
// Page-specific values for the shared header include.
// $pageTitle is already computed above; the rest describe this lesson.
$metaDescription = $content['short_description'] ?? '';
$metaKeywords    = $content['tags'] ?? 'learning';
$metaAuthor      = $content['author_name'] ?? 'MyIslam';
$ogType          = 'article';

require __DIR__ . '/includes/header.php';
?>
<style>
    /* Offline video notice: shown instead of the player when there's no network */
    .video-offline-notice {
        display: none;
        align-items: center;
        gap: 14px;
        padding: 20px;
        border-radius: 14px;
        border: 1px solid var(--line);
        background: var(--surface-2);
    }
    .video-offline-notice .video-offline-icon {
        font-size: 30px;
        line-height: 1;
        flex-shrink: 0;
    }
    .video-offline-notice .video-offline-msg strong {
        display: block;
        margin-bottom: 4px;
        color: var(--danger);
        font-size: 15px;
        font-weight: 700;
    }
    .video-offline-notice .video-offline-msg span {
        color: var(--muted);
        font-size: 13px;
        line-height: 1.5;
    }
    /* Download button sits centered directly below its own video (uploaded
       videos only — embedded videos like YouTube can't be downloaded). */
    .video-download-row {
        margin-top: 10px;
        text-align: center;
    }
    /* While offline, hide the player/embed and its download button,
       and show the notice above in its place. */
    body.is-offline .media-wrapper video,
    body.is-offline .media-wrapper .video-download-row,
    body.is-offline .video-embed-wrapper iframe {
        display: none !important;
    }
    body.is-offline .video-offline-notice {
        display: flex;
    }
</style>
<script>
    // Reflect the browser's connectivity state on <body> as early as
    // possible so the offline video notice (CSS above) can take over
    // for .media-wrapper / .video-embed-wrapper before the user notices
    // a broken player.
    (function () {
        function applyConnectivityStatus() {
            document.body.classList.toggle('is-offline', !navigator.onLine);
        }
        applyConnectivityStatus();
        window.addEventListener('online', applyConnectivityStatus);
        window.addEventListener('offline', applyConnectivityStatus);
    })();
</script>
        <?php if ($totalParts > 1): ?>
        <div class="progress-nav">
            <div class="progress-container">
                <div class="progress-steps">
                    <?php for ($i = 1; $i <= $totalParts; $i++): ?>
                        <?php
                        $isCompleted = $userProgress && (int)$userProgress['current_part'] > $i;
                        $isActive = $i === $currentPart;
                        $stepUrl = 'content.php?slug=' . urlencode($contentSlug) . '&part=' . $i;
                        ?>
                        <div class="step <?php echo $isCompleted ? 'completed' : ''; ?> <?php echo $isActive ? 'active' : ''; ?>">
                            <a href="<?php echo $stepUrl; ?>" class="step-link">
                                <div class="step-marker">
                                    <span class="number"><?php echo $i; ?></span>
                                    <span class="check-icon">✓</span>
                                </div>
                                <div class="step-label">Part <?php echo $i; ?></div>
                            </a>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($debugMode): ?>
            <div class="debug-panel">
                <div><strong>Content ID:</strong> <?php echo (int)$content['id']; ?></div>
                <div><strong>Content Slug:</strong> <?php echo e($contentSlug); ?></div>
                <div><strong>Subject ID:</strong> <?php echo (int)($content['subject_id'] ?? 0); ?></div>
                <div><strong>Category ID:</strong> <?php echo (int)($content['category_id'] ?? 0); ?></div>
                <div><strong>Part ID:</strong> <?php echo (int)$partId; ?></div>
                <div><strong>Display Mode:</strong> <?php echo e($displayMode); ?></div>
                <div><strong>Sections Found:</strong> <?php echo count($partSections); ?></div>
                <div><strong>Total Parts:</strong> <?php echo $totalParts; ?></div>
                <div><strong>Current Part:</strong> <?php echo $currentPart; ?></div>
                <div><strong>Is Last Part:</strong> <?php echo $isLastPart ? 'true' : 'false'; ?></div>
                <div><strong>Next Part:</strong> <?php echo $nextPart ?? 'null'; ?></div>
                <div><strong>Prev Part:</strong> <?php echo $prevPart ?? 'null'; ?></div>
                <div><strong>Quiz Found:</strong> <?php echo $hasQuiz ? 'Yes (ID: ' . (int)$quiz['id'] . ')' : 'No'; ?></div>
                <div><strong>Questions Found:</strong> <?php echo count($quizQuestions); ?></div>
                <div><strong>Completed Attempts:</strong> <?php echo $completedAttemptCount; ?></div>
                <div><strong>Remaining Attempts:</strong> <?php echo $remainingAttempts === null ? 'Unlimited' : (int)$remainingAttempts; ?></div>
                <div><strong>Quiz Passed:</strong> <?php echo $quizPassed ? 'true' : 'false'; ?></div>
                <div><strong>Has Audio:</strong> <?php echo $hasAudio ? 'true' : 'false'; ?></div>
                <div><strong>Has Video:</strong> <?php echo $hasVideo ? 'true' : 'false'; ?></div>
                <div><strong>Can Complete:</strong> <?php echo $canComplete ? 'true' : 'false'; ?></div>
                <div><strong>Allow Auto Completion:</strong> <?php echo $allowAutoCompletion ? 'true' : 'false'; ?></div>
                <div><strong>Allow Manual Completion:</strong> <?php echo $allowManualCompletion ? 'true' : 'false'; ?></div>
                <div><strong>Completion Blocked:</strong> <?php echo $completionBlocked ? 'true' : 'false'; ?></div>
                <div><strong>Content sort_order:</strong> <?php echo isset($content['sort_order']) ? (int)$content['sort_order'] : 'NULL'; ?></div>
                <?php if (!empty($nextContents)): ?>
                    <div style="margin-top:8px;"><strong>Next contents (up to 5):</strong></div>
                    <?php foreach ($nextContents as $nc): ?>
                        <div style="font-size:13px;padding-left:6px;">
                            ID: <?php echo (int)$nc['id']; ?> — <?php echo e($nc['title'] ?? 'Untitled'); ?>
                            (sort_order: <?php echo isset($nc['sort_order']) ? (int)$nc['sort_order'] : 'NULL'; ?>,
                            locked: <?php echo (!empty($nc['is_locked']) ? '1' : '0'); ?>,
                            video_path: <?php echo !empty($nc['video_path']) ? 'yes' : 'no'; ?>,
                            video_url: <?php echo !empty($nc['video_url']) ? 'yes' : 'no'; ?>)
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="margin-top:8px; font-size:13px; color:var(--muted);">No next contents found.</div>
                <?php endif; ?>
                <div style="margin-top:6px; font-size:13px;"><strong>Counts:</strong> published=<?php echo $nextCountPublished; ?>, total=<?php echo $nextCountAll; ?></div>
                <?php if (!empty($nextDebug)): ?>
                    <div style="margin-top:6px; font-size:13px;"><strong>Next debug:</strong></div>
                    <?php foreach ($nextDebug as $d): ?>
                        <pre style="font-size:11px;background:transparent;color:var(--muted);padding-left:6px;"><?php echo e(json_encode($d)); ?></pre>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($quizError !== ''): ?>
                    <div><strong>Error:</strong> <?php echo e($quizError); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="page-stack">
            <section class="content-section">
                <div class="section-header">
                    <div class="section-badge">Part <?php echo $currentPart; ?> of <?php echo $totalParts; ?></div>
                    <h1 class="section-title"><?php echo e($currentPartData['title'] ?? 'Lesson'); ?></h1>
                    <div class="section-meta">
                        <span>📖 <?php echo $readingTime; ?> min read</span>
                        <span><?php echo $modeIcon; ?> <?php echo $modeLabel; ?></span>
                        <span>📑 <?php echo $sectionCount; ?> section<?php echo $sectionCount > 1 ? 's' : ''; ?></span>
                        <?php if (!empty($content['category_name'])): ?>
                            <span>🏷️ <?php echo e($content['category_name']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($content['author_name'])): ?>
                            <span>✍️ <?php echo e($content['author_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($hasAudio): ?>
                            <span>🎵 Audio</span>
                        <?php endif; ?>
                        <?php if ($hasVideo): ?>
                            <span>🎬 Video</span>
                        <?php endif; ?>
                    </div>
                    <!-- No manual download button: this lesson auto-saves for
                         offline reading on page load (see the script near the
                         end of this file). This element just carries the ids
                         that script needs - it renders nothing. -->
                    <span id="offlineLessonMarker"
                          data-content-id="<?php echo (int)$content['id']; ?>"
                          data-content-slug="<?php echo e($content['slug'] ?? ''); ?>"
                          style="display:none;"></span>
                </div>

                <div class="section-content">
                    <?php if (!empty($content['short_description']) && $currentPart === 1): ?>
                        <div class="intro-card"><?php echo e($content['short_description']); ?></div>
                    <?php endif; ?>

                    <!-- Video Player (Uploaded) -->
                    <?php if (!empty($currentPartData['video_path'])): ?>
                        <div class="media-wrapper">
                            <video controls preload="metadata" style="width:100%;">
                                <?php 
                                $videoPath = $currentPartData['video_path'];
                                $videoExt = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
                                $videoMime = 'video/mp4';
                                if ($videoExt === 'webm') $videoMime = 'video/webm';
                                elseif ($videoExt === 'ogg') $videoMime = 'video/ogg';
                                ?>
                                <source src="<?php echo e($videoPath); ?>" type="<?php echo $videoMime; ?>">
                                Your browser does not support video.
                            </video>
                            <div class="video-offline-notice">
                                <span class="video-offline-icon" aria-hidden="true">📡</span>
                                <div class="video-offline-msg">
                                    <strong>No internet connection</strong>
                                    <span>Turn on mobile data or connect to Wi-Fi to watch this video.</span>
                                </div>
                            </div>
                            <div class="video-download-row">
                                <a href="<?php echo e($videoPath); ?>" download class="btn btn-secondary">⬇️ Download Video</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Video Player (External URL) -->
                    <?php if (!empty($currentPartData['video_url'])): ?>
                        <div class="video-embed-wrapper">
                            <iframe src="<?php echo e($currentPartData['video_url']); ?>" 
                                    frameborder="0" 
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                    allowfullscreen>
                            </iframe>
                            <div class="video-offline-notice">
                                <span class="video-offline-icon" aria-hidden="true">📡</span>
                                <div class="video-offline-msg">
                                    <strong>No internet connection</strong>
                                    <span>Turn on mobile data or connect to Wi-Fi to watch this video.</span>
                                </div>
                            </div>
                        </div>
                        <?php // No download button here: embedded videos (e.g. YouTube) are streamed via iframe and can't be downloaded directly. ?>
                    <?php endif; ?>

                    <!-- Additional Videos (beyond the single primary video above) -->
                    <?php foreach ($currentPartExtraVideos as $extraVideo): ?>
                        <?php if (!empty($extraVideo['caption'])): ?>
                            <p style="margin:16px 0 6px;font-weight:600;font-size:14px;"><?php echo e($extraVideo['caption']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($extraVideo['video_path'])): ?>
                            <div class="media-wrapper" style="margin-top:8px;">
                                <video controls preload="metadata" style="width:100%;">
                                    <?php
                                    $extraVideoExt = strtolower(pathinfo($extraVideo['video_path'], PATHINFO_EXTENSION));
                                    $extraVideoMime = 'video/mp4';
                                    if ($extraVideoExt === 'webm') $extraVideoMime = 'video/webm';
                                    elseif ($extraVideoExt === 'ogg') $extraVideoMime = 'video/ogg';
                                    ?>
                                    <source src="<?php echo e($extraVideo['video_path']); ?>" type="<?php echo $extraVideoMime; ?>">
                                    Your browser does not support video.
                                </video>
                                <div class="video-offline-notice">
                                    <span class="video-offline-icon" aria-hidden="true">📡</span>
                                    <div class="video-offline-msg">
                                        <strong>No internet connection</strong>
                                        <span>Turn on mobile data or connect to Wi-Fi to watch this video.</span>
                                    </div>
                                </div>
                                <div class="video-download-row">
                                    <a href="<?php echo e($extraVideo['video_path']); ?>" download class="btn btn-secondary">⬇️ Download Video</a>
                                </div>
                            </div>
                        <?php elseif (!empty($extraVideo['video_url'])): ?>
                            <div class="video-embed-wrapper" style="margin-top:8px;">
                                <iframe src="<?php echo e($extraVideo['video_url']); ?>"
                                        frameborder="0"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                        allowfullscreen>
                                </iframe>
                                <div class="video-offline-notice">
                                    <span class="video-offline-icon" aria-hidden="true">📡</span>
                                    <div class="video-offline-msg">
                                        <strong>No internet connection</strong>
                                        <span>Turn on mobile data or connect to Wi-Fi to watch this video.</span>
                                    </div>
                                </div>
                            </div>
                            <?php // No download button here: embedded videos (e.g. YouTube) are streamed via iframe and can't be downloaded directly. ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <!-- Audio Player -->
                    <?php if ($hasAudio): ?>
                        <div class="media-wrapper">
                            <audio controls preload="metadata" style="width:100%;">
                                <?php 
                                $audioPath = $currentPartData['audio_path'];
                                $audioExt = strtolower(pathinfo($audioPath, PATHINFO_EXTENSION));
                                $audioMime = 'audio/mpeg';
                                if ($audioExt === 'ogg') $audioMime = 'audio/ogg';
                                elseif ($audioExt === 'wav') $audioMime = 'audio/wav';
                                elseif ($audioExt === 'm4a') $audioMime = 'audio/mp4';
                                ?>
                                <source src="<?php echo e($audioPath); ?>" type="<?php echo $audioMime; ?>">
                                Your browser does not support audio.
                            </audio>
                            <div style="margin-top:8px;">
                                <a href="<?php echo e($audioPath); ?>" download class="btn btn-secondary">⬇️ Download Audio</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ===== CONTENT DISPLAY ===== -->
                    <?php if ($displayMode === 'combined'): ?>
                        <!-- Combined Paragraph Mode - Mixed RTL/LTR -->
                        <div class="combined-content-container">
                            
                            <div style="margin-top:8px;">
                                <?php echo $combinedContent; ?>
                            </div>
                            <?php if ($hasRTL && $hasLTR): ?>
                                <div style="margin-top:12px; font-size:12px; color:var(--muted); border-top:1px solid var(--line); padding-top:10px;">
                                    
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Separate Sections Mode -->
                        <div class="sections-container">
                            <?php foreach ($renderedSections as $sectionIndex => $section): ?>
                                <?php
                                $isRTL = ($section['direction'] ?? 'ltr') === 'rtl';
                                $dirAttr = $isRTL ? 'rtl' : 'ltr';
                                $langClass = $isRTL ? 'rtl' : 'ltr';
                              
                                $dirLabel = $isRTL ? 'RTL' : 'LTR';
                                $displayContent = $section['content'];
                                $sectionNumber = (int)($section['section_number'] ?? $sectionIndex + 1);
                                
                                // Get colors from the section data
                                $textColor = $section['text_color'] ?? null;
                                $bgColor = $section['background_color'] ?? null;
                                
                                // Build inline style - preserve custom colors
                                $style = '';
                                if ($textColor && $textColor !== '') $style .= 'color: ' . $textColor . ';';
                                // See the matching comment in the combined-content render path above:
                                // skip inlining the untouched-default white background so the section
                                // can follow the theme's surface color in dark mode.
                                $isDefaultBgPair = isUntouchedDefaultColorPair($textColor, $bgColor);
                                if ($bgColor && $bgColor !== '' && !$isDefaultBgPair) {
                                    $style .= 'background-color: ' . $bgColor . ';';
                                }
                                
                                $hasColors = (($textColor && $textColor !== '') || ($bgColor && $bgColor !== '')) && !$isDefaultBgPair;
                                $hasTextColor = hasCustomTextColor($textColor, $bgColor);
                                // Explicit flag instead of CSS sniffing the style string: see
                                // needsDarkModeTextFallback() docblock for why [style*="color"]
                                // is unsafe (it also matches "background-color"). Also excludes
                                // the untouched #1f2937/#ffffff default pair - see
                                // isUntouchedDefaultColorPair().
                                $colorFlagClass = $hasTextColor ? ' has-custom-text-color' : ' no-custom-text-color';
                                $darkModeFallbackClass = needsDarkModeTextFallback($textColor, $bgColor) ? ' dark-mode-safe-text' : '';
                                ?>
                                
                                <div class="section-display <?php echo $langClass . $colorFlagClass . $darkModeFallbackClass; ?>" 
                                     dir="<?php echo $dirAttr; ?>" 
                                     style="<?php echo $style; ?>">
                                    
                                    <div class="section-body <?php echo $isRTL ? 'arabic-font' : ''; ?>">
                                        <?php echo $displayContent; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($content['reference_text']) || !empty($content['source_name'])): ?>
                        <div class="reference-card">
                            <?php if (!empty($content['reference_text'])): ?>
                                <div><strong>📚 Reference:</strong> <?php echo e($content['reference_text']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($content['source_name'])): ?>
                                <div><strong>Source:</strong> <?php echo e($content['source_name']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ===== QUIZ SECTION - ONLY SHOWS ON LAST PART ===== -->
            <?php if ($hasQuiz && $isLastPart): ?>
                <section class="quiz-section" id="quizSection">
                    <div class="quiz-header">
                        <div class="quiz-title">📝 <?php echo e($quiz['title'] ?? 'Lesson Quiz'); ?></div>
                        <p><?php echo e($quiz['description'] ?? 'Test your understanding of this lesson.'); ?></p>
                        <div class="quiz-meta">
                            <span>Passing: <?php echo (int)($quiz['passing_score'] ?? 70); ?>%</span>
                            <span>Difficulty: <?php echo e(ucfirst((string)($quiz['difficulty'] ?? 'easy'))); ?></span>
                            <span>Questions: <?php echo count($quizQuestions); ?></span>
                            <?php if (!empty($quiz['time_limit_minutes'])): ?>
                                <span>Time limit: <?php echo (int)$quiz['time_limit_minutes']; ?> min</span>
                            <?php endif; ?>
                            <?php if ((int)($quiz['unlimited_attempts'] ?? 0) === 1): ?>
                                <span>Attempts: Unlimited</span>
                            <?php else: ?>
                                <span>Attempts left: <?php echo (int)$remainingAttempts; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="quizContainer">
                        <?php if ($attemptExists && !$canTakeQuiz): ?>
                            <div id="quizResult" class="quiz-result" style="display:block;"></div>
                            <script>
                                window.existingAttemptData = <?php echo json_encode($latestAttemptData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                                window.quizQuestionsData = <?php echo json_encode($quizQuestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                                window.quizPassingScore = <?php echo (int)($quiz['passing_score'] ?? 70); ?>;
                                window.quizId = <?php echo (int)$quiz['id']; ?>;
                                window.contentId = <?php echo (int)$content['id']; ?>;
                                window.quizAlreadyPassed = <?php echo $quizPassed ? 'true' : 'false'; ?>;
                                window.quizMode = 'lesson';
                            </script>
                        <?php else: ?>
                            <?php if ($attemptExists && $canTakeQuiz): ?>
                                <div class="quiz-info-box">
                                    You have attempted this quiz before. You can try again.
                                    <?php if ($remainingAttempts !== null): ?>
                                        Remaining attempts: <strong><?php echo (int)$remainingAttempts; ?></strong>.
                                    <?php else: ?>
                                        Attempts are unlimited.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php
                            // Admin-disabled submission (see "Completion Lock" in admin_contents.php)
                            // used to hide the whole quiz form. Now it still lets the learner read
                            // the questions and pick answers - only the Submit button (and the
                            // lesson completion button, gated separately by $completionBlocked)
                            // are actually disabled.
                            $submissionDisabledByAdmin = !$allowAutoCompletion && !$quizPassed;
                            ?>
                            <?php if (!$canTakeQuiz && !$quizPassed && !$attemptExists): ?>
                                <div class="quiz-locked">
                                    This quiz is currently unavailable.
                                </div>
                            <?php else: ?>
                                <?php if ($submissionDisabledByAdmin): ?>
                                    <div class="quiz-locked">
                                        🔒 Quiz submission is currently disabled for this lesson by the admin. You can still review the questions below, but answers can't be submitted right now.
                                    </div>
                                <?php endif; ?>
                                <form id="quizForm">
                                    <?php foreach ($quizQuestions as $index => $question): ?>
                                        <div class="quiz-question" data-question-id="<?php echo (int)$question['id']; ?>">
                                            <div class="question-text">
                                                <?php echo ($index + 1) . '. ' . e($question['question_text']); ?>
                                            </div>
                                            <div class="question-extra">
                                                <?php if ((int)($question['points'] ?? 1) > 0): ?>
                                                    <span><?php echo (int)$question['points']; ?> point(s)</span>
                                                <?php endif; ?>
                                                <?php if (!empty($question['difficulty'])): ?>
                                                    <span> • <?php echo e(ucfirst((string)$question['difficulty'])); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($question['question_type'])): ?>
                                                    <span> • <?php echo e(str_replace('_', ' ', (string)$question['question_type'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($question['image_path'])): ?>
                                                <img class="question-image" src="<?php echo e($question['image_path']); ?>" alt="Question image">
                                            <?php endif; ?>
                                            <div class="options">
                                                <?php if ($question['question_type'] === 'explanation'): ?>
                                                    <textarea
                                                        name="q_<?php echo (int)$question['id']; ?>"
                                                        class="explanation-textarea"
                                                        rows="5"
                                                        placeholder="Type your explanation here..."
                                                    ></textarea>
                                                <?php else: ?>
                                                    <?php
                                                    $options = $question['options'] ?? [];
                                                    foreach ($options as $optIndex => $option):
                                                        $inputType = 'radio';
                                                        $optionId = (int)$option['id'];
                                                        $optionText = e((string)($option['option_text'] ?? ''));
                                                    ?>
                                                        <label class="option">
                                                            <input type="<?php echo $inputType; ?>" name="q_<?php echo (int)$question['id']; ?>" value="<?php echo $optionId; ?>">
                                                            <span>
                                                                <strong><?php echo chr(65 + $optIndex); ?>.</strong>
                                                                <?php echo $optionText; ?>
                                                                <?php if (!empty($option['image_path'])): ?>
                                                                    <br><img class="option-image" src="<?php echo e($option['image_path']); ?>" alt="Option image">
                                                                <?php endif; ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="quiz-actions">
                                        <button type="button" class="btn btn-primary" id="submitQuizBtn" <?php echo $submissionDisabledByAdmin ? 'disabled title="Quiz submission is currently disabled for this lesson by the admin."' : ''; ?>>Submit Answers</button>
                                    </div>
                                </form>
                                <div id="quizResult" class="quiz-result" style="display:none;"></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php elseif ($hasQuiz && !$isLastPart): ?>
                <!-- Quiz is available but user hasn't reached the last part yet -->
                <div class="quiz-info-box">
                    📝 The quiz will unlock after you finish all lesson parts.
                </div>
            <?php endif; ?>

            <!-- ===== NAVIGATION BUTTONS - FIXED ===== -->
            <div class="navigation-buttons">
                <div>
                    <?php if ($prevPart): ?>
                        <a href="content.php?slug=<?php echo urlencode($contentSlug); ?>&part=<?php echo $prevPart; ?>" class="btn btn-secondary">
                            ← Previous Part
                        </a>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($nextPart): ?>
                        <a href="content.php?slug=<?php echo urlencode($contentSlug); ?>&part=<?php echo $nextPart; ?>" class="btn btn-primary">
                            Next Part →
                        </a>
                    <?php elseif ($isLastPart && !$completionStatus): ?>
                        <?php if ($completionBlocked): ?>
                            <div class="btn btn-secondary" style="cursor:default; opacity:.75;" title="<?php echo $hasQuiz ? 'Completion via this lesson\'s quiz has been disabled by the admin.' : 'Completion for this lesson has been disabled by the admin.'; ?>">
                                🔒 Completion Disabled
                            </div>
                        <?php else: ?>
                            <button class="btn btn-success" id="completeContentBtn" <?php echo !$canComplete ? 'disabled' : ''; ?>>
                                🎓 Complete Lesson
                            </button>
                        <?php endif; ?>
                    <?php elseif ($completionStatus): ?>
                        <div class="btn btn-success" style="cursor:default; background:var(--brand-soft); color:var(--brand); border-color:var(--brand);">
                            ✅ Lesson Completed
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php if (!empty($nextContents)): ?>
    <aside class="next-lesson-panel" aria-label="Upcoming lessons">
        <div class="next-lesson-card">
            <h4>Up Next</h4>
            <div class="next-lesson-list">
                <?php foreach ($nextContents as $nc):
                    $locked = !empty($nc['locked']) && (int)$nc['locked'] === 1;
                    $hasVideo = !empty($nc['has_video']);
                    $link = (!$locked) ? (!empty($nc['slug']) ? 'content.php?slug=' . urlencode((string)$nc['slug']) : 'content.php?id=' . (int)$nc['id']) : null;
                ?>
                <div class="next-item <?php echo $locked ? 'locked' : ''; ?>">
                    <?php if ($link): ?><a href="<?php echo $link; ?>" class="next-lesson-link"><?php endif; ?>
                        <div class="next-item-media">
                            <?php if ($hasVideo): ?>
                                <video class="next-item-video" muted playsinline preload="metadata" <?php if (!$locked): ?>controls<?php endif; ?>>
                                    <?php if (!empty($nc['video_path'])): ?>
                                        <source src="<?php echo e($nc['video_path']); ?>" type="video/mp4">
                                    <?php elseif (!empty($nc['video_url'])): ?>
                                        <source src="<?php echo e($nc['video_url']); ?>">
                                    <?php endif; ?>
                                </video>
                            <?php else: ?>
                                <div class="next-item-placeholder">No video</div>
                            <?php endif; ?>
                        </div>
                        <div class="next-item-body">
                            <div class="next-lesson-title"><?php echo e($nc['title'] ?? 'Untitled'); ?></div>
                            <div class="next-lesson-meta"><?php echo $locked ? '🔒 Locked' : 'Available'; ?></div>
                        </div>
                    <?php if ($link): ?></a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>
    <?php endif; ?>

<!-- Page-specific: locked "Up Next" items shouldn't be clickable.
     (Shared GLightbox init + chrome toggle behavior now live in
     includes/footer.php, required at the end of this page.) -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.next-item.locked a').forEach(a => {
            a.addEventListener('click', function(e){
                e.preventDefault();
            });
            a.setAttribute('aria-disabled', 'true');
        });
    });
</script>
<script>
    const submitQuizBtn = document.getElementById('submitQuizBtn');
    const completeContentBtn = document.getElementById('completeContentBtn');
    const quizForm = document.getElementById('quizForm');
    const quizResult = document.getElementById('quizResult');

    function getOptionTextById(questionId, optionId) {
        const questions = window.quizQuestionsData || [];
        const question = questions.find(q => String(q.id) === String(questionId));
        if (!question || !Array.isArray(question.options)) return String(optionId || '');
        const option = question.options.find(opt => String(opt.id) === String(optionId));
        return option ? option.option_text : String(optionId || '');
    }

    function getCorrectAnswerText(question) {
        if (!question) return 'Not available';
        if (question.question_type === 'explanation') {
            return question.expected_answer || 'Not available';
        }
        if (Array.isArray(question.options)) {
            const correctOption = question.options.find(opt => String(opt.is_correct) === '1' || opt.is_correct === true);
            if (correctOption) return correctOption.option_text || 'Not available';
        }
        return 'Not available';
    }

    function showToast(message, isError = false) {
        // The shared header/footer may or may not provide a #toast element
        // (and may or may not style it). Rather than silently doing nothing
        // when it's missing - which is why "answer all questions" warnings
        // were going unnoticed - create/style it ourselves so the alert is
        // guaranteed to be visible every time.
        let toastEl = document.getElementById('toast');
        if (!toastEl) {
            toastEl = document.createElement('div');
            toastEl.id = 'toast';
            document.body.appendChild(toastEl);
        }

        toastEl.style.position = 'fixed';
        toastEl.style.left = '50%';
        toastEl.style.bottom = '24px';
        toastEl.style.transform = 'translateX(-50%) translateY(16px)';
        toastEl.style.padding = '12px 22px';
        toastEl.style.borderRadius = '12px';
        toastEl.style.color = '#fff';
        toastEl.style.fontWeight = '600';
        toastEl.style.fontSize = '14px';
        toastEl.style.boxShadow = '0 10px 25px rgba(0,0,0,.25)';
        toastEl.style.zIndex = '99999';
        toastEl.style.maxWidth = '90vw';
        toastEl.style.textAlign = 'center';
        toastEl.style.opacity = '0';
        toastEl.style.pointerEvents = 'none';
        toastEl.style.transition = 'opacity .25s ease, transform .25s ease';

        toastEl.textContent = message;
        toastEl.style.background = isError ? '#dc2626' : '#0E4A34';

        // Force a reflow so the transition re-triggers on consecutive calls.
        void toastEl.offsetWidth;
        toastEl.style.opacity = '1';
        toastEl.style.transform = 'translateX(-50%) translateY(0)';

        clearTimeout(showToast._hideTimer);
        showToast._hideTimer = setTimeout(() => {
            toastEl.style.opacity = '0';
            toastEl.style.transform = 'translateX(-50%) translateY(16px)';
        }, 3200);
    }

    async function fetchWithTimeout(url, options = {}, timeout = 15000) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), timeout);
        try {
            const response = await fetch(url, { ...options, signal: controller.signal });
            clearTimeout(id);
            return response;
        } catch (error) {
            clearTimeout(id);
            throw error;
        }
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function showCongratsModal(score, total, percentage) {
        const modal = document.createElement('div');
        modal.style.cssText = `
            position:fixed; inset:0; background:rgba(0,0,0,.52); display:flex; align-items:center; justify-content:center; z-index:10000; padding:16px;
        `;
        modal.innerHTML = `
            <div style="background:var(--surface); width:100%; max-width:420px; border-radius:22px; padding:28px; text-align:center; box-shadow:0 20px 50px rgba(0,0,0,.18);">
                <div style="font-size:60px; margin-bottom:10px;">🎉</div>
                <h2 style="margin-bottom:10px; color:var(--brand);">Congratulations!</h2>
                <p style="font-size:18px; margin-bottom:10px;">You passed the quiz with <strong>${escapeHtml(percentage)}%</strong></p>
                <p style="color:var(--muted); margin-bottom:18px;">Score: ${escapeHtml(score)}/${escapeHtml(total)}</p>
                <button type="button" style="border:none; background:var(--brand); color:#fff; padding:12px 18px; border-radius:12px; font-weight:700; cursor:pointer;" onclick="this.closest('div').parentElement.remove();">Continue</button>
            </div>
        `;
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });
        document.body.appendChild(modal);
    }

    // Types each line into `container` one character at a time, with a
    // blinking cursor that trails the current line, then moves to the next
    // line once it finishes. Returns a Promise that resolves when all lines
    // have finished typing.
    function typeTerminalLines(container, lines, speed = 18, lineDelay = 200) {
        if (!container) return Promise.resolve();
        container.innerHTML = '';
        return new Promise((resolve) => {
            let lineIndex = 0;
            function nextLine() {
                if (lineIndex >= lines.length) {
                    resolve();
                    return;
                }
                const { text, className } = lines[lineIndex];
                const lineEl = document.createElement('div');
                lineEl.className = className;
                container.appendChild(lineEl);
                const cursor = document.createElement('span');
                cursor.className = 'term-cursor';
                lineEl.appendChild(cursor);
                let charIndex = 0;
                function typeChar() {
                    if (charIndex < text.length) {
                        lineEl.insertBefore(document.createTextNode(text[charIndex]), cursor);
                        charIndex++;
                        setTimeout(typeChar, speed);
                    } else {
                        cursor.remove();
                        lineIndex++;
                        setTimeout(nextLine, lineDelay);
                    }
                }
                typeChar();
            }
            nextLine();
        });
    }

    // Builds the grading lines (score, correct count, passing threshold,
    // pass/fail status) and types them into the terminal-style result box.
    function animateQuizTerminal(elementId, data) {
        const el = document.getElementById(elementId);
        if (!el) return;

        const passed = !!data.passed;
        const percentage = Number(data.percentage || 0);
        const score = Number(data.score || 0);
        const total = Number(data.total || 0);
        const correctCount = Number(data.correct_count || 0);
        const totalQuestionsCount = Number(data.total_questions || 0);
        const passingScore = window.quizPassingScore || 70;

        const lines = [
            {
                text: passed ? '🎉 Congratulations! You passed the quiz.' : '📉 You did not pass this time.',
                className: 'term-title'
            },
            { text: `Score: ${score} / ${total} (${percentage}%)`, className: 'term-line' },
            { text: `Correct Answers: ${correctCount} of ${totalQuestionsCount}`, className: 'term-line' },
            { text: `Passing Score Required: ${passingScore}%`, className: 'term-line' }
        ];
        if (data.attempt_number) {
            lines.push({ text: `Attempt #: ${data.attempt_number}`, className: 'term-line' });
        }
        lines.push({
            text: passed ? 'Status: PASSED ✅' : 'Status: NOT PASSED — try again ❌',
            className: 'term-line'
        });

        typeTerminalLines(el, lines);
    }

    function buildReviewHtml(data) {
        const passed = !!data.passed;
        const percentage = Number(data.percentage || 0);
        const score = Number(data.score || 0);
        const total = Number(data.total || 0);
        const correctCount = Number(data.correct_count || 0);
        const totalQuestionsCount = Number(data.total_questions || 0);
        const results = Array.isArray(data.results) ? data.results : [];

        let html = `
            <div class="quiz-terminal ${passed ? 'pass' : 'fail'}" id="quizTerminalReview"></div>
            <h3 style="margin:20px 0 12px;">📋 Review Answers</h3>
            <div style="display:grid; gap:12px;">
        `;

        results.forEach((result, idx) => {
            const isCorrect = !!result.is_correct;
            const questionId = result.question_id;
            const question = (window.quizQuestionsData || []).find(q => String(q.id) === String(questionId));
            const isExplanation = question ? question.question_type === 'explanation' : false;
            
            let userAnswerDisplay = '';
            if (result.user_answer_text) {
                userAnswerDisplay = escapeHtml(result.user_answer_text);
            } else if (result.user_answer !== undefined && result.user_answer !== null && result.user_answer !== '') {
                if (isExplanation) {
                    userAnswerDisplay = escapeHtml(String(result.user_answer));
                } else {
                    userAnswerDisplay = getOptionTextById(questionId, result.user_answer);
                }
            } else {
                userAnswerDisplay = '<em style="color:var(--muted);">No answer</em>';
            }
            
            let correctAnswerDisplay = '';
            if (result.correct_answer_text) {
                correctAnswerDisplay = escapeHtml(result.correct_answer_text);
            } else if (result.correct_answer !== undefined && result.correct_answer !== null && result.correct_answer !== '') {
                if (isExplanation) {
                    correctAnswerDisplay = escapeHtml(String(result.correct_answer));
                } else {
                    correctAnswerDisplay = getOptionTextById(questionId, result.correct_answer);
                }
            } else if (question) {
                correctAnswerDisplay = getCorrectAnswerText(question);
            } else {
                correctAnswerDisplay = '<em style="color:var(--muted);">Not available</em>';
            }
            
            const explanation = result.explanation ? escapeHtml(result.explanation) : (question ? escapeHtml(question.explanation || '') : '');
            const questionText = escapeHtml(result.question_text || `Question ${idx + 1}`);
            
            let imageHtml = '';
            if (question && question.image_path) {
                let imgSrc = question.image_path.replace(/ /g, '%20');
                imageHtml = `<div style="margin:10px 0;"><img src="${escapeHtml(imgSrc)}" style="max-width:100%; max-height:200px; border-radius:8px; border:1px solid var(--line);"></div>`;
            }

            html += `
                <div style="border: 2px solid var(--line); background:var(--surface-2); border-radius:10px; padding:16px; border-left:4px solid ${isCorrect ? 'var(--brand)' : 'var(--danger)'};">
                    <p style="font-weight:800; margin-bottom:8px;">${idx + 1}. ${questionText}</p>
                    ${imageHtml}
                    <p style="margin-bottom:6px;"><span style="color:var(--muted);">Your answer:</span> ${userAnswerDisplay} ${isCorrect ? '✅' : '❌'}</p>
                    <p style="margin-bottom:${explanation ? '6px' : '0'};"><span style="color:var(--muted);">Correct answer:</span> ${correctAnswerDisplay}</p>
                    ${explanation ? `<p style="color:var(--muted); font-size:13px;">💡 ${explanation}</p>` : ''}
                </div>
            `;
        });

        html += `</div>`;

        if (passed) {
            html += `<div style="margin-top:20px; padding:14px; border-radius:14px; background:var(--brand-soft); color:var(--brand); border:1px solid var(--brand);">🎓 Great job. You have successfully completed this lesson quiz.</div>`;
        } else {
            html += `<div style="margin-top:20px; padding:14px; border-radius:14px; background:#fee2e2; color:#991b1b; border:1px solid var(--danger);">Review the material and try again.</div>`;
        }

        return html;
    }

    function renderAttemptResult(data) {
        const quizResultDiv = document.getElementById('quizResult');
        if (!quizResultDiv) return;
        quizResultDiv.innerHTML = buildReviewHtml(data);
        quizResultDiv.style.display = 'block';
        const form = document.getElementById('quizForm');
        if (form) form.style.display = 'none';
        animateQuizTerminal('quizTerminalReview', data);
        if (data.passed) {
            const btn = document.getElementById('completeContentBtn');
            if (btn) btn.disabled = false;
        }
        quizResultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showLessonFailureMessage(message) {
        const quizResultDiv = document.getElementById('quizResult');
        if (!quizResultDiv) return;
        quizResultDiv.innerHTML = `
            <div class="quiz-terminal fail" id="quizTerminalFail"></div>
            <div style="display:flex; gap:12px; margin-top:16px;">
                <button class="btn btn-primary" id="retryQuizBtn">🔄 Try Again</button>
            </div>
        `;
        quizResultDiv.style.display = 'block';
        const form = document.getElementById('quizForm');
        if (form) form.style.display = 'none';
        typeTerminalLines(document.getElementById('quizTerminalFail'), [
            { text: '❌ Quiz Not Passed', className: 'term-title' },
            { text: message, className: 'term-line' }
        ]);
        quizResultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('retryQuizBtn')?.addEventListener('click', () => {
            if (quizForm) {
                quizForm.reset();
                quizForm.style.display = '';
            }
            quizResultDiv.style.display = 'none';
            if (submitQuizBtn) {
                submitQuizBtn.disabled = false;
                submitQuizBtn.textContent = 'Submit Answers';
            }
        });
    }

    async function autoCompleteLessonAfterPass() {
        if (!completeContentBtn) return false;
        completeContentBtn.disabled = true;
        completeContentBtn.textContent = '⏳ Completing lesson...';
        try {
            const response = await fetchWithTimeout('ajax/complete_content.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    content_id: <?php echo (int)$content['id']; ?>,
                    subject_id: <?php echo (int)($content['subject_id'] ?? 0); ?>
                })
            });
            const data = await response.json();
            if (data.success) {
                showToast('🎉 Lesson completed automatically!');
                setTimeout(() => {
                    window.location.href = '<?php echo e($afterCompleteUrl); ?>';
                }, 1400);
                return true;
            }
            throw new Error(data.message || 'Failed to complete lesson.');
        } catch (error) {
            console.error(error);
            showToast(error.message || 'Quiz passed, but lesson auto-completion failed.', true);
            completeContentBtn.disabled = false;
            completeContentBtn.textContent = '🎓 Complete Lesson';
            return false;
        }
    }

    function collectAnswers(formEl) {
        const payload = {};
        const fd = new FormData(formEl);
        for (const [key, value] of fd.entries()) {
            if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2).replace('q_', '');
                if (!Array.isArray(payload[cleanKey])) payload[cleanKey] = [];
                payload[cleanKey].push(value);
            } else {
                const cleanKey = key.replace('q_', '');
                payload[cleanKey] = value;
            }
        }
        return payload;
    }

    function getAnsweredCount(answers) {
        let count = 0;
        Object.keys(answers).forEach((qid) => {
            const val = answers[qid];
            if (Array.isArray(val)) {
                if (val.length > 0) count++;
            } else if (val !== '' && val !== null && val !== undefined) {
                count++;
            }
        });
        return count;
    }

    if (submitQuizBtn && quizForm) {
        submitQuizBtn.addEventListener('click', async () => {
            <?php if (!$allowAutoCompletion && !$quizPassed): ?>
            showToast('Quiz submission is currently disabled for this lesson by the admin.', true);
            return;
            <?php endif; ?>
            const answers = collectAnswers(quizForm);
            const totalQuestions = <?php echo count($quizQuestions); ?>;
            const answeredCount = getAnsweredCount(answers);
            if (answeredCount < totalQuestions) {
                showToast(`Please answer all ${totalQuestions} questions before submitting.`, true);
                return;
            }
            submitQuizBtn.disabled = true;
            submitQuizBtn.textContent = '⏳ Grading...';
            try {
                const response = await fetchWithTimeout('admin/quiz_submit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        quiz_id: <?php echo (int)($quiz['id'] ?? 0); ?>,
                        content_id: <?php echo (int)$content['id']; ?>,
                        answers: answers
                    })
                });
                const data = await response.json();
                if (!response.ok && data.attempted) {
                    renderAttemptResult(data.attempt_data || data);
                    showToast(data.message || 'You have already used your attempts.', true);
                    return;
                }
                const isLessonMode = (window.quizMode || 'lesson') === 'lesson';
                if (isLessonMode && !data.passed && data.success === false) {
                    showLessonFailureMessage(data.message || 'You did not achieve the passing score. Please review the lesson and try again.');
                    return;
                }
                if (!data.success) {
                    throw new Error(data.message || 'Failed to submit quiz.');
                }
                renderAttemptResult(data);
                if (data.passed) {
                    showCongratsModal(data.score, data.total, data.percentage);
                    showToast('Quiz passed! Completing lesson...');
                    await autoCompleteLessonAfterPass();
                } else {
                    showToast('Quiz submitted. Review your answers below.', true);
                }
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Unable to submit quiz.', true);
            } finally {
                submitQuizBtn.disabled = false;
                submitQuizBtn.textContent = 'Submit Answers';
            }
        });
    }

    if (completeContentBtn) {
        completeContentBtn.addEventListener('click', async () => {
            <?php if ($hasQuiz && !$quizPassed): ?>
            showToast('Please pass the lesson quiz first.', true);
            document.getElementById('quizSection')?.scrollIntoView({ behavior: 'smooth' });
            return;
            <?php endif; ?>
            completeContentBtn.disabled = true;
            completeContentBtn.textContent = '⏳ Processing...';
            try {
                const response = await fetchWithTimeout('ajax/complete_content.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        content_id: <?php echo (int)$content['id']; ?>,
                        subject_id: <?php echo (int)($content['subject_id'] ?? 0); ?>
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('🎉 Excellent! Lesson completed!');
                    setTimeout(() => {
                        window.location.href = '<?php echo e($afterCompleteUrl); ?>';
                    }, 1400);
                } else {
                    throw new Error(data.message || 'Failed to complete lesson.');
                }
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Unable to complete lesson.', true);
                completeContentBtn.disabled = false;
                completeContentBtn.textContent = '🎓 Complete Lesson';
            }
        });
    }

    let startTime = Date.now();
    window.addEventListener('beforeunload', () => {
        const timeOnPage = Math.floor((Date.now() - startTime) / 1000);
        if (timeOnPage > 10) {
            navigator.sendBeacon('ajax/track_time.php', JSON.stringify({
                content_id: <?php echo (int)$content['id']; ?>,
                part: <?php echo (int)$currentPart; ?>,
                time_spent: timeOnPage
            }));
        }
    });

    // Keyboard Navigation - Only for parts within the same content
    document.addEventListener('keydown', (e) => {
        // Left Arrow - Previous Part
        if (e.key === 'ArrowLeft' && !e.ctrlKey && !e.metaKey && <?php echo $prevPart ? 'true' : 'false'; ?>) {
            e.preventDefault();
            window.location.href = 'content.php?slug=<?php echo urlencode($contentSlug); ?>&part=<?php echo (int)$prevPart; ?>';
        }
        
        // Right Arrow - Next Part
        if (e.key === 'ArrowRight' && !e.ctrlKey && !e.metaKey && <?php echo $nextPart ? 'true' : 'false'; ?>) {
            e.preventDefault();
            window.location.href = 'content.php?slug=<?php echo urlencode($contentSlug); ?>&part=<?php echo (int)$nextPart; ?>';
        }
        
        // 'C' key - Complete Content
        if (e.key.toLowerCase() === 'c' && !e.ctrlKey && !e.metaKey && completeContentBtn && !completeContentBtn.disabled) {
            e.preventDefault();
            completeContentBtn.click();
        }
    });

    if (typeof window.existingAttemptData !== 'undefined' && window.existingAttemptData) {
        renderAttemptResult(window.existingAttemptData);
    }
</script>

<!-- PWA: auto-save this lesson for offline reading. No button - runs on
     page load, only downloads if not already saved, and only notifies on
     success (self-contained script, doesn't share scope with the quiz
     script above; needs only the marker element and OfflineDB, loaded
     site-wide by includes/pwa.php). -->
<script>
    (async function () {
        const marker = document.getElementById('offlineLessonMarker');
        if (!marker) return;

        const contentId = marker.dataset.contentId;

        try {
            const alreadyDownloaded = await OfflineDB.isDownloaded(contentId);
            if (alreadyDownloaded) {
                return; // Nothing to do - already saved from a previous visit.
            }

            const response = await fetch(`api/get_lesson_content.php?id=${encodeURIComponent(contentId)}`);
            if (!response.ok) {
                throw new Error(`Server responded ${response.status}`);
            }
            const lessonData = await response.json();
            if (lessonData.error) {
                throw new Error(lessonData.error);
            }
            await OfflineDB.saveLesson(lessonData);
            if (typeof showToast === 'function') {
                showToast('Lesson saved for offline reading 📥', false);
            }
        } catch (err) {
            // Deliberately silent to the user - this is a background,
            // automatic action, not something they asked for and are
            // waiting on. Failures (e.g. no connection) are expected and
            // not worth interrupting them about; it'll just try again next
            // visit.
            console.warn('Auto-save for offline reading failed:', err);
        }
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>