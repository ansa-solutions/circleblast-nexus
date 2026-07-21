<?php
/**
 * Portal Journal
 *
 * Member-facing journal section. Members log wins, insights, referrals
 * given/received, and actions without needing a 1:1 meeting record.
 *
 * Sections:
 *   - Collapsible quick-entry form (type chips, content, optional context, date)
 *   - Tab-filtered feed (All / Win / Insight / Referral Given / Referral Received / Action)
 *   - Per-entry delete
 *   - Counts bar
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Journal {

	/** Human-readable labels and icons per type. */
	private static $type_meta = [
		'win'               => ['label' => 'Win',               'icon' => '🏆', 'pill' => 'cbnexus-pill--gold-soft'],
		'ask'               => ['label' => 'Ask',               'icon' => '🙋', 'pill' => 'cbnexus-pill--blue-soft'],
		'insight'           => ['label' => 'Insight',           'icon' => '💡', 'pill' => 'cbnexus-pill--accent-soft'],
		'referral_given'    => ['label' => 'Referral Given',    'icon' => '🤝', 'pill' => 'cbnexus-pill--green'],
		'referral_received' => ['label' => 'Referral Received', 'icon' => '⭐', 'pill' => 'cbnexus-pill--blue'],
		'action'            => ['label' => 'Action',            'icon' => '✅', 'pill' => 'cbnexus-pill--muted'],
	];

	public static function init(): void {
		add_action('wp_ajax_cbnexus_journal_add',    [__CLASS__, 'ajax_add']);
		add_action('wp_ajax_cbnexus_journal_delete', [__CLASS__, 'ajax_delete']);
		add_action('wp_ajax_cbnexus_journal_filter', [__CLASS__, 'ajax_filter']);
		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
	}

	public static function enqueue_scripts(): void {
		global $post;
		if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'cbnexus_portal')) { return; }
		wp_enqueue_script(
			'cbnexus-journal',
			CBNEXUS_PLUGIN_URL . 'assets/js/journal.js',
			[],
			CBNEXUS_VERSION,
			true
		);
		wp_localize_script('cbnexus-journal', 'cbnexusJournal', [
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce'    => wp_create_nonce('cbnexus_journal'),
		]);

		// Quick "Share Win / Ask" header modal — reuses the journal AJAX endpoint.
		wp_enqueue_script(
			'cbnexus-winask',
			CBNEXUS_PLUGIN_URL . 'assets/js/winask.js',
			['cbnexus-journal'],
			CBNEXUS_VERSION,
			true
		);
	}

	// ─── Render ────────────────────────────────────────────────────────

	public static function render(array $profile): void {
		$uid    = (int) $profile['user_id'];
		$counts = CBNexus_Journal_Repository::count_by_type($uid);
		$total  = array_sum($counts);
		$filter = isset($_GET['jtype']) ? sanitize_key($_GET['jtype']) : '';
		if ($filter !== '' && !in_array($filter, CBNexus_Journal_Repository::TYPES, true)) { $filter = ''; }
		$entries = CBNexus_Journal_Repository::get_for_member($uid, $filter, 50);
		?>
		<div class="cbnexus-journal" id="cbnexus-journal"
		     data-nonce="<?php echo esc_attr(wp_create_nonce('cbnexus_journal')); ?>"
		     data-ajax="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>">

			<!-- Page header -->
			<div class="cbnexus-cu-header" style="margin-bottom:16px;">
				<h2><?php esc_html_e('My Journal', 'circleblast-nexus'); ?></h2>
				<p class="cbnexus-text-muted"><?php esc_html_e('Track your wins, insights, referrals, and commitments — independent of any meeting.', 'circleblast-nexus'); ?></p>
			</div>

			<!-- Stats bar -->
			<?php if ($total > 0) : ?>
			<div class="cbnexus-journal-stats" id="cbnexus-journal-stats">
				<?php foreach (self::$type_meta as $type => $meta) :
					if (empty($counts[$type])) { continue; } ?>
					<span class="cbnexus-pill <?php echo esc_attr($meta['pill']); ?>">
						<?php echo esc_html($meta['icon'] . ' ' . $counts[$type] . ' ' . $meta['label']); ?>
						<?php echo esc_html($counts[$type] === 1 ? '' : 's'); ?>
					</span>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<!-- Add Entry form (collapsible) -->
			<div class="cbnexus-card cbnexus-cu-submit-card" style="margin-bottom:16px;">
				<button type="button" class="cbnexus-cu-submit-toggle" id="cbnexus-journal-toggle" aria-expanded="false">
					<span class="cbnexus-cu-submit-toggle-text">
						<span style="font-size:15px;">✏️</span>
						<span style="font-weight:600;font-size:14px;"><?php esc_html_e('New Entry', 'circleblast-nexus'); ?></span>
						<span class="cbnexus-text-muted" style="font-size:13px;font-weight:400;"><?php esc_html_e('— Log a win, insight, referral, or action', 'circleblast-nexus'); ?></span>
					</span>
					<span class="cbnexus-cu-submit-chevron" aria-hidden="true">›</span>
				</button>
				<div class="cbnexus-cu-submit-body" id="cbnexus-journal-form-body" hidden>
					<form id="cbnexus-journal-form" autocomplete="off">
						<!-- Type chips -->
						<div class="cbnexus-cu-submit-type-row" style="flex-wrap:wrap;gap:8px;">
							<?php foreach (self::$type_meta as $type => $meta) : ?>
								<label class="cbnexus-cu-type-option">
									<input type="radio" name="entry_type" value="<?php echo esc_attr($type); ?>" <?php checked($type, 'win'); ?> />
									<span class="cbnexus-cu-type-chip cbnexus-cu-type-chip--<?php echo esc_attr(str_replace('_', '-', $type)); ?>">
										<?php echo esc_html($meta['icon'] . ' ' . $meta['label']); ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>

						<!-- Member picker (referral types only) -->
						<?php
						$members = CBNexus_Member_Repository::get_all_members('active');
						usort($members, fn($a, $b) => strcasecmp($a['display_name'], $b['display_name']));
						?>
						<div class="cbnexus-form-field cbnexus-journal-field-member" id="cbnexus-journal-member-field" style="margin-top:12px;display:none;">
							<label for="cbnexus-journal-member" id="cbnexus-journal-member-label"><?php esc_html_e('Member', 'circleblast-nexus'); ?></label>
							<select id="cbnexus-journal-member" name="related_member">
								<option value=""><?php esc_html_e('— Select a member —', 'circleblast-nexus'); ?></option>
								<?php foreach ($members as $m) :
									if ((int) $m['user_id'] === $uid) { continue; }
								?>
									<option value="<?php echo esc_attr($m['user_id']); ?>"><?php echo esc_html($m['display_name'] . ($m['cb_company'] ? ' · ' . $m['cb_company'] : '')); ?></option>
								<?php endforeach; ?>
								<option value="external"><?php esc_html_e('Someone outside the group', 'circleblast-nexus'); ?></option>
							</select>
						</div>

						<!-- External name (shown when "outside the group" selected) -->
						<div class="cbnexus-form-field" id="cbnexus-journal-external-field" style="display:none;">
							<label for="cbnexus-journal-external"><?php esc_html_e('Their name', 'circleblast-nexus'); ?></label>
							<input type="text" id="cbnexus-journal-external" name="external_name" placeholder="<?php esc_attr_e('Full name', 'circleblast-nexus'); ?>" maxlength="200" />
						</div>

						<!-- Main content textarea -->
						<div class="cbnexus-form-field" style="margin-top:12px;" id="cbnexus-journal-content-field">
							<label for="cbnexus-journal-content" id="cbnexus-journal-content-label"><?php esc_html_e('What happened?', 'circleblast-nexus'); ?> <span style="color:var(--cb-accent);">*</span></label>
							<textarea id="cbnexus-journal-content" name="content" rows="3"
								placeholder="<?php esc_attr_e('Describe your win, insight, or referral...', 'circleblast-nexus'); ?>"
								maxlength="2000" required></textarea>
						</div>

						<!-- Context textarea -->
						<div class="cbnexus-form-field" id="cbnexus-journal-context-field">
							<label for="cbnexus-journal-context" id="cbnexus-journal-context-label"><?php esc_html_e('Context / Notes', 'circleblast-nexus'); ?> <span class="cbnexus-text-muted">(<?php esc_html_e('optional', 'circleblast-nexus'); ?>)</span></label>
							<textarea id="cbnexus-journal-context" name="context" rows="2"
								placeholder="<?php esc_attr_e('e.g. names involved, deal size, follow-up needed...', 'circleblast-nexus'); ?>"
								maxlength="1000"></textarea>
						</div>

						<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
							<div class="cbnexus-form-field" style="flex:0 0 auto;margin:0;">
								<label for="cbnexus-journal-date"><?php esc_html_e('Date', 'circleblast-nexus'); ?></label>
								<input type="date" id="cbnexus-journal-date" name="entry_date"
									value="<?php echo esc_attr(gmdate('Y-m-d')); ?>"
									max="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
							</div>
							<div class="cbnexus-form-field" style="flex:0 0 auto;margin:0;">
								<label for="cbnexus-journal-visibility"><?php esc_html_e('Visibility', 'circleblast-nexus'); ?></label>
								<select id="cbnexus-journal-visibility" name="visibility">
									<option value="private"><?php esc_html_e('Private (only me)', 'circleblast-nexus'); ?></option>
									<option value="members"><?php esc_html_e('Visible to members', 'circleblast-nexus'); ?></option>
								</select>
							</div>
							<div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
								<div id="cbnexus-journal-msg" style="display:none;font-size:13px;"></div>
								<button type="submit" class="cbnexus-btn cbnexus-btn-primary cbnexus-btn-sm"><?php esc_html_e('Save Entry', 'circleblast-nexus'); ?></button>
							</div>
						</div>
					</form>
				</div>
			</div>

			<!-- Type filter tabs -->
			<?php $portal_url = CBNexus_Portal_Router::get_portal_url(); ?>
			<div class="cbnexus-journal-filters" id="cbnexus-journal-filters">
				<?php
				$all_url = add_query_arg('section', 'journal', $portal_url);
				?>
				<a href="<?php echo esc_url($all_url); ?>" class="cbnexus-journal-filter-tab <?php echo $filter === '' ? 'active' : ''; ?>">
					<?php esc_html_e('All', 'circleblast-nexus'); ?>
					<?php if ($total > 0) : ?><span class="cbnexus-nav-badge" style="position:static;margin-left:4px;"><?php echo esc_html($total); ?></span><?php endif; ?>
				</a>
				<?php foreach (self::$type_meta as $type => $meta) :
					$tab_url = add_query_arg(['section' => 'journal', 'jtype' => $type], $portal_url);
					$cnt = $counts[$type] ?? 0;
				?>
					<a href="<?php echo esc_url($tab_url); ?>" class="cbnexus-journal-filter-tab <?php echo $filter === $type ? 'active' : ''; ?>">
						<?php echo esc_html($meta['icon'] . ' ' . $meta['label']); ?>
						<?php if ($cnt > 0) : ?><span class="cbnexus-nav-badge" style="position:static;margin-left:4px;"><?php echo esc_html($cnt); ?></span><?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>

			<!-- Feed -->
			<div id="cbnexus-journal-feed">
				<?php self::render_feed($entries, $uid); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the feed of entries (shared by initial render and AJAX filter).
	 *
	 * @param array $entries Array of journal row objects.
	 * @param int   $uid     Current user ID (for delete button ownership check).
	 */
	public static function render_feed(array $entries, int $uid): void {
		if (empty($entries)) {
			?>
			<div class="cbnexus-card">
				<p class="cbnexus-text-muted" style="text-align:center;padding:24px 0;">
					<?php esc_html_e('Nothing here yet. Add your first entry above! 🎉', 'circleblast-nexus'); ?>
				</p>
			</div>
			<?php
			return;
		}

		$prev_month = '';
		foreach ($entries as $e) :
			$meta  = self::$type_meta[$e->entry_type] ?? self::$type_meta['win'];
			$month = date_i18n('F Y', strtotime($e->entry_date));
			if ($month !== $prev_month) :
				$prev_month = $month;
				?>
				<div class="cbnexus-journal-month-header"><?php echo esc_html($month); ?></div>
				<?php
				endif;
			?>
			<div class="cbnexus-card cbnexus-journal-entry" data-entry-id="<?php echo esc_attr($e->id); ?>" style="padding:14px 18px;margin-bottom:10px;">
				<div style="display:flex;align-items:flex-start;gap:10px;">
					<span style="font-size:18px;line-height:1;flex-shrink:0;margin-top:2px;"><?php echo esc_html($meta['icon']); ?></span>
					<div style="flex:1;min-width:0;">
						<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
							<span class="cbnexus-pill <?php echo esc_attr($meta['pill']); ?>" style="font-size:11px;padding:2px 8px;"><?php echo esc_html($meta['label']); ?></span>
							<span class="cbnexus-text-muted" style="font-size:12px;"><?php echo esc_html(date_i18n('M j, Y', strtotime($e->entry_date))); ?></span>
							<?php if ($e->visibility === 'members') : ?>
								<span class="cbnexus-text-muted" style="font-size:11px;">👥 <?php esc_html_e('Shared', 'circleblast-nexus'); ?></span>
							<?php endif; ?>
						</div>
						<div style="font-size:14px;line-height:1.6;color:var(--cb-text-primary, #1a1a2e);margin-bottom:<?php echo $e->context ? '6px' : '0'; ?>">
							<?php echo nl2br(esc_html($e->content)); ?>
						</div>
						<?php if (!empty($e->context)) : ?>
							<div style="font-size:13px;color:var(--cb-text-sec);font-style:italic;line-height:1.5;"><?php echo nl2br(esc_html($e->context)); ?></div>
						<?php endif; ?>
					</div>
					<?php if ((int) $e->member_id === $uid) : ?>
						<button type="button" class="cbnexus-journal-delete-btn"
							data-entry-id="<?php echo esc_attr($e->id); ?>"
							aria-label="<?php esc_attr_e('Delete entry', 'circleblast-nexus'); ?>"
							title="<?php esc_attr_e('Delete', 'circleblast-nexus'); ?>"
							style="flex-shrink:0;background:none;border:none;cursor:pointer;color:var(--cb-text-muted,#888);font-size:16px;padding:2px 4px;line-height:1;opacity:0.5;transition:opacity .15s;"
							onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.5'">✕</button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach;
	}

	// ─── AJAX ──────────────────────────────────────────────────────────

	private static function verify_ajax(): ?int {
		check_ajax_referer('cbnexus_journal', 'nonce');
		$uid = get_current_user_id();
		if (!$uid || !CBNexus_Member_Repository::is_member($uid)) {
			wp_send_json_error('Access denied.', 403);
		}
		return $uid;
	}

	public static function ajax_add(): void {
		$uid = self::verify_ajax();

		$entry_type     = sanitize_key(wp_unslash($_POST['entry_type'] ?? 'win'));
		$related_member = sanitize_text_field(wp_unslash($_POST['related_member'] ?? ''));
		$external_name  = sanitize_text_field(wp_unslash($_POST['external_name'] ?? ''));
		$context_raw    = sanitize_textarea_field(wp_unslash($_POST['context'] ?? ''));

		// For referral types, prepend the related member/person to context.
		if (in_array($entry_type, ['referral_given', 'referral_received'], true)) {
			$person_name = '';
			if ($related_member === 'external' && $external_name !== '') {
				$person_name = $external_name . ' (external)';
			} elseif ($related_member !== '' && $related_member !== 'external') {
				$profile = CBNexus_Member_Repository::get_profile((int) $related_member);
				if ($profile) {
					$person_name = $profile['display_name'];
				}
			}
			if ($person_name !== '') {
				$prefix = $entry_type === 'referral_given' ? 'Referred: ' : 'Referred by: ';
				$context_raw = $prefix . $person_name . ($context_raw !== '' ? "\n" . $context_raw : '');
			}
		}

		$data = [
			'entry_type' => $entry_type,
			'content'    => sanitize_textarea_field(wp_unslash($_POST['content'] ?? '')),
			'context'    => $context_raw,
			'entry_date' => sanitize_text_field(wp_unslash($_POST['entry_date'] ?? gmdate('Y-m-d'))),
			'visibility' => sanitize_key(wp_unslash($_POST['visibility'] ?? 'private')),
		];

		$result = CBNexus_Journal_Service::create($uid, $data);
		if (!$result['success']) {
			wp_send_json_error($result);
		}

		// Return the newly rendered entry card + updated counts for JS to inject.
		$entry = CBNexus_Journal_Repository::get((int) $result['entry_id']);
		ob_start();
		self::render_feed([$entry], $uid);
		$card_html = ob_get_clean();

		$counts = CBNexus_Journal_Repository::count_by_type($uid);
		wp_send_json_success([
			'card_html' => $card_html,
			'counts'    => $counts,
			'total'     => array_sum($counts),
		]);
	}

	public static function ajax_delete(): void {
		$uid      = self::verify_ajax();
		$entry_id = absint($_POST['entry_id'] ?? 0);
		$result   = CBNexus_Journal_Service::delete($entry_id, $uid);

		if (!$result['success']) {
			wp_send_json_error($result);
		}

		$counts = CBNexus_Journal_Repository::count_by_type($uid);
		wp_send_json_success([
			'counts' => $counts,
			'total'  => array_sum($counts),
		]);
	}

	public static function ajax_filter(): void {
		$uid    = self::verify_ajax();
		$type   = sanitize_key($_POST['jtype'] ?? '');
		if ($type !== '' && !in_array($type, CBNexus_Journal_Repository::TYPES, true)) { $type = ''; }
		$entries = CBNexus_Journal_Repository::get_for_member($uid, $type, 50);
		ob_start();
		self::render_feed($entries, $uid);
		$html = ob_get_clean();
		wp_send_json_success(['html' => $html]);
	}

	// ─── Quick "Share Win / Ask" modal (header action) ─────────────────

	/**
	 * Render the header button that opens the quick Win/Ask modal.
	 * Sits next to "Know Someone?" in the portal header.
	 */
	public static function render_header_button(): void {
		if (!is_user_logged_in()) { return; }
		?>
		<button type="button" class="cbnexus-header-link cbnexus-winask-header-btn" data-winask-open aria-label="<?php esc_attr_e('Share a win or ask', 'circleblast-nexus'); ?>" title="<?php esc_attr_e('Share a win or ask with the club', 'circleblast-nexus'); ?>">
			<span class="cbnexus-header-link-icon">🙌</span> <?php esc_html_e('Win / Ask', 'circleblast-nexus'); ?>
		</button>
		<?php
	}

	/**
	 * Render the quick Win/Ask modal. Called once per portal page load
	 * (typically from the router alongside the other portal modals).
	 *
	 * Submissions are auto-shared with members and dated today, so they
	 * appear on the Club "Asks & Wins" board for the monthly meeting.
	 */
	public static function render_quick_modal(): void {
		if (!is_user_logged_in()) { return; }
		?>
		<!-- Win/Ask Modal Overlay -->
		<div id="cbnexus-winask-overlay" class="cbnexus-winask-overlay"></div>

		<!-- Win/Ask Modal -->
		<div id="cbnexus-winask-modal" class="cbnexus-winask-modal" role="dialog" aria-modal="true" aria-labelledby="cbnexus-winask-title">
			<div class="cbnexus-winask-header">
				<h3 id="cbnexus-winask-title"><?php esc_html_e('Share with the Club', 'circleblast-nexus'); ?></h3>
				<button type="button" class="cbnexus-winask-close" data-winask-close aria-label="<?php esc_attr_e('Close', 'circleblast-nexus'); ?>">&times;</button>
			</div>
			<p class="cbnexus-winask-subtitle"><?php esc_html_e('Celebrate a win or ask the group for help. Shared entries appear on the Club board and are read out at the monthly meeting.', 'circleblast-nexus'); ?></p>

			<div id="cbnexus-winask-msg" class="cbnexus-winask-msg" style="display:none;"></div>

			<form id="cbnexus-winask-form" autocomplete="off">
				<div class="cbnexus-winask-type-row">
					<label class="cbnexus-winask-type-option">
						<input type="radio" name="entry_type" value="win" checked />
						<span class="cbnexus-winask-type-chip cbnexus-winask-type-chip--win">🏆 <?php esc_html_e('Win', 'circleblast-nexus'); ?></span>
					</label>
					<label class="cbnexus-winask-type-option">
						<input type="radio" name="entry_type" value="ask" />
						<span class="cbnexus-winask-type-chip cbnexus-winask-type-chip--ask">🙋 <?php esc_html_e('Ask', 'circleblast-nexus'); ?></span>
					</label>
				</div>

				<div class="cbnexus-winask-field">
					<label for="cbnexus-winask-content" id="cbnexus-winask-content-label"><?php esc_html_e('What did you win?', 'circleblast-nexus'); ?> <span class="cbnexus-required">*</span></label>
					<textarea id="cbnexus-winask-content" name="content" rows="3" maxlength="2000" required
						placeholder="<?php esc_attr_e('e.g. Closed a new client this week!', 'circleblast-nexus'); ?>"></textarea>
				</div>

				<div class="cbnexus-winask-submit">
					<button type="submit" class="cbnexus-btn cbnexus-btn-primary"><?php esc_html_e('Share with Club', 'circleblast-nexus'); ?></button>
					<button type="button" class="cbnexus-btn cbnexus-btn-outline" data-winask-close><?php esc_html_e('Cancel', 'circleblast-nexus'); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	// ─── Dashboard card (called by CBNexus_Portal_Dashboard) ───────────

	/**
	 * Render a compact journal summary card for the dashboard Home tab.
	 *
	 * @param int $uid Member user ID.
	 */
	public static function render_dashboard_card(int $uid): void {
		$counts  = CBNexus_Journal_Repository::count_by_type($uid);
		$total   = array_sum($counts);
		$recent  = CBNexus_Journal_Repository::get_recent($uid, 3);
		$journal_url = add_query_arg('section', 'journal', CBNexus_Portal_Router::get_portal_url());
		?>
		<div class="cbnexus-card" style="margin-bottom:16px;">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
				<h3 style="margin:0;font-size:15px;">📓 <?php esc_html_e('My Journal', 'circleblast-nexus'); ?></h3>
				<a href="<?php echo esc_url($journal_url); ?>" class="cbnexus-btn cbnexus-btn-outline cbnexus-btn-sm"><?php esc_html_e('View All →', 'circleblast-nexus'); ?></a>
			</div>

			<?php if ($total === 0) : ?>
				<p class="cbnexus-text-muted" style="font-size:13px;margin:0;">
					<?php esc_html_e('No entries yet. ', 'circleblast-nexus'); ?>
					<a href="<?php echo esc_url($journal_url); ?>"><?php esc_html_e('Log your first win!', 'circleblast-nexus'); ?></a>
				</p>
			<?php else : ?>
				<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
					<?php foreach (self::$type_meta as $type => $meta) :
						if (empty($counts[$type])) { continue; }
					?>
						<span class="cbnexus-pill <?php echo esc_attr($meta['pill']); ?>" style="font-size:12px;">
							<?php echo esc_html($meta['icon'] . ' ' . $counts[$type]); ?>
						</span>
					<?php endforeach; ?>
				</div>
				<?php if (!empty($recent)) : ?>
					<ul style="margin:0;padding:0;list-style:none;">
						<?php foreach ($recent as $e) :
							$meta = self::$type_meta[$e->entry_type] ?? self::$type_meta['win'];
						?>
							<li style="display:flex;gap:6px;align-items:flex-start;padding:5px 0;border-bottom:1px solid var(--cb-border,#e8e0f0);">
								<span style="flex-shrink:0;"><?php echo esc_html($meta['icon']); ?></span>
								<span style="font-size:13px;flex:1;"><?php echo esc_html(wp_trim_words($e->content, 12)); ?></span>
								<span class="cbnexus-text-muted" style="font-size:11px;flex-shrink:0;"><?php echo esc_html(date_i18n('M j', strtotime($e->entry_date))); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
