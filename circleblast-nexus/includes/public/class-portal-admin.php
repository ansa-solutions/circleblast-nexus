<?php
/**
 * Portal Admin (Manage) — Master Orchestrator
 *
 * Unified in-portal admin dashboard visible to cb_admin and cb_super_admin.
 * Admin tabs: Members, Recruitment, Matching, Archivist, Events.
 * Super-admin tabs (additional): Analytics, Emails, Logs, Settings.
 *
 * Sub-navigation uses ?section=manage&admin_tab=<tab> pattern.
 *
 * Each tab's render and action-handler logic lives in its own file under
 * includes/public/admin-tabs/. This master file handles routing, tab
 * navigation, shared UI helpers, and action dispatch.
 */

defined('ABSPATH') || exit;

// ── Load tab modules ────────────────────────────────────────────────────
$tab_dir = __DIR__ . '/admin-tabs/';
require_once $tab_dir . 'class-portal-admin-members.php';
require_once $tab_dir . 'class-portal-admin-recruitment.php';
require_once $tab_dir . 'class-portal-admin-matching.php';
require_once $tab_dir . 'class-portal-admin-archivist.php';
require_once $tab_dir . 'class-portal-admin-events.php';
require_once $tab_dir . 'class-portal-admin-analytics.php';
require_once $tab_dir . 'class-portal-admin-emails.php';
require_once $tab_dir . 'class-portal-admin-logs.php';
require_once $tab_dir . 'class-portal-admin-settings.php';
require_once $tab_dir . 'class-portal-admin-feedback.php';

final class CBNexus_Portal_Admin {

	private static $tabs = [
		// Admin tabs (cb_admin + cb_super_admin).
		'members'     => ['label' => 'Members',      'icon' => '👥', 'cap' => 'cbnexus_manage_members'],
		'recruitment' => ['label' => 'Recruitment',   'icon' => '🎯', 'cap' => 'cbnexus_manage_members'],
		'matching'    => ['label' => 'Matching',      'icon' => '🔗', 'cap' => 'cbnexus_manage_matching_rules'],
		'archivist'   => ['label' => 'Meeting Notes', 'icon' => '📝', 'cap' => 'cbnexus_manage_circleup'],
		'events'      => ['label' => 'Events',        'icon' => '📅', 'cap' => 'cbnexus_manage_members'],
		// Super-admin tabs (cb_super_admin only).
		'analytics'   => ['label' => 'Analytics',     'icon' => '📊', 'cap' => 'cbnexus_export_data'],
		'emails'      => ['label' => 'Emails',        'icon' => '✉️',  'cap' => 'cbnexus_manage_plugin_settings'],
		'help'        => ['label' => 'Help',          'icon' => '❓', 'cap' => 'cbnexus_manage_plugin_settings'],
		'logs'        => ['label' => 'Logs',          'icon' => '📋', 'cap' => 'cbnexus_view_logs'],
		'feedback'    => ['label' => 'Feedback',      'icon' => '📬', 'cap' => 'cbnexus_manage_plugin_settings'],
		'settings'    => ['label' => 'Settings',      'icon' => '⚙️',  'cap' => 'cbnexus_manage_plugin_settings'],
	];

	public static function init(): void {
		add_action('init', [__CLASS__, 'handle_actions']);
	}

	// =====================================================================
	//  ACTION DISPATCH
	// =====================================================================

