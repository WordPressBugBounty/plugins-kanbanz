<?php
/**
 * Plugin Initialization & Bootstrap.
 *
 * @package Kanbanz
 */

if(!defined('ABSPATH')) exit;

if (!defined('KANBANZ_FILE')) {
	exit;
}

// 1. Load Core Domain Functions
include_once(KANBANZ_DIR . '/main/functions.php');

// 2. Deskuss Integration: Load real bridge if active, fallbacks otherwise
if (defined('DESKUSS_DIR')) {
	include_once(KANBANZ_DIR . '/main/deskuss/deskuss.php');
} else {
	include_once(KANBANZ_DIR . '/main/deskuss/fallbacks.php');
}

// 3. Hook into plugins_loaded
add_action('plugins_loaded', 'kanbanz_load_plugin', 9);

// 4. Activation Hook
register_activation_hook(KANBANZ_FILE, 'kanbanz_activation');
/**
 * Run plugin activation routine: register CPTs, sync capabilities, flush rewrites, and schedule sync cron.
 */
function kanbanz_activation() {
	kanbanz_register_post_types();
	kanbanz_add_rewrite_rules();
	kanbanz_add_custom_capabilities();
	update_option('kanbanz_version', KANBANZ_VERSION);
	flush_rewrite_rules();

	if (defined('DESKUSS_DIR')) {
		wp_schedule_single_event(time() + 30, 'kanbanz_sync_cron');
	}
}

// 5. Deactivation Hook
register_deactivation_hook(KANBANZ_FILE, 'kanbanz_deactivation');
/**
 * Run plugin deactivation routine: clear scheduled cron events and flush rewrites.
 */
function kanbanz_deactivation() {
	$timestamp = wp_next_scheduled('kanbanz_sync_cron');
	if ($timestamp) {
		wp_unschedule_event($timestamp, 'kanbanz_sync_cron');
	}
	wp_clear_scheduled_hook('kanbanz_sync_cron');
	flush_rewrite_rules();
}

/**
 * Checks if plugin updates/migrations are required.
 * Follows the standard Pagelayer / Deskuss update_check pattern.
 */
function kanbanz_update_check() {
	global $wpdb;

	$current_version = get_option('kanbanz_version');
	$version = (int) str_replace('.', '', $current_version);

	// No update required
	if ($current_version == KANBANZ_VERSION) {
		return true;
	}

	// Is it first run ?
	if (empty($current_version)) {
		// Reinstall / Initialize once the rewrite API is available
		add_action('init', 'kanbanz_activation', 20);
		$version = (int) str_replace('.', '', KANBANZ_VERSION);
	}

	// Run custom update / sync routines
	kanbanz_add_custom_capabilities();
	add_action('init', 'kanbanz_flush_rewrite_rules', 20);

	// Save the new Version
	update_option('kanbanz_version', KANBANZ_VERSION);
}

/**
 * Flush rewrite rules after Kanbanz has registered its post types and rewrite rules on init.
 */
function kanbanz_flush_rewrite_rules() {
	flush_rewrite_rules();
}

/**
 * Main plugin loader: registers hooks, menus, rewrite rules, and assets.
 */
function kanbanz_load_plugin() {
	// Check if the installed version is outdated
	kanbanz_update_check();

	add_action('init', 'kanbanz_register_post_types', 0);
	add_action('init', 'kanbanz_add_rewrite_rules');
	add_filter('query_vars', 'kanbanz_query_vars');
	add_action('admin_menu', 'kanbanz_admin_menu', 5);
	add_action('admin_enqueue_scripts', 'kanbanz_enqueue_admin_assets', 10);

	// Lazy-load AJAX handlers only when a kanbanz AJAX action is requested
	add_action('admin_init', 'kanbanz_maybe_load_ajax');
}

/**
 * Load AJAX handlers only when a Kanbanz AJAX request is received.
 */
function kanbanz_maybe_load_ajax() {
	if (!defined('DOING_AJAX') || !DOING_AJAX) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter, no data is changed.
	$action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash($_REQUEST['action'])) : '';
	if (strpos($action, 'kanbanz_') === 0) {
		include_once(KANBANZ_DIR . '/main/ajax.php');
	}
}

/**
 * Register custom post types: kanbanz_board and kanbanz_task.
 */
