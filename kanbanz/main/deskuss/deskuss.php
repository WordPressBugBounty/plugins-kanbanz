<?php
/**
 * Deskuss integration loader for Kanbanz.
 *
 * This is the single entry point for the Deskuss integration.
 * It is included by init.php ONLY when the Deskuss plugin is
 * active (DESKUSS_DIR is defined).
 *
 * It pulls in the bridge, the helpers, the AJAX handlers and the
 * UI template-part renderers, registers the cron and sync
 * endpoints, and boots the bridge.
 *
 * When Deskuss is disabled, none of this code is loaded and every
 * kanbanz_deskuss_*() / kanbanz_get_*_departments() helper falls
 * back to a no-op defined in main/deskuss/fallbacks.php so the rest
 * of the plugin keeps working without a single Deskuss reference.
 *
 * @package kanbanz
 * @subpackage deskuss
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE') || !defined('DESKUSS_DIR')) exit;

// Core integration pieces
include_once(KANBANZ_DIR . '/main/deskuss/functions.php');
include_once(KANBANZ_DIR . '/main/deskuss/bridge.php');
include_once(KANBANZ_DIR . '/main/deskuss/ajax.php');
include_once(KANBANZ_DIR . '/main/deskuss/template-parts.php');

/**
 * Boot the Deskuss integration.
 *
 * Registers AJAX handlers, schedules the cron, wires up the
 * admin-post sync endpoint and initialises the bridge. Called from
 * kanbanz_load_plugin() on plugins_loaded.
 */
function kanbanz_deskuss_boot() {
	// AJAX endpoints
	kanbanz_deskuss_register_ajax();

	// Cron + manual sync
	add_action('init', 'kanbanz_deskuss_schedule_cron');
	add_action('kanbanz_sync_cron', 'kanbanz_deskuss_run_sync_cron');
	add_action('admin_post_kanbanz_run_sync', 'kanbanz_deskuss_admin_run_sync');
}

/**
 * Schedule the hourly Deskuss sync cron.
 */
function kanbanz_deskuss_schedule_cron() {
	if(!get_option('kanbanz_cron_enabled', 1)) return;
	if(!wp_next_scheduled('kanbanz_sync_cron')) {
		wp_schedule_event(time() + 60, 'hourly', 'kanbanz_sync_cron');
	}
}

/**
 * Run the Deskuss sync cron job.
 */
function kanbanz_deskuss_run_sync_cron() {
	if(!get_option('kanbanz_cron_enabled', 1)) return;
	if(!class_exists('Kanbanz_Deskuss_Bridge')) return;
	Kanbanz_Deskuss_Bridge::get_instance()->run_full_sync();
}

/**
 * Admin-post handler: run the Deskuss sync manually.
 */
function kanbanz_deskuss_admin_run_sync() {
	if(!current_user_can('manage_options')) wp_die('Forbidden');
	check_admin_referer('kanbanz_run_sync');
	if(!class_exists('Kanbanz_Deskuss_Bridge')) {
		wp_safe_redirect(add_query_arg('kanbanz_sync_done', 'no_bridge', wp_get_referer()));
		exit;
	}
	$stats = Kanbanz_Deskuss_Bridge::get_instance()->run_full_sync();
	$encoded = rawurlencode(wp_json_encode($stats));
	wp_safe_redirect(add_query_arg(array('kanbanz_sync_done' => '1', 'kanbanz_sync_stats' => $encoded), wp_get_referer()));
	exit;
}

// Boot the integration straight away — this file is only included
// when Deskuss is active, so it is safe to initialise here.
kanbanz_deskuss_boot();