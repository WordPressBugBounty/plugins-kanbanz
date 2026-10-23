<?php if (!defined('ABSPATH')) exit;
if (empty($board_data)) { $board_data = kanbanz_get_board_data($board_id); }
if (!$board_data) { echo '<p>' . esc_html__('Board not found.', 'kanbanz') . '</p>'; return; }
$config = json_decode(wp_json_encode(array(
	'board_id' => $board_id,
	'ajaxurl' => admin_url('admin-ajax.php'),
	'nonce' => wp_create_nonce('kanbanz_nonce'),
	'admin_url' => admin_url('admin.php?page=kanbanz'),
)), true);
$theme = isset($user_theme) ? $user_theme : 'light';
$layout = isset($current_layout) ? $current_layout : 'board';
$role = isset($role) ? $role : kanbanz_get_user_role();
$users_for_filter = isset($users_for_filter) ? $users_for_filter : array();
$statuses = is_array($board_data['tasks']) ? array_keys($board_data['tasks']) : array();
$user_meta = isset($board_data['user_meta']) ? $board_data['user_meta'] : array();
$labels = isset($user_meta['task_labels']) ? $user_meta['task_labels'] : array();
$wip_limits = get_post_meta($board_id, 'kanbanz_wip_limits', true);
if (!is_array($wip_limits)) $wip_limits = array();
$current_username = isset($current_username) ? $current_username : wp_get_current_user()->user_login;
$display_name = isset($display_name) ? $display_name : wp_get_current_user()->display_name;
?>
<div class="kanbanz-wrapper" data-theme="<?php echo esc_attr($theme); ?>" data-layout="<?php echo esc_attr($layout); ?>" id="kanbanz-app">
	<a href="#kanbanz-canvas" class="kanbanz-skip-link screen-reader-shortcut"><?php esc_html_e('Skip to board content', 'kanbanz'); ?></a>
	<!-- Header -->
	<header class="kanbanz-header">
		<div class="kanbanz-header__left">
	<a href="<?php echo esc_url(admin_url('admin.php?page=kanbanz')); ?>" class="kanbanz-header__back" title="<?php esc_attr_e('Back to boards', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Back to boards', 'kanbanz'); ?>">
		<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
	</a>
	<div class="kanbanz-header__title-group">
		<h1 class="kanbanz-header__title" id="kanbanz-board-title" contenteditable="<?php echo $role === 'admin' ? 'true' : 'false'; ?>" role="<?php echo $role === 'admin' ? 'textbox' : ''; ?>" aria-label="<?php esc_attr_e('Board title', 'kanbanz'); ?>" aria-multiline="false"><?php echo esc_html($board_data['board']['title']); ?></h1>
		<span class="kanbanz-header__badge" id="kanbanz-task-count" aria-label="<?php esc_attr_e('Total tasks', 'kanbanz'); ?>">0</span>
	</div>
