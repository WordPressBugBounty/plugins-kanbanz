<?php
/**
 * Deskuss integration functions for Kanbanz.
 *
 * This file is only loaded when the Deskuss plugin is active
 * (i.e. when the DESKUSS_DIR constant is defined).
 *
 * All Deskuss-specific helpers, data access and query helpers
 * live here so the core Kanbanz code stays clean and the
 * Deskuss integration can be disabled / removed as a whole.
 *
 * @package kanbanz
 * @subpackage deskuss
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE')) exit;

/**
 * Whether the Deskuss integration is active.
 *
 * Centralised guard so the rest of the plugin does not have to
 * call defined('DESKUSS_DIR') on every line.
 *
 * @return bool
 */
function kanbanz_deskuss_active() {
	return defined('DESKUSS_DIR');
}

/**
 * Get the departments a given user can manage / see.
 *
 * Admins see every department, managers only the ones they manage,
 * everyone else gets an empty list.
 *
 * @param int $user_id
 * @return array
 */
function kanbanz_get_user_departments($user_id) {
	if(!kanbanz_deskuss_active()) return array();

	global $wpdb;
	$dept_table = esc_sql($wpdb->prefix . 'dk_department');
	$depts = array();

	if(user_can($user_id, 'administrator') || user_can($user_id, 'manage_options')) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
		$results = $wpdb->get_results("SELECT id, name FROM {$dept_table} ORDER BY name");
	} elseif(user_can($user_id, 'kanbanz_manager')) {
		$staff_info = function_exists('deskuss_get_user_info') ? deskuss_get_user_info($user_id) : null;
		if(empty($staff_info) || empty($staff_info->member_id)) return array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT id, name FROM {$dept_table} WHERE manager_id = %d ORDER BY name",
			intval($staff_info->member_id)
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	} else {
		return array();
	}

	if(!empty($results)) {
		foreach($results as $row) {
			$depts[] = (object) array('term_id' => intval($row->id), 'name' => $row->name);
		}
	}
	return $depts;
}

/**
 * Get every department in Deskuss.
 *
 * @return array
 */
function kanbanz_get_all_departments() {
	if(!kanbanz_deskuss_active()) return array();

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$results = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}dk_department ORDER BY name");
	$depts = array();
	if(!empty($results)) {
		foreach($results as $row) {
			$depts[] = (object) array('term_id' => intval($row->id), 'name' => $row->name);
		}
	}
	return $depts;
}

/**
 * Get the name of a single department.
 *
 * @param int $dept_id
 * @return string
 */
function kanbanz_get_department_name($dept_id) {
	if(!$dept_id || !kanbanz_deskuss_active()) return '';
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	return $wpdb->get_var($wpdb->prepare(
		"SELECT name FROM {$wpdb->prefix}dk_department WHERE id = %d",
		intval($dept_id)
	)) ?: '';
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Base URL for linking to a Deskuss ticket.
 *
 * Filtered by the bridge so the configured Deskuss URL slug is
 * respected.
 *
 * @return string
 */
function kanbanz_get_ticket_url_base() {
	return apply_filters('kanbanz_ticket_url_base', '');
}

/**
 * Get the linked Deskuss ticket number for a task.
 *
 * Reads the new `kanbanz_link_ticket` meta first and falls back
 * to the legacy `deskuss_link_ticket` key for backward compat.
 *
 * @param int $task_id
 * @return string|int
 */
function kanbanz_get_task_ticket($task_id) {
	$ticket = get_post_meta($task_id, 'kanbanz_link_ticket', true);
	if(empty($ticket)) {
		$ticket = get_post_meta($task_id, 'deskuss_link_ticket', true);
	}
	return $ticket;
}

/**
 * Get the Deskuss department id linked to a board.
 *
 * @param int $board_id
 * @return int|string
 */
function kanbanz_get_board_dept_id($board_id) {
	return get_post_meta($board_id, 'deskuss_dept_link', true);
}

/**
 * Get the staff assigned to a task, reading the new meta key
 * first and falling back to the legacy Deskuss key.
 *
 * @param int $task_id
 * @return mixed
 */
function kanbanz_get_task_assigned_staff($task_id) {
	$assigned = get_post_meta($task_id, 'kanbanz_assigned_staff', true);
	if(empty($assigned)) {
		$assigned = get_post_meta($task_id, 'deskuss_assigned_staff', true);
	}
	return $assigned;
}

/**
 * Is the given user a board manager (admin or manager of a dept)?
 *
 * @param int $user_id
 * @return bool
 */
function kanbanz_is_board_manager($user_id) {
	if(!$user_id) return false;
	if(user_can($user_id, 'administrator') || user_can($user_id, 'manage_options')) return true;
	if(user_can($user_id, 'kanbanz_manager')) return true;

	if(!kanbanz_deskuss_active()) return false;
	$depts = kanbanz_get_user_departments($user_id);
	return !empty($depts);
}

/**
 * Departments available to the current user for the My Tasks filter.
 *
 * @return array
 */
function kanbanz_get_my_depts_for_filter() {
	if(!kanbanz_deskuss_active()) return array();
	return kanbanz_get_user_departments(get_current_user_id());
}

/**
 * Get the current user's resolved Deskuss tickets.
 *
 * Proxies through to the Deskuss helper when available.
 *
 * @param array $args
 * @return array
 */
function kanbanz_get_my_resolved_tickets($args = array()) {
	if(!kanbanz_deskuss_active() || !function_exists('deskuss_get_my_resolved_tickets')) {
		return array('tickets' => array(), 'total' => 0, 'total_pages' => 0);
	}
	return deskuss_get_my_resolved_tickets($args);
}

/**
 * Count the current user's pending tasks.
 *
 * Checks both the new `kanbanz_assigned_staff` and the legacy
 * `deskuss_assigned_staff` meta keys so historical data is
 * still picked up.
 *
 * @return int
 */
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
		"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'task_status' AND m1.meta_value != 'Completed'
		 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND (
			(m2.meta_key = 'kanbanz_assigned_staff' AND m2.meta_value LIKE %s)
			OR (m2.meta_key = 'deskuss_assigned_staff' AND m2.meta_value LIKE %s)
		 )
		 WHERE p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
		 AND p.post_parent IN ($accessible_boards_implode)",
		'%' . $wpdb->esc_like($username) . '%', '%' . $wpdb->esc_like($username) . '%'
	));
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}