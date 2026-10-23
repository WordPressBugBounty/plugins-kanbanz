<?php
/**
* AJAX.
*
* @package kanbanz
*/

if(!defined('ABSPATH')) exit;

/**
* Ajax Class
*/
class Kanbanz_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_kanbanz_create_board', array( $this, 'kanbanz_create_new_board' ) );
		add_action('wp_ajax_kanbanz_save_status', array($this, 'kanbanz_save_status')); 
		add_action('wp_ajax_kanbanz_handle_status', array($this, 'kanbanz_handle_status_title')); 
		add_action('wp_ajax_kanbanz_save_new_task', array($this, 'kanbanz_save_new_task')); 
		add_action('wp_ajax_kanbanz_rename_task', array($this, 'kanbanz_rename_task')); 
		add_action('wp_ajax_kanbanz_open_task', array($this, 'kanbanz_open_task')); 
		add_action('wp_ajax_kanbanz_remove_task', array($this, 'kanbanz_remove_task')); 
		add_action('wp_ajax_kanbanz_tasks_status', array($this, 'kanbanz_tasks_status')); 
		add_action('wp_ajax_kanbanz_remove_board', array($this, 'kanbanz_remove_board')); 
		add_action('wp_ajax_kanbanz_save_task_description', array($this, 'kanbanz_save_task_description')); 
		add_action('wp_ajax_kanbanz_save_comment', array($this, 'kanbanz_save_comment')); 
		add_action('wp_ajax_kanbanz_save_priority', array($this, 'kanbanz_save_priority')); 
		add_action('wp_ajax_kanbanz_save_date', array($this, 'kanbanz_save_date')); 
		add_action('wp_ajax_kanbanz_get_details', array($this, 'kanbanz_get_details')); 
		add_action('wp_ajax_kanbanz_get_board_statuses', array($this, 'kanbanz_get_board_statuses')); 
		add_action('wp_ajax_kanbanz_filter_tasks_by_user', array($this, 'kanbanz_filter_tasks_by_user')); 
		add_action('wp_ajax_kanbanz_move_task', array($this, 'kanbanz_move_task')); 
		add_action('wp_ajax_kanbanz_move_all_tasks', array($this, 'kanbanz_move_all_tasks')); 
		add_action('wp_ajax_kanbanz_sort_all_tasks', array($this, 'kanbanz_sort_all_tasks')); 
		add_action('wp_ajax_kanbanz_update_status_order', array($this, 'kanbanz_update_status_order')); 
		add_action('wp_ajax_kanbanz_update_board_title', array($this, 'kanbanz_update_board_title')); 
		add_action('wp_ajax_kanbanz_update_board_desc', array($this, 'kanbanz_update_board_desc'));
		add_action('wp_ajax_kanbanz_get_labels', array($this, 'kanbanz_get_labels')); 
		add_action('wp_ajax_kanbanz_save_custom_label', array($this, 'kanbanz_save_custom_label')); 
		add_action('wp_ajax_kanbanz_delete_custom_label', array($this, 'kanbanz_delete_custom_label')); 
		add_action('wp_ajax_kanbanz_add_task_label', array($this, 'kanbanz_add_task_label')); 
		add_action('wp_ajax_kanbanz_board_layout', array($this, 'kanbanz_board_layout')); 
		add_action('wp_ajax_kanbanz_label_element', array($this, 'kanbanz_label_element')); 
		add_action('wp_ajax_kanbanz_board_search', array($this, 'kanbanz_board_search')); 
		add_action('wp_ajax_kanbanz_get_activity', array($this, 'kanbanz_get_activity')); 
		add_action('wp_ajax_kanbanz_members_list', array($this, 'kanbanz_members_list')); 
		add_action('wp_ajax_kanbanz_assign_staff', array($this, 'kanbanz_assign_staff'));

		add_action('wp_ajax_kanbanz_get_collab_users',  array($this, 'kanbanz_get_collab_users')); 
		add_action('wp_ajax_kanbanz_update_staff_members',  array($this, 'kanbanz_update_staff_members')); 
		add_action('wp_ajax_kanbanz_task_attachments',  array($this, 'kanbanz_task_attachments_handler'));
		add_action('wp_ajax_kanbanz_save_theme',  array($this, 'kanbanz_save_theme'));
		add_action('wp_ajax_kanbanz_save_subtasks',  array($this, 'kanbanz_save_subtasks'));
		add_action('wp_ajax_kanbanz_save_activity',  array($this, 'kanbanz_save_activity'));
		add_action('wp_ajax_kanbanz_save_labels',  array($this, 'kanbanz_save_labels'));
		// NOT registered on wp_ajax_kanbanz_upload_attachment: the standalone
		// kanbanz_upload_attachment() at the bottom of this file owns that
		// action. Both were hooked to it, and because this class is
		// instantiated before that add_action() runs, this method went first
		// and read $_FILES['files'] — while the editor posts a single
		// $_FILES['attachment'] per request. Every upload therefore died here
		// with "No files uploaded." before the working handler was reached.
		add_action('wp_ajax_kanbanz_remove_attachment',  array($this, 'kanbanz_remove_attachment'));
		// kanbanz_sync_departments is registered by the Deskuss module
		// (main/deskuss/ajax.php) when Deskuss is active.
	}

	private function kanbanz_require_cap($cap) {
		if (!current_user_can($cap)) {
			wp_send_json_error(['message' => __('You do not have sufficient permission.', 'kanbanz')]);
		}
	}

	private function kanbanz_user_can_edit_task($task_id) {
		$role = kanbanz_get_user_role();
		if ($role === 'admin') return true;

		// Used by every task-mutation handler below (rename, remove,
		// description, priority, date, move, etc.) — previously 'manager'
		// returned true here unconditionally, letting any manager edit any
		// task on any board site-wide regardless of department/assignment.
		$post = get_post($task_id);
		if (!$post || $post->post_type !== 'kanbanz_task') return false;

		if ($role === 'manager') {
			return kanbanz_user_can_access_board($post->post_parent);
		}
		if ($role === 'staff') {
			$staff_info = kanbanz_get_user_info(get_current_user_id());
			if (empty($staff_info)) return false;
			$username = $staff_info->member_username;
			$assigned = kanbanz_get_task_assigned_staff($task_id);
			if (is_array($assigned) && in_array($username, $assigned)) return true;
			if ($post->post_author == get_current_user_id()) return true;
			return false;
		}
		return false;
	}

	private function deskess_require_task_edit($task_id) {
		if (!$this->kanbanz_user_can_edit_task($task_id)) {
			wp_send_json_error(['message' => __('You do not have permission to edit this task.', 'kanbanz')]);
		}
	}

	public function kanbanz_get_post_data( $post ) {
		$task_status = get_post_meta($post->ID, 'task_status', true);
		$priority = get_post_meta($post->ID, 'priority', true);
		if (empty($priority)) {
			$priority = get_post_meta($post->ID, 'task_priority', true);
		}
		$due_date = get_post_meta($post->ID, 'due_date', true);
		$assigned_staff = kanbanz_get_task_assigned_staff($post->ID);
		$ticket_number = kanbanz_get_task_ticket($post->ID);
		$created_date = !empty($post->post_date) ? substr($post->post_date, 0, 10) : '';
		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'name'        => $post->post_name,
			'status'      => $post->post_status,
			'task_status' => $task_status ? $task_status : '',
			'url'         => get_permalink($post->ID),
			'content'     => $post->post_content,
			'date'        => $post->post_date,
			'created_date'=> $created_date,
			'task_meta'   => array(
				'priority'    => $priority,
				'due_date'    => $due_date,
				'created_date'=> $created_date,
				'assigned'    => $assigned_staff,
				'is_overdue'  => (!empty($due_date) && strtotime($due_date) < time() && $task_status !== 'Completed'),
				'ticket_number' => $ticket_number,
				'labels'      => kanbanz_get_task_labels($post->ID),
			),
		);
	}

	public function kanbanz_create_new_board() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');

		// Only administrators can create boards
		$role = kanbanz_get_user_role();
		if ($role !== 'admin') {
			wp_send_json_error(['message' => __('Only administrators can create boards', 'kanbanz')]);
		}

		$data = array();
		$data['name'] = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
		$data['description'] = isset($_POST['description']) ? sanitize_text_field(wp_unslash($_POST['description'])) : '';
		$data['parent'] = isset($_POST['parent']) ? absint($_POST['parent']) : 0;

		$current_user = wp_get_current_user();
		$username = $current_user->user_login;	
		$data['user_id'] = $username;

		$data['assign_manager_id'] = get_current_user_id();
		// Optional team; with none the board is visible to all staff
		$data['assign_members'] = isset($_POST['assigned_id']) && is_array($_POST['assigned_id']) ? array_map('sanitize_text_field', wp_unslash($_POST['assigned_id'])) : array();
		$data['dept_id'] = isset($_POST['dept_id']) ? absint($_POST['dept_id']) : 0;

		$res = kanbanz_create_board($data);

		if ($res['success']) {
			wp_send_json_success($res);
		} else {
			wp_send_json_error($res);
		}
	}

	public function build_tree( $board, $parent = 0 ) {
		$result = array();
		if ( ! $board ) {
			return $result;
		}
		foreach ( $board as $key => $doc ) {    
			$result[] = array(
			'post'  => $this->kanbanz_get_post_data( $doc ),
			);
		}
		return $result;
	}

	public function kanbanz_save_status() {
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$status = isset($_POST['val']) ? sanitize_text_field(wp_unslash($_POST['val'])) : '';
		$board_id = isset($_POST['parent']) ? absint($_POST['parent']) : 0;
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';
		
		if (empty($status) || $board_id === 0) {
			wp_send_json_error(['message' => __('Invalid status or board ID', 'kanbanz')]);
		}

		// Get current statuses
		$statuses = get_post_meta($board_id, 'board_statuses', true);
		if (!$statuses) {
			$statuses = array();
		}

		// Add new status
		if (!in_array($status, $statuses)) {
			$statuses[] = $status;
			update_post_meta($board_id, 'board_statuses', $statuses);
		}

		$log_title = 'Status Created by ' . $user_name;
		kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		
		wp_send_json_success(['message'=> 'Status has been saved successfully','statuses' => $statuses, 'status_name' => $status]);
	}

	public function kanbanz_handle_status_title(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$previous_status = isset($_POST['prev_val']) ? sanitize_text_field(wp_unslash($_POST['prev_val'])) : '';
		$new_status = isset($_POST['val']) ? sanitize_text_field(wp_unslash($_POST['val'])) : '';
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';
		$status_color = get_user_meta($user_id, 'kanbanz_status_background', true);

		if (!$board_id || empty($new_status)) {
			wp_send_json_error(['message' => __('Missing or invalid board ID or value', 'kanbanz')]);
		}

		// Renaming a status column is a board-config action — restrict to
		// admin/manager (with the manager still scoped to their own board
		// via kanbanz_user_can_access_board()), not any kanbanz_staff user.
		$role = kanbanz_get_user_role();
		if ($role !== 'admin' && (!in_array($role, array('manager'), true) || !kanbanz_user_can_access_board($board_id))) {
			wp_send_json_error(['message' => __('You do not have permission to rename statuses on this board.', 'kanbanz')]);
		}

		$statuses = get_post_meta($board_id, 'board_statuses', true);

		if (is_array($statuses) && in_array($previous_status, $statuses)) {
			$statuses[array_search($previous_status, $statuses)] = $new_status;
			update_post_meta($board_id, 'board_statuses', $statuses);
			$args = array(
				'post_type' => 'kanbanz_task',
				'post_parent' => $board_id,
				'meta_query' => array(
					array(
						'key' => 'task_status',
						'value' => $previous_status,
						'compare' => '='
					),
				),
				'posts_per_page' => -1,
			);
			$query = new WP_Query($args);

			if ($query->have_posts()) {
				while ($query->have_posts()) {
					$query->the_post();
					$post_id = get_the_ID();
					update_post_meta($post_id, 'task_status', $new_status);
				}
				wp_reset_postdata();
			}

			if(!empty($previous_status) && isset($status_color[$board_id][$previous_status])){
				$status_color[$board_id][$new_status] = $status_color[$board_id][$previous_status];
				unset($status_color[$board_id][$previous_status]);  
				update_user_meta($user_id, 'kanbanz_status_background', $status_color);
			}

			$log_title = $previous_status. ' Status changed to ' . $new_status .' By '. $user_name;
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');

			wp_send_json_success(['message' => __('Status successfully changed.', 'kanbanz'), 'status' => $new_status]);
		} else {
			wp_send_json_error(['message' => __('Previous status not found', 'kanbanz')]);
		}   
	}   

	public function kanbanz_save_new_task() {
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		//todo add permission to create the new tasks to only admin and manager
		$task = isset($_POST['val']) ? sanitize_text_field(wp_unslash($_POST['val'])) : '';
		$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
		$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';

		if (empty($task) || $board_id === 0 || empty($status)) {
			wp_send_json_error(['message' => __('Invalid task name, board ID, or status', 'kanbanz')]);
		}

		$args = array(
			'post_type'      => 'kanbanz_task',
			'posts_per_page' => 1,
			'post_parent'    => $board_id,
			'orderby'        => 'menu_order',
			'order'          => 'DESC',
			'fields'         => 'ids',
		);

		$query = new WP_Query($args);
		$highest_order = $query->have_posts() ? (int) get_post($query->posts[0])->menu_order : -1;
		$new_menu_order = $highest_order + 1;

		$post_id = wp_insert_post(
			array(
				'post_title'  => $task,
				'post_type'   => 'kanbanz_task',
				'post_status' => 'publish',
				'post_author' => get_current_user_id(),
				'post_parent' => $board_id,
				'menu_order'  => $new_menu_order,
			)
		);

		if (is_wp_error($post_id)) {
			wp_send_json_error(['message' => __('Unable to create the task', 'kanbanz')]);
		}

		update_post_meta($post_id, 'task_status', $status);
		$post = get_post($post_id);

		if (!$post) {
			wp_send_json_error(['message' => __('Failed to retrieve created task', 'kanbanz')]);
		}

		// Update activity
		$log_title = 'Task Created by ' . $user_name;
		kanbanz_update_activity($post_id, $user_name, $log_title, 'kanbanz_activity');
		wp_send_json_success($this->kanbanz_get_post_data($post));
	}

	public function kanbanz_rename_task(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$this->deskess_require_task_edit($task_id);
		$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

		$user = wp_get_current_user();
		$updated = wp_update_post([
			'ID'         => $task_id,
			'post_title' => $title,
		]);

		if (is_wp_error($updated)) {
			wp_send_json_error(['message' => $updated->get_error_message()]);
		}

		// Log activity
		$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $user->user_login,
			'action'  => 'renamed task to ' . $title,
			'comment' => '',
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
		update_post_meta($task_id, 'kanbanz_activity', $activity);
		update_post_meta($task_id, 'kanbanz_last_edited', [
			'user' => $user->user_login,
			'time' => time(),
		]);

		wp_send_json_success(['message' => __('Task renamed', 'kanbanz'), 'title' => $title]);
	}

	public function kanbanz_open_task(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		// This handler had no capability check at all — the nonce was the only
		// gate, unlike every other handler in this class.
		$this->kanbanz_require_cap('kanbanz_staff');
		$post_id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
		$board_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}

		$data = get_post($post_id);

		if (!$data || $data->post_type !== 'kanbanz_task') {
			wp_send_json_error(['message' => __('No task data found.', 'kanbanz')]);
		}

		// Always resolve the board from the post hierarchy, never from the
		// submitted board_id — the caller controls that value, so trusting it
		// for the access check below would let it be pointed at a board the
		// user does have access to while reading a task from one they don't.
		$board_id = kanbanz_resolve_board_id($post_id);

		// This returns the task's full contents (description, comment and
		// activity history, attachments, assignees), so it needs a real gate:
		// board access, or being an assignee of this particular task. The
		// assignee clause keeps My Tasks coherent — it lists everything assigned
		// to you, including tasks on boards you were never added to.
		if (!kanbanz_user_can_view_task($post_id)) {
			wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
		}

		// Get task meta
		$task_meta = get_post_meta($post_id);
		$priority = isset($task_meta['priority'][0]) ? sanitize_text_field($task_meta['priority'][0]) : '';
		if (empty($priority)) {
			$priority = isset($task_meta['task_priority'][0]) ? sanitize_text_field($task_meta['task_priority'][0]) : '';
		}
		$due_date = isset($task_meta['due_date'][0]) ? sanitize_text_field($task_meta['due_date'][0]) : '';
		$assigned = isset($task_meta['kanbanz_assigned_staff'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_assigned_staff'][0]) : [];
		// Activity: read from BOTH keys and merge (backward compat).
		// Writers historically used 'kanbanz_activity' (e.g. task creation, subtasks, Deskuss bridge)
		// while comments used 'kanbanz_activity'. Unify on 'kanbanz_activity' going forward.
		$activity_a = isset($task_meta['kanbanz_activity'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_activity'][0]) : [];
		$activity_b = isset($task_meta['kanbanz_activity'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_activity'][0]) : [];
		$activity = array_merge($activity_a, $activity_b);
		// De-duplicate by (user+action+time) and sort newest-first
		$seen = [];
		$activity = array_filter($activity, function($item) use (&$seen) {
			$key = (isset($item['user']) ? $item['user'] : '') . '|' . (isset($item['action']) ? $item['action'] : '') . '|' . (isset($item['time']) ? $item['time'] : '');
			if(isset($seen[$key])) return false;
			$seen[$key] = true;
			return true;
		});
		usort($activity, function($x, $y) {
			$tx = isset($x['time']) ? (int) $x['time'] : 0;
			$ty = isset($y['time']) ? (int) $y['time'] : 0;
			return $ty - $tx; // newest first
		});
		$subtasks = isset($task_meta['kanbanz_subtasks_meta'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_subtasks_meta'][0]) : [];
		$labels = isset($task_meta['kanbanz_labels'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_labels'][0]) : [];
		$attachment_ids = isset($task_meta['kanbanz_attachments'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_attachments'][0]) : [];
		$last_edited = isset($task_meta['kanbanz_last_edited'][0]) ? (array) maybe_unserialize($task_meta['kanbanz_last_edited'][0]) : [];

		// Auto-migrate legacy JSON subtasks to CPT child posts
		if (!empty($subtasks) && is_array($subtasks)) {
			$migrated = false;
			foreach ($subtasks as $old_st) {
				$st_title = isset($old_st['text']) ? sanitize_text_field($old_st['text']) : '';
				if (empty($st_title)) continue;
				$st_done = !empty($old_st['done']) || !empty($old_st['checked']);
				$st_id = wp_insert_post([
					'post_title'  => $st_title,
					'post_type'   => 'kanbanz_task',
					'post_status' => 'publish',
					'post_parent' => $post_id,
					'post_author' => get_current_user_id(),
				]);
				if (!is_wp_error($st_id)) {
					if ($st_done) {
						update_post_meta($st_id, 'kanbanz_subtask_done', 1);
					}
					update_post_meta($st_id, 'task_status', '');
					update_post_meta($st_id, 'priority', '');
					update_post_meta($st_id, 'due_date', '');
					update_post_meta($st_id, 'kanbanz_assigned_staff', []);
					update_post_meta($st_id, 'kanbanz_subtask_parent', $post_id);
					$migrated = true;
				}
			}
			if ($migrated) {
				delete_post_meta($post_id, 'kanbanz_subtasks_meta');
				$subtasks = [];
			}
		}
        
		// Format attachments
		$attachments = [];
		foreach ($attachment_ids as $att_id) {
			$url = wp_get_attachment_url($att_id);
			if (!$url) continue;
			$file = get_attached_file($att_id);
			$size = file_exists($file) ? filesize($file) : 0;
			$mime = get_post_mime_type($att_id);
			$name = basename(get_attached_file($att_id));
			$attachments[] = [
				'id'   => $att_id,
				'name' => $name,
				'size' => $size,
				'type' => (strpos($mime, 'image/') === 0) ? 'image' : 
						((strpos($mime, 'application/pdf') === 0) ? 'pdf' : 
						((strpos($mime, 'zip') !== false) ? 'zip' : 'file')),
				'url'  => $url,
			];
		}
        
		// Format activity
		$formatted_activity = [];
		foreach ($activity as $item) {
			$formatted_activity[] = [
				'user'    => isset($item['user']) ? sanitize_text_field($item['user']) : 'unknown',
				'action'  => isset($item['action']) ? sanitize_text_field($item['action']) : 'updated',
				'comment' => isset($item['comment']) ? wp_kses_post($item['comment']) : '',
				'time'    => isset($item['time']) ? absint($item['time']) : time(),
				'type'    => isset($item['type']) ? sanitize_text_field($item['type']) : 'system',
			];
		}
        
		// Format subtasks — CPT child posts first, then legacy JSON meta
		$formatted_subtasks = [];

		// CPT subtasks (child kanbanz_task posts)
		$subtask_posts = get_posts([
			'post_type'   => 'kanbanz_task',
			'post_parent' => $post_id,
			'numberposts' => -1,
			'orderby'     => 'menu_order date',
			'order'       => 'ASC',
		]);
		foreach ($subtask_posts as $st) {
			$st_done = get_post_meta($st->ID, 'kanbanz_subtask_done', true);
			$st_assignee = kanbanz_get_task_assigned_staff($st->ID);
			$st_due = get_post_meta($st->ID, 'due_date', true);
			$st_priority = get_post_meta($st->ID, 'priority', true);
			if (empty($st_priority)) {
				$st_priority = get_post_meta($st->ID, 'task_priority', true);
			}
			$formatted_subtasks[] = [
				'id'       => $st->ID,
				'title'    => $st->post_title,
				'checked'  => !empty($st_done),
				'done'      => !empty($st_done),
				'assignee'  => is_array($st_assignee) ? $st_assignee : [],
				'due_date'  => $st_due ?: '',
				'priority'  => $st_priority ?: '',
				'is_cpt'   => true,
			];
		}

		// Legacy JSON meta subtasks (if any)
		foreach ($subtasks as $task) {
			$formatted_subtasks[] = [
				'id'       => isset($task['id']) ? sanitize_text_field($task['id']) : uniqid('st_'),
				'title'    => isset($task['text']) ? sanitize_text_field($task['text']) : '',
				'checked'  => !empty($task['done']) || !empty($task['checked']),
				'done'     => !empty($task['done']) || !empty($task['checked']),
				'is_cpt'  => false,
			];
		}
        
		// Format labels
		$formatted_labels = [];
		foreach ($labels as $label) {
			$formatted_labels[] = [
				'text'       => isset($label['text']) ? sanitize_text_field($label['text']) : '',
				'background' => isset($label['background']) ? sanitize_hex_color($label['background']) : '#3a86ff',
			];
		}
        
		// Format last edited
		$formatted_last_edited = [
			'user' => isset($last_edited['user']) ? sanitize_text_field($last_edited['user']) : 'unknown',
			'time' => isset($last_edited['time']) ? absint($last_edited['time']) : time(),
		];

		$content = '<div class="kanbanz-task-detail-content">' . wp_kses_post($data->post_content) . '</div>';
        
		$task_status = get_post_meta($post_id, 'task_status', true);
		$ticket_number = kanbanz_get_task_ticket($post_id);

		wp_send_json_success([
			'id'          => $post_id,
			'content'     => $content,
			'title'       => $data->post_title,
			'description' => $data->post_content,
			'priority'    => $priority,
			'due_date'    => $due_date,
			'assigned'    => $assigned,
			'activity'    => $formatted_activity,
			'subtasks'    => $formatted_subtasks,
			'labels'      => $formatted_labels,
			'attachments' => $attachments,
			'last_edited' => $formatted_last_edited,
			'board_id'    => $board_id,
			'status'      => $task_status ? sanitize_text_field($task_status) : '',
			'parent_id'   => ($data->post_parent > 0 && get_post_type($data->post_parent) === 'kanbanz_task') ? $data->post_parent : 0,
			'parent_title' => ($data->post_parent > 0 && get_post_type($data->post_parent) === 'kanbanz_task') ? get_the_title($data->post_parent) : '',
			'ticket_number' => $ticket_number ? sanitize_text_field($ticket_number) : '',
		]);
	}

	public function kanbanz_remove_task(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');

		$post_id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
		$this->deskess_require_task_edit($post_id);  
		$title = get_the_title($post_id);
		$board_id = kanbanz_resolve_board_id($post_id);
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';

		if (!$post_id) {
			wp_send_json_error(['message' => __('Invalid task ID', 'kanbanz')]);
		}
		$deleted = wp_delete_post($post_id, false);

		if ($deleted) {

			// Update the log
			$log_title = $title. ' has been removed by '. $user_name;
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');

			wp_send_json_success(['message' => __('Task deleted successfully.', 'kanbanz')]);
		} else {
			wp_send_json_error(['message' => __('Failed to delete the task', 'kanbanz')]);
		}
	}

	public function kanbanz_save_task_description(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$post_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$this->deskess_require_task_edit($post_id);
		$post_content = isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '';
		$user_id = get_current_user_id();
		$user = wp_get_current_user();

		$updated = wp_update_post([
			'ID'           => $post_id,
			'post_content' => $post_content,
		]);

		if (is_wp_error($updated)) {
			wp_send_json_error(['message' => $updated->get_error_message()]);
		}

		// Log activity
		$activity = get_post_meta($post_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $user->user_login,
			'action'  => 'edited description',
			'comment' => '',
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
		update_post_meta($post_id, 'kanbanz_activity', $activity);
		update_post_meta($post_id, 'kanbanz_last_edited', [
			'user' => $user->user_login,
			'time' => time(),
		]);

		wp_send_json_success(['message' => __('Description updated', 'kanbanz'), 'content' => $post_content]);
	}

	
	public function kanbanz_update_board_desc(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');

		// 'edit_posts' is a generic WP capability held by Contributors/
		// Authors regardless of any Kanbanz role — tightened to kanbanz_staff
		// plus an actual board-access check, matching the rest of this file.
		if ( ! current_user_can( 'kanbanz_staff' ) ) {
			wp_send_json_error(['message' => __('You do not have sufficient permission', 'kanbanz')]);
		}

		$post_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
		$post_content = isset($_POST['val']) ? wp_kses_post(wp_unslash($_POST['val'])) : '';
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';

		if (!$post_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($post_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		if (empty($post_content)) {
			wp_send_json_error(['message' => __('Description is empty.', 'kanbanz')]);
		}

		$updated = wp_update_post(['ID' => $post_id,'post_content' => $post_content,], true);

		if (is_wp_error($updated)) {
			wp_send_json_error(['message' => $updated->get_error_message()]);
		} else {
			
			$log_title = 'Board description has been updated by '. $user_name;
			kanbanz_update_activity($post_id, $user_name, $log_title, 'kanbanz_board_activity');

			wp_send_json_success(['content' => $post_content]);
		}
	}


	// not cheked yet
	public function kanbanz_tasks_status(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
		$order = isset($_POST['task_order']) ? array_map('absint', $_POST['task_order']) : array();
		$title = get_the_title($task_id);
		$board_id = kanbanz_resolve_board_id($task_id);
		$user_id = get_current_user_id();
		$user_info = get_userdata($user_id);
		$user_name = $user_info ? $user_info->display_name : 'Unknown User';

		if ($task_id === 0 || empty($status)) {
			wp_send_json_error(['message' => __('Task Cannot shift', 'kanbanz')]);
		}
		// This is the live drag-and-drop handler — previously only checked
		// the baseline kanbanz_staff capability, with no check that the
		// caller can access the task's board.
		if (!$board_id || !kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}
		if ($status === kanbanz_get_board_terminal_status($board_id) && !kanbanz_user_can_complete_tasks($board_id)) {
			wp_send_json_error(['message' => __('You do not have permission to mark tasks Completed on this board.', 'kanbanz')]);
		}

		$prev_status = get_post_meta($task_id,'task_status',true);

	if (!empty($order)) {
		global $wpdb;
		// Restrict the reorder to tasks that actually belong to this board —
		// previously $order ids were trusted as-is and could rewrite
		// menu_order for any post ID on the site, not just this board's tasks.
		$valid_ids = get_posts(array(
			'post_type'      => 'kanbanz_task',
			'post_parent'    => $board_id,
			'post__in'       => $order,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));
		$case_args = array();
		$ids = array();
		foreach ($order as $index => $id) {
			if (!in_array($id, $valid_ids, true)) continue;
			$case_args[] = $id;
			$case_args[] = $index;
			$ids[] = $id;
		}
		if (!empty($ids)) {
			$case_sql = implode(' ', array_fill(0, count($ids), 'WHEN %d THEN %d'));
			$in_sql = implode(',', array_fill(0, count($ids), '%d'));
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal table names (escaped) and prepared/int-cast values.
			$wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET menu_order = CASE ID {$case_sql} END WHERE ID IN ({$in_sql})", array_merge($case_args, $ids)));
		}
	}

		update_post_meta($task_id, 'task_status', $status);
		$post = get_post($task_id);

		$log_title = $title .' has been moved to '. $status .' by '. $user_name;
		kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		
		wp_send_json_success($this->kanbanz_get_post_data($post));
	}


	public function kanbanz_remove_board(){
		check_ajax_referer('kanbanz_nonce','_wpnonce');

		if ( ! current_user_can( 'delete_posts' ) ) {
			wp_send_json_error(['message' => __('You do not have sufficient permission', 'kanbanz')]);
		}

		$post_id = isset( $_POST['board_id'] ) ? absint( $_POST['board_id'] ) : 0;

		if (!$post_id) {
			wp_send_json_error(['message' => __('Invalid board ID', 'kanbanz')]);
		}

		$is_department = get_post_meta($post_id, 'kanbanz_is_department', true);
		if ($is_department) {
			wp_send_json_error(['message' => __('Department boards cannot be deleted.', 'kanbanz')]);
		}

		$deleted = wp_delete_post($post_id, false);

		if ($deleted) {
			wp_send_json_success(['message' => __('Board deleted successfully.', 'kanbanz')]);
		} else {
			wp_send_json_error(['message' => __('Failed to delete the board', 'kanbanz')]);
		}
	}

	public function kanbanz_save_comment(){
		global $wpdb;
		check_ajax_referer('kanbanz_nonce','_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		$comment = isset($_POST['comment']) ? wp_kses_post(wp_unslash($_POST['comment'])) : '';
		$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;

		if (empty($comment) || empty($task_id)) {
			wp_send_json_error(['message' => __('Invalid/empty input data.', 'kanbanz')]);
		}

		$user = wp_get_current_user();
		if (!$user->exists()) {
			wp_send_json_error(['message' => __('User not logged in.', 'kanbanz')]);
		}

		// Resolve board_id (walk up parent chain for subtasks)
		$board_id = kanbanz_resolve_board_id($task_id);

		// Save comment via WordPress comments API
		$comment_data = array(
			'comment_post_ID'  => $task_id,
			'comment_type'     => 'kanbanz_comment',
			'comment_author'   => $user->display_name,
			'comment_author_email' => $user->user_email,
			'comment_author_IP'   => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
			'comment_content'  => $comment,
			'user_id'          => $user->ID,
			'comment_approved' => 1,
		);
		$comment_id = wp_insert_comment($comment_data);

		if ($comment_id) {
			// Log activity (new)
			$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
			$new_entry = [
				'user'    => $user->user_login,
				'action'  => 'commented',
				'comment' => $comment,
				'time'    => time(),
			];
			array_unshift($activity, $new_entry); // Prepend
			update_post_meta($task_id, 'kanbanz_activity', $activity);
			update_post_meta($task_id, 'kanbanz_last_edited', [
				'user' => $user->user_login,
				'time' => time(),
			]);

			// Store mentioned usernames in comment meta for future use
			$mentioned_usernames = kanbanz_parse_mentions($comment);
			if (!empty($mentioned_usernames)) {
				update_post_meta($task_id, '_kanbanz_last_mentions', $mentioned_usernames);
				kanbanz_notify_mentioned_users($task_id, $board_id, $comment, $user->ID);
			}
			kanbanz_notify_task_commenters($task_id, $board_id, $comment, $user->ID);
            
			$inserted_data = get_comment($comment_id, ARRAY_A);
			wp_send_json_success(['thread' => $inserted_data, 'activity' => $activity]);
		} else {
			wp_send_json_error(['message' => __('Failed to save thread.', 'kanbanz')]);
		}
	}

	public function kanbanz_save_priority() {
		check_ajax_referer('kanbanz_nonce','_wpnonce');

		$task_id  = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
		$this->deskess_require_task_edit($task_id);
		$priority = isset( $_POST['priority'] ) ? sanitize_text_field( wp_unslash( $_POST['priority'] ) ) : '';

		$user = wp_get_current_user();
		update_post_meta($task_id, 'priority', $priority);

		// Log activity
		$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $user->user_login,
			'action'  => 'changed priority to ' . $priority,
			'comment' => '',
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
		update_post_meta($task_id, 'kanbanz_activity', $activity);
		update_post_meta($task_id, 'kanbanz_last_edited', [
			'user' => $user->user_login,
			'time' => time(),
		]);

		wp_send_json_success(['message' => __('Priority updated', 'kanbanz'), 'priority' => $priority]);
	}
	
	public function kanbanz_save_date() {
		check_ajax_referer('kanbanz_nonce','_wpnonce');

		$task_id  = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
		$this->deskess_require_task_edit($task_id);
		$due_date = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';

		$user = wp_get_current_user();
		update_post_meta($task_id, 'due_date', $due_date);

		// Log activity
		$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $user->user_login,
			'action'  => 'changed due date to ' . $due_date,
			'comment' => '',
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
		update_post_meta($task_id, 'kanbanz_activity', $activity);
		update_post_meta($task_id, 'kanbanz_last_edited', [
			'user' => $user->user_login,
			'time' => time(),
		]);

		wp_send_json_success(['message' => __('Due date updated', 'kanbanz'), 'due_date' => $due_date]);
	}
	
	public function kanbanz_get_details() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		$board_query = new WP_Query(array(
			'post_type'      => 'kanbanz_board',
			'post_status'    => array('publish', 'draft', 'pending'),
			'posts_per_page' => -1, // phpcs:ignore
		));

		$arranged = $this->build_tree($board_query->posts);
		$all_status = get_post_meta($board_id, 'board_statuses', true);

		wp_send_json_success([
			'statuses' => $all_status,
			'tree' => $arranged,
		]);
	}

	//status actions
	public function kanbanz_get_board_statuses() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		if (!$board_id) {
			wp_send_json_error(['message' => __('Invalid board ID.', 'kanbanz')]);
		}

		$all_status = get_post_meta($board_id, 'board_statuses', true);

		if (!$all_status) {
			wp_send_json_error(['message' => __('No statuses found for this board.', 'kanbanz')]);
		}

		wp_send_json_success(['statuses' => $all_status]);
	}

	// Filter tasks by user
	public function kanbanz_filter_tasks_by_user() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$filter_user_id = isset($_POST['filter_user_id']) ? intval($_POST['filter_user_id']) : 0;

		if (!$board_id) {
			wp_send_json_error(['message' => __('Invalid board ID.', 'kanbanz')]);
		}

		// Get user's role
		$role = kanbanz_get_user_role();
		
		// Get filter username
		$filter_username = '';
		if ($filter_user_id > 0) {
			$filter_staff = kanbanz_get_user_info($filter_user_id);
			$filter_username = $filter_staff ? $filter_staff->member_username : '';
		}

		// Get board data - pass role to skip internal filtering
		$response_data = kanbanz_get_board_data($board_id);
		
		if (!$response_data) {
			wp_send_json_error(['message' => __('Board not found or access denied.', 'kanbanz')]);
		}

		// Apply user filter if specified
		if ($filter_user_id > 0 && !empty($filter_username)) {
			$filtered_tasks = [];
			foreach ($response_data['tasks'] as $status => $tasks) {
				$filtered_tasks[$status] = array_filter($tasks, function($task) use ($filter_username) {
					$assigned = !empty($task['task_meta']['assigned']) ? $task['task_meta']['assigned'] : [];
					// Handle both array and string formats
					if (is_string($assigned)) {
						$assigned = explode(',', $assigned);
					}
					return in_array($filter_username, (array)$assigned);
				});
			}
			$response_data['tasks'] = $filtered_tasks;
		}

		wp_send_json_success($response_data);
	}

	public function kanbanz_move_task() {
		check_ajax_referer('kanbanz_nonce','_wpnonce');
        
		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$this->deskess_require_task_edit($task_id);
		$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        
		if (!$task_id || !$status) {
			wp_send_json_error(['message' => __('Invalid task ID or status.', 'kanbanz')]);
		}

		// The drag-and-drop UI calls kanbanz_tasks_status() rather than this
		// handler, but this one stays registered and reachable, so it needs the
		// same two guards that one applies — otherwise both are bypassable by
		// calling this action directly.
		$board_id = kanbanz_resolve_board_id($task_id);
		if (!$board_id || !kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		// Only accept a status this board actually defines. Writing an
		// arbitrary string here puts the task in a column the board never
		// renders, so it disappears from the board view while still counting
		// toward analytics totals.
		$board_statuses = get_post_meta($board_id, 'board_statuses', true);
		if (is_array($board_statuses) && !empty($board_statuses) && !in_array($status, $board_statuses, true)) {
			wp_send_json_error(['message' => __('That status does not exist on this board.', 'kanbanz')]);
		}

		if ($status === kanbanz_get_board_terminal_status($board_id) && !kanbanz_user_can_complete_tasks($board_id)) {
			wp_send_json_error(['message' => __('You do not have permission to mark tasks Completed on this board.', 'kanbanz')]);
		}

		$current_status = get_post_meta($task_id, 'task_status', true);
		if (empty($current_status)) {
			$current_status = get_post_meta($task_id, 'status', true);
		}
		$user = wp_get_current_user();
        
		update_post_meta($task_id, 'status', $status);
		update_post_meta($task_id, 'task_status', $status);
        
		// Log activity
		$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $user->user_login,
			'action'  => 'moved task from ' . $current_status . ' to ' . $status,
			'comment' => '',
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
		update_post_meta($task_id, 'kanbanz_activity', $activity);
		update_post_meta($task_id, 'kanbanz_last_edited', [
			'user' => $user->user_login,
			'time' => time(),
		]);
        
		wp_send_json_success(['message' => __('Task moved', 'kanbanz'), 'status' => $status]);
	}

	public function kanbanz_move_all_tasks(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		if (!current_user_can('kanbanz_staff')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
		}
		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$previous_status = isset($_POST['prev_status']) ? sanitize_text_field(wp_unslash($_POST['prev_status'])) : '';
		$new_status = isset($_POST['new_status']) ? sanitize_text_field(wp_unslash($_POST['new_status'])) : '';

		if (!$board_id || !$new_status) {
			wp_send_json_error(['message' => __('Missing or invalid board ID or status.', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		$args = array(
			'post_type' => 'kanbanz_task',
			'post_parent' => $board_id,
			'meta_query' => array(
				array(
					'key' => 'task_status',
					'value' => $previous_status,
					'compare' => '='
				),
			),
			'posts_per_page' => -1,
		);

		$query = new WP_Query($args);

		if ($query->have_posts()) {
			$moved_tasks = array();

			while ($query->have_posts()) {
				$query->the_post();
				$post_id = get_the_ID();
				update_post_meta($post_id, 'task_status', $new_status);
				$moved_tasks[] = $post_id;
			}

			wp_reset_postdata();

			wp_send_json_success([
				'message' => __('All tasks successfully moved to "', 'kanbanz') . $new_status . '" status.',
				'status' => $new_status,
				'moved_tasks' => $moved_tasks
			]);
		} else {
			wp_send_json_error(['message' => __('No tasks found with the given status.', 'kanbanz')]);
		}
	}
	public function kanbanz_sort_all_tasks() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		if (!current_user_can('kanbanz_staff')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
		}
		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
		$sort_by = isset($_POST['sort_by']) ? sanitize_text_field(wp_unslash($_POST['sort_by'])) : '';
		$sort_order = isset($_POST['sort_order']) ? sanitize_text_field(wp_unslash($_POST['sort_order'])) : '';

		if (!$board_id || !$status || !$sort_by) {
			wp_send_json_error(["message" => __('Missing or invalid board ID, status, or sort option.', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		$order = $sort_order === 'ASC' ? 'ASC' : 'DESC';
		$meta_key = '';

		switch ($sort_by) {
			case 'date':
				$orderby = 'date';
				break;
			case 'task':
				$orderby = 'title'; // We'll handle the sorting manually below
				break;
			case 'due':
				$orderby = 'meta_value';
				$meta_key = 'due_date';
				break;
			default:
				$orderby = 'date';
				break;
		}

		$args = array(
			'post_type' => 'kanbanz_task',
			'post_parent' => $board_id,
			'orderby' => $orderby,
			'order' => $order, // Initially sort in ascending order, we'll handle DESC later if needed
			'meta_key' => $meta_key,
			'meta_query' => array(
				array(
					'key' => 'task_status',
					'value' => $status,
					'compare' => '='
				),
			),
			'posts_per_page' => -1,
		);
		$query = new WP_Query($args);
		if ($query->have_posts()) {
			$tasks = $query->posts;

			if ($sort_by === 'task') {
				usort($tasks, function ($a, $b) use ($order) {
					$a_title = $a->post_title;
					$b_title = $b->post_title;
					// Extract numerical parts of the task title for natural sorting
					preg_match('/\d+/', $a_title, $a_matches);
					preg_match('/\d+/', $b_title, $b_matches);
					$a_num = isset($a_matches[0]) ? (int) $a_matches[0] : 0;
					$b_num = isset($b_matches[0]) ? (int) $b_matches[0] : 0;
					// Compare numerical values, handling sort order
					if ($order === 'ASC') {
						return $a_num <=> $b_num;
					} else {
						return $b_num <=> $a_num;
					}
				});
			}

			$sorted_tasks = [];
			$menu_order = 0;
			
			foreach ($tasks as $task) {
				wp_update_post(array(
					'ID' => $task->ID,
					'menu_order' => $menu_order,
				));
				$menu_order++;
				$sorted_tasks[] = [
					'ID' => $task->ID,
					'title' => $task->post_title,
				];
			}

			wp_reset_postdata();
			wp_send_json_success(["message" => __('Tasks sorted successfully.', 'kanbanz'),  'tasks' => $sorted_tasks,]);
		} else {
			wp_send_json_error(["message" => __('No tasks found.', 'kanbanz')]);
		}
	}

	

	//this will go under the usermeta instead of postmeta
	public function kanbanz_update_status_order(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		if (!isset($_POST['board_id']) || !isset($_POST['order'])) {
			wp_send_json_error(['message' => __('Invalid board ID or status order', 'kanbanz')]);
		}

		$board_id = intval($_POST['board_id']);
		$order = array_map('sanitize_text_field', wp_unslash($_POST['order']));

		if (empty($order)) {
			wp_send_json_error(['message' => __('Status order is empty.', 'kanbanz')]);
		}

		$updated = update_post_meta($board_id, 'board_statuses', $order);

		if (!$updated) {
			wp_send_json_error(['message' => __('Failed to update order. Please try again.', 'kanbanz')]);
		}

		wp_send_json_success(  ['message' => __('List successfully reorder', 'kanbanz')]);
	}

	public function kanbanz_update_board_title(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');

		if ( ! current_user_can( 'kanbanz_staff' ) ) {
			wp_send_json_error(['message' => __('You do not have sufficient permission', 'kanbanz')]);
		}

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$new_title = isset($_POST['board_title']) ? sanitize_text_field(wp_unslash($_POST['board_title'])) : '';

		if (!$board_id || empty($new_title)) {
			wp_send_json_error(['message' => __('Invalid board ID or Title', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		$post_data = array(
			'ID' => $board_id,
			'post_title' => $new_title,
		);

		$updated_post_id = wp_update_post($post_data, true);

		if (is_wp_error($updated_post_id)) {
			wp_send_json_error(['message' => __('Failed to update board title.', 'kanbanz')]);
		}else {
			wp_send_json_success(['message' => __('Board title updated successfully.', 'kanbanz'), 'post_title' => $new_title]);
		}
	}

	public function kanbanz_get_labels() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('User authentication failed', 'kanbanz')]);
			return;
		}

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
		$custom_labels = get_user_meta($user_id, 'kanbanz_custom_labels', true);
		$tasks_labels = get_user_meta($user_id, 'kanbanz_task_labels', true);

		// Initialize if $custom_labels is not an array
		if (!is_array($custom_labels)) {
			$custom_labels = [];
		}

		// Retrieve or set default labels for the board
		if (empty($custom_labels[$board_id])) {
			$default_colors = [
				uniqid() => ['color' => 'rgb(75, 206, 151)'],
				uniqid() => ['color' => 'rgb(245, 205, 71)'],
				uniqid() => ['color' => 'rgb(254, 163, 98)'],
				uniqid() => ['color' => 'rgb(248, 113, 104)'],
				uniqid() => ['color' => 'rgb(159, 143, 239)'],
			];
			$custom_labels[$board_id] = $default_colors;
			update_user_meta($user_id, 'kanbanz_custom_labels', $custom_labels);
		}

		$response_data = ['custom_labels' => $custom_labels[$board_id]];

		if (!empty($task_id) && !empty($tasks_labels[$task_id])) {
			$response_data['tasks_labels'] = $tasks_labels[$task_id];
		}

		wp_send_json_success($response_data);
	}

	public function kanbanz_save_custom_label() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('Error retrieving user ID.', 'kanbanz')]);
			return;
		}

		$post_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Board ID is required.', 'kanbanz')]);
			return;
		}

		$label_color = isset($_POST['label_color']) ? sanitize_text_field(wp_unslash($_POST['label_color'])) : '';
		$title = isset($_POST['val']) ? sanitize_text_field(wp_unslash($_POST['val'])) : '';
		$index = isset($_POST['index']) ? sanitize_text_field(wp_unslash($_POST['index'])) : '';

		if (empty($label_color)) {
			wp_send_json_error(['message' => __('Label color cannot be empty.', 'kanbanz')]);
			return;
		}

		$custom_labels = get_user_meta($user_id, 'kanbanz_custom_labels', true);
		$task_labels = get_user_meta($user_id, 'kanbanz_task_labels', true);
		$custom_labels = is_array($custom_labels) ? $custom_labels : [];

		if (!isset($custom_labels[$post_id])) {
			$custom_labels[$post_id] = [];
		}
		
		// Add or update the label in the custom labels array
		if (!empty($index)) {
			$custom_labels[$post_id][$index] = [
				'color' => $label_color,
				'txt'   => $title
			];

			foreach ($task_labels as &$labels) {

				if (isset($labels[$index])) {
					$labels[$index]['background'] = $label_color;
					$labels[$index]['txt'] = $title;
				}
	
			}

		} else {
			$custom_labels[$post_id][uniqid()] = [
				'color' => $label_color,
				'txt'   => $title
			];
		}

		// Update user meta with the new labels
		update_user_meta($user_id, 'kanbanz_custom_labels', $custom_labels);
		update_user_meta($user_id, 'kanbanz_task_labels', $task_labels);

		// Return success response
		wp_send_json_success([
			'message' => __('Label saved successfully.', 'kanbanz'),
			'custom_labels' => $custom_labels[$post_id]
		]);
	}
	
	public function kanbanz_delete_custom_label() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');

		if ( ! current_user_can( 'kanbanz_staff' ) ) {
			wp_send_json_error(['message' => __('You do not have sufficient permission', 'kanbanz')]);
		}

		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('Error on getting user', 'kanbanz')]);
			return;
		}

		$post_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Error on getting post id', 'kanbanz')]);
			return;
		}
		if (!kanbanz_user_can_access_board($post_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
			return;
		}

		$index = isset($_POST['index']) ? sanitize_text_field(wp_unslash($_POST['index'])) : '';
		$all_labels = get_user_meta($user_id, 'kanbanz_custom_labels', true);

		if((!empty($index) || $index === '0') && isset($all_labels[$post_id][$index]) ){
			unset($all_labels[$post_id][$index]);
		}

		update_user_meta($user_id, 'kanbanz_custom_labels', $all_labels);
		wp_send_json_success(['message' => __('Label deleted successfully', 'kanbanz')]);
	}
	public function kanbanz_add_task_label() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('Error on getting user', 'kanbanz')]);
			return;
		}
		
		$post_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
		
		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Error on getting post id', 'kanbanz')]);
			return;
		}

		$all_labels = get_user_meta($user_id, 'kanbanz_task_label', true);
		
		if(!is_array($all_labels)){
			$all_labels = array();
		}
		
		update_user_meta($user_id, 'kanbanz_custom_labels', $all_labels);
		wp_send_json_success(['message' => __('Label saved successfully', 'kanbanz')]);
	}
	public function kanbanz_board_layout() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('Error on getting user', 'kanbanz')]);
			return;
		}

		$post_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$layout = isset($_POST['layout']) ? sanitize_text_field(wp_unslash($_POST['layout'])) : '';

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Error on getting post id', 'kanbanz')]);
			return;
		}

		if (empty($layout)) {
			wp_send_json_error(['message' => __('Layout does not exist', 'kanbanz')]);
			return;
		}

		$kanbanz_layout = get_user_meta($user_id, 'kanbanz_board_layout', true);

		if(!is_array($kanbanz_layout)){
			$kanbanz_layout = array();
		}

		$kanbanz_layout[$post_id] = $layout;
		update_user_meta($user_id, 'kanbanz_board_layout', $kanbanz_layout);
		wp_send_json_success(['message' => __('Layout changed successfully', 'kanbanz'), 'layout' => $kanbanz_layout]);
	}
	public function kanbanz_label_element() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$user_id = get_current_user_id();
		
		if ($user_id === 0) {
			wp_send_json_error(['message' => __('Error on getting user', 'kanbanz')]);
			return;
		}

		$post_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
		$is_checked = isset($_POST['isChecked']) ? filter_var(wp_unslash($_POST['isChecked']), FILTER_VALIDATE_BOOLEAN) : false;
		$content = isset($_POST['text']) ? sanitize_text_field(wp_unslash($_POST['text'])) : '';
		$uniqueid = isset($_POST['unique_id']) ? sanitize_text_field(wp_unslash($_POST['unique_id'])) : '';
		$label_color = isset($_POST['color']) ? sanitize_text_field(wp_unslash($_POST['color'])) : '';

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Error on getting post id', 'kanbanz')]);
			return;
		}

		if (empty($label_color) && empty($content)) {
			wp_send_json_error(['message' => __('There is something gone error', 'kanbanz')]);
			return;
		}

		$task_label = get_user_meta($user_id, 'kanbanz_task_labels', true);

		if (!is_array($task_label)) {
			$task_label = array();
		}

		if (!isset($task_label[$post_id])) {
			$task_label[$post_id] = array();
		}

		$label_data = array(
			'background' => $label_color,
			'txt' => $content,
		);

		if($is_checked === false){
			unset($task_label[$post_id][$uniqueid]);
		}else{
			$task_label[$post_id][$uniqueid] = $label_data;
		}

		update_user_meta($user_id, 'kanbanz_task_labels', $task_label); 
		wp_send_json_success(['message' => __('Label added successfully', 'kanbanz'), 'task_labels'=>$task_label[$post_id]]);
	}

	public function kanbanz_board_search(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$search_query =  isset($_POST['val']) ? sanitize_text_field(wp_unslash($_POST['val'])) : '';

		$args = [
			'post_type'      => 'kanbanz_board',
			'posts_per_page' => 5,
			's'              => $search_query,
			'post_status'    => 'publish',
		];

		$query = new WP_Query($args);
		$results = [];

		if ($query->have_posts()) {

			while ($query->have_posts()) {
				$query->the_post();
				$results[] = [
					'id'      => get_the_ID(),
					'title'   => esc_html(get_the_title()),
					'content' =>  wp_kses_post(apply_filters('the_content', get_the_content())),
				];
			}

			wp_reset_postdata();
			wp_send_json_success($results);
		} else {
			wp_send_json_error(['message' => __('Board not found.', 'kanbanz')]);
		}
		wp_die();
	}
	public function kanbanz_get_activity(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		$board_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		$type = isset($_POST['board_type']) ? sanitize_text_field( wp_unslash($_POST['board_type'])) : '';
		

		if(empty($type)){
			wp_send_json_error(['message' => __('Error on getting activity', 'kanbanz')]);
		}

		if ($board_id === 0) {
			wp_send_json_error(['message' => __('Invalid board ID', 'kanbanz')]);
		}

		// Activity reveals board/task history, so require access to the board it belongs to
		$access_board = ($type == 'board') ? $board_id : kanbanz_resolve_board_id($board_id);
		if (!$access_board || !kanbanz_user_can_access_board($access_board)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		if($type == 'board'){
			$activity = get_post_meta($board_id, 'kanbanz_board_activity', true);
		}else{
			$activity = get_post_meta($board_id, 'kanbanz_activity', true);
		}

		wp_send_json_success($activity);
	}

	public function kanbanz_members_list(){
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');
		
		$user_id = get_current_user_id();

		if ($user_id === 0) {
			wp_send_json_error(['message' => __('User authentication failed', 'kanbanz')]);
		}

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;
		$task_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;

		// Resolve the board from the task rather than trusting the posted
		// board_id, then gate on it — this previously returned the assignee
		// list for any task id on any board to any kanbanz_staff user.
		if (!$board_id) {
			$board_id = kanbanz_resolve_board_id($task_id);
		} else {
			$board_id = kanbanz_resolve_board_id($task_id) ?: $board_id;
		}
		if (!$board_id || !kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		// kanbanz_staff is a capability, not a role, so get_users(['role' => ...])
		// matched nothing and this always returned an empty user list.
		$users = kanbanz_get_all_agents();

		$all_assigned_staff = kanbanz_get_task_assigned_staff($task_id);
		wp_send_json_success(['users' => $users , 'assigned' => $all_assigned_staff]);
	}

	public function kanbanz_assign_staff() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');

		if ( ! current_user_can( 'kanbanz_staff' ) ) {
			wp_send_json_error(['message' => __('You do not have sufficient permission', 'kanbanz')]);
		}

		$post_id = isset($_POST['task_id']) ? intval($_POST['task_id']) : 0;
		$staff_slug = isset($_POST['slug']) ? sanitize_text_field(wp_unslash($_POST['slug'])) : '';

		// Get user's role
		$role = kanbanz_get_user_role();

		// Get the board for this task (walk up parent chain for subtasks)
		$board_id = kanbanz_resolve_board_id($post_id);
		if (!$board_id) {
			wp_send_json_error(['message' => __('Task not found in a board', 'kanbanz')]);
		}

		// Only the manager branch below had any access restriction — staff
		// had none at all, letting any staff member (re)assign any task on
		// any board site-wide. Baseline board-access check for everyone
		// except admin; managers get the additional department check below.
		if ($role !== 'admin' && !kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		// Manager can only assign users within their department
		if ($role === 'manager') {
			$user_id = get_current_user_id();
			$staff_info = kanbanz_get_user_info($user_id);
			
			if (!empty($staff_info)) {
				$dept_ids = Dept::objects()
					->filter(['manager_id' => $staff_info->member_id])
					->values_flat('dept_id');
				$dept_ids_array = array_column($dept_ids, 'dept_id');
				
				// Check if task is in manager's department
				$task_dept_id = get_post_meta($board_id, 'deskuss_dept_link', true);
				if (!in_array($task_dept_id, $dept_ids_array)) {
					wp_send_json_error(['message' => __('You can only assign tasks in your department', 'kanbanz')]);
				}
				
				// Verify the user being assigned is in the same department
				$assignee_info = kanbanz_get_user_info($staff_slug);
				if (!empty($assignee_info)) {
					$assignee_dept_id = $assignee_info->dept_id;
					if (!in_array($assignee_dept_id, $dept_ids_array)) {
						wp_send_json_error(['message' => __('You can only assign users from your department', 'kanbanz')]);
					}
				}
			}
		}
		
		$all_assigned_staff = kanbanz_get_task_assigned_staff($post_id);

		if (empty($all_assigned_staff) || !is_array($all_assigned_staff)) {
			$all_assigned_staff = array(); // Initialize empty array if not set
		}

		$is_new_assignee = !in_array($staff_slug, $all_assigned_staff);
		if (!$is_new_assignee) {
			$all_assigned_staff = array_diff($all_assigned_staff, array($staff_slug)); // Remove staff slug
		} else {
			$all_assigned_staff[] = $staff_slug; // Add staff slug
		}

		$all_assigned_staff = array_values($all_assigned_staff);
		update_post_meta($post_id, 'kanbanz_assigned_staff', $all_assigned_staff);
		if ($is_new_assignee) {
			kanbanz_add_assignees_to_board_team($board_id, $staff_slug);
			kanbanz_notify_assigned_user($staff_slug, $post_id, $board_id, get_current_user_id());
		}
		wp_send_json_success($all_assigned_staff);
	}
	
	public function kanbanz_get_collab_users() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		if (!current_user_can('kanbanz_staff')) {
			wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
		}

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		if (empty($board_id)) {
			wp_send_json_error(['message' => __('Board id not found.', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}

		$user_data = kanbanz_get_all_agents();

		$assigned_staff = get_post_meta($board_id, 'kanbanz_team_association', true);

		if (empty($assigned_staff)) {
			$assigned_staff = [];
		}
		
		wp_send_json_success([
			'all_users'       => $user_data,
			'assigned_staff'  => $assigned_staff
		]);
	}

	public function kanbanz_update_staff_members() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$board_id = isset($_POST['board_id']) ? intval($_POST['board_id']) : 0;

		if (empty($board_id)) {
			wp_send_json_error(['message' => __('Error updating the members. Board ID missing.', 'kanbanz')]);
		}

		// This writes kanbanz_team_association — the meta that GRANTS board
		// access. With only the kanbanz_staff cap in front of it, any staff user
		// could add themselves to any board's team (or write the meta onto an
		// arbitrary post id) and grant themselves access. Three gates, in order:
		// the target must really be a board, the caller must already have access
		// to it, and editing a team is a management action.
		if (get_post_type($board_id) !== 'kanbanz_board') {
			wp_send_json_error(['message' => __('Invalid board.', 'kanbanz')]);
		}
		if (!kanbanz_user_can_access_board($board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
		}
		$role = kanbanz_get_user_role();
		if ($role !== 'admin' && $role !== 'manager') {
			wp_send_json_error(['message' => __('You do not have permission to change board members.', 'kanbanz')]);
		}

		// Each member is an array like array('username' => ...); the username is sanitized below.
		$new_members = isset($_POST['assigned_members']) ? (array) wp_unslash($_POST['assigned_members']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$usernames = [];
		foreach ($new_members as $member) {

			if (!empty($member['username'])) {
				$candidate = sanitize_text_field($member['username']);
				// Only real users who are actually Kanbanz staff may land in the
				// team meta — otherwise arbitrary strings become access grants.
				$candidate_user = get_user_by('login', $candidate);
				if ($candidate_user && user_can($candidate_user, 'kanbanz_staff')) {
					$usernames[] = $candidate;
				}
			}

		}

		// A board with no team is open to all staff. When it becomes private, keep
		// everyone who is assigned a task on it so they don't lose their own tasks.
		if (empty(kanbanz_board_team($board_id)) && !empty($usernames)) {
			$usernames = array_merge($usernames, kanbanz_get_board_assignees($board_id));
		}
		$usernames = kanbanz_save_board_team($board_id, $usernames);

		$members = array();
		foreach ($usernames as $login) {
			$member = get_user_by('login', $login);
			$members[] = array('username' => $login, 'name' => $member ? $member->display_name : $login);
		}

		wp_send_json_success([
			'message' => __('Team members updated successfully.', 'kanbanz'),
			'board_id' => $board_id,
			'updated_members' => $usernames,
			'members' => $members,
		]);
	}

	public function kanbanz_task_attachments_handler() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

		if (empty($post_id)) {
			wp_send_json_error(['message' => __('Invalid post ID.', 'kanbanz')]);
		}

		$allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'zip', 'docx', 'doc', 'xlsx', 'csv', 'txt'];

		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$new_image_ids = [];

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file array is passed to media_handle_upload(), which validates it.
		if (!empty($_FILES['upload'])) {
			foreach ($_FILES['upload']['name'] as $key => $value) {
				if ($_FILES['upload']['error'][$key] === 0) {
					$ext = strtolower(pathinfo($value, PATHINFO_EXTENSION));
					if (!in_array($ext, $allowed_types)) {
						continue;
					}

					$file = [
						'name'     => $_FILES['upload']['name'][$key],
						'type'     => $_FILES['upload']['type'][$key],
						'tmp_name' => $_FILES['upload']['tmp_name'][$key],
						'error'    => $_FILES['upload']['error'][$key],
						'size'     => $_FILES['upload']['size'][$key],
					];
					// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

					$_FILES['single_upload'] = $file;

					$attach_id = media_handle_upload('single_upload', $post_id);

					if (!is_wp_error($attach_id)) {
						$new_image_ids[] = $attach_id;
					}
				}
			}
		}

		$existing_image_ids = get_post_meta($post_id, 'kanbanz_image_attachments', true);
		
		if (!is_array($existing_image_ids)) {
			$existing_image_ids = [];
		}

		$all_image_ids = array_unique(array_merge($existing_image_ids, $new_image_ids));

		update_post_meta($post_id, 'kanbanz_image_attachments', $all_image_ids);

		$output = '';
		
		foreach ($all_image_ids as $image_id) {
			$image_url = wp_get_attachment_url($image_id);
			$output .= '<div class="kanbanz-attachment">';
			$output .= '<a href="' . esc_url($image_url) . '" data-lightbox="image">';
			$output .= '<img src="' . esc_url($image_url) . '" alt="" />';
			$output .= '</a></div>';
		}

		wp_send_json_success([
			'message' => __('Files uploaded successfully.', 'kanbanz'),
			'output'  => $output,
			'image_ids' => $all_image_ids,
		]);
	}

	/**
	 * Save user theme preference (light/dark)
	 */
	public function kanbanz_save_theme() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$user_id = get_current_user_id();
		$theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : 'light';

		if (!in_array($theme, ['light', 'dark'])) {
			wp_send_json_error(['message' => __('Invalid theme.', 'kanbanz')]);
		}

		update_user_meta($user_id, 'kanbanz_theme', $theme);
		wp_send_json_success(['theme' => $theme]);
	}

	/**
	 * Save subtasks for a task (JSON in post_meta — no DB schema change)
	 */
	public function kanbanz_save_subtasks() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and each field is sanitized below.
		$subtasks = isset($_POST['subtasks']) ? json_decode(wp_unslash($_POST['subtasks']), true) : [];

		if (!$task_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}

		// Sanitize subtasks
		$sanitized = [];
		foreach ($subtasks as $st) {
			if (empty($st['text'])) continue;
			$sanitized[] = [
				'id'   => !empty($st['id']) ? sanitize_text_field($st['id']) : uniqid('st_'),
				'text' => sanitize_text_field($st['text']),
				'done' => !empty($st['done']) ? true : false,
			];
		}

		update_post_meta($task_id, 'kanbanz_subtasks_meta', $sanitized);
		wp_send_json_success(['subtasks' => $sanitized, 'count' => count($sanitized)]);
	}

	/**
	 * Save activity log for a task (JSON in post_meta — no DB schema change)
	 */
	public function kanbanz_save_activity() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';
		$comment = isset($_POST['comment']) ? wp_kses_post(wp_unslash($_POST['comment'])) : '';
        
		if (!$task_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}
        
		$current_user = wp_get_current_user();
		$activity = get_post_meta($task_id, 'kanbanz_activity', true) ?: [];
		$new_entry = [
			'user'    => $current_user->user_login,
			'action'  => $action,
			'comment' => $comment,
			'time'    => time(),
		];
		array_unshift($activity, $new_entry); // Prepend
        
		update_post_meta($task_id, 'kanbanz_activity', $activity);
		update_post_meta($task_id, 'kanbanz_last_edited', [
			'user' => $current_user->user_login,
			'time' => time(),
		]);
        
		wp_send_json_success(['activity' => $activity, 'last_edited' => $new_entry]);
	}

	/**
	 * Save labels for a task (JSON in post_meta — no DB schema change)
	 */
	public function kanbanz_save_labels() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and each field is sanitized below.
		$labels = isset($_POST['labels']) ? json_decode(wp_unslash($_POST['labels']), true) : [];
        
		if (!$task_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}
        
		$sanitized = [];
		foreach ($labels as $label) {
			if (empty($label['text'])) continue;
			$sanitized[] = [
				'text'       => sanitize_text_field($label['text']),
				'background' => sanitize_hex_color($label['background']),
			];
		}
        
		update_post_meta($task_id, 'kanbanz_labels', $sanitized);
		wp_send_json_success(['labels' => $sanitized, 'count' => count($sanitized)]);
	}

	/**
	 * Upload attachment to a task
	 */
	public function kanbanz_upload_attachment() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		if (!$task_id) {
			wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
		}

		// kanbanz_staff alone only says "is a Kanbanz user", not "may touch
		// this task". Kept in step with the standalone handler that owns this
		// action, so reviving this method cannot reintroduce the gap.
		$upload_board_id = kanbanz_resolve_board_id($task_id);
		if (!$upload_board_id || !kanbanz_user_can_access_board($upload_board_id)) {
			wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
		}

		if (empty($_FILES['files'])) {
			wp_send_json_error(['message' => __('No files uploaded.', 'kanbanz')]);
		}
        
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		$uploadedfiles = $_FILES['files']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_handle_upload().
		$upload_overrides = ['test_form' => false];
		$attachments = [];
        
		if (!function_exists('wp_handle_upload')) {
			wp_send_json_error(['message' => __('File upload not available.', 'kanbanz')]);
		}
        
		foreach ($uploadedfiles['name'] as $key => $name) {
			if ($uploadedfiles['error'][$key] !== UPLOAD_ERR_OK) continue;
            
			$file = [
				'name'     => $name,
				'type'     => $uploadedfiles['type'][$key],
				'tmp_name' => $uploadedfiles['tmp_name'][$key],
				'error'    => $uploadedfiles['error'][$key],
				'size'     => $uploadedfiles['size'][$key],
			];
            
			$upload = wp_handle_upload($file, $upload_overrides);
			if ($upload && empty($upload['error'])) {
				$attachment_id = wp_insert_attachment([
					'post_mime_type' => $upload['type'],
					'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
					'post_content'   => '',
					'post_status'    => 'inherit'
				], $upload['file']);
                
				if (!is_wp_error($attachment_id)) {
					require_once(ABSPATH . 'wp-admin/includes/image.php');
					$attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
					wp_update_attachment_metadata($attachment_id, $attachment_data);
                    
					// Add attachment ID to task meta
					$existing = get_post_meta($task_id, 'kanbanz_attachments', true) ?: [];
					$existing[] = $attachment_id;
					update_post_meta($task_id, 'kanbanz_attachments', $existing);
                    
					$attachments[] = [
						'id'   => $attachment_id,
						'name' => $name,
						'size' => $file['size'],
						'type' => (strpos($upload['type'], 'image/') === 0) ? 'image' : 
								((strpos($upload['type'], 'application/pdf') === 0) ? 'pdf' : 
								((strpos($upload['type'], 'zip') !== false) ? 'zip' : 'file')),
						'url'  => wp_get_attachment_url($attachment_id),
					];
				}
			}
		}
        
		if (empty($attachments)) {
			wp_send_json_error(['message' => __('No files were uploaded.', 'kanbanz')]);
		}
        
		wp_send_json_success(['attachments' => $attachments, 'count' => count($attachments)]);
	}

	/**
	 * Remove attachment from a task
	 */
	public function kanbanz_remove_attachment() {
		check_ajax_referer('kanbanz_nonce', '_wpnonce');
		$this->kanbanz_require_cap('kanbanz_staff');

		$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
		$attachment_id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        
		if (!$task_id || !$attachment_id) {
			wp_send_json_error(['message' => __('Invalid task or attachment ID.', 'kanbanz')]);
		}
        
		$existing = get_post_meta($task_id, 'kanbanz_attachments', true) ?: [];
		$new = array_filter($existing, function($id) use ($attachment_id) { return $id != $attachment_id; });
        
		if (count($new) === count($existing)) {
			wp_send_json_error(['message' => __('Attachment not found.', 'kanbanz')]);
		}

		update_post_meta($task_id, 'kanbanz_attachments', $new);
		wp_delete_attachment($attachment_id, true);

		wp_send_json_success(['removed' => $attachment_id, 'count' => count($new)]);
	}
}

