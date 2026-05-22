<?php
/**
 * Portal Admin – Emails Tab (super-admin)
 *
 * Extracted from class-portal-admin.php for maintainability.
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Admin_Emails {

	private static $email_templates = [
		'welcome_member'                => ['name' => 'Welcome New Member',             'group' => 'Members'],
		'reactivation_member'           => ['name' => 'Member Reactivation',            'group' => 'Members'],
		'meeting_request_received'      => ['name' => 'Meeting Request Received',       'group' => '1:1 Meetings'],
		'meeting_request_sent'          => ['name' => 'Meeting Request Sent',           'group' => '1:1 Meetings'],
		'meeting_accepted'              => ['name' => 'Meeting Accepted',               'group' => '1:1 Meetings'],
		'meeting_declined'              => ['name' => 'Meeting Declined',               'group' => '1:1 Meetings'],
		'meeting_reminder'              => ['name' => 'Meeting Reminder',               'group' => '1:1 Meetings'],
		'meeting_notes_request'         => ['name' => 'Notes Request',                  'group' => '1:1 Meetings'],
		'suggestion_match'              => ['name' => 'Monthly Match',                  'group' => 'Matching'],
		'suggestion_reminder'           => ['name' => 'Match Reminder',                 'group' => 'Matching'],
		'circleup_summary'              => ['name' => 'CircleUp Recap',                 'group' => 'CircleUp'],
		'circleup_forward'              => ['name' => 'CircleUp Forward',               'group' => 'CircleUp'],
		'event_reminder'                => ['name' => 'Event Reminder',                 'group' => 'Events'],
		'event_submitted_confirmation'  => ['name' => 'Event Submission Confirmation',  'group' => 'Events'],
		'event_approved'                => ['name' => 'Event Approved',                 'group' => 'Events'],
		'event_denied'                  => ['name' => 'Event Denied',                   'group' => 'Events'],
		'event_pending'                 => ['name' => 'Event Pending Review',           'group' => 'Events'],
		'events_digest'                 => ['name' => 'Events Digest',                  'group' => 'Events'],
		'monthly_admin_report'          => ['name' => 'Monthly Admin Report',           'group' => 'Admin'],
		'recruitment_categories'        => ['name' => 'Recruitment Categories Blast',   'group' => 'Recruitment'],
		'recruit_stage_referrer'        => ['name' => 'Referrer Stage Update',          'group' => 'Recruitment'],
		'recruit_invitation'            => ['name' => 'Candidate Invitation',           'group' => 'Recruitment'],
		'recruit_accepted'              => ['name' => 'Candidate Accepted',             'group' => 'Recruitment'],
		'recruit_visited_thankyou'      => ['name' => 'Visit Thank You',                'group' => 'Recruitment'],
		'recruit_feedback_referrer'     => ['name' => 'Feedback Received (Referrer)',    'group' => 'Recruitment'],
		'recruit_council_review'        => ['name' => 'Council Review Window',          'group' => 'Recruitment'],
		'recruit_onboarding_handoff'    => ['name' => 'Onboarding Handoff',             'group' => 'Recruitment'],
		'recruit_declined'              => ['name' => 'Decline Closure (Candidate)',    'group' => 'Recruitment'],
	];

	public static function render(): void {
		if (!current_user_can('cbnexus_manage_plugin_settings')) {
			echo '<div class="cbnexus-card"><p>Permission denied.</p></div>';
			return;
		}

		$notice = sanitize_key($_GET['pa_notice'] ?? '');
		CBNexus_Portal_Admin::render_notice($notice);

		if (isset($_GET['tpl'])) {
			self::render_email_editor(sanitize_key($_GET['tpl']));
			return;
		}

		$grouped = [];
		foreach (self::$email_templates as $id => $meta) {
			$grouped[$meta['group']][$id] = $meta;
		}
		?>
		<div class="cbnexus-card">
			<h2>Email Templates</h2>
			<p class="cbnexus-text-muted">Customize the emails The Circle sends. Edits override the default templates.</p>

			<?php foreach ($grouped as $group => $templates) : ?>
				<h3 style="margin:20px 0 8px;"><?php echo esc_html($group); ?></h3>
				<div class="cbnexus-admin-table-wrap">
					<table class="cbnexus-admin-table">
						<thead><tr>
							<th>Template</th>
							<th style="width:120px;">Referral Prompt</th>
							<th style="width:100px;">Customized?</th>
							<th style="width:80px;">Actions</th>
						</tr></thead>
						<tbody>
						<?php foreach ($templates as $id => $meta) :
							$has_override = (bool) get_option('cbnexus_email_tpl_' . $id);
							$prompt_type  = CBNexus_Email_Service::get_referral_prompt_type($id);
						?>
							<tr>
								<td><?php echo esc_html($meta['name']); ?></td>
								<td>
									<?php if ($prompt_type === 'prominent') : ?>
										<span class="cbnexus-status-pill" style="background:#5b2d6e;">Prominent</span>
									<?php elseif ($prompt_type === 'subtle') : ?>
										<span class="cbnexus-status-pill" style="background:#8b7a94;">Subtle</span>
									<?php else : ?>
										<span class="cbnexus-admin-meta">None</span>
									<?php endif; ?>
								</td>
								<td><?php echo $has_override ? '<span class="cbnexus-status-pill cbnexus-status-green">Yes</span>' : '<span class="cbnexus-admin-meta">Default</span>'; ?></td>
								<td><a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('emails', ['tpl' => $id])); ?>" class="cbnexus-link">Edit</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_email_editor(string $tpl_id): void {
		if (!isset(self::$email_templates[$tpl_id])) {
			echo '<div class="cbnexus-card"><p>Template not found.</p></div>';
			return;
		}

		$meta     = self::$email_templates[$tpl_id];
		$override = get_option('cbnexus_email_tpl_' . $tpl_id);
		$file     = CBNEXUS_PLUGIN_DIR . 'templates/emails/' . $tpl_id . '.php';
		$default  = file_exists($file) ? include $file : ['subject' => '', 'body' => ''];

		$subject      = $override['subject'] ?? $default['subject'] ?? '';
		$body         = $override['body'] ?? $default['body'] ?? '';
		$prompt_type  = CBNexus_Email_Service::get_referral_prompt_type($tpl_id);

		// Enqueue the rich-text editor script.
		wp_enqueue_script(
			'cbnexus-email-editor',
			CBNEXUS_PLUGIN_URL . 'assets/js/email-editor.js',
			[],
			CBNEXUS_VERSION,
			true
		);

		$placeholders = ['first_name', 'last_name', 'display_name', 'email', 'company', 'site_url', 'portal_url', 'login_url'];
		?>
		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h2>Edit: <?php echo esc_html($meta['name']); ?></h2>
				<a href="<?php echo esc_url(CBNexus_Portal_Admin::admin_url('emails')); ?>" class="cbnexus-btn">← Back</a>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field('cbnexus_portal_save_email_tpl'); ?>
				<input type="hidden" name="tpl_id" value="<?php echo esc_attr($tpl_id); ?>" />

				<div class="cbnexus-admin-form-stack">
					<div>
						<label>Subject Line</label>
						<input type="text" name="subject" value="<?php echo esc_attr($subject); ?>" />
					</div>
					<div id="cbnexus-email-editor">
						<label style="margin-bottom:8px;display:block;">Body</label>

						<!-- Editor mode tabs -->
						<div class="cbnexus-rte-tabs">
							<a href="#" data-rte-tab="visual" class="cbnexus-rte-tab active">Visual</a>
							<a href="#" data-rte-tab="html" class="cbnexus-rte-tab">HTML</a>
						</div>

						<!-- Formatting toolbar (visual mode only) -->
						<div class="cbnexus-rte-toolbar">
							<button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
							<button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
							<button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
							<span class="cbnexus-rte-sep"></span>
							<button type="button" data-cmd="formatBlock" data-val="<h2>" title="Heading">H</button>
							<button type="button" data-cmd="formatBlock" data-val="<h3>" title="Subheading">H<small>2</small></button>
							<button type="button" data-cmd="formatBlock" data-val="<p>" title="Paragraph">¶</button>
							<span class="cbnexus-rte-sep"></span>
							<button type="button" data-cmd="insertUnorderedList" title="Bullet List">• List</button>
							<button type="button" data-cmd="insertOrderedList" title="Numbered List">1. List</button>
							<span class="cbnexus-rte-sep"></span>
							<button type="button" data-cmd="createLink" title="Insert Link">🔗 Link</button>
							<button type="button" data-cmd="unlink" title="Remove Link">Unlink</button>
							<span class="cbnexus-rte-sep"></span>
							<button type="button" data-cmd="removeFormat" title="Clear Formatting">✕ Clear</button>
						</div>

						<!-- Visual editor (contenteditable) -->
						<div class="cbnexus-rte-visual" contenteditable="true"><?php echo wp_kses_post($body); ?></div>

						<!-- HTML editor (textarea, hidden by default) -->
						<textarea class="cbnexus-rte-html" rows="14" style="display:none;"><?php echo esc_textarea($body); ?></textarea>

						<!-- Hidden textarea that the form actually submits -->
						<textarea name="body" style="display:none!important;"><?php echo esc_textarea($body); ?></textarea>

						<!-- Placeholder insertion -->
						<div class="cbnexus-rte-placeholders">
							<span class="cbnexus-admin-meta">Insert placeholder:</span>
							<?php foreach ($placeholders as $ph) : ?>
								<button type="button" class="cbnexus-rte-placeholder-btn" data-placeholder="<?php echo esc_attr($ph); ?>">{{<?php echo esc_html($ph); ?>}}</button>
							<?php endforeach; ?>
						</div>
					</div>

					<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;">
						<label><strong>Recruitment Referral Prompt</strong></label>
						<p class="cbnexus-admin-meta" style="margin:0 0 8px;">Choose whether this email includes a recruitment referral section. Only shown when open roles exist.</p>
						<select name="referral_prompt" style="min-width:200px;">
							<option value="none" <?php selected($prompt_type, 'none'); ?>>None</option>
							<option value="subtle" <?php selected($prompt_type, 'subtle'); ?>>Subtle footer</option>
							<option value="prominent" <?php selected($prompt_type, 'prominent'); ?>>Prominent section</option>
						</select>
						<span class="cbnexus-admin-meta" style="margin-left:8px;">
							<?php if ($prompt_type === 'subtle') : ?>
								Compact "We're Looking For…" list above the email footer.
							<?php elseif ($prompt_type === 'prominent') : ?>
								"Help Us Grow" card with priority badges and referral CTA.
							<?php else : ?>
								No recruitment content in this email.
							<?php endif; ?>
						</span>
					</div>
				</div>

				<div class="cbnexus-admin-button-row">
					<button type="submit" name="cbnexus_portal_save_email_tpl" value="1" class="cbnexus-btn cbnexus-btn-accent">Save Template</button>
					<?php if ($override) : ?>
						<a href="<?php echo esc_url(wp_nonce_url(CBNexus_Portal_Admin::admin_url('emails', ['cbnexus_portal_reset_tpl' => $tpl_id]), 'cbnexus_portal_reset_' . $tpl_id, '_panonce')); ?>" class="cbnexus-btn" onclick="return confirm('Reset to default template?');">Reset to Default</a>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── Action Handlers ────────────────────────────────────────────────

	public static function handle_save_email_template(): void {
		if (!wp_verify_nonce(wp_unslash($_POST['_wpnonce'] ?? ''), 'cbnexus_portal_save_email_tpl')) { return; }
		if (!current_user_can('cbnexus_manage_plugin_settings')) { return; }

		$tpl_id = sanitize_key($_POST['tpl_id'] ?? '');
		if (!isset(self::$email_templates[$tpl_id])) { return; }

		update_option('cbnexus_email_tpl_' . $tpl_id, [
			'subject' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
			'body'    => wp_unslash($_POST['body'] ?? ''),
		]);

		// Save referral prompt setting for this template.
		$prompt = sanitize_key($_POST['referral_prompt'] ?? 'none');
		$all_settings = get_option('cbnexus_email_referral_prompts', []);
		$all_settings[$tpl_id] = $prompt;
		CBNexus_Email_Service::save_referral_prompt_settings($all_settings);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('emails', ['tpl' => $tpl_id, 'pa_notice' => 'template_saved']));
		exit;
	}

	public static function handle_reset_email_template(): void {
		$tpl_id = sanitize_key($_GET['cbnexus_portal_reset_tpl']);
		if (!wp_verify_nonce(wp_unslash($_GET['_panonce'] ?? ''), 'cbnexus_portal_reset_' . $tpl_id)) { return; }
		if (!current_user_can('cbnexus_manage_plugin_settings')) { return; }

		delete_option('cbnexus_email_tpl_' . $tpl_id);

		wp_safe_redirect(CBNexus_Portal_Admin::admin_url('emails', ['pa_notice' => 'template_reset']));
		exit;
	}
}