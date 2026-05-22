<?php
/**
 * Email Template: Candidate Declined (Closure)
 *
 * Sent to a candidate when they are moved to the Declined stage,
 * so the referrer doesn\'t have to deliver the news personally.
 */

defined('ABSPATH') || exit;

return [
	'subject' => 'Thank You for Visiting The Circle, {{candidate_first_name}}',
	'body'    => '
<p style="font-size:16px;color:#333;margin:0 0 16px;">Hi {{candidate_first_name}},</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
Thank you again for taking the time to visit The Circle and learn about our community. We genuinely appreciated meeting you.</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
After careful consideration, we\'ve decided not to move forward with membership at this time. This isn\'t a reflection of your value as a professional — it simply means we don\'t see the right fit for the group right now.</p>
<p style="font-size:15px;color:#333;line-height:1.6;margin:0 0 16px;">
We wish you continued success and hope our paths cross again.</p>
<p style="font-size:15px;color:#333;margin:0;">— The Circle Team</p>',
];
