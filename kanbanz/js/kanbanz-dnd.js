/**
 * Kanbanz — Drag & Drop Module
 * Uses WordPress default jQuery UI Sortable (jquery-ui-sortable).
 */
(function($) {
	'use strict';

	var KZ = window.KanbanzEditor || window.KZ;
	if (!KZ) return;

	var _prevColumnOrder = null;
	var _draggedTaskId = 0;

	// ---- Task sorting (within a column & across columns) ----
	function initTaskSortable(container) {
		var $bodies;
		if (container) {
			var $c = $(container);
			$bodies = $c.hasClass('kanbanz-column__body') ? $c : $c.find('.kanbanz-column__body');
		} else {
			$bodies = $('.kanbanz-column__body');
		}

		$bodies.each(function() {
			var $body = $(this);
			if ($body.data('sortable-initialized')) return;
			$body.data('sortable-initialized', true);

			$body.sortable({
				connectWith: '.kanbanz-column__body',
				placeholder: 'kanbanz-card-sortable-placeholder',
				items: '.kanbanz-card',
				cursor: 'grabbing',
				distance: 3,
				start: function(event, ui) {
					_draggedTaskId = parseInt(ui.item.attr('data-task-id')) || 0;
					// Remembered so a blocked drop (see update: below) can be
					// reverted to where the card actually started.
					ui.item.data('kanbanz-origin-col', ui.item.parent()[0]);
					ui.item.data('kanbanz-origin-index', ui.item.index());
					if (ui.item && ui.placeholder) {
						ui.placeholder.height(ui.item.outerHeight());
					}
				},
				stop: function(event, ui) {
					var taskId = parseInt(ui.item.attr('data-task-id'));
					_draggedTaskId = taskId || 0;
					setTimeout(function() { _draggedTaskId = 0; }, 400);
				},
				update: function(event, ui) {
					// Only process on the target container where item is dropped
					if (this !== ui.item.parent()[0]) return;

					var taskId = parseInt(ui.item.attr('data-task-id'));
					var $newCol = $(this);
					var newStatus = $newCol.attr('data-status');
					var oldStatus = ui.sender ? ui.sender.attr('data-status') : newStatus;

					if (!taskId || !newStatus) return;

					// Server-side enforcement (kanbanz_tasks_status) is the
					// real gate; this is just a same-tick UX affordance so a
					// blocked drop doesn't look like it silently worked, then
					// snap back after a round-trip.
					var cfg = KZ.config || {};
					if (newStatus === cfg.terminalStatus && cfg.canCompleteTasks === false) {
						var $origin = $(ui.item.data('kanbanz-origin-col'));
						var originIndex = ui.item.data('kanbanz-origin-index');
						if ($origin.length) {
							var $siblings = $origin.find('.kanbanz-card');
							if (originIndex >= $siblings.length) {
								$origin.append(ui.item);
							} else {
								ui.item.insertBefore($siblings.eq(originIndex));
							}
						}
						if (KZ.Toast && typeof KZ.Toast.error === 'function') {
							KZ.Toast.error('You do not have permission to mark tasks Completed on this board.');
						}
						return;
					}

					var order = [];
					$newCol.find('.kanbanz-card').each(function() {
						var id = parseInt($(this).attr('data-task-id'));
						if (id) order.push(id);
					});

					if (typeof KZ.updateCount === 'function') {
						KZ.updateCount(newStatus);
						if (oldStatus && oldStatus !== newStatus) {
							KZ.updateCount(oldStatus);
						}
					}
					if (typeof KZ.updateAllCounts === 'function') {
						KZ.updateAllCounts();
					}

					KZ.moveTask(taskId, newStatus, order);
				}
			});
		});
	}

	// ---- Column sorting ----
	function initColumnSortable() {
		var $canvas = $('#kanbanz-canvas');
		if (!$canvas.length || $canvas.data('column-sortable-initialized')) return;
		$canvas.data('column-sortable-initialized', true);

		$canvas.sortable({
			items: '.kanbanz-column:not(.kanbanz-column--add)',
			handle: '.kanbanz-column__header',
			placeholder: 'kanbanz-column-sortable-placeholder',
			cursor: 'grabbing',
			distance: 5,
			start: function(event, ui) {
				_prevColumnOrder = $canvas.find('.kanbanz-column:not(.kanbanz-column--add)').toArray();
				if (ui.item && ui.placeholder) {
					ui.placeholder.height(ui.item.outerHeight()).width(ui.item.outerWidth());
				}
			},
			update: function(event, ui) {
				var order = [];
				$canvas.find('.kanbanz-column:not(.kanbanz-column--add)').each(function() {
					var status = $(this).attr('data-status');
					if (status) order.push(status);
				});

				var fd = new FormData();
				fd.append('action', 'kanbanz_update_status_order');
				fd.append('_wpnonce', KZ.config.nonce || (window.kanbanzVars ? kanbanzVars.ajax_nonce : ''));
				fd.append('board_id', KZ.config.board_id);
				order.forEach(function(s) {
					fd.append('order[]', s);
				});

				fetch(KZ.config.ajaxurl || (window.kanbanzVars ? kanbanzVars.ajaxurl : ''), {
					method: 'POST',
					body: fd,
					credentials: 'same-origin'
				}).then(function(r) { return r.json(); })
				.then(function(res) {
					if (res.success) {
						if (KZ.Toast) KZ.Toast.success(typeof KZ.config.i18n === 'object' && KZ.config.i18n && KZ.config.i18n.saved ? KZ.config.i18n.saved : 'Saved');
						KZ.columns = order;
						_prevColumnOrder = null;
					} else {
						if (KZ.Toast) KZ.Toast.error(typeof KZ.config.i18n === 'object' && KZ.config.i18n && KZ.config.i18n.errorGeneric ? KZ.config.i18n.errorGeneric : 'Something went wrong');
						KZ._rollbackColumnOrder();
					}
				}).catch(function() {
					if (KZ.Toast) KZ.Toast.error(typeof KZ.config.i18n === 'object' && KZ.config.i18n && KZ.config.i18n.errorGeneric ? KZ.config.i18n.errorGeneric : 'Network error');
					KZ._rollbackColumnOrder();
				});
			}
		});
	}

	// Rollback column order on error
	KZ._rollbackColumnOrder = function() {
		if (!_prevColumnOrder || !_prevColumnOrder.length) return;
		var $canvas = $('#kanbanz-canvas');
		var addTile = document.getElementById('kanbanz-add-column');
		_prevColumnOrder.forEach(function(col) {
			$canvas.append(col);
		});
		if (addTile) $canvas.append(addTile);
		_prevColumnOrder = null;
	};

	// Expose methods for editor.js
	KZ.initTaskSortable = initTaskSortable;
	KZ.getDraggedTaskId = function() { return _draggedTaskId; };

	function initDnD() {
		if (typeof $.fn.sortable === 'undefined') {
			console.warn('jQuery UI Sortable (wordpress default) not loaded. Drag & drop disabled.');
			return;
		}

		initTaskSortable();
		initColumnSortable();

		var canvas = document.getElementById('kanbanz-canvas');
		if (canvas && window.MutationObserver) {
			var observer = new MutationObserver(function(mutations) {
				mutations.forEach(function(mutation) {
					if (mutation.addedNodes && mutation.addedNodes.length) {
						var needsInit = false;
						for (var i = 0; i < mutation.addedNodes.length; i++) {
							var node = mutation.addedNodes[i];
							if (node.nodeType === 1 && ($(node).hasClass('kanbanz-column') || $(node).hasClass('kanbanz-column__body') || $(node).find('.kanbanz-column__body').length)) {
								needsInit = true;
								break;
							}
						}
						if (needsInit) {
							initTaskSortable();
						}
					}
				});
			});
			observer.observe(canvas, { childList: true, subtree: true });
		}
	}

	KZ.initDnD = initDnD;

	$(document).ready(function() {
		initDnD();
	});
})(jQuery);