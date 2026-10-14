<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/legal_layout.php';
legalStart('Privacy Policy');
?>
<p>This Privacy Policy explains how <?= e(LEGAL_COMPANY) ?> (“we”, “us”) collects, uses and protects personal data when you use WP API Pro at <?= e(parse_url(APP_URL, PHP_URL_HOST)) ?> (the “Service”). It is written to meet India’s Digital Personal Data Protection Act, 2023 (“DPDP Act”) and the requirements of Meta’s WhatsApp Business Platform.</p>

<h2>1. Who is responsible for which data</h2>
<ul>
  <li><strong>Your account data</strong> (name, email, phone, company, billing): we decide how it is used — we are the <em>data fiduciary</em>.</li>
  <li><strong>Your customers’ data</strong> (contacts you upload, WhatsApp chats with them): you decide what is collected and why. We process it only on your instructions to provide the Service — we act as your <em>data processor</em>. You are responsible for having your customers’ consent to message them.</li>
</ul>

<h2>2. Data we collect</h2>
<ul>
  <li><strong>Account:</strong> name, email, phone number, company name, password (stored only as a one-way hash), time zone.</li>
  <li><strong>WhatsApp connection:</strong> your WhatsApp Business Account ID, phone number ID and access token (stored encrypted).</li>
  <li><strong>Messaging data:</strong> contacts, tags, custom fields, message templates, campaigns, and the content of messages sent and received through your number, including media files.</li>
  <li><strong>Billing:</strong> plan, wallet balance and transactions. Card, UPI and bank details are entered on Razorpay’s secure page; we never see or store them.</li>
  <li><strong>Technical:</strong> IP address, browser type, login times and an activity log, used for security and fraud prevention.</li>
</ul>

<h2>3. How we use it</h2>
<ul>
  <li>To provide the Service: send and receive WhatsApp messages, run campaigns, chatbots and automation, and show reports.</li>
  <li>To bill you and keep your wallet ledger.</li>
  <li>To secure accounts (login protection, abuse and spam prevention) and to send service emails such as verification, password reset and plan reminders.</li>
  <li>To meet legal, tax and accounting obligations.</li>
</ul>
<p>We do <strong>not</strong> sell personal data, and we do not use your customers’ data for our own marketing or advertising.</p>

<h2>4. Who we share it with</h2>
<ul>
  <li><strong>Meta Platforms (WhatsApp Cloud API)</strong> — messages are delivered through Meta’s infrastructure under Meta’s terms.</li>
  <li><strong>Razorpay</strong> — to process wallet recharges.</li>
  <li><strong>Our hosting and email providers</strong> — to run the Service and send emails.</li>
  <li><strong>Authorities</strong> — only when required by law.</li>
</ul>

<h2>5. Storage, security and retention</h2>
<p>Data is stored on secured servers. Access tokens are encrypted, passwords are hashed, all traffic uses HTTPS, and access is limited to what is needed to operate the Service. We keep account and messaging data while your account is active. After you close your account we delete or anonymise it within 90 days, except billing records, which Indian tax law requires us to keep for up to 8 years.</p>

<h2>6. Cookies</h2>
<p>We use one essential session cookie to keep you logged in, and your browser may store small settings (for example, a hidden checklist). We do not use advertising or third-party tracking cookies.</p>

<h2>7. Your rights</h2>
<p>Under the DPDP Act you can ask to access, correct or erase your personal data, withdraw consent, and nominate another person to exercise these rights. Your customers who want their data removed should contact you (the business they talk to); we will help you fulfil such requests. Anyone can stop marketing messages by replying <strong>STOP</strong>.</p>

<h2 id="data-deletion">8. How to delete your data</h2>
<div class="box">
  <ul style="margin-bottom:0">
    <li>Email <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a> from your registered email address with the subject “Delete my data”.</li>
    <li>We confirm the request and delete your account, contacts, messages and WhatsApp connection data within 30 days, and send you a confirmation.</li>
    <li>You can also disconnect WhatsApp at any time from Settings, and delete individual contacts and templates yourself.</li>
  </ul>
</div>

<h2>9. Children</h2>
<p>The Service is for businesses and is not meant for anyone under 18.</p>

<h2>10. Changes</h2>
<p>We will post updates on this page and change the “Last updated” date. Important changes will also be emailed to account holders.</p>

<h2>11. Contact and grievance officer</h2>
<div class="box"><?php if (GRIEVANCE_NAME !== ''): ?>Grievance Officer: <strong><?= e(GRIEVANCE_NAME) ?></strong><br><?php endif; ?><?= legalCompanyBlock() ?><br>We respond to privacy requests within 30 days.</div>
<?php legalEnd(); ?>
