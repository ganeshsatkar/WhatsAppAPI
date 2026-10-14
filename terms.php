<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_layout.php';
legalStart('Terms of Service');
$juris = LEGAL_CITY !== '' ? LEGAL_CITY . ', India' : 'India';
?>
<p>These Terms govern your use of WP API Pro (the “Service”), operated by <?= e(LEGAL_COMPANY) ?>. By creating an account you agree to these Terms, our <a href="/privacy.php">Privacy Policy</a> and our <a href="/refund.php">Refund &amp; Cancellation Policy</a>.</p>

<h2>1. The Service</h2>
<p>WP API Pro is software that lets businesses send and receive WhatsApp messages through Meta’s official WhatsApp Cloud API: campaigns, a shared inbox, chatbots, automation, QR codes and an API. WhatsApp is a trademark of Meta Platforms; we are an independent service and are not owned or endorsed by Meta.</p>

<h2>2. Your account</h2>
<ul>
  <li>You must be 18 or older and use the Service for a genuine business.</li>
  <li>Keep your login details safe. You are responsible for all activity under your account.</li>
  <li>Give accurate information, and keep your email and phone number up to date.</li>
</ul>

<h2>3. Acceptable use — WhatsApp rules apply</h2>
<p>You must follow the <a href="https://business.whatsapp.com/policy" target="_blank" rel="noopener">WhatsApp Business Messaging Policy</a> and <a href="https://www.whatsapp.com/legal/commerce-policy" target="_blank" rel="noopener">Commerce Policy</a>. In particular you must:</p>
<ul>
  <li>Message only people who have agreed (opted in) to receive messages from you, and honour opt-out requests such as “STOP”.</li>
  <li>Not send spam, misleading, illegal, adult, gambling or otherwise prohibited content.</li>
  <li>Not use the Service to harass anyone, or to collect payment card or government ID numbers over chat.</li>
</ul>
<p>Meta may limit, flag or ban a number that breaks its rules. We may suspend accounts that put the platform at risk. Neither we nor Meta are liable for restrictions caused by your messaging behaviour.</p>

<h2>4. Plans, wallet and charges</h2>
<ul>
  <li><strong>Plan fee:</strong> a fixed fee for the plan period (for example Basic or Platform, per 30 days), paid in advance from your wallet. New accounts may get a free trial; one trial per business.</li>
  <li><strong>Message charges:</strong> each business-initiated template message is charged from your prepaid wallet at the rate shown in the app for its category (Marketing, Utility, Authentication). Rates follow Meta’s pricing and may change when Meta changes its prices; the rate shown at the time of sending applies.</li>
  <li>Messages that WhatsApp rejects or cannot deliver are refunded to your wallet automatically, as described in the app.</li>
  <li>Prices are in Indian Rupees. Applicable taxes are charged as required by law.</li>
  <li>Wallet balance is a prepaid credit for using the Service. It is not a bank account, does not earn interest and cannot be transferred to another account.</li>
</ul>

<h2>5. Renewal and cancellation</h2>
<p>Plans do not renew automatically; you renew from Billing. When a plan ends, sending features pause until you renew, but your data is kept. You can stop using the Service at any time. Refunds are covered by our <a href="/refund.php">Refund &amp; Cancellation Policy</a>.</p>

<h2>6. Your content and data</h2>
<p>You own your contacts, templates and messages. You give us permission to store and process them only to provide the Service. You confirm you have the right and the consent to upload your customers’ data and to message them.</p>

<h2>7. Availability</h2>
<p>We work to keep the Service available and secure, but it depends on third parties (Meta, Razorpay, hosting and internet providers). We do not guarantee uninterrupted service and may perform maintenance from time to time.</p>

<h2>8. Limitation of liability</h2>
<p>To the maximum extent allowed by law, the Service is provided “as is”. We are not liable for indirect or consequential losses (such as lost profit or business). Our total liability for any claim is limited to the amount you paid us in the 3 months before the claim.</p>

<h2>9. Suspension and termination</h2>
<p>We may suspend or close an account for breach of these Terms, unpaid charges, fraud, or a legal requirement. Where possible we will warn you first. Unused wallet balance on termination is handled under the Refund Policy, unless the account was closed for fraud or a policy breach.</p>

<h2>10. Changes to these Terms</h2>
<p>We may update these Terms. We will post changes here and email account holders about important ones. Continuing to use the Service after a change means you accept it.</p>

<h2>11. Law and disputes</h2>
<p>These Terms are governed by the laws of India. Disputes are subject to the exclusive jurisdiction of the courts at <?= e($juris) ?>.</p>

<h2>12. Contact</h2>
<div class="box"><?= legalCompanyBlock() ?></div>
<?php legalEnd(); ?>