new Kanbanz_Ajax();

// --- V2 Single-file Upload Handler ---

add_action('wp_ajax_kanbanz_upload_attachment', 'kanbanz_upload_attachment');
function kanbanz_upload_attachment() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
	if (!$task_id) {
		wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
	}

	// kanbanz_staff alone only says "is a Kanbanz user", not "may touch this
	// task" — without a board check any staff member could attach files to
	// tasks on boards they cannot open.
	$attach_board_id = kanbanz_resolve_board_id($task_id);
	if (!$attach_board_id || !kanbanz_user_can_access_board($attach_board_id)) {
		wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
	}

	if (empty($_FILES['attachment'])) {
		wp_send_json_error(['message' => __('No file uploaded.', 'kanbanz')]);
	}

	require_once(ABSPATH . 'wp-admin/includes/file.php');
	require_once(ABSPATH . 'wp-admin/includes/image.php');

	$file = $_FILES['attachment']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by wp_handle_upload().
	if ($file['error'] !== UPLOAD_ERR_OK) {
		wp_send_json_error(['message' => __('Upload error code: ', 'kanbanz') . $file['error']]);
	}

	$upload = wp_handle_upload($file, ['test_form' => false]);
	if (empty($upload) || !empty($upload['error'])) {
		wp_send_json_error(['message' => __('Upload failed: ', 'kanbanz') . ($upload['error'] ?? 'unknown error')]);
	}

	$attachment_id = wp_insert_attachment([
		'post_mime_type' => $upload['type'],
		'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
		'post_content'   => '',
		'post_status'    => 'inherit',
	], $upload['file']);

	if (is_wp_error($attachment_id)) {
		wp_send_json_error(['message' => __('Could not create attachment.', 'kanbanz')]);
	}

	$attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
	wp_update_attachment_metadata($attachment_id, $attachment_data);

	$existing = get_post_meta($task_id, 'kanbanz_attachments', true);
	if (!is_array($existing)) $existing = [];
	$existing[] = $attachment_id;
	update_post_meta($task_id, 'kanbanz_attachments', $existing);

	$name = basename($upload['file']);
	$mime = $upload['type'];
	wp_send_json_success([
		'attachment' => [
			'id'   => $attachment_id,
			'name' => $name,
			'size' => $file['size'],
			'type' => (strpos($mime, 'image/') === 0) ? 'image' :
					  ((strpos($mime, 'application/pdf') === 0) ? 'pdf' :
					  ((strpos($mime, 'zip') !== false) ? 'zip' : 'file')),
			'url'  => wp_get_attachment_url($attachment_id),
		],
	]);
}

