/**
 * Kanbanz Settings page: tabs, notification toggles and test email.
 */
jQuery(document).ready(function($) {
	// Tab switching logic
	$('.kanbanz-settings-tabs a').on('click', function(e) {
		e.preventDefault();
		$('.kanbanz-settings-tabs a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.kanbanz-tab-content').hide();
		$($(this).attr('href')).show();
	});

	// Toggle triggers wrapper visibility based on email enabled
	$('#email_notifications_enabled').on('change', function() {
		if ($(this).is(':checked')) {
			$('#kanbanz-notification-triggers-wrap').slideDown(150);
		} else {
			$('#kanbanz-notification-triggers-wrap').slideUp(150);
		}
	});

	// Send Test Email AJAX trigger
	$('#kanbanz-send-test-email-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#kanbanz-test-email-status');
		$btn.prop('disabled', true).text('Sending...');
		$status.text('').css('color', '');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'kanbanz_send_test_email',
				_wpnonce: kanbanzSettings.nonce
			},
			success: function(res) {
				if (res.success) {
					$status.text(res.data.message).css('color', 'var(--kanbanz-success, #10b981)');
				} else {
					$status.text(res.data.message).css('color', 'var(--kanbanz-danger, #ef4444)');
				}
				$btn.prop('disabled', false).text('Send Test Email');
			},
			error: function() {
				$status.text('Network error.').css('color', 'var(--kanbanz-danger, #ef4444)');
				$btn.prop('disabled', false).text('Send Test Email');
			}
		});
	});
});
