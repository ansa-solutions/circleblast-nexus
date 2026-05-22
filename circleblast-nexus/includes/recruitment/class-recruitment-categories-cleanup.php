<?php
/**
 * Recruitment Categories Cleanup
 *
 * Identify and delete recruitment categories that are not referenced by
 * any active pipeline candidate or any active member.
 *
 * "Active pipeline" = candidate stages other than accepted/declined.
 * "Active member" = any user whose cb_member_categories meta contains
 * the category id.
 */

defined('ABSPATH') || exit;

final class CBNexus_Recruitment_Categories_Cleanup {

	/**
	 * Stages whose candidates pin a category open.
	 */
	private const ACTIVE_STAGES = ['referral', 'contacted', 'invited', 'visited', 'decision'];

	/**
	 * Return the categories that would be deleted by a cleanup run.
	 *
	 * @return array<int, object> Categories (id, title, …)
	 */
	public static function find_unused(): array {
		global $wpdb;

		$cat_table  = $wpdb->prefix . 'cb_recruitment_categories';
		$cand_table = $wpdb->prefix . 'cb_candidates';

		$categories = $wpdb->get_results("SELECT * FROM {$cat_table} ORDER BY title ASC");
		if (empty($categories)) { return []; }

		$used = self::collect_used_category_ids($cand_table);

		$unused = [];
		foreach ($categories as $cat) {
			if (!isset($used[(int) $cat->id])) {
				$unused[] = $cat;
			}
		}
		return $unused;
	}

	/**
	 * Delete the categories identified as unused. Refuses to delete any
	 * id that is still referenced (defense-in-depth).
	 *
	 * @return array{deleted:int, skipped:int}
	 */
	public static function delete_unused(): array {
		global $wpdb;

		$cand_table = $wpdb->prefix . 'cb_candidates';
		$cat_table  = $wpdb->prefix . 'cb_recruitment_categories';

		$unused = self::find_unused();
		if (empty($unused)) {
			return ['deleted' => 0, 'skipped' => 0];
		}

		// Re-check just before deleting to close any race.
		$used = self::collect_used_category_ids($cand_table);

		$deleted = 0;
		$skipped = 0;
		foreach ($unused as $cat) {
			$id = (int) $cat->id;
			if (isset($used[$id])) {
				$skipped++;
				continue;
			}
			$ok = $wpdb->delete($cat_table, ['id' => $id], ['%d']);
			if ($ok) { $deleted++; }
		}

		if (class_exists('CBNexus_Logger') && $deleted > 0) {
			CBNexus_Logger::info('Recruitment categories cleanup ran.', [
				'deleted' => $deleted,
				'skipped' => $skipped,
			]);
		}

		return ['deleted' => $deleted, 'skipped' => $skipped];
	}

	/**
	 * Build a {category_id => true} set of category ids referenced by
	 * any non-terminal candidate or any member meta entry.
	 *
	 * @return array<int, bool>
	 */
	private static function collect_used_category_ids(string $cand_table): array {
		global $wpdb;

		$placeholders = implode(',', array_fill(0, count(self::ACTIVE_STAGES), '%s'));
		$by_candidate = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT category_id FROM {$cand_table}
			 WHERE category_id IS NOT NULL AND stage IN ({$placeholders})",
			...self::ACTIVE_STAGES
		));

		$by_member_rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->usermeta}
			 WHERE meta_key = 'cb_member_categories' AND meta_value != ''"
		);

		$used = [];
		foreach ($by_candidate as $cid) {
			$id = (int) $cid;
			if ($id > 0) { $used[$id] = true; }
		}
		foreach ($by_member_rows as $raw) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				foreach ($decoded as $v) {
					$id = (int) $v;
					if ($id > 0) { $used[$id] = true; }
				}
			} elseif (is_numeric($raw)) {
				$used[(int) $raw] = true;
			}
		}

		return $used;
	}
}
