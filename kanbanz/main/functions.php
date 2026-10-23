<?php
if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_VERSION')){
	die('Hacking Attempt!');
}

function kanbanz_get_user_role($user_id = 0) {
	if(!$user_id) {
		$user_id = get_current_user_id();
	}
	if(!$user_id) return 'none';

	if(user_can($user_id, 'manage_options')) {
		return 'admin';
	}

	if(user_can($user_id, 'kanbanz_manager')) {
		return 'manager';
	}

	if(user_can($user_id, 'kanbanz_staff')) {
		return 'staff';
	}

	return 'none';
}

function kanbanz_get_accessible_boards($args = []) {
	$role = kanbanz_get_user_role();
	$user_id = get_current_user_id();
	$is_admin = current_user_can('administrator');

	$per_page = apply_filters('kanbanz_boards_per_page', 100);
	$args = wp_parse_args($args, [
		'include_dept_boards' => true,
		'posts_per_page' => $per_page,
	]);

	$query_args = array(
		'post_type'      => 'kanbanz_board',
		'post_status'    => array('publish', 'draft', 'pending'),
		'posts_per_page' => $args['posts_per_page'],
		'fields'         => 'ids',
	);

	$current_user = wp_get_current_user();
	$username = $current_user->user_login;

	if($role === 'admin') {
		if(!$args['include_dept_boards']) {
			$query_args['meta_query'] = array(
				'relation' => 'OR',
				array('key' => 'deskuss_dept_link', 'compare' => 'NOT EXISTS'),
				array('key' => 'deskuss_dept_link', 'value' => '', 'compare' => '='),
			);
		}
	} elseif($role === 'manager') {
		$dept_terms = kanbanz_get_user_departments($user_id);
		$dept_ids = !empty($dept_terms) ? wp_list_pluck($dept_terms, 'term_id') : array();
		$meta_query = array('relation' => 'OR');

		if(!empty($dept_ids)) {
			$meta_query[] = array('key' => 'deskuss_dept_link', 'value' => $dept_ids, 'compare' => 'IN');
		}
		$meta_query[] = array(
			'key'     => 'kanbanz_board_assignment',
			'value'   => $username,
			'compare' => '=',
		);
		$meta_query[] = array(
			'key'     => 'kanbanz_team_association',
			'value'   => serialize($username),
			'compare' => 'LIKE',
		);
		// Department-less boards with no team are open to all staff — mirrors
		// kanbanz_user_can_access_board(), so the listing shows exactly what the
		// user can actually open.
		$meta_query[] = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array('key' => 'deskuss_dept_link', 'compare' => 'NOT EXISTS'),
				array('key' => 'deskuss_dept_link', 'value' => '', 'compare' => '='),
			),
			array(
				'relation' => 'OR',
				array('key' => 'kanbanz_team_association', 'compare' => 'NOT EXISTS'),
				array('key' => 'kanbanz_team_association', 'value' => array('', 'a:0:{}'), 'compare' => 'IN'),
			),
		);
		$query_args['meta_query'] = $meta_query;
	} else {
		$meta_query = array('relation' => 'OR');
		$meta_query[] = array(
			'key'     => 'kanbanz_board_assignment',
			'value'   => $username,
			'compare' => '=',
		);
		$meta_query[] = array(
			'key'     => 'kanbanz_team_association',
			'value'   => serialize($username),
			'compare' => 'LIKE',
		);
		// Department-less boards with no team are open to all staff — mirrors
		// kanbanz_user_can_access_board(), so the listing shows exactly what the
		// user can actually open.
		$meta_query[] = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array('key' => 'deskuss_dept_link', 'compare' => 'NOT EXISTS'),
				array('key' => 'deskuss_dept_link', 'value' => '', 'compare' => '='),
			),
			array(
				'relation' => 'OR',
				array('key' => 'kanbanz_team_association', 'compare' => 'NOT EXISTS'),
				array('key' => 'kanbanz_team_association', 'value' => array('', 'a:0:{}'), 'compare' => 'IN'),
			),
		);
		$query_args['meta_query'] = $meta_query;
	}

	$query = new WP_Query($query_args);
	$board_ids = $query->have_posts() ? $query->posts : array();
	wp_reset_postdata();
	return $board_ids;
}

function kanbanz_user_can_access_board($board_id) {
	$role = kanbanz_get_user_role();
	if($role === 'admin') return true;

	$user_id = get_current_user_id();
	$current_user = wp_get_current_user();
	$username = $current_user->user_login;

	if($role === 'manager') {
		$dept_terms = kanbanz_get_user_departments($user_id);
		$dept_ids = !empty($dept_terms) ? wp_list_pluck($dept_terms, 'term_id') : array();
		$board_dept = kanbanz_get_board_dept_id($board_id);
		if(in_array($board_dept, $dept_ids)) return true;
	}

	$assigned = get_post_meta($board_id, 'kanbanz_board_assignment', true);
	if($assigned === $username) return true;

	$team = get_post_meta($board_id, 'kanbanz_team_association', true);
	if(is_array($team) && in_array($username, $team)) return true;

	// A board with no department is open to every staff user while it has no
	// team; adding team members makes it private to the team.
	if(kanbanz_board_is_open_to_all_staff($board_id)) return true;

	return false;
}

/**
 * A board's team members (user logins), without administrators — they can
 * see every board anyway, so they never make a board private.
 *
 * @param int $board_id Board ID.
 * @return array
 */
function kanbanz_board_team($board_id) {
	$team = get_post_meta($board_id, 'kanbanz_team_association', true);
	if(!is_array($team)) return array();
	return array_values(array_filter($team, function($login) {
		$user = get_user_by('login', $login);
		return $user && !user_can($user, 'manage_options');
	}));
}

/**
 * Save a board's team. Administrators and non-staff users are dropped, and an
 * empty team deletes the meta — an empty team is what makes a department-less
 * board visible to all staff (see kanbanz_board_is_open_to_all_staff()).
 *
 * @param int   $board_id Board ID.
 * @param array $logins   User logins.
 * @return array The saved team.
 */
function kanbanz_save_board_team($board_id, $logins) {
	$team = array();
	foreach((array) $logins as $login) {
		$user = $login ? get_user_by('login', $login) : false;
		if(!$user || user_can($user, 'manage_options') || !user_can($user, 'kanbanz_staff')) continue;
		$team[] = $user->user_login;
	}
	$team = array_values(array_unique($team));
	if(empty($team)) {
		delete_post_meta($board_id, 'kanbanz_team_association');
	} else {
		update_post_meta($board_id, 'kanbanz_team_association', $team);
	}
	return $team;
}

/**
 * Logins of everyone assigned to a task (or subtask) on a board.
 *
 * @param int $board_id Board ID.
 * @return array
 */
function kanbanz_get_board_assignees($board_id) {
	$task_ids = get_posts(array(
		'post_type'      => 'kanbanz_task',
		'post_parent'    => $board_id,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	));
	if (!empty($task_ids)) {
		$task_ids = array_merge($task_ids, get_posts(array(
			'post_type'       => 'kanbanz_task',
			'post_parent__in' => $task_ids,
			'post_status'     => 'any',
			'posts_per_page'  => -1,
			'fields'          => 'ids',
		)));
	}
	$logins = array();
	foreach ($task_ids as $task_id) {
		$assigned = kanbanz_get_task_assigned_staff($task_id);
		if (is_array($assigned)) $logins = array_merge($logins, $assigned);
	}
	return array_values(array_unique(array_filter($logins)));
}

/**
 * Output the optional "Team members" checkbox list used when creating a board.
 * Checked users are submitted as assigned_id[]; administrators are left out
 * because they see every board anyway.
 *
 * @param string $id HTML id for the list container.
 */
function kanbanz_render_team_picker($id) {
	$users = array_filter(kanbanz_get_eligible_assignees(0), function($u) { return $u['role'] !== 'admin'; });
	if (empty($users)) return;
	/* translators: %s: person's name */
	$remove_label = __('Remove %s', 'kanbanz');
	?>
	<div class="kanbanz-field kanbanz-team-picker" id="<?php echo esc_attr($id); ?>" data-remove-label="<?php echo esc_attr($remove_label); ?>">
		<span class="kanbanz-team-picker__heading" id="<?php echo esc_attr($id); ?>-label"><?php esc_html_e('Who can see this board?', 'kanbanz'); ?></span>
		<div class="kanbanz-visibility-options" role="radiogroup" aria-labelledby="<?php echo esc_attr($id); ?>-label">
			<label class="kanbanz-visibility-option">
				<input type="radio" name="<?php echo esc_attr($id); ?>-visibility" value="everyone" checked />
				<span class="dashicons dashicons-groups" aria-hidden="true"></span>
				<span class="kanbanz-visibility-option__text">
					<strong><?php esc_html_e('Everyone', 'kanbanz'); ?></strong>
					<small><?php esc_html_e('All Kanbanz users can see this board.', 'kanbanz'); ?></small>
				</span>
			</label>
			<label class="kanbanz-visibility-option">
				<input type="radio" name="<?php echo esc_attr($id); ?>-visibility" value="team" />
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<span class="kanbanz-visibility-option__text">
					<strong><?php esc_html_e('Only team members', 'kanbanz'); ?></strong>
					<small><?php esc_html_e('People you choose, plus admins and you.', 'kanbanz'); ?></small>
				</span>
			</label>
		</div>

		<div class="kanbanz-team-picker__people" hidden>
			<div class="kanbanz-team-picker__chips" aria-live="polite"></div>
			<div class="kanbanz-team-picker__search">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<input type="search" class="kanbanz-input" placeholder="<?php esc_attr_e('Search people...', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Search people', 'kanbanz'); ?>" autocomplete="off" />
			</div>
			<ul class="kanbanz-team-picker__list" aria-label="<?php esc_attr_e('People', 'kanbanz'); ?>">
				<?php foreach ($users as $u): ?>
				<li class="kanbanz-team-picker__item" data-search="<?php echo esc_attr(strtolower($u['name'] . ' ' . $u['username'] . ' ' . $u['role_label'])); ?>">
					<label>
						<input type="checkbox" class="kanbanz-team-picker__cb" name="assigned_id[]" value="<?php echo esc_attr($u['username']); ?>" data-name="<?php echo esc_attr($u['name']); ?>" data-avatar="<?php echo esc_url($u['avatar_url']); ?>" />
						<img class="kanbanz-team-picker__avatar" src="<?php echo esc_url($u['avatar_url']); ?>" alt="" width="28" height="28" loading="lazy" />
						<span class="kanbanz-team-picker__who">
							<span class="kanbanz-team-picker__name"><?php echo esc_html($u['name']); ?></span>
							<span class="kanbanz-team-picker__login">@<?php echo esc_html($u['username']); ?></span>
						</span>
						<span class="kanbanz-manager-badge__tag"><?php echo esc_html($u['role_label']); ?></span>
						<span class="dashicons dashicons-yes kanbanz-team-picker__check" aria-hidden="true"></span>
					</label>
				</li>
				<?php endforeach; ?>
			</ul>
			<p class="kanbanz-team-picker__empty" hidden><?php esc_html_e('No people match your search.', 'kanbanz'); ?></p>
			<p class="kanbanz-team-picker__error" role="alert" hidden><?php esc_html_e('Pick at least one team member, or choose Everyone.', 'kanbanz'); ?></p>
		</div>
	</div>
	<?php
}

/**
 * Whether a board is visible to every staff user: it has no department and
 * no team members. Adding the first team member makes it private to the team.
 */
function kanbanz_board_is_open_to_all_staff($board_id) {
	return empty(kanbanz_get_board_dept_id($board_id)) && empty(kanbanz_board_team($board_id));
}

/**
 * One-time migration to the "empty team = visible to all staff" rule, keeping
 * who can see each board the same: boards that were flagged open to all staff
 * get their (ineffective) team cleared, and administrators are removed from
 * every team. The old kanbanz_open_to_all_staff flag is then deleted.
 */
