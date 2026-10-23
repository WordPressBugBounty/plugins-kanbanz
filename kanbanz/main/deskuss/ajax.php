<?php
/**
 * Deskuss-specific AJAX handlers for Kanbanz.
 *
 * Loaded only when Deskuss is active. The sync-departments endpoint
 * lives here so the core AJAX class stays Deskuss-free.
 *
 * @package kanbanz
 * @subpackage deskuss
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE') || !defined('DESKUSS_DIR')) exit;

/**
 * Register the Deskuss AJAX handlers.
 *
 * Hooked from kanbanz_deskuss_boot() so the endpoints are only
 * registered when the integration is active.
 */
function kanbanz_deskuss_register_ajax() {
	add_action('wp_ajax_kanbanz_sync_departments', 'kanbanz_deskuss_sync_departments');
}

/**
 * AJAX: sync all departments from Deskuss.
 *
 * Creates boards for departments that don't have one and reparents
 * orphan tasks. Requires manage_options.
 */
function kanbanz_deskuss_sync_departments() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if(!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('Insufficient permissions', 'kanbanz')));
	}

	if(!kanbanz_deskuss_active()) {
		wp_send_json_error(array('message' => __('Deskuss is not active', 'kanbanz')));
	}

	if(!class_exists('Kanbanz_Deskuss_Bridge')) {
		wp_send_json_error(array('message' => __('Bridge not loaded', 'kanbanz')));
	}

	$bridge = Kanbanz_Deskuss_Bridge::get_instance();
	$result = $bridge->sync_all_departments();

	wp_send_json_success($result);
}