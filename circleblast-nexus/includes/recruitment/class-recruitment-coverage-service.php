<?php
/**
 * Recruitment Coverage Service
 *
 * Phase 1 of Recruitment Coverage Visibility.
 * Computes coverage status for each recruitment category based on
 * which active members are tagged with that category via cb_member_categories.
 * Also retrieves pipeline candidates linked to each category.
 */

defined('ABSPATH') || exit;

final class CBNexus_Recruitment_Coverage_Service {

	/**
	 * Coverage status constants.
	 */
	public const STATUS_COVERED  = 'covered';
	public const STATUS_PARTIAL  = 'partial';
	public const STATUS_GAP      = 'gap';

	/**
	 * Get full coverage data for all categories.
	 *
	 * Returns an array of category objects enriched with:
	 *   - member_count:  int
	 *   - members:       array of {user_id, display_name}
	 *   - coverage_status: 'covered' | 'partial' | 'gap'
	 *   - pipeline_candidates: array of candidate objects linked to this category
	 *
	 * @return array
	 */
	public static function get_full_coverage(): array {
		// Cache per request to avoid recomputing for summary/gaps/focus calls.
		static $cached = null;
		if ($cached !== null) {
			return $cached;
		}

		global $wpdb;

		$cat_table = $wpdb->prefix . 'cb_recruitment_categories';
		$categories = $wpdb->get_results("SELECT * FROM {$cat_table} ORDER BY title ASC");

		if (empty($categories)) {
			return [];
		}

		// Build a map of category_id => [member data].
		$member_map = self::build_member_category_map();

		// Build a map of category_id => [pipeline candidates].
		$pipeline_map = self::build_pipeline_map();

		$results = [];
		foreach ($categories as $cat) {
			$members      = $member_map[$cat->id] ?? [];
			$member_count = count($members);
			$target       = isset($cat->target_count) ? max(1, (int) $cat->target_count) : 1;

			if ($member_count >= $target) {
				$status = self::STATUS_COVERED;
			} elseif ($member_count > 0) {
				$status = self::STATUS_PARTIAL;
			} else {
				$status = self::STATUS_GAP;
			}

			$cat->member_count       = $member_count;
			$cat->members            = $members;
			$cat->coverage_status    = $status;
			$cat->target_count       = $target;
			$cat->pipeline_candidates = $pipeline_map[$cat->id] ?? [];

			$results[] = $cat;
		}

		$cached = $results;
		return $results;
	}

	/**
	 * Get summary stats across all categories.
	 *
	 * @return array{total:int, covered:int, partial:int, gaps:int, coverage_pct:float, capacity_filled:int, capacity_total:int}
	 */
	public static function get_summary(): array {
		$categories = self::get_full_coverage();
		$total   = count($categories);
		$covered = 0;
		$partial = 0;
		$gaps    = 0;

		foreach ($categories as $cat) {
			switch ($cat->coverage_status) {
				case self::STATUS_COVERED:
					$covered++;
					break;
				case self::STATUS_PARTIAL:
					$partial++;
					break;
				case self::STATUS_GAP:
					$gaps++;
					break;
			}
		}

		// Group capacity is decoupled from category count — track filled-of-N seats.
		$active_members = CBNexus_Member_Repository::get_all_members('active');
		$capacity_total = class_exists('CBNexus_Recruitment_Settings')
			? CBNexus_Recruitment_Settings::get_capacity_total()
			: 25;

		return [
			'total'           => $total,
			'covered'         => $covered,
			'partial'         => $partial,
			'gaps'            => $gaps,
			'coverage_pct'    => $total > 0 ? round(($covered / $total) * 100, 1) : 0,
			'capacity_filled' => count($active_members),
			'capacity_total'  => $capacity_total,
		];
	}

	/**
	 * Get top N gap categories (open needs), ordered by priority.
	 *
	 * @param int $limit Max number of gaps to return.
	 * @return array
	 */
	public static function get_top_gaps(int $limit = 5): array {
		$categories = self::get_full_coverage();
		$priority_order = ['high' => 0, 'medium' => 1, 'low' => 2];

		$gaps = array_filter($categories, function ($cat) {
			return $cat->coverage_status === self::STATUS_GAP;
		});

		usort($gaps, function ($a, $b) use ($priority_order) {
			$pa = $priority_order[$a->priority] ?? 1;
			$pb = $priority_order[$b->priority] ?? 1;
			return $pa - $pb;
		});

		return array_slice($gaps, 0, $limit);
	}