add_action('admin_init', 'kanbanz_migrate_board_team_rule', 101);
function kanbanz_migrate_board_team_rule() {
	if(get_option('kanbanz_board_team_rule_v2')) return;

	// Installs that never ran the v1 backfill treated every department-less board as open.
	$v1_ran = (bool) get_option('kanbanz_open_boards_backfilled_v1');

	$boards = get_posts(array(
		'post_type'      => 'kanbanz_board',
		'posts_per_page' => -1,
		'post_status'    => array('publish', 'draft', 'pending'),
		'fields'         => 'ids',
	));
	foreach($boards as $board_id) {
		$flag = get_post_meta($board_id, 'kanbanz_open_to_all_staff', true);
		delete_post_meta($board_id, 'kanbanz_open_to_all_staff');
		if(!empty(get_post_meta($board_id, 'deskuss_dept_link', true))) continue;

		$team = get_post_meta($board_id, 'kanbanz_team_association', true);
		$was_open = ($flag === '1') || ($flag === '' && !$v1_ran);
		if($was_open) {
			if(!empty($team)) update_post_meta($board_id, '_kanbanz_team_before_v2', $team);
			delete_post_meta($board_id, 'kanbanz_team_association');
		} else {
			kanbanz_save_board_team($board_id, is_array($team) ? $team : array());
		}
	}

	update_option('kanbanz_board_team_rule_v2', 1);
}

/**
 * The board's terminal ("Completed") status — the last entry of its
 * board_statuses column list. Same heuristic already used by
 * kanbanz-pro's burndown chart (kanbanz_pro_get_board_burndown()).
 */
function kanbanz_get_board_terminal_status($board_id) {
	$statuses = get_post_meta($board_id, 'board_statuses', true);
	if (!is_array($statuses) || empty($statuses)) return '';
	return end($statuses);
}

/**
 * Whether the current user may VIEW a task.
 *
 * Board access, or being one of the task's assignees. The assignee clause
 * matters because My Tasks lists everything assigned to you regardless of board
 * membership — without it those rows are visible but un-openable, which is
 * exactly the state a staff member lands in when they hold an assignment on a
 * board they were never added to.
 *
 * READ-ONLY grant. Do not reuse this for mutations: the write-side rule lives in
 * Kanbanz_Ajax::kanbanz_user_can_edit_task().
 */
function kanbanz_user_can_view_task($task_id) {
	$board_id = kanbanz_resolve_board_id($task_id);
	if ($board_id && kanbanz_user_can_access_board($board_id)) {
		return true;
	}

	$username = wp_get_current_user()->user_login;
	if (empty($username)) return false;

	$assigned = kanbanz_get_task_assigned_staff($task_id);
	if (!is_array($assigned)) $assigned = !empty($assigned) ? array($assigned) : array();

	return in_array($username, array_map('strval', $assigned), true);
}

/**
 * A task's priority, reading both meta keys in use.
 *
 * Priority is written to 'priority' (see kanbanz_save_priority() in ajax.php)
 * but historic rows and some readers use 'task_priority'. Reading only the
 * latter yields an empty string for every task saved through the UI, which is
 * why the My Tasks priority column and task notifications rendered blank.
 * Centralized here so the fallback can't be forgotten at a new read site.
 */
function kanbanz_get_task_priority($task_id) {
	$priority = get_post_meta($task_id, 'priority', true);
	if (empty($priority)) {
		$priority = get_post_meta($task_id, 'task_priority', true);
	}
	return $priority;
}

/**
 * Get all board managers (Admins, Department Managers, and explicitly assigned Board Managers).
 */
function kanbanz_get_board_managers($board_id) {
	$managers = array();
	$seen_uids = array();

	// 1. Administrators
	$admins = get_users(array('role' => 'administrator'));
	foreach ($admins as $admin) {
		if (!isset($seen_uids[$admin->ID])) {
			$seen_uids[$admin->ID] = true;
			$managers[] = array(
				'id' => $admin->ID,
				'name' => $admin->display_name ?: $admin->user_login,
				'username' => $admin->user_login,
				'email' => $admin->user_email,
				'role' => 'Administrator',
				'badge' => 'Admin',
				'source' => 'admin'
			);
		}
	}

	// 2. Department Managers (if board is linked to a department)
	$dept_id = kanbanz_get_board_dept_id($board_id);
	if (!empty($dept_id)) {
		global $wpdb;
		$dept_table = esc_sql($wpdb->prefix . 'dk_department');
		$member_table = esc_sql($wpdb->prefix . 'dk_members');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
		if ($wpdb->get_var("SHOW TABLES LIKE '{$dept_table}'") === $dept_table) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
			$manager_id = $wpdb->get_var($wpdb->prepare("SELECT manager_id FROM {$dept_table} WHERE id = %d", intval($dept_id)));
			if (!empty($manager_id)) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
				$user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$member_table} WHERE member_id = %d", intval($manager_id)));
				if ($user_id && !isset($seen_uids[$user_id])) {
					$u = get_user_by('id', $user_id);
					if ($u) {
						$seen_uids[$user_id] = true;
						$managers[] = array(
							'id' => $u->ID,
							'name' => $u->display_name ?: $u->user_login,
							'username' => $u->user_login,
							'email' => $u->user_email,
							'role' => 'Dept Manager',
							'badge' => 'Dept Manager',
							'source' => 'dept'
						);
					}
				}
			}
		}
	}

	// 3. Explicit Board Managers or Board Assignment
	$board_assigned = get_post_meta($board_id, 'kanbanz_board_assignment', true);
	if (!empty($board_assigned)) {
		$u = get_user_by('login', $board_assigned) ?: get_user_by('id', $board_assigned);
		if ($u && !isset($seen_uids[$u->ID])) {
			$seen_uids[$u->ID] = true;
			$managers[] = array(
				'id' => $u->ID,
				'name' => $u->display_name ?: $u->user_login,
				'username' => $u->user_login,
				'email' => $u->user_email,
				'role' => 'Board Lead',
				'badge' => 'Board Lead',
				'source' => 'board'
			);
		}
	}

	$board_mgrs = get_post_meta($board_id, 'kanbanz_board_managers', true);
	if (is_array($board_mgrs)) {
		foreach ($board_mgrs as $mgr_identifier) {
			$u = is_numeric($mgr_identifier) ? get_user_by('id', $mgr_identifier) : get_user_by('login', $mgr_identifier);
			if ($u && !isset($seen_uids[$u->ID])) {
				$seen_uids[$u->ID] = true;
				$managers[] = array(
					'id' => $u->ID,
					'name' => $u->display_name ?: $u->user_login,
					'username' => $u->user_login,
					'email' => $u->user_email,
					'role' => 'Board Manager',
					'badge' => 'Manager',
					'source' => 'board_manager'
				);
			}
		}
	}

	return $managers;
}

/**
 * One-time migration: the global "Restrict who can mark tasks Completed"
 * setting was removed. Boards that relied on it (no board-level completion
 * mode) get the "managers" mode it implied, so nobody gains the right to
 * complete tasks; then the global key is dropped.
 */
add_action('admin_init', 'kanbanz_migrate_completion_setting', 101);
function kanbanz_migrate_completion_setting() {
	if (get_option('kanbanz_completion_setting_migrated_v1')) return;

	$settings = get_option('kanbanz_settings', array());
	if (is_array($settings) && isset($settings['restrict_task_completion'])) {
		if ($settings['restrict_task_completion'] === '1') {
			$boards = get_posts(array(
				'post_type'      => 'kanbanz_board',
				'post_status'    => array('publish', 'draft', 'pending'),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			));
			foreach ($boards as $board_id) {
				if (get_post_meta($board_id, 'kanbanz_completion_mode', true) === '') {
					update_post_meta($board_id, 'kanbanz_completion_mode', 'managers');
				}
			}
		}
		unset($settings['restrict_task_completion']);
		update_option('kanbanz_settings', $settings);
	}

	update_option('kanbanz_completion_setting_migrated_v1', 1);
}

/**
 * Whether the current user is allowed to move a task into $board_id's
 * terminal status, based on the board's Task completion setting.
 */
function kanbanz_user_can_complete_tasks($board_id) {
	if (empty($board_id)) return true;

	$role = kanbanz_get_user_role();
	if ($role === 'admin') return true;

	// Set per board in Board Settings → Task completion; boards without a setting allow everyone.
	$completion_mode = get_post_meta($board_id, 'kanbanz_completion_mode', true);

	if (empty($completion_mode) || $completion_mode === 'everyone') {
		return true;
	}

	// Check if user is a Manager with board access
	if ($role === 'manager' && kanbanz_user_can_access_board($board_id)) return true;

	$user = wp_get_current_user();
	if (!$user || empty($user->ID)) return false;
	$username = $user->user_login;
	$user_id = $user->ID;

	// Check if user is explicitly in board_managers or board_assignment
	$board_assigned = get_post_meta($board_id, 'kanbanz_board_assignment', true);
	if ($board_assigned === $username || $board_assigned == $user_id) return true;

	$board_mgrs = get_post_meta($board_id, 'kanbanz_board_managers', true);
	if (is_array($board_mgrs) && (in_array($username, $board_mgrs, true) || in_array($user_id, $board_mgrs) || in_array((string)$user_id, $board_mgrs, true))) {
		return true;
	}

	if ($completion_mode === 'managers') {
		return false;
	}

	if ($completion_mode === 'custom') {
		$allowed = get_post_meta($board_id, 'kanbanz_completion_allowed_users', true);
		if (is_array($allowed)) {
			if (in_array($username, $allowed, true) || in_array($user_id, $allowed) || in_array((string)$user_id, $allowed, true)) {
				return true;
			}
		}
		return false;
	}

	return true;
}

// kanbanz_filter_tasks_for_user() was removed here. It had no callers anywhere
// in either plugin, so it enforced nothing, and it was broken in two ways that
// would have made it unsafe to simply wire up: `|| empty($assigned)` let every
// unassigned task through, and `return $filtered ?: $tasks` returned the FULL
// task list whenever the filter matched nothing. Per-task visibility inside a
// board is a feature that would need enforcing consistently across
// kanbanz_get_board_data(), kanbanz_ajax_get_board_data(), kanbanz_board_search()
// and kanbanz_open_task() — a display-only helper cannot deliver it.

/**
 * Centralized Assignee Engine: Get all eligible staff users who can be assigned to tasks.
 * Includes Administrators, Kanban Managers, Kanban Staff, Deskuss Managers, Deskuss Staff, and Editors.
 *
 * @param int $board_id Optional board ID for board-specific context.
 * @return array List of user items with id, username, name, display_name, avatar_url, role, role_label, initials.
 */
function kanbanz_get_eligible_assignees($board_id = 0) {
	global $wpdb;
	$users_by_id = array();

	// 1. Fetch by roles
	$roles_to_query = array('administrator', 'editor', 'kanbanz_manager', 'kanbanz_staff', 'deskuss_manager', 'deskuss_staff');
	$wp_users = get_users(array(
		'role__in' => $roles_to_query,
	));

	foreach ($wp_users as $u) {
		$users_by_id[$u->ID] = $u;
	}

	// 2. Fetch users with kanbanz_staff capability explicitly
	$cap_users = get_users(array('capability' => 'kanbanz_staff'));
	foreach ($cap_users as $u) {
		$users_by_id[$u->ID] = $u;
	}

	// 3. Deskuss department members (if Deskuss is active)
	if (function_exists('kanbanz_deskuss_active') && kanbanz_deskuss_active()) {
		$members_table = esc_sql($wpdb->prefix . 'dk_members');
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
		if ($wpdb->get_var("SHOW TABLES LIKE '{$members_table}'") === $members_table) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names (escaped) and prepared/int-cast values.
			$member_rows = $wpdb->get_results("SELECT DISTINCT user_id FROM {$members_table} WHERE user_id > 0");
			foreach ($member_rows as $mr) {
				$uid = intval($mr->user_id);
				if ($uid && !isset($users_by_id[$uid])) {
					$u = get_user_by('id', $uid);
					if ($u) $users_by_id[$uid] = $u;
				}
			}
		}
	}

	// 4. Format users with role badges
	$result = array();
	foreach ($users_by_id as $uid => $u) {
		$staff_info = function_exists('deskuss_get_user_info') ? deskuss_get_user_info($uid) : null;
		$display_name = $u->display_name ?: $u->user_login;
		if ($staff_info && !empty($staff_info->member_firstname)) {
			$display_name = trim($staff_info->member_firstname . ' ' . ($staff_info->member_lastname ?: ''));
		}

		$role_label = 'Kanban Staff';
		$role_key = 'kanbanz_staff';

		if (user_can($u, 'administrator') || user_can($u, 'manage_options') || in_array('administrator', (array)$u->roles, true)) {
			$role_label = 'Admin';
			$role_key = 'admin';
		} elseif (user_can($u, 'kanbanz_manager') || in_array('kanbanz_manager', (array)$u->roles, true)) {
			$role_label = 'Manager';
			$role_key = 'manager';
		} elseif (user_can($u, 'deskuss_manager') || in_array('deskuss_manager', (array)$u->roles, true)) {
			$role_label = 'Deskuss Manager';
			$role_key = 'manager';
		} elseif (user_can($u, 'deskuss_staff') || in_array('deskuss_staff', (array)$u->roles, true)) {
			$role_label = 'Deskuss Staff';
			$role_key = 'deskuss_staff';
		}

		$initials = strtoupper(substr($display_name, 0, 1) . substr($u->user_login, 0, 1));
		if (strlen($initials) < 2) {
			$initials = strtoupper(substr($u->user_login, 0, 2));
		}

		$result[] = array(
			'id'           => $u->ID,
			'username'     => $u->user_login,
			'name'         => $display_name,
			'display_name' => $display_name,
			'avatar_url'   => get_avatar_url($u->ID, array('size' => 32)),
			'role'         => $role_key,
			'role_label'   => $role_label,
			'initials'     => $initials,
		);
	}

	// Sort alphabetically by display_name
	usort($result, function($a, $b) {
		return strcasecmp($a['display_name'], $b['display_name']);
	});

	return $result;
}

