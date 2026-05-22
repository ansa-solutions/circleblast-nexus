<?php
/**
 * Portal Club Dashboard
 *
 * Club-wide analytics, tips & focus items, and presentation mode.
 *
 * Enhancements over original:
 *   - Trend arrows/deltas on stat cards using snapshot data
 *   - "This Month" activity section (meetings completed, suggestions, notes)
 *   - "Tips & Focus" section replacing Topic Cloud (data-driven, club-wide)
 *   - Mini sparklines for key metrics (inline SVG)
 *   - Enhanced Presentation mode: keyboard nav, recruitment coverage,
 *     new member/visitor spotlights, longer win text, progress dots
 */

defined('ABSPATH') || exit;

final class CBNexus_Portal_Club {

	public static function init(): void {
		add_action('cbnexus_analytics_snapshot', [__CLASS__, 'take_snapshot']);
	}

	private static function stat_tooltips(): array {
		return [
			'members'   => 'Total active members in the group.',
			'meetings'  => 'Total 1:1 meetings completed across all members.',
			'connected' => 'Percentage of all possible member pairs who have actually met. Higher = stronger network.',
			'new'       => 'Members who joined in the last 90 days.',
			'circleups' => 'Published CircleUp group meeting summaries.',
			'wins'      => 'Total wins shared across all CircleUp meetings.',
		];
	}

	// ─── Main Render ──────────────────────────────────────────────

