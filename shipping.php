<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_layout.php';
legalStart('Service Delivery Policy');
?>
<p>WP API Pro is an online software service. <strong>No physical goods are shipped.</strong></p>

<h2>How the service is delivered</h2>
<ul>
  <li><strong>Account and free trial:</strong> available immediately after sign-up.</li>
  <li><strong>Plan activation:</strong> immediate after payment from your wallet. You will see the plan and its end date under Billing.</li>
  <li><strong>Wallet recharge:</strong> credited automatically, usually within a few minutes of a successful Razorpay payment.</li>
  <li><strong>WhatsApp connection and templates:</strong> depend on Meta. Connecting a number takes a few minutes once your Meta account is ready; template approval by Meta usually takes minutes but can take up to 24–48 hours.</li>
</ul>

<h2>Access</h2>
<p>The Service is used in a web browser at <?= e(parse_url(APP_URL, PHP_URL_HOST)) ?> and can be installed on your phone as an app. Login details are sent to the email you register with.</p>

<h2>Problems with delivery</h2>
<p>If a paid plan or recharge does not appear in your account, contact us with your payment ID and we will resolve it, or refund under our <a href="/refund.php">Refund Policy</a>.</p>

<div class="box"><?= legalCompanyBlock() ?></div>
<?php legalEnd(); ?>