/**
 * Get staff users for the board Assignee filter and Board Settings custom completers list.
 *
 * @param int $board_id Optional board ID.
 * @return array
 */
function kanbanz_get_users_for_filter($board_id = 0) {
	return kanbanz_get_eligible_assignees($board_id);
}

function kanbanz_load_boards($include_dept_boards = true) {
	$role = kanbanz_get_user_role();
	$user_id = get_current_user_id();
	$current_user = wp_get_current_user();
	$username = $current_user->user_login;

	$args = array(
		'post_type'      => 'kanbanz_board',
		'post_status'    => array('publish', 'draft', 'pending'),
		'posts_per_page' => apply_filters('kanbanz_boards_per_page', 100),
	);

	if($role === 'admin') {
		if(!$include_dept_boards) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array('key' => 'deskuss_dept_link', 'compare' => 'NOT EXISTS'),
				array('key' => 'deskuss_dept_link', 'value' => '', 'compare' => '='),
			);
		}
	} elseif($role === 'manager') {
		$dept_terms = kanbanz_get_user_departments($user_id);
		$dept_ids = !empty($dept_terms) ? wp_list_pluck($dept_terms, 'term_id') : array();
		$meta_query = array('relation' => 'OR');
		if(!empty($dept_ids)) {
			$meta_query[] = array('key' => 'deskuss_dept_link', 'value' => $dept_ids, 'compare' => 'IN');
		}
		$meta_query[] = array('key' => 'kanbanz_board_assignment', 'value' => $username, 'compare' => '=');
		$args['meta_query'] = $meta_query;
	} else {
		$meta_query = array('relation' => 'OR');
		$meta_query[] = array('key' => 'kanbanz_board_assignment', 'value' => $username, 'compare' => '=');
		$meta_query[] = array('key' => 'deskuss_dept_link', 'compare' => 'NOT EXISTS');
		$meta_query[] = array('key' => 'deskuss_dept_link', 'value' => '', 'compare' => '=');
		$args['meta_query'] = $meta_query;
	}

	$query = new WP_Query($args);
	$boards = array();
	if($query->have_posts()) {
		while($query->have_posts()) {
			$query->the_post();
			$boards[] = array(
				'board_id' => get_the_ID(),
				'title'    => get_the_title(),
				'content'  => apply_filters('the_content', get_the_content()),
				'url'      => 'kanban.php?board_id=' . get_the_ID(),
			);
		}
	}
	wp_reset_postdata();
	return $boards;
}

function kanbanz_get_status_tasks($board_id, $status, $page = 1, $per_page = 50, $filters = array()) {
	global $wpdb;
	$statuses = get_post_meta($board_id, 'board_statuses', true);
	if (!is_array($statuses)) $statuses = array();

	$meta_query = array();
	$is_first_status = (!empty($statuses) && $statuses[0] === $status);

	if ($is_first_status) {
		$meta_query[] = array(
			'relation' => 'OR',
			array('key' => 'task_status', 'value' => $status, 'compare' => '='),
			array('key' => 'task_status', 'compare' => 'NOT EXISTS'),
		);
	} else {
		$meta_query[] = array('key' => 'task_status', 'value' => $status, 'compare' => '=');
	}

	// Priority filter (supports single string or array / comma-separated list of priorities)
	$priorities = array();
	if (!empty($filters['priorities']) && is_array($filters['priorities'])) {
		$priorities = array_filter(array_map('sanitize_text_field', $filters['priorities']));
	} elseif (!empty($filters['priority'])) {
		if (is_array($filters['priority'])) {
			$priorities = array_filter(array_map('sanitize_text_field', $filters['priority']));
		} else {
			$parts = explode(',', $filters['priority']);
			$priorities = array_filter(array_map('trim', array_map('sanitize_text_field', $parts)));
		}
	}

	if (!empty($priorities)) {
		if (count($priorities) === 1) {
			$p_val = reset($priorities);
			$meta_query[] = array(
				'relation' => 'OR',
				array('key' => 'priority', 'value' => $p_val, 'compare' => '='),
				array('key' => 'task_priority', 'value' => $p_val, 'compare' => '='),
			);
		} else {
			$meta_query[] = array(
				'relation' => 'OR',
				array('key' => 'priority', 'value' => $priorities, 'compare' => 'IN'),
				array('key' => 'task_priority', 'value' => $priorities, 'compare' => 'IN'),
			);
		}
	}

	if (!empty($filters['overdue'])) {
		$meta_query[] = array(
			'key' => 'due_date',
			'value' => gmdate('Y-m-d'),
			'compare' => '<',
			'type' => 'DATE'
		);
	}

	if (!empty($filters['assignee'])) {
		$assignee_id = absint($filters['assignee']);
		$assignee_user = get_user_by('id', $assignee_id);
		$assignee_username = $assignee_user ? $assignee_user->user_login : '';

		$assignee_subquery = array('relation' => 'OR');
		$assignee_subquery[] = array(
			'key' => 'task_assigned',
			'value' => '"' . $assignee_id . '"',
			'compare' => 'LIKE'
		);
		$assignee_subquery[] = array(
			'key' => 'kanbanz_assigned_staff',
			'value' => '"' . $assignee_id . '"',
			'compare' => 'LIKE'
		);
		if (!empty($assignee_username)) {
			$assignee_subquery[] = array(
				'key' => 'task_assigned',
				'value' => '"' . $assignee_username . '"',
				'compare' => 'LIKE'
			);
			$assignee_subquery[] = array(
				'key' => 'kanbanz_assigned_staff',
				'value' => '"' . $assignee_username . '"',
				'compare' => 'LIKE'
			);
		}
		$meta_query[] = $assignee_subquery;
	}

	$date_type = !empty($filters['date_type']) ? $filters['date_type'] : 'due_date';
	$date_query = array();

	if ($date_type === 'created_date') {
		if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
			$dq = array('inclusive' => true);
			if (!empty($filters['date_from'])) $dq['after'] = $filters['date_from'] . ' 00:00:00';
			if (!empty($filters['date_to'])) $dq['before'] = $filters['date_to'] . ' 23:59:59';
			$date_query[] = $dq;
		}
	} else {
		if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
			$meta_query[] = array(
				'key' => 'due_date',
				'value' => array($filters['date_from'], $filters['date_to']),
				'compare' => 'BETWEEN',
				'type' => 'DATE'
			);
		} elseif (!empty($filters['date_from'])) {
			$meta_query[] = array(
				'key' => 'due_date',
				'value' => $filters['date_from'],
				'compare' => '>=',
				'type' => 'DATE'
			);
		} elseif (!empty($filters['date_to'])) {
			$meta_query[] = array(
				'key' => 'due_date',
				'value' => $filters['date_to'],
				'compare' => '<=',
				'type' => 'DATE'
			);
		}
	}

	$args = array(
		'post_type' => 'kanbanz_task',
		'post_parent' => $board_id,
		'posts_per_page' => $per_page,
		'paged' => $page,
		'post_status' => 'publish',
		'orderby' => array('menu_order' => 'ASC', 'date' => 'DESC'),
	);

	if (!empty($filters['search'])) {
		$args['s'] = sanitize_text_field($filters['search']);
	}

	if (!empty($meta_query)) {
		if (count($meta_query) > 1) {
			$meta_query['relation'] = 'AND';
		}
		$args['meta_query'] = $meta_query;
	}

	if (!empty($date_query)) {
		$args['date_query'] = $date_query;
	}

	$query = new WP_Query($args);
	return array(
		'posts' => $query->posts,
		'found_posts' => $query->found_posts,
		'max_num_pages' => $query->max_num_pages,
	);
}

function kanbanz_format_task_card_data($task, $subtasks_by_parent = array(), $comments_by_post = array(), $statuses = array()) {
	$assigned_staff = kanbanz_get_task_assigned_staff($task->ID);
	$status = get_post_meta($task->ID, 'task_status', true);
	$due_date = get_post_meta($task->ID, 'due_date', true);
	$priority = get_post_meta($task->ID, 'priority', true);
	if (empty($priority)) {
		$priority = get_post_meta($task->ID, 'task_priority', true);
	}
	if (empty($status) && !empty($statuses)) {
		$status = $statuses[0];
	}

	$subtask_count_total = $subtasks_by_parent[$task->ID]['total'] ?? 0;
	$subtask_count_done = $subtasks_by_parent[$task->ID]['done'] ?? 0;

	$subtasks_raw = get_post_meta($task->ID, 'kanbanz_subtasks_meta', true);
	if (is_array($subtasks_raw)) {
		$subtask_count_total += count($subtasks_raw);
		foreach ($subtasks_raw as $st) {
			if (!empty($st['done']) || !empty($st['checked'])) $subtask_count_done++;
		}
	}

	$comment_count = $comments_by_post[$task->ID] ?? 0;

	$attachment_count = 0;
	$att_ids = get_post_meta($task->ID, 'kanbanz_attachments', true);
	if (is_array($att_ids)) $attachment_count = count($att_ids);

	$created_date = !empty($task->post_date) ? substr($task->post_date, 0, 10) : '';

	return array(
		'id' => $task->ID,
		'title' => $task->post_title,
		'name' => $task->post_name,
		'content' => $task->post_content,
		'status' => $status,
		'date' => $task->post_date,
		'created_date' => $created_date,
		'task_meta' => array(
			'due_date' => $due_date,
			'created_date' => $created_date,
			'priority' => $priority,
			'assigned' => $assigned_staff,
			'is_overdue' => (!empty($due_date) && strtotime($due_date) < time() && $status !== 'Completed'),
			'subtask_count' => $subtask_count_total,
			'subtask_done' => $subtask_count_done,
			'comment_count' => $comment_count,
			'attachment_count' => $attachment_count,
			'ticket_number' => kanbanz_get_task_ticket($task->ID),
			'labels' => kanbanz_get_task_labels($task->ID),
		)
	);
}

/**
 * Get a task's labels as a list of array('text' => ..., 'background' => ...).
 *
 * Labels are stored per task in the kanbanz_labels post meta. Older installs kept
 * them per user (kanbanz_task_labels user meta, 'txt' key), which is used as a fallback.
 *
 * @param int   $task_id Task ID.
 * @param array $legacy  Legacy labels for this task from the user meta, if any.
 * @return array
 */
function kanbanz_get_task_labels($task_id, $legacy = array()) {
	$labels = get_post_meta($task_id, 'kanbanz_labels', true);
	if (!is_array($labels) || empty($labels)) {
		$labels = is_array($legacy) ? $legacy : array();
	}
	$out = array();
	foreach ($labels as $lbl) {
		if (!is_array($lbl)) continue;
		$text = isset($lbl['text']) ? $lbl['text'] : (isset($lbl['txt']) ? $lbl['txt'] : '');
		if ($text === '') continue;
		$out[] = array(
			'text'       => $text,
			'background' => !empty($lbl['background']) ? $lbl['background'] : '#3a86ff',
		);
	}
	return $out;
}