add_action('wp_ajax_kanbanz_attach_media_to_task', 'kanbanz_ajax_attach_media_to_task');
/**
 * Attach existing or newly uploaded WordPress media library items to a Kanban task.
 */
function kanbanz_ajax_attach_media_to_task() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
	if (!$task_id) {
		wp_send_json_error(['message' => __('Invalid task ID.', 'kanbanz')]);
	}

	$attach_board_id = kanbanz_resolve_board_id($task_id);
	if (!$attach_board_id || !kanbanz_user_can_access_board($attach_board_id)) {
		wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
	}

	$ids = isset($_POST['attachment_ids']) ? array_map('absint', (array) wp_unslash($_POST['attachment_ids'])) : array();
	if (empty($ids)) {
		wp_send_json_error(['message' => __('No attachments selected.', 'kanbanz')]);
	}

	$existing = get_post_meta($task_id, 'kanbanz_attachments', true);
	if (!is_array($existing)) $existing = [];

	$added_count = 0;
	foreach ($ids as $att_id) {
		$clean_id = absint($att_id);
		if ($clean_id && !in_array($clean_id, $existing)) {
			$existing[] = $clean_id;
			$added_count++;
		}
	}

	update_post_meta($task_id, 'kanbanz_attachments', $existing);

	$user_name = wp_get_current_user()->user_login;
	$log_title = $added_count . ' attachment(s) added via Media Library by ' . $user_name;
	kanbanz_update_activity($task_id, $user_name, $log_title, 'kanbanz_activity');

	wp_send_json_success([
		'message' => __('Attachments added successfully.', 'kanbanz'),
		'attachment_ids' => $existing,
		'count' => count($existing)
	]);
}

