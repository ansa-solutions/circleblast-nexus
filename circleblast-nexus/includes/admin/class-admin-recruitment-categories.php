<?php
/**
 * Admin Recruitment Categories
 *
 * Manage the types of members the group is actively looking to recruit.
 * CRUD interface, priority levels, "filled" toggle, and ability to
 * email the list to all members on a schedule or on-demand.
 */

defined('ABSPATH') || exit;

final class CBNexus_Admin_Recruitment_Categories {

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'register_menu']);
		add_action('admin_init', [__CLASS__, 'handle_actions']);
		add_action('cbnexus_recruitment_blast', [__CLASS__, 'send_blast']);
	}

	public static function register_menu(): void {
		add_submenu_page(
			'cbnexus-members',
			__('Recruitment Needs', 'circleblast-nexus'),
			__('Recruitment Needs', 'circleblast-nexus'),
			'cbnexus_manage_members',
			'cbnexus-recruitment-categories',
			[__CLASS__, 'render_page']
		);
	}

	public static function handle_actions(): void {
		if (isset($_POST['cbnexus_add_category'])) { self::handle_add(); }
		if (isset($_POST['cbnexus_update_category'])) { self::handle_update(); }
		if (isset($_GET['cbnexus_delete_category'])) { self::handle_delete(); }
		if (isset($_GET['cbnexus_toggle_filled'])) { self::handle_toggle(); }
		if (isset($_GET['cbnexus_send_blast'])) { self::handle_manual_blast(); }
		if (isset($_POST['cbnexus_save_schedule'])) { self::handle_schedule(); }
	}

	// ─── CRUD ──────────────────────────────────────────────────────────

	private static function handle_add(): void {
		check_admin_referer('cbnexus_add_recruit_cat');
		if (!current_user_can('cbnexus_manage_members')) { wp_die('Permission denied.'); }

		global $wpdb;
		$now = gmdate('Y-m-d H:i:s');
		$max_sort = (int) $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}cb_recruitment_categories") + 1;

		$wpdb->insert($wpdb->prefix . 'cb_recruitment_categories', [
			'title'       => sanitize_text_field($_POST['title'] ?? ''),
			'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
			'industry'    => sanitize_text_field($_POST['industry'] ?? ''),
			'priority'    => in_array($_POST['priority'] ?? '', ['high', 'medium', 'low'], true) ? $_POST['priority'] : 'medium',
			'sort_order'  => $max_sort,
			'created_by'  => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
		]);

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=added'));
		exit;
	}

	private static function handle_update(): void {
		check_admin_referer('cbnexus_update_recruit_cat');
		if (!current_user_can('cbnexus_manage_members')) { wp_die('Permission denied.'); }

		global $wpdb;
		$id = absint($_POST['category_id'] ?? 0);

		$wpdb->update($wpdb->prefix . 'cb_recruitment_categories', [
			'title'       => sanitize_text_field($_POST['title'] ?? ''),
			'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
			'industry'    => sanitize_text_field($_POST['industry'] ?? ''),
			'priority'    => in_array($_POST['priority'] ?? '', ['high', 'medium', 'low'], true) ? $_POST['priority'] : 'medium',
			'updated_at'  => gmdate('Y-m-d H:i:s'),
		], ['id' => $id]);

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=updated'));
		exit;
	}

	private static function handle_delete(): void {
		$id = absint($_GET['cbnexus_delete_category'] ?? 0);
		if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'cbnexus_delete_cat_' . $id)) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'cb_recruitment_categories', ['id' => $id]);

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=deleted'));
		exit;
	}

	private static function handle_toggle(): void {
		$id = absint($_GET['cbnexus_toggle_filled'] ?? 0);
		if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'cbnexus_toggle_' . $id)) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$current = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT is_filled FROM {$wpdb->prefix}cb_recruitment_categories WHERE id = %d", $id
		));
		$wpdb->update($wpdb->prefix . 'cb_recruitment_categories', ['is_filled' => $current ? 0 : 1], ['id' => $id]);

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=toggled'));
		exit;
	}

	// ─── Email Blast ───────────────────────────────────────────────────

	private static function handle_manual_blast(): void {
		if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'cbnexus_send_recruit_blast')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		self::send_blast();

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=blast_sent'));
		exit;
	}

	private static function handle_schedule(): void {
		check_admin_referer('cbnexus_save_recruit_schedule');
		if (!current_user_can('cbnexus_manage_members')) { return; }

		$freq = sanitize_key($_POST['schedule'] ?? 'none');
		update_option('cbnexus_recruit_blast_schedule', $freq);

		// Clear existing schedule.
		wp_clear_scheduled_hook('cbnexus_recruitment_blast');

		if ($freq !== 'none') {
			wp_schedule_event(time(), $freq, 'cbnexus_recruitment_blast');
		}

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_notice=schedule_saved'));
		exit;
	}

	/**
	 * Send the recruitment categories list to all active members.
	 */
	public static function send_blast(): void {
		$categories = self::get_open_categories();
		if (empty($categories)) { return; }

		$members = CBNexus_Member_Repository::get_all_members('active');

		// Build the categories HTML block.
		$list_html = '';
		foreach ($categories as $cat) {
			$priority_color = ['high' => '#dc2626', 'medium' => '#c49a3c', 'low' => '#059669'][$cat->priority] ?? '#666';
			$list_html .= '<div style="border-left:3px solid ' . $priority_color . ';padding:8px 12px;margin:8px 0;background:#f8fafc;border-radius:0 6px 6px 0;">';
			$list_html .= '<strong>' . esc_html($cat->title) . '</strong>';
			if ($cat->industry) { $list_html .= ' <span style="color:#666;">(' . esc_html($cat->industry) . ')</span>'; }
			if ($cat->description) { $list_html .= '<br/><span style="font-size:13px;color:#4a5568;">' . esc_html($cat->description) . '</span>'; }
			$list_html .= '</div>';
		}

		$portal_url   = class_exists('CBNexus_Portal_Router') ? CBNexus_Portal_Router::get_portal_url() : home_url();
		$referral_url = add_query_arg('referral', 'open', $portal_url);

		foreach ($members as $m) {
			CBNexus_Email_Service::send('recruitment_categories', $m['user_email'], [
				'first_name'      => $m['first_name'],
				'categories_list' => $list_html,
				'count'           => count($categories),
				'referral_url'    => $referral_url,
			], ['recipient_id' => (int) $m['user_id'], 'related_type' => 'recruitment_blast']);
		}

		update_option('cbnexus_last_recruit_blast', gmdate('Y-m-d H:i:s'));
	}

	public static function get_open_categories(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}cb_recruitment_categories
			 WHERE is_filled = 0 ORDER BY title ASC"
		) ?: [];
	}

	// ─── Render ────────────────────────────────────────────────────────

	public static function render_page(): void {
		$editing = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
		$notice  = sanitize_key($_GET['cbnexus_notice'] ?? '');

		$notices = [
			'added'          => __('Category added.', 'circleblast-nexus'),
			'updated'        => __('Category updated.', 'circleblast-nexus'),
			'deleted'        => __('Category deleted.', 'circleblast-nexus'),
			'toggled'        => __('Status updated.', 'circleblast-nexus'),
			'blast_sent'     => __('Email sent to all members!', 'circleblast-nexus'),
			'schedule_saved' => __('Schedule saved.', 'circleblast-nexus'),
		];

		global $wpdb;
		$categories = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cb_recruitment_categories ORDER BY title ASC") ?: [];
		$schedule   = get_option('cbnexus_recruit_blast_schedule', 'none');
		$last_blast = get_option('cbnexus_last_recruit_blast', '');
		$industries = CBNexus_Member_Service::get_industries();
		$editing_cat = $editing ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cb_recruitment_categories WHERE id = %d", $editing)) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Recruitment Needs', 'circleblast-nexus'); ?></h1>
			<p><?php esc_html_e('Define what types of members the group is looking for. Send this list to all members so they know who to refer.', 'circleblast-nexus'); ?></p>

			<?php if (isset($notices[$notice])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html($notices[$notice]); ?></p></div>
			<?php endif; ?>

			<div style="display:flex;gap:24px;flex-wrap:wrap;">
				<!-- Categories List -->
				<div style="flex:2;min-width:400px;">
					<table class="wp-list-table widefat fixed striped">
						<thead><tr>
							<th><?php esc_html_e('Role / Category', 'circleblast-nexus'); ?></th>
							<th><?php esc_html_e('Industry', 'circleblast-nexus'); ?></th>
							<th><?php esc_html_e('Priority', 'circleblast-nexus'); ?></th>
							<th><?php esc_html_e('Status', 'circleblast-nexus'); ?></th>
							<th><?php esc_html_e('Actions', 'circleblast-nexus'); ?></th>
						</tr></thead>
						<tbody>
						<?php if (empty($categories)) : ?>
							<tr><td colspan="5"><?php esc_html_e('No categories defined yet.', 'circleblast-nexus'); ?></td></tr>
						<?php else : foreach ($categories as $cat) :
							$p_colors = ['high' => '#dc2626', 'medium' => '#c49a3c', 'low' => '#059669'];
						?>
							<tr<?php echo $cat->is_filled ? ' style="opacity:0.5;"' : ''; ?>>
								<td>
									<strong><?php echo esc_html($cat->title); ?></strong>
									<?php if ($cat->description) : ?><br/><span style="color:#666;font-size:12px;"><?php echo esc_html(wp_trim_words($cat->description, 15)); ?></span><?php endif; ?>
								</td>
								<td><?php echo esc_html($cat->industry ?: '—'); ?></td>
								<td><span style="color:<?php echo esc_attr($p_colors[$cat->priority] ?? '#666'); ?>;font-weight:600;text-transform:uppercase;font-size:11px;"><?php echo esc_html($cat->priority); ?></span></td>
								<td><?php echo $cat->is_filled ? '✅ Filled' : '🔍 Open'; ?></td>
								<td>
									<a href="<?php echo esc_url(admin_url('admin.php?page=cbnexus-recruitment-categories&edit=' . $cat->id)); ?>"><?php esc_html_e('Edit', 'circleblast-nexus'); ?></a> |
									<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_toggle_filled=' . $cat->id), 'cbnexus_toggle_' . $cat->id)); ?>"><?php echo $cat->is_filled ? esc_html__('Reopen', 'circleblast-nexus') : esc_html__('Mark Filled', 'circleblast-nexus'); ?></a> |
									<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_delete_category=' . $cat->id), 'cbnexus_delete_cat_' . $cat->id)); ?>" onclick="return confirm('Delete this category?');" style="color:#dc2626;"><?php esc_html_e('Delete', 'circleblast-nexus'); ?></a>
								</td>
							</tr>
						<?php endforeach; endif; ?>
						</tbody>
					</table>

					<!-- Blast Controls -->
					<div style="margin-top:16px;padding:16px;background:#fff;border:1px solid #ccd0d4;border-radius:4px;">
						<h3 style="margin-top:0;"><?php esc_html_e('Email to Members', 'circleblast-nexus'); ?></h3>
						<p>
							<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=cbnexus-recruitment-categories&cbnexus_send_blast=1'), 'cbnexus_send_recruit_blast')); ?>" class="button button-primary" onclick="return confirm('Send recruitment needs to all active members?');"><?php esc_html_e('Send Now', 'circleblast-nexus'); ?></a>
							<?php if ($last_blast) : ?><span style="margin-left:12px;color:#666;">Last sent: <?php echo esc_html($last_blast); ?></span><?php endif; ?>
						</p>
						<form method="post" style="margin-top:12px;">
							<?php wp_nonce_field('cbnexus_save_recruit_schedule'); ?>
							<label><strong><?php esc_html_e('Auto-send schedule:', 'circleblast-nexus'); ?></strong></label>
							<select name="schedule" style="margin:0 8px;">
								<option value="none" <?php selected($schedule, 'none'); ?>><?php esc_html_e('Manual only', 'circleblast-nexus'); ?></option>
								<option value="weekly" <?php selected($schedule, 'weekly'); ?>><?php esc_html_e('Weekly', 'circleblast-nexus'); ?></option>
								<option value="monthly" <?php selected($schedule, 'monthly'); ?>><?php esc_html_e('Monthly', 'circleblast-nexus'); ?></option>
							</select>
							<button type="submit" name="cbnexus_save_schedule" value="1" class="button"><?php esc_html_e('Save Schedule', 'circleblast-nexus'); ?></button>
						</form>
					</div>
				</div>

				<!-- Add / Edit Form -->
				<div style="flex:1;min-width:300px;">
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:16px;">
						<h3 style="margin-top:0;"><?php echo $editing_cat ? esc_html__('Edit Category', 'circleblast-nexus') : esc_html__('Add Category', 'circleblast-nexus'); ?></h3>
						<form method="post">
							<?php if ($editing_cat) : ?>
								<?php wp_nonce_field('cbnexus_update_recruit_cat'); ?>
								<input type="hidden" name="category_id" value="<?php echo esc_attr($editing_cat->id); ?>" />
							<?php else : ?>
								<?php wp_nonce_field('cbnexus_add_recruit_cat'); ?>
							<?php endif; ?>

							<p><label><strong><?php esc_html_e('Title / Role', 'circleblast-nexus'); ?></strong></label><br/>
							<input type="text" name="title" value="<?php echo esc_attr($editing_cat->title ?? ''); ?>" class="large-text" required placeholder="e.g. Financial Advisor, Healthcare Executive" /></p>

							<p><label><strong><?php esc_html_e('Description', 'circleblast-nexus'); ?></strong></label><br/>
							<textarea name="description" rows="3" class="large-text" placeholder="What qualities or background are we looking for?"><?php echo esc_textarea($editing_cat->description ?? ''); ?></textarea></p>

							<p><label><strong><?php esc_html_e('Industry', 'circleblast-nexus'); ?></strong></label><br/>
							<select name="industry"><option value=""><?php esc_html_e('— Any —', 'circleblast-nexus'); ?></option>
								<?php foreach ($industries as $ind) : ?>
									<option value="<?php echo esc_attr($ind); ?>" <?php selected($editing_cat->industry ?? '', $ind); ?>><?php echo esc_html($ind); ?></option>
								<?php endforeach; ?>
							</select></p>

							<p><label><strong><?php esc_html_e('Priority', 'circleblast-nexus'); ?></strong></label><br/>
							<select name="priority">
								<option value="high" <?php selected($editing_cat->priority ?? '', 'high'); ?>>🔴 <?php esc_html_e('High', 'circleblast-nexus'); ?></option>
								<option value="medium" <?php selected($editing_cat->priority ?? 'medium', 'medium'); ?>>🟡 <?php esc_html_e('Medium', 'circleblast-nexus'); ?></option>
								<option value="low" <?php selected($editing_cat->priority ?? '', 'low'); ?>>🟢 <?php esc_html_e('Low', 'circleblast-nexus'); ?></option>
							</select></p>

							<p><button type="submit" name="<?php echo $editing_cat ? 'cbnexus_update_category' : 'cbnexus_add_category'; ?>" value="1" class="button button-primary">
								<?php echo $editing_cat ? esc_html__('Update', 'circleblast-nexus') : esc_html__('Add Category', 'circleblast-nexus'); ?>
							</button>
							<?php if ($editing_cat) : ?>
								<a href="<?php echo esc_url(admin_url('admin.php?page=cbnexus-recruitment-categories')); ?>" class="button"><?php esc_html_e('Cancel', 'circleblast-nexus'); ?></a>
							<?php endif; ?></p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
