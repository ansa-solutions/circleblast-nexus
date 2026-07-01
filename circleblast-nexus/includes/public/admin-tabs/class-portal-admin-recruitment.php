<?php
/**
 * Portal Admin – Recruitment Tab
 *
 * Extracted from class-portal-admin.php for maintainability.
 * Handles recruitment pipeline, candidate CRUD, stage automations.
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Admin_Recruitment {

	private static $recruit_stages = [
		'referral'  => 'Referral',
		'contacted' => 'Contacted',
		'invited'   => 'Invited',
		'visited'   => 'Visited',
		'decision'  => 'Decision',
		'accepted'  => 'Accepted',
		'declined'  => 'Declined',
	];

	/** Days of inactivity in an active pre-decision stage before "needs follow-up" fires. */
	const STALE_FOLLOWUP_DAYS = 14;

	// ─── Action state (UI indicator helper) ─────────────────────────────

	/**
	 * Compute the recruiter-facing action state for a candidate row.
	 * Returns one of: ['code' => 'clear'|'waiting'|'ready'|'attention', 'label' => string, 'color' => '#hex'].
	 */
	private static function get_action_state(object $c): array {
		$stage = (string) $c->stage;
		$updated_ts = strtotime((string) $c->updated_at);
		$now = time();

		// Decision stage: council-review window logic.
		if ($stage === 'decision') {
			$sent_at = get_option('cbnexus_council_review_sent_' . $c->id);
			$hours = CBNexus_Recruitment_Settings::get_council_review_hours();
			if ($sent_at) {
				$opened_ts = strtotime($sent_at);
				$elapsed_h = ($now - $opened_ts) / 3600;
				if ($elapsed_h >= $hours) {
					return [
						'code'  => 'ready',
						'label' => 'Council review window closed — ready to advance',
						'color' => '#16a34a',
					];
				}
				$left_h = max(0, $hours - $elapsed_h);
				return [
					'code'  => 'waiting',
					'label' => 'Council review in progress (' . (int) ceil($left_h) . 'h left)',
					'color' => '#ca8a04',
				];
			}
			// No review email recorded — flag to send it.
			return [
				'code'  => 'attention',
				'label' => 'Decision stage — council review not yet sent',
				'color' => '#dc2626',
			];
		}

		// Visited: did the candidate respond to the Interested/Not Interested email?
		if ($stage === 'visited') {
			$fb = get_option('cbnexus_visit_feedback_' . $c->id);
			if (is_array($fb) && !empty($fb['answer'])) {
				$ans = $fb['answer'];
				if ($ans === 'interested') {
					return [
						'code'  => 'attention',
						'label' => 'Candidate marked Interested — move to Decision',
						'color' => '#16a34a',
					];
				}
				if ($ans === 'not_interested') {
					return [
						'code'  => 'attention',
						'label' => 'Candidate marked Not Interested — move to Declined',
						'color' => '#6b7280',
					];
				}
			}
			// No response yet — flag if stale.
			if ($updated_ts && ($now - $updated_ts) > self::STALE_FOLLOWUP_DAYS * 86400) {
				return [
					'code'  => 'attention',
					'label' => 'No post-visit response — follow up',
					'color' => '#dc2626',
				];
			}
			return ['code' => 'waiting', 'label' => 'Awaiting candidate response', 'color' => '#ca8a04'];
		}

		// Other pre-accepted stages: flag if stale.
		if (in_array($stage, ['referral', 'contacted', 'invited'], true)) {
			if ($updated_ts && ($now - $updated_ts) > self::STALE_FOLLOWUP_DAYS * 86400) {
				return [
					'code'  => 'attention',
					'label' => 'No movement in ' . self::STALE_FOLLOWUP_DAYS . '+ days — follow up',
					'color' => '#dc2626',
				];
			}
		}

		return ['code' => 'clear', 'label' => '', 'color' => ''];
	}

	/** Inline HTML for the indicator chip — placed next to the stage select. */
	private static function render_action_chip(array $state): string {
		if (empty($state['code']) || $state['code'] === 'clear') { return ''; }
		$icons = ['waiting' => '⏳', 'ready' => '✅', 'attention' => '🔴'];
		$icon  = $icons[$state['code']] ?? '•';
		return '<div title="' . esc_attr($state['label']) . '" style="margin-top:6px;font-size:11px;color:' . esc_attr($state['color']) . ';font-weight:600;line-height:1.3;">'
			. $icon . ' ' . esc_html($state['label']) . '</div>';
	}

	// ─── Render ─────────────────────────────────────────────────────────

	public static function render(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'cb_candidates';
		$notice = sanitize_key($_GET['pa_notice'] ?? '');
		$filter = sanitize_key($_GET['stage'] ?? '');
		$members = CBNexus_Member_Repository::get_all_members('active');

		// If editing a candidate, show the edit form.
		$edit_id = absint($_GET['edit_candidate'] ?? 0);
		if ($edit_id) {
			self::render_candidate_form($edit_id, $members);
			return;
		}

		// Stage counts.
		$stage_counts = [];
		foreach (self::$recruit_stages as $key => $label) {
			$stage_counts[$key] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE stage = %s", $key));
		}

		// Candidates — hide accepted/declined from default "All" view since they are now members.
		$sql = "SELECT c.*, u.display_name as referrer_name FROM {$table} c LEFT JOIN {$wpdb->users} u ON c.referrer_id = u.ID";
		if ($filter !== '' && isset(self::$recruit_stages[$filter])) {
			$sql .= $wpdb->prepare(" WHERE c.stage = %s", $filter);
		} else {
			$sql .= " WHERE c.stage NOT IN ('accepted', 'declined')";
		}
		$sql .= " ORDER BY c.updated_at DESC";
		$candidates = $wpdb->get_results($sql);

		$base = CBNexus_Portal_Admin::admin_url('recruitment');
		?>
		<?php CBNexus_Portal_Admin::render_notice($notice); ?>

		<div class="cbnexus-card">
			<h2>Recruitment Pipeline</h2>

			<!-- Funnel -->
			<div class="cbnexus-admin-filters">
				<?php $active_count = array_sum($stage_counts) - ($stage_counts['accepted'] ?? 0) - ($stage_counts['declined'] ?? 0); ?>
				<a href="<?php echo esc_url($base); ?>" class="<?php echo $filter === '' ? 'active' : ''; ?>">All (<?php echo $active_count; ?>)</a>
				<?php foreach (self::$recruit_stages as $key => $label) : ?>
					<a href="<?php echo esc_url(add_query_arg('stage', $key, $base)); ?>" class="<?php echo $filter === $key ? 'active' : ''; ?>"><?php echo esc_html($label); ?> (<?php echo esc_html($stage_counts[$key]); ?>)</a>
				<?php endforeach; ?>
			</div>
		</div>

		<!-- Add Candidate -->
		<div class="cbnexus-card">
			<h3>Add Candidate</h3>
			<form method="post" action="" class="cbnexus-admin-inline-form">
				<?php wp_nonce_field('cbnexus_portal_add_candidate'); ?>
				<div class="cbnexus-admin-form-grid">
					<div>
						<label>Name *</label>
						<input type="text" name="name" required />
					</div>
					<div>
						<label>Email</label>
						<input type="email" name="email" />
					</div>
					<div>
						<label>Company</label>
						<input type="text" name="company" />
					</div>
					<div>
						<label>Job Title</label>
						<input type="text" name="title" />
					</div>
					<div>
						<label>Industry</label>
						<input type="text" name="industry" />
					</div>
					<div>
						<label>Referred By</label>
						<select name="referrer_id">
							<option value="0">—</option>
							<?php foreach ($members as $m) : ?><option value="<?php echo esc_attr($m['user_id']); ?>"><?php echo esc_html($m['display_name']); ?></option><?php endforeach; ?>
						</select>
					</div>
					<div>
						<label>Notes</label>
						<input type="text" name="notes" />
					</div>
					<div>
						<label>Category</label>
						<select name="category_id">
							<option value="0">—</option>
							<?php
							global $wpdb;
							$cat_table = $wpdb->prefix . 'cb_recruitment_categories';
							$need_cats = $wpdb->get_results("SELECT id, title FROM {$cat_table} ORDER BY title ASC") ?: [];
							foreach ($need_cats as $nc) : ?>
								<option value="<?php echo esc_attr($nc->id); ?>"><?php echo esc_html($nc->title); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<button type="submit" name="cbnexus_portal_add_candidate" value="1" class="cbnexus-btn cbnexus-btn-accent">Add Candidate</button>
			</form>
		</div>

		<!-- Candidates Table -->
		<div class="cbnexus-card">
			<div class="cbnexus-admin-table-wrap">
				<table class="cbnexus-admin-table">
					<thead><tr>
						<th style="width:48px;"><span class="screen-reader-text">Actions</span></th>
						<th style="width:170px;">Stage</th>
						<th>Candidate</th>
						<th>Company</th>
						<th>Category</th>
						<th>Referred By</th>
						<th>Notes</th>
						<th>Updated</th>
					</tr></thead>
					<tbody>
					<?php if (empty($candidates)) : ?>
						<tr><td colspan="8" class="cbnexus-admin-empty">No candidates yet.</td></tr>
					<?php else : foreach ($candidates as $c) : ?>
						<tr>
							<td class="cbnexus-admin-actions-cell" style="text-align:center;padding:0 4px;">
								<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment', ['edit_candidate' => $c->id])); ?>" aria-label="Edit candidate" title="Edit candidate" style="display:inline-block;padding:4px 6px;text-decoration:none;font-size:16px;line-height:1;color:#5b2d6e;">
									<span aria-hidden="true">✏️</span>
								</a>
							</td>
							<td>
								<form method="post" action="" class="cbnexus-admin-stage-form">
									<?php wp_nonce_field('cbnexus_portal_update_candidate'); ?>
									<input type="hidden" name="candidate_id" value="<?php echo esc_attr($c->id); ?>" />
									<input type="hidden" name="notes" value="<?php echo esc_attr($c->notes); ?>" />
									<input type="hidden" name="cbnexus_portal_update_candidate" value="1" />
									<select name="stage" onchange="this.form.submit();" style="width:100%;">
										<?php foreach (self::$recruit_stages as $key => $label) : ?>
											<option value="<?php echo esc_attr($key); ?>" <?php selected($c->stage, $key); ?>><?php echo esc_html($label); ?></option>
										<?php endforeach; ?>
									</select>
								</form>
								<?php echo self::render_action_chip(self::get_action_state($c)); ?>
							</td>
							<td>
								<strong><?php echo esc_html($c->name); ?></strong>
								<?php if ($c->email) : ?><div class="cbnexus-admin-meta"><?php echo esc_html($c->email); ?></div><?php endif; ?>
							</td>
							<td><?php echo esc_html($c->company ?: '—'); ?></td>
							<td class="cbnexus-admin-meta"><?php
								$cat_name_c = '—';
								if (!empty($c->category_id)) {
									global $wpdb;
									$cat_name_c = $wpdb->get_var($wpdb->prepare(
										"SELECT title FROM {$wpdb->prefix}cb_recruitment_categories WHERE id = %d",
										$c->category_id
									)) ?: '—';
								}
								echo esc_html($cat_name_c);
							?></td>
							<td><?php echo esc_html($c->referrer_name ?: '—'); ?></td>
							<td class="cbnexus-admin-meta" style="max-width:260px;">
								<?php $note_text = $c->notes ?: '—'; ?>
								<div title="<?php echo esc_attr($c->notes ?: ''); ?>" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px;">
									<?php echo esc_html($note_text); ?>
								</div>
								<?php
								$fb = get_option('cbnexus_visit_feedback_' . $c->id);
								if ($fb && is_array($fb) && !empty($fb['label'])) :
								?>
									<div style="margin-top:4px;"><span style="display:inline-block;padding:2px 8px;background:#f3eef6;border-radius:10px;font-size:11px;color:#5b2d6e;font-weight:600;">📊 <?php echo esc_html($fb['label']); ?></span></div>
								<?php endif; ?>
							</td>
							<td class="cbnexus-admin-meta"><?php echo esc_html(date_i18n('M j', strtotime($c->updated_at))); ?></td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		self::render_recruitment_needs();
	}

	/**
	 * Inline candidate edit form within the portal.
	 */
	private static function render_candidate_form(int $id, array $members): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';
		$c = $wpdb->get_row($wpdb->prepare(
			"SELECT c.*, u.display_name as referrer_name FROM {$table} c LEFT JOIN {$wpdb->users} u ON c.referrer_id = u.ID WHERE c.id = %d",
			$id
		));

		if (!$c) {
			echo '<div class="cbnexus-card"><p>Candidate not found.</p></div>';
			return;
		}
		?>
		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h2>Edit Candidate</h2>
				<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment')); ?>" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm">← Back</a>
			</div>

			<?php
			// Acceptance was attempted but the candidate is missing fields needed
			// to create their member account — show exactly what to fill in.
			$accept_missing = get_transient('cbnexus_recruit_accept_missing_' . $id);
			if (is_array($accept_missing) && !empty($accept_missing)) :
				delete_transient('cbnexus_recruit_accept_missing_' . $id);
			?>
				<div class="cbnexus-portal-notice cbnexus-notice-error" style="margin-top:12px;">
					<strong>Can't accept <?php echo esc_html($c->name); ?> yet.</strong>
					Accepting a candidate creates their member account, which needs:
					<strong><?php echo esc_html(implode(', ', $accept_missing)); ?></strong>.
					Fill in the missing field<?php echo count($accept_missing) === 1 ? '' : 's'; ?> below,
					then set the Stage to <em>Accepted</em> and save.
				</div>
			<?php endif; ?>

			<form method="post" style="max-width:600px;margin-top:12px;">
				<?php wp_nonce_field('cbnexus_portal_save_candidate'); ?>
				<input type="hidden" name="candidate_id" value="<?php echo esc_attr($c->id); ?>" />

				<div style="display:flex;flex-direction:column;gap:12px;">
					<div style="display:flex;gap:12px;">
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Name *</label>
							<input type="text" name="name" value="<?php echo esc_attr($c->name); ?>" class="cbnexus-input" style="width:100%;" required />
						</div>
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Email</label>
							<input type="email" name="email" value="<?php echo esc_attr($c->email); ?>" class="cbnexus-input" style="width:100%;" />
						</div>
					</div>
					<div style="display:flex;gap:12px;">
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Company</label>
							<input type="text" name="company" value="<?php echo esc_attr($c->company); ?>" class="cbnexus-input" style="width:100%;" />
						</div>
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Job Title</label>
							<input type="text" name="title" value="<?php echo esc_attr($c->title ?? ''); ?>" class="cbnexus-input" style="width:100%;" />
						</div>
					</div>
					<div style="display:flex;gap:12px;">
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Industry</label>
							<input type="text" name="industry" value="<?php echo esc_attr($c->industry); ?>" class="cbnexus-input" style="width:100%;" />
						</div>
						<div style="flex:1;"></div>
					</div>
					<div style="display:flex;gap:12px;">
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Stage</label>
							<select name="stage" class="cbnexus-input" style="width:100%;">
								<?php foreach (self::$recruit_stages as $key => $label) : ?>
									<option value="<?php echo esc_attr($key); ?>" <?php selected($c->stage, $key); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Referred By</label>
							<select name="referrer_id" class="cbnexus-input" style="width:100%;">
								<option value="0">—</option>
								<?php foreach ($members as $m) : ?>
									<option value="<?php echo esc_attr($m['user_id']); ?>" <?php selected((int) $c->referrer_id, $m['user_id']); ?>><?php echo esc_html($m['display_name']); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;">Category</label>
						<?php
						global $wpdb;
						$cat_table_edit = $wpdb->prefix . 'cb_recruitment_categories';
						$need_cats_edit = $wpdb->get_results("SELECT id, title FROM {$cat_table_edit} ORDER BY title ASC") ?: [];
						?>
						<select name="category_id" class="cbnexus-input" style="width:100%;">
							<option value="0">—</option>
							<?php foreach ($need_cats_edit as $nc) : ?>
								<option value="<?php echo esc_attr($nc->id); ?>" <?php selected((int) ($c->category_id ?? 0), (int) $nc->id); ?>><?php echo esc_html($nc->title); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="cbnexus-admin-meta" style="display:block;margin-top:4px;">Which recruitment need is this candidate for?</span>
					</div>
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;">Notes</label>
						<textarea name="notes" rows="3" class="cbnexus-input" style="width:100%;"><?php echo esc_textarea($c->notes); ?></textarea>
					</div>
				</div>

				<div style="margin-top:16px;display:flex;gap:8px;">
					<button type="submit" name="cbnexus_portal_save_candidate" value="1" class="cbnexus-btn cbnexus-btn-primary">Update Candidate</button>
					<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment')); ?>" class="cbnexus-btn cbnexus-btn-outline">Cancel</a>
				</div>
			</form>

			<div style="margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:13px;color:#6b7280;">
				Added <?php echo esc_html(date_i18n('M j, Y', strtotime($c->created_at))); ?>
				· Last updated <?php echo esc_html(date_i18n('M j, Y g:i A', strtotime($c->updated_at))); ?>
				<?php
				$fb = get_option('cbnexus_visit_feedback_' . $c->id);
				if ($fb && is_array($fb) && !empty($fb['label'])) :
				?>
					<div style="margin-top:8px;padding:10px 14px;background:#f8f5fa;border-radius:8px;color:#4a154b;font-size:13px;">
						<strong>📊 Visit Feedback:</strong> <?php echo esc_html($fb['label']); ?>
						<span style="color:#a094a8;margin-left:6px;">(<?php echo esc_html(date_i18n('M j, Y', strtotime($fb['answered_at']))); ?>)</span>
					</div>
				<?php endif; ?>
				<?php
				$state = self::get_action_state($c);
				if (!empty($state['code']) && $state['code'] !== 'clear') :
				?>
					<div style="margin-top:8px;padding:10px 14px;background:#fff;border:1px solid <?php echo esc_attr($state['color']); ?>;border-left:4px solid <?php echo esc_attr($state['color']); ?>;border-radius:6px;font-size:13px;color:<?php echo esc_attr($state['color']); ?>;font-weight:600;">
						<?php echo esc_html($state['label']); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php self::render_candidate_timeline((int) $c->id); ?>
		</div>
		<?php
	}

	/**
	 * Render the per-candidate event timeline (stage changes, emails sent,
	 * feedback received). Replaces the recruiter-targeted email log.
	 */
	private static function render_candidate_timeline(int $candidate_id): void {
		$events = CBNexus_Candidate_Event_Repository::get_for_candidate($candidate_id, 100);
		?>
		<div class="cbnexus-card" style="margin-top:12px;">
			<h3 style="margin:0 0 12px;">📜 Activity Timeline</h3>
			<?php if (empty($events)) : ?>
				<p class="cbnexus-admin-meta">No activity recorded yet.</p>
			<?php else : ?>
				<ul style="list-style:none;margin:0;padding:0;border-left:2px solid #e5e7eb;">
				<?php foreach ($events as $ev) :
					$icon_map = [
						'stage_change'        => '➡️',
						'email_sent'          => '✉️',
						'email_skipped'       => '⚠️',
						'feedback_received'   => '💬',
						'council_review_sent' => '👥',
					];
					$icon = $icon_map[$ev->event_type] ?? '•';
				?>
					<li style="position:relative;padding:8px 0 8px 22px;font-size:13px;color:#333;">
						<span style="position:absolute;left:-9px;top:10px;width:16px;height:16px;background:#fff;border:2px solid #c4b5d6;border-radius:50%;text-align:center;font-size:10px;line-height:12px;"><?php echo esc_html($icon); ?></span>
						<div><?php echo esc_html($ev->message); ?></div>
						<div class="cbnexus-admin-meta" style="font-size:11px;color:#9ca3af;margin-top:2px;">
							<?php echo esc_html(date_i18n('M j, Y · g:i A', strtotime($ev->created_at))); ?>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Action Handlers ────────────────────────────────────────────────

	/**
	 * Sanitize the shared candidate contact fields from $_POST.
	 *
	 * Single source of truth for the add/edit handlers so the field list,
	 * unslashing, and sanitizers can't drift between them. Returns an
	 * associative array whose key order matches the wpdb format list
	 * ['%s','%s','%s','%s','%s'].
	 *
	 * @return array{name:string,email:string,company:string,title:string,industry:string}
	 */
	private static function sanitize_candidate_fields(): array {
		return [
			'name'     => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
			'email'    => sanitize_email(wp_unslash($_POST['email'] ?? '')),
			'company'  => sanitize_text_field(wp_unslash($_POST['company'] ?? '')),
			'title'    => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
			'industry' => sanitize_text_field(wp_unslash($_POST['industry'] ?? '')),
		];
	}

	public static function handle_save_candidate(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'cbnexus_portal_save_candidate')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';
		$id    = absint($_POST['candidate_id'] ?? 0);
		$new_stage = sanitize_key($_POST['stage'] ?? 'referral');

		$candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
		if (!$candidate) { return; }

		$old_stage = $candidate->stage;

		$fields = self::sanitize_candidate_fields();

		// Gate acceptance: accepting a candidate creates their member account,
		// which needs enough data. If anything is missing, don't accept yet —
		// keep the current stage and send the recruiter back here to complete it.
		$accept_blocked = false;
		if ($new_stage === 'accepted' && $old_stage !== 'accepted') {
			$blockers = self::get_member_conversion_blockers((object) $fields);
			if (!empty($blockers)) {
				$accept_blocked = true;
				$new_stage = $old_stage; // hold the candidate where it is
				set_transient('cbnexus_recruit_accept_missing_' . $id, $blockers, 120);
			}
		}

		$wpdb->update($table, array_merge($fields, [
			'category_id' => absint($_POST['category_id'] ?? 0) ?: null,
			'referrer_id' => absint($_POST['referrer_id'] ?? 0) ?: null,
			'stage'       => $new_stage,
			'notes'       => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
			'updated_at'  => gmdate('Y-m-d H:i:s'),
		]), ['id' => $id], ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'], ['%d']);

		// The recruiter tried to accept but the account can't be created yet —
		// bounce back to the edit form where the missing-fields banner renders.
		if ($accept_blocked) {
			wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['edit_candidate' => $id]));
			exit;
		}

		$stage_changed = ($old_stage !== $new_stage);
		if ($stage_changed) {
			$before_id = self::get_last_event_id($id);
			$updated = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
			if ($updated) {
				self::run_recruitment_automations($updated, $old_stage, $new_stage);
			}
			self::stash_stage_change_events($id, $candidate->name, $before_id);
		}

		// Check for conversion errors to surface to the admin.
		$convert_err = get_transient('cbnexus_recruit_convert_error_' . $id);
		if ($convert_err) {
			delete_transient('cbnexus_recruit_convert_error_' . $id);
			wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', [
				'pa_notice'    => 'candidate_convert_failed',
				'candidate_id' => $id,
			]));
			exit;
		}

		$args = ['pa_notice' => $stage_changed ? 'stage_changed' : 'candidate_saved'];
		if ($stage_changed) { $args['candidate_id'] = $id; }
		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', $args));
		exit;
	}

	public static function handle_add_candidate(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'cbnexus_portal_add_candidate')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$now = gmdate('Y-m-d H:i:s');

		$wpdb->insert($wpdb->prefix . 'cb_candidates', array_merge(self::sanitize_candidate_fields(), [
			'category_id' => absint($_POST['category_id'] ?? 0) ?: null,
			'referrer_id' => absint($_POST['referrer_id'] ?? 0) ?: null,
			'stage'       => 'referral',
			'notes'       => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')),
			'created_at'  => $now,
			'updated_at'  => $now,
		]), ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s']);

		// Notify referrer that their prospect was received.
		$new_id = $wpdb->insert_id;
		if ($new_id) {
			$candidate = $wpdb->get_row($wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cb_candidates WHERE id = %d", $new_id
			));
			if ($candidate) {
				self::run_recruitment_automations($candidate, '', 'referral');
			}
		}

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'candidate_added']));
		exit;
	}

	public static function handle_update_candidate(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'cbnexus_portal_update_candidate')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';
		$id    = absint($_POST['candidate_id'] ?? 0);
		$new_stage = sanitize_key($_POST['stage'] ?? 'referral');
		$notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));

		$candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
		if (!$candidate) { return; }

		$old_stage = $candidate->stage;

		// Gate acceptance from the quick stage dropdown. The dropdown only posts
		// the stage, so validate against the stored candidate record. If the
		// member account can't be created yet, save the notes but hold the stage
		// and send the recruiter to the edit form to fill in what's missing.
		if ($new_stage === 'accepted' && $old_stage !== 'accepted') {
			$blockers = self::get_member_conversion_blockers($candidate);
			if (!empty($blockers)) {
				$wpdb->update($table, [
					'notes'      => $notes,
					'updated_at' => gmdate('Y-m-d H:i:s'),
				], ['id' => $id], ['%s', '%s'], ['%d']);
				set_transient('cbnexus_recruit_accept_missing_' . $id, $blockers, 120);
				wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['edit_candidate' => $id]));
				exit;
			}
		}

		$wpdb->update($table, [
			'stage'      => $new_stage,
			'notes'      => $notes,
			'updated_at' => gmdate('Y-m-d H:i:s'),
		], ['id' => $id], ['%s', '%s', '%s'], ['%d']);

		$stage_changed = ($old_stage !== $new_stage);
		if ($stage_changed) {
			// Snapshot the event id high-water-mark so we can list everything
			// the automation logs as a result of this stage change.
			$before_id = self::get_last_event_id($id);

			// Re-fetch so automations see the updated notes/stage.
			$updated = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
			if ($updated) {
				self::run_recruitment_automations($updated, $old_stage, $new_stage);
			}

			// Stash the events that just fired for the post-redirect notice.
			self::stash_stage_change_events($id, $candidate->name, $before_id);
		}

		// Check for conversion errors to surface to the admin.
		$convert_err = get_transient('cbnexus_recruit_convert_error_' . $id);
		if ($convert_err) {
			delete_transient('cbnexus_recruit_convert_error_' . $id);
			wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', [
				'pa_notice'    => 'candidate_convert_failed',
				'candidate_id' => $id,
			]));
			exit;
		}

		$args = ['pa_notice' => $stage_changed ? 'stage_changed' : 'candidate_updated'];
		if ($stage_changed) { $args['candidate_id'] = $id; }
		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', $args));
		exit;
	}

	/**
	 * Highest event id currently logged for this candidate — used as a
	 * watermark so we know which events were appended during this request.
	 */
	private static function get_last_event_id(int $candidate_id): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidate_events';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return 0;
		}
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT MAX(id) FROM {$table} WHERE candidate_id = %d", $candidate_id
		));
	}

	/**
	 * Save the list of new events as a transient for the post-redirect
	 * pop-up notice that lists what just happened.
	 */
	private static function stash_stage_change_events(int $candidate_id, string $name, int $before_id): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidate_events';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) { return; }

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT event_type, message FROM {$table}
			 WHERE candidate_id = %d AND id > %d ORDER BY id ASC",
			$candidate_id, $before_id
		)) ?: [];

		$events = [];
		foreach ($rows as $r) {
			$events[] = [
				'type'    => $r->event_type,
				'message' => $r->message,
			];
		}

		set_transient('cbnexus_portal_stage_change_' . $candidate_id, [
			'candidate_name' => $name,
			'events'         => $events,
		], 60);
	}

	// ─── Recruitment Automations ────────────────────────────────────────

	/**
	 * Public wrapper so WP-admin recruitment handler can also trigger these.
	 */
	public static function trigger_recruitment_automation(object $candidate, string $old_stage, string $new_stage): void {
		self::run_recruitment_automations($candidate, $old_stage, $new_stage);
	}

	/**
	 * Recruitment pipeline automations triggered on stage transitions.
	 *
	 * Behavior (post-revamp):
	 *   - Bob (recruiter) is NOT emailed. All "what just happened" lives on the
	 *     candidate card as a timeline via CBNexus_Candidate_Event_Repository.
	 *   - Invited  → send recruit_invitation to candidate AND to referrer (same body)
	 *   - Visited  → send recruit_visited_thankyou with Interested / Not Interested buttons
	 *   - Decision → send recruit_council_review to all active members (objection window)
	 *   - Accepted → create member, welcome email, recruit_accepted to referrer,
	 *                recruit_onboarding_handoff to the configured onboarding lead
	 *   - Declined → send recruit_declined to the candidate as polite closure
	 */
	public static function run_recruitment_automations(object $candidate, string $old_stage, string $new_stage): void {
		// ── Acceptance guard (shared by every caller) ──
		// Accepting creates a member account, which needs a minimum set of data.
		// If it's missing, refuse the transition here — revert the stored stage
		// to where it was, record why, and stash the missing fields — rather than
		// letting convert_candidate_to_member fail silently and leave the
		// candidate stranded at "accepted". The portal handlers pre-check and
		// redirect to the edit form for a nicer prompt; this backstop covers the
		// WP-admin page and any future caller. The convert-error transient is
		// what those surfaces read to show the reason.
		if ($new_stage === 'accepted' && $old_stage !== 'accepted') {
			$blockers = self::get_member_conversion_blockers($candidate);
			if (!empty($blockers)) {
				global $wpdb;
				$wpdb->update(
					$wpdb->prefix . 'cb_candidates',
					['stage' => $old_stage, 'updated_at' => gmdate('Y-m-d H:i:s')],
					['id' => (int) $candidate->id],
					['%s', '%s'],
					['%d']
				);
				set_transient('cbnexus_recruit_accept_missing_' . $candidate->id, $blockers, 120);
				set_transient(
					'cbnexus_recruit_convert_error_' . $candidate->id,
					'Missing required info: ' . implode(', ', $blockers) . '.',
					120
				);
				CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'stage_change',
					'Acceptance blocked — missing: ' . implode(', ', $blockers),
					['from' => $old_stage, 'to' => $old_stage]);
				return;
			}
		}

		$referrer        = $candidate->referrer_id ? get_userdata($candidate->referrer_id) : null;
		$stage_labels    = self::$recruit_stages;
		$candidate_first = explode(' ', trim($candidate->name))[0] ?? $candidate->name;
		$company_line    = $candidate->company ? ' (' . $candidate->company . ')' : '';

		// Always record the stage transition itself (skip on initial referral with no prior stage).
		if ($old_stage !== $new_stage) {
			$msg = $old_stage === ''
				? 'Added to pipeline as ' . ($stage_labels[$new_stage] ?? $new_stage)
				: 'Stage changed: ' . ($stage_labels[$old_stage] ?? $old_stage) . ' → ' . ($stage_labels[$new_stage] ?? $new_stage);
			CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'stage_change', $msg, [
				'from' => $old_stage,
				'to'   => $new_stage,
			]);
		}

		// ── Invited → invitation to candidate + same-body copy to referrer ──
		if ($new_stage === 'invited' && !empty($candidate->email)) {
			$invitation_notes = $candidate->notes ?: '';
			$notes_block = $invitation_notes
				? '<div style="background:#fff7ed;border-left:3px solid #c49a3c;padding:12px 16px;margin:16px 0;font-size:14px;">'
					. '<strong>📝 A note from your host:</strong> ' . esc_html($invitation_notes) . '</div>'
				: '';

			$next_cu = CBNexus_Recruitment_Settings::get_next_circleup_display();

			$invitation_vars = [
				'candidate_first_name'   => $candidate_first,
				'candidate_name'         => $candidate->name,
				'referrer_name'          => $referrer ? $referrer->display_name : 'a member of The Circle',
				'invitation_notes_block' => $notes_block,
				'next_circleup'          => $next_cu['combined'],
			];

			$sent_candidate = CBNexus_Email_Service::send('recruit_invitation', $candidate->email, $invitation_vars, [
				'related_type' => 'recruitment_invitation',
				'related_id'   => $candidate->id,
			]);
			if ($sent_candidate) {
				CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
					'Invitation email sent to candidate (' . $candidate->email . ')', ['template' => 'recruit_invitation']);
			}

			// Send the same invitation body to the referrer as well — replaces the old
			// stage-change-referrer email so the referrer sees exactly what the candidate sees.
			if ($referrer) {
				$sent_referrer = CBNexus_Email_Service::send('recruit_invitation', $referrer->user_email, $invitation_vars, [
					'recipient_id' => $referrer->ID,
					'related_type' => 'recruitment_invitation_referrer',
					'related_id'   => $candidate->id,
				]);
				if ($sent_referrer) {
					CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
						'Invitation email copy sent to referrer (' . $referrer->user_email . ')', ['template' => 'recruit_invitation']);
				}
			}
		}

		// ── Visited → thank-you with Interested / Not Interested buttons (once only) ──
		if ($new_stage === 'visited') {
			$opt_key = 'cbnexus_recruit_visited_sent_' . $candidate->id;

			if (empty($candidate->email)) {
				CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_skipped',
					'Visit thank-you skipped — candidate has no email on file.');
				if (class_exists('CBNexus_Logger')) {
					CBNexus_Logger::warning('Cannot send visit feedback survey — candidate has no email.', [
						'candidate_id' => $candidate->id,
					]);
				}
			} elseif (get_option($opt_key)) {
				if (class_exists('CBNexus_Logger')) {
					CBNexus_Logger::info('Visit feedback survey already sent; skipping.', [
						'candidate_id' => $candidate->id,
					]);
				}
			} else {
				$feedback_urls = self::generate_visit_feedback_urls((int) $candidate->id);

				$followup = $referrer
					? $referrer->display_name
					: 'The Circle Council';

				$sent = CBNexus_Email_Service::send('recruit_visited_thankyou', $candidate->email, [
					'candidate_first_name' => $candidate_first,
					'candidate_name'       => $candidate->name,
					'followup_name'        => $followup,
					'fb_interested'        => $feedback_urls['fb_interested'],
					'fb_not_interested'    => $feedback_urls['fb_not_interested'],
				], [
					'related_type' => 'recruitment_visited',
					'related_id'   => $candidate->id,
				]);

				if ($sent) {
					update_option($opt_key, gmdate('Y-m-d H:i:s'), false);
					CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
						'Post-visit email sent with Interested / Not Interested buttons', ['template' => 'recruit_visited_thankyou']);
				}
			}
		}

		// ── Decision → council review email to all active members (objection window) ──
		if ($new_stage === 'decision') {
			self::send_council_review_email($candidate);
		}

		// ── Accepted → auto-create member + notify referrer + handoff email to onboarding ──
		$accepted_referrer_emailed = false;
		if ($new_stage === 'accepted') {
			$conversion      = self::convert_candidate_to_member($candidate);
			$created_user_id = $conversion['user_id'];

			if (!empty($conversion['errors'])) {
				set_transient(
					'cbnexus_recruit_convert_error_' . $candidate->id,
					implode(' ', $conversion['errors']),
					60
				);
			}

			if ($referrer && $created_user_id) {
				$sent = CBNexus_Email_Service::send('recruit_accepted', $referrer->user_email, [
					'referrer_name'  => $referrer->display_name,
					'candidate_name' => $candidate->name,
					'portal_url'     => CBNexus_Portal_Router::get_portal_url(),
				], [
					'recipient_id' => $referrer->ID,
					'related_type' => 'recruitment_accepted',
				]);
				$accepted_referrer_emailed = true;
				if ($sent) {
					CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
						'Acceptance notice sent to referrer (' . $referrer->user_email . ')', ['template' => 'recruit_accepted']);
				}
			}

			// Onboarding handoff — to each configured onboarding recipient.
			$ob_recipients = CBNexus_Recruitment_Settings::get_onboarding_recipients();
			$ob_vars = [
				'candidate_name'         => $candidate->name,
				'candidate_email'        => $candidate->email,
				'candidate_company_line' => $company_line,
				'referrer_label'         => $referrer ? $referrer->display_name : '—',
			];
			$ob_emailed = [];
			foreach ($ob_recipients as $r) {
				$ok = CBNexus_Email_Service::send('recruit_onboarding_handoff', $r['user_email'], $ob_vars, [
					'recipient_id' => $r['user_id'],
					'related_type' => 'recruitment_onboarding_handoff',
					'related_id'   => $candidate->id,
				]);
				if ($ok) { $ob_emailed[] = $r['display_name'] . ' (' . $r['user_email'] . ')'; }
			}
			if (!empty($ob_emailed)) {
				CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
					'Onboarding handoff email sent to: ' . implode(', ', $ob_emailed),
					['template' => 'recruit_onboarding_handoff', 'recipients' => count($ob_emailed)]);
			}

			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::info('Candidate accepted and converted to member.', [
					'candidate_id' => $candidate->id,
					'candidate'    => $candidate->name,
					'new_user_id'  => $created_user_id,
				]);
			}
		}

		// ── Declined → polite closure email to the candidate ──
		if ($new_stage === 'declined' && !empty($candidate->email)) {
			$sent = CBNexus_Email_Service::send('recruit_declined', $candidate->email, [
				'candidate_first_name' => $candidate_first,
				'candidate_name'       => $candidate->name,
			], [
				'related_type' => 'recruitment_declined',
				'related_id'   => $candidate->id,
			]);
			if ($sent) {
				CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'email_sent',
					'Decline closure email sent to candidate (' . $candidate->email . ')', ['template' => 'recruit_declined']);
			}
		}
	}

	/**
	 * Send the council-review email to all active members, then record an event
	 * and stamp the candidate so the review-window timer starts ticking.
	 */
	private static function send_council_review_email(object $candidate): void {
		$members = CBNexus_Member_Repository::get_all_members('active');
		if (empty($members)) { return; }

		// Resolve the candidate's category name (if any) for inclusion in the email.
		$category_label = '—';
		if (!empty($candidate->category_id)) {
			global $wpdb;
			$found = $wpdb->get_var($wpdb->prepare(
				"SELECT title FROM {$wpdb->prefix}cb_recruitment_categories WHERE id = %d",
				(int) $candidate->category_id
			));
			if ($found) { $category_label = $found; }
		}
		if ($category_label === '—' && !empty($candidate->industry)) {
			$category_label = $candidate->industry;
		}

		$company_line = $candidate->company ? ' (' . $candidate->company . ')' : '';
		$review_hours = CBNexus_Recruitment_Settings::get_council_review_hours();

		$sent_count = 0;
		foreach ($members as $m) {
			$email = $m['user_email'] ?? '';
			if (empty($email) || !is_email($email)) { continue; }

			$ok = CBNexus_Email_Service::send('recruit_council_review', $email, [
				'candidate_name'         => $candidate->name,
				'candidate_company_line' => $company_line,
				'candidate_category'     => $category_label,
				'review_hours'           => $review_hours,
			], [
				'recipient_id' => $m['user_id'],
				'related_type' => 'recruitment_council_review',
				'related_id'   => $candidate->id,
			]);
			if ($ok) { $sent_count++; }
		}

		// Stamp the candidate so the UI can compute when the window closes.
		update_option('cbnexus_council_review_sent_' . $candidate->id, gmdate('Y-m-d H:i:s'), false);

		CBNexus_Candidate_Event_Repository::log((int) $candidate->id, 'council_review_sent',
			'Council review email sent to ' . $sent_count . ' active members — ' . $review_hours . '-hour objection window opened.', [
				'recipients'   => $sent_count,
				'review_hours' => $review_hours,
			]);
	}

	/**
	 * Determine what — if anything — prevents this candidate from being turned
	 * into a member account. Mirrors the requirements of
	 * CBNexus_Member_Service::create_member() so acceptance never silently fails.
	 *
	 * Returns a list of human-readable missing fields (empty = ready to accept).
	 * Only the values relevant to member creation are examined ($candidate may be
	 * a partial object carrying just name/email/company/title/industry).
	 *
	 * @return string[]
	 */
	private static function get_member_conversion_blockers(object $candidate): array {
		$missing = [];

		$email = trim((string) ($candidate->email ?? ''));
		if ($email === '' || !is_email($email)) {
			$missing[] = 'a valid email address';
			// Without a usable email we can't tell new-vs-existing; stop here.
			return $missing;
		}

		// When the email already belongs to a WP account, conversion promotes
		// that account instead of creating one, so the new-user field
		// requirements below don't apply.
		if (email_exists($email)) {
			return $missing;
		}

		$name  = trim((string) ($candidate->name ?? ''));
		$parts = preg_split('/\s+/', $name, 2, PREG_SPLIT_NO_EMPTY);
		if (empty($parts[0])) {
			$missing[] = 'a name';
		} elseif (empty($parts[1])) {
			$missing[] = 'a last name (enter the full name)';
		}

		if (trim((string) ($candidate->company ?? '')) === '')  { $missing[] = 'company'; }
		if (trim((string) ($candidate->title ?? '')) === '')    { $missing[] = 'job title'; }
		if (trim((string) ($candidate->industry ?? '')) === '') { $missing[] = 'industry'; }

		return $missing;
	}

	/**
	 * Convert an accepted candidate into a full The Circle member.
	 *
	 * @return array{user_id: int|null, errors: string[]}
	 */
	private static function convert_candidate_to_member(object $candidate): array {
		if (empty($candidate->email)) {
			$err = 'Cannot create member — candidate has no email address.';
			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::warning($err, [
					'candidate_id' => $candidate->id,
					'candidate'    => $candidate->name,
				]);
			}
			return ['user_id' => null, 'errors' => [$err]];
		}

		$name_parts = explode(' ', trim($candidate->name), 2);
		$first_name = $name_parts[0] ?? '';
		$last_name  = $name_parts[1] ?? '';

		$profile_data = [
			'cb_company'       => $candidate->company ?: '',
			'cb_title'         => ($candidate->title ?? '') ?: '',
			'cb_industry'      => $candidate->industry ?: '',
			'cb_referred_by'   => $candidate->referrer_id ? (get_userdata($candidate->referrer_id)->display_name ?? '') : '',
			'cb_ambassador_id' => $candidate->referrer_id ?: '',
		];

		// If the email already has a WP account, promote it to cb_member
		// instead of skipping. This handles candidates who had a subscriber
		// or other non-member account before being accepted.
		$existing_user_id = email_exists($candidate->email);
		if ($existing_user_id) {
			$user = get_userdata($existing_user_id);
			if ($user && CBNexus_Member_Repository::is_member($existing_user_id)) {
				if (class_exists('CBNexus_Logger')) {
					CBNexus_Logger::info('Accepted candidate is already a member; skipping.', [
						'candidate_id' => $candidate->id,
						'user_id'      => $existing_user_id,
					]);
				}
				return ['user_id' => $existing_user_id, 'errors' => []];
			}

			// Assign cb_member role and set up profile.
			$user->add_role('cb_member');
			$profile_data['cb_member_status'] = 'active';
			$profile_data['cb_join_date']     = gmdate('Y-m-d');
			$profile_data['cb_onboarding_stage'] = 'access_setup';
			CBNexus_Member_Repository::update_profile($existing_user_id, $profile_data);

			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::info('Existing WP user promoted to cb_member from accepted candidate.', [
					'candidate_id' => $candidate->id,
					'user_id'      => $existing_user_id,
				]);
			}

			$user_id = $existing_user_id;
		} else {
			// Create a brand-new WP user + member profile.
			$user_data = [
				'user_email'   => $candidate->email,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim($candidate->name),
			];

			$result = CBNexus_Member_Service::create_member($user_data, $profile_data, 'cb_member');

			if (!$result['success']) {
				$errors = $result['errors'] ?? ['Unknown error creating member.'];
				if (class_exists('CBNexus_Logger')) {
					CBNexus_Logger::error('Failed to auto-create member from accepted candidate.', [
						'candidate_id' => $candidate->id,
						'errors'       => $errors,
					]);
				}
				return ['user_id' => null, 'errors' => $errors];
			}

			$user_id = $result['user_id'];
		}

		$profile = CBNexus_Member_Repository::get_profile($user_id);
		if ($profile) {
			CBNexus_Email_Service::send_welcome($user_id, $profile);
		}

		return ['user_id' => $user_id, 'errors' => []];
	}

	/**
	 * Generate tokenized one-click feedback URLs for the visit survey.
	 * Post-revamp: only two answers — interested / not_interested.
	 */
	private static function generate_visit_feedback_urls(int $candidate_id): array {
		$answers = ['interested', 'not_interested'];
		$urls = [];
		foreach ($answers as $answer) {
			$token = CBNexus_Token_Service::generate(0, 'visit_feedback', [
				'candidate_id' => $candidate_id,
				'answer'       => $answer,
			], 30, false);
			$urls['fb_' . $answer] = CBNexus_Token_Service::url($token);
		}
		return $urls;
	}

	/**
	 * Match comma-separated guest names against the recruitment pipeline.
	 */
	public static function match_guest_attendees_to_pipeline(string $guest_csv): void {
		$names = array_filter(array_map('trim', explode(',', $guest_csv)));
		if (empty($names)) { return; }

		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';

		$pre_visited = ['referral', 'contacted', 'invited'];
		$placeholders = implode(',', array_fill(0, count($pre_visited), '%s'));
		$candidates = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} WHERE stage IN ({$placeholders})",
			...$pre_visited
		));

		if (empty($candidates)) { return; }

		foreach ($names as $guest_name) {
			$guest_lower = mb_strtolower($guest_name);

			foreach ($candidates as $c) {
				$candidate_lower = mb_strtolower(trim($c->name));

				$match = ($guest_lower === $candidate_lower)
					|| (mb_strlen($guest_lower) >= 3 && mb_strpos($candidate_lower, $guest_lower) !== false)
					|| (mb_strlen($candidate_lower) >= 3 && mb_strpos($guest_lower, $candidate_lower) !== false);

				if (!$match) { continue; }

				$old_stage = $c->stage;

				$wpdb->update($table, [
					'stage'      => 'visited',
					'updated_at' => gmdate('Y-m-d H:i:s'),
				], ['id' => $c->id], ['%s', '%s'], ['%d']);

				self::run_recruitment_automations($c, $old_stage, 'visited');

				if (class_exists('CBNexus_Logger')) {
					CBNexus_Logger::info('Guest attendee matched to pipeline candidate; auto-transitioned to visited.', [
						'guest_name'   => $guest_name,
						'candidate_id' => $c->id,
						'candidate'    => $c->name,
						'from_stage'   => $old_stage,
					]);
				}

				break;
			}
		}
	}

	/**
	 * Transition explicitly checked invited recruits to "visited" stage.
	 */
	public static function transition_checked_recruits_to_visited(array $candidate_ids): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';

		foreach ($candidate_ids as $cid) {
			if ($cid <= 0) { continue; }

			$candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $cid));
			if (!$candidate || $candidate->stage === 'visited') { continue; }

			$old_stage = $candidate->stage;

			$wpdb->update($table, [
				'stage'      => 'visited',
				'updated_at' => gmdate('Y-m-d H:i:s'),
			], ['id' => $cid], ['%s', '%s'], ['%d']);

			self::run_recruitment_automations($candidate, $old_stage, 'visited');

			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::info('Invited recruit checked as attending; auto-transitioned to visited.', [
					'candidate_id' => $cid,
					'candidate'    => $candidate->name,
					'from_stage'   => $old_stage,
				]);
			}
		}
	}

	// ═════════════════════════════════════════════════════════════════════
	// Recruitment Needs (Categories)
	// ═════════════════════════════════════════════════════════════════════

	private static function render_recruitment_needs(): void {
		global $wpdb;
		$table      = $wpdb->prefix . 'cb_recruitment_categories';
		$schedule   = get_option('cbnexus_recruit_blast_schedule', 'none');
		$last_blast = get_option('cbnexus_last_recruit_blast', '');
		$industries = CBNexus_Member_Service::get_industries();

		$edit_id     = absint($_GET['edit_need'] ?? 0);
		$editing_cat = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $edit_id)) : null;
		$p_colors    = ['high' => 'var(--cb-red)', 'medium' => 'var(--cb-gold)', 'low' => 'var(--cb-green)'];

		// Use coverage service for computed status.
		$categories = class_exists('CBNexus_Recruitment_Coverage_Service')
			? CBNexus_Recruitment_Coverage_Service::get_full_coverage()
			: [];
		$summary = class_exists('CBNexus_Recruitment_Coverage_Service')
			? CBNexus_Recruitment_Coverage_Service::get_summary()
			: ['total' => 0, 'covered' => 0, 'partial' => 0, 'gaps' => 0, 'coverage_pct' => 0];

		$status_icons = [
			'covered' => '✅',
			'partial' => '🟡',
			'gap'     => '🔍',
		];
		$status_labels = [
			'covered' => 'Filled',
			'partial' => 'Partial',
			'gap'     => 'Open',
		];
		$recruit_stage_labels = [
			'referral'  => 'Referral',
			'contacted' => 'Contacted',
			'invited'   => 'Invited',
			'visited'   => 'Visited',
			'decision'  => 'Decision',
		];
		?>

		<div class="cbnexus-card" style="margin-top:20px;">
			<div class="cbnexus-admin-header-row">
				<h2>🎯 Recruitment Needs</h2>
			</div>
			<p class="cbnexus-admin-meta" style="margin-bottom:12px;">Define what types of members the group is looking for. Coverage is computed automatically based on member assignments.</p>

			<?php
			$cap_filled = (int) ($summary['capacity_filled'] ?? 0);
			$cap_total  = (int) ($summary['capacity_total']  ?? 25);
			$cap_open   = max(0, $cap_total - $cap_filled);
			?>
			<!-- Capacity Bar (N of 25 — decoupled from category count) -->
			<div style="display:flex;gap:16px;align-items:center;padding:12px 16px;background:#f8f5fa;border-radius:10px;margin-bottom:16px;flex-wrap:wrap;">
				<span style="font-weight:700;color:var(--cbnexus-plum,#4a154b);font-size:15px;"><?php echo esc_html($cap_filled); ?> of <?php echo esc_html($cap_total); ?> seats filled</span>
				<span style="font-size:13px;color:#6b7280;"><?php echo esc_html($cap_open); ?> open</span>
				<?php if ($summary['total'] > 0) : ?>
					<span style="font-size:13px;color:#059669;margin-left:auto;">✅ <?php echo esc_html($summary['covered']); ?> categories filled</span>
					<?php if ($summary['partial'] > 0) : ?>
						<span style="font-size:13px;color:#d97706;">🟡 <?php echo esc_html($summary['partial']); ?> partial</span>
					<?php endif; ?>
					<span style="font-size:13px;color:#dc2626;">🔍 <?php echo esc_html($summary['gaps']); ?> open</span>
				<?php endif; ?>
			</div>

			<!-- Categories Table -->
			<div class="cbnexus-admin-table-wrap">
				<table class="cbnexus-admin-table">
					<thead><tr>
						<th>Role / Category</th><th>Industry</th><th>Priority</th><th>Status</th><th>Filled By</th><th>Pipeline</th><th>Actions</th>
					</tr></thead>
					<tbody>
					<?php if (empty($categories)) : ?>
						<tr><td colspan="7" class="cbnexus-admin-empty">No categories defined yet.</td></tr>
					<?php else : foreach ($categories as $cat) :
						$is_covered = $cat->coverage_status === 'covered';
					?>
						<tr<?php echo $is_covered ? ' style="opacity:0.6;"' : ''; ?>>
							<td>
								<strong><?php echo esc_html($cat->title); ?></strong>
								<?php if ($cat->description) : ?><br/><span class="cbnexus-admin-meta"><?php echo esc_html(wp_trim_words($cat->description, 15)); ?></span><?php endif; ?>
							</td>
							<td class="cbnexus-admin-meta"><?php echo esc_html($cat->industry ?: '—'); ?></td>
							<td><span style="color:<?php echo esc_attr($p_colors[$cat->priority] ?? 'var(--cb-text-sec)'); ?>;font-weight:600;text-transform:uppercase;font-size:11px;"><?php echo esc_html($cat->priority); ?></span></td>
							<td>
								<span style="white-space:nowrap;">
									<?php echo esc_html($status_icons[$cat->coverage_status] ?? ''); ?>
									<?php echo esc_html($status_labels[$cat->coverage_status] ?? ''); ?>
								</span>
								<div class="cbnexus-admin-meta" style="font-size:11px;"><?php echo esc_html($cat->member_count); ?> / <?php echo esc_html($cat->target_count); ?></div>
							</td>
							<td>
								<?php if (!empty($cat->members)) : ?>
									<?php foreach ($cat->members as $mem) : ?>
										<span style="display:inline-block;padding:2px 8px;background:#f3eef6;border-radius:10px;font-size:12px;color:#5b2d6e;font-weight:500;margin:2px;"><?php echo esc_html($mem['display_name']); ?></span>
									<?php endforeach; ?>
								<?php else : ?>
									<span class="cbnexus-admin-meta">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if (!empty($cat->pipeline_candidates)) : ?>
									<?php foreach ($cat->pipeline_candidates as $pc) : ?>
										<div style="font-size:12px;margin-bottom:2px;">
											<span style="display:inline-block;padding:1px 6px;background:#eff6ff;border-radius:8px;font-size:11px;color:#1d4ed8;"><?php echo esc_html($recruit_stage_labels[$pc->stage] ?? $pc->stage); ?></span>
											<?php echo esc_html($pc->name); ?>
										</div>
									<?php endforeach; ?>
								<?php else : ?>
									<span class="cbnexus-admin-meta">—</span>
								<?php endif; ?>
							</td>
							<td class="cbnexus-admin-actions-cell">
								<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment', ['edit_need' => $cat->id])); ?>" class="cbnexus-link">Edit</a>
								<a href="<?php echo esc_url(wp_nonce_url(CBNexus_Portal_Admin::admin_url('recruitment', ['cbnexus_portal_delete_need' => $cat->id]), 'cbnexus_portal_need_' . $cat->id, '_panonce')); ?>" class="cbnexus-link cbnexus-link-red" onclick="return confirm('Delete this category?');">Delete</a>
							</td>
						</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Send Blast -->
			<div style="margin-top:12px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
				<a href="<?php echo esc_url(wp_nonce_url(CBNexus_Portal_Admin::admin_url('recruitment', ['cbnexus_portal_send_needs_blast' => '1']), 'cbnexus_portal_needs_blast', '_panonce')); ?>" class="cbnexus-btn cbnexus-btn-accent" onclick="return confirm('Send recruitment needs to all active members?');">📧 Send to Members</a>
				<?php if ($last_blast) : ?><span class="cbnexus-admin-meta">Last sent: <?php echo esc_html(date_i18n('M j, Y', strtotime($last_blast))); ?></span><?php endif; ?>
				<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment', ['cleanup_categories' => 'preview'])); ?>" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm" style="margin-left:auto;">🧹 Clean Up Unused</a>
				<form method="post" style="display:flex;align-items:center;gap:8px;">
					<?php wp_nonce_field('cbnexus_portal_save_needs_schedule', '_panonce_schedule'); ?>
					<label class="cbnexus-admin-meta" style="white-space:nowrap;">Auto-send:</label>
					<select name="needs_schedule" class="cbnexus-input" style="width:auto;">
						<option value="none" <?php selected($schedule, 'none'); ?>>Manual only</option>
						<option value="weekly" <?php selected($schedule, 'weekly'); ?>>Weekly</option>
						<option value="monthly" <?php selected($schedule, 'monthly'); ?>>Monthly</option>
					</select>
					<button type="submit" name="cbnexus_portal_save_needs_schedule" value="1" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm">Save</button>
				</form>
			</div>

			<?php
			$cleanup_view = sanitize_key($_GET['cleanup_categories'] ?? '');
			if ($cleanup_view === 'preview') {
				self::render_cleanup_preview();
			}
			?>
		</div>

		<!-- Add / Edit Form -->
		<div class="cbnexus-card" style="margin-top:12px;">
			<h3><?php echo $editing_cat ? '✏️ Edit Category' : '➕ Add Category'; ?></h3>
			<form method="post" style="max-width:600px;">
				<?php if ($editing_cat) : ?>
					<?php wp_nonce_field('cbnexus_portal_update_need', '_panonce'); ?>
					<input type="hidden" name="need_id" value="<?php echo esc_attr($editing_cat->id); ?>" />
				<?php else : ?>
					<?php wp_nonce_field('cbnexus_portal_add_need', '_panonce'); ?>
				<?php endif; ?>
				<div style="display:flex;flex-direction:column;gap:12px;margin-top:12px;">
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;">Title / Role *</label>
						<input type="text" name="need_title" value="<?php echo esc_attr($editing_cat->title ?? ''); ?>" class="cbnexus-input" style="width:100%;" required placeholder="e.g. Financial Advisor, Healthcare Executive" />
					</div>
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;">Description</label>
						<textarea name="need_description" rows="2" class="cbnexus-input" style="width:100%;" placeholder="What qualities or background are we looking for?"><?php echo esc_textarea($editing_cat->description ?? ''); ?></textarea>
					</div>
					<div style="display:flex;gap:12px;">
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Industry</label>
							<select name="need_industry" class="cbnexus-input">
								<option value="">— Any —</option>
								<?php foreach ($industries as $ind) : ?>
									<option value="<?php echo esc_attr($ind); ?>" <?php selected($editing_cat->industry ?? '', $ind); ?>><?php echo esc_html($ind); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div style="flex:1;">
							<label style="display:block;font-weight:600;margin-bottom:4px;">Priority</label>
							<select name="need_priority" class="cbnexus-input">
								<option value="high" <?php selected($editing_cat->priority ?? '', 'high'); ?>>🔴 High</option>
								<option value="medium" <?php selected($editing_cat->priority ?? 'medium', 'medium'); ?>>🟡 Medium</option>
								<option value="low" <?php selected($editing_cat->priority ?? '', 'low'); ?>>🟢 Low</option>
							</select>
						</div>
					</div>
					<div style="width:120px;">
						<label style="display:block;font-weight:600;margin-bottom:4px;">Target Count</label>
						<input type="number" name="need_target_count" min="1" max="10" value="<?php echo esc_attr($editing_cat->target_count ?? 1); ?>" class="cbnexus-input" style="width:100%;" />
						<span class="cbnexus-admin-meta" style="display:block;margin-top:4px;">Members needed</span>
					</div>
				</div>
				<div style="margin-top:16px;display:flex;gap:8px;">
					<button type="submit" name="<?php echo $editing_cat ? 'cbnexus_portal_update_need' : 'cbnexus_portal_add_need'; ?>" value="1" class="cbnexus-btn cbnexus-btn-primary"><?php echo $editing_cat ? 'Update' : 'Add Category'; ?></button>
					<?php if ($editing_cat) : ?>
						<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('recruitment')); ?>" class="cbnexus-btn cbnexus-btn-outline">Cancel</a>
					<?php endif; ?>
				</div>
			</form>
		</div>

		<?php self::render_focus_settings(); ?>
		<?php self::render_onboarding_recipients(); ?>
		<?php
	}

	// ─── Onboarding Recipients Settings ──────────────────────────────

	/**
	 * Render the multi-select for "who gets the handoff email when a
	 * candidate is accepted." Defaults to the first cb_admin if nothing
	 * is configured (see CBNexus_Recruitment_Settings::get_onboarding_recipients()).
	 */
	private static function render_onboarding_recipients(): void {
		$selected_ids = CBNexus_Recruitment_Settings::get_onboarding_user_ids();
		$selected_set = array_flip($selected_ids);
		$members      = CBNexus_Recruitment_Settings::get_eligible_onboarding_members();
		?>
		<div class="cbnexus-card" style="margin-top:12px;">
			<h3>📬 Onboarding Recipients</h3>
			<p class="cbnexus-admin-meta" style="margin:0 0 14px;">
				When a candidate moves to <strong>Accepted</strong>, every council member selected below gets the onboarding handoff email
				(contact info, suggested next steps). Only admins and super admins are eligible.
				Hold Ctrl/Cmd to select multiple.
				If you leave it blank, the first admin user gets the email so it doesn\'t get dropped.
			</p>

			<form method="post" style="max-width:520px;">
				<?php wp_nonce_field('cbnexus_portal_save_onboarding_recipients', '_panonce_ob'); ?>
				<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;">Members</label>
				<select name="onboarding_user_ids[]" multiple size="<?php echo esc_attr(min(10, max(4, count($members)))); ?>" class="cbnexus-input" style="width:100%;">
					<?php foreach ($members as $m) :
						$uid = (int) $m['user_id'];
					?>
						<option value="<?php echo esc_attr($uid); ?>" <?php echo isset($selected_set[$uid]) ? 'selected' : ''; ?>>
							<?php echo esc_html($m['display_name']); ?> &lt;<?php echo esc_html($m['user_email']); ?>&gt;
						</option>
					<?php endforeach; ?>
				</select>
				<div style="margin-top:12px;">
					<button type="submit" name="cbnexus_portal_save_onboarding_recipients" value="1" class="cbnexus-btn cbnexus-btn-primary">Save Recipients</button>
				</div>
			</form>

			<?php $current = CBNexus_Recruitment_Settings::get_onboarding_recipients(); ?>
			<div class="cbnexus-admin-meta" style="margin-top:14px;font-size:12px;">
				<strong>Currently emailing:</strong>
				<?php if (empty($current)) : ?>
					<em>nobody (fallback active — no admins found)</em>
				<?php else : ?>
					<?php
					$labels = array_map(function ($r) {
						return $r['display_name'] . ' (' . $r['user_email'] . ')';
					}, $current);
					echo esc_html(implode(', ', $labels));
					if (empty($selected_ids)) {
						echo ' <em>(fallback — no explicit recipients configured)</em>';
					}
					?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function handle_save_onboarding_recipients(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce_ob'] ?? ''), 'cbnexus_portal_save_onboarding_recipients')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		$ids = $_POST['onboarding_user_ids'] ?? [];
		if (!is_array($ids)) { $ids = []; }

		CBNexus_Recruitment_Settings::save_onboarding_user_ids($ids);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'onboarding_saved']));
		exit;
	}

	// ─── Monthly Focus Settings ──────────────────────────────────────

	/**
	 * Render the Monthly Focus configuration card.
	 */
	private static function render_focus_settings(): void {
		if (!class_exists('CBNexus_Recruitment_Coverage_Service')) {
			return;
		}

		$settings   = CBNexus_Recruitment_Coverage_Service::get_focus_settings();
		$focus_meta = CBNexus_Recruitment_Coverage_Service::get_focus_meta();
		$focus_ids  = $focus_meta['category_ids'] ?? [];
		$has_focus  = CBNexus_Recruitment_Coverage_Service::has_active_focus();
		$next_run   = wp_next_scheduled('cbnexus_recruitment_focus_rotate');

		// Get the current focus category titles for display.
		$focus_titles = [];
		if (!empty($focus_ids)) {
			global $wpdb;
			$placeholders = implode(',', array_fill(0, count($focus_ids), '%d'));
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id, title, priority FROM {$wpdb->prefix}cb_recruitment_categories WHERE id IN ({$placeholders}) ORDER BY FIELD(priority, 'high','medium','low')",
				...$focus_ids
			));
			$focus_titles = $rows ?: [];
		}

		$p_dots = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#059669'];
		?>
		<div class="cbnexus-card" style="margin-top:12px;">
			<h3>🔄 Monthly Recruitment Focus</h3>
			<p class="cbnexus-admin-meta" style="margin:0 0 14px;">
				Each month, two days before the CircleUp meeting (4th Wednesday), a set of recruitment categories is randomly selected as the group's focus.
				These focus categories replace the default "Who We're Looking For" content on the Home tab, Directory, Club Stats, and in email prompts.
				Once coverage reaches the threshold below, focus rotation pauses automatically.
			</p>

			<?php if ($has_focus && !empty($focus_titles)) : ?>
			<!-- Current Focus -->
			<div style="margin-bottom:16px;padding:12px 16px;background:#faf6fc;border:1px solid #e9e3ed;border-radius:8px;">
				<div style="font-size:12px;font-weight:600;color:#5b2d6e;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Current Focus (since <?php echo esc_html(date_i18n('M j', strtotime($focus_meta['rotated_at']))); ?>)</div>
				<div style="display:flex;gap:8px;flex-wrap:wrap;">
					<?php foreach ($focus_titles as $ft) : ?>
						<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;background:#fff;border:1px solid #e9e3ed;border-radius:8px;font-size:13px;font-weight:500;">
							<span style="width:7px;height:7px;border-radius:50%;background:<?php echo esc_attr($p_dots[$ft->priority] ?? '#d97706'); ?>;"></span>
							<?php echo esc_html($ft->title); ?>
						</span>
					<?php endforeach; ?>
				</div>
				<?php if ($focus_meta['next_circleup']) : ?>
					<div class="cbnexus-admin-meta" style="margin-top:8px;">Next CircleUp: <?php echo esc_html(date_i18n('l, M j', strtotime($focus_meta['next_circleup']))); ?></div>
				<?php endif; ?>
			</div>
			<?php elseif (!empty($focus_meta['rotated_at']) && !empty($focus_meta['skipped'])) : ?>
			<div style="margin-bottom:16px;padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#166534;">
				✅ Focus rotation paused — coverage is above the threshold.
			</div>
			<?php else : ?>
			<div style="margin-bottom:16px;padding:10px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;font-size:13px;color:#92400e;">
				No focus categories selected yet. The cron job will pick them automatically, or you can trigger a rotation manually below.
			</div>
			<?php endif; ?>

			<!-- Settings Form -->
			<form method="post" style="max-width:500px;">
				<?php wp_nonce_field('cbnexus_portal_save_focus_settings', '_panonce_focus'); ?>
				<div style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px;">
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px;">Categories per month</label>
						<input type="number" name="focus_count" min="1" max="10" value="<?php echo esc_attr($settings['count']); ?>" class="cbnexus-input" style="width:80px;" />
						<span class="cbnexus-admin-meta" style="display:block;margin-top:3px;">How many categories to highlight each cycle</span>
					</div>
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px;">Coverage pause threshold</label>
						<div style="display:flex;align-items:center;gap:4px;">
							<input type="number" name="focus_threshold" min="50" max="100" step="5" value="<?php echo esc_attr($settings['coverage_threshold']); ?>" class="cbnexus-input" style="width:80px;" />
							<span style="font-size:14px;font-weight:500;">%</span>
						</div>
						<span class="cbnexus-admin-meta" style="display:block;margin-top:3px;">Stop rotating when coverage reaches this level</span>
					</div>
				</div>
				<div style="display:flex;gap:8px;align-items:center;">
					<button type="submit" name="cbnexus_portal_save_focus_settings" value="1" class="cbnexus-btn cbnexus-btn-primary">Save Focus Settings</button>
					<a href="<?php echo esc_url(wp_nonce_url(CBNexus_Portal_Admin::admin_url('recruitment', ['cbnexus_portal_rotate_focus' => '1']), 'cbnexus_portal_rotate_focus', '_panonce')); ?>" class="cbnexus-btn cbnexus-btn-outline" onclick="return confirm('Rotate focus categories now? This will replace the current selection.');">🔄 Rotate Now</a>
				</div>
			</form>

			<?php if ($next_run) : ?>
			<div class="cbnexus-admin-meta" style="margin-top:10px;">
				Next automatic rotation: <?php echo esc_html(date_i18n('l, M j · g:i a', $next_run)); ?>
				· <a href="<?php echo esc_url(add_query_arg(['section' => 'manage', 'admin_tab' => 'settings'], CBNexus_Portal_Router::get_portal_url())); ?>" class="cbnexus-link">Adjust schedule →</a>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle saving focus settings.
	 */
	public static function handle_save_focus_settings(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce_focus'] ?? ''), 'cbnexus_portal_save_focus_settings')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		CBNexus_Recruitment_Coverage_Service::save_focus_settings([
			'count'              => absint($_POST['focus_count'] ?? 3),
			'coverage_threshold' => absint($_POST['focus_threshold'] ?? 80),
		]);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'focus_saved']));
		exit;
	}

	/**
	 * Handle manual focus rotation.
	 */
	public static function handle_rotate_focus(): void {
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_rotate_focus')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		CBNexus_Recruitment_Coverage_Service::rotate_focus();

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'focus_rotated']));
		exit;
	}

	// ─── Recruitment Needs Action Handlers ────────────────────────────

	public static function handle_add_need(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce'] ?? ''), 'cbnexus_portal_add_need')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$table    = $wpdb->prefix . 'cb_recruitment_categories';
		$now      = gmdate('Y-m-d H:i:s');
		$max_sort = (int) $wpdb->get_var("SELECT MAX(sort_order) FROM {$table}") + 1;

		$wpdb->insert($table, [
			'title'        => sanitize_text_field(wp_unslash($_POST['need_title'] ?? '')),
			'description'  => sanitize_textarea_field(wp_unslash($_POST['need_description'] ?? '')),
			'industry'     => sanitize_text_field($_POST['need_industry'] ?? ''),
			'priority'     => in_array($_POST['need_priority'] ?? '', ['high', 'medium', 'low'], true) ? $_POST['need_priority'] : 'medium',
			'target_count' => max(1, absint($_POST['need_target_count'] ?? 1)),
			'sort_order'   => $max_sort,
			'created_by'   => get_current_user_id(),
			'created_at'   => $now,
			'updated_at'   => $now,
		]);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'need_added']));
		exit;
	}

	public static function handle_update_need(): void {
		$id = absint($_POST['need_id'] ?? 0);
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce'] ?? ''), 'cbnexus_portal_update_need')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$wpdb->update($wpdb->prefix . 'cb_recruitment_categories', [
			'title'        => sanitize_text_field(wp_unslash($_POST['need_title'] ?? '')),
			'description'  => sanitize_textarea_field(wp_unslash($_POST['need_description'] ?? '')),
			'industry'     => sanitize_text_field($_POST['need_industry'] ?? ''),
			'priority'     => in_array($_POST['need_priority'] ?? '', ['high', 'medium', 'low'], true) ? $_POST['need_priority'] : 'medium',
			'target_count' => max(1, absint($_POST['need_target_count'] ?? 1)),
			'updated_at'   => gmdate('Y-m-d H:i:s'),
		], ['id' => $id]);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'need_updated']));
		exit;
	}

	public static function handle_toggle_need(): void {
		$id = absint($_GET['cbnexus_portal_toggle_need'] ?? 0);
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_need_' . $id)) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$table   = $wpdb->prefix . 'cb_recruitment_categories';
		$current = (int) $wpdb->get_var($wpdb->prepare("SELECT is_filled FROM {$table} WHERE id = %d", $id));
		$wpdb->update($table, ['is_filled' => $current ? 0 : 1], ['id' => $id]);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'need_toggled']));
		exit;
	}

	public static function handle_delete_need(): void {
		$id = absint($_GET['cbnexus_portal_delete_need'] ?? 0);
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_need_' . $id)) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'cb_recruitment_categories', ['id' => $id]);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'need_deleted']));
		exit;
	}

	public static function handle_send_needs_blast(): void {
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_needs_blast')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		CBNexus_Admin_Recruitment_Categories::send_blast();

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'needs_blast_sent']));
		exit;
	}

	public static function handle_save_needs_schedule(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce_schedule'] ?? ''), 'cbnexus_portal_save_needs_schedule')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		$freq = sanitize_key($_POST['needs_schedule'] ?? 'none');
		update_option('cbnexus_recruit_blast_schedule', $freq);

		wp_clear_scheduled_hook('cbnexus_recruitment_blast');
		if ($freq !== 'none') {
			wp_schedule_event(time(), $freq, 'cbnexus_recruitment_blast');
		}

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'needs_schedule_saved']));
		exit;
	}

	// ─── Categories Cleanup (UI) ─────────────────────────────────────

	/**
	 * Preview card listing categories the cleanup would delete + a confirm button.
	 */
	private static function render_cleanup_preview(): void {
		$unused = CBNexus_Recruitment_Categories_Cleanup::find_unused();
		$back_url = CBNexus_Portal_Admin::admin_url('recruitment');
		?>
		<div class="cbnexus-card" style="margin-top:12px;border:2px solid #c49a3c;">
			<div class="cbnexus-admin-header-row">
				<h3 style="margin:0;">🧹 Clean Up Unused Categories</h3>
				<a href="<?php echo esc_url($back_url); ?>" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm">← Close</a>
			</div>
			<p class="cbnexus-admin-meta" style="margin:8px 0 14px;">
				A category is "unused" if no active pipeline candidate AND no member is tagged with it.
				Accepted and declined candidates are ignored.
				<strong>This action cannot be undone.</strong>
			</p>

			<?php if (empty($unused)) : ?>
				<p style="font-size:14px;color:#166534;background:#f0fdf4;padding:12px 16px;border-radius:8px;border:1px solid #bbf7d0;margin:0;">
					✅ Nothing to clean up — every category is referenced.
				</p>
			<?php else : ?>
				<p style="font-size:14px;color:#333;margin:0 0 10px;">
					<strong><?php echo count($unused); ?></strong> categor<?php echo count($unused) === 1 ? 'y' : 'ies'; ?> will be deleted:
				</p>
				<ul style="margin:0 0 16px;padding:0 0 0 18px;font-size:13px;color:#333;max-height:300px;overflow-y:auto;">
					<?php foreach ($unused as $cat) : ?>
						<li style="margin:2px 0;"><?php echo esc_html($cat->title); ?></li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="">
					<?php wp_nonce_field('cbnexus_portal_cleanup_categories', '_panonce_cleanup'); ?>
					<button type="submit" name="cbnexus_portal_cleanup_categories" value="1" class="cbnexus-btn cbnexus-btn-primary" style="background:#dc2626;border-color:#dc2626;" onclick="return confirm('Delete <?php echo (int) count($unused); ?> unused categories? This cannot be undone.');">
						Delete <?php echo (int) count($unused); ?> Categor<?php echo count($unused) === 1 ? 'y' : 'ies'; ?>
					</button>
					<a href="<?php echo esc_url($back_url); ?>" class="cbnexus-btn cbnexus-btn-outline" style="margin-left:8px;">Cancel</a>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_cleanup_categories(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_panonce_cleanup'] ?? ''), 'cbnexus_portal_cleanup_categories')) { return; }
		if (!current_user_can('cbnexus_manage_members')) { return; }

		$result = CBNexus_Recruitment_Categories_Cleanup::delete_unused();

		set_transient('cbnexus_portal_cleanup_result', $result, 60);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('recruitment', ['pa_notice' => 'categories_cleaned']));
		exit;
	}
}