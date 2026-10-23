<?php
/**
 * Fallback stubs for the Deskuss integration.
 *
 * This file is loaded by init.php when Deskuss is NOT active so the
 * rest of the plugin can call the Deskuss-aware helpers without
 * fatal errors. Every function here is a no-op / empty-return so
 * the Kanban UI simply hides Deskuss-related bits.
 *
 * When Deskuss IS active, main/deskuss/deskuss.php is loaded
 * instead and these helpers are defined there with the real
 * implementations.
 *
 * @package kanbanz
 * @subpackage deskuss
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE')) exit;
if(defined('DESKUSS_DIR')) return; // real helpers are loaded

if(!function_exists('kanbanz_deskuss_active')) {
	function kanbanz_deskuss_active() { return false; }
}
if(!function_exists('kanbanz_deskuss_enabled')) {
	function kanbanz_deskuss_enabled() { return false; }
}
if(!function_exists('kanbanz_get_user_departments')) {
	function kanbanz_get_user_departments($user_id) { return array(); }
}
if(!function_exists('kanbanz_get_all_departments')) {
	function kanbanz_get_all_departments() { return array(); }
}
if(!function_exists('kanbanz_get_department_name')) {
	function kanbanz_get_department_name($dept_id) { return ''; }
}
if(!function_exists('kanbanz_get_ticket_url_base')) {
	function kanbanz_get_ticket_url_base() { return ''; }
}
if(!function_exists('kanbanz_get_task_ticket')) {
	function kanbanz_get_task_ticket($task_id) { return ''; }
}
if(!function_exists('kanbanz_get_board_dept_id')) {
	function kanbanz_get_board_dept_id($board_id) { return ''; }
}
if(!function_exists('kanbanz_get_task_assigned_staff')) {
	function kanbanz_get_task_assigned_staff($task_id) { return get_post_meta($task_id, 'kanbanz_assigned_staff', true); }
}
if(!function_exists('kanbanz_is_board_manager')) {
	function kanbanz_is_board_manager($user_id) {
		if(!$user_id) return false;
		if(user_can($user_id, 'administrator') || user_can($user_id, 'manage_options')) return true;
		return user_can($user_id, 'kanbanz_manager');
	}
}
if(!function_exists('kanbanz_get_my_depts_for_filter')) {
	function kanbanz_get_my_depts_for_filter() { return array(); }
}
if(!function_exists('kanbanz_get_my_resolved_tickets')) {
	function kanbanz_get_my_resolved_tickets($args = array()) { return array('tickets' => array(), 'total' => 0, 'total_pages' => 0); }
}
if(!function_exists('kanbanz_count_my_pending_tasks')) {
	function kanbanz_count_my_pending_tasks() {
		global $wpdb;
		$current_user = wp_get_current_user();
		$username = $current_user->user_login;
		$accessible_boards = kanbanz_get_user_accessible_boards();
		if(empty($accessible_boards)) {
			return 0;
		}
		$accessible_boards_implode = implode(',', array_map('intval', $accessible_boards));

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'task_status' AND m1.meta_value != 'Completed'
			 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = 'kanbanz_assigned_staff' AND m2.meta_value LIKE %s
			 WHERE p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
			 AND p.post_parent IN ($accessible_boards_implode)",
			'%' . $wpdb->esc_like($username) . '%'
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}

// Template-part renderers — all no-ops when Deskuss is disabled.
if(!function_exists('kanbanz_deskuss_render_sync_button')) {
	function kanbanz_deskuss_render_sync_button() {}
}
if(!function_exists('kanbanz_deskuss_render_dept_field')) {
	function kanbanz_deskuss_render_dept_field() {}
}
if(!function_exists('kanbanz_deskuss_render_new_board_dept_field')) {
	function kanbanz_deskuss_render_new_board_dept_field() {}
}
if(!function_exists('kanbanz_deskuss_render_card_ticket_link')) {
	function kanbanz_deskuss_render_card_ticket_link($ticket_number) {}
}
if(!function_exists('kanbanz_deskuss_render_task_ticket_cell')) {
	function kanbanz_deskuss_render_task_ticket_cell($ticket_number) {
		echo '<td class="kanbanz-mt-col-ticket"><span class="kanbanz-mt-na">' . esc_html__('N/A', 'kanbanz') . '</span></td>';
	}
}
if(!function_exists('kanbanz_deskuss_render_tickets_tab_button')) {
	function kanbanz_deskuss_render_tickets_tab_button($active_tab) {}
}
if(!function_exists('kanbanz_deskuss_render_dept_filter')) {
	function kanbanz_deskuss_render_dept_filter($dept_id, $active_tab) {}
}
if(!function_exists('kanbanz_deskuss_render_resolved_tickets_tab')) {
	function kanbanz_deskuss_render_resolved_tickets_tab($resolved_tickets, $tickets_total, $tickets_total_pages, $page, $start_date, $end_date, $board_id, $dept_id, $search, $active_tab) {}
}
if(!function_exists('kanbanz_deskuss_render_settings_sections')) {
	function kanbanz_deskuss_render_settings_sections($cron_enabled, $policy, $reassign_to, $last_sync, $deskuss_active, $boards, $sync_url) {}
}
if(!function_exists('kanbanz_deskuss_save_settings')) {
	function kanbanz_deskuss_save_settings() {}
}