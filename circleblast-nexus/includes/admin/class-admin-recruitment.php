<?php
/**
 * Admin Recruitment
 *
 * ITER-0017: Recruitment pipeline for tracking potential new members
 * from referral through decision. Includes candidate CRUD, pipeline
 * stage management, and quarterly review stats.
 */

defined('ABSPATH') || exit;

final class CBNexus_Admin_Recruitment {

	private static $stages = [
		'referral'  => 'Referral',
		'contacted' => 'Contacted',
		'invited'   => 'Invited',
		'visited'   => 'Visited',
		'decision'  => 'Decision',
		'accepted'  => 'Accepted',
		'declined'  => 'Declined',
	];

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'register_menu']);
		add_action('admin_init', [__CLASS__, 'handle_actions']);
	}

	public static function register_menu(): void {
		add_submenu_page(
			'cbnexus-members',
			__('Recruitment', 'circleblast-nexus'),
			__('Recruitment', 'circleblast-nexus'),
			'cbnexus_manage_members',
			'cbnexus-recruitment',
			[__CLASS__, 'render_page']
		);
	}

	public static function handle_actions(): void {
		if (isset($_POST['cbnexus_add_candidate'])) { self::handle_add(); }
		if (isset($_POST['cbnexus_update_candidate'])) { self::handle_update(); }
	}

	private static function handle_add(): void {
		check_admin_referer('cbnexus_add_candidate');
		if (!current_user_can('cbnexus_manage_members')) { wp_die('Permission denied.'); }

		global $wpdb;
		$now = gmdate('Y-m-d H:i:s');

		$wpdb->insert($wpdb->prefix . 'cb_candidates', [
			'name'        => sanitize_text_field($_POST['name'] ?? ''),
			'email'       => sanitize_email($_POST['email'] ?? ''),
			'company'     => sanitize_text_field($_POST['company'] ?? ''),
			'title'       => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
			'industry'    => sanitize_text_field($_POST['industry'] ?? ''),
			'referrer_id' => absint($_POST['referrer_id'] ?? 0) ?: null,
			'stage'       => 'referral',
			'notes'       => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
			'created_at'  => $now,
			'updated_at'  => $now,
		], ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);

		// Notify referrer that their prospect was received.
		$new_id = $wpdb->insert_id;
		if ($new_id && class_exists('CBNexus_Portal_Admin')) {
			$candidate = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cb_candidates WHERE id = %d", $new_id
			));
			if ($candidate) {
				CBNexus_Portal_Admin::trigger_recruitment_automation($candidate, '', 'referral');
			}
		}

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment&cbnexus_notice=added'));
		exit;
	}

	private static function handle_update(): void {
		check_admin_referer('cbnexus_update_candidate');
		if (!current_user_can('cbnexus_manage_members')) { wp_die('Permission denied.'); }

		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';
		$id    = absint($_POST['candidate_id'] ?? 0);
		$new_stage = sanitize_key($_POST['stage'] ?? 'referral');

		// Get current state before updating.
		$candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
		if (!$candidate) {
			wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment&cbnexus_notice=error'));
			exit;
		}

		$old_stage = $candidate->stage;

		$wpdb->update($table, [
			'stage'      => $new_stage,
			'notes'      => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
			'updated_at' => gmdate('Y-m-d H:i:s'),
		], ['id' => $id], ['%s', '%s', '%s'], ['%d']);

		// Trigger automations on stage change (delegate to Portal Admin which has the logic).
		if ($old_stage !== $new_stage && class_exists('CBNexus_Portal_Admin')) {
			// Re-fetch so automations see the updated notes/stage.
			$updated = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
			if ($updated) {
				CBNexus_Portal_Admin::trigger_recruitment_automation($updated, $old_stage, $new_stage);
			}
		}

		// Check for conversion errors to surface to the admin.
		$convert_err = get_transient('cbnexus_recruit_convert_error_' . $id);
		if ($convert_err) {
			delete_transient('cbnexus_recruit_convert_error_' . $id);
			wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment&cbnexus_notice=candidate_convert_failed&candidate_id=' . $id));
			exit;
		}

		wp_safe_redirect(admin_url('admin.php?page=cbnexus-recruitment&cbnexus_notice=updated'));
		exit;
	}

	public static function render_page(): void {
		if (!current_user_can('cbnexus_manage_members')) { wp_die('Permission denied.'); }

		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';
		$notice = sanitize_key($_GET['cbnexus_notice'] ?? '');
		$members = CBNexus_Member_Repository::get_all_members('active');

		// Pipeline stats.
		$stage_counts = [];
		foreach (self::$stages as $key => $label) {
			$stage_counts[$key] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE stage = %s", $key));
		}

		// All candidates — hide accepted/declined from default "All" view since they are now members.
		$filter = isset($_GET['stage']) ? sanitize_key($_GET['stage']) : '';
		$sql = "SELECT c.*, u.display_name as referrer_name FROM {$table} c LEFT JOIN {$wpdb->users} u ON c.referrer_id = u.ID";
		if ($filter !== '' && isset(self::$stages[$filter])) {
			$sql .= $wpdb->prepare(" WHERE c.stage = %s", $filter);
		} else {
			$sql .= " WHERE c.stage NOT IN ('accepted', 'declined')";
		}
		$sql .= " ORDER BY c.updated_at DESC";
		$candidates = $wpdb->get_results($sql);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Recruitment Pipeline', 'circleblast-nexus'); ?></h1>

			<?php if ($notice === 'added') : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Candidate added.', 'circleblast-nexus'); ?></p></div><?php endif; ?>
			<?php if ($notice === 'updated') : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Candidate updated.', 'circleblast-nexus'); ?></p></div><?php endif; ?>
			<?php if ($notice === 'candidate_convert_failed') :
				$cid = absint($_GET['candidate_id'] ?? 0);
				$err = $cid ? get_transient('cbnexus_recruit_convert_error_' . $cid) : '';
				if ($cid) { delete_transient('cbnexus_recruit_convert_error_' . $cid); }
				$detail = $err ?: 'Unknown error.';
			?>
			<div class="notice notice-error is-dismissible"><p><?php echo esc_html('Could not create member account: ' . $detail . ' Please fill in the missing fields and try again, or create the member manually.'); ?></p></div>
			<?php endif; ?>

			<!-- Pipeline Funnel -->
			<div style="display:flex;gap:8px;margin:16px 0;flex-wrap:wrap;">
				<?php $active_count = array_sum($stage_counts) - ($stage_counts['accepted'] ?? 0) - ($stage_counts['declined'] ?? 0); ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=cbnexus-recruitment')); ?>" class="button <?php echo $filter === '' ? 'button-primary' : ''; ?>"><?php esc_html_e('All', 'circleblast-nexus'); ?> (<?php echo $active_count; ?>)</a>
				<?php foreach (self::$stages as $key => $label) : ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=cbnexus-recruitment&stage=' . $key)); ?>" class="button <?php echo $filter === $key ? 'button-primary' : ''; ?>"><?php echo esc_html($label); ?> (<?php echo esc_html($stage_counts[$key]); ?>)</a>
				<?php endforeach; ?>
			</div>

			<!-- Add Candidate Form -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;margin-bottom:20px;">
				<h3 style="margin-top:0;"><?php esc_html_e('Add Candidate', 'circleblast-nexus'); ?></h3>
				<form method="post" action="" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
					<?php wp_nonce_field('cbnexus_add_candidate'); ?>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Name*', 'circleblast-nexus'); ?></label><input type="text" name="name" required class="regular-text" /></div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Email', 'circleblast-nexus'); ?></label><input type="email" name="email" class="regular-text" /></div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Company', 'circleblast-nexus'); ?></label><input type="text" name="company" /></div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Job Title', 'circleblast-nexus'); ?></label><input type="text" name="title" /></div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Industry', 'circleblast-nexus'); ?></label><input type="text" name="industry" /></div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Referred By', 'circleblast-nexus'); ?></label>
						<select name="referrer_id"><option value="0">—</option>
						<?php foreach ($members as $m) : ?><option value="<?php echo esc_attr($m['user_id']); ?>"><?php echo esc_html($m['display_name']); ?></option><?php endforeach; ?>
						</select>
					</div>
					<div><label style="display:block;font-size:12px;color:#666;"><?php esc_html_e('Notes', 'circleblast-nexus'); ?></label><input type="text" name="notes" class="regular-text" /></div>
					<?php submit_button(__('Add', 'circleblast-nexus'), 'primary', 'cbnexus_add_candidate', false); ?>
				</form>
			</div>

			<!-- Candidates Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e('Candidate', 'circleblast-nexus'); ?></th>
					<th style="width:120px;"><?php esc_html_e('Company', 'circleblast-nexus'); ?></th>
					<th style="width:100px;"><?php esc_html_e('Referred By', 'circleblast-nexus'); ?></th>
					<th style="width:120px;"><?php esc_html_e('Stage', 'circleblast-nexus'); ?></th>
					<th><?php esc_html_e('Notes', 'circleblast-nexus'); ?></th>
					<th style="width:90px;"><?php esc_html_e('Updated', 'circleblast-nexus'); ?></th>
				</tr></thead>
				<tbody>
				<?php if (empty($candidates)) : ?>
					<tr><td colspan="6"><?php esc_html_e('No candidates yet.', 'circleblast-nexus'); ?></td></tr>
				<?php else : foreach ($candidates as $c) : ?>
					<tr>
						<td><strong><?php echo esc_html($c->name); ?></strong><?php if ($c->email) : ?><br><span style="font-size:12px;color:#666;"><?php echo esc_html($c->email); ?></span><?php endif; ?></td>
						<td><?php echo esc_html($c->company ?: '—'); ?></td>
						<td><?php echo esc_html($c->referrer_name ?: '—'); ?></td>
						<td>
							<form method="post" action="" style="display:flex;gap:4px;">
								<?php wp_nonce_field('cbnexus_update_candidate'); ?>
								<input type="hidden" name="candidate_id" value="<?php echo esc_attr($c->id); ?>" />
								<input type="hidden" name="notes" value="<?php echo esc_attr($c->notes); ?>" />
								<select name="stage" onchange="this.form.submit();" style="font-size:12px;">
									<?php foreach (self::$stages as $key => $label) : ?>
										<option value="<?php echo esc_attr($key); ?>" <?php selected($c->stage, $key); ?>><?php echo esc_html($label); ?></option>
									<?php endforeach; ?>
								</select>
								<noscript><?php submit_button(__('Update', 'circleblast-nexus'), 'small', 'cbnexus_update_candidate', false); ?></noscript>
								<input type="hidden" name="cbnexus_update_candidate" value="1" />
							</form>
						</td>
						<td style="font-size:13px;"><?php echo esc_html($c->notes ?: '—'); ?></td>
						<td style="font-size:12px;color:#666;"><?php echo esc_html(date_i18n('M j', strtotime($c->updated_at))); ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
