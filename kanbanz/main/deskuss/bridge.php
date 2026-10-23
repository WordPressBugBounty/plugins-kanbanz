<?php
if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE') || !defined('DESKUSS_DIR')) exit;

class Kanbanz_Deskuss_Bridge {

	private static $instance = null;

	public static function get_instance() {
		if(null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action('save_post_kanbanz_task', array($this, 'on_task_saved'), 10, 3);
		add_action('deskuss_wp_create_task', array($this, 'on_ticket_created'), 10, 1);
		add_action('deskuss_wp_sync_task_assignment', array($this, 'on_ticket_assigned'), 10, 1);
		add_action('deskuss_wp_sync_task_unassignment', array($this, 'on_ticket_unassigned'), 10, 1);
		add_action('deskuss_wp_close_task', array($this, 'on_ticket_closed'), 10, 1);
		add_action('deskuss_wp_sync_task_status', array($this, 'on_ticket_status_changed'), 10, 3);
		add_action('deskuss_wp_sync_ticket_event', array($this, 'on_ticket_event'), 10, 3);
		add_action('deskuss_wp_ticket_replied', array($this, 'on_ticket_replied'), 10, 2);
		add_action('deskuss_wp_ticket_message', array($this, 'on_ticket_message'), 10, 2);

		add_action('deskuss_wp_add_department', array($this, 'on_department_added'), 10, 2);
		add_action('deskuss_wp_update_department', array($this, 'on_department_updated'), 10, 2);
		add_action('deskuss_wp_delete_department', array($this, 'on_department_deleted'), 10, 1);

		add_action('kanbanz_sync_cron', array($this, 'run_full_sync'));

		add_filter('kanbanz_ticket_url_base', array($this, 'filter_ticket_url_base'), 10, 1);

		add_action('plugins_loaded', array($this, 'sync_capabilities'), 20);
		add_action('admin_init', array($this, 'sync_legacy_meta'), 99);
		add_action('admin_init', array($this, 'backfill_dept_board_teams'), 100);
	}

	public function sync_capabilities() {
		global $wp_roles;
		if(!isset($wp_roles)) $wp_roles = new WP_Roles();

		foreach(array('administrator', 'editor') as $role_name) {
			$role = get_role($role_name);
			if($role && $role->has_cap('deskuss_staff') && !$role->has_cap('kanbanz_staff')) {
				$role->add_cap('kanbanz_staff');
			}
		}

		// The deskuss_staff role itself has to be handled separately: it is
		// registered with only read/upload_files, so has_cap('deskuss_staff')
		// is false for it and the loop above can never match. (user_can() on a
		// *member* of the role returns true only because WordPress folds the
		// role name into the user's allcaps — that says nothing about the role
		// object.) Without this, actual Deskuss agents never receive
		// kanbanz_staff and are refused at every kanbanz_staff-gated page,
		// including the admin menu, despite holding task assignments.
		//
		// kanbanz_staff only, never kanbanz_manager — manager unlocks
		// department-wide board access in kanbanz_get_accessible_boards().
		// support_client is intentionally excluded: those users are redirected
		// out of wp-admin by Deskuss itself.
		$deskuss_staff = get_role('deskuss_staff');
		if($deskuss_staff && !$deskuss_staff->has_cap('kanbanz_staff')) {
			$deskuss_staff->add_cap('kanbanz_staff');
		}
	}

	public function sync_legacy_meta() {
		if(get_option('kanbanz_legacy_meta_synced_v3')) return;

		$boards = get_posts(array(
			'post_type'      => 'kanbanz_board',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));

		foreach($boards as $board_id) {
			$kanbanz_dept = get_post_meta($board_id, 'kanbanz_dept_link', true);
			if(!empty($kanbanz_dept) && empty(get_post_meta($board_id, 'deskuss_dept_link', true))) {
				update_post_meta($board_id, 'deskuss_dept_link', $kanbanz_dept);
			}
			if(!empty($kanbanz_dept) || !empty(get_post_meta($board_id, 'deskuss_dept_link', true))) {
				update_post_meta($board_id, 'kanbanz_is_department', '1');
			}
			delete_post_meta($board_id, 'kanbanz_dept_link');

			$deskuss_assigned = get_post_meta($board_id, 'deskuss_board_assignment', true);
			if(!empty($deskuss_assigned) && empty(get_post_meta($board_id, 'kanbanz_board_assignment', true))) {
				update_post_meta($board_id, 'kanbanz_board_assignment', $deskuss_assigned);
			}
			$deskuss_team = get_post_meta($board_id, 'deskuss_team_association', true);
			if(!empty($deskuss_team) && empty(get_post_meta($board_id, 'kanbanz_team_association', true))) {
				update_post_meta($board_id, 'kanbanz_team_association', $deskuss_team);
			}
		}

		$tasks = get_posts(array(
			'post_type'      => 'kanbanz_task',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));

		foreach($tasks as $task_id) {
			$deskuss_ticket = get_post_meta($task_id, 'deskuss_link_ticket', true);
			if(!empty($deskuss_ticket) && empty(get_post_meta($task_id, 'kanbanz_link_ticket', true))) {
				update_post_meta($task_id, 'kanbanz_link_ticket', $deskuss_ticket);
			}
			$deskuss_assigned = get_post_meta($task_id, 'deskuss_assigned_staff', true);
			if(!empty($deskuss_assigned) && empty(get_post_meta($task_id, 'kanbanz_assigned_staff', true))) {
				update_post_meta($task_id, 'kanbanz_assigned_staff', $deskuss_assigned);
			}
		}

		update_option('kanbanz_legacy_meta_synced_v3', '1');
	}

	public static function init() {
		return self::get_instance();
	}

	public function on_task_saved($post_id, $post, $update) {
		if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if($post->post_status === 'auto-draft') return;

		$deskuss_ticket = get_post_meta($post_id, 'deskuss_link_ticket', true);
		if(!empty($deskuss_ticket)) {
			update_post_meta($post_id, 'kanbanz_link_ticket', $deskuss_ticket);
		}

		$deskuss_assigned = get_post_meta($post_id, 'deskuss_assigned_staff', true);
		if(!empty($deskuss_assigned)) {
			update_post_meta($post_id, 'kanbanz_assigned_staff', $deskuss_assigned);
		}

		$board_id = $post->post_parent;
		if($board_id && get_post_type($board_id) === 'kanbanz_board') {
			$deskuss_dept = get_post_meta($board_id, 'deskuss_dept_link', true);
			if(!empty($deskuss_dept)) {
				update_post_meta($board_id, 'kanbanz_is_department', '1');
			}

			$deskuss_board_assigned = get_post_meta($board_id, 'deskuss_board_assignment', true);
			if(!empty($deskuss_board_assigned) && empty(get_post_meta($board_id, 'kanbanz_board_assignment', true))) {
				update_post_meta($board_id, 'kanbanz_board_assignment', $deskuss_board_assigned);
			}

			$deskuss_board_team = get_post_meta($board_id, 'deskuss_team_association', true);
			if(!empty($deskuss_board_team) && empty(get_post_meta($board_id, 'kanbanz_team_association', true))) {
				update_post_meta($board_id, 'kanbanz_team_association', $deskuss_board_team);
			}
		}
	}

	public function on_ticket_created($ticket_data) {
		$ticket_id = 0;
		if(is_object($ticket_data)) {
			if(method_exists($ticket_data, 'getId')) {
				$ticket_id = intval($ticket_data->getId());
			} elseif(!empty($ticket_data->ht['ticket_id'])) {
				$ticket_id = absint($ticket_data->ht['ticket_id']);
			}
		}
		if(empty($ticket_id)) return;

		$this->ensure_task_for_ticket($ticket_id);
	}

	public function on_ticket_assigned($ticket_id) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return;

		$assigned = $this->get_deskuss_ticket_assignees_by_id($ticket_id);
		if($assigned) {
			update_post_meta($task_id, 'kanbanz_assigned_staff', $assigned);
			update_post_meta($task_id, 'deskuss_assigned_staff', $assigned);
			kanbanz_add_assignees_to_board_team(wp_get_post_parent_id($task_id), $assigned);
		}

		$board_id = $this->find_board_by_ticket($ticket_id);
		if($board_id) {
			/* translators: %s: ticket ID */
			$log_title = sprintf(__('Ticket #%s reassigned', 'kanbanz'), $ticket_id);
			kanbanz_update_activity($board_id, wp_get_current_user()->user_login, $log_title, 'kanbanz_board_activity');
		}
	}