// Kanban V2 — Extended AJAX handlers piggyback on existing actions
// The new kanban board reuses existing AJAX endpoints (kanbanz_save_new_task,
// kanbanz_remove_task, kanbanz_tasks_status, kanbanz_save_status, etc.)
// since they share the same data layer (kanbanz_board / kanbanz_task post types).
// Additional V2-specific handlers are registered below.

add_action('wp_ajax_kanbanz_get_boards', 'kanbanz_get_boards');
function kanbanz_get_boards() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}
	$boards = kanbanz_load_boards(true);
	$result = [];
	foreach ($boards as $board) {
		$board_id = $board['board_id'];
		$statuses = get_post_meta($board_id, 'board_statuses', true);
		$result[] = [
			'id'          => $board_id,
			'title'       => $board['title'],
			'content'     => wp_trim_words($board['content'], 20),
			'url'         => admin_url('admin.php?page=kanbanz&board_id=' . $board_id),
			'stage_count' => is_array($statuses) ? count($statuses) : 0,
			'date'        => get_the_date('M j, Y', $board_id),
		];
	}
	wp_send_json_success($result);
}

add_action('wp_ajax_kanbanz_get_board_data', 'kanbanz_ajax_get_board_data');
function kanbanz_ajax_get_board_data() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}
	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	if (!$board_id) {
		wp_send_json_error(['message' => __('Invalid board ID', 'kanbanz')]);
	}
	$data = kanbanz_get_board_data($board_id);
	if (!$data) {
		wp_send_json_error(['message' => __('Board not found', 'kanbanz')]);
	}
	wp_send_json_success($data);
}