function kanbanz_render_task_card_html($task, $labels = array()) {
	$priority = !empty($task['task_meta']['priority']) ? $task['task_meta']['priority'] : '';
	$due_date = !empty($task['task_meta']['due_date']) ? $task['task_meta']['due_date'] : '';
	$is_overdue = !empty($task['task_meta']['is_overdue']);
	$assigned = !empty($task['task_meta']['assigned']) ? (array)$task['task_meta']['assigned'] : array();
	$task_labels = kanbanz_get_task_labels($task['id'], !empty($labels[$task['id']]) ? (array)$labels[$task['id']] : array());
	$priority_class = '';
	if ($priority === 'urgent') $priority_class = 'kanbanz-card--urgent';
	elseif ($priority === 'high') $priority_class = 'kanbanz-card--high';
	elseif ($priority === 'medium') $priority_class = 'kanbanz-card--medium';
	elseif ($priority === 'low') $priority_class = 'kanbanz-card--low';
	$overdue_class = $is_overdue ? 'kanbanz-card--overdue' : '';

	ob_start();
	?>
	<div class="kanbanz-card <?php echo esc_attr(trim($priority_class . ' ' . $overdue_class)); ?>" data-task-id="<?php echo esc_attr($task['id']); ?>">
		<input type="checkbox" class="kanbanz-card__checkbox" data-task-id="<?php echo esc_attr($task['id']); ?>" aria-label="<?php esc_attr_e('Select task', 'kanbanz'); ?>" />
		<div class="kanbanz-card__labels">
			<?php if (!empty($task_labels)): foreach (array_slice($task_labels, 0, 3) as $lbl): ?>
			<span class="kanbanz-label" style="background:<?php echo esc_attr($lbl['background']); ?>;color:#fff;"><?php echo esc_html($lbl['text']); ?></span>
			<?php endforeach; endif; ?>
		</div>
		<div class="kanbanz-card__title"><?php echo esc_html($task['title']); ?></div>
		<?php if (function_exists('kanbanz_deskuss_render_card_ticket_link')) {
			kanbanz_deskuss_render_card_ticket_link(!empty($task['task_meta']['ticket_number']) ? $task['task_meta']['ticket_number'] : '');
		} ?>
		<div class="kanbanz-card__meta">
			<?php if ($priority && $priority !== 'Normal'): ?>
			<span class="kanbanz-card__priority kanbanz-card__priority--<?php echo esc_attr(strtolower($priority)); ?>"><?php echo esc_html(ucfirst($priority)); ?></span>
			<?php endif; ?>
			<?php if ($due_date): ?>
			<span class="kanbanz-card__due <?php echo $is_overdue ? 'kanbanz-card__due--overdue' : ''; ?>">
				<span class="dashicons dashicons-calendar-alt"></span>
				<?php echo esc_html(date_i18n('M j', strtotime($due_date))); ?>
			</span>
			<?php endif; ?>
		</div>
		<div class="kanbanz-card__footer">
			<div class="kanbanz-card__avatars">
				<?php if (!empty($assigned)): foreach (array_slice($assigned, 0, 3) as $username):
					$assignee = kanbanz_get_user_info($username);
					if ($assignee):
				?>
				<span class="kanbanz-avatar" title="<?php echo esc_attr($assignee->member_firstname . ' ' . $assignee->member_lastname); ?>">
					<?php echo esc_html(substr($assignee->member_firstname, 0, 1) . substr($assignee->member_lastname, 0, 1)); ?>
				</span>
				<?php endif; endforeach; ?>
				<?php if (count($assigned) > 3): ?>
				<span class="kanbanz-avatar kanbanz-avatar--more">+<?php echo esc_html(count($assigned) - 3); ?></span>
				<?php endif; endif; ?>
			</div>
			<?php
			$st_total = !empty($task['task_meta']['subtask_count']) ? intval($task['task_meta']['subtask_count']) : 0;
			$st_done = !empty($task['task_meta']['subtask_done']) ? intval($task['task_meta']['subtask_done']) : 0;
			$cmt_count = !empty($task['task_meta']['comment_count']) ? intval($task['task_meta']['comment_count']) : 0;
			$att_count = !empty($task['task_meta']['attachment_count']) ? intval($task['task_meta']['attachment_count']) : 0;
			$indicators = [];
			if ($st_total > 0) $indicators[] = '<span class="kanbanz-card__indicator"><span class="dashicons dashicons-list-view"></span> ' . $st_done . '/' . $st_total . '</span>';
			if ($cmt_count > 0) $indicators[] = '<span class="kanbanz-card__indicator"><span class="dashicons dashicons-format-chat"></span> ' . $cmt_count . '</span>';
			if ($att_count > 0) $indicators[] = '<span class="kanbanz-card__indicator"><span class="dashicons dashicons-paperclip"></span> ' . $att_count . '</span>';
			if (!empty($indicators)):
			?>
			<div class="kanbanz-card__indicators"><?php echo wp_kses_post(implode('', $indicators)); ?></div>
			<?php endif; ?>
		</div>
		<?php if ($st_total > 0):
			$pct = round($st_done / $st_total * 100);
		?>
		<div class="kanbanz-card__subtask-bar"><div class="kanbanz-card__subtask-bar-fill" style="width:<?php echo esc_attr($pct); ?>%;"></div></div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

function kanbanz_get_board_data($board_id, $per_page = 50) {
	global $wpdb;
	$board_post = get_post($board_id);
	if(!$board_post || $board_post->post_type !== 'kanbanz_board') {
		return false;
	}

	$author_id = $board_post->post_author;
	$created_by = get_the_author_meta('display_name', $author_id);
	$created_date = get_the_date('F j, Y', $board_id);
	$user_id = get_current_user_id();
	$user_name = wp_get_current_user()->user_login;
	$is_admin = current_user_can('administrator');
	$role = kanbanz_get_user_role();

	// Only admins bypass the check unconditionally — managers are scoped to
	// their own/assigned boards via kanbanz_user_can_access_board() (which
	// already has manager/department logic built in, see functions.php:98).
	// This previously also bypassed for 'manager', granting every manager
	// access to every board site-wide regardless of department assignment.
	if($role !== 'admin' && !kanbanz_user_can_access_board($board_id)) {
		return false;
	}

	$statuses = get_post_meta($board_id, 'board_statuses', true);
	if(!$statuses) return false;

	$per_page = apply_filters('kanbanz_tasks_per_page', $per_page);

	$response_data = array(
		'board' => array(
			'id' => $board_post->ID,
			'title' => $board_post->post_title,
			'content' => $board_post->post_content,
			'post_author' => $created_by,
			'post_date' => $created_date,
		),
		'tasks' => array(),
		'task_counts' => array(),
		'task_pagination' => array(),
		'user_meta' => array(),
		'user_role' => $role,
	);

	$all_fetched_tasks = array();
	$status_tasks_map = array();

	foreach($statuses as $status) {
		$response_data['tasks'][$status] = array();
        
		$res = kanbanz_get_status_tasks($board_id, $status, 1, $per_page);
		$status_posts = $res['posts'];
		$total_found = $res['found_posts'];

		$response_data['task_counts'][$status] = $total_found;
		$response_data['task_pagination'][$status] = array(
			'page' => 1,
			'per_page' => $per_page,
			'total' => $total_found,
			'has_more' => ($per_page > 0 && (1 * $per_page) < $total_found),
		);

		$status_tasks_map[$status] = $status_posts;
		foreach($status_posts as $sp) {
			$all_fetched_tasks[] = $sp;
		}
	}

	if(!empty($all_fetched_tasks)) {
		$task_ids = wp_list_pluck($all_fetched_tasks, 'ID');
		update_meta_cache('post', $task_ids);

		// Batch pre-fetch subtasks count
		$subtasks_by_parent = array();
		if (!empty($task_ids)) {
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
		}

		// Batch pre-fetch comments count
		$comments_by_post = array();
		if (!empty($task_ids)) {
			$task_ids_in = implode(',', array_map('intval', $task_ids));
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
		}

		foreach($statuses as $status) {
			if(!empty($status_tasks_map[$status])) {
				foreach($status_tasks_map[$status] as $task) {
					$formatted = kanbanz_format_task_card_data($task, $subtasks_by_parent, $comments_by_post, $statuses);
					$response_data['tasks'][$status][] = $formatted;
				}
			}
		}
	}

	$board_layout = get_user_meta($user_id, 'kanbanz_board_layout', true);
	$task_labels = get_user_meta($user_id, 'kanbanz_task_labels', true);
	$board_status = get_post_meta($board_id, 'board_statuses', true);
	$dept_id = kanbanz_get_board_dept_id($board_id);
	$is_department = get_post_meta($board_id, 'kanbanz_is_department', true);

	$response_data['user_meta'] = array(
		'board_layout' => isset($board_layout[$board_id]) ? $board_layout[$board_id] : 'board',
		'task_labels' => $task_labels,
	);

	if(!empty($dept_id)){
		$response_data['user_meta']['dept_id'] = $dept_id;
	}
	$response_data['board']['is_department'] = !empty($is_department) ? true : false;

	foreach($statuses as $status) {
		$response_data['user_meta']['status_background'][$status] = isset($status_background[$board_id][$status]) ? $status_background[$board_id][$status] : '';
	}

	return $response_data;
}

function kanbanz_get_task_details($task_id) {
	$post = get_post($task_id);
	if(!$post || $post->post_type !== 'kanbanz_task') return false;

	$priority_level = kanbanz_get_task_priority($task_id);
	$due_date = get_post_meta($task_id, 'due_date', true);
	$attachments = get_post_meta($task_id, 'kanbanz_image_attachments', true);
	$assigned_staff = kanbanz_get_task_assigned_staff($task_id);
	$comments = get_comments(array(
		'post_id' => $task_id,
		'type'    => 'kanbanz_comment',
		'orderby' => 'comment_date',
		'order'   => 'DESC',
	));

	$formatted_comments = array();
	foreach($comments as $comment) {
		$formatted_comments[] = array(
			'id'           => $comment->comment_ID,
			'task_id'      => $task_id,
			'type'         => 'comment',
			'author_name'  => $comment->comment_author,
			'author_email' => $comment->comment_author_email,
			'description'  => $comment->comment_content,
			'created_by'   => $comment->user_id,
			'created_at'   => $comment->comment_date,
		);
	}

	$task_details = array(
		'content' => $post->post_content,
		'priority_level' => $priority_level,
		'due_date' => $due_date,
		'attachments' => $attachments,
		'comments' => $formatted_comments,
		'assigned' => $assigned_staff,
		'ticket_number' => kanbanz_get_task_ticket($task_id),
	);

	return $task_details;
}

function kanbanz_resolve_board_id($task_id) {
	$parent_id = wp_get_post_parent_id($task_id);
	if(!$parent_id) return 0;
	$parent_post = get_post($parent_id);
	if($parent_post && $parent_post->post_type === 'kanbanz_task') {
		return wp_get_post_parent_id($parent_id);
	}
	return $parent_id;
}

function kanbanz_get_user_info($user) {
	if(is_numeric($user)) {
		$wp_user = get_user_by('ID', $user);
	} else {
		$wp_user = get_user_by('login', $user);
	}

	if(!$wp_user) return null;

	return (object) array(
		'member_id'        => $wp_user->ID,
		'member_username'  => $wp_user->user_login,
		'member_firstname' => $wp_user->first_name ?: $wp_user->display_name,
		'member_lastname'  => $wp_user->last_name ?: '',
		'dept_id'          => 0,
	);
}

function kanbanz_get_all_agents() {
	$eligible = kanbanz_get_eligible_assignees();
	$agents = array();
	foreach ($eligible as $u) {
		$agents[] = (object) array(
			'member_id'        => $u['id'],
			'member_username'  => $u['username'],
			'member_firstname' => $u['display_name'],
			'member_lastname'  => '',
		);
	}
	return $agents;
}

function kanbanz_get_team_members($board_id) {
	$team = get_post_meta($board_id, 'kanbanz_team_association', true);
	if(!is_array($team)) $team = array();

	$members = array();
	foreach($team as $username) {
		$user = get_user_by('login', $username);
		if($user) {
			$members[] = (object) array(
				'member_id'        => $user->ID,
				'member_username'  => $user->user_login,
				'member_firstname' => $user->first_name ?: $user->display_name,
				'member_lastname'  => $user->last_name ?: '',
			);
		}
	}
	return $members;
}

function kanbanz_create_board($data) {
	$title       = isset($data['name']) ? sanitize_text_field(wp_unslash($data['name'])) : '';
	$description = isset($data['description']) ? sanitize_text_field(wp_unslash($data['description'])) : '';
	$parent      = isset($data['parent']) ? absint($data['parent']) : 0;
	$user_id     = get_current_user_id();
	$user        = get_user_by('ID', $user_id);

	if(empty($title)) {
		return array('success' => false, 'message' => 'Title cannot be empty');
	}

	if(!$user) {
		return array('success' => false, 'message' => 'User not found.');
	}

	$assign_board = !empty($data['assign_manager_name']) ? sanitize_text_field($data['assign_manager_name']) : $user->user_login;
	$assigned_staff = (isset($data['assign_members']) && is_array($data['assign_members'])) ? $data['assign_members'] : array();

	$post_data = array(
		'post_title'   => $title,
		'post_type'    => 'kanbanz_board',
		'post_content' => $description,
		'post_status'  => 'publish',
		'post_author'  => $user_id,
		'post_parent'  => $parent,
	);

	$post_id = wp_insert_post($post_data);

	if(is_wp_error($post_id)) {
		return array('success' => false, 'message' => 'Failed to create board.');
	}

	$default_statuses = array('To Do', 'In Progress', 'Under review', 'Completed');
	update_post_meta($post_id, 'board_statuses', $default_statuses);

	if(!empty($data['dept_id'])) {
		update_post_meta($post_id, 'deskuss_dept_link', absint($data['dept_id']));
		update_post_meta($post_id, 'kanbanz_is_department', '1');
	}

	$custom_label = get_user_meta($user_id, 'kanbanz_custom_labels', true);
	if(!is_array($custom_label)) $custom_label = array();

	$default_colors = array(
		uniqid() => array('color' => 'rgb(75, 206, 151)'),
		uniqid() => array('color' => 'rgb(245, 205, 71)'),
		uniqid() => array('color' => 'rgb(254, 163, 98)'),
		uniqid() => array('color' => 'rgb(248, 113, 104)'),
		uniqid() => array('color' => 'rgb(159, 143, 239)'),
	);

	$custom_label[$post_id] = $default_colors;
	update_user_meta($user_id, 'kanbanz_custom_labels', $custom_label);

	if(!empty($assign_board)) {
		update_post_meta($post_id, 'kanbanz_board_assignment', $assign_board);
	}

	if(!empty($assigned_staff)) {
		$usernames = array();
		foreach($assigned_staff as $staff_id) {
			$wp_user = is_numeric($staff_id) ? get_user_by('ID', $staff_id) : get_user_by('login', $staff_id);
			if($wp_user) {
				$usernames[] = $wp_user->user_login;
			}
		}
		kanbanz_save_board_team($post_id, $usernames);
	}

	$activity_logs = get_post_meta($post_id, 'kanbanz_board_activity', true);
	if(empty($activity_logs) || !is_array($activity_logs)) $activity_logs = array();

	$activity_logs[] = array(
		'log_title' => 'Board Created by ' . $user->user_login,
		'time'      => current_time('mysql'),
		'user'      => $user->user_login,
	);
	update_post_meta($post_id, 'kanbanz_board_activity', $activity_logs);

	return array('success' => true, 'message' => 'Board created successfully.', 'post_id' => $post_id, 'post_title' => $title, 'post_desc' => $description);
}

function kanbanz_update_board($data) {
	$title       = isset($data['name']) ? sanitize_text_field(wp_unslash($data['name'])) : '';
	$description = isset($data['description']) ? sanitize_textarea_field(wp_unslash($data['description'])) : '';
	$parent      = isset($data['parent']) ? absint($data['parent']) : 0;
	$dept_id     = isset($data['dept_id']) ? absint($data['dept_id']) : 0;
	$user_id     = get_current_user_id();
	$user        = get_user_by('ID', $user_id);

	if(!$user) {
		return array('success' => false, 'message' => 'User not found.');
	}

	$assign_board = !empty($data['assign_manager_name']) ? sanitize_text_field($data['assign_manager_name']) : $user->user_login;
	$assigned_staff = (isset($data['assign_members']) && is_array($data['assign_members'])) ? $data['assign_members'] : array();

	$posts = get_posts(array(
		'post_type'   => 'kanbanz_board',
		'meta_query'  => array(
			array('key' => 'deskuss_dept_link', 'value' => $dept_id),
		),
		'fields'      => 'ids',
		'numberposts' => 1,
	));

	if(empty($posts)) {
		return array('success' => false, 'message' => 'No board found for the given department.');
	}

	$post_id = $posts[0];
	$update_data = array('ID' => $post_id);
	$has_changes = false;

	if(!empty($title)) {
		$update_data['post_title'] = $title;
		$has_changes = true;
	}
	if(!empty($description)) {
		$update_data['post_content'] = $description;
		$has_changes = true;
	}

	if($has_changes) {
		wp_update_post($update_data);
	}

	if(!empty($assign_board)) {
		update_post_meta($post_id, 'kanbanz_board_assignment', $assign_board);
	}

	if(!empty($assigned_staff)) {
		$usernames = array();
		foreach($assigned_staff as $staff_id) {
			$wp_user = is_numeric($staff_id) ? get_user_by('ID', $staff_id) : get_user_by('login', $staff_id);
			if($wp_user) {
				$usernames[] = $wp_user->user_login;
			}
		}
		kanbanz_save_board_team($post_id, $usernames);
	}

	return array('success' => true, 'message' => 'Board updated successfully.', 'post_id' => $post_id);
}

function kanbanz_get_user_accessible_boards() {
	return kanbanz_get_accessible_boards(array('include_dept_boards' => true));
}

/**
 * Shared assignee/board/search query for the My Tasks completed & pending
 * lists. Deliberately does NOT filter by task_status — a board's terminal
 * ("done") status isn't always literally 'Completed' (see
 * kanbanz_get_board_terminal_status()), so completed-vs-pending is decided
 * per-post in PHP by the two callers below instead of in a single meta_query
 * that can't express a per-board value.
 *
 * Deliberately does NOT restrict to kanbanz_get_user_accessible_boards()
 * either — being the assignee on a task is its own grant to see that task
 * on your own My Tasks page, even on a board you're not (or are no longer) a
 * team member of. Board team membership still gates the regular board view
 * and the org-wide Analytics page; this only affects a user's own task list.
 */
function kanbanz_get_my_tasks_candidates($args) {
	$current_user = wp_get_current_user();
	$username = $current_user->user_login;

	$meta_query = array(
		array('relation' => 'OR',
			array('key' => 'kanbanz_assigned_staff', 'value' => $username, 'compare' => 'LIKE'),
			array('key' => 'deskuss_assigned_staff', 'value' => $username, 'compare' => 'LIKE'),
		),
	);

	$query_args = array(
		'post_type'      => 'kanbanz_task',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => $meta_query,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);

	if(!empty($args['board_id'])) {
		$query_args['post_parent'] = absint($args['board_id']);
	}
	if(!empty($args['search'])) {
		$query_args['s'] = sanitize_text_field($args['search']);
	}

	$query = new WP_Query($query_args);
	return $query->posts;
}

/**
 * Whether $post's task_status matches its own board's terminal ("done")
 * status. $terminal_cache is keyed by board_id and passed by reference so
 * repeated calls across a task list only look each board up once.
 */
function kanbanz_task_is_completed($post, &$terminal_cache) {
	$board_id = intval($post->post_parent);
	if(!isset($terminal_cache[$board_id])) {
		$terminal = kanbanz_get_board_terminal_status($board_id);
		$terminal_cache[$board_id] = !empty($terminal) ? $terminal : 'Completed';
	}
	$status = get_post_meta($post->ID, 'task_status', true);
	return (strtolower($status) === strtolower($terminal_cache[$board_id]));
}

function kanbanz_get_my_completed_tasks($args = array()) {
	$defaults = array(
		'start_date' => '',
		'end_date'   => '',
		'board_id'   => 0,
		'search'     => '',
		'page'       => 1,
		'per_page'   => 20,
	);
	$args = wp_parse_args($args, $defaults);

	$candidates = kanbanz_get_my_tasks_candidates($args);
	if(empty($candidates)) {
		return array('tasks' => array(), 'total' => 0, 'total_pages' => 0);
	}

	$has_date_range = (!empty($args['start_date']) && !empty($args['end_date']));
	$start_ts = $has_date_range ? strtotime($args['start_date']) : 0;
	$end_ts   = $has_date_range ? strtotime($args['end_date']) : 0;

	$terminal_cache = array();
	$matched = array();

	foreach($candidates as $post) {
		if(!kanbanz_task_is_completed($post, $terminal_cache)) continue;

		if($has_date_range) {
			$modified_ts = strtotime($post->post_modified);
			if($modified_ts < $start_ts || $modified_ts > $end_ts) continue;
		}

		$matched[] = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => get_post_meta($post->ID, 'task_status', true),
			'priority' => kanbanz_get_task_priority($post->ID),
			'due_date' => get_post_meta($post->ID, 'due_date', true),
			'board_id' => $post->post_parent,
			'modified' => $post->post_modified,
		);
	}

	$total = count($matched);
	$per_page = max(1, intval($args['per_page']));
	$offset = (max(1, intval($args['page'])) - 1) * $per_page;

	return array(
		'tasks'       => array_slice($matched, $offset, $per_page),
		'total'       => $total,
		'total_pages' => (int) ceil($total / $per_page),
	);
}

