<?php
/**
 * Settings page for Kanbanz.
 *
 * @package kanbanz
 */

if(!defined('ABSPATH')) exit;

/**
 * Render the Kanbanz Settings admin page.
 */
function kanbanz_render_settings_page() {
	if(!current_user_can('manage_options')) return;

	if(isset($_POST['kanbanz_save_native_settings']) && check_admin_referer('kanbanz_native_settings')) {
		$settings = array(
			'tasks_per_page'              => isset($_POST['tasks_per_page']) ? absint($_POST['tasks_per_page']) : 50,
			'default_view'                => isset($_POST['default_view']) ? sanitize_text_field(wp_unslash($_POST['default_view'])) : 'board',
			'date_format'                 => isset($_POST['date_format']) ? sanitize_text_field(wp_unslash($_POST['date_format'])) : 'Y-m-d',
			'email_notifications_enabled' => !empty($_POST['email_notifications_enabled']) ? '1' : '0',
			'notify_on_assign'            => !empty($_POST['notify_on_assign']) ? '1' : '0',
			'notify_on_comment'           => !empty($_POST['notify_on_comment']) ? '1' : '0',
		);
		update_option('kanbanz_settings', $settings);
		if (function_exists('kanbanz_deskuss_save_settings')) {
			kanbanz_deskuss_save_settings();
		}
		echo '<div class="kanbanz-notice kanbanz-notice--success is-dismissible" style="margin: 20px 0 10px 0;"><p><strong>' . esc_html__('Settings saved successfully.', 'kanbanz') . '</strong></p></div>';
	}

	$settings = get_option('kanbanz_settings', array(
		'tasks_per_page'              => 50,
		'default_view'                => 'board',
		'date_format'                 => 'Y-m-d',
		'email_notifications_enabled' => '0',
		'notify_on_assign'            => '1',
		'notify_on_comment'           => '1',
	));

	$cron_enabled    = get_option('kanbanz_cron_enabled', 1);
	$policy          = get_option('kanbanz_dept_delete_policy', 'trash');
	$reassign_to     = (int) get_option('kanbanz_dept_reassign_board', 0);
	$last_sync       = get_option('kanbanz_last_cron_sync', array());
	$deskuss_active  = function_exists('kanbanz_deskuss_active') ? kanbanz_deskuss_active() : false;

	$boards = get_posts(array(
		'post_type'      => 'kanbanz_board',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
	));

	$sync_url = wp_nonce_url(
		admin_url('admin-post.php?action=kanbanz_run_sync'),
		'kanbanz_run_sync'
	);
	?>
	<div class="kanbanz-wrapper kanbanz-page-wrap" id="kanbanz-app">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
			<h1 style="margin:0;font-weight:700;font-size:22px;color:var(--kanbanz-text-primary, #212529);"><?php esc_html_e('Kanbanz Settings', 'kanbanz'); ?></h1>
			<button type="button" class="kanbanz-icon-btn" data-kanbanz-theme-toggle title="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-pressed="false">
				<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
			</button>
		</div>

		<div class="kanbanz-settings-card">
			<div class="kanbanz-settings-tabs">
				<a href="#tab-general" class="nav-tab-active"><?php esc_html_e('General', 'kanbanz'); ?></a>
				<a href="#tab-notifications"><?php esc_html_e('Notifications', 'kanbanz'); ?></a>
				<?php if ($deskuss_active): ?>
				<a href="#tab-deskuss"><?php esc_html_e('Deskuss Integration', 'kanbanz'); ?></a>
				<?php endif; ?>
			</div>

			<form method="post" id="kanbanz-settings-form">
				<?php wp_nonce_field('kanbanz_native_settings'); ?>

				<!-- General Tab -->
				<div id="tab-general" class="kanbanz-tab-content" style="display:block;">
					<h3 class="kanbanz-settings-section-title"><?php esc_html_e('General Configuration', 'kanbanz'); ?></h3>
                    
					<div class="kanbanz-field">
						<label for="tasks_per_page"><?php esc_html_e('Tasks Per Column / Page', 'kanbanz'); ?></label>
						<input type="number" name="tasks_per_page" id="tasks_per_page" value="<?php echo esc_attr(isset($settings['tasks_per_page']) ? $settings['tasks_per_page'] : 50); ?>" class="kanbanz-input" style="max-width:120px;" min="10" max="200" />
						<p class="kanbanz-field__hint"><?php esc_html_e('Number of tasks loaded per column initially and on scroll.', 'kanbanz'); ?></p>
					</div>

					<div class="kanbanz-field">
						<label for="default_view"><?php esc_html_e('Default Board View', 'kanbanz'); ?></label>
						<select name="default_view" id="default_view" class="kanbanz-select" style="max-width:400px;">
							<option value="board" <?php selected(isset($settings['default_view']) ? $settings['default_view'] : 'board', 'board'); ?>><?php esc_html_e('Board (Kanban Columns)', 'kanbanz'); ?></option>
							<option value="list" <?php selected(isset($settings['default_view']) ? $settings['default_view'] : 'board', 'list'); ?>><?php esc_html_e('List (Table Rows)', 'kanbanz'); ?></option>
						</select>
					</div>

					<div class="kanbanz-field">
						<label for="date_format"><?php esc_html_e('Date Display Format', 'kanbanz'); ?></label>
						<input type="text" name="date_format" id="date_format" value="<?php echo esc_attr(isset($settings['date_format']) ? $settings['date_format'] : 'Y-m-d'); ?>" class="kanbanz-input" style="max-width:400px;" />
						<p class="kanbanz-field__hint"><?php esc_html_e('PHP date format string (e.g. Y-m-d or M j, Y).', 'kanbanz'); ?></p>
					</div>
				</div>

				<!-- Notifications Tab -->
				<div id="tab-notifications" class="kanbanz-tab-content" style="display:none;">
					<h3 class="kanbanz-settings-section-title"><?php esc_html_e('Email Notifications', 'kanbanz'); ?></h3>
                    
					<div class="kanbanz-field">
						<label class="kanbanz-checkbox-label" for="email_notifications_enabled">
							<input type="checkbox" name="email_notifications_enabled" id="email_notifications_enabled" value="1" <?php checked(!empty($settings['email_notifications_enabled'])); ?> />
							<strong><?php esc_html_e('Enable email notifications via wp_mail()', 'kanbanz'); ?></strong>
						</label>
					</div>

					<div class="kanbanz-field" style="margin-left: 24px; display: <?php echo !empty($settings['email_notifications_enabled']) ? 'block' : 'none'; ?>;" id="kanbanz-notification-triggers-wrap">
						<label><?php esc_html_e('Notification Triggers:', 'kanbanz'); ?></label>
						<label class="kanbanz-checkbox-label" for="notify_on_assign">
							<input type="checkbox" name="notify_on_assign" id="notify_on_assign" value="1" <?php checked(!empty($settings['notify_on_assign'])); ?> />
							<?php esc_html_e('Send email when a user is assigned to a task', 'kanbanz'); ?>
						</label>
						<label class="kanbanz-checkbox-label" for="notify_on_comment">
							<input type="checkbox" name="notify_on_comment" id="notify_on_comment" value="1" <?php checked(!empty($settings['notify_on_comment'])); ?> />
							<?php esc_html_e('Send email for new comments on your tasks and when @mentioned', 'kanbanz'); ?>
						</label>
					</div>

					<div class="kanbanz-field" style="margin-top: 24px;">
						<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-send-test-email-btn">
							<?php esc_html_e('Send Test Email', 'kanbanz'); ?>
						</button>
						<span id="kanbanz-test-email-status" style="margin-left: 10px; font-size: 13px; font-weight: 500;"></span>
					</div>
				</div>

				<!-- Deskuss Integration Tab -->
				<?php if ($deskuss_active): ?>
				<div id="tab-deskuss" class="kanbanz-tab-content" style="display:none;">
					<?php
					if (function_exists('kanbanz_deskuss_render_settings_sections')) {
						kanbanz_deskuss_render_settings_sections($cron_enabled, $policy, $reassign_to, $last_sync, $deskuss_active, $boards, $sync_url);
					}
					?>
				</div>
				<?php endif; ?>

				<div class="kanbanz-settings-submit">
					<input type="submit" name="kanbanz_save_native_settings" class="kanbanz-btn kanbanz-btn--primary" style="padding:10px 24px;font-size:15px;" value="<?php esc_attr_e('Save Settings', 'kanbanz'); ?>" />
				</div>
			</form>
		</div>
	</div>

	<?php
}