	public static function handle_actions(): void {
		if (!is_user_logged_in() || !current_user_can('cbnexus_manage_members')) { return; }

		// ── Members ──────────────────────────────────────────────────────
		if (isset($_GET['cbnexus_portal_member_action'], $_GET['uid'], $_GET['_panonce'])) {
			CBNexus_Portal_Admin_Members::handle_member_status();
		}
		if (isset($_POST['cbnexus_portal_save_member'])) {
			CBNexus_Portal_Admin_Members::handle_save_member();
		}
		if (isset($_GET['cbnexus_portal_export']) && $_GET['cbnexus_portal_export'] === 'members') {
			CBNexus_Portal_Admin_Members::handle_export();
		}

		// ── Recruitment ──────────────────────────────────────────────────
		if (isset($_POST['cbnexus_portal_add_candidate'])) {
			CBNexus_Portal_Admin_Recruitment::handle_add_candidate();
		}
		if (isset($_POST['cbnexus_portal_update_candidate'])) {
			CBNexus_Portal_Admin_Recruitment::handle_update_candidate();
		}
		if (isset($_POST['cbnexus_portal_save_candidate'])) {
			CBNexus_Portal_Admin_Recruitment::handle_save_candidate();
		}

		// ── Recruitment Needs ────────────────────────────────────────────
		if (isset($_POST['cbnexus_portal_add_need'])) {
			CBNexus_Portal_Admin_Recruitment::handle_add_need();
		}
		if (isset($_POST['cbnexus_portal_update_need'])) {
			CBNexus_Portal_Admin_Recruitment::handle_update_need();
		}
		if (isset($_GET['cbnexus_portal_toggle_need'])) {
			CBNexus_Portal_Admin_Recruitment::handle_toggle_need();
		}
		if (isset($_GET['cbnexus_portal_delete_need'])) {
			CBNexus_Portal_Admin_Recruitment::handle_delete_need();
		}
		if (isset($_GET['cbnexus_portal_send_needs_blast'])) {
			CBNexus_Portal_Admin_Recruitment::handle_send_needs_blast();
		}
		if (isset($_POST['cbnexus_portal_save_needs_schedule'])) {
			CBNexus_Portal_Admin_Recruitment::handle_save_needs_schedule();
		}
		if (isset($_POST['cbnexus_portal_save_focus_settings'])) {
			CBNexus_Portal_Admin_Recruitment::handle_save_focus_settings();
		}
		if (isset($_GET['cbnexus_portal_rotate_focus'])) {
			CBNexus_Portal_Admin_Recruitment::handle_rotate_focus();
		}
		if (isset($_POST['cbnexus_portal_save_onboarding_recipients'])) {
			CBNexus_Portal_Admin_Recruitment::handle_save_onboarding_recipients();
		}

		// ── Matching ─────────────────────────────────────────────────────
		if (isset($_POST['cbnexus_portal_save_rules'])) {
			CBNexus_Portal_Admin_Matching::handle_save_rules();
		}
		if (isset($_GET['cbnexus_portal_run_cycle'])) {
			CBNexus_Portal_Admin_Matching::handle_run_cycle();
		}

		// ── Archivist ────────────────────────────────────────────────────
		if (isset($_POST['cbnexus_portal_create_circleup'])) {
			CBNexus_Portal_Admin_Archivist::handle_create_circleup();
		}
		if (isset($_POST['cbnexus_portal_save_circleup'])) {
			CBNexus_Portal_Admin_Archivist::handle_save_circleup();
		}
		if (isset($_GET['cbnexus_portal_extract'])) {
			CBNexus_Portal_Admin_Archivist::handle_extract();
		}
		if (isset($_GET['cbnexus_portal_parse'])) {
			CBNexus_Portal_Admin_Archivist::handle_parse();
		}
		if (isset($_GET['cbnexus_portal_publish'])) {
			CBNexus_Portal_Admin_Archivist::handle_publish();
		}
		if (isset($_POST['cbnexus_portal_add_item'])) {
			CBNexus_Portal_Admin_Archivist::handle_add_item();
		}
		if (isset($_POST['cbnexus_portal_update_items'])) {
			CBNexus_Portal_Admin_Archivist::handle_update_items();
		}

		// ── Events ───────────────────────────────────────────────────────
		if (isset($_GET['cbnexus_portal_event_action'])) {
			CBNexus_Portal_Admin_Events::handle_event_action();
		}
		if (isset($_POST['cbnexus_portal_save_event'])) {
			CBNexus_Portal_Admin_Events::handle_save_event();
		}
		if (isset($_POST['cbnexus_portal_send_events'])) {
			CBNexus_Portal_Admin_Events::handle_send_events();
		}
		if (isset($_POST['cbnexus_portal_save_digest_settings'])) {
			CBNexus_Portal_Admin_Events::handle_save_digest_settings();
		}

		// ── Emails (super-admin) ─────────────────────────────────────────
		if (isset($_POST['cbnexus_portal_save_email_tpl'])) {
			CBNexus_Portal_Admin_Emails::handle_save_email_template();
		}
		if (isset($_GET['cbnexus_portal_reset_tpl'])) {
			CBNexus_Portal_Admin_Emails::handle_reset_email_template();
		}

		// ── Settings (super-admin) ───────────────────────────────────────
		if (isset($_POST['cbnexus_portal_save_color_scheme'])) {
			CBNexus_Portal_Admin_Settings::handle_save_color_scheme();
		}

		if (isset($_POST['cbnexus_portal_save_cron_schedules'])) {
			CBNexus_Portal_Admin_Settings::handle_save_cron_schedules();
		}

		if (isset($_POST['cbnexus_portal_save_email_sender'])) {
			CBNexus_Portal_Admin_Settings::handle_save_email_sender();
		}

		if (isset($_POST['cbnexus_portal_save_api_keys'])) {
			CBNexus_Portal_Admin_Settings::handle_save_api_keys();
		}

		if (isset($_POST['cbnexus_portal_save_suggestion_emails'])) {
			CBNexus_Portal_Admin_Settings::handle_save_suggestion_emails();
		}

		// ── Help Content (super-admin) ──────────────────────────────────
		if (isset($_POST['cbnexus_portal_save_help_content'])) {
			CBNexus_Portal_Help::handle_save_help_content();
		}
		if (isset($_GET['cbnexus_portal_reset_help'])) {
			CBNexus_Portal_Help::handle_reset_help_content();
		}
		if (isset($_POST['cbnexus_portal_save_stat_tooltips'])) {
			CBNexus_Portal_Help::handle_save_tooltips();
		}
		if (isset($_GET['cbnexus_portal_reset_tooltips'])) {
			CBNexus_Portal_Help::handle_reset_tooltips();
		}

		// ── Feedback (super-admin) ──────────────────────────────────────
		if (isset($_POST['cbnexus_portal_update_feedback'])) {
			CBNexus_Portal_Admin_Feedback::handle_update_status();
		}
		if (isset($_GET['cbnexus_portal_delete_feedback'])) {
			CBNexus_Portal_Admin_Feedback::handle_delete();
		}
	}

