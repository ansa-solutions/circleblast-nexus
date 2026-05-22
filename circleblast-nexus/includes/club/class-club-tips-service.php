<?php
/**
 * Club Tips Service
 *
 * Generates contextual, data-driven tips and focus items for:
 *   - Club page (club-wide): nudges based on aggregate metrics
 *   - Dashboard (personal): nudges based on individual member data
 *
 * Each tip has: icon, text, optional CTA (label + URL), priority (1=highest).
 * Tips are ranked by priority; callers decide how many to show.
 */

defined('ABSPATH') || exit;

final class CBNexus_Club_Tips_Service {

	// ─── Club-Wide Tips (for Club page) ───────────────────────────

	/**
	 * Get club-wide tips based on aggregate data.
	 *
	 * @param int $limit Max tips to return.
	 * @return array Array of tip objects: {icon, text, cta_label, cta_url, priority}
	 */
	public static function get_club_tips(int $limit = 3): array {
		$tips = [];
		$portal_url = CBNexus_Portal_Router::get_portal_url();

		$stats     = CBNexus_Portal_Club::compute_club_stats();
		$month     = self::get_this_month_activity();
		$trends    = self::get_trends();

		// (Removed) Recruitment-gap tip — the old "%d open roles" message counted
		// uncovered categories, which is misleading now that capacity is tracked
		// by seat count instead. Removed per user request; the Recruitment tab
		// shows seat-fill and category coverage directly.

		// Rule 2: Notes completion rate below threshold.
		$notes_rate = $month['notes_rate'] ?? 0;
		if ($month['meetings_completed'] > 0 && $notes_rate < 70) {
			$tips[] = (object) [
				'icon'      => '📝',
				'text'      => sprintf(
					__('Only %d%% of meetings this month have notes. Meeting notes capture wins and keep the group\'s momentum — every note counts!', 'circleblast-nexus'),
					$notes_rate
				),
				'cta_label' => __('My meetings', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'meetings', $portal_url),
				'priority'  => 2,
			];
		}

		// Rule 3: Network density is low — encourage more unique pairings.
		if ($stats['network_density'] < 50 && $stats['total_members'] >= 5) {
			$tips[] = (object) [
				'icon'      => '🤝',
				'text'      => sprintf(
					__('Network density is at %d%% — that means many member pairs haven\'t connected yet. Try scheduling a 1:1 with someone new this month!', 'circleblast-nexus'),
					$stats['network_density']
				),
				'cta_label' => __('Browse directory', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 3,
			];
		}

		// Rule 4: Meeting activity trend.
		$meetings_delta = $trends['meetings_delta'] ?? 0;
		if ($meetings_delta > 0) {
			$tips[] = (object) [
				'icon'      => '📈',
				'text'      => sprintf(
					__('Meetings are up %d from last month — great momentum! Let\'s keep it going.', 'circleblast-nexus'),
					$meetings_delta
				),
				'cta_label' => '',
				'cta_url'   => '',
				'priority'  => 5,
			];
		} elseif ($meetings_delta < 0) {
			$tips[] = (object) [
				'icon'      => '💡',
				'text'      => sprintf(
					__('Meetings are down %d from last month. A quick 1:1 goes a long way — even a 20-minute coffee chat counts!', 'circleblast-nexus'),
					abs($meetings_delta)
				),
				'cta_label' => __('Schedule a 1:1', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 3,
			];
		}

		// Rule 5: Open action items from CircleUp.
		$open_actions = self::get_club_open_actions_count();
		if ($open_actions > 0) {
			$tips[] = (object) [
				'icon'      => '✅',
				'text'      => sprintf(
					_n(
						'%d action item from CircleUp is still open. Check your dashboard to stay on track.',
						'%d action items from CircleUp are still open. Check your dashboard to stay on track.',
						$open_actions,
						'circleblast-nexus'
					),
					$open_actions
				),
				'cta_label' => __('My actions', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'dashboard', $portal_url),
				'priority'  => 2,
			];
		}

		// Rule 6: New members joined recently.
		if ($stats['new_members'] > 0) {
			$tips[] = (object) [
				'icon'      => '👋',
				'text'      => sprintf(
					_n(
						'%d new member joined in the last 90 days. Reach out for a welcome 1:1 to help them get connected!',
						'%d new members joined in the last 90 days. Reach out for a welcome 1:1 to help them get connected!',
						$stats['new_members'],
						'circleblast-nexus'
					),
					$stats['new_members']
				),
				'cta_label' => __('See who\'s new', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 4,
			];
		}

		// Rule 7: Celebrate wins milestone.
		if ($stats['wins_total'] > 0 && $stats['wins_total'] % 10 <= 3 && $stats['wins_total'] >= 10) {
			$tips[] = (object) [
				'icon'      => '🏆',
				'text'      => sprintf(
					__('The group has hit %d total wins! Every win shared at CircleUp builds momentum for the whole group.', 'circleblast-nexus'),
					$stats['wins_total']
				),
				'cta_label' => '',
				'cta_url'   => '',
				'priority'  => 6,
			];
		}

		usort($tips, fn($a, $b) => $a->priority - $b->priority);
		return array_slice($tips, 0, $limit);
	}

	// ─── Personal Tips (for Dashboard) ────────────────────────────

	/**
	 * Get personal tips for a specific member.
	 *
	 * @param int $user_id Member user ID.
	 * @param int $limit   Max tips to return.
	 * @return array Array of tip objects.
	 */
	public static function get_personal_tips(int $user_id, int $limit = 3): array {
		global $wpdb;
		$tips = [];
		$portal_url = CBNexus_Portal_Router::get_portal_url();

		$profile = CBNexus_Member_Repository::get_profile($user_id);
		if (!$profile) {
			return [];
		}

		$members = CBNexus_Member_Repository::get_all_members('active');
		$total_members = max(1, count($members) - 1);

		// Personal meeting stats.
		$meetings_completed = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE (member_a_id = %d OR member_b_id = %d) AND status IN ('completed', 'closed')",
			$user_id, $user_id
		));

		$unique_met = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(DISTINCT CASE WHEN member_a_id = %d THEN member_b_id ELSE member_a_id END)
			 FROM {$wpdb->prefix}cb_meetings
			 WHERE (member_a_id = %d OR member_b_id = %d) AND status IN ('completed', 'closed')",
			$user_id, $user_id, $user_id
		));