add_action('wp_ajax_kanbanz_get_assignable_users', 'kanbanz_get_assignable_users');
/**
 * Return all eligible assignees (Admins, Kanban Managers & Staff, Deskuss Managers & Staff) with role badges.
 */
function kanbanz_get_assignable_users() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	if ($board_id && !kanbanz_user_can_access_board($board_id)) {
		wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
	}

	$users = kanbanz_get_eligible_assignees($board_id);
	wp_send_json_success(['users' => $users]);
}

// --- Subtask CPT Handlers (Phase 3) ---

/**
 * Verify $subtask_id is a real kanbanz_task subtask (has a parent task) and
 * that the current user can access the board it belongs to. Previously the
 * subtask handlers below only checked the baseline kanbanz_staff capability
 * with no post-type or board-ownership check at all, so any Kanbanz user
 * could mutate/force-delete an arbitrary post ID on the site by passing it
 * as subtask_id.
 */
function kanbanz_can_manage_subtask($subtask_id) {
	$post = get_post($subtask_id);
	if (!$post || $post->post_type !== 'kanbanz_task' || !$post->post_parent) {
		return false;
	}
	$board_id = wp_get_post_parent_id($post->post_parent);
	if (!$board_id) return false;
	return kanbanz_user_can_access_board($board_id);
}

