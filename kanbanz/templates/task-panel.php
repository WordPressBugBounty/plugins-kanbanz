<?php if (!defined('ABSPATH')) exit;
// Task detail panel - rendered via AJAX, this is the HTML structure template
// The panel is populated dynamically by JavaScript using data from AJAX responses
?>
<div class="kanbanz-panel-content" id="kanbanz-task-panel-content">
	<!-- Task Metadata Bar -->
	<div class="kanbanz-task-meta-bar">
		<div class="kanbanz-task-meta-bar__left">
			<span class="kanbanz-task-priority-badge kanbanz-task-priority-badge--{{priority_class}}" id="kanbanz-task-priority">
				{{priority_label}}
			</span>
			<span class="kanbanz-task-due" id="kanbanz-task-due">
				<span class="dashicons dashicons-calendar-alt"></span>
				<span class="kanbanz-task-due__text">{{due_date}}</span>
			</span>
		</div>
		<div class="kanbanz-task-meta-bar__right">
			<span class="kanbanz-task-id" id="kanbanz-task-id-label">#TASK-{{task_id}}</span>
		</div>
	</div>

	<!-- Description -->
	<div class="kanbanz-task-section kanbanz-task-description">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Description', 'kanbanz'); ?></h4>
		<div class="kanbanz-task-description__content" id="kanbanz-task-description" data-placeholder="<?php esc_attr_e('Add a description...', 'kanbanz'); ?>">
			{{description}}
		</div>
	</div>

	<!-- Assignees -->
	<div class="kanbanz-task-section kanbanz-task-assignees">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Assignees', 'kanbanz'); ?></h4>
		<div class="kanbanz-task-assignees__list" id="kanbanz-task-assignees">
			{{assignee_chips}}
		</div>
		<div class="kanbanz-task-assignees__add" id="kanbanz-task-assignees-add">
			<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline" id="kanbanz-task-add-assignee">
				<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Assign', 'kanbanz'); ?>
			</button>
			<div class="kanbanz-dropdown" id="kanbanz-assignee-dropdown" style="display:none;">
				<div class="kanbanz-dropdown__menu">
					<div class="kanbanz-dropdown__header"><?php esc_html_e('Select member', 'kanbanz'); ?></div>
					<div id="kanbanz-assignee-list">{{assignee_options}}</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Priority -->
	<div class="kanbanz-task-section kanbanz-task-priority-section">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Priority', 'kanbanz'); ?></h4>
		<div class="kanbanz-priority-chips" id="kanbanz-task-priority-chips">
			<button type="button" class="kanbanz-priority-chip kanbanz-priority-chip--urgent" data-priority="urgent"><?php esc_html_e('Urgent', 'kanbanz'); ?></button>
			<button type="button" class="kanbanz-priority-chip kanbanz-priority-chip--high" data-priority="high"><?php esc_html_e('High', 'kanbanz'); ?></button>
			<button type="button" class="kanbanz-priority-chip kanbanz-priority-chip--medium" data-priority="medium"><?php esc_html_e('Medium', 'kanbanz'); ?></button>
			<button type="button" class="kanbanz-priority-chip kanbanz-priority-chip--low" data-priority="low"><?php esc_html_e('Low', 'kanbanz'); ?></button>
		</div>
	</div>

	<!-- Due Date -->
	<div class="kanbanz-task-section kanbanz-task-due-section">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Due Date', 'kanbanz'); ?></h4>
		<input type="date" class="kanbanz-input" id="kanbanz-task-due-input" value="{{due_date_raw}}" />
	</div>

	<!-- Labels -->
	<div class="kanbanz-task-section kanbanz-task-labels-section">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Labels', 'kanbanz'); ?></h4>
		<div class="kanbanz-task-labels__list" id="kanbanz-task-labels">
			{{label_chips}}
		</div>
		<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--outline" id="kanbanz-task-add-label">
			<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e('Add Label', 'kanbanz'); ?>
		</button>
	</div>

	<!-- Subtasks -->
	<div class="kanbanz-task-section kanbanz-task-subtasks">
		<h4 class="kanbanz-task-section__title">
			<?php esc_html_e('Subtasks', 'kanbanz'); ?>
			<span class="kanbanz-task-section__count" id="kanbanz-subtask-count">0/0</span>
		</h4>
		<div class="kanbanz-subtask-list" id="kanbanz-subtask-list">
			{{subtask_items}}
		</div>
		<div class="kanbanz-subtask-add">
			<input type="text" id="kanbanz-subtask-input" class="kanbanz-input" placeholder="<?php esc_attr_e('Add a subtask...', 'kanbanz'); ?>" />
		</div>
	</div>

	<!-- Attachments -->
	<div class="kanbanz-task-section kanbanz-task-attachments">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Attachments', 'kanbanz'); ?></h4>
		<div class="kanbanz-attachment-list" id="kanbanz-panel-attachment-list">
			{{attachment_items}}
		</div>
		<div class="kanbanz-attachment-dropzone" id="kanbanz-attachment-dropzone" role="button" tabindex="0" aria-label="<?php esc_attr_e('Drop files here or click to upload', 'kanbanz'); ?>">
			<span class="dashicons dashicons-upload kanbanz-attachment-dropzone__icon" aria-hidden="true"></span>
			<div class="kanbanz-attachment-dropzone__text"><strong><?php esc_html_e('Click to upload', 'kanbanz'); ?></strong> <?php esc_html_e('or drag and drop files here', 'kanbanz'); ?></div>
			<div class="kanbanz-attachment-dropzone__hint"><?php esc_html_e('Supports images (paste via Ctrl+V), PDFs, documents, archives', 'kanbanz'); ?></div>
		</div>
		<input type="file" id="kanbanz-panel-file-input" multiple style="display:none;" />
	</div>

	<!-- Activity -->
	<div class="kanbanz-task-section kanbanz-task-activity">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Activity', 'kanbanz'); ?></h4>
		<div class="kanbanz-comment-input">
			<div class="kanbanz-comment-input__avatar">
				{{current_user_initials}}
			</div>
			<textarea id="kanbanz-comment-input" class="kanbanz-textarea" placeholder="<?php esc_attr_e('Add a comment...', 'kanbanz'); ?>" rows="2"></textarea>
		</div>
		<button type="button" class="kanbanz-btn kanbanz-btn--sm kanbanz-btn--primary" id="kanbanz-submit-comment" style="display:none;"><?php esc_html_e('Comment', 'kanbanz'); ?></button>
		<div class="kanbanz-activity-feed" id="kanbanz-activity-feed">
			{{activity_items}}
		</div>
	</div>

	<!-- Quick Actions -->
	<div class="kanbanz-task-section kanbanz-task-quick-actions">
		<h4 class="kanbanz-task-section__title"><?php esc_html_e('Actions', 'kanbanz'); ?></h4>
		<div class="kanbanz-quick-actions">
			<div class="kanbanz-quick-action" id="kanbanz-task-move">
				<span class="dashicons dashicons-move"></span>
				<?php esc_html_e('Move to...', 'kanbanz'); ?>
				<select id="kanbanz-task-move-select" class="kanbanz-select">
					{{move_options}}
				</select>
			</div>
			<button type="button" class="kanbanz-btn kanbanz-btn--danger kanbanz-btn--sm" id="kanbanz-task-delete">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e('Delete Task', 'kanbanz'); ?>
			</button>
		</div>
	</div>
</div>