	public static function render(array $profile): void {
		$present = isset($_GET['present']) && $_GET['present'] === '1';
		$portal_url = CBNexus_Portal_Router::get_portal_url();

		if ($present) {
			self::render_presentation($portal_url);
			return;
		}

		$stats       = self::compute_club_stats();
		$top         = self::get_top_connectors(5);
		$wins        = self::get_recent_wins(6);
		$present_url = add_query_arg(['section' => 'club', 'present' => '1'], $portal_url);
		$tips_data   = self::stat_tooltips();
		$stat_trends = CBNexus_Club_Tips_Service::get_stat_trends();
		$month       = CBNexus_Club_Tips_Service::get_this_month_activity();
		$club_tips   = CBNexus_Club_Tips_Service::get_club_tips(3);
		$sparklines  = [
			'meetings_total'  => CBNexus_Club_Tips_Service::get_sparkline_data('meetings_total', 6),
			'total_members'   => CBNexus_Club_Tips_Service::get_sparkline_data('total_members', 6),
			'network_density' => CBNexus_Club_Tips_Service::get_sparkline_data('network_density', 6),
		];
		?>
		<div class="cbnexus-club-dash" id="cbnexus-club">
			<div class="cbnexus-club-header">
				<h2><?php esc_html_e('Club Overview', 'circleblast-nexus'); ?></h2>
				<a href="<?php echo esc_url($present_url); ?>" class="cbnexus-btn cbnexus-btn-gold cbnexus-btn-sm" target="_blank">🖥 <?php esc_html_e('Present', 'circleblast-nexus'); ?></a>
			</div>

			<!-- Stat Cards with Trends & Sparklines -->
			<div class="cbnexus-quick-stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
				<?php
				$stat_cards = [
					['key' => 'members',   'value' => $stats['total_members'],          'label' => 'Members',      'accent' => 'cbnexus-stat-card--accent', 'trend_key' => 'total_members',   'spark' => 'total_members'],
					['key' => 'meetings',  'value' => $stats['meetings_total'],         'label' => '1:1 Meetings', 'accent' => '',                          'trend_key' => 'meetings_total',  'spark' => 'meetings_total'],
					['key' => 'connected', 'value' => $stats['network_density'] . '%',  'label' => 'Connected',    'accent' => 'cbnexus-stat-card--green',  'trend_key' => 'network_density', 'spark' => 'network_density'],
					['key' => 'new',       'value' => $stats['new_members'],            'label' => 'New (90d)',    'accent' => '',                          'trend_key' => 'new_members',     'spark' => ''],
					['key' => 'circleups', 'value' => $stats['circleup_count'],         'label' => 'CircleUps',    'accent' => '',                          'trend_key' => 'circleup_count',  'spark' => ''],
					['key' => 'wins',      'value' => $stats['wins_total'],             'label' => 'Wins',         'accent' => 'cbnexus-stat-card--gold',   'trend_key' => 'wins_total',      'spark' => ''],
				];
				foreach ($stat_cards as $card) :
					$trend = $stat_trends[$card['trend_key']] ?? null;
					$spark = $card['spark'] ? ($sparklines[$card['spark']] ?? []) : [];
				?>
					<div class="cbnexus-stat-card <?php echo esc_attr($card['accent']); ?>">
						<button type="button" class="cbnexus-info-btn" aria-label="Info" data-tooltip="<?php echo esc_attr($tips_data[$card['key']]); ?>">ⓘ</button>
						<span class="cbnexus-stat-value"><?php echo esc_html($card['value']); ?></span>
						<span class="cbnexus-stat-label"><?php echo esc_html($card['label']); ?></span>
						<?php if ($trend) : ?>
							<span class="cbnexus-trend <?php echo esc_attr($trend->css_class); ?>"><?php echo esc_html($trend->arrow . ' ' . $trend->label); ?></span>
						<?php endif; ?>
						<?php if (count($spark) >= 2) : ?>
							<div class="cbnexus-sparkline" aria-hidden="true"><?php echo self::render_sparkline_svg($spark); ?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- This Month's Activity -->
			<div class="cbnexus-card cbnexus-month-activity">
				<h3>📅 <?php echo esc_html(sprintf(__('%s Activity', 'circleblast-nexus'), date_i18n('F'))); ?></h3>
				<div class="cbnexus-month-grid">
					<div class="cbnexus-month-stat">
						<span class="cbnexus-month-num"><?php echo esc_html($month['meetings_completed']); ?></span>
						<span class="cbnexus-month-label"><?php esc_html_e('Meetings completed', 'circleblast-nexus'); ?></span>
					</div>
					<div class="cbnexus-month-stat">
						<span class="cbnexus-month-num"><?php echo esc_html($month['suggestions_sent']); ?></span>
						<span class="cbnexus-month-label"><?php esc_html_e('Suggestions sent', 'circleblast-nexus'); ?></span>
					</div>
					<div class="cbnexus-month-stat">
						<span class="cbnexus-month-num"><?php echo esc_html($month['notes_submitted']); ?></span>
						<span class="cbnexus-month-label"><?php esc_html_e('Notes submitted', 'circleblast-nexus'); ?></span>
					</div>
					<div class="cbnexus-month-stat">
						<span class="cbnexus-month-num"><?php echo esc_html($month['notes_rate']); ?>%</span>
						<span class="cbnexus-month-label"><?php esc_html_e('Notes completion', 'circleblast-nexus'); ?></span>
					</div>
				</div>
			</div>

			<div class="cbnexus-dash-cols">
				<!-- Top Connectors -->
				<div class="cbnexus-card">
					<h3>🌟 <?php esc_html_e('Top Connectors', 'circleblast-nexus'); ?></h3>
					<?php if (empty($top)) : ?><p class="cbnexus-text-muted"><?php esc_html_e('No meeting data yet.', 'circleblast-nexus'); ?></p>
					<?php else : $rank = 0; foreach ($top as $t) : $rank++; ?>
						<div class="cbnexus-row">
							<span class="cbnexus-club-rank <?php echo $rank <= 3 ? 'cbnexus-club-rank--top' : 'cbnexus-club-rank--other'; ?>"><?php echo esc_html($rank); ?></span>
							<strong style="flex:1;"><?php echo esc_html($t->display_name); ?></strong>
							<span class="cbnexus-text-muted"><?php echo esc_html($t->meeting_count); ?></span>
						</div>
					<?php endforeach; endif; ?>
				</div>

				<!-- Tips & Focus (replaces Topic Cloud) -->
				<div class="cbnexus-card">
					<h3>💡 <?php esc_html_e('Tips & Focus', 'circleblast-nexus'); ?></h3>
					<?php if (empty($club_tips)) : ?>
						<p class="cbnexus-text-muted"><?php esc_html_e('Everything looks great — keep up the momentum!', 'circleblast-nexus'); ?></p>
					<?php else : foreach ($club_tips as $tip) : ?>
						<div class="cbnexus-tip-row">
							<span class="cbnexus-tip-icon"><?php echo esc_html($tip->icon); ?></span>
							<div class="cbnexus-tip-body">
								<p><?php echo esc_html($tip->text); ?></p>
								<?php if ($tip->cta_label && $tip->cta_url) : ?>
									<a href="<?php echo esc_url($tip->cta_url); ?>" class="cbnexus-link" style="font-size:12px;"><?php echo esc_html($tip->cta_label); ?> →</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</div>

			<?php self::render_coverage_card(); ?>

			<!-- Recent Wins -->
			<?php if (!empty($wins)) : ?>
			<div class="cbnexus-card">
				<h3>🏆 <?php esc_html_e('Recent Wins', 'circleblast-nexus'); ?></h3>
				<div class="cbnexus-wins-grid">
					<?php foreach ($wins as $w) : ?>
						<div class="cbnexus-win-card">
							<span class="cbnexus-win-icon">🏆</span>
							<p><?php echo esc_html(wp_trim_words($w->content, 25)); ?></p>
							<?php if ($w->speaker_name) : ?><span class="cbnexus-text-muted">— <?php echo esc_html($w->speaker_name); ?></span><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Recruitment Coverage Card ────────────────────────────────
	// (Kept intact from previous implementation)

	private static function render_coverage_card(): void {
		if (!class_exists('CBNexus_Recruitment_Coverage_Service')) { return; }
		$summary = CBNexus_Recruitment_Coverage_Service::get_summary();
		if ($summary['total'] === 0) { return; }

		$all      = CBNexus_Recruitment_Coverage_Service::get_full_coverage();
		$pipeline = CBNexus_Recruitment_Coverage_Service::get_pipeline_summary();
		$is_admin = current_user_can('cbnexus_manage_members');
		$portal_url = CBNexus_Portal_Router::get_portal_url();
		$expanded = isset($_GET['coverage']) && $_GET['coverage'] === 'expanded';
		$focus_meta = CBNexus_Recruitment_Coverage_Service::get_focus_meta();
		$focus_ids  = $focus_meta['category_ids'] ?? [];
		$has_focus  = CBNexus_Recruitment_Coverage_Service::has_active_focus();
		$status_icons  = ['covered' => '🟢', 'partial' => '🟡', 'gap' => '🔴'];
		$status_labels = ['covered' => 'Covered', 'partial' => 'Partial', 'gap' => 'Open'];
		?>
		<div class="cbnexus-card" id="coverage-scorecard">
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
				<h3 style="margin:0;">🎯 <?php esc_html_e('Recruitment Coverage', 'circleblast-nexus'); ?></h3>
				<?php if ($is_admin) : ?>
					<a href="<?php echo esc_url(add_query_arg(['section' => 'manage', 'admin_tab' => 'recruitment'], $portal_url)); ?>" class="cbnexus-link" style="font-size:13px;"><?php esc_html_e('Manage →', 'circleblast-nexus'); ?></a>
				<?php endif; ?>
			</div>
			<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:16px;padding:12px 16px;background:var(--cb-bg-deep,#f8f6fa);border-radius:8px;">
				<div style="flex:1;min-width:120px;">
					<div style="font-size:28px;font-weight:800;color:var(--cb-text);"><?php echo esc_html((int) ($summary['capacity_filled'] ?? 0)); ?> <span style="font-size:18px;color:var(--cb-text-sec);font-weight:600;">of <?php echo esc_html((int) ($summary['capacity_total'] ?? 25)); ?></span></div>
					<div style="font-size:12px;color:var(--cb-text-sec);"><?php esc_html_e('Seats Filled', 'circleblast-nexus'); ?></div>
				</div>
				<div style="display:flex;gap:14px;">
					<div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:#059669;"><?php echo esc_html($summary['covered']); ?></div><div style="font-size:11px;color:var(--cb-text-ter);">Filled</div></div>
					<?php if ($summary['partial'] > 0) : ?><div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:#d97706;"><?php echo esc_html($summary['partial']); ?></div><div style="font-size:11px;color:var(--cb-text-ter);">Partial</div></div><?php endif; ?>
					<div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:#dc2626;"><?php echo esc_html($summary['gaps']); ?></div><div style="font-size:11px;color:var(--cb-text-ter);">Open</div></div>
					<div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:#5b2d6e;"><?php echo esc_html($pipeline['total']); ?></div><div style="font-size:11px;color:var(--cb-text-ter);">In Pipeline</div></div>
				</div>
			</div>
			<?php if ($pipeline['total'] > 0) :
				$stages = ['referral' => 'Referral', 'contacted' => 'Contacted', 'invited' => 'Invited', 'visited' => 'Visited', 'decision' => 'Decision'];
				$colors = ['#8b7a94', '#a78bba', '#7c5b99', '#5b2d6e', '#3d1a4a'];
			?>
			<div style="margin-bottom:16px;padding:12px 16px;border:1px solid var(--cb-border,#e5e7eb);border-radius:8px;">
				<div style="font-size:13px;font-weight:600;margin-bottom:10px;">📋 <?php esc_html_e('Recruit Pipeline', 'circleblast-nexus'); ?></div>
				<div style="display:flex;gap:8px;flex-wrap:wrap;">
					<?php $ci = 0; foreach ($stages as $key => $label) : ?>
						<div style="flex:1;min-width:60px;text-align:center;padding:8px 4px;background:var(--cb-bg-deep,#f8f6fa);border-radius:6px;">
							<div style="font-size:18px;font-weight:700;color:<?php echo esc_attr($colors[$ci]); ?>;"><?php echo esc_html($pipeline[$key]); ?></div>
							<div style="font-size:10px;color:var(--cb-text-ter);"><?php echo esc_html($label); ?></div>
						</div>
					<?php $ci++; endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
			<?php if ($has_focus && !empty($focus_ids)) : ?>
			<div style="font-size:11px;font-weight:600;color:var(--cb-text-ter,#9ca3af);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">🔄 This Month's Recruitment Focus</div>
			<?php endif; ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(<?php echo $expanded ? '280px' : '220px'; ?>,1fr));gap:8px;">
				<?php foreach ($all as $cat) :
					$status = $cat->coverage_status ?? 'gap'; $icon = $status_icons[$status] ?? '🔴'; $label = $status_labels[$status] ?? 'Open';
					$is_focus = in_array((int) $cat->id, $focus_ids, true);
					$focus_border = ($is_focus && $status !== 'covered') ? 'border:2px solid #5b2d6e;' : 'border:1px solid var(--cb-border,#e5e7eb);';
				?>
					<div style="display:flex;align-items:flex-start;gap:8px;padding:8px 12px;<?php echo $focus_border; ?>border-radius:8px;background:var(--cb-card,#fff);<?php echo $status === 'gap' ? 'border-style:dashed;opacity:0.75;' : ''; ?><?php echo ($is_focus && $status !== 'covered') ? 'opacity:1;background:#faf6fc;' : ''; ?>">
						<span style="font-size:12px;flex-shrink:0;margin-top:2px;"><?php echo $icon; ?></span>
						<div style="flex:1;min-width:0;">
							<div style="font-size:13px;font-weight:600;<?php echo !$expanded ? 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;' : ''; ?>">
								<?php echo esc_html($cat->title); ?>
								<?php if ($is_focus && $status !== 'covered') : ?><span style="font-size:10px;padding:1px 5px;background:#5b2d6e;color:#fff;border-radius:6px;margin-left:4px;vertical-align:middle;">Focus</span><?php endif; ?>
							</div>
							<?php if ($expanded && $cat->description) : ?><div style="font-size:12px;color:var(--cb-text-sec);margin-top:2px;"><?php echo esc_html(wp_trim_words($cat->description, 20)); ?></div><?php endif; ?>
							<?php if ($expanded && $cat->industry) : ?><span style="display:inline-block;font-size:10px;padding:1px 6px;background:#f3eef6;border-radius:8px;color:#5b2d6e;margin-top:3px;"><?php echo esc_html($cat->industry); ?></span><?php endif; ?>
							<?php if ($status !== 'gap' && !empty($cat->members)) : ?><div style="font-size:11px;color:var(--cb-text-ter);margin-top:2px;"><?php echo esc_html(implode(', ', array_column($cat->members, 'display_name'))); ?></div>
							<?php elseif ($status === 'gap') : ?><div style="font-size:11px;color:var(--cb-text-ter);font-style:italic;"><?php echo esc_html($label); ?><?php if ($cat->priority === 'high') : ?> <span style="color:#dc2626;">●</span><?php endif; ?></div><?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if (!$expanded) : ?>
			<div style="margin-top:10px;"><a href="<?php echo esc_url(add_query_arg(['section' => 'club', 'coverage' => 'expanded'], $portal_url)); ?>#coverage-scorecard" class="cbnexus-link" style="font-size:13px;"><?php esc_html_e('Expand all categories →', 'circleblast-nexus'); ?></a></div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Sparkline SVG Helper ─────────────────────────────────────

	private static function render_sparkline_svg(array $data, int $width = 80, int $height = 24): string {
		if (count($data) < 2) { return ''; }
		$values = array_column($data, 'value');
		$min = min($values); $max = max($values); $range = max($max - $min, 1);
		$points = []; $step = $width / (count($values) - 1);
		foreach ($values as $i => $v) {
			$x = round($i * $step, 1);
			$y = round($height - (($v - $min) / $range * ($height - 4)) - 2, 1);
			$points[] = "{$x},{$y}";
		}
		$polyline = implode(' ', $points);
		$color = end($values) >= reset($values) ? 'var(--cb-green,#3d8b4d)' : 'var(--cb-red,#c44040)';
		return '<svg width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '" fill="none" xmlns="http://www.w3.org/2000/svg">'
			. '<polyline points="' . esc_attr($polyline) . '" stroke="' . $color . '" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	}

	// ─── Presentation Mode ────────────────────────────────────────

	private static function render_presentation(string $portal_url): void {
		$stats   = self::compute_club_stats();
		$wins    = self::get_recent_wins(8);
		$top     = self::get_top_connectors(5);
		$month   = CBNexus_Club_Tips_Service::get_this_month_activity();
		$trends  = CBNexus_Club_Tips_Service::get_trends();
		$qr_data = urlencode($portal_url);
		$back_url = add_query_arg('section', 'club', $portal_url);
		$new_members = CBNexus_Club_Tips_Service::get_new_members(5);
		$visitors    = CBNexus_Club_Tips_Service::get_visitors(5);
		$has_cov     = class_exists('CBNexus_Recruitment_Coverage_Service');
		$cov_summary = $has_cov ? CBNexus_Recruitment_Coverage_Service::get_summary() : ['total' => 0];
		$cov_gaps    = $has_cov ? CBNexus_Recruitment_Coverage_Service::get_focus_categories(5) : [];
		$scheme    = CBNexus_Color_Scheme::get_scheme();
		$accent    = $scheme['accent']    ?? '#2b5a94';
		$secondary = $scheme['secondary'] ?? '#f09214';
		$m_delta = $trends['meetings_delta'] ?? 0;
		$mem_delta = $trends['members_delta'] ?? 0;
		$p_colors = ['high' => '#ef4444', 'medium' => '#f59e0b', 'low' => '#10b981'];
		?>
		<style>
			.cbnexus-present-wrap{position:fixed;inset:0;z-index:999999;background:linear-gradient(135deg,<?php echo esc_attr($accent); ?> 0%,<?php echo esc_attr(self::darken_hex($accent,20)); ?> 50%,<?php echo esc_attr(self::darken_hex($accent,35)); ?> 100%);color:#fff;overflow:hidden;font-family:'DM Sans',-apple-system,BlinkMacSystemFont,sans-serif}
			.cbnexus-present-inner{max-width:960px;margin:0 auto;padding:0 32px;height:100vh;position:relative}
			.cbnexus-present-section{position:absolute;inset:0;padding:56px 0 80px;display:flex;flex-direction:column;justify-content:center;opacity:0;visibility:hidden;transition:opacity .4s ease,visibility .4s ease;overflow-y:auto}
			.cbnexus-present-section--active{opacity:1;visibility:visible}
			.cbnexus-present-exit{position:fixed;top:16px;right:20px;z-index:999999;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.8);font-size:14px;padding:8px 18px;border-radius:10px;cursor:pointer;font-family:inherit;text-decoration:none;transition:background .2s}
			.cbnexus-present-exit:hover{background:rgba(255,255,255,.22);color:#fff}
			.cbnexus-present-nav-hint{position:fixed;bottom:20px;right:20px;z-index:999999;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.4);font-size:12px;padding:6px 14px;border-radius:8px;pointer-events:none}
			.cbnexus-present-dots{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);z-index:999999;display:flex;gap:8px}
			.cbnexus-present-dot{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.2);cursor:pointer;transition:all .2s}
			.cbnexus-present-dot--active{background:<?php echo esc_attr($secondary); ?>;transform:scale(1.3)}
			.cbnexus-present-title{text-align:center;font-size:clamp(40px,6vw,64px);font-weight:800;margin:0 0 4px;background:linear-gradient(90deg,<?php echo esc_attr($secondary); ?>,<?php echo esc_attr(self::lighten_hex($secondary,25)); ?>,<?php echo esc_attr($secondary); ?>);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
			.cbnexus-present-date{text-align:center;font-size:20px;color:rgba(255,255,255,.5);margin:0 0 40px}
			.cbnexus-present-kpis{display:flex;justify-content:center;gap:48px;flex-wrap:wrap;margin:0 0 20px}
			.cbnexus-present-kpi{text-align:center}
			.cbnexus-present-kpi-num{display:block;font-size:clamp(40px,5vw,56px);font-weight:800;color:<?php echo esc_attr($secondary); ?>}
			.cbnexus-present-kpi-label{font-size:15px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1px}
			.cbnexus-present-kpi-trend{display:block;font-size:14px;margin-top:4px}
			.cbnexus-present-kpi-trend--up{color:#6ee7b7}
			.cbnexus-present-kpi-trend--down{color:#fca5a5}
			.cbnexus-present-divider{border:0;border-top:1px solid rgba(255,255,255,.1);margin:40px 0}
			.cbnexus-present-sh{font-size:28px;font-weight:700;margin:0 0 20px}
			.cbnexus-present-cols{display:grid;grid-template-columns:1fr 1fr;gap:32px}
			@media(max-width:700px){.cbnexus-present-cols{grid-template-columns:1fr}}
			.cbnexus-present-wgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}
			.cbnexus-present-wcard{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:18px;font-size:17px;line-height:1.5}
			.cbnexus-present-wcard em{color:rgba(255,255,255,.45);font-size:13px}
			.cbnexus-present-ldr{font-size:20px;padding:10px 0;display:flex;align-items:center;gap:10px}
			.cbnexus-present-ldr-rank{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;font-weight:700;font-size:16px;flex-shrink:0}
			.cbnexus-present-ldr-rank--top{background:rgba(255,255,255,.1);color:<?php echo esc_attr($secondary); ?>}
			.cbnexus-present-ldr-rank--other{background:rgba(255,255,255,.04);color:rgba(255,255,255,.45)}
			.cbnexus-present-ldr-count{color:rgba(255,255,255,.4);margin-left:auto}
			.cbnexus-present-person{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:14px 18px;min-width:200px;flex:1}
			.cbnexus-present-person-name{font-size:18px;font-weight:600;margin-bottom:2px}
			.cbnexus-present-person-meta{font-size:14px;color:rgba(255,255,255,.45)}
			.cbnexus-present-gap-card{background:rgba(255,255,255,.06);border:1px dashed rgba(255,255,255,.15);border-radius:10px;padding:12px 16px;flex:1;min-width:180px}
			.cbnexus-present-gap-title{font-size:16px;font-weight:600}
			.cbnexus-present-gap-meta{font-size:12px;color:rgba(255,255,255,.4);margin-top:2px}
			.cbnexus-present-month{display:flex;justify-content:center;gap:32px;flex-wrap:wrap;margin:16px 0 0}
			.cbnexus-present-month-num{display:block;font-size:28px;font-weight:700;color:<?php echo esc_attr($secondary); ?>}
			.cbnexus-present-month-label{font-size:13px;color:rgba(255,255,255,.45)}
			.cbnexus-present-qr-persistent{position:fixed;bottom:16px;left:20px;z-index:999999;display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:8px 14px 8px 8px;cursor:default;transition:background .2s}
			.cbnexus-present-qr-persistent:hover{background:rgba(255,255,255,.14)}
			.cbnexus-present-qr-persistent img{width:56px;height:56px;border-radius:8px}
			.cbnexus-present-qr-persistent-label{color:rgba(255,255,255,.5);font-size:12px;line-height:1.3}
		</style>

		<div class="cbnexus-present-wrap" id="cbnexus-present-wrap">
			<a href="<?php echo esc_url($back_url); ?>" class="cbnexus-present-exit">✕ Exit</a>
			<div class="cbnexus-present-nav-hint" id="cbnexus-nav-hint">← → Navigate · Esc to exit</div>
			<div class="cbnexus-present-dots" id="cbnexus-present-dots"></div>
			<div class="cbnexus-present-qr-persistent">
				<img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?php echo esc_attr($qr_data); ?>" alt="QR Code" width="56" height="56" />
				<span class="cbnexus-present-qr-persistent-label">Scan to open<br/>the portal</span>
			</div>

			<div class="cbnexus-present-inner">

				<!-- Section 0: Title + KPIs + This Month -->
				<div class="cbnexus-present-section" data-section="0">
					<h1 class="cbnexus-present-title">The Circle</h1>
					<p class="cbnexus-present-date"><?php echo esc_html(date_i18n('F Y')); ?> Report</p>
					<div class="cbnexus-present-kpis">
						<div class="cbnexus-present-kpi">
							<span class="cbnexus-present-kpi-num"><?php echo esc_html($stats['total_members']); ?></span>
							<span class="cbnexus-present-kpi-label">Members</span>
							<?php if ($mem_delta != 0) : ?><span class="cbnexus-present-kpi-trend cbnexus-present-kpi-trend--<?php echo $mem_delta > 0 ? 'up' : 'down'; ?>"><?php echo $mem_delta > 0 ? '▲' : '▼'; ?> <?php echo esc_html(abs($mem_delta)); ?> this month</span><?php endif; ?>
						</div>
						<div class="cbnexus-present-kpi">
							<span class="cbnexus-present-kpi-num"><?php echo esc_html($stats['meetings_total']); ?></span>
							<span class="cbnexus-present-kpi-label">Meetings</span>
							<?php if ($m_delta != 0) : ?><span class="cbnexus-present-kpi-trend cbnexus-present-kpi-trend--<?php echo $m_delta > 0 ? 'up' : 'down'; ?>"><?php echo $m_delta > 0 ? '▲' : '▼'; ?> <?php echo esc_html(abs($m_delta)); ?> vs last month</span><?php endif; ?>
						</div>
						<div class="cbnexus-present-kpi"><span class="cbnexus-present-kpi-num"><?php echo esc_html($stats['network_density']); ?>%</span><span class="cbnexus-present-kpi-label">Connected</span></div>
						<div class="cbnexus-present-kpi"><span class="cbnexus-present-kpi-num"><?php echo esc_html($stats['wins_total']); ?></span><span class="cbnexus-present-kpi-label">Wins</span></div>
					</div>
					<div class="cbnexus-present-month">
						<div style="text-align:center"><span class="cbnexus-present-month-num"><?php echo esc_html($month['meetings_completed']); ?></span><span class="cbnexus-present-month-label">Meetings this month</span></div>
						<div style="text-align:center"><span class="cbnexus-present-month-num"><?php echo esc_html($month['notes_submitted']); ?></span><span class="cbnexus-present-month-label">Notes submitted</span></div>
						<div style="text-align:center"><span class="cbnexus-present-month-num"><?php echo esc_html($month['notes_rate']); ?>%</span><span class="cbnexus-present-month-label">Notes completion</span></div>
					</div>
				</div>

				<?php if (!empty($wins)) : ?>
				<!-- Section 1: Wins (with longer text — 40 words) -->
				<div class="cbnexus-present-section" data-section="1">
					<h2 class="cbnexus-present-sh">🏆 Wins</h2>
					<div class="cbnexus-present-wgrid">
						<?php foreach ($wins as $w) : ?>
							<div class="cbnexus-present-wcard"><?php echo esc_html(wp_trim_words($w->content, 40)); ?><?php if ($w->speaker_name) : ?><br/><em>— <?php echo esc_html($w->speaker_name); ?></em><?php endif; ?></div>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<!-- Section 2: Connectors + New Members/Visitors -->
				<div class="cbnexus-present-section" data-section="2">
					<div class="cbnexus-present-cols">
						<?php if (!empty($top)) : ?>
						<div>
							<h2 class="cbnexus-present-sh">🌟 Top Connectors</h2>
							<?php $rank = 0; foreach ($top as $t) : $rank++; ?>
								<div class="cbnexus-present-ldr">
									<span class="cbnexus-present-ldr-rank <?php echo $rank <= 3 ? 'cbnexus-present-ldr-rank--top' : 'cbnexus-present-ldr-rank--other'; ?>"><?php echo $rank; ?></span>
									<span><?php echo esc_html($t->display_name); ?></span>
									<span class="cbnexus-present-ldr-count"><?php echo esc_html($t->meeting_count); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
						<div>
							<?php if (!empty($new_members)) : ?>
								<h2 class="cbnexus-present-sh">👋 New Members</h2>
								<div style="display:flex;flex-wrap:wrap;gap:12px;">
									<?php foreach ($new_members as $nm) : ?>
										<div class="cbnexus-present-person">
											<div class="cbnexus-present-person-name"><?php echo esc_html($nm['display_name']); ?></div>
											<div class="cbnexus-present-person-meta"><?php echo esc_html(trim(($nm['cb_title'] ?? '') . ($nm['cb_company'] ? ' · ' . $nm['cb_company'] : ''))); ?></div>
											<?php if (!empty($nm['cb_looking_for'])) : ?><div class="cbnexus-present-person-meta" style="margin-top:4px;color:rgba(255,255,255,.55);">Looking for: <?php echo esc_html(wp_trim_words($nm['cb_looking_for'], 10)); ?></div><?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if (!empty($visitors)) : ?>
								<h2 class="cbnexus-present-sh" <?php echo !empty($new_members) ? 'style="margin-top:24px;"' : ''; ?>>🔍 Upcoming Visitors</h2>
								<div style="display:flex;flex-wrap:wrap;gap:12px;">
									<?php foreach ($visitors as $v) : ?>
										<div class="cbnexus-present-person">
											<div class="cbnexus-present-person-name"><?php echo esc_html($v->name); ?></div>
											<div class="cbnexus-present-person-meta"><?php echo esc_html(trim(($v->company ?? '') . ($v->industry ? ' · ' . $v->industry : ''))); ?></div>
											<?php if (!empty($v->referrer_name)) : ?><div class="cbnexus-present-person-meta">Referred by <?php echo esc_html($v->referrer_name); ?></div><?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
							<?php if (empty($new_members) && empty($visitors)) : ?>
								<h2 class="cbnexus-present-sh">👥 Members</h2>
								<p style="color:rgba(255,255,255,.5);">No new members or visitors this period.</p>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<?php if ($has_cov && $cov_summary['total'] > 0 && $cov_summary['gaps'] > 0) : ?>
				<!-- Section 3: Recruitment Coverage -->
				<div class="cbnexus-present-section" data-section="3">
					<h2 class="cbnexus-present-sh">🎯 Who We Need</h2>
					<p style="font-size:18px;color:rgba(255,255,255,.55);margin:-12px 0 20px;"><?php echo esc_html((int) ($cov_summary['capacity_filled'] ?? 0)); ?> of <?php echo esc_html((int) ($cov_summary['capacity_total'] ?? 25)); ?> seats · <?php echo esc_html($cov_summary['gaps']); ?> open roles</p>
					<div style="display:flex;flex-wrap:wrap;gap:12px;">
						<?php foreach ($cov_gaps as $gap) :
							$dot_color = $p_colors[$gap->priority] ?? '#f59e0b';
						?>
							<div class="cbnexus-present-gap-card">
								<div class="cbnexus-present-gap-title"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($dot_color); ?>;margin-right:8px;"></span><?php echo esc_html($gap->title); ?></div>
								<?php if ($gap->industry) : ?><div class="cbnexus-present-gap-meta"><?php echo esc_html($gap->industry); ?></div><?php endif; ?>
								<?php if ($gap->description) : ?><div class="cbnexus-present-gap-meta" style="margin-top:4px;"><?php echo esc_html(wp_trim_words($gap->description, 15)); ?></div><?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="font-size:16px;color:rgba(255,255,255,.5);margin-top:20px;">Know someone who fits? Talk to any member or submit a referral in the portal.</p>
				</div>
				<?php endif; ?>

				</div>
			</div>

		<!-- Keyboard Navigation -->
		<script>
		(function(){
			var sections = document.querySelectorAll('.cbnexus-present-section');
			var dotContainer = document.getElementById('cbnexus-present-dots');
			var hint = document.getElementById('cbnexus-nav-hint');
			var current = 0;
			var total = sections.length;

			// Activate first section
			if(sections.length) sections[0].classList.add('cbnexus-present-section--active');

			// Build progress dots
			for(var i=0;i<total;i++){
				var d = document.createElement('span');
				d.className = 'cbnexus-present-dot' + (i===0?' cbnexus-present-dot--active':'');
				d.dataset.idx = i;
				d.addEventListener('click', function(){ goTo(parseInt(this.dataset.idx)); });
				dotContainer.appendChild(d);
			}

			function goTo(idx){
				if(idx<0||idx>=total) return;
				sections[current].classList.remove('cbnexus-present-section--active');
				current = idx;
				sections[current].classList.add('cbnexus-present-section--active');
				sections[current].scrollTop = 0;
				var dots = dotContainer.querySelectorAll('.cbnexus-present-dot');
				dots.forEach(function(d,i){ d.className = 'cbnexus-present-dot'+(i===current?' cbnexus-present-dot--active':''); });
			}

			document.addEventListener('keydown', function(e){
				if(e.key==='ArrowRight'||e.key==='ArrowDown'||e.key===' '){
					e.preventDefault(); goTo(current+1);
				} else if(e.key==='ArrowLeft'||e.key==='ArrowUp'){
					e.preventDefault(); goTo(current-1);
				} else if(e.key==='Escape'){
					var exit = document.querySelector('.cbnexus-present-exit');
					if(exit) exit.click();
				}
			});

			// Hide hint after 5s
			setTimeout(function(){ if(hint) hint.style.opacity='0'; },5000);
		})();
		</script>
		<?php
	}

	// ─── Color Helpers ────────────────────────────────────────────

	private static function darken_hex(string $hex, int $pct): string {
		$hex = ltrim($hex, '#');
		$r = max(0, hexdec(substr($hex, 0, 2)) - (int) round(255 * $pct / 100));
		$g = max(0, hexdec(substr($hex, 2, 2)) - (int) round(255 * $pct / 100));
		$b = max(0, hexdec(substr($hex, 4, 2)) - (int) round(255 * $pct / 100));
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}

	private static function lighten_hex(string $hex, int $pct): string {
		$hex = ltrim($hex, '#');
		$r = min(255, hexdec(substr($hex, 0, 2)) + (int) round(255 * $pct / 100));
		$g = min(255, hexdec(substr($hex, 2, 2)) + (int) round(255 * $pct / 100));
		$b = min(255, hexdec(substr($hex, 4, 2)) + (int) round(255 * $pct / 100));
		return sprintf('#%02x%02x%02x', $r, $g, $b);
	}

	// ─── Data Queries ─────────────────────────────────────────────

	public static function compute_club_stats(): array {
		global $wpdb;
		$members = CBNexus_Member_Repository::get_all_members('active');
		$total   = count($members);
		$new = 0; $cutoff = gmdate('Y-m-d', strtotime('-90 days'));
		foreach ($members as $m) { if (($m['cb_join_date'] ?? '') >= $cutoff) { $new++; } }
		$meetings_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_meetings WHERE status IN ('completed', 'closed')");
		$unique_pairs = (int) $wpdb->get_var("SELECT COUNT(DISTINCT CONCAT(LEAST(member_a_id, member_b_id), ':', GREATEST(member_a_id, member_b_id))) FROM {$wpdb->prefix}cb_meetings WHERE status IN ('completed', 'closed')");
		$possible = $total > 1 ? ($total * ($total - 1)) / 2 : 1;
		$density  = round($unique_pairs / $possible * 100);
		$circleup_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_circleup_meetings WHERE status = 'published'");
		$circleup_wins = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_circleup_items WHERE item_type = 'win' AND status = 'approved'");
		$meeting_wins  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_meeting_notes WHERE wins IS NOT NULL AND wins != '' AND is_private = 0");
		$journal_wins  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_member_journal WHERE entry_type = 'win' AND visibility = 'members'");
		$wins_total = $circleup_wins + $meeting_wins + $journal_wins;
		return [
			'total_members'   => $total,
			'new_members'     => $new,
			'meetings_total'  => $meetings_total,
			'network_density' => $density,
			'circleup_count'  => $circleup_count,
			'wins_total'      => $wins_total,
		];
	}

	private static function get_top_connectors(int $limit): array {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"SELECT u.display_name, sub.meeting_count FROM (
				SELECT user_id, SUM(cnt) as meeting_count FROM (
					SELECT member_a_id as user_id, COUNT(*) as cnt FROM {$wpdb->prefix}cb_meetings WHERE status IN ('completed','closed') GROUP BY member_a_id
					UNION ALL
					SELECT member_b_id as user_id, COUNT(*) as cnt FROM {$wpdb->prefix}cb_meetings WHERE status IN ('completed','closed') GROUP BY member_b_id
				) t GROUP BY user_id
			) sub JOIN {$wpdb->users} u ON sub.user_id = u.ID
			ORDER BY sub.meeting_count DESC LIMIT %d",
			$limit
		)) ?: [];
	}

