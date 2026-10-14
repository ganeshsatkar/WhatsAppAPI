<?php
// Replaced by campaign_report.php (failure reasons, billed retry, CSV export).
// The old debug page sent template messages without wallet billing.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
redirect('/user/campaign_report.php?id=' . (int)($_GET['id'] ?? 0));
