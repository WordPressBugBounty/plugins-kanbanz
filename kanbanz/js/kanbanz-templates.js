/**
 * Kanbanz "Add New Board" page: template picker, custom stages and board creation.
 */
jQuery(document).ready(function($) {
	var KZ = window.KanbanzEditor || window.KZ;
	var ajaxurl = kanbanzTemplates.ajaxUrl;
	var nonce = kanbanzTemplates.nonce;

	// "Only team members" with nobody picked would create a board visible to everyone
	function teamIsValid(picker) {
		return !window.KanbanzTeamPicker || KanbanzTeamPicker.validate(picker);
	}

	function notify(msg, type) {
		if (KZ && KZ.Toast) { KZ.Toast[type === 'error' ? 'error' : 'success'](msg); }
		else { alert(msg); }
	}

	// ----- Template quick-create: dialog with the board name and who can see it -----
	var $tplModal = $('#kanbanz-template-modal');
	var tplPicker = document.getElementById('kanbanz-template-team-picker');
	var tplOpener = null;

	function escHtml(str) {
		return String(str == null ? '' : str).replace(/[&<>"']/g, function(c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function openTemplateModal(btn) {
		tplOpener = btn;
		var $btn = $(btn);
		$('#kanbanz-template-key').val($btn.data('template-key'));
		$('#kanbanz-template-board-name').val($btn.data('template-name'));
		$('#kanbanz-template-modal-title').text($btn.data('template-name'));
		var stages = $btn.data('template-stages') || [];
		$('#kanbanz-template-stages').html(stages.map(function(st) { return '<span class="kanbanz-chip">' + escHtml(st) + '</span>'; }).join(''));
		if (window.KanbanzTeamPicker) KanbanzTeamPicker.reset(tplPicker);
		$tplModal.show();
		$('#kanbanz-template-board-name').trigger('focus').trigger('select');
	}
	function closeTemplateModal() {
		$tplModal.hide();
		$('#kanbanz-template-submit').prop('disabled', false).find('.kanbanz-spinner').hide();
		if (tplOpener) tplOpener.focus();
	}

	$('.kanbanz-use-template-btn').on('click', function() { openTemplateModal(this); });
	$('#kanbanz-template-modal-close, #kanbanz-template-cancel').on('click', closeTemplateModal);
	$tplModal.on('click', function(e) { if (e.target === this) closeTemplateModal(); });
	$(document).on('keydown', function(e) { if (e.key === 'Escape' && $tplModal.is(':visible')) closeTemplateModal(); });

	$('#kanbanz-template-form').on('submit', function(e) {
		e.preventDefault();
		var name = $.trim($('#kanbanz-template-board-name').val());
		if (!name) { $('#kanbanz-template-board-name').trigger('focus'); return; }
		if (!teamIsValid(tplPicker)) return;

		var $btn = $('#kanbanz-template-submit');
		$btn.prop('disabled', true).find('.kanbanz-spinner').show();
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'kanbanz_create_board_from_template',
				_wpnonce: nonce,
				template_key: $('#kanbanz-template-key').val(),
				title: name,
				assigned_id: window.KanbanzTeamPicker ? KanbanzTeamPicker.selected(tplPicker) : []
			},
			success: function(res) {
				if (res.success && res.data.redirect) {
					window.location.href = res.data.redirect;
				} else {
					notify((res.data && res.data.message) ? res.data.message : kanbanzTemplates.i18n.errorCreating, 'error');
					$btn.prop('disabled', false).find('.kanbanz-spinner').hide();
				}
			},
			error: function() {
				notify(kanbanzTemplates.i18n.networkError, 'error');
				$btn.prop('disabled', false).find('.kanbanz-spinner').hide();
			}
		});
	});

	// ----- Toggle between the templates grid and the custom "Create New" form -----
	function showCustomForm() {
		$('#kanbanz-templates-grid-section').hide();
		$('#kanbanz-custom-board-section').show();
	}
	function showTemplatesGrid() {
		$('#kanbanz-custom-board-section').hide();
		$('#kanbanz-templates-grid-section').show();
	}
	$('#kanbanz-create-new-card').on('click', showCustomForm);
	$('#kanbanz-back-to-templates, #kanbanz-custom-board-cancel').on('click', showTemplatesGrid);

	// ----- Custom form: stage tag editor -----
	$(document).on('click', '.kanbanz-stage-tag__remove', function() {
		$(this).parent().remove();
	});

	$('#kanbanz-new-stage-input').on('keydown', function(e) {
		if (e.key !== 'Enter') return;
		e.preventDefault();
		var val = $(this).val().trim();
		if (!val) return;
		var exists = $('.kanbanz-stage-tag[data-stage]').toArray().some(function(t) {
			return $(t).data('stage').toString().toLowerCase() === val.toLowerCase();
		});
		if (exists) { notify(kanbanzTemplates.i18n.stageExists, 'error'); return; }

		var $tag = $('<div class="kanbanz-stage-tag"></div>').attr('data-stage', val).text(val);
		var $remove = $('<button type="button" class="kanbanz-stage-tag__remove">&times;</button>').attr('aria-label', kanbanzTemplates.i18n.removeStage + ': ' + val);
		$tag.append(' ').append($remove);
		$('#kanbanz-add-stage-tag').before($tag);
		$(this).val('');
	});

	// ----- Custom form: submit -----
	$('#kanbanz-new-board-form').on('submit', function(e) {
		e.preventDefault();
		var $form = $(this);
		var stages = $('.kanbanz-stage-tag[data-stage]').toArray().map(function(t) { return $(t).data('stage').toString(); });
		if (!stages.length) {
			notify(kanbanzTemplates.i18n.addStage, 'error');
			return;
		}
		if (!teamIsValid(document.getElementById('kanbanz-new-board-team-picker'))) return;

		var $spinner = $('#kanbanz-create-spinner');
		var $btn = $('#kanbanz-submit-new-board');
		$spinner.show();
		$btn.prop('disabled', true);

		var fd = new FormData($form[0]); // includes the ticked assigned_id[] people from the form's picker
		fd.append('action', 'kanbanz_create_board');
		fd.append('_wpnonce', nonce);
		fd.append('custom_stages', JSON.stringify(stages));

		fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res.success && res.data.post_id) {
					var boardId = res.data.post_id;
					var updateFd = new FormData();
					updateFd.append('action', 'kanbanz_update_status_order');
					updateFd.append('_wpnonce', nonce);
					updateFd.append('board_id', boardId);
					stages.forEach(function(s) { updateFd.append('order[]', s); });

					fetch(ajaxurl, { method: 'POST', body: updateFd, credentials: 'same-origin' })
						.then(function() {
							window.location.href = kanbanzTemplates.boardUrl + boardId;
						})
						.catch(function() {
							notify(kanbanzTemplates.i18n.stagesNotSaved, 'error');
							setTimeout(function() { window.location.href = kanbanzTemplates.boardUrl + boardId; }, 1200);
						});
				} else {
					notify((res.data && res.data.message) ? res.data.message : kanbanzTemplates.i18n.errorCreating, 'error');
					$spinner.hide();
					$btn.prop('disabled', false);
				}
			})
			.catch(function() {
				notify(kanbanzTemplates.i18n.networkError, 'error');
				$spinner.hide();
				$btn.prop('disabled', false);
			});
	});
});