	// =====================================================================
	//  RENDER ENTRY POINT
	// =====================================================================

	public static function render(array $profile): void {
		if (!current_user_can('cbnexus_manage_members')) {
			echo '<div class="cbnexus-card"><p>You do not have permission to access this page.</p></div>';
			return;
		}

		$tab = isset($_GET['admin_tab']) ? sanitize_key($_GET['admin_tab']) : 'members';
		if (!isset(self::$tabs[$tab]) || !current_user_can(self::$tabs[$tab]['cap'])) {
			$tab = 'members';
		}

		self::render_tab_nav($tab);

		echo '<div class="cbnexus-admin-content">';
		switch ($tab) {
			case 'members':     CBNexus_Portal_Admin_Members::render(); break;
			case 'recruitment': CBNexus_Portal_Admin_Recruitment::render(); break;
			case 'matching':    CBNexus_Portal_Admin_Matching::render(); break;
			case 'archivist':   CBNexus_Portal_Admin_Archivist::render(); break;
			case 'events':      CBNexus_Portal_Admin_Events::render(); break;
			case 'analytics':   CBNexus_Portal_Admin_Analytics::render(); break;
			case 'emails':      CBNexus_Portal_Admin_Emails::render(); break;
			case 'help':        CBNexus_Portal_Help::render_editor(); break;
			case 'logs':        CBNexus_Portal_Admin_Logs::render(); break;
			case 'feedback':    CBNexus_Portal_Admin_Feedback::render(); break;
			case 'settings':    CBNexus_Portal_Admin_Settings::render(); break;
		}
		echo '</div>';
	}

