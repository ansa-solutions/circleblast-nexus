<?php
/**
 * Email Template: Council Review (sent to all active members)
 *
 * Triggered when a candidate moves from Visited → Decision.
 * Members have a defined window to raise an objection before the
 * candidate is invited to join.
 */

defined('ABSPATH') || exit;

return [
	'subject' => 'Council Review: {{candidate_name}} — {{review_hours}}-hour comment window',
	'body'    => '
<p style="font-size:16px;color:#333;margin:0 0 16px;">Hello Circle members,</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
A candidate who visited a recent Circle Up is now under consideration for membership.</p>
<table role="presentation" cellspacing="0" cellpadding="0" style="background:#faf6fc;border:1px solid #e9e3ed;border-radius:8px;margin:16px 0;width:100%;">
<tr><td style="padding:16px 20px;">
<p style="margin:0 0 6px;font-size:13px;color:#5b2d6e;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Candidate</p>
<p style="margin:0 0 10px;font-size:16px;color:#1a1a2e;font-weight:600;">{{candidate_name}}{{candidate_company_line}}</p>
<p style="margin:0 0 4px;font-size:13px;color:#5b2d6e;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Category</p>
<p style="margin:0;font-size:14px;color:#333;">{{candidate_category}}</p>
</td></tr></table>
<p style="font-size:15px;color:#333;line-height:1.6;margin:16px 0;">
If you have a concern about this candidate joining — interpersonal, professional, or otherwise — please reply to this email or contact the Council within <strong>{{review_hours}} hours</strong>. If we don\'t hear back, we\'ll proceed with the invitation.</p>
<p style="font-size:13px;color:#888;line-height:1.5;margin:16px 0 0;">
The Council reads every reply. No need to respond if you have no concerns.</p>
<p style="font-size:15px;color:#333;margin:20px 0 0;">— The Circle Council</p>',
];
