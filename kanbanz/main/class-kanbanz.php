<?php
if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE')) exit;

/**
 * Core Kanbanz class.
 * shared logic (version info etc.).
 */
class Kanbanz {

	var $version = KANBANZ_VERSION;
	var $page = 'listing';

	public function __construct() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter, no data is changed.
		$this->page = isset($_GET['board_id']) ? 'board' : 'listing';
	}
}