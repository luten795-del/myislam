<?php

declare(strict_types=1);
session_start();
// Enable error reporting for debugging but log instead of display
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Best-effort: these two ARE runtime-changeable (PHP_INI_ALL) even on hosts
// that lock down upload_max_filesize/post_max_size (PHP_INI_PERDIR, which
// ini_set() cannot touch - those require .htaccess/.user.ini/php.ini).
@ini_set('max_execution_time', '300');
@ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notifications.php';

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================
/**
 * Normalizes a pasted YouTube/Vimeo (or raw <iframe> embed code) URL into a
 * proper embeddable URL. Used for both the top-level content video_url and
 * each part's video_url so both behave identically.
 */
function normalizeVideoEmbedUrl(string $videoUrl): string {
    $videoUrl = trim($videoUrl);
    if ($videoUrl === '') {
        return '';
    }
    if (preg_match('/src=["\']([^"\']+)["\']/', $videoUrl, $matches)) {
        $videoUrl = $matches[1];
    }
    if (preg_match('/youtube\.com\/watch\?v=([^&]+)/', $videoUrl, $matches)) {
        $videoUrl = 'https://www.youtube.com/embed/' . $matches[1];
    } elseif (preg_match('/youtu\.be\/([^?]+)/', $videoUrl, $matches)) {
        $videoUrl = 'https://www.youtube.com/embed/' . $matches[1];
    } elseif (preg_match('#youtube\.com/embed/([^?]+)#', $videoUrl, $matches)) {
        $videoUrl = 'https://www.youtube.com/embed/' . $matches[1];
    } elseif (preg_match('/vimeo\.com\/(\d+)/', $videoUrl, $matches)) {
        $videoUrl = 'https://player.vimeo.com/video/' . $matches[1];
    } elseif (preg_match('#player\.vimeo\.com/video/(\d+)#', $videoUrl, $matches)) {
        $videoUrl = 'https://player.vimeo.com/video/' . $matches[1];
    }
    return $videoUrl;
}

function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function initials(string $name): string {
    $name = trim($name);
    if ($name === '') return 'A';
    $parts = preg_split('/\s+/', $name) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'A';
}

function make_slug(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? $text : 'content';
}

// ============================================================================
// DETECT BASE URL
// ============================================================================
function getBaseUrl(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptDir = dirname($scriptName);
    $baseDir = str_replace('/admin', '', $scriptDir);
    if ($baseDir === '/' || $baseDir === '\\') {
        return '';
    }
    return $baseDir;
}

function getUploadUrl(string $type = ''): string {
    $base = getBaseUrl();
    if ($base) {
        return $base . '/uploads/' . ($type ? $type . '/' : '');
    }
    return '/uploads/' . ($type ? $type . '/' : '');
}

function getUploadPath(string $type = ''): string {
    $possiblePaths = [
        __DIR__ . '/../uploads/' . ($type ? $type . '/' : ''),
        $_SERVER['DOCUMENT_ROOT'] . '/uploads/' . ($type ? $type . '/' : ''),
        $_SERVER['DOCUMENT_ROOT'] . getBaseUrl() . '/uploads/' . ($type ? $type . '/' : ''),
    ];
    
    foreach ($possiblePaths as $path) {
        $cleanPath = str_replace('//', '/', $path);
        if (is_dir($cleanPath) || !file_exists(dirname($cleanPath))) {
            if (!is_dir($cleanPath)) {
                mkdir($cleanPath, 0755, true);
            }
            return $cleanPath;
        }
    }
    
    $defaultPath = __DIR__ . '/../uploads/' . ($type ? $type . '/' : '');
    if (!is_dir($defaultPath)) {
        mkdir($defaultPath, 0755, true);
    }
    return $defaultPath;
}

// ============================================================================
// FILE UPLOAD HANDLER
// ============================================================================
function handleFileUpload(array $file, string $type): ?string {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        error_log("No file uploaded or upload error for {$type}");
        return null;
    }
    
    $uploadDir = getUploadPath($type);
    
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            error_log("Failed to create directory: {$uploadDir}");
            return null;
        }
    }
    
    if (!is_writable($uploadDir)) {
        error_log("Upload directory is not writable: {$uploadDir}");
        chmod($uploadDir, 0755);
    }
    
    $allowedExtensions = [
        'audio' => ['mp3', 'ogg', 'wav', 'm4a', 'aac', 'flac', 'mpga', 'mpeg', 'mpg', 'mp2', 'mpa'],
        'video' => ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'mpeg', 'mpg']
    ];
    
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions[$type] ?? [])) {
        error_log("Invalid {$type} format: {$extension}");
        return null;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    error_log("=== UPLOAD DEBUG ===");
    error_log("File: {$file['name']}");
    error_log("Extension: {$extension}");
    error_log("MIME Type: {$mimeType}");
    error_log("Type: {$type}");
    
    $isMpegAudio = ($type === 'audio' && in_array($extension, ['mpeg', 'mpg', 'mpga', 'mp2', 'mpa']));
    
    $fileName = uniqid($type . '_') . '.' . $extension;
    $destination = $uploadDir . $fileName;
    
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        error_log("Failed to move uploaded {$type} file to {$destination}");
        return null;
    }
    
    if ($isMpegAudio) {
        $mp3FileName = str_replace('.' . $extension, '.mp3', $fileName);
        $mp3Destination = $uploadDir . $mp3FileName;
        
        error_log("Attempting to convert MPEG to MP3: {$destination} -> {$mp3Destination}");
        
        if (convertMpegToMp3($destination, $mp3Destination)) {
            unlink($destination);
            $fileName = $mp3FileName;
            $destination = $mp3Destination;
            $extension = 'mp3';
            error_log("MPEG converted to MP3 successfully: {$fileName}");
        } else {
            error_log("MPEG to MP3 conversion failed. Keeping original file.");
        }
    }
    
    chmod($destination, 0644);
    $relativePath = getUploadUrl($type) . $fileName;
    
    error_log("Successfully uploaded {$type} file: {$relativePath}");
    
    return $relativePath;
}

function convertMpegToMp3(string $sourcePath, string $destPath): bool {
    $ffmpegPaths = [
        'ffmpeg',
        '/usr/bin/ffmpeg',
        '/usr/local/bin/ffmpeg',
        'C:\ffmpeg\bin\ffmpeg.exe',
        '/home/vol*/ffmpeg',
    ];
    
    $ffmpegPath = null;
    foreach ($ffmpegPaths as $path) {
        $test = escapeshellcmd($path) . ' -version 2>&1';
        exec($test, $testOutput, $testReturn);
        if ($testReturn === 0) {
            $ffmpegPath = $path;
            error_log("Found ffmpeg at: {$ffmpegPath}");
            break;
        }
    }
    
    if (!$ffmpegPath) {
        error_log("FFMPEG not found on server. Cannot convert MPEG to MP3.");
        return false;
    }
    
    $command = escapeshellcmd($ffmpegPath) . ' -i ' . escapeshellarg($sourcePath) . ' -acodec libmp3lame -ab 128k -ar 44100 -ac 2 ' . escapeshellarg($destPath) . ' 2>&1';
    error_log("Running conversion command: {$command}");
    
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0 && file_exists($destPath) && filesize($destPath) > 0) {
        error_log("Successfully converted MPEG to MP3: {$destPath}");
        error_log("Original size: " . filesize($sourcePath) . " bytes");
        error_log("MP3 size: " . filesize($destPath) . " bytes");
        return true;
    }
    
    error_log("FFMPEG conversion failed. Return code: {$returnCode}");
    error_log("Output: " . implode("\n", $output));
    return false;
}

// ============================================================================
// AUTHENTICATION
// ============================================================================
if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: /admin/login.php?error=login_required');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$conn = db();

if (!$conn) {
    die('Unable to connect to database.');
}

function getAdminUser(PDO $conn, int $userId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, full_name, email, profile_image, role, is_active, created_at
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) return null;
        if (strtolower((string)($user['role'] ?? '')) !== 'admin') return null;

        return $user;
    } catch (PDOException $e) {
        error_log("getAdminUser: " . $e->getMessage());
        return null;
    }
}

// ============================================================================
// DATA FETCHING FUNCTIONS
// ============================================================================
function getSubjects(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT id, name, slug, icon, is_active
            FROM subjects
            ORDER BY name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getSubjects: " . $e->getMessage());
        return [];
    }
}

function getCategories(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT id, subject_id, name, slug, is_active
            FROM categories
            ORDER BY name ASC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCategories: " . $e->getMessage());
        return [];
    }
}

