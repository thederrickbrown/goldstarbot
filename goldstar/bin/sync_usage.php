<?php
/**
 * Usage-based billing sync (run by cron, e.g. daily).
 *
 * For every active/trialing customer with a linked workspace, set the Stripe
 * subscription seat quantity to max(min_seats, active users in the trailing
 * window). "Active" = gave OR received a star. Charge = quantity x $2, so the
 * next invoice reflects the prior period's real activity (min 5 = $10).
 *
 * Usage:  php bin/sync_usage.php
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\StarService;
use App\Support\StripeService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$cfg = require __DIR__ . '/../config.local.php';

$logger = new Logger('usage-sync');
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logger->pushHandler(new StreamHandler($logDir . '/usage.log', Logger::INFO));

$db = $cfg['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['database'], $db['charset'] ?? 'utf8mb4'),
    $db['username'],
    $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdoFactory = static function () use ($pdo): PDO { return $pdo; };
$stars  = new StarService($pdoFactory, $logger);
$stripe = new StripeService($cfg['stripe'] ?? [], $logger);

if (!$stripe->isConfigured()) {
    fwrite(STDERR, "Stripe not configured; nothing to do.\n");
    exit(0);
}

$minSeats   = $stripe->minSeats();
$windowDays = 31; // trailing month of activity

$rows = $pdo->query(
    "SELECT id, email, team_id, stripe_subscription_id
     FROM gsb_accounts
     WHERE stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''
       AND team_id IS NOT NULL
       AND sub_status IN ('active','trialing')"
)->fetchAll();

$stamp = date('Y-m-d H:i:s');
echo "[{$stamp}] usage sync: " . count($rows) . " subscription(s)\n";

foreach ($rows as $r) {
    $active = $stars->activeUserCount((string) $r['team_id'], $windowDays);
    $qty = max($minSeats, $active);
    $ok = $stripe->updateSeatQuantity((string) $r['stripe_subscription_id'], $qty);
    $line = sprintf('%s  team=%s  active=%d  qty=%d  %s', $r['email'], $r['team_id'], $active, $qty, $ok ? 'ok' : 'FAIL');
    echo "  {$line}\n";
    $logger->info('seat sync', ['email' => $r['email'], 'team' => $r['team_id'], 'active' => $active, 'qty' => $qty, 'ok' => $ok]);
}

echo "done\n";