		$notes_submitted = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meeting_notes WHERE author_id = %d", $user_id
		));
		$notes_rate = $meetings_completed > 0 ? min(100, round($notes_submitted / $meetings_completed * 100)) : 0;

		$pending     = CBNexus_Meeting_Repository::get_pending_for_member($user_id);
		$needs_notes = CBNexus_Meeting_Repository::get_needs_notes($user_id);
		$open_actions = CBNexus_CircleUp_Repository::count_open_actions($user_id);

		// Days since last meeting.
		$last_meeting_date = $wpdb->get_var($wpdb->prepare(
			"SELECT MAX(COALESCE(completed_at, updated_at)) FROM {$wpdb->prefix}cb_meetings
			 WHERE (member_a_id = %d OR member_b_id = %d) AND status IN ('completed', 'closed')",
			$user_id, $user_id
		));
		$days_since_last = $last_meeting_date ? (int) ((time() - strtotime($last_meeting_date)) / DAY_IN_SECONDS) : 999;

		// Industries not yet connected with.
		$met_industries = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT um.meta_value FROM {$wpdb->prefix}cb_meetings m
			 JOIN {$wpdb->usermeta} um ON um.user_id = CASE WHEN m.member_a_id = %d THEN m.member_b_id ELSE m.member_a_id END
			 AND um.meta_key = 'cb_industry'
			 WHERE (m.member_a_id = %d OR m.member_b_id = %d) AND m.status IN ('completed', 'closed')",
			$user_id, $user_id, $user_id
		));
		$all_industries = array_unique(array_filter(array_map(fn($m) => $m['cb_industry'] ?? '', $members)));
		$unmet_industries = array_diff($all_industries, $met_industries ?: []);

		// Rule 1: Outstanding notes.
		if (!empty($needs_notes)) {
			$count = count($needs_notes);
			$tips[] = (object) [
				'icon'      => '📝',
				'text'      => sprintf(
					_n(
						'You have %d meeting waiting for notes — capture your wins and insights while they\'re fresh!',
						'You have %d meetings waiting for notes — capture your wins and insights while they\'re fresh!',
						$count,
						'circleblast-nexus'
					),
					$count
				),
				'cta_label' => __('Add notes', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'meetings', $portal_url),
				'priority'  => 1,
			];
		}

		// Rule 2: Pending meeting requests.
		if (!empty($pending)) {
			$count = count($pending);
			$tips[] = (object) [
				'icon'      => '⚡',
				'text'      => sprintf(
					_n(
						'%d meeting request is waiting for your response.',
						'%d meeting requests are waiting for your response.',
						$count,
						'circleblast-nexus'
					),
					$count
				),
				'cta_label' => __('Respond', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'meetings', $portal_url),
				'priority'  => 1,
			];
		}

		// Rule 3: Open action items.
		if ($open_actions > 0) {
			$tips[] = (object) [
				'icon'      => '✅',
				'text'      => sprintf(
					_n(
						'You have %d open action item from CircleUp.',
						'You have %d open action items from CircleUp.',
						$open_actions,
						'circleblast-nexus'
					),
					$open_actions
				),
				'cta_label' => '',
				'cta_url'   => '',
				'priority'  => 2,
			];
		}

		// Rule 4: Haven't met in a while / never met.
		if ($days_since_last > 30 && $meetings_completed > 0) {
			$tips[] = (object) [
				'icon'      => '☕',
				'text'      => sprintf(
					__('It\'s been %d days since your last 1:1. A quick coffee chat keeps your connections strong!', 'circleblast-nexus'),
					$days_since_last
				),
				'cta_label' => __('Find someone to meet', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 3,
			];
		} elseif ($meetings_completed === 0) {
			$tips[] = (object) [
				'icon'      => '🚀',
				'text'      => __('You haven\'t had your first 1:1 yet! Browse the directory and request a meeting — everyone\'s been in your shoes.', 'circleblast-nexus'),
				'cta_label' => __('Browse directory', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 2,
			];
		}

		// Rule 5: Low notes rate.
		if ($meetings_completed >= 3 && $notes_rate < 50) {
			$tips[] = (object) [
				'icon'      => '💡',
				'text'      => sprintf(
					__('Your notes completion is at %d%%. Adding notes after meetings helps the group track wins and stay accountable.', 'circleblast-nexus'),
					$notes_rate
				),
				'cta_label' => __('My meetings', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'meetings', $portal_url),
				'priority'  => 3,
			];
		}

		// Rule 6: Expand your network.
		if (!empty($unmet_industries) && $unique_met < $total_members) {
			$suggest = array_slice($unmet_industries, 0, 2);
			$pct = $total_members > 0 ? round($unique_met / $total_members * 100) : 0;
			$tips[] = (object) [
				'icon'      => '🌐',
				'text'      => sprintf(
					__('You\'ve met %d%% of the group. Try connecting with someone in %s to expand your network.', 'circleblast-nexus'),
					$pct,
					implode(' or ', $suggest)
				),
				'cta_label' => __('Browse directory', 'circleblast-nexus'),
				'cta_url'   => add_query_arg('section', 'directory', $portal_url),
				'priority'  => 4,
			];
		}

		// Rule 7: Positive reinforcement.
		$this_month_meetings = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE (member_a_id = %d OR member_b_id = %d) AND status IN ('completed', 'closed')
			 AND MONTH(COALESCE(completed_at, updated_at)) = MONTH(NOW())
			 AND YEAR(COALESCE(completed_at, updated_at)) = YEAR(NOW())",
			$user_id, $user_id
		));
		if ($this_month_meetings >= 3) {
			$tips[] = (object) [
				'icon'      => '🔥',
				'text'      => sprintf(
					__('You\'ve had %d meetings this month — you\'re on fire! Keep the connections flowing.', 'circleblast-nexus'),
					$this_month_meetings
				),
				'cta_label' => '',
				'cta_url'   => '',
				'priority'  => 6,
			];
		}

		// Rule 8: Recruitment.
		if (class_exists('CBNexus_Recruitment_Coverage_Service')) {
			$summary = CBNexus_Recruitment_Coverage_Service::get_summary();
			if ($summary['gaps'] > 0) {
				$focus = CBNexus_Recruitment_Coverage_Service::get_focus_categories(2);
				$names = array_map(fn($c) => $c->title, $focus);
				if (!empty($names)) {
					$tips[] = (object) [
						'icon'      => '🎯',
						'text'      => sprintf(
							__('We\'re looking for a %s — know someone who\'d be a great fit?', 'circleblast-nexus'),
							implode(' or ', $names)
						),
						'cta_label' => __('Refer someone', 'circleblast-nexus'),
						'cta_url'   => add_query_arg(['section' => 'club', 'coverage' => 'expanded'], $portal_url) . '#coverage-scorecard',
						'priority'  => 5,
					];
				}
			}
		}

		usort($tips, fn($a, $b) => $a->priority - $b->priority);
		return array_slice($tips, 0, $limit);
	}

	// ─── Data Helpers ─────────────────────────────────────────────

	/**
	 * Get this month's activity metrics (club-wide).
	 */
	public static function get_this_month_activity(): array {
		global $wpdb;
		$month_start = gmdate('Y-m-01 00:00:00');

		$meetings_completed = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE status IN ('completed', 'closed')
			 AND COALESCE(completed_at, updated_at) >= '{$month_start}'"
		);

		$suggestions_sent = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE source = 'auto' AND suggested_at >= '{$month_start}'"
		);

		$notes_submitted = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meeting_notes
			 WHERE created_at >= '{$month_start}'"
		);

		$meetings_with_notes = 0;
		if ($meetings_completed > 0) {
			$meetings_with_notes = (int) $wpdb->get_var(
				"SELECT COUNT(DISTINCT m.id) FROM {$wpdb->prefix}cb_meetings m
				 JOIN {$wpdb->prefix}cb_meeting_notes n ON n.meeting_id = m.id
				 WHERE m.status IN ('completed', 'closed')
				 AND COALESCE(m.completed_at, m.updated_at) >= '{$month_start}'"
			);
		}
		$notes_rate = $meetings_completed > 0 ? round($meetings_with_notes / $meetings_completed * 100) : 0;

		$new_members = 0;
		$members = CBNexus_Member_Repository::get_all_members('active');
		foreach ($members as $m) {
			if (($m['cb_join_date'] ?? '') >= gmdate('Y-m-01')) {
				$new_members++;
			}
		}

		return [
			'meetings_completed' => $meetings_completed,
			'suggestions_sent'   => $suggestions_sent,
			'notes_submitted'    => $notes_submitted,
			'notes_rate'         => $notes_rate,
			'new_members'        => $new_members,
		];
	}

	/**
	 * Get trend deltas comparing current to ~30 days prior.
	 */
	public static function get_trends(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_analytics_snapshots';

		$latest = $wpdb->get_results(
			"SELECT metric_key, metric_value FROM {$table}
			 WHERE scope = 'club' ORDER BY snapshot_date DESC LIMIT 10"
		);

		$month_ago_date = gmdate('Y-m-d', strtotime('-30 days'));
		$older = $wpdb->get_results($wpdb->prepare(
			"SELECT metric_key, metric_value FROM {$table}
			 WHERE scope = 'club' AND snapshot_date <= %s
			 ORDER BY snapshot_date DESC LIMIT 10",
			$month_ago_date
		));

		$latest_map = [];
		$older_map  = [];
		foreach ($latest ?: [] as $r) {
			if (!isset($latest_map[$r->metric_key])) {
				$latest_map[$r->metric_key] = (float) $r->metric_value;
			}
		}
		foreach ($older ?: [] as $r) {
			if (!isset($older_map[$r->metric_key])) {
				$older_map[$r->metric_key] = (float) $r->metric_value;
			}
		}

		if (empty($older_map)) {
			return self::compute_direct_trends();
		}

		$deltas = [];
		foreach ($latest_map as $key => $val) {
			$old = $older_map[$key] ?? $val;
			$deltas[$key . '_delta'] = $val - $old;
			$deltas[$key . '_prev']  = $old;
		}

		$direct = self::compute_direct_trends();
		$deltas['meetings_delta'] = $deltas['meetings_total_delta'] ?? $direct['meetings_delta'] ?? 0;
		$deltas['members_delta']  = $deltas['total_members_delta'] ?? $direct['members_delta'] ?? 0;

		return $deltas;
	}

	/**
	 * Direct comparison: this month vs last month.
	 */
	private static function compute_direct_trends(): array {
		global $wpdb;

		$this_month_start = gmdate('Y-m-01 00:00:00');
		$last_month_start = gmdate('Y-m-01 00:00:00', strtotime('-1 month'));

		$meetings_this = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE status IN ('completed', 'closed')
			 AND COALESCE(completed_at, updated_at) >= '{$this_month_start}'"
		);

		$meetings_last = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings
			 WHERE status IN ('completed', 'closed')
			 AND COALESCE(completed_at, updated_at) >= %s AND COALESCE(completed_at, updated_at) < %s",
			$last_month_start, $this_month_start
		));

		$members = CBNexus_Member_Repository::get_all_members('active');
		$new_this_month = 0;
		foreach ($members as $m) {
			if (($m['cb_join_date'] ?? '') >= gmdate('Y-m-01')) {
				$new_this_month++;
			}
		}

		return [
			'meetings_delta' => $meetings_this - $meetings_last,
			'members_delta'  => $new_this_month,
		];
	}

	/**
	 * Get sparkline data for a metric over the last N months.
	 */
	public static function get_sparkline_data(string $metric_key, int $months = 6): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_analytics_snapshots';

		$since = gmdate('Y-m-01', strtotime("-{$months} months"));
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT snapshot_date, metric_value FROM {$table}
			 WHERE scope = 'club' AND metric_key = %s AND snapshot_date >= %s
			 ORDER BY snapshot_date ASC",
			$metric_key, $since
		));

		$monthly = [];
		foreach ($rows ?: [] as $r) {
			$month_key = substr($r->snapshot_date, 0, 7);
			$monthly[$month_key] = (float) $r->metric_value;
		}

		$result = [];
		foreach ($monthly as $ym => $val) {
			$result[] = [
				'label' => date_i18n('M', strtotime($ym . '-01')),
				'value' => $val,
			];
		}

		return $result;
	}

	/**
	 * Get stat card trends for club page stat grid.
	 */
	public static function get_stat_trends(): array {
		$trends = self::get_trends();

		return [
			'total_members'   => self::format_trend($trends['members_delta'] ?? 0, 'this month'),
			'meetings_total'  => self::format_trend($trends['meetings_delta'] ?? 0, 'vs last month'),
			'network_density' => self::format_trend($trends['network_density_delta'] ?? 0, 'vs last month', '%'),
			'new_members'     => null,
			'circleup_count'  => null,
			'wins_total'      => null,
		];
	}

	private static function format_trend($delta, string $context, string $suffix = ''): ?object {
		$delta = (int) round($delta);
		if ($delta === 0) { return null; }

		return (object) [
			'delta'     => $delta,
			'direction' => $delta > 0 ? 'up' : 'down',
			'arrow'     => $delta > 0 ? '▲' : '▼',
			'label'     => ($delta > 0 ? '+' : '') . $delta . $suffix . ' ' . $context,
			'css_class' => $delta > 0 ? 'cbnexus-trend--up' : 'cbnexus-trend--down',
		];
	}

	private static function get_club_open_actions_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_circleup_items
			 WHERE item_type = 'action' AND status IN ('pending', 'in_progress')"
		);
	}

	/**
	 * Get new members (joined in the current calendar month).
	 *
	 * Uses cb_join_date — members added through the normal flow
	 * (recruitment pipeline or manual admin creation) get the correct
	 * date stamped automatically.
	 */
	public static function get_new_members(int $limit = 5): array {
		$members     = CBNexus_Member_Repository::get_all_members('active');
		$month_start = gmdate('Y-m-01');

		$new = [];
		foreach ($members as $m) {
			$join_date = $m['cb_join_date'] ?? '';
			if ($join_date >= $month_start) {
				$new[] = $m;
			}
		}

		usort($new, fn($a, $b) => ($b['cb_join_date'] ?? '') <=> ($a['cb_join_date'] ?? ''));
		return array_slice($new, 0, $limit);
	}

	/**
	 * Get upcoming visitors (candidates at 'invited' stage only).
	 */
	public static function get_visitors(int $limit = 5): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';

		if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
			return [];
		}

		return $wpdb->get_results($wpdb->prepare(
			"SELECT c.*, u.display_name as referrer_name
			 FROM {$table} c
			 LEFT JOIN {$wpdb->users} u ON c.referrer_id = u.ID
			 WHERE c.stage = 'invited'
			 ORDER BY c.updated_at DESC LIMIT %d",
			$limit
		)) ?: [];
	}
}