	private static function render_tab_nav(string $current): void {
		$portal_url = CBNexus_Portal_Router::get_portal_url();
		$base_url   = add_query_arg('section', 'manage', $portal_url);

		/* Split tabs into admin vs super-admin rows */
		$admin_caps  = ['cbnexus_manage_members', 'cbnexus_manage_matching_rules', 'cbnexus_manage_circleup'];
		$admin_tabs  = [];
		$super_tabs  = [];

		foreach (self::$tabs as $slug => $tab) {
			if (!current_user_can($tab['cap'])) { continue; }
			if (in_array($tab['cap'], $admin_caps, true)) {
				$admin_tabs[$slug] = $tab;
			} else {
				$super_tabs[$slug] = $tab;
			}
		}
		?>
		<div class="cbnexus-admin-tabs-wrap">
			<?php if (!empty($admin_tabs)) : ?>
			<div class="cbnexus-admin-tabs">
				<?php foreach ($admin_tabs as $slug => $tab) :
					$is_active = $slug === $current;
					$url = add_query_arg('admin_tab', $slug, $base_url);
				?>
					<a href="<?php echo esc_url($url); ?>" class="cbnexus-admin-tab <?php echo $is_active ? 'active' : ''; ?>" title="<?php echo esc_attr($tab['label']); ?>">
						<span class="cbnexus-admin-tab-icon"><?php echo esc_html($tab['icon']); ?></span>
						<span class="cbnexus-admin-tab-label"><?php echo esc_html($tab['label']); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if (!empty($super_tabs)) : ?>
			<?php
				$feedback_new_count = 0;
				if (isset($super_tabs['feedback']) && class_exists('CBNexus_Feedback_Service')) {
					$feedback_new_count = CBNexus_Feedback_Service::count_new();
				}
			?>
			<div class="cbnexus-admin-tabs cbnexus-admin-tabs--super">
				<?php foreach ($super_tabs as $slug => $tab) :
					$is_active = $slug === $current;
					$url = add_query_arg('admin_tab', $slug, $base_url);
				?>
					<a href="<?php echo esc_url($url); ?>" class="cbnexus-admin-tab <?php echo $is_active ? 'active' : ''; ?>" title="<?php echo esc_attr($tab['label']); ?>" style="position:relative;">
						<span class="cbnexus-admin-tab-icon"><?php echo esc_html($tab['icon']); ?></span>
						<span class="cbnexus-admin-tab-label"><?php echo esc_html($tab['label']); ?></span>
						<?php if ($slug === 'feedback' && $feedback_new_count > 0) : ?>
							<span class="cbnexus-nav-badge" style="position:absolute; top:-4px; right:-4px;"><?php echo esc_html($feedback_new_count); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// =====================================================================
	//  SHARED HELPERS (used by tab classes via CBNexus_Portal_Admin::)
	// =====================================================================

	/**
	 * Build a portal admin URL.
	 */
	public static function admin_url(string $tab = 'members', array $extra = []): string {
		$portal_url = CBNexus_Portal_Router::get_portal_url();
		$args = array_merge(['section' => 'manage', 'admin_tab' => $tab], $extra);
		return add_query_arg($args, $portal_url);
	}

	/**
	 * Render a coloured status pill.
	 */
	public static function status_pill(string $status): void {
		$colors = [
			'active' => 'green', 'approved' => 'green', 'published' => 'green', 'accepted' => 'green',
			'inactive' => 'red', 'cancelled' => 'red', 'declined' => 'red', 'denied' => 'red',
			'alumni' => 'muted', 'closed' => 'muted',
			'pending' => 'gold', 'draft' => 'gold', 'suggested' => 'gold',
			'referral' => 'blue', 'contacted' => 'blue', 'invited' => 'blue', 'visited' => 'blue', 'decision' => 'gold',
		];
		$c = $colors[$status] ?? 'muted';
		echo '<span class="cbnexus-status-pill cbnexus-status-' . esc_attr($c) . '">' . esc_html(ucfirst($status)) . '</span>';
	}

	/**
	 * Render a stat card with optional info tooltip.
	 *
	 * @param string $label Display label.
	 * @param mixed  $value Display value.
	 * @param string $tooltip Optional tooltip text (shown via ⓘ button).
	 */
	public static function stat_card(string $label, $value, string $tooltip = ''): void {
		?>
		<div class="cbnexus-admin-stat">
			<?php if ($tooltip !== '') : ?>
				<button type="button" class="cbnexus-info-btn" aria-label="Info" data-tooltip="<?php echo esc_attr($tooltip); ?>">ⓘ</button>
			<?php endif; ?>
			<div class="cbnexus-admin-stat-value"><?php echo esc_html($value); ?></div>
			<div class="cbnexus-admin-stat-label"><?php echo esc_html($label); ?></div>
		</div>
		<?php
	}

	/**
	 * Render a flash notice banner.
	 */
	public static function render_notice(string $notice): void {
		if ($notice === '') { return; }
		$messages = [
			'status_updated'          => 'Member status updated.',
			'member_created'          => 'Member created. Welcome email sent.',
			'member_updated'          => 'Member profile updated.',
			'candidate_added'         => 'Candidate added to pipeline.',
			'candidate_updated'       => 'Candidate stage updated.',
			'candidate_saved'         => 'Candidate updated.',
			'rules_saved'             => 'Matching rules saved.',
			'cycle_complete'          => 'Suggestion cycle completed. Emails sent.',
			'cycle_skipped'           => 'Suggestion cycle skipped — a cycle already ran within the last 24 hours.',
			'circleup_created'        => 'CircleUp meeting created.',
			'circleup_saved'          => 'Meeting details saved.',
			'extraction_done'         => 'AI extraction complete.',
			'items_parsed'            => 'Summary parsed — items added for review.',
			'no_items_parsed'         => 'No structured items found in the summary. Try adding items manually.',
			'item_added'              => 'Item added.',
			'items_updated'           => 'Items updated.',
			'published'               => 'Meeting published and summary emailed to all members.',
			'event_updated'           => 'Event updated.',
			'events_sent'             => 'Event notification sent to all active members.',
			'no_events_selected'      => 'No events selected. Check at least one event to send.',
			'digest_saved'            => 'Events digest settings saved.',
			'template_saved'          => 'Email template saved.',
			'template_reset'          => 'Template reset to default.',
			'scheme_saved'            => 'Color scheme updated! Changes are now live.',
			'cron_saved'              => 'Cron schedules updated! Changes are now active.',
			'sender_saved'            => 'Email sender settings saved.',
			'apikeys_saved'           => 'API keys updated.',
			'suggestion_emails_saved' => 'Suggestion email setting saved.',
			'need_added'              => 'Recruitment need added.',
			'need_updated'            => 'Recruitment need updated.',
			'need_toggled'            => 'Recruitment need status updated.',
			'need_deleted'            => 'Recruitment need deleted.',
			'needs_blast_sent'        => 'Recruitment needs emailed to all active members.',
			'needs_schedule_saved'    => 'Recruitment needs schedule saved.',
			'focus_saved'             => 'Monthly focus settings saved.',
			'focus_rotated'           => 'Recruitment focus categories rotated.',
			'onboarding_saved'        => 'Onboarding recipients saved.',
			'help_saved'              => 'Help content saved.',
			'help_reset'              => 'Help content reset to default.',
			'tooltips_saved'          => 'Stat tooltips saved.',
			'tooltips_reset'          => 'All stat tooltips reset to defaults.',
			'error'                   => 'An error occurred.',
		];
		$msg = $messages[$notice] ?? '';

		// Dynamic error message for candidate-to-member conversion failures.
		if ($notice === 'candidate_convert_failed') {
			$cid = absint($_GET['candidate_id'] ?? 0);
			$err = $cid ? get_transient('cbnexus_recruit_convert_error_' . $cid) : '';
			if ($cid) { delete_transient('cbnexus_recruit_convert_error_' . $cid); }
			$detail = $err ?: 'Unknown error.';
			$msg = 'Could not create member account for this candidate: ' . $detail
				 . ' Please fill in the missing fields and try again, or create the member manually.';
			echo '<div class="cbnexus-portal-notice cbnexus-notice-error">' . esc_html($msg) . '</div>';
			return;
		}

		// Dynamic error message for extraction failures.
		if ($notice === 'extraction_failed') {
			$circleup_id = absint($_GET['circleup_id'] ?? 0);
			$err = get_transient('cbnexus_extract_error_' . $circleup_id);
			delete_transient('cbnexus_extract_error_' . $circleup_id);
			$msg = 'AI extraction failed: ' . ($err ?: 'Unknown error. Check that CBNEXUS_CLAUDE_API_KEY is defined in wp-config.php.');
			echo '<div class="cbnexus-portal-notice cbnexus-notice-error">' . esc_html($msg) . '</div>';
			return;
		}

		if (!$msg) { return; }
		$type = in_array($notice, ['error', 'no_events_selected', 'cycle_skipped'], true) ? 'error' : 'success';
		echo '<div class="cbnexus-portal-notice cbnexus-notice-' . esc_attr($type) . '">' . esc_html($msg) . '</div>';
	}

	/**
	 * Public bridge for WP-admin recruitment automation handler.
	 * Called from class-admin-recruitment.php — delegates to the recruitment tab.
	 */
	public static function trigger_recruitment_automation(object $candidate, string $old_stage, string $new_stage): void {
		CBNexus_Portal_Admin_Recruitment::trigger_recruitment_automation($candidate, $old_stage, $new_stage);
	}
}
