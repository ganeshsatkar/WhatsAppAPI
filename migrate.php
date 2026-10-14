<?php
// ============================================================
// WP API — upgrade ANY existing database to the current schema (idempotent)
//
// Works for: the original v1.0 database, v1.1 (security release), and the
// wpapi_complete database. It reads database.sql (the single source of truth)
// and adds whatever is missing — tables, columns, indexes, enum values — then
// runs the data steps once. Safe to run again: every step checks first.
//
//   CLI (SSH):  php migrate.php
//   Browser:    https://<domain>/migrate.php?key=<CRON_SECRET from .env>
//
// Delete migrate.php from the server when it reports "Done".
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && (CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string)($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit('Forbidden');
}
if (!$cli) header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

const TARGET_VERSION = '2.0.0';
$pdo = db()->getPDO();
$dbn = DB_NAME;
$errors = 0;
$log = function (string $m) { echo $m . "\n"; if (ob_get_level()) @ob_flush(); flush(); };
$run = function (string $label, string $sql, array $params = []) use ($pdo, $log, &$errors): bool {
    try { $st = $pdo->prepare($sql); $st->execute($params); $log("  ✓ $label"); return true; }
    catch (\Throwable $e) { $errors++; $log("  ✗ $label — " . $e->getMessage()); return false; }
};
$tableExists = fn(string $t) => (bool)db()->count('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?', [$dbn, $t]);
$colInfo     = fn(string $t, string $c) => db()->row('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?', [$dbn, $t, $c]);
$indexCols   = function (string $t) use ($dbn): array {   // index name => "col1,col2"
    $out = [];
    foreach (db()->all('SELECT index_name, column_name FROM information_schema.statistics WHERE table_schema=? AND table_name=? ORDER BY index_name, seq_in_index', [$dbn, $t]) as $r) {
        $out[$r['index_name']] = isset($out[$r['index_name']]) ? $out[$r['index_name']] . ',' . $r['column_name'] : $r['column_name'];
    }
    return $out;
};
$enumValues = function (string $type): array {
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $type, $m);
    return $m[1];
};

$log('WP API migration → ' . TARGET_VERSION . ' — database ' . $dbn);
$alreadyAtTarget = $tableExists('schema_migrations') && db()->count('SELECT COUNT(*) FROM schema_migrations WHERE version=?', [TARGET_VERSION]) > 0;

// ── 1. Parse database.sql ───────────────────────────────────
$sql = file_get_contents(__DIR__ . '/database.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`(\w+)` \((.*?)\n\) ENGINE=[^;]*;/s', $sql, $tables, PREG_SET_ORDER);
if (!$tables) exit("database.sql could not be parsed\n");

$newColumns = [];   // "table.column" added in this run (drives one-time data steps)
$log('1) Tables, columns, indexes (from database.sql: ' . count($tables) . ' tables)');
foreach ($tables as [$full, $table, $body]) {
    if (!$tableExists($table)) {
        $create = preg_replace('/^CREATE TABLE (?:IF NOT EXISTS )?/', 'CREATE TABLE IF NOT EXISTS ', $full);
        $run("create table $table", $create);
        continue;
    }
    $existingIdx = $indexCols($table);
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = rtrim(trim($line), ',');
        if ($line === '') continue;

        // Column
        if (preg_match('/^`(\w+)`\s+(.+)$/', $line, $m)) {
            [$_, $col, $def] = $m;
            $info = $colInfo($table, $col);
            if (!$info) {
                $def = preg_replace('/\bUNIQUE\b/', '', $def);              // keep column adds safe on existing data
                if ($run("add column $table.$col", "ALTER TABLE `$table` ADD COLUMN `$col` $def")) $newColumns[] = "$table.$col";
            } elseif (preg_match('/^ENUM\(/i', $def)) {
                $want = $enumValues(substr($def, 0, strpos($def, ')') + 1));
                $have = $enumValues($info['COLUMN_TYPE']);
                $missing = array_diff($want, $have);
                if ($missing) {
                    // Only ever ADD enum values (keep existing ones so no row loses its value)
                    $all = array_values(array_unique(array_merge($want, $have)));
                    $rest = trim(substr($def, strpos($def, ')') + 1));
                    $enum = "ENUM('" . implode("','", array_map(fn($v) => str_replace("'", "''", $v), $all)) . "')";
                    $run("extend enum $table.$col (+" . implode(',', $missing) . ')', "ALTER TABLE `$table` MODIFY COLUMN `$col` $enum $rest");
                }
            }
            continue;
        }

        // Secondary index (skip when an index on the same columns already exists under another name)
        if (preg_match('/^(UNIQUE\s+KEY|UNIQUE\s+INDEX|KEY|INDEX)\s+`(\w+)`\s*\(([^)]+)\)/i', $line, $m)) {
            [$_, $kind, $name, $cols] = $m;
            $colsNorm = str_replace(['`', ' '], '', $cols);
            if (isset($existingIdx[$name]) || in_array($colsNorm, $existingIdx, true)) continue;
            $unique = stripos($kind, 'UNIQUE') === 0;
            if ($unique) {
                // remove duplicate rows first (keep the oldest) so the unique key can be created
                $c = explode(',', $colsNorm);
                // '=' (not '<=>'): rows with NULLs never violate a unique key, so they must not be deleted
                $join = implode(' AND ', array_map(fn($x) => "a.`$x` = b.`$x`", $c));
                $run("dedupe $table ($colsNorm)", "DELETE a FROM `$table` a JOIN `$table` b ON $join AND a.id > b.id");
            }
            $run(($unique ? 'unique ' : 'index ') . "$table.$name", "ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE ' : '') . "KEY `$name` ($cols)");
            $existingIdx[$name] = $colsNorm;
        }
        // PRIMARY KEY / FOREIGN KEY lines are left alone on existing tables
    }
}

