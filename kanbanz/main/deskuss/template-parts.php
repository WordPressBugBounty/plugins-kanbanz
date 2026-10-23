<?php
/**
 * Deskuss UI template-part renderers for Kanbanz.
 *
 * Every Deskuss-specific snippet of HTML that used to be inlined
 * in the core templates now lives behind a helper in this file.
 *
 * When the Deskuss integration is disabled these helpers are
 * available too but they short-circuit and render nothing, so the
 * core templates can call them unconditionally without sprinkling
 * `if(defined('DESKUSS_DIR'))` checks everywhere.
 *
 * @package kanbanz
 * @subpackage deskuss
 */

if(!defined('ABSPATH')) exit;

if(!defined('KANBANZ_FILE')) exit;

/**
 * Echo nothing when Deskuss is disabled — used as a guard by every
 * renderer below so the templates can call them unconditionally.
 *
 * @return bool true when the caller should render, false to bail.
 */
function kanbanz_deskuss_enabled() {
	return defined('DESKUSS_DIR');
}

/**
 * Sync Departments button shown in the board listing header.
 */
function kanbanz_deskuss_render_sync_button() {
	if(!kanbanz_deskuss_enabled()) return;
	?>
	<button type="button" class="kanbanz-btn kanbanz-btn--secondary" id="kanbanz-sync-departments-btn">
		<span class="dashicons dashicons-update" aria-hidden="true"></span>
		<?php esc_html_e('Sync Departments', 'kanbanz'); ?>
	</button>
	<?php
}

/**
 * "Link to Department" field used in the Create Board modal and the
 * New Board page form.
 */
function kanbanz_deskuss_render_dept_field() {
	if(!kanbanz_deskuss_enabled()) return;
	$depts = kanbanz_get_all_departments();
	?>
	<div class="kanbanz-field">
		<label for="kanbanz-board-dept"><?php esc_html_e('Link to Department (optional)', 'kanbanz'); ?></label>
		<select id="kanbanz-board-dept" name="dept_id">
			<option value=""><?php esc_html_e('None (Custom Board)', 'kanbanz'); ?></option>
			<?php if(!empty($depts)): foreach($depts as $dept): ?>
			<option value="<?php echo esc_attr($dept->term_id); ?>"><?php echo esc_html($dept->name); ?></option>
			<?php endforeach; endif; ?>
		</select>
	</div>
	<?php
}

/**
 * "Link to Department" field variant for the New Board page form.
 */
function kanbanz_deskuss_render_new_board_dept_field() {
	if(!kanbanz_deskuss_enabled()) return;
	$depts = kanbanz_get_all_departments();
	?>
	<div class="kanbanz-field">
		<label for="kanbanz-new-board-dept"><?php esc_html_e('Link to Department (optional)', 'kanbanz'); ?></label>
		<select id="kanbanz-new-board-dept" name="dept_id" class="kanbanz-select">
			<option value=""><?php esc_html_e('None', 'kanbanz'); ?></option>
			<?php if(!empty($depts)): foreach($depts as $dept): ?>
			<option value="<?php echo esc_attr($dept->term_id); ?>"><?php echo esc_html($dept->name); ?></option>
			<?php endforeach; endif; ?>
		</select>
	</div>
	<?php
}

/**
 * Ticket link badge shown on a kanban card.
 *
 * @param int $ticket_number
 */
function kanbanz_deskuss_render_card_ticket_link($ticket_number) {
	if(!kanbanz_deskuss_enabled()) return;
	if(empty($ticket_number)) return;
	?>
	<a class="kanbanz-card__ticket-link" href="<?php echo esc_url(kanbanz_get_ticket_url_base() . intval($ticket_number)); ?>" target="_blank" title="<?php esc_attr_e('View ticket', 'kanbanz'); ?>">
		<span class="dashicons dashicons-tickets-alt"></span>
		<span><?php echo esc_html('#' . $ticket_number); ?></span>
	</a>
	<?php
}

