<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_layout.php';
legalStart('Contact Us');
$wa = preg_replace('/\D/', '', SUPPORT_PHONE);
?>
<p>We are happy to help with setup, billing or any question about WP API Pro.</p>

<div class="box">
  <?= legalCompanyBlock() ?>
  <?php if ($wa !== ''): ?>
  <p style="margin:14px 0 0;"><a href="https://wa.me/91<?= e($wa) ?>?text=<?= rawurlencode('Hi, I need help with WP API Pro') ?>" target="_blank" rel="noopener"
     style="display:inline-block;background:#25d366;color:#000;font-weight:700;text-decoration:none;padding:12px 20px;border-radius:10px;">💬 Chat with us on WhatsApp</a></p>
  <?php endif; ?>
</div>

<h2>Support hours</h2>
<p>Monday to Saturday, 10:00 AM – 7:00 PM IST. We usually reply within one working day.</p>

<h2>What to include</h2>
<ul>
  <li>Your registered email address.</li>
  <li>For payment questions: the Razorpay payment ID or the date and amount.</li>
  <li>For WhatsApp issues: your WhatsApp number and a screenshot of the error.</li>
</ul>

<h2>Privacy and data deletion</h2>
<p>See our <a href="/privacy.php#data-deletion">Privacy Policy</a> for how to access or delete your data.</p>
<?php legalEnd(); ?>