	/**
	 * Get coverage data for a single category.
	 *
	 * @param int $category_id
	 * @return object|null
	 */
	public static function get_category_coverage(int $category_id): ?object {
		$all = self::get_full_coverage();
		foreach ($all as $cat) {
			if ((int) $cat->id === $category_id) {
				return $cat;
			}
		}
		return null;
	}

	/**
	 * Get the category ID assigned to a member (single category per the 1:1 constraint).
	 *
	 * @param int $user_id
	 * @return int|null Category ID or null.
	 */
	public static function get_member_category(int $user_id): ?int {
		$raw = get_user_meta($user_id, 'cb_member_categories', true);
		if (empty($raw)) {
			return null;
		}

		// Stored as JSON array but we only use the first value (one category per member).
		$decoded = is_string($raw) ? json_decode($raw, true) : $raw;
		if (is_array($decoded) && !empty($decoded)) {
			return (int) $decoded[0];
		}

		// Handle legacy scalar value.
		if (is_numeric($raw)) {
			return (int) $raw;
		}

		return null;
	}

	/**
	 * Set the category for a member.
	 *
	 * @param int      $user_id
	 * @param int|null $category_id  Null or 0 to clear.
	 * @return void
	 */
	public static function set_member_category(int $user_id, ?int $category_id): void {
		if (empty($category_id)) {
			delete_user_meta($user_id, 'cb_member_categories');
			return;
		}

		// Store as JSON array for consistency with other array meta fields.
		update_user_meta($user_id, 'cb_member_categories', wp_json_encode([$category_id]));
	}

	/**
	 * Build a map of category_id => list of active members tagged with that category.
	 *
	 * @return array<int, array>
	 */
	private static function build_member_category_map(): array {
		$members = CBNexus_Member_Repository::get_all_members('active');
		$map = [];

		foreach ($members as $m) {
			$cat_id = self::get_member_category($m['user_id']);
			if ($cat_id === null) {
				continue;
			}

			if (!isset($map[$cat_id])) {
				$map[$cat_id] = [];
			}

			$map[$cat_id][] = [
				'user_id'      => $m['user_id'],
				'display_name' => $m['display_name'],
				'cb_company'   => $m['cb_company'] ?? '',
			];
		}

		return $map;
	}

	/**
	 * Build a map of category_id => pipeline candidates (not yet accepted/declined).
	 *
	 * @return array<int, array>
	 */
	private static function build_pipeline_map(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'cb_candidates';
		$active_stages = ['referral', 'contacted', 'invited', 'visited', 'decision'];
		$placeholders  = implode(',', array_fill(0, count($active_stages), '%s'));

		$candidates = $wpdb->get_results($wpdb->prepare(
			"SELECT id, name, email, company, industry, category_id, stage, updated_at
			 FROM {$table}
			 WHERE category_id IS NOT NULL AND stage IN ({$placeholders})
			 ORDER BY updated_at DESC",
			...$active_stages
		));

		$map = [];
		foreach ($candidates as $c) {
			$cid = (int) $c->category_id;
			if (!isset($map[$cid])) {
				$map[$cid] = [];
			}
			$map[$cid][] = $c;
		}

		return $map;
	}

	// ─── Monthly Focus Categories ────────────────────────────────────

	/**
	 * Get the current monthly focus categories.
	 *
	 * Returns the randomly-selected focus categories for this month's cycle.
	 * Falls back to get_top_gaps() if no focus has been set yet or if
	 * the stored focus is stale / empty.
	 *
	 * @param int $limit Max results (0 = use stored count).
	 * @return array Array of category objects.
	 */
	public static function get_focus_categories(int $limit = 0): array {
		$focus = get_option('cbnexus_recruitment_focus', []);

		// If no focus stored, fall back to top gaps.
		if (empty($focus) || empty($focus['category_ids'])) {
			$settings = self::get_focus_settings();
			return self::get_top_gaps($limit > 0 ? $limit : $settings['count']);
		}

		$all = self::get_full_coverage();
		$ids = (array) $focus['category_ids'];

		// Filter to only the focus IDs that are still gaps/partial.
		$focused = [];
		foreach ($all as $cat) {
			if (in_array((int) $cat->id, $ids, true) && $cat->coverage_status !== self::STATUS_COVERED) {
				$focused[] = $cat;
			}
		}

		// If all focus categories got filled since rotation, fall back.
		if (empty($focused)) {
			$settings = self::get_focus_settings();
			return self::get_top_gaps($limit > 0 ? $limit : $settings['count']);
		}

		return $limit > 0 ? array_slice($focused, 0, $limit) : $focused;
	}

