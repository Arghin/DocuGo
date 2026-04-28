/**
 * mobile.js — DocuGo Mobile Navigation & Responsive Helpers
 *
 * Add before </body> on every page:
 * <script src="../assets/js/mobile.js"></script>
 *
 * This script:
 *  1. Injects the mobile top bar dynamically (reads page title + brand)
 *  2. Handles sidebar drawer open/close + overlay
 *  3. Closes drawer on nav link click (for SPA-like feel)
 *  4. Handles resize transitions cleanly
 */

(function () {
    'use strict';

    /* ── Config ── */
    var BREAKPOINT = 900;

    /* ── Detect page context ── */
    var isAdmin  = document.querySelector('.p-side') !== null;
    var sidebar  = document.querySelector('.sidebar') || document.querySelector('.p-side');
    var mainEl   = document.querySelector('.main')   || document.querySelector('.p-main');

    if (!sidebar) return; /* nothing to do on pages without sidebar */

    /* ── 1. Create mobile topbar ── */
    function buildTopbar() {
        if (document.querySelector('.mobile-topbar')) return;

        /* Read page title from desktop topbar or <title> */
        var desktopTitle =
            document.querySelector('.topbar h1')   ||
            document.querySelector('.topbar h2')   ||
            document.querySelector('.p-topbar .ttl');

        var pageTitle = desktopTitle
            ? desktopTitle.textContent.trim()
            : document.title.replace(' — DocuGo', '').trim();

        /* Read brand sub-text */
        var brandSmall = document.querySelector('.sidebar-brand small') ||
                         document.querySelector('.p-side-logo .s');
        var brandSub   = brandSmall ? brandSmall.textContent.trim() : '';

        var bar = document.createElement('div');
        bar.className = 'mobile-topbar';
        bar.innerHTML =
            '<div>' +
              '<div class="mobile-topbar-brand">DocuGo</div>' +
              (brandSub ? '<div class="mobile-topbar-title">' + escHTML(brandSub) + '</div>' : '') +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:0.5rem;">' +
              '<span style="font-size:0.82rem;color:rgba(255,255,255,0.85);font-weight:500;">' +
                escHTML(pageTitle) +
              '</span>' +
              '<button class="hamburger" id="drawerToggle" aria-label="Open menu" aria-expanded="false">' +
                '<span></span><span></span><span></span>' +
              '</button>' +
            '</div>';

        document.body.insertBefore(bar, document.body.firstChild);
    }

    /* ── 2. Create overlay ── */
    function buildOverlay() {
        if (document.querySelector('.sidebar-overlay')) return;
        var ov = document.createElement('div');
        ov.className = 'sidebar-overlay';
        ov.id = 'sidebarOverlay';
        document.body.appendChild(ov);
        ov.addEventListener('click', closeDrawer);
    }

    /* ── 3. Inject drawer close button inside sidebar ── */
    function buildCloseBtn() {
        if (sidebar.querySelector('.drawer-close')) return;
        var wrap = document.createElement('div');
        wrap.className = 'drawer-close';
        wrap.style.cssText = 'display:none;padding:0.6rem 1rem 0;justify-content:flex-end;';
        wrap.innerHTML =
            '<button aria-label="Close menu" onclick="window.__docugoCloseDrawer()">✕</button>';
        sidebar.insertBefore(wrap, sidebar.firstChild);
    }

    /* ── 4. Drawer open / close ── */
    function openDrawer() {
        sidebar.classList.add('drawer-open');
        var overlay  = document.getElementById('sidebarOverlay');
        var toggle   = document.getElementById('drawerToggle');
        var closeBtn = sidebar.querySelector('.drawer-close');
        if (overlay)  overlay.classList.add('visible');
        if (toggle)   { toggle.classList.add('is-open'); toggle.setAttribute('aria-expanded','true'); }
        if (closeBtn) closeBtn.style.display = 'flex';
        document.body.style.overflow = 'hidden'; /* prevent background scroll */
    }

    function closeDrawer() {
        sidebar.classList.remove('drawer-open');
        var overlay  = document.getElementById('sidebarOverlay');
        var toggle   = document.getElementById('drawerToggle');
        var closeBtn = sidebar.querySelector('.drawer-close');
        if (overlay)  overlay.classList.remove('visible');
        if (toggle)   { toggle.classList.remove('is-open'); toggle.setAttribute('aria-expanded','false'); }
        if (closeBtn) closeBtn.style.display = 'none';
        document.body.style.overflow = '';
    }

    /* Expose globally so inline onclick works */
    window.__docugoCloseDrawer = closeDrawer;

    /* ── 5. Wire hamburger button ── */
    function wireToggle() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('#drawerToggle');
            if (!btn) return;
            sidebar.classList.contains('drawer-open') ? closeDrawer() : openDrawer();
        });
    }

    /* ── 6. Close drawer when a nav link is tapped ── */
    function wireNavLinks() {
        var links = sidebar.querySelectorAll('a');
        links.forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= BREAKPOINT) closeDrawer();
            });
        });
    }

    /* ── 7. Close on resize to desktop ── */
    function wireResize() {
        var timer;
        window.addEventListener('resize', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                if (window.innerWidth > BREAKPOINT) closeDrawer();
            }, 100);
        });
    }

    /* ── 8. Handle swipe-to-close on sidebar ── */
    function wireSwipe() {
        var startX, startY;
        sidebar.addEventListener('touchstart', function (e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        sidebar.addEventListener('touchend', function (e) {
            if (!startX) return;
            var dx = e.changedTouches[0].clientX - startX;
            var dy = Math.abs(e.changedTouches[0].clientY - startY);
            /* swipe left > 60px, and mostly horizontal */
            if (dx < -60 && dy < 50) closeDrawer();
            startX = null;
        }, { passive: true });
    }

    /* ── 9. Active nav item highlight ── */
    function highlightActive() {
        var path  = window.location.pathname.split('/').pop();
        var links = sidebar.querySelectorAll('a.menu-item, a.p-nav-item');
        links.forEach(function (link) {
            var href = link.getAttribute('href') || '';
            link.classList.toggle('active', href === path || href.endsWith('/' + path));
        });
    }

    /* ── Utility ── */
    function escHTML(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    /* ── Init on DOM ready ── */
    function init() {
        buildTopbar();
        buildOverlay();
        buildCloseBtn();
        wireToggle();
        wireNavLinks();
        wireResize();
        wireSwipe();
        highlightActive();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();