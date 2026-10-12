/* Service worker for offline read-only caching
   - Precache a small app shell (now includes the shared theme.css, the
     offline-lesson storage/render scripts, icons, and the offline viewer)
   - Runtime cache visited content pages and media (uploads)
   - Fallback to offline.html for navigation when offline - EXCEPT a
     content.php lesson URL that was explicitly downloaded via the
     "Download for offline" button, which falls back to offline-lesson.html
     instead so it can render the saved copy from IndexedDB (see
     assets/js/offline-db.js / offline-render.js).
*/

const PRECACHE = 'myislam-precache-v4';
const RUNTIME = 'myislam-runtime-v1';

// Files to precache — ONLY truly static, non-personalized assets. Deliberately
// does NOT include '/' or '/subjects.php': those pages render differently
// depending on whether someone is logged in, so precaching them at install
// time risks permanently freezing in whatever state existed at that moment
// (e.g. logged-out) - and caches.match() can't tell "stale precached
// anonymous version" apart from "fresh runtime-cached logged-in version" if
// both exist. Personalized pages should ONLY ever come from the runtime
// cache, populated by someone's actual authenticated visits (see the fetch
// handler below) - never from this fixed install-time list.
const PRECACHE_URLS = [
  '/offline.html',
  '/offline-lesson.html',
  '/manifest.json',
  '/assets/css/theme.css',
  '/assets/js/offline-db.js',
  '/assets/js/offline-render.js',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png'
];

self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(PRECACHE).then(cache =>
      // NOT cache.addAll() - that's atomic and rejects the WHOLE install if
      // even one URL 404s (e.g. a path typo, or a file not deployed yet),
      // which discards this entire service worker update and silently
      // leaves offline support disabled. Caching each URL independently
      // means one bad path only skips that one file.
      Promise.all(
        PRECACHE_URLS.map(url =>
          cache.add(url).catch(err => {
            console.warn('Precache failed for', url, err);
          })
        )
      )
    )
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.map(key => {
        if (![PRECACHE, RUNTIME].includes(key)) return caches.delete(key);
        return Promise.resolve();
      })
    ))
  );
  self.clients.claim();
});

// A brand-new service worker doesn't "control" the page that triggered its
// install until the NEXT navigation - so the reactive caching in the fetch
// handler below can't catch that very first page on that very first visit.
// To close that gap, every page (via includes/pwa.php) posts its own URL
// here right after registering, so it gets cached immediately regardless
// of control status - "pages download themselves as soon as visited"
// starts from the very first page, not the second.
//
// Replies back to the page with { type: 'URL_CACHED', url, isNew } so
// pwa.php can show a one-time "available offline" notice - only when this
// page WASN'T already cached before, not on every repeat visit.
self.addEventListener('message', event => {
  if (!event.data || event.data.type !== 'CACHE_URL' || !event.data.url) {
    return;
  }
  const targetUrl = event.data.url;
  const replyTo = event.source;

  event.waitUntil(
    caches.open(RUNTIME).then(cache =>
      cache.match(targetUrl).then(existing => {
        const wasAlreadyCached = !!existing;
        return fetch(targetUrl)
          .then(response => {
            if (!response.ok) return;
            return cache.put(targetUrl, response).then(() => {
              if (replyTo) {
                replyTo.postMessage({ type: 'URL_CACHED', url: targetUrl, isNew: !wasAlreadyCached });
              }
            });
          })
          .catch(() => { /* offline or blocked - nothing to cache, ignore */ });
      })
    )
  );
});

function isNavigationRequest(request) {
  return request.mode === 'navigate' || (request.method === 'GET' && request.headers.get('accept')?.includes('text/html'));
}

// A lesson page that has (or might have) a downloaded offline copy -
// content.php with a slug or id in the query string.
function isLessonUrl(url) {
  return url.pathname.endsWith('/content.php') && (url.searchParams.has('slug') || url.searchParams.has('id'));
}

self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);

  // Cross-origin requests (YouTube embeds, external CDNs, etc.) are never
  // intercepted, full stop - not just skipped in one branch. This used to
  // only be checked before the static-asset branch, so a cross-origin
  // iframe navigation (a YouTube embed is a nested "navigate" request) or
  // any other cross-origin GET could still reach the catch-all branch
  // below, fail, and have nothing cached to fall back to - which returns
  // undefined instead of a Response and Chrome rejects that with
  // "Failed to convert value to 'Response'". Bailing out here before any
  // branch runs means the browser just handles those requests normally.
  if (url.origin !== self.location.origin) {
    return;
  }

  // 1) Navigation requests: network-first, fallback to cache, then to the
  //    lesson-aware offline shell (for downloaded lessons) or the generic
  //    offline page.
  if (isNavigationRequest(request)) {
    event.respondWith(
      fetch(request).then(resp => {
        // Update runtime cache for navigation responses
        const copy = resp.clone();
        caches.open(RUNTIME).then(cache => cache.put(request, copy));
        return resp;
      }).catch(() => caches.match(request).then(cached => {
        if (cached) return cached;
        if (isLessonUrl(url)) {
          // Its own JS reads window.location.search - which still shows
          // the original content.php?slug=...&id=... URL in the address
          // bar - looks that lesson up in IndexedDB, and renders it if it
          // was downloaded via the "Download for offline" button.
          return caches.match('/offline-lesson.html');
        }
        return caches.match('/offline.html');
      }))
    );
    return;
  }

  // 2) Static assets (images/videos/uploads) AND the app's own CSS/JS
  //    bundle: cache-first. (Already guaranteed same-origin by the bailout
  //    above.)
  if (
    request.destination === 'image' ||
    request.destination === 'video' ||
    url.pathname.startsWith('/uploads/') ||
    url.pathname.startsWith('/assets/')
  ) {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then(resp => {
        const copy = resp.clone();
        caches.open(RUNTIME).then(cache => cache.put(request, copy));
        return resp;
      }).catch(() => cached))
    );
    return;
  }

  // 3) Other requests (API calls, etc.): try network, fallback to cache.
  //    Not intercepting POST - quiz submits and progress updates must
  //    always go live, never served from cache.
  if (request.method !== 'GET') {
    return;
  }
  event.respondWith(
    fetch(request).then(response => {
      // Optionally cache API responses here if desired
      return response;
    }).catch(() => caches.match(request))
  );
});