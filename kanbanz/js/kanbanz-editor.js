/**
 * Deskuss Kanban V2 — Core Engine
 * IIFE module exposing window.KanbanzEditor
 */
(function() {
	'use strict';

	var KZ = {};
	KZ.data = null;
	KZ.tasks = new Map();
	KZ.columns = [];
	KZ.filters = { search: '', myTasks: false, priorities: new Set(), priority: '', overdue: false, assignee: 0, assigneeName: '', dateTarget: 'due_date', datePreset: 'all', dateFrom: '', dateTo: '' };
	KZ.selection = new Set();
	KZ.ui = { theme: 'light', panelOpen: false, activeTaskId: null, commandOpen: false, layout: 'board' };
	KZ.config = {};
	KZ._lastFocusBeforeDialog = null; // for focus restore

	// --- i18n helper ---
	function t(key, fallback) {
		if (KZ.config && KZ.config.i18n && KZ.config.i18n[key]) return KZ.config.i18n[key];
		return fallback != null ? fallback : key;
	}

	// --- Utilities ---
	function escHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	function escAttr(str) {
		return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
	}

	// Minimal printf-style helper: sprintf_like('Ticket #%s', 123) -> 'Ticket #123'
	function sprintf_like(fmt) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(fmt).replace(/%s/g, function() {
			return args[i++] != null ? String(args[i - 1]) : '';
		});
	}

	function ajax(action, data, callback) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('_wpnonce', KZ.config.nonce || kanbanzVars.ajax_nonce);
		if (data) {
			Object.keys(data).forEach(function(key) {
				if (Array.isArray(data[key])) {
					data[key].forEach(function(v) { fd.append(key + '[]', v); });
				} else {
					fd.append(key, data[key]);
				}
			});
		}
		fetch(KZ.config.ajaxurl || kanbanzVars.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(res) { if (callback) callback(res); })
			.catch(function(err) { if (callback) callback({ success: false, data: { message: err.message } }); });
	}

	// --- Toast ---
	KZ.Toast = {
		show: function(msg, type) {
			type = type || 'success';
			var container = document.getElementById('kanbanz-toast-container');
			if (!container) return;
			var icons = { success: 'dashicons-yes-alt', error: 'dashicons-dismiss', warning: 'dashicons-warning' };
			var toast = document.createElement('div');
			toast.className = 'kanbanz-toast kanbanz-toast--' + type;
			toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
			toast.innerHTML = '<span class="dashicons ' + (icons[type] || icons.success) + ' kanbanz-toast__icon" aria-hidden="true"></span><span class="kanbanz-toast__message">' + escHtml(msg) + '</span>';
			container.appendChild(toast);
			setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
		},
		success: function(msg) { KZ.Toast.show(msg, 'success'); },
		error: function(msg) { KZ.Toast.show(msg, 'error'); },
		warning: function(msg) { KZ.Toast.show(msg, 'warning'); }
	};

	// --- Focus Trap (for modal/drawer/palette/confirm) ---
	KZ.FocusTrap = {
		_active: null,
		_handler: null,
		trap: function(container, opts) {
			opts = opts || {};
			if (!container) return;
			KZ.FocusTrap.release();
			KZ._lastFocusBeforeDialog = document.activeElement;
			KZ.FocusTrap._active = container;
			// mark dialog visible to AT
			container.setAttribute('aria-hidden', 'false');
			// focus the container or first focusable
			var focusables = KZ.FocusTrap._getFocusables(container);
			if (opts.initialFocus) {
				var el = typeof opts.initialFocus === 'function' ? opts.initialFocus() : opts.initialFocus;
				if (el) { try { el.focus(); } catch(e) {} }
			} else if (focusables.length) {
				try { focusables[0].focus(); } catch(e) {}
			} else {
				try { container.focus(); } catch(e) {}
			}
			KZ.FocusTrap._handler = function(e) {
				if (e.key !== 'Tab') return;
				var f = KZ.FocusTrap._getFocusables(container);
				if (!f.length) { e.preventDefault(); return; }
				var first = f[0], last = f[f.length - 1];
				if (e.shiftKey) {
					if (document.activeElement === first) { e.preventDefault(); try { last.focus(); } catch(err){} }
				} else {
					if (document.activeElement === last) { e.preventDefault(); try { first.focus(); } catch(err){} }
				}
			};
			container.addEventListener('keydown', KZ.FocusTrap._handler);
		},
		release: function(restoreFocus) {
			if (KZ.FocusTrap._active) {
				KZ.FocusTrap._active.removeEventListener('keydown', KZ.FocusTrap._handler);
				KZ.FocusTrap._active.setAttribute('aria-hidden', 'true');
				KZ.FocusTrap._active = null;
				KZ.FocusTrap._handler = null;
			}
			if (restoreFocus !== false && KZ._lastFocusBeforeDialog) {
				try { KZ._lastFocusBeforeDialog.focus(); } catch(e) {}
				KZ._lastFocusBeforeDialog = null;
			}
		},
		_getFocusables: function(container) {
			var sel = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"]), [contenteditable="true"]';
			return Array.from(container.querySelectorAll(sel)).filter(function(el) {
				return el.offsetParent !== null || el === document.activeElement;
			});
		}
	};

	// --- Confirm Modal (replaces native confirm/prompt) ---
	// Usage: KZ.confirm({ title, message, confirmText, cancelText, danger, input: {value, placeholder} })
	//        -> returns a Promise; resolves to {ok: bool, value: string|null}
	KZ.confirm = function(opts) {
		opts = opts || {};
		return new Promise(function(resolve) {
			var modal = document.getElementById('kanbanz-confirm-modal');
			if (!modal) {
				// Fallback to native if markup missing
				if (opts.input) {
					var v = prompt(opts.message || '', opts.input && opts.input.value ? opts.input.value : '');
					resolve({ ok: v !== null, value: v });
				} else {
					resolve({ ok: confirm(opts.message || ''), value: null });
				}
				return;
			}
			var titleEl = document.getElementById('kanbanz-confirm-title');
			var msgEl = document.getElementById('kanbanz-confirm-message');
			var inputWrap = document.getElementById('kanbanz-confirm-input-wrap');
			var inputEl = document.getElementById('kanbanz-confirm-input');
			var okBtn = document.getElementById('kanbanz-confirm-ok');
			var cancelBtn = document.getElementById('kanbanz-confirm-cancel');
			var overlay = modal.querySelector('.kanbanz-confirm-modal__overlay');
			var dialog = modal.querySelector('.kanbanz-confirm-modal__dialog');

			if (titleEl) titleEl.textContent = opts.title || t('confirm', 'Confirm');
			if (msgEl) msgEl.textContent = opts.message || '';
			if (okBtn) {
				okBtn.textContent = opts.confirmText || t('confirm', 'Confirm');
				okBtn.className = 'kanbanz-btn ' + (opts.danger ? 'kanbanz-btn--danger' : 'kanbanz-btn--primary');
			}
			if (cancelBtn) cancelBtn.textContent = opts.cancelText || t('cancel', 'Cancel');

			var useInput = !!opts.input;
			if (inputWrap) inputWrap.style.display = useInput ? '' : 'none';
			if (useInput && inputEl) {
				inputEl.value = (opts.input && opts.input.value != null) ? opts.input.value : '';
				if (opts.input && opts.input.placeholder) inputEl.placeholder = opts.input.placeholder;
			}

			modal.style.display = '';
			modal.setAttribute('aria-hidden', 'false');
			KZ.FocusTrap.trap(dialog, { initialFocus: useInput ? inputEl : (opts.danger ? cancelBtn : okBtn) });

			function cleanup() {
				if (okBtn) okBtn.removeEventListener('click', onOk);
				if (cancelBtn) cancelBtn.removeEventListener('click', onCancel);
				if (overlay) overlay.removeEventListener('click', onCancel);
				if (inputEl) inputEl.removeEventListener('keydown', onKey);
				if (dialog) dialog.removeEventListener('keydown', onKey);
				modal.style.display = 'none';
				modal.setAttribute('aria-hidden', 'true');
				KZ.FocusTrap.release();
			}
			function onOk() {
				var val = useInput ? inputEl.value : null;
				cleanup();
				resolve({ ok: true, value: val });
			}
			function onCancel() {
				cleanup();
				resolve({ ok: false, value: null });
			}
			function onKey(e) {
				if (e.key === 'Escape') { e.preventDefault(); onCancel(); }
				else if (e.key === 'Enter' && (e.target === inputEl)) { e.preventDefault(); onOk(); }
			}
			if (okBtn) okBtn.addEventListener('click', onOk);
			if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
			if (overlay) overlay.addEventListener('click', onCancel);
			if (inputEl) inputEl.addEventListener('keydown', onKey);
			if (dialog) dialog.addEventListener('keydown', onKey);
		});
	};

	// --- DOM Helpers ---
	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
	function getCard(id) { return $('.kanbanz-card[data-task-id="' + id + '"]'); }
	function getColBody(status) { return $('[data-status="' + status + '"].kanbanz-column__body'); }
	function updateCount(status) {
		var body = getColBody(status);
		if (!body) return;
		var cards = $$('.kanbanz-card', body).length;
		var col = body.closest('.kanbanz-column');
		var countEl = col ? $('.kanbanz-column__count', col) : null;
		if (!countEl) return;
		// data-count is the column total (it can include tasks not loaded yet);
		// apply the change in loaded cards to it so updateWipDisplay() stays in sync.
		var loaded = parseInt(col.dataset.loaded, 10);
		var total = parseInt(countEl.dataset.count, 10);
		if (isNaN(loaded)) loaded = cards;
		if (isNaN(total)) total = cards;
		total = Math.max(cards, total + (cards - loaded));
		col.dataset.loaded = cards;
		col.dataset.total = total;
		countEl.dataset.count = total;
		countEl.textContent = total;
	}
	function updateAllCounts() {
		KZ.columns.forEach(function(s) { updateCount(s); });
		var total = 0;
		KZ.tasks.forEach(function() { total++; });
		var countEl = document.getElementById('kanbanz-task-count');
		if (countEl) countEl.textContent = total;
		KZ.updateWipDisplay();
		KZ.updateEmptyStates();
	}

	// --- Task Creation ---
	KZ.showTaskInput = function(status) {
		var body = getColBody(status);
		if (!body) return;
		var existing = $('.kanbanz-inline-task-input', body);
		if (existing) { existing.focus(); return; }
		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'kanbanz-inline-task-input';
		input.setAttribute('aria-label', t('addTask', 'Add task'));
		input.placeholder = t('addTask', 'Add task...');
		body.insertBefore(input, body.firstChild);
		input.focus();
		input.addEventListener('keydown', function(e) {
			if (e.key === 'Enter' && this.value.trim()) {
				KZ.createTask(this.value.trim(), status);
				this.remove();
			} else if (e.key === 'Escape') {
				this.remove();
			}
		});
		input.addEventListener('blur', function() {
			var self = this;
			setTimeout(function() { if (self.parentNode) self.remove(); }, 200);
		});
	};

	// --- Task CRUD ---
	KZ.createTask = function(title, status, boardId) {
		boardId = boardId || KZ.config.board_id;
		ajax('kanbanz_save_new_task', { val: title, board_id: boardId, status: status }, function(res) {
			if (res.success) {
				var task = res.data;
				var todayStr = (new Date()).toISOString().substring(0, 10);
				task.created_date = task.created_date || todayStr;
				if (!task.task_meta) {
					task.task_meta = { priority: '', due_date: '', created_date: todayStr, assigned: [], is_overdue: false };
				} else {
					task.task_meta.created_date = task.task_meta.created_date || todayStr;
				}
				task.task_status = task.task_status || status;
				KZ.tasks.set(task.id, task);
				var body = getColBody(status);
				if (body) {
					var card = KZ.renderCard(task);
					var addBtn = body.parentElement.querySelector('.kanbanz-column__add-task');
					if (addBtn) addBtn.parentElement.insertBefore(card, addBtn);
					else body.appendChild(card);
				}
				updateCount(status);
				updateAllCounts();
				KZ.Toast.success(t('taskCreated', 'Task created'));
			} else {
				KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorCreatingTask', 'Error creating task'));
			}
		});
	};

	KZ.deleteTask = function(taskId) {
		KZ.confirm({
			title: t('delete', 'Delete'),
			message: t('confirmDelete', 'Are you sure you want to delete this task? This cannot be undone.'),
			confirmText: t('delete', 'Delete'),
			cancelText: t('cancel', 'Cancel'),
			danger: true
		}).then(function(result) {
			if (!result.ok) return;
			ajax('kanbanz_remove_task', { task_id: taskId }, function(res) {
				if (res.success) {
					var card = getCard(taskId);
					var status = KZ.tasks.get(taskId) ? (KZ.tasks.get(taskId).task_status || '') : '';
					if (card) card.remove();
					KZ.tasks.delete(taskId);
					updateCount(status);
					updateAllCounts();
					if (KZ.ui.activeTaskId === taskId) KZ.closePanel();
					KZ.Toast.success(t('taskDeleted', 'Task deleted'));
				} else {
					KZ.Toast.error(t('errorDeletingTask', 'Error deleting task'));
				}
			});
		});
	};

	KZ.moveTask = function(taskId, newStatus, order) {
		var task = KZ.tasks.get(taskId);
		var oldStatus = task ? (task.task_status || '') : '';
		// Snapshot card position for rollback
		var card = getCard(taskId);
		var oldParent = card ? card.parentNode : null;
		var oldNextSibling = card ? card.nextSibling : null;
		// Callers other than drag-and-drop (panel/context menu "Move") leave the card in its old column
		if (card && card.closest('.kanbanz-column__body') && card.closest('.kanbanz-column__body').dataset.status !== newStatus) {
			var newBody = getColBody(newStatus);
			if (newBody) {
				var addBtn = newBody.querySelector('.kanbanz-column__add-task');
				if (addBtn) newBody.insertBefore(card, addBtn);
				else newBody.appendChild(card);
			}
		}
		var data = { task_id: taskId, status: newStatus };
		if (order && Array.isArray(order)) {
			order.forEach(function(id, idx) { data['task_order[' + idx + ']'] = id; });
		}
		ajax('kanbanz_tasks_status', data, function(res) {
			if (res.success) {
				if (task) task.task_status = newStatus;
				updateCount(newStatus);
				if (oldStatus && oldStatus !== newStatus) updateCount(oldStatus);
				updateAllCounts();
				if (KZ.ui.activeTaskId == taskId) {
					var badge = document.getElementById('kanbanz-modal-status-badge');
					if (badge) badge.textContent = newStatus;
				}
				KZ.Toast.success(t('taskMoved', 'Task moved'));
			} else {
				// Rollback: move the card back to its old column/position
				if (card && oldParent) {
					if (oldNextSibling) oldParent.insertBefore(card, oldNextSibling);
					else oldParent.appendChild(card);
				}
				if (task) task.task_status = oldStatus;
				updateCount(newStatus);
				if (oldStatus && oldStatus !== newStatus) updateCount(oldStatus);
				updateAllCounts();
				KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorMovingTask', 'Error moving task'));
			}
		});
	};

	// --- Column Menu ---
	KZ.showColumnMenu = function(columnEl, event) {
		event.stopPropagation();
		var menu = document.getElementById('kanbanz-column-menu');
		if (!menu) return;
		menu.dataset.status = columnEl.dataset.status;
		var rect = event.currentTarget.getBoundingClientRect();
		menu.style.top = rect.bottom + 4 + 'px';
		menu.style.left = rect.left + 'px';
		menu.style.display = '';
		var trigger = event.currentTarget;
		if (trigger) trigger.setAttribute('aria-expanded', 'true');
		menu._trigger = trigger;
		setTimeout(function() { document.addEventListener('click', KZ.hideColumnMenu, { once: true }); }, 0);
	};

	KZ.hideColumnMenu = function() {
		var menu = document.getElementById('kanbanz-column-menu');
		if (menu) {
			menu.style.display = 'none';
			if (menu._trigger) { menu._trigger.setAttribute('aria-expanded', 'false'); menu._trigger = null; }
		}
	};

	KZ.setWipLimit = function(status) {
		var currentLimit = (KZ.config.wip_limits && KZ.config.wip_limits[status]) || 0;
		KZ.confirm({
			title: t('sectionSubtasks', 'Set WIP Limit'),
			message: t('wipLimitFor', 'WIP limit for') + ' "' + status + '" (' + t('setWipLimitPrompt', '0 to remove') + '):',
			confirmText: t('saveBtn', 'Save'),
			cancelText: t('cancel', 'Cancel'),
			danger: false,
			input: { value: String(currentLimit || ''), placeholder: '0' }
		}).then(function(result) {
			if (!result.ok) return;
			var value = result.value;
			var limit = parseInt(value, 10);
			if (isNaN(limit) || limit < 0) { KZ.Toast.error(t('invalidWipLimit', 'Invalid WIP limit')); return; }
			ajax('kanbanz_set_wip_limit', { board_id: KZ.config.board_id, status: status, wip_limit: limit }, function(res) {
				if (res.success) {
					KZ.config.wip_limits = res.data.wip_limits;
					KZ.updateWipDisplay();
					KZ.Toast.success(limit > 0 ? t('wipLimitSetTo', 'WIP limit set to') + ' ' + limit : t('wipLimitRemoved', 'WIP limit removed'));
				} else {
					KZ.Toast.error(t('errorSettingWip', 'Error setting WIP limit'));
				}
			});
		});
	};

	KZ.updateWipDisplay = function() {
		var limits = KZ.config.wip_limits || {};
		document.querySelectorAll('.kanbanz-column').forEach(function(col) {
			var status = col.dataset.status;
			if (!status) return;
			var countEl = col.querySelector('.kanbanz-column__count');
			if (!countEl) return;
			var count = parseInt(countEl.dataset.count, 10) || 0;
			var limit = limits[status] || 0;
			countEl.dataset.wipLimit = limit;
			if (limit > 0) {
				countEl.textContent = count + '/' + limit;
				if (count > limit) {
					col.classList.add('kanbanz-column--wip-exceeded');
				} else {
					col.classList.remove('kanbanz-column--wip-exceeded');
				}
			} else {
				countEl.textContent = count;
				col.classList.remove('kanbanz-column--wip-exceeded');
			}
		});
	};

	// --- Board Settings ---
	KZ.showBoardSettings = function() {
		var settings = document.getElementById('kanbanz-settings');
		var btn = document.getElementById('kanbanz-board-settings-btn');
		if (settings) {
			settings.style.display = '';
			settings.classList.add('kanbanz-settings--open');
			if (btn) btn.setAttribute('aria-expanded', 'true');
			if (KZ._settingsTab === 'activity') KZ.loadBoardActivity();
			var drawer = settings.querySelector('.kanbanz-settings__drawer');
			KZ.FocusTrap.trap(drawer, { initialFocus: document.getElementById('kanbanz-settings-close') });
		}
	};
	KZ.hideBoardSettings = function() {
		var settings = document.getElementById('kanbanz-settings');
		var btn = document.getElementById('kanbanz-board-settings-btn');
		if (settings) {
			settings.style.display = 'none';
			settings.classList.remove('kanbanz-settings--open');
			if (btn) btn.setAttribute('aria-expanded', 'false');
			KZ.FocusTrap.release();
		}
	};

	KZ.timeAgo = function(timeVal) {
		if (!timeVal) return '';
		var date;
		if (typeof timeVal === 'number' || (!isNaN(timeVal) && !isNaN(parseFloat(timeVal)))) {
			date = new Date(parseInt(timeVal, 10) * 1000);
		} else {
			date = new Date(timeVal);
		}
		if (isNaN(date.getTime())) return String(timeVal);

		var seconds = Math.floor((new Date() - date) / 1000);
		if (seconds < 0) seconds = 0;
		if (seconds < 60) return t('justNow', 'Just now');
		var minutes = Math.floor(seconds / 60);
		if (minutes < 60) return minutes + 'm ' + t('ago', 'ago');
		var hours = Math.floor(minutes / 60);
		if (hours < 24) return hours + 'h ' + t('ago', 'ago');
		var days = Math.floor(hours / 24);
		if (days < 7) return days + 'd ' + t('ago', 'ago');

		return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	};

	KZ._settingsTab = 'settings';
	KZ.showSettingsTab = function(name) {
		KZ._settingsTab = name;
		$$('.kanbanz-settings__tab').forEach(function(tab) {
			var active = tab.dataset.tab === name;
			tab.classList.toggle('kanbanz-settings__tab--active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
			tab.setAttribute('tabindex', active ? '0' : '-1');
		});
		var settingsPanel = document.getElementById('kanbanz-settings-body');
		var activityPanel = document.getElementById('kanbanz-settings-activity-panel');
		if (settingsPanel) settingsPanel.hidden = name !== 'settings';
		if (activityPanel) activityPanel.hidden = name !== 'activity';
		if (name === 'activity') KZ.loadBoardActivity();
	};

	KZ._activity = [];
	KZ._activityShown = 0;
	KZ.loadBoardActivity = function(done) {
		var container = document.getElementById('kanbanz-settings-activity');
		if (container && !KZ._activity.length) {
			container.innerHTML = '<div class="kanbanz-skeleton" style="width:80%;"></div><div class="kanbanz-skeleton" style="width:60%;"></div><div class="kanbanz-skeleton" style="width:70%;"></div>';
		}
		ajax('kanbanz_get_activity', { id: KZ.config.board_id, board_type: 'board' }, function(res) {
			KZ._activity = (res.success && Array.isArray(res.data)) ? res.data : [];
			KZ.renderBoardActivity(20);
			if (typeof done === 'function') done();
		});
	};

	// Day heading for an activity entry: Today / Yesterday / date
	KZ.activityDay = function(timeVal) {
		var date = (typeof timeVal === 'number' || (!isNaN(timeVal) && !isNaN(parseFloat(timeVal)))) ? new Date(parseInt(timeVal, 10) * 1000) : new Date(timeVal);
		if (isNaN(date.getTime())) return '';
		var today = new Date(); today.setHours(0, 0, 0, 0);
		var day = new Date(date.getTime()); day.setHours(0, 0, 0, 0);
		var diff = Math.round((today - day) / 86400000);
		if (diff === 0) return t('today', 'Today');
		if (diff === 1) return t('yesterday', 'Yesterday');
		return date.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
	};

	KZ.renderBoardActivity = function(count) {
		var container = document.getElementById('kanbanz-settings-activity');
		var more = document.getElementById('kanbanz-activity-more');
		if (!container) return;
		var items = KZ._activity.slice(0, count);
		KZ._activityShown = items.length;
		if (!items.length) {
			container.innerHTML = '<div class="kanbanz-settings__empty-state"><span class="dashicons dashicons-backup" aria-hidden="true"></span><p>' + escHtml(t('noActivityYet', 'No activity yet.')) + '</p></div>';
			if (more) more.style.display = 'none';
			return;
		}
		var html = '';
		var lastDay = null;
		items.forEach(function(entry) {
			var day = KZ.activityDay(entry.time);
			if (day !== lastDay) {
				html += '<h4 class="kanbanz-activity-day">' + escHtml(day) + '</h4>';
				lastDay = day;
			}
			var rawUser = entry.user || entry.username || 'User';
			var actionText = entry.action || entry.log_title || entry.message || '';
			var iconClass = 'dashicons-marker';
			var actLower = actionText.toLowerCase();
			if (actLower.indexOf('moved') !== -1) iconClass = 'dashicons-move';
			else if (actLower.indexOf('created') !== -1 || actLower.indexOf('added') !== -1) iconClass = 'dashicons-plus';
			else if (actLower.indexOf('removed') !== -1 || actLower.indexOf('deleted') !== -1) iconClass = 'dashicons-trash';
			else if (actLower.indexOf('completed') !== -1) iconClass = 'dashicons-yes-alt';
			else if (actLower.indexOf('renamed') !== -1 || actLower.indexOf('updated') !== -1 || actLower.indexOf('saved') !== -1 || actLower.indexOf('permission') !== -1) iconClass = 'dashicons-edit';

			html += '<div class="kanbanz-activity-entry">'
				+ '<div class="kanbanz-activity-entry__avatar" title="' + escAttr(rawUser) + '">' + escHtml(rawUser.substring(0, 2).toUpperCase()) + '</div>'
				+ '<div class="kanbanz-activity-entry__text">'
				+ '<div class="kanbanz-activity-entry__title"><span class="dashicons ' + iconClass + ' kanbanz-activity-entry__icon" aria-hidden="true"></span> ' + escHtml(actionText) + '</div>'
				+ '<div class="kanbanz-activity-entry__time">' + escHtml(KZ.timeAgo(entry.time)) + '</div>'
				+ '</div></div>';
		});
		container.innerHTML = html;
		if (more) more.style.display = KZ._activity.length > items.length ? '' : 'none';
	};

	// --- Task Modal ---
	KZ.openTask = function(taskId) {
		KZ.ui.activeTaskId = taskId;
		KZ.ui.panelOpen = true;
		var ws = document.getElementById('kanbanz-modal-workspace');
		if (ws) {
			ws.classList.add('kanbanz-modal-workspace--open');
			ws.setAttribute('aria-hidden', 'false');
		}
		var left = document.getElementById('kanbanz-modal-left');
		if (left) left.innerHTML = '<div class="kanbanz-panel__skeleton" aria-hidden="true"><div class="kanbanz-skeleton" style="width:60%;"></div><div class="kanbanz-skeleton" style="width:80%;"></div><div class="kanbanz-skeleton" style="width:40%;"></div></div>';
		var activity = document.getElementById('kanbanz-modal-activity');
		if (activity) activity.innerHTML = '<div class="kanbanz-panel__skeleton" aria-hidden="true"><div class="kanbanz-skeleton" style="width:70%;"></div><div class="kanbanz-skeleton" style="width:90%;"></div><div class="kanbanz-skeleton" style="width:50%;"></div></div>';
		var taskIdEl = document.getElementById('kanbanz-modal-task-id');
		if (taskIdEl) taskIdEl.textContent = '#TASK-' + taskId;
		var statusBadge = document.getElementById('kanbanz-modal-status-badge');
		var task = KZ.tasks.get(taskId);
		if (statusBadge && task) statusBadge.textContent = task.task_status || '';
		// Trap focus in the modal container
		var container = document.getElementById('kanbanz-modal-container');
		if (container) KZ.FocusTrap.trap(container, { initialFocus: document.getElementById('kanbanz-modal-close') });
		ajax('kanbanz_open_task', { task_id: taskId, board_id: KZ.config.board_id }, function(res) {
			if (res.success) {
				KZ.renderTaskPanel(res.data);
			} else {
				KZ.Toast.error(t('errorLoadingTask', 'Error loading task'));
			}
		});
		window.location.hash = 'task-' + taskId;
	};

	KZ.closePanel = function() {
		KZ.ui.panelOpen = false;
		KZ.ui.activeTaskId = null;
		var ws = document.getElementById('kanbanz-modal-workspace');
		if (ws) {
			ws.classList.remove('kanbanz-modal-workspace--open');
			ws.setAttribute('aria-hidden', 'true');
		}
		KZ.FocusTrap.release();
		window.location.hash = '';
		// Clean up assignee dropdown portal
		if (KZ._assigneeDropdownPortal) {
			KZ._assigneeDropdownPortal.style.display = 'none';
		}
		// Clean up task clipboard paste handler
		if (KZ._taskPasteHandler) {
			window.removeEventListener('paste', KZ._taskPasteHandler);
			KZ._taskPasteHandler = null;
		}
	};

	KZ.renderTaskPanel = function(data) {
		var left = document.getElementById('kanbanz-modal-left');
		if (!left) return;
		var activityEl = document.getElementById('kanbanz-modal-activity');
		var taskIdEl = document.getElementById('kanbanz-modal-task-id');
		var statusBadge = document.getElementById('kanbanz-modal-status-badge');
		if (taskIdEl) taskIdEl.textContent = '#TASK-' + data.id;
		if (statusBadge) statusBadge.textContent = data.status || '';

		var priority = data.priority || '';
		var dueDate = data.due_date || '';
		var assigned = data.assigned || [];
		var activity = data.activity || [];
		var subtasks = data.subtasks || [];
		var labels = data.labels || [];
		var attachments = data.attachments || [];

		var priorityClass = '';
		var priorityLabel = '';
		if (priority === 'urgent') { priorityClass = 'urgent'; priorityLabel = t('priorityUrgent', 'Urgent'); }
		else if (priority === 'high') { priorityClass = 'high'; priorityLabel = t('priorityHigh', 'High'); }
		else if (priority === 'medium') { priorityClass = 'medium'; priorityLabel = t('priorityMedium', 'Medium'); }
		else if (priority === 'low') { priorityClass = 'low'; priorityLabel = t('priorityLow', 'Low'); }

		var html = '';

		// Breadcrumb (if subtask with parent)
		if (data.parent_id && data.parent_id > 0) {
			html += '<div class="kanbanz-panel__breadcrumb">';
			html += '<span class="dashicons dashicons-arrow-left-alt2"></span> ';
			html += '<a href="#" class="kanbanz-panel__breadcrumb-link" data-parent-id="' + data.parent_id + '">' + escHtml(data.parent_title || 'Parent Task') + '</a>';
			html += '</div>';
		}

		// Title
		html += '<h2 class="kanbanz-panel__title" id="kanbanz-panel-title" contenteditable="true" role="textbox" aria-label="' + escAttr(t('taskTitlePlaceholder', 'Task title')) + '" data-placeholder="' + escAttr(t('taskTitlePlaceholder', 'Task title')) + '">' + escHtml(data.title || '') + '</h2>';

		// Meta bar
		html += '<div class="kanbanz-task-meta-bar"><div class="kanbanz-task-meta-bar__left">';
		if (priority) {
			html += '<span class="kanbanz-task-priority-badge kanbanz-task-priority-badge--' + escAttr(priorityClass) + '">' + escHtml(priorityLabel) + '</span>';
		}
		if (dueDate) {
			html += '<span class="kanbanz-task-due"><span class="dashicons dashicons-calendar-alt"></span> ' + escHtml(dueDate) + '</span>';
		}
		html += '</div><div class="kanbanz-task-meta-bar__right"><span class="kanbanz-task-id">#TASK-' + data.id + '</span></div></div>';

		// Linked ticket
		if (data.ticket_number) {
			html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionLinkedTicket', 'Linked Ticket')) + '</h4>';
			html += '<div class="kanbanz-task-linked-ticket">';
			html += '<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>';
			html += '<a href="' + escAttr((KZ.config.ticket_url_base || '') + encodeURIComponent(data.ticket_number)) + '" target="_blank" rel="noopener noreferrer" style="color:var(--kanbanz-accent);text-decoration:none;">' + escHtml(sprintf_like('#%s', data.ticket_number)) + '</a>';
			html += '</div></div>';
		}

		// Assignees
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionAssignees', 'Assignees')) + '</h4>';
		html += '<div class="kanbanz-task-assignees__list" id="kanbanz-panel-assignees">';
		if (assigned.length) {
			assigned.forEach(function(username) {
				var initials = username.substring(0, 2).toUpperCase();
				html += '<span class="kanbanz-avatar kanbanz-panel-assignee-chip" role="button" tabindex="0" aria-label="' + escAttr(t('removeLabel', 'Remove') + ': ' + username) + '" title="' + escAttr(username) + '" data-username="' + escAttr(username) + '" style="cursor:pointer;">' + escHtml(initials) + '</span>';
			});
		}
		html += '</div>';
		html += '<div class="kanbanz-assignee-dropdown" id="kanbanz-assignee-dropdown" style="position:relative;margin-top:8px;">';
		html += '<label for="kanbanz-assignee-search" class="screen-reader-text">' + escHtml(t('searchMembersPlaceholder', 'Search members...')) + '</label>';
		html += '<input type="text" id="kanbanz-assignee-search" class="kanbanz-input" placeholder="' + escAttr(t('searchMembersPlaceholder', 'Search members...')) + '" style="width:100%;" autocomplete="off" />';
		html += '<div class="kanbanz-assignee-dropdown__list" id="kanbanz-assignee-dropdown-list" style="display:none;"></div>';
		html += '</div></div>';

		// Description (Markdown Editor with Write/Preview & Toolbar)
		html += '<div class="kanbanz-task-section"><div class="kanbanz-task-section__header"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionDescription', 'Description')) + '</h4>';
		html += '<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--icon kanbanz-desc-edit-btn" id="kanbanz-desc-edit-btn" aria-label="' + escAttr(t('editDescription', 'Edit description')) + '" title="' + escAttr(t('editDescription', 'Edit description')) + '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>';
		html += '</div>';
		html += '<div class="kanbanz-markdown-preview" id="kanbanz-desc-view">' + (data.description ? KZ.renderMarkdown(data.description) : '<span class="kanbanz-task-description__placeholder">' + escHtml(t('addDescriptionPlaceholder', 'Add a description in Markdown...')) + '</span>') + '</div>';
		html += '<div class="kanbanz-markdown-editor" id="kanbanz-markdown-editor" style="display:none;">';
		html += '<div class="kanbanz-markdown-header">';
		html += '<div class="kanbanz-markdown-tabs">';
		html += '<button type="button" class="kanbanz-markdown-tab kanbanz-markdown-tab--active" data-tab="write">Write</button>';
		html += '<button type="button" class="kanbanz-markdown-tab" data-tab="preview">Preview</button>';
		html += '</div>';
		html += '<div class="kanbanz-markdown-toolbar">';
		html += '<button type="button" class="kanbanz-md-btn" data-format="bold" title="Bold (**text**)"><strong>B</strong></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="italic" title="Italic (*text*)"><em>I</em></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="strike" title="Strikethrough (~~text~~)"><s>S</s></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="heading" title="Heading (### text)">H</button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="code" title="Code (`code` or ```code```)">&lt;/&gt;</button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="link" title="Link ([text](url))"><span class="dashicons dashicons-admin-links" style="font-size:14px;width:14px;height:14px;"></span></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="list" title="Bullet List (- item)"><span class="dashicons dashicons-editor-ul" style="font-size:14px;width:14px;height:14px;"></span></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="checklist" title="Checklist (- [ ] task)"><span class="dashicons dashicons-forms" style="font-size:14px;width:14px;height:14px;"></span></button>';
		html += '<button type="button" class="kanbanz-md-btn" data-format="quote" title="Quote (> quote)"><span class="dashicons dashicons-editor-quote" style="font-size:14px;width:14px;height:14px;"></span></button>';
		html += '</div></div>';
		html += '<textarea class="kanbanz-markdown-textarea" id="kanbanz-desc-editor" data-task-id="' + data.id + '" placeholder="' + escAttr(t('addDescriptionPlaceholder', 'Add a detailed description in Markdown...')) + '">' + escHtml(data.description || '') + '</textarea>';
		html += '<div class="kanbanz-markdown-preview" id="kanbanz-desc-live-preview" style="display:none;"></div>';
		html += '</div>';
		html += '<div class="kanbanz-task-description__actions" id="kanbanz-desc-actions" style="display:none;gap:8px;margin-top:8px;">';
		html += '<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-desc-save">' + escHtml(t('saveBtn', 'Save')) + '</button>';
		html += '<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline" id="kanbanz-desc-cancel">' + escHtml(t('cancelBtn', 'Cancel')) + '</button>';
		html += '</div></div>';

		// Priority chips
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionPriority', 'Priority')) + '</h4><div class="kanbanz-priority-chips" id="kanbanz-priority-chips">';
		['urgent','high','medium','low'].forEach(function(p) {
			var labelKey = 'priority' + p.charAt(0).toUpperCase() + p.slice(1);
			html += '<button type="button" class="kanbanz-priority-chip kanbanz-priority-chip--' + p + (priority === p ? ' kanbanz-priority-chip--active' : '') + '" data-priority="' + p + '" data-task-id="' + data.id + '" aria-pressed="' + (priority === p ? 'true' : 'false') + '">' + escHtml(t(labelKey, p.charAt(0).toUpperCase() + p.slice(1))) + '</button>';
		});
		html += '</div></div>';

		// Due date
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionDueDate', 'Due Date')) + '</h4>';
		html += '<label for="kanbanz-panel-due-date" class="screen-reader-text">' + escHtml(t('sectionDueDate', 'Due Date')) + '</label>';
		html += '<input type="date" class="kanbanz-input" id="kanbanz-panel-due-date" value="' + escAttr(dueDate) + '" data-task-id="' + data.id + '" /></div>';

		// Labels
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionLabels', 'Labels')) + '</h4>';
		html += '<div class="kanbanz-task-labels__list" id="kanbanz-panel-labels">';
		if (labels.length) {
			labels.forEach(function(lbl, idx) {
				var bg = lbl.background || '#3a86ff';
				var text = lbl.text || '';
				var contrast = KZ.getContrastColor(bg);
				html += '<span class="kanbanz-label kanbanz-panel-label-chip" role="button" tabindex="0" aria-label="' + escAttr(t('removeLabel', 'Remove label') + ': ' + text) + '" style="background:' + escAttr(bg) + ';color:' + escAttr(contrast) + ';cursor:pointer;display:inline-flex;align-items:center;gap:4px;" data-index="' + idx + '" data-task-id="' + data.id + '">' + escHtml(text) + ' <span style="opacity:0.7;font-size:10px;" aria-hidden="true" title="' + escAttr(t('removeLabel', 'Remove label')) + '">&times;</span></span>';
			});
		}
		html += '</div>';
		html += '<div style="display:flex;align-items:center;gap:8px;margin-top:8px;">';
		html += '<label for="kanbanz-panel-label-input" class="screen-reader-text">' + escHtml(t('labelTextPlaceholder', 'Label text')) + '</label>';
		html += '<input type="text" id="kanbanz-panel-label-input" class="kanbanz-input" placeholder="' + escAttr(t('labelTextPlaceholder', 'Label text')) + '" style="flex:1;" />';
		html += '<input type="color" id="kanbanz-panel-label-color" aria-label="' + escAttr(t('labelTextPlaceholder', 'Label color')) + '" value="#3a86ff" style="width:32px;height:32px;border:none;cursor:pointer;" />';
		html += '<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-panel-label-add" data-task-id="' + data.id + '">' + escHtml(t('addBtn', 'Add')) + '</button>';
		html += '</div></div>';

		// Subtasks (with drag handles)
		var doneCount = subtasks.filter(function(s) { return s.checked || s.done; }).length;
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionSubtasks', 'Subtasks')) + ' <span class="kanbanz-task-section__count" id="kanbanz-panel-subtask-count">' + doneCount + '/' + subtasks.length + '</span></h4>';
		if (subtasks.length > 0) {
			html += '<div class="kanbanz-subtask-progress" style="height:4px;background:var(--kanbanz-surface-3);border-radius:2px;margin-bottom:var(--kanbanz-space-2);overflow:hidden;">';
			html += '<div style="height:100%;width:' + (subtasks.length > 0 ? Math.round(doneCount / subtasks.length * 100) : 0) + '%;background:var(--kanbanz-accent);border-radius:2px;transition:width var(--kanbanz-transition);"></div>';
			html += '</div>';
		}
		html += '<div class="kanbanz-subtask-list" id="kanbanz-panel-subtask-list">';
		subtasks.forEach(function(st, idx) {
			var isDone = st.checked || st.done;
			var isCpt = st.is_cpt || (st.id && typeof st.id === 'number' && st.id > 0);
			html += '<div class="kanbanz-subtask-item' + (isDone ? ' kanbanz-subtask-item--done' : '') + '" data-index="' + idx + '" data-subtask-id="' + (st.id || '') + '">';
			html += '<span class="kanbanz-subtask-item__handle" title="' + escAttr(t('dragToReorder', 'Drag to reorder')) + '">⋮⋮</span>';
			html += '<input type="checkbox"' + (isDone ? ' checked' : '') + ' class="kanbanz-panel-subtask-check" data-task-id="' + data.id + '" data-index="' + idx + '" data-subtask-id="' + (st.id || '') + '" />';
			if (isCpt && st.id) {
				html += '<span class="kanbanz-subtask-item__text kanbanz-subtask-item__text--link" data-subtask-id="' + st.id + '">' + escHtml(st.title || st.text || '') + '</span>';
			} else {
				html += '<span class="kanbanz-subtask-item__text">' + escHtml(st.title || st.text || '') + '</span>';
			}
			if (st.due_date) html += '<span class="kanbanz-subtask-item__due" style="font-size:var(--kanbanz-font-xs);color:var(--kanbanz-text-tertiary);margin-left:4px;">' + escHtml(KZ.formatDate(st.due_date)) + '</span>';
			if (st.priority && st.priority !== 'Normal' && st.priority !== '') html += '<span class="kanbanz-subtask-item__priority" style="font-size:var(--kanbanz-font-xs);padding:0 4px;border-radius:var(--kanbanz-radius-full);background:' + (st.priority === 'urgent' ? 'rgba(220,53,69,0.1)' : st.priority === 'high' ? 'rgba(245,158,11,0.1)' : 'rgba(37,99,235,0.1)') + ';color:' + (st.priority === 'urgent' ? 'var(--kanbanz-priority-urgent)' : st.priority === 'high' ? 'var(--kanbanz-priority-high)' : 'var(--kanbanz-priority-medium)') + ';">' + escHtml(st.priority) + '</span>';
			html += '<span class="kanbanz-subtask-item__delete dashicons dashicons-no-alt" data-task-id="' + data.id + '" data-index="' + idx + '" data-subtask-id="' + (st.id || '') + '" style="cursor:pointer;"></span>';
			html += '</div>';
		});
		html += '</div>';
		html += '<div style="display:flex;gap:8px;margin-top:8px;">';
		html += '<label for="kanbanz-panel-subtask-input" class="screen-reader-text">' + escHtml(t('addSubtaskPlaceholder', 'Add a subtask...')) + '</label>';
		html += '<input type="text" id="kanbanz-panel-subtask-input" class="kanbanz-input" placeholder="' + escAttr(t('addSubtaskPlaceholder', 'Add a subtask...')) + '" style="flex:1;" />';
		html += '</div></div>';

		// Attachments
		html += '<div class="kanbanz-task-section"><h4 class="kanbanz-task-section__title">' + escHtml(t('sectionAttachments', 'Attachments')) + '</h4>';
		html += '<div id="kanbanz-panel-attachment-list">';
		if (attachments.length) {
			attachments.forEach(function(att) {
				var isImage = att.type === 'image' || (att.url && att.url.match(/\.(jpeg|jpg|gif|png|webp|svg)$/i));
				var iconClass = att.type === 'pdf' ? 'dashicons-pdf' : 'dashicons-media-default';
				html += '<div class="kanbanz-attachment-item">';
				if (isImage && att.url) {
					html += '<img src="' + escAttr(att.url) + '" class="kanbanz-attachment-item__thumb" alt="' + escAttr(att.name) + '" loading="lazy" />';
				} else {
					html += '<span class="dashicons ' + iconClass + ' kanbanz-attachment-item__icon" aria-hidden="true"></span>';
				}
				html += '<span class="kanbanz-attachment-item__name"><a href="' + escAttr(att.url) + '" target="_blank" rel="noopener noreferrer" title="' + escAttr(att.name) + '">' + escHtml(att.name) + '</a></span>';
				html += '<span class="kanbanz-attachment-item__size">' + KZ.formatFileSize(att.size) + '</span>';
				html += '<button type="button" class="dashicons dashicons-no-alt kanbanz-attachment-remove" aria-label="' + escAttr(t('delete', 'Delete')) + '" title="' + escAttr(t('delete', 'Delete')) + '" data-attachment-id="' + att.id + '" data-task-id="' + data.id + '" style="cursor:pointer;color:var(--kanbanz-text-tertiary);background:none;border:none;padding:0;"></button>';
				html += '</div>';
			});
		}
		html += '</div>';
		html += '<div class="kanbanz-attachment-dropzone" id="kanbanz-attachment-dropzone" role="button" tabindex="0" aria-label="' + escAttr(t('dropFilesHere', 'Drop files here or click to upload')) + '">';
		html += '<span class="dashicons dashicons-upload kanbanz-attachment-dropzone__icon" aria-hidden="true"></span>';
		html += '<div class="kanbanz-attachment-dropzone__text"><strong>' + escHtml(t('clickToUpload', 'Click to upload')) + '</strong> ' + escHtml(t('orDragAndDrop', 'or drag and drop files here')) + '</div>';
		html += '<div class="kanbanz-attachment-dropzone__hint">' + escHtml(t('pasteHint', 'Supports images (paste via Ctrl+V), PDFs, documents, archives')) + '</div>';
		html += '</div>';
		html += '<input type="file" id="kanbanz-panel-file-input" multiple style="display:none;" /></div>';

		left.innerHTML = html;
		KZ.bindPanelEvents(data);
		KZ.loadAssigneeOptions(data);

		// Render activity timeline in right column
		if (activityEl) {
			activityEl.innerHTML = KZ.renderActivityTimeline(data);
			KZ.bindActivityEvents(data);
		}
	};

	KZ.loadAssigneeOptions = function(data) {
		ajax('kanbanz_get_assignable_users', { board_id: KZ.config.board_id || 0 }, function(res) {
			if (!res.success) return;
			var users = res.data.users || [];
			KZ._assignableUsers = users;
			KZ._assignedUsers = data.assigned || [];
			var portal = KZ._assigneeDropdownPortal;
			if (portal) portal.style.display = 'none';
			var searchInput = document.getElementById('kanbanz-assignee-search');
			if (searchInput) {
				searchInput.addEventListener('input', function() {
					KZ.renderAssigneeDropdown(this.value, data.id);
				});
				searchInput.addEventListener('focus', function() {
					KZ.renderAssigneeDropdown(this.value, data.id);
				});
				searchInput.addEventListener('blur', function() {
					setTimeout(function() { KZ.hideAssigneeDropdown(); }, 200);
				});
			}
			// Re-attach scroll listeners for the modal containers
			KZ._attachDropdownScrollListeners();
		});
	};

	KZ._scrollListenerAttached = false;

	KZ._attachDropdownScrollListeners = function() {
		if (KZ._assigneeScrollHandler && !KZ._scrollListenerAttached) {
			window.addEventListener('resize', KZ._assigneeScrollHandler);
			KZ._scrollListenerAttached = true;
		}
		// Always re-attach scroll on modal containers (they get recreated)
		if (KZ._assigneeScrollHandler) {
			document.querySelectorAll('.kanbanz-modal-workspace__left, .kanbanz-modal-workspace__body').forEach(function(el) {
				el.removeEventListener('scroll', KZ._assigneeScrollHandler);
				el.addEventListener('scroll', KZ._assigneeScrollHandler);
			});
		}
	};

	KZ._assigneeDropdownPortal = null;
	KZ._assigneeScrollHandler = null;

	KZ.hideAssigneeDropdown = function() {
		var portal = KZ._assigneeDropdownPortal;
		if (portal) {
			portal.style.display = 'none';
		}
	};

	KZ.positionAssigneeDropdown = function() {
		var searchInput = document.getElementById('kanbanz-assignee-search');
		var portal = KZ._assigneeDropdownPortal;
		if (!searchInput || !portal) return;
		var rect = searchInput.getBoundingClientRect();
		portal.style.top = (rect.bottom + 2) + 'px';
		portal.style.left = rect.left + 'px';
		portal.style.width = Math.max(rect.width, 260) + 'px';
		portal.style.zIndex = '1000000';
	};

	KZ.renderAssigneeDropdown = function(query, taskId) {
		var searchInput = document.getElementById('kanbanz-assignee-search');
		if (!searchInput) return;

		var userListId = 'kanbanz-assignee-dropdown-list';
		var listEl = document.getElementById(userListId);
		var users = KZ._assignableUsers || [];
		var assigned = KZ._assignedUsers || [];
		var q = (query || '').toLowerCase().trim();
		var html = '';
		var shown = 0;
		users.forEach(function(u) {
			if (assigned.indexOf(u.username) > -1) return;
			var label = (u.display_name || u.username).toLowerCase();
			if (q && label.indexOf(q) === -1 && u.username.toLowerCase().indexOf(q) === -1) return;
			if (shown >= 10) return;
			shown++;
			var initials = u.initials || u.username.substring(0, 2).toUpperCase();
			var roleLabel = u.role_label || (u.role && u.role !== 'staff' ? u.role : '');
			var roleClass = 'role--' + (u.role || 'staff');
			var roleBadge = roleLabel ? '<span class="kanbanz-assignee-dropdown__role ' + escAttr(roleClass) + '">' + escHtml(roleLabel) + '</span>' : '';
			var avatarHtml = u.avatar_url
				? '<img src="' + escAttr(u.avatar_url) + '" class="kanbanz-assignee-dropdown__avatar" alt="" />'
				: '<span class="kanbanz-assignee-dropdown__initials">' + escHtml(initials) + '</span>';
			html += '<div class="kanbanz-assignee-dropdown__item" data-username="' + escAttr(u.username) + '" data-task-id="' + taskId + '">';
			html += avatarHtml;
			html += '<div class="kanbanz-assignee-dropdown__info"><span class="kanbanz-assignee-dropdown__name">' + escHtml(u.display_name || u.username) + '</span>';
			html += '<span class="kanbanz-assignee-dropdown__username">@' + escHtml(u.username) + '</span></div>';
			html += roleBadge;
			html += '</div>';
		});
		if (!html) {
			html = '<div class="kanbanz-assignee-dropdown__empty">' + escHtml(t('noMembersFound', 'No members found')) + '</div>';
		}

		// Create or reuse floating portal appended to <body>
		var portal = KZ._assigneeDropdownPortal;
		if (!portal) {
			portal = document.createElement('div');
			portal.className = 'kanbanz-assignee-dropdown__list kanbanz-assignee-dropdown__list--floating';
			portal.id = 'kanbanz-assignee-dropdown-portal';
			document.body.appendChild(portal);

			// Close on outside click
			document.addEventListener('click', function(e) {
				var currentSearchInput = document.getElementById('kanbanz-assignee-search');
				if (!portal.contains(e.target) && e.target !== currentSearchInput) {
					portal.style.display = 'none';
				}
			});
			KZ._assigneeDropdownPortal = portal;

			// Reposition on scroll/resize
			KZ._assigneeScrollHandler = function() {
				if (portal.style.display !== 'none') {
					KZ.positionAssigneeDropdown();
				}
			};
		}

		portal.innerHTML = html;
		portal.style.display = (shown > 0 || q) ? '' : 'none';

		if (portal.style.display !== 'none') {
			KZ.positionAssigneeDropdown();
		}

		portal.querySelectorAll('.kanbanz-assignee-dropdown__item').forEach(function(item) {
			item.addEventListener('mousedown', function(e) {
				e.preventDefault(); // Prevent blur from hiding dropdown before click registers
				var username = this.dataset.username;
				var tid = this.dataset.taskId;
				portal.style.display = 'none';
				ajax('kanbanz_assign_staff', { task_id: tid, slug: username }, function(res) {
					if (res.success) {
						KZ.Toast.success(t('memberAssigned', 'Member assigned'));
						KZ.openTask(parseInt(tid));
						KZ.rerenderCard(parseInt(tid), { assigned: res.data || [] });
					} else {
						KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorAssigning', 'Error assigning'));
					}
				});
			});
		});

		// Also keep the local listEl in sync (hidden, for reference)
		if (listEl) listEl.style.display = 'none';
	};

	KZ.formatFileSize = function(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
	};

	KZ.formatRelativeTime = function(timestamp) {
		if (!timestamp) return '';
		var now = Math.floor(Date.now() / 1000);
		var diff = now - timestamp;
		if (diff < 60) return t('justNow', 'just now');
		if (diff < 3600) return Math.floor(diff / 60) + ' ' + t('minutesAgo', 'm ago');
		if (diff < 86400) return Math.floor(diff / 3600) + ' ' + t('hoursAgo', 'h ago');
		if (diff < 604800) return Math.floor(diff / 86400) + ' ' + t('daysAgo', 'd ago');
		return new Date(timestamp * 1000).toLocaleDateString();
	};

	KZ.renderActivityTimeline = function(data) {
		var activity = data.activity || [];
		var html = '';
		html += '<h3 class="kanbanz-activity__heading">' + escHtml(t('activityHeading', 'Activity')) + '</h3>';
		html += '<div class="kanbanz-activity__filters" role="group" aria-label="' + escAttr(t('activityHeading', 'Activity filters')) + '">';
		[
			{ key: 'filterAll', fallback: 'All', val: 'all' },
			{ key: 'filterComments', fallback: 'Comments', val: 'comments' },
			{ key: 'filterWorkflow', fallback: 'Workflow', val: 'workflow' },
			{ key: 'filterSystem', fallback: 'System', val: 'system' }
		].forEach(function(f, idx) {
			html += '<button type="button" class="kanbanz-activity__filter-btn' + (idx === 0 ? ' kanbanz-activity__filter-btn--active' : '') + '" data-filter="' + f.val + '" aria-pressed="' + (idx === 0 ? 'true' : 'false') + '">' + escHtml(t(f.key, f.fallback)) + '</button>';
		});
		html += '</div>';
		html += '<div class="kanbanz-activity__timeline" id="kanbanz-activity-timeline">';
		if (activity.length === 0) {
			html += '<div style="text-align:center;color:var(--kanbanz-text-tertiary);padding:var(--kanbanz-space-4);">' + escHtml(t('noActivityYet', 'No activity yet')) + '</div>';
		}
		activity.forEach(function(entry) {
			var eventType = entry.type || 'system';
			var icon = 'dashicons-info';
			var iconClass = 'kanbanz-activity__entry-icon--system';
			var actionLower = (entry.action || '').toLowerCase();
			// If the server provided a type, trust it; otherwise infer from the action text
			if (!entry.type) {
				if (/comment/i.test(actionLower)) { eventType = 'comment'; }
				else if (/moved|changed|status|priority|due date|label/i.test(actionLower)) { eventType = 'workflow'; }
				else if (/assign|unassign/i.test(actionLower)) { eventType = 'workflow'; }
				else if (/creat/i.test(actionLower)) { eventType = 'system'; }
			}
			// Pick icon based on final eventType
			if (eventType === 'comment') { icon = 'dashicons-format-chat'; iconClass = 'kanbanz-activity__entry-icon--comment'; }
			else if (eventType === 'workflow' && /assign|unassign/i.test(actionLower)) { icon = 'dashicons-admin-users'; iconClass = 'kanbanz-activity__entry-icon--assign'; }
			else if (eventType === 'workflow') { icon = 'dashicons-randomize'; iconClass = 'kanbanz-activity__entry-icon--status'; }
			else if (eventType === 'system' && /creat/i.test(actionLower)) { icon = 'dashicons-plus-alt2'; iconClass = ''; }
			var initials = (entry.user || '?').substring(0, 2).toUpperCase();
			var time = entry.time ? KZ.formatRelativeTime(entry.time) : '';
			var exactTime = entry.time ? new Date(entry.time * 1000).toLocaleString() : '';
			html += '<div class="kanbanz-activity__entry" data-event-type="' + eventType + '">';
			html += '<div class="kanbanz-activity__entry-icon ' + escAttr(iconClass) + '"><span class="dashicons ' + icon + '"></span></div>';
			html += '<div class="kanbanz-activity__avatar">' + escHtml(initials) + '</div>';
			html += '<div class="kanbanz-activity__content">';
			html += '<span class="kanbanz-activity__user">' + escHtml(entry.user || '') + '</span> ';
			html += '<span class="kanbanz-activity__action">' + escHtml(entry.action || '') + '</span>';
			if (entry.comment) html += '<div class="kanbanz-activity__comment">' + KZ.highlightMentions(escHtml(entry.comment)) + '</div>';
			html += '<span class="kanbanz-activity__time" title="' + escAttr(exactTime) + '">' + escHtml(time) + '</span>';
			html += '</div></div>';
		});
		html += '</div>';
		// Comment input
		html += '<div class="kanbanz-activity__comment-input">';
		html += '<label for="kanbanz-modal-comment-input" class="screen-reader-text">' + escHtml(t('addCommentPlaceholder', 'Add a comment...')) + '</label>';
		html += '<textarea id="kanbanz-modal-comment-input" class="kanbanz-textarea" placeholder="' + escAttr(t('addCommentPlaceholder', 'Add a comment...')) + '" rows="2" data-task-id="' + data.id + '"></textarea>';
		html += '<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary kanbanz-modal-comment-btn" data-task-id="' + data.id + '" style="display:none;margin-top:4px;">' + escHtml(t('commentBtn', 'Comment')) + '</button>';
		html += '</div>';
		return html;
	};

	KZ.bindActivityEvents = function(data) {
		// Filter buttons
		var timeline = document.getElementById('kanbanz-activity-timeline');
		var filterBtns = $$('.kanbanz-activity__filter-btn', document.getElementById('kanbanz-modal-activity'));
		filterBtns.forEach(function(btn) {
			btn.addEventListener('click', function() {
				filterBtns.forEach(function(b) { b.classList.remove('kanbanz-activity__filter-btn--active'); b.setAttribute('aria-pressed', 'false'); });
				this.classList.add('kanbanz-activity__filter-btn--active');
				this.setAttribute('aria-pressed', 'true');
				var filter = this.dataset.filter;
				var entries = timeline ? $$('.kanbanz-activity__entry', timeline) : [];
				entries.forEach(function(entry) {
					if (filter === 'all') { entry.style.display = ''; }
					else if (filter === 'comments' && entry.dataset.eventType === 'comment') { entry.style.display = ''; }
					else if (filter === 'workflow' && entry.dataset.eventType === 'workflow') { entry.style.display = ''; }
					else if (filter === 'system' && entry.dataset.eventType === 'system') { entry.style.display = ''; }
					else { entry.style.display = 'none'; }
				});
			});
		});
		 // Comment input with @mention autocomplete
		var commentInput = document.getElementById('kanbanz-modal-comment-input');
		var commentBtn = document.querySelector('.kanbanz-modal-comment-btn');
		if (commentInput && commentBtn) {
			commentInput.addEventListener('input', function() { commentBtn.style.display = this.value.trim() ? '' : 'none'; });
			commentBtn.addEventListener('click', function() {
				var taskId = this.dataset.taskId;
				var comment = commentInput.value.trim();
				if (!comment) return;
				ajax('kanbanz_save_comment', { task_id: taskId, comment: comment, post_id: 0 }, function(res) {
					if (res.success) {
						commentInput.value = '';
						commentBtn.style.display = 'none';
						KZ.Toast.success(t('commentAdded', 'Comment added'));
						KZ.openTask(parseInt(taskId));
					} else {
						KZ.Toast.error(t('errorAddingComment', 'Error adding comment'));
					}
				});
			});
			// @mention autocomplete
			var mentionDropdown = null;
			var mentionIndex = -1;
			commentInput.addEventListener('input', function() {
				var val = this.value;
				var atPos = val.lastIndexOf('@');
				if (atPos === -1 || (atPos > 0 && val[atPos - 1] !== ' ' && val[atPos - 1] !== '\n')) {
					if (mentionDropdown) { mentionDropdown.remove(); mentionDropdown = null; }
					return;
				}
				var query = val.substring(atPos + 1);
				if (query.indexOf(' ') > -1 || query.indexOf('\n') > -1) {
					if (mentionDropdown) { mentionDropdown.remove(); mentionDropdown = null; }
					return;
				}
				var q = query.toLowerCase();
				ajax('kanbanz_get_assignable_users', { board_id: KZ.config.board_id || 0 }, function(res) {
					if (!res.success || !res.data.users) return;
					if (mentionDropdown) { mentionDropdown.remove(); mentionDropdown = null; }
					var users = res.data.users.filter(function(u) {
						return u.display_name.toLowerCase().indexOf(q) > -1 || u.username.toLowerCase().indexOf(q) > -1;
					}).slice(0, 5);
					if (!users.length) return;
					mentionDropdown = document.createElement('div');
					mentionDropdown.className = 'kanbanz-mention-dropdown';
					mentionIndex = -1;
					users.forEach(function(u, idx) {
						var item = document.createElement('div');
						item.className = 'kanbanz-mention-item';
						item.innerHTML = '<span class="kanbanz-mention-item__avatar" style="background:' + (u.role === 'admin' ? 'var(--kanbanz-danger)' : u.role === 'manager' ? 'var(--kanbanz-success)' : 'var(--kanbanz-accent)') + '">' + escHtml(u.initials) + '</span><span class="kanbanz-mention-item__name">' + escHtml(u.display_name) + '</span><span class="kanbanz-mention-item__role">@' + escHtml(u.username) + '</span>';
						item.addEventListener('click', function() {
							var inputVal = commentInput.value;
							var before = inputVal.substring(0, atPos);
							commentInput.value = before + '@' + u.username + ' ' + inputVal.substring(atPos + 1 + query.length);
							commentInput.focus();
							if (mentionDropdown) { mentionDropdown.remove(); mentionDropdown = null; }
						});
						mentionDropdown.appendChild(item);
					});
					var rect = commentInput.getBoundingClientRect();
					mentionDropdown.style.position = 'fixed';
					mentionDropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
					mentionDropdown.style.left = rect.left + 'px';
					mentionDropdown.style.width = Math.min(rect.width, 300) + 'px';
					document.body.appendChild(mentionDropdown);
				});
			});
			commentInput.addEventListener('keydown', function(e) {
				if (!mentionDropdown) return;
				var items = mentionDropdown.querySelectorAll('.kanbanz-mention-item');
				if (e.key === 'ArrowDown') {
					e.preventDefault();
					mentionIndex = Math.min(mentionIndex + 1, items.length - 1);
					items.forEach(function(i) { i.classList.remove('kanbanz-mention-item--active'); });
					if (items[mentionIndex]) items[mentionIndex].classList.add('kanbanz-mention-item--active');
				} else if (e.key === 'ArrowUp') {
					e.preventDefault();
					mentionIndex = Math.max(mentionIndex - 1, 0);
					items.forEach(function(i) { i.classList.remove('kanbanz-mention-item--active'); });
					if (items[mentionIndex]) items[mentionIndex].classList.add('kanbanz-mention-item--active');
				} else if (e.key === 'Enter' && mentionIndex >= 0 && items[mentionIndex]) {
					e.preventDefault();
					items[mentionIndex].click();
				} else if (e.key === 'Escape') {
					mentionDropdown.remove();
					mentionDropdown = null;
				}
			});
		}
	};

	// Keep a board card in sync after the task's meta (labels, assignees) changes in the panel
	KZ.rerenderCard = function(taskId, meta) {
		var task = KZ.tasks.get(taskId);
		if (!task) return;
		task.task_meta = Object.assign(task.task_meta || {}, meta || {});
		var card = document.querySelector('.kanbanz-card[data-task-id="' + taskId + '"]');
		if (card) card.replaceWith(KZ.renderCard(task));
	};
	KZ.setTaskLabels = function(taskId, labels) {
		KZ.rerenderCard(taskId, { labels: labels || [] });
	};

	KZ.bindPanelEvents = function(data) {
		// Breadcrumb — navigate to parent task
		var breadcrumbLink = document.querySelector('.kanbanz-panel__breadcrumb-link');
		if (breadcrumbLink) {
			breadcrumbLink.addEventListener('click', function(e) {
				e.preventDefault();
				var parentId = parseInt(this.dataset.parentId);
				if (parentId) KZ.openTask(parentId);
			});
		}

		// Panel title rename
		var panelTitle = document.getElementById('kanbanz-panel-title');
		if (panelTitle && data.id) {
			var originalTitle = panelTitle.textContent;
			panelTitle.addEventListener('blur', function() {
				var newTitle = this.textContent.trim();
				if (newTitle && newTitle !== originalTitle) {
					ajax('kanbanz_rename_task', { task_id: data.id, title: newTitle }, function(res) {
						if (res.success) {
							originalTitle = newTitle;
							KZ.Toast.success(t('taskRenamed', 'Task renamed'));
							var card = getCard(data.id);
							if (card) {
								var cardTitle = card.querySelector('.kanbanz-card__title');
								if (cardTitle) cardTitle.textContent = newTitle;
							}
						} else {
							panelTitle.textContent = originalTitle;
							KZ.Toast.error(t('errorRenamingTask', 'Error renaming task'));
						}
					});
				} else {
					this.textContent = originalTitle;
				}
			});
			panelTitle.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
				if (e.key === 'Escape') { this.textContent = originalTitle; this.blur(); }
			});
		}

		// Assignee remove (click on avatar chip)
		var assigneeList = document.getElementById('kanbanz-panel-assignees');
		if (assigneeList) {
			assigneeList.addEventListener('click', function(e) {
				var chip = e.target.closest('.kanbanz-panel-assignee-chip');
				if (!chip) return;
				var username = chip.dataset.username;
				var taskId = data.id;
				ajax('kanbanz_assign_staff', { task_id: taskId, slug: username }, function(res) {
					if (res.success) { KZ.Toast.success(t('memberRemoved', 'Member removed')); KZ.openTask(parseInt(taskId)); KZ.rerenderCard(parseInt(taskId), { assigned: res.data || [] }); }
					else KZ.Toast.error(t('errorRemovingMember', 'Error removing member'));
				});
			});
		}

		// Label add
		var labelAdd = document.getElementById('kanbanz-panel-label-add');
		if (labelAdd) {
			labelAdd.addEventListener('click', function() {
				var taskId = this.dataset.taskId;
				var input = document.getElementById('kanbanz-panel-label-input');
				var colorInput = document.getElementById('kanbanz-panel-label-color');
				var text = input ? input.value.trim() : '';
				var color = colorInput ? colorInput.value : '#3a86ff';
				if (!text) return;
				var existingLabels = data.labels || [];
				existingLabels.push({ text: text, background: color });
				ajax('kanbanz_save_labels', { task_id: taskId, labels: JSON.stringify(existingLabels) }, function(res) {
					if (res.success) { KZ.Toast.success(t('labelAdded', 'Label added')); KZ.setTaskLabels(parseInt(taskId), res.data.labels); KZ.openTask(parseInt(taskId)); }
					else KZ.Toast.error(t('errorAddingLabel', 'Error adding label'));
				});
			});
		}

		// Label remove (click on chip)
		var labelList = document.getElementById('kanbanz-panel-labels');
		if (labelList) {
			labelList.addEventListener('click', function(e) {
				var chip = e.target.closest('.kanbanz-panel-label-chip');
				if (!chip) return;
				var index = parseInt(chip.dataset.index);
				var taskId = data.id;
				var existingLabels = data.labels || [];
				if (index >= 0 && index < existingLabels.length) {
					existingLabels.splice(index, 1);
					ajax('kanbanz_save_labels', { task_id: taskId, labels: JSON.stringify(existingLabels) }, function(res) {
						if (res.success) { KZ.Toast.success(t('labelRemoved', 'Label removed')); KZ.setTaskLabels(parseInt(taskId), res.data.labels); KZ.openTask(parseInt(taskId)); }
						else KZ.Toast.error(t('errorRemovingLabel', 'Error removing label'));
					});
				}
			});
			// Keyboard support for label remove
			labelList.addEventListener('keydown', function(e) {
				if (e.key !== 'Enter' && e.key !== ' ') return;
				var chip = e.target.closest('.kanbanz-panel-label-chip');
				if (!chip) return;
				e.preventDefault();
				chip.click();
			});
		}

		// Subtask add (CPT-based)
		var subtaskInput = document.getElementById('kanbanz-panel-subtask-input');
		if (subtaskInput) {
			subtaskInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter' && this.value.trim()) {
					var taskId = data.id;
					var title = this.value.trim();
					this.value = '';
					ajax('kanbanz_create_subtask', { parent_id: taskId, title: title, board_id: KZ.config.board_id }, function(res) {
						if (res.success) {
							KZ.Toast.success(t('subtaskAdded', 'Subtask added'));
							KZ.openTask(parseInt(taskId));
							KZ.refreshCard(parseInt(taskId));
						} else {
							KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorAddingSubtask', 'Error adding subtask'));
						}
					});
				}
			});
		}

		// Subtask checkbox toggle + delete (CPT-based)
		var subtaskList = document.getElementById('kanbanz-panel-subtask-list');
		if (subtaskList) {
			subtaskList.addEventListener('change', function(e) {
				if (!e.target.classList.contains('kanbanz-panel-subtask-check')) return;
				var taskId = data.id;
				var subtaskId = e.target.dataset.subtaskId;
				var checked = e.target.checked;
				if (!subtaskId) return;
				ajax('kanbanz_toggle_subtask', { subtask_id: subtaskId, done: checked ? 1 : 0 }, function(res) {
					if (res.success) {
						KZ.Toast.success(t('subtaskUpdated', 'Subtask updated'));
						KZ.openTask(parseInt(taskId));
						KZ.refreshCard(parseInt(taskId));
					}
				});
			});
			subtaskList.addEventListener('click', function(e) {
				// Open subtask as full task modal
				var linkEl = e.target.closest('.kanbanz-subtask-item__text--link');
				if (linkEl) {
					e.preventDefault();
					e.stopPropagation();
					var subtaskId = parseInt(linkEl.dataset.subtaskId);
					if (subtaskId) KZ.openTask(subtaskId);
					return;
				}
				// Delete subtask
				if (!e.target.classList.contains('kanbanz-subtask-item__delete')) return;
				e.stopPropagation();
				var taskId = data.id;
				var subtaskId = e.target.dataset.subtaskId;
				if (!subtaskId) return;
				KZ.confirm({
					title: t('delete', 'Delete'),
					message: t('confirmDeleteSubtask', 'Delete this subtask?'),
					confirmText: t('delete', 'Delete'),
					cancelText: t('cancel', 'Cancel'),
					danger: true
				}).then(function(result) {
					if (!result.ok) return;
					ajax('kanbanz_delete_subtask', { subtask_id: subtaskId }, function(res) {
						if (res.success) {
							KZ.Toast.success(t('subtaskDeleted', 'Subtask deleted'));
							KZ.openTask(parseInt(taskId));
							KZ.refreshCard(parseInt(taskId));
						}
					});
				});
			});

			// Subtask Sortable Drag-and-Drop Reordering
			if (typeof jQuery !== 'undefined' && typeof jQuery.fn.sortable !== 'undefined') {
				jQuery(subtaskList).sortable({
					handle: '.kanbanz-subtask-item__handle',
					placeholder: 'kanbanz-subtask-sortable-placeholder',
					cursor: 'grabbing',
					update: function() {
						var order = [];
						subtaskList.querySelectorAll('.kanbanz-subtask-item').forEach(function(item) {
							var sid = parseInt(item.dataset.subtaskId);
							if (sid) order.push(sid);
						});
						if (order.length) {
							ajax('kanbanz_reorder_subtasks', { parent_id: data.id, subtask_order: order }, function(res) {
								if (res.success) {
									KZ.Toast.success(t('orderSaved', 'Subtasks reordered'));
								}
							});
						}
					}
				});
			}
		}

		// Attachment upload helper
		var uploadFiles = function(files) {
			var taskId = data.id;
			if (!files || !files.length) return;
			var fileArr = Array.from(files);
			var totalFiles = fileArr.length;
			var completedCount = 0;
			var hadErrors = false;

			// Show uploading state in attachment list
			var attachList = document.getElementById('kanbanz-panel-attachment-list');
			var loadingEl = document.createElement('div');
			loadingEl.className = 'kanbanz-attachment-uploading';
			loadingEl.id = 'kanbanz-attachment-loading-indicator';
			loadingEl.innerHTML = '<span class="dashicons dashicons-update kanbanz-spin" aria-hidden="true"></span> ' +
								  escHtml(t('uploadingFiles', 'Uploading ' + totalFiles + ' file(s)...'));
			if (attachList) attachList.prepend(loadingEl);

			fileArr.forEach(function(file) {
				var fd = new FormData();
				fd.append('action', 'kanbanz_upload_attachment');
				fd.append('_wpnonce', KZ.config.nonce);
				fd.append('task_id', taskId);
				fd.append('attachment', file);
				fetch(KZ.config.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function(r) { return r.json(); })
				.then(function(res) {
					completedCount++;
					if (!res.success) {
						hadErrors = true;
						KZ.Toast.error((res.data && res.data.message) ? res.data.message : t('errorUploadingFile', 'Error uploading file') + ': ' + file.name);
					}
					if (completedCount === totalFiles) {
						if (!hadErrors) {
							KZ.Toast.success(totalFiles > 1 ? t('filesUploaded', 'Files uploaded successfully') : t('fileUploaded', 'File uploaded'));
						}
						KZ.openTask(parseInt(taskId));
						KZ.refreshCard(parseInt(taskId));
					}
				})
				.catch(function() {
					completedCount++;
					hadErrors = true;
					KZ.Toast.error(t('errorUploadingFile', 'Error uploading file') + ': ' + file.name);
					if (completedCount === totalFiles) {
						KZ.openTask(parseInt(taskId));
						KZ.refreshCard(parseInt(taskId));
					}
				});
			});
		};

		// WordPress Media Library Modal Integration
		KZ.openMediaUploader = function(targetTaskId) {
			targetTaskId = targetTaskId || data.id;
			if (window.wp && window.wp.media) {
				var mediaFrame = wp.media({
					title: t('attachFiles', 'Select or Upload Attachments for Task'),
					button: { text: t('attachToTask', 'Attach to Task') },
					multiple: true
				});

				mediaFrame.on('select', function() {
					var selection = mediaFrame.state().get('selection');
					var ids = [];
					selection.each(function(attachment) {
						ids.push(attachment.id);
					});

					if (ids.length > 0) {
						ajax('kanbanz_attach_media_to_task', {
							task_id: targetTaskId,
							attachment_ids: ids
						}, function(res) {
							if (res && res.success) {
								KZ.Toast.success(res.data.message || t('filesAttached', 'Files attached successfully'));
								KZ.openTask(parseInt(targetTaskId));
								KZ.refreshCard(parseInt(targetTaskId));
							} else {
								KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorAttachingFiles', 'Error attaching files'));
							}
						});
					}
				});

				mediaFrame.open();
			} else if (fileInput) {
				fileInput.click();
			}
		};

		// Attachment Dropzone & File Input
		var dropzone = document.getElementById('kanbanz-attachment-dropzone');
		var fileInput = document.getElementById('kanbanz-panel-file-input');
		if (dropzone) {
			dropzone.addEventListener('click', function(e) {
				e.preventDefault();
				KZ.openMediaUploader(data.id);
			});
			dropzone.addEventListener('keydown', function(e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					KZ.openMediaUploader(data.id);
				}
			});
			if (fileInput) {
				fileInput.addEventListener('change', function() {
					uploadFiles(this.files);
					this.value = '';
				});
			}

			// Drag & drop handlers
			['dragenter', 'dragover'].forEach(function(evt) {
				dropzone.addEventListener(evt, function(e) {
					e.preventDefault();
					e.stopPropagation();
					dropzone.classList.add('kanbanz-attachment-dropzone--active');
				});
			});
			['dragleave', 'dragend'].forEach(function(evt) {
				dropzone.addEventListener(evt, function(e) {
					e.preventDefault();
					e.stopPropagation();
					dropzone.classList.remove('kanbanz-attachment-dropzone--active');
				});
			});
			dropzone.addEventListener('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				dropzone.classList.remove('kanbanz-attachment-dropzone--active');
				if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
					uploadFiles(e.dataTransfer.files);
				}
			});
		}

		// Clipboard Paste support (Ctrl+V / Cmd+V for screenshots and images)
		var pasteHandler = function(e) {
			if (!KZ.ui.panelOpen || KZ.ui.activeTaskId !== data.id) return;
			var cData = e.clipboardData || (e.originalEvent && e.originalEvent.clipboardData);
			if (!cData) return;
			var imageFiles = [];
            
			// Check items
			if (cData.items) {
				for (var i = 0; i < cData.items.length; i++) {
					var item = cData.items[i];
					if (item.type && item.type.indexOf('image') !== -1) {
						var blob = item.getAsFile();
						if (blob) {
							var now = new Date();
							var pad = function(n) { return n < 10 ? '0' + n : n; };
							var filename = 'pasted-image-' + now.getFullYear() + pad(now.getMonth()+1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds()) + '.png';
							imageFiles.push(new File([blob], filename, { type: blob.type || 'image/png' }));
						}
					}
				}
			}
			// Check files fallback
			if (imageFiles.length === 0 && cData.files && cData.files.length) {
				for (var j = 0; j < cData.files.length; j++) {
					if (cData.files[j].type && cData.files[j].type.indexOf('image') !== -1) {
						imageFiles.push(cData.files[j]);
					}
				}
			}

			if (imageFiles.length > 0) {
				e.preventDefault();
				KZ.Toast.info(t('uploadingPastedImage', 'Uploading pasted image...'));
				uploadFiles(imageFiles);
			}
		};
		if (KZ._taskPasteHandler) {
			window.removeEventListener('paste', KZ._taskPasteHandler);
		}
		KZ._taskPasteHandler = pasteHandler;
		window.addEventListener('paste', KZ._taskPasteHandler);

		// Attachment remove
		var attachList = document.getElementById('kanbanz-panel-attachment-list');
		if (attachList) {
			attachList.addEventListener('click', function(e) {
				var removeBtn = e.target.closest('.kanbanz-attachment-remove');
				if (!removeBtn) return;
				var taskId = removeBtn.dataset.taskId;
				var attachmentId = removeBtn.dataset.attachmentId;
				ajax('kanbanz_remove_attachment', { task_id: taskId, attachment_id: attachmentId }, function(res) {
					if (res.success) { KZ.Toast.success(t('attachmentRemoved', 'Attachment removed')); KZ.openTask(parseInt(taskId)); KZ.refreshCard(parseInt(taskId)); }
					else KZ.Toast.error(t('errorRemovingAttachment', 'Error removing attachment'));
				});
			});
		}

		// Priority chips
		$$('.kanbanz-priority-chip', document.getElementById('kanbanz-modal-left')).forEach(function(chip) {
			chip.addEventListener('click', function() {
				$$('.kanbanz-priority-chip').forEach(function(c) { c.classList.remove('kanbanz-priority-chip--active'); c.setAttribute('aria-pressed', 'false'); });
				this.classList.add('kanbanz-priority-chip--active');
				this.setAttribute('aria-pressed', 'true');
				var taskId = this.dataset.taskId;
				var priority = this.dataset.priority;
				ajax('kanbanz_save_priority', { task_id: taskId, priority: priority }, function(res) {
					if (res.success) {
						KZ.Toast.success(t('priorityUpdated', 'Priority updated'));
						var task = KZ.tasks.get(parseInt(taskId));
						if (task && task.task_meta) task.task_meta.priority = priority;
						KZ.refreshCard(parseInt(taskId));
					}
				});
			});
		});

		// Due date
		var dueInput = document.getElementById('kanbanz-panel-due-date');
		if (dueInput) {
			dueInput.addEventListener('change', function() {
				var taskId = this.dataset.taskId;
				var dueDate = this.value;
				ajax('kanbanz_save_date', { task_id: taskId, due_date: dueDate }, function(res) {
					if (res.success) {
						KZ.Toast.success(t('dueDateUpdated', 'Due date updated'));
						var task = KZ.tasks.get(parseInt(taskId));
						if (task && task.task_meta) {
							task.task_meta.due_date = dueDate;
							task.task_meta.is_overdue = dueDate && new Date(dueDate + 'T23:59:59') < new Date();
						}
						KZ.refreshCard(parseInt(taskId));
					}
				});
			});
		}

		// Description: Markdown Editor with Tabs, Toolbar & Auto-Save
		var descView = document.getElementById('kanbanz-desc-view');
		var descEditorContainer = document.getElementById('kanbanz-markdown-editor');
		var descEditor = document.getElementById('kanbanz-desc-editor');
		var descLivePreview = document.getElementById('kanbanz-desc-live-preview');
		var descActions = document.getElementById('kanbanz-desc-actions');
		var descEditBtn = document.getElementById('kanbanz-desc-edit-btn');
		var descSaveBtn = document.getElementById('kanbanz-desc-save');
		var descCancelBtn = document.getElementById('kanbanz-desc-cancel');

		if (descEditBtn && descView && descEditor && descEditorContainer) {
			descEditBtn.addEventListener('click', function() {
				descView.style.display = 'none';
				descEditBtn.style.display = 'none';
				descEditorContainer.style.display = 'block';
				descActions.style.display = 'flex';
				descEditor.focus();
			});

			// Write vs Preview tabs
			descEditorContainer.querySelectorAll('.kanbanz-markdown-tab').forEach(function(tab) {
				tab.addEventListener('click', function() {
					descEditorContainer.querySelectorAll('.kanbanz-markdown-tab').forEach(function(t) {
						t.classList.remove('kanbanz-markdown-tab--active');
					});
					this.classList.add('kanbanz-markdown-tab--active');
					var mode = this.dataset.tab;
					if (mode === 'preview') {
						descEditor.style.display = 'none';
						descLivePreview.innerHTML = KZ.renderMarkdown(descEditor.value) || '<em>' + escHtml(t('noDescription', 'Nothing to preview')) + '</em>';
						descLivePreview.style.display = 'block';
					} else {
						descLivePreview.style.display = 'none';
						descEditor.style.display = 'block';
						descEditor.focus();
					}
				});
			});

			// Toolbar formatting buttons
			descEditorContainer.querySelectorAll('.kanbanz-md-btn').forEach(function(btn) {
				btn.addEventListener('click', function(e) {
					e.preventDefault();
					var format = this.dataset.format;
					var start = descEditor.selectionStart;
					var end = descEditor.selectionEnd;
					var text = descEditor.value;
					var selected = text.substring(start, end);
					var replacement = '';

					switch(format) {
						case 'bold': replacement = '**' + (selected || 'bold text') + '**'; break;
						case 'italic': replacement = '*' + (selected || 'italic text') + '*'; break;
						case 'strike': replacement = '~~' + (selected || 'strikethrough text') + '~~'; break;
						case 'heading': replacement = '### ' + (selected || 'Heading'); break;
						case 'code':
							replacement = selected.indexOf('\n') > -1 ? '```\n' + (selected || 'code block') + '\n```' : '`' + (selected || 'code') + '`';
							break;
						case 'link': replacement = '[' + (selected || 'Link text') + '](https://example.com)'; break;
						case 'list': replacement = '- ' + (selected || 'List item'); break;
						case 'checklist': replacement = '- [ ] ' + (selected || 'Task item'); break;
						case 'quote': replacement = '> ' + (selected || 'Quote text'); break;
					}

					descEditor.value = text.substring(0, start) + replacement + text.substring(end);
					descEditor.focus();
					descEditor.setSelectionRange(start + replacement.length, start + replacement.length);
				});
			});

			if (descSaveBtn) {
				descSaveBtn.addEventListener('click', function() {
					var content = descEditor.value;
					ajax('kanbanz_save_task_description', { task_id: data.id, description: content }, function(res) {
						if (res.success) {
							KZ.Toast.success(t('descriptionSaved', 'Description saved'));
							descView.innerHTML = KZ.renderMarkdown(content) || '<span class="kanbanz-task-description__placeholder">' + escHtml(t('addDescriptionPlaceholder', 'Add a description in Markdown...')) + '</span>';
							descView.style.display = 'block';
							descEditBtn.style.display = '';
							descEditorContainer.style.display = 'none';
							descActions.style.display = 'none';
							var task = KZ.tasks.get(data.id);
							if (task) task.description = content;
						}
					});
				});
			}

			if (descCancelBtn) {
				descCancelBtn.addEventListener('click', function() {
					descEditorContainer.style.display = 'none';
					descActions.style.display = 'none';
					descView.style.display = 'block';
					descEditBtn.style.display = '';
					descEditor.value = data.description || '';
				});
			}

			// Ctrl+Enter or Cmd+Enter to save
			descEditor.addEventListener('keydown', function(e) {
				if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
					e.preventDefault();
					if (descSaveBtn) descSaveBtn.click();
				}
			});
		}
	};

	// --- Markdown Parser ---
	KZ.renderMarkdown = function(md) {
		if (!md || typeof md !== 'string') return '';
		var text = md.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
		var html = escHtml(text);
		// Code blocks: ```code```
		html = html.replace(/```([a-z0-9_-]*)\n([\s\S]*?)```/g, function(match, lang, code) {
			return '<pre><code>' + code.trim() + '</code></pre>';
		});
		// Inline code: `code`
		html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
		// Headings: #, ##, ###
		html = html.replace(/^### (.*$)/gim, '<h3>$1</h3>');
		html = html.replace(/^## (.*$)/gim, '<h2>$1</h2>');
		html = html.replace(/^# (.*$)/gim, '<h1>$1</h1>');
		// Blockquotes: > quote
		html = html.replace(/^\> (.*$)/gim, '<blockquote>$1</blockquote>');
		// Bold: **text** or __text__
		html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
		// Italic: *text* or _text_
		html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
		html = html.replace(/_([^_]+)_/g, '<em>$1</em>');
		// Strikethrough: ~~text~~
		html = html.replace(/~~([^~]+)~~/g, '<del>$1</del>');
		// Checklists: - [x] task or - [ ] task
		html = html.replace(/^- \[x\] (.*$)/gim, '<ul class="kanbanz-md-checklist"><li><span class="dashicons dashicons-yes-alt" style="color:var(--kanbanz-accent,#3b82f6);font-size:16px;width:16px;height:16px;"></span> $1</li></ul>');
		html = html.replace(/^- \[ \] (.*$)/gim, '<ul class="kanbanz-md-checklist"><li><span class="dashicons dashicons-marker" style="color:var(--kanbanz-text-tertiary,#9ca3af);font-size:16px;width:16px;height:16px;"></span> $1</li></ul>');
		// Bullet lists: - item or * item
		html = html.replace(/^[*-] (.*$)/gim, '<ul><li>$1</li></ul>');
		// Clean up adjacent list closures
		html = html.replace(/<\/ul>\s*<ul[^>]*>/g, '');
		// Links: [text](https://...)
		html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
		// Newlines & paragraphs
		html = html.replace(/\n\n+/g, '<br><br>');
		html = html.replace(/\n/g, '<br>');
		return html;
	};

	// --- Bulk Task Selection State & Methods ---
	KZ.selectedTasks = new Set();
	KZ._lastSelectedTaskId = null;

	KZ.syncCardSelectionUI = function() {
		var app = document.getElementById('kanbanz-app');
		if (app) app.classList.toggle('kanbanz-app--bulk-active', KZ.selectedTasks.size > 0);
		document.querySelectorAll('.kanbanz-card').forEach(function(card) {
			var tid = parseInt(card.dataset.taskId);
			var isSel = tid && KZ.selectedTasks.has(tid);
			card.classList.toggle('kanbanz-card--selected', !!isSel);
			var cb = card.querySelector('.kanbanz-card__checkbox');
			if (cb) cb.checked = !!isSel;
		});
	};

	KZ.updateBulkBar = function() {
		var bar = document.getElementById('kanbanz-bulk-bar');
		var countEl = document.getElementById('kanbanz-bulk-count');
		if (!bar || !countEl) return;
		var count = KZ.selectedTasks.size;
		countEl.textContent = count;
		if (count > 0) {
			bar.style.display = 'flex';
		} else {
			bar.style.display = 'none';
		}
	};

	KZ.clearSelection = function() {
		KZ.selectedTasks.clear();
		KZ._lastSelectedTaskId = null;
		KZ.syncCardSelectionUI();
		KZ.updateBulkBar();
	};

	KZ.toggleTaskSelection = function(taskId, isSelected, shiftKey) {
		taskId = parseInt(taskId);
		if (!taskId) return;

		if (shiftKey && KZ._lastSelectedTaskId && KZ._lastSelectedTaskId !== taskId) {
			var allCards = Array.from(document.querySelectorAll('.kanbanz-card'));
			var idx1 = allCards.findIndex(function(c) { return parseInt(c.dataset.taskId) === KZ._lastSelectedTaskId; });
			var idx2 = allCards.findIndex(function(c) { return parseInt(c.dataset.taskId) === taskId; });
			if (idx1 !== -1 && idx2 !== -1) {
				var start = Math.min(idx1, idx2);
				var end = Math.max(idx1, idx2);
				for (var i = start; i <= end; i++) {
					var id = parseInt(allCards[i].dataset.taskId);
					if (id) KZ.selectedTasks.add(id);
				}
			}
		} else {
			if (isSelected) KZ.selectedTasks.add(taskId);
			else KZ.selectedTasks.delete(taskId);
		}

		KZ._lastSelectedTaskId = taskId;
		KZ.syncCardSelectionUI();
		KZ.updateBulkBar();
	};

	KZ.bulkAction = function(action, value) {
		var taskIds = Array.from(KZ.selectedTasks);
		if (!taskIds.length) return;

		var data = {
			task_ids: taskIds,
			bulk_action: action
		};
		if (action === 'move') data.target_status = value;
		else if (action === 'priority') data.priority = value;
		else if (action === 'assign') data.assignee = value;

		ajax('kanbanz_bulk_task_action', data, function(res) {
			if (res.success) {
				KZ.Toast.success(t('bulkActionSuccess', 'Updated ' + (res.data.affected || taskIds.length) + ' tasks'));
				KZ.clearSelection();
				window.location.reload();
			} else {
				KZ.Toast.error((res.data && res.data.message) ? res.data.message : t('errorBulkAction', 'Error updating tasks'));
			}
		});
	};

	// --- Render Card ---
	KZ.renderCard = function(task) {
		var card = document.createElement('div');
		card.className = 'kanbanz-card';
		card.dataset.taskId = task.id;
		card.setAttribute('role', 'button');
		card.setAttribute('tabindex', '0');
		card.setAttribute('aria-label', (task.title || task.name || t('taskShort', 'Task')) + ' #' + task.id);
		var meta = task.task_meta || {};
		if (meta.priority === 'urgent') card.classList.add('kanbanz-card--urgent');
		else if (meta.priority === 'high') card.classList.add('kanbanz-card--high');
		else if (meta.priority === 'medium') card.classList.add('kanbanz-card--medium');
		if (meta.is_overdue) card.classList.add('kanbanz-card--overdue');
		if (KZ.selectedTasks && KZ.selectedTasks.has(task.id)) card.classList.add('kanbanz-card--selected');

		var html = '<input type="checkbox" class="kanbanz-card__checkbox" data-task-id="' + task.id + '"' + (KZ.selectedTasks && KZ.selectedTasks.has(task.id) ? ' checked' : '') + ' aria-label="' + escAttr(t('selectTask', 'Select task')) + '" />';
		// Labels
		var labels = (task.task_meta && task.task_meta.labels && task.task_meta.labels.length) ? task.task_meta.labels
			: (KZ.config.user_meta && KZ.config.user_meta.task_labels && KZ.config.user_meta.task_labels[task.id]);
		if (labels && labels.length) {
			html += '<div class="kanbanz-card__labels">';
			labels.slice(0, 3).forEach(function(lbl) {
				var bg = lbl.background || lbl.color || '#3a86ff';
				var text = lbl.txt || lbl.text || '';
				var contrast = KZ.getContrastColor(bg);
				html += '<span class="kanbanz-label" style="background:' + escAttr(bg) + ';color:' + escAttr(contrast) + ';">' + escHtml(text) + '</span>';
			});
			html += '</div>';
		}
		html += '<div class="kanbanz-card__title">' + escHtml(task.title || task.name || '') + '</div>';
		if (meta.ticket_number) {
			html += '<a class="kanbanz-card__ticket-link" href="' + escAttr((KZ.config.ticket_url_base || '') + encodeURIComponent(meta.ticket_number)) + '" target="_blank" rel="noopener noreferrer" title="' + escAttr(t('sectionLinkedTicket', 'View ticket')) + '" aria-label="' + escAttr(t('sectionLinkedTicket', 'View ticket') + ' #' + meta.ticket_number) + '">';
			html += '<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>';
			html += '<span>#' + escHtml(meta.ticket_number) + '</span>';
			html += '</a>';
		}
		html += '<div class="kanbanz-card__meta">';
		if (meta.priority && meta.priority !== 'Normal' && meta.priority !== '') {
			html += '<span class="kanbanz-card__priority kanbanz-card__priority--' + escAttr(meta.priority.toLowerCase()) + '">' + escHtml(meta.priority) + '</span>';
		}
		if (meta.due_date) {
			var isOverdue = meta.is_overdue;
			html += '<span class="kanbanz-card__due' + (isOverdue ? ' kanbanz-card__due--overdue' : '') + '"><span class="dashicons dashicons-calendar-alt"></span> ' + escHtml(KZ.formatDate(meta.due_date)) + '</span>';
		}
		html += '</div>';

		// Avatars + indicators
		html += '<div class="kanbanz-card__footer"><div class="kanbanz-card__avatars">';
		var assigned = meta.assigned || [];
		assigned.slice(0, 3).forEach(function(username) {
			html += '<span class="kanbanz-avatar" title="' + escAttr(username) + '">' + escHtml(username.substring(0, 2).toUpperCase()) + '</span>';
		});
		if (assigned.length > 3) {
			html += '<span class="kanbanz-avatar kanbanz-avatar--more">+' + (assigned.length - 3) + '</span>';
		}
		html += '</div>';
		// Card indicators
		var indicators = [];
		if (meta.subtask_count > 0) indicators.push('<span class="kanbanz-card__indicator"><span class="dashicons dashicons-list-view"></span> ' + (meta.subtask_done || 0) + '/' + meta.subtask_count + '</span>');
		if (meta.comment_count > 0) indicators.push('<span class="kanbanz-card__indicator"><span class="dashicons dashicons-format-chat"></span> ' + meta.comment_count + '</span>');
		if (meta.attachment_count > 0) indicators.push('<span class="kanbanz-card__indicator"><span class="dashicons dashicons-paperclip"></span> ' + meta.attachment_count + '</span>');
		if (indicators.length) {
			html += '<div class="kanbanz-card__indicators">' + indicators.join('') + '</div>';
		}
		html += '</div>';

		// Subtask progress bar
		if (meta.subtask_count > 0) {
			var pct = Math.round((meta.subtask_done || 0) / meta.subtask_count * 100);
			html += '<div class="kanbanz-card__subtask-bar"><div class="kanbanz-card__subtask-bar-fill" style="width:' + pct + '%;"></div></div>';
		}

		card.innerHTML = html;
		return card;
	};

	// --- Filters ---
	KZ.isFilterActive = function() {
		var hasSearch = !!(KZ.filters.search && KZ.filters.search.trim() !== '');
		var hasMyTasks = !!KZ.filters.myTasks;
		var hasOverdue = !!KZ.filters.overdue;
		var hasPriorities = (KZ.filters.priorities && KZ.filters.priorities.size > 0) || (!!KZ.filters.priority && KZ.filters.priority !== '');
		var hasAssignee = !!(KZ.filters.assignee && parseInt(KZ.filters.assignee) > 0);
		var hasDate = (KZ.filters.datePreset && KZ.filters.datePreset !== 'all') || !!(KZ.filters.dateFrom && KZ.filters.dateFrom !== '') || !!(KZ.filters.dateTo && KZ.filters.dateTo !== '');
		return hasSearch || hasMyTasks || hasOverdue || hasPriorities || hasAssignee || hasDate;
	};

	KZ.updateFilterUIIndicators = function() {
		var isFiltered = KZ.isFilterActive();
		var clearBtn = document.getElementById('kanbanz-clear-filters-btn');
		if (clearBtn) {
			clearBtn.style.display = isFiltered ? 'inline-flex' : 'none';
		}

		// Update Date filter button active state & label
		var dateBtn = document.getElementById('kanbanz-date-filter-btn');
		if (dateBtn) {
			var hasDate = (KZ.filters.datePreset && KZ.filters.datePreset !== 'all') || !!(KZ.filters.dateFrom && KZ.filters.dateFrom !== '');
			dateBtn.classList.toggle('kanbanz-btn--filter-active', hasDate);
			var label = t('date', 'Date');
			if (KZ.filters.datePreset === 'today') label += ': ' + t('today', 'Today');
			else if (KZ.filters.datePreset === 'week') label += ': ' + t('this_week', 'Week');
			else if (KZ.filters.datePreset === 'month') label += ': ' + t('this_month', 'Month');
			else if (KZ.filters.datePreset === 'custom') label += ': ' + t('custom', 'Custom');
			dateBtn.innerHTML = '<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' + escHtml(label);
		}

		// Update Assignee filter button active state & label
		var assigneeBtn = document.getElementById('kanbanz-assignee-filter-btn');
		if (assigneeBtn) {
			var hasAssignee = !!(KZ.filters.assignee && parseInt(KZ.filters.assignee) > 0);
			assigneeBtn.classList.toggle('kanbanz-btn--filter-active', hasAssignee);
			var aLabel = t('assignee', 'Assignee');
			if (hasAssignee && KZ.filters.assigneeName) {
				aLabel += ': ' + KZ.filters.assigneeName;
			}
			assigneeBtn.innerHTML = '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span> ' + escHtml(aLabel);
		}
	};

	KZ.applyFilters = function() {
		var cards = $$('.kanbanz-card', document.getElementById('kanbanz-canvas'));
		cards.forEach(function(card) {
			var show = true;
			var taskId = parseInt(card.dataset.taskId);
			var task = KZ.tasks.get(taskId);
			if (!task) { card.style.display = ''; return; }
			var meta = task.task_meta || {};

			// Search filter
			if (KZ.filters.search) {
				var title = card.querySelector('.kanbanz-card__title');
				var text = title ? title.textContent.toLowerCase() : '';
				if (text.indexOf(KZ.filters.search.toLowerCase()) === -1) show = false;
			}

			// My Tasks filter
			if (KZ.filters.myTasks && KZ.config.current_username) {
				var assigned = meta.assigned || [];
				if (typeof assigned === 'string') assigned = [assigned];
				if (assigned.indexOf(KZ.config.current_username) === -1) show = false;
			}

			// Priority filter (multi-select Set)
			if (KZ.filters.priorities && KZ.filters.priorities.size > 0) {
				var p = (meta.priority || '').toLowerCase();
				if (!KZ.filters.priorities.has(p)) show = false;
			} else if (KZ.filters.priority && KZ.filters.priority !== '') {
				if (meta.priority !== KZ.filters.priority) show = false;
			}

			// Overdue filter
			if (KZ.filters.overdue) {
				var isOverdue = !!meta.is_overdue;
				if (!isOverdue && meta.due_date) {
					var nowStr = (new Date()).toISOString().substring(0, 10);
					if (meta.due_date < nowStr) isOverdue = true;
				}
				if (!isOverdue) show = false;
			}

			// Assignee filter
			if (KZ.filters.assignee > 0) {
				var filterStaff = null;
				(KZ.config.users_for_filter || []).forEach(function(u) {
					if (u.id == KZ.filters.assignee) filterStaff = u;
				});
				var assignedUsers = meta.assigned || [];
				if (typeof assignedUsers === 'string') assignedUsers = [assignedUsers];
				var match = false;
				if (filterStaff && assignedUsers.indexOf(filterStaff.username) !== -1) match = true;
				if (assignedUsers.indexOf(String(KZ.filters.assignee)) !== -1 || assignedUsers.indexOf(parseInt(KZ.filters.assignee)) !== -1) match = true;
				if (!match) show = false;
			}

			// Date filter (due_date / created_date)
			if (KZ.filters.dateFrom || KZ.filters.dateTo) {
				var targetDateStr = '';
				if (KZ.filters.dateTarget === 'created_date') {
					targetDateStr = task.created_date || (task.task_meta && task.task_meta.created_date) || task.date || (task.task_meta && task.task_meta.date) || (task.post_date ? task.post_date.substring(0, 10) : '');
				} else {
					targetDateStr = meta.due_date || '';
				}
				if (targetDateStr) {
					var taskDay = targetDateStr.substring(0, 10);
					if (KZ.filters.dateFrom && taskDay < KZ.filters.dateFrom) show = false;
					if (KZ.filters.dateTo && taskDay > KZ.filters.dateTo) show = false;
				} else {
					show = false;
				}
			}

			card.style.display = show ? '' : 'none';
		});

		updateAllCounts();
		KZ.updateFilterUIIndicators();

		try {
			var toSave = Object.assign({}, KZ.filters, {
				priorities: Array.from(KZ.filters.priorities || [])
			});
			localStorage.setItem('kanbanz-editor_filters_' + KZ.config.board_id, JSON.stringify(toSave));
		} catch(e) {}
	};

	KZ.loadSavedFilters = function() {
		try {
			var saved = localStorage.getItem('kanbanz-editor_filters_' + KZ.config.board_id);
			if (saved) {
				var filters = JSON.parse(saved);
				if (filters.search) KZ.filters.search = filters.search;
				if (filters.myTasks) KZ.filters.myTasks = true;
				if (filters.overdue) KZ.filters.overdue = true;
				if (Array.isArray(filters.priorities)) {
					KZ.filters.priorities = new Set(filters.priorities);
				} else if (filters.priority) {
					KZ.filters.priorities = new Set([filters.priority]);
				}
				if (filters.assignee) KZ.filters.assignee = filters.assignee;
				if (filters.assigneeName) KZ.filters.assigneeName = filters.assigneeName;
				if (filters.dateTarget) KZ.filters.dateTarget = filters.dateTarget;
				if (filters.datePreset) KZ.filters.datePreset = filters.datePreset;
				if (filters.dateFrom) KZ.filters.dateFrom = filters.dateFrom;
				if (filters.dateTo) KZ.filters.dateTo = filters.dateTo;

				// Apply UI state
				var searchInput = document.getElementById('kanbanz-search-input');
				if (searchInput && KZ.filters.search) searchInput.value = KZ.filters.search;
				var myTasksChip = document.getElementById('kanbanz-my-tasks-chip');
				if (myTasksChip && KZ.filters.myTasks) { myTasksChip.classList.add('kanbanz-chip--active'); myTasksChip.setAttribute('aria-pressed', 'true'); }
				var overdueChip = document.getElementById('kanbanz-overdue-chip');
				if (overdueChip && KZ.filters.overdue) { overdueChip.classList.add('kanbanz-chip--active'); overdueChip.setAttribute('aria-pressed', 'true'); }
                
				$$('.kanbanz-chip--priority').forEach(function(chip) {
					var p = chip.dataset.value;
					if (KZ.filters.priorities && KZ.filters.priorities.has(p)) {
						chip.classList.add('kanbanz-chip--active');
						chip.setAttribute('aria-pressed', 'true');
					}
				});

				if (KZ.filters.assignee) {
					$$('.kanbanz-dropdown__item', document.getElementById('kanbanz-assignee-filter-menu')).forEach(function(item) {
						var uid = parseInt(item.dataset.userId) || 0;
						var isActive = (uid === parseInt(KZ.filters.assignee));
						item.classList.toggle('kanbanz-dropdown__item--active', isActive);
						item.setAttribute('aria-checked', isActive ? 'true' : 'false');
					});
				}

				if (KZ.filters.datePreset) {
					$$('.kanbanz-date-preset-btn').forEach(function(b) {
						b.classList.toggle('kanbanz-date-preset-btn--active', b.dataset.preset === KZ.filters.datePreset);
					});
				}

				KZ.updateFilterUIIndicators();
			}
		} catch(e) {}
	};

	// --- Infinite Scroll & Database Search ---
	KZ.initInfiniteScroll = function() {
		var colBodies = $$('.kanbanz-column__body', document.getElementById('kanbanz-canvas'));
		colBodies.forEach(function(colBody) {
			if (colBody.dataset.scrollBound === 'true') return;
			colBody.dataset.scrollBound = 'true';

			colBody.addEventListener('scroll', function() {
				var col = this.closest('.kanbanz-column');
				if (!col) return;
				var hasMore = col.dataset.hasMore === 'true';
				var isLoading = col.dataset.loading === 'true';

				if (hasMore && !isLoading) {
					if (this.scrollTop + this.clientHeight >= this.scrollHeight - 150) {
						KZ.loadMoreColumnTasks(col);
					}
				}
			});
		});

		if (window.IntersectionObserver) {
			if (KZ._scrollObserver) {
				KZ._scrollObserver.disconnect();
			}
			KZ._scrollObserver = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					if (entry.isIntersecting) {
						var loader = entry.target;
						var col = loader.closest('.kanbanz-column');
						if (!col) return;
						var hasMore = col.dataset.hasMore === 'true';
						var isLoading = col.dataset.loading === 'true';

						if (hasMore && !isLoading) {
							KZ.loadMoreColumnTasks(col);
						}
					}
				});
			}, {
				root: null,
				rootMargin: '250px',
				threshold: 0.05
			});

			var loaders = $$('.kanbanz-column__scroll-loader', document.getElementById('kanbanz-canvas'));
			loaders.forEach(function(loader) {
				var col = loader.closest('.kanbanz-column');
				if (col && col.dataset.hasMore === 'true') {
					loader.style.display = 'block';
					KZ._scrollObserver.observe(loader);
				} else {
					loader.style.display = 'none';
				}
			});
		}
	};

	KZ.loadMoreColumnTasks = function(col) {
		var colBody = col.querySelector('.kanbanz-column__body');
		var colFooter = col.querySelector('.kanbanz-column__pagination-footer');
		var loader = col.querySelector('.kanbanz-column__scroll-loader');
		var loadBtn = col.querySelector('.kanbanz-column__load-more-btn');
		var status = col.dataset.status;
		var page = parseInt(col.dataset.page || '1', 10) + 1;

		col.dataset.loading = 'true';
		if (loader) loader.style.display = 'block';
		var prioritiesArr = Array.from(KZ.filters.priorities || []);
		var pStr = prioritiesArr.length > 0 ? prioritiesArr.join(',') : (KZ.filters.priority || '');

		var data = {
			board_id: KZ.config.board_id,
			status: status,
			page: page,
			per_page: 50,
			search: KZ.filters.search,
			priority: pStr,
			overdue: KZ.filters.overdue ? '1' : '',
			assignee: KZ.filters.assignee || '',
			date_type: KZ.filters.dateTarget || 'due_date',
			date_from: KZ.filters.dateFrom || '',
			date_to: KZ.filters.dateTo || ''
		};

		ajax('kanbanz_load_more_tasks', data, function(res) {
			if (loader) loader.style.display = 'none';
			if (loadBtn) loadBtn.style.display = '';
			col.dataset.loading = 'false';

			if (res && res.success && res.data) {
				col.dataset.page = res.data.page;
				col.dataset.hasMore = res.data.has_more ? 'true' : 'false';
				col.dataset.total = res.data.total;

				if (res.data.tasks && res.data.tasks.length > 0) {
					res.data.tasks.forEach(function(t) {
						KZ.tasks.set(t.id, t);
					});
				}

				if (res.data.html && colBody) {
					var temp = document.createElement('div');
					temp.innerHTML = res.data.html;
					var newCards = Array.from(temp.children);
					var insertTarget = colFooter || loader;
					newCards.forEach(function(card) {
						if (insertTarget) colBody.insertBefore(card, insertTarget);
						else colBody.appendChild(card);
					});

					var currentLoaded = colBody.querySelectorAll('.kanbanz-card').length;
					col.dataset.loaded = currentLoaded;

					var countSpan = col.querySelector('.kanbanz-column__count');
					var wipLimit = parseInt(countSpan ? countSpan.dataset.wipLimit : '0', 10);
					if (countSpan) {
						if (wipLimit > 0) {
							countSpan.textContent = res.data.total + '/' + wipLimit;
						} else if (currentLoaded < res.data.total) {
							countSpan.textContent = currentLoaded + '/' + res.data.total;
						} else {
							countSpan.textContent = res.data.total;
						}
					}

					if (window.jQuery && window.jQuery(colBody).data('ui-sortable')) {
						window.jQuery(colBody).sortable('refresh');
					}
				}
				if (colFooter) colFooter.style.display = res.data.has_more ? 'block' : 'none';
				KZ.applyFilters();
				KZ.initInfiniteScroll();
			}
		});
	};

	KZ.performDBSearch = function() {
		var searchInput = document.getElementById('kanbanz-search-input');
		var query = searchInput ? searchInput.value.trim() : '';
		KZ.filters.search = query;

		var prioritiesArr = Array.from(KZ.filters.priorities || []);
		var pStr = prioritiesArr.length > 0 ? prioritiesArr.join(',') : (KZ.filters.priority || '');

		var data = {
			board_id: KZ.config.board_id,
			search: query,
			priority: pStr,
			overdue: KZ.filters.overdue ? '1' : '',
			assignee: KZ.filters.assignee || '',
			date_type: KZ.filters.dateTarget || 'due_date',
			date_from: KZ.filters.dateFrom || '',
			date_to: KZ.filters.dateTo || ''
		};

		KZ.updateFilterUIIndicators();

		ajax('kanbanz_search_board_tasks', data, function(res) {
			if (res && res.success && res.data && res.data.statuses) {
				var statusesData = res.data.statuses;
				$$('.kanbanz-column', document.getElementById('kanbanz-canvas')).forEach(function(col) {
					var status = col.dataset.status;
					var colBody = col.querySelector('.kanbanz-column__body');
					var loader = col.querySelector('.kanbanz-column__scroll-loader');
					var stData = statusesData[status];

					if (stData && colBody) {
						col.querySelectorAll('.kanbanz-card').forEach(function(c) { c.remove(); });
						col.dataset.page = '1';
						col.dataset.hasMore = stData.has_more ? 'true' : 'false';
						col.dataset.total = stData.total;

						if (stData.tasks && stData.tasks.length > 0) {
							stData.tasks.forEach(function(t) { KZ.tasks.set(t.id, t); });
						}

						var colFooter = col.querySelector('.kanbanz-column__pagination-footer');
						if (stData.html) {
							var temp = document.createElement('div');
							temp.innerHTML = stData.html;
							var newCards = Array.from(temp.children);
							newCards.forEach(function(card) {
								if (colFooter) colBody.insertBefore(card, colFooter);
								else colBody.appendChild(card);
							});
						}
						if (colFooter) colFooter.style.display = stData.has_more ? 'block' : 'none';

						var loadedCount = colBody.querySelectorAll('.kanbanz-card').length;
						col.dataset.loaded = loadedCount;

						var countSpan = col.querySelector('.kanbanz-column__count');
						var wipLimit = parseInt(countSpan ? countSpan.dataset.wipLimit : '0', 10);
						if (countSpan) {
							if (wipLimit > 0) {
								countSpan.textContent = stData.total + '/' + wipLimit;
							} else if (loadedCount < stData.total) {
								countSpan.textContent = loadedCount + '/' + stData.total;
							} else {
								countSpan.textContent = stData.total;
							}
						}

						if (window.jQuery && window.jQuery(colBody).data('ui-sortable')) {
							window.jQuery(colBody).sortable('refresh');
						}
					}
				});
				KZ.applyFilters();
				KZ.initInfiniteScroll();
			}
		});
	};

	// --- Theme ---
	KZ.applyTheme = function(theme) {
		KZ.ui.theme = theme;
		var wrapper = document.getElementById('kanbanz-app');
		if (wrapper) wrapper.dataset.theme = theme;
		var themeBtn = document.getElementById('kanbanz-theme-toggle');
		if (themeBtn) {
			var icon = themeBtn.querySelector('.dashicons');
			// Dark = show moon (indicates "currently dark"); Light = show lightbulb (indicates "currently light")
			if (icon) icon.className = 'dashicons ' + (theme === 'dark' ? 'dashicons-star-filled' : 'dashicons-lightbulb');
			themeBtn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
			themeBtn.setAttribute('aria-label', theme === 'dark' ? t('cmdToggleTheme', 'Switch to light theme') : t('cmdToggleTheme', 'Switch to dark theme'));
		}
	};

	KZ.toggleTheme = function() {
		// Delegate to the shared theme controller if available (keeps all screens in sync)
		if (window.kanbanzTheme && typeof window.kanbanzTheme.toggle === 'function') {
			window.kanbanzTheme.toggle();
			// Sync local ui state with the applied theme
			var applied = window.kanbanzTheme.current();
			KZ.ui.theme = applied;
			ajax('kanbanz_save_theme', { theme: applied }, function() {});
			return;
		}
		KZ.ui.theme = KZ.ui.theme === 'dark' ? 'light' : 'dark';
		KZ.applyTheme(KZ.ui.theme);
		try { localStorage.setItem('kanbanz_theme', KZ.ui.theme); } catch(e) {}
		ajax('kanbanz_save_theme', { theme: KZ.ui.theme }, function() {});
	};

	// --- Layout ---
	KZ.setLayout = function(layout) {
		KZ.ui.layout = layout;
		var wrapper = document.getElementById('kanbanz-app');
		if (wrapper) wrapper.dataset.layout = layout;
		$$('.kanbanz-toggle-btn', document.getElementById('kanbanz-layout-toggle')).forEach(function(btn) {
			var active = btn.dataset.layout === layout;
			btn.classList.toggle('kanbanz-toggle-btn--active', active);
			btn.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
		ajax('kanbanz_board_layout', { board_id: KZ.config.board_id, layout: layout }, function() {});
	};

	// --- Command Palette ---
	KZ.openCmdPalette = function() {
		KZ.ui.commandOpen = true;
		var palette = document.getElementById('kanbanz-cmd-palette');
		var btn = document.getElementById('kanbanz-cmd-palette-btn');
		if (palette) {
			palette.style.display = '';
			palette.setAttribute('aria-hidden', 'false');
		}
		if (btn) btn.setAttribute('aria-expanded', 'true');
		var input = document.getElementById('kanbanz-cmd-input');
		KZ.updateCmdResults('');
		var container = palette ? palette.querySelector('.kanbanz-cmd-palette__container') : null;
		if (container) KZ.FocusTrap.trap(container, { initialFocus: input });
		else if (input) input.focus();
		if (input) { input.value = ''; }
	};

	KZ.closeCmdPalette = function() {
		KZ.ui.commandOpen = false;
		var palette = document.getElementById('kanbanz-cmd-palette');
		var btn = document.getElementById('kanbanz-cmd-palette-btn');
		if (palette) {
			palette.style.display = 'none';
			palette.setAttribute('aria-hidden', 'true');
		}
		if (btn) btn.setAttribute('aria-expanded', 'false');
		KZ.FocusTrap.release();
	};

	KZ.updateCmdResults = function(query) {
		var container = document.getElementById('kanbanz-cmd-results');
		if (!container) return;
		var html = '';
		var resultCount = 0;

		// Static commands
		var commands = [
			{ title: t('cmdNewTask', 'New Task'), action: 'new-task', icon: 'dashicons-plus-alt2' },
			{ title: t('cmdMyTasks', 'My Tasks'), action: 'my-tasks', icon: 'dashicons-admin-users' },
			{ title: t('cmdToggleTheme', 'Toggle Theme'), action: 'theme', icon: 'dashicons-lightbulb' },
			{ title: t('cmdBoardView', 'Board View'), action: 'board', icon: 'dashicons-screenoptions' },
			{ title: t('cmdListView', 'List View'), action: 'list', icon: 'dashicons-list-view' },
		];

		if (!query) {
			commands.forEach(function(cmd) {
				html += '<div class="kanbanz-cmd-item" data-action="' + escAttr(cmd.action) + '"><span class="dashicons ' + cmd.icon + ' kanbanz-cmd-item__icon"></span><span class="kanbanz-cmd-item__title">' + escHtml(cmd.title) + '</span></div>';
			});
			container.innerHTML = html;
			return;
		}

		var q = query.toLowerCase();

		// Search matching commands
		commands.filter(function(cmd) { return cmd.title.toLowerCase().indexOf(q) > -1; }).forEach(function(cmd) {
			html += '<div class="kanbanz-cmd-item" data-action="' + escAttr(cmd.action) + '"><span class="dashicons ' + cmd.icon + ' kanbanz-cmd-item__icon"></span><span class="kanbanz-cmd-item__title">' + escHtml(cmd.title) + '</span></div>';
			resultCount++;
		});

		// Search tasks
		KZ.tasks.forEach(function(task, id) {
			var title = (task.title || task.name || '').toLowerCase();
			if (title.indexOf(q) > -1 && resultCount < 10) {
				html += '<div class="kanbanz-cmd-item" data-action="open-task" data-task-id="' + id + '"><span class="dashicons dashicons-screenoptions kanbanz-cmd-item__icon"></span><span class="kanbanz-cmd-item__title">' + escHtml(task.title || task.name || 'Task #' + id) + '</span><span class="kanbanz-cmd-item__desc">#' + id + '</span></div>';
				resultCount++;
			}
		});

		if (!resultCount) html = '<div class="kanbanz-cmd-item"><span class="kanbanz-cmd-item__title" style="color:var(--kanbanz-text-tertiary);">' + escHtml(t('noResults', 'No results found')) + '</span></div>';
		container.innerHTML = html;
	};

	// --- Date Formatting ---
	KZ.formatDate = function(dateStr) {
		if (!dateStr) return '';
		// Treat as local date (not UTC) to avoid "yesterday/today" off-by-one in some TZs
		var d = new Date(dateStr + 'T00:00:00');
		var now = new Date();
		// Compare based on local calendar day to avoid TZ drift
		var startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
		var startOfDate = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
		var diffDays = Math.round((startOfDate - startOfDay) / 86400000);
		var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		if (diffDays === 0) return t('today', 'Today');
		if (diffDays === 1) return t('tomorrow', 'Tomorrow');
		if (diffDays === -1) return t('yesterday', 'Yesterday');
		if (diffDays < -1) return months[d.getMonth()] + ' ' + d.getDate() + ' (' + t('overdue', 'overdue') + ')';
		return months[d.getMonth()] + ' ' + d.getDate();
	};

	KZ.refreshCard = function(taskId) {
		ajax('kanbanz_get_subtasks', { task_id: taskId }, function(res) {
			if (!res.success) return;
			var total = res.data.total || 0;
			var done = res.data.done || 0;
			var card = document.querySelector('.kanbanz-card[data-task-id="' + taskId + '"]');
			if (!card) return;
			var task = KZ.tasks.get(taskId);
			if (task) {
				if (!task.task_meta) task.task_meta = {};
				task.task_meta.subtask_count = total;
				task.task_meta.subtask_done = done;
			}
			var indicatorEl = card.querySelector('.kanbanz-card__indicator .dashicons-list-view');
			if (indicatorEl) {
				indicatorEl.parentElement.innerHTML = '<span class="dashicons dashicons-list-view"></span> ' + done + '/' + total;
			} else if (total > 0) {
				var indicatorsEl = card.querySelector('.kanbanz-card__indicators');
				if (!indicatorsEl) {
					var footerEl = card.querySelector('.kanbanz-card__footer');
					if (footerEl) {
						indicatorsEl = document.createElement('div');
						indicatorsEl.className = 'kanbanz-card__indicators';
						footerEl.appendChild(indicatorsEl);
					}
				}
				if (indicatorsEl) {
					var newIndicator = document.createElement('span');
					newIndicator.className = 'kanbanz-card__indicator';
					newIndicator.innerHTML = '<span class="dashicons dashicons-list-view"></span> ' + done + '/' + total;
					indicatorsEl.appendChild(newIndicator);
				}
			}
			var barFill = card.querySelector('.kanbanz-card__subtask-bar-fill');
			if (barFill && total > 0) {
				barFill.style.width = Math.round(done / total * 100) + '%';
			} else if (total > 0 && !barFill) {
				var bar = document.createElement('div');
				bar.className = 'kanbanz-card__subtask-bar';
				bar.innerHTML = '<div class="kanbanz-card__subtask-bar-fill" style="width:' + Math.round(done / total * 100) + '%;"></div>';
				card.appendChild(bar);
			}
		});
	};

	KZ.getContrastColor = function(hex) {
		if (!hex) return '#fff';
		hex = hex.replace('rgb(', '').replace(')', '');
		if (hex.indexOf(',') > -1) {
			var parts = hex.split(',');
			var r = parseInt(parts[0].trim()), g = parseInt(parts[1].trim()), b = parseInt(parts[2].trim());
			return ((r * 299 + g * 587 + b * 114) / 1000) > 128 ? '#000' : '#fff';
		}
		hex = hex.replace('#', '');
		if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
		var r = parseInt(hex.substring(0,2), 16), g = parseInt(hex.substring(2,4), 16), b = parseInt(hex.substring(4,6), 16);
		return ((r * 299 + g * 587 + b * 114) / 1000) > 128 ? '#000' : '#fff';
	};

	KZ.highlightMentions = function(text) {
		if (!text) return '';
		return text.replace(/@(\w+)/g, '<span class="kanbanz-mention-highlight" style="background:var(--kanbanz-accent-light);color:var(--kanbanz-accent);padding:0 4px;border-radius:var(--kanbanz-radius-sm);font-weight:500;">@$1</span>');
	};

	// --- Keyboard Shortcuts Modal ---
	KZ.showShortcuts = function() {
		var modal = document.getElementById('kanbanz-shortcuts-modal');
		var list = document.getElementById('kanbanz-shortcuts-list');
		var btn = document.getElementById('kanbanz-help-btn');
		if (!modal) return;
		// Build the list from config.shortcuts (translated on the PHP side)
		var shortcuts = (KZ.config && KZ.config.shortcuts) || [];
		if (list) {
			var html = '';
			shortcuts.forEach(function(s) {
				html += '<li class="kanbanz-shortcuts-list__item"><kbd class="kanbanz-kbd">' + escHtml(s.keys) + '</kbd><span class="kanbanz-shortcuts-list__desc">' + escHtml(s.desc) + '</span></li>';
			});
			list.innerHTML = html;
		}
		modal.style.display = '';
		modal.setAttribute('aria-hidden', 'false');
		if (btn) btn.setAttribute('aria-expanded', 'true');
		var dialog = modal.querySelector('.kanbanz-shortcuts-modal__dialog');
		if (dialog) KZ.FocusTrap.trap(dialog, { initialFocus: document.getElementById('kanbanz-shortcuts-close') });
	};
	KZ.hideShortcuts = function() {
		var modal = document.getElementById('kanbanz-shortcuts-modal');
		var btn = document.getElementById('kanbanz-help-btn');
		if (modal) { modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
		if (btn) btn.setAttribute('aria-expanded', 'false');
		KZ.FocusTrap.release();
	};

	// --- Empty states ---
	// Show/hide the empty-board banner (no columns) and per-column empty hints.
	KZ.updateEmptyStates = function() {
		var canvas = document.getElementById('kanbanz-canvas');
		var emptyBoard = document.getElementById('kanbanz-empty-board');
		if (!canvas) return;
		var cols = canvas.querySelectorAll('.kanbanz-column:not(.kanbanz-column--add)');
		var hasColumns = cols.length > 0;
		if (emptyBoard) emptyBoard.style.display = hasColumns ? 'none' : '';
		// Per-column empty hint
		cols.forEach(function(col) {
			var body = col.querySelector('.kanbanz-column__body');
			if (!body) return;
			var cardCount = body.querySelectorAll('.kanbanz-card').length;
			var existingHint = body.querySelector('.kanbanz-column__empty-hint');
			if (cardCount === 0 && !existingHint) {
				var hint = document.createElement('div');
				hint.className = 'kanbanz-column__empty-hint';
				hint.setAttribute('aria-hidden', 'true');
				hint.textContent = t('noResults', 'No tasks') + ' — ' + t('addTask', 'Add task');
				body.appendChild(hint);
			} else if (cardCount > 0 && existingHint) {
				existingHint.remove();
			}
		});
	};
	function initEvents() {
		// Card click & checkbox delegation (handles both PHP-rendered and JS-created cards)
		var canvas = document.getElementById('kanbanz-canvas');
		if (canvas) {
			canvas.addEventListener('click', function(e) {
				// Card selection checkbox clicked
				var checkbox = e.target.closest('.kanbanz-card__checkbox');
				if (checkbox) {
					var card = checkbox.closest('.kanbanz-card');
					if (card) {
						var taskId = parseInt(card.dataset.taskId);
						KZ.toggleTaskSelection(taskId, checkbox.checked, e.shiftKey);
					}
					e.stopPropagation();
					return;
				}

				var card = e.target.closest('.kanbanz-card');
				if (!card) return;
				// Skip click that fires right after a jQuery UI Sortable drag
				if (typeof KZ.getDraggedTaskId === 'function' && KZ.getDraggedTaskId()) return;
				if (e.target.closest('.kanbanz-card__avatars') || e.target.closest('.kanbanz-card__priority')) return;
				var taskId = parseInt(card.dataset.taskId);
				if (taskId) KZ.openTask(taskId);
			});
		}

		// Help / shortcuts modal
		var helpBtn = document.getElementById('kanbanz-help-btn');
		if (helpBtn) helpBtn.addEventListener('click', KZ.showShortcuts);
		var shortcutsClose = document.getElementById('kanbanz-shortcuts-close');
		if (shortcutsClose) shortcutsClose.addEventListener('click', KZ.hideShortcuts);
		var shortcutsOverlay = document.querySelector('.kanbanz-shortcuts-modal__overlay');
		if (shortcutsOverlay) shortcutsOverlay.addEventListener('click', KZ.hideShortcuts);

		// Empty board CTA — clicking "Add First Stage" focuses the add-stage input
		var emptyAddStage = document.getElementById('kanbanz-empty-add-stage');
		if (emptyAddStage) emptyAddStage.addEventListener('click', function() {
			var addBtn = document.getElementById('kanbanz-add-stage-btn');
			if (addBtn) addBtn.click();
		});

		// Theme toggle
		var themeBtn = document.getElementById('kanbanz-theme-toggle');
		if (themeBtn) themeBtn.addEventListener('click', KZ.toggleTheme);

		// Layout toggle
		$$('.kanbanz-toggle-btn', document.getElementById('kanbanz-layout-toggle')).forEach(function(btn) {
			btn.addEventListener('click', function() { KZ.setLayout(this.dataset.layout); });
		});

		// Board title edit
		var titleEl = document.getElementById('kanbanz-board-title');
		if (titleEl) {
			titleEl.addEventListener('blur', function() {
				var newTitle = this.textContent.trim();
				if (newTitle && newTitle !== KZ.config.board_title) {
					ajax('kanbanz_update_board_title', { board_id: KZ.config.board_id, board_title: newTitle }, function(res) {
						if (res.success) { KZ.config.board_title = newTitle; KZ.Toast.success(t('titleUpdated', 'Title updated')); }
						else KZ.Toast.error(t('errorUpdatingTitle', 'Error updating title'));
					});
				}
			});
			titleEl.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); this.blur(); } });
		}

		// Board settings
		var settingsBtn = document.getElementById('kanbanz-board-settings-btn');
		if (settingsBtn) settingsBtn.addEventListener('click', KZ.showBoardSettings);
		var settingsClose = document.getElementById('kanbanz-settings-close');
		if (settingsClose) settingsClose.addEventListener('click', KZ.hideBoardSettings);
		var settingsOverlay = document.getElementById('kanbanz-settings-overlay');
		if (settingsOverlay) settingsOverlay.addEventListener('click', KZ.hideBoardSettings);

		// Description save
		var saveDescBtn = document.getElementById('kanbanz-save-desc');
		if (saveDescBtn) {
			saveDescBtn.addEventListener('click', function() {
				var desc = document.getElementById('kanbanz-settings-desc');
				if (desc) {
					ajax('kanbanz_update_board_desc', { board_id: KZ.config.board_id, val: desc.value }, function(res) {
						if (res.success) KZ.Toast.success(t('descriptionSaved', 'Description saved'));
					});
				}
			});
		}

		// Settings drawer tabs (Settings / Activity)
		var settingsTabs = $$('.kanbanz-settings__tab');
		settingsTabs.forEach(function(tab, index) {
			tab.addEventListener('click', function() { KZ.showSettingsTab(this.dataset.tab); });
			tab.addEventListener('keydown', function(e) {
				var next = null;
				if (e.key === 'ArrowRight') next = settingsTabs[(index + 1) % settingsTabs.length];
				else if (e.key === 'ArrowLeft') next = settingsTabs[(index - 1 + settingsTabs.length) % settingsTabs.length];
				else if (e.key === 'Home') next = settingsTabs[0];
				else if (e.key === 'End') next = settingsTabs[settingsTabs.length - 1];
				if (!next) return;
				e.preventDefault();
				KZ.showSettingsTab(next.dataset.tab);
				next.focus();
			});
		});
		var activityMore = document.getElementById('kanbanz-activity-more');
		if (activityMore) activityMore.addEventListener('click', function() { KZ.renderBoardActivity(KZ._activityShown + 20); });

		// Team members: add/remove. A department-less board with no team is visible to all staff.
		var teamSection = document.getElementById('kanbanz-team-section');
		var teamList = document.getElementById('kanbanz-team-list');
		if (teamSection && teamList) {
			var teamUsers = null;
			var addSearch = document.getElementById('kanbanz-team-add-search');
			var addResults = document.getElementById('kanbanz-team-add-results');

			var teamLogins = function() {
				return $$('.kanbanz-team-list__item', teamList).map(function(li) { return li.dataset.username; });
			};
			var renderTeamStatus = function() {
				var status = document.getElementById('kanbanz-team-status');
				if (status) status.textContent = teamList.children.length ? teamSection.dataset.privateText : teamSection.dataset.openText;
			};
			var teamItem = function(login, name) {
				var label = t('removeFromTeamLabel', 'Remove from the team') + ': ' + name;
				var li = document.createElement('li');
				li.className = 'kanbanz-team-list__item';
				li.dataset.username = login;
				li.innerHTML = '<span class="kanbanz-team-list__name">' + escHtml(name) + ' <span class="kanbanz-team-list__login">(' + escHtml(login) + ')</span></span>'
					+ '<button type="button" class="kanbanz-icon-btn kanbanz-team-list__remove" aria-label="' + escAttr(label) + '" title="' + escAttr(label) + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
				return li;
			};
			var saveTeam = function(logins, done) {
				var data = { board_id: KZ.config.board_id };
				logins.forEach(function(login, i) { data['assigned_members[' + i + '][username]'] = login; });
				ajax('kanbanz_update_staff_members', data, function(res) {
					if (res.success) {
						// Redraw from the saved team: the server may add task assignees or drop users
						teamList.innerHTML = '';
						(res.data.members || []).forEach(function(m) { teamList.appendChild(teamItem(m.username, m.name)); });
						done(res.data.updated_members || []);
						renderTeamStatus();
					} else {
						KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorSaving', 'Error saving'));
					}
				});
			};

			teamList.addEventListener('click', function(e) {
				var btn = e.target.closest('.kanbanz-team-list__remove');
				if (!btn) return;
				var item = btn.closest('.kanbanz-team-list__item');
				var name = item.querySelector('.kanbanz-team-list__name').textContent.trim();
				var last = teamList.children.length === 1;
				KZ.confirm({
					title: t('removeFromTeam', 'Remove from team'),
					message: (last
						? t('confirmRemoveLastFromTeam', 'Remove the last team member? The board will become visible to all staff.')
						: t('confirmRemoveFromTeam', 'Remove this member from the board team? They will no longer see this board.')) + ' (' + name + ')',
					confirmText: t('remove', 'Remove'),
					cancelText: t('cancel', 'Cancel'),
					danger: true
				}).then(function(result) {
					if (!result || !result.ok) return;
					saveTeam(teamLogins().filter(function(l) { return l !== item.dataset.username; }), function() {
						KZ.Toast.success(t('memberRemovedFromTeam', 'Removed from team'));
					});
				});
			});

			var renderAddResults = function() {
				var q = addSearch.value.trim().toLowerCase();
				var current = teamLogins();
				var matches = (teamUsers || []).filter(function(u) {
					return u.role !== 'admin' && current.indexOf(u.username) === -1 &&
						(!q || (u.name || '').toLowerCase().indexOf(q) > -1 || u.username.toLowerCase().indexOf(q) > -1);
				}).slice(0, 8);
				addResults.innerHTML = matches.length ? matches.map(function(u) {
					return '<button type="button" class="kanbanz-team-add__item" data-username="' + escAttr(u.username) + '" data-name="' + escAttr(u.name || u.username) + '">'
						+ escHtml(u.name || u.username) + ' <span class="kanbanz-team-list__login">(' + escHtml(u.username) + ' · ' + escHtml(u.role_label || '') + ')</span></button>';
				}).join('') : '<div class="kanbanz-team-add__empty">' + escHtml(t('noUsersFound', 'No users found')) + '</div>';
				addResults.style.display = '';
			};
			if (addSearch && addResults) {
				var openAddResults = function() {
					if (teamUsers) { renderAddResults(); return; }
					ajax('kanbanz_get_assignable_users', { board_id: KZ.config.board_id }, function(res) {
						teamUsers = res.success ? (res.data.users || []) : [];
						renderAddResults();
					});
				};
				addSearch.addEventListener('focus', openAddResults);
				addSearch.addEventListener('input', openAddResults);
				addSearch.addEventListener('keydown', function(e) {
					if (e.key === 'Escape') { addResults.style.display = 'none'; this.blur(); }
				});
				document.addEventListener('click', function(e) {
					if (!e.target.closest('.kanbanz-team-add')) addResults.style.display = 'none';
				});
				addResults.addEventListener('click', function(e) {
					var item = e.target.closest('.kanbanz-team-add__item');
					if (!item) return;
					var login = item.dataset.username;
					var name = item.dataset.name;
					var add = function() {
						saveTeam(teamLogins().concat([login]), function(saved) {
							if (saved.indexOf(login) === -1) return;
							addSearch.value = '';
							addResults.style.display = 'none';
							KZ.Toast.success(t('memberAddedToTeam', 'Added to team'));
						});
					};
					if (teamList.children.length) { add(); return; }
					KZ.confirm({
						title: t('makeBoardPrivate', 'Make board private?'),
						message: t('confirmFirstTeamMember', 'Adding a team member makes this board visible only to its team, admins and the board owner. Everyone currently assigned a task on this board is added to the team too.'),
						confirmText: t('addMember', 'Add member'),
						cancelText: t('cancel', 'Cancel')
					}).then(function(result) { if (result && result.ok) add(); });
				});
			}
		}

		// Task completion permission mode & custom completers
		var compSelect = document.getElementById('kanbanz-completion-mode-select');
		var compWrap = document.getElementById('kanbanz-custom-completers-wrap');
		if (compSelect) {
			compSelect.addEventListener('change', function() {
				if (compWrap) compWrap.style.display = (this.value === 'custom') ? 'block' : 'none';
			});
		}

		var saveCompBtn = document.getElementById('kanbanz-save-completion-settings');
		if (saveCompBtn) {
			saveCompBtn.addEventListener('click', function() {
				var mode = compSelect ? compSelect.value : 'everyone';
				var allowedStaff = [];
				if (compWrap) {
					$$('.kanbanz-completer-checkbox:checked', compWrap).forEach(function(cb) {
						allowedStaff.push(cb.value);
					});
				}

				ajax('kanbanz_save_board_settings', {
					board_id: KZ.config.board_id,
					completion_mode: mode,
					allowed_staff: allowedStaff
				}, function(res) {
					if (res && res.success) {
						KZ.Toast.success(res.data.message || t('saved', 'Saved'));
						if (typeof res.data.can_complete_tasks !== 'undefined') {
							KZ.config.canCompleteTasks = res.data.can_complete_tasks;
						}
						KZ.loadBoardActivity();
					} else {
						KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorSaving', 'Error saving'));
					}
				});
			});
		}

		// Save Board Managers & Department (Admin Only)
		var saveMgrsBtn = document.getElementById('kanbanz-save-board-managers') || document.getElementById('kanbanz-save-dept');
		var deptSelect = document.getElementById('kanbanz-settings-dept-select');
		var mgrsWrap = document.getElementById('kanbanz-assign-managers-wrap');
		if (saveMgrsBtn) {
			saveMgrsBtn.addEventListener('click', function() {
				var selectedMgrs = [];
				if (mgrsWrap) {
					$$('.kanbanz-board-manager-checkbox:checked', mgrsWrap).forEach(function(cb) {
						selectedMgrs.push(cb.value);
					});
				}
				var deptId = deptSelect ? deptSelect.value : '';

				ajax('kanbanz_save_board_settings', {
					board_id: KZ.config.board_id,
					board_managers: selectedMgrs,
					dept_id: deptId
				}, function(res) {
					if (res && res.success) {
						KZ.Toast.success(res.data.message || t('saved', 'Saved'));
						if (res.data.board_managers) {
							var mgrList = document.getElementById('kanbanz-settings-managers-list');
							if (mgrList) {
								var html = '';
								res.data.board_managers.forEach(function(bm) {
									var initials = (bm.name || bm.username || '?').substring(0, 2).toUpperCase();
									html += '<div class="kanbanz-manager-badge">'
										  + '  <div class="kanbanz-manager-badge__avatar">' + escHtml(initials) + '</div>'
										  + '  <div class="kanbanz-manager-badge__info">'
										  + '    <span class="kanbanz-manager-badge__name">' + escHtml(bm.name) + '</span>'
										  + '    <span class="kanbanz-manager-badge__tag">' + escHtml(bm.badge) + '</span>'
										  + '  </div>'
										  + '</div>';
								});
								mgrList.innerHTML = html || '<p class="kanbanz-settings__empty">' + escHtml(t('noManagersAssigned', 'No managers assigned.')) + '</p>';
							}
						}
						KZ.loadBoardActivity();
					} else {
						KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorSaving', 'Error saving'));
					}
				});
			});
		}

		// Refresh board activity button
		var refreshActBtn = document.getElementById('kanbanz-refresh-activity-btn');
		if (refreshActBtn) {
			refreshActBtn.addEventListener('click', function() {
				var spin = this.querySelector('.dashicons');
				if (spin) spin.classList.add('kanbanz-spin');
				KZ.loadBoardActivity(function() { if (spin) spin.classList.remove('kanbanz-spin'); });
			});
		}

		// Delete board
		var deleteBoardBtn = document.getElementById('kanbanz-delete-board-btn');
		if (deleteBoardBtn) {
			deleteBoardBtn.addEventListener('click', function() {
				KZ.confirm({
					title: t('delete', 'Delete'),
					message: t('confirmDeleteBoard', 'Are you sure you want to delete this board? This cannot be undone.'),
					confirmText: t('delete', 'Delete'),
					cancelText: t('cancel', 'Cancel'),
					danger: true
				}).then(function(result) {
					if (!result.ok) return;
					ajax('kanbanz_remove_board', { board_id: KZ.config.board_id }, function(res) {
						if (res.success) { window.location.href = KZ.config.admin_url || (typeof admin_url === 'function' ? admin_url('admin.php?page=kanbanz') : 'admin.php?page=kanbanz'); }
						else KZ.Toast.error(t('errorDeletingBoard', 'Error deleting board'));
					});
				});
			});
		}

		// Modal close/back/overlay/delete
		var modalClose = document.getElementById('kanbanz-modal-close');
		if (modalClose) modalClose.addEventListener('click', KZ.closePanel);
		var modalBack = document.getElementById('kanbanz-modal-back');
		if (modalBack) modalBack.addEventListener('click', KZ.closePanel);
		var modalOverlay = document.getElementById('kanbanz-modal-overlay');
		if (modalOverlay) modalOverlay.addEventListener('click', KZ.closePanel);
		var deleteBtn = document.getElementById('kanbanz-modal-delete-btn');
		if (deleteBtn) deleteBtn.addEventListener('click', function() {
			var taskId = KZ.ui.activeTaskId;
			if (taskId) KZ.deleteTask(taskId);
		});
		// Move task button — show column dropdown
		var moveBtn = document.getElementById('kanbanz-modal-move-btn');
		if (moveBtn) {
			moveBtn.addEventListener('click', function() {
				var existing = document.getElementById('kanbanz-modal-move-dropdown');
				if (existing) { existing.remove(); moveBtn.setAttribute('aria-expanded', 'false'); return; }
				var dropdown = document.createElement('div');
				dropdown.id = 'kanbanz-modal-move-dropdown';
				dropdown.className = 'kanbanz-context-menu';
				dropdown.setAttribute('role', 'menu');
				dropdown.setAttribute('aria-label', escAttr(t('errorMovingTask', 'Move task to column')));
				dropdown.style.position = 'absolute';
				dropdown.style.zIndex = '999999'; // --kanbanz-z-modal
				var rect = this.getBoundingClientRect();
				dropdown.style.top = (rect.bottom + 4) + 'px';
				dropdown.style.right = (window.innerWidth - rect.right) + 'px';
				moveBtn.setAttribute('aria-expanded', 'true');
				KZ.columns.forEach(function(col) {
					var item = document.createElement('button');
					item.type = 'button';
					item.className = 'kanbanz-context-menu__item';
					item.setAttribute('role', 'menuitem');
					item.textContent = col;
					item.addEventListener('click', function() {
						var taskId = KZ.ui.activeTaskId;
						if (taskId) KZ.moveTask(taskId, col);
						dropdown.remove();
						moveBtn.setAttribute('aria-expanded', 'false');
					});
					dropdown.appendChild(item);
				});
				document.body.appendChild(dropdown);
				setTimeout(function() {
					document.addEventListener('click', function handler(e) {
						if (!dropdown.contains(e.target)) { dropdown.remove(); document.removeEventListener('click', handler); moveBtn.setAttribute('aria-expanded', 'false'); }
					});
				}, 10);
			});
		}

		// Add task button
		var addTaskBtn = document.getElementById('kanbanz-add-task-btn');
		if (addTaskBtn) addTaskBtn.addEventListener('click', function() {
			var firstBody = document.querySelector('.kanbanz-column__body');
			if (firstBody && firstBody.dataset.status) KZ.showTaskInput(firstBody.dataset.status);
		});

		// Column collapse buttons & expand on click
		$$('.kanbanz-column__collapse-btn').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var col = this.closest('.kanbanz-column');
				if (!col) return;
				col.classList.toggle('kanbanz-column--collapsed');
				var isCollapsed = col.classList.contains('kanbanz-column--collapsed');
				var icon = this.querySelector('.dashicons');
				if (icon) icon.className = isCollapsed ? 'dashicons dashicons-arrow-right-alt2' : 'dashicons dashicons-arrow-left-alt2';
				this.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
				var status = col.dataset.status;
				try {
					var collapsed = JSON.parse(localStorage.getItem('kanbanz_collapsed') || '{}');
					collapsed[status] = isCollapsed;
					localStorage.setItem('kanbanz_collapsed', JSON.stringify(collapsed));
				} catch(e) {}
			});
		});

		// Clicking on a collapsed column expands it
		$$('.kanbanz-column').forEach(function(col) {
			col.addEventListener('click', function(e) {
				if (this.classList.contains('kanbanz-column--collapsed')) {
					var btn = this.querySelector('.kanbanz-column__collapse-btn');
					if (btn) btn.click();
				}
			});
		});

		// Restore collapsed columns from localStorage
		try {
			var collapsed = JSON.parse(localStorage.getItem('kanbanz_collapsed') || '{}');
			Object.keys(collapsed).forEach(function(status) {
				if (collapsed[status]) {
					var col = document.querySelector('.kanbanz-column[data-status="' + status + '"]');
					if (col) {
						col.classList.add('kanbanz-column--collapsed');
						var btn = col.querySelector('.kanbanz-column__collapse-btn');
						if (btn) {
							var icon = btn.querySelector('.dashicons');
							if (icon) icon.className = 'dashicons dashicons-arrow-right-alt2';
							btn.setAttribute('aria-expanded', 'false');
						}
					}
				}
			});
		} catch(e) {}

		// Column add task buttons
		$$('.kanbanz-column__add-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				var addTask = this.closest('.kanbanz-column__add-task');
				if (addTask) KZ.showTaskInput(addTask.dataset.status);
			});
		});

		// Column header add buttons
		$$('.kanbanz-column__add').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var col = this.closest('.kanbanz-column');
				if (col) KZ.showTaskInput(col.querySelector('.kanbanz-column__body').dataset.status);
			});
		});

		// Column menu buttons
		$$('.kanbanz-column__menu-trigger').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var col = this.closest('.kanbanz-column');
				if (col) KZ.showColumnMenu(col, e);
			});
		});

		// Column context menu actions
		var columnMenu = document.getElementById('kanbanz-column-menu');
		if (columnMenu) {
			$$('.kanbanz-context-menu__item', columnMenu).forEach(function(item) {
				item.addEventListener('click', function() {
					var action = this.dataset.action;
					var status = columnMenu.dataset.status;
					columnMenu.style.display = 'none';
					switch(action) {
						case 'add-task': KZ.showTaskInput(status); break;
						case 'sort-date': KZ.sortColumn(status, 'date'); break;
						case 'sort-priority': KZ.sortColumn(status, 'priority'); break;
						case 'wip-limit': KZ.setWipLimit(status); break;
						case 'rename': KZ.renameColumn(status); break;
						case 'delete-column': KZ.deleteColumn(status); break;
						case 'select-all':
							var col = document.querySelector('.kanbanz-column[data-status="' + status.replace(/"/g, '\\"') + '"]');
							if (col) {
								col.querySelectorAll('.kanbanz-card').forEach(function(c) {
									var tid = parseInt(c.dataset.taskId);
									if (tid) KZ.selectedTasks.add(tid);
								});
								KZ.syncCardSelectionUI();
								KZ.updateBulkBar();
								KZ.Toast.success(KZ.selectedTasks.size + ' ' + t('tasksSelected', 'tasks selected'));
							}
							break;
					}
				});
			});
		}

		// Floating Bulk Action Bar Event Handlers
		var bulkBar = document.getElementById('kanbanz-bulk-bar');
		if (bulkBar) {
			var bulkMoveBtn = document.getElementById('kanbanz-bulk-move-btn');
			var bulkMoveMenu = document.getElementById('kanbanz-bulk-move-menu');
			var bulkPriorityBtn = document.getElementById('kanbanz-bulk-priority-btn');
			var bulkPriorityMenu = document.getElementById('kanbanz-bulk-priority-menu');
			var bulkAssignBtn = document.getElementById('kanbanz-bulk-assign-btn');
			var bulkAssignMenu = document.getElementById('kanbanz-bulk-assign-menu');

			var closeBulkMenus = function() {
				if (bulkMoveMenu) bulkMoveMenu.style.display = 'none';
				if (bulkPriorityMenu) bulkPriorityMenu.style.display = 'none';
				if (bulkAssignMenu) bulkAssignMenu.style.display = 'none';
			};

			if (bulkMoveBtn && bulkMoveMenu) {
				bulkMoveBtn.addEventListener('click', function(e) {
					e.stopPropagation();
					var isHidden = bulkMoveMenu.style.display === 'none' || !bulkMoveMenu.style.display;
					closeBulkMenus();
					bulkMoveMenu.style.display = isHidden ? 'block' : 'none';
				});
			}

			if (bulkPriorityBtn && bulkPriorityMenu) {
				bulkPriorityBtn.addEventListener('click', function(e) {
					e.stopPropagation();
					var isHidden = bulkPriorityMenu.style.display === 'none' || !bulkPriorityMenu.style.display;
					closeBulkMenus();
					bulkPriorityMenu.style.display = isHidden ? 'block' : 'none';
				});
			}

			if (bulkAssignBtn && bulkAssignMenu) {
				bulkAssignBtn.addEventListener('click', function(e) {
					e.stopPropagation();
					var isHidden = bulkAssignMenu.style.display === 'none' || !bulkAssignMenu.style.display;
					closeBulkMenus();
					bulkAssignMenu.style.display = isHidden ? 'block' : 'none';
				});
			}

			// Close bulk menus on outside click
			document.addEventListener('click', function(e) {
				if (!e.target.closest('.kanbanz-bulk-dropdown')) {
					closeBulkMenus();
				}
			});

			// Bulk action items click
			$$('.kanbanz-bulk-action-item', bulkBar).forEach(function(item) {
				item.addEventListener('click', function() {
					var action = this.dataset.action;
					var value = this.dataset.value;
					closeBulkMenus();
					KZ.bulkAction(action, value);
				});
			});

			// Bulk delete
			var bulkDeleteBtn = document.getElementById('kanbanz-bulk-delete-btn');
			if (bulkDeleteBtn) {
				bulkDeleteBtn.addEventListener('click', function() {
					var count = KZ.selectedTasks.size;
					if (!count) return;
					KZ.confirm({
						title: t('delete', 'Delete'),
						message: t('confirmBulkDelete', 'Are you sure you want to delete ' + count + ' selected task(s)? This cannot be undone.'),
						confirmText: t('delete', 'Delete'),
						cancelText: t('cancel', 'Cancel'),
						danger: true
					}).then(function(result) {
						if (result && result.ok) {
							KZ.bulkAction('delete', '');
						}
					});
				});
			}

			// Clear bulk selection
			var bulkClearBtn = document.getElementById('kanbanz-bulk-clear-btn');
			if (bulkClearBtn) {
				bulkClearBtn.addEventListener('click', function() {
					KZ.clearSelection();
				});
			}
		}

		// Add stage
		var addStageBtn = document.getElementById('kanbanz-add-stage-btn');
		if (addStageBtn) {
			addStageBtn.addEventListener('click', function() {
				var col = document.getElementById('kanbanz-add-column');
				if (col) {
					var input = document.createElement('input');
					input.type = 'text';
					input.className = 'kanbanz-inline-task-input';
					input.setAttribute('aria-label', escAttr(t('addStage', 'Add Stage')));
					input.placeholder = t('stageNamePlaceholder', 'Stage name...');
					col.innerHTML = '';
					col.appendChild(input);
					input.focus();
					input.addEventListener('keydown', function(e) {
						if (e.key === 'Enter' && this.value.trim()) {
							KZ.addStage(this.value.trim());
							this.remove();
							col.innerHTML = '<button type="button" class="kanbanz-column__add-stage-btn" id="kanbanz-add-stage-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' + escHtml(t('addStage', 'Add Stage')) + '</button>';
							initEvents();
						} else if (e.key === 'Escape') {
							this.remove();
							col.innerHTML = '<button type="button" class="kanbanz-column__add-stage-btn" id="kanbanz-add-stage-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' + escHtml(t('addStage', 'Add Stage')) + '</button>';
							initEvents();
						}
					});
				}
			});
		}

		// Filter chips
		var myTasksChip = document.getElementById('kanbanz-my-tasks-chip');
		if (myTasksChip) myTasksChip.addEventListener('click', function() {
			KZ.filters.myTasks = !KZ.filters.myTasks;
			this.classList.toggle('kanbanz-chip--active', KZ.filters.myTasks);
			this.setAttribute('aria-pressed', KZ.filters.myTasks ? 'true' : 'false');
			KZ.applyFilters();
			KZ.performDBSearch();
		});

		var overdueChip = document.getElementById('kanbanz-overdue-chip');
		if (overdueChip) overdueChip.addEventListener('click', function() {
			KZ.filters.overdue = !KZ.filters.overdue;
			this.classList.toggle('kanbanz-chip--active', KZ.filters.overdue);
			this.setAttribute('aria-pressed', KZ.filters.overdue ? 'true' : 'false');
			KZ.applyFilters();
			KZ.performDBSearch();
		});

		$$('.kanbanz-chip--priority').forEach(function(chip) {
			chip.addEventListener('click', function() {
				var val = (this.dataset.value || '').toLowerCase();
				if (KZ.filters.priorities.has(val)) {
					KZ.filters.priorities.delete(val);
					this.classList.remove('kanbanz-chip--active');
					this.setAttribute('aria-pressed', 'false');
				} else {
					KZ.filters.priorities.add(val);
					this.classList.add('kanbanz-chip--active');
					this.setAttribute('aria-pressed', 'true');
				}
				KZ.applyFilters();
				clearTimeout(searchTimeout);
				searchTimeout = setTimeout(function() {
					KZ.performDBSearch();
				}, 250);
			});
		});

		// Search
		var searchInput = document.getElementById('kanbanz-search-input');
		var searchSubmit = document.getElementById('kanbanz-search-submit');
		if (searchInput) {
			var searchTimeout;
			searchInput.addEventListener('input', function() {
				clearTimeout(searchTimeout);
				var val = this.value.trim();
				KZ.filters.search = val;
				KZ.applyFilters(); // Instant DOM filter
				searchTimeout = setTimeout(function() {
					if (val.length >= 2 || val.length === 0) {
						KZ.performDBSearch(); // DB search across all board tasks
					}
				}, 350);
			});
			searchInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					clearTimeout(searchTimeout);
					KZ.performDBSearch();
				}
			});
		}
		if (searchSubmit) {
			searchSubmit.addEventListener('click', function(e) {
				e.preventDefault();
				if (typeof searchTimeout !== 'undefined') clearTimeout(searchTimeout);
				KZ.performDBSearch();
			});
		}

		// Date Filter Dropdown Controls
		var dateBtn = document.getElementById('kanbanz-date-filter-btn');
		var dateMenu = document.getElementById('kanbanz-date-filter-menu');
		var dateTargetSelect = document.getElementById('kanbanz-date-target-select');
		if (dateBtn && dateMenu) {
			dateBtn.addEventListener('click', function(e) {
				e.stopPropagation();
				var willOpen = dateMenu.style.display === 'none' || !dateMenu.style.display;
				dateMenu.style.display = willOpen ? 'block' : 'none';
				dateBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			});
			document.addEventListener('click', function(e) {
				if (dateMenu && !dateMenu.contains(e.target) && !dateBtn.contains(e.target)) {
					dateMenu.style.display = 'none';
					dateBtn.setAttribute('aria-expanded', 'false');
				}
			});
		}
		if (dateTargetSelect) {
			dateTargetSelect.addEventListener('change', function() {
				KZ.filters.dateTarget = this.value;
				if (KZ.filters.datePreset && KZ.filters.datePreset !== 'all') {
					KZ.applyFilters();
					KZ.performDBSearch();
				}
			});
		}
		$$('.kanbanz-date-preset-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				$$('.kanbanz-date-preset-btn').forEach(function(b) { b.classList.remove('kanbanz-date-preset-btn--active'); });
				this.classList.add('kanbanz-date-preset-btn--active');

				var preset = this.dataset.preset;
				KZ.filters.datePreset = preset;

				function toLocalDateString(d) {
					var y = d.getFullYear();
					var m = String(d.getMonth() + 1).padStart(2, '0');
					var day = String(d.getDate()).padStart(2, '0');
					return y + '-' + m + '-' + day;
				}

				var now = new Date();
				var todayStr = toLocalDateString(now);

				if (preset === 'all') {
					KZ.filters.dateFrom = '';
					KZ.filters.dateTo = '';
				} else if (preset === 'today') {
					KZ.filters.dateFrom = todayStr;
					KZ.filters.dateTo = todayStr;
				} else if (preset === 'week') {
					var dayOfWeek = now.getDay();
					var firstDay = new Date(now.getFullYear(), now.getMonth(), now.getDate() - dayOfWeek);
					var lastDay = new Date(now.getFullYear(), now.getMonth(), now.getDate() + (6 - dayOfWeek));
					KZ.filters.dateFrom = toLocalDateString(firstDay);
					KZ.filters.dateTo = toLocalDateString(lastDay);
				} else if (preset === 'month') {
					var firstMonthDay = new Date(now.getFullYear(), now.getMonth(), 1);
					var lastMonthDay = new Date(now.getFullYear(), now.getMonth() + 1, 0);
					KZ.filters.dateFrom = toLocalDateString(firstMonthDay);
					KZ.filters.dateTo = toLocalDateString(lastMonthDay);
				}

				if (dateMenu) dateMenu.style.display = 'none';
				if (dateBtn) dateBtn.setAttribute('aria-expanded', 'false');
				KZ.applyFilters();
				KZ.performDBSearch();
			});
		});

		var applyDateBtn = document.getElementById('kanbanz-apply-date-range');
		if (applyDateBtn) {
			applyDateBtn.addEventListener('click', function() {
				var fromInput = document.getElementById('kanbanz-date-from');
				var toInput = document.getElementById('kanbanz-date-to');
				var fromVal = fromInput ? fromInput.value : '';
				var toVal = toInput ? toInput.value : '';

				if (fromVal || toVal) {
					KZ.filters.datePreset = 'custom';
					KZ.filters.dateFrom = fromVal;
					KZ.filters.dateTo = toVal;
					$$('.kanbanz-date-preset-btn').forEach(function(b) { b.classList.remove('kanbanz-date-preset-btn--active'); });
					if (dateMenu) dateMenu.style.display = 'none';
					if (dateBtn) dateBtn.setAttribute('aria-expanded', 'false');
					KZ.applyFilters();
					KZ.performDBSearch();
				}
			});
		}

		// Clear Filters button
		var clearFiltersBtn = document.getElementById('kanbanz-clear-filters-btn');
		if (clearFiltersBtn) {
			clearFiltersBtn.addEventListener('click', function() {
				KZ.filters = {
					search: '',
					myTasks: false,
					priorities: new Set(),
					priority: '',
					overdue: false,
					assignee: 0,
					assigneeName: '',
					dateTarget: 'due_date',
					datePreset: 'all',
					dateFrom: '',
					dateTo: ''
				};
				if (searchInput) searchInput.value = '';
				var myTasksChip = document.getElementById('kanbanz-my-tasks-chip');
				if (myTasksChip) { myTasksChip.classList.remove('kanbanz-chip--active'); myTasksChip.setAttribute('aria-pressed', 'false'); }
				var overdueChip = document.getElementById('kanbanz-overdue-chip');
				if (overdueChip) { overdueChip.classList.remove('kanbanz-chip--active'); overdueChip.setAttribute('aria-pressed', 'false'); }
				$$('.kanbanz-chip--priority').forEach(function(c) { c.classList.remove('kanbanz-chip--active'); c.setAttribute('aria-pressed', 'false'); });
				$$('.kanbanz-date-preset-btn').forEach(function(b) { b.classList.toggle('kanbanz-date-preset-btn--active', b.dataset.preset === 'all'); });
				var fromInput = document.getElementById('kanbanz-date-from');
				var toInput = document.getElementById('kanbanz-date-to');
				if (fromInput) fromInput.value = '';
				if (toInput) toInput.value = '';
				if (assigneeDropdown) {
					$$('.kanbanz-dropdown__item', assigneeDropdown).forEach(function(item) {
						var isZero = (parseInt(item.dataset.userId) || 0) === 0;
						item.classList.toggle('kanbanz-dropdown__item--active', isZero);
						item.setAttribute('aria-checked', isZero ? 'true' : 'false');
					});
				}
				KZ.applyFilters();
				KZ.performDBSearch();
			});
		}

		// Initialize Infinite Scroll per column
		KZ.initInfiniteScroll();

		// Load More button click handler
		document.addEventListener('click', function(e) {
			var loadMoreBtn = e.target.closest('.kanbanz-column__load-more-btn');
			if (loadMoreBtn) {
				var col = loadMoreBtn.closest('.kanbanz-column');
				if (col && col.dataset.loading !== 'true') {
					KZ.loadMoreColumnTasks(col);
				}
			}
		});

		// Assignee filter dropdown
		var assigneeTrigger = $('#kanbanz-assignee-filter .kanbanz-dropdown__trigger');
		var assigneeDropdown = $('#kanbanz-assignee-filter .kanbanz-dropdown__menu');
		if (assigneeTrigger && assigneeDropdown) {
			assigneeTrigger.addEventListener('click', function(e) {
				e.stopPropagation();
				var willOpen = assigneeDropdown.style.display === 'none' || !assigneeDropdown.style.display;
				assigneeDropdown.style.display = willOpen ? 'block' : 'none';
				assigneeTrigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
			});
			document.addEventListener('click', function(e) {
				if (assigneeDropdown && !assigneeDropdown.contains(e.target) && !assigneeTrigger.contains(e.target)) {
					assigneeDropdown.style.display = 'none';
					assigneeTrigger.setAttribute('aria-expanded', 'false');
				}
			});
			$$('.kanbanz-dropdown__item', assigneeDropdown).forEach(function(item) {
				item.addEventListener('click', function() {
					$$('.kanbanz-dropdown__item', assigneeDropdown).forEach(function(i) { i.classList.remove('kanbanz-dropdown__item--active'); i.setAttribute('aria-checked', 'false'); });
					this.classList.add('kanbanz-dropdown__item--active');
					this.setAttribute('aria-checked', 'true');
					var uid = parseInt(this.dataset.userId) || 0;
					var uName = this.dataset.name || (uid === 0 ? '' : this.textContent.trim());
					KZ.filters.assignee = uid;
					KZ.filters.assigneeName = uid === 0 ? '' : uName;
					assigneeDropdown.style.display = 'none';
					assigneeTrigger.setAttribute('aria-expanded', 'false');
					KZ.applyFilters();
					KZ.performDBSearch();
				});
			});
		}

		// Command palette
		var cmdBtn = document.getElementById('kanbanz-cmd-palette-btn');
		if (cmdBtn) cmdBtn.addEventListener('click', KZ.openCmdPalette);
		var cmdInput = document.getElementById('kanbanz-cmd-input');
		if (cmdInput) {
			cmdInput.addEventListener('input', function() { KZ.updateCmdResults(this.value); });
			cmdInput.addEventListener('keydown', function(e) {
				if (e.key === 'Escape') KZ.closeCmdPalette();
				if (e.key === 'Enter') {
					var first = document.querySelector('.kanbanz-cmd-item');
					if (first) first.click();
				}
			});
		}
		var cmdOverlay = document.querySelector('.kanbanz-cmd-palette__overlay');
		if (cmdOverlay) cmdOverlay.addEventListener('click', KZ.closeCmdPalette);
		$$('.kanbanz-cmd-palette__container').forEach(function() {});
		document.addEventListener('click', function(e) {
			if (e.target.closest('.kanbanz-cmd-item')) {
				var action = e.target.closest('.kanbanz-cmd-item').dataset.action;
				KZ.closeCmdPalette();
				switch(action) {
					case 'new-task': var fb = document.querySelector('.kanbanz-column__body'); if (fb) KZ.showTaskInput(fb.dataset.status); break;
					case 'my-tasks': var chip = document.getElementById('kanbanz-my-tasks-chip'); if (chip) chip.click(); break;
					case 'theme': KZ.toggleTheme(); break;
					case 'board': KZ.setLayout('board'); break;
					case 'list': KZ.setLayout('list'); break;
					case 'open-task': var tid = e.target.closest('.kanbanz-cmd-item').dataset.taskId; if (tid) KZ.openTask(parseInt(tid)); break;
				}
			}
		});

		// Keyboard shortcuts
		document.addEventListener('keydown', function(e) {
			// Don't trigger shortcuts when typing in inputs/contenteditable
			if (e.key === 'Escape') {
				if (KZ.selectedTasks && KZ.selectedTasks.size > 0) {
					KZ.clearSelection();
				}
				KZ.closePanel();
				KZ.closeCmdPalette();
				KZ.hideColumnMenu();
				KZ.hideBoardSettings();
				KZ.hideShortcuts();
			}
			if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); KZ.openCmdPalette(); }
			if (e.key === '/' && !KZ.ui.commandOpen) { e.preventDefault(); var si = document.getElementById('kanbanz-search-input'); if (si) si.focus(); }
			if (e.key === '?' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); KZ.showShortcuts(); }
			// Single-letter shortcuts: only when no specific control is focused (i.e. body/canvas)
			if ((e.key === 'n' || e.key === 'm') && (e.target === document.body || e.target.id === 'kanbanz-app' || e.target.classList && e.target.classList.contains('kanbanz-canvas'))) {
				if (e.key === 'n') { var fb = document.querySelector('.kanbanz-column__body'); if (fb) KZ.showTaskInput(fb.dataset.status); }
				if (e.key === 'm') { var chip = document.getElementById('kanbanz-my-tasks-chip'); if (chip) chip.click(); }
			}
		});

		// Open task panel on Enter/Space when a card has focus
		document.addEventListener('keydown', function(e) {
			if (e.key !== 'Enter' && e.key !== ' ') return;
			var card = e.target.closest && e.target.closest('.kanbanz-card');
			if (card && card.getAttribute('role') === 'button') {
				e.preventDefault();
				var taskId = parseInt(card.dataset.taskId);
				if (taskId) KZ.openTask(taskId);
			}
		});
	}

	// --- Column Actions ---
	KZ.addStage = function(name) {
		// Optimistic: insert a placeholder column immediately, commit via AJAX, rollback on failure.
		var canvas = document.getElementById('kanbanz-canvas');
		var addColTile = document.getElementById('kanbanz-add-column');
		if (!canvas) return;
		var optimisticCol = KZ._buildColumnDOM(name, 0, [], 0);
		canvas.insertBefore(optimisticCol, addColTile);
		KZ.columns.push(name);
		updateAllCounts();
		ajax('kanbanz_save_status', { val: name, parent: KZ.config.board_id }, function(res) {
			if (res.success) {
				KZ.Toast.success(t('addStage', 'Add Stage') + ' — ' + escHtml(name));
				// Re-bind events on the new column
				KZ._bindColumnEvents(optimisticCol);
				// Re-init sortable on the new column body
				if (typeof KZ.initTaskSortable === 'function') {
					KZ.initTaskSortable(optimisticCol);
				}
			} else {
				// Rollback
				optimisticCol.remove();
				var idx = KZ.columns.indexOf(name);
				if (idx > -1) KZ.columns.splice(idx, 1);
				updateAllCounts();
				KZ.Toast.error(res.data && res.data.message ? res.data.message : t('errorAddingStage', 'Error adding stage'));
			}
		});
	};

	// Build a column DOM element (used for optimistic add-stage)
	KZ._buildColumnDOM = function(status, order, tasks, wipLimit) {
		var col = document.createElement('div');
		col.className = 'kanbanz-column';
		col.dataset.status = status;
		col.dataset.order = String(order);
		var html = '<div class="kanbanz-column__header">'
			+ '<div class="kanbanz-column__header-left">'
			+ '<button type="button" class="kanbanz-column__collapse-btn" aria-label="' + escAttr(t('addStage', 'Collapse')) + '" aria-expanded="true"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>'
			+ '<span class="kanbanz-column__dot" aria-hidden="true"></span>'
			+ '<h3 class="kanbanz-column__title">' + escHtml(status) + '</h3>'
			+ '<span class="kanbanz-column__count" data-count="0" data-wip-limit="0" aria-label="0">0</span>'
			+ '</div>'
			+ '<div class="kanbanz-column__header-right">'
			+ '<button type="button" class="kanbanz-icon-btn kanbanz-icon-btn--sm kanbanz-column__add" aria-label="' + escAttr(t('addTask', 'Add task')) + '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></button>'
			+ '<button type="button" class="kanbanz-icon-btn kanbanz-icon-btn--sm kanbanz-column__menu-trigger" aria-label="' + escAttr(t('addStage', 'Column options')) + '" aria-haspopup="true" aria-expanded="false" aria-controls="kanbanz-column-menu"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></button>'
			+ '</div></div>'
			+ '<div class="kanbanz-column__body" data-status="' + escAttr(status) + '"></div>'
			+ '<div class="kanbanz-column__add-task" data-status="' + escAttr(status) + '">'
			+ '<button type="button" class="kanbanz-column__add-btn"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> ' + escHtml(t('addTask', 'Add task')) + '</button>'
			+ '</div>';
		col.innerHTML = html;
		return col;
	};

	// Bind events for a single (newly added) column
	KZ._bindColumnEvents = function(col) {
		var collapseBtn = col.querySelector('.kanbanz-column__collapse-btn');
		if (collapseBtn) {
			collapseBtn.addEventListener('click', function() {
				col.classList.toggle('kanbanz-column--collapsed');
				var ic = this.querySelector('.dashicons');
				if (ic) ic.className = col.classList.contains('kanbanz-column--collapsed') ? 'dashicons dashicons-arrow-right-alt2' : 'dashicons dashicons-arrow-left-alt2';
			});
		}
		var addBtn = col.querySelector('.kanbanz-column__add');
		if (addBtn) {
			addBtn.addEventListener('click', function(e) {
				e.stopPropagation();
				KZ.showTaskInput(col.querySelector('.kanbanz-column__body').dataset.status);
			});
		}
		var addTaskBtn = col.querySelector('.kanbanz-column__add-btn');
		if (addTaskBtn) {
			addTaskBtn.addEventListener('click', function() {
				KZ.showTaskInput(this.closest('.kanbanz-column__add-task').dataset.status);
			});
		}
		var menuTrigger = col.querySelector('.kanbanz-column__menu-trigger');
		if (menuTrigger) {
			menuTrigger.addEventListener('click', function(e) {
				e.stopPropagation();
				KZ.showColumnMenu(col, e);
			});
		}
	};

	KZ.renameColumn = function(status) {
		var col = document.querySelector('[data-status="' + status + '"].kanbanz-column');
		if (!col) return;
		var titleEl = col.querySelector('.kanbanz-column__title');
		if (!titleEl) return;
		titleEl.contentEditable = 'true';
		titleEl.focus();
		var range = document.createRange();
		range.selectNodeContents(titleEl);
		var sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange(range);
		var originalText = titleEl.textContent;

		function finishRename() {
			titleEl.contentEditable = 'false';
			var newName = titleEl.textContent.trim();
			if (newName && newName !== originalText) {
				ajax('kanbanz_handle_status', { board_id: KZ.config.board_id, prev_val: originalText, val: newName }, function(res) {
					if (res.success) {
						col.dataset.status = newName;
						var body = col.querySelector('.kanbanz-column__body');
						if (body) body.dataset.status = newName;
						KZ.Toast.success(t('columnRenamed', 'Column renamed'));
					} else {
						titleEl.textContent = originalText;
						KZ.Toast.error(t('errorRenamingColumn', 'Error renaming column'));
					}
				});
			} else {
				titleEl.textContent = originalText;
			}
		}
		titleEl.addEventListener('blur', finishRename, { once: true });
		titleEl.addEventListener('keydown', function(e) {
			if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); }
			if (e.key === 'Escape') { titleEl.textContent = originalText; titleEl.blur(); }
		});
	};

	KZ.deleteColumn = function(status) {
		KZ.confirm({
			title: t('delete', 'Delete'),
			message: t('confirmDeleteColumn', 'Delete this column and move all its tasks? This cannot be undone.'),
			confirmText: t('delete', 'Delete'),
			cancelText: t('cancel', 'Cancel'),
			danger: true
		}).then(function(result) {
			if (!result.ok) return;
			var statuses = KZ.columns;
			var targetStatus = statuses.find(function(s) { return s !== status; });
			if (!targetStatus) { KZ.Toast.error(t('cannotDeleteOnlyColumn', 'Cannot delete the only column')); return; }
			// Snapshot for rollback
			var colEl = document.querySelector('.kanbanz-column[data-status="' + status.replace(/"/g, '\\"') + '"]');
			var colIndex = colEl ? Array.from(colEl.parentNode.children).indexOf(colEl) : -1;
			var tasksInColumn = colEl ? Array.from(colEl.querySelectorAll('.kanbanz-card')) : [];
			var targetBody = getColBody(targetStatus);
			// Optimistic: move all cards to the target column, then remove the column
			if (colEl && targetBody) {
				tasksInColumn.forEach(function(card) { targetBody.appendChild(card); });
				colEl.remove();
			}
			// Update local state
			var newStatuses = statuses.filter(function(s) { return s !== status; });
			KZ.columns = newStatuses;
			// Update task statuses in the map
			tasksInColumn.forEach(function(card) {
				var tid = parseInt(card.dataset.taskId);
				var tk = KZ.tasks.get(tid);
				if (tk) tk.task_status = targetStatus;
			});
			updateCount(targetStatus);
			updateAllCounts();
			// Commit: move tasks on server, then remove status from board
			ajax('kanbanz_move_all_tasks', { board_id: KZ.config.board_id, prev_status: status, new_status: targetStatus }, function(res) {
				if (res.success) {
					ajax('kanbanz_update_status_order', { board_id: KZ.config.board_id, order: newStatuses }, function(res2) {
						if (res2.success) {
							KZ.Toast.success(t('columnRenamed', 'Column deleted'));
						} else {
							KZ._rollbackDeleteColumn(colEl, colIndex, tasksInColumn, status, targetStatus);
							KZ.Toast.error(t('errorMovingTasks', 'Error moving tasks'));
						}
					});
				} else {
					KZ._rollbackDeleteColumn(colEl, colIndex, tasksInColumn, status, targetStatus);
					KZ.Toast.error(t('errorMovingTasks', 'Error moving tasks'));
				}
			});
		});
	};

	// Rollback for deleteColumn: re-insert the column at its old index, move tasks back
	KZ._rollbackDeleteColumn = function(colEl, colIndex, tasksInColumn, status, targetStatus) {
		var canvas = document.getElementById('kanbanz-canvas');
		if (!canvas) return;
		if (colEl && colIndex >= 0) {
			var ref = canvas.children[colIndex] || null;
			canvas.insertBefore(colEl, ref);
		}
		var body = colEl ? colEl.querySelector('.kanbanz-column__body') : null;
		if (body) {
			tasksInColumn.forEach(function(card) { body.appendChild(card); });
		}
		tasksInColumn.forEach(function(card) {
			var tid = parseInt(card.dataset.taskId);
			var tk = KZ.tasks.get(tid);
			if (tk) tk.task_status = status;
		});
		if (!KZ.columns.includes(status)) KZ.columns.push(status);
		// Restore original order
		KZ.columns.sort(function() { return 0; }); // no-op; original order not tracked precisely
		updateCount(targetStatus);
		if (body) updateCount(status);
		updateAllCounts();
	};

	KZ.sortColumn = function(status, sortBy) {
		var order = localStorage.getItem('kanbanz_sort_' + status) || 'ASC';
		var body = getColBody(status);
		if (!body) return;
		// Snapshot for rollback
		var prevCards = Array.from(body.children);
		// Optimistic: sort the DOM
		var cards = Array.from(body.querySelectorAll('.kanbanz-card'));
		var dir = order === 'ASC' ? 1 : -1;
		cards.sort(function(a, b) {
			var ta = KZ.tasks.get(parseInt(a.dataset.taskId));
			var tb = KZ.tasks.get(parseInt(b.dataset.taskId));
			var va = '', vb = '';
			if (sortBy === 'date') {
				va = ta ? String(ta.id) : ''; vb = tb ? String(tb.id) : ''; // higher id = newer; server uses modified date
				// We don't have modified date client-side; fall back to id (recent first when DESC)
				var na = parseInt(va) || 0, nb = parseInt(vb) || 0;
				return (nb - na) * dir;
			} else if (sortBy === 'priority') {
				var ranks = { urgent: 4, high: 3, medium: 2, low: 1, normal: 0, '': 0 };
				va = ta && ta.task_meta ? ranks[ta.task_meta.priority || ''] || 0 : 0;
				vb = tb && tb.task_meta ? ranks[tb.task_meta.priority || ''] || 0 : 0;
				return (va - vb) * dir * -1; // urgent first
			}
			return 0;
		});
		cards.forEach(function(c) { body.appendChild(c); });
		// Commit
		ajax('kanbanz_sort_all_tasks', { board_id: KZ.config.board_id, status: status, sort_by: sortBy, sort_order: order }, function(res) {
			if (res.success) {
				localStorage.setItem('kanbanz_sort_' + status, order === 'ASC' ? 'DESC' : 'ASC');
				KZ.Toast.success(t('saved', 'Saved'));
			} else {
				// Rollback: restore previous order
				prevCards.forEach(function(c) { body.appendChild(c); });
				KZ.Toast.error(t('errorSortingTasks', 'Error sorting tasks'));
			}
		});
	};

	// --- Init ---
	KZ.init = function(config) {
		KZ.config = config || {};
		if (window.kanbanzData) {
			Object.assign(KZ.config, window.kanbanzData);
			KZ.columns = KZ.config.statuses || [];
			KZ.ui.theme = KZ.config.theme || 'light';
			KZ.ui.layout = KZ.config.layout || 'board';
			// Load tasks into map
			var tasks = KZ.config.tasks || {};
			Object.keys(tasks).forEach(function(status) {
				(tasks[status] || []).forEach(function(task) {
					if (task.task_meta) task.task_status = status;
					KZ.tasks.set(task.id, task);
				});
			});
		}
		// Load theme from localStorage (or shared controller)
		try {
			var saved = localStorage.getItem('kanbanz_theme');
			if (saved) { KZ.ui.theme = saved; }
			else if (window.kanbanzTheme && typeof window.kanbanzTheme.current === 'function') {
				KZ.ui.theme = window.kanbanzTheme.current();
			}
		} catch(e) {}
		KZ.applyTheme(KZ.ui.theme);

		initEvents();
		updateAllCounts();
		KZ.updateWipDisplay();
		KZ.updateEmptyStates();
		KZ.loadSavedFilters();
		KZ.applyFilters();

		// Notifications
		KZ.startNotificationPolling();
		var bell = document.getElementById('kanbanz-notification-bell');
		if (bell) bell.addEventListener('click', function(e) { e.stopPropagation(); KZ.toggleNotificationDropdown(); });
		var markAllBtn = document.getElementById('kanbanz-notification-mark-all');
		if (markAllBtn) markAllBtn.addEventListener('click', function(e) { e.stopPropagation(); KZ.markNotificationsRead(); KZ.loadNotifications(); });
		document.addEventListener('click', function(e) {
			var dd = document.getElementById('kanbanz-notification-dropdown');
			var bell2 = document.getElementById('kanbanz-notification-bell');
			if (dd && dd.style.display !== 'none' && !dd.contains(e.target) && !bell2.contains(e.target)) {
				KZ.toggleNotificationDropdown(false);
			}
		});

		// Pause notifications polling when tab is hidden to save battery/CPU
		document.addEventListener('visibilitychange', function() {
			if (document.hidden) {
				if (KZ.notifications.pollTimer) { clearInterval(KZ.notifications.pollTimer); KZ.notifications.pollTimer = null; }
			} else {
				if (!KZ.notifications.pollTimer) KZ.startNotificationPolling();
			}
		});

		// Deep linking
		if (window.location.hash && window.location.hash.indexOf('#task-') === 0) {
			var taskId = parseInt(window.location.hash.replace('#task-', ''));
			if (taskId) setTimeout(function() { KZ.openTask(taskId); }, 300);
		}

		// Browser back/forward support
		window.addEventListener('hashchange', function() {
			if (window.location.hash && window.location.hash.indexOf('#task-') === 0) {
				var tid = parseInt(window.location.hash.replace('#task-', ''));
				if (tid) KZ.openTask(tid);
			} else if (KZ.ui.panelOpen) {
				KZ.closePanel();
			}
		});
	};

	// --- Notifications ---
	KZ.notifications = { unread: 0, pollTimer: null };

	KZ.loadNotifications = function() {
		ajax('kanbanz_get_notifications', {}, function(res) {
			if (res && res.success) {
				KZ.renderNotifications(res.data.notifications || [], res.data.unread_count || 0);
			}
		});
	};

	KZ.renderNotifications = function(notifications, unreadCount) {
		KZ.notifications.unread = unreadCount;
		var bell = document.getElementById('kanbanz-notification-bell');
		var countEl = document.getElementById('kanbanz-notification-count');
		var listEl = document.getElementById('kanbanz-notification-list');

		if (!bell || !countEl || !listEl) return;

		if (unreadCount > 0) {
			countEl.textContent = unreadCount > 99 ? '99+' : unreadCount;
			countEl.style.display = '';
			bell.classList.add('kanbanz-notification-bell--has-unread');
		} else {
			countEl.style.display = 'none';
			bell.classList.remove('kanbanz-notification-bell--has-unread');
		}

		if (notifications.length === 0) {
			listEl.innerHTML = '<div class="kanbanz-notification-dropdown__empty">' + escHtml(t('noNotifications', 'No notifications')) + '</div>';
			return;
		}

		var html = '';
		notifications.forEach(function(n) {
			var timeAgo = KZ.timeAgo(n.created_at);
			var isUnread = n.is_read == '0' || n.is_read === 0;
			var typeIcon = n.type === 'mention' ? 'dashicons-admin-comments' : 'dashicons-bell';
			var taskTitle = escHtml(n.task_title || '');
			var msg = escHtml(n.message || '');
			html += '<div class="kanbanz-notification-item' + (isUnread ? ' kanbanz-notification-item--unread' : '') + '" data-id="' + n.id + '" data-task-id="' + n.task_id + '" data-board-id="' + n.board_id + '">';
			html += '  <div class="kanbanz-notification-item__icon"><span class="dashicons ' + typeIcon + '"></span></div>';
			html += '  <div class="kanbanz-notification-item__content">';
			html += '    <div class="kanbanz-notification-item__message">' + msg + '</div>';
			if (taskTitle) {
				html += '    <div class="kanbanz-notification-item__task">' + taskTitle + '</div>';
			}
			html += '    <div class="kanbanz-notification-item__time">' + timeAgo + '</div>';
			html += '  </div>';
			html += '</div>';
		});
		listEl.innerHTML = html;

		listEl.querySelectorAll('.kanbanz-notification-item').forEach(function(item) {
			item.addEventListener('click', function() {
				var taskId = this.dataset.taskId;
				var boardId = this.dataset.boardId;
				var nId = parseInt(this.dataset.id);
				KZ.markNotificationsRead([nId]);
				if (taskId) {
					KZ.openTask(parseInt(taskId));
				}
				KZ.toggleNotificationDropdown(false);
			});
		});
	};

	KZ.toggleNotificationDropdown = function(forceState) {
		var dd = document.getElementById('kanbanz-notification-dropdown');
		var bell = document.getElementById('kanbanz-notification-bell');
		if (!dd) return;
		var isOpen = dd.style.display !== 'none';
		var show = typeof forceState === 'boolean' ? forceState : !isOpen;
		dd.style.display = show ? '' : 'none';
		if (bell) bell.setAttribute('aria-expanded', show ? 'true' : 'false');
		// NOTE: Do NOT mark-all-read on open — that was destructive (a glance = unread lost).
		// Users mark read explicitly via "Mark all read" or by clicking individual notifications.
		if (show) KZ.loadNotifications();
	};

	KZ.markNotificationsRead = function(ids) {
		ajax('kanbanz_mark_notifications_read', { notification_ids: ids || [] }, function(res) {
			if (res && res.success) {
				KZ.notifications.unread = res.data.unread_count || 0;
				var countEl = document.getElementById('kanbanz-notification-count');
				var bell = document.getElementById('kanbanz-notification-bell');
				if (countEl) {
					if (KZ.notifications.unread > 0) {
						countEl.textContent = KZ.notifications.unread > 99 ? '99+' : KZ.notifications.unread;
						countEl.style.display = '';
					} else {
						countEl.style.display = 'none';
					}
				}
				if (bell) {
					if (KZ.notifications.unread > 0) {
						bell.classList.add('kanbanz-notification-bell--has-unread');
					} else {
						bell.classList.remove('kanbanz-notification-bell--has-unread');
					}
				}
				var items = document.querySelectorAll('.kanbanz-notification-item--unread');
				items.forEach(function(el) { el.classList.remove('kanbanz-notification-item--unread'); });
			}
		});
	};

	KZ.startNotificationPolling = function() {
		if (KZ.notifications.pollTimer) clearInterval(KZ.notifications.pollTimer);
		KZ.loadNotifications();
		KZ.notifications.pollTimer = setInterval(function() {
			KZ.loadNotifications();
		}, 60000);
	};

	window.KanbanzEditor = KZ;
	window.KZ = KZ;

	// Auto-init when DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() { if (window.kanbanzData) KZ.init(); });
	} else {
		if (window.kanbanzData) KZ.init();
	}
})();