/**
 * Verify the current user can access the board a (would-be) parent task
 * belongs to, for creating a new subtask under it.
 */
function kanbanz_can_manage_task_board($task_id) {
	$post = get_post($task_id);
	if (!$post || $post->post_type !== 'kanbanz_task') return false;
	$board_id = $post->post_parent;
	if (!$board_id) return false;
	return kanbanz_user_can_access_board($board_id);
}

add_action('wp_ajax_kanbanz_create_subtask', 'kanbanz_create_subtask');
function kanbanz_create_subtask() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
	$title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;

	if (!$parent_id || empty($title)) {
		wp_send_json_error(['message' => __('Invalid parent task or empty title', 'kanbanz')]);
	}

	if (!kanbanz_can_manage_task_board($parent_id)) {
		wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
	}

	if (!$board_id) {
		$board_id = wp_get_post_parent_id($parent_id);
	}

	$user = wp_get_current_user();
	$user_name = $user->user_login;

	$post_id = wp_insert_post([
		'post_title'  => $title,
		'post_type'   => 'kanbanz_task',
		'post_status' => 'publish',
		'post_parent' => $parent_id,
		'post_author' => get_current_user_id(),
	]);

	if (is_wp_error($post_id)) {
		wp_send_json_error(['message' => __('Failed to create subtask', 'kanbanz')]);
	}

	update_post_meta($post_id, 'task_status', '');
	update_post_meta($post_id, 'kanbanz_subtask_parent', $parent_id);
	update_post_meta($post_id, 'priority', '');
	update_post_meta($post_id, 'due_date', '');
	update_post_meta($post_id, 'kanbanz_assigned_staff', []);

	kanbanz_update_activity($parent_id, $user_name, 'added subtask: ' . $title, 'kanbanz_activity', ['type' => 'subtask_change']);

	wp_send_json_success([
		'id'        => $post_id,
		'title'     => $title,
		'parent_id' => $parent_id,
		'status'    => '',
		'done'      => false,
	]);
}

add_action('wp_ajax_kanbanz_toggle_subtask', 'kanbanz_toggle_subtask');
function kanbanz_toggle_subtask() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$subtask_id = isset($_POST['subtask_id']) ? absint($_POST['subtask_id']) : 0;
	$done = !empty($_POST['done']) ? 1 : 0;

	if (!$subtask_id) {
		wp_send_json_error(['message' => __('Invalid subtask ID', 'kanbanz')]);
	}
	if (!kanbanz_can_manage_subtask($subtask_id)) {
		wp_send_json_error(['message' => __('You do not have access to this subtask.', 'kanbanz')]);
	}

	update_post_meta($subtask_id, 'kanbanz_subtask_done', $done);

	$parent_id = wp_get_post_parent_id($subtask_id);
	$user = wp_get_current_user();
	$title = get_the_title($subtask_id);
	$action_text = $done ? 'completed subtask: ' . $title : 'reopened subtask: ' . $title;
	if ($parent_id) {
		kanbanz_update_activity($parent_id, $user->user_login, $action_text, 'kanbanz_activity', ['type' => 'subtask_change']);
	}

	wp_send_json_success(['id' => $subtask_id, 'done' => $done]);
}

add_action('wp_ajax_kanbanz_delete_subtask', 'kanbanz_delete_subtask');
function kanbanz_delete_subtask() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$subtask_id = isset($_POST['subtask_id']) ? absint($_POST['subtask_id']) : 0;
	if (!$subtask_id) {
		wp_send_json_error(['message' => __('Invalid subtask ID', 'kanbanz')]);
	}
	if (!kanbanz_can_manage_subtask($subtask_id)) {
		wp_send_json_error(['message' => __('You do not have access to this subtask.', 'kanbanz')]);
	}

	$parent_id = wp_get_post_parent_id($subtask_id);
	$title = get_the_title($subtask_id);

	wp_delete_post($subtask_id, true);

	$user = wp_get_current_user();
	if ($parent_id) {
		kanbanz_update_activity($parent_id, $user->user_login, 'deleted subtask: ' . $title, 'kanbanz_activity', ['type' => 'subtask_change']);
	}

	wp_send_json_success(['deleted' => true]);
}

