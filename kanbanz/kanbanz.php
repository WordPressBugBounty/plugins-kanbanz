<?php
/**
 * Plugin Name: Kanbanz
 * Plugin URI: https://kanbanz.com
 * Description: Powerful kanban board plugin with task management, department boards, and Deskuss ticket integration.
 * Version: 1.0.2
 * Author: Deskuss
 * Author URI: https://deskuss.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kanbanz
 * Domain Path: /languages
 */

if(!defined('ABSPATH')) exit;

if(defined('KANBANZ_FILE')) return;

define('KANBANZ_FILE', __FILE__);
define('KANBANZ_BASE', plugin_basename(KANBANZ_FILE));
define('KANBANZ_VERSION', '1.0.2');
define('KANBANZ_DIR', dirname(KANBANZ_FILE));
define('KANBANZ_URL', plugins_url('', KANBANZ_FILE));
define('KANBANZ_SLUG', 'kanbanz');
define('KANBANZ_CSS', KANBANZ_URL . '/css');
define('KANBANZ_JS', KANBANZ_URL . '/js');
define('KANBANZ_TEMPLATES', KANBANZ_DIR . '/templates');

include_once(KANBANZ_DIR . '/init.php');