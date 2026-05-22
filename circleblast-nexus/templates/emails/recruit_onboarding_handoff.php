<?php
/**
 * Email Template: Onboarding Handoff (sent at Accepted stage)
 *
 * Notifies the onboarding lead that a candidate has been accepted and is
 * ready for the next step (collect dues, schedule orientation, etc.).
 */

defined('ABSPATH') || exit;

return [
	'subject' => 'New Member Accepted — Please Onboard {{candidate_name}}',
	'body'    => '
<p style="font-size:16px;color:#333;margin:0 0 16px;">Hi there,</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
<strong>{{candidate_name}}</strong>{{candidate_company_line}} has been accepted into The Circle and is ready for onboarding.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin:16px 0;width:100%;">
<tr><td style="padding:16px 20px;">
<p style="margin:0 0 6px;font-size:13px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Contact</p>
<p style="margin:0 0 4px;font-size:14px;color:#1a1a2e;">{{candidate_email}}</p>
<p style="margin:8px 0 6px;font-size:13px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Referred By</p>
<p style="margin:0;font-size:14px;color:#1a1a2e;">{{referrer_label}}</p>
</td></tr></table>
<p style="font-size:15px;color:#333;line-height:1.6;margin:16px 0;">
Suggested next steps:</p>
<ul style="font-size:15px;color:#333;line-height:1.8;margin:0 0 16px;padding-left:20px;">
<li>Collect the membership check or arrange payment</li>
<li>Schedule a brief orientation / welcome call</li>
<li>Confirm their first Circle Up meeting attendance</li>
</ul>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
Their member account has already been created and a welcome email is on its way to them.</p>
<p style="font-size:15px;color:#333;margin:0;">— The Circle Recruitment System</p>',
];
