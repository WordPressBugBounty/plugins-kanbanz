/**
 * Kanbanz board listing: search, create/delete board modals and optional Deskuss department sync.
 */
(function() {
	var kanbanBaseUrl = kanbanzListing.boardUrl;
	var ajaxurl = kanbanzListing.ajaxUrl;
	var nonce = kanbanzListing.nonce;

	// Minimal toast helper (this template doesn't load kanbanz-editor.js)
	function showToast(msg, type) {
		type = type || 'success';
		var container = document.getElementById('kanbanz-toast-container');
		if (!container) { alert(msg); return; }
		var icons = { success: 'dashicons-yes-alt', error: 'dashicons-dismiss', warning: 'dashicons-warning' };
		var toast = document.createElement('div');
		toast.className = 'kanbanz-toast kanbanz-toast--' + type;
		toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
		toast.innerHTML = '<span class="dashicons ' + (icons[type] || icons.success) + ' kanbanz-toast__icon" aria-hidden="true"></span><span class="kanbanz-toast__message">' + (msg || '').replace(/[&<>]/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }) + '</span>';
		container.appendChild(toast);
		setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
	}

	// Minimal confirm modal (returns a Promise<{ok:boolean}>)
	function confirmModal(message, opts) {
		opts = opts || {};
		return new Promise(function(resolve) {
			var modal = document.getElementById('kanbanz-confirm-modal');
			if (!modal) { resolve({ ok: confirm(message) }); return; }
			var titleEl = document.getElementById('kanbanz-confirm-title');
			var msgEl = document.getElementById('kanbanz-confirm-message');
			var okBtn = document.getElementById('kanbanz-confirm-ok');
			var cancelBtn = document.getElementById('kanbanz-confirm-cancel');
			var overlay = modal.querySelector('.kanbanz-confirm-modal__overlay');
			var dialog = modal.querySelector('.kanbanz-confirm-modal__dialog');
			if (titleEl) titleEl.textContent = opts.title || kanbanzListing.i18n.confirm;
			if (msgEl) msgEl.textContent = message;
			if (okBtn) okBtn.textContent = opts.confirmText || kanbanzListing.i18n.confirm;
			if (cancelBtn) cancelBtn.textContent = opts.cancelText || kanbanzListing.i18n.cancel;
			modal.style.display = '';
			modal.setAttribute('aria-hidden', 'false');
			var lastFocus = document.activeElement;
			if (okBtn) { try { (opts.danger ? cancelBtn : okBtn).focus(); } catch(e) {} }
			function cleanup() {
				if (okBtn) okBtn.removeEventListener('click', onOk);
				if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
				if (overlay) overlay.removeEventListener('click', onCancel);
				if (dialog) dialog.removeEventListener('keydown', onKey);
				modal.style.display = 'none';
				modal.setAttribute('aria-hidden', 'true');
				if (lastFocus) { try { lastFocus.focus(); } catch(e) {} }
			}
			function onOk() { cleanup(); resolve({ ok: true }); }
			function onCancel() { cleanup(); resolve({ ok: false }); }
			function onKey(e) {
				if (e.key === 'Escape') { e.preventDefault(); onCancel(); }
			}
			if (okBtn) okBtn.addEventListener('click', onOk);
			if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
			if (overlay) overlay.addEventListener('click', onCancel);
			if (dialog) dialog.addEventListener('keydown', onKey);
		});
	}

	// Update board count
	var cards = document.querySelectorAll('.kanbanz-board-card');
	var countEl = document.getElementById('kanbanz-board-count');
	if (countEl) countEl.textContent = cards.length;

	// Search boards
	var searchInput = document.getElementById('kanbanz-board-search');
	if (searchInput) {
		searchInput.addEventListener('input', function() {
			var q = this.value.toLowerCase();
			cards.forEach(function(card) {
				var title = card.querySelector('.kanbanz-board-card__title');
				var desc = card.querySelector('.kanbanz-board-card__desc');
				var t = title ? title.textContent.toLowerCase() : '';
				var d = desc ? desc.textContent.toLowerCase() : '';
				card.style.display = (t.indexOf(q) > -1 || d.indexOf(q) > -1) ? '' : 'none';
			});
			// Hide empty sections
			document.querySelectorAll('.kanbanz-listing__section').forEach(function(section) {
				var hasVisible = false;
				section.querySelectorAll('.kanbanz-board-card').forEach(function(c) {
					if (c.style.display !== 'none') hasVisible = true;
				});
				section.style.display = hasVisible ? '' : 'none';
			});
		});
	}

	// Create board modal
	var createBtn = document.getElementById('kanbanz-create-board-btn');
	var createEmptyBtn = document.getElementById('kanbanz-create-board-empty');
	var createModal = document.getElementById('kanbanz-create-modal');
	var createCloseBtn = document.getElementById('kanbanz-create-modal-close');
	var createCancelBtn = document.getElementById('kanbanz-create-cancel');
	var createForm = document.getElementById('kanbanz-create-board-form');

	function openCreateModal() {
		if (!createModal) return;
		if (window.KanbanzTeamPicker) KanbanzTeamPicker.reset(document.getElementById('kanbanz-create-team-picker'));
		createModal.style.display = '';
	}
	function closeCreateModal() { if (createModal) createModal.style.display = 'none'; }

	if (createBtn) createBtn.addEventListener('click', openCreateModal);
	if (createEmptyBtn) createEmptyBtn.addEventListener('click', openCreateModal);
	if (createCloseBtn) createCloseBtn.addEventListener('click', closeCreateModal);
	if (createCancelBtn) createCancelBtn.addEventListener('click', closeCreateModal);

	if (createForm) {
		createForm.addEventListener('submit', function(e) {
			e.preventDefault();
			if (window.KanbanzTeamPicker && !KanbanzTeamPicker.validate(document.getElementById('kanbanz-create-team-picker'))) return;
			var submitBtn = document.getElementById('kanbanz-create-submit');
			var spinner = submitBtn ? submitBtn.querySelector('.kanbanz-spinner') : null;
			if (spinner) spinner.style.display = '';
			submitBtn.disabled = true;

			var fd = new FormData(createForm);
			fd.append('action', 'kanbanz_create_board');
			fd.append('_wpnonce', nonce);

			fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success) {
						window.location.href = kanbanBaseUrl + res.data.post_id;
					} else {
						showToast(res.data && res.data.message ? res.data.message : kanbanzListing.i18n.errorCreating, 'error');
						if (spinner) spinner.style.display = 'none';
						submitBtn.disabled = false;
					}
				})
				.catch(function() {
					showToast(kanbanzListing.i18n.networkError, 'error');
					if (spinner) spinner.style.display = 'none';
					submitBtn.disabled = false;
				});
		});
	}

	// Board card context menu
	var contextMenu = document.getElementById('kanbanz-board-context-menu');
	var deleteBtn = contextMenu ? contextMenu.querySelector('[data-action="delete"]') : null;
	var activeBoardId = 0;
	var activeIsDept = false;

	document.querySelectorAll('.kanbanz-board-card__menu-btn').forEach(function(btn) {
		btn.addEventListener('click', function(e) {
			e.preventDefault();
			e.stopPropagation();
			activeBoardId = parseInt(this.dataset.boardId);
			activeIsDept = this.dataset.isDept === '1';
			contextMenu.style.display = '';
			// Show/hide delete based on dept status
			if (deleteBtn) {
				deleteBtn.style.display = activeIsDept ? 'none' : '';
			}
			var rect = this.getBoundingClientRect();
			contextMenu.style.top = rect.bottom + 4 + 'px';
			contextMenu.style.left = rect.left + 'px';
		});
	});

	document.addEventListener('click', function() {
		if (contextMenu) contextMenu.style.display = 'none';
	});

	document.querySelectorAll('.kanbanz-context-menu__item').forEach(function(item) {
		item.addEventListener('click', function() {
			var action = this.dataset.action;
			if (action === 'open') {
				window.location.href = kanbanBaseUrl + activeBoardId;
			} else if (action === 'delete' && !activeIsDept) {
				var deleteModal = document.getElementById('kanbanz-delete-modal');
				if (deleteModal) deleteModal.style.display = '';
			}
			contextMenu.style.display = 'none';
		});
	});

	// Delete confirm modal
	var deleteModal = document.getElementById('kanbanz-delete-modal');
	var deleteCloseBtn = document.getElementById('kanbanz-delete-modal-close');
	var deleteCancelBtn = document.getElementById('kanbanz-delete-cancel');
	var deleteConfirmBtn = document.getElementById('kanbanz-delete-confirm');

	if (deleteCloseBtn) deleteCloseBtn.addEventListener('click', function() { deleteModal.style.display = 'none'; });
	if (deleteCancelBtn) deleteCancelBtn.addEventListener('click', function() { deleteModal.style.display = 'none'; });

	if (deleteConfirmBtn) {
		deleteConfirmBtn.addEventListener('click', function() {
			var fd = new FormData();
			fd.append('action', 'kanbanz_remove_board');
			fd.append('_wpnonce', nonce);
			fd.append('board_id', activeBoardId);

			fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success) {
						window.location.reload();
					} else {
						showToast(res.data && res.data.message ? res.data.message : kanbanzListing.i18n.errorDeleting, 'error');
					}
				});
		});
	}

	// Click overlay to close modals
	document.querySelectorAll('.kanbanz-modal-overlay').forEach(function(overlay) {
		overlay.addEventListener('click', function(e) {
			if (e.target === overlay) overlay.style.display = 'none';
		});
	});

	if (kanbanzListing.deskussSync) {
		// Sync Departments
		var syncBtn = document.getElementById('kanbanz-sync-departments-btn');
		if (syncBtn) {
			syncBtn.addEventListener('click', function() {
				confirmModal(kanbanzListing.i18n.syncConfirm, { title: kanbanzListing.i18n.syncTitle, confirmText: kanbanzListing.i18n.syncButton, danger: true }).then(function(result) {
					if (!result.ok) return;
					syncBtn.disabled = true;
					var icon = syncBtn.querySelector('.dashicons');
					if (icon) icon.classList.add('kanbanz-spin');

					var fd = new FormData();
					fd.append('action', 'kanbanz_sync_departments');
					fd.append('_wpnonce', nonce);

					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r) { return r.json(); })
						.then(function(res) {
							syncBtn.disabled = false;
							if (icon) icon.classList.remove('kanbanz-spin');
							if (res.success) {
								var d = res.data;
								var msg = kanbanzListing.i18n.syncComplete + ' ' + (d.boards_created || 0) + ' ' + kanbanzListing.i18n.boardsCreated + ', ' + (d.tasks_reparented || 0) + ' ' + kanbanzListing.i18n.tasksReparented;
								showToast(msg, 'success');
								if (d.boards_created > 0 || d.tasks_reparented > 0) {
									setTimeout(function() { window.location.reload(); }, 1200);
								}
							} else {
								showToast(res.data && res.data.message ? res.data.message : kanbanzListing.i18n.syncFailed, 'error');
							}
						})
						.catch(function() {
							syncBtn.disabled = false;
							if (icon) icon.classList.remove('kanbanz-spin');
							showToast(kanbanzListing.i18n.networkError, 'error');
						});
				});
			});
		}
	}
})();
