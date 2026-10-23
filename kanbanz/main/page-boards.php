<?php
/**
 * Board listing & board view page renderer.
 *
 * Extracted from the Kanbanz class following the pagelayer pattern
 * where each admin page lives in its own file under main/.
 *
 * @package kanbanz
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE')) exit;

/**
 * Render either the board listing or a single board view,
 * depending on whether a board_id query var is present.
 */
function kanbanz_render_boards_page() {
	if(!current_user_can('kanbanz_staff')) {
		echo '<div class="kanbanz-notice kanbanz-notice--error"><p>' . esc_html__('You do not have permission to access this page.', 'kanbanz') . '</p></div>';
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing parameter, no data is changed.
	$board_id = isset($_GET['board_id']) ? absint($_GET['board_id']) : 0;
	if($board_id) {
		kanbanz_render_board_view($board_id);
	} else {
		kanbanz_render_board_listing();
	}
}

/**
 * Render the board listing page (grid of all boards).
 */
function kanbanz_render_board_listing() {
	include_once KANBANZ_TEMPLATES . '/board-listing.php';
}

/**
 * Render a single board view (the kanban canvas).
 *
 * @param int $board_id
 */
function kanbanz_render_board_view($board_id) {
	$role = kanbanz_get_user_role();
	// Only admins see every board unconditionally. Managers are scoped to
	// their own/assigned boards via kanbanz_user_can_access_board(), which
	// already implements department + assignment logic (functions.php:98) —
	// this used to also bypass the check for 'manager', granting every
	// manager access to every board site-wide regardless of department.
	if($role !== 'admin' && !kanbanz_user_can_access_board($board_id)) {
		echo '<div class="kanbanz-notice kanbanz-notice--error"><p>' . esc_html__('You do not have access to this board.', 'kanbanz') . '</p></div>';
		return;
	}
	$board_data = kanbanz_get_board_data($board_id);
	if(!$board_data) {
		echo '<div class="kanbanz-notice kanbanz-notice--error"><p>' . esc_html__('Board not found.', 'kanbanz') . '</p></div>';
		return;
	}
	$user_id = get_current_user_id();
	$user_theme = get_user_meta($user_id, 'kanbanz_theme', true);
	if(empty($user_theme)) $user_theme = 'light';
	$board_layout = get_user_meta($user_id, 'kanbanz_board_layout', true);
	$current_layout = isset($board_layout[$board_id]) ? $board_layout[$board_id] : 'board';
	$current_user = wp_get_current_user();
	$current_username = $current_user->user_login;
	$staff_info = kanbanz_get_user_info($user_id);
	$display_name = $staff_info ? trim($staff_info->member_firstname . ' ' . $staff_info->member_lastname) : $current_user->display_name;
	$users_for_filter = kanbanz_get_users_for_filter($board_id);
	$wip_limits = get_post_meta($board_id, 'kanbanz_wip_limits', true);
	if(!is_array($wip_limits)) $wip_limits = array();
	$config = array(
		'board_id'          => $board_id,
		'board_title'       => $board_data['board']['title'],
		'board_description' => $board_data['board']['content'],
		'board_author'      => $board_data['board']['post_author'],
		'board_date'        => $board_data['board']['post_date'],
		'statuses'          => array_keys($board_data['tasks']),
		'tasks'             => $board_data['tasks'],
		'wip_limits'        => $wip_limits,
		'user_role'         => $role,
		'current_user_id'   => $user_id,
		'current_username'  => $current_username,
		'current_display'   => $display_name,
		'is_department'     => !empty($board_data['board']['is_department']),
		'theme'             => $user_theme,
		'layout'            => $current_layout,
		'user_meta'         => $board_data['user_meta'],
		'users_for_filter'  => $users_for_filter,
		'ticket_url_base'   => kanbanz_get_ticket_url_base(),
		'terminalStatus'         => kanbanz_get_board_terminal_status($board_id),
		'canCompleteTasks'       => kanbanz_user_can_complete_tasks($board_id),
		'canManageBoard'         => ($role === 'admin' || ($role === 'manager' && kanbanz_user_can_access_board($board_id))),
		'boardManagers'          => kanbanz_get_board_managers($board_id),
		'completionMode'         => get_post_meta($board_id, 'kanbanz_completion_mode', true) ?: 'everyone',
		'completionAllowedUsers' => get_post_meta($board_id, 'kanbanz_completion_allowed_users', true) ?: array(),
		'departments'            => function_exists('kanbanz_get_all_departments') ? kanbanz_get_all_departments() : array(),
		'deptId'                 => kanbanz_get_board_dept_id($board_id),
		'ajaxurl'           => admin_url('admin-ajax.php'),
		'nonce'             => wp_create_nonce('kanbanz_nonce'),
		'admin_url'         => admin_url('admin.php?page=kanbanz'),
		'i18n'              => array(
			'addTask'              => __('Add task', 'kanbanz'),
			'addStage'             => __('Add Stage', 'kanbanz'),
			'searchPlaceholder'    => __('Search tasks...', 'kanbanz'),
			'noResults'            => __('No results found', 'kanbanz'),
			'confirmDelete'        => __('Are you sure you want to delete this task? This cannot be undone.', 'kanbanz'),
			'confirmDeleteBoard'   => __('Are you sure you want to delete this board? This cannot be undone.', 'kanbanz'),
			'confirmDeleteColumn'  => __('Delete this column and move all its tasks? This cannot be undone.', 'kanbanz'),
			'confirmDeleteSubtask' => __('Delete this subtask?', 'kanbanz'),
			'taskCreated'          => __('Task created', 'kanbanz'),
			'taskDeleted'          => __('Task deleted', 'kanbanz'),
			'taskMoved'            => __('Task moved', 'kanbanz'),
			'taskRenamed'          => __('Task renamed', 'kanbanz'),
			'saved'                => __('Saved', 'kanbanz'),
			'error'                => __('Something went wrong', 'kanbanz'),
			'errorGeneric'         => __('Something went wrong', 'kanbanz'),
			'errorLoadingTask'     => __('Error loading task', 'kanbanz'),
			'errorCreatingTask'    => __('Error creating task', 'kanbanz'),
			'errorDeletingTask'    => __('Error deleting task', 'kanbanz'),
			'errorRenamingTask'    => __('Error renaming task', 'kanbanz'),
			'errorUpdatingTitle'   => __('Error updating title', 'kanbanz'),
			'errorMovingTask'      => __('Error moving task', 'kanbanz'),
			'errorAssigning'       => __('Error assigning', 'kanbanz'),
			'errorRemovingMember'  => __('Error removing member', 'kanbanz'),
			'errorAddingLabel'     => __('Error adding label', 'kanbanz'),
			'errorRemovingLabel'   => __('Error removing label', 'kanbanz'),
			'errorAddingSubtask'   => __('Error adding subtask', 'kanbanz'),
			'errorDeletingSubtask' => __('Error deleting subtask', 'kanbanz'),
			'errorUploadingFile'   => __('Error uploading file', 'kanbanz'),
			'errorRemovingAttachment' => __('Error removing attachment', 'kanbanz'),
			'errorSortingTasks'    => __('Error sorting tasks', 'kanbanz'),
			'errorMovingTasks'     => __('Error moving tasks', 'kanbanz'),
			'errorRenamingColumn'  => __('Error renaming column', 'kanbanz'),
			'errorSettingWip'      => __('Error setting WIP limit', 'kanbanz'),
			'errorAddingStage'     => __('Error adding stage', 'kanbanz'),
			'errorDeletingBoard'   => __('Error deleting board', 'kanbanz'),
			'boardCreated'         => __('Board created', 'kanbanz'),
			'boardDeleted'         => __('Board deleted', 'kanbanz'),
			'titleUpdated'         => __('Title updated', 'kanbanz'),
			'descriptionSaved'     => __('Description saved', 'kanbanz'),
			'priorityUpdated'      => __('Priority updated', 'kanbanz'),
			'dueDateUpdated'       => __('Due date updated', 'kanbanz'),
			'memberAssigned'       => __('Member assigned', 'kanbanz'),
			'memberRemoved'        => __('Member removed', 'kanbanz'),
			'labelAdded'           => __('Label added', 'kanbanz'),
			'labelRemoved'         => __('Label removed', 'kanbanz'),
			'removeFromTeam'       => __('Remove from team', 'kanbanz'),
			'removeFromTeamLabel'  => __('Remove from the team', 'kanbanz'),
			'confirmRemoveFromTeam' => __('Remove this member from the board team? They will no longer see this board.', 'kanbanz'),
			'confirmRemoveLastFromTeam' => __('Remove the last team member? The board will become visible to all staff.', 'kanbanz'),
			'memberRemovedFromTeam' => __('Removed from team', 'kanbanz'),
			'memberAddedToTeam'    => __('Added to team', 'kanbanz'),
			'makeBoardPrivate'     => __('Make board private?', 'kanbanz'),
			'confirmFirstTeamMember' => __('Adding a team member makes this board visible only to its team, admins and the board owner. Everyone currently assigned a task on this board is added to the team too.', 'kanbanz'),
			'addMember'            => __('Add member', 'kanbanz'),
			'remove'               => __('Remove', 'kanbanz'),
			'noUsersFound'         => __('No users found', 'kanbanz'),
			'errorSaving'          => __('Error saving', 'kanbanz'),
			'subtaskAdded'         => __('Subtask added', 'kanbanz'),
			'subtaskDeleted'       => __('Subtask deleted', 'kanbanz'),
			'subtaskUpdated'       => __('Subtask updated', 'kanbanz'),
			'commentAdded'         => __('Comment added', 'kanbanz'),
			'errorAddingComment'   => __('Error adding comment', 'kanbanz'),
			'fileUploaded'         => __('File uploaded', 'kanbanz'),
			'attachmentRemoved'    => __('Attachment removed', 'kanbanz'),
			'columnRenamed'        => __('Column renamed', 'kanbanz'),
			'noActivityYet'        => __('No activity yet', 'kanbanz'),
			'noNotifications'       => __('No notifications', 'kanbanz'),
			'noMembersFound'       => __('No members found', 'kanbanz'),
			'cannotDeleteOnlyColumn' => __('Cannot delete the only column', 'kanbanz'),
			'invalidWipLimit'      => __('Invalid WIP limit', 'kanbanz'),
			'wipLimitSetTo'        => __('WIP limit set to', 'kanbanz'),
			'wipLimitRemoved'      => __('WIP limit removed', 'kanbanz'),
			'addCommentPlaceholder' => __('Add a comment...', 'kanbanz'),
			'addDescriptionPlaceholder' => __('Add a description...', 'kanbanz'),
			'taskTitlePlaceholder' => __('Task title', 'kanbanz'),
			'searchMembersPlaceholder' => __('Search members...', 'kanbanz'),
			'addSubtaskPlaceholder' => __('Add a subtask...', 'kanbanz'),
			'labelTextPlaceholder' => __('Label text', 'kanbanz'),
			'stageNamePlaceholder' => __('Stage name...', 'kanbanz'),
			'commandPlaceholder'   => __('Search tasks or type a command...', 'kanbanz'),
			'activityHeading'      => __('Activity', 'kanbanz'),
			'filterAll'            => __('All', 'kanbanz'),
			'filterComments'       => __('Comments', 'kanbanz'),
			'filterWorkflow'       => __('Workflow', 'kanbanz'),
			'filterSystem'         => __('System', 'kanbanz'),
			'sectionAssignees'     => __('Assignees', 'kanbanz'),
			'sectionDescription'   => __('Description', 'kanbanz'),
			'sectionPriority'      => __('Priority', 'kanbanz'),
			'sectionDueDate'       => __('Due Date', 'kanbanz'),
			'sectionLabels'        => __('Labels', 'kanbanz'),
			'sectionSubtasks'      => __('Subtasks', 'kanbanz'),
			'sectionAttachments'   => __('Attachments', 'kanbanz'),
			'sectionLinkedTicket'  => __('Linked Ticket', 'kanbanz'),
			'editDescription'      => __('Edit description', 'kanbanz'),
			'removeLabel'          => __('Remove label', 'kanbanz'),
			'uploadBtn'            => __('Upload', 'kanbanz'),
			'saveBtn'              => __('Save', 'kanbanz'),
			'cancelBtn'            => __('Cancel', 'kanbanz'),
			'addBtn'               => __('Add', 'kanbanz'),
			'commentBtn'           => __('Comment', 'kanbanz'),
			'priorityUrgent'       => __('Urgent', 'kanbanz'),
			'priorityHigh'         => __('High', 'kanbanz'),
			'priorityMedium'       => __('Medium', 'kanbanz'),
			'priorityLow'          => __('Low', 'kanbanz'),
			'today'                => __('Today', 'kanbanz'),
			'tomorrow'             => __('Tomorrow', 'kanbanz'),
			'yesterday'            => __('Yesterday', 'kanbanz'),
			'overdue'              => __('overdue', 'kanbanz'),
			'justNow'              => __('just now', 'kanbanz'),
			'minutesAgo'           => __('m ago', 'kanbanz'),
			'hoursAgo'             => __('h ago', 'kanbanz'),
			'daysAgo'              => __('d ago', 'kanbanz'),
			'cmdNewTask'           => __('New Task', 'kanbanz'),
			'cmdMyTasks'           => __('My Tasks', 'kanbanz'),
			'cmdToggleTheme'       => __('Toggle Theme', 'kanbanz'),
			'cmdBoardView'         => __('Board View', 'kanbanz'),
			'cmdListView'          => __('List View', 'kanbanz'),
			'taskShort'            => __('Task', 'kanbanz'),
			'setWipLimitPrompt'    => __('Set WIP limit (0 to remove):', 'kanbanz'),
			'wipLimitFor'          => __('WIP limit for', 'kanbanz'),
			'cancel'               => __('Cancel', 'kanbanz'),
			'confirm'              => __('Confirm', 'kanbanz'),
			'close'               => __('Close', 'kanbanz'),
			'delete'              => __('Delete', 'kanbanz'),
		),
		'shortcuts' => array(
			array('keys' => 'Ctrl/⌘ + K', 'desc' => __('Open command palette', 'kanbanz')),
			array('keys' => '/', 'desc' => __('Focus search', 'kanbanz')),
			array('keys' => 'N', 'desc' => __('New task in first column', 'kanbanz')),
			array('keys' => 'M', 'desc' => __('Toggle My Tasks filter', 'kanbanz')),
			array('keys' => '?', 'desc' => __('Show this shortcut cheatsheet', 'kanbanz')),
			array('keys' => 'Esc', 'desc' => __('Close panel / palette / menu', 'kanbanz')),
			array('keys' => 'Ctrl/⌘ + Enter', 'desc' => __('Save description (in editor)', 'kanbanz')),
			array('keys' => 'Enter', 'desc' => __('Save / open task (on card)', 'kanbanz')),
		),
	);
	wp_add_inline_script('kanbanz-editor', 'window.kanbanzData = ' . wp_json_encode($config) . ';', 'before');
	include_once KANBANZ_TEMPLATES . '/board-view.php';
}