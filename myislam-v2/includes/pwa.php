<?php
// includes/pwa.php — outputs manifest link, service worker registration,
// and loads the offline-lesson storage layer. Included on nearly every
// page site-wide, so this is the one place that makes offline download
// support available everywhere, not just on content.php.
//
// This file assumes session_start() has already run on the including page
// (true for every page that currently includes this file) so $_SESSION is
// readable here.
$isLoggedInForOffline = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#166534">
<script>
// "Stay logged in across offline periods": every page load reports the
// user's CURRENT session state into localStorage - which, unlike a
// session cookie's server-side record, survives being read with zero
// network. This does NOT let anyone log in fresh with no connection
// (that still requires reaching the server to check a password) - it
// only means someone who WAS logged in doesn't get bounced to a login
// wall just because they went offline later. See offline.html, which
// reads this flag to decide whether to show a friendly "here are your
// downloaded lessons" screen or a normal "you need to connect" message.
localStorage.setItem('myislam_logged_in', <?php echo $isLoggedInForOffline ? 'true' : 'false'; ?>);

if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker.register('/service-worker.js')
            .then(reg => {
                console.log('ServiceWorker registered:', reg.scope);
                // A brand-new service worker doesn't "control" the page
                // that triggered its install until the NEXT navigation, so
                // this page wouldn't otherwise get cached until a second
                // visit. Explicitly ask the (soon-to-be) active worker to
                // cache this exact page right now, so every page - starting
                // with the very first one a person opens - is available
                // offline without needing a repeat visit first.
                const tellWorkerToCacheThisPage = (worker) => {
                    if (worker) {
                        worker.postMessage({ type: 'CACHE_URL', url: window.location.href });
                    }
                };
                if (reg.active) {
                    tellWorkerToCacheThisPage(reg.active);
                } else {
                    navigator.serviceWorker.ready.then(readyReg => {
                        tellWorkerToCacheThisPage(readyReg.active);
                    });
                }
            })
            .catch(err => console.warn('ServiceWorker registration failed:', err));

        // The service worker replies once it's actually finished caching a
        // page (see the 'message' handler in service-worker.js). Only show
        // the "available offline" notice the FIRST time a given page gets
        // cached (isNew) - not on every repeat visit to an already-cached
        // page, which would get naggy fast.
        navigator.serviceWorker.addEventListener('message', function(event) {
            if (event.data && event.data.type === 'URL_CACHED' && event.data.isNew) {
                showOfflineReadyToast();
            }
        });
    });
}

// Self-contained toast, independent of whatever toast implementation (or
// lack of one) each individual page has - this file is included
// site-wide, so it can't depend on a page-specific #toast element.
function showOfflineReadyToast() {
    let el = document.getElementById('pwaOfflineToast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'pwaOfflineToast';
        el.style.cssText = [
            'position:fixed', 'bottom:20px', 'left:50%', 'transform:translateX(-50%)',
            'background:#0E4A34', 'color:#fff', 'padding:10px 18px', 'border-radius:8px',
            'font-size:13px', 'z-index:99999', 'box-shadow:0 4px 12px rgba(0,0,0,0.25)',
            'opacity:0', 'transition:opacity 0.3s ease', 'max-width:80vw', 'text-align:center'
        ].join(';');
        document.body.appendChild(el);
    }
    el.textContent = '📥 This page is now saved - you can open it offline.';
    requestAnimationFrame(() => { el.style.opacity = '1'; });
    clearTimeout(el._hideTimer);
    el._hideTimer = setTimeout(() => { el.style.opacity = '0'; }, 3500);
}
</script>

<!-- PWA: IndexedDB wrapper for offline-downloaded lessons (see
     assets/js/offline-db.js). Loaded here - not per-page - so any page
     can check/display "downloaded" state or list a user's downloads. -->
<script src="/assets/js/offline-db.js"></script>