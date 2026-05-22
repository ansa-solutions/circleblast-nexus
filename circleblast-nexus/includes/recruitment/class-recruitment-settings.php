<?php
/**
 * Recruitment Settings
 *
 * Small accessor for tunable knobs that previously lived as magic numbers
 * inside the recruitment automations. Each value has a sensible default
 * so admins don't have to configure anything for the system to work.
 */

defined('ABSPATH') || exit;

final class CBNexus_Recruitment_Settings {

	const OPT_CAPACITY_TOTAL         = 'cbnexus_capacity_total';
	const OPT_ONBOARDING_USER_IDS    = 'cbnexus_onboarding_user_ids';
	const OPT_COUNCIL_REVIEW_HOURS   = 'cbnexus_council_review_hours';
	const OPT_CIRCLEUP_DEFAULT_TIME  = 'cbnexus_circleup_default_time';

	/**
	 * Group capacity ("N of 25"). Decoupled from category count.
	 */
	public static function get_capacity_total(): int {
		$saved = (int) get_option(self::OPT_CAPACITY_TOTAL, 25);
		return $saved > 0 ? $saved : 25;
	}

	/**
	 * Members who get the onboarding-handoff email when a candidate is Accepted.
	 *
	 * Returns an array of {user_id, user_email, display_name} entries. If no
	 * members are configured, falls back to the first cb_admin so the handoff
	 * email never silently drops on the floor.
	 *
	 * @return array<int, array{user_id:int, user_email:string, display_name:string}>
	 */
	public static function get_onboarding_recipients(): array {
		$ids = self::get_onboarding_user_ids();
		$out = [];
		foreach ($ids as $uid) {
			$u = get_userdata($uid);
			if ($u && !empty($u->user_email) && is_email($u->user_email)) {
				$out[] = [
					'user_id'      => (int) $u->ID,
					'user_email'   => $u->user_email,
					'display_name' => $u->display_name,
				];
			}
		}

		if (!empty($out)) { return $out; }

		// Fallback — first cb_admin user, so the email never gets dropped.
		$admins = get_users(['role' => 'cb_admin', 'fields' => ['ID', 'user_email', 'display_name']]);
		foreach ($admins as $a) {
			if (!empty($a->user_email) && is_email($a->user_email)) {
				return [[
					'user_id'      => (int) $a->ID,
					'user_email'   => $a->user_email,
					'display_name' => $a->display_name,
				]];
			}
		}
		return [];
	}

	/**
	 * Saved onboarding user IDs (raw).
	 *
	 * @return int[]
	 */
	public static function get_onboarding_user_ids(): array {
		$raw = get_option(self::OPT_ONBOARDING_USER_IDS, []);
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($raw)) { return []; }

		$ids = [];
		foreach ($raw as $v) {
			$id = (int) $v;
			if ($id > 0) { $ids[$id] = true; }
		}
		return array_keys($ids);
	}

	/**
	 * Save onboarding user IDs (filters to existing CB-role users).
	 */
	public static function save_onboarding_user_ids(array $user_ids): void {
		$clean = [];
		foreach ($user_ids as $v) {
			$id = (int) $v;
			if ($id <= 0) { continue; }
			$u = get_userdata($id);
			if (!$u) { continue; }
			$has_cb_role = array_intersect(['cb_member', 'cb_admin', 'cb_super_admin'], (array) $u->roles);
			if (empty($has_cb_role)) { continue; }
			$clean[$id] = $id;
		}
		update_option(self::OPT_ONBOARDING_USER_IDS, array_values($clean), false);
	}

	/**
	 * How long the council review window is open after a candidate moves to Decision.
	 */
	public static function get_council_review_hours(): int {
		$saved = (int) get_option(self::OPT_COUNCIL_REVIEW_HOURS, 48);
		return $saved > 0 ? $saved : 48;
	}

	/**
	 * Default Circle Up meeting time (display string).
	 * Used in the candidate invitation email when no upcoming event row has a time.
	 */
	public static function get_circleup_default_time(): string {
		$saved = trim((string) get_option(self::OPT_CIRCLEUP_DEFAULT_TIME, ''));
		return $saved !== '' ? $saved : '5:30 PM';
	}

	/**
	 * Get the next Circle Up date + time as a display string (e.g. "Friday, June 27 at 5:30 PM").
	 * Tries to find an upcoming approved event whose title contains "circle up", then
	 * falls back to the formula-based 4th Friday of the month with the default time.
	 *
	 * @return array{date_label: string, time_label: string, combined: string}
	 */
	public static function get_next_circleup_display(): array {
		global $wpdb;

		$next_event = null;
		$events_table = $wpdb->prefix . 'cb_events';
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table)) === $events_table) {
			$today = gmdate('Y-m-d');
			$next_event = $wpdb->get_row($wpdb->prepare(
				"SELECT event_date, event_time FROM {$events_table}
				 WHERE status = 'approved'
				   AND event_date >= %s
				   AND title LIKE %s
				 ORDER BY event_date ASC, event_time ASC LIMIT 1",
				$today, '%circle up%'
			));
		}

		if ($next_event && !empty($next_event->event_date)) {
			$ts = strtotime($next_event->event_date . ' ' . ($next_event->event_time ?: '00:00:00'));
			$date_label = date_i18n('l, F j', $ts);
			$time_label = !empty($next_event->event_time)
				? date_i18n('g:i A', $ts)
				: self::get_circleup_default_time();
			return [
				'date_label' => $date_label,
				'time_label' => $time_label,
				'combined'   => $date_label . ' at ' . $time_label,
			];
		}

		// Fallback: use the existing 4th Friday formula from the coverage service.
		$formula_date = '';
		if (class_exists('CBNexus_Recruitment_Coverage_Service')
			&& method_exists('CBNexus_Recruitment_Coverage_Service', 'get_next_circleup_date_public')) {
			$formula_date = CBNexus_Recruitment_Coverage_Service::get_next_circleup_date_public();
		}

		if ($formula_date === '') {
			// Compute inline as last resort: next 4th Friday of the month.
			$now   = new \DateTime('now', new \DateTimeZone('UTC'));
			$year  = (int) $now->format('Y');
			$month = (int) $now->format('n');
			$try   = new \DateTime("fourth Friday of {$year}-{$month}", new \DateTimeZone('UTC'));
			if ($try <= $now) {
				$month++;
				if ($month > 12) { $month = 1; $year++; }
				$try = new \DateTime("fourth Friday of {$year}-{$month}", new \DateTimeZone('UTC'));
			}
			$formula_date = $try->format('Y-m-d');
		}

		$ts = strtotime($formula_date);
		$date_label = $ts ? date_i18n('l, F j', $ts) : 'the next Circle Up';
		$time_label = self::get_circleup_default_time();
		return [
			'date_label' => $date_label,
			'time_label' => $time_label,
			'combined'   => $ts ? ($date_label . ' at ' . $time_label) : $date_label,
		];
	}
}
