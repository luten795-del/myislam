<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

date_default_timezone_set('Africa/Dar_es_Salaam');

function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// header.php's profile button falls back to these initials when there's no
// profile picture - defined here (guarded) in case it isn't already pulled
// in by translations.php / a shared functions file on this page.
if (!function_exists('initials')) {
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
}

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    header('Location: login.php?error=login_required');
    exit;
}
$userId = (int)$_SESSION['user_id'];
$lang = ($_SESSION['language'] ?? 'en') === 'sw' ? 'sw' : 'en';

try {
    $conn = db();
    if (!$conn) throw new Exception('Database connection failed');
} catch (Throwable $e) {
    error_log('Adhkaar DB error: ' . $e->getMessage());
    die('System error. Please try again later.');
}

// ============================================================================
// CURRENT USER (same fetch/sanitize pattern as user_dashboard.php, so the
// shared header's profile menu shows the real name/email/photo/join-date
// here too, instead of falling back to a generic "User" placeholder)
// ============================================================================
if (!function_exists('getCurrentUser')) {
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
            error_log('Error getting user: ' . $e->getMessage());
            return null;
        }
    }
}

try {
    $dbUser = getCurrentUser($conn, $userId);
    if (!$dbUser) {
        session_destroy();
        header('Location: login.php?error=account_disabled');
        exit;
    }
} catch (Throwable $e) {
    error_log('Error fetching user: ' . $e->getMessage());
    die('Unable to load user data. Please try again later.');
}

$userInfo = [
    'name' => $dbUser['username'] ?? $dbUser['full_name'] ?? 'User',
    'email' => $dbUser['email'] ?? '',
    'picture' => !empty($dbUser['profile_image']) ? $dbUser['profile_image'] : '',
    'role' => $dbUser['role'] ?? 'user',
    'member_since' => isset($dbUser['created_at']) ? date('F Y', strtotime($dbUser['created_at'])) : 'recently',
];
$userDisplayName = $userInfo['name'];

