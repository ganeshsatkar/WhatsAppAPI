<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_layout.php';
legalStart('Refund & Cancellation Policy');
?>
<p>This policy explains when money paid to WP API Pro (operated by <?= e(LEGAL_COMPANY) ?>) is refunded. It applies to plan fees and wallet recharges.</p>

<h2>1. Free trial</h2>
<p>New accounts can try the Service free for the trial period. No payment is taken for the trial, so nothing needs to be refunded or cancelled.</p>

<h2>2. Plan fees</h2>
<ul>
  <li>Plan fees are for a fixed period (for example 30 days) and are <strong>not refundable</strong> once the plan is activated, including when you stop using the Service before the period ends.</li>
  <li>You can cancel at any time simply by not renewing. Plans never renew automatically.</li>
  <li>If you were charged twice for the same plan, or charged for a plan that was never activated, we refund the extra amount in full.</li>
</ul>

<h2>3. Message charges</h2>
<ul>
  <li>Messages that WhatsApp rejects, or reports as not delivered, are refunded to your wallet <strong>automatically</strong>.</li>
  <li>Messages that were delivered are not refundable, because the WhatsApp charge has already been incurred.</li>
</ul>

<h2>4. Wallet balance</h2>
<ul>
  <li>Unused wallet balance can be refunded to your original payment method when you close your account. Email us from your registered address with the subject “Wallet refund”.</li>
  <li>Payment gateway charges and taxes already paid on the recharge may be deducted from the refund.</li>
  <li>Promotional or bonus credit added by us is not refundable.</li>
  <li>Balance is not refundable for accounts closed because of fraud or a breach of our <a href="/terms.php">Terms</a> or WhatsApp policies.</li>
</ul>

<h2>5. Failed or incomplete payments</h2>
<p>If money was debited from your bank but your wallet was not credited, it is usually credited automatically within a few minutes. If not, contact us with your Razorpay payment ID. Payments that fail at Razorpay are reversed by your bank, normally within 5–7 working days.</p>

<h2>6. How refunds are processed</h2>
<div class="box">Approved refunds are processed within <strong><?= (int)REFUND_DAYS ?> working days</strong> to the original payment method (UPI, card or bank account). Your bank may take a few more days to show it.</div>

<h2>7. Contact</h2>
<div class="box"><?= legalCompanyBlock() ?></div>
<?php legalEnd(); ?>