// ── 2. Data ─────────────────────────────────────────────────
$log('2) Data');
$isNew = fn(string $tc) => in_array($tc, $newColumns, true);

// Message pricing: make sure every category exists; old rows get meta cost = their old price
$run('message pricing rows', "INSERT IGNORE INTO message_pricing (category, meta_cost, markup_percent, price_per_msg) VALUES
    ('MARKETING',1.0900,10,1.1990),('UTILITY',0.1450,10,0.1595),('AUTHENTICATION',0.1450,10,0.1595),('SERVICE',0,10,0)");
if ($isNew('message_pricing.meta_cost')) $run('meta cost = old price', 'UPDATE message_pricing SET meta_cost = price_per_msg WHERE meta_cost = 0');

// Plans: Trial / Basic / Platform (old plans retired, not deleted)
$run('plans Trial/Basic/Platform', "INSERT INTO plans (name,slug,price,duration_days,is_public,max_contacts,max_messages,max_team_members,chatbot_builder,automation_workflows,advanced_analytics,api_access,is_active) VALUES
     ('Trial','trial',0,7,0,-1,-1,-1,1,1,1,1,1),
     ('Basic','basic',599,30,1,5000,-1,2,1,0,0,0,1),
     ('Platform','platform',1199,30,1,-1,-1,-1,1,1,1,1,1)
    ON DUPLICATE KEY UPDATE is_active=1" . ($alreadyAtTarget ? '' : ", name=VALUES(name), price=VALUES(price), duration_days=VALUES(duration_days), is_public=VALUES(is_public),
      max_contacts=VALUES(max_contacts), max_messages=VALUES(max_messages), max_team_members=VALUES(max_team_members),
      chatbot_builder=VALUES(chatbot_builder), automation_workflows=VALUES(automation_workflows), advanced_analytics=VALUES(advanced_analytics), api_access=VALUES(api_access)"));
$run('retire old plans', "UPDATE plans SET is_active=0, is_public=0 WHERE slug IN ('free','pro','ultimate','growth','enterprise')");

if (!$alreadyAtTarget) {
    // Nobody gets locked out on upgrade: existing customers get 30 days on the plan they are on today
    $run('30-day window for existing subscriptions', "UPDATE subscriptions SET expires_at = NOW() + INTERVAL 30 DAY WHERE status='active' AND expires_at IS NULL");
    $run('30-day window for users without a subscription', "INSERT INTO subscriptions (user_id, plan_id, status, started_at, expires_at)
        SELECT u.id, u.plan_id, 'active', NOW(), NOW() + INTERVAL 30 DAY FROM users u
         WHERE u.role='user' AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW()))");
}

if ($isNew('conversations.last_inbound_at')) {
    $run('24h window: last inbound time', "UPDATE conversations cv SET last_inbound_at = (SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id=cv.id AND m.direction='inbound')");
}
if ($isNew('wallet_transactions.msg_count')) {
    try {
        $pdo->exec("UPDATE wallet_transactions
            SET msg_count = CAST(REGEXP_REPLACE(REGEXP_SUBSTR(description, '[0-9]+ (marketing|utility|authentication|service)'), '[^0-9]', '') AS UNSIGNED),
                category  = UPPER(REGEXP_SUBSTR(description, '(marketing|utility|authentication|service)'))
            WHERE type='debit' AND msg_count IS NULL AND description REGEXP '[0-9]+ (marketing|utility|authentication|service)'");
        $log('  ✓ profit report: back-filled old debits');
    } catch (\Throwable $e) { $log('  · profit back-fill skipped (' . $e->getMessage() . ')'); }
}

$n = db()->run('INSERT IGNORE INTO wallets (user_id, balance) SELECT id, 0 FROM users')->rowCount();
$log("  ✓ wallets created: $n");

foreach (db()->all("SELECT id FROM user_whatsapp_settings WHERE verify_token IS NULL OR verify_token=''") as $r) {
    db()->run('UPDATE user_whatsapp_settings SET verify_token=? WHERE id=?', [generateToken(32), $r['id']]);
}

$n = 0;
foreach (db()->all("SELECT id, access_token FROM user_whatsapp_settings WHERE access_token IS NOT NULL AND access_token <> '' AND access_token NOT LIKE 'enc:v1:%'") as $r) {
    db()->run('UPDATE user_whatsapp_settings SET access_token=? WHERE id=?', [encryptSecret($r['access_token']), $r['id']]);
    $n++;
}
$log("  ✓ WhatsApp access tokens encrypted: $n");

$n = 0;
foreach (db()->all("SELECT id, user_id, phone FROM contacts WHERE phone REGEXP '[^0-9]' OR CHAR_LENGTH(phone) = 10") as $c) {
    $p = normalizePhone($c['phone']);
    if (!isValidPhone($p) || $p === $c['phone']) continue;
    if (db()->row('SELECT id FROM contacts WHERE user_id=? AND phone=? AND id<>?', [$c['user_id'], $p, $c['id']])) continue;
    db()->run('UPDATE contacts SET phone=? WHERE id=?', [$p, $c['id']]);
    $n++;
}
$log("  ✓ contact phones normalised: $n");

$run('record version', 'INSERT IGNORE INTO schema_migrations (version) VALUES (?)', [TARGET_VERSION]);
$log($errors ? "Finished with $errors error(s) — see ✗ lines above." : 'Done. Safe to run again; delete migrate.php from the server now.');
