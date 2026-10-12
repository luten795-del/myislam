<?php
/**
 * Shared page footer - pairs with includes/header.php.
 *
 * The calling page is responsible for:
 *   1. Printing its own content inside <main>.
 *   2. Closing </main> itself.
 *   3. Printing any page-specific aside (e.g. content.php's "Up Next"
 *      panel) that belongs inside .page-layout, if it has one.
 *   4. Printing any page-specific <script> blocks it needs (e.g.
 *      content.php's completion-tracking / keyboard-navigation script).
 *   5. THEN requiring this file to close out the page.
 *
 * This file does not know about page-specific asides or scripts - keeping
 * it generic is what lets every page share it.
 */
?>
<style>
.footer-meta {
    text-align: center; font-size: 12px; padding: 20px; margin-top: 20px;
    border-top: 1px solid var(--line); font-weight: 700;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
}
.footer-meta .brand-text {
    color: var(--brand);
    font-weight: 700;
}
.footer-meta .gold-text {
    color: var(--gold);
    font-weight: 700;
}
</style>    





<div class="footer-meta">
    <div class="brand-text">MyIslam</div>
    <div class="gold-text">#Manhaj Salaf</div>
</div>

<!-- GLightbox for image lightbox -->
<script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const lightbox = GLightbox({
            selector: '.section-body img, .combined-content-container img, .article-content img',
            touchNavigation: true,
            loop: false
        });
    });
</script>

<!-- Universal chrome behavior: sidebar toggle, dark/light mode toggle,
     language toggle, profile menu. This has no dependency on any
     page-specific data, so it's identical on every page - previously
     copy-pasted, now defined once. -->
<script>
    const body = document.body;
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const themeToggle = document.getElementById('themeToggle');
    const langToggle = document.getElementById('langToggle');
    const profileToggle = document.getElementById('profileToggle');
    const profileMenu = document.getElementById('profileMenu');
    const profileArea = document.getElementById('profileArea');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        if (body) body.style.overflow = 'hidden';
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        if (body) body.style.overflow = '';
        if (menuToggle) menuToggle.setAttribute('aria-expanded', 'false');
    }

    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            if (sidebar && sidebar.classList.contains('open')) {
                closeSidebar();
            }
            if (profileMenu && profileMenu.classList.contains('show')) {
                profileMenu.classList.remove('show');
                if (profileToggle) profileToggle.setAttribute('aria-expanded', 'false');
            }
        }
    });

    // Check for saved theme on page load - default to dark if not set
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.body.classList.remove('dark-mode');
        document.body.classList.add('light-mode');
        if (themeToggle) themeToggle.textContent = '🌙';
    } else {
        // Default to dark mode
        document.body.classList.add('dark-mode');
        document.body.classList.remove('light-mode');
        if (themeToggle) themeToggle.textContent = '☀';
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            document.body.classList.toggle('light-mode');
            const isDark = document.body.classList.contains('dark-mode');
            themeToggle.textContent = isDark ? '☀' : '🌙';
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            if (typeof showToast === 'function') {
                showToast(isDark ? 'Dark mode enabled 🌙' : 'Light mode enabled ☀️', false);
            }
        });
    }

    // Language Toggle
    let currentLang = localStorage.getItem('language') || '<?php echo $_SESSION['language'] ?? "en"; ?>';
    if (langToggle) {
        langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
        langToggle.addEventListener('click', async () => {
            currentLang = currentLang === 'en' ? 'sw' : 'en';
            langToggle.textContent = currentLang === 'en' ? 'EN' : 'SW';
            localStorage.setItem('language', currentLang);

            try {
                await fetch('ajax/switch_language.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ language: currentLang })
                });
                location.reload();
            } catch (e) {
                console.error('Language switch error:', e);
            }
        });
    }

    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpen = profileMenu.classList.contains('show');
            profileMenu.classList.toggle('show', !isOpen);
            profileToggle.setAttribute('aria-expanded', String(!isOpen));
        });

        document.addEventListener('click', function(e) {
            if (!profileArea.contains(e.target)) {
                profileMenu.classList.remove('show');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
</script>

</body>
</html>