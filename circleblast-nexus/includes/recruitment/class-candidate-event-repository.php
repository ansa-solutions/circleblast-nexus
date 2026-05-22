<?php
/**
 * Candidate Event Repository
 *
 * Per-candidate event log: stage changes, emails sent, council reviews,
 * feedback received. Surfaces in the recruitment UI as a timeline.
 */

defined('ABSPATH') || exit;

final class CBNexus_Candidate_Event_Repository {

	const TABLE = 'cb_candidate_events';

	/**
	 * Log an event for a candidate.
	 *
	 * @param int    $candidate_id
	 * @param string $event_type   short slug (e.g. 'stage_change', 'email_sent', 'feedback_received')
	 * @param string $message      human-readable line shown in the UI
	 * @param array  $meta         optional extra context (stored as JSON)
	 */
	public static function log(int $candidate_id, string $event_type, string $message, array $meta = []): void {
		if ($candidate_id <= 0) { return; }

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return;
		}

		$wpdb->insert($table, [
			'candidate_id' => $candidate_id,
			'event_type'   => substr($event_type, 0, 50),
			'message'      => $message,
			'meta'         => $meta ? wp_json_encode($meta) : null,
			'created_at'   => gmdate('Y-m-d H:i:s'),
		], ['%d', '%s', '%s', '%s', '%s']);
	}

	/**
	 * Get the event timeline for a candidate (most recent first).
	 *
	 * @return array<int, object>
	 */
	public static function get_for_candidate(int $candidate_id, int $limit = 50): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return [];
		}

		return $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} WHERE candidate_id = %d ORDER BY created_at DESC, id DESC LIMIT %d",
			$candidate_id, $limit
		)) ?: [];
	}

	/**
	 * Whether a specific event type has ever been logged for this candidate.
	 * Used to gate "needs action" indicators.
	 */
	public static function has_event(int $candidate_id, string $event_type): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
			return false;
		}

		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM {$table} WHERE candidate_id = %d AND event_type = %s LIMIT 1",
			$candidate_id, $event_type
		));
		return !empty($found);
	}
}