	private static function get_recent_wins(int $limit): array {
		global $wpdb;
		return $wpdb->get_results($wpdb->prepare(
			"(SELECT i.content, u.display_name as speaker_name, i.created_at
			  FROM {$wpdb->prefix}cb_circleup_items i
			  LEFT JOIN {$wpdb->users} u ON i.speaker_id = u.ID
			  WHERE i.item_type = 'win' AND i.status = 'approved')
			 UNION ALL
			 (SELECT n.wins AS content, u.display_name as speaker_name, n.created_at
			  FROM {$wpdb->prefix}cb_meeting_notes n
			  LEFT JOIN {$wpdb->users} u ON n.author_id = u.ID
			  WHERE n.wins IS NOT NULL AND n.wins != '' AND n.is_private = 0)
			 UNION ALL
			 (SELECT j.content, u.display_name as speaker_name, j.created_at
			  FROM {$wpdb->prefix}cb_member_journal j
			  LEFT JOIN {$wpdb->users} u ON j.member_id = u.ID
			  WHERE j.entry_type = 'win' AND j.visibility = 'members')
			 ORDER BY created_at DESC LIMIT %d", $limit
		)) ?: [];
	}

	// ─── Analytics Snapshot (WP-Cron) ─────────────────────────────

	public static function take_snapshot(): void {
		global $wpdb;
		$date  = gmdate('Y-m-d');
		$table = $wpdb->prefix . 'cb_analytics_snapshots';
		$now   = gmdate('Y-m-d H:i:s');
		$stats = self::compute_club_stats();
		$metrics = [
			'total_members'   => $stats['total_members'],
			'meetings_total'  => $stats['meetings_total'],
			'network_density' => $stats['network_density'],
			'wins_total'      => $stats['wins_total'],
		];
		foreach ($metrics as $key => $val) {
			$wpdb->replace($table, [
				'snapshot_date' => $date, 'scope' => 'club', 'member_id' => 0,
				'metric_key' => $key, 'metric_value' => $val, 'created_at' => $now,
			], ['%s', '%s', '%d', '%s', '%f', '%s']);
		}
	}
}