/**
 * Pending tasks are always a full, all-time count of the user's backlog —
 * intentionally ignores 'start_date'/'end_date' (a My Tasks date filter that
 * only makes sense for "what did I finish in this window"; a still-open task
 * doesn't belong to any particular window).
 */
function kanbanz_get_my_pending_tasks($args = array()) {
	$defaults = array(
		'start_date' => '',
		'end_date'   => '',
		'board_id'   => 0,
		'search'     => '',
		'page'       => 1,
		'per_page'   => 20,
	);
	$args = wp_parse_args($args, $defaults);

	$candidates = kanbanz_get_my_tasks_candidates($args);
	if(empty($candidates)) {
		return array('tasks' => array(), 'total' => 0, 'total_pages' => 0);
	}

	$terminal_cache = array();
	$matched = array();

	foreach($candidates as $post) {
		if(kanbanz_task_is_completed($post, $terminal_cache)) continue;

		$matched[] = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => get_post_meta($post->ID, 'task_status', true),
			'priority' => kanbanz_get_task_priority($post->ID),
			'due_date' => get_post_meta($post->ID, 'due_date', true),
			'board_id' => $post->post_parent,
			'modified' => $post->post_modified,
		);
	}

	$total = count($matched);
	$per_page = max(1, intval($args['per_page']));
	$offset = (max(1, intval($args['page'])) - 1) * $per_page;

	return array(
		'tasks'       => array_slice($matched, $offset, $per_page),
		'total'       => $total,
		'total_pages' => (int) ceil($total / $per_page),
	);
}

function kanbanz_get_my_boards_for_filter() {
	$accessible = kanbanz_get_accessible_boards(array('include_dept_boards' => true));
	if(empty($accessible)) return array();

	$boards = array();
	foreach($accessible as $board_id) {
		$post = get_post($board_id);
		if($post) {
			$boards[] = array('id' => $board_id, 'title' => $post->post_title);
		}
	}
	return $boards;
}

function kanbanz_add_notification($user_id, $from_user_id, $task_id, $board_id, $type = 'mention', $message = '') {
	$notifications = get_user_meta($user_id, 'kanbanz_notifications', true);
	if(!is_array($notifications)) $notifications = array();

	array_unshift($notifications, array(
		'id'           => uniqid('kz_'),
		'from_user_id' => $from_user_id,
		'task_id'      => $task_id,
		'board_id'     => $board_id,
		'type'         => $type,
		'message'      => $message,
		'is_read'      => 0,
		'created_at'   => current_time('mysql'),
	));

	$notifications = array_slice($notifications, 0, 100);
	update_user_meta($user_id, 'kanbanz_notifications', $notifications);
	return true;
}

function kanbanz_get_notifications($user_id, $unread_only = false, $limit = 30) {
	$notifications = get_user_meta($user_id, 'kanbanz_notifications', true);
	if(!is_array($notifications)) return array();

	if($unread_only) {
		$notifications = array_filter($notifications, function($n) {
			return empty($n['is_read']);
		});
	}

	return array_slice(array_values($notifications), 0, $limit);
}

function kanbanz_mark_notifications_read($user_id, $notification_ids = array()) {
	$notifications = get_user_meta($user_id, 'kanbanz_notifications', true);
	if(!is_array($notifications)) return;

	if(!empty($notification_ids)) {
		$ids = array_map('strval', $notification_ids);
		foreach($notifications as &$n) {
			if(in_array(strval($n['id']), $ids)) {
				$n['is_read'] = 1;
			}
		}
	} else {
		foreach($notifications as &$n) {
			$n['is_read'] = 1;
		}
	}

	update_user_meta($user_id, 'kanbanz_notifications', $notifications);
}

function kanbanz_count_unread_notifications($user_id) {
	$notifications = get_user_meta($user_id, 'kanbanz_notifications', true);
	if(!is_array($notifications)) return 0;

	$count = 0;
	foreach($notifications as $n) {
		if(empty($n['is_read'])) $count++;
	}
	return $count;
}

