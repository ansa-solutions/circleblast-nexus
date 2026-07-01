<?php
/**
 * Migration 028: Add title column to cb_candidates.
 *
 * The candidate → member conversion requires a Job Title (cb_title) when a
 * brand-new WP user is created. Candidates previously had nowhere to store it,
 * so acceptance silently failed for anyone without a pre-existing account.
 * This gives the pipeline a place to capture it up front.
 */

defined('ABSPATH') || exit;

final class CBNexus_Migration_028_Add_Candidate_Title {

	public static function up(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cb_candidates';

		// Idempotent: skip if column already exists.
		$col = $wpdb->get_results( $wpdb->prepare(
			'SHOW COLUMNS FROM `' . $table . '` LIKE %s',
			'title'
		) );
		if ( ! empty( $col ) ) {
			return true;
		}

		$result = $wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `title` VARCHAR(200) DEFAULT NULL AFTER `company`"
		);

		return ( $result !== false );
	}
}