function kanbanz_register_post_types() {
	register_post_type('kanbanz_board', array(
		'labels' => array(
			'name'          => __('Kanban Boards', 'kanbanz'),
			'singular_name' => __('Board', 'kanbanz'),
		),
		'public'          => false,
		'show_ui'         => false,
		'supports'        => array('title', 'editor', 'page-attributes'),
		'capability_type' => 'post',
	));

	register_post_type('kanbanz_task', array(
		'labels' => array(
			'name'          => __('Kanban Tasks', 'kanbanz'),
			'singular_name' => __('Task', 'kanbanz'),
		),
		'public'          => false,
		'show_ui'         => false,
		'supports'        => array('title', 'editor', 'page-attributes'),
		'capability_type' => 'post',
	));
}

/**
 * Add custom rewrite rules for public/front-end board routing.
 */
function kanbanz_add_rewrite_rules() {
	add_rewrite_rule('^kanbanz/(.*)?$', 'index.php?kanbanz_route=$matches[1]', 'top');
}

/**
 * Register custom query vars.
 *
 * @param array $vars Public query variables.
 * @return array
 */
function kanbanz_query_vars($vars) {
	$vars[] = 'kanbanz_route';
	return $vars;
}

/**
 * Capability-schema revision constant.
 */
define('KANBANZ_CAPS_VERSION', '3');

/**
 * Re-apply capability grants when schema revision changes.
 */
add_action('admin_init', 'kanbanz_maybe_sync_capabilities');
function kanbanz_maybe_sync_capabilities() {
	if (get_option('kanbanz_caps_version') === KANBANZ_CAPS_VERSION) {
		return;
	}
	kanbanz_add_custom_capabilities();
	update_option('kanbanz_caps_version', KANBANZ_CAPS_VERSION);
}

/**
 * Register Kanban roles and assign capabilities across administrators, editors, and Deskuss staff.
 */
function kanbanz_add_custom_capabilities() {
	// 1. Dedicated Kanban Roles
	if (!get_role('kanbanz_manager')) {
		add_role('kanbanz_manager', __('Kanban Manager', 'kanbanz'), array(
			'read'            => true,
			'upload_files'    => true,
			'kanbanz_staff'   => true,
			'kanbanz_manager' => true,
		));
	} else {
		$manager = get_role('kanbanz_manager');
		$manager->add_cap('read');
		$manager->add_cap('upload_files');
		$manager->add_cap('kanbanz_staff');
		$manager->add_cap('kanbanz_manager');
	}

	if (!get_role('kanbanz_staff')) {
		add_role('kanbanz_staff', __('Kanban Staff', 'kanbanz'), array(
			'read'          => true,
			'upload_files'  => true,
			'kanbanz_staff' => true,
		));
	} else {
		$staff = get_role('kanbanz_staff');
		$staff->add_cap('read');
		$staff->add_cap('upload_files');
		$staff->add_cap('kanbanz_staff');
	}

	// 2. Administrators & Editors inherit Kanban capabilities
	$admin = get_role('administrator');
	if ($admin) {
		$admin->add_cap('kanbanz_staff');
		$admin->add_cap('kanbanz_manager');
		$admin->add_cap('upload_files');
	}
	$editor = get_role('editor');
	if ($editor) {
		$editor->add_cap('kanbanz_staff');
		$editor->add_cap('kanbanz_manager');
		$editor->add_cap('upload_files');
	}

	// 3. Deskuss Agents inherit Kanban capabilities
	$deskuss_staff = get_role('deskuss_staff');
	if ($deskuss_staff) {
		$deskuss_staff->add_cap('kanbanz_staff');
		$deskuss_staff->add_cap('upload_files');
	}
}

/**
 * Privacy scoping: Ensure non-administrator Kanban Staff only see their own
 * uploaded media when querying the WordPress Media Library.
 *
 * @param array $query WP_Query arguments for media modal.
 * @return array
 */
add_filter('ajax_query_attachments_args', 'kanbanz_scope_media_library');
function kanbanz_scope_media_library($query) {
	$user_id = get_current_user_id();
	if ($user_id && !current_user_can('manage_options') && current_user_can('kanbanz_staff')) {
		$query['author'] = $user_id;
	}
	return $query;
}

/**
 * Register WordPress Admin Menu items for Kanbanz.
 */