	public function on_ticket_unassigned($ticket_id) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return;

		$assigned = $this->get_deskuss_ticket_assignees_by_id($ticket_id);
		$val = $assigned ?: array();
		update_post_meta($task_id, 'kanbanz_assigned_staff', $val);
		update_post_meta($task_id, 'deskuss_assigned_staff', $val);
	}

	public function on_ticket_closed($ticket_id) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return;

		$board_id = $this->find_board_by_ticket($ticket_id);
		if(!$board_id) return;

		// Map the 'closed' state to the board's Completed/Done column
		$closed_status = $this->resolve_kanbanz_status_for_state($board_id, 'closed');
		if(!$closed_status) return;

		/* translators: 1: ticket ID, 2: status name */
		$log_title = sprintf(__('Ticket #%1$s closed — task moved to %2$s', 'kanbanz'), $ticket_id, $closed_status);
		$this->set_task_status_from_ticket($task_id, $board_id, $closed_status, $ticket_id, $log_title);
	}

	public function on_ticket_status_changed($ticket_id, $status_id, $status_state) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return;

		$board_id = $this->find_board_by_ticket($ticket_id);
		if(!$board_id) return;

		// Map the ticket state (open/progress/resolved/closed) to a kanbanz column
		$kanbanz_status = $this->resolve_kanbanz_status_for_state($board_id, $status_state);
		if(!$kanbanz_status) return;

		/* translators: 1: ticket ID, 2: status name */
		$log_title = sprintf(__('Ticket #%1$s status changed to %2$s', 'kanbanz'), $ticket_id, $kanbanz_status);
		$this->set_task_status_from_ticket($task_id, $board_id, $kanbanz_status, $ticket_id, $log_title);
	}

	public function on_ticket_event($ticket_id, $state, $data) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return;

		$board_id = $this->find_board_by_ticket($ticket_id);
		if(!$board_id) return;

		/* translators: 1: ticket ID, 2: ticket state */
		$log_title = sprintf(__('Ticket #%1$s: %2$s', 'kanbanz'), $ticket_id, $state);
		kanbanz_update_activity($task_id, wp_get_current_user()->user_login, $log_title, 'kanbanz_activity');
	}

	public function on_ticket_replied($ticket_id, $response_id = 0) {
		$ticket_id = absint($ticket_id);
		if(!$ticket_id) return;

		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) {
			$task_id = $this->ensure_task_for_ticket($ticket_id);
		}
		if(!$task_id) return;

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';

		if(absint($response_id) > 0) {
			/* translators: 1: ticket ID, 2: response ID */
			$log_title = sprintf(__('Reply posted on ticket #%1$d (response #%2$d)', 'kanbanz'), $ticket_id, absint($response_id));
		} else {
			/* translators: %d: ticket ID */
			$log_title = sprintf(__('Reply posted on ticket #%d', 'kanbanz'), $ticket_id);
		}
		kanbanz_update_activity($task_id, $user_name, $log_title, 'kanbanz_activity');

		$task = get_post($task_id);
		if($task && (!intval($task->post_parent) || !get_post($task->post_parent))) {
			$this->reparent_orphaned_task($task_id, $ticket_id);
		}

		// Agent replied → ticket is now "answered" → move task to In Progress
		$board_id = $this->find_board_by_ticket($ticket_id);
		if($board_id) {
			$progress_status = $this->resolve_kanbanz_status_for_state($board_id, 'progress');
			if($progress_status) {
				/* translators: 1: ticket ID, 2: status name */
				$move_log = sprintf(__('Ticket #%1$s answered — task moved to %2$s', 'kanbanz'), $ticket_id, $progress_status);
				$this->set_task_status_from_ticket($task_id, $board_id, $progress_status, $ticket_id, $move_log);
			}
		}
	}

	/**
	 * Handler for the deskuss_wp_ticket_message hook (user reply / end-user message).
	 * When the end-user replies to a ticket, the ticket's isanswered flag is set
	 * to 0 — the task should move back to "To Do" so agents know it needs attention.
	 */
	public function on_ticket_message($ticket_id, $message_id = 0) {
		$ticket_id = absint($ticket_id);
		if(!$ticket_id) return;

		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) {
			$task_id = $this->ensure_task_for_ticket($ticket_id);
		}
		if(!$task_id) return;

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';

		if(absint($message_id) > 0) {
			/* translators: 1: ticket ID, 2: message ID */
			$log_title = sprintf(__('User replied on ticket #%1$d (message #%2$d)', 'kanbanz'), $ticket_id, absint($message_id));
		} else {
			/* translators: %d: ticket ID */
			$log_title = sprintf(__('User replied on ticket #%d', 'kanbanz'), $ticket_id);
		}
		kanbanz_update_activity($task_id, $user_name, $log_title, 'kanbanz_activity');

		// User replied → ticket needs agent attention → move task back to To Do
		$board_id = $this->find_board_by_ticket($ticket_id);
		if($board_id) {
			$todo_status = $this->resolve_kanbanz_status_for_state($board_id, 'open');
			if($todo_status) {
				/* translators: 1: ticket ID, 2: status name */
				$move_log = sprintf(__('Ticket #%1$s user replied — task moved to %2$s', 'kanbanz'), $ticket_id, $todo_status);
				$this->set_task_status_from_ticket($task_id, $board_id, $todo_status, $ticket_id, $move_log);
			}
		}
	}

	public function on_department_deleted($dept_id) {
		$dept_id = intval($dept_id);
		$board_id = $this->find_board_by_deskuss_dept($dept_id);
		if(!$board_id) return;

		$policy = get_option('kanbanz_dept_delete_policy', 'trash');
		$reassign_to = (int) get_option('kanbanz_dept_reassign_board', 0);

		if($policy === 'reassign' && $reassign_to && get_post_type($reassign_to) === 'kanbanz_board' && $reassign_to !== $board_id) {
			$this->reparent_tasks_to_board($board_id, $reassign_to);
			$this->trash_board($board_id);
		} else {
			$this->trash_board($board_id);
		}

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
		kanbanz_update_activity($board_id, $user_name,
			/* translators: %s: deletion policy */
			sprintf(__('Department deleted in Deskuss; board handled (%s)', 'kanbanz'), $policy),
			'kanbanz_board_activity');
	}

	public function reparent_orphaned_task($task_id, $ticket_id) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$dept_id = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT dept_id FROM {$wpdb->prefix}dk_ticket WHERE ticket_id = %d",
			intval($ticket_id)
		));
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if(!$dept_id) return false;

		$board_id = $this->ensure_board_for_department($dept_id);
		if(!$board_id) return false;

		wp_update_post(array('ID' => $task_id, 'post_parent' => $board_id));
		return true;
	}

	private function trash_board($board_id) {
		$tasks = get_posts(array(
			'post_type'      => 'kanbanz_task',
			'post_parent'    => $board_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));
		foreach($tasks as $task_id) {
			wp_trash_post($task_id);
		}
		wp_trash_post($board_id);
	}

	private function reparent_tasks_to_board($from_board_id, $to_board_id) {
		$tasks = get_posts(array(
			'post_type'      => 'kanbanz_task',
			'post_parent'    => $from_board_id,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));
		foreach($tasks as $task_id) {
			wp_update_post(array('ID' => $task_id, 'post_parent' => $to_board_id));
		}
	}

	public function run_full_sync() {
		if(!defined('DESKUSS_DIR')) {
			return array('depts_created' => 0, 'tickets_created' => 0, 'orphans_fixed' => 0, 'skipped' => 'deskuss_inactive');
		}

		$stats = array('depts_created' => 0, 'tickets_created' => 0, 'orphans_fixed' => 0);

		// Refresh team membership on boards that already exist, not just on
		// ones created below — staff added to a department after its board was
		// created would otherwise never gain access to it.
		$this->sync_dept_board_teams();

		$depts = $this->get_all_deskuss_departments();
		if(!empty($depts)) {
			foreach($depts as $dept) {
				$existing = $this->find_board_by_deskuss_dept(intval($dept->id));
				if(!$existing) {
					$board_id = $this->ensure_board_for_department(intval($dept->id));
					if($board_id) $stats['depts_created']++;
				}
			}
		}

		$tickets = $this->get_open_tickets_without_task();
		if(!empty($tickets)) {
			foreach($tickets as $ticket) {
				if($this->ensure_task_for_ticket(intval($ticket->ticket_id))) {
					$stats['tickets_created']++;
				}
			}
		}

		$orphans = $this->get_orphaned_tasks();
		if(!empty($orphans)) {
			foreach($orphans as $task_id) {
				$ticket_ref = get_post_meta($task_id, 'kanbanz_link_ticket', true);
				if(!$ticket_ref) $ticket_ref = get_post_meta($task_id, 'deskuss_link_ticket', true);
				if(!$ticket_ref) continue;

				global $wpdb;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
				$ticket_id = (int) $wpdb->get_var($wpdb->prepare(
					"SELECT ticket_id FROM {$wpdb->prefix}dk_ticket WHERE number = %s OR ticket_id = %d",
					$ticket_ref, intval($ticket_ref)
				));
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if(!$ticket_id) continue;

				if($this->reparent_orphaned_task($task_id, $ticket_id)) {
					$stats['orphans_fixed']++;
				}
			}
		}

		update_option('kanbanz_last_cron_sync', array('time' => time(), 'stats' => $stats));
		return $stats;
	}

	private function get_all_deskuss_departments() {
		global $wpdb;
		$dept_table = esc_sql($wpdb->prefix . 'dk_department');
		$like = $wpdb->esc_like($dept_table);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like)) !== $dept_table) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
		return $wpdb->get_results("SELECT id, name FROM {$dept_table} ORDER BY id");
	}

	private function get_open_tickets_without_task() {
		global $wpdb;
		$ticket_table = esc_sql($wpdb->prefix . 'dk_ticket');
		$status_table  = esc_sql($wpdb->prefix . 'dk_ticket_status');

		foreach(array($ticket_table, $status_table) as $t) {
			$like = $wpdb->esc_like($t);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $like)) !== $t) {
				return array();
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$existing = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s
			   AND pm.meta_key IN ('kanbanz_link_ticket','deskuss_link_ticket')",
			'kanbanz_task'
		));
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = array_unique(array_filter(array_map('strval', $existing)));

		if(empty($existing)) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			return $wpdb->get_results($wpdb->prepare(
				"SELECT t.ticket_id, t.number, t.dept_id
				FROM {$ticket_table} t
				INNER JOIN {$status_table} s ON s.id = t.status_id
				WHERE s.state <> %s
				ORDER BY t.ticket_id DESC
				LIMIT 500",
				'closed'
			));
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$placeholders = implode(',', array_fill(0, count($existing), '%s'));
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		return $wpdb->get_results($wpdb->prepare(
			"SELECT t.ticket_id, t.number, t.dept_id
			FROM {$ticket_table} t
			INNER JOIN {$status_table} s ON s.id = t.status_id
			WHERE s.state <> %s
			  AND t.number NOT IN ($placeholders)
			ORDER BY t.ticket_id DESC
			LIMIT 500",
			array_merge(array('closed'), $existing)
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function get_orphaned_tasks() {
		return get_posts(array(
			'post_type'      => 'kanbanz_task',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => 'kanbanz_link_ticket',
					'compare' => 'EXISTS',
				),
			),
			'post_parent__in' => array(0),
		));
	}

	public function filter_ticket_url_base($url) {
		// Respect the configured Deskuss URL slug (default 'deskuss') and use the
		// admin tickets.php endpoint which accepts both ?id=<ticket_id> and ?number=<number>.
		// Cards store the public ticket number, so we append ?number= here and the
		// caller concatenates the number.
		$slug = get_option('deskuss_url_slug', 'deskuss');
		return trailingslashit(get_site_url()) . $slug . '/admin/tickets.php?number=';
	}

	public function on_department_added($dept_data, $post_data = array()) {
		$dept_id = 0;
		$dept_name = '';

		if(is_object($dept_data)) {
			$ht = !empty($dept_data->ht) ? $dept_data->ht : array();
			$dept_id = isset($ht['id']) ? absint($ht['id']) : 0;
			$dept_name = isset($ht['name']) ? sanitize_text_field(wp_unslash($ht['name'])) : '';
		} elseif(is_array($dept_data)) {
			$dept_id = !empty($dept_data['dept_id']) ? absint($dept_data['dept_id']) : 0;
			$dept_name = !empty($dept_data['name']) ? $dept_data['name'] : '';
		}

		if(empty($dept_id)) return;

		$board_id = $this->ensure_board_for_department($dept_id);
		if(!$board_id) return;

		if(!empty($dept_name)) {
			wp_update_post(array('ID' => $board_id, 'post_title' => $dept_name . ' Board'));
		}

		$this->sync_dept_board_meta($dept_id, $post_data);
	}

	public function on_department_updated($dept_data, $post_data = array()) {
		$dept_id = 0;
		$dept_name = '';

		if(is_object($dept_data)) {
			$ht = !empty($dept_data->ht) ? $dept_data->ht : array();
			$dept_id = isset($ht['id']) ? absint($ht['id']) : 0;
			$dept_name = isset($ht['name']) ? sanitize_text_field(wp_unslash($ht['name'])) : '';
		} elseif(is_array($dept_data)) {
			$dept_id = !empty($dept_data['dept_id']) ? absint($dept_data['dept_id']) : 0;
			$dept_name = !empty($dept_data['name']) ? $dept_data['name'] : '';
		}

		if(empty($dept_id) || empty($dept_name)) return;

		$board_id = $this->find_board_by_deskuss_dept($dept_id);
		if($board_id) {
			wp_update_post(array('ID' => $board_id, 'post_title' => $dept_name . ' Board'));
			$this->sync_dept_board_meta($dept_id, $post_data);
		}
	}

	public function sync_all_departments() {
		if(!defined('DESKUSS_DIR')) return array('boards_created' => 0, 'tasks_reparented' => 0);

		global $wpdb;
		$dept_table = esc_sql($wpdb->prefix . 'dk_department');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
		$depts = $wpdb->get_results("SELECT id, name FROM {$dept_table} ORDER BY name");

		$boards_created = 0;
		$tasks_reparented = 0;

		if(!empty($depts)) {
			foreach($depts as $dept) {
				$existing = $this->find_board_by_deskuss_dept($dept->id);
				if(!$existing) {
					$board_id = $this->ensure_board_for_department($dept->id);
					if($board_id) {
						$boards_created++;
					}
				}
			}
		}

		$orphan_tasks = get_posts(array(
			'post_type'      => 'kanbanz_task',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array('key' => 'deskuss_link_ticket', 'compare' => 'EXISTS'),
			),
			'post_parent'    => 0,
		));

		if(!empty($orphan_tasks)) {
			$ticket_table = esc_sql($wpdb->prefix . 'dk_ticket');
			foreach($orphan_tasks as $task_id) {
				$ticket_number = get_post_meta($task_id, 'deskuss_link_ticket', true);
				if(empty($ticket_number)) continue;

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
				$ticket = $wpdb->get_row($wpdb->prepare(
					"SELECT ticket_id, dept_id FROM {$ticket_table} WHERE number = %s OR ticket_id = %d",
					$ticket_number, intval($ticket_number)
				));
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if(empty($ticket) || empty($ticket->dept_id)) continue;

				$board_id = $this->ensure_board_for_department($ticket->dept_id);
				if($board_id) {
					wp_update_post(array('ID' => $task_id, 'post_parent' => $board_id));
					update_post_meta($board_id, 'kanbanz_is_department', '1');
					$tasks_reparented++;
				}
			}
		}

		return array('boards_created' => $boards_created, 'tasks_reparented' => $tasks_reparented);
	}

	private function sync_dept_board_meta($deskuss_dept_id, $post_data = array()) {
		$board_id = $this->find_board_by_deskuss_dept($deskuss_dept_id);
		if(!$board_id) return;

		update_post_meta($board_id, 'kanbanz_is_department', '1');
		if(!empty($post_data['assign_manager_name'])) {
			update_post_meta($board_id, 'kanbanz_board_assignment', sanitize_text_field($post_data['assign_manager_name']));
		}
		if(!empty($post_data['members']) && is_array($post_data['members'])) {
			$usernames = array();
			foreach($post_data['members'] as $member_id) {
				$user = get_user_by('ID', absint($member_id));
				if($user) $usernames[] = $user->user_login;
			}
			if(!empty($usernames)) {
				update_post_meta($board_id, 'kanbanz_team_association', $usernames);
			}
		}
	}

	private function find_board_by_deskuss_dept($deskuss_dept_id) {
		$boards = get_posts(array(
			'post_type'      => 'kanbanz_board',
			'meta_key'       => 'deskuss_dept_link',
			'meta_value'     => $deskuss_dept_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		));
		return !empty($boards) ? $boards[0] : null;
	}

	/**
	 * WP user_logins belonging to a Deskuss department — both the staff whose
	 * primary dept_id is this department and those granted access to it.
	 *
	 * Extracted so board creation and the backfill below cannot drift apart;
	 * previously this lived inline in ensure_board_for_department(), which runs
	 * only when a board is first created, so existing boards never picked up
	 * staff added to a department afterwards.
	 */
	private function get_dept_team_usernames($dept_id) {
		global $wpdb;

		if(!function_exists('deskuss_get_user_info')) return array();

		$sda_table = esc_sql($wpdb->prefix . 'dk_staff_dept_access');
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$dept_staff_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT staff_id FROM {$sda_table} WHERE dept_id = %d",
			intval($dept_id)
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$staff_table = esc_sql($wpdb->prefix . 'dk_staff');
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$primary_staff = $wpdb->get_col($wpdb->prepare(
			"SELECT staff_id FROM {$staff_table} WHERE dept_id = %d",
			intval($dept_id)
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$team_usernames = array();
		foreach(array_unique(array_merge($dept_staff_ids, $primary_staff)) as $staff_id) {
			$info = deskuss_get_user_info(intval($staff_id));
			if(!empty($info) && !empty($info->member_username)) {
				$team_usernames[] = sanitize_text_field($info->member_username);
			}
		}

		return array_values(array_unique($team_usernames));
	}

	/**
	 * Add each department's current staff to its board's team.
	 *
	 * Membership was only ever written when a board was created, so a staff
	 * member added to a department later never gained access to that
	 * department's board — they could hold task assignments on a board they
	 * could not open.
	 *
	 * Unions rather than overwrites: manually added team members are preserved,
	 * and no one can lose access as a result of this running.
	 */
	public function sync_dept_board_teams() {
		$boards = get_posts(array(
			'post_type'      => 'kanbanz_board',
			'posts_per_page' => -1,
			'post_status'    => array('publish', 'draft', 'pending'),
			'fields'         => 'ids',
		));

		foreach($boards as $board_id) {
			$dept_id = get_post_meta($board_id, 'deskuss_dept_link', true);
			if(empty($dept_id)) continue;

			$dept_team = $this->get_dept_team_usernames($dept_id);
			if(empty($dept_team)) continue;

			$current = get_post_meta($board_id, 'kanbanz_team_association', true);
			if(!is_array($current)) $current = array();

			$merged = array_values(array_unique(array_merge($current, $dept_team)));
			if(count($merged) !== count($current) || array_diff($merged, $current)) {
				update_post_meta($board_id, 'kanbanz_team_association', $merged);
			}
		}
	}

	/**
	 * One-shot backfill for sites that predate sync_dept_board_teams().
	 */
	public function backfill_dept_board_teams() {
		if(get_option('kanbanz_dept_teams_backfilled_v1')) return;
		$this->sync_dept_board_teams();
		update_option('kanbanz_dept_teams_backfilled_v1', 1);
	}

	public function ensure_board_for_department($dept_id) {
		$existing = $this->find_board_by_deskuss_dept($dept_id);
		if($existing) return $existing;

		$dept_name = kanbanz_get_department_name($dept_id);
		if(empty($dept_name)) {
			$dept_name = 'Department ' . $dept_id;
		}

		$board_title = $dept_name . ' Board';

		$manager_username = '';
		$team_usernames = array();

		global $wpdb;
		$dept_table = esc_sql($wpdb->prefix . 'dk_department');
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$dept_row = $wpdb->get_row($wpdb->prepare(
			"SELECT manager_id FROM {$dept_table} WHERE id = %d",
			intval($dept_id)
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if(!empty($dept_row) && !empty($dept_row->manager_id)) {
			if(function_exists('deskuss_get_user_info')) {
				$manager_info = deskuss_get_user_info(intval($dept_row->manager_id));
				if(!empty($manager_info) && !empty($manager_info->member_username)) {
					$manager_username = sanitize_text_field($manager_info->member_username);
				}
			}
			$wp_user = get_user_by('login', $manager_username);
			if(!$wp_user) {
				$wp_user = get_user_by('ID', intval($dept_row->manager_id));
				if($wp_user) $manager_username = $wp_user->user_login;
			}
		}

		$team_usernames = $this->get_dept_team_usernames($dept_id);

		$postarr = array(
			'post_title'   => $board_title,
			'post_type'    => 'kanbanz_board',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_author'  => 1,
		);
		if(!empty($manager_username)) {
			$manager_user = get_user_by('login', $manager_username);
			if($manager_user) {
				$postarr['post_author'] = $manager_user->ID;
			}
		}

		$board_id = wp_insert_post($postarr);
		if(is_wp_error($board_id)) return null;

		update_post_meta($board_id, 'board_statuses', array('To Do', 'In Progress', 'Under review', 'Completed'));
		update_post_meta($board_id, 'deskuss_dept_link', intval($dept_id));
		update_post_meta($board_id, 'kanbanz_is_department', '1');

		if(!empty($manager_username)) {
			update_post_meta($board_id, 'kanbanz_board_assignment', $manager_username);
		}
		if(!empty($team_usernames)) {
			update_post_meta($board_id, 'kanbanz_team_association', $team_usernames);
		}

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
		/* translators: %s: department name */
		$log_title = sprintf(__('Board auto-created for department "%s"', 'kanbanz'), $dept_name);
		kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');

		return $board_id;
	}

	private function find_task_by_ticket($ticket_id) {
		$search_values = array($ticket_id);

		$ticket_number = $ticket_id;
		if(is_numeric($ticket_id)) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$num = $wpdb->get_var($wpdb->prepare(
				"SELECT number FROM {$wpdb->prefix}dk_ticket WHERE ticket_id = %d",
				intval($ticket_id)
			));
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if($num) {
				$ticket_number = $num;
				$search_values[] = $num;
			}
		}
		$search_values = array_unique($search_values);

		$meta_query = array('relation' => 'OR');
		foreach($search_values as $val) {
			$meta_query[] = array('key' => 'kanbanz_link_ticket', 'value' => $val);
			$meta_query[] = array('key' => 'deskuss_link_ticket', 'value' => $val);
		}

		$args = array(
			'post_type'   => 'kanbanz_task',
			'meta_query'  => $meta_query,
			'posts_per_page' => 1,
			'fields'      => 'ids',
		);
		$query = new WP_Query($args);
		return !empty($query->posts) ? $query->posts[0] : null;
	}

	private function ensure_task_for_ticket($ticket_id) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if($task_id) return $task_id;

		global $wpdb;
		$ticket_id = intval($ticket_id);
		if(empty($ticket_id)) return null;

		$ticket_table = esc_sql($wpdb->prefix . 'dk_ticket');
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$ticket = $wpdb->get_row($wpdb->prepare(
			"SELECT ticket_id, number, staff_id, team_id, dept_id FROM {$ticket_table} WHERE ticket_id = %d",
			$ticket_id
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if(empty($ticket)) return null;

		$ticket_number = $ticket->number;
		$dept_id = $ticket->dept_id;

		$subject = '';
		if(class_exists('Ticket')) {
			$ticket_obj = Ticket::lookup($ticket_id);
			if($ticket_obj && method_exists($ticket_obj, 'getSubject')) {
				$subject = $ticket_obj->getSubject();
			}
		}
		if(empty($subject)) {
			$cdata_table = esc_sql($wpdb->prefix . 'dk_ticket__cdata');
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$subject_row = $wpdb->get_var($wpdb->prepare(
				"SELECT subject FROM {$cdata_table} WHERE ticket_id = %d", $ticket_id
			));
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$subject = $subject_row ?: '';
		}

		$title = '#' . $ticket_number . ' - ' . $subject;
		$description = __('Ticket #', 'kanbanz') . $ticket_number . ': ' . $subject;

		$board_id = null;
		if(!empty($dept_id)) {
			$board_id = $this->ensure_board_for_department($dept_id);
		}

		$postarr = array(
			'post_type'     => 'kanbanz_task',
			'post_title'    => sanitize_text_field($title),
			'post_content' => wp_kses_post($description),
			'post_status'   => 'publish',
			'post_author'   => 0,
		);
		if($board_id) {
			$postarr['post_parent'] = $board_id;
		}

		$new_id = wp_insert_post($postarr);
		if(is_wp_error($new_id)) return null;

		update_post_meta($new_id, 'kanbanz_link_ticket', $ticket_number);
		update_post_meta($new_id, 'deskuss_link_ticket', $ticket_number);

		$initial_status_name = 'To Do';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$initial_status_id = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT status_id FROM {$ticket_table} WHERE ticket_id = %d",
			$ticket_id
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if($initial_status_id) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$status_state_row = $wpdb->get_row($wpdb->prepare(
				"SELECT state, name FROM {$wpdb->prefix}dk_ticket_status WHERE id = %d",
				$initial_status_id
			));
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if($status_state_row && !empty($status_state_row->state)) {
				$mapped = $board_id ? $this->resolve_kanbanz_status_for_state($board_id, $status_state_row->state) : null;
				if($mapped) {
					$initial_status_name = $mapped;
				}
			}
		}
		// Final guard: make sure the resolved name actually exists on the board
		if($board_id) {
			$board_statuses = get_post_meta($board_id, 'board_statuses', true);
			if(is_array($board_statuses) && !in_array($initial_status_name, $board_statuses, true)) {
				$initial_status_name = $board_statuses[0] ?? 'To Do';
			}
		}
		update_post_meta($new_id, 'task_status', $initial_status_name);

		$assigned = $this->get_deskuss_ticket_assignees_by_id($ticket_id);
		if(!empty($assigned)) {
			update_post_meta($new_id, 'kanbanz_assigned_staff', $assigned);
			update_post_meta($new_id, 'deskuss_assigned_staff', $assigned);
			kanbanz_add_assignees_to_board_team($board_id, $assigned);
		}

		if($board_id) {
			update_post_meta($board_id, 'kanbanz_is_department', '1');
		}

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
		/* translators: %s: ticket number */
		$log_title = sprintf(__('Task auto-created from Ticket #%s', 'kanbanz'), $ticket_number);
		kanbanz_update_activity($new_id, $user_name, $log_title, 'kanbanz_activity');
		if($board_id) {
			kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		}

		return $new_id;
	}

	private function find_board_by_ticket($ticket_id) {
		$task_id = $this->find_task_by_ticket($ticket_id);
		if(!$task_id) return null;

		$task = get_post($task_id);
		return $task ? $task->post_parent : null;
	}

	private function get_deskuss_ticket($ticket_id) {
		if(!class_exists('Ticket')) return null;
		return Ticket::lookup($ticket_id);
	}

	private function get_deskuss_ticket_assignees_by_id($ticket_id) {
		global $wpdb;
		$ticket_id = intval($ticket_id);
		if(empty($ticket_id)) return array();

		$ticket_table = esc_sql($wpdb->prefix . 'dk_ticket');
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
		$ticket = $wpdb->get_row($wpdb->prepare(
			"SELECT staff_id, team_id FROM {$ticket_table} WHERE ticket_id = %d",
			$ticket_id
		));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if(empty($ticket)) return array();

		$assigned = array();

		if(!empty($ticket->staff_id)) {
			if(function_exists('deskuss_get_user_info')) {
				$staff_info = deskuss_get_user_info(intval($ticket->staff_id));
				if(!empty($staff_info) && !empty($staff_info->member_username)) {
					$assigned[] = sanitize_text_field($staff_info->member_username);
				}
			}
		}

		if(!empty($ticket->team_id)) {
			$team_member_table = esc_sql($wpdb->prefix . 'dk_team_member');
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
			$team_members = $wpdb->get_results($wpdb->prepare(
				"SELECT staff_id FROM {$team_member_table} WHERE team_id = %d",
				intval($ticket->team_id)
			));
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach($team_members as $tm) {
				if(function_exists('deskuss_get_user_info')) {
					$member_info = deskuss_get_user_info(intval($tm->staff_id));
					if(!empty($member_info) && !empty($member_info->member_username)) {
						$assigned[] = sanitize_text_field($member_info->member_username);
					}
				}
			}
		}

		return array_unique($assigned);
	}

	private function get_status_map($board_id) {
		$statuses = get_post_meta($board_id, 'board_statuses', true);
		if(!is_array($statuses) || empty($statuses)) return array();

		// Allow per-board override via post meta 'kanbanz_deskuss_status_map'
		// Expected shape: array('open'=>'To Do','progress'=>'In Progress','resolved'=>'Completed','closed'=>'Completed')
		$saved = get_post_meta($board_id, 'kanbanz_deskuss_status_map', true);
		if(is_array($saved) && !empty($saved)) {
			// Only keep entries that actually exist in the board's statuses
			$clean = array();
			foreach(array('open', 'progress', 'resolved', 'closed') as $key) {
				if(!empty($saved[$key]) && in_array($saved[$key], $statuses, true)) {
					$clean[$key] = $saved[$key];
				}
			}
			if(!empty($clean)) return $clean;
		}

		// Smart defaults: match by name (case-insensitive), then fall back to position.
		$first  = $statuses[0];
		$last   = end($statuses);
		$second = isset($statuses[1]) ? $statuses[1] : $first;

		// Candidate names for each state
		$candidates = array(
			'open'     => array('To Do', 'Todo', 'Backlog', 'New', 'Open', 'Pending'),
			'progress' => array('In Progress', 'In Progress', 'Working', 'Active', 'Answered', 'In Progress'),
			'resolved' => array('Completed', 'Done', 'Resolved', 'Finished', 'Complete'),
			'closed'   => array('Completed', 'Done', 'Closed', 'Resolved', 'Finished', 'Complete'),
		);

		$map = array();
		foreach($candidates as $state => $names) {
			$matched = null;
			foreach($names as $name) {
				foreach($statuses as $s) {
					if(strcasecmp($s, $name) === 0) { $matched = $s; break 2; }
				}
			}
			$map[$state] = $matched;
		}

		// Position-based fallbacks
		if(empty($map['open']))     $map['open']     = $first;
		if(empty($map['progress'])) $map['progress'] = $second;
		if(empty($map['resolved'])) $map['resolved'] = $last;
		if(empty($map['closed']))   $map['closed']   = $last;

		// Filter out empty values
		$map = array_filter($map, function($v) { return !empty($v); });

		return $map;
	}

	/**
	 * Resolve a Deskuss ticket state ('open'|'progress'|'in_progress'|'resolved'|'closed')
	 * to the matching kanbanz board column name. Returns null if no mapping.
	 */
	private function resolve_kanbanz_status_for_state($board_id, $state) {
		$state = strtolower(trim((string) $state));
		// Normalize 'in_progress' / 'progress' aliases
		if($state === 'in_progress' || $state === 'progress' || $state === 'answered') $state = 'progress';
		if($state === 'open' || $state === 'new') $state = 'open';

		$map = $this->get_status_map($board_id);
		return isset($map[$state]) ? $map[$state] : null;
	}

	/**
	 * Apply a status change to a task (with activity logging).
	 * Skips the write if the task is already in the target status.
	 */
	private function set_task_status_from_ticket($task_id, $board_id, $new_status, $ticket_id, $reason_log = '') {
		if(empty($new_status)) return false;
		$current = get_post_meta($task_id, 'task_status', true);
		if($current === $new_status) return true; // already there

		update_post_meta($task_id, 'task_status', $new_status);

		$current_user = wp_get_current_user();
		$user_name = ($current_user && $current_user->exists()) ? $current_user->user_login : 'System';
		/* translators: %s: ticket ID */
		$log_title = $reason_log ?: sprintf(__('Ticket #%s status synced', 'kanbanz'), $ticket_id);
		kanbanz_update_activity($task_id, $user_name, $log_title, 'kanbanz_activity');
		kanbanz_update_activity($board_id, $user_name, $log_title, 'kanbanz_board_activity');
		return true;
	}
}

Kanbanz_Deskuss_Bridge::init();