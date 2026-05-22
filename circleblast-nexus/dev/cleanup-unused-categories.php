<?php
/**
 * One-off cleanup: delete recruitment categories with zero associations.
 *
 * A category is "unused" if NO active or pipeline candidate references it AND
 * NO member has it in cb_member_categories. Accepted/declined candidates are
 * ignored — they\'re historical and shouldn\'t pin a category open.
 *
 * USAGE (dry run by default — prints what would be deleted, makes no changes):
 *   wp eval-file dev/cleanup-unused-categories.php
 *
 * To actually delete, pass a positional "apply" arg:
 *   wp eval-file dev/cleanup-unused-categories.php apply
 *
 * DELETE THIS FILE AFTER RUNNING.
 */

defined('ABSPATH') || exit;

global $wpdb;

// WP-CLI is required (this script uses WP_CLI for logging).
if (!defined('WP_CLI') || !WP_CLI) {
	echo "This script must be run via WP-CLI (wp eval-file).\n";
	return;
}

// Accept "apply" or "--apply" as a positional arg to eval-file.
$apply = false;
foreach ((array) ($args ?? []) as $arg) {
	if (in_array($arg, ['apply', '--apply'], true)) { $apply = true; }
}

$cat_table   = $wpdb->prefix . 'cb_recruitment_categories';
$cand_table  = $wpdb->prefix . 'cb_candidates';

$categories = $wpdb->get_results("SELECT id, title FROM {$cat_table} ORDER BY id ASC");
if (empty($categories)) {
	WP_CLI::success('No categories exist — nothing to clean.');
	return;
}

WP_CLI::log(sprintf('Found %d categories. Checking usage…', count($categories)));

// Build a set of category IDs referenced by ANY non-terminal candidate.
$active_stages   = ['referral', 'contacted', 'invited', 'visited', 'decision'];
$placeholders    = implode(',', array_fill(0, count($active_stages), '%s'));
$used_by_candidates_raw = $wpdb->get_col($wpdb->prepare(
	"SELECT DISTINCT category_id FROM {$cand_table}
	 WHERE category_id IS NOT NULL AND stage IN ({$placeholders})",
	...$active_stages
));
$used_by_candidates = array_filter(array_map('intval', $used_by_candidates_raw));

// Build a set of category IDs referenced by any member via cb_member_categories.
$member_meta_rows = $wpdb->get_col(
	"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'cb_member_categories' AND meta_value != ''"
);
$used_by_members = [];
foreach ($member_meta_rows as $raw) {
	$decoded = json_decode($raw, true);
	if (is_array($decoded)) {
		foreach ($decoded as $v) {
			$id = (int) $v;
			if ($id > 0) { $used_by_members[$id] = true; }
		}
	} elseif (is_numeric($raw)) {
		$used_by_members[(int) $raw] = true;
	}
}
$used_by_members = array_keys($used_by_members);

$used_set = array_flip(array_unique(array_merge($used_by_candidates, $used_by_members)));

$to_delete = [];
$to_keep   = [];
foreach ($categories as $cat) {
	if (isset($used_set[(int) $cat->id])) {
		$to_keep[] = $cat;
	} else {
		$to_delete[] = $cat;
	}
}

WP_CLI::log('');
WP_CLI::log(sprintf('Keeping %d categories (have active candidates or members).', count($to_keep)));
WP_CLI::log(sprintf('%s %d unused categories:', $apply ? 'Deleting' : 'Would delete', count($to_delete)));

foreach ($to_delete as $cat) {
	WP_CLI::log(sprintf('  - [%d] %s', $cat->id, $cat->title));
}

if (!$apply) {
	WP_CLI::log('');
	WP_CLI::warning('Dry run only. Re-run with "wp eval-file dev/cleanup-unused-categories.php --apply" to actually delete.');
	return;
}

$deleted = 0;
foreach ($to_delete as $cat) {
	$ok = $wpdb->delete($cat_table, ['id' => (int) $cat->id], ['%d']);
	if ($ok) { $deleted++; }
}

WP_CLI::success(sprintf('Deleted %d unused recruitment categories.', $deleted));