/**
 * Ticket badge cell for the My Tasks tables.
 *
 * @param string $ticket_number
 */
function kanbanz_deskuss_render_task_ticket_cell($ticket_number) {
	if(!kanbanz_deskuss_enabled()) {
		// When Deskuss is disabled we still render the N/A cell so the
		// table column layout stays consistent if the theme expects it.
		echo '<td class="kanbanz-mt-col-ticket"><span class="kanbanz-mt-na">' . esc_html__('N/A', 'kanbanz') . '</span></td>';
		return;
	}
	?>
	<td class="kanbanz-mt-col-ticket">
		<?php if(!empty($ticket_number)) : ?>
		<span class="kanbanz-mt-ticket-badge">
			<span class="dashicons dashicons-tickets-alt"></span>
			<?php echo esc_html('#' . $ticket_number); ?>
		</span>
		<?php else : ?>
		<span class="kanbanz-mt-na"><?php esc_html_e('N/A', 'kanbanz'); ?></span>
		<?php endif; ?>
	</td>
	<?php
}

/**
 * "Resolved Tickets" tab button shown in the My Tasks tabs list.
 */
function kanbanz_deskuss_render_tickets_tab_button($active_tab) {
	if(!kanbanz_deskuss_enabled()) return;
	?>
	<button type="button" class="kanbanz-mt-tab <?php echo $active_tab === 'tickets' ? 'kanbanz-mt-tab--active' : ''; ?>" data-tab="tickets" role="tab" id="kanbanz-mt-tab-tickets" aria-selected="<?php echo $active_tab === 'tickets' ? 'true' : 'false'; ?>" aria-controls="kanbanz-mt-panel-tickets" tabindex="<?php echo $active_tab === 'tickets' ? '0' : '-1'; ?>">
		<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
		<?php esc_html_e('Resolved Tickets', 'kanbanz'); ?>
	</button>
	<?php
}

/**
 * Department filter dropdown for the My Tasks filter row.
 */
function kanbanz_deskuss_render_dept_filter($dept_id, $active_tab) {
	if(!kanbanz_deskuss_enabled()) return;
	$accessible_depts = kanbanz_get_my_depts_for_filter();
	?>
	<div class="kanbanz-mt-filter-item kanbanz-mt-dept-filter" style="<?php echo $active_tab !== 'tickets' ? 'display:none;' : ''; ?>">
		<label for="kanbanz-mt-dept-id"><?php esc_html_e('Department', 'kanbanz'); ?></label>
		<select id="kanbanz-mt-dept-id" name="dept_id" class="kanbanz-mt-select">
			<option value="0"><?php esc_html_e('All Departments', 'kanbanz'); ?></option>
			<?php foreach($accessible_depts as $dept) : ?>
			<option value="<?php echo esc_attr($dept->term_id); ?>" <?php selected($dept_id, $dept->term_id); ?>>
				<?php echo esc_html($dept->name); ?>
			</option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php
}

/**
 * The whole "Resolved Tickets" tab panel.
 *
 * @param array $resolved_tickets
 * @param int $tickets_total
 * @param int $tickets_total_pages
 * @param int $page
 * @param string $start_date
 * @param string $end_date
 * @param int $board_id
 * @param int $dept_id
 * @param string $search
 * @param string $active_tab
 */