function kanbanz_notify_mentioned_users($task_id, $board_id, $comment_text, $from_user_id) {
	$mentioned = kanbanz_parse_mentions($comment_text);
	if(empty($mentioned)) return;

	foreach($mentioned as $username) {
		$user = get_user_by('login', $username);
		if(!$user || $user->ID == $from_user_id) continue;

		$task = get_post($task_id);
		$task_title = $task ? $task->post_title : '';
		$from_user = get_user_by('ID', $from_user_id);
		$from_name = $from_user ? $from_user->display_name : 'Someone';

		$message = sprintf('%s mentioned you on task "%s"', $from_name, mb_substr($task_title, 0, 80));
		kanbanz_notify_user($user->ID, $from_user_id, $task_id, $board_id, 'mention', $message);
	}
}

/**
 * Notify a user about a task event: adds an in-admin notification and, when
 * email notifications and the matching per-type setting are enabled, sends an email.
 *
 * @param int    $user_id      User to notify.
 * @param int    $from_user_id User who triggered the event.
 * @param int    $task_id      Task ID.
 * @param int    $board_id     Board ID.
 * @param string $type         'assign', 'comment' or 'mention'.
 * @param string $message      Notification text.
 */
function kanbanz_notify_user($user_id, $from_user_id, $task_id, $board_id, $type, $message) {
	if (!$user_id || $user_id == $from_user_id) return;

	kanbanz_add_notification($user_id, $from_user_id, $task_id, $board_id, $type, $message);

	$setting = ($type === 'assign') ? 'notify_on_assign' : 'notify_on_comment';
	if (kanbanz_get_setting($setting, '1') !== '1') return;

	$task = get_post($task_id);
	kanbanz_send_email_notification($user_id, $type, array(
		'task_title' => $task ? $task->post_title : '',
		'message'    => $message,
		'board_url'  => admin_url('admin.php?page=kanbanz&board_id=' . absint($board_id)),
	));
}

/**
 * Add task assignees to the board's team, so an assignee can always open the
 * board their task is on. Boards with no team are already visible to all
 * staff, so they are left alone — adding one assignee would make the board
 * private to that user. Admins and non-staff users are skipped.
 *
 * @param int          $board_id  Board ID.
 * @param string|array $usernames One or more user logins.
 * @return array Logins that were added to the team.
 */
function kanbanz_add_assignees_to_board_team($board_id, $usernames) {
	if (!$board_id || get_post_type($board_id) !== 'kanbanz_board') return array();

	$team = kanbanz_board_team($board_id);
	// Department boards keep their access rules; only a department-less board
	// with no team is open to everyone.
	if (empty($team) && empty(kanbanz_get_board_dept_id($board_id))) return array();

	$saved = kanbanz_save_board_team($board_id, array_merge($team, (array) $usernames));
	return array_values(array_diff($saved, $team));
}

/**
 * Notify a user (by login) that they were assigned to a task.
 */
function kanbanz_notify_assigned_user($username, $task_id, $board_id, $from_user_id) {
	$user = get_user_by('login', $username);
	if (!$user) return;

	$task = get_post($task_id);
	$from_user = get_user_by('ID', $from_user_id);
	/* translators: 1: name of the user who made the assignment, 2: task title */
	$message = sprintf(__('%1$s assigned you to task "%2$s"', 'kanbanz'), $from_user ? $from_user->display_name : __('Someone', 'kanbanz'), $task ? mb_substr($task->post_title, 0, 80) : '');
	kanbanz_notify_user($user->ID, $from_user_id, $task_id, $board_id, 'assign', $message);
}

/**
 * Notify a task's assignees and author about a new comment. Users who were
 * @mentioned in the comment are skipped, as they already get a mention notification.
 */
function kanbanz_notify_task_commenters($task_id, $board_id, $comment_text, $from_user_id) {
	$task = get_post($task_id);
	if (!$task) return;

	$recipients = array();
	$assigned = kanbanz_get_task_assigned_staff($task_id);
	foreach ((array) $assigned as $username) {
		$user = $username ? get_user_by('login', $username) : false;
		if ($user) $recipients[$user->ID] = true;
	}
	if ($task->post_author) $recipients[(int) $task->post_author] = true;

	$mentioned = kanbanz_parse_mentions($comment_text);
	foreach ($mentioned as $username) {
		$user = get_user_by('login', $username);
		if ($user) unset($recipients[$user->ID]);
	}

	$from_user = get_user_by('ID', $from_user_id);
	/* translators: 1: name of the commenter, 2: task title */
	$message = sprintf(__('%1$s commented on task "%2$s"', 'kanbanz'), $from_user ? $from_user->display_name : __('Someone', 'kanbanz'), mb_substr($task->post_title, 0, 80));

	foreach (array_keys($recipients) as $user_id) {
		kanbanz_notify_user($user_id, $from_user_id, $task_id, $board_id, 'comment', $message);
	}
}

function kanbanz_parse_mentions($text) {
	preg_match_all('/@(\w+)/', $text, $matches);
	return $matches[1];
}

function kanbanz_get_tasks_graph_data($period = 30) {
	global $wpdb;
	$current_user_id = get_current_user_id();
	if($current_user_id === 0) return array();

	$current_user = wp_get_current_user();
	$username = $current_user->user_login;
	$date_start = gmdate('Y-m-d', strtotime("-{$period} days"));
	$date_end_day = gmdate('Y-m-d');
	$date_end_full = $date_end_day . ' 23:59:59';

	$like_user = '%' . $wpdb->esc_like($username) . '%';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$completed = $wpdb->get_results($wpdb->prepare(
		"SELECT DATE(p.post_modified) as date, COUNT(*) as count
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'task_status' AND m1.meta_value = 'Completed'
		 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND (
			(m2.meta_key = 'kanbanz_assigned_staff' AND m2.meta_value LIKE %s)
			OR (m2.meta_key = 'deskuss_assigned_staff' AND m2.meta_value LIKE %s)
		 )
		 WHERE p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
		 AND p.post_modified >= %s AND p.post_modified <= %s
		 GROUP BY DATE(p.post_modified) ORDER BY date",
		$like_user, $like_user, $date_start, $date_end_full
	));
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$created = $wpdb->get_results($wpdb->prepare(
		"SELECT DATE(p.post_date) as date, COUNT(*) as count
		 FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND (
			(m.meta_key = 'kanbanz_assigned_staff' AND m.meta_value LIKE %s)
			OR (m.meta_key = 'deskuss_assigned_staff' AND m.meta_value LIKE %s)
		 )
		 WHERE p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
		 AND p.post_date >= %s AND p.post_date <= %s
		 GROUP BY DATE(p.post_date) ORDER BY date",
		$like_user, $like_user, $date_start, $date_end_full
	));
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return array('completed' => $completed, 'created' => $created);
}

function kanbanz_count_my_completed_tasks($date_start, $date_end) {
	global $wpdb;
	$current_user = wp_get_current_user();
	$username = $current_user->user_login;
	$like_user = '%' . $wpdb->esc_like($username) . '%';
	$end_full = $date_end . ' 23:59:59';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	return (int) $wpdb->get_var($wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = 'task_status' AND m1.meta_value = 'Completed'
		 INNER JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND (
			(m2.meta_key = 'kanbanz_assigned_staff' AND m2.meta_value LIKE %s)
			OR (m2.meta_key = 'deskuss_assigned_staff' AND m2.meta_value LIKE %s)
		 )
		 WHERE p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
		 AND p.post_modified >= %s AND p.post_modified <= %s",
		$like_user, $like_user, $date_start, $end_full
	));
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

function kanbanz_get_task_activity($task_id) {
	$task_id = absint($task_id);
	if(!$task_id) return array();
	// Merge both keys for backward compat (writers historically used either)
	$a = get_post_meta($task_id, 'kanbanz_activity', true);
	$b = get_post_meta($task_id, 'kanbanz_task_activity', true);
	$a = is_array($a) ? $a : array();
	$b = is_array($b) ? $b : array();
	$activity = array_merge($a, $b);
	// De-duplicate by (user+action+time) and sort newest-first
	$seen = array();
	$activity = array_filter($activity, function($item) use (&$seen) {
		$key = (isset($item['user']) ? $item['user'] : '') . '|' . (isset($item['action']) ? $item['action'] : '') . '|' . (isset($item['time']) ? $item['time'] : '');
		if(isset($seen[$key])) return false;
		$seen[$key] = true;
		return true;
	});
	usort($activity, function($x, $y) {
		$tx = isset($x['time']) ? (int) $x['time'] : 0;
		$ty = isset($y['time']) ? (int) $y['time'] : 0;
		return $ty - $tx;
	});
	return $activity;
}

function kanbanz_update_activity($post_id, $user_name, $message, $slug = 'kanbanz_activity', $event_data = array()) {
	$post_activity = get_post_meta($post_id, $slug, true);
	if(empty($post_activity) || !is_array($post_activity)) {
		$post_activity = array();
	}

	$entry = array(
		'user'    => $user_name,
		'action'  => $message,
		'time'    => time(),
		'type'    => isset($event_data['type']) ? sanitize_text_field($event_data['type']) : 'system',
	);

	if(!empty($event_data['old_value'])) $entry['old_value'] = sanitize_text_field($event_data['old_value']);
	if(!empty($event_data['new_value'])) $entry['new_value'] = sanitize_text_field($event_data['new_value']);
	if(!empty($event_data['comment'])) $entry['comment'] = wp_kses_post($event_data['comment']);

	array_unshift($post_activity, $entry);
	$post_activity = array_slice($post_activity, 0, 100);
	update_post_meta($post_id, $slug, $post_activity);
	return $post_activity;
}

function kanbanz_get_column_tasks_paginated($board_id, $status, $page = 1, $per_page = 30) {
	global $wpdb;
	$offset = ($page - 1) * $per_page;

	$args = array(
		'post_type'      => 'kanbanz_task',
		'post_parent'    => $board_id,
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'post_status'    => 'publish',
		'orderby'        => array('menu_order' => 'ASC', 'date' => 'DESC'),
		'meta_query'     => array(
			array(
				'key'     => 'task_status',
				'value'   => $status,
				'compare' => '='
			)
		)
	);

	$query = new WP_Query($args);
	$tasks = $query->posts;
	$total_tasks = $query->found_posts;
	$has_more = ($offset + count($tasks)) < $total_tasks;

	$formatted_tasks = array();

	if (!empty($tasks)) {
		$task_ids = wp_list_pluck($tasks, 'ID');
		update_meta_cache('post', $task_ids);

		// Pre-fetch subtasks
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

		// Pre-fetch comment counts
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

		foreach ($tasks as $task) {
			$assigned_staff = kanbanz_get_task_assigned_staff($task->ID);
			$due_date = get_post_meta($task->ID, 'due_date', true);
			$priority = get_post_meta($task->ID, 'priority', true);
			if (empty($priority)) {
				$priority = get_post_meta($task->ID, 'task_priority', true);
			}

			$subtask_count_total = $subtasks_by_parent[$task->ID]['total'] ?? 0;
			$subtask_count_done = $subtasks_by_parent[$task->ID]['done'] ?? 0;

			$subtasks_raw = get_post_meta($task->ID, 'kanbanz_subtasks_meta', true);
			if (is_array($subtasks_raw)) {
				$subtask_count_total += count($subtasks_raw);
				foreach ($subtasks_raw as $st) {
					if (!empty($st['done']) || !empty($st['checked'])) $subtask_count_done++;
				}
			}

			$comment_count = $comments_by_post[$task->ID] ?? 0;
			$att_ids = get_post_meta($task->ID, 'kanbanz_attachments', true);
			$attachment_count = is_array($att_ids) ? count($att_ids) : 0;

			$formatted_tasks[] = array(
				'id'        => $task->ID,
				'title'     => $task->post_title,
				'name'      => $task->post_name,
				'content'   => $task->post_content,
				'task_meta' => array(
					'due_date'         => $due_date,
					'priority'         => $priority,
					'assigned'         => $assigned_staff,
					'is_overdue'       => (!empty($due_date) && strtotime($due_date) < time() && $status !== 'Completed'),
					'subtask_count'    => $subtask_count_total,
					'subtask_done'     => $subtask_count_done,
					'comment_count'    => $comment_count,
					'attachment_count' => $attachment_count,
					'ticket_number'    => kanbanz_get_task_ticket($task->ID),
					'labels'           => kanbanz_get_task_labels($task->ID),
				)
			);
		}
	}

	return array(
		'tasks'       => $formatted_tasks,
		'total_tasks' => $total_tasks,
		'page'        => $page,
		'per_page'    => $per_page,
		'has_more'    => $has_more,
	);
}

function kanbanz_get_setting($key, $default = '') {
	$settings = get_option('kanbanz_settings', array());
	if (is_array($settings) && isset($settings[$key])) {
		return $settings[$key];
	}
	return $default;
}