	/**
	 * Get focus settings (admin-configurable).
	 *
	 * @return array{count: int, coverage_threshold: int}
	 */
	public static function get_focus_settings(): array {
		$saved = get_option('cbnexus_recruitment_focus_settings', []);
		return [
			'count'              => max(1, min(10, (int) ($saved['count'] ?? 3))),
			'coverage_threshold' => max(50, min(100, (int) ($saved['coverage_threshold'] ?? 80))),
		];
	}

	/**
	 * Save focus settings.
	 *
	 * @param array $data
	 */
	public static function save_focus_settings(array $data): void {
		update_option('cbnexus_recruitment_focus_settings', [
			'count'              => max(1, min(10, (int) ($data['count'] ?? 3))),
			'coverage_threshold' => max(50, min(100, (int) ($data['coverage_threshold'] ?? 80))),
		], false);
	}

	/**
	 * Get stored focus metadata (for display in admin).
	 *
	 * @return array{category_ids: int[], rotated_at: string, next_circleup: string}
	 */
	public static function get_focus_meta(): array {
		$focus = get_option('cbnexus_recruitment_focus', []);
		return [
			'category_ids'  => $focus['category_ids'] ?? [],
			'rotated_at'    => $focus['rotated_at'] ?? '',
			'next_circleup' => $focus['next_circleup'] ?? '',
		];
	}

	/**
	 * Rotate focus categories (called by cron 2 days before CircleUp).
	 *
	 * Picks N random gap categories, avoiding recently-featured ones where
	 * possible, and stores them. Skips rotation if coverage is above threshold.
	 */
	public static function rotate_focus(): void {
		$settings  = self::get_focus_settings();
		$summary   = self::get_summary();

		// Stop sending focus if coverage exceeds threshold.
		if ($summary['total'] > 0 && $summary['coverage_pct'] >= $settings['coverage_threshold']) {
			// Clear focus — all pages will fall back to showing nothing or "all covered".
			update_option('cbnexus_recruitment_focus', [
				'category_ids'  => [],
				'rotated_at'    => gmdate('Y-m-d H:i:s'),
				'next_circleup' => self::compute_next_circleup_date(),
				'skipped'       => true,
				'skip_reason'   => sprintf('Coverage at %s%% (threshold: %s%%)', $summary['coverage_pct'], $settings['coverage_threshold']),
			], false);

			if (class_exists('CBNexus_Logger')) {
				CBNexus_Logger::info('Recruitment focus rotation skipped — coverage above threshold.', [
					'coverage_pct' => $summary['coverage_pct'],
					'threshold'    => $settings['coverage_threshold'],
				]);
			}
			return;
		}

		$all_gaps = array_filter(self::get_full_coverage(), function ($cat) {
			return $cat->coverage_status !== self::STATUS_COVERED;
		});

		if (empty($all_gaps)) {
			update_option('cbnexus_recruitment_focus', [
				'category_ids'  => [],
				'rotated_at'    => gmdate('Y-m-d H:i:s'),
				'next_circleup' => self::compute_next_circleup_date(),
				'skipped'       => true,
				'skip_reason'   => 'No gap categories to rotate.',
			], false);
			return;
		}

		$count = min($settings['count'], count($all_gaps));

		// Get previously-featured IDs to deprioritize them.
		$prev_focus = get_option('cbnexus_recruitment_focus', []);
		$prev_ids   = $prev_focus['category_ids'] ?? [];

		// Split into not-recently-featured and recently-featured.
		$fresh  = [];
		$stale  = [];
		foreach ($all_gaps as $cat) {
			$cid = (int) $cat->id;
			if (in_array($cid, $prev_ids, true)) {
				$stale[] = $cat;
			} else {
				$fresh[] = $cat;
			}
		}

		// Shuffle both pools randomly.
		shuffle($fresh);
		shuffle($stale);

		// Pick from fresh first, then fill remainder from stale.
		$pool     = array_merge($fresh, $stale);
		$selected = array_slice($pool, 0, $count);
		$ids      = array_map(function ($cat) { return (int) $cat->id; }, $selected);

		update_option('cbnexus_recruitment_focus', [
			'category_ids'  => $ids,
			'rotated_at'    => gmdate('Y-m-d H:i:s'),
			'next_circleup' => self::compute_next_circleup_date(),
			'skipped'       => false,
		], false);

		if (class_exists('CBNexus_Logger')) {
			CBNexus_Logger::info('Recruitment focus categories rotated.', [
				'count'    => count($ids),
				'ids'      => $ids,
				'titles'   => array_map(function ($cat) { return $cat->title; }, $selected),
			]);
		}
	}