function kanbanz_admin_menu() {
	add_menu_page(
		__('Kanbanz', 'kanbanz'),
		__('Kanbanz', 'kanbanz'),
		'kanbanz_staff',
		'kanbanz',
		'kanbanz_boards_page',
		'dashicons-screenoptions',
		56
	);
	add_submenu_page(
		'kanbanz',
		__('All Boards', 'kanbanz'),
		__('All Boards', 'kanbanz'),
		'kanbanz_staff',
		'kanbanz',
		'kanbanz_boards_page'
	);
	add_submenu_page(
		'kanbanz',
		__('Add New Board', 'kanbanz'),
		__('Add New Board', 'kanbanz'),
		'manage_options',
		'kanbanz-templates',
		'kanbanz_templates_page'
	);
	add_submenu_page(
		'kanbanz',
		__('Settings', 'kanbanz'),
		__('Settings', 'kanbanz'),
		'manage_options',
		'kanbanz-settings',
		'kanbanz_settings_page'
	);
}

/**
 * Callback to render the Boards page (Listing or Board View).
 */
function kanbanz_boards_page() {
	include_once(KANBANZ_DIR . '/main/page-boards.php');
	kanbanz_render_boards_page();
}

/**
 * Callback to render the Templates page.
 */
function kanbanz_templates_page() {
	include_once(KANBANZ_DIR . '/main/page-templates.php');
	kanbanz_render_templates_page();
}

/**
 * Callback to render the Settings page.
 */
function kanbanz_settings_page() {
	include_once(KANBANZ_DIR . '/main/settings.php');
	kanbanz_render_settings_page();
}

/**
 * Version string for a plugin asset: the plugin version plus the file's
 * modification time, so browsers never keep a stale copy after the file changes.
 *
 * @param string $path Path relative to the plugin folder, e.g. 'js/kanbanz-editor.js'.
 * @return string
 */
function kanbanz_asset_ver($path) {
	$file = KANBANZ_DIR . '/' . ltrim($path, '/');
	return KANBANZ_VERSION . (file_exists($file) ? '.' . filemtime($file) : '');
}

/**
 * Unified Asset Manager: Enqueues styles, scripts, and localized data for all Kanbanz admin screens.
 *
 * @param string $hook_suffix The current admin screen hook suffix.
 */
