<?php if(!defined('ABSPATH')) exit;
$templates = function_exists('kanbanz_get_board_templates') ? kanbanz_get_board_templates() : array();
?>
<div class="kanbanz-wrapper kanbanz-page-wrap" id="kanbanz-app">
	<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:20px;">
		<h1 style="margin:0;display:flex;align-items:center;gap:8px;color:var(--kanbanz-text-primary);"><span class="dashicons dashicons-index-card" aria-hidden="true"></span> <?php esc_html_e('Add New Board', 'kanbanz'); ?></h1>
		<button type="button" class="kanbanz-icon-btn" data-kanbanz-theme-toggle title="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-label="<?php esc_attr_e('Toggle theme', 'kanbanz'); ?>" aria-pressed="false">
			<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
		</button>
	</div>

	<!-- Templates grid (default view) -->
	<div id="kanbanz-templates-grid-section">
		<p style="color:var(--kanbanz-text-secondary);margin-bottom:24px;font-size:14px;"><?php esc_html_e('Start from scratch or choose a pre-configured workflow template to launch your board in seconds.', 'kanbanz'); ?></p>

		<?php
		// A representative icon per template so cards are scannable at a
		// glance instead of relying on the color bar alone; keyed here
		// rather than in kanbanz_get_board_templates() since it's a purely
		// presentational concern of this page, not template data.
		$template_icons = array(
			'customer_support' => 'dashicons-sos',
			'software_dev'     => 'dashicons-editor-code',
			'marketing'        => 'dashicons-megaphone',
			'hr_onboarding'    => 'dashicons-groups',
			'blank'            => 'dashicons-media-default',
		);
		?>
		<div class="kanbanz-templates-grid" style="display:grid;grid-template-columns:repeat(auto-fill, minmax(280px, 1fr));gap:20px;">
			<div class="kanbanz-card kanbanz-template-card kanbanz-template-card--custom" id="kanbanz-create-new-card" style="padding:0;border:2px dashed var(--kanbanz-accent);border-radius:var(--kanbanz-radius-lg);overflow:hidden;cursor:pointer;display:flex;flex-direction:column;justify-content:space-between;">
				<div style="height:8px;background:var(--kanbanz-accent);"></div>
				<div style="padding:20px;flex:1;">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true" style="font-size:22px;width:22px;height:22px;color:var(--kanbanz-accent);margin-bottom:8px;display:block;"></span>
					<h3 style="margin:0 0 8px 0;font-size:18px;color:var(--kanbanz-text-primary);"><?php esc_html_e('Create New', 'kanbanz'); ?></h3>
					<p style="color:var(--kanbanz-text-secondary);font-size:13px;line-height:1.5;margin:0;"><?php esc_html_e('Start from scratch with full control over the name, description, and stages.', 'kanbanz'); ?></p>
				</div>
				<div style="padding:12px 20px;background:var(--kanbanz-surface-1);border-top:1px solid var(--kanbanz-border-light);text-align:right;">
					<button type="button" class="kanbanz-btn kanbanz-btn--primary kanbanz-btn--sm" id="kanbanz-create-new-btn">
						<?php esc_html_e('Create New', 'kanbanz'); ?> →
					</button>
				</div>
			</div>

			<?php foreach ($templates as $key => $tmpl):
				$icon = isset($template_icons[$key]) ? $template_icons[$key] : 'dashicons-layout';
			?>
			<div class="kanbanz-card kanbanz-template-card" style="padding:0;border-radius:var(--kanbanz-radius-lg);overflow:hidden;cursor:default;display:flex;flex-direction:column;justify-content:space-between;">
				<div style="height:8px;background:<?php echo esc_attr($tmpl['color']); ?>;"></div>
				<div style="padding:20px;flex:1;">
					<span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true" style="font-size:22px;width:22px;height:22px;color:<?php echo esc_attr($tmpl['color']); ?>;margin-bottom:8px;display:block;"></span>
					<h3 style="margin:0 0 8px 0;font-size:18px;color:var(--kanbanz-text-primary);"><?php echo esc_html($tmpl['name']); ?></h3>
					<p style="color:var(--kanbanz-text-secondary);font-size:13px;line-height:1.5;margin-bottom:16px;"><?php echo esc_html($tmpl['description']); ?></p>

					<div style="margin-bottom:16px;">
						<div style="font-size:11px;font-weight:600;color:var(--kanbanz-text-tertiary);text-transform:uppercase;margin-bottom:6px;"><?php esc_html_e('Stages:', 'kanbanz'); ?></div>
						<div style="display:flex;flex-wrap:wrap;gap:4px;">
							<?php foreach ($tmpl['statuses'] as $st): ?>
							<span class="kanbanz-chip" style="cursor:default;"><?php echo esc_html($st); ?></span>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<div style="padding:12px 20px;background:var(--kanbanz-surface-1);border-top:1px solid var(--kanbanz-border-light);text-align:right;">
					<button type="button" class="kanbanz-btn kanbanz-btn--primary kanbanz-btn--sm kanbanz-use-template-btn" data-template-key="<?php echo esc_attr($key); ?>" data-template-name="<?php echo esc_attr($tmpl['name']); ?>" data-template-stages="<?php echo esc_attr(wp_json_encode(array_values($tmpl['statuses']))); ?>">
						<?php esc_html_e('Use Template', 'kanbanz'); ?> →
					</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>

	<!-- Custom "Create New" form (hidden until the Create New card is clicked) -->
	<div id="kanbanz-custom-board-section" style="display:none;">
		<button type="button" class="kanbanz-btn kanbanz-btn--secondary kanbanz-btn--sm" id="kanbanz-back-to-templates" style="margin-bottom:16px;">
			<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
			<?php esc_html_e('Back to Templates', 'kanbanz'); ?>
		</button>

		<div class="kanbanz-card" style="max-width:640px;padding:24px;cursor:default;">
			<form id="kanbanz-new-board-form">
				<div class="kanbanz-field">
					<label for="kanbanz-new-board-name"><?php esc_html_e('Board Name', 'kanbanz'); ?> <span class="kanbanz-required" aria-hidden="true">*</span></label>
					<input type="text" id="kanbanz-new-board-name" name="title" required class="kanbanz-input" aria-required="true" placeholder="<?php esc_attr_e('e.g., Sprint Planning, Product Roadmap', 'kanbanz'); ?>" />
				</div>
				<div class="kanbanz-field">
					<label for="kanbanz-new-board-desc"><?php esc_html_e('Description', 'kanbanz'); ?></label>
					<textarea id="kanbanz-new-board-desc" name="description" class="kanbanz-textarea" rows="4" placeholder="<?php esc_attr_e('Describe the purpose of this board...', 'kanbanz'); ?>"></textarea>
				</div>
				<div class="kanbanz-field">
					<span aria-hidden="true"><?php esc_html_e('Default Stages', 'kanbanz'); ?></span>
					<div class="kanbanz-stages-editor" id="kanbanz-stages-editor" role="group" aria-label="<?php esc_attr_e('Board stages editor', 'kanbanz'); ?>">
						<div class="kanbanz-stage-tag" data-stage="To Do"><?php esc_html_e('To Do', 'kanbanz'); ?> <button type="button" class="kanbanz-stage-tag__remove" aria-label="<?php esc_attr_e('Remove stage: To Do', 'kanbanz'); ?>">&times;</button></div>
						<div class="kanbanz-stage-tag" data-stage="In Progress"><?php esc_html_e('In Progress', 'kanbanz'); ?> <button type="button" class="kanbanz-stage-tag__remove" aria-label="<?php esc_attr_e('Remove stage: In Progress', 'kanbanz'); ?>">&times;</button></div>
						<div class="kanbanz-stage-tag" data-stage="Under Review"><?php esc_html_e('Under Review', 'kanbanz'); ?> <button type="button" class="kanbanz-stage-tag__remove" aria-label="<?php esc_attr_e('Remove stage: Under Review', 'kanbanz'); ?>">&times;</button></div>
						<div class="kanbanz-stage-tag" data-stage="Completed"><?php esc_html_e('Completed', 'kanbanz'); ?> <button type="button" class="kanbanz-stage-tag__remove" aria-label="<?php esc_attr_e('Remove stage: Completed', 'kanbanz'); ?>">&times;</button></div>
						<div class="kanbanz-stage-tag kanbanz-stage-tag--add" id="kanbanz-add-stage-tag">
							<label for="kanbanz-new-stage-input" class="screen-reader-text"><?php esc_html_e('Add stage', 'kanbanz'); ?></label>
							<input type="text" id="kanbanz-new-stage-input" placeholder="<?php esc_attr_e('Add stage...', 'kanbanz'); ?>" />
						</div>
					</div>
				</div>
				<?php kanbanz_render_team_picker('kanbanz-new-board-team-picker'); ?>
				<?php kanbanz_deskuss_render_new_board_dept_field(); ?>
				<div class="kanbanz-form-actions">
					<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-custom-board-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
					<button type="submit" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-submit-new-board">
						<span class="kanbanz-spinner" id="kanbanz-create-spinner" style="display:none;" aria-hidden="true"></span>
						<?php esc_html_e('Create Board', 'kanbanz'); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- "Use Template" dialog: board name + who can see it -->
	<div class="kanbanz-modal-overlay" id="kanbanz-template-modal" style="display:none;">
		<div class="kanbanz-modal" role="dialog" aria-modal="true" aria-labelledby="kanbanz-template-modal-title">
			<div class="kanbanz-modal__header">
				<h2 id="kanbanz-template-modal-title"><?php esc_html_e('Create board from template', 'kanbanz'); ?></h2>
				<button type="button" class="kanbanz-modal__close" id="kanbanz-template-modal-close" aria-label="<?php esc_attr_e('Close', 'kanbanz'); ?>">
					<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
				</button>
			</div>
			<form id="kanbanz-template-form" class="kanbanz-modal__body">
				<input type="hidden" id="kanbanz-template-key" value="" />
				<div class="kanbanz-field">
					<label for="kanbanz-template-board-name"><?php esc_html_e('Board Name', 'kanbanz'); ?> <span class="kanbanz-required" aria-hidden="true">*</span></label>
					<input type="text" id="kanbanz-template-board-name" class="kanbanz-input" required aria-required="true" />
				</div>
				<div class="kanbanz-field">
					<span class="kanbanz-team-picker__heading"><?php esc_html_e('Stages', 'kanbanz'); ?></span>
					<div class="kanbanz-template-modal__stages" id="kanbanz-template-stages"></div>
				</div>
				<?php kanbanz_render_team_picker('kanbanz-template-team-picker'); ?>
				<div class="kanbanz-modal__actions">
					<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-template-cancel"><?php esc_html_e('Cancel', 'kanbanz'); ?></button>
					<button type="submit" class="kanbanz-btn kanbanz-btn--primary" id="kanbanz-template-submit">
						<span class="kanbanz-spinner" style="display:none;" aria-hidden="true"></span>
						<?php esc_html_e('Create Board', 'kanbanz'); ?>
					</button>
				</div>
			</form>
		</div>
	</div>
</div>

