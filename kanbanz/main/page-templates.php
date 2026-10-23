<?php
/**
 * Board Templates page renderer.
 *
 * @package kanbanz
 */

if(!defined('ABSPATH')) exit;

function kanbanz_render_templates_page() {
	if(!current_user_can('manage_options')) return;
	include_once(KANBANZ_TEMPLATES . '/board-templates.php');
}
