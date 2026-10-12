/**
 * offline-db.js
 *
 * Thin IndexedDB wrapper for storing downloaded lessons so they can be
 * read with no network connection. Used by:
 *   - content.php's "Download for offline" button (saves a lesson)
 *   - offline-lesson.html's renderer (reads a saved lesson)
 *   - any future "My Downloads" list page (lists / deletes saved lessons)
 *
 * Why IndexedDB and not localStorage: lesson content (Arabic text,
 * multiple parts/sections, HTML) can be large, and localStorage has a
 * ~5MB total limit shared across everything on the origin. IndexedDB's
 * limit is a much larger, browser-managed quota, and it stores structured
 * objects directly instead of forcing everything through JSON.stringify
 * into a single string per key.
 */

const OfflineDB = (function () {
    const DB_NAME = 'myislam-offline';
    const DB_VERSION = 1;
    const STORE_LESSONS = 'lessons';

    /** @returns {Promise<IDBDatabase>} */
    function openDB() {
        return new Promise((resolve, reject) => {
            if (!('indexedDB' in window)) {
                reject(new Error('IndexedDB is not supported in this browser.'));
                return;
            }
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(STORE_LESSONS)) {
                    const store = db.createObjectStore(STORE_LESSONS, { keyPath: 'id' });
                    store.createIndex('slug', 'slug', { unique: false });
                    store.createIndex('downloaded_at', 'downloaded_at', { unique: false });
                }
            };

            request.onsuccess = (event) => resolve(event.target.result);
            request.onerror = (event) => reject(event.target.error);
        });
    }

    /**
     * Save (or overwrite) a lesson for offline reading.
     * @param {object} lessonData - the JSON returned by
     *   /api/get_lesson_content.php (must include a numeric `id`).
     * @returns {Promise<void>}
     */
    async function saveLesson(lessonData) {
        if (!lessonData || typeof lessonData.id === 'undefined') {
            throw new Error('saveLesson: lessonData must include an id.');
        }
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LESSONS, 'readwrite');
            const store = tx.objectStore(STORE_LESSONS);
            const record = Object.assign({}, lessonData, {
                downloaded_at: new Date().toISOString(),
            });
            store.put(record);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    /**
     * @param {number|string} id - lesson id (matches contents.id)
     * @returns {Promise<object|null>}
     */
    async function getLesson(id) {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LESSONS, 'readonly');
            const store = tx.objectStore(STORE_LESSONS);
            const request = store.get(Number(id));
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * @param {string} slug
     * @returns {Promise<object|null>}
     */
    async function getLessonBySlug(slug) {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LESSONS, 'readonly');
            const store = tx.objectStore(STORE_LESSONS);
            const index = store.index('slug');
            const request = index.get(slug);
            request.onsuccess = () => resolve(request.result || null);
            request.onerror = () => reject(request.error);
        });
    }

    /** @returns {Promise<object[]>} every downloaded lesson, most recent first */
    async function listLessons() {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LESSONS, 'readonly');
            const store = tx.objectStore(STORE_LESSONS);
            const request = store.getAll();
            request.onsuccess = () => {
                const rows = request.result || [];
                rows.sort((a, b) => (b.downloaded_at || '').localeCompare(a.downloaded_at || ''));
                resolve(rows);
            };
            request.onerror = () => reject(request.error);
        });
    }

    /** @param {number|string} id */
    async function deleteLesson(id) {
        const db = await openDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LESSONS, 'readwrite');
            const store = tx.objectStore(STORE_LESSONS);
            store.delete(Number(id));
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    /** @param {number|string} id @returns {Promise<boolean>} */
    async function isDownloaded(id) {
        const lesson = await getLesson(id);
        return lesson !== null;
    }

    return {
        saveLesson,
        getLesson,
        getLessonBySlug,
        listLessons,
        deleteLesson,
        isDownloaded,
    };
})();
