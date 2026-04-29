<?php
/**
 * Webhook GitHub → auto-deploy su Hostinger.
 *
 * Configurazione: config.php deve contenere:
 *   'webhook_secret' => 'xxxxxxxxxx',  // stesso valore inserito su GitHub
 *   'deploy_branch'  => 'refs/heads/main',  // opzionale (default: main)
 *
 * Setup su GitHub: repo → Settings → Webhooks → Add webhook
 *   - Payload URL: https://tuodominio.it/deploy.php
 *   - Content type: application/json
 *   - Secret: lo stesso di config.php
 *   - Events: Just the push event
 */

header('Content-Type: text/plain; charset=utf-8');

$logFile = __DIR__ . '/data/deploy.log';
if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);

function dlog($msg) {
  global $logFile;
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  @file_put_contents($logFile, $line, FILE_APPEND);
  echo $line;
}

// --- Carica config ---
$cfgFile = __DIR__ . '/config.php';
if (!file_exists($cfgFile)) {
  http_response_code(503);
  dlog('ERROR: config.php mancante. Esegui prima install.php.');
  exit;
}
$cfg = require $cfgFile;
$secret = $cfg['webhook_secret'] ?? '';
$branch = $cfg['deploy_branch'] ?? 'refs/heads/main';

if (!$secret) {
  http_response_code(503);
  dlog('ERROR: webhook_secret non configurato in config.php');
  exit;
}

// --- Endpoint info / health check (GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo "Life Manager — Deploy webhook\n";
  echo "Branch: $branch\n";
  echo "Status: configurato\n";
  echo "Per testare manualmente: POST con header GitHub valido.\n";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

// --- Verifica HMAC GitHub ---
$body = file_get_contents('php://input');
$received = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

if (!$received || !hash_equals($expected, $received)) {
  http_response_code(401);
  dlog("ERROR: signature non valida. Received: $received");
  exit('Signature mismatch');
}

// --- Verifica che sia un push event sul branch giusto ---
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
  dlog('Ping ricevuto: webhook configurato correttamente.');
  exit('pong');
}
if ($event !== 'push') {
  dlog("Skip: evento '$event' (non push)");
  exit("Skipped: $event");
}

$payload = json_decode($body, true) ?: [];
$ref = $payload['ref'] ?? '';
if ($ref !== $branch) {
  dlog("Skip: ref '$ref' diverso da '$branch'");
  exit("Skipped: branch $ref");
}

$pusher = $payload['pusher']['name'] ?? '?';
$commit = substr($payload['after'] ?? '', 0, 7);
$commitMsg = $payload['head_commit']['message'] ?? '';
dlog("Push da $pusher · commit $commit · " . substr($commitMsg, 0, 80));

// --- Esegui git pull ---
if (!function_exists('shell_exec')) {
  http_response_code(500);
  dlog('ERROR: shell_exec disabilitato sul server. Contatta Hostinger o usa il deploy manuale.');
  exit('shell_exec disabled');
}

chdir(__DIR__);

// reset locale eventuale (in caso di file modificati a mano sul server)
$output = '';
$output .= shell_exec('git fetch --all 2>&1');
$output .= shell_exec('git reset --hard origin/' . basename($branch) . ' 2>&1');
$output .= shell_exec('git pull 2>&1');

dlog("git output:\n" . trim($output));

// Permessi su data/ se servono
@chmod(__DIR__ . '/data', 0755);

dlog("Deploy completato.\n--------------------");
echo "OK";