function kanbanz_get_board_member_avatars($board_id) {
	$staff = get_post_meta($board_id, 'kanbanz_staff_members', true);
	if (!is_array($staff) || empty($staff)) {
		return array();
	}
	$avatars = array();
	foreach ($staff as $username) {
		$user = get_user_by('login', $username);
		if ($user) {
			$name = $user->display_name ?: $user->user_login;
			$parts = explode(' ', trim($name));
			$initials = strtoupper(substr($parts[0], 0, 1));
			if (isset($parts[1])) {
				$initials .= strtoupper(substr($parts[1], 0, 1));
			}
			$avatars[] = array(
				'username'   => $username,
				'name'       => $name,
				'initials'   => $initials,
				'avatar_url' => get_avatar_url($user->ID, array('size' => 32)),
			);
		}
	}
	return $avatars;
}

function kanbanz_get_board_task_counts_by_status($board_id) {
	global $wpdb;
	$statuses = get_post_meta($board_id, 'board_statuses', true);
	if (!is_array($statuses)) $statuses = array();

	$counts = array();
	foreach ($statuses as $st) {
		$counts[$st] = 0;
	}

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$results = $wpdb->get_results($wpdb->prepare("
		SELECT pm.meta_value AS status, COUNT(*) AS cnt
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = 'task_status')
		WHERE p.post_parent = %d AND p.post_type = 'kanbanz_task' AND p.post_status = 'publish'
		GROUP BY pm.meta_value
	", $board_id));
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if (!empty($results)) {
		foreach ($results as $row) {
			$counts[$row->status] = (int)$row->cnt;
		}
	}
	return $counts;
}

function kanbanz_send_email_notification($to_user_id, $type, $data = array()) {
	$enabled = kanbanz_get_setting('email_notifications_enabled', '0');
	if ($enabled !== '1' && $enabled !== true && $enabled !== 1) return false;

	$user = get_user_by('ID', $to_user_id);
	if (!$user || empty($user->user_email)) return false;

	/* translators: %s: notification type */
	$subject = sprintf(__('[Kanbanz] Notification: %s', 'kanbanz'), ucfirst(str_replace('_', ' ', $type)));
	$task_title = isset($data['task_title']) ? $data['task_title'] : '';
	$message = isset($data['message']) ? $data['message'] : '';
	$board_url = isset($data['board_url']) ? $data['board_url'] : admin_url('admin.php?page=kanbanz');

	$body  = "<h2>Kanbanz Task Notification</h2>\n";
	$body .= "<p><strong>Type:</strong> " . esc_html($type) . "</p>\n";
	if ($task_title) {
		$body .= "<p><strong>Task:</strong> " . esc_html($task_title) . "</p>\n";
	}
	if ($message) {
		$body .= "<p>" . esc_html($message) . "</p>\n";
	}
	$body .= "<p><a href='" . esc_url($board_url) . "'>View in Kanbanz</a></p>\n";

	$headers = array('Content-Type: text/html; charset=UTF-8');
	return wp_mail($user->user_email, $subject, $body, $headers);
}

function kanbanz_get_board_templates() {
	$templates = array(
		'customer_support' => array(
			'key'         => 'customer_support',
			'name'        => __('Customer Support', 'kanbanz'),
			'description' => __('Manage support tickets from report to resolution.', 'kanbanz'),
			'statuses'    => array('New', 'Open', 'In Progress', 'On Hold', 'Resolved'),
			'color'       => '#3b82f6',
		),
		'software_dev' => array(
			'key'         => 'software_dev',
			'name'        => __('Software Development', 'kanbanz'),
			'description' => __('Sprint / feature tracking workflow for developers.', 'kanbanz'),
			'statuses'    => array('Backlog', 'To Do', 'In Progress', 'Code Review', 'Done'),
			'color'       => '#6366f1',
		),
		'marketing' => array(
			'key'         => 'marketing',
			'name'        => __('Marketing Campaign', 'kanbanz'),
			'description' => __('Plan, create, review and launch marketing campaigns.', 'kanbanz'),
			'statuses'    => array('Ideas', 'Planning', 'In Progress', 'Review', 'Published'),
			'color'       => '#ec4899',
		),
		'hr_onboarding' => array(
			'key'         => 'hr_onboarding',
			'name'        => __('HR Onboarding', 'kanbanz'),
			'description' => __('Track applicants and new employee onboarding steps.', 'kanbanz'),
			'statuses'    => array('Applied', 'Interview', 'Offer', 'Onboarding', 'Active'),
			'color'       => '#10b981',
		),
		'blank' => array(
			'key'         => 'blank',
			'name'        => __('Blank Board', 'kanbanz'),
			'description' => __('Simple standard 3-column kanban board.', 'kanbanz'),
			'statuses'    => array('To Do', 'In Progress', 'Done'),
			'color'       => '#6b7280',
		),
	);
	return apply_filters('kanbanz_board_templates', $templates);
}

function kanbanz_create_board_from_template($template_key, $board_title = '', $members = array()) {
	$templates = kanbanz_get_board_templates();
	$tmpl = isset($templates[$template_key]) ? $templates[$template_key] : $templates['blank'];

	$board_data = array(
		'name'           => $board_title ?: $tmpl['name'],
		'description'    => $tmpl['description'],
		'assign_members' => (array) $members,
	);
	$res = kanbanz_create_board($board_data);
	if (!is_array($res) || empty($res['success'])) {
		return new WP_Error('create_failed', isset($res['message']) ? $res['message'] : 'Failed to create board.');
	}

	$board_id = intval($res['post_id']);
	update_post_meta($board_id, 'board_statuses', $tmpl['statuses']);

	return $board_id;
}

/**
 * Comprehensive Analytics Data Engine: Calculates summary metrics, daily trends, status distribution,
 * priority distribution, and staff & user performance tables.
 *
 * @param array $args Filter arguments: start_date, end_date, board_id, staff_filter.
 * @return array Analytics dataset.
 */
function kanbanz_get_analytics_data($args = array()) {
	global $wpdb;

	$defaults = array(
		'start_date'   => gmdate('Y-m-d', strtotime('-30 days')),
		'end_date'     => gmdate('Y-m-d'),
		'board_id'     => 0,
		'staff_filter' => '',
		'user_id'      => 0,
	);
	$args = wp_parse_args($args, $defaults);

	$start_date = sanitize_text_field($args['start_date']);
	$end_date   = sanitize_text_field($args['end_date']);

	// start_date/end_date arrive straight from $_REQUEST on the My Tasks page
	// and the CSV export endpoints, so they have to be normalized here rather
	// than trusted:
	//
	//  - Unparseable values fall back to the default 30-day window instead of
	//    letting strtotime() return false and poisoning every comparison below.
	//  - An inverted range (end before start) is swapped rather than silently
	//    producing an empty report, which reads as a broken page.
	//  - The span is capped, because the trends loop further down emits one
	//    array entry per day and that result is JSON-encoded into an HTML
	//    attribute by the My Tasks template. An uncapped range is a memory
	//    bomb: start_date=1900-01-01 yields ~46k entries / 3.3MB of JSON and
	//    exhausts a 64MB PHP limit before the page can render.
	$start_ts_raw = strtotime($start_date);
	$end_ts_raw   = strtotime($end_date);
	if (!$start_ts_raw) $start_ts_raw = strtotime('-30 days');
	if (!$end_ts_raw)   $end_ts_raw   = time();

	if ($start_ts_raw > $end_ts_raw) {
		$swap         = $start_ts_raw;
		$start_ts_raw = $end_ts_raw;
		$end_ts_raw   = $swap;
	}

	$max_days = (int) apply_filters('kanbanz_analytics_max_range_days', 366);
	if ($max_days > 0 && ($end_ts_raw - $start_ts_raw) > ($max_days * DAY_IN_SECONDS)) {
		$start_ts_raw = $end_ts_raw - ($max_days * DAY_IN_SECONDS);
	}

	$start_date = gmdate('Y-m-d', $start_ts_raw);
	$end_date   = gmdate('Y-m-d', $end_ts_raw);
	$start      = $start_date . ' 00:00:00';
	$end        = $end_date . ' 23:59:59';
	$board_id   = intval($args['board_id']);
	$staff_filter = sanitize_text_field($args['staff_filter']);
	$user_id_filter = intval($args['user_id']);

	$where = array("p.post_type = 'kanbanz_task'", "p.post_status = 'publish'");

	if ($board_id > 0) {
		// A specific board was requested — verify the current user can actually
		// access it before scoping the query to it, otherwise any kanbanz_staff
		// user could pass an arbitrary board_id and read another board's analytics.
		if (!kanbanz_user_can_access_board($board_id)) {
			$where[] = '1 = 0';
		} else {
			$where[] = $wpdb->prepare("p.post_parent = %d", $board_id);
		}
	} else {
		$accessible = kanbanz_get_user_accessible_boards();
		if (!empty($accessible)) {
			$where[] = "p.post_parent IN (" . implode(',', array_map('intval', $accessible)) . ")";
		} else {
			$where[] = '1 = 0';
		}
	}

	if ($user_id_filter > 0) {
		$u_obj = get_userdata($user_id_filter);
		$u_login = $u_obj ? $u_obj->user_login : '';
		$like_u_login = '%' . $wpdb->esc_like($u_login) . '%';

		$where[] = $wpdb->prepare(
			"(p.post_author = %d OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_usr WHERE pm_usr.post_id = p.ID AND (
				(pm_usr.meta_key = 'kanbanz_assigned_staff' AND pm_usr.meta_value LIKE %s) OR
				(pm_usr.meta_key = 'deskuss_assigned_staff' AND pm_usr.meta_value LIKE %s)
			)))",
			$user_id_filter, $like_u_login, $like_u_login
		);
	}

	if (!empty($staff_filter)) {
		$like_staff = '%' . $wpdb->esc_like($staff_filter) . '%';
		$where[] = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm_st WHERE pm_st.post_id = p.ID AND (
				(pm_st.meta_key = 'kanbanz_assigned_staff' AND pm_st.meta_value LIKE %s) OR
				(pm_st.meta_key = 'deskuss_assigned_staff' AND pm_st.meta_value LIKE %s)
			))",
			$like_staff, $like_staff
		);
	}

	$where_sql = implode(' AND ', $where);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$tasks = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_parent, p.post_date, p.post_modified, p.post_author
		 FROM {$wpdb->posts} p
		 WHERE {$where_sql}
		 ORDER BY p.post_date ASC"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// phpcs:enable PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if (!empty($tasks)) {
		update_meta_cache('post', wp_list_pluck($tasks, 'ID'));
	}

	// Resolve only the users this task set actually references, rather than
	// pre-loading a fixed slice of the user table. The previous
	// get_users(array('number' => 500)) silently truncated on sites with more
	// than 500 users: anyone outside that first page fell through to
	// user_id => 0 with their raw login shown instead of a display name, and
	// the per-user analytics modal — which keys off that ID — could not open.
	$needed_logins = array();
	$needed_ids    = array();
	foreach ($tasks as $t) {
		$t_assigned = kanbanz_get_task_assigned_staff($t->ID);
		if (!is_array($t_assigned)) $t_assigned = !empty($t_assigned) ? array($t_assigned) : array();
		foreach ($t_assigned as $login) {
			$login = (string) $login;
			if ($login !== '') $needed_logins[$login] = true;
		}
		if (intval($t->post_author) > 0) $needed_ids[intval($t->post_author)] = true;
	}

	$user_map = array();
	$found_logins = array();
	if (!empty($needed_logins)) {
		foreach (get_users(array('login__in' => array_keys($needed_logins))) as $u) {
			$user_map[$u->user_login] = $u;
			$user_map[$u->ID] = $u;
			$found_logins[$u->user_login] = true;
			unset($needed_ids[$u->ID]);
		}
	}
	if (!empty($needed_ids)) {
		foreach (get_users(array('include' => array_keys($needed_ids))) as $u) {
			$user_map[$u->user_login] = $u;
			$user_map[$u->ID] = $u;
		}
	}

	$total_tasks = 0;
	$completed_count = 0;
	$pending_count = 0;
	$overdue_count = 0;
	$completion_times = array();

	$daily_completed = array();
	$daily_created = array();

	$status_counts = array();
	$priority_counts = array();

	$staff_data = array();
	$user_data = array();

	$now_ts   = time();
	$start_ts = strtotime($start);
	$end_ts   = strtotime($end);

	$terminal_status_cache = array();

	foreach ($tasks as $t) {
		$status = get_post_meta($t->ID, 'task_status', true);
		if (empty($status)) $status = 'To Do';
		$status = ucfirst(strtolower($status));

		$priority = get_post_meta($t->ID, 'priority', true) ?: get_post_meta($t->ID, 'task_priority', true);
		if (empty($priority)) $priority = 'Normal';
		$priority = ucfirst(strtolower($priority));

		$due_date = get_post_meta($t->ID, 'due_date', true);
		$assigned = kanbanz_get_task_assigned_staff($t->ID);
		if (!is_array($assigned)) $assigned = !empty($assigned) ? array($assigned) : array();
		$assigned = array_filter(array_map('strval', $assigned));

		$created_date  = gmdate('Y-m-d', strtotime($t->post_date));
		$modified_date = gmdate('Y-m-d', strtotime($t->post_modified));
		$created_ts    = strtotime($t->post_date);
		$modified_ts   = strtotime($t->post_modified);

		$board_id_of_task = intval($t->post_parent);
		if (!isset($terminal_status_cache[$board_id_of_task])) {
			$terminal = kanbanz_get_board_terminal_status($board_id_of_task);
			$terminal_status_cache[$board_id_of_task] = !empty($terminal) ? $terminal : 'Completed';
		}
		$is_completed = (strtolower($status) === strtolower($terminal_status_cache[$board_id_of_task]));
		$is_overdue   = (!empty($due_date) && strtotime($due_date) < $now_ts && !$is_completed);

		$created_in_range   = ($created_ts >= $start_ts && $created_ts <= $end_ts);
		$completed_in_range = ($is_completed && $modified_ts >= $start_ts && $modified_ts <= $end_ts);

		if ($created_in_range) {
			$daily_created[$created_date] = isset($daily_created[$created_date]) ? $daily_created[$created_date] + 1 : 1;
		}
		if ($completed_in_range) {
			$daily_completed[$modified_date] = isset($daily_completed[$modified_date]) ? $daily_completed[$modified_date] + 1 : 1;
		}

		// Pending tasks are always counted all-time (a backlog isn't scoped to
		// a date window); completed tasks are only counted here if they were
		// completed inside the selected range — matching the "Total = Pending
		// (all-time) + Completed (in range)" convention already used by
		// kanbanz_pro_get_employee_performance_metrics(). Skipping here (rather
		// than gating each counter below) keeps the trend buckets above,
		// which have their own independent range checks, unaffected.
		if ($is_completed && !$completed_in_range) {
			continue;
		}

		$total_tasks++;

		$status_counts[$status] = isset($status_counts[$status]) ? $status_counts[$status] + 1 : 1;
		$priority_counts[$priority] = isset($priority_counts[$priority]) ? $priority_counts[$priority] + 1 : 1;

		if ($is_completed) {
			$completed_count++;
			if ($modified_ts > $created_ts) {
				$completion_times[] = ($modified_ts - $created_ts) / 3600;
			}
		} else {
			$pending_count++;
			if ($is_overdue) {
				$overdue_count++;
			}
		}

		foreach ($assigned as $username) {
			if (!isset($staff_data[$username])) {
				$u_obj = isset($user_map[$username]) ? $user_map[$username] : null;
				$staff_data[$username] = array(
					'user_id'          => $u_obj ? $u_obj->ID : 0,
					'username'         => $username,
					'display_name'     => $u_obj ? ($u_obj->display_name ?: $username) : ucfirst($username),
					'user_email'       => $u_obj ? $u_obj->user_email : '',
					'total'            => 0,
					'completed'        => 0,
					'pending'          => 0,
					'overdue'          => 0,
					'completion_times' => array(),
					'tracked_hours'    => 0,
				);
			}
			$staff_data[$username]['total']++;
			if ($is_completed) {
				$staff_data[$username]['completed']++;
				if ($modified_ts > $created_ts) {
					$staff_data[$username]['completion_times'][] = ($modified_ts - $created_ts) / 3600;
				}
			} else {
				$staff_data[$username]['pending']++;
				if ($is_overdue) {
					$staff_data[$username]['overdue']++;
				}
			}
		}

		$author_id = intval($t->post_author);
		if ($author_id > 0) {
			$author_user = isset($user_map[$author_id]) ? $user_map[$author_id] : null;
			$author_key  = $author_user ? $author_user->user_login : 'user_' . $author_id;
			if (!isset($user_data[$author_key])) {
				$user_data[$author_key] = array(
					'user_id'      => $author_id,
					'username'     => $author_user ? $author_user->user_login : 'user_' . $author_id,
					'display_name' => $author_user ? ($author_user->display_name ?: $author_user->user_login) : 'User #' . $author_id,
					'total'        => 0,
					'completed'    => 0,
					'pending'      => 0,
					'overdue'      => 0,
				);
			}
			$user_data[$author_key]['total']++;
			if ($is_completed) {
				$user_data[$author_key]['completed']++;
			} else {
				$user_data[$author_key]['pending']++;
				if ($is_overdue) {
					$user_data[$author_key]['overdue']++;
				}
			}
		}
	}

	$staff_list = array();
	foreach ($staff_data as $username => $s) {
		$s_tot  = $s['total'];
		$s_comp = $s['completed'];
		$s['completion_rate']      = $s_tot > 0 ? round(($s_comp / $s_tot) * 100, 1) : 0;
		$s['avg_completion_hours'] = !empty($s['completion_times']) ? round(array_sum($s['completion_times']) / count($s['completion_times']), 1) : 0;

		if (function_exists('kanbanz_pro_get_time_entries') && $s['user_id'] > 0) {
			$entries = kanbanz_pro_get_time_entries(0, $board_id, $s['user_id']);
			$tot_sec = 0;
			foreach ($entries as $e) {
				if (!empty($e->duration)) $tot_sec += intval($e->duration);
			}
			$s['tracked_hours'] = round($tot_sec / 3600, 1);
		}
		unset($s['completion_times']);
		$staff_list[] = $s;
	}

	usort($staff_list, function($a, $b) {
		if ($a['completed'] !== $b['completed']) return $b['completed'] - $a['completed'];
		return $b['total'] - $a['total'];
	});

	$user_list = array();
	foreach ($user_data as $uk => $u) {
		$u_tot  = $u['total'];
		$u_comp = $u['completed'];
		$u['completion_rate'] = $u_tot > 0 ? round(($u_comp / $u_tot) * 100, 1) : 0;
		$user_list[] = $u;
	}
	usort($user_list, function($a, $b) {
		return $b['total'] - $a['total'];
	});

	$trends_data = array();
	$cur_d = new DateTime($start_date);
	$end_d = new DateTime($end_date);
	while ($cur_d <= $end_d) {
		$d_str = $cur_d->format('Y-m-d');
		$trends_data[] = array(
			'date'         => $d_str,
			'display_date' => $cur_d->format('M j'),
			'completed'    => isset($daily_completed[$d_str]) ? $daily_completed[$d_str] : 0,
			'created'      => isset($daily_created[$d_str]) ? $daily_created[$d_str] : 0,
		);
		$cur_d->modify('+1 day');
	}

	$completion_rate = $total_tasks > 0 ? round(($completed_count / $total_tasks) * 100, 1) : 0;
	$avg_hours       = !empty($completion_times) ? round(array_sum($completion_times) / count($completion_times), 1) : 0;

	return array(
		'summary' => array(
			'total_tasks'          => $total_tasks,
			'completed_tasks'      => $completed_count,
			'pending_tasks'        => $pending_count,
			'overdue_tasks'        => $overdue_count,
			'completion_rate'      => $completion_rate,
			'avg_completion_hours' => $avg_hours,
		),
		'trends'                => $trends_data,
		'status_distribution'   => $status_counts,
		'priority_distribution' => $priority_counts,
		'staff_table'           => $staff_list,
		'user_table'            => $user_list,
	);
}

