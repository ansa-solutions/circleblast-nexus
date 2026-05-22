<?php
/**
 * Portal Admin – Analytics Tab (super-admin)
 *
 * Extracted from class-portal-admin.php for maintainability.
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Admin_Analytics {

	/** Info tooltips for admin overview cards. */
	private static function overview_tooltips(): array {
		return [
			'Active Members'     => 'Total members with active status in the system.',
			'Completed Meetings' => 'Total 1:1 meetings that reached completed or closed status.',
			'Acceptance Rate'    => 'Percentage of auto-generated suggestions that were accepted by members.',
			'High Risk'          => 'Members with engagement score below 20 or inactive for 90+ days.',
		];
	}

	/** Info tooltips for engagement table columns. */
	private static function column_tooltips(): array {
		return [
			'Meetings'  => '1:1 meetings completed or closed',
			'Unique'    => 'Distinct members connected with',
			'CircleUp'  => 'CircleUp group meetings attended',
			'Notes %'   => 'Percentage of meetings with notes submitted',
			'Accept %'  => 'Percentage of meeting suggestions accepted',
			'Score'     => 'Engagement score (0–100): meetings 30pts + unique 24pts + CircleUp 24pts + notes 12pts + accept 10pts',
			'Risk'      => 'High: >90d inactive or score<20. Medium: >45d or score<40. Low: all else.',
		];
	}

	public static function render(): void {
		if (!current_user_can('cbnexus_export_data')) {
			echo '<div class="cbnexus-card"><p>Permission denied.</p></div>';
			return;
		}

		$member_data = CBNexus_Admin_Analytics::compute_member_engagement();
		$overview    = self::compute_overview();
		$export_url  = wp_nonce_url(CBNexus_Portal_Admin::admin_url('analytics', ['cbnexus_portal_export' => 'members']), 'cbnexus_portal_export', '_panonce');
		$tips = self::overview_tooltips();
		$col_tips = self::column_tooltips();
		?>
		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h2>Club Analytics</h2>
				<a href="<?php echo esc_url($export_url); ?>" class="cbnexus-btn">Export CSV</a>
			</div>

			<div class="cbnexus-admin-stats-row" id="cbnexus-analytics-filters">
				<?php $i = 0; foreach ($overview as $label => $val) : $filter_key = self::label_to_filter_key($label); ?>
					<div class="cbnexus-admin-stat cbnexus-admin-stat--filterable" data-filter="<?php echo esc_attr($filter_key); ?>" tabindex="0" role="button" aria-label="Filter by <?php echo esc_attr($label); ?>">
						<button type="button" class="cbnexus-info-btn" aria-label="Info" data-tooltip="<?php echo esc_attr($tips[$label] ?? ''); ?>">ⓘ</button>
						<div class="cbnexus-admin-stat-value"><?php echo esc_html($val); ?></div>
						<div class="cbnexus-admin-stat-label"><?php echo esc_html($label); ?></div>
					</div>
				<?php $i++; endforeach; ?>
			</div>
			<div id="cbnexus-filter-bar" class="cbnexus-filter-bar" style="display:none;">
				<span id="cbnexus-filter-label" class="cbnexus-filter-label"></span>
				<button type="button" id="cbnexus-filter-reset" class="cbnexus-btn cbnexus-btn-sm cbnexus-btn-outline">Show All</button>
			</div>
		</div>

		<?php self::render_coverage_card(); ?>

		<div class="cbnexus-card">
			<h3>Member Engagement</h3>
			<div class="cbnexus-admin-table-wrap">
				<table class="cbnexus-admin-table" id="cbnexus-engagement-table">
					<thead><tr>
						<th>Member</th>
						<?php foreach ($col_tips as $col => $tip) : ?>
							<th><?php echo esc_html($col); ?> <button type="button" class="cbnexus-info-btn cbnexus-info-btn--th" aria-label="Info" data-tooltip="<?php echo esc_attr($tip); ?>">ⓘ</button></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ($member_data as $m) :
						$risk_classes = ['high' => 'red', 'medium' => 'gold', 'low' => 'green'];
						$rc = $risk_classes[$m['risk']] ?? 'muted';
					?>
						<tr data-meetings="<?php echo esc_attr($m['meetings']); ?>"
							data-risk="<?php echo esc_attr($m['risk']); ?>"
							data-score="<?php echo esc_attr($m['score']); ?>">
							<td>
								<strong><?php echo esc_html($m['name']); ?></strong>
								<div class="cbnexus-admin-meta"><?php echo esc_html($m['company']); ?></div>
							</td>
							<td><?php echo esc_html($m['meetings']); ?></td>
							<td><?php echo esc_html($m['unique_met']); ?></td>
							<td><?php echo esc_html($m['circleup']); ?></td>
							<td><?php echo esc_html($m['notes_pct']); ?>%</td>
							<td><?php echo esc_html($m['accept_pct']); ?>%</td>
							<td><strong><?php echo esc_html($m['score']); ?></strong>/100</td>
							<td><span class="cbnexus-status-pill cbnexus-status-<?php echo esc_attr($rc); ?>"><?php echo esc_html(ucfirst($m['risk'])); ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		(function(){
			const cards = document.querySelectorAll('.cbnexus-admin-stat--filterable');
			const table = document.getElementById('cbnexus-engagement-table');
			const filterBar = document.getElementById('cbnexus-filter-bar');
			const filterLabel = document.getElementById('cbnexus-filter-label');
			const resetBtn = document.getElementById('cbnexus-filter-reset');
			if (!table || !filterBar) return;
			const rows = table.querySelectorAll('tbody tr');
			let activeFilter = null;

			function applyFilter(key) {
				activeFilter = key;
				cards.forEach(c => c.classList.toggle('cbnexus-admin-stat--active', c.dataset.filter === key));
				filterBar.style.display = 'flex';

				if (key === 'high_risk') {
					filterLabel.textContent = 'Showing: High Risk members';
					rows.forEach(r => r.style.display = r.dataset.risk === 'high' ? '' : 'none');
				} else if (key === 'completed_meetings') {
					filterLabel.textContent = 'Showing: Members with completed meetings';
					rows.forEach(r => r.style.display = parseInt(r.dataset.meetings) > 0 ? '' : 'none');
				} else if (key === 'acceptance_rate') {
					filterLabel.textContent = 'Sorted by acceptance rate';
					rows.forEach(r => r.style.display = '');
				} else if (key === 'active_members') {
					filterLabel.textContent = 'Showing: All active members';
					rows.forEach(r => r.style.display = '');
				}
			}

			function clearFilter() {
				activeFilter = null;
				cards.forEach(c => c.classList.remove('cbnexus-admin-stat--active'));
				filterBar.style.display = 'none';
				rows.forEach(r => r.style.display = '');
			}

			cards.forEach(c => c.addEventListener('click', function(e) {
				if (e.target.closest('.cbnexus-info-btn')) return; // Don't filter on info click
				const key = this.dataset.filter;
				if (activeFilter === key) { clearFilter(); } else { applyFilter(key); }
			}));

			resetBtn.addEventListener('click', clearFilter);
		})();
		</script>
		<?php
	}

	// ─── Recruitment Coverage Card ────────────────────────────────────

	private static function render_coverage_card(): void {
		if (!class_exists('CBNexus_Recruitment_Coverage_Service')) {
			return;
		}

		$summary = CBNexus_Recruitment_Coverage_Service::get_summary();
		if ($summary['total'] === 0) {
			return;
		}

		$all = CBNexus_Recruitment_Coverage_Service::get_full_coverage();
		$pipeline = CBNexus_Recruitment_Coverage_Service::get_pipeline_summary();
		$portal_url = CBNexus_Portal_Router::get_portal_url();
		$manage_url = add_query_arg(['section' => 'manage', 'admin_tab' => 'recruitment'], $portal_url);

		$status_icons = ['covered' => '🟢', 'partial' => '🟡', 'gap' => '🔴'];
		?>
		<div class="cbnexus-card">
			<div class="cbnexus-admin-header-row">
				<h3 style="margin:0;">🎯 Recruitment Coverage</h3>
				<a href="<?php echo esc_url($manage_url); ?>" class="cbnexus-btn cbnexus-btn-sm">Manage Recruitment</a>
			</div>

			<!-- Summary stats row -->
			<div class="cbnexus-admin-stats-row" style="margin-bottom:16px;">
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value"><?php echo esc_html((int) ($summary['capacity_filled'] ?? 0)); ?>/<?php echo esc_html((int) ($summary['capacity_total'] ?? 25)); ?></div>
					<div class="cbnexus-admin-stat-label">Seats Filled</div>
				</div>
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value" style="color:#059669;"><?php echo esc_html($summary['covered']); ?></div>
					<div class="cbnexus-admin-stat-label">Filled</div>
				</div>
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value" style="color:#d97706;"><?php echo esc_html($summary['partial']); ?></div>
					<div class="cbnexus-admin-stat-label">Partial</div>
				</div>
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value" style="color:#dc2626;"><?php echo esc_html($summary['gaps']); ?></div>
					<div class="cbnexus-admin-stat-label">Open</div>
				</div>
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value"><?php echo esc_html($summary['total']); ?></div>
					<div class="cbnexus-admin-stat-label">Total</div>
				</div>
				<div class="cbnexus-admin-stat">
					<div class="cbnexus-admin-stat-value" style="color:#5b2d6e;"><?php echo esc_html($pipeline['total']); ?></div>
					<div class="cbnexus-admin-stat-label">In Pipeline</div>
				</div>
			</div>

			<?php if ($pipeline['total'] > 0) : ?>
			<!-- Pipeline funnel -->
			<div style="margin-bottom:16px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px;">
				<div style="font-size:13px;font-weight:600;margin-bottom:10px;">📋 Recruit Pipeline <span style="font-weight:400;color:#6c757d;">(<?php echo esc_html($pipeline['total']); ?> active)</span></div>
				<div class="cbnexus-admin-stats-row">
					<?php
					$stages = ['referral' => 'Referral', 'contacted' => 'Contacted', 'invited' => 'Invited', 'visited' => 'Visited', 'decision' => 'Decision'];
					$colors = ['#8b7a94', '#a78bba', '#7c5b99', '#5b2d6e', '#3d1a4a'];
					$ci = 0;
					foreach ($stages as $key => $label) : ?>
						<div class="cbnexus-admin-stat">
							<div class="cbnexus-admin-stat-value" style="color:<?php echo esc_attr($colors[$ci]); ?>;"><?php echo esc_html($pipeline[$key]); ?></div>
							<div class="cbnexus-admin-stat-label"><?php echo esc_html($label); ?></div>
						</div>
					<?php $ci++; endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<!-- Category table -->
			<div class="cbnexus-admin-table-wrap">
				<table class="cbnexus-admin-table">
					<thead><tr>
						<th style="width:30px;">Status</th>
						<th>Category</th>
						<th>Industry</th>
						<th>Priority</th>
						<th>Filled By</th>
						<th>Pipeline</th>
					</tr></thead>
					<tbody>
					<?php foreach ($all as $cat) :
						$status = $cat->coverage_status ?? 'gap';
						$icon   = $status_icons[$status] ?? '🔴';
						$p_cls  = ['high' => 'red', 'medium' => 'gold', 'low' => 'green'];
					?>
						<tr>
							<td style="text-align:center;"><?php echo $icon; ?></td>
							<td>
								<strong><?php echo esc_html($cat->title); ?></strong>
								<?php if ($cat->description) : ?>
									<div class="cbnexus-admin-meta"><?php echo esc_html(wp_trim_words($cat->description, 10)); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html($cat->industry ?: '—'); ?></td>
							<td><span class="cbnexus-status-pill cbnexus-status-<?php echo esc_attr($p_cls[$cat->priority] ?? 'muted'); ?>"><?php echo esc_html(ucfirst($cat->priority)); ?></span></td>
							<td>
								<?php if (!empty($cat->members)) :
									foreach ($cat->members as $mem) : ?>
										<span style="display:inline-block;font-size:12px;padding:2px 8px;background:#f3eef6;border-radius:10px;color:#5b2d6e;margin:1px 2px;"><?php echo esc_html($mem['display_name']); ?></span>
									<?php endforeach;
								else : ?>
									<span class="cbnexus-admin-meta">—</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if (!empty($cat->pipeline_candidates)) :
									foreach ($cat->pipeline_candidates as $cand) : ?>
										<span style="display:inline-block;font-size:11px;padding:1px 6px;background:#fef3c7;border-radius:8px;color:#92400e;margin:1px 2px;"><?php echo esc_html($cand->name); ?> <span style="opacity:.6;">(<?php echo esc_html($cand->stage); ?>)</span></span>
									<?php endforeach;
								else : ?>
									<span class="cbnexus-admin-meta">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/** Map label text to a JS-friendly filter key. */
	private static function label_to_filter_key(string $label): string {
		return strtolower(str_replace([' ', '-'], '_', $label));
	}

	private static function compute_overview(): array {
		global $wpdb;
		$members = CBNexus_Member_Repository::get_all_members('active');
		$meetings = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings WHERE status IN ('completed','closed')");
		$suggestions = CBNexus_Suggestion_Generator::get_cycle_stats();
		$accept_rate = $suggestions['total'] > 0 ? round($suggestions['accepted'] / $suggestions['total'] * 100) . '%' : '—';
		$member_data = CBNexus_Admin_Analytics::compute_member_engagement();
		$high_risk = count(array_filter($member_data, fn($m) => $m['risk'] === 'high'));

		return [
			'Active Members'     => count($members),
			'Completed Meetings' => $meetings,
			'Acceptance Rate'    => $accept_rate,
			'High Risk'          => $high_risk,
		];
	}
}