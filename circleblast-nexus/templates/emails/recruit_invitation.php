<?php
/**
 * Email Template: Recruitment Invitation
 *
 * Sent to a candidate when they are moved to the "Invited" stage.
 * Also sent (verbatim) to the referrer so they can follow up directly.
 */

defined('ABSPATH') || exit;

return [
	'subject' => 'You\'re Invited to Visit The Circle, {{candidate_first_name}}!',
	'body'    => '
<p style="font-size:16px;color:#333;margin:0 0 16px;">Hi {{candidate_first_name}},</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
{{referrer_name}} from The Circle has recommended you as someone who would be a great fit for our professional networking group.</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
<strong>The Circle</strong> is a curated community of professionals who meet monthly to build meaningful relationships, exchange referrals, and support each other\'s growth. Our members come from diverse industries and share a commitment to collaboration over competition.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="background:#faf6fc;border:1px solid #e9e3ed;border-radius:8px;margin:16px 0;width:100%;">
<tr><td style="padding:16px 20px;">
<p style="margin:0 0 6px;font-size:13px;color:#5b2d6e;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Next Circle Up</p>
<p style="margin:0;font-size:16px;color:#1a1a2e;font-weight:600;">{{next_circleup}}</p>
</td></tr></table>
<p style="font-size:15px;color:#333;line-height:1.6;margin:16px 0 8px;"><strong>Here\'s what to expect:</strong></p>
<ul style="font-size:15px;color:#333;line-height:1.8;margin:0 0 20px;padding-left:20px;">
<li>A welcoming group of professionals genuinely interested in helping each other</li>
<li>Structured but relaxed format focused on relationship-building</li>
<li>No pressure — come see what we\'re about</li>
</ul>
{{invitation_notes_block}}
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
If you have any questions beforehand, feel free to reach out to {{referrer_name}} or reply to this email.</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 8px;">We look forward to meeting you!</p>
<p style="font-size:15px;color:#333;margin:0;">— The Circle Team</p>',
];