function kanbanz_enqueue_admin_assets($hook_suffix) {
	// Only load on Kanbanz screens
	$kanbanz_hooks = array(
		'toplevel_page_kanbanz',
		'kanbanz_page_kanbanz',
		'kanbanz_page_kanbanz-settings',
		'kanbanz_page_kanbanz-templates'
	);
	if (!in_array($hook_suffix, $kanbanz_hooks, true)) {
		return;
	}


	// 1. Styles
	wp_enqueue_style('dashicons');
	wp_enqueue_style('kanbanz-editor', KANBANZ_CSS . '/kanbanz-editor.css', array(), kanbanz_asset_ver('css/kanbanz-editor.css'));

	// 2. Theme Bootstrap Script (Header inline to prevent FOUC)
	wp_enqueue_script('kanbanz-theme', KANBANZ_JS . '/kanbanz-theme.js', array(), kanbanz_asset_ver('js/kanbanz-theme.js'), false);
	$boot = '(function(){try{var k=localStorage.getItem("kanbanz_theme");'
		. 'if(k!=="light"&&k!=="dark"){k=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";}'
		. 'var s=function(){var w=document.querySelector(".kanbanz-wrapper");if(w)w.setAttribute("data-theme",k);'
		. 'document.querySelectorAll("[data-kanbanz-theme-toggle]").forEach(function(b){b.setAttribute("aria-pressed",k==="dark"?"true":"false");var i=b.querySelector(".dashicons");if(i)i.className="dashicons "+(k==="dark"?"dashicons-star-filled":"dashicons-lightbulb");});};'
		. 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",s);else s();'
		. '}catch(e){}})();';
	wp_add_inline_script('kanbanz-theme', $boot, 'before');

	// 3. WordPress Media Library & jQuery UI Sortable
	wp_enqueue_media();
	wp_enqueue_script('jquery-ui-sortable');

	// 4. Main Kanbanz Editor JS
	wp_enqueue_script('kanbanz-editor', KANBANZ_JS . '/kanbanz-editor.js', array('jquery', 'jquery-ui-sortable'), kanbanz_asset_ver('js/kanbanz-editor.js'), true);
	wp_localize_script('kanbanz-editor', 'kanbanzVars', array(
		'ajaxurl'    => admin_url('admin-ajax.php'),
		'ajax_nonce' => wp_create_nonce('kanbanz_nonce'),
		'plugin_url' => KANBANZ_URL,
	));

	// 5. Drag-and-Drop Sortable Engine (on Board View)
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter, no data is changed.
	if (!empty($_GET['board_id'])) {
		wp_enqueue_script('kanbanz-dnd', KANBANZ_JS . '/kanbanz-dnd.js', array('jquery', 'jquery-ui-sortable', 'kanbanz-editor'), kanbanz_asset_ver('js/kanbanz-dnd.js'), true);
	}
	// 6. Page-specific scripts
	if ($hook_suffix === 'kanbanz_page_kanbanz-settings') {
		wp_enqueue_script('kanbanz-settings', KANBANZ_JS . '/kanbanz-settings.js', array('jquery'), kanbanz_asset_ver('js/kanbanz-settings.js'), true);
		wp_localize_script('kanbanz-settings', 'kanbanzSettings', array(
			'nonce' => wp_create_nonce('kanbanz_nonce'),
		));
	} elseif ($hook_suffix === 'kanbanz_page_kanbanz-templates') {
		wp_enqueue_script('kanbanz-team-picker', KANBANZ_JS . '/kanbanz-team-picker.js', array(), kanbanz_asset_ver('js/kanbanz-team-picker.js'), true);
		wp_enqueue_script('kanbanz-templates', KANBANZ_JS . '/kanbanz-templates.js', array('jquery', 'kanbanz-editor', 'kanbanz-team-picker'), kanbanz_asset_ver('js/kanbanz-templates.js'), true);
		wp_localize_script('kanbanz-templates', 'kanbanzTemplates', array(
			'ajaxUrl'  => admin_url('admin-ajax.php'),
			'boardUrl' => admin_url('admin.php?page=kanbanz&board_id='),
			'nonce'    => wp_create_nonce('kanbanz_nonce'),
			'i18n'     => array(
				'stageExists'    => __('Stage already exists', 'kanbanz'),
				'removeStage'    => __('Remove stage', 'kanbanz'),
				'addStage'       => __('Add at least one stage before creating the board.', 'kanbanz'),
				'stagesNotSaved' => __('Board created with default stages. Custom stages could not be saved.', 'kanbanz'),
				'errorCreating'  => __('Error creating board', 'kanbanz'),
				'networkError'   => __('Network error. Please try again.', 'kanbanz'),
			),
		));
	} elseif (empty($_GET['board_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter, no data is changed.
		wp_enqueue_script('kanbanz-team-picker', KANBANZ_JS . '/kanbanz-team-picker.js', array(), kanbanz_asset_ver('js/kanbanz-team-picker.js'), true);
		wp_enqueue_script('kanbanz-listing', KANBANZ_JS . '/kanbanz-listing.js', array('kanbanz-team-picker'), kanbanz_asset_ver('js/kanbanz-listing.js'), true);
		wp_localize_script('kanbanz-listing', 'kanbanzListing', array(
			'ajaxUrl'     => admin_url('admin-ajax.php'),
			'boardUrl'    => admin_url('admin.php?page=kanbanz&board_id='),
			'nonce'       => wp_create_nonce('kanbanz_nonce'),
			'deskussSync' => kanbanz_deskuss_enabled(),
			'i18n'        => array(
				'confirm'         => __('Confirm', 'kanbanz'),
				'cancel'          => __('Cancel', 'kanbanz'),
				'errorCreating'   => __('Error creating board', 'kanbanz'),
				'errorDeleting'   => __('Error deleting board', 'kanbanz'),
				'networkError'    => __('Network error. Please try again.', 'kanbanz'),
				'syncConfirm'     => __('Sync all departments from Deskuss? This will create boards for departments that don\'t have one and reparent orphan tasks.', 'kanbanz'),
				'syncTitle'       => __('Sync Departments', 'kanbanz'),
				'syncButton'      => __('Sync', 'kanbanz'),
				'syncComplete'    => __('Sync complete:', 'kanbanz'),
				'boardsCreated'   => __('board(s) created', 'kanbanz'),
				'tasksReparented' => __('task(s) reparented', 'kanbanz'),
				'syncFailed'      => __('Sync failed', 'kanbanz'),
			),
		));
	}
}