function getContents(PDO $conn): array {
    try {
        $stmt = $conn->query("
            SELECT
                c.id, c.subject_id, c.category_id, c.title, c.slug, c.short_description,
                c.body, c.body_html, c.arabic_text, c.translation, c.reference_text,
                c.source_name, c.tags, c.status, c.created_by, c.approved_by,
                c.sort_order, c.is_featured, c.audio_path, c.video_path, c.video_url,
                c.created_at, c.updated_at, c.published_at, c.image_path,
                c.allow_auto_completion, c.allow_manual_completion,
                s.name AS subject_name, s.icon AS subject_icon,
                cat.name AS category_name,
                (SELECT COUNT(*) FROM content_parts WHERE content_id = c.id) as total_parts,
                (SELECT COUNT(*) FROM quizzes WHERE content_id = c.id AND mode = 'lesson' AND status = 'published') as has_quiz
            FROM contents c
            LEFT JOIN subjects s ON s.id = c.subject_id
            LEFT JOIN categories cat ON cat.id = c.category_id
            ORDER BY c.created_at DESC, c.id DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getContents: " . $e->getMessage());
        return [];
    }
}

function getContentById(PDO $conn, int $id): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT id, subject_id, category_id, title, slug, short_description,
                   body, body_html, audio_path, video_path, video_url,
                   arabic_text, translation, reference_text, source_name, tags,
                   status, created_by, approved_by, sort_order, is_featured, published_at,
                   image_path, allow_auto_completion, allow_manual_completion
            FROM contents WHERE id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $content = $stmt->fetch() ?: null;
        
        if ($content && !empty($content['arabic_text'])) {
            $content['arabic_text'] = html_entity_decode($content['arabic_text'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($content && !empty($content['translation'])) {
            $content['translation'] = html_entity_decode($content['translation'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        return $content;
    } catch (PDOException $e) {
        error_log("getContentById: " . $e->getMessage());
        return null;
    }
}

// ============================================================================
// GET CONTENT PARTS
// ============================================================================
// ============================================================================
// GET PART VIDEOS (additional videos beyond the part's primary video_path/video_url)
// ============================================================================
function getPartVideos(PDO $conn, int $partId): array {
    try {
        $stmt = $conn->prepare("
            SELECT id, part_id, video_path, video_url, caption, sort_order
            FROM part_videos
            WHERE part_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([$partId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getPartVideos: " . $e->getMessage());
        return [];
    }
}

function getContentParts(PDO $conn, int $contentId): array {
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
        $stmt->execute([$contentId]);
        $parts = $stmt->fetchAll();
        
        foreach ($parts as &$part) {
            if (!empty($part['arabic_body'])) {
                $part['arabic_body'] = html_entity_decode($part['arabic_body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!empty($part['arabic_body_html'])) {
                $part['arabic_body_html'] = html_entity_decode($part['arabic_body_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!empty($part['swahili_body'])) {
                $part['swahili_body'] = html_entity_decode($part['swahili_body'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!empty($part['swahili_body_html'])) {
                $part['swahili_body_html'] = html_entity_decode($part['swahili_body_html'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!isset($part['display_mode']) || $part['display_mode'] === '') {
                $part['display_mode'] = 'sections';
            }
            // Additional videos beyond the primary video_path/video_url
            $part['videos'] = getPartVideos($conn, (int)$part['id']);
        }
        
        return $parts;
    } catch (PDOException $e) {
        error_log("getContentParts: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// GET PART SECTIONS
// ============================================================================
function getPartSections(PDO $conn, int $partId): array {
    try {
        $stmt = $conn->prepare("
            SELECT id, part_id, section_number, 
                   content, content_html,
                   language, direction, sort_order,
                   text_color, background_color,
                   video_path, video_url, video_caption, display_separate
            FROM part_sections
            WHERE part_id = ?
            ORDER BY sort_order ASC, section_number ASC
        ");
        $stmt->execute([$partId]);
        $sections = $stmt->fetchAll();
        
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

function getContentQuiz(PDO $conn, int $contentId): ?array {
    try {
        $stmt = $conn->prepare("
            SELECT q.*, (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as total_questions
            FROM quizzes q
            WHERE q.content_id = ? AND q.mode = 'lesson' AND q.status = 'published'
            LIMIT 1
        ");
        $stmt->execute([$contentId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log("getContentQuiz: " . $e->getMessage());
        return null;
    }
}

function getQuizQuestions(PDO $conn, int $quizId): array {
    try {
        $stmt = $conn->prepare("
            SELECT qq.*,
                   (SELECT JSON_ARRAYAGG(
                        JSON_OBJECT('id', qo.id, 'option_text', qo.option_text, 'is_correct', qo.is_correct, 'sort_order', qo.sort_order)
                    ) FROM quiz_options qo WHERE qo.question_id = qq.id) as options_json
            FROM quiz_questions qq
            WHERE qq.quiz_id = ?
            ORDER BY qq.sort_order ASC, qq.id ASC
        ");
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();
        foreach ($questions as &$q) {
            $q['options'] = json_decode($q['options_json'] ?? '[]', true) ?: [];
        }
        return $questions;
    } catch (PDOException $e) {
        error_log("getQuizQuestions: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// SAVE CONTENT PARTS WITH SECTIONS - FIXED
// ============================================================================
function saveContentParts(PDO $conn, int $contentId, array $parts): void {
    try {
        $keepIds = [];
        foreach ($parts as $part) {
            if (!empty($part['id'])) {
                $keepIds[] = (int)$part['id'];
            }
        }
        
        if (!empty($keepIds)) {
            $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
            $stmt = $conn->prepare("DELETE FROM content_parts WHERE content_id = ? AND id NOT IN ($placeholders)");
            $params = array_merge([$contentId], $keepIds);
            $stmt->execute($params);
        } else {
            $stmt = $conn->prepare("DELETE FROM content_parts WHERE content_id = ?");
            $stmt->execute([$contentId]);
        }
        
        $stmt = $conn->prepare("
            INSERT INTO content_parts (id, content_id, part_number, title, 
                arabic_body, arabic_body_html,
                swahili_body, swahili_body_html,
                section_order, audio_path, video_path, video_url, sort_order, display_mode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                part_number = VALUES(part_number),
                title = VALUES(title),
                arabic_body = VALUES(arabic_body),
                arabic_body_html = VALUES(arabic_body_html),
                swahili_body = VALUES(swahili_body),
                swahili_body_html = VALUES(swahili_body_html),
                section_order = VALUES(section_order),
                audio_path = VALUES(audio_path),
                video_path = VALUES(video_path),
                video_url = VALUES(video_url),
                sort_order = VALUES(sort_order),
                display_mode = VALUES(display_mode)
        ");
        
        foreach ($parts as $part) {
            $partId = !empty($part['id']) ? (int)$part['id'] : null;
            
            // Ensure display_mode is set and valid
            $displayMode = isset($part['display_mode']) && $part['display_mode'] !== '' 
                ? $part['display_mode'] 
                : 'sections';
            
            // Validate display_mode value
            if (!in_array($displayMode, ['sections', 'combined'])) {
                $displayMode = 'sections';
            }
            
            $stmt->execute([
                $partId,
                $contentId,
                $part['part_number'],
                $part['title'] ?? '',
                $part['arabic_body'] ?? '',
                $part['arabic_body_html'] ?? null,
                $part['swahili_body'] ?? '',
                $part['swahili_body_html'] ?? null,
                $part['section_order'] ?? 'swahili_first',
                $part['audio_path'] ?? null,
                $part['video_path'] ?? null,
                $part['video_url'] ?? null,
                $part['sort_order'] ?? $part['part_number'],
                $displayMode
            ]);
            
            // Get the actual part ID - ensure it's an integer
            $actualPartId = $partId;
            if ($actualPartId === null) {
                $actualPartId = (int)$conn->lastInsertId();
            }
            
            // Save sections for this part - ensure $actualPartId is an integer
            if (!empty($part['sections']) && $actualPartId > 0) {
                savePartSections($conn, $actualPartId, $part['sections']);
            }

            // Save this part's additional videos (beyond the primary
            // video_path/video_url handled above) - always call this, even
            // with an empty array, so removing all extra videos in the form
            // actually clears them out instead of leaving stale rows behind.
            if ($actualPartId > 0) {
                savePartVideos($conn, $actualPartId, $part['videos'] ?? []);
            }
        }
    } catch (PDOException $e) {
        error_log("saveContentParts: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================================
// SAVE PART VIDEOS (additional videos beyond the part's primary video)
// ============================================================================
function savePartVideos(PDO $conn, int $partId, array $videos): void {
    try {
        $stmt = $conn->prepare("DELETE FROM part_videos WHERE part_id = ?");
        $stmt->execute([$partId]);

        if (empty($videos)) {
            return;
        }

        $stmt = $conn->prepare("
            INSERT INTO part_videos (part_id, video_path, video_url, caption, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");

        $order = 0;
        foreach ($videos as $video) {
            $videoPath = trim($video['video_path'] ?? '');
            $videoUrl = trim($video['video_url'] ?? '');
            if ($videoPath === '' && $videoUrl === '') {
                continue; // skip empty rows (an "Add Video" block left blank)
            }
            $stmt->execute([
                $partId,
                $videoPath !== '' ? $videoPath : null,
                $videoUrl !== '' ? $videoUrl : null,
                trim($video['caption'] ?? '') ?: null,
                $order,
            ]);
            $order++;
        }
    } catch (PDOException $e) {
        error_log("savePartVideos: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================================
// SAVE PART SECTIONS
// ============================================================================
function savePartSections(PDO $conn, int $partId, array $sections): void {
    try {
        // Delete existing sections for this part
        $stmt = $conn->prepare("DELETE FROM part_sections WHERE part_id = ?");
        $stmt->execute([$partId]);
        
        if (empty($sections)) {
            return;
        }
        
        $stmt = $conn->prepare("
            INSERT INTO part_sections (part_id, section_number, content, content_html, language, direction, sort_order, text_color, background_color, video_path, video_url, video_caption, display_separate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($sections as $section) {
            $sectionNumber = (int)($section['section_number'] ?? 0);
            $content = trim($section['content'] ?? '');
            $contentHtml = trim($section['content_html'] ?? '');
            $language = $section['language'] ?? 'swahili';
            $direction = $section['direction'] ?? 'ltr';
            $sortOrder = (int)($section['sort_order'] ?? $sectionNumber);
            $textColor = $section['text_color'] ?? null;
            $bgColor = $section['background_color'] ?? null;
            
            $videoPath    = trim((string)($section['video_path'] ?? ''));
            $videoUrl     = trim((string)($section['video_url'] ?? ''));
            $videoCaption = trim((string)($section['video_caption'] ?? ''));
            
            // Keep the section if it has text OR a video
            if (empty($content) && empty($contentHtml) && $videoPath === '' && $videoUrl === '') {
                continue;
            }
            
            $stmt->execute([
                $partId,
                $sectionNumber,
                $content,
                $contentHtml,
                $language,
                $direction,
                $sortOrder,
                $textColor,
                $bgColor,
                $videoPath !== ''    ? $videoPath    : null,
                $videoUrl !== ''     ? $videoUrl     : null,
                $videoCaption !== '' ? $videoCaption : null,
                !empty($section['display_separate']) ? 1 : 0
            ]);
        }
    } catch (PDOException $e) {
        error_log("savePartSections: " . $e->getMessage());
        throw $e;
    }
}

// ============================================================================
// SAVE QUIZ - FIXED
// ============================================================================
function saveQuiz(PDO $conn, int $contentId, array $quizData, array $questions, int $subjectId = null): void {
    try {
        $stmt = $conn->prepare("SELECT id FROM quizzes WHERE content_id = ? AND mode = 'lesson'");
        $stmt->execute([$contentId]);
        $existingQuiz = $stmt->fetch();
        
        $passingScore = isset($quizData['passing_score']) ? (int)$quizData['passing_score'] : 70;
        $timeLimit = isset($quizData['time_limit_minutes']) && $quizData['time_limit_minutes'] !== '' 
            ? (int)$quizData['time_limit_minutes'] 
            : null;
        
        if ($existingQuiz) {
            $stmt = $conn->prepare("UPDATE quizzes SET title = ?, description = ?, passing_score = ?, time_limit_minutes = ?, status = 'published' WHERE id = ?");
            $stmt->execute([
                $quizData['title'], 
                $quizData['description'] ?? null, 
                $passingScore, 
                $timeLimit, 
                $existingQuiz['id']
            ]);
            $quizId = $existingQuiz['id'];
            $stmt = $conn->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
            $stmt->execute([$quizId]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO quizzes (content_id, subject_id, title, slug, description, difficulty, time_limit_minutes, total_points, max_attempts, status, mode, created_by, passing_score, created_at)
                VALUES (?, ?, ?, ?, ?, 'easy', ?, 0, 3, 'published', 'lesson', ?, ?, NOW())
            ");
            $slug = make_slug($quizData['title']) . '-' . uniqid();
            $stmt->execute([
                $contentId, 
                $subjectId, 
                $quizData['title'], 
                $slug, 
                $quizData['description'] ?? null, 
                $timeLimit, 
                $_SESSION['user_id'], 
                $passingScore
            ]);
            $quizId = $conn->lastInsertId();
        }
        
        $totalPoints = 0;
        $questionStmt = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text, question_type, explanation, points, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $optionStmt = $conn->prepare("INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)");
        
        foreach ($questions as $index => $q) {
            $points = (int)($q['points'] ?? 1);
            $totalPoints += $points;
            $questionType = $q['question_type'] ?? 'multiple_choice';
            
            $questionStmt->execute([
                $quizId, 
                $q['question_text'], 
                $questionType, 
                $q['explanation'] ?? null, 
                $points, 
                $index
            ]);
            $questionId = $conn->lastInsertId();
            
            if ($questionType === 'true_false') {
                $correctAnswer = isset($q['correct_answer']) ? strtoupper($q['correct_answer']) : '';
                $optionStmt->execute([$questionId, 'True', ($correctAnswer === 'TRUE') ? 1 : 0, 0]);
                $optionStmt->execute([$questionId, 'False', ($correctAnswer === 'FALSE') ? 1 : 0, 1]);
            } else {
                if (!empty($q['options'])) {
                    foreach ($q['options'] as $optIndex => $opt) {
                        if (empty($opt['text'])) continue;
                        $isCorrect = isset($opt['is_correct']) && $opt['is_correct'] ? 1 : 0;
                        $optionStmt->execute([$questionId, $opt['text'], $isCorrect, $optIndex]);
                    }
                }
            }
        }
        
        $stmt = $conn->prepare("UPDATE quizzes SET total_points = ? WHERE id = ?");
        $stmt->execute([$totalPoints, $quizId]);
    } catch (PDOException $e) {
        error_log("saveQuiz: " . $e->getMessage());
        throw $e;
    }
}

function contentSlugExists(PDO $conn, string $slug, ?int $excludeId = null): bool {
    try {
        if ($excludeId) {
            $stmt = $conn->prepare("SELECT id FROM contents WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $conn->prepare("SELECT id FROM contents WHERE slug = ? LIMIT 1");
            $stmt->execute([$slug]);
        }
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("contentSlugExists: " . $e->getMessage());
        return false;
    }
}

function uniqueContentSlug(PDO $conn, string $baseSlug, ?int $excludeId = null): string {
    $baseSlug = $baseSlug !== '' ? $baseSlug : 'content';
    $slug = $baseSlug;
    $counter = 1;

    while (contentSlugExists($conn, $slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    return $slug;
}

function deleteMediaFile(string $filePath): bool {
    if (empty($filePath)) {
        return false;
    }
    
    $relativePath = ltrim($filePath, '/');
    
    $possiblePaths = [
        $_SERVER['DOCUMENT_ROOT'] . '/' . $relativePath,
        $_SERVER['DOCUMENT_ROOT'] . '/myislam/' . $relativePath,
        dirname(__DIR__) . '/' . $relativePath,
        __DIR__ . '/../' . $relativePath,
    ];
    
    foreach ($possiblePaths as $fullPath) {
        $fullPath = str_replace('//', '/', $fullPath);
        if (file_exists($fullPath) && is_file($fullPath)) {
            if (unlink($fullPath)) {
                error_log("Successfully deleted file: {$fullPath}");
                return true;
            } else {
                error_log("Failed to delete file: {$fullPath}");
            }
        }
    }
    
    error_log("File not found for deletion: {$filePath}");
    return false;
}

$admin = getAdminUser($conn, $userId);
if (!$admin) {
    session_destroy();
    http_response_code(403);
    exit('Access denied. Admins only.');
}

// ============================================================================
// AJAX ENDPOINTS
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['edit'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $editId = (int)$_GET['edit'];
        $editContent = getContentById($conn, $editId);
        if (!$editContent) {
            echo json_encode(['error' => 'Content not found']);
            exit;
        }
        $parts = getContentParts($conn, $editId);
        
        // Load sections for each part
        foreach ($parts as &$part) {
            $part['sections'] = getPartSections($conn, $part['id']);
        }
        
        $quiz = getContentQuiz($conn, $editId);
        $questions = $quiz ? getQuizQuestions($conn, $quiz['id']) : [];
        
        echo json_encode([
            'id' => $editContent['id'],
            'subject_id' => $editContent['subject_id'],
            'category_id' => $editContent['category_id'],
            'title' => $editContent['title'],
            'slug' => $editContent['slug'],
            'short_description' => $editContent['short_description'],
            'body' => $editContent['body'],
            'body_html' => $editContent['body_html'],
            'audio_path' => $editContent['audio_path'] ?? null,
            'video_path' => $editContent['video_path'] ?? null,
            'video_url' => $editContent['video_url'] ?? null,
            'arabic_text' => $editContent['arabic_text'] ?? '',
            'translation' => $editContent['translation'] ?? '',
            'reference_text' => $editContent['reference_text'],
            'source_name' => $editContent['source_name'],
            'tags' => $editContent['tags'],
            'status' => $editContent['status'],
            'sort_order' => $editContent['sort_order'],
            'is_featured' => $editContent['is_featured'],
            'allow_auto_completion' => $editContent['allow_auto_completion'] ?? 1,
            'allow_manual_completion' => $editContent['allow_manual_completion'] ?? 1,
            'image_path' => $editContent['image_path'] ?? null,
            'parts' => $parts,
            'quiz' => $quiz,
            'questions' => $questions
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        error_log("AJAX edit error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['delete_media'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $contentId = isset($_GET['content_id']) ? (int)$_GET['content_id'] : 0;
        $partId = isset($_GET['part_id']) ? (int)$_GET['part_id'] : 0;
        $mediaType = isset($_GET['media_type']) ? trim($_GET['media_type']) : '';
        $fieldName = isset($_GET['field_name']) ? trim($_GET['field_name']) : '';
        
        if (!$contentId || !$mediaType || !$fieldName) {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM contents WHERE id = ? LIMIT 1");
        $stmt->execute([$contentId]);
        $content = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$content) {
            echo json_encode(['success' => false, 'message' => 'Content not found']);
            exit;
        }
        
        $fileToDelete = null;
        
        if ($partId > 0) {
            $stmt = $conn->prepare("SELECT * FROM content_parts WHERE id = ? AND content_id = ? LIMIT 1");
            $stmt->execute([$partId, $contentId]);
            $part = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$part) {
                echo json_encode(['success' => false, 'message' => 'Part not found']);
                exit;
            }
            
            $fileToDelete = $part[$fieldName] ?? null;
            $stmt = $conn->prepare("UPDATE content_parts SET {$fieldName} = NULL WHERE id = ?");
            $stmt->execute([$partId]);
            
        } else {
            $fileToDelete = $content[$fieldName] ?? null;
            $stmt = $conn->prepare("UPDATE contents SET {$fieldName} = NULL WHERE id = ?");
            $stmt->execute([$contentId]);
        }
        
        $fileDeleted = false;
        if ($fileToDelete) {
            $fileDeleted = deleteMediaFile($fileToDelete);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Media removed successfully',
            'file_deleted' => $fileDeleted,
            'file_path' => $fileToDelete
        ]);
        exit;
        
    } catch (Throwable $e) {
        error_log("AJAX delete_media error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

$message = '';
$messageType = 'success';
$editingContent = null;
$contentParts = [];
$contentQuiz = null;
$quizQuestions = [];

if (isset($_GET['edit']) && (int)$_GET['edit'] > 0) {
    $editingContent = getContentById($conn, (int)$_GET['edit']);
    if ($editingContent) {
        $contentParts = getContentParts($conn, $editingContent['id']);
        // Load sections for each part
        foreach ($contentParts as &$part) {
            $part['sections'] = getPartSections($conn, $part['id']);
        }
        $contentQuiz = getContentQuiz($conn, $editingContent['id']);
        if ($contentQuiz) {
            $quizQuestions = getQuizQuestions($conn, $contentQuiz['id']);
        }
    }
}

// ============================================================================
// POST HANDLER
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If the uploaded video (or audio) pushed the total request body past
    // PHP's post_max_size, PHP drops $_POST and $_FILES entirely and just
    // logs a warning - it does NOT stop the script or throw anything. That
    // means $action below silently becomes '', the save_content branch is
    // skipped, and execution falls through to the full HTML page render at
    // the bottom of this file. The AJAX caller then tries to JSON-parse an
    // entire HTML document and fails with "Unexpected token '<'".
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $maxPost = ini_get('post_max_size');
        $maxUpload = ini_get('upload_max_filesize');
        error_log("save_content: request body (" . $_SERVER['CONTENT_LENGTH'] . " bytes) exceeded post_max_size ({$maxPost}) or upload_max_filesize ({$maxUpload}); \$_POST/\$_FILES were emptied by PHP.");
        header('Content-Type: application/json');
        http_response_code(413);
        echo json_encode([
            'success' => false,
            'message' => "That file is too large for the server to accept (server limit: post_max_size={$maxPost}, upload_max_filesize={$maxUpload}). Please use a smaller video file, or paste a YouTube/Vimeo link in the Video URL field instead."
        ]);
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_content') {
        // Guard against ANY uncaught error/exception in the upload-handling
        // and part-building code below (before the DB transaction's own
        // try/catch further down). Previously this section had no try/catch
        // of its own, so a fatal error here (or a thrown exception from
        // handleFileUpload(), a malformed parts payload, etc.) produced
        // either a blank response or the web server's own HTML error page -
        // again showing up in the browser as "Unexpected token '<'" instead
        // of a JSON error the frontend could display.
        try {
        error_log("=== Starting save_content ===");
        
        $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        $subjectId = isset($_POST['subject_id']) ? (int)$_POST['subject_id'] : 0;
        $categoryId = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
        $title = trim((string)($_POST['title'] ?? ''));
        $slugInput = trim((string)($_POST['slug'] ?? ''));
        $shortDescription = trim((string)($_POST['short_description'] ?? ''));
        $referenceText = trim((string)($_POST['reference_text'] ?? ''));
        $sourceName = trim((string)($_POST['source_name'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'draft'));
        $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
        $allowAutoCompletion = isset($_POST['allow_auto_completion']) ? 1 : 0;
        $allowManualCompletion = isset($_POST['allow_manual_completion']) ? 1 : 0;
        
        $allowedTags = '<p><br><b><i><em><strong><u><s><a><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img><table><th><thead><tbody><span><div><figure><figcaption><audio><video><iframe><source>';
        
        // ============================================================
        // HANDLE AUDIO UPLOAD
        // ============================================================
        $audioPath = null;
        
        if ($contentId > 0) {
            $existingContent = getContentById($conn, $contentId);
            if ($existingContent && !empty($existingContent['audio_path'])) {
                $audioPath = $existingContent['audio_path'];
            }
        }
        
        if (isset($_POST['existing_audio']) && $_POST['existing_audio'] !== '') {
            $audioPath = $_POST['existing_audio'];
        }
        
        if (isset($_FILES['audio_file']) && $_FILES['audio_file']['error'] === UPLOAD_ERR_OK && $_FILES['audio_file']['size'] > 0) {
            $uploadedPath = handleFileUpload($_FILES['audio_file'], 'audio');
            if ($uploadedPath !== null) {
                $audioPath = $uploadedPath;
                error_log("New audio uploaded: " . $audioPath);
            } else {
                error_log("Audio upload failed");
            }
        }
        
        // ============================================================
        // HANDLE VIDEO UPLOAD
        // ============================================================
        $videoPath = null;
        
        if ($contentId > 0) {
            $existingContent = getContentById($conn, $contentId);
            if ($existingContent && !empty($existingContent['video_path'])) {
                $videoPath = $existingContent['video_path'];
            }
        }
        
        if (isset($_POST['existing_video_path']) && $_POST['existing_video_path'] !== '') {
            $videoPath = $_POST['existing_video_path'];
        }
        
        if (isset($_FILES['video_file']) && $_FILES['video_file']['error'] === UPLOAD_ERR_OK && $_FILES['video_file']['size'] > 0) {
            $uploadedPath = handleFileUpload($_FILES['video_file'], 'video');
            if ($uploadedPath !== null) {
                $videoPath = $uploadedPath;
                error_log("New video uploaded: " . $videoPath);
            } else {
                error_log("Video upload failed");
            }
        }
        
        $videoUrl = normalizeVideoEmbedUrl($_POST['video_url'] ?? '');
        
        if (!empty($videoUrl)) {
            error_log("Video URL converted to: " . $videoUrl);
        }
        
        // ============================================================
        // CONTENT PARTS WITH SECTIONS
        // ============================================================
        $hasParts = isset($_POST['has_parts']) ? 1 : 0;
        $parts = [];
        
        if ($hasParts && isset($_POST['parts'])) {
            foreach ($_POST['parts'] as $idx => $partData) {
                if (empty($partData['title'])) continue;
                
                $partId = isset($partData['id']) ? (int)$partData['id'] : 0;
                $displayMode = isset($partData['display_mode']) && $partData['display_mode'] !== '' 
                    ? $partData['display_mode'] 
                    : 'sections';
                
                // Validate display_mode
                if (!in_array($displayMode, ['sections', 'combined'])) {
                    $displayMode = 'sections';
                }
                
                // Process sections for this part
                $sections = [];
                if (isset($partData['sections']) && is_array($partData['sections'])) {
                    foreach ($partData['sections'] as $secIdx => $sectionData) {
                        $sectionContent = trim($sectionData['content'] ?? '');
                        $sectionHtml = trim($sectionData['content_html'] ?? '');
                        
                        // ---- Section video: uploaded file (keeps the old one if no new upload) ----
                        $secVideoPath = trim((string)($sectionData['existing_video_path'] ?? ''));
                        $secVideoPath = $secVideoPath !== '' ? $secVideoPath : null;

                        if (isset($_FILES['parts']['name'][$idx]['sections'][$secIdx]['video_file']) &&
                            $_FILES['parts']['error'][$idx]['sections'][$secIdx]['video_file'] === UPLOAD_ERR_OK &&
                            $_FILES['parts']['size'][$idx]['sections'][$secIdx]['video_file'] > 0) {
                            $file = [
                                'name'     => $_FILES['parts']['name'][$idx]['sections'][$secIdx]['video_file'],
                                'type'     => $_FILES['parts']['type'][$idx]['sections'][$secIdx]['video_file'],
                                'tmp_name' => $_FILES['parts']['tmp_name'][$idx]['sections'][$secIdx]['video_file'],
                                'error'    => $_FILES['parts']['error'][$idx]['sections'][$secIdx]['video_file'],
                                'size'     => $_FILES['parts']['size'][$idx]['sections'][$secIdx]['video_file'],
                            ];
                            $uploadedPath = handleFileUpload($file, 'video');
                            if ($uploadedPath !== null) {
                                $secVideoPath = $uploadedPath;
                            }
                        }

                        // ---- Section video: YouTube / Vimeo link or pasted <iframe> code ----
                        $secVideoUrl = normalizeVideoEmbedUrl($sectionData['video_url'] ?? '');
                        if ($secVideoUrl !== '' && !preg_match('#^https://#i', $secVideoUrl)) {
                            $secVideoUrl = ''; // only allow https embeds
                        }
                        $secVideoCaption = trim((string)($sectionData['video_caption'] ?? ''));
                        // Per-section "keep separate" flag (only matters when the part uses Combined Paragraph mode)
                        $secSeparate = !empty($sectionData['display_separate']) ? 1 : 0;

                        // Skip only if the section has no text AND no video
                        if (empty($sectionContent) && empty($sectionHtml) && $secVideoPath === null && $secVideoUrl === '') {
                            continue;
                        }
                        
                        $safeHtml = strip_tags($sectionHtml, $allowedTags);
                        $safeHtml = preg_replace('/href=["\']javascript:[^"\']*["\']/i', 'href="#"', $safeHtml);
                        if (empty($safeHtml) && !empty($sectionContent)) {
                            $safeHtml = nl2br(e($sectionContent));
                        }
                        
                        // Get colors - only keep a color the admin actually
                        // customized (color-picker onchange flips the hidden
                        // *_touched field to "1" - see updateColorPreview()
                        // in the admin JS). Otherwise the <input type=color>
                        // element's own default value would get saved as if
                        // it were a deliberate choice on every single
                        // section, which is exactly the bug content.php's
                        // isUntouchedDefaultColorPair() has to work around
                        // for legacy rows saved before this flag existed.
                        $textColorTouched = ($sectionData['text_color_touched'] ?? '0') === '1';
                        $bgColorTouched = ($sectionData['background_color_touched'] ?? '0') === '1';
                        $textColor = $textColorTouched ? ($sectionData['text_color'] ?? null) : null;
                        $bgColor = $bgColorTouched ? ($sectionData['background_color'] ?? null) : null;
                        
                        $sections[] = [
                            'section_number' => (int)($sectionData['section_number'] ?? count($sections) + 1),
                            'content' => $sectionContent,
                            'content_html' => $safeHtml,
                            'language' => $sectionData['language'] ?? 'swahili',
                            'direction' => $sectionData['direction'] ?? 'ltr',
                            'sort_order' => (int)($sectionData['sort_order'] ?? count($sections) + 1),
                            'text_color' => $textColor,
                            'background_color' => $bgColor,
                            'video_path' => $secVideoPath,
                            'video_url' => $secVideoUrl !== '' ? $secVideoUrl : null,
                            'video_caption' => $secVideoCaption !== '' ? $secVideoCaption : null,
                            'display_separate' => $secSeparate
                        ];
                    }
                }
                
                // Handle audio upload for part
                $partAudioPath = $partData['existing_audio'] ?? null;
                if (isset($_FILES['parts']['name'][$idx]['audio_file']) && 
                    $_FILES['parts']['error'][$idx]['audio_file'] === UPLOAD_ERR_OK &&
                    $_FILES['parts']['size'][$idx]['audio_file'] > 0) {
                    $file = [
                        'name' => $_FILES['parts']['name'][$idx]['audio_file'],
                        'type' => $_FILES['parts']['type'][$idx]['audio_file'],
                        'tmp_name' => $_FILES['parts']['tmp_name'][$idx]['audio_file'],
                        'error' => $_FILES['parts']['error'][$idx]['audio_file'],
                        'size' => $_FILES['parts']['size'][$idx]['audio_file']
                    ];
                    $uploadedPath = handleFileUpload($file, 'audio');
                    if ($uploadedPath !== null) {
                        $partAudioPath = $uploadedPath;
                    }
                }
                
                // Handle video upload for part
                $partVideoPath = $partData['existing_video_path'] ?? null;
                if (isset($_FILES['parts']['name'][$idx]['video_file']) && 
                    $_FILES['parts']['error'][$idx]['video_file'] === UPLOAD_ERR_OK &&
                    $_FILES['parts']['size'][$idx]['video_file'] > 0) {
                    $file = [
                        'name' => $_FILES['parts']['name'][$idx]['video_file'],
                        'type' => $_FILES['parts']['type'][$idx]['video_file'],
                        'tmp_name' => $_FILES['parts']['tmp_name'][$idx]['video_file'],
                        'error' => $_FILES['parts']['error'][$idx]['video_file'],
                        'size' => $_FILES['parts']['size'][$idx]['video_file']
                    ];
                    $uploadedPath = handleFileUpload($file, 'video');
                    if ($uploadedPath !== null) {
                        $partVideoPath = $uploadedPath;
                    }
                }
                
                // Handle additional videos for this part (beyond the single
                // primary video above) - mirrors the same upload-or-URL
                // pattern, just looped over parts[idx][videos][vIdx][...].
                $partExtraVideos = [];
                if (!empty($partData['videos']) && is_array($partData['videos'])) {
                    foreach ($partData['videos'] as $vIdx => $videoData) {
                        $existingVideoPath = trim($videoData['existing_video_path'] ?? '');
                        $videoFilePath = $existingVideoPath !== '' ? $existingVideoPath : null;

                        if (isset($_FILES['parts']['name'][$idx]['videos'][$vIdx]['video_file']) &&
                            $_FILES['parts']['error'][$idx]['videos'][$vIdx]['video_file'] === UPLOAD_ERR_OK &&
                            $_FILES['parts']['size'][$idx]['videos'][$vIdx]['video_file'] > 0) {
                            $file = [
                                'name' => $_FILES['parts']['name'][$idx]['videos'][$vIdx]['video_file'],
                                'type' => $_FILES['parts']['type'][$idx]['videos'][$vIdx]['video_file'],
                                'tmp_name' => $_FILES['parts']['tmp_name'][$idx]['videos'][$vIdx]['video_file'],
                                'error' => $_FILES['parts']['error'][$idx]['videos'][$vIdx]['video_file'],
                                'size' => $_FILES['parts']['size'][$idx]['videos'][$vIdx]['video_file']
                            ];
                            $uploadedPath = handleFileUpload($file, 'video');
                            if ($uploadedPath !== null) {
                                $videoFilePath = $uploadedPath;
                            }
                        }

                        $extraVideoUrl = normalizeVideoEmbedUrl($videoData['video_url'] ?? '');
                        $extraCaption = trim($videoData['caption'] ?? '');

                        if ($videoFilePath === null && $extraVideoUrl === '') {
                            continue; // this "+ Add Video" block was left empty
                        }

                        $partExtraVideos[] = [
                            'video_path' => $videoFilePath,
                            'video_url' => $extraVideoUrl !== '' ? $extraVideoUrl : null,
                            'caption' => $extraCaption !== '' ? $extraCaption : null,
                        ];
                    }
                }

                $parts[] = [
                    'id' => $partId,
                    'part_number' => (int)$partData['part_number'],
                    'title' => trim($partData['title']),
                    'sections' => $sections,
                    'display_mode' => $displayMode,
                    'audio_path' => $partAudioPath,
                    'video_path' => $partVideoPath,
                    'video_url' => (function () use ($partData) {
                        $normalized = normalizeVideoEmbedUrl($partData['video_url'] ?? '');
                        return $normalized !== '' ? $normalized : null;
                    })(),
                    'videos' => $partExtraVideos,
                    'sort_order' => (int)$partData['part_number'],
                    // Legacy fields (for backwards compatibility)
                    'arabic_body' => '',
                    'arabic_body_html' => null,
                    'swahili_body' => '',
                    'swahili_body_html' => null,
                    'section_order' => 'swahili_first'
                ];
            }
        }
        
        // Handle content background image upload
        $contentImage = $_POST['existing_content_image'] ?? null;
        
        if (isset($_FILES['content_image']) && $_FILES['content_image']['error'] === UPLOAD_ERR_OK && $_FILES['content_image']['size'] > 0) {
            $uploadDir = getUploadPath('contents');
            $ext = strtolower(pathinfo($_FILES['content_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $filename = 'content_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['content_image']['tmp_name'], $uploadDir . $filename)) {
                    if ($contentId > 0 && !empty($contentImage) && file_exists($_SERVER['DOCUMENT_ROOT'] . $contentImage)) {
                        @unlink($_SERVER['DOCUMENT_ROOT'] . $contentImage);
                    }
                    $contentImage = getUploadUrl('contents') . $filename;
                }
            }
        }
        
        // ============================================================
        // QUIZ
        // ============================================================
        $hasQuiz = isset($_POST['has_quiz']) ? 1 : 0;
        $quizData = null;
        $questions = [];
        
        if ($hasQuiz) {
            $quizData = [
                'title' => trim($_POST['quiz_title'] ?? 'Knowledge Check'),
                'description' => trim($_POST['quiz_description'] ?? ''),
                'passing_score' => (int)($_POST['passing_score'] ?? 70),
                'time_limit_minutes' => !empty($_POST['time_limit_minutes']) ? (int)$_POST['time_limit_minutes'] : null,
            ];
            
            if (isset($_POST['questions'])) {
                foreach ($_POST['questions'] as $qIndex => $qData) {
                    if (empty($qData['question_text'])) continue;
                    $qType = $qData['question_type'] ?? 'multiple_choice';
                    $question = [
                        'question_text' => trim($qData['question_text']),
                        'question_type' => $qType,
                        'explanation' => trim($qData['explanation'] ?? ''),
                        'points' => (int)($qData['points'] ?? 1),
                        'correct_answer' => $qType === 'true_false' ? ($qData['correct_answer'] ?? '') : null,
                        'options' => []
                    ];
                    if ($qType === 'multiple_choice') {
                        if (isset($qData['options']) && is_array($qData['options'])) {
                            $correctIndex = (int)($qData['correct_option_index'] ?? -1);
                            foreach ($qData['options'] as $optIndex => $optText) {
                                if (trim($optText) === '') continue;
                                $question['options'][] = [
                                    'text' => trim($optText),
                                    'is_correct' => ($optIndex == $correctIndex) ? 1 : 0
                                ];
                            }
                        }
                    }
                    $questions[] = $question;
                }
            }
        }
        
        if (!in_array($status, ['draft', 'pending', 'published', 'rejected'], true)) {
            $status = 'draft';
        }
        
        $slug = make_slug($slugInput !== '' ? $slugInput : $title);
        
        if ($subjectId <= 0) {
            $message = 'Please select a subject.';
            $messageType = 'danger';
        } elseif ($categoryId <= 0) {
            $message = 'Please select a category.';
            $messageType = 'danger';
        } elseif ($title === '') {
            $message = 'Content title is required.';
            $messageType = 'danger';
        } elseif ($hasParts && empty($parts)) {
            $message = 'Please add at least one content part.';
            $messageType = 'danger';
        } else {
            $slug = uniqueContentSlug($conn, $slug, $contentId > 0 ? $contentId : null);
            
            try {
                $conn->beginTransaction();
                
                $publishedAt = ($status === 'published') ? date('Y-m-d H:i:s') : null;
                $approvedBy = ($status === 'published') ? $userId : null;
                $shouldNotify = false;
                $newContentId = null;
                
                if ($contentId > 0) {
                    // UPDATE EXISTING CONTENT
                    $current = getContentById($conn, $contentId);
                    $wasPublished = ($current['status'] ?? '') === 'published';
                    $willBePublished = ($status === 'published');
                    $shouldNotify = (!$wasPublished && $willBePublished);
                    
                    if ($current && $wasPublished && $willBePublished && !empty($current['published_at'])) {
                        $publishedAt = $current['published_at'];
                    }
                    
                    $sql = "UPDATE contents SET 
                        subject_id = :subject_id,
                        category_id = :category_id,
                        title = :title,
                        slug = :slug,
                        short_description = :short_description,
                        body = :body,
                        body_html = :body_html,
                        arabic_text = :arabic_text,
                        translation = :translation,
                        audio_path = :audio_path,
                        video_path = :video_path,
                        video_url = :video_url,
                        reference_text = :reference_text,
                        source_name = :source_name,
                        tags = :tags,
                        image_path = :image_path,
                        status = :status,
                        approved_by = :approved_by,
                        sort_order = :sort_order,
                        is_featured = :is_featured,
                        allow_auto_completion = :allow_auto_completion,
                        allow_manual_completion = :allow_manual_completion,
                        published_at = :published_at,
                        updated_at = NOW()
                    WHERE id = :id";
                    
                    $stmt = $conn->prepare($sql);
                    
                    $stmt->bindValue(':subject_id', $subjectId, PDO::PARAM_INT);
                    $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
                    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
                    $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
                    $stmt->bindValue(':short_description', $shortDescription !== '' ? $shortDescription : null, PDO::PARAM_STR);
                    $stmt->bindValue(':body', $hasParts ? 'Content divided into parts below.' : '', PDO::PARAM_STR);
                    $stmt->bindValue(':body_html', $hasParts ? null : '', PDO::PARAM_STR);
                    $stmt->bindValue(':arabic_text', null, PDO::PARAM_STR);
                    $stmt->bindValue(':translation', null, PDO::PARAM_STR);
                    $stmt->bindValue(':audio_path', $audioPath, PDO::PARAM_STR);
                    $stmt->bindValue(':video_path', $videoPath, PDO::PARAM_STR);
                    $stmt->bindValue(':video_url', $videoUrl !== '' ? $videoUrl : null, PDO::PARAM_STR);
                    $stmt->bindValue(':reference_text', $referenceText !== '' ? $referenceText : null, PDO::PARAM_STR);
                    $stmt->bindValue(':source_name', $sourceName !== '' ? $sourceName : null, PDO::PARAM_STR);
                    $stmt->bindValue(':tags', $tags !== '' ? $tags : null, PDO::PARAM_STR);
                    $stmt->bindValue(':image_path', $contentImage, PDO::PARAM_STR);
                    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
                    $stmt->bindValue(':approved_by', $approvedBy, PDO::PARAM_INT);
                    $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
                    $stmt->bindValue(':is_featured', $isFeatured, PDO::PARAM_INT);
                    $stmt->bindValue(':allow_auto_completion', $allowAutoCompletion, PDO::PARAM_INT);
                    $stmt->bindValue(':allow_manual_completion', $allowManualCompletion, PDO::PARAM_INT);
                    $stmt->bindValue(':published_at', $publishedAt, PDO::PARAM_STR);
                    $stmt->bindValue(':id', $contentId, PDO::PARAM_INT);
                    
                    $stmt->execute();
                    
                    if ($hasParts) {
                        saveContentParts($conn, $contentId, $parts);
                    } else {
                        $stmt = $conn->prepare("DELETE FROM content_parts WHERE content_id = ?");
                        $stmt->execute([$contentId]);
                    }
                    
                    if ($hasQuiz && $quizData && !empty($questions)) {
                        saveQuiz($conn, $contentId, $quizData, $questions, $subjectId);
                    } elseif (!$hasQuiz && $contentId > 0) {
                        $stmt = $conn->prepare("DELETE q, qq FROM quizzes q LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id WHERE q.content_id = ? AND q.mode = 'lesson'");
                        $stmt->execute([$contentId]);
                    }
                    
                    $message = 'Content updated successfully.';
                    
                } else {
                    // INSERT NEW CONTENT
                    $shouldNotify = ($status === 'published');
                    $approvedBy = ($status === 'published') ? $userId : null;
                    
                    $sql = "INSERT INTO contents SET 
                        subject_id = :subject_id,
                        category_id = :category_id,
                        title = :title,
                        slug = :slug,
                        short_description = :short_description,
                        body = :body,
                        body_html = :body_html,
                        arabic_text = :arabic_text,
                        translation = :translation,
                        audio_path = :audio_path,
                        video_path = :video_path,
                        video_url = :video_url,
                        reference_text = :reference_text,
                        source_name = :source_name,
                        tags = :tags,
                        image_path = :image_path,
                        status = :status,
                        created_by = :created_by,
                        approved_by = :approved_by,
                        sort_order = :sort_order,
                        is_featured = :is_featured,
                        allow_auto_completion = :allow_auto_completion,
                        allow_manual_completion = :allow_manual_completion,
                        published_at = :published_at,
                        created_at = NOW(),
                        updated_at = NOW()";
                    
                    $stmt = $conn->prepare($sql);
                    
                    $stmt->bindValue(':subject_id', $subjectId, PDO::PARAM_INT);
                    $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
                    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
                    $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
                    $stmt->bindValue(':short_description', $shortDescription !== '' ? $shortDescription : null, PDO::PARAM_STR);
                    $stmt->bindValue(':body', $hasParts ? 'Content divided into parts below.' : '', PDO::PARAM_STR);
                    $stmt->bindValue(':body_html', $hasParts ? null : '', PDO::PARAM_STR);
                    $stmt->bindValue(':arabic_text', null, PDO::PARAM_STR);
                    $stmt->bindValue(':translation', null, PDO::PARAM_STR);
                    $stmt->bindValue(':audio_path', $audioPath, PDO::PARAM_STR);
                    $stmt->bindValue(':video_path', $videoPath, PDO::PARAM_STR);
                    $stmt->bindValue(':video_url', $videoUrl !== '' ? $videoUrl : null, PDO::PARAM_STR);
                    $stmt->bindValue(':reference_text', $referenceText !== '' ? $referenceText : null, PDO::PARAM_STR);
                    $stmt->bindValue(':source_name', $sourceName !== '' ? $sourceName : null, PDO::PARAM_STR);
                    $stmt->bindValue(':tags', $tags !== '' ? $tags : null, PDO::PARAM_STR);
                    $stmt->bindValue(':image_path', $contentImage, PDO::PARAM_STR);
                    $stmt->bindValue(':status', $status, PDO::PARAM_STR);
                    $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
                    $stmt->bindValue(':approved_by', $approvedBy, PDO::PARAM_INT);
                    $stmt->bindValue(':sort_order', $sortOrder, PDO::PARAM_INT);
                    $stmt->bindValue(':is_featured', $isFeatured, PDO::PARAM_INT);
                    $stmt->bindValue(':allow_auto_completion', $allowAutoCompletion, PDO::PARAM_INT);
                    $stmt->bindValue(':allow_manual_completion', $allowManualCompletion, PDO::PARAM_INT);
                    $stmt->bindValue(':published_at', $publishedAt, PDO::PARAM_STR);
                    
                    $stmt->execute();
                    $newContentId = (int)$conn->lastInsertId();
                    
                    if ($hasParts && !empty($parts)) {
                        saveContentParts($conn, $newContentId, $parts);
                    }
                    
                    if ($hasQuiz && $quizData && !empty($questions)) {
                        saveQuiz($conn, $newContentId, $quizData, $questions, $subjectId);
                    }
                    
                    $message = 'Content created successfully.';
                }
                
                $conn->commit();
                error_log("Transaction committed successfully");
                
                if ($shouldNotify) {
                    $notifyTitle = "New Lesson: " . $title;
                    $notifyMessage = $shortDescription ?: "A new lesson has been added. Start learning now!";
                    $notifyLink = "/content.php?id=" . ($contentId > 0 ? $contentId : $newContentId);
                    createNotificationForAll($conn, 'lesson', $notifyTitle, $notifyMessage, $notifyLink, null);
                }
                
                $messageType = 'success';
                
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
                
                header("Location: admin_contents.php?message=" . urlencode($message) . "&type=success");
                exit;
                
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("save_content error: " . $e->getMessage());
                error_log("SQL State: " . $e->getCode());
                error_log("Error Info: " . print_r($e->errorInfo, true));
                
                // Check if it's a column not found error
                if (strpos($e->getMessage(), 'Unknown column') !== false) {
                    $message = 'Database error: Missing column. Please run database updates.';
                } else {
                    $message = 'Unable to save content: ' . $e->getMessage();
                }
                $messageType = 'danger';
                
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            } catch (Throwable $e) {
                $conn->rollBack();
                error_log("save_content throwable: " . $e->getMessage());
                $message = 'Unable to save content: ' . $e->getMessage();
                $messageType = 'danger';
                
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                }
            }
        }

        // Validation failed above (subject/category/title missing, or parts
        // required but none were provided). Every other path in this action
        // already exits with its own JSON/redirect response, so reaching
        // here means $message/$messageType were set by the validation
        // checks and never sent back to the browser. Without this, an AJAX
        // save request would fall through to the full HTML page render
        // below, and the frontend's `resp.json()` call would fail with
        // "Unexpected token '<'" because it received HTML instead of JSON.
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message ?? 'Please correct the errors and try again.']);
            exit;
        }
        } catch (Throwable $e) {
            // Catches anything thrown in the audio/video upload handling or
            // parts-building above, i.e. before the DB transaction's own
            // try/catch even starts. Without this, such an error used to
            // fall straight through to the full HTML page render at the
            // bottom of the file instead of a JSON response.
            error_log("save_content: uncaught error before transaction: " . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to save content: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    if ($action === 'toggle_status') {
        $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        if ($contentId > 0) {
            try {
                $stmt = $conn->prepare("SELECT status, title FROM contents WHERE id = ?");
                $stmt->execute([$contentId]);
                $current = $stmt->fetch();
                $wasPublished = ($current['status'] ?? '') === 'published';
                $contentTitle = $current['title'] ?? '';
                
                $stmt = $conn->prepare("UPDATE contents SET status = CASE WHEN status = 'published' THEN 'draft' ELSE 'published' END, approved_by = CASE WHEN status = 'published' THEN approved_by ELSE ? END, published_at = CASE WHEN status = 'published' THEN published_at ELSE NOW() END WHERE id = ?");
                $stmt->execute([$userId, $contentId]);
                
                if (!$wasPublished) {
                    $notifyTitle = "New Lesson: " . $contentTitle;
                    $notifyMessage = "A new lesson has been published. Check it out!";
                    $notifyLink = "/content.php?id=" . $contentId;
                    createNotificationForAll($conn, 'lesson', $notifyTitle, $notifyMessage, $notifyLink, null);
                }
                
                $message = 'Content status updated.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_status: " . $e->getMessage());
                $message = 'Unable to update content status: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
        
        header("Location: admin_contents.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit;
    }

    if ($action === 'toggle_completion_lock') {
        $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        $field = isset($_POST['field']) ? trim((string)$_POST['field']) : '';
        $column = null;
        if ($field === 'auto') {
            $column = 'allow_auto_completion';
        } elseif ($field === 'manual') {
            $column = 'allow_manual_completion';
        }

        if ($contentId > 0 && $column !== null) {
            try {
                $stmt = $conn->prepare("UPDATE contents SET {$column} = IF({$column} = 1, 0, 1), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$contentId]);

                $stmt = $conn->prepare("SELECT {$column} AS new_value FROM contents WHERE id = ?");
                $stmt->execute([$contentId]);
                $newValue = (int)($stmt->fetchColumn() ?: 0);

                $label = $field === 'auto' ? 'Automatic (quiz) completion' : 'Manual completion';
                $message = $label . ' for this content is now ' . ($newValue === 1 ? 'enabled' : 'disabled') . '.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("toggle_completion_lock: " . $e->getMessage());
                $message = 'Unable to update completion setting: ' . $e->getMessage();
                $messageType = 'danger';
                $newValue = null;
            }
        } else {
            $message = 'Invalid completion lock request.';
            $messageType = 'danger';
            $newValue = null;
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => $messageType === 'success',
                'message' => $message,
                'field' => $field,
                'new_value' => $newValue
            ]);
            exit;
        }

        header("Location: admin_contents.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit;
    }

    if ($action === 'bulk_completion_lock') {
        // Locks or unlocks BOTH completion paths (auto-complete via quiz,
        // and the manual "Complete Lesson" button) for EVERY content row at
        // once - the bulk counterpart to the per-row toggles above. Used
        // both for the "Lock All / Unlock All" button and to flip the
        // default for all existing content in one step.
        $direction = isset($_POST['direction']) ? trim((string)$_POST['direction']) : '';
        if (in_array($direction, ['lock', 'unlock'], true)) {
            $newFlag = $direction === 'lock' ? 0 : 1;
            try {
                $stmt = $conn->prepare("UPDATE contents SET allow_auto_completion = ?, allow_manual_completion = ?, updated_at = NOW()");
                $stmt->execute([$newFlag, $newFlag]);
                $affected = $stmt->rowCount();

                $message = $direction === 'lock'
                    ? "Locked completion (auto + manual) on {$affected} content(s)."
                    : "Unlocked completion (auto + manual) on {$affected} content(s).";
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("bulk_completion_lock: " . $e->getMessage());
                $message = 'Unable to update contents in bulk: ' . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid bulk lock request.';
            $messageType = 'danger';
        }

        header("Location: admin_contents.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit;
    }
    
    if ($action === 'delete_quiz') {
        $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        if ($contentId > 0) {
            try {
                $stmt = $conn->prepare("DELETE q, qq FROM quizzes q LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id WHERE q.content_id = ? AND q.mode = 'lesson'");
                $stmt->execute([$contentId]);
                $message = 'Quiz deleted successfully.';
                $messageType = 'success';
            } catch (PDOException $e) {
                error_log("delete_quiz: " . $e->getMessage());
                $message = 'Unable to delete quiz: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
        
        header("Location: admin_contents.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit;
    }
    
    if ($action === 'delete_content') {
        $contentId = isset($_POST['content_id']) ? (int)$_POST['content_id'] : 0;
        if ($contentId > 0) {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare("DELETE q, qq, qo FROM quizzes q LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id LEFT JOIN quiz_options qo ON qo.question_id = qq.id WHERE q.content_id = ? AND q.mode = 'lesson'");
                $stmt->execute([$contentId]);
                $stmt = $conn->prepare("DELETE FROM content_parts WHERE content_id = ?");
                $stmt->execute([$contentId]);
                $stmt = $conn->prepare("DELETE FROM user_content_progress WHERE content_id = ?");
                $stmt->execute([$contentId]);
                $stmt = $conn->prepare("DELETE FROM contents WHERE id = ?");
                $stmt->execute([$contentId]);
                $conn->commit();
                $message = 'Content deleted successfully.';
                $messageType = 'success';
            } catch (PDOException $e) {
                $conn->rollBack();
                error_log("delete_content: " . $e->getMessage());
                $message = 'Unable to delete content: ' . $e->getMessage();
                $messageType = 'danger';
            }
        } else {
            $message = 'Invalid content ID.';
            $messageType = 'danger';
        }
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => $messageType === 'success', 'message' => $message]);
            exit;
        }
        header("Location: admin_contents.php?message=" . urlencode($message) . "&type=" . $messageType);
        exit;
    }
}

if (isset($_GET['message'])) {
    $message = urldecode($_GET['message']);
    $messageType = $_GET['type'] ?? 'success';
}

$subjects = getSubjects($conn);
$categories = getCategories($conn);
$contents = getContents($conn);

$totalContents = count($contents);
$publishedContents = count(array_filter($contents, fn($c) => (string)($c['status'] ?? '') === 'published'));
$draftContents = count(array_filter($contents, fn($c) => (string)($c['status'] ?? '') === 'draft'));
$usedSubjects = count(array_unique(array_filter(array_map(fn($c) => (int)($c['subject_id'] ?? 0), $contents))));

$mainNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => true],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false],
];

$sidebarNavigation = [
    ['name' => 'Dashboard', 'link' => 'dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => 'Subjects', 'link' => 'admin_subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => 'Categories', 'link' => 'admin_categories.php', 'active' => false, 'icon' => '🗂️'],
    ['name' => 'Contents', 'link' => 'admin_contents.php', 'active' => true, 'icon' => '📘'],
    ['name' => 'Quizzes', 'link' => 'admin_quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => 'Users', 'link' => 'admin_users.php', 'active' => false, 'icon' => '👥'],
    ['name' => 'Logout', 'link' => '../logout.php', 'active' => false, 'icon' => '🚪'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Admin Contents - MyIslam</title>

<!-- CKEditor from CDN -->
<script src="https://cdn.ckeditor.com/ckeditor5/40.2.0/classic/ckeditor.js"></script>

<style>
*{box-sizing:border-box;margin:0;padding:0}

/* ===== DARK MODE AS DEFAULT ===== */
:root {
    /* Default to dark mode variables */
    --bg: #0f1720;
    --surface: #18212b;
    --surface-2: #1d2833;
    --surface-3: #22303c;
    --line: #304150;
    --text: #edf2f7;
    --text-secondary: #cbd5e1;
    --muted: #94a3b8;
    --brand: #4C9172;
    --brand-soft: rgba(76, 145, 114, 0.16);
    --gold: #C9A85E;
    --gold-soft: rgba(201, 168, 94, 0.16);
    --accent: #6FA0C4;
    --warning: #f59e0b;
    --danger: #ef4444;
    --success: #10b981;
    --header-bg: #13202a;
    --header-height: 72px;
    --footer-height: 92px;
    --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.3);
    --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.4);
    --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.5);
    --radius-sm: 12px;
    --radius-md: 16px;
    --radius-lg: 20px;
}

/* ===== LIGHT MODE OVERRIDES ===== */
body.light-mode {
    --bg: #f5f7f5;
    --surface: #ffffff;
    --surface-2: #f9fafb;
    --surface-3: #eef2f7;
    --line: #e5e7eb;
    --text: #1f2937;
    --text-secondary: #4b5563;
    --muted: #6b7280;
    --brand: #166534;
    --brand-soft: #e7f3eb;
    --gold: #9C7A2E;
    --gold-soft: rgba(156,122,46,0.10);
    --accent: #3b82f6;
    --header-bg: #e1f0e7;
}

body{font-family:system-ui,-apple-system,'Segoe UI','Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5}
a{text-decoration:none;color:inherit} button,input,textarea,select{font:inherit}

/* RTL/LTR styles for editor */
.ck-editor__editable[dir="rtl"] {
    direction: rtl !important;
    text-align: right !important;
    font-family: 'Amiri', 'Scheherazade New', 'Traditional Arabic', serif !important;
    font-size: 20px !important;
}

.ck-editor__editable[dir="ltr"] {
    direction: ltr !important;
    text-align: left !important;
}

.app-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:var(--header-height);background:var(--header-bg);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:12px 24px;backdrop-filter:blur(10px);gap:18px}
.header-left,.header-right{display:flex;align-items:center;gap:12px}
.header-left{flex:1;min-width:0}
.header-top-nav{display:flex;align-items:center;gap:8px;overflow-x:auto}
.header-top-link{height:42px;padding:0 14px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:700;transition:.2s}
.header-top-link:hover,.header-top-link.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}
.menu-toggle,.icon-btn,.lang-btn,.brand-box{height:44px;min-width:44px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;transition:.2s;cursor:pointer}
.menu-toggle:hover,.icon-btn:hover,.lang-btn:hover,.profile-btn:hover{transform:translateY(-2px);box-shadow:var(--shadow-sm)}
.menu-toggle{font-size:22px}.lang-btn{min-width:56px;padding:0 14px;font-weight:600}
.brand-box{font-size:22px;font-weight:700;color:var(--brand);background:transparent;border:none;white-space:nowrap}
.btn.danger{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.btn.danger:hover{background:rgba(239,68,68,.2)}
.btn-sm{padding:6px 12px;font-size:12px}
.profile-area{position:relative}
.profile-btn{width:44px;height:44px;border-radius:50%;overflow:hidden;background:var(--surface);border:2px solid var(--brand);cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;color:var(--brand);font-weight:700}
.profile-btn img{width:100%;height:100%;object-fit:cover}
.profile-menu{position:absolute;top:56px;right:0;width:280px;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:16px;display:none;box-shadow:var(--shadow-lg);z-index:1001}
.profile-menu.show{display:block}
.profile-menu-top{display:flex;gap:12px;padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--line)}
.profile-menu-top img,.profile-avatar-fallback{width:56px;height:56px;border-radius:50%}
.profile-avatar-fallback{display:inline-flex;align-items:center;justify-content:center;background:var(--brand-soft);color:var(--brand);font-weight:700;font-size:16px}
.profile-menu-top h4{font-size:16px;margin-bottom:4px;font-weight:600}
.profile-menu-top p{font-size:12px;color:var(--muted)}
.profile-menu a{display:block;padding:10px 12px;border-radius:var(--radius-sm);font-size:14px;transition:background .2s;margin:4px 0}
.profile-menu a:hover{background:var(--brand-soft);color:var(--brand)}

.sidebar-overlay{display:none;position:fixed;top:var(--header-height);left:0;width:100%;height:calc(100vh - var(--header-height));background:rgba(0,0,0,.5);z-index:1100;backdrop-filter:blur(4px)}
.sidebar-overlay.show{display:block}
.sidebar{position:fixed;top:var(--header-height);left:-100%;width:320px;height:calc(100vh - var(--header-height));background:var(--surface);border-right:1px solid var(--line);padding:24px 20px;z-index:1200;transition:left .3s ease;overflow-y:auto}
.sidebar.open{left:0}
.sidebar-title{font-size:12px;color:var(--muted);text-transform:uppercase;margin-bottom:20px;font-weight:700;letter-spacing:1px;padding:0 8px}
.subject-list{display:grid;gap:8px}
.subject-link{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:var(--radius-md);background:var(--surface-2);border:1px solid transparent;font-size:14px;font-weight:500;transition:all .2s}
.subject-link:hover{background:var(--brand-soft);border-color:var(--brand);transform:translateX(4px)}
.subject-link.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand)}

/* Wider main content */
.main-content{max-width:1600px;width:100%;margin:0 auto;padding:calc(var(--header-height) + 24px) 24px calc(var(--footer-height) + 100px)}
.page-stack{display:grid;gap:24px}
.section-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-lg);padding:24px;transition:.2s}
.section-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.hero-card{background:linear-gradient(135deg,var(--brand-soft) 0%,var(--surface) 100%);border:none;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px}
.hero-card h1{font-size:32px;margin-bottom:8px;font-weight:700}
.hero-card p{color:var(--text-secondary);font-size:16px}

.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.stat-card{text-align:center;padding:20px}
.stat-value{font-size:32px;font-weight:800;color:var(--brand);margin-bottom:8px}
.stat-label{font-size:13px;color:var(--muted)}

.btn{padding:12px 20px;border-radius:14px;font-weight:700;border:none;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:8px}
.btn.primary{background:var(--brand);color:#fff}
.btn.secondary{background:var(--surface-2);border:1px solid var(--line);color:var(--text)}
.btn.warn{background:rgba(245,158,11,.12);color:var(--warning);border:1px solid rgba(245,158,11,.3)}
.btn:hover{transform:translateY(-2px)}

.filters-row{display:grid;grid-template-columns:1fr 220px 220px;gap:12px;margin-bottom:16px}
.search-wrap{position:relative}
.search-input{width:100%;height:48px;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:0 16px 0 46px;outline:none}
.search-input:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:18px}

.contents-list{display:grid;gap:12px}
.content-item{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px;flex-wrap:wrap}
.content-left{display:flex;align-items:flex-start;gap:12px;flex:1;min-width:200px}
.content-icon{width:46px;height:46px;border-radius:14px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.content-title{font-size:16px;font-weight:800;margin-bottom:4px}
.content-meta{font-size:12px;color:var(--muted);line-height:1.7}
.content-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;background:var(--brand-soft);color:var(--brand)}
.badge.neutral{background:var(--surface-3);color:var(--muted)}
.badge.warn{background:rgba(245,158,11,.12);color:var(--warning)}
.badge.success{background:rgba(16,185,129,.12);color:var(--success)}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}

/* Modal - Wider */
.modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:2000;backdrop-filter:blur(6px);align-items:center;justify-content:center}
.modal{background:var(--surface);border-radius:var(--radius-lg);max-width:98vw;width:98%;max-height:95vh;overflow-y:auto;box-shadow:var(--shadow-lg);border:1px solid var(--line)}
@media(min-width:1200px){.modal{max-width:1200px;width:95%}}
.modal-header{padding:24px 24px 16px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.modal-header h2{font-size:22px;font-weight:700}
.modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:var(--muted);padding:0 8px}
.modal-body{padding:24px}
.form-fields{display:grid;gap:14px}
.form-row{display:grid;gap:8px}
.form-row label{font-size:14px;font-weight:700}
.form-input,.form-textarea,.form-select{width:100%;border-radius:12px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);padding:12px 14px;outline:none}
.form-input:focus,.form-textarea:focus,.form-select:focus{border-color:var(--brand);background:var(--surface);box-shadow:0 0 0 4px rgba(34,197,94,.08)}
.form-textarea{min-height:120px;resize:vertical}
.inline-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.action-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}

/* Tool Tabs */
.tool-tabs{display:flex;gap:8px;margin-bottom:16px;border-bottom:2px solid var(--line);padding-bottom:8px;flex-wrap:wrap}
.tool-tab{padding:8px 20px;border-radius:8px 8px 0 0;cursor:pointer;font-weight:600;font-size:13px;transition:.2s;background:var(--surface-2);color:var(--muted);border:1px solid transparent;border-bottom:none}
.tool-tab.active{background:var(--brand-soft);color:var(--brand);border-color:var(--brand);border-bottom:2px solid var(--brand-soft);margin-bottom:-2px}
.tool-tab:hover{background:var(--brand-soft);color:var(--brand)}

/* Parts */
.parts-section,.quiz-section{margin-top:20px;padding-top:20px;border-top:2px solid var(--brand-soft)}
.parts-header,.quiz-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px}
.part-item{background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:12px;position:relative}
.part-item.drag-over{border-color:var(--accent);background:rgba(59,130,246,.05);box-shadow:0 0 0 4px rgba(59,130,246,.1)}
.part-item.dragging{opacity:0.5}
.part-drag-handle{position:absolute;top:16px;right:16px;cursor:move;font-size:24px;opacity:0.5;user-select:none;z-index:10}
.part-drag-handle:hover{opacity:1}

/* Display Mode Selector */
.display-mode-selector{background:var(--surface-3);border-radius:8px;padding:12px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.display-mode-selector label{font-weight:700;font-size:14px;margin:0;color:var(--text-secondary)}
.display-mode-selector select{flex:1;min-width:180px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--surface);font-weight:600}
.display-mode-selector .mode-info{font-size:12px;color:var(--muted);flex-basis:100%}
.display-mode-selector .mode-info .mode-icon{font-size:16px}

/* Section styling */
.section-item{background:var(--surface-3);border:1px solid var(--line);border-radius:10px;padding:14px;margin-bottom:10px;position:relative}
.section-item .section-header-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px}
.section-item .section-number{font-weight:700;color:var(--text-secondary);font-size:13px}
.section-item .section-remove-btn{cursor:pointer;color:var(--danger);background:none;border:none;font-size:16px;padding:4px 8px}
.section-insert-divider{display:flex;align-items:center;gap:10px;margin:-4px 0 10px;}
.section-insert-divider::before,.section-insert-divider::after{content:"";flex:1;border-top:1px dashed var(--line);}
.section-insert-btn{flex-shrink:0;cursor:pointer;background:var(--surface);border:1px dashed var(--brand);color:var(--brand);font-size:12px;font-weight:600;padding:4px 12px;border-radius:999px;transition:all .15s;white-space:nowrap;}
.section-insert-btn:hover{background:var(--brand);color:#fff;border-style:solid;}
.section-item .section-language-selector{display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap}
.section-item .section-language-selector label{font-size:13px;font-weight:600}
.section-item .section-language-selector select{padding:6px 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface);flex:1;min-width:120px}
.section-item .section-language-selector .direction-badge{padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700}
.direction-badge.rtl{background:var(--brand-soft);color:var(--brand)}
.direction-badge.ltr{background:var(--gold-soft);color:var(--gold)}

/* Color picker styles */
.section-color-row{display:flex;gap:14px;margin-top:8px;flex-wrap:wrap;align-items:center}
.section-color-row .color-group{display:flex;align-items:center;gap:6px}
.section-color-row .color-group label{font-size:12px;font-weight:600;color:var(--text-secondary);margin:0}
.section-color-row .color-group input[type="color"]{width:40px;height:40px;padding:2px;border-radius:8px;border:2px solid var(--line);cursor:pointer;background:var(--surface)}
.section-color-row .color-group input[type="color"]::-webkit-color-swatch-wrapper{padding:0}
.section-color-row .color-group input[type="color"]::-webkit-color-swatch{border:none;border-radius:4px}
.section-color-row .color-reset-btn{padding:4px 12px;font-size:11px;border-radius:6px;background:var(--surface-2);border:1px solid var(--line);color:var(--text-secondary);cursor:pointer}
.section-color-row .color-reset-btn:hover{background:var(--danger);color:#fff;border-color:var(--danger)}
.section-color-row .color-preview{width:30px;height:30px;border-radius:6px;border:1px solid var(--line);display:inline-block}

.section-item .section-editor-wrapper{margin-top:6px}

/* CKEditor styling */
.ck-editor__editable {min-height:200px!important;background:#fff!important;border-radius:0 0 12px 12px!important;padding:0 20px!important}
.part-editor{width:100%;border-radius:12px;overflow:hidden;background:white}
.ck.ck-editor__top .ck-sticky-panel__content{border-radius:12px 12px 0 0!important}
.ck-editor{border:2px solid var(--line)!important;border-radius:12px!important;overflow:hidden!important}
.ck-editor:focus-within{border-color:var(--brand)!important;box-shadow:0 0 0 4px rgba(22,101,52,0.1)!important}
.ck.ck-toolbar{background:#f9fafb!important;border-radius:12px 12px 0 0!important;border-bottom:1px solid var(--line)!important;padding:8px!important}

/* Drop Zones */
.drop-zone{border:2px dashed var(--line);border-radius:12px;padding:20px;text-align:center;transition:all .2s;cursor:pointer;background:var(--surface-2);margin-top:8px}
.drop-zone.dragover{border-color:var(--accent);background:rgba(59,130,246,.05)}
.drop-zone-text{font-size:14px;color:var(--muted);margin-bottom:8px}
.drop-zone-icon{font-size:32px;display:block;margin-bottom:8px}

/* Options */
.options-container{margin-top:12px}
.option-item{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.option-item input[type="text"]{flex:1}
.btn-icon{padding:8px 12px;font-size:12px}

/* Toast */
.toast-container{position:fixed;top:80px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:10px}
.toast{padding:16px 20px;border-radius:var(--radius-md);background:var(--success);color:#fff;box-shadow:var(--shadow-lg);font-weight:500;display:flex;align-items:center;gap:10px;animation:slideIn .3s ease;max-width:400px}
.toast.success{background:#10b981}
.toast.danger{background:#ef4444}
.toast .close-toast{margin-left:auto;cursor:pointer;font-size:20px;opacity:0.8}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}

@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:950px){.header-top-nav{display:none}.filters-row{grid-template-columns:1fr}}
@media(max-width:768px){
:root{--header-height:64px;--footer-height:80px}
.main-content{padding:calc(var(--header-height) + 16px) 16px calc(var(--footer-height) + 80px)}
.section-card{padding:16px}.hero-card h1{font-size:24px}.stats-grid,.inline-row{grid-template-columns:1fr;gap:12px}.content-item{flex-direction:column}.tool-tabs{overflow-x:auto;flex-wrap:nowrap}
.display-mode-selector{flex-direction:column;align-items:stretch}
.display-mode-selector select{min-width:unset}
.section-item .section-language-selector{flex-direction:column;align-items:stretch}
.section-color-row{flex-direction:column;align-items:stretch}
.section-color-row .color-group{justify-content:space-between}
}
</style>
<?php include __DIR__ . '/../includes/pwa.php'; ?>
</head>
<body>

<div class="toast-container" id="toastContainer"></div>

<header class="app-header">
    <div class="header-left">
        <button class="menu-toggle" id="menuToggle" aria-label="Menu">☰</button>
        <div class="brand-box">🛠️ Admin</div>
        <nav class="header-top-nav" aria-label="Admin navigation">
            <?php foreach ($mainNavigation as $navItem): ?>
                <a href="<?php echo e($navItem['link']); ?>" class="header-top-link <?php echo $navItem['active'] ? 'active' : ''; ?>">
                    <?php echo e($navItem['name']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <div class="header-right">
        <button class="icon-btn" id="themeToggle" aria-label="Dark mode">☀</button>
        <button class="lang-btn" id="langToggle">EN</button>
        <button class="icon-btn" aria-label="Notifications">🔔</button>
        <div class="profile-area" id="profileArea">
            <button class="profile-btn" id="profileToggle" type="button" aria-label="Open profile menu" aria-expanded="false">
                <?php if (!empty($admin['profile_image'])): ?>
                    <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                <?php else: ?>
                    <?php echo e(initials((string)$admin['full_name'])); ?>
                <?php endif; ?>
            </button>
            <div class="profile-menu" id="profileMenu">
                <div class="profile-menu-top">
                    <?php if (!empty($admin['profile_image'])): ?>
                        <img src="<?php echo e($admin['profile_image']); ?>" alt="Profile">
                    <?php else: ?>
                        <div class="profile-avatar-fallback"><?php echo e(initials((string)$admin['full_name'])); ?></div>
                    <?php endif; ?>
                    <div>
                        <h4><?php echo e($admin['full_name'] ?? 'Admin'); ?></h4>
                        <p><?php echo e($admin['email'] ?? ''); ?></p>
                        <p style="font-size:10px;">Role: <?php echo e(ucfirst((string)($admin['role'] ?? 'admin'))); ?></p>
                    </div>
                </div>
                <a href="../profile.php">👤 My Profile</a>
                <a href="admin_settings.php">⚙️ Admin Settings</a>
                <a href="../logout.php">🚪 Logout</a>
            </div>
        </div>
    </div>
</header>

<div class="page-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-title">🛠️ Admin Panel</div>
        <nav class="subject-list">
            <?php foreach ($sidebarNavigation as $item): ?>
                <a href="<?php echo e($item['link']); ?>" class="subject-link <?php echo $item['active'] ? 'active' : ''; ?>">
                    <span><?php echo e($item['icon']); ?></span>
                    <span><?php echo e($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <main class="main-content">
        <div class="page-stack">
            <section class="hero-card section-card">
                <div>
                    <h1>📘 Manage Contents</h1>
                    <p>Create, edit, and manage lessons with multiple RTL/LTR sections per part.</p>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <form method="POST" onsubmit="return confirm('Lock completion (auto + manual) on ALL contents? This affects every lesson in the system.');" style="display:inline;">
                        <input type="hidden" name="action" value="bulk_completion_lock">
                        <input type="hidden" name="direction" value="lock">
                        <button type="submit" class="btn warn">🔒 Lock All Contents</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Unlock completion (auto + manual) on ALL contents? This affects every lesson in the system.');" style="display:inline;">
                        <input type="hidden" name="action" value="bulk_completion_lock">
                        <input type="hidden" name="direction" value="unlock">
                        <button type="submit" class="btn">🔓 Unlock All Contents</button>
                    </form>
                    <button class="btn primary" id="createContentBtn">➕ Create Content</button>
                </div>
            </section>

            <div class="stats-grid">
                <div class="section-card stat-card"><div class="stat-value"><?php echo $totalContents; ?></div><div class="stat-label">Total Contents</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $publishedContents; ?></div><div class="stat-label">Published</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $draftContents; ?></div><div class="stat-label">Drafts</div></div>
                <div class="section-card stat-card"><div class="stat-value"><?php echo $usedSubjects; ?></div><div class="stat-label">Subjects In Use</div></div>
            </div>

            <section class="section-card">
                <div class="filters-row">
                    <div class="search-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="contentSearchInput" class="search-input" placeholder="Search title, subject, category, tags...">
                    </div>
                    <select id="subjectFilter" class="form-select">
                        <option value="">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo strtolower(e($subject['name'] ?? '')); ?>"><?php echo e($subject['name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="statusFilter" class="form-select">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                        <option value="published">Published</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>

                <?php if (!empty($contents)): ?>
                    <div class="contents-list" id="contentsList">
                        <?php foreach ($contents as $content): ?>
                            <?php
                            $searchBlob = strtolower(($content['title'] ?? '') . ' ' . ($content['slug'] ?? '') . ' ' . ($content['subject_name'] ?? '') . ' ' . ($content['category_name'] ?? '') . ' ' . ($content['tags'] ?? ''));
                            $subjectFilterValue = strtolower((string)($content['subject_name'] ?? ''));
                            $statusFilterValue = strtolower((string)($content['status'] ?? 'draft'));
                            $hasPartsBadge = ($content['total_parts'] ?? 0) > 0;
                            $hasQuizBadge = ($content['has_quiz'] ?? 0) > 0;
                            $hasAudio = !empty($content['audio_path']);
                            $hasVideo = !empty($content['video_path']) || !empty($content['video_url']);
                            ?>
                            <div class="content-item content-search-item" data-search="<?php echo e($searchBlob); ?>" data-subject="<?php echo e($subjectFilterValue); ?>" data-status="<?php echo e($statusFilterValue); ?>">
                                <div class="content-left">
                                    <div class="content-icon"><?php echo e($content['subject_icon'] ?? '📘'); ?></div>
                                    <div>
                                        <div class="content-title"><?php echo e($content['title'] ?? 'Untitled'); ?></div>
                                        <div class="content-meta">
                                            <strong>Subject:</strong> <?php echo e($content['subject_name'] ?? 'Unknown'); ?> | 
                                            <strong>Category:</strong> <?php echo e($content['category_name'] ?? 'None'); ?><br>
                                            <strong>Slug:</strong> <?php echo e($content['slug'] ?? '--'); ?> | 
                                            <strong>Source:</strong> <?php echo e($content['source_name'] ?? 'None'); ?><br>
                                            <?php if($hasPartsBadge): ?><span class="badge success">📑 <?php echo $content['total_parts']; ?> Parts</span><?php endif; ?>
                                            <?php if($hasQuizBadge): ?><span class="badge">📝 Has Quiz</span><?php endif; ?>
                                            <?php if($hasAudio): ?><span class="badge">🎵 Audio</span><?php endif; ?>
                                            <?php if($hasVideo): ?><span class="badge">🎬 Video</span><?php endif; ?>
                                            <?php if(!empty($content['is_featured'])): ?><span class="badge">⭐ Featured</span><?php endif; ?>
                                            <?php if(isset($content['allow_auto_completion']) && (int)$content['allow_auto_completion'] === 0): ?><span class="badge warn" title="Passing the quiz will NOT auto-complete this lesson">🔒 Auto-Complete Off</span><?php endif; ?>
                                            <?php if(isset($content['allow_manual_completion']) && (int)$content['allow_manual_completion'] === 0): ?><span class="badge warn" title="The manual Complete Lesson button is disabled">🔒 Manual-Complete Off</span><?php endif; ?>
                                            <strong>Created:</strong> <?php echo !empty($content['created_at']) ? e(date('M d, Y', strtotime($content['created_at']))) : '--'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="content-actions">
                                    <span class="badge neutral"><?php echo e($content['subject_name'] ?? 'Unknown'); ?></span>
                                    <span class="badge <?php echo ((string)($content['status'] ?? '') === 'published') ? 'success' : 'warn'; ?>"><?php echo e(ucfirst((string)($content['status'] ?? 'draft'))); ?></span>
                                    <button class="btn secondary edit-content-btn" data-content-id="<?php echo (int)$content['id']; ?>">✏️ Edit</button>
                                    <form method="POST" class="toggle-status-form" style="display:inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="content_id" value="<?php echo (int)$content['id']; ?>">
                                        <button type="submit" class="btn <?php echo ((string)($content['status'] ?? '') === 'published') ? 'warn' : 'primary'; ?>">
                                            <?php echo ((string)($content['status'] ?? '') === 'published') ? '📄 Unpublish' : '🚀 Publish'; ?>
                                        </button>
                                    </form>
                                    <?php
                                        $autoLocked = isset($content['allow_auto_completion']) && (int)$content['allow_auto_completion'] === 0;
                                        $manualLocked = isset($content['allow_manual_completion']) && (int)$content['allow_manual_completion'] === 0;
                                    ?>
                                    <?php if (!empty($content['has_quiz'])): ?>
                                        <form method="POST" class="toggle-completion-form" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_completion_lock">
                                            <input type="hidden" name="content_id" value="<?php echo (int)$content['id']; ?>">
                                            <input type="hidden" name="field" value="auto">
                                            <button type="submit" class="btn secondary btn-sm" title="Toggle whether passing the quiz auto-completes this lesson">
                                                <?php echo $autoLocked ? '🔓 Unlock Auto-Complete' : '🔒 Lock Auto-Complete'; ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST" class="toggle-completion-form" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_completion_lock">
                                            <input type="hidden" name="content_id" value="<?php echo (int)$content['id']; ?>">
                                            <input type="hidden" name="field" value="manual">
                                            <button type="submit" class="btn secondary btn-sm" title="Toggle whether the Complete Lesson button works for this lesson">
                                                <?php echo $manualLocked ? '🔓 Unlock Complete Btn' : '🔒 Lock Complete Btn'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this content? This cannot be undone.');" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_content">
                                        <input type="hidden" name="content_id" value="<?php echo (int)$content['id']; ?>">
                                        <button type="submit" class="btn danger btn-sm">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="emptySearchState" class="empty-state" style="display:none;">No matching contents found.</div>
                <?php else: ?>
                    <div class="empty-state">📭 No contents found. Click "Create Content" to get started!</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>

<!-- Content Modal -->
<div class="modal-overlay" id="contentModal">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modalTitle">Create New Content</h2>
            <button class="modal-close" id="closeModalBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="tool-tabs" id="toolTabs">
                <div class="tool-tab active" data-section="main">📝 Main Content</div>
                <div class="tool-tab" data-section="media">🎬 Media</div>
                <div class="tool-tab" data-section="quiz">📝 Quiz</div>
                <div class="tool-tab" data-section="settings">⚙️ Settings</div>
            </div>

            <form method="POST" class="form-fields" id="contentForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_content">
                <input type="hidden" name="content_id" id="content_id" value="0">

                <!-- Main Content Section -->
                <div class="form-section" id="mainSection">
                    <div class="inline-row">
                        <div class="form-row">
                            <label for="subject_id">Subject *</label>
                            <select id="subject_id" name="subject_id" class="form-select" required>
                                <option value="">Select subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo (int)$subject['id']; ?>"><?php echo e(($subject['icon'] ?? '📚') . ' ' . ($subject['name'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="category_id">Category *</label>
                            <select id="category_id" name="category_id" class="form-select" required>
                                <option value="">Select category</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo (int)$category['id']; ?>" data-subject-id="<?php echo (int)$category['subject_id']; ?>"><?php echo e($category['name'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="title">Title *</label>
                        <input type="text" id="title" name="title" class="form-input" required placeholder="e.g., Introduction to Tajweed">
                    </div>

                    <div class="inline-row">
                        <div class="form-row">
                            <label for="slug">Slug (URL)</label>
                            <input type="text" id="slug" name="slug" class="form-input" placeholder="auto-generated from title">
                        </div>
                        <div class="form-row">
                            <label for="sort_order">Sort Order</label>
                            <input type="number" id="sort_order" name="sort_order" class="form-input" value="0" min="0">
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="short_description">Short Description</label>
                        <textarea id="short_description" name="short_description" class="form-textarea" placeholder="Brief summary shown in listings"></textarea>
                    </div>

                    <div class="form-row">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" id="hasPartsToggle" name="has_parts" value="1">
                            <span>📑 Split content into multiple parts</span>
                        </label>
                    </div>

                    <!-- Regular Content (legacy) -->
                    <div id="regularContent">
                        <div class="form-row">
                            <label>Main Content (Swahili/English)</label>
                            <div id="body_editor" style="background:white;border-radius:12px; min-height: 200px;"></div>
                            <textarea id="body" name="body" style="display:none;"></textarea>
                            <input type="hidden" id="body_html" name="body_html" value="">
                        </div>
                    </div>

                    <!-- Multi-part Content -->
                    <div id="multiPartContent" style="display:none;">
                        <div class="parts-section">
                            <div class="parts-header">
                                <h4>Content Parts (Each part can have multiple RTL/LTR sections)</h4>
                                <button type="button" class="btn secondary btn-icon" onclick="addPart()">+ Add Part</button>
                            </div>
                            <div id="partsContainer"></div>
                        </div>
                    </div>
                </div>

                <!-- Media Section -->
                <div class="form-section" id="mediaSection" style="display:none;">
                    <h3 style="margin-bottom:16px;">🎬 Media Integration</h3>
                    
                    <div class="form-row">
                        <label>🎧 Main Audio Upload</label>
                        <div class="drop-zone" id="audioDropZone">
                            <span class="drop-zone-icon">🎵</span>
                            <div class="drop-zone-text">Drag and drop audio files (MP3, OGG, WAV, M4A, MPEG)</div>
                            <input type="file" id="audio_file" name="audio_file" accept="audio/*,.mp3,.ogg,.wav,.m4a,.aac,.flac,.mpeg,.mpg,.mpga,.mp2,.mpa" style="display:none">
                        </div>
                        <div id="audioPreview" style="margin-top:8px;"></div>
                        <div id="audioCurrentInfo" style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <input type="hidden" id="existing_audio" name="existing_audio" value="">
                            <span id="audioFileName" style="font-size:13px; color:var(--muted);"></span>
                            <button type="button" id="removeAudioBtn" class="btn danger btn-sm" style="padding:6px 12px; font-size:12px;">🗑️ Remove Audio</button>
                        </div>
                    </div>

                    <div class="form-row">
                        <label>🎬 Main Video Upload</label>
                        <div class="drop-zone" id="videoDropZone">
                            <span class="drop-zone-icon">🎥</span>
                            <div class="drop-zone-text">Drag and drop video files (MP4, WebM, OGG)</div>
                            <input type="file" id="video_file" name="video_file" accept="video/*" style="display:none">
                        </div>
                        <div id="videoPreview" style="margin-top:8px;"></div>
                        <div id="videoCurrentInfo" style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <input type="hidden" id="existing_video_path" name="existing_video_path" value="">
                            <span id="videoFileName" style="font-size:13px; color:var(--muted);"></span>
                            <button type="button" id="removeVideoBtn" class="btn danger btn-sm" style="padding:6px 12px; font-size:12px;">🗑️ Remove Video</button>
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="video_url">🎥 External Video URL (YouTube / Vimeo)</label>
                        <textarea id="video_url" name="video_url" class="form-textarea" placeholder="Paste embed code or full URL"></textarea>
                        <button type="button" id="clearVideoUrlBtn" class="btn warn btn-sm" style="margin-top:8px; padding:6px 12px; font-size:12px;">🗑️ Clear URL</button>
                    </div>

                    <div class="form-row">
                        <label>🖼️ Content Card Image</label>
                        <div id="contentImagePreviewContainer" class="image-preview-container" style="display:none; margin-bottom:8px;">
                            <img id="contentImagePreview" class="image-preview" src="" alt="Preview" style="max-width:200px; max-height:120px; border-radius:8px; border:1px solid var(--line);">
                        </div>
                        <input type="file" id="content_image" name="content_image" accept="image/*" style="padding:8px;" onchange="previewContentImage(this)">
                        <div id="contentImageCurrentInfo" style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <input type="hidden" id="existing_content_image" name="existing_content_image" value="">
                            <span id="contentImageFileName" style="font-size:13px; color:var(--muted);"></span>
                            <button type="button" id="removeImageBtn" class="btn danger btn-sm" style="padding:6px 12px; font-size:12px;">🗑️ Remove Image</button>
                        </div>
                    </div>
                </div>

                <!-- Quiz Section -->
                <div class="form-section" id="quizSection" style="display:none;">
                    <div class="quiz-section">
                        <div class="parts-header">
                            <h4>Quiz Settings</h4>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                <input type="checkbox" id="hasQuizToggle" name="has_quiz" value="1">
                                <span>📝 Enable Quiz</span>
                            </label>
                        </div>
                        <div id="quizContent" style="display:none;margin-top:16px;">
                            <div class="inline-row" style="margin-bottom:15px;">
                                <div class="form-row">
                                    <label>Quiz Title</label>
                                    <input type="text" name="quiz_title" class="form-input" value="Knowledge Check">
                                </div>
                                <div class="form-row">
                                    <label>Passing Score (%)</label>
                                    <input type="number" name="passing_score" class="form-input" min="0" max="100" value="70">
                                </div>
                            </div>
                            <div class="form-row">
                                <label>Quiz Description</label>
                                <textarea name="quiz_description" class="form-textarea" rows="2" placeholder="Optional description"></textarea>
                            </div>
                            <div class="inline-row">
                                <div class="form-row">
                                    <label>Time Limit (minutes, optional)</label>
                                    <input type="number" name="time_limit_minutes" class="form-input" min="1">
                                </div>
                                <div></div>
                            </div>
                            <div class="parts-header" style="margin-top:20px;">
                                <h4>Questions</h4>
                                <button type="button" class="btn secondary btn-icon" onclick="addQuestion()">+ Add Question</button>
                            </div>
                            <div id="questionsContainer"></div>
                        </div>
                    </div>
                </div>

                <!-- Settings Section -->
                <div class="form-section" id="settingsSection" style="display:none;">
                    <h3 style="margin-bottom:16px;">⚙️ Content Settings</h3>
                    
                    <div class="inline-row">
                        <div class="form-row">
                            <label for="reference_text">Reference</label>
                            <input type="text" id="reference_text" name="reference_text" class="form-input" placeholder="e.g., Quran 2:255">
                        </div>
                        <div class="form-row">
                            <label for="source_name">Source Name</label>
                            <input type="text" id="source_name" name="source_name" class="form-input" placeholder="e.g., Imam Nawawi">
                        </div>
                    </div>
                    
                    <div class="inline-row">
                        <div class="form-row">
                            <label for="tags">Tags</label>
                            <input type="text" id="tags" name="tags" class="form-input" placeholder="tajweed, quran, recitation">
                        </div>
                        <div class="form-row">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-select">
                                <option value="draft">Draft</option>
                                <option value="pending">Pending</option>
                                <option value="published">Published</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="is_featured" value="1">
                            ⭐ Feature this content (show on homepage)
                        </label>
                    </div>

                    <div class="form-row" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line, #e2e5e2);">
                        <label style="font-weight:700;display:block;margin-bottom:10px;">🔒 Completion Lock</label>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px;">
                            <input type="checkbox" name="allow_auto_completion" value="1">
                            Allow automatic completion (passing this content's lesson quiz marks it complete)
                        </label>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="allow_manual_completion" value="1">
                            Allow manual completion (the "Complete Lesson" button, for content with no quiz)
                        </label>
                        <p style="font-size:12px;color:var(--muted,#6b7280);margin-top:8px;">
                            New content is locked by default (both boxes unchecked) - check a box to allow that completion path. Useful while a lesson or its quiz is still being reviewed. Use "🔒 Lock All Contents" / "🔓 Unlock All Contents" above the list to change this for every existing lesson at once.
                        </p>
                    </div>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn primary">Save Content</button>
                    <button type="button" class="btn secondary" id="cancelModalBtn">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ==========================================
// CKEDITOR UPLOAD ADAPTER
// ==========================================
class MyUploadAdapter {
    constructor(loader) { this.loader = loader; }
    upload() {
        return this.loader.file.then(file => new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('upload', file);
            fetch('upload_content_image.php', {
                method: 'POST', body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(result => {
                if (result.error) reject(result.error);
                else resolve({ default: result.url });
            })
            .catch(err => reject(err));
        }));
    }
    abort() {}
}

function MyCustomUploadAdapterPlugin(editor) {
    editor.plugins.get('FileRepository').createUploadAdapter = (loader) => new MyUploadAdapter(loader);
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function setEditorToLTR(editor) {
    if (!editor) return;
    try {
        const viewRoot = editor.editing.view.document.getRoot();
        if (viewRoot && viewRoot._element) {
            viewRoot._element.setAttribute('dir', 'ltr');
            viewRoot._element.style.direction = 'ltr';
            viewRoot._element.style.textAlign = 'left';
            viewRoot._element.style.fontFamily = '';
            viewRoot._element.style.fontSize = '';
        }
    } catch(e) { console.warn('setEditorToLTR error:', e); }
}

function setEditorToRTL(editor) {
    if (!editor) return;
    try {
        const viewRoot = editor.editing.view.document.getRoot();
        if (viewRoot && viewRoot._element) {
            viewRoot._element.setAttribute('dir', 'rtl');
            viewRoot._element.style.direction = 'rtl';
            viewRoot._element.style.textAlign = 'right';
            viewRoot._element.style.fontFamily = "'Amiri', 'Scheherazade New', 'Traditional Arabic', serif";
            viewRoot._element.style.fontSize = '20px';
        }
    } catch(e) { console.warn('setEditorToRTL error:', e); }
}

// ==========================================
// CREATE EDITOR BASED ON DIRECTION
// ==========================================
async function createEditor(elementId, direction = 'ltr') {
    const element = document.getElementById(elementId);
    if (!element) return null;
    element.innerHTML = '';
    try {
        const isRTL = (direction === 'rtl');
        const editor = await ClassicEditor.create(element, {
            extraPlugins: [MyCustomUploadAdapterPlugin],
            toolbar: {
                items: ['heading', '|', 'bold', 'italic', 'underline', '|', 
                        'bulletedList', 'numberedList', '|', 'link', 'imageUpload', 
                        'mediaEmbed', '|', 'undo', 'redo'],
                shouldNotGroupWhenFull: true
            },
            language: { ui: 'en', content: isRTL ? 'ar' : 'en' }
        });
        if (isRTL) {
            setEditorToRTL(editor);
        } else {
            setEditorToLTR(editor);
        }
        return editor;
    } catch (error) {
        console.error('Editor creation failed:', error);
        return null;
    }
}

// ==========================================
// TOGGLE DISPLAY MODE
// ==========================================
function toggleDisplayMode(index) {
    const select = document.getElementById(`displayMode_${index}`);
    const info = document.getElementById(`displayModeInfo_${index}`);
    const mode = select ? select.value : 'sections';
    
    if (!info) return;
    
    if (mode === 'sections') {
        info.innerHTML = '<span class="mode-icon">📋</span> Separate sections: Each section appears as a separate block with its own language badge.';
        const container = document.getElementById(`sectionsContainer_${index}`);
        if (container) container.style.display = '';
        const addBtn = document.querySelector(`#partsContainer .part-item[data-part-index="${index}"] .sections-header button`);
        if (addBtn) addBtn.style.display = '';
    } else {
        info.innerHTML = '<span class="mode-icon">📄</span> Combined paragraph: All sections flow together as one paragraph with mixed RTL/LTR formatting.';
    }
}

// ==========================================
// SECTION FUNCTIONS
// ==========================================

// ==========================================
// SECTION INDEX SEQUENCING
//
// Each section's form field names/element ids embed an index
// (parts[X][sections][IDX][...]). That index only needs to be UNIQUE
// within its part - the server reads sections with a plain foreach() and
// uses the hidden section_number field (kept in sync by renumberSections)
// for actual ordering, not this index. So we hand out indices from a
// counter that only ever increases, never reusing one - this is what
// makes inserting a section in the middle (or removing one, then adding
// another) safe from id/name collisions with sections that already exist.
// ==========================================
function getNextSectionSeq(container) {
    const current = parseInt(container.dataset.seq || String(container.querySelectorAll('.section-item').length), 10);
    container.dataset.seq = String(current + 1);
    return current;
}

// Human-readable direction badge text - kept in one place so a blank
// section, a loaded/edited section, and a live language-select change
// all render the exact same label instead of drifting apart.
function directionBadgeText(direction) {
    return direction === 'rtl' ? 'RTL (Arabic)' : 'LTR (Swahili)';
}

// Mirrors content.php's isUntouchedDefaultColorPair(): rows saved before
// color customization was tracked (touched flags) always carry this
// theme's literal light-mode defaults in the color columns, whether or not
// the admin ever opened the color picker. Used only when re-opening an
// existing part for edit, to avoid mistaking "never customized" for "chose
// this exact pair on purpose".
function isUntouchedDefaultColorPair(textColor, bgColor) {
    const norm = (c) => (c || '').toLowerCase().trim();
    return norm(textColor) === '#1f2937' && norm(bgColor) === '#ffffff';
}

// Builds one section's markup - blank (addSection/insertSectionAfter/new
// part's first section) or pre-filled with saved data (editing an existing
// part). This is the SINGLE source of truth for section markup; every call
// site (addSection, insertSectionAfter, addPart, addPartWithData) goes
// through this function so the fields, ids, and styling can never drift
// out of sync with each other again.
//
// `opts.textColor`/`opts.bgColor` are only pre-checked as "customized" when
// the caller explicitly passes `opts.textTouched`/`opts.bgTouched` as true -
// i.e. when loading a section that was saved with a genuinely admin-picked
// color. A brand-new section starts untouched, so nothing gets saved to the
// database unless the admin actually opens a color picker (see the hidden
// *_touched fields and updateColorPreview()/resetColor() below).
function buildSectionHtml(partIndex, idx, sectionNumber, opts = {}) {
    const language = opts.language || 'swahili';
    const direction = opts.direction || (language === 'arabic' ? 'rtl' : 'ltr');
    const isRTL = direction === 'rtl';
    const textColor = opts.textColor || '#1f2937';
    const bgColor = opts.bgColor || '#ffffff';
    const textTouched = opts.textTouched ? '1' : '0';
    const bgTouched = opts.bgTouched ? '1' : '0';
    const content = opts.content || '';
    const contentHtml = opts.contentHtml || '';
    const videoPath = opts.videoPath || '';
    const videoUrl = opts.videoUrl || '';
    const videoCaption = opts.videoCaption || '';
    const keepSeparate = !!opts.separate;
    const vBase = `parts[${partIndex}][sections][${idx}]`;

    return `
        <div class="section-item" data-section-number="${sectionNumber}">
            <div class="section-header-row">
                <span class="section-number">Section ${sectionNumber}</span>
                <button type="button" class="section-remove-btn" onclick="removeSection(this)">✕</button>
            </div>
            <div class="section-language-selector">
                <label>🌐 Language/Direction:</label>
                <select name="parts[${partIndex}][sections][${idx}][language]" class="form-select" onchange="updateSectionDirection(this, '${partIndex}', ${idx})">
                    <option value="arabic" ${language === 'arabic' ? 'selected' : ''}>🇸🇦 Arabic (RTL)</option>
                    <option value="swahili" ${language === 'swahili' ? 'selected' : ''}>🇰🇪 Swahili (LTR)</option>
                </select>
                <span id="directionBadge_${partIndex}_${idx}" class="direction-badge ${isRTL ? 'rtl' : 'ltr'}">${directionBadgeText(direction)}</span>
            </div>
            <input type="hidden" name="parts[${partIndex}][sections][${idx}][section_number]" value="${sectionNumber}">
            <input type="hidden" name="parts[${partIndex}][sections][${idx}][direction]" id="sectionDirection_${partIndex}_${idx}" value="${direction}">
            
            <!-- Color Picker Row -->
            <div class="section-color-row">
                <div class="color-group">
                    <label>🎨 Text Color:</label>
                    <input type="color" name="parts[${partIndex}][sections][${idx}][text_color]" id="textColor_${partIndex}_${idx}" value="${textColor}" onchange="updateColorPreview('${partIndex}', ${idx}, 'text')">
                    <span class="color-preview" id="textPreview_${partIndex}_${idx}" style="background:${textColor};"></span>
                    <button type="button" class="color-reset-btn" onclick="resetColor('${partIndex}', ${idx}, 'text')">Reset</button>
                    <input type="hidden" name="parts[${partIndex}][sections][${idx}][text_color_touched]" id="textColorTouched_${partIndex}_${idx}" value="${textTouched}">
                </div>
                <div class="color-group">
                    <label>🖌️ Background Color:</label>
                    <input type="color" name="parts[${partIndex}][sections][${idx}][background_color]" id="bgColor_${partIndex}_${idx}" value="${bgColor}" onchange="updateColorPreview('${partIndex}', ${idx}, 'bg')">
                    <span class="color-preview" id="bgPreview_${partIndex}_${idx}" style="background:${bgColor};border:1px solid var(--line);"></span>
                    <button type="button" class="color-reset-btn" onclick="resetColor('${partIndex}', ${idx}, 'bg')">Reset</button>
                    <input type="hidden" name="parts[${partIndex}][sections][${idx}][background_color_touched]" id="bgColorTouched_${partIndex}_${idx}" value="${bgTouched}">
                </div>
            </div>
            
            <!-- Keep this section separate even when the part is in Combined Paragraph mode -->
            <label class="section-separate-toggle" style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:13px;font-weight:600;cursor:pointer;">
                <input type="checkbox" name="${vBase}[display_separate]" value="1" ${keepSeparate ? 'checked' : ''}>
                <span>📌 Show this section separately <span style="font-weight:400;color:var(--muted);">(stays its own block even when the part is in Combined Paragraph mode)</span></span>
            </label>

            <div class="section-editor-wrapper">
                <div class="part-editor" id="section_editor_${partIndex}_${idx}" style="min-height:150px;"></div>
                <textarea name="parts[${partIndex}][sections][${idx}][content]" style="display:none;">${escapeHtml(content)}</textarea>
                <textarea name="parts[${partIndex}][sections][${idx}][content_html]" id="section_html_${partIndex}_${idx}" style="display:none;">${escapeHtml(contentHtml)}</textarea>
            </div>

            <!-- Section Video (optional): uploaded file and/or YouTube/Vimeo link -->
            <div class="section-video-box" style="margin-top:12px;padding:12px;border:1px dashed var(--line);border-radius:10px;">
                <label style="font-size:13px;font-weight:700;">🎬 Section Video (optional)</label>
                <input type="file" name="${vBase}[video_file]" accept="video/*" class="form-input" style="margin-top:6px;">
                <input type="hidden" name="${vBase}[existing_video_path]" value="${escapeHtml(videoPath)}">
                ${videoPath ? `
                <div class="section-video-current" style="margin:6px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                    <span style="font-size:12px;color:var(--muted);">📁 Current: ${escapeHtml(videoPath.split('/').pop())}</span>
                    <button type="button" class="btn danger btn-sm" onclick="removeSectionVideoFile(this)">🗑️ Remove file</button>
                </div>` : ''}
                <input type="text" name="${vBase}[video_url]" class="form-input" style="margin-top:6px;"
                       placeholder="Or paste a YouTube / Vimeo link (or embed code)" value="${escapeHtml(videoUrl)}">
                <input type="text" name="${vBase}[video_caption]" class="form-input" style="margin-top:6px;"
                       placeholder="Video caption (optional)" value="${escapeHtml(videoCaption)}">
            </div>
        </div>
    `;
}

// Inserts a fresh "➕ Insert Section Here" divider between every
// consecutive pair of sections in a container. Called after any
// add/remove/insert so the gaps always stay accurate. Rebuilding all
// dividers from scratch each time is simpler and safer than trying to
// patch them incrementally.
function refreshSectionDividers(container) {
    if (!container) return;
    container.querySelectorAll('.section-insert-divider').forEach(d => d.remove());
    const items = Array.from(container.querySelectorAll('.section-item'));
    items.forEach((item, idx) => {
        if (idx < items.length - 1) {
            const divider = document.createElement('div');
            divider.className = 'section-insert-divider';
            divider.innerHTML = `<button type="button" class="section-insert-btn" onclick="insertSectionAfter(this)">➕ Insert Section Here</button>`;
            item.after(divider);
        }
    });
}

function addSection(partIndex) {
    const container = document.getElementById(`sectionsContainer_${partIndex}`);
    const sectionCount = container.querySelectorAll('.section-item').length;
    const sectionNumber = sectionCount + 1;
    const idx = getNextSectionSeq(container);

    const html = buildSectionHtml(partIndex, idx, sectionNumber);
    container.insertAdjacentHTML('beforeend', html);
    
    // Create editor with default LTR
    createEditor(`section_editor_${partIndex}_${idx}`, 'ltr').then(editor => {
        if (editor) {
            document.getElementById(`section_editor_${partIndex}_${idx}`).editorInstance = editor;
        }
    });

    refreshSectionDividers(container);
}

// Inserts a brand-new blank section directly between the two sections
// straddling the "➕ Insert Section Here" divider that was clicked - this
// is the actual "add a section in between two consecutive sections"
// feature. The new section gets a never-before-used index (see
// getNextSectionSeq above) so it can never collide with an existing
// section's fields, regardless of how many were added/removed/inserted
// before it. renumberSections() immediately after fixes up the visible
// "Section N" labels and the section_number values that actually control
// display order - the index used in field names is otherwise irrelevant
// to the server, which just reads sections in the order they appear.
function insertSectionAfter(btn) {
    const divider = btn.closest('.section-insert-divider');
    const prevSection = divider ? divider.previousElementSibling : null;
    const container = btn.closest('.sections-container');
    if (!container || !prevSection) return;

    const partIndex = container.id.replace('sectionsContainer_', '');
    const idx = getNextSectionSeq(container);
    // sectionNumber here is a placeholder label only - renumberSections()
    // below immediately recalculates it based on final position.
    const html = buildSectionHtml(partIndex, idx, idx + 1);

    prevSection.insertAdjacentHTML('afterend', html);

    createEditor(`section_editor_${partIndex}_${idx}`, 'ltr').then(editor => {
        if (editor) {
            document.getElementById(`section_editor_${partIndex}_${idx}`).editorInstance = editor;
        }
    });

    renumberSections(container);
    refreshSectionDividers(container);
}

// Repaints the two little swatch chips from whatever the color inputs
// currently hold. Purely visual - does not touch the *_touched flags.
function updateColorSwatches(partIndex, sectionIndex) {
    const textColor = document.getElementById(`textColor_${partIndex}_${sectionIndex}`)?.value || '#1f2937';
    const bgColor = document.getElementById(`bgColor_${partIndex}_${sectionIndex}`)?.value || '#ffffff';
    
    const textPreview = document.getElementById(`textPreview_${partIndex}_${sectionIndex}`);
    if (textPreview) textPreview.style.background = textColor;
    
    const bgPreview = document.getElementById(`bgPreview_${partIndex}_${sectionIndex}`);
    if (bgPreview) bgPreview.style.background = bgColor;
}

// Called on a color input's onchange, which only fires from an actual user
// pick - so this is also the moment we flip the hidden *_touched flag to
// "1", marking the color as genuinely customized (rather than just the
// input's default value sitting there unused). The PHP save handler only
// persists a color when its touched flag is "1" - see the "Get colors"
// block in the POST handler further up this file.
function updateColorPreview(partIndex, sectionIndex, type) {
    updateColorSwatches(partIndex, sectionIndex);
    if (type === 'text') {
        const touched = document.getElementById(`textColorTouched_${partIndex}_${sectionIndex}`);
        if (touched) touched.value = '1';
    } else if (type === 'bg') {
        const touched = document.getElementById(`bgColorTouched_${partIndex}_${sectionIndex}`);
        if (touched) touched.value = '1';
    }
}

// Reset means "go back to having no customization here" - not just putting
// the swatch back to the visual default. So this un-marks the touched flag
// too, and the section will save with no color at all (same as a section
// the admin never opened the picker on).
function resetColor(partIndex, sectionIndex, type) {
    if (type === 'text') {
        const input = document.getElementById(`textColor_${partIndex}_${sectionIndex}`);
        if (input) input.value = '#1f2937';
        const touched = document.getElementById(`textColorTouched_${partIndex}_${sectionIndex}`);
        if (touched) touched.value = '0';
    } else {
        const input = document.getElementById(`bgColor_${partIndex}_${sectionIndex}`);
        if (input) input.value = '#ffffff';
        const touched = document.getElementById(`bgColorTouched_${partIndex}_${sectionIndex}`);
        if (touched) touched.value = '0';
    }
    updateColorSwatches(partIndex, sectionIndex);
}

function updateSectionDirection(select, partIndex, sectionIndex) {
    const direction = select.value === 'arabic' ? 'rtl' : 'ltr';
    document.getElementById(`sectionDirection_${partIndex}_${sectionIndex}`).value = direction;
    
    const badge = document.getElementById(`directionBadge_${partIndex}_${sectionIndex}`);
    if (badge) {
        badge.textContent = directionBadgeText(direction);
        badge.className = direction === 'rtl' ? 'direction-badge rtl' : 'direction-badge ltr';
    }
    
    const editorDiv = document.getElementById(`section_editor_${partIndex}_${sectionIndex}`);
    if (editorDiv && editorDiv.editorInstance) {
        if (direction === 'rtl') {
            setEditorToRTL(editorDiv.editorInstance);
        } else {
            setEditorToLTR(editorDiv.editorInstance);
        }
    }
}

// Clears the saved video file of a section. Applied when "Save Content" is clicked.
function removeSectionVideoFile(btn) {
    const box = btn.closest('.section-video-box');
    if (!box) return;
    const hidden = box.querySelector('input[name$="[existing_video_path]"]');
    if (hidden) hidden.value = '';
    const current = box.querySelector('.section-video-current');
    if (current) current.remove();
    showToast('Video file removed. Click "Save Content" to apply.', 'success');
}

function removeSection(btn) {
    const section = btn.closest('.section-item');
    const container = section.closest('.sections-container');
    const editorDiv = section.querySelector('.part-editor');
    if (editorDiv && editorDiv.editorInstance) {
        editorDiv.editorInstance.destroy().then(() => {
            section.remove();
            renumberSections(container);
            refreshSectionDividers(container);
        }).catch(() => {
            section.remove();
            renumberSections(container);
            refreshSectionDividers(container);
        });
    } else {
        section.remove();
        renumberSections(container);
        refreshSectionDividers(container);
    }
}

function renumberSections(container) {
    if (!container) return;
    container.querySelectorAll('.section-item').forEach((section, idx) => {
        const numSpan = section.querySelector('.section-number');
        if (numSpan) numSpan.textContent = `Section ${idx + 1}`;
        const hiddenInput = section.querySelector('input[name*="[section_number]"]');
        if (hiddenInput) hiddenInput.value = idx + 1;
    });
}

// ==========================================
// ADD PART WITH SECTIONS
// ==========================================
async function addPart() {
    const container = document.getElementById('partsContainer');
    const index = Date.now();
    const html = `
        <div class="part-item" data-part-index="${index}" draggable="true">
            <div class="part-drag-handle" title="Drag to reorder">⋮⋮</div>
            <input type="hidden" name="parts[${index}][part_number]" value="">
            <input type="hidden" name="parts[${index}][id]" value="0">
            
            <div class="form-row" style="margin-bottom:12px;">
                <label>Part Title</label>
                <input type="text" name="parts[${index}][title]" class="form-input" placeholder="Enter part title" required>
            </div>
            
            <!-- Display Mode Selector -->
            <div class="display-mode-selector">
                <label>📐 Display Mode:</label>
                <select name="parts[${index}][display_mode]" class="form-select" id="displayMode_${index}" onchange="toggleDisplayMode('${index}')">
                    <option value="sections">📋 Separate Sections (Blocks)</option>
                    <option value="combined">📄 Combined Paragraph (Mixed RTL/LTR)</option>
                </select>
                <div class="mode-info" id="displayModeInfo_${index}">
                    <span class="mode-icon">📋</span> Separate sections: Each section appears as a separate block with its own language badge.
                </div>
            </div>
            
            <div class="sections-header" style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 8px;">
                <h4 style="font-size:14px;">📝 Content Sections (RTL/LTR)</h4>
                <button type="button" class="btn secondary btn-sm" onclick="addSection('${index}')">+ Add Section</button>
            </div>
            <div class="sections-container" id="sectionsContainer_${index}"></div>
            
            <div class="inline-row" style="margin-top:10px;gap:15px;">
                <div class="form-row" style="flex:1;">
                    <label>🎧 Audio (optional)</label>
                    <div class="drop-zone" id="partAudioDrop_${index}">
                        <span class="drop-zone-icon">🎵</span>
                        <div class="drop-zone-text">Drop audio file or click</div>
                        <input type="file" name="parts[${index}][audio_file]" accept="audio/*" style="display:none" id="partAudioInput_${index}">
                    </div>
                    <input type="hidden" name="parts[${index}][existing_audio]" value="" id="partExistingAudio_${index}">
                    <div id="partAudioPreview_${index}" style="margin-top:8px;"></div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:5px;">
                        <span id="partAudioFileName_${index}" style="font-size:12px; color:var(--muted);"></span>
                        <button type="button" class="btn danger btn-sm part-remove-audio" data-part-index="${index}" style="display:none; padding:4px 10px; font-size:11px;">🗑️ Remove Audio</button>
                    </div>
                </div>
                <div class="form-row" style="flex:1;">
                    <label>🎬 Video (optional)</label>
                    <div class="drop-zone" id="partVideoDrop_${index}">
                        <span class="drop-zone-icon">🎥</span>
                        <div class="drop-zone-text">Drop video file or click</div>
                        <input type="file" name="parts[${index}][video_file]" accept="video/*" style="display:none" id="partVideoInput_${index}">
                    </div>
                    <input type="hidden" name="parts[${index}][existing_video_path]" value="" id="partExistingVideo_${index}">
                    <div id="partVideoPreview_${index}" style="margin-top:8px;"></div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:5px;">
                        <span id="partVideoFileName_${index}" style="font-size:12px; color:var(--muted);"></span>
                        <button type="button" class="btn danger btn-sm part-remove-video" data-part-index="${index}" style="display:none; padding:4px 10px; font-size:11px;">🗑️ Remove Video</button>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <label>External Video URL (optional)</label>
                <input type="text" name="parts[${index}][video_url]" class="form-input" placeholder="YouTube/Vimeo embed URL" id="partVideoUrl_${index}">
                <button type="button" class="btn warn btn-sm part-clear-url" data-part-index="${index}" style="display:none; margin-top:5px; padding:4px 10px; font-size:11px;">🗑️ Clear URL</button>
            </div>

            <div class="form-row" style="margin-top:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <label style="margin:0;">🎬 Additional Videos <span style="font-size:11px;color:var(--muted);font-weight:400;">(optional — for a part with more than one video)</span></label>
                    <button type="button" class="btn secondary btn-sm" onclick="addPartExtraVideo('${index}')">+ Add Video</button>
                </div>
                <div class="part-extra-videos" id="partExtraVideos_${index}"></div>
            </div>
            
            <button type="button" class="btn warn" style="margin-top:15px;" onclick="removePart(this)">🗑️ Remove Part</button>
        </div>`;
    container.insertAdjacentHTML('beforeend', html);

    // First section by default - built through the shared template so it
    // can never drift out of sync with addSection()/addPartWithData().
    const sectionsContainer = document.getElementById(`sectionsContainer_${index}`);
    sectionsContainer.insertAdjacentHTML('beforeend', buildSectionHtml(index, 0, 1));
    sectionsContainer.dataset.seq = '1';

    createEditor(`section_editor_${index}_0`, 'ltr').then(editor => {
        if (editor) {
            document.getElementById(`section_editor_${index}_0`).editorInstance = editor;
        }
    });
    
    setupDragDrop(`partAudioDrop_${index}`, `partAudioInput_${index}`, `partAudioPreview_${index}`, `partExistingAudio_${index}`);
    setupDragDrop(`partVideoDrop_${index}`, `partVideoInput_${index}`, `partVideoPreview_${index}`, `partExistingVideo_${index}`);
    setupPartDragEvents();
    attachPartMediaListeners(index);
    renumberParts();
}

// ==========================================
// ADD PART WITH DATA (for editing)
// ==========================================
async function addPartWithData(part, index) {
    const container = document.getElementById('partsContainer');
    const partDbId = part.id || 0;
    const sections = part.sections || [];
    const displayMode = part.display_mode || 'sections';
    
    let sectionsHtml = '';
    let seq = 0;
    if (sections.length > 0) {
        sections.forEach((section, secIdx) => {
            // Rows saved before color customization was tracked always have
            // the theme's own literal defaults (#1f2937/#ffffff) baked in,
            // even for sections nobody ever colored - see
            // isUntouchedDefaultColorPair() below. Don't treat those as a
            // real customization when re-opening the part for edit, or the
            // "touched" flag would falsely lock in a color the admin never
            // actually chose.
            const untouchedPair = isUntouchedDefaultColorPair(section.text_color, section.background_color);
            sectionsHtml += buildSectionHtml(index, secIdx, secIdx + 1, {
                language: section.language || 'swahili',
                direction: section.direction || 'ltr',
                content: section.content || '',
                contentHtml: section.content_html || '',
                textColor: section.text_color || '#1f2937',
                bgColor: section.background_color || '#ffffff',
                textTouched: !!section.text_color && !untouchedPair,
                bgTouched: !!section.background_color && !untouchedPair,
                videoPath: section.video_path || '',
                videoUrl: section.video_url || '',
                videoCaption: section.video_caption || '',
                separate: String(section.display_separate) === '1'
            });
            seq = secIdx + 1;
        });
    } else {
        sectionsHtml = buildSectionHtml(index, 0, 1);
        seq = 1;
    }
    
    const modeIcon = displayMode === 'combined' ? '📄' : '📋';
    const modeInfo = displayMode === 'combined' 
        ? '<span class="mode-icon">📄</span> Combined paragraph: All sections flow together as one paragraph with mixed RTL/LTR formatting.'
        : '<span class="mode-icon">📋</span> Separate sections: Each section appears as a separate block with its own language badge.';
    
    const html = `
        <div class="part-item" data-part-index="${index}" data-part-db-id="${partDbId}" draggable="true">
            <div class="part-drag-handle" title="Drag to reorder">⋮⋮</div>
            <input type="hidden" name="parts[${index}][id]" value="${partDbId}">
            <input type="hidden" name="parts[${index}][part_number]" value="${part.part_number || index + 1}">
            
            <div class="form-row" style="margin-bottom:12px;">
                <label>Part Title</label>
                <input type="text" name="parts[${index}][title]" class="form-input" value="${escapeHtml(part.title || '')}" required>
            </div>
            
            <!-- Display Mode Selector -->
            <div class="display-mode-selector">
                <label>📐 Display Mode:</label>
                <select name="parts[${index}][display_mode]" class="form-select" id="displayMode_${index}" onchange="toggleDisplayMode('${index}')">
                    <option value="sections" ${displayMode === 'sections' ? 'selected' : ''}>📋 Separate Sections (Blocks)</option>
                    <option value="combined" ${displayMode === 'combined' ? 'selected' : ''}>📄 Combined Paragraph (Mixed RTL/LTR)</option>
                </select>
                <div class="mode-info" id="displayModeInfo_${index}">
                    ${modeInfo}
                </div>
            </div>
            
            <div class="sections-header" style="display:flex;justify-content:space-between;align-items:center;margin:12px 0 8px;">
                <h4 style="font-size:14px;">📝 Content Sections (RTL/LTR)</h4>
                <button type="button" class="btn secondary btn-sm" onclick="addSection('${index}')">+ Add Section</button>
            </div>
            <div class="sections-container" id="sectionsContainer_${index}">
                ${sectionsHtml}
            </div>
            
            <div class="inline-row" style="margin-top:10px;gap:15px;">
                <div class="form-row" style="flex:1;">
                    <label>🎧 Audio (optional)</label>
                    <div class="drop-zone" id="partAudioDrop_${index}">
                        <span class="drop-zone-icon">🎵</span>
                        <div class="drop-zone-text">Drop audio file or click</div>
                        <input type="file" name="parts[${index}][audio_file]" accept="audio/*" style="display:none" id="partAudioInput_${index}">
                    </div>
                    <input type="hidden" name="parts[${index}][existing_audio]" value="${escapeHtml(part.audio_path || '')}" id="partExistingAudio_${index}">
                    <div id="partAudioPreview_${index}" style="margin-top:8px;">${part.audio_path ? `<p style="font-size:12px;">📁 Current: ${part.audio_path.split('/').pop()}</p>` : ''}</div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:5px;">
                        <span id="partAudioFileName_${index}" style="font-size:12px; color:var(--muted);">${part.audio_path ? `📁 Current: ${part.audio_path.split('/').pop()}` : ''}</span>
                        <button type="button" class="btn danger btn-sm part-remove-audio" data-part-index="${index}" data-part-db-id="${partDbId}" style="${part.audio_path ? 'display:inline-flex;' : 'display:none;'} padding:4px 10px; font-size:11px;">🗑️ Remove Audio</button>
                    </div>
                </div>
                <div class="form-row" style="flex:1;">
                    <label>🎬 Video (optional)</label>
                    <div class="drop-zone" id="partVideoDrop_${index}">
                        <span class="drop-zone-icon">🎥</span>
                        <div class="drop-zone-text">Drop video file or click</div>
                        <input type="file" name="parts[${index}][video_file]" accept="video/*" style="display:none" id="partVideoInput_${index}">
                    </div>
                    <input type="hidden" name="parts[${index}][existing_video_path]" value="${escapeHtml(part.video_path || '')}" id="partExistingVideo_${index}">
                    <div id="partVideoPreview_${index}" style="margin-top:8px;">${part.video_path ? `<p style="font-size:12px;">📁 Current: ${part.video_path.split('/').pop()}</p>` : ''}</div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:5px;">
                        <span id="partVideoFileName_${index}" style="font-size:12px; color:var(--muted);">${part.video_path ? `📁 Current: ${part.video_path.split('/').pop()}` : ''}</span>
                        <button type="button" class="btn danger btn-sm part-remove-video" data-part-index="${index}" data-part-db-id="${partDbId}" style="${part.video_path ? 'display:inline-flex;' : 'display:none;'} padding:4px 10px; font-size:11px;">🗑️ Remove Video</button>
                    </div>
                </div>
            </div>
            
            <div class="form-row">
                <label>External Video URL (optional)</label>
                <input type="text" name="parts[${index}][video_url]" class="form-input" value="${escapeHtml(part.video_url || '')}" placeholder="YouTube/Vimeo embed URL" id="partVideoUrl_${index}">
                <button type="button" class="btn warn btn-sm part-clear-url" data-part-index="${index}" data-part-db-id="${partDbId}" style="${part.video_url ? 'display:inline-flex;' : 'display:none;'} margin-top:5px; padding:4px 10px; font-size:11px;">🗑️ Clear URL</button>
            </div>

            <div class="form-row" style="margin-top:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <label style="margin:0;">🎬 Additional Videos <span style="font-size:11px;color:var(--muted);font-weight:400;">(optional — for a part with more than one video)</span></label>
                    <button type="button" class="btn secondary btn-sm" onclick="addPartExtraVideo('${index}')">+ Add Video</button>
                </div>
                <div class="part-extra-videos" id="partExtraVideos_${index}"></div>
            </div>
            
            <button type="button" class="btn warn" style="margin-top:15px;" onclick="removePart(this)">🗑️ Remove Part</button>
        </div>`;
    container.insertAdjacentHTML('beforeend', html);

    // Hydrate any additional videos this part already has saved.
    (part.videos || []).forEach(v => addPartExtraVideo(index, v));
    
    // Create editors for each section
    const sectionEditors = container.querySelectorAll(`#sectionsContainer_${index} .section-item`);
    sectionEditors.forEach((section, secIdx) => {
        const editorDiv = section.querySelector('.part-editor');
        const directionInput = section.querySelector(`input[name*="[direction]"]`);
        const direction = directionInput ? directionInput.value : 'ltr';
        const contentHtml = section.querySelector(`textarea[name*="[content_html]"]`);
        
        createEditor(editorDiv.id, direction).then(editor => {
            if (editor) {
                if (direction === 'rtl') {
                    setEditorToRTL(editor);
                } else {
                    setEditorToLTR(editor);
                }
                if (contentHtml && contentHtml.value) {
                    editor.setData(contentHtml.value);
                }
                editorDiv.editorInstance = editor;
            }
        });
    });
    
    setupDragDrop(`partAudioDrop_${index}`, `partAudioInput_${index}`, `partAudioPreview_${index}`, `partExistingAudio_${index}`);
    setupDragDrop(`partVideoDrop_${index}`, `partVideoInput_${index}`, `partVideoPreview_${index}`, `partExistingVideo_${index}`);
    attachPartMediaListeners(index);
    setupPartDragEvents();
    const loadedSectionsContainer = document.getElementById(`sectionsContainer_${index}`);
    loadedSectionsContainer.dataset.seq = String(seq);
    refreshSectionDividers(loadedSectionsContainer);
}

// ==========================================
// ADDITIONAL VIDEOS PER PART (beyond the single primary video/URL above)
// ==========================================
const partExtraVideoSeq = {}; // partIndex -> next video slot index, so removing one never reuses a name

function buildPartExtraVideoHtml(partIndex, videoIndex, data = {}) {
    const existingPath = data.video_path || '';
    const videoUrl = data.video_url || '';
    const caption = data.caption || '';
    return `
        <div class="part-extra-video-row" data-video-index="${videoIndex}" style="margin-top:10px;padding:10px;border:1px solid var(--border-color,#ddd);border-radius:8px;background:var(--bg-secondary,rgba(0,0,0,0.02));">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <strong style="font-size:12px;">Video ${videoIndex + 1}</strong>
                <button type="button" class="btn danger btn-sm" onclick="this.closest('.part-extra-video-row').remove()" style="padding:2px 8px;font-size:11px;">🗑️ Remove</button>
            </div>
            <input type="file" name="parts[${partIndex}][videos][${videoIndex}][video_file]" accept="video/*" class="form-input" style="margin-bottom:6px;">
            <input type="hidden" name="parts[${partIndex}][videos][${videoIndex}][existing_video_path]" value="${escapeHtml(existingPath)}">
            ${existingPath ? `<p style="font-size:11px;color:var(--muted);margin:0 0 6px;">📁 Current: ${escapeHtml(existingPath.split('/').pop())}</p>` : ''}
            <input type="text" name="parts[${partIndex}][videos][${videoIndex}][video_url]" class="form-input" placeholder="Or paste YouTube/Vimeo embed URL" value="${escapeHtml(videoUrl)}" style="margin-bottom:6px;">
            <input type="text" name="parts[${partIndex}][videos][${videoIndex}][caption]" class="form-input" placeholder="Caption (optional)" value="${escapeHtml(caption)}">
        </div>`;
}

function addPartExtraVideo(partIndex, data = {}) {
    const container = document.getElementById(`partExtraVideos_${partIndex}`);
    if (!container) return;
    const nextIndex = partExtraVideoSeq[partIndex] ?? 0;
    container.insertAdjacentHTML('beforeend', buildPartExtraVideoHtml(partIndex, nextIndex, data));
    partExtraVideoSeq[partIndex] = nextIndex + 1;
}

// ==========================================
// DRAG-DROP ZONES
// ==========================================
function setupDragDrop(dropZoneId, inputId, previewId, hiddenInputId = null) {
    const dropZone = document.getElementById(dropZoneId);
    const input = document.getElementById(inputId);
    if (!dropZone || !input) return;
    
    dropZone.addEventListener('click', () => input.click());
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(evt => {
        dropZone.addEventListener(evt, () => dropZone.classList.add('dragover'));
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, () => dropZone.classList.remove('dragover'));
    });
    
    dropZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            input.files = files;
            handleFilePreview(files, previewId, dropZone);
            if (hiddenInputId) {
                const hiddenInput = document.getElementById(hiddenInputId);
                if (hiddenInput) hiddenInput.value = '';
            }
        }
    });
    
    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            handleFilePreview(input.files, previewId, dropZone);
            if (hiddenInputId) {
                const hiddenInput = document.getElementById(hiddenInputId);
                if (hiddenInput) hiddenInput.value = '';
            }
        }
    });
}

function handleFilePreview(files, previewId, dropZone) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    preview.innerHTML = '';
    
    Array.from(files).forEach(file => {
        if (dropZone) {
            const textDiv = dropZone.querySelector('.drop-zone-text');
            if (textDiv) textDiv.textContent = file.name;
        }
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = e => {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.cssText = 'width:100px;height:100px;object-fit:cover;border-radius:8px;margin:4px';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        } else if (file.type.startsWith('audio/')) {
            const audio = document.createElement('audio');
            audio.controls = true; audio.style.width = '200px';
            audio.src = URL.createObjectURL(file);
            preview.appendChild(audio);
        } else if (file.type.startsWith('video/')) {
            const video = document.createElement('video');
            video.controls = true; video.style.width = '200px';
            video.src = URL.createObjectURL(file);
            preview.appendChild(video);
        }
    });
}

function previewContentImage(input) {
    const preview = document.getElementById('contentImagePreview');
    const container = document.getElementById('contentImagePreviewContainer');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            container.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ==========================================
// PART MEDIA LISTENERS
// ==========================================
function attachPartMediaListeners(partIndex) {
    const removeAudioBtn = document.querySelector(`.part-remove-audio[data-part-index="${partIndex}"]`);
    if (removeAudioBtn) {
        const newBtn = removeAudioBtn.cloneNode(true);
        removeAudioBtn.parentNode.replaceChild(newBtn, removeAudioBtn);
        newBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const partDbId = newBtn.getAttribute('data-part-db-id') || 0;
            const contentId = document.getElementById('content_id').value;
            if (!contentId || contentId == 0) {
                showToast('Please save the content first before removing media', 'danger');
                return;
            }
            if (!confirm('Are you sure you want to remove this audio from the part?')) return;
            removePartMedia(contentId, partDbId, 'audio', 'audio_path', `partExistingAudio_${partIndex}`, `partAudioFileName_${partIndex}`, newBtn);
        });
    }
    
    const removeVideoBtn = document.querySelector(`.part-remove-video[data-part-index="${partIndex}"]`);
    if (removeVideoBtn) {
        const newBtn = removeVideoBtn.cloneNode(true);
        removeVideoBtn.parentNode.replaceChild(newBtn, removeVideoBtn);
        newBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const partDbId = newBtn.getAttribute('data-part-db-id') || 0;
            const contentId = document.getElementById('content_id').value;
            if (!contentId || contentId == 0) {
                showToast('Please save the content first before removing media', 'danger');
                return;
            }
            if (!confirm('Are you sure you want to remove this video from the part?')) return;
            removePartMedia(contentId, partDbId, 'video', 'video_path', `partExistingVideo_${partIndex}`, `partVideoFileName_${partIndex}`, newBtn);
        });
    }
    
    const clearUrlBtn = document.querySelector(`.part-clear-url[data-part-index="${partIndex}"]`);
    if (clearUrlBtn) {
        const newBtn = clearUrlBtn.cloneNode(true);
        clearUrlBtn.parentNode.replaceChild(newBtn, clearUrlBtn);
        newBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const partDbId = newBtn.getAttribute('data-part-db-id') || 0;
            const contentId = document.getElementById('content_id').value;
            if (!contentId || contentId == 0) {
                showToast('Please save the content first before clearing URL', 'danger');
                return;
            }
            if (!confirm('Are you sure you want to clear the external video URL for this part?')) return;
            clearPartVideoUrl(contentId, partDbId, `partVideoUrl_${partIndex}`, newBtn);
        });
    }
}

async function removePartMedia(contentId, partDbId, mediaType, fieldName, hiddenInputId, fileNameSpanId, buttonElement) {
    const originalText = buttonElement ? buttonElement.textContent : '';
    if (buttonElement) {
        buttonElement.disabled = true;
        buttonElement.textContent = '⏳ Removing...';
    }
    
    try {
        if (partDbId && parseInt(partDbId) > 0) {
            const url = `admin_contents.php?ajax=1&delete_media=1&content_id=${contentId}&part_id=${partDbId}&media_type=${mediaType}&field_name=${fieldName}`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                const hiddenInput = document.getElementById(hiddenInputId);
                if (hiddenInput) hiddenInput.value = '';
                if (buttonElement) buttonElement.style.display = 'none';
                const fileNameSpan = document.getElementById(fileNameSpanId);
                if (fileNameSpan) fileNameSpan.textContent = '';
                showToast(`${mediaType.charAt(0).toUpperCase() + mediaType.slice(1)} removed successfully from part!`, 'success');
            } else {
                showToast(result.message || 'Failed to remove media', 'danger');
            }
        } else {
            const hiddenInput = document.getElementById(hiddenInputId);
            if (hiddenInput) hiddenInput.value = '';
            const fileNameSpan = document.getElementById(fileNameSpanId);
            if (fileNameSpan) fileNameSpan.textContent = '';
            if (buttonElement) buttonElement.style.display = 'none';
            showToast(`${mediaType.charAt(0).toUpperCase() + mediaType.slice(1)} cleared from part. Save to apply changes.`, 'success');
        }
    } catch (error) {
        console.error('Error removing media:', error);
        showToast('An error occurred while removing media', 'danger');
    } finally {
        if (buttonElement) {
            buttonElement.disabled = false;
            buttonElement.textContent = originalText;
        }
    }
}

async function clearPartVideoUrl(contentId, partDbId, videoUrlFieldId, buttonElement) {
    const originalText = buttonElement ? buttonElement.textContent : '';
    if (buttonElement) {
        buttonElement.disabled = true;
        buttonElement.textContent = '⏳ Clearing...';
    }
    
    try {
        if (partDbId && parseInt(partDbId) > 0) {
            const url = `admin_contents.php?ajax=1&delete_media=1&content_id=${contentId}&part_id=${partDbId}&media_type=video&field_name=video_url`;
            const response = await fetch(url);
            const result = await response.json();
            
            if (result.success) {
                const videoUrlField = document.getElementById(videoUrlFieldId);
                if (videoUrlField) videoUrlField.value = '';
                if (buttonElement) buttonElement.style.display = 'none';
                showToast('Video URL cleared successfully from part!', 'success');
            } else {
                showToast(result.message || 'Failed to clear URL', 'danger');
            }
        } else {
            const videoUrlField = document.getElementById(videoUrlFieldId);
            if (videoUrlField) videoUrlField.value = '';
            if (buttonElement) buttonElement.style.display = 'none';
            showToast('Video URL cleared from part. Save to apply changes.', 'success');
        }
    } catch (error) {
        console.error('Error clearing URL:', error);
        showToast('An error occurred', 'danger');
    } finally {
        if (buttonElement) {
            buttonElement.disabled = false;
            buttonElement.textContent = originalText;
        }
    }
}

// ==========================================
// REMOVE PART
// ==========================================
function removePart(btn) {
    const partItem = btn.closest('.part-item');
    const editors = partItem.querySelectorAll('.part-editor');
    const cleanup = () => { partItem.remove(); renumberParts(); };
    
    if (editors.length > 0) {
        const promises = Array.from(editors).map(div => {
            if (div.editorInstance) return div.editorInstance.destroy();
            return Promise.resolve();
        });
        Promise.all(promises).then(cleanup).catch(cleanup);
    } else {
        cleanup();
    }
}

function renumberParts() {
    document.querySelectorAll('#partsContainer .part-item').forEach((part, idx) => {
        const numInput = part.querySelector('input[name*="[part_number]"]');
        if (numInput) numInput.value = idx + 1;
        part.dataset.partIndex = idx;
    });
}

// ==========================================
// DRAG EVENTS
// ==========================================
let draggedItem = null;

function setupPartDragEvents() {
    document.querySelectorAll('#partsContainer .part-item').forEach(part => {
        part.addEventListener('dragstart', e => {
            draggedItem = part;
            part.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', part.dataset.partIndex);
        });
        part.addEventListener('dragover', e => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (part !== draggedItem) part.classList.add('drag-over');
        });
        part.addEventListener('dragleave', () => part.classList.remove('drag-over'));
        part.addEventListener('drop', e => {
            e.preventDefault(); e.stopPropagation();
            part.classList.remove('drag-over');
            if (part !== draggedItem) {
                const container = document.getElementById('partsContainer');
                const items = [...container.querySelectorAll('.part-item')];
                const draggedIdx = items.indexOf(draggedItem);
                const droppedIdx = items.indexOf(part);
                if (draggedIdx < droppedIdx) part.parentNode.insertBefore(draggedItem, part.nextSibling);
                else part.parentNode.insertBefore(draggedItem, part);
                renumberParts();
            }
        });
        part.addEventListener('dragend', () => {
            part.classList.remove('dragging');
            document.querySelectorAll('.part-item').forEach(p => p.classList.remove('drag-over'));
            draggedItem = null;
        });
    });
}

// ==========================================
// TOAST
// ==========================================
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span>${type === 'success' ? '✅' : '❌'}</span><span>${message}</span><span class="close-toast" onclick="this.parentElement.remove()">&times;</span>`;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
}

<?php if ($message !== ''): ?>
showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($messageType); ?>);
<?php endif; ?>

// ==========================================
// MODAL FUNCTIONS
// ==========================================
const modal = document.getElementById('contentModal');
const createBtn = document.getElementById('createContentBtn');
const closeModalBtn = document.getElementById('closeModalBtn');
const cancelModalBtn = document.getElementById('cancelModalBtn');

function openModal(editingData = null) {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Reset all sections first
    document.querySelectorAll('.form-section').forEach(s => s.style.display = 'none');
    document.querySelectorAll('.tool-tab').forEach(t => t.classList.remove('active'));
    
    // Show main section by default
    const mainTab = document.querySelector('.tool-tab[data-section="main"]');
    if (mainTab) mainTab.classList.add('active');
    document.getElementById('mainSection').style.display = '';
    
    if (editingData) {
        document.getElementById('modalTitle').textContent = 'Edit Content';
        document.getElementById('content_id').value = editingData.id || 0;
        document.getElementById('subject_id').value = editingData.subject_id || '';
        filterCategoryOptions();
        document.getElementById('category_id').value = editingData.category_id || '';
        document.getElementById('title').value = editingData.title || '';
        document.getElementById('slug').value = editingData.slug || '';
        document.getElementById('sort_order').value = editingData.sort_order || 0;
        document.getElementById('short_description').value = editingData.short_description || '';
        document.getElementById('reference_text').value = editingData.reference_text || '';
        document.getElementById('source_name').value = editingData.source_name || '';
        document.getElementById('tags').value = editingData.tags || '';
        document.getElementById('status').value = editingData.status || 'draft';
        document.querySelector('[name="is_featured"]').checked = editingData.is_featured == 1;
        document.querySelector('[name="allow_auto_completion"]').checked = editingData.allow_auto_completion == null || editingData.allow_auto_completion == 1;
        document.querySelector('[name="allow_manual_completion"]').checked = editingData.allow_manual_completion == null || editingData.allow_manual_completion == 1;
        
        document.getElementById('existing_content_image').value = editingData.image_path || '';
        document.getElementById('existing_audio').value = editingData.audio_path || '';
        document.getElementById('existing_video_path').value = editingData.video_path || '';
        if (editingData.video_url) document.getElementById('video_url').value = editingData.video_url;
        
        updateMediaDisplay(editingData);
        loadContentDetails(editingData.id);
    } else {
        document.getElementById('modalTitle').textContent = 'Create New Content';
        document.getElementById('contentForm').reset();
        document.getElementById('content_id').value = '0';
        document.getElementById('partsContainer').innerHTML = '';
        document.getElementById('questionsContainer').innerHTML = '';
        document.getElementById('multiPartContent').style.display = 'none';
        document.getElementById('quizContent').style.display = 'none';
        document.getElementById('hasQuizToggle').checked = false;
        document.getElementById('audioPreview').innerHTML = '';
        document.getElementById('videoPreview').innerHTML = '';
        updateMediaDisplay(null);
    }
}

function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

createBtn.addEventListener('click', () => openModal());
closeModalBtn.addEventListener('click', closeModal);
cancelModalBtn.addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// ==========================================
// TAB SWITCHING
// ==========================================
document.querySelectorAll('.tool-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.tool-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        document.querySelectorAll('.form-section').forEach(s => s.style.display = 'none');
        
        const sectionId = this.dataset.section + 'Section';
        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.style.display = '';
        }
    });
});

function updateMediaDisplay(editingData) {
    const removeAudioBtn = document.getElementById('removeAudioBtn');
    const removeVideoBtn = document.getElementById('removeVideoBtn');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const clearVideoUrlBtn = document.getElementById('clearVideoUrlBtn');
    const audioFileNameSpan = document.getElementById('audioFileName');
    const videoFileNameSpan = document.getElementById('videoFileName');
    const contentImageFileNameSpan = document.getElementById('contentImageFileName');
    const audioPreview = document.getElementById('audioPreview');
    const videoPreview = document.getElementById('videoPreview');
    const videoUrlField = document.getElementById('video_url');
    
    if (removeAudioBtn) removeAudioBtn.style.display = 'none';
    if (removeVideoBtn) removeVideoBtn.style.display = 'none';
    if (removeImageBtn) removeImageBtn.style.display = 'none';
    if (clearVideoUrlBtn) clearVideoUrlBtn.style.display = 'none';
    if (audioFileNameSpan) audioFileNameSpan.textContent = '';
    if (videoFileNameSpan) videoFileNameSpan.textContent = '';
    if (contentImageFileNameSpan) contentImageFileNameSpan.textContent = '';
    if (audioPreview) audioPreview.innerHTML = '';
    if (videoPreview) videoPreview.innerHTML = '';
    const contentImageContainer = document.getElementById('contentImagePreviewContainer');
    if (contentImageContainer) contentImageContainer.style.display = 'none';
    
    if (editingData && editingData.audio_path && editingData.audio_path !== '') {
        if (audioFileNameSpan) audioFileNameSpan.textContent = `📁 Current: ${editingData.audio_path.split('/').pop()}`;
        if (removeAudioBtn) removeAudioBtn.style.display = 'inline-flex';
        if (audioPreview) audioPreview.innerHTML = `<p style="font-size:13px;">📁 Current: ${editingData.audio_path.split('/').pop()}</p>`;
    }
    
    if (editingData && editingData.video_path && editingData.video_path !== '') {
        if (videoFileNameSpan) videoFileNameSpan.textContent = `📁 Current: ${editingData.video_path.split('/').pop()}`;
        if (removeVideoBtn) removeVideoBtn.style.display = 'inline-flex';
        if (videoPreview) videoPreview.innerHTML = `<p style="font-size:13px;">📁 Current: ${editingData.video_path.split('/').pop()}</p>`;
    }
    
    if (editingData && editingData.video_url && editingData.video_url !== '') {
        if (clearVideoUrlBtn) clearVideoUrlBtn.style.display = 'inline-flex';
        if (videoUrlField) videoUrlField.value = editingData.video_url;
    }
    
    if (editingData && editingData.image_path && editingData.image_path !== '') {
        if (contentImageFileNameSpan) contentImageFileNameSpan.textContent = `📁 Current: ${editingData.image_path.split('/').pop()}`;
        if (removeImageBtn) removeImageBtn.style.display = 'inline-flex';
        const contentImagePreview = document.getElementById('contentImagePreview');
        if (contentImagePreview && contentImageContainer) {
            contentImagePreview.src = editingData.image_path;
            contentImageContainer.style.display = 'block';
        }
    }
}

function attachMediaListeners() {
    const removeAudioBtn = document.getElementById('removeAudioBtn');
    const removeVideoBtn = document.getElementById('removeVideoBtn');
    const removeImageBtn = document.getElementById('removeImageBtn');
    const clearVideoUrlBtn = document.getElementById('clearVideoUrlBtn');
    
    if (removeAudioBtn) {
        removeAudioBtn.addEventListener('click', () => {
            const contentId = document.getElementById('content_id').value;
            if (contentId && contentId > 0) {
                removeMedia(contentId, 0, 'audio', 'audio_path', 'removeAudioBtn', 'existing_audio');
            } else {
                showToast('Please save the content first before removing media', 'danger');
            }
        });
    }
    
    if (removeVideoBtn) {
        removeVideoBtn.addEventListener('click', () => {
            const contentId = document.getElementById('content_id').value;
            if (contentId && contentId > 0) {
                removeMedia(contentId, 0, 'video', 'video_path', 'removeVideoBtn', 'existing_video_path');
            } else {
                showToast('Please save the content first before removing media', 'danger');
            }
        });
    }
    
    if (removeImageBtn) {
        removeImageBtn.addEventListener('click', () => {
            const contentId = document.getElementById('content_id').value;
            if (contentId && contentId > 0) {
                removeMedia(contentId, 0, 'image', 'image_path', 'removeImageBtn', 'existing_content_image');
            } else {
                showToast('Please save the content first before removing media', 'danger');
            }
        });
    }
    
    if (clearVideoUrlBtn) {
        clearVideoUrlBtn.addEventListener('click', () => {
            const contentId = document.getElementById('content_id').value;
            if (contentId && contentId > 0) {
                clearVideoUrl(contentId, 0);
            } else {
                showToast('Please save the content first before clearing URL', 'danger');
            }
        });
    }
}

async function removeMedia(contentId, partId, mediaType, fieldName, elementId, hiddenInputId) {
    if (!contentId || contentId == 0) {
        showToast('Please save the content first before removing media', 'danger');
        return;
    }
    
    if (!confirm(`Are you sure you want to remove this ${mediaType}?`)) return;
    
    const removeBtn = document.getElementById(elementId);
    const originalText = removeBtn ? removeBtn.textContent : '';
    if (removeBtn) {
        removeBtn.disabled = true;
        removeBtn.textContent = '⏳ Removing...';
    }
    
    try {
        let url = `admin_contents.php?ajax=1&delete_media=1&content_id=${contentId}&media_type=${mediaType}&field_name=${fieldName}`;
        if (partId) url += `&part_id=${partId}`;
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success) {
            const hiddenInput = document.getElementById(hiddenInputId);
            if (hiddenInput) hiddenInput.value = '';
            let previewDiv = null;
            if (mediaType === 'audio') previewDiv = document.getElementById('audioPreview');
            else if (mediaType === 'video') previewDiv = document.getElementById('videoPreview');
            else if (mediaType === 'image') previewDiv = document.getElementById('contentImagePreviewContainer');
            if (previewDiv) previewDiv.innerHTML = '';
            if (removeBtn) removeBtn.style.display = 'none';
            showToast(`${mediaType.charAt(0).toUpperCase() + mediaType.slice(1)} removed successfully!`, 'success');
        } else {
            showToast(result.message || 'Failed to remove media', 'danger');
        }
    } catch (error) {
        console.error('Error removing media:', error);
        showToast('An error occurred while removing media', 'danger');
    } finally {
        if (removeBtn) {
            removeBtn.disabled = false;
            removeBtn.textContent = originalText;
        }
    }
}

async function clearVideoUrl(contentId, partId) {
    if (!contentId || contentId == 0) {
        showToast('Please save the content first before clearing URL', 'danger');
        return;
    }
    
    if (!confirm('Are you sure you want to clear the external video URL?')) return;
    
    const clearUrlBtn = document.getElementById('clearVideoUrlBtn');
    const originalText = clearUrlBtn ? clearUrlBtn.textContent : '';
    if (clearUrlBtn) {
        clearUrlBtn.disabled = true;
        clearUrlBtn.textContent = '⏳ Clearing...';
    }
    
    try {
        let url = `admin_contents.php?ajax=1&delete_media=1&content_id=${contentId}&media_type=video&field_name=video_url`;
        if (partId) url += `&part_id=${partId}`;
        
        const response = await fetch(url);
        const result = await response.json();
        
        if (result.success) {
            const videoUrlField = document.getElementById('video_url');
            if (videoUrlField) videoUrlField.value = '';
            if (clearUrlBtn) clearUrlBtn.style.display = 'none';
            showToast('Video URL cleared successfully!', 'success');
        } else {
            showToast(result.message || 'Failed to clear URL', 'danger');
        }
    } catch (error) {
        console.error('Error clearing URL:', error);
        showToast('An error occurred', 'danger');
    } finally {
        if (clearUrlBtn) {
            clearUrlBtn.disabled = false;
            clearUrlBtn.textContent = originalText;
        }
    }
}

// ==========================================
// LOAD CONTENT DETAILS
// ==========================================
async function loadContentDetails(contentId) {
    try {
        const resp = await fetch(`admin_contents.php?edit=${contentId}&ajax=1`);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        
        document.getElementById('partsContainer').innerHTML = '';
        if (data.parts && data.parts.length > 0) {
            document.getElementById('hasPartsToggle').checked = true;
            document.getElementById('multiPartContent').style.display = '';
            document.getElementById('regularContent').style.display = 'none';
            for (const [i, part] of data.parts.entries()) {
                await addPartWithData(part, i);
                attachPartMediaListeners(i);
            }
        } else {
            document.getElementById('hasPartsToggle').checked = false;
            document.getElementById('multiPartContent').style.display = 'none';
            document.getElementById('regularContent').style.display = '';
        }
        
        document.getElementById('questionsContainer').innerHTML = '';
        if (data.quiz) {
            document.getElementById('hasQuizToggle').checked = true;
            document.getElementById('quizContent').style.display = '';
            document.querySelector('[name="quiz_title"]').value = data.quiz.title || 'Knowledge Check';
            document.querySelector('[name="passing_score"]').value = data.quiz.passing_score || 70;
            document.querySelector('[name="quiz_description"]').value = data.quiz.description || '';
            document.querySelector('[name="time_limit_minutes"]').value = data.quiz.time_limit_minutes || '';
            if (data.questions) {
                for (const [i, q] of data.questions.entries()) addQuestionWithData(q, i);
            }
        } else {
            document.getElementById('hasQuizToggle').checked = false;
            document.getElementById('quizContent').style.display = 'none';
        }
    } catch (err) {
        console.error(err);
        showToast('Failed to load content: ' + err.message, 'danger');
    }
}

// ==========================================
// QUIZ BUILDER
// ==========================================
let questionCounter = 0;

function addQuestion() {
    const container = document.getElementById('questionsContainer');
    const index = questionCounter++;
    container.insertAdjacentHTML('beforeend', `
        <div class="part-item" data-q-index="${index}">
            <div class="inline-row" style="margin-bottom:10px;">
                <div style="flex:2;"><label>Question Text</label><input type="text" name="questions[${index}][question_text]" class="form-input" required></div>
                <div style="flex:1;"><label>Type</label><select name="questions[${index}][question_type]" class="form-select" onchange="toggleQuestionType(this,${index})"><option value="multiple_choice">Multiple Choice</option><option value="true_false">True/False</option></select></div>
                <div style="width:100px;"><label>Points</label><input type="number" name="questions[${index}][points]" class="form-input" value="1" min="1"></div>
            </div>
            <div class="options-container" id="optionsContainer_${index}">
                <label>Options (mark correct answer)</label>
                <div class="options-list" id="optionsList_${index}">
                    <div class="option-item"><input type="radio" name="questions[${index}][correct_option_index]" value="0" checked><input type="text" name="questions[${index}][options][]" class="form-input" placeholder="Option 1" required><button type="button" class="btn secondary" style="padding:6px 10px;" onclick="removeOption(this)">✕</button></div>
                    <div class="option-item"><input type="radio" name="questions[${index}][correct_option_index]" value="1"><input type="text" name="questions[${index}][options][]" class="form-input" placeholder="Option 2" required><button type="button" class="btn secondary" style="padding:6px 10px;" onclick="removeOption(this)">✕</button></div>
                </div>
                <button type="button" class="btn secondary btn-icon" style="margin-top:8px;" onclick="addOption(${index})">+ Add Option</button>
            </div>
            <div class="form-row" id="tfAnswer_${index}" style="display:none;"><label>Correct Answer</label><select name="questions[${index}][correct_answer]" class="form-select"><option value="true">True</option><option value="false">False</option></select></div>
            <div class="form-row"><label>Explanation (optional)</label><textarea name="questions[${index}][explanation]" class="form-textarea" rows="2"></textarea></div>
            <button type="button" class="btn warn" style="margin-top:10px;" onclick="removeQuestion(this)">Remove Question</button>
        </div>`);
}

function addQuestionWithData(q, index) {
    const container = document.getElementById('questionsContainer');
    const qType = q.question_type || 'multiple_choice';
    container.insertAdjacentHTML('beforeend', `
        <div class="part-item" data-q-index="${index}">
            <div class="inline-row" style="margin-bottom:10px;">
                <div style="flex:2;"><label>Question Text</label><input type="text" name="questions[${index}][question_text]" class="form-input" value="${escapeHtml(q.question_text)}" required></div>
                <div style="flex:1;"><label>Type</label><select name="questions[${index}][question_type]" class="form-select" onchange="toggleQuestionType(this,${index})"><option value="multiple_choice" ${qType==='multiple_choice'?'selected':''}>Multiple Choice</option><option value="true_false" ${qType==='true_false'?'selected':''}>True/False</option></select></div>
                <div style="width:100px;"><label>Points</label><input type="number" name="questions[${index}][points]" class="form-input" value="${q.points||1}" min="1"></div>
            </div>
            <div class="options-container" id="optionsContainer_${index}" style="${qType==='multiple_choice'?'':'display:none;'}">
                <label>Options (mark correct answer)</label>
                <div class="options-list" id="optionsList_${index}"></div>
                <button type="button" class="btn secondary btn-icon" style="margin-top:8px;" onclick="addOption(${index})">+ Add Option</button>
            </div>
            <div class="form-row" id="tfAnswer_${index}" style="${qType==='true_false'?'':'display:none;'}"><label>Correct Answer</label><select name="questions[${index}][correct_answer]" class="form-select"><option value="true" ${q.correct_answer==='true'?'selected':''}>True</option><option value="false" ${q.correct_answer==='false'?'selected':''}>False</option></select></div>
            <div class="form-row"><label>Explanation (optional)</label><textarea name="questions[${index}][explanation]" class="form-textarea" rows="2">${escapeHtml(q.explanation||'')}</textarea></div>
            <button type="button" class="btn warn" style="margin-top:10px;" onclick="removeQuestion(this)">Remove Question</button>
        </div>`);
    if (qType === 'multiple_choice' && q.options) {
        const list = document.getElementById(`optionsList_${index}`);
        list.innerHTML = '';
        q.options.forEach((opt, optIdx) => addOptionWithData(index, opt, optIdx, opt.is_correct));
    }
    questionCounter = Math.max(questionCounter, index + 1);
}

function toggleQuestionType(select, index) {
    const parent = select.closest('.part-item');
    const opts = parent.querySelector('.options-container');
    const tf = parent.querySelector(`[id^="tfAnswer_"]`);
    if (select.value === 'multiple_choice') { opts.style.display = ''; tf.style.display = 'none'; }
    else { opts.style.display = 'none'; tf.style.display = ''; }
}

function addOption(qIndex) {
    const list = document.getElementById(`optionsList_${qIndex}`);
    const count = list.children.length;
    const div = document.createElement('div');
    div.className = 'option-item';
    div.innerHTML = `<input type="radio" name="questions[${qIndex}][correct_option_index]" value="${count}"><input type="text" name="questions[${qIndex}][options][]" class="form-input" placeholder="Option ${count+1}" required><button type="button" class="btn secondary" style="padding:6px 10px;" onclick="removeOption(this)">✕</button>`;
    list.appendChild(div);
    updateRadioValues(qIndex);
}

function addOptionWithData(qIndex, opt, optIdx, isCorrect) {
    const list = document.getElementById(`optionsList_${qIndex}`);
    const div = document.createElement('div');
    div.className = 'option-item';
    div.innerHTML = `<input type="radio" name="questions[${qIndex}][correct_option_index]" value="${optIdx}" ${isCorrect?'checked':''}><input type="text" name="questions[${qIndex}][options][]" class="form-input" value="${escapeHtml(opt.option_text)}" required><button type="button" class="btn secondary" style="padding:6px 10px;" onclick="removeOption(this)">✕</button>`;
    list.appendChild(div);
}

function removeOption(btn) {
    const item = btn.closest('.option-item');
    const list = item.parentNode;
    const qIndex = list.id.split('_')[1];
    item.remove();
    updateRadioValues(qIndex);
}

function updateRadioValues(qIndex) {
    const list = document.getElementById(`optionsList_${qIndex}`);
    list.querySelectorAll('input[type="radio"]').forEach((radio, idx) => radio.value = idx);
}

function removeQuestion(btn) { btn.closest('.part-item').remove(); }

// ==========================================
// TOGGLES
// ==========================================
document.getElementById('hasPartsToggle')?.addEventListener('change', function() {
    const isChecked = this.checked;
    document.getElementById('regularContent').style.display = isChecked ? 'none' : '';
    document.getElementById('multiPartContent').style.display = isChecked ? '' : 'none';
});

document.getElementById('hasQuizToggle')?.addEventListener('change', function() {
    document.getElementById('quizContent').style.display = this.checked ? '' : 'none';
});

// ==========================================
// CATEGORY FILTER
// ==========================================
const subjectSelect = document.getElementById('subject_id');
const categorySelect = document.getElementById('category_id');

function filterCategoryOptions() {
    if (!subjectSelect || !categorySelect) return;
    const selSubject = subjectSelect.value;
    Array.from(categorySelect.options).forEach((opt, idx) => {
        if (idx === 0) { opt.hidden = false; return; }
        const optSubjectId = opt.getAttribute('data-subject-id');
        opt.hidden = selSubject !== '' && optSubjectId !== selSubject;
    });
    if (categorySelect.options[categorySelect.selectedIndex]?.hidden) categorySelect.value = '';
}

if (subjectSelect && categorySelect) {
    subjectSelect.addEventListener('change', filterCategoryOptions);
    filterCategoryOptions();
}

// ==========================================
// SLUG
// ==========================================
document.getElementById('title')?.addEventListener('blur', function() {
    const slugInput = document.getElementById('slug');
    if (slugInput && !slugInput.value) {
        slugInput.value = this.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
});

// ==========================================
// EDIT BUTTON
// ==========================================
document.querySelectorAll('.edit-content-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        try {
            const resp = await fetch(`admin_contents.php?edit=${this.dataset.contentId}&ajax=1`);
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            openModal(data);
        } catch (err) {
            showToast('Failed to load content: ' + err.message, 'danger');
        }
    });
});

// ==========================================
// FORM SUBMISSION
// ==========================================
document.getElementById('contentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Collect all editor content
    for (const div of document.querySelectorAll('.part-editor')) {
        if (div.editorInstance) {
            const idParts = div.id.split('_');
            const partIndex = idParts[2];
            const sectionIndex = idParts[3];
            
            if (partIndex !== undefined && sectionIndex !== undefined) {
                let html = div.editorInstance.getData();
                const textarea = document.createElement('textarea');
                textarea.innerHTML = html;
                html = textarea.value;
                
                const htmlField = document.querySelector(`textarea[name="parts[${partIndex}][sections][${sectionIndex}][content_html]"]`);
                if (htmlField) htmlField.value = html;
                
                const plainField = document.querySelector(`textarea[name="parts[${partIndex}][sections][${sectionIndex}][content]"]`);
                if (plainField) {
                    const temp = document.createElement('div');
                    temp.innerHTML = html;
                    plainField.value = (temp.textContent || '').trim();
                }
            }
        }
    }
    
    // Also collect main editor content if it exists
    if (window.bodyEditor) {
        let html = window.bodyEditor.getData();
        const textarea = document.createElement('textarea');
        textarea.innerHTML = html;
        html = textarea.value;
        const bodyHtmlField = document.getElementById('body_html');
        if (bodyHtmlField) bodyHtmlField.value = html;
        const bodyField = document.getElementById('body');
        if (bodyField) {
            const temp = document.createElement('div');
            temp.innerHTML = html;
            bodyField.value = (temp.textContent || '').trim();
        }
    }
    
    const formData = new FormData(this);
    try {
        const resp = await fetch('admin_contents.php', {
            method: 'POST', 
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const result = await resp.json();
        if (result.success) { 
            showToast(result.message, 'success'); 
            closeModal(); 
            setTimeout(() => location.reload(), 1000); 
        } else {
            showToast(result.message || 'An error occurred while saving', 'danger');
        }
    } catch (err) { 
        console.error('Save error:', err);
        showToast('An error occurred while saving: ' + err.message, 'danger'); 
    }
});

// ==========================================
// AJAX TOGGLE STATUS
// ==========================================
document.querySelectorAll('.toggle-status-form').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        try {
            const resp = await fetch('admin_contents.php', { 
                method: 'POST', 
                body: new FormData(this), 
                headers: { 'X-Requested-With': 'XMLHttpRequest' } 
            });
            const result = await resp.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) { showToast('An error occurred', 'danger'); }
    });
});

// ==========================================
// AJAX TOGGLE COMPLETION LOCK (auto / manual)
// ==========================================
document.querySelectorAll('.toggle-completion-form').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        try {
            const resp = await fetch('admin_contents.php', {
                method: 'POST',
                body: new FormData(this),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await resp.json();
            showToast(result.message, result.success ? 'success' : 'danger');
            if (result.success) setTimeout(() => location.reload(), 1000);
        } catch (err) { showToast('An error occurred', 'danger'); }
    });
});

// ==========================================
// SEARCH/FILTER
// ==========================================
const contentSearchInput = document.getElementById('contentSearchInput');
const subjectFilter = document.getElementById('subjectFilter');
const statusFilter = document.getElementById('statusFilter');
const emptySearchState = document.getElementById('emptySearchState');

function applyContentFilters() {
    const query = (contentSearchInput?.value || '').trim().toLowerCase();
    const subjectVal = (subjectFilter?.value || '').trim().toLowerCase();
    const statusVal = (statusFilter?.value || '').trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.content-search-item').forEach(item => {
        const matches = (query === '' || (item.dataset.search||'').includes(query)) && 
                       (subjectVal === '' || (item.dataset.subject||'') === subjectVal) && 
                       (statusVal === '' || (item.dataset.status||'') === statusVal);
        item.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });
    if (emptySearchState) emptySearchState.style.display = visible === 0 ? 'block' : 'none';
}
contentSearchInput?.addEventListener('input', applyContentFilters);
subjectFilter?.addEventListener('change', applyContentFilters);
statusFilter?.addEventListener('change', applyContentFilters);

// ==========================================
// SIDEBAR & THEME
// ==========================================
(function() {
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');
    
    function openSidebar() { sidebar?.classList.add('open'); sidebarOverlay?.classList.add('show'); body.style.overflow = 'hidden'; }
    function closeSidebar() { sidebar?.classList.remove('open'); sidebarOverlay?.classList.remove('show'); body.style.overflow = ''; }
    menuToggle?.addEventListener('click', () => sidebar?.classList.contains('open') ? closeSidebar() : openSidebar());
    sidebarOverlay?.addEventListener('click', closeSidebar);
    
    // Default to dark mode
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        if (themeToggle) themeToggle.textContent = '🌙';
    } else {
        document.body.classList.add('dark-mode');
        document.body.classList.remove('light-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }
    
    themeToggle?.addEventListener('click', () => { 
        document.body.classList.toggle('dark-mode');
        document.body.classList.toggle('light-mode');
        const isDark = document.body.classList.contains('dark-mode');
        themeToggle.textContent = isDark ? '☀' : '🌙';
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
    
    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', e => { 
            e.stopPropagation(); 
            profileMenu.classList.toggle('show'); 
            profileToggle.setAttribute('aria-expanded', profileMenu.classList.contains('show')); 
        });
        document.addEventListener('click', e => { 
            if (!profileArea?.contains(e.target)) { 
                profileMenu.classList.remove('show'); 
                profileToggle.setAttribute('aria-expanded', 'false'); 
            } 
        });
    }
})();

// ==========================================
// INITIALIZATION
// ==========================================
setupDragDrop('audioDropZone', 'audio_file', 'audioPreview', 'existing_audio');
setupDragDrop('videoDropZone', 'video_file', 'videoPreview', 'existing_video_path');
attachMediaListeners();

// Initialize the main CKEditor
async function initMainEditor() {
    const editorElement = document.getElementById('body_editor');
    if (!editorElement) return;
    try {
        const editor = await ClassicEditor.create(editorElement, {
            extraPlugins: [MyCustomUploadAdapterPlugin],
            toolbar: {
                items: ['heading', '|', 'bold', 'italic', 'underline', '|', 
                        'bulletedList', 'numberedList', '|', 'link', 'imageUpload', 
                        'mediaEmbed', '|', 'undo', 'redo'],
                shouldNotGroupWhenFull: true
            },
            language: { ui: 'en', content: 'en' }
        });
        setEditorToLTR(editor);
        window.bodyEditor = editor;
        
        const bodyHtmlField = document.getElementById('body_html');
        if (bodyHtmlField && bodyHtmlField.value) {
            editor.setData(bodyHtmlField.value);
        }
        console.log('✅ Main editor initialized');
    } catch (error) {
        console.error('Main editor initialization failed:', error);
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    console.log('🚀 Page loaded');
    await initMainEditor();
});
</script>
</body>
</html>