function kanbanz_deskuss_render_resolved_tickets_tab($resolved_tickets, $tickets_total, $tickets_total_pages, $page, $start_date, $end_date, $board_id, $dept_id, $search, $active_tab) {
	if(!kanbanz_deskuss_enabled()) return;
	?>
	<!-- Resolved Tickets Tab -->
	<div class="kanbanz-mt-section kanbanz-mt-tickets-section" role="tabpanel" id="kanbanz-mt-panel-tickets" aria-labelledby="kanbanz-mt-tab-tickets" style="<?php echo $active_tab !== 'tickets' ? 'display:none;' : ''; ?>">
		<?php if(!empty($resolved_tickets)) : ?>
		<table class="wp-list-table widefat fixed striped kanbanz-mt-table">
			<thead>
				<tr>
					<th class="kanbanz-mt-col-ticket-num"><?php esc_html_e('Ticket #', 'kanbanz'); ?></th>
					<th class="kanbanz-mt-col-subject"><?php esc_html_e('Subject', 'kanbanz'); ?></th>
					<th class="kanbanz-mt-col-dept"><?php esc_html_e('Department', 'kanbanz'); ?></th>
					<th class="kanbanz-mt-col-priority"><?php esc_html_e('Priority', 'kanbanz'); ?></th>
					<th class="kanbanz-mt-col-created"><?php esc_html_e('Created', 'kanbanz'); ?></th>
					<th class="kanbanz-mt-col-resolved"><?php esc_html_e('Resolved', 'kanbanz'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach($resolved_tickets as $ticket) :
					$ticket_id = isset($ticket['ticket_id']) ? intval($ticket['ticket_id']) : 0;
					$ticket_url = trailingslashit(site_url()) . 'deskuss/admin/tickets.php?id=' . $ticket_id;
					$number = isset($ticket['number']) ? $ticket['number'] : '';
					$subject = isset($ticket['subject']) ? $ticket['subject'] : '';
					$dept_name = isset($ticket['dept_name']) ? $ticket['dept_name'] : '';
					$ticket_priority = isset($ticket['priority']) ? $ticket['priority'] : '';
					$created_date = isset($ticket['created_date']) ? $ticket['created_date'] : '';
					$resolved_date = isset($ticket['resolved_date']) ? $ticket['resolved_date'] : '';
				?>
				<tr>
					<td class="kanbanz-mt-col-ticket-num">
						<a href="<?php echo esc_url($ticket_url); ?>" target="_blank" title="<?php echo esc_attr($subject); ?>">
							<?php echo esc_html($number); ?>
						</a>
					</td>
					<td class="kanbanz-mt-col-subject">
						<?php echo esc_html($subject); ?>
					</td>
					<td class="kanbanz-mt-col-dept">
						<?php echo esc_html($dept_name); ?>
					</td>
					<td class="kanbanz-mt-col-priority">
						<?php if(!empty($ticket_priority)) : ?>
						<span class="kanbanz-mt-priority-badge kanbanz-mt-priority-<?php echo esc_attr(strtolower($ticket_priority)); ?>">
							<?php echo esc_html(ucfirst($ticket_priority)); ?>
						</span>
						<?php endif; ?>
					</td>
					<td class="kanbanz-mt-col-created">
						<?php if(!empty($created_date)) : ?>
						<span class="kanbanz-mt-date"><?php echo esc_html(date_i18n('M j, Y', strtotime($created_date))); ?></span>
						<?php endif; ?>
					</td>
					<td class="kanbanz-mt-col-resolved">
						<?php if(!empty($resolved_date)) : ?>
						<span class="kanbanz-mt-date"><?php echo esc_html(date_i18n('M j, Y', strtotime($resolved_date))); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if($tickets_total_pages > 1) : ?>
		<div class="kanbanz-mt-pagination">
			<?php
			echo wp_kses_post(paginate_links(array(
				'base'      => admin_url('admin.php?page=kanbanz_my_tasks&tab=tickets&%_%'),
				'format'    => '&paged=%#%',
				'total'     => $tickets_total_pages,
				'current'   => $page,
				'show_all'  => false,
				'type'      => 'plain',
				'add_args'  => array(
					'start_date' => gmdate('Y-m-d', strtotime($start_date)),
					'end_date'   => gmdate('Y-m-d', strtotime($end_date)),
					'board_id'   => $board_id,
					'dept_id'    => $dept_id,
					's'          => $search,
				),
			)));
			?>
		</div>
		<?php endif; ?>

		<?php else : ?>
		<div class="kanbanz-mt-empty">
			<span class="dashicons dashicons-tickets-alt"></span>
			<p><?php esc_html_e('No resolved tickets found.', 'kanbanz'); ?></p>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The "Auto-sync from Deskuss" + "Department delete policy" +
 * "Deskuss ticket status mapping" + "Manual sync" sections of the
 * Settings page.
 *
 * Extracted verbatim from kanbanz_settings_page() so the settings
 * page itself only deals with Kanbanz-native options.
 */
function kanbanz_deskuss_render_settings_sections($cron_enabled, $policy, $reassign_to, $last_sync, $deskuss_active, $boards, $sync_url) {
	if(!$deskuss_active):
		?>
		<div class="notice notice-warning"><p><?php esc_html_e('Deskuss is not active. Sync and bridge features are disabled until Deskuss is enabled.', 'kanbanz'); ?></p></div>
		<?php
		return;
	endif;
	?>
	<h2><?php esc_html_e('Auto-sync from Deskuss', 'kanbanz'); ?></h2>
	<p>
		<label>
			<input type="checkbox" name="cron_enabled" value="1" <?php checked($cron_enabled, 1); ?> />
			<?php esc_html_e('Enable hourly auto-sync (backfill missing departments, open tickets, and orphaned tasks)', 'kanbanz'); ?>
		</label>
	</p>
	<p class="description">
		<?php esc_html_e('When a Deskuss ticket is replied to, the matching KanbanZ task is created (if missing) and its board is repaired if it was orphaned.', 'kanbanz'); ?>
	</p>

	<h2><?php esc_html_e('When a department is deleted in Deskuss', 'kanbanz'); ?></h2>
	<p>
		<label><input type="radio" name="dept_delete_policy" value="trash" <?php checked($policy, 'trash'); ?> />
		<?php esc_html_e('Trash the linked board and all its tasks', 'kanbanz'); ?></label><br>
		<label><input type="radio" name="dept_delete_policy" value="reassign" <?php checked($policy, 'reassign'); ?> />
		<?php esc_html_e('Move tasks to another board, then trash this board', 'kanbanz'); ?></label>
	</p>
	<p>
		<label for="dept_reassign_board"><?php esc_html_e('Reassign target board:', 'kanbanz'); ?></label>
		<select name="dept_reassign_board" id="dept_reassign_board">
			<option value="0">— <?php esc_html_e('Select a board', 'kanbanz'); ?> —</option>
			<?php foreach($boards as $b): ?>
				<option value="<?php echo esc_attr($b->ID); ?>" <?php selected($reassign_to, $b->ID); ?>>
					<?php echo esc_html($b->post_title); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<?php if(!empty($boards)): ?>
	<h2><?php esc_html_e('Deskuss ticket status mapping', 'kanbanz'); ?></h2>
	<p class="description">
		<?php esc_html_e('Map Deskuss ticket states to Kanbanz board columns. When a ticket is closed/resolved, the task moves to the "Closed/Resolved" column. When an agent replies, the task moves to "In Progress". When the user replies back, the task returns to "To Do / Open". Leave a field as "Auto" to use smart defaults (matches by name, then by position).', 'kanbanz'); ?>
	</p>
	<table class="widefat striped" style="margin-top:12px;">
		<thead>
			<tr>
				<th><?php esc_html_e('Board', 'kanbanz'); ?></th>
				<th><?php esc_html_e('Open / New (user replied)', 'kanbanz'); ?></th>
				<th><?php esc_html_e('In Progress (agent answered)', 'kanbanz'); ?></th>
				<th><?php esc_html_e('Resolved', 'kanbanz'); ?></th>
				<th><?php esc_html_e('Closed', 'kanbanz'); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach($boards as $b):
			$b_statuses = get_post_meta($b->ID, 'board_statuses', true);
			if(!is_array($b_statuses) || empty($b_statuses)) continue;
			$saved_map = get_post_meta($b->ID, 'kanbanz_deskuss_status_map', true);
			if(!is_array($saved_map)) $saved_map = array();
			$states = array('open', 'progress', 'resolved', 'closed');
		?>
			<tr>
				<td><strong><?php echo esc_html($b->post_title); ?></strong></td>
				<?php foreach($states as $state):
					$current_val = $saved_map[$state] ?? '';
				?>
				<td>
					<select name="kanbanz_status_map[<?php echo esc_attr($b->ID); ?>][<?php echo esc_attr($state); ?>]">
						<option value=""><?php esc_html_e('Auto', 'kanbanz'); ?></option>
						<?php foreach($b_statuses as $s): ?>
						<option value="<?php echo esc_attr($s); ?>" <?php selected($current_val, $s); ?>>
							<?php echo esc_html($s); ?>
						</option>
						<?php endforeach; ?>
					</select>
				</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<hr/>

	<h2><?php esc_html_e('Manual sync', 'kanbanz'); ?></h2>
	<p><?php esc_html_e('Run the backfill job right now (creates boards for any departments without one, creates tasks for any open tickets without one, and reparents orphaned tasks).', 'kanbanz'); ?></p>
	<p>
		<a href="<?php echo esc_url($sync_url); ?>" class="button button-secondary">
			<?php esc_html_e('Run Sync Now', 'kanbanz'); ?>
		</a>
	</p>

	<?php if(!empty($last_sync) && !empty($last_sync['time'])): ?>
		<p class="description">
			<?php
			$when = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), intval($last_sync['time']));
			$s = $last_sync['stats'] ?? array();
			printf(
				/* translators: 1: last sync time, 2: departments created, 3: tickets created, 4: orphans fixed */
				esc_html__('Last sync: %1$s — Departments: %2$d created, Tickets: %3$d created, Orphans: %4$d fixed', 'kanbanz'),
				esc_html($when),
				intval($s['depts_created'] ?? 0),
				intval($s['tickets_created'] ?? 0),
				intval($s['orphans_fixed'] ?? 0)
			);
			?>
		</p>
	<?php endif; ?>
	<?php
}