	/**
	 * Public wrapper for compute_next_circleup_date so the recruitment-settings
	 * helper can build the candidate invitation date+time without duplicating
	 * the 4th-Friday formula.
	 *
	 * @return string Y-m-d
	 */
	public static function get_next_circleup_date_public(): string {
		return self::compute_next_circleup_date();
	}

	/**
	 * Compute the date of the next 4th Friday from today.
	 *
	 * @return string Y-m-d
	 */
	private static function compute_next_circleup_date(): string {
		$now   = new \DateTime('now', new \DateTimeZone('UTC'));
		$year  = (int) $now->format('Y');
		$month = (int) $now->format('n');

		// Try this month first.
		$fourth_friday = self::nth_weekday_of_month(4, 5, $month, $year); // 5 = Friday
		if ($fourth_friday > $now) {
			return $fourth_friday->format('Y-m-d');
		}

		// Otherwise next month.
		$month++;
		if ($month > 12) {
			$month = 1;
			$year++;
		}
		return self::nth_weekday_of_month(4, 5, $month, $year)->format('Y-m-d');
	}

	/**
	 * Get the Nth occurrence of a weekday in a given month.
	 *
	 * @param int $nth      Which occurrence (1-5).
	 * @param int $weekday  ISO weekday (1=Mon, 5=Fri, 7=Sun).
	 * @param int $month    Month number (1-12).
	 * @param int $year     Year.
	 * @return \DateTime
	 */
	private static function nth_weekday_of_month(int $nth, int $weekday, int $month, int $year): \DateTime {
		$day_names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
		$name = $day_names[$weekday] ?? 'Friday';
		$ordinals = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth'];
		$ord = $ordinals[$nth] ?? 'fourth';

		$date = new \DateTime("{$ord} {$name} of {$year}-{$month}", new \DateTimeZone('UTC'));
		return $date;
	}

	/**
	 * Cron callback: rotate focus categories.
	 *
	 * Runs on the cron schedule (default: monthly, recommended: 4th Wednesday).
	 * The cron frequency is configurable in Settings → Cron Jobs.
	 */
	public static function cron_rotate_focus(): void {
		self::rotate_focus();
	}

	/**
	 * Check if focus is currently active (has categories and wasn't skipped).
	 *
	 * @return bool
	 */
	public static function has_active_focus(): bool {
		$focus = get_option('cbnexus_recruitment_focus', []);
		return !empty($focus['category_ids']) && empty($focus['skipped']);
	}

	// ─── Email Prompt HTML ────────────────────────────────────────────

	/**
	 * Get candidate counts by pipeline stage.
	 *
	 * @return array{referral:int, contacted:int, invited:int, visited:int, decision:int, total:int}
	 */
	public static function get_pipeline_summary(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';

		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return ['referral' => 0, 'contacted' => 0, 'invited' => 0, 'visited' => 0, 'decision' => 0, 'total' => 0];
		}

		$rows = $wpdb->get_results(
			"SELECT stage, COUNT(*) as cnt FROM {$table}
			 WHERE stage IN ('referral','contacted','invited','visited','decision')
			 GROUP BY stage"
		);

		$counts = ['referral' => 0, 'contacted' => 0, 'invited' => 0, 'visited' => 0, 'decision' => 0];
		$total = 0;
		foreach ($rows ?: [] as $r) {
			$counts[$r->stage] = (int) $r->cnt;
			$total += (int) $r->cnt;
		}
		$counts['total'] = $total;

