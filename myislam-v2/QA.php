<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['language'])) {
    $_SESSION['language'] = $_COOKIE['language'] ?? 'en';
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/translations.php';

function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

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
    error_log('Q&A DB error: ' . $e->getMessage());
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
        SELECT c.id, c.name, c.name_sw, c.slug, c.icon,
               (SELECT COUNT(*) FROM qa_items q WHERE q.category_id = c.id AND q.is_active = 1) AS item_count
        FROM qa_categories c
        WHERE c.is_active = 1
        ORDER BY c.sort_order ASC, c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Q&A categories fetch error: ' . $ex->getMessage());
    $categories = [];
}

// A category is optional here - with none selected, every active question
// across all categories is shown (this is a browsable Q&A bank, not a
// single daily reading list like adhkaar.php).
$selectedSlug = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$selectedCategory = null;
foreach ($categories as $c) {
    if ($c['slug'] === $selectedSlug) { $selectedCategory = $c; break; }
}

$items = [];
try {
    if ($selectedCategory) {
        $stmt = $conn->prepare("
            SELECT id, question, question_sw, answer, answer_sw, reference_type,
                   book_title, book_author, book_page_reference,
                   scholar_name, scholar_title, source_reference,
                   audio_path, audio_url, video_path, video_url
            FROM qa_items
            WHERE category_id = ? AND is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
        $stmt->execute([(int)$selectedCategory['id']]);
    } else {
        $stmt = $conn->query("
            SELECT id, question, question_sw, answer, answer_sw, reference_type,
                   book_title, book_author, book_page_reference,
                   scholar_name, scholar_title, source_reference,
                   audio_path, audio_url, video_path, video_url
            FROM qa_items
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");
    }
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
    error_log('Q&A items fetch error: ' . $ex->getMessage());
}

$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$selectedItem = null;
foreach ($items as $it) {
    if ((int)$it['id'] === $selectedId) { $selectedItem = $it; break; }
}
if (!$selectedItem && !empty($items)) {
    $selectedItem = $items[0];
}

function resolveMediaUrl(?string $path, ?string $url): ?string {
    if (!empty($path)) return $path;
    if (!empty($url)) return $url;
    return null;
}

function isYoutubeUrl(string $url): bool {
    return (bool)preg_match('/(youtube\.com|youtu\.be|youtube-nocookie\.com)/i', $url);
}

/**
 * A YouTube *search results* link (youtube.com/results?search_query=...) has
 * no video ID in it - it's what you get from searching but never opening a
 * video. It can't be embedded or played at all, so it's worth telling the
 * visitor plainly rather than sending them to a search page via "open
 * externally".
 */
function isYoutubeSearchResultsUrl(string $url): bool {
    $url = strtolower($url);
    return str_contains($url, 'youtube.com/results') || str_contains($url, 'search_query=');
}

/**
 * Extracts the raw 11-character video ID from any common YouTube URL shape:
 * youtu.be/ID, youtube.com/watch?v=ID (v not necessarily the first query
 * param, e.g. ?list=...&v=ID), youtube.com/embed/ID, /shorts/ID, /live/ID,
 * m.youtube.com links, and youtube-nocookie.com. Returns null if the string
 * isn't a recognizable YouTube link, so callers can fall back to a plain
 * "open externally" link instead of embedding.
 */
function youtubeVideoId(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    // If the stored value has stray text/markup around the link (pasted as
    // "Watch here: https://..." or copied out of an <a href="..."> tag),
    // pull out just the URL instead of treating the whole string as one.
    // This must run BEFORE any tag-stripping, since strip_tags() would
    // discard the href attribute along with the tag.
    if (preg_match('#https?://[^\s"\'<>]+#i', $raw, $m)) {
        $url = rtrim($m[0], '.,;)]');
    } else {
        $url = trim(strip_tags($raw));
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return null;
    }

    $host = strtolower($parts['host']);
    $path = $parts['path'] ?? '';

    if (str_contains($host, 'youtu.be')) {
        $id = trim($path, '/');
        return $id !== '' ? $id : null;
    }

    if (str_contains($host, 'youtube.com') || str_contains($host, 'youtube-nocookie.com')) {
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (!empty($query['v']) && is_string($query['v'])) {
                return $query['v'];
            }
        }
        if (preg_match('#/(?:embed|v|shorts|live)/([A-Za-z0-9_-]{6,})#', $path, $m)) {
            return $m[1];
        }
    }

    return null;
}

function youtubeEmbedUrl(string $url): ?string {
    $id = youtubeVideoId($url);
    return $id ? 'https://www.youtube.com/embed/' . $id : null;
}

// Detail fields for the selected question, computed once - the full
// reading view (question, answer, reference, media) renders in the wide
// main pane, not squeezed into the sidebar list (same lesson as adhkaar.php).
$mainQuestion = $mainAnswer = $mainAudio = $mainVideo = null;
$videoIsLocalMain = $videoIsYoutubeMain = false;
if ($selectedItem) {
    $mainQuestion = ($lang === 'sw' && !empty($selectedItem['question_sw'])) ? $selectedItem['question_sw'] : $selectedItem['question'];
    $mainAnswer   = ($lang === 'sw' && !empty($selectedItem['answer_sw'])) ? $selectedItem['answer_sw'] : $selectedItem['answer'];
    $mainAudio = resolveMediaUrl($selectedItem['audio_path'], $selectedItem['audio_url']);
    $mainVideo = resolveMediaUrl($selectedItem['video_path'], $selectedItem['video_url']);
    $videoIsLocalMain = !empty($selectedItem['video_path']);
    $videoIsYoutubeMain = $mainVideo && !$videoIsLocalMain && isYoutubeUrl($mainVideo);
}

// ----------------------------------------------------------------------
// Shared header (same convention as adhkaar.php)
// ----------------------------------------------------------------------
$pageTitle       = 'Q&A - MyIslam';
$metaDescription = $lang === 'sw' ? 'Maswali na majibu ya Kiislamu yenye rejea za vitabu na wanavyuoni.' : 'Islamic questions and answers, referenced to books and scholars.';
$metaKeywords    = 'maswali, majibu, qa, fatwa, islam';
$metaAuthor      = 'MyIslam';
$ogType          = 'website';
$htmlLang        = $lang;
$htmlDir         = 'ltr';
$bodyClass       = 'theme-general qa-page';
$sidebarNavigation = [
    ['name' => t('dashboard'), 'link' => 'user_dashboard.php', 'active' => false, 'icon' => '📊'],
    ['name' => t('subjects'), 'link' => 'subjects.php', 'active' => false, 'icon' => '📚'],
    ['name' => t('quizzes'), 'link' => 'quizzes.php', 'active' => false, 'icon' => '📝'],
    ['name' => t('monthly_exams'), 'link' => 'monthly_exams.php', 'active' => false, 'icon' => '📅'],
    ['name' => t('certification'), 'link' => 'certification_exams.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('my_certificates'), 'link' => 'my_certificates.php', 'active' => false, 'icon' => '🎓'],
    ['name' => t('leaderboard'), 'link' => 'leaderboard.php', 'active' => false, 'icon' => '🏆'],
    ['name' => 'Q&A', 'link' => 'qa.php', 'active' => true, 'icon' => '❓'],
    ['name' => t('multimedia'), 'link' => 'multimedia.php', 'active' => false, 'icon' => '🖼️'],
    ['name' => t('notifications'), 'link' => 'notification.php', 'active' => false, 'icon' => '🔔'],
    ['name' => t('help_center'), 'link' => 'help_center.php', 'active' => false, 'icon' => '❓'],
];

require __DIR__ . '/includes/header.php';
?>

<!-- Page-specific styles only - reset/vars/header/sidebar live in theme.css via header.php -->
<style>
.qa-page-title { margin-bottom: 20px; }
.qa-page-title h1 { font-size: clamp(22px, 4vw, 30px); font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px; }
.qa-page-title p { color: var(--muted); font-size: 14px; }

.qa-layout { display: grid; grid-template-columns: 300px 1fr; gap: 24px; align-items: start; }
@media (max-width: 900px) { .qa-layout { grid-template-columns: 1fr; } }

.qa-list-card { box-sizing: border-box; width: 100%; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: 16px; position: sticky; top: calc(var(--header-height, 0px) + 16px); max-height: calc(100vh - var(--header-height, 0px) - 32px); overflow-y: auto; }
@media (max-width: 900px) { .qa-list-card { position: static; max-height: none; } }

.qa-list-card select { width: 100%; margin-bottom: 10px; }
.qa-list-card input[type="search"] { width: 100%; margin-bottom: 12px; box-sizing: border-box; padding: 10px 12px; border-radius: var(--radius-md); border: 1px solid var(--line); background: var(--surface-2, var(--surface)); color: var(--text); font-size: 14px; }

.qa-list-item { display: block; padding: 12px 14px; margin-bottom: 6px; border-radius: var(--radius-md); border: 1px solid transparent; font-size: 14px; font-weight: 600; color: var(--text); transition: all 0.2s ease; }
.qa-list-item:hover { background: var(--brand-soft, rgba(15,123,74,0.08)); }
.qa-list-item.active { background: var(--brand-soft, rgba(15,123,74,0.12)); border-color: var(--brand); color: var(--brand); }
.qa-list-item .qa-ref-tag { display: block; margin-top: 4px; font-size: 11px; font-weight: 500; color: var(--muted); }

.qa-detail-card { box-sizing: border-box; width: 100%; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius-lg); padding: clamp(16px, 3vw, 28px); }
.qa-detail-card > .qa-detail-inner { max-width: 760px; margin: 0 auto; }
html { scroll-behavior: smooth; }
#qaMainDetail { scroll-margin-top: calc(var(--header-height, 0px) + 12px); }

.qa-empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
.qa-empty-state .icon { font-size: 48px; margin-bottom: 12px; opacity: .6; }

.qa-question { font-size: clamp(19px, 3vw, 24px); font-weight: 800; line-height: 1.4; margin-bottom: 18px; }

.qa-answer-block { margin-bottom: 22px; padding: 16px 18px; background: var(--gold-soft, rgba(156,122,46,0.10)); border: 1px solid var(--gold, #9C7A2E); border-radius: var(--radius-md); }
.qa-answer-block h4 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gold, #9C7A2E); margin-bottom: 8px; }
.qa-answer-block p { font-size: 16px; line-height: 1.8; white-space: pre-line; }

.qa-reference-block { margin: 22px 0; padding: 16px 18px; background: var(--surface-2, rgba(0,0,0,0.02)); border: 1px solid var(--line); border-radius: var(--radius-md); }
.qa-reference-block h4 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 8px; }
.qa-reference-block .qa-ref-main { font-size: 15px; font-weight: 700; margin-bottom: 2px; }
.qa-reference-block .qa-ref-sub { font-size: 13px; color: var(--muted); }
.qa-reference-block .qa-ref-citation { margin-top: 8px; font-size: 13px; color: var(--muted); font-style: italic; }

.media-grid { box-sizing: border-box; width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 22px; }
@media (max-width: 640px) { .media-grid { grid-template-columns: 1fr; } }
.media-grid.media-grid-single { grid-template-columns: 1fr; max-width: 420px; }
.media-panel { padding: 14px; background: var(--surface-2, rgba(0,0,0,0.02)); border: 1px solid var(--line); border-radius: var(--radius-md); }
.media-panel h4 { font-size: 13px; margin-bottom: 10px; color: var(--muted); }
.media-panel audio, .media-panel video { width: 100%; border-radius: var(--radius-sm); }
.media-panel iframe { width: 100%; aspect-ratio: 16/9; border: none; border-radius: var(--radius-sm); }
.media-empty a { color: var(--brand); font-weight: 600; font-size: 14px; }
.media-notice-gold { background: var(--gold-soft, rgba(156,122,46,0.10)); color: var(--gold, #9C7A2E); border: 1px solid var(--gold, #9C7A2E); border-radius: var(--radius-md); padding: 12px 14px; font-size: 14px; font-weight: 600; }
</style>

<div class="qa-page-title">
    <h1>❓ <?= $lang === 'sw' ? 'Maswali na Majibu' : 'Questions &amp; Answers' ?></h1>
    <p><?= $lang === 'sw' ? 'Maswali ya Kiislamu yenye rejea za vitabu na wanavyuoni' : 'Islamic questions, referenced to books and scholars' ?></p>
</div>

<div class="qa-layout">
    <aside class="qa-list-card">
        <select id="qaCategorySelect">
            <option value=""><?= $lang === 'sw' ? 'Makundi Yote' : 'All Categories' ?></option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= e($c['slug']) ?>" <?= ($selectedCategory && $selectedCategory['slug'] === $c['slug']) ? 'selected' : '' ?>>
                    <?= e($c['icon']) ?> <?= e($lang === 'sw' && !empty($c['name_sw']) ? $c['name_sw'] : $c['name']) ?> (<?= (int)$c['item_count'] ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <input type="search" id="qaSearch" placeholder="<?= $lang === 'sw' ? 'Tafuta swali...' : 'Search questions...' ?>">

        <?php foreach ($items as $it): ?>
            <?php
                $isActive = ($selectedItem && (int)$selectedItem['id'] === (int)$it['id']);
                $listQuestion = ($lang === 'sw' && !empty($it['question_sw'])) ? $it['question_sw'] : $it['question'];
                $refTag = $it['reference_type'] === 'book'
                    ? '📖 ' . ($it['book_title'] ?: ($lang === 'sw' ? 'Kitabu' : 'Book'))
                    : '🎓 ' . ($it['scholar_name'] ?: ($lang === 'sw' ? 'Mwanachuoni' : 'Ulamaa'));
            ?>
            <a href="?<?= $selectedCategory ? 'category=' . e($selectedCategory['slug']) . '&' : '' ?>id=<?= (int)$it['id'] ?>#qaMainDetail"
               class="qa-list-item <?= $isActive ? 'active' : '' ?>">
                <?= e(mb_strimwidth($listQuestion, 0, 90, '…')) ?>
                <span class="qa-ref-tag"><?= e($refTag) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (empty($items)): ?>
            <p style="color:var(--muted);text-align:center;padding:20px 8px;font-size:14px;">
                <?= $lang === 'sw' ? 'Hakuna maswali bado.' : 'No questions yet.' ?>
            </p>
        <?php endif; ?>
    </aside>

    <section class="qa-detail-card" id="qaMainDetail">
        <?php if (!$selectedItem): ?>
            <div class="qa-empty-state">
                <div class="icon">❓</div>
                <p><?= $lang === 'sw' ? 'Chagua swali kutoka kwenye orodha kuanza.' : 'Choose a question from the list to get started.' ?></p>
            </div>
        <?php else: ?>
            <div class="qa-detail-inner">
                <div class="qa-question"><?= nl2br(e($mainQuestion)) ?></div>

                <div class="qa-answer-block">
                    <h4><?= $lang === 'sw' ? 'Jibu' : 'Answer' ?></h4>
                    <p><?= nl2br(e($mainAnswer)) ?></p>
                </div>

                <div class="qa-reference-block">
                    <?php if ($selectedItem['reference_type'] === 'book'): ?>
                        <h4>📖 <?= $lang === 'sw' ? 'Rejea: Kitabu' : 'Reference: Book' ?></h4>
                        <div class="qa-ref-main"><?= e($selectedItem['book_title'] ?: '—') ?></div>
                        <?php if (!empty($selectedItem['book_author'])): ?>
                            <div class="qa-ref-sub"><?= $lang === 'sw' ? 'Mwandishi' : 'Author' ?>: <?= e($selectedItem['book_author']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($selectedItem['book_page_reference'])): ?>
                            <div class="qa-ref-sub"><?= e($selectedItem['book_page_reference']) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <h4>🎓 <?= $lang === 'sw' ? 'Rejea: Mwanachuoni' : 'Reference: Ulamaa' ?></h4>
                        <div class="qa-ref-main"><?= e($selectedItem['scholar_name'] ?: '—') ?></div>
                        <?php if (!empty($selectedItem['scholar_title'])): ?>
                            <div class="qa-ref-sub"><?= e($selectedItem['scholar_title']) ?></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($selectedItem['source_reference'])): ?>
                        <div class="qa-ref-citation"><?= e($selectedItem['source_reference']) ?></div>
                    <?php endif; ?>
                </div>

                <?php
                    // Only build/show a media panel for audio or video when that
                    // field actually has a value - same "show only if it exists"
                    // rule used on the Adhkaar page.
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
                                <audio controls preload="none" src="<?= e($mainAudio) ?>">
                                    <?= $lang === 'sw' ? 'Kivinjari chako hakiunga mkono kicheza sauti.' : 'Your browser does not support the audio player.' ?>
                                </audio>
                            </div>
                        <?php endif; ?>
                        <?php if ($hasMainVideo): ?>
                            <div class="media-panel">
                                <h4>🎬 <?= $lang === 'sw' ? 'Video' : 'Video' ?></h4>
                                <?php if ($videoIsLocalMain): ?>
                                    <video controls preload="none" src="<?= e($mainVideo) ?>">
                                        <?= $lang === 'sw' ? 'Kivinjari chako hakiunga mkono kicheza video.' : 'Your browser does not support the video player.' ?>
                                    </video>
                                <?php elseif ($videoIsYoutubeMain && youtubeEmbedUrl($mainVideo)): ?>
                                    <iframe
                                        src="<?= e(youtubeEmbedUrl($mainVideo)) ?>"
                                        title="<?= e($mainQuestion) ?>"
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                        allowfullscreen
                                        loading="lazy"
                                        referrerpolicy="strict-origin-when-cross-origin"
                                    ></iframe>
                                <?php elseif (isYoutubeSearchResultsUrl($mainVideo)): ?>
                                    <div class="media-notice-gold">
                                        🎬 <?= $lang === 'sw' ? 'Video ya swali hili haipatikani kwa sasa. Tafadhali angalia tena baadaye.' : 'This video isn\'t available right now. Please check back later.' ?>
                                    </div>
                                <?php else: ?>
                                    <p class="media-empty"><a href="<?= e($mainVideo) ?>" target="_blank" rel="noopener"><?= $lang === 'sw' ? 'Fungua video kwa kiungo cha nje →' : 'Open video via external link →' ?></a></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function() {
    const categorySelect = document.getElementById('qaCategorySelect');
    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            window.location.href = this.value ? ('?category=' + encodeURIComponent(this.value)) : '?';
        });
    }

    const searchInput = document.getElementById('qaSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim().toLowerCase();
            document.querySelectorAll('.qa-list-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>