add_action('wp_ajax_kanbanz_convert_subtask', 'kanbanz_convert_subtask');
function kanbanz_convert_subtask() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$subtask_id = isset($_POST['subtask_id']) ? absint($_POST['subtask_id']) : 0;
	$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';

	if (!$subtask_id) {
		wp_send_json_error(['message' => __('Invalid subtask ID', 'kanbanz')]);
	}
	if (!kanbanz_can_manage_subtask($subtask_id)) {
		wp_send_json_error(['message' => __('You do not have access to this subtask.', 'kanbanz')]);
	}

	$title = get_the_title($subtask_id);
	$parent_id = wp_get_post_parent_id($subtask_id);
	$board_id = $parent_id ? wp_get_post_parent_id($parent_id) : 0;

	// Remove subtask flag and parent
	delete_post_meta($subtask_id, 'kanbanz_subtask_parent');
	wp_update_post(['ID' => $subtask_id, 'post_parent' => $board_id]);

	// Set status
	if ($status) {
		update_post_meta($subtask_id, 'task_status', $status);
	} elseif ($parent_id) {
		$parent_status = get_post_meta($parent_id, 'task_status', true);
		update_post_meta($subtask_id, 'task_status', $parent_status);
	}

	$user = wp_get_current_user();
	if ($parent_id) {
		kanbanz_update_activity($parent_id, $user->user_login, 'converted subtask to task: ' . $title, 'kanbanz_activity', ['type' => 'subtask_change']);
	}

	wp_send_json_success([
		'id'        => $subtask_id,
		'title'     => $title,
		'parent_id' => $board_id,
		'status'    => $status ?: ($parent_id ? get_post_meta($parent_id, 'task_status', true) : ''),
	]);
}

add_action('wp_ajax_kanbanz_get_subtasks', 'kanbanz_get_subtasks');
function kanbanz_get_subtasks() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$task_id = isset($_POST['task_id']) ? absint($_POST['task_id']) : 0;
	if (!$task_id) {
		wp_send_json_error(['message' => __('Invalid task ID', 'kanbanz')]);
	}
	if (!kanbanz_can_manage_task_board($task_id)) {
		wp_send_json_error(['message' => __('You do not have access to this task.', 'kanbanz')]);
	}

	$subtasks = get_posts([
		'post_type'   => 'kanbanz_task',
		'post_parent' => $task_id,
		'numberposts' => -1,
		'orderby'     => 'menu_order date',
		'order'       => 'ASC',
	]);

	$result = [];
	foreach ($subtasks as $st) {
		$done = get_post_meta($st->ID, 'kanbanz_subtask_done', true);
		$assignees = kanbanz_get_task_assigned_staff($st->ID);
		$due_date = get_post_meta($st->ID, 'due_date', true);
		$priority = get_post_meta($st->ID, 'priority', true);
		if (empty($priority)) {
			$priority = get_post_meta($st->ID, 'task_priority', true);
		}

		$result[] = [
			'id'       => $st->ID,
			'title'    => $st->post_title,
			'done'     => !empty($done),
			'status'   => get_post_meta($st->ID, 'task_status', true),
			'assignee' => is_array($assignees) ? $assignees : [],
			'due_date' => $due_date ?: '',
			'priority' => $priority ?: '',
		];
	}

	// Count done for progress
	$total = count($result);
	$done_count = count(array_filter($result, function($s) { return $s['done']; }));

	wp_send_json_success(['subtasks' => $result, 'total' => $total, 'done' => $done_count]);
}

// --- WIP Limit Handler ---

add_action('wp_ajax_kanbanz_set_wip_limit', 'kanbanz_set_wip_limit');
function kanbanz_set_wip_limit() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
	$limit = isset($_POST['wip_limit']) ? absint($_POST['wip_limit']) : 0;

	if (!$board_id || empty($status)) {
		wp_send_json_error(['message' => __('Invalid board or status', 'kanbanz')]);
	}

	$wip_limits = get_post_meta($board_id, 'kanbanz_wip_limits', true);
	if (!is_array($wip_limits)) $wip_limits = [];

	if ($limit > 0) {
		$wip_limits[$status] = $limit;
	} else {
		unset($wip_limits[$status]);
	}

	update_post_meta($board_id, 'kanbanz_wip_limits', $wip_limits);

	wp_send_json_success(['wip_limits' => $wip_limits]);
}

// --- Notification Handlers ---

add_action('wp_ajax_kanbanz_get_notifications', 'kanbanz_ajax_get_notifications');
function kanbanz_ajax_get_notifications() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$user_id = get_current_user_id();
	$notifications = kanbanz_get_notifications($user_id, false, 30);

	$enriched = [];
	foreach ($notifications as $n) {
		$from_user = get_user_by('ID', $n['from_user_id']);
		$task = get_post($n['task_id']);
		$n['from_display_name'] = $from_user ? $from_user->display_name : '';
		$n['from_avatar'] = $from_user ? get_avatar_url($from_user->ID, ['size' => 32]) : '';
		$n['task_title'] = $task ? $task->post_title : '';
		$n['board_url'] = admin_url('admin.php?page=kanbanz&board_id=' . $n['board_id']);
		$enriched[] = $n;
	}

	$unread_count = kanbanz_count_unread_notifications($user_id);

	wp_send_json_success(['notifications' => $enriched, 'unread_count' => $unread_count]);
}

add_action('wp_ajax_kanbanz_mark_notifications_read', 'kanbanz_ajax_mark_notifications_read');
function kanbanz_ajax_mark_notifications_read() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$user_id = get_current_user_id();
	$ids = isset($_POST['notification_ids']) && is_array($_POST['notification_ids']) ? array_map('absint', wp_unslash($_POST['notification_ids'])) : [];

	kanbanz_mark_notifications_read($user_id, $ids);

	$unread_count = kanbanz_count_unread_notifications($user_id);
	wp_send_json_success(['unread_count' => $unread_count]);
}

add_action('wp_ajax_kanbanz_load_more_tasks', 'kanbanz_ajax_load_more_tasks');
function kanbanz_ajax_load_more_tasks() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	$status   = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
	$page     = isset($_POST['page']) ? absint($_POST['page']) : 1;
	$per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 50;

	if (!$board_id || empty($status)) {
		wp_send_json_error(['message' => __('Invalid board or status.', 'kanbanz')]);
	}

	$filters = array(
		'search'   => isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '',
		'priority' => isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : (isset($_POST['priorities']) ? (is_array($_POST['priorities']) ? implode(',', array_map('sanitize_text_field', wp_unslash($_POST['priorities']))) : sanitize_text_field(wp_unslash($_POST['priorities']))) : ''),
		'overdue'  => !empty($_POST['overdue']) && ($_POST['overdue'] === 'true' || $_POST['overdue'] === '1' || $_POST['overdue'] === true),
		'assignee' => isset($_POST['assignee']) ? sanitize_text_field(wp_unslash($_POST['assignee'])) : '',
		'date_type'=> isset($_POST['date_type']) ? sanitize_text_field(wp_unslash($_POST['date_type'])) : 'due_date',
		'date_from'=> isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '',
		'date_to'  => isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '',
	);

	$res = kanbanz_get_status_tasks($board_id, $status, $page, $per_page, $filters);
	$posts = $res['posts'];
	$total_found = $res['found_posts'];

	$html = '';
	$tasks_data = array();

	if (!empty($posts)) {
		$task_ids = wp_list_pluck($posts, 'ID');
		update_meta_cache('post', $task_ids);

		global $wpdb;
		$subtasks_by_parent = array();
		$task_ids_in = implode(',', array_map('intval', $task_ids));
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$st_results = $wpdb->get_results("
			SELECT p.post_parent, p.ID, pm.meta_value AS is_done
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'kanbanz_subtask_done')
			WHERE p.post_parent IN ($task_ids_in)
			  AND p.post_type = 'kanbanz_task'
			  AND p.post_status = 'publish'
		");
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if (!empty($st_results)) {
			foreach ($st_results as $st) {
				$parent_id = (int)$st->post_parent;
				if (!isset($subtasks_by_parent[$parent_id])) {
					$subtasks_by_parent[$parent_id] = array('total' => 0, 'done' => 0);
				}
				$subtasks_by_parent[$parent_id]['total']++;
				if (!empty($st->is_done) && ($st->is_done === '1' || $st->is_done === 'true')) {
					$subtasks_by_parent[$parent_id]['done']++;
				}
			}
		}

		$comments_by_post = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$c_results = $wpdb->get_results("
			SELECT comment_post_ID, COUNT(*) as c_count
			FROM {$wpdb->comments}
			WHERE comment_post_ID IN ($task_ids_in)
			  AND comment_type = 'kanbanz_comment'
			GROUP BY comment_post_ID
		");
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if (!empty($c_results)) {
			foreach ($c_results as $c) {
				$comments_by_post[(int)$c->comment_post_ID] = (int)$c->c_count;
			}
		}

		$statuses = get_post_meta($board_id, 'board_statuses', true);
		$user_id = get_current_user_id();
		$task_labels = get_user_meta($user_id, 'kanbanz_task_labels', true);

		foreach ($posts as $t) {
			$formatted = kanbanz_format_task_card_data($t, $subtasks_by_parent, $comments_by_post, $statuses);
			$tasks_data[] = $formatted;
			$html .= kanbanz_render_task_card_html($formatted, $task_labels);
		}
	}

	$has_more = ($page * $per_page) < $total_found;

	wp_send_json_success(array(
		'status' => $status,
		'page' => $page,
		'per_page' => $per_page,
		'total' => $total_found,
		'has_more' => $has_more,
		'html' => $html,
		'tasks' => $tasks_data,
	));
}

add_action('wp_ajax_kanbanz_search_board_tasks', 'kanbanz_ajax_search_board_tasks');
function kanbanz_ajax_search_board_tasks() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

	if (!$board_id) {
		wp_send_json_error(['message' => __('Invalid board.', 'kanbanz')]);
	}

	$statuses = get_post_meta($board_id, 'board_statuses', true);
	if (!is_array($statuses)) $statuses = array();

	$filters = array(
		'search'   => $search,
		'priority' => isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : (isset($_POST['priorities']) ? (is_array($_POST['priorities']) ? implode(',', array_map('sanitize_text_field', wp_unslash($_POST['priorities']))) : sanitize_text_field(wp_unslash($_POST['priorities']))) : ''),
		'overdue'  => !empty($_POST['overdue']) && ($_POST['overdue'] === 'true' || $_POST['overdue'] === '1' || $_POST['overdue'] === true),
		'assignee' => isset($_POST['assignee']) ? sanitize_text_field(wp_unslash($_POST['assignee'])) : '',
		'date_type'=> isset($_POST['date_type']) ? sanitize_text_field(wp_unslash($_POST['date_type'])) : 'due_date',
		'date_from'=> isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : '',
		'date_to'  => isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : '',
	);

	$results_by_status = array();
	$total_found_all = 0;
	$user_id = get_current_user_id();
	$task_labels = get_user_meta($user_id, 'kanbanz_task_labels', true);

	foreach ($statuses as $status) {
		$res = kanbanz_get_status_tasks($board_id, $status, 1, 50, $filters);
		$posts = $res['posts'];
		$total_found = $res['found_posts'];
		$total_found_all += $total_found;

		$html = '';
		$tasks_data = array();

		if (!empty($posts)) {
			$task_ids = wp_list_pluck($posts, 'ID');
			update_meta_cache('post', $task_ids);

			global $wpdb;
			$subtasks_by_parent = array();
			$task_ids_in = implode(',', array_map('intval', $task_ids));
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$st_results = $wpdb->get_results("
				SELECT p.post_parent, p.ID, pm.meta_value AS is_done
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'kanbanz_subtask_done')
				WHERE p.post_parent IN ($task_ids_in)
				  AND p.post_type = 'kanbanz_task'
				  AND p.post_status = 'publish'
			");
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if (!empty($st_results)) {
				foreach ($st_results as $st) {
					$parent_id = (int)$st->post_parent;
					if (!isset($subtasks_by_parent[$parent_id])) {
						$subtasks_by_parent[$parent_id] = array('total' => 0, 'done' => 0);
					}
					$subtasks_by_parent[$parent_id]['total']++;
					if (!empty($st->is_done) && ($st->is_done === '1' || $st->is_done === 'true')) {
						$subtasks_by_parent[$parent_id]['done']++;
					}
				}
			}

			$comments_by_post = array();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$c_results = $wpdb->get_results("
				SELECT comment_post_ID, COUNT(*) as c_count
				FROM {$wpdb->comments}
				WHERE comment_post_ID IN ($task_ids_in)
				  AND comment_type = 'kanbanz_comment'
				GROUP BY comment_post_ID
			");
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if (!empty($c_results)) {
				foreach ($c_results as $c) {
					$comments_by_post[(int)$c->comment_post_ID] = (int)$c->c_count;
				}
			}

			foreach ($posts as $t) {
				$formatted = kanbanz_format_task_card_data($t, $subtasks_by_parent, $comments_by_post, $statuses);
				$tasks_data[] = $formatted;
				$html .= kanbanz_render_task_card_html($formatted, $task_labels);
			}
		}

		$results_by_status[$status] = array(
			'total' => $total_found,
			'has_more' => (1 * 50) < $total_found,
			'page' => 1,
			'html' => $html,
			'tasks' => $tasks_data,
		);
	}

	wp_send_json_success(array(
		'search' => $search,
		'total_found' => $total_found_all,
		'statuses' => $results_by_status,
	));
}

// --- New Procedural AJAX Handlers for Board Listing, Templates, Import/Export, Bulk Actions, Settings ---

