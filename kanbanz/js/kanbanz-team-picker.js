/**
 * Kanbanz team picker for new boards ("Who can see this board?").
 *
 * The checked .kanbanz-team-picker__cb boxes (name="assigned_id[]") are the
 * source of truth that the create-board forms submit; this script adds the
 * Everyone / Only team switch, search, and chips for the selected people.
 */
(function() {
	function escHtml(str) {
		return String(str == null ? '' : str).replace(/[&<>"']/g, function(c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function parts(root) {
		return {
			people: root.querySelector('.kanbanz-team-picker__people'),
			chips: root.querySelector('.kanbanz-team-picker__chips'),
			search: root.querySelector('.kanbanz-team-picker__search input'),
			items: Array.prototype.slice.call(root.querySelectorAll('.kanbanz-team-picker__item')),
			boxes: Array.prototype.slice.call(root.querySelectorAll('.kanbanz-team-picker__cb')),
			empty: root.querySelector('.kanbanz-team-picker__empty'),
			error: root.querySelector('.kanbanz-team-picker__error')
		};
	}

	function isTeamMode(root) {
		var checked = root.querySelector('.kanbanz-visibility-options input:checked');
		return checked && checked.value === 'team';
	}

	function renderChips(root) {
		var p = parts(root);
		var removeLabel = root.dataset.removeLabel || 'Remove %s';
		p.chips.innerHTML = p.boxes.filter(function(b) { return b.checked; }).map(function(b) {
			var label = removeLabel.replace('%s', b.dataset.name);
			return '<span class="kanbanz-team-picker__chip">'
				+ '<img src="' + escHtml(b.dataset.avatar) + '" alt="" width="20" height="20" />'
				+ escHtml(b.dataset.name)
				+ '<button type="button" data-username="' + escHtml(b.value) + '" aria-label="' + escHtml(label) + '" title="' + escHtml(label) + '">&times;</button>'
				+ '</span>';
		}).join('');
		p.items.forEach(function(li) {
			li.classList.toggle('kanbanz-team-picker__item--selected', li.querySelector('.kanbanz-team-picker__cb').checked);
		});
		if (p.boxes.some(function(b) { return b.checked; })) p.error.hidden = true;
	}

	function filter(root) {
		var p = parts(root);
		var q = p.search.value.trim().toLowerCase();
		var shown = 0;
		p.items.forEach(function(li) {
			var match = !q || li.dataset.search.indexOf(q) > -1;
			li.hidden = !match;
			if (match) shown++;
		});
		p.empty.hidden = shown > 0;
	}

	function setMode(root) {
		var p = parts(root);
		var team = isTeamMode(root);
		p.people.hidden = !team;
		root.querySelectorAll('.kanbanz-visibility-option').forEach(function(opt) {
			opt.classList.toggle('kanbanz-visibility-option--active', opt.querySelector('input').checked);
		});
		if (!team) {
			// "Everyone" means no team: make sure nothing is submitted
			p.boxes.forEach(function(b) { b.checked = false; });
			p.error.hidden = true;
			renderChips(root);
		}
	}

	function init(root) {
		if (root.dataset.pickerReady) return;
		root.dataset.pickerReady = '1';
		var p = parts(root);
		root.querySelectorAll('.kanbanz-visibility-options input').forEach(function(radio) {
			radio.addEventListener('change', function() {
				setMode(root);
				if (isTeamMode(root)) p.search.focus();
			});
		});
		p.boxes.forEach(function(b) { b.addEventListener('change', function() { renderChips(root); }); });
		p.search.addEventListener('input', function() { filter(root); });
		p.search.addEventListener('keydown', function(e) {
			// Enter in the search box selects the only visible match instead of submitting the form
			if (e.key !== 'Enter') return;
			e.preventDefault();
			var visible = p.items.filter(function(li) { return !li.hidden; });
			if (visible.length === 1) {
				var box = visible[0].querySelector('.kanbanz-team-picker__cb');
				box.checked = true;
				renderChips(root);
				p.search.value = '';
				filter(root);
			}
		});
		p.chips.addEventListener('click', function(e) {
			var btn = e.target.closest('button[data-username]');
			if (!btn) return;
			p.boxes.forEach(function(b) { if (b.value === btn.dataset.username) b.checked = false; });
			renderChips(root);
			p.search.focus();
		});
		setMode(root);
		renderChips(root);
	}

	window.KanbanzTeamPicker = {
		init: init,
		/**
		 * "Only team members" needs at least one person; otherwise the board
		 * would silently be visible to everyone. Shows the error and returns false.
		 */
		validate: function(root) {
			if (!root || !isTeamMode(root)) return true;
			var p = parts(root);
			if (p.boxes.some(function(b) { return b.checked; })) return true;
			p.error.hidden = false;
			p.search.focus();
			return false;
		},
		selected: function(root) {
			return root ? parts(root).boxes.filter(function(b) { return b.checked; }).map(function(b) { return b.value; }) : [];
		},
		/** Back to "Everyone" with nothing selected and an empty search. */
		reset: function(root) {
			if (!root) return;
			var everyone = root.querySelector('.kanbanz-visibility-options input[value="everyone"]');
			if (everyone) everyone.checked = true;
			var p = parts(root);
			p.search.value = '';
			filter(root);
			setMode(root);
		}
	};

	function initAll() {
		document.querySelectorAll('.kanbanz-team-picker').forEach(init);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll);
	else initAll();
})();
