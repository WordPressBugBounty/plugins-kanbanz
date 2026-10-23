<?php if (!defined('ABSPATH')) exit; ?>
<div class="kanbanz-wrapper" id="kanbanz-app">
	<div class="kanbanz-listing">
		<!-- Header -->
		<div class="kanbanz-listing__header">
			<div class="kanbanz-listing__header-left">
				<h1 class="kanbanz-listing__title"><?php esc_html_e('Kanban Boards', 'kanbanz'); ?></h1>
				<span class="kanbanz-listing__count" id="kanbanz-board-count">0</span>
			</div>
			<div class="kanbanz-listing__header-right">
				<div class="kanbanz-listing__search">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<label for="kanbanz-board-search" class="screen-reader-text"><?php esc_html_e('Search boards', 'kanbanz'); ?></label>
					<input type="text" id="kanbanz-board-search" placeholder="<?php esc_attr_e('Search boards...', 'kanbanz'); ?>" />
				</div>
				<button type="button" class="kanbanz-icon-btn" data-kanbanz-theme-toggle title="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-pressed="false">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
				</button>
				<?php if (current_user_can('manage_options')): ?>
				<?php kanbanz_deskuss_render_sync_button(); ?>
				<button type="button" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-create-board-btn">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e('New Board', 'kanbanz'); ?>
				</button>
				<?php endif; ?>
			</div>
		</div>

		<!-- Board Grid -->
		<div id="kanbanz-board-sections">
		<?php
		global $wpdb;

		$accessible_ids = kanbanz_get_user_accessible_boards();
		$role = kanbanz_get_user_role();
		$user_id = get_current_user_id();

		// Query department names
		$depts = kanbanz_get_all_departments();
		$dept_names = [];
		if (!empty($depts)) {
			foreach ($depts as $dept) {
				$dept_names[$dept->term_id] = $dept->name;
			}
		}

		// Separate boards into department and custom
		$dept_boards = [];
		$custom_boards = [];
		$deskuss_active = kanbanz_deskuss_active();

		if (!empty($accessible_ids)) {
			$board_query = new WP_Query(array(
				'post_type'      => 'kanbanz_board',
				'post_status'    => array('publish', 'draft', 'pending'),
				'posts_per_page' => apply_filters('kanbanz_boards_per_page', 100),
				'post__in'       => $accessible_ids,
				'orderby'        => 'date',
				'order'          => 'DESC',
			));
			if ($board_query->have_posts()) {
				while ($board_query->have_posts()) {
					$board_query->the_post();
					$board_id = get_the_ID();
					$dept_link = kanbanz_get_board_dept_id($board_id);
					$is_dept = get_post_meta($board_id, 'kanbanz_is_department', true);
					$board_data = array(
						'board_id' => $board_id,
						'title'    => get_the_title(),
						'content'  => get_the_content(),
						'is_dept'  => !empty($dept_link) ? true : false,
						'dept_id'  => !empty($dept_link) ? intval($dept_link) : 0,
						'dept_name' => !empty($dept_link) && isset($dept_names[intval($dept_link)]) ? $dept_names[intval($dept_link)] : '',
					);
					if (!empty($dept_link) && $is_dept) {
						$dept_boards[] = $board_data;
					} else {
						$custom_boards[] = $board_data;
					}
				}
			}
			wp_reset_postdata();
		}

		$total_count = count($dept_boards) + count($custom_boards);

		// Department Boards Section (only shown when deskuss is active)
		if (!empty($dept_boards) && $deskuss_active):
		?>
		<div class="kanbanz-listing__section">
			<h2 class="kanbanz-listing__section-header">
				<span class="dashicons dashicons-building"></span>
				<?php esc_html_e('Department Boards', 'kanbanz'); ?>
				<span class="kanbanz-listing__section-count"><?php echo esc_html(count($dept_boards)); ?></span>
			</h2>
			<div class="kanbanz-listing__grid">
			<?php foreach ($dept_boards as $board):
				$board_id = $board['board_id'];
				$statuses = get_post_meta($board_id, 'board_statuses', true);
				$status_count = is_array($statuses) ? count($statuses) : 0;
				$total_tasks = 0;
				$task_counts = array();

				$task_args = array(
					'post_type' => 'kanbanz_task',
					'post_parent' => $board_id,
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'fields' => 'ids',
				);
				$task_query = new WP_Query($task_args);
				$total_tasks = $task_query->found_posts;
				wp_reset_postdata();

				if (is_array($statuses)) {
					foreach ($statuses as $status) {
						$status_task_args = array(
							'post_type' => 'kanbanz_task',
							'post_parent' => $board_id,
							'post_status' => 'publish',
							'meta_key' => 'task_status',
							'meta_value' => $status,
							'posts_per_page' => -1,
							'fields' => 'ids',
						);
						$status_query = new WP_Query($status_task_args);
						$task_counts[$status] = $status_query->found_posts;
						wp_reset_postdata();
					}
				}

				$board_url = admin_url('admin.php?page=kanbanz&board_id=' . $board_id);
			?>
			<a href="<?php echo esc_url($board_url); ?>" class="kanbanz-board-card" data-board-id="<?php echo esc_attr($board_id); ?>" data-is-dept="1">
				<div class="kanbanz-board-card__header">
					<h3 class="kanbanz-board-card__title"><?php echo esc_html($board['title']); ?></h3>
					<?php if (!empty($board['dept_name'])): ?>
					<span class="kanbanz-board-card__dept-badge"><?php echo esc_html($board['dept_name']); ?></span>
					<?php endif; ?>
					<?php if (current_user_can('manage_options')): ?>
					<button type="button" class="kanbanz-board-card__menu-btn" data-board-id="<?php echo esc_attr($board_id); ?>" data-is-dept="1" title="<?php esc_attr_e('More options', 'kanbanz'); ?>">
						<span class="dashicons dashicons-ellipsis"></span>
					</button>
					<?php endif; ?>
				</div>
				<?php if (!empty($board['content'])): ?>
				<p class="kanbanz-board-card__desc"><?php echo esc_html(wp_trim_words($board['content'], 15)); ?></p>
				<?php endif; ?>
				<div class="kanbanz-board-card__meta">
					<span class="kanbanz-board-card__stat">
						<span class="dashicons dashicons-category"></span>
						<?php echo esc_html($status_count); ?> <?php esc_html_e('stages', 'kanbanz'); ?>
					</span>
					<span class="kanbanz-board-card__stat">
						<span class="dashicons dashicons-list-view"></span>
						<?php echo esc_html($total_tasks); ?> <?php esc_html_e('tasks', 'kanbanz'); ?>
					</span>
				</div>
				<?php if (is_array($statuses) && !empty($statuses)): ?>
				<div class="kanbanz-board-card__progress">
					<?php if ($total_tasks < 1): ?>
					<span class="kanbanz-board-card__empty-hint"><?php esc_html_e('No tasks yet', 'kanbanz'); ?></span>
					<?php else: ?>
					<?php foreach ($statuses as $status): $count = isset($task_counts[$status]) ? $task_counts[$status] : 0; $pct = round(($count / $total_tasks) * 100); ?>
					<div class="kanbanz-board-card__bar" style="width:<?php echo esc_attr($pct); ?>%" title="<?php echo esc_attr($status . ': ' . $count); ?>"></div>
					<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="kanbanz-board-card__footer">
					<span class="kanbanz-board-card__date"><?php echo esc_html(get_the_date('M j, Y', $board_id)); ?></span>
					<?php if ($role === 'admin'): ?>
					<span class="kanbanz-board-card__author"><?php echo esc_html(get_the_author_meta('display_name', get_post_field('post_author', $board_id))); ?></span>
					<?php endif; ?>
				</div>
			</a>
			<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<!-- Custom Boards Section -->
		<?php if (!empty($custom_boards)): ?>
		<div class="kanbanz-listing__section">
			<h2 class="kanbanz-listing__section-header">
				<span class="dashicons dashicons-layout"></span>
				<?php esc_html_e('Custom Boards', 'kanbanz'); ?>
				<span class="kanbanz-listing__section-count"><?php echo esc_html(count($custom_boards)); ?></span>
			</h2>
			<div class="kanbanz-listing__grid">
			<?php foreach ($custom_boards as $board):
				$board_id = $board['board_id'];
				$statuses = get_post_meta($board_id, 'board_statuses', true);
				$status_count = is_array($statuses) ? count($statuses) : 0;
				$total_tasks = 0;
				$task_counts = array();

				$task_args = array(
					'post_type' => 'kanbanz_task',
					'post_parent' => $board_id,
					'post_status' => 'publish',
					'posts_per_page' => -1,
					'fields' => 'ids',
				);
				$task_query = new WP_Query($task_args);
				$total_tasks = $task_query->found_posts;
				wp_reset_postdata();

				if (is_array($statuses)) {
					foreach ($statuses as $status) {
						$status_task_args = array(
							'post_type' => 'kanbanz_task',
							'post_parent' => $board_id,
							'post_status' => 'publish',
							'meta_key' => 'task_status',
							'meta_value' => $status,
							'posts_per_page' => -1,
							'fields' => 'ids',
						);
						$status_query = new WP_Query($status_task_args);
						$task_counts[$status] = $status_query->found_posts;
						wp_reset_postdata();
					}
				}

				$board_url = admin_url('admin.php?page=kanbanz&board_id=' . $board_id);
			?>
			<a href="<?php echo esc_url($board_url); ?>" class="kanbanz-board-card" data-board-id="<?php echo esc_attr($board_id); ?>" data-is-dept="0">
				<div class="kanbanz-board-card__header">
					<h3 class="kanbanz-board-card__title"><?php echo esc_html($board['title']); ?></h3>
					<?php if (current_user_can('manage_options')): ?>
					<button type="button" class="kanbanz-board-card__menu-btn" data-board-id="<?php echo esc_attr($board_id); ?>" data-is-dept="0" title="<?php esc_attr_e('More options', 'kanbanz'); ?>">
						<span class="dashicons dashicons-ellipsis"></span>
					</button>
					<?php endif; ?>
				</div>
				<?php if (!empty($board['content'])): ?>
				<p class="kanbanz-board-card__desc"><?php echo esc_html(wp_trim_words($board['content'], 15)); ?></p>
				<?php endif; ?>
				<div class="kanbanz-board-card__meta">
					<span class="kanbanz-board-card__stat">
						<span class="dashicons dashicons-category"></span>
						<?php echo esc_html($status_count); ?> <?php esc_html_e('stages', 'kanbanz'); ?>
					</span>
					<span class="kanbanz-board-card__stat">
						<span class="dashicons dashicons-list-view"></span>
						<?php echo esc_html($total_tasks); ?> <?php esc_html_e('tasks', 'kanbanz'); ?>
					</span>
				</div>
				<?php if (is_array($statuses) && !empty($statuses)): ?>
				<div class="kanbanz-board-card__progress">
					<?php if ($total_tasks < 1): ?>
					<span class="kanbanz-board-card__empty-hint"><?php esc_html_e('No tasks yet', 'kanbanz'); ?></span>
					<?php else: ?>
					<?php foreach ($statuses as $status): $count = isset($task_counts[$status]) ? $task_counts[$status] : 0; $pct = round(($count / $total_tasks) * 100); ?>
					<div class="kanbanz-board-card__bar" style="width:<?php echo esc_attr($pct); ?>%" title="<?php echo esc_attr($status . ': ' . $count); ?>"></div>
					<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="kanbanz-board-card__footer">
					<span class="kanbanz-board-card__date"><?php echo esc_html(get_the_date('M j, Y', $board_id)); ?></span>
					<?php if ($role === 'admin'): ?>
					<span class="kanbanz-board-card__author"><?php echo esc_html(get_the_author_meta('display_name', get_post_field('post_author', $board_id))); ?></span>
					<?php endif; ?>
				</div>
			</a>
			<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php if (empty($dept_boards) && empty($custom_boards)): ?>
		<div class="kanbanz-listing__empty">
			<span class="dashicons dashicons-screenoptions"></span>
			<h3><?php esc_html_e('No boards yet', 'kanbanz'); ?></h3>
			<p><?php esc_html_e('Create your first kanban board to get started.', 'kanbanz'); ?></p>
			<?php if (current_user_can('manage_options')): ?>
			<button type="button" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-create-board-empty">
				<span class="dashicons dashicons-plus-alt2"></span>
				<?php esc_html_e('Create Board', 'kanbanz'); ?>
			</button>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		</div>
	</div>

	<!-- Create Board Modal -->
	<div class="kanbanz-modal-overlay" id="kanbanz-create-modal" style="display:none;">
		<div class="kanbanz-modal">
			<div class="kanbanz-modal__header">
				<h2><?php esc_html_e('Create New Board', 'kanbanz'); ?></h2>
				<button type="button" class="kanbanz-modal__close" id="kanbanz-create-modal-close">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<form id="kanbanz-create-board-form" class="kanbanz-modal__body">
				<div class="kanbanz-field">
					<label for="kanbanz-board-name"><?php esc_html_e('Board Name', 'kanbanz'); ?> <span class="kanbanz-required">*</span></label>
					<input type="text" id="kanbanz-board-name" name="title" required placeholder="<?php esc_attr_e('e.g., Sprint Board, Product Roadmap', 'kanbanz'); ?>" />
				</div>
				<div class="kanbanz-field">
					<label for="kanbanz-board-desc"><?php esc_html_e('Description', 'kanbanz'); ?></label>
					<textarea id="kanbanz-board-desc" name="description" rows="3" placeholder="<?php esc_attr_e('Briefly describe the purpose of this board...', 'kanbanz'); ?>"></textarea>
				</div>
				<div class="kanbanz-field">
					<label><?php esc_html_e('Default Stages', 'kanbanz'); ?></label>
					<div class="kanbanz-stages-preview">
						<span class="kanbanz-stage-tag"><?php esc_html_e('To Do', 'kanbanz'); ?></span>
						<span class="kanbanz-stage-tag"><?php esc_html_e('In Progress', 'kanbanz'); ?></span>
						<span class="kanbanz-stage-tag"><?php esc_html_e('Under Review', 'kanbanz'); ?></span>
						<span class="kanbanz-stage-tag"><?php esc_html_e('Completed', 'kanbanz'); ?></span>
					</div>
					<p class="kanbanz-field__hint"><?php esc_html_e('You can customize stages after creating the board.', 'kanbanz'); ?></p>
				</div>
				<?php kanbanz_render_team_picker('kanbanz-create-team-picker'); ?>
				<?php kanbanz_deskuss_render_dept_field(); ?>
				<div class="kanbanz-modal__actions">
					<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-create-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
					<button type="submit" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-create-submit">
						<span class="kanbanz-spinner" style="display:none;"></span>
						<?php esc_html_e('Create Board', 'kanbanz'); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- Board Card Context Menu -->
	<div class="kanbanz-context-menu" id="kanbanz-board-context-menu" style="display:none;">
		<button type="button" class="kanbanz-context-menu__item" data-action="open">
			<span class="dashicons dashicons-external-links-alt"></span> <?php esc_html_e('Open Board', 'kanbanz'); ?>
		</button>
		<button type="button" class="kanbanz-context-menu__item kanbanz-context-menu__item--danger kanbanz-context-menu__item--dept-hide" data-action="delete" style="display:none;">
			<span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete Board', 'kanbanz'); ?>
		</button>
	</div>

	<!-- Delete Confirm Modal -->
	<div class="kanbanz-modal-overlay" id="kanbanz-delete-modal" style="display:none;">
		<div class="kanbanz-modal kanbanz-modal--small">
			<div class="kanbanz-modal__header">
				<h2><?php esc_html_e('Delete Board', 'kanbanz'); ?></h2>
				<button type="button" class="kanbanz-modal__close" id="kanbanz-delete-modal-close">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
			<div class="kanbanz-modal__body">
				<p><?php esc_html_e('Are you sure you want to delete this board? All tasks and stages will be permanently removed. This action cannot be undone.', 'kanbanz'); ?></p>
				<div class="kanbanz-modal__actions">
					<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-delete-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
					<button type="button" class="kanbanz-btn kanbanz-btn--danger" id="kanbanz-delete-confirm"><?php esc_html_e('Delete Board', 'kanbanz'); ?></button>
				</div>
			</div>
		</div>
	</div>

	<!-- Toast Container -->
	<div class="kanbanz-toast-container" id="kanbanz-toast-container" role="region" aria-label="<?php esc_attr_e('Notifications', 'kanbanz'); ?>" aria-live="polite"></div>

	<!-- Confirm Modal (replaces native confirm) -->
	<div class="kanbanz-confirm-modal" id="kanbanz-confirm-modal" role="alertdialog" aria-modal="true" aria-labelledby="kanbanz-confirm-title" aria-describedby="kanbanz-confirm-message" aria-hidden="true" style="display:none;">
		<div class="kanbanz-confirm-modal__overlay" tabindex="-1"></div>
		<div class="kanbanz-confirm-modal__dialog" role="document" tabindex="-1">
			<h3 class="kanbanz-confirm-modal__title" id="kanbanz-confirm-title"><?php esc_html_e('Confirm', 'kanbanz'); ?></h3>
			<p class="kanbanz-confirm-modal__message" id="kanbanz-confirm-message"></p>
			<div class="kanbanz-confirm-modal__actions">
				<button type="button" class="kanbanz-btn kanbanz-btn--outline" id="kanbanz-confirm-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
				<button type="button" class="kanbanz-btn kanbanz-btn--danger" id="kanbanz-confirm-ok"><?php esc_html_e('Confirm', 'kanbanz'); ?></button>
			</div>
		</div>
	</div>
</div>