add_action('wp_ajax_kanbanz_get_board_listing_data', 'kanbanz_ajax_get_board_listing_data');
function kanbanz_ajax_get_board_listing_data() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$accessible_ids = kanbanz_get_user_accessible_boards();
	if (empty($accessible_ids)) {
		wp_send_json_success(['boards' => []]);
	}

	$query = new WP_Query(array(
		'post_type'      => 'kanbanz_board',
		'post_status'    => array('publish', 'draft', 'pending'),
		'posts_per_page' => -1,
		'post__in'       => $accessible_ids,
		'orderby'        => 'date',
		'order'          => 'DESC',
	));

	$boards = array();
	if ($query->have_posts()) {
		while ($query->have_posts()) {
			$query->the_post();
			$board_id = get_the_ID();
			$is_dept = get_post_meta($board_id, 'kanbanz_is_department', true);
			$dept_link = kanbanz_get_board_dept_id($board_id);

			$task_counts = kanbanz_get_board_task_counts_by_status($board_id);
			$total_tasks = array_sum($task_counts);
			$avatars = kanbanz_get_board_member_avatars($board_id);

			$boards[] = array(
				'id'          => $board_id,
				'title'       => get_the_title(),
				'description' => get_the_content(),
				'is_dept'     => !empty($is_dept) && !empty($dept_link),
				'dept_id'     => !empty($dept_link) ? intval($dept_link) : 0,
				'task_counts' => $task_counts,
				'total_tasks' => $total_tasks,
				'avatars'     => $avatars,
				'updated_at'  => get_the_modified_date('M j, Y'),
				'url'         => admin_url('admin.php?page=kanbanz&board_id=' . $board_id),
			);
		}
		wp_reset_postdata();
	}

	wp_send_json_success(['boards' => $boards]);
}

add_action('wp_ajax_kanbanz_bulk_task_action', 'kanbanz_ajax_bulk_task_action');
function kanbanz_ajax_bulk_task_action() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$task_ids = isset($_POST['task_ids']) ? array_filter(array_map('absint', (array) wp_unslash($_POST['task_ids']))) : array();
	$bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field(wp_unslash($_POST['bulk_action'])) : '';

	if (empty($task_ids) || empty($bulk_action)) {
		wp_send_json_error(['message' => __('Invalid task parameters.', 'kanbanz')]);
	}

	// None of the three branches below validated that $task_ids are actual
	// kanbanz_task posts on a board the caller can access — 'move'/'assign'
	// had no check at all, and 'delete' used the same global admin/manager
	// bypass already fixed elsewhere in this file (a manager could force-
	// delete any task on any board, not just their own). Filter task_ids
	// down to only the ones the current user is actually allowed to touch.
	$accessible_task_ids = array();
	foreach ($task_ids as $tid) {
		$post = get_post($tid);
		if (!$post || $post->post_type !== 'kanbanz_task') continue;
		$board_id = kanbanz_resolve_board_id($tid);
		if (!$board_id || !kanbanz_user_can_access_board($board_id)) continue;
		$accessible_task_ids[$tid] = $board_id;
	}

	$count = 0;
	if ($bulk_action === 'move') {
		$target_status = isset($_POST['target_status']) ? sanitize_text_field(wp_unslash($_POST['target_status'])) : '';
		if (empty($target_status)) wp_send_json_error(['message' => __('Missing target status.', 'kanbanz')]);
		foreach ($accessible_task_ids as $tid => $board_id) {
			if ($target_status === kanbanz_get_board_terminal_status($board_id) && !kanbanz_user_can_complete_tasks($board_id)) {
				continue;
			}
			update_post_meta($tid, 'task_status', $target_status);
			$count++;
		}
	} elseif ($bulk_action === 'delete') {
		$role = kanbanz_get_user_role();
		if ($role !== 'admin' && $role !== 'manager') {
			wp_send_json_error(['message' => __('Permission denied to delete tasks.', 'kanbanz')]);
		}
		foreach ($accessible_task_ids as $tid => $board_id) {
			wp_delete_post($tid, true);
			$count++;
		}
	} elseif ($bulk_action === 'assign') {
		$assignee = isset($_POST['assignee']) ? sanitize_text_field(wp_unslash($_POST['assignee'])) : '';
		foreach ($accessible_task_ids as $tid => $board_id) {
			$existing = kanbanz_get_task_assigned_staff($tid);
			if (!is_array($existing)) $existing = array();
			if ($assignee && !in_array($assignee, $existing)) {
				$existing[] = $assignee;
				update_post_meta($tid, 'kanbanz_assigned_staff', $existing);
				kanbanz_add_assignees_to_board_team($board_id, $assignee);
				kanbanz_notify_assigned_user($assignee, $tid, $board_id, get_current_user_id());
			}
			$count++;
		}
	} elseif ($bulk_action === 'priority') {
		$priority = isset($_POST['priority']) ? sanitize_text_field(wp_unslash($_POST['priority'])) : '';
		$valid_priorities = array('urgent', 'high', 'medium', 'low', 'none', '');
		if (in_array(strtolower($priority), $valid_priorities, true)) {
			$val = strtolower($priority) === 'none' ? '' : strtolower($priority);
			foreach ($accessible_task_ids as $tid => $board_id) {
				update_post_meta($tid, 'kanbanz_task_priority', $val);
				$count++;
			}
		}
	}

	wp_send_json_success(['affected' => $count, 'action' => $bulk_action]);
}

add_action('wp_ajax_kanbanz_reorder_subtasks', 'kanbanz_ajax_reorder_subtasks');
function kanbanz_ajax_reorder_subtasks() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
	$order = isset($_POST['subtask_order']) ? array_filter(array_map('absint', (array) wp_unslash($_POST['subtask_order']))) : array();

	if (!$parent_id || empty($order)) {
		wp_send_json_error(['message' => __('Invalid parent task or order.', 'kanbanz')]);
	}

	$board_id = kanbanz_resolve_board_id($parent_id);
	if (!$board_id || !kanbanz_user_can_access_board($board_id)) {
		wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
	}

	global $wpdb;
	$valid_subtask_ids = get_posts(array(
		'post_type'      => 'kanbanz_task',
		'post_parent'    => $parent_id,
		'post__in'       => $order,
		'posts_per_page' => -1,
		'fields'         => 'ids',
	));

	$case_args = array();
	$ids = array();
	foreach ($order as $index => $id) {
		if (!in_array($id, $valid_subtask_ids, true)) continue;
		$case_args[] = $id;
		$case_args[] = $index;
		$ids[] = $id;
	}

	if (!empty($ids)) {
		$case_sql = implode(' ', array_fill(0, count($ids), 'WHEN %d THEN %d'));
		$in_sql = implode(',', array_fill(0, count($ids), '%d'));
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal table names (escaped) and prepared/int-cast values.
		$wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET menu_order = CASE ID {$case_sql} END WHERE ID IN ({$in_sql})", array_merge($case_args, $ids)));
	}

	wp_send_json_success(['reordered' => count($ids)]);
}

add_action('wp_ajax_kanbanz_create_board_from_template', 'kanbanz_ajax_create_board_from_template');
function kanbanz_ajax_create_board_from_template() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Only admins can create boards.', 'kanbanz')]);
	}

	$template_key = isset($_POST['template_key']) ? sanitize_text_field(wp_unslash($_POST['template_key'])) : 'blank';
	$title        = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
	// Optional team; with none the board is visible to all staff
	$members      = isset($_POST['assigned_id']) && is_array($_POST['assigned_id']) ? array_map('sanitize_text_field', wp_unslash($_POST['assigned_id'])) : array();

	$board_id = kanbanz_create_board_from_template($template_key, $title, $members);
	if (is_wp_error($board_id)) {
		wp_send_json_error(['message' => $board_id->get_error_message()]);
	}

	wp_send_json_success([
		'board_id' => $board_id,
		'redirect' => admin_url('admin.php?page=kanbanz&board_id=' . $board_id),
	]);
}

// NOT registered on wp_ajax_kanbanz_save_settings. The settings page saves via a
// normal form post handled in main/settings.php. Kept (unhooked) rather than
// deleted so the intended REST-ish shape isn't lost; re-register only after
// reconciling it with the writer in settings.php.
function kanbanz_ajax_save_settings() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Unauthorized', 'kanbanz')]);
	}

	$settings = array(
		'tasks_per_page'              => isset($_POST['tasks_per_page']) ? absint($_POST['tasks_per_page']) : 50,
		'default_view'                => isset($_POST['default_view']) ? sanitize_text_field(wp_unslash($_POST['default_view'])) : 'board',
		'date_format'                 => isset($_POST['date_format']) ? sanitize_text_field(wp_unslash($_POST['date_format'])) : 'Y-m-d',
		'email_notifications_enabled' => !empty($_POST['email_notifications_enabled']) ? '1' : '0',
		'notify_on_assign'            => !empty($_POST['notify_on_assign']) ? '1' : '0',
		'notify_on_comment'           => !empty($_POST['notify_on_comment']) ? '1' : '0',
	);

	update_option('kanbanz_settings', $settings);
	wp_send_json_success(['message' => __('Settings saved successfully.', 'kanbanz')]);
}

add_action('wp_ajax_kanbanz_send_test_email', 'kanbanz_ajax_send_test_email');
function kanbanz_ajax_send_test_email() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => __('Unauthorized', 'kanbanz')]);
	}

	$user_id = get_current_user_id();
	$sent = kanbanz_send_email_notification($user_id, 'test_notification', array(
		'task_title' => 'Test Notification',
		'message'    => 'This is a test notification from your Kanbanz plugin settings.',
	));

	if ($sent) {
		wp_send_json_success(['message' => __('Test email sent successfully.', 'kanbanz')]);
	} else {
		wp_send_json_error(['message' => __('Failed to send test email. Ensure your server can send emails.', 'kanbanz')]);
	}
}

add_action('wp_ajax_kanbanz_save_board_settings', 'kanbanz_ajax_save_board_settings');
/**
 * Save board settings (completion permissions, allowed staff, department link, and lead).
 */
function kanbanz_ajax_save_board_settings() {
	check_ajax_referer('kanbanz_nonce', '_wpnonce');
	if (!current_user_can('kanbanz_staff')) {
		wp_send_json_error(['message' => __('Insufficient permissions', 'kanbanz')]);
	}

	$board_id = isset($_POST['board_id']) ? absint($_POST['board_id']) : 0;
	if (!$board_id || get_post_type($board_id) !== 'kanbanz_board') {
		wp_send_json_error(['message' => __('Invalid board.', 'kanbanz')]);
	}
	if (!kanbanz_user_can_access_board($board_id)) {
		wp_send_json_error(['message' => __('You do not have access to this board.', 'kanbanz')]);
	}

	$role = kanbanz_get_user_role();
	if ($role !== 'admin' && $role !== 'manager') {
		wp_send_json_error(['message' => __('You do not have permission to manage board settings.', 'kanbanz')]);
	}

	$user_name = wp_get_current_user()->user_login;

	// 1. Completion Mode
	if (isset($_POST['completion_mode'])) {
		$mode = sanitize_text_field(wp_unslash($_POST['completion_mode']));
		if (in_array($mode, array('everyone', 'managers', 'custom'), true)) {
			update_post_meta($board_id, 'kanbanz_completion_mode', $mode);
			$mode_labels = array(
				'everyone' => 'Everyone',
				'managers' => 'Admins & Board Managers only',
				'custom'   => 'Custom Staff List'
			);
			$label = isset($mode_labels[$mode]) ? $mode_labels[$mode] : $mode;
			$log_title = 'Task completion permission set to "' . $label . '" by ' . $user_name;
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		}
	}

	// 2. Custom Allowed Staff
	if (isset($_POST['allowed_staff'])) {
		$raw_staff = wp_unslash($_POST['allowed_staff']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below.
		$allowed = array();
		if (is_array($raw_staff)) {
			foreach ($raw_staff as $st) {
				$sanitized = sanitize_text_field($st);
				if ($sanitized !== '') $allowed[] = $sanitized;
			}
		} elseif (is_string($raw_staff) && $raw_staff !== '') {
			$allowed = array_map('sanitize_text_field', explode(',', $raw_staff));
		}
		update_post_meta($board_id, 'kanbanz_completion_allowed_users', $allowed);
	}

	// 3. Board Managers, Department Link, and Board Lead (Admin Only)
	if ($role === 'admin') {
		if (isset($_POST['board_managers'])) {
			$raw_mgrs = wp_unslash($_POST['board_managers']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per item below.
			$mgrs = array();
			if (is_array($raw_mgrs)) {
				foreach ($raw_mgrs as $m) {
					$sanitized = sanitize_text_field($m);
					if ($sanitized !== '') $mgrs[] = $sanitized;
				}
			} elseif (is_string($raw_mgrs) && $raw_mgrs !== '') {
				$mgrs = array_map('sanitize_text_field', explode(',', $raw_mgrs));
			}
			update_post_meta($board_id, 'kanbanz_board_managers', $mgrs);
			$log_title = 'Board managers updated by ' . $user_name;
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		}

		if (isset($_POST['dept_id'])) {
			$dept_id = sanitize_text_field(wp_unslash($_POST['dept_id']));
			update_post_meta($board_id, 'deskuss_dept_link', $dept_id);
			$log_title = 'Board department link updated by ' . $user_name;
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		}

		if (isset($_POST['board_lead'])) {
			$board_lead = sanitize_text_field(wp_unslash($_POST['board_lead']));
			update_post_meta($board_id, 'kanbanz_board_assignment', $board_lead);
		}
	}

	wp_send_json_success([
		'message' => __('Board settings saved successfully.', 'kanbanz'),
		'board_managers' => kanbanz_get_board_managers($board_id),
		'completion_mode' => get_post_meta($board_id, 'kanbanz_completion_mode', true) ?: 'everyone',
		'completion_allowed_users' => get_post_meta($board_id, 'kanbanz_completion_allowed_users', true) ?: array(),
		'can_complete_tasks' => kanbanz_user_can_complete_tasks($board_id),
	]);
}