// ============================================================================
// DATA
// ============================================================================
try {
    $categories = $conn->query("
        SELECT c.id, c.name, c.name_sw, c.slug, c.icon, c.category_type,
               (SELECT COUNT(*) FROM adhkaar a WHERE a.category_id = c.id AND a.is_active = 1) AS item_count
        FROM adhkaar_categories c
        WHERE c.is_active = 1
        ORDER BY c.sort_order ASC, c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Adhkaar categories fetch error: ' . $ex->getMessage());
    $categories = [];
}

// ----------------------------------------------------------------------
// Time-of-day detection - drives both which category opens by default
// (when the user hasn't picked one explicitly) and the visual theme.
//   04:00-10:59  morning  (sunrise)
//   11:00-15:59  general  (plain daylight, no strong morning/evening feel)
//   16:00-18:59  evening  (sunset)
//   19:00-03:59  night    (moon & stars)
// ----------------------------------------------------------------------
function currentTimeOfDay(): string {
    $hour = (int)date('G');
    if ($hour >= 4 && $hour < 11) return 'morning';
    if ($hour >= 11 && $hour < 16) return 'general';
    if ($hour >= 16 && $hour < 19) return 'evening';
    return 'night';
}
$currentTimeOfDay = currentTimeOfDay();

$selectedSlug = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$selectedCategory = null;
foreach ($categories as $c) {
    if ($c['slug'] === $selectedSlug) { $selectedCategory = $c; break; }
}
if (!$selectedCategory && $selectedSlug === '' && !empty($categories)) {
    // No explicit category requested - prefer whichever matches the
    // current time of day, falling back to the first available category.
    foreach ($categories as $c) {
        if (($c['category_type'] ?? 'general') === $currentTimeOfDay) { $selectedCategory = $c; break; }
    }
}
if (!$selectedCategory && !empty($categories)) {
    $selectedCategory = $categories[0];
}

$items = [];
if ($selectedCategory) {
    try {
        $stmt = $conn->prepare("
            SELECT id, title, title_sw,
                   virtue, virtue_sw, audio_path, audio_url, video_path, video_url,
                   source_reference
            FROM adhkaar
            WHERE category_id = ? AND is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$selectedCategory['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        error_log('Adhkaar items fetch error: ' . $ex->getMessage());
    }
}

// ----------------------------------------------------------------------
// Each adhkaar item can now bundle MULTIPLE verses/instances (e.g. a
// sleep-adhkaar hadith that groups Ikhlas + Falaq + An-Nas, each with its
// own Arabic text, translation, and repeat count - see adhkaar_verses).
// For the sidebar we only need each item's list of repeat-count targets
// (cheap: just integers) so the "completed today" progress summary can be
// computed for every item without loading each one's full verse text.
// ----------------------------------------------------------------------
$itemVerseTargets = [];
if (!empty($items)) {
    try {
        $ids = array_column($items, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT adhkaar_id, repeat_count
            FROM adhkaar_verses
            WHERE adhkaar_id IN ($placeholders)
            ORDER BY adhkaar_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemVerseTargets[(int)$row['adhkaar_id']][] = (int)$row['repeat_count'];
        }
    } catch (Throwable $ex) {
        error_log('Adhkaar verse-targets fetch error: ' . $ex->getMessage());
    }
}

$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedItem = null;
$selectedIndex = -1;
foreach ($items as $idx => $it) {
    if ((int)$it['id'] === $selectedId) { $selectedItem = $it; $selectedIndex = $idx; break; }
}
if (!$selectedItem && !empty($items)) {
    $selectedItem = $items[0];
    $selectedIndex = 0;
}
// Previous/next items in the same reading order as the sidebar list, so
// finishing an item at the bottom of the page can jump straight to the
// next one instead of having to scroll back up to the list to pick it.
$prevItem = ($selectedIndex > 0) ? $items[$selectedIndex - 1] : null;
$nextItem = ($selectedIndex >= 0 && $selectedIndex < count($items) - 1) ? $items[$selectedIndex + 1] : null;

// Full verse list for the selected item only - this is the data that
// actually renders in the wide reading pane.
$verses = [];
if ($selectedItem) {
    try {
        $stmt = $conn->prepare("
            SELECT id, arabic_text, transliteration, translation_en, translation_sw, repeat_count
            FROM adhkaar_verses
            WHERE adhkaar_id = ?
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$selectedItem['id']]);
        $verses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
        error_log('Adhkaar verses fetch error: ' . $ex->getMessage());
    }
}

function resolveMediaUrl(?string $path, ?string $url): ?string {
    if (!empty($path)) return $path;
    if (!empty($url)) return $url;
    return null;
}

function isYoutubeUrl(string $url): bool {
    return (bool)preg_match('/(youtube\.com|youtu\.be)/i', $url);
}

function youtubeEmbedUrl(string $url): ?string {
    if (preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([A-Za-z0-9_-]{6,})/', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    return null;
}

// ----------------------------------------------------------------------
// Detail fields for the selected item, computed once here rather than
// inside the sidebar loop - the full reading view (Arabic text,
// translation, media) renders once in the wide main pane, not squeezed
// into the narrow item list.
// ----------------------------------------------------------------------
$mainTitle = $mainVirtue = $mainAudio = $mainVideo = null;
$videoIsLocalMain = $videoIsYoutubeMain = false;
if ($selectedItem) {
    $mainTitle = ($lang === 'sw' && !empty($selectedItem['title_sw'])) ? $selectedItem['title_sw'] : $selectedItem['title'];
    $mainVirtue = ($lang === 'sw' && !empty($selectedItem['virtue_sw'])) ? $selectedItem['virtue_sw'] : $selectedItem['virtue'];
    $mainAudio = resolveMediaUrl($selectedItem['audio_path'], $selectedItem['audio_url']);
    $mainVideo = resolveMediaUrl($selectedItem['video_path'], $selectedItem['video_url']);
    $videoIsLocalMain = !empty($selectedItem['video_path']);
    $videoIsYoutubeMain = $mainVideo && !$videoIsLocalMain && isYoutubeUrl($mainVideo);
}
// ----------------------------------------------------------------------
// Shared header - replaces the hand-rolled <head>/<header>/sidebar markup
// this page used to carry on its own (see header.php's own doc comment
// for why). currentTimeOfDay() above still decides which visual theme
// applies; it's just handed to header.php via $bodyClass now instead of
// this file printing its own <body class="..."> tag.
$bodyThemeType     = $currentTimeOfDay;
$pageTitle         = 'Adhkaar - MyIslam';
$metaDescription   = $lang === 'sw' ? 'Soma, sikiliza au tazama adhkaar za Kiislamu.' : 'Read, listen to, or watch Islamic adhkaar.';
$metaKeywords      = 'adhkaar, dhikr, dua, islam';
$metaAuthor        = 'MyIslam';
$ogType            = 'website';
$htmlLang          = $lang;
$htmlDir           = 'ltr';
// "adhkaar-page" is only used to scope this page's own background
// watermark below - it doesn't affect header.php/theme.css at all.
$bodyClass         = 'theme-' . $bodyThemeType . ' adhkaar-page';
// NOTE: $mainNavigation and $unreadCount still aren't built on this page,
// so header.php falls back to its defaults for those (empty top-nav,
// notification badge hidden) - it degrades gracefully rather than
// erroring. $userInfo/$userDisplayName ARE set above now, same as
// content.php and user_dashboard.php, so the profile menu here shows the
// real name/email/photo/join-date instead of a generic placeholder.
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

// ----------------------------------------------------------------------
// Reflection of the Day - a Hadith OR a Qur'an Ayat, admin enters/manages
// these in admin_adhkaar.php (Hadith/Ayat tab). One is shown per calendar
// day, the same for every user, and rotates through all active entries in
// order (by sort_order/id) as the day-of-year advances, wrapping back to
// the start once it runs out. Using day-of-year (not a random pick) means
// it only changes once a day and stays put on repeat visits/refreshes
// within that day. Still stored in the `hadiths` table/$hadith variable
// for backward compatibility - the `type` column ('hadith' or 'ayat')
// is what actually decides how it's labelled below.
// ----------------------------------------------------------------------
$hadith = null;
try {
    $hadithCount = (int)$conn->query("SELECT COUNT(*) FROM hadiths WHERE is_active = 1")->fetchColumn();
    if ($hadithCount > 0) {
        $dayIndex = ((int)date('z')) % $hadithCount; // date('z') = 0-365, day of year
        $stmt = $conn->prepare("
            SELECT text, text_sw, source_reference, type
            FROM hadiths
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
            LIMIT 1 OFFSET ?
        ");
        $stmt->bindValue(1, $dayIndex, PDO::PARAM_INT);
        $stmt->execute();
        $hadith = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $ex) {
    // Either the hadiths table, or its `type` column, doesn't exist yet on
    // older installs - retry without `type` so existing installs that
    // haven't run the ALTER TABLE yet still show a card (just always
    // labelled as a hadith), instead of losing the feature entirely.
    try {
        $hadithCount = (int)$conn->query("SELECT COUNT(*) FROM hadiths WHERE is_active = 1")->fetchColumn();
        if ($hadithCount > 0) {
            $dayIndex = ((int)date('z')) % $hadithCount;
            $stmt = $conn->prepare("
                SELECT text, text_sw, source_reference
                FROM hadiths
                WHERE is_active = 1
                ORDER BY sort_order ASC, id ASC
                LIMIT 1 OFFSET ?
            ");
            $stmt->bindValue(1, $dayIndex, PDO::PARAM_INT);
            $stmt->execute();
            $hadith = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($hadith) $hadith['type'] = 'hadith';
        }
    } catch (Throwable $ex2) {
        error_log('Adhkaar hadith-of-day fetch error: ' . $ex2->getMessage());
    }
}
$hadithType = $hadith['type'] ?? 'hadith';
$hadithIsAyat = ($hadithType === 'ayat');

require __DIR__ . '/includes/header.php';
?>

<!-- Page-specific styles only - the reset, CSS variables, header, and
     sidebar styles now live once in /assets/css/theme.css via header.php. -->
<style>
/* Decorative Arabic watermark, scoped to this page only */
body.adhkaar-page::before {
    content: ""; position: fixed; inset: 0; pointer-events: none; z-index: -1;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 200 200'%3E%3Ctext x='20' y='150' font-family='Amiri, serif' font-size='120' fill='%230E4A34' fill-opacity='0.025' transform='rotate(-15 100 100)'%3E%D8%A7%D9%84%D9%84%D9%87%3C/text%3E%3C/svg%3E");
    background-repeat: repeat;
}

.page-title { text-align: center; padding: 0px; }
.page-title h1 { font-size: clamp(24px, 5vw, 32px); color: var(--brand); font-weight: 800; }
.page-title p { color: var(--text-secondary); margin-top: 4px; font-size: 14px; }

.hadith-of-day {
    box-sizing: border-box; max-width: 760px; margin: 18px auto 0; padding: clamp(16px, 4vw, 24px);
    background: var(--gold-soft); border: 1px solid var(--gold); border-radius: var(--radius-lg);
    text-align: center;
}
.hadith-of-day .hadith-label {
    font-size: 11px; text-transform: uppercase; letter-spacing: .5px; font-weight: 700;
    color: var(--gold); margin-bottom: 10px;
}
.hadith-of-day .hadith-text {
    font-family: 'Amiri', 'Traditional Arabic', serif; direction: rtl; unicode-bidi: embed;
    font-size: clamp(19px, 3.4vw, 24px); line-height: 2; color: var(--text);
}
.hadith-of-day .hadith-text-sw { margin-top: 12px; font-size: 14px; line-height: 1.7; color: var(--text-secondary); font-style: italic; }
.hadith-of-day .hadith-source { margin-top: 12px; font-size: 12px; color: var(--muted); font-weight: 600; }

.adhkaar-layout {
    box-sizing: border-box; max-width: 100%; margin: 0 auto; padding: 8px 10px 12px;
    display: grid; grid-template-columns: 300px 1fr; gap: 12px; align-items: start;
    width: 100%;
}
@media (max-width: 900px) { .adhkaar-layout { grid-template-columns: 1fr; padding: 8px 12px; gap: 10px; } }
@media (max-width: 480px) { .adhkaar-layout { padding: 6px 8px; gap: 8px; } }

.category-pills { box-sizing: border-box; display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.category-pill {
    padding: 9px 14px; border-radius: 999px; background: var(--surface); border: 1px solid var(--line);
    font-size: 13px; font-weight: 600; color: var(--text-secondary); transition: all .2s; white-space: nowrap;
}
.category-pill:hover { background: var(--surface-3); }
.category-pill.active { background: var(--brand); border-color: var(--brand); color: #fff; }

.item-list-card {
    box-sizing: border-box; width: 100%;
    background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg);
    padding: 12px; position: sticky; top: calc(var(--header-height) + 8px);
    max-height: calc(100vh - var(--header-height) - 16px); overflow-y: auto;
}
.item-list-card h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 12px; font-weight: 700; }
.adhkar-list-item {
    display: block; padding: 12px 14px; border-radius: var(--radius-md); border: 1px solid transparent;
    margin-bottom: 6px; transition: all .2s; font-size: 14px; font-weight: 600; color: var(--text);
}
.adhkar-list-item:hover { background: var(--surface-2); }
.adhkar-list-item.active { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
.adhkar-list-item .item-meta { display: block; font-size: 11px; font-weight: 500; color: var(--muted); margin-top: 2px; }
.adhkar-list-item.active .item-meta { color: var(--brand); opacity: .75; }

.empty-list { color: var(--muted); font-size: 13px; text-align: center; padding: 20px 10px; }

.progress-summary {
    font-size: 12px; font-weight: 700; color: var(--brand); background: var(--brand-soft);
    border: 1px solid var(--brand); border-radius: 999px; padding: 6px 12px; text-align: center; margin-bottom: 12px;
}
.adhkar-list-item.item-done { position: relative; padding-right: 34px; }
.adhkar-list-item.item-done::after {
    content: "✓"; position: absolute; right: 12px; top: 12px; color: var(--brand); font-weight: 800; font-size: 14px;
}
.repeat-counter .resetBtn {
    width: 28px; height: 28px; border-radius: 50%; background: transparent; color: var(--gold);
    border: 1px solid var(--gold); font-size: 14px; display: inline-flex; align-items: center; justify-content: center;
}
.repeat-counter .resetBtn:hover { background: var(--gold-soft); }

.detail-card { box-sizing: border-box; width: 100%; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(16px, 3vw, 28px); }
/* Cap the reading column so lines of translation/virtue text stay a
   comfortable length even when the pane itself is very wide on desktop. */
.detail-card > .adhkaar-tracked-item { max-width: 720px; margin: 0 auto; }
.detail-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 18px; }
.detail-header h2 { font-size: clamp(20px, 4vw, 26px); color: var(--brand); font-weight: 800; overflow-wrap: anywhere; }
.detail-source { font-size: 12px; color: var(--muted); margin-top: 4px; }

.repeat-counter {
    box-sizing: border-box; display: inline-flex; align-items: center; gap: 10px; padding: 8px 8px 8px 16px; border-radius: 999px;
    background: var(--gold-soft); border: 1px solid var(--gold); user-select: none;
}
.repeat-counter .count-label { font-size: 13px; font-weight: 700; color: var(--gold); }
.repeat-counter button {
    width: 34px; height: 34px; border-radius: 50%; background: var(--gold); color: #fff; font-weight: 800;
    font-size: 16px; display: inline-flex; align-items: center; justify-content: center; transition: transform .15s;
}
.repeat-counter button:active { transform: scale(0.9); }
.repeat-counter.done { background: var(--brand-soft); border-color: var(--brand); }
.repeat-counter.done .count-label { color: var(--brand); }
.repeat-counter.done button { background: var(--brand); }

.verse-label {
    font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--brand);
    margin: 28px 0 4px; padding-top: 20px; border-top: 1px dashed var(--line);
}
.verse-label:first-of-type { border-top: none; padding-top: 0; margin-top: 8px; }
.verse-repeat-counter { margin: 4px 0 8px; }

.arabic-box {
    box-sizing: border-box; width: 100%;
    background: var(--brand-soft); border: 1px solid var(--line); border-radius: var(--radius-lg);
    padding: clamp(16px, 5vw, 36px); margin: 20px 0; text-align: center;
}
.arabic-text {
    font-family: 'Amiri', 'Traditional Arabic', serif; direction: rtl; unicode-bidi: embed;
    font-size: clamp(22px, 5vw, 40px); line-height: 2.1; color: var(--text); overflow-wrap: break-word;
}
.transliteration { margin-top: 16px; font-style: italic; color: var(--text-secondary); font-size: 15px; overflow-wrap: anywhere; }

.translation-block { box-sizing: border-box; width: 100%; margin: 18px 0; padding: 16px 18px; background: var(--surface-2); border-radius: var(--radius-md); border: 1px solid var(--line); }
.translation-block h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin-bottom: 6px; font-weight: 700; }
.translation-block p { font-size: 15px; line-height: 1.7; color: var(--text); overflow-wrap: anywhere; }

.virtue-block { box-sizing: border-box; width: 100%; margin: 18px 0; padding: 16px 18px; background: var(--gold-soft); border: 1px solid var(--gold); border-radius: var(--radius-md); }
.virtue-block h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--gold); margin-bottom: 6px; font-weight: 700; }
.virtue-block p { font-size: 14px; line-height: 1.7; color: var(--text); overflow-wrap: anywhere; }

.media-grid { box-sizing: border-box; width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 22px; }
@media (max-width: 640px) { .media-grid { grid-template-columns: 1fr; } }
/* Only one of audio/video exists for this item - don't stretch it across
   a two-column grid meant for both. */
.media-grid.media-grid-single { grid-template-columns: 1fr; max-width: 420px; }
.media-panel { box-sizing: border-box; width: 100%; background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 16px; }
.media-panel h4 { font-size: 13px; font-weight: 700; color: var(--text-secondary); margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.media-panel audio { width: 100%; }
.media-panel video, .media-panel iframe { width: 100%; aspect-ratio: 16/9; border-radius: 10px; border: none; display: block; }
.media-empty { color: var(--muted); font-size: 13px; padding: 18px 0; text-align: center; }

.empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
.empty-state .icon { font-size: 48px; margin-bottom: 12px; opacity: .6; }

/* Prev/Next navigation at the bottom of the reading pane - lets a user
   who's scrolled to the end of an item continue straight to the next one
   instead of scrolling back up to the sidebar list. */
.item-nav { box-sizing: border-box; width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--line); }
.item-nav-link {
    box-sizing: border-box; display: flex; align-items: center; gap: 10px; padding: 14px 16px;
    background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--radius-md);
    transition: all .2s ease; overflow: hidden;
}
.item-nav-link:hover { background: var(--brand-soft); border-color: var(--brand); }
.item-nav-next { grid-column: 2; justify-content: flex-end; text-align: right; }
.item-nav-arrow { font-size: 18px; color: var(--brand); flex-shrink: 0; }
.item-nav-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.item-nav-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); font-weight: 700; }
.item-nav-title { font-size: 14px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-nav-done {
    grid-column: 2; display: flex; align-items: center; justify-content: flex-end; text-align: right;
    padding: 14px 16px; color: var(--brand); font-weight: 600; font-size: 14px;
}
@media (max-width: 560px) {
    .item-nav { grid-template-columns: 1fr; }
    .item-nav-next, .item-nav-done { grid-column: 1; }
    .item-nav-next { justify-content: flex-start; text-align: left; flex-direction: row-reverse; }
    .item-nav-done { justify-content: flex-start; text-align: left; }
}

/* Smooth scroll when a sidebar link jumps to #mainDetail, and land the
   reading pane below the sticky header instead of half-hidden under it. */
html { scroll-behavior: smooth; }
#mainDetail { scroll-margin-top: calc(var(--header-height, 0px) + 12px); }

/* Extra-small phones (iPhone SE / small Android, ~360-390px wide) - tighten
   padding/gaps further so nothing feels cramped or forces horizontal scroll. */
@media (max-width: 480px) {
    .item-list-card { padding: 10px; }
    .detail-card { padding: 12px; }
    .arabic-box { padding: 16px; margin: 14px 0; }
    .arabic-text { font-size: 20px; line-height: 1.9; }
    .translation-block, .virtue-block { padding: 12px 14px; margin: 12px 0; }
    .media-panel { padding: 12px; }
    .media-grid { gap: 12px; margin-top: 16px; }
    .repeat-counter { padding: 6px 6px 6px 12px; gap: 8px; }
    .repeat-counter button { width: 30px; height: 30px; font-size: 14px; }
    .detail-header h2 { font-size: 19px; }
    .hadith-of-day { padding: 14px; margin-top: 12px; }
    .hadith-of-day .hadith-text { font-size: 18px; line-height: 1.85; }
}

/* ============================================================================
   TIME-OF-DAY THEMES
   Applied via a class on <body> derived automatically from the visitor's
   current local time (see currentTimeOfDay() above) - never manually
   selected. These only touch the page's decorative background and accent
   colors - dark/light mode continues to control surface and text colors
   independently, so any theme x mode combination stays readable.
   ============================================================================ */
body.theme-morning {
    background: linear-gradient(180deg, #FFF3D6 0%, #FFE1B0 18%, #FCEEDB 42%, var(--bg) 72%);
}
body.theme-morning.dark-mode {
    background: linear-gradient(180deg, #3a2f1f 0%, #2c2418 18%, #1c1f1a 42%, var(--bg) 72%);
}
body.theme-morning .sky-decor {
    position: fixed; left: 50%; top: 70px; width: 200px; height: 200px;
    background: radial-gradient(circle, rgba(255,196,84,.55), rgba(255,196,84,0) 70%);
    border-radius: 50%; transform: translateX(-50%); pointer-events: none; z-index: 0;
    animation: sunRise 6s ease-in-out infinite alternate;
}
@keyframes sunRise { from { top: 95px; opacity: .65; } to { top: 45px; opacity: 1; } }
body.theme-morning .page-title h1 { color: #B8791E; }

body.theme-evening {
    background: linear-gradient(180deg, #3b2350 0%, #7a3b52 22%, #c96a4f 46%, var(--bg) 76%);
}
body.theme-evening.dark-mode {
    background: linear-gradient(180deg, #1c1030 0%, #2c1826 22%, #3a2018 46%, var(--bg) 76%);
}
body.theme-evening .sky-decor {
    position: fixed; left: 50%; bottom: 28%; width: 180px; height: 180px;
    background: radial-gradient(circle, rgba(255,140,90,.5), rgba(255,140,90,0) 70%);
    border-radius: 50%; transform: translateX(-50%); pointer-events: none; z-index: 0;
    animation: sunSet 6s ease-in-out infinite alternate;
}
@keyframes sunSet { from { bottom: 30%; opacity: .75; } to { bottom: 20%; opacity: 1; } }
body.theme-evening .page-title h1 { color: #C96A4F; }

body.theme-night { background: linear-gradient(180deg, #05060f 0%, #0b1226 38%, var(--bg) 80%); }
body.theme-night .sky-decor {
    position: fixed; inset: 0; pointer-events: none; z-index: 0; opacity: .6;
    background-image:
        radial-gradient(1.5px 1.5px at 20% 18%, #fff, transparent),
        radial-gradient(1.5px 1.5px at 62% 10%, #fff, transparent),
        radial-gradient(1px 1px at 80% 28%, #fff, transparent),
        radial-gradient(1px 1px at 30% 42%, #fff, transparent),
        radial-gradient(1.5px 1.5px at 90% 14%, #fff, transparent),
        radial-gradient(1px 1px at 45% 6%, #fff, transparent),
        radial-gradient(1px 1px at 12% 35%, #fff, transparent);
    animation: twinkle 3.5s ease-in-out infinite alternate;
}
@keyframes twinkle { from { opacity: .3; } to { opacity: .75; } }
body.theme-night .sky-decor::after {
    content: "🌙"; position: absolute; top: 55px; right: 10%; font-size: 40px;
    filter: drop-shadow(0 0 14px rgba(255,255,255,.5));
}
body.theme-night .page-title h1 { color: #8FA6D9; }
</style>

<div class="sky-decor" aria-hidden="true"></div>

<div class="page-title" style="position:relative;z-index:1;">
    <h1>📿 Adhkaar</h1>
    <p><?= $lang === 'sw' ? 'Chagua adhkaar ya kusoma, sikiliza au tazama' : 'Choose an adhkar to read, listen to, or watch' ?></p>
</div>

<?php if ($hadith): ?>
<div class="hadith-of-day" style="position:relative;z-index:1;">
    <div class="hadith-label">
        <?= $hadithIsAyat ? '📖' : '🕌' ?>
        <?php if ($hadithIsAyat): ?>
            <?= $lang === 'sw' ? 'Aya ya Leo' : 'Ayat of the Day' ?>
        <?php else: ?>
            <?= $lang === 'sw' ? 'Hadithi ya Leo' : 'Hadith of the Day' ?>
        <?php endif; ?>
    </div>
    <p class="hadith-text" lang="ar" dir="rtl"><?= nl2br(e($hadith['text'])) ?></p>
    <?php if (!empty($hadith['text_sw'])): ?>
        <p class="hadith-text-sw"><?= nl2br(e($hadith['text_sw'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($hadith['source_reference'])): ?>
        <p class="hadith-source">— <?= e($hadith['source_reference']) ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- header.php already opened <main id="mainContent"> for us - this div
     just supplies the adhkaar-specific grid layout inside it. -->
<div class="adhkaar-layout" style="position:relative;z-index:1;">
    <aside>
        <div class="category-pills">
            <label for="categorySelect" style="display:block;margin-bottom:8px;font-weight:700;"><?= $lang === 'sw' ? 'Chagua Sehemu' : 'Choose category' ?></label>
            <select id="categorySelect" name="category" aria-label="Category selector" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--line);background:var(--surface);">
                <?php foreach ($categories as $c):
                    $label = e($lang === 'sw' && !empty($c['name_sw']) ? $c['name_sw'] : $c['name']);
                    $optVal = e($c['slug']);
                    $optSelected = ($selectedCategory && $selectedCategory['slug'] === $c['slug']) ? 'selected' : '';
                ?>
                    <option value="<?= $optVal ?>" <?= $optSelected ?>><?= $label ?> (<?= (int)$c['item_count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="item-list-card">
            <h3><?= $lang === 'sw' ? 'Orodha' : 'Items' ?></h3>
            <input type="search" id="adhkarSearch" placeholder="<?= $lang === 'sw' ? 'Tafuta...' : 'Search...' ?>" aria-label="<?= $lang === 'sw' ? 'Tafuta dhikr' : 'Search adhkar' ?>" style="width:100%;padding:9px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface);margin-bottom:10px;font-size:14px;">
            <div id="categoryProgressSummary" class="progress-summary" style="display:none;"></div>
            <?php if (empty($items)): ?>
                <p class="empty-list"><?= $lang === 'sw' ? 'Hakuna dhikr bado katika sehemu hii.' : 'No adhkaar in this category yet.' ?></p>
            <?php else: ?>
                <?php foreach ($items as $it):
                    $isActive = ($selectedItem && (int)$selectedItem['id'] === (int)$it['id']);
                    $verseTargets = $itemVerseTargets[(int)$it['id']] ?? [];
                    $verseCount = count($verseTargets);
                    if ($verseCount === 1) {
                        $itemMeta = $verseTargets[0] . '× ' . ($lang === 'sw' ? 'mara' : 'repeat');
                    } elseif ($verseCount > 1) {
                        $itemMeta = $verseCount . ' ' . ($lang === 'sw' ? 'sehemu' : 'parts');
                    } else {
                        $itemMeta = $lang === 'sw' ? 'Haijakamilika' : 'Not set up yet';
                    }
                ?>
                    <a href="?category=<?= e($selectedCategory['slug']) ?>&id=<?= (int)$it['id'] ?>#mainDetail"
                       class="adhkar-list-item <?= $isActive ? 'active' : '' ?>"
                       id="item-<?= (int)$it['id'] ?>" data-verse-targets="<?= e(json_encode($verseTargets)) ?>">
                        <?= e($lang === 'sw' && !empty($it['title_sw']) ? $it['title_sw'] : $it['title']) ?>
                        <span class="item-meta"><?= e($itemMeta) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <section class="detail-card" id="mainDetail">
        <?php if (!$selectedItem): ?>
            <div class="empty-state">
                <div class="icon">📿</div>
                <p><?= $lang === 'sw' ? 'Chagua dhikr kutoka kwenye orodha kuanza.' : 'Choose an adhkar from the list to get started.' ?></p>
            </div>
        <?php else: ?>
            <div class="adhkaar-tracked-item" data-item-id="<?= (int)$selectedItem['id'] ?>" data-category-id="<?= (int)$selectedCategory['id'] ?>">
                <div class="detail-header">
                    <div>
                        <h2><?= e($mainTitle) ?></h2>
                        <?php if (!empty($selectedItem['source_reference'])): ?>
                            <div class="detail-source"><?= e($selectedItem['source_reference']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

             

                <?php if (empty($verses)): ?>
                    <div class="empty-state" style="padding:30px 10px;">
                        <p><?= $lang === 'sw' ? 'Dhikr hii bado haijawekwa vizuri - tafadhali mjulishe msimamizi.' : 'This adhkar has no verses set up yet - please let an admin know.' ?></p>
                    </div>
                <?php endif; ?>

                <?php foreach ($verses as $vIndex => $verse): ?>
                    <?php if (count($verses) > 1): ?>
                        <div class="verse-label"><?= $lang === 'sw' ? 'Sehemu' : 'Part' ?> <?= $vIndex + 1 ?> / <?= count($verses) ?></div>
                    <?php endif; ?>

                    <div class="arabic-box">
                        <div class="arabic-text" lang="ar" dir="rtl"><?= nl2br(e($verse['arabic_text'])) ?></div>
                        <?php if (!empty($verse['transliteration'])): ?>
                            <div class="transliteration"><?= e($verse['transliteration']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php
                        $verseTranslation = ($lang === 'sw' && !empty($verse['translation_sw'])) ? $verse['translation_sw'] : $verse['translation_en'];
                        $verseTarget = (int)($verse['repeat_count'] ?: 1);
                    ?>
                    
                    <?php if (!empty($verseTranslation)): ?>
                        <div class="translation-block">
                            <h4><?= $lang === 'sw' ? 'Tafsiri' : 'Translation' ?></h4>
                            <p><?= nl2br(e($verseTranslation)) ?></p>
                        </div>
                    <?php endif; ?>
                       <?php if (!empty($mainVirtue)): ?>
                    <div class="virtue-block">
                        <h4>✨ <?= $lang === 'sw' ? 'Fadhila' : 'Virtue' ?></h4>
                        <p><?= nl2br(e($mainVirtue)) ?></p>
                    </div>
                <?php endif; ?>

                    <div class="repeat-counter verse-repeat-counter" data-item-id="<?= (int)$selectedItem['id'] ?>" data-verse-index="<?= (int)$vIndex ?>" data-target="<?= $verseTarget ?>" data-count="0">
                        <span class="count-label"><span class="countValue">0</span> / <?= $verseTarget ?></span>
                        <button type="button" class="countBtn" aria-label="<?= $lang === 'sw' ? 'Hesabu mara moja' : 'Count one repeat' ?>">+</button>
                        <button type="button" class="resetBtn" title="<?= $lang === 'sw' ? 'Anza upya' : 'Reset' ?>" aria-label="<?= $lang === 'sw' ? 'Anza upya hesabu' : 'Reset counter' ?>">↺</button>
                    </div>
                <?php endforeach; ?>

                <?php
                    // Only build/show a media panel for audio or video when that
                    // field actually has a value - no "No audio/video yet"
                    // placeholders taking up a slot for media that doesn't exist.
                    $hasMainAudio = (bool)$mainAudio;
                    $hasMainVideoEmbed = $mainVideo && ($videoIsLocalMain || ($videoIsYoutubeMain && youtubeEmbedUrl($mainVideo)));
                    $hasMainVideoLinkOnly = $mainVideo && !$hasMainVideoEmbed;
                    $hasMainVideo = $hasMainVideoEmbed || $hasMainVideoLinkOnly;
                    $mainMediaPanelCount = ($hasMainAudio ? 1 : 0) + ($hasMainVideo ? 1 : 0);
                ?>
                <?php if ($mainMediaPanelCount > 0): ?>
                    <div class="media-grid<?= $mainMediaPanelCount === 1 ? ' media-grid-single' : '' ?>">
                        <?php if ($hasMainAudio): ?>
                            <div class="media-panel">
                                <h4>🔊 <?= $lang === 'sw' ? 'Sauti' : 'Audio' ?></h4>
                                <audio controls preload="none" src="<?= e($mainAudio) ?>" class="adhkaar-audio-tracked" data-item-id="<?= (int)$selectedItem['id'] ?>" data-category-id="<?= (int)$selectedCategory['id'] ?>">
                                    <?= $lang === 'sw' ? 'Kivinjari chako hakiunga mkono kicheza sauti.' : 'Your browser does not support the audio player.' ?>
                                </audio>
                            </div>
                        <?php endif; ?>
                        <?php if ($hasMainVideo): ?>
                            <div class="media-panel">
                                <h4>🎬 <?= $lang === 'sw' ? 'Video' : 'Video' ?></h4>
                                <?php if ($videoIsLocalMain): ?>
                                    <video controls preload="none" src="<?= e($mainVideo) ?>" class="adhkaar-video-tracked" data-item-id="<?= (int)$selectedItem['id'] ?>" data-category-id="<?= (int)$selectedCategory['id'] ?>">
                                        <?= $lang === 'sw' ? 'Kivinjari chako hakiunga mkono kicheza video.' : 'Your browser does not support the video player.' ?>
                                    </video>
                                <?php elseif ($videoIsYoutubeMain && youtubeEmbedUrl($mainVideo)): ?>
                                    <iframe src="<?= e(youtubeEmbedUrl($mainVideo)) ?>" title="<?= e($mainTitle) ?>" allowfullscreen loading="lazy" class="adhkaar-youtube-tracked" data-item-id="<?= (int)$selectedItem['id'] ?>" data-category-id="<?= (int)$selectedCategory['id'] ?>"></iframe>
                                <?php else: ?>
                                    <p class="media-empty"><a href="<?= e($mainVideo) ?>" target="_blank" rel="noopener"><?= $lang === 'sw' ? 'Fungua video kwa kiungo cha nje →' : 'Open video via external link →' ?></a></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($prevItem || $nextItem): ?>
                    <div class="item-nav">
                        <?php if ($prevItem): ?>
                            <a href="?category=<?= e($selectedCategory['slug']) ?>&id=<?= (int)$prevItem['id'] ?>#mainDetail" class="item-nav-link item-nav-prev">
                                <span class="item-nav-arrow">←</span>
                                <span class="item-nav-text">
                                    <span class="item-nav-label"><?= $lang === 'sw' ? 'Iliyotangulia' : 'Previous' ?></span>
                                    <span class="item-nav-title"><?= e($lang === 'sw' && !empty($prevItem['title_sw']) ? $prevItem['title_sw'] : $prevItem['title']) ?></span>
                                </span>
                            </a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <?php if ($nextItem): ?>
                            <a href="?category=<?= e($selectedCategory['slug']) ?>&id=<?= (int)$nextItem['id'] ?>#mainDetail" class="item-nav-link item-nav-next">
                                <span class="item-nav-text">
                                    <span class="item-nav-label"><?= $lang === 'sw' ? 'Inayofuata' : 'Next' ?></span>
                                    <span class="item-nav-title"><?= e($lang === 'sw' && !empty($nextItem['title_sw']) ? $nextItem['title_sw'] : $nextItem['title']) ?></span>
                                </span>
                                <span class="item-nav-arrow">→</span>
                            </a>
                        <?php else: ?>
                            <div class="item-nav-done">✓ <?= $lang === 'sw' ? 'Umemaliza orodha hii' : 'You\'ve reached the end of this list' ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
</main>

<script>
(function() {
    const body = document.body;
    const currentCategoryId = <?= (int)($selectedCategory['id'] ?? 0); ?>;

    // ------------------------------------------------------------------
    // Server-side activity tracking (so admins can see who read/listened/
    // watched/completed what - see ajax/track_adhkaar.php). Best-effort:
    // failures are swallowed since this should never block the reading
    // experience itself. A per-tab Set avoids firing the same "listen"/
    // "watch" event repeatedly if a user pauses and replays.
    // ------------------------------------------------------------------
    const trackedThisSession = new Set();
    function trackAdhkaarActivity(itemId, action, categoryId) {
        const dedupeKey = itemId + ':' + action;
        if (action !== 'complete' && trackedThisSession.has(dedupeKey)) return;
        trackedThisSession.add(dedupeKey);
        fetch('ajax/track_adhkaar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ adhkaar_id: itemId, category_id: categoryId || currentCategoryId, action })
        }).catch(() => { /* best-effort - reading still works offline/on failure */ });
    }

    // Dark-mode toggle, sidebar toggle, and profile-menu wiring now live
    // once in footer.php (shared with every page using header.php) instead
    // of being duplicated here.

    // Category selector: navigate when changed
    const categorySelect = document.getElementById('categorySelect');
    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            const val = this.value;
            if (val) {
                // preserve no id when selecting a different category
                window.location.href = '?category=' + encodeURIComponent(val);
            }
        });
    }

    // The currently open item counts as "read" the moment its detail panel
    // is on the page (it's rendered inline, already expanded - there's no
    // separate "open" click to hook).
    document.querySelectorAll('.adhkaar-tracked-item').forEach(el => {
        trackAdhkaarActivity(el.dataset.itemId, 'read', el.dataset.categoryId);
    });

    // Audio/video: track "listen"/"watch" the first time playback actually
    // starts, not just when the panel renders.
    document.querySelectorAll('.adhkaar-audio-tracked').forEach(el => {
        el.addEventListener('play', () => trackAdhkaarActivity(el.dataset.itemId, 'listen', el.dataset.categoryId), { once: true });
    });
    document.querySelectorAll('.adhkaar-video-tracked').forEach(el => {
        el.addEventListener('play', () => trackAdhkaarActivity(el.dataset.itemId, 'watch', el.dataset.categoryId), { once: true });
    });
    // YouTube embeds: a plain iframe (no Player API) can't tell us when
    // playback actually starts, so we count it as "watched" once the
    // embed is shown - the closest reliable signal available without
    // pulling in the full YouTube IFrame API.
    document.querySelectorAll('.adhkaar-youtube-tracked').forEach(el => {
        trackAdhkaarActivity(el.dataset.itemId, 'watch', el.dataset.categoryId);
    });

    // Tap-to-count dhikr counter. An item can now have MULTIPLE verses
    // (see adhkaar_verses), each with its own counter - so each counter's
    // localStorage key is scoped by item id + verse index (its position
    // within that item, not its database id, so the sidebar summary below
    // can reconstruct the same keys without needing to load verse ids for
    // every item). The date in the key makes progress reset each day.
    (function() {
        const counters = Array.from(document.querySelectorAll('.repeat-counter'));
        const countersByItem = {};
        counters.forEach(el => {
            const id = el.dataset.itemId || 'anon';
            (countersByItem[id] = countersByItem[id] || []).push(el);
        });

        counters.forEach(counterEl => {
            const target = parseInt(counterEl.dataset.target, 10) || 1;
            const itemId = counterEl.dataset.itemId || 'anon';
            const verseIndex = counterEl.dataset.verseIndex || '0';
            const storageKey = 'adhkaar_count_' + itemId + '_v' + verseIndex + '_' + new Date().toDateString();
            const countValueEl = counterEl.querySelector('.countValue') || counterEl.querySelector('#countValue');
            const countBtn = counterEl.querySelector('.countBtn') || counterEl.querySelector('#countBtn');
            let count = parseInt(localStorage.getItem(storageKey) || '0', 10);
            let wasDone = count >= target;

            function renderLocal() {
                if (countValueEl) countValueEl.textContent = String(count);
                const nowDone = count >= target;
                counterEl.classList.toggle('done', nowDone);
                if (countBtn) countBtn.setAttribute('aria-label', nowDone ? 'Completed' : 'Count one repeat');
                checkItemCompletion(itemId);
                if (nowDone && !wasDone) {
                    // Only fire "complete" once the WHOLE item (every verse) is done,
                    // not just this one verse - see checkItemCompletion below.
                    const allDone = (countersByItem[itemId] || []).every(el => el.classList.contains('done'));
                    if (allDone) trackAdhkaarActivity(itemId, 'complete', currentCategoryId);
                }
                wasDone = nowDone;
            }
            renderLocal();

            if (countBtn) {
                countBtn.addEventListener('click', () => {
                    count = Math.min(target, count + 1);
                    localStorage.setItem(storageKey, String(count));
                    renderLocal();
                    if (navigator.vibrate && count <= target) {
                        navigator.vibrate(15);
                    }
                });
            }

            // Reset button: clears today's progress for this verse back to 0
            const resetBtn = counterEl.querySelector('.resetBtn');
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    count = 0;
                    localStorage.setItem(storageKey, '0');
                    renderLocal();
                });
            }
        });

        // An item counts as "done" only once EVERY one of its verses is
        // done - mirrors that onto the sidebar list item as a checkmark,
        // and refreshes the "N/M done today" summary.
        function checkItemCompletion(itemId) {
            const group = countersByItem[itemId] || [];
            const allDone = group.length > 0 && group.every(el => el.classList.contains('done'));
            const listItem = document.getElementById('item-' + itemId);
            if (listItem) listItem.classList.toggle('item-done', allDone);
            updateProgressSummary();
        }

        function updateProgressSummary() {
            const summaryEl = document.getElementById('categoryProgressSummary');
            if (!summaryEl) return;
            const allItems = document.querySelectorAll('.adhkar-list-item[data-verse-targets]');
            const dateStr = new Date().toDateString();
            let doneCount = 0;
            let countedItems = 0;
            allItems.forEach(li => {
                let targets = [];
                try { targets = JSON.parse(li.dataset.verseTargets || '[]'); } catch (err) { targets = []; }
                if (!targets.length) return; // item not set up yet - excluded from the total
                countedItems++;
                const id = li.id.replace('item-', '');
                const allVersesDone = targets.every((target, idx) => {
                    const key = 'adhkaar_count_' + id + '_v' + idx + '_' + dateStr;
                    const c = parseInt(localStorage.getItem(key) || '0', 10);
                    return c >= (parseInt(target, 10) || 1);
                });
                if (allVersesDone) doneCount++;
            });
            if (countedItems > 0) {
                summaryEl.textContent = doneCount + ' / ' + countedItems + (document.documentElement.lang === 'sw' ? ' zimekamilika leo' : ' completed today');
                summaryEl.style.display = 'block';
            }
        }
        updateProgressSummary();

        // Live search: filters the sidebar item list by title as you type
        const searchInput = document.getElementById('adhkarSearch');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const q = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('.adhkar-list-item').forEach(li => {
                    const matches = li.textContent.toLowerCase().includes(q);
                    li.style.display = matches ? '' : 'none';
                });
            });
        }
    })();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>