/**
 * Save the Deskuss-specific settings posted from the settings page.
 *
 * Hooked from the settings page handler so the core page does not
 * need to know about Deskuss.
 */
function kanbanz_deskuss_save_settings() {
	if(!kanbanz_deskuss_enabled()) return;

	check_admin_referer('kanbanz_native_settings');

	update_option('kanbanz_cron_enabled', isset($_POST['cron_enabled']) ? 1 : 0);
	update_option('kanbanz_dept_delete_policy', sanitize_key(wp_unslash($_POST['dept_delete_policy'] ?? 'trash')));
	update_option('kanbanz_dept_reassign_board', absint($_POST['dept_reassign_board'] ?? 0));

	// Save per-board Deskuss status mapping
	if(!empty($_POST['kanbanz_status_map']) && is_array($_POST['kanbanz_status_map'])) {
		$boards_for_map = get_posts(array(
			'post_type'      => 'kanbanz_board',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		));
		foreach($boards_for_map as $bid) {
			if(!isset($_POST['kanbanz_status_map'][$bid])) continue;
			$raw = wp_unslash($_POST['kanbanz_status_map'][$bid]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
			$statuses = get_post_meta($bid, 'board_statuses', true);
			if(!is_array($statuses)) continue;
			$clean = array();
			foreach(array('open', 'progress', 'resolved', 'closed') as $key) {
				$val = sanitize_text_field($raw[$key] ?? '');
				if($val && in_array($val, $statuses, true)) {
					$clean[$key] = $val;
				}
			}
			if(!empty($clean)) {
				update_post_meta($bid, 'kanbanz_deskuss_status_map', $clean);
			} else {
				delete_post_meta($bid, 'kanbanz_deskuss_status_map');
			}
		}
	}
}