		return $counts;
	}

	/**
	 * Get the subtle footer block HTML for regular emails.
	 *
	 * Shows top 2-3 open categories with priority dots in a compact list.
	 * Returns empty string if no gaps exist.
	 */
	public static function get_footer_prompt_html(): string {
		$gaps = self::get_focus_categories(3);
		if (empty($gaps)) {
			return '';
		}

		$admin_email = get_option('admin_email', '');
		$p_colors = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#059669'];

		$html  = '<tr><td style="padding:0 30px;">';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">';
		$html .= '<tr><td style="border-top:1px solid #e9e3ed;padding:18px 0 4px;">';
		$html .= '<p style="margin:0 0 10px;font-size:12px;font-weight:700;color:#8b7a94;text-transform:uppercase;letter-spacing:0.8px;">We\'re Looking For&hellip;</p>';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">';

		foreach ($gaps as $gap) {
			$dot = esc_attr($p_colors[$gap->priority] ?? '#d97706');
			$html .= '<tr><td style="padding:4px 0;font-size:13px;color:#333;">';
			$html .= '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:' . $dot . ';margin-right:6px;vertical-align:middle;"></span>';
			$html .= '<strong>' . esc_html($gap->title) . '</strong>';
			if ($gap->description) {
				$html .= ' <span style="color:#888;">&mdash; ' . esc_html(wp_trim_words($gap->description, 8, '…')) . '</span>';
			}
			$html .= '</td></tr>';
		}

		$html .= '</table>';

		if ($admin_email) {
			$portal_url = class_exists('CBNexus_Portal_Router') ? CBNexus_Portal_Router::get_portal_url() : home_url();
			$referral_url = add_query_arg('referral', 'open', $portal_url);
			$html .= '<p style="margin:10px 0 0;font-size:12px;color:#8b7a94;">Know someone who\'d be a great fit? <a href="' . esc_url($referral_url) . '" style="color:#5b2d6e;font-weight:600;text-decoration:none;">Submit a referral &rarr;</a></p>';
		}

		$html .= '</td></tr></table></td></tr>';

		return $html;
	}

	/**
	 * Get the prominent "Help Us Grow" section HTML for digest/summary emails.
	 *
	 * Returns empty string if no gaps exist.
	 */
	public static function get_prominent_prompt_html(): string {
		$gaps = self::get_focus_categories(3);
		if (empty($gaps)) {
			return '';
		}

		$admin_email = get_option('admin_email', '');
		$p_colors = ['high' => '#dc2626', 'medium' => '#d97706', 'low' => '#059669'];
		$p_labels = ['high' => 'High Priority', 'medium' => 'Medium Priority', 'low' => 'Low Priority'];

		$html  = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#faf6fc;border:1px solid #e9e3ed;border-radius:8px;margin:20px 0 0;">';
		$html .= '<tr><td style="padding:22px 24px;">';
		$html .= '<h3 style="margin:0 0 6px;font-size:16px;color:#5b2d6e;font-weight:700;">&#127793; Help Us Grow</h3>';
		$html .= '<p style="margin:0 0 14px;font-size:13px;color:#555;line-height:1.5;">We\'re actively looking for members who can fill these roles. If someone comes to mind, we\'d love an introduction.</p>';
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0">';

		$first = true;
		foreach ($gaps as $gap) {
			$dot   = esc_attr($p_colors[$gap->priority] ?? '#d97706');
			$label = esc_html($p_labels[$gap->priority] ?? 'Medium Priority');

			if (!$first) {
				$html .= '<tr><td style="height:6px;"></td></tr>';
			}
			$first = false;

			$html .= '<tr><td style="padding:8px 12px;background:#fff;border-radius:6px;">';
			$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>';
			$html .= '<td style="font-size:14px;color:#1a1a2e;">';
			$html .= '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $dot . ';margin-right:8px;vertical-align:middle;"></span>';
			$html .= '<strong>' . esc_html($gap->title) . '</strong>';
			$html .= '</td>';
			$html .= '<td style="text-align:right;font-size:12px;color:' . $dot . ';font-weight:600;">' . $label . '</td>';
			$html .= '</tr></table>';

			if ($gap->description) {
				$html .= '<p style="margin:4px 0 0 16px;font-size:12px;color:#777;">' . esc_html(wp_trim_words($gap->description, 12, '…')) . '</p>';
			}
			$html .= '</td></tr>';
		}

		$html .= '</table>';

		// CTA
		if ($admin_email) {
			$portal_url = class_exists('CBNexus_Portal_Router') ? CBNexus_Portal_Router::get_portal_url() : home_url();
			$referral_url = add_query_arg('referral', 'open', $portal_url);
			$html .= '<p style="margin:16px 0 0;text-align:center;">';
			$html .= '<a href="' . esc_url($referral_url) . '" style="display:inline-block;padding:10px 24px;background:#5b2d6e;color:#fff;text-decoration:none;border-radius:6px;font-size:13px;font-weight:600;">Submit a Referral</a>';
			$html .= '</p>';
		}

		$html .= '</td></tr></table>';

		return $html;
	}
}