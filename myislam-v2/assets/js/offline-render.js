/**
 * offline-render.js
 *
 * Renders a lesson object (as saved by OfflineDB.saveLesson, shaped exactly
 * like the JSON from api/get_lesson_content.php) into a container element,
 * offline, with no server involved.
 *
 * IMPORTANT: this intentionally reproduces the same HTML structure and CSS
 * classes content.php generates server-side (.combined-content-container,
 * .mixed-rtl / .mixed-ltr, .section-display / .section-body,
 * .has-custom-text-color / .no-custom-text-color, .dark-mode-safe-text) so
 * that theme.css's existing rules - including the dark-mode color-fallback
 * logic - apply identically whether the lesson came from the server or
 * from IndexedDB. If you change how content.php builds this markup, mirror
 * the change here too, or the two will drift apart.
 */

const OfflineRenderer = (function () {
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    /** Builds the class list for a section, matching content.php's PHP logic. */
    function sectionColorClasses(section) {
        const classes = [];
        classes.push(section.has_custom_text_color ? 'has-custom-text-color' : 'no-custom-text-color');
        if (section.needs_dark_mode_fallback) {
            classes.push('dark-mode-safe-text');
        }
        return classes;
    }

    function sectionInlineStyle(section) {
        let style = '';
        if (section.text_color) style += `color: ${section.text_color};`;
        if (section.background_color) style += `background-color: ${section.background_color};`;
        return style;
    }

    /** Combined-paragraph mode: each section becomes a mixed-rtl/mixed-ltr span. */
    function renderCombinedPart(part) {
        const wrapper = document.createElement('div');
        wrapper.className = 'combined-content-container';

        part.sections.forEach((section) => {
            const isRtl = (section.direction || 'ltr') === 'rtl';
            const span = document.createElement('span');
            span.className = [isRtl ? 'mixed-rtl' : 'mixed-ltr', ...sectionColorClasses(section)].join(' ');
            span.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            span.setAttribute('lang', isRtl ? 'ar' : 'sw');
            const style = sectionInlineStyle(section);
            if (style) span.setAttribute('style', style);
            span.innerHTML = section.content_html || escapeHtml(section.content || '');
            wrapper.appendChild(span);
            wrapper.appendChild(document.createTextNode(' '));
        });

        return wrapper;
    }

    /** Separate-sections mode: each section becomes its own section-display block. */
    function renderSeparatePart(part) {
        const fragment = document.createDocumentFragment();

        part.sections.forEach((section) => {
            const isRtl = (section.direction || 'ltr') === 'rtl';
            const div = document.createElement('div');
            div.className = ['section-display', isRtl ? 'rtl' : 'ltr', ...sectionColorClasses(section)].join(' ');
            div.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            const style = sectionInlineStyle(section);
            if (style) div.setAttribute('style', style);

            const badge = document.createElement('div');
            badge.className = `section-badge-label ${isRtl ? 'rtl' : 'ltr'}`;
            badge.textContent = isRtl ? 'Arabic' : 'Swahili';
            div.appendChild(badge);

            const body = document.createElement('div');
            body.className = 'section-body';
            body.innerHTML = section.content_html || escapeHtml(section.content || '');
            div.appendChild(body);

            fragment.appendChild(div);
        });

        return fragment;
    }

    /**
     * @param {object} part - one entry from lesson.parts
     * @returns {HTMLElement|DocumentFragment}
     */
    function renderPart(part) {
        if (!part.sections || part.sections.length === 0) {
            const fallback = document.createElement('div');
            fallback.className = 'section-body no-custom-text-color';
            fallback.innerHTML = part.arabic_body_html || part.swahili_body_html
                || escapeHtml(part.arabic_body || part.swahili_body || '');
            return fallback;
        }
        return part.display_mode === 'combined' ? renderCombinedPart(part) : renderSeparatePart(part);
    }

    /**
     * Renders a full downloaded lesson into a container.
     * @param {object} lesson - shaped like api/get_lesson_content.php's response
     * @param {HTMLElement} container
     * @param {number} [partNumber] - 1-based; defaults to the first part
     */
    function render(lesson, container, partNumber) {
        container.innerHTML = '';

        const parts = lesson.parts || [];
        const activePart = parts.find((p) => p.part_number === (partNumber || 1)) || parts[0];

        const titleEl = document.createElement('h1');
        titleEl.className = 'lesson-title';
        titleEl.textContent = (activePart && activePart.title) || lesson.title;
        container.appendChild(titleEl);

        const offlineBadge = document.createElement('div');
        offlineBadge.className = 'offline-badge';
        offlineBadge.textContent = `📥 Viewing offline copy - downloaded ${new Date(lesson.downloaded_at).toLocaleDateString()}`;
        container.appendChild(offlineBadge);

        if (!activePart) {
            const empty = document.createElement('p');
            empty.textContent = 'This lesson has no downloadable content.';
            container.appendChild(empty);
            return;
        }

        container.appendChild(renderPart(activePart));

        if (parts.length > 1) {
            const nav = document.createElement('div');
            nav.className = 'offline-part-nav';
            parts.forEach((p) => {
                const btn = document.createElement('button');
                btn.textContent = `Part ${p.part_number}`;
                btn.className = p === activePart ? 'active' : '';
                btn.addEventListener('click', () => render(lesson, container, p.part_number));
                nav.appendChild(btn);
            });
            container.appendChild(nav);
        }
    }

    return { render };
})();
