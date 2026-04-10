/**
 * theme.js — Shared theme toggle (no framework dependency)
 * Load in <head> (synchronous) to prevent flash of wrong theme.
 */
(function () {
    'use strict';

    function getPreferred() {
        var saved = localStorage.getItem('theme');
        if (saved === 'dark' || saved === 'light') return saved;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(theme) {
        var isDark = theme === 'dark';
        document.documentElement.classList.toggle('dark', isDark);
        document.body && document.body.classList.toggle('dark', isDark);
        localStorage.setItem('theme', theme);
    }

    // Apply immediately (before paint)
    apply(getPreferred());

    // Re-apply after body is available (for pages where script is in <head>)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { apply(getPreferred()); });
    }

    // Listen for system preference changes (only if no explicit user choice)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        if (!localStorage.getItem('theme')) {
            apply(e.matches ? 'dark' : 'light');
        }
    });

    // Public API
    window.toggleTheme = function () {
        var next = getPreferred() === 'dark' ? 'light' : 'dark';
        apply(next);
        return next;
    };

    window.getTheme = function () {
        return getPreferred();
    };
})();
