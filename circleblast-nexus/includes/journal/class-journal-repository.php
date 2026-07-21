<?php
/**
 * Journal Repository
 *
 * Data access layer for the cb_member_journal table.
 * Members can log wins, insights, referrals given/received, and
 * actions independently of any meeting workflow.
 */

defined('ABSPATH') || exit;

final class CBNexus_Journal_Repository {

	/** Valid entry types. */
	const TYPES = ['win', 'ask', 'insight', 'referral_given', 'referral_received', 'action'];

	/** Valid visibility values. */
	const VISIBILITY = ['private', 'members'];

	// ─── Write ─────────────────────────────────────────────────────────

	/**
	 * Insert a new journal entry.
	 *
	 * @param int    $member_id  Author user ID.
	 * @param array  $data       Entry data: entry_type, content, context, entry_date, visibility.
	 * @return int|false New entry ID or false.
	 */
	public static function create(int $member_id, array $data) {
		global $wpdb;

		$type       = in_array($data['entry_type'] ?? '', self::TYPES, true) ? $data['entry_type'] : 'win';
		$visibility = in_array($data['visibility'] ?? '', self::VISIBILITY, true) ? $data['visibility'] : 'private';
		$date       = !empty($data['entry_date']) ? sanitize_text_field($data['entry_date']) : gmdate('Y-m-d');

		$result = $wpdb->insert(
			$wpdb->prefix . 'cb_member_journal',
			[
				'member_id'  => $member_id,
				'entry_type' => $type,
				'content'    => sanitize_textarea_field($data['content'] ?? ''),
				'context'    => isset($data['context']) && $data['context'] !== '' ? sanitize_textarea_field($data['context']) : null,
				'entry_date' => $date,
				'visibility' => $visibility,
				'created_at' => gmdate('Y-m-d H:i:s'),
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s']
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Delete a journal entry. Only the owning member may delete.
	 *
	 * @param int $entry_id  Entry ID.
	 * @param int $member_id Member user ID (ownership guard).
	 * @return bool
	 */
	public static function delete(int $entry_id, int $member_id): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'cb_member_journal',
			['id' => $entry_id, 'member_id' => $member_id],
			['%d', '%d']
		);
	}

	// ─── Read ──────────────────────────────────────────────────────────

	/**
	 * Get a single entry by ID.
	 *
	 * @param int $entry_id Entry ID.
	 * @return object|null
	 */
	public static function get(int $entry_id): ?object {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cb_member_journal WHERE id = %d",
			$entry_id
		)) ?: null;
	}

	/**
	 * Get journal entries for a member with optional type filter.
	 *
	 * @param int    $member_id Member user ID.
	 * @param string $type      Optional type filter (empty = all).
	 * @param int    $limit     Max results.
	 * @return array
	 */
	public static function get_for_member(int $member_id, string $type = '', int $limit = 50): array {
		global $wpdb;

		if ($type !== '' && in_array($type, self::TYPES, true)) {
			return $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cb_member_journal
				 WHERE member_id = %d AND entry_type = %s
				 ORDER BY entry_date DESC, id DESC LIMIT %d",
				$member_id, $type, $limit
			)) ?: [];
		}

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cb_member_journal
			 WHERE member_id = %d
			 ORDER BY entry_date DESC, id DESC LIMIT %d",
			$member_id, $limit
		)) ?: [];
	}

	/**
	 * Count entries per type for a member.
	 *
	 * @param int $member_id Member user ID.
	 * @return array<string,int> e.g. ['win' => 5, 'insight' => 3, ...]
	 */
	public static function count_by_type(int $member_id): array {
		global $wpdb;

		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT entry_type, COUNT(*) AS cnt
			 FROM {$wpdb->prefix}cb_member_journal
			 WHERE member_id = %d
			 GROUP BY entry_type",
			$member_id
		)) ?: [];

		$counts = array_fill_keys(self::TYPES, 0);
		foreach ($rows as $row) {
			$counts[$row->entry_type] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * Count total entries for a member.
	 *
	 * @param int $member_id Member user ID.
	 * @return int
	 */
	public static function count_total(int $member_id): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cb_member_journal WHERE member_id = %d",
			$member_id
		));
	}

	/**
	 * Get recent entries for dashboard summary (most recent N across all types).
	 *
	 * @param int $member_id Member user ID.
	 * @param int $limit     Number of entries.
	 * @return array
	 */
	public static function get_recent(int $member_id, int $limit = 5): array {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cb_member_journal
			 WHERE member_id = %d
			 ORDER BY entry_date DESC, id DESC LIMIT %d",
			$member_id, $limit
		)) ?: [];
	}

	/**
	 * Get member-visible (shared) entries of given types since a date.
	 * Used to build the club-wide "Asks & Wins" board for the monthly meeting.
	 *
	 * @param string[] $types      Entry types to include (filtered against TYPES).
	 * @param string   $since_date Inclusive lower bound (Y-m-d) on entry_date.
	 * @param int      $limit      Max results.
	 * @return array Rows with an extra `display_name` column from the author.
	 */
	public static function get_shared_since(array $types, string $since_date, int $limit = 100): array {
		global $wpdb;

		$types = array_values(array_intersect($types, self::TYPES));
		if (empty($types)) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($types), '%s'));
		$sql = "SELECT j.*, u.display_name
			 FROM {$wpdb->prefix}cb_member_journal j
			 LEFT JOIN {$wpdb->users} u ON j.member_id = u.ID
			 WHERE j.visibility = 'members'
			   AND j.entry_type IN ($placeholders)
			   AND j.entry_date >= %s
			 ORDER BY j.entry_date DESC, j.id DESC
			 LIMIT %d";

		$params = array_merge($types, [$since_date, $limit]);
		return $wpdb->get_results($wpdb->prepare($sql, $params)) ?: [];
	}
}
