<?php
/**
 * Email Template: Recruitment - Visit Thank You + Yes/No CTA
 *
 * Sent to a candidate when they are moved to the "Visited" stage.
 * Includes a short thank-you and two tokenized buttons so the candidate
 * can record whether they\'re interested without needing to reply.
 */

defined('ABSPATH') || exit;

return [
	'subject' => 'Thanks for Visiting The Circle, {{candidate_first_name}}!',
	'body'    => '
<p style="font-size:16px;color:#333;margin:0 0 16px;">Hi {{candidate_first_name}},</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
Thank you for visiting The Circle! We loved having you at our meeting and hope you enjoyed getting to know the group.</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 20px;">
We\'d like to know whether you see The Circle as a fit for you. Pick one of the buttons below — that\'s it, no form to fill out.</p>
<table role="presentation" cellspacing="0" cellpadding="0" align="center" style="margin:24px auto;">
<tr>
<td style="padding-right:10px;">
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="background-color:#16a34a;border-radius:6px;">
<a href="{{fb_interested}}" style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;">I\'m Interested →</a>
</td></tr></table>
</td>
<td style="padding-left:10px;">
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td style="background-color:#6b7280;border-radius:6px;">
<a href="{{fb_not_interested}}" style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;">Not for Me</a>
</td></tr></table>
</td>
</tr></table>
<p style="font-size:13px;color:#888;line-height:1.5;margin:24px 0 0;text-align:center;">
Your response goes directly to {{followup_name}} — no pressure either way.</p>
<p style="font-size:15px;color:#333;margin:24px 0 0;">— The Circle Team</p>',
];
