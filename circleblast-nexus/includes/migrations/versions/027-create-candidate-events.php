<?php
/**
 * Migration: 027 - Create candidate events table
 *
 * Per-candidate timeline of what happened (stage changes, emails sent,
 * council reviews opened, feedback received). Replaces recruiter-targeted
 * emails with an in-UI log on the candidate card.
 */

defined('ABSPATH') || exit;

final class CBNexus_Migration_027_Create_Candidate_Events {

	public static function up(): bool {
		global $wpdb;

		$table   = $wpdb->prefix . 'cb_candidate_events';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			candidate_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(50) NOT NULL,
			message TEXT NOT NULL,
			meta TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_candidate (candidate_id),
			KEY idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		$found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
		return ($found === $table);
	}
}
