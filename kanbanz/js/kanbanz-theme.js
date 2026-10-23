/**
 * Kanbanz — Shared theme controller.
 * Applies the user's saved theme (light/dark) to every Kanbanz admin screen
 * and keeps it in sync with localStorage. Falls back to OS preference on
 * first visit. Exposes window.kanbanzTheme with apply/toggle/init.
 *
 * This script is intentionally tiny and has no dependencies so it can run
 * before paint (enqueued in <head> or with defer) to avoid a flash of the
 * wrong theme.
 */
(function() {
	'use strict';

	var STORAGE_KEY = 'kanbanz_theme';
	var wrapperSel = '.kanbanz-wrapper';

	function readSaved() {
		try {
			var v = localStorage.getItem(STORAGE_KEY);
			if (v === 'light' || v === 'dark') return v;
		} catch (e) {}
		return null;
	}

	function osPreference() {
		try {
			return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
		} catch (e) { return 'light'; }
	}

	function currentTheme() {
		return readSaved() || osPreference();
	}

	function applyTheme(theme) {
		theme = (theme === 'dark') ? 'dark' : 'light';
		try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) {}
		document.querySelectorAll(wrapperSel).forEach(function(el) {
			el.setAttribute('data-theme', theme);
		});
		// Sync any theme toggle buttons on the page
		document.querySelectorAll('[data-kanbanz-theme-toggle]').forEach(function(btn) {
			var icon = btn.querySelector('.dashicons');
			if (icon) icon.className = 'dashicons ' + (theme === 'dark' ? 'dashicons-star-filled' : 'dashicons-lightbulb');
			btn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
			btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
		});
		// Notify any listeners (e.g. Chart.js redraw)
		try { window.dispatchEvent(new CustomEvent('kanbanz:theme-change', { detail: { theme: theme } })); } catch (e) {}
	}

	function toggleTheme() {
		var cur = readSaved() || osPreference();
		applyTheme(cur === 'dark' ? 'light' : 'dark');
	}

	// Boot: apply theme ASAP. Runs immediately if DOM is already parsed,
	// otherwise on DOMContentLoaded. We do NOT wait for window.load.
	function boot() {
		applyTheme(currentTheme());
		// Bind any toggle buttons present
		document.querySelectorAll('[data-kanbanz-theme-toggle]').forEach(function(btn) {
			btn.addEventListener('click', toggleTheme);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Re-apply when new wrappers are added (defensive; covers AJAX-injected UI)
	var observer = new MutationObserver(function() {
		var theme = readSaved() || osPreference();
		document.querySelectorAll(wrapperSel + ':not([data-theme-synced])').forEach(function(el) {
			el.setAttribute('data-theme', theme);
			el.setAttribute('data-theme-synced', '1');
		});
	});
	if (document.body) {
		observer.observe(document.body, { childList: true, subtree: true });
	}

	window.kanbanzTheme = {
		apply: applyTheme,
		toggle: toggleTheme,
		current: currentTheme,
		saved: readSaved,
		osPreference: osPreference
	};
})();