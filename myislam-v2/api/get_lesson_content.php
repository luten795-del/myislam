<?php
/**
 * GET /api/get_lesson_content.php?slug=... (or ?id=...)
 *
 * Returns the read-only reading material for a lesson - title, parts, and
 * each part's sections (text, saved colors, direction/language, display
 * mode) - as JSON, for the browser to store in IndexedDB and render later
 * with no network connection.
 *
 * SCOPE - deliberately read-only content, not the whole page:
 *   - No quiz questions/options: quizzes need to be graded and progress
 *     needs to be saved against a live session; downloading a quiz for
 *     "offline completion" would need an answer-queue + background-sync
 *     design of its own, which is a separate feature to build on top of
 *     this once the basic reading-offline flow works.
 *   - No user_content_progress: progress is meaningless to store per
 *     download since it changes based on live server state.
 *   - Same auth check as content.php: a lesson can only be downloaded by a
 *     logged-in user, same as viewing it live.
 *
 * This mirrors content.php's own content/parts/sections queries exactly
 * (same tables, same columns, same decode-then-return steps) so a
 * downloaded lesson matches what the live page would show. It shares
 * color-helpers.php with content.php so the "is this text color really
 * custom, or just the untouched default?" decision is identical online
 * and offline - the client only needs to apply the precomputed
 * has_custom_text_color / needs_dark_mode_fallback flags, not recompute
 * luminance itself.
 */

declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');

function respondError(int $httpStatus, string $message): never
{
    http_response_code($httpStatus);
    echo json_encode(['error' => $message]);
    exit;
}

// ============================================================================
// AUTH CHECK - same rule as content.php
// ============================================================================
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    respondError(401, 'login_required');
}
$userId = (int)$_SESSION['user_id'];

// ============================================================================
// DEPENDENCIES
// ============================================================================
$databaseConfigFile = __DIR__ . '/../config/database.php';
if (!file_exists($databaseConfigFile)) {
    respondError(500, 'System configuration missing.');
}
require_once $databaseConfigFile;
require_once __DIR__ . '/../includes/color-helpers.php';

function decodeField(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

try {
    $conn = db();
    if (!$conn instanceof PDO) {
        throw new RuntimeException('Database connection failed.');
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $ex) {
    error_log('get_lesson_content: DB connection error: ' . $ex->getMessage());
    respondError(500, 'Unable to connect to database.');
}

// ============================================================================
// INPUTS
// ============================================================================
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : null;
$id   = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (empty($slug) && empty($id)) {
    respondError(400, 'no_content_specified');
}

// ============================================================================
// FETCH CONTENT - same query shape as content.php
// ============================================================================
$content = null;

try {
    if (!empty($slug)) {
        $stmt = $conn->prepare("
            SELECT
                c.*,
                cat.name AS category_name,
                s.name AS subject_name,
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
                s.name AS subject_name,
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
    error_log('get_lesson_content: Error fetching content: ' . $ex->getMessage());
    respondError(500, 'Error loading content.');
}

if (!$content) {
    respondError(404, 'not_found');
}

// ============================================================================
// FETCH PARTS
// ============================================================================
try {
    $stmt = $conn->prepare("
        SELECT id, content_id, part_number, title,
               arabic_body, arabic_body_html,
               swahili_body, swahili_body_html,
               audio_path, video_path, video_url, display_mode
        FROM content_parts
        WHERE content_id = ?
        ORDER BY part_number ASC
    ");
    $stmt->execute([(int)$content['id']]);
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('get_lesson_content: Error fetching parts: ' . $ex->getMessage());
    respondError(500, 'Error loading lesson parts.');
}

// ============================================================================
// FETCH SECTIONS PER PART, CLASSIFY COLORS
// ============================================================================
function getSectionsForPart(PDO $conn, int $partId): array
{
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
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$responseParts = [];

foreach ($parts as $part) {
    $sections = [];
    try {
        $rawSections = getSectionsForPart($conn, (int)$part['id']);
    } catch (Throwable $ex) {
        error_log('get_lesson_content: Error fetching sections for part ' . $part['id'] . ': ' . $ex->getMessage());
        $rawSections = [];
    }

    foreach ($rawSections as $section) {
        $textColor = $section['text_color'] ?? null;
        $bgColor   = $section['background_color'] ?? null;

        $sections[] = [
            'id'                       => (int)$section['id'],
            'section_number'           => (int)$section['section_number'],
            'language'                 => $section['language'] ?? 'sw',
            'direction'                => $section['direction'] ?? 'ltr',
            'content'                  => decodeField($section['content'] ?? null),
            'content_html'             => decodeField($section['content_html'] ?? null),
            'text_color'               => $textColor,
            'background_color'         => $bgColor,
            // Precomputed with the exact same rules content.php uses, so the
            // offline viewer never has to re-derive - and can't drift from -
            // what the live page decides.
            'has_custom_text_color'    => hasCustomTextColor($textColor, $bgColor),
            'needs_dark_mode_fallback' => needsDarkModeTextFallback($textColor, $bgColor),
        ];
    }

    $responseParts[] = [
        'id'               => (int)$part['id'],
        'part_number'      => (int)$part['part_number'],
        'title'            => $part['title'] ?? null,
        'display_mode'     => $part['display_mode'] ?? 'sections',
        'arabic_body'      => decodeField($part['arabic_body'] ?? null),
        'arabic_body_html' => decodeField($part['arabic_body_html'] ?? null),
        'swahili_body'     => decodeField($part['swahili_body'] ?? null),
        'swahili_body_html'=> decodeField($part['swahili_body_html'] ?? null),
        'audio_path'       => $part['audio_path'] ?? ($content['audio_path'] ?? null),
        'video_path'       => $part['video_path'] ?? ($content['video_path'] ?? null),
        'video_url'        => $part['video_url'] ?? ($content['video_url'] ?? null),
        'sections'         => $sections,
    ];
}

// ============================================================================
// RESPONSE
// ============================================================================
echo json_encode([
    'id'            => (int)$content['id'],
    'slug'          => $content['slug'] ?? null,
    'title'         => $content['title'] ?? 'Untitled',
    'category_name' => $content['category_name'] ?? null,
    'subject_name'  => $content['subject_name'] ?? null,
    'author_name'   => $content['author_name'] ?? null,
    'audio_path'    => $content['audio_path'] ?? null,
    'video_path'    => $content['video_path'] ?? null,
    'video_url'     => $content['video_url'] ?? null,
    'parts'         => $responseParts,
    'fetched_at'    => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