/**
 * Calculates detailed analytics and active task list for a specific single user.
 *
 * @param int $user_id WP User ID or 0 for username lookups.
 * @param array $args Optional filters: start_date, end_date, board_id, username.
 * @return array Single user analytics dataset including profile info, summary, charts, and recent tasks.
 */
function kanbanz_get_single_user_analytics($user_id = 0, $args = array()) {
	global $wpdb;

	$username = sanitize_text_field($args['username'] ?? '');
	if ($user_id <= 0 && !empty($username)) {
		$u_by_login = get_user_by('login', $username);
		if ($u_by_login) {
			$user_id = $u_by_login->ID;
		}
	}

	$u_obj = get_userdata($user_id);
	if (!$u_obj && !empty($username)) {
		$display_name = ucfirst($username);
		$user_email   = '';
		$avatar_url   = get_avatar_url(0);
		$roles        = array('Staff');
	} else if ($u_obj) {
		$username     = $u_obj->user_login;
		$display_name = $u_obj->display_name ?: $u_obj->user_login;
		$user_email   = $u_obj->user_email;
		$avatar_url   = get_avatar_url($u_obj->ID, array('size' => 96));
		$roles        = !empty($u_obj->roles) ? array_map('ucfirst', $u_obj->roles) : array('Member');
	} else {
		return array('error' => __('User not found', 'kanbanz'));
	}

	$analytics = kanbanz_get_analytics_data(array_merge($args, array('user_id' => $user_id, 'staff_filter' => $username)));

	// Fetch active & recent tasks assigned to this user, restricted to boards the
	// requesting (current) user can access — prevents leaking task data from
	// boards the caller has no access to when looking up another user's analytics.
	$like_u = '%' . $wpdb->esc_like($username) . '%';
	$accessible_boards = kanbanz_get_user_accessible_boards();
	if (empty($accessible_boards)) {
		return array(
			'user_info' => array(
				'user_id'      => $user_id,
				'username'     => $username,
				'display_name' => $display_name,
				'user_email'   => $user_email,
				'avatar_url'   => $avatar_url,
				'roles'        => implode(', ', $roles),
			),
			'summary'               => $analytics['summary'],
			'trends'                => $analytics['trends'],
			'status_distribution'   => $analytics['status_distribution'],
			'priority_distribution' => $analytics['priority_distribution'],
			'tasks'                 => array(),
		);
	}
	$where = array(
		"p.post_type = 'kanbanz_task'",
		"p.post_status = 'publish'",
		"p.post_parent IN (" . implode(',', array_map('intval', $accessible_boards)) . ")",
		$wpdb->prepare(
			"(p.post_author = %d OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND (
				(pm.meta_key = 'kanbanz_assigned_staff' AND pm.meta_value LIKE %s) OR
				(pm.meta_key = 'deskuss_assigned_staff' AND pm.meta_value LIKE %s)
			)))",
			$user_id, $like_u, $like_u
		)
	);
	$where_sql = implode(' AND ', $where);

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal table names (escaped) and prepared/int-cast values.
	$raw_tasks = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_parent, p.post_date, p.post_modified
		 FROM {$wpdb->posts} p
		 WHERE {$where_sql}
		 ORDER BY p.post_modified DESC
		 LIMIT 15" // todo check by hardcoded limit
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if (!empty($raw_tasks)) {
		update_meta_cache('post', wp_list_pluck($raw_tasks, 'ID'));
	}

	$tasks_list = array();
	foreach ($raw_tasks as $rt) {
		$st   = get_post_meta($rt->ID, 'task_status', true) ?: 'To Do';
		$pr   = get_post_meta($rt->ID, 'priority', true) ?: (get_post_meta($rt->ID, 'task_priority', true) ?: 'Normal');
		$due  = get_post_meta($rt->ID, 'due_date', true);
		$board_title = get_the_title($rt->post_parent);

		$tasks_list[] = array(
			'id'          => $rt->ID,
			'title'       => $rt->post_title,
			'board_id'    => $rt->post_parent,
			/* translators: %d: board ID */
			'board_title' => $board_title ?: sprintf(__('Board #%d', 'kanbanz'), $rt->post_parent),
			'status'      => ucfirst(strtolower($st)),
			'priority'    => ucfirst(strtolower($pr)),
			'due_date'    => $due ?: '',
			'created'     => gmdate('Y-m-d', strtotime($rt->post_date)),
			'link'        => admin_url('admin.php?page=kanbanz&board_id=' . $rt->post_parent . '#task-' . $rt->ID),
		);
	}

	return array(
		'user_info' => array(
			'user_id'      => $user_id,
			'username'     => $username,
			'display_name' => $display_name,
			'user_email'   => $user_email,
			'avatar_url'   => $avatar_url,
			'roles'        => implode(', ', $roles),
		),
		'summary'               => $analytics['summary'],
		'trends'                => $analytics['trends'],
		'status_distribution'   => $analytics['status_distribution'],
		'priority_distribution' => $analytics['priority_distribution'],
		'tasks'                 => $tasks_list,
	);
}