</div>
<div class="kanbanz-header__right">
	<button type="button" class="kanbanz-notification-bell" id="kanbanz-notification-bell" title="<?php esc_attr_e('Notifications', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Notifications', 'kanbanz'); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="kanbanz-notification-dropdown">
		<span class="dashicons dashicons-bell" aria-hidden="true"></span>
		<span class="kanbanz-notification-bell__count" id="kanbanz-notification-count" style="display:none;" role="status" aria-live="polite">0</span>
	</button>
	<div class="kanbanz-notification-dropdown" id="kanbanz-notification-dropdown" role="menu" aria-label="<?php esc_attr_e('Notifications list', 'kanbanz'); ?>" style="display:none;">
		<div class="kanbanz-notification-dropdown__header">
			<span class="kanbanz-notification-dropdown__title"><?php esc_html_e('Notifications', 'kanbanz'); ?></span>
			<button type="button" class="kanbanz-notification-dropdown__mark-all" id="kanbanz-notification-mark-all"><?php esc_html_e('Mark all read', 'kanbanz'); ?></button>
		</div>
		<div class="kanbanz-notification-dropdown__list" id="kanbanz-notification-list">
			<div class="kanbanz-notification-dropdown__empty"><?php esc_html_e('No notifications', 'kanbanz'); ?></div>
		</div>
	</div>
	<button type="button" class="kanbanz-icon-btn" id="kanbanz-theme-toggle" data-kanbanz-theme-toggle title="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-pressed="false">
		<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
	</button>
	<div class="kanbanz-toggle-group" id="kanbanz-layout-toggle" role="group" aria-label="<?php esc_attr_e('Layout view', 'kanbanz'); ?>">
		<button type="button" class="kanbanz-toggle-btn <?php echo $layout === 'board' ? 'kanbanz-toggle-btn--active' : ''; ?>" data-layout="board" title="<?php esc_attr_e('Board view', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Board view', 'kanbanz'); ?>" aria-pressed="<?php echo $layout === 'board' ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-screenoptions" aria-hidden="true"></span> <?php esc_html_e('Board', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-toggle-btn <?php echo $layout === 'list' ? 'kanbanz-toggle-btn--active' : ''; ?>" data-layout="list" title="<?php esc_attr_e('List view', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('List view', 'kanbanz'); ?>" aria-pressed="<?php echo $layout === 'list' ? 'true' : 'false'; ?>">
			<span class="dashicons dashicons-list-view" aria-hidden="true"></span> <?php esc_html_e('List', 'kanbanz'); ?>
		</button>
	</div>
	<?php if ($role === 'admin' || $role === 'manager'): ?>
	<button type="button" class="kanbanz-btn kanbanz-btn--primary kanbanz-btn--sm" id="kanbanz-add-task-btn" aria-label="<?php esc_attr_e('New task', 'kanbanz'); ?>">
		<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
		<?php esc_html_e('New Task', 'kanbanz'); ?>
	</button>
	<?php endif; ?>
	<button type="button" class="kanbanz-icon-btn" id="kanbanz-help-btn" title="<?php esc_attr_e('Keyboard shortcuts (?)', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Keyboard shortcuts', 'kanbanz'); ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="kanbanz-shortcuts-modal">
		<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
	</button>
	<button type="button" class="kanbanz-icon-btn" id="kanbanz-board-settings-btn" title="<?php esc_attr_e('Board settings', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Board settings', 'kanbanz'); ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="kanbanz-settings">
		<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
	</button>
		</div>
	</header>

	<!-- Toolbar -->
	<div class="kanbanz-toolbar" id="kanbanz-toolbar">
		<div class="kanbanz-toolbar__left">
			<div class="kanbanz-search" id="kanbanz-search">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<label for="kanbanz-search-input" class="screen-reader-text"><?php esc_html_e('Search tasks', 'kanbanz'); ?></label>
				<input type="text" id="kanbanz-search-input" placeholder="<?php esc_attr_e('Search tasks... (Press Enter for DB search)', 'kanbanz'); ?>" autocomplete="off" />
				<button type="button" class="kanbanz-search-btn" id="kanbanz-search-submit" title="<?php esc_attr_e('Search Database', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Search Database', 'kanbanz'); ?>">
					<span class="dashicons dashicons-arrow-right-alt2"></span>
				</button>
			</div>
			<div class="kanbanz-chips" id="kanbanz-filter-chips" role="group" aria-label="<?php esc_attr_e('Task filters', 'kanbanz'); ?>">
				<button type="button" class="kanbanz-chip" data-filter="my-tasks" id="kanbanz-my-tasks-chip" aria-pressed="false">
					<?php esc_html_e('My Tasks', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip kanbanz-chip--priority" data-filter="priority" data-value="urgent" style="--chip-color:var(--kanbanz-priority-urgent);" aria-pressed="false">
					<?php esc_html_e('Urgent', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip kanbanz-chip--priority" data-filter="priority" data-value="high" style="--chip-color:var(--kanbanz-priority-high);" aria-pressed="false">
					<?php esc_html_e('High', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip kanbanz-chip--priority" data-filter="priority" data-value="medium" style="--chip-color:var(--kanbanz-priority-medium);" aria-pressed="false">
					<?php esc_html_e('Medium', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip kanbanz-chip--priority" data-filter="priority" data-value="low" style="--chip-color:var(--kanbanz-priority-low, #6b7280);" aria-pressed="false">
					<?php esc_html_e('Low', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip" data-filter="overdue" id="kanbanz-overdue-chip" aria-pressed="false">
					<?php esc_html_e('Overdue', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-chip kanbanz-chip--reset" id="kanbanz-clear-filters-btn" style="display:none;" title="<?php esc_attr_e('Clear all active filters', 'kanbanz'); ?>">
					<span class="dashicons dashicons-no-alt"></span> <?php esc_html_e('Clear Filters', 'kanbanz'); ?>
				</button>
			</div>
		</div>
		<div class="kanbanz-toolbar__right">
			<!-- Date Filter Dropdown -->
			<div class="kanbanz-dropdown" id="kanbanz-date-filter">
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-dropdown__trigger" id="kanbanz-date-filter-btn" title="<?php esc_attr_e('Date Filter', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Date Filter', 'kanbanz'); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="kanbanz-date-filter-menu">
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<?php esc_html_e('Date', 'kanbanz'); ?>
				</button>
				<div class="kanbanz-dropdown__menu kanbanz-dropdown__menu--right" id="kanbanz-date-filter-menu" role="menu" aria-label="<?php esc_attr_e('Date Filter', 'kanbanz'); ?>" style="display:none;">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Date Filter', 'kanbanz'); ?></div>
					<div class="kanbanz-date-filter-section">
						<div class="kanbanz-date-filter-target">
							<label><?php esc_html_e('Filter By:', 'kanbanz'); ?></label>
							<select id="kanbanz-date-target-select" class="kanbanz-select-sm">
								<option value="due_date"><?php esc_html_e('Due Date', 'kanbanz'); ?></option>
								<option value="created_date"><?php esc_html_e('Created Date', 'kanbanz'); ?></option>
							</select>
						</div>
						<div class="kanbanz-date-presets">
							<button type="button" class="kanbanz-date-preset-btn kanbanz-date-preset-btn--active" data-preset="all"><?php esc_html_e('All Time', 'kanbanz'); ?></button>
							<button type="button" class="kanbanz-date-preset-btn" data-preset="today"><?php esc_html_e('Today', 'kanbanz'); ?></button>
							<button type="button" class="kanbanz-date-preset-btn" data-preset="week"><?php esc_html_e('This Week', 'kanbanz'); ?></button>
							<button type="button" class="kanbanz-date-preset-btn" data-preset="month"><?php esc_html_e('This Month', 'kanbanz'); ?></button>
						</div>
						<div class="kanbanz-date-custom-range">
							<div class="kanbanz-date-field">
								<label for="kanbanz-date-from"><?php esc_html_e('From:', 'kanbanz'); ?></label>
								<input type="date" id="kanbanz-date-from" class="kanbanz-date-input" />
							</div>
							<div class="kanbanz-date-field">
								<label for="kanbanz-date-to"><?php esc_html_e('To:', 'kanbanz'); ?></label>
								<input type="date" id="kanbanz-date-to" class="kanbanz-date-input" />
							</div>
							<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-apply-date-range"><?php esc_html_e('Apply Range', 'kanbanz'); ?></button>
						</div>
					</div>
				</div>
			</div>

			<div class="kanbanz-dropdown" id="kanbanz-assignee-filter">
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-dropdown__trigger" id="kanbanz-assignee-filter-btn" aria-label="<?php esc_attr_e('Filter by assignee', 'kanbanz'); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="kanbanz-assignee-filter-menu">
					<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
					<?php esc_html_e('Assignee', 'kanbanz'); ?>
				</button>
				<div class="kanbanz-dropdown__menu kanbanz-dropdown__menu--right" id="kanbanz-assignee-filter-menu" role="menu" aria-label="<?php esc_attr_e('Filter by assignee', 'kanbanz'); ?>">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Filter by assignee', 'kanbanz'); ?></div>
					<button type="button" class="kanbanz-dropdown__item kanbanz-dropdown__item--active" data-user-id="0" role="menuitemradio" aria-checked="true"><?php esc_html_e('All members', 'kanbanz'); ?></button>
					<?php if (!empty($users_for_filter)): foreach ($users_for_filter as $uf): ?>
					<button type="button" class="kanbanz-dropdown__item" data-user-id="<?php echo esc_attr($uf['id']); ?>" data-username="<?php echo esc_attr($uf['username']); ?>" data-name="<?php echo esc_attr($uf['name']); ?>" role="menuitemradio" aria-checked="false"><?php echo esc_html($uf['name'] . ' (@' . $uf['username'] . ')'); ?></button>
					<?php endforeach; endif; ?>
				</div>
			</div>
			<button type="button" class="kanbanz-icon-btn" id="kanbanz-cmd-palette-btn" title="<?php esc_attr_e('Command palette (Ctrl+K)', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Command palette (Ctrl+K)', 'kanbanz'); ?>" aria-haspopup="dialog" aria-expanded="false" aria-controls="kanbanz-cmd-palette">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
			</button>
		</div>
	</div>

	<!-- Canvas -->
	<div class="kanbanz-canvas" id="kanbanz-canvas">
		<?php foreach ($statuses as $i => $status):
			$status_tasks = isset($board_data['tasks'][$status]) ? $board_data['tasks'][$status] : array();
			$loaded_task_count = is_array($status_tasks) ? count($status_tasks) : 0;
			$total_status_tasks = isset($board_data['task_counts'][$status]) ? intval($board_data['task_counts'][$status]) : $loaded_task_count;
			$pagination_info = isset($board_data['task_pagination'][$status]) ? $board_data['task_pagination'][$status] : array('page' => 1, 'per_page' => 50, 'total' => $total_status_tasks, 'has_more' => false);
			$wip_limit = isset($wip_limits[$status]) ? intval($wip_limits[$status]) : 0;
			$wip_exceeded = ($wip_limit > 0 && $total_status_tasks > $wip_limit);
		?>
		<div class="kanbanz-column<?php echo $wip_exceeded ? ' kanbanz-column--wip-exceeded' : ''; ?>" 
			 data-status="<?php echo esc_attr($status); ?>" 
			 data-order="<?php echo esc_attr($i); ?>"
			 data-page="<?php echo esc_attr($pagination_info['page']); ?>"
			 data-has-more="<?php echo $pagination_info['has_more'] ? 'true' : 'false'; ?>"
			 data-total="<?php echo esc_attr($total_status_tasks); ?>"
			 data-loaded="<?php echo esc_attr($loaded_task_count); ?>">
			<div class="kanbanz-column__header">
				<div class="kanbanz-column__header-left">
					<button type="button" class="kanbanz-column__collapse-btn" title="<?php esc_attr_e('Collapse', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Collapse column', 'kanbanz'); ?>" aria-expanded="true">
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					</button>
					<span class="kanbanz-column__dot" aria-hidden="true"></span>
					<h3 class="kanbanz-column__title"><?php echo esc_html($status); ?></h3>
					<span class="kanbanz-column__count<?php echo $wip_exceeded ? ' kanbanz-column__count--wip-exceeded' : ''; ?>" data-count="<?php echo esc_attr($total_status_tasks); ?>" data-wip-limit="<?php echo esc_attr($wip_limit); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: 1: task count, 2: WIP limit suffix */ __('%1$d tasks%2$s', 'kanbanz'), $total_status_tasks, $wip_limit > 0 ? sprintf(/* translators: %d: WIP limit */ __(', WIP limit %d', 'kanbanz'), $wip_limit) : '')); ?>"><?php echo esc_html($wip_limit > 0 ? $total_status_tasks . '/' . $wip_limit : ($loaded_task_count < $total_status_tasks ? $loaded_task_count . '/' . $total_status_tasks : $total_status_tasks)); ?></span>
				</div>
				<div class="kanbanz-column__header-right">
					<button type="button" class="kanbanz-icon-btn kanbanz-icon-btn--sm kanbanz-column__add" title="<?php esc_attr_e('Add task', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Add task', 'kanbanz'); ?>">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					</button>
					<button type="button" class="kanbanz-icon-btn kanbanz-icon-btn--sm kanbanz-column__menu-trigger" title="<?php esc_attr_e('Column options', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Column options', 'kanbanz'); ?>" aria-haspopup="true" aria-expanded="false" aria-controls="kanbanz-column-menu">
						<span class="dashicons dashicons-ellipsis" aria-hidden="true"></span>
					</button>
				</div>
			</div>
			<div class="kanbanz-column__body" data-status="<?php echo esc_attr($status); ?>">
				<?php if (is_array($status_tasks) && !empty($status_tasks)): foreach ($status_tasks as $task):
					$priority = !empty($task['task_meta']['priority']) ? $task['task_meta']['priority'] : '';
					$due_date = !empty($task['task_meta']['due_date']) ? $task['task_meta']['due_date'] : '';
					$is_overdue = !empty($task['task_meta']['is_overdue']);
					$assigned = !empty($task['task_meta']['assigned']) ? (array)$task['task_meta']['assigned'] : array();
					$task_labels = kanbanz_get_task_labels($task['id'], !empty($labels[$task['id']]) ? (array)$labels[$task['id']] : array());
					$priority_class = '';
					if ($priority === 'urgent') $priority_class = 'kanbanz-card--urgent';
					elseif ($priority === 'high') $priority_class = 'kanbanz-card--high';
					elseif ($priority === 'medium') $priority_class = 'kanbanz-card--medium';
					$overdue_class = $is_overdue ? 'kanbanz-card--overdue' : '';
				?>
				<div class="kanbanz-card <?php echo esc_attr(trim($priority_class . ' ' . $overdue_class)); ?>" data-task-id="<?php echo esc_attr($task['id']); ?>">
					<input type="checkbox" class="kanbanz-card__checkbox" data-task-id="<?php echo esc_attr($task['id']); ?>" aria-label="<?php esc_attr_e('Select task', 'kanbanz'); ?>" />
					<div class="kanbanz-card__labels">
						<?php if (!empty($task_labels)): foreach (array_slice($task_labels, 0, 3) as $lbl): ?>
						<span class="kanbanz-label" style="background:<?php echo esc_attr($lbl['background']); ?>;color:#fff;"><?php echo esc_html($lbl['text']); ?></span>
						<?php endforeach; endif; ?>
					</div>
					<div class="kanbanz-card__title"><?php echo esc_html($task['title']); ?></div>
					<?php if (function_exists('kanbanz_deskuss_render_card_ticket_link')) { kanbanz_deskuss_render_card_ticket_link(!empty($task['task_meta']['ticket_number']) ? $task['task_meta']['ticket_number'] : ''); } ?>
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
				<?php endforeach; endif; ?>
				<div class="kanbanz-column__pagination-footer" style="<?php echo $pagination_info['has_more'] ? 'display:block;' : 'display:none;'; ?>">
					<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-column__load-more-btn" data-status="<?php echo esc_attr($status); ?>">
						<span class="dashicons dashicons-download"></span> <?php esc_html_e('Load More Tasks', 'kanbanz'); ?>
					</button>
					<div class="kanbanz-column__scroll-loader" style="display:none;">
						<span class="dashicons dashicons-update kanbanz-spin"></span> <?php esc_html_e('Loading tasks...', 'kanbanz'); ?>
					</div>
				</div>
			</div>
			<div class="kanbanz-column__add-task" data-status="<?php echo esc_attr($status); ?>">
				<button type="button" class="kanbanz-column__add-btn">
					<span class="dashicons dashicons-plus-alt2"></span>
					<?php esc_html_e('Add task', 'kanbanz'); ?>
				</button>
			</div>
		</div>
		<?php endforeach; ?>

		<?php if ($role === 'admin' || $role === 'manager'): ?>
		<div class="kanbanz-column kanbanz-column--add" id="kanbanz-add-column">
			<button type="button" class="kanbanz-column__add-stage-btn" id="kanbanz-add-stage-btn">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e('Add Stage', 'kanbanz'); ?>
			</button>
		</div>
		<?php endif; ?>
	</div>

	<!-- Task Modal Workspace -->
	<div class="kanbanz-modal-workspace" id="kanbanz-modal-workspace" role="dialog" aria-modal="true" aria-labelledby="kanbanz-modal-task-id" aria-hidden="true">
		<div class="kanbanz-modal-workspace__overlay" id="kanbanz-modal-overlay" tabindex="-1"></div>
		<div class="kanbanz-modal-workspace__container" id="kanbanz-modal-container" role="document" tabindex="-1">
			<div class="kanbanz-modal-workspace__header">
				<div class="kanbanz-modal-workspace__header-left">
					<button type="button" class="kanbanz-icon-btn" id="kanbanz-modal-back" title="<?php esc_attr_e('Back to board', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Back to board', 'kanbanz'); ?>">
						<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
					</button>
					<span class="kanbanz-task-id-badge" id="kanbanz-modal-task-id"></span>
					<span class="kanbanz-task-status-badge" id="kanbanz-modal-status-badge"></span>
				</div>
				<div class="kanbanz-modal-workspace__header-right">
					<button type="button" class="kanbanz-icon-btn" id="kanbanz-modal-move-btn" title="<?php esc_attr_e('Move task', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Move task', 'kanbanz'); ?>" aria-haspopup="menu" aria-expanded="false">
						<span class="dashicons dashicons-move" aria-hidden="true"></span>
					</button>
					<button type="button" class="kanbanz-icon-btn kanbanz-panel-delete-btn" id="kanbanz-modal-delete-btn" title="<?php esc_attr_e('Delete task', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Delete task', 'kanbanz'); ?>" style="color:var(--kanbanz-danger);">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					</button>
					<button type="button" class="kanbanz-icon-btn" id="kanbanz-modal-close" title="<?php esc_attr_e('Close', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Close task panel', 'kanbanz'); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>
			</div>
			<div class="kanbanz-modal-workspace__body">
				<div class="kanbanz-modal-workspace__left" id="kanbanz-modal-left" tabindex="-1">
					<div class="kanbanz-panel__skeleton" aria-hidden="true">
						<div class="kanbanz-skeleton" style="width:60%;"></div>
						<div class="kanbanz-skeleton" style="width:80%;"></div>
						<div class="kanbanz-skeleton" style="width:40%;"></div>
					</div>
				</div>
				<div class="kanbanz-modal-workspace__activity" id="kanbanz-modal-activity" tabindex="-1">
					<div class="kanbanz-panel__skeleton" aria-hidden="true">
						<div class="kanbanz-skeleton" style="width:70%;"></div>
						<div class="kanbanz-skeleton" style="width:90%;"></div>
						<div class="kanbanz-skeleton" style="width:50%;"></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Command Palette -->
	<div class="kanbanz-cmd-palette" id="kanbanz-cmd-palette" role="dialog" aria-modal="true" aria-labelledby="kanbanz-cmd-input" aria-hidden="true" style="display:none;">
		<div class="kanbanz-cmd-palette__overlay" tabindex="-1"></div>
		<div class="kanbanz-cmd-palette__container" role="document" tabindex="-1">
			<div class="kanbanz-cmd-palette__input-wrap">
				<span class="dashicons dashicons-search" aria-hidden="true"></span>
				<label for="kanbanz-cmd-input" class="screen-reader-text"><?php esc_html_e('Search tasks or type a command', 'kanbanz'); ?></label>
				<input type="text" id="kanbanz-cmd-input" placeholder="<?php esc_attr_e('Search tasks or type a command...', 'kanbanz'); ?>" autocomplete="off" />
			</div>
			<div class="kanbanz-cmd-palette__results" id="kanbanz-cmd-results" role="listbox" aria-label="<?php esc_attr_e('Commands and tasks', 'kanbanz'); ?>"></div>
		</div>
	</div>

	<!-- Board Settings Drawer -->
	<?php
	$settings_can_manage = ($role === 'admin' || $role === 'manager');
	$settings_created_on = !empty($board_data['board']['post_date']) ? date_i18n(get_option('date_format'), strtotime($board_data['board']['post_date'])) : '';
	?>
	<div class="kanbanz-settings" id="kanbanz-settings" role="dialog" aria-modal="true" aria-labelledby="kanbanz-settings-title" aria-hidden="true" style="display:none;">
		<div class="kanbanz-settings__overlay" id="kanbanz-settings-overlay" tabindex="-1"></div>
		<div class="kanbanz-settings__drawer" role="document" tabindex="-1">
			<div class="kanbanz-settings__header">
				<div class="kanbanz-settings__heading">
					<h2 id="kanbanz-settings-title"><?php esc_html_e('Board Settings', 'kanbanz'); ?></h2>
					<p class="kanbanz-settings__subtitle"><?php echo esc_html($board_data['board']['title']); ?></p>
				</div>
				<button type="button" class="kanbanz-icon-btn" id="kanbanz-settings-close" aria-label="<?php esc_attr_e('Close settings', 'kanbanz'); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>

			<div class="kanbanz-settings__tabs" role="tablist" aria-label="<?php esc_attr_e('Board settings sections', 'kanbanz'); ?>">
				<button type="button" class="kanbanz-settings__tab kanbanz-settings__tab--active" role="tab" id="kanbanz-settings-tab-settings" aria-controls="kanbanz-settings-body" aria-selected="true" data-tab="settings">
					<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php esc_html_e('Settings', 'kanbanz'); ?>
				</button>
				<button type="button" class="kanbanz-settings__tab" role="tab" id="kanbanz-settings-tab-activity" aria-controls="kanbanz-settings-activity-panel" aria-selected="false" tabindex="-1" data-tab="activity">
					<span class="dashicons dashicons-backup" aria-hidden="true"></span> <?php esc_html_e('Activity', 'kanbanz'); ?>
				</button>
			</div>

			<!-- Settings tab -->
			<div class="kanbanz-settings__body" id="kanbanz-settings-body" role="tabpanel" aria-labelledby="kanbanz-settings-tab-settings">

				<!-- General -->
				<section class="kanbanz-settings__card">
					<h3 class="kanbanz-settings__card-title"><?php esc_html_e('General', 'kanbanz'); ?></h3>
					<dl class="kanbanz-settings__meta">
						<div><dt><?php esc_html_e('Created by', 'kanbanz'); ?></dt><dd id="kanbanz-settings-author"><?php echo esc_html($board_data['board']['post_author']); ?></dd></div>
						<div><dt><?php esc_html_e('Created on', 'kanbanz'); ?></dt><dd id="kanbanz-settings-date"><?php echo esc_html($settings_created_on); ?></dd></div>
					</dl>
					<label class="kanbanz-settings__label" for="kanbanz-settings-desc"><?php esc_html_e('Description', 'kanbanz'); ?></label>
					<textarea id="kanbanz-settings-desc" class="kanbanz-textarea kanbanz-settings__textarea" rows="3" placeholder="<?php esc_attr_e('What is this board for?', 'kanbanz'); ?>"><?php echo esc_textarea($board_data['board']['content']); ?></textarea>
					<div class="kanbanz-settings__actions">
						<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-save-desc"><?php esc_html_e('Save description', 'kanbanz'); ?></button>
					</div>
				</section>

				<?php if ($settings_can_manage && empty($board_data['board']['is_department'])): ?>
				<!-- Team & access -->
				<?php
				$team_members = kanbanz_board_team($board_id);
				$team_open_text = __('Everyone with Kanbanz access can see this board. Add members to make it private to the team.', 'kanbanz');
				$team_private_text = __('Only the team members below, admins and the board owner can see this board. Anyone assigned a task here is added automatically.', 'kanbanz');
				?>
				<section class="kanbanz-settings__card" id="kanbanz-team-section" data-open-text="<?php echo esc_attr($team_open_text); ?>" data-private-text="<?php echo esc_attr($team_private_text); ?>">
					<h3 class="kanbanz-settings__card-title"><?php esc_html_e('Team & access', 'kanbanz'); ?></h3>
					<p class="kanbanz-settings__hint" id="kanbanz-team-status"><?php echo esc_html($team_members ? $team_private_text : $team_open_text); ?></p>
					<div class="kanbanz-team-add">
						<label for="kanbanz-team-add-search" class="screen-reader-text"><?php esc_html_e('Add member', 'kanbanz'); ?></label>
						<span class="dashicons dashicons-search kanbanz-team-add__icon" aria-hidden="true"></span>
						<input type="text" id="kanbanz-team-add-search" class="kanbanz-input" placeholder="<?php esc_attr_e('Add member...', 'kanbanz'); ?>" autocomplete="off" />
						<div class="kanbanz-team-add__results" id="kanbanz-team-add-results" style="display:none;"></div>
					</div>
					<ul class="kanbanz-team-list" id="kanbanz-team-list">
						<?php foreach ($team_members as $member_login):
							$member = get_user_by('login', $member_login);
							$member_name = $member ? $member->display_name : $member_login;
							/* translators: %s: team member's name */
							$remove_label = sprintf(__('Remove %s from the team', 'kanbanz'), $member_name);
						?>
						<li class="kanbanz-team-list__item" data-username="<?php echo esc_attr($member_login); ?>">
							<span class="kanbanz-team-list__name"><?php echo esc_html($member_name); ?> <span class="kanbanz-team-list__login">(<?php echo esc_html($member_login); ?>)</span></span>
							<button type="button" class="kanbanz-icon-btn kanbanz-team-list__remove" aria-label="<?php echo esc_attr($remove_label); ?>" title="<?php echo esc_attr($remove_label); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
						</li>
						<?php endforeach; ?>
					</ul>
				</section>
				<?php endif; ?>

				<!-- Board managers & department -->
				<section class="kanbanz-settings__card">
					<h3 class="kanbanz-settings__card-title"><?php esc_html_e('Board managers', 'kanbanz'); ?></h3>
					<p class="kanbanz-settings__hint"><?php esc_html_e('Managers have full control of this board, including completing tasks.', 'kanbanz'); ?></p>
					<div id="kanbanz-settings-managers-list" class="kanbanz-managers-list">
						<?php
						$board_mgrs = kanbanz_get_board_managers($board_id);
						if (!empty($board_mgrs)):
							foreach ($board_mgrs as $bm):
								$initials = strtoupper(substr($bm['name'] ?: $bm['username'], 0, 2));
						?>
						<div class="kanbanz-manager-badge">
							<div class="kanbanz-manager-badge__avatar"><?php echo esc_html($initials); ?></div>
							<div class="kanbanz-manager-badge__info">
								<span class="kanbanz-manager-badge__name"><?php echo esc_html($bm['name']); ?></span>
								<span class="kanbanz-manager-badge__tag"><?php echo esc_html($bm['badge']); ?></span>
							</div>
						</div>
						<?php endforeach; else: ?>
						<p class="kanbanz-settings__empty"><?php esc_html_e('No managers assigned.', 'kanbanz'); ?></p>
						<?php endif; ?>
					</div>

					<?php if ($role === 'admin'): ?>
					<?php
					$cur_assigned_mgrs = get_post_meta($board_id, 'kanbanz_board_managers', true);
					if (!is_array($cur_assigned_mgrs)) $cur_assigned_mgrs = array();
					$all_staff = kanbanz_get_eligible_assignees($board_id);
					?>
					<span class="kanbanz-settings__label" id="kanbanz-assign-managers-label"><?php esc_html_e('Assign managers', 'kanbanz'); ?></span>
					<div id="kanbanz-assign-managers-wrap" class="kanbanz-settings__checklist" role="group" aria-labelledby="kanbanz-assign-managers-label">
						<?php foreach ($all_staff as $st):
							$is_mgr_checked = in_array($st['username'], $cur_assigned_mgrs, true) || in_array($st['id'], $cur_assigned_mgrs) || in_array((string)$st['id'], $cur_assigned_mgrs, true);
						?>
						<label class="kanbanz-settings__check">
							<input type="checkbox" class="kanbanz-board-manager-checkbox" value="<?php echo esc_attr($st['username']); ?>" data-user-id="<?php echo esc_attr($st['id']); ?>" <?php checked($is_mgr_checked); ?> />
							<span class="kanbanz-settings__check-name"><?php echo esc_html($st['name']); ?> <span class="kanbanz-team-list__login">@<?php echo esc_html($st['username']); ?></span></span>
							<span class="kanbanz-manager-badge__tag"><?php echo esc_html($st['role_label']); ?></span>
						</label>
						<?php endforeach; ?>
					</div>

					<?php if (function_exists('kanbanz_deskuss_active') && kanbanz_deskuss_active()): ?>
					<label class="kanbanz-settings__label" for="kanbanz-settings-dept-select"><?php esc_html_e('Linked department', 'kanbanz'); ?></label>
					<select id="kanbanz-settings-dept-select" class="kanbanz-select kanbanz-settings__select">
						<option value=""><?php esc_html_e('None (independent board)', 'kanbanz'); ?></option>
						<?php
						$all_depts = function_exists('kanbanz_get_all_departments') ? kanbanz_get_all_departments() : array();
						$cur_dept = kanbanz_get_board_dept_id($board_id);
						foreach ($all_depts as $dept):
						?>
						<option value="<?php echo esc_attr($dept->term_id); ?>" <?php selected($cur_dept, $dept->term_id); ?>><?php echo esc_html($dept->name); ?></option>
						<?php endforeach; ?>
					</select>
					<?php endif; ?>

					<div class="kanbanz-settings__actions">
						<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-save-board-managers">
							<?php echo (function_exists('kanbanz_deskuss_active') && kanbanz_deskuss_active()) ? esc_html__('Save managers & department', 'kanbanz') : esc_html__('Save managers', 'kanbanz'); ?>
						</button>
					</div>
					<?php elseif (function_exists('kanbanz_get_board_dept_id') && kanbanz_get_board_dept_id($board_id)): ?>
					<dl class="kanbanz-settings__meta">
						<div><dt><?php esc_html_e('Linked department', 'kanbanz'); ?></dt><dd><?php echo esc_html(function_exists('kanbanz_get_department_name') ? kanbanz_get_department_name(kanbanz_get_board_dept_id($board_id)) : kanbanz_get_board_dept_id($board_id)); ?></dd></div>
					</dl>
					<?php endif; ?>
				</section>

				<?php if ($settings_can_manage): ?>
				<!-- Task completion -->
				<?php
				$cur_comp_mode = get_post_meta($board_id, 'kanbanz_completion_mode', true) ?: 'everyone';
				$cur_allowed_staff = get_post_meta($board_id, 'kanbanz_completion_allowed_users', true);
				if (!is_array($cur_allowed_staff)) $cur_allowed_staff = array();
				?>
				<section class="kanbanz-settings__card">
					<h3 class="kanbanz-settings__card-title"><?php esc_html_e('Task completion', 'kanbanz'); ?></h3>
					<p class="kanbanz-settings__hint"><?php esc_html_e('Choose who may move tasks into the final column.', 'kanbanz'); ?></p>
					<label class="screen-reader-text" for="kanbanz-completion-mode-select"><?php esc_html_e('Who can complete tasks', 'kanbanz'); ?></label>
					<select id="kanbanz-completion-mode-select" class="kanbanz-select kanbanz-settings__select">
						<option value="everyone" <?php selected($cur_comp_mode, 'everyone'); ?>><?php esc_html_e('Everyone on this board', 'kanbanz'); ?></option>
						<option value="managers" <?php selected($cur_comp_mode, 'managers'); ?>><?php esc_html_e('Admins & board managers only', 'kanbanz'); ?></option>
						<option value="custom" <?php selected($cur_comp_mode, 'custom'); ?>><?php esc_html_e('Selected staff only', 'kanbanz'); ?></option>
					</select>

					<div id="kanbanz-custom-completers-wrap" class="kanbanz-settings__checklist" role="group" aria-label="<?php esc_attr_e('Staff allowed to complete tasks', 'kanbanz'); ?>" style="display:<?php echo ($cur_comp_mode === 'custom') ? 'block' : 'none'; ?>;">
						<?php
						$filter_staff = kanbanz_get_users_for_filter($board_id);
						foreach ($filter_staff as $st):
							$is_checked = in_array($st['username'], $cur_allowed_staff, true) || in_array($st['id'], $cur_allowed_staff) || in_array((string)$st['id'], $cur_allowed_staff, true);
						?>
						<label class="kanbanz-settings__check">
							<input type="checkbox" class="kanbanz-completer-checkbox" value="<?php echo esc_attr($st['username']); ?>" data-user-id="<?php echo esc_attr($st['id']); ?>" <?php checked($is_checked); ?> />
							<span class="kanbanz-settings__check-name"><?php echo esc_html($st['name']); ?> <span class="kanbanz-team-list__login">@<?php echo esc_html($st['username']); ?></span></span>
						</label>
						<?php endforeach; ?>
					</div>

					<div class="kanbanz-settings__actions">
						<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-save-completion-settings"><?php esc_html_e('Save', 'kanbanz'); ?></button>
					</div>
				</section>
				<?php endif; ?>

				<?php if ($role === 'admin' && empty($board_data['board']['is_department'])): ?>
				<!-- Danger zone -->
				<section class="kanbanz-settings__card kanbanz-settings__card--danger">
					<h3 class="kanbanz-settings__card-title"><?php esc_html_e('Danger zone', 'kanbanz'); ?></h3>
					<p class="kanbanz-settings__hint"><?php esc_html_e('Deleting a board permanently removes it with all of its tasks.', 'kanbanz'); ?></p>
					<div class="kanbanz-settings__actions">
						<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--danger" id="kanbanz-delete-board-btn">
							<span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e('Delete board', 'kanbanz'); ?>
						</button>
					</div>
				</section>
				<?php endif; ?>
			</div>

			<!-- Activity tab -->
			<div class="kanbanz-settings__body kanbanz-settings__body--activity" id="kanbanz-settings-activity-panel" role="tabpanel" aria-labelledby="kanbanz-settings-tab-activity" hidden>
				<div class="kanbanz-settings__activity-head">
					<p class="kanbanz-settings__hint"><?php esc_html_e('Recent changes to this board, newest first.', 'kanbanz'); ?></p>
					<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--secondary" id="kanbanz-refresh-activity-btn">
						<span class="dashicons dashicons-update" aria-hidden="true"></span> <?php esc_html_e('Refresh', 'kanbanz'); ?>
					</button>
				</div>
				<div id="kanbanz-settings-activity" class="kanbanz-activity-list" aria-live="polite"></div>
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--secondary kanbanz-settings__more" id="kanbanz-activity-more" style="display:none;"><?php esc_html_e('Show more', 'kanbanz'); ?></button>
			</div>
		</div>
	</div>

	<!-- Column Context Menu -->
	<div class="kanbanz-context-menu" id="kanbanz-column-menu" role="menu" aria-label="<?php esc_attr_e('Column options', 'kanbanz'); ?>" style="display:none;">
		<button type="button" class="kanbanz-context-menu__item" data-action="add-task" role="menuitem">
			<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> <?php esc_html_e('Add Task', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item" data-action="sort-date" role="menuitem">
			<span class="dashicons dashicons-sort" aria-hidden="true"></span> <?php esc_html_e('Sort by Date', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item" data-action="sort-priority" role="menuitem">
			<span class="dashicons dashicons-sort" aria-hidden="true"></span> <?php esc_html_e('Sort by Priority', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item" data-action="wip-limit" role="menuitem">
			<span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span> <?php esc_html_e('Set WIP Limit', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item" data-action="rename" role="menuitem">
			<span class="dashicons dashicons-edit" aria-hidden="true"></span> <?php esc_html_e('Rename Column', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item" data-action="select-all" role="menuitem">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e('Select All in Column', 'kanbanz'); ?>
		</button>
		<?php if ($role === 'admin'): ?>
		<button type="button" class="kanbanz-context-menu__item kanbanz-context-menu__item--danger" data-action="delete-column" role="menuitem">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e('Delete Column', 'kanbanz'); ?>
		</button>
		<?php endif; ?>
	</div>

	<!-- Confirm Modal (replaces native confirm/prompt) -->
	<div class="kanbanz-confirm-modal" id="kanbanz-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="kanbanz-confirm-title" aria-describedby="kanbanz-confirm-message" aria-hidden="true" style="display:none;">
		<div class="kanbanz-confirm-modal__overlay" tabindex="-1"></div>
		<div class="kanbanz-confirm-modal__dialog" role="document" tabindex="-1">
			<h3 class="kanbanz-confirm-modal__title" id="kanbanz-confirm-title"><?php esc_html_e('Confirm', 'kanbanz'); ?></h3>
			<p class="kanbanz-confirm-modal__message" id="kanbanz-confirm-message"></p>
			<div class="kanbanz-confirm-modal__input-wrap" id="kanbanz-confirm-input-wrap" style="display:none;">
				<label for="kanbanz-confirm-input" class="screen-reader-text"><?php esc_html_e('Input value', 'kanbanz'); ?></label>
				<input type="text" id="kanbanz-confirm-input" class="kanbanz-input" autocomplete="off" />
			</div>
			<div class="kanbanz-confirm-modal__actions">
				<button type="button" class="kanbanz-btn kanbanz-btn--outline" id="kanbanz-confirm-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
				<button type="button" class="kanbanz-btn kanbanz-btn--danger" id="kanbanz-confirm-ok"><?php esc_html_e('Confirm', 'kanbanz'); ?></button>
			</div>
		</div>
	</div>

	<!-- Keyboard Shortcuts Modal -->
	<div class="kanbanz-shortcuts-modal" id="kanbanz-shortcuts-modal" role="dialog" aria-modal="true" aria-labelledby="kanbanz-shortcuts-title" aria-hidden="true" style="display:none;">
		<div class="kanbanz-shortcuts-modal__overlay" tabindex="-1"></div>
		<div class="kanbanz-shortcuts-modal__dialog" role="document" tabindex="-1">
			<div class="kanbanz-shortcuts-modal__header">
				<h3 id="kanbanz-shortcuts-title"><?php esc_html_e('Keyboard Shortcuts', 'kanbanz'); ?></h3>
				<button type="button" class="kanbanz-icon-btn" id="kanbanz-shortcuts-close" aria-label="<?php esc_attr_e('Close', 'kanbanz'); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>
			<ul class="kanbanz-shortcuts-list" id="kanbanz-shortcuts-list"></ul>
		</div>
	</div>

	<!-- Empty Board State (no columns) -->
	<div class="kanbanz-empty-board" id="kanbanz-empty-board" style="display:none;">
		<div class="kanbanz-empty-board__icon"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span></div>
		<h2 class="kanbanz-empty-board__title"><?php esc_html_e('This board has no stages yet', 'kanbanz'); ?></h2>
		<p class="kanbanz-empty-board__desc"><?php esc_html_e('Stages let you group tasks by status (e.g. To Do, In Progress, Done). Add your first stage to get started.', 'kanbanz'); ?></p>
		<?php if ($role === 'admin' || $role === 'manager'): ?>
		<button type="button" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-empty-add-stage">
			<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
			<?php esc_html_e('Add First Stage', 'kanbanz'); ?>
		</button>
		<?php else: ?>
		<p class="kanbanz-empty-board__hint"><?php esc_html_e('Ask an admin or manager to add stages to this board.', 'kanbanz'); ?></p>
		<?php endif; ?>
	</div>

	<!-- Floating Bulk Selection Bar -->
	<div class="kanbanz-bulk-bar" id="kanbanz-bulk-bar" role="toolbar" aria-label="<?php esc_attr_e('Bulk task actions', 'kanbanz'); ?>" style="display:none;">
		<div class="kanbanz-bulk-bar__info">
			<span class="kanbanz-bulk-bar__count" id="kanbanz-bulk-count">0</span>
			<span class="kanbanz-bulk-bar__label"><?php esc_html_e('selected', 'kanbanz'); ?></span>
		</div>
		<div class="kanbanz-bulk-bar__actions">
			<!-- Move Dropdown -->
			<div class="kanbanz-dropdown kanbanz-bulk-dropdown" id="kanbanz-bulk-move-dropdown">
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-dropdown__trigger" id="kanbanz-bulk-move-btn" title="<?php esc_attr_e('Move selected tasks to a column', 'kanbanz'); ?>">
					<span class="dashicons dashicons-move"></span> <?php esc_html_e('Move', 'kanbanz'); ?>
				</button>
				<div class="kanbanz-dropdown__menu" id="kanbanz-bulk-move-menu" style="display:none;">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Move to Stage', 'kanbanz'); ?></div>
					<?php foreach ($statuses as $st): ?>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="move" data-value="<?php echo esc_attr($st); ?>"><?php echo esc_html($st); ?></button>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Priority Dropdown -->
			<div class="kanbanz-dropdown kanbanz-bulk-dropdown" id="kanbanz-bulk-priority-dropdown">
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-dropdown__trigger" id="kanbanz-bulk-priority-btn" title="<?php esc_attr_e('Set priority on selected tasks', 'kanbanz'); ?>">
					<span class="dashicons dashicons-flag"></span> <?php esc_html_e('Priority', 'kanbanz'); ?>
				</button>
				<div class="kanbanz-dropdown__menu" id="kanbanz-bulk-priority-menu" style="display:none;">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Set Priority', 'kanbanz'); ?></div>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="priority" data-value="urgent"><?php esc_html_e('Urgent', 'kanbanz'); ?></button>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="priority" data-value="high"><?php esc_html_e('High', 'kanbanz'); ?></button>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="priority" data-value="medium"><?php esc_html_e('Medium', 'kanbanz'); ?></button>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="priority" data-value="low"><?php esc_html_e('Low', 'kanbanz'); ?></button>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="priority" data-value="none"><?php esc_html_e('None / Clear', 'kanbanz'); ?></button>
				</div>
			</div>

			<!-- Assign Member Dropdown -->
			<div class="kanbanz-dropdown kanbanz-bulk-dropdown" id="kanbanz-bulk-assign-dropdown">
				<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline kanbanz-dropdown__trigger" id="kanbanz-bulk-assign-btn" title="<?php esc_attr_e('Assign member to selected tasks', 'kanbanz'); ?>">
					<span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Assign', 'kanbanz'); ?>
				</button>
				<div class="kanbanz-dropdown__menu" id="kanbanz-bulk-assign-menu" style="display:none;">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Assign Member', 'kanbanz'); ?></div>
					<?php if (!empty($users_for_filter)): foreach ($users_for_filter as $uf): ?>
					<button type="button" class="kanbanz-dropdown__item kanbanz-bulk-action-item" data-action="assign" data-value="<?php echo esc_attr($uf['username']); ?>"><?php echo esc_html($uf['name'] . ' (@' . $uf['username'] . ')'); ?></button>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<?php if ($role === 'admin' || $role === 'manager'): ?>
			<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--danger" id="kanbanz-bulk-delete-btn" title="<?php esc_attr_e('Delete selected tasks', 'kanbanz'); ?>">
				<span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete', 'kanbanz'); ?>
			</button>
			<?php endif; ?>

			<button type="button" class="kanbanz-icon-btn kanbanz-icon-btn--sm" id="kanbanz-bulk-clear-btn" title="<?php esc_attr_e('Clear selection (Esc)', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Clear selection', 'kanbanz'); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
	</div>

	<!-- Toast Container -->
	<div class="kanbanz-toast-container" id="kanbanz-toast-container" role="region" aria-label="<?php esc_attr_e('Notifications', 'kanbanz'); ?>" aria-live="polite"></div>
</div>