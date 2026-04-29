<?php
/**
 * Life Manager — installer per Hostinger / MySQL
 * Apri questo file dal browser dopo aver caricato i file sul server.
 * Si auto-disabilita una volta completato (rinomina sé stesso in install.done.php).
 */

$INSTALLED_FLAG = __DIR__ . '/config.php';

if (file_exists($INSTALLED_FLAG)) {
  $cfg = require $INSTALLED_FLAG;
  if (!empty($cfg['installed'])) {
    http_response_code(403);
    echo "<h1>Installazione già completata</h1><p>Il file config.php esiste. Per reinstallare, eliminalo manualmente.</p><p><a href='/'>Apri l'app</a></p>";
    exit;
  }
}

$step = $_POST['step'] ?? '1';
$err = null;
$done = false;
$adminEmail = null;

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === '2') {
  $cfg = [
    'db_driver' => 'mysql',
    'db_host' => trim($_POST['db_host']),
    'db_name' => trim($_POST['db_name']),
    'db_user' => trim($_POST['db_user']),
    'db_pass' => $_POST['db_pass'],
    'auto_verify_email' => !empty($_POST['auto_verify']),
    'app_url' => trim($_POST['app_url'] ?? ''),
    'webhook_secret' => bin2hex(random_bytes(20)),
    'deploy_branch' => 'refs/heads/main',
    'installed' => true,
  ];
  $admin = [
    'email' => strtolower(trim($_POST['admin_email'])),
    'password' => $_POST['admin_password'],
    'name' => trim($_POST['admin_name']),
  ];

  // Validazione
  if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) $err = "Email admin non valida";
  elseif (strlen($admin['password']) < 6) $err = "La password admin deve essere almeno 6 caratteri";

  if (!$err) {
    try {
      $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
      $db = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
      ]);

      // SCHEMA
      $schema = [
        "CREATE TABLE IF NOT EXISTS users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(255) UNIQUE NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          name VARCHAR(120),
          email_verified TINYINT DEFAULT 0,
          verification_token VARCHAR(120),
          reset_token VARCHAR(120),
          reset_expires DATETIME NULL,
          is_admin TINYINT DEFAULT 0,
          active TINYINT DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          last_login DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS sessions (
          token VARCHAR(80) PRIMARY KEY,
          user_id INT NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
          user_agent VARCHAR(500),
          INDEX idx_user (user_id),
          FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS projects (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          name VARCHAR(255) NOT NULL,
          category VARCHAR(80),
          status VARCHAR(20) DEFAULT 'attivo',
          priority INT DEFAULT 3,
          path VARCHAR(500),
          url VARCHAR(500),
          description TEXT,
          next_action VARCHAR(500),
          revenue DECIMAL(12,2) DEFAULT 0,
          cost DECIMAL(12,2) DEFAULT 0,
          color VARCHAR(20) DEFAULT '#6366f1',
          archived TINYINT DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS tasks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          project_id INT NULL,
          title VARCHAR(255) NOT NULL,
          notes TEXT,
          priority INT DEFAULT 3,
          status VARCHAR(20) DEFAULT 'todo',
          due_date DATE NULL,
          completed_at DATETIME NULL,
          estimated_minutes INT DEFAULT 30,
          scheduled_date DATE NULL,
          scheduled_start VARCHAR(10),
          scheduled_end VARCHAR(10),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS routines (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          icon VARCHAR(10) DEFAULT '✓',
          frequency VARCHAR(20) DEFAULT 'daily',
          time VARCHAR(10),
          category VARCHAR(80),
          active TINYINT DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS routine_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          routine_id INT NOT NULL,
          user_id INT,
          date DATE NOT NULL,
          done TINYINT DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_routine_date (routine_id, date),
          FOREIGN KEY(routine_id) REFERENCES routines(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS events (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          description TEXT,
          start_date DATE NOT NULL,
          start_time VARCHAR(10),
          end_date DATE NULL,
          end_time VARCHAR(10),
          category VARCHAR(80),
          project_id INT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS notes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          content TEXT,
          tag VARCHAR(80),
          pinned TINYINT DEFAULT 0,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS goals (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          title VARCHAR(255) NOT NULL,
          description TEXT,
          target_value DECIMAL(14,2),
          current_value DECIMAL(14,2) DEFAULT 0,
          unit VARCHAR(40),
          deadline DATE NULL,
          category VARCHAR(80),
          status VARCHAR(20) DEFAULT 'attivo',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS finances (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          type VARCHAR(20) NOT NULL,
          amount DECIMAL(12,2) NOT NULL,
          description VARCHAR(500),
          category VARCHAR(80),
          project_id INT NULL,
          date DATE NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS schedule_blocks (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NOT NULL,
          weekday INT NOT NULL,
          start_time VARCHAR(10) NOT NULL,
          end_time VARCHAR(10) NOT NULL,
          type VARCHAR(20) NOT NULL,
          label VARCHAR(255) NOT NULL,
          color VARCHAR(20) DEFAULT '#6366f1',
          notes TEXT,
          active TINYINT DEFAULT 1,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      ];

      foreach ($schema as $sql) $db->exec($sql);

      // Crea admin
      $exists = $db->prepare("SELECT id FROM users WHERE email=?");
      $exists->execute([$admin['email']]);
      if ($exists->fetch()) {
        // Aggiorna password ed eleva a admin
        $db->prepare("UPDATE users SET password_hash=?, name=?, is_admin=1, active=1, email_verified=1 WHERE email=?")
          ->execute([password_hash($admin['password'], PASSWORD_DEFAULT), $admin['name'], $admin['email']]);
        $stmt = $db->prepare("SELECT id FROM users WHERE email=?");
        $stmt->execute([$admin['email']]);
        $uid = (int)$stmt->fetchColumn();
      } else {
        $stmt = $db->prepare("INSERT INTO users (email, password_hash, name, email_verified, is_admin, active) VALUES (?,?,?,1,1,1)");
        $stmt->execute([$admin['email'], password_hash($admin['password'], PASSWORD_DEFAULT), $admin['name']]);
        $uid = (int)$db->lastInsertId();
      }

      // Seed iniziale (settimana tipo + routine)
      $countBlocks = $db->prepare("SELECT COUNT(*) FROM schedule_blocks WHERE user_id=?");
      $countBlocks->execute([$uid]);
      if ((int)$countBlocks->fetchColumn() === 0) {
        $C = ['sonno'=>'#94a3b8','sport'=>'#f59e0b','pasto'=>'#10b981','lavoro'=>'#4f46e5','personale'=>'#8b5cf6','libero'=>'#06b6d4'];
        $weekday_blocks = [
          ['00:00','07:00','sonno','Sonno'],
          ['07:00','07:30','personale','Sveglia & colazione'],
          ['07:30','09:00','sport','Palestra'],
          ['09:00','09:30','personale','Doccia & preparazione'],
          ['09:30','13:00','lavoro','Lavoro mattina'],
          ['13:00','14:00','pasto','Pranzo'],
          ['14:00','18:00','lavoro','Lavoro pomeriggio'],
          ['18:00','19:30','personale','Tempo libero'],
          ['19:30','20:30','pasto','Cena'],
          ['20:30','23:00','libero','Sera libera'],
          ['23:00','24:00','sonno','Sonno'],
        ];
        $weekend_blocks = [
          ['00:00','08:00','sonno','Sonno'],
          ['08:00','09:00','personale','Sveglia & colazione'],
          ['09:00','11:00','sport','Sport / Outdoor'],
          ['11:00','13:00','personale','Tempo libero'],
          ['13:00','14:30','pasto','Pranzo'],
          ['14:30','17:00','libero','Riposo / Hobby'],
          ['17:00','19:30','personale','Tempo libero'],
          ['19:30','21:00','pasto','Cena'],
          ['21:00','24:00','libero','Sera libera'],
        ];
        $stmt = $db->prepare("INSERT INTO schedule_blocks (user_id, weekday, start_time, end_time, type, label, color) VALUES (?,?,?,?,?,?,?)");
        for ($wd=0; $wd<=4; $wd++) foreach ($weekday_blocks as $b) $stmt->execute([$uid, $wd, $b[0], $b[1], $b[2], $b[3], $C[$b[2]]]);
        for ($wd=5; $wd<=6; $wd++) foreach ($weekend_blocks as $b) $stmt->execute([$uid, $wd, $b[0], $b[1], $b[2], $b[3], $C[$b[2]]]);

        $rstmt = $db->prepare("INSERT INTO routines (user_id, title, icon, frequency, time, category) VALUES (?,?,?,?,?,?)");
        $rstmt->execute([$uid, 'Allenamento', '💪', 'daily', '07:30', 'Salute']);
        $rstmt->execute([$uid, 'Lettura', '📚', 'daily', '21:00', 'Personale']);
        $rstmt->execute([$uid, 'Revisione settimanale', '🎯', 'weekly', '09:00', 'Lavoro']);
      }

      // Scrivi config.php
      $configContent = "<?php\nreturn " . var_export($cfg, true) . ";\n";
      if (false === file_put_contents($INSTALLED_FLAG, $configContent)) {
        throw new Exception("Impossibile scrivere config.php (controlla i permessi della cartella)");
      }
      @chmod($INSTALLED_FLAG, 0640);

      $done = true;
      $adminEmail = $admin['email'];
      $webhookSecret = $cfg['webhook_secret'];
    } catch (Exception $e) {
      $err = "Errore: " . $e->getMessage();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installazione · Life Manager</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, "Segoe UI", system-ui, sans-serif; background: linear-gradient(135deg, #f7f6f2 0%, #ece8e0 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; color: #1f2937; }
.box { background: #fff; max-width: 560px; width: 100%; border-radius: 20px; padding: 36px; box-shadow: 0 20px 60px rgba(15,23,42,0.10); }
.logo { width: 56px; height: 56px; background: linear-gradient(135deg, #4f46e5, #7c3aed); border-radius: 16px; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 20px; }
h1 { font-size: 24px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 8px; }
.muted { color: #6b7280; font-size: 14px; margin-bottom: 24px; }
.field { margin-bottom: 16px; }
label { display: block; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 6px; }
input[type=text], input[type=email], input[type=password] { width: 100%; padding: 11px 14px; border: 1px solid #d9d3c4; border-radius: 10px; font-size: 15px; }
input:focus { outline: none; border-color: #4f46e5; box-shadow: 0 0 0 3px #eef2ff; }
.row { display: flex; gap: 12px; }
.row .field { flex: 1; }
.btn { width: 100%; padding: 13px; background: #4f46e5; color: #fff; border: 0; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; margin-top: 8px; }
.btn:hover { background: #4338ca; }
.section-title { font-weight: 600; margin: 22px 0 12px; padding-top: 16px; border-top: 1px solid #ece8de; }
.section-title:first-of-type { padding-top: 0; border-top: 0; margin-top: 0; }
.err { background: #fef2f2; color: #dc2626; padding: 11px 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
.ok { background: #ecfdf5; color: #059669; padding: 14px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
.checkbox-row { display: flex; align-items: flex-start; gap: 10px; padding: 12px; background: #fbfaf6; border-radius: 10px; }
.checkbox-row input { margin-top: 3px; }
.checkbox-row label { text-transform: none; letter-spacing: 0; font-size: 13px; color: #374151; font-weight: 500; cursor: pointer; }
.checkbox-row label small { color: #6b7280; font-weight: 400; display: block; margin-top: 2px; }
code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
a.btn-link { display: inline-block; margin-top: 16px; padding: 12px 22px; background: #4f46e5; color: #fff; border-radius: 10px; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>
<div class="box">
<?php if ($done): ?>
  <div class="logo">✓</div>
  <h1>Installazione completata</h1>
  <p class="muted">Database creato, tabelle inizializzate, admin attivo.</p>
  <div class="ok">
    <b>Admin:</b> <?= h($adminEmail) ?><br>
    <b>Database:</b> <?= h($_POST['db_name']) ?>
  </div>

  <div class="section-title">Auto-deploy GitHub → Hostinger</div>
  <p class="muted" style="margin-bottom:14px;font-size:13px">
    Configura il webhook su GitHub così ogni push su <code>main</code> aggiorna automaticamente il sito.
  </p>
  <div style="background:#fbfaf6;border:1px solid #ece8de;border-radius:10px;padding:14px;font-size:13px;margin-bottom:14px">
    <b style="display:block;margin-bottom:8px">1. Vai su GitHub:</b>
    <code style="display:block;background:#fff;padding:8px;border-radius:6px;margin-bottom:12px;word-break:break-all">https://github.com/camillithomas7-prog/life-manager/settings/hooks/new</code>
    <b style="display:block;margin-bottom:8px">2. Compila i campi:</b>
    <div style="background:#fff;padding:10px;border-radius:6px;margin-bottom:6px">
      <small style="color:#6b7280">Payload URL</small><br>
      <code style="font-size:12px;word-break:break-all"><?= h(($cfg['app_url'] ?: 'https://' . ($_SERVER['HTTP_HOST'] ?? '')) . '/deploy.php') ?></code>
    </div>
    <div style="background:#fff;padding:10px;border-radius:6px;margin-bottom:6px">
      <small style="color:#6b7280">Content type</small><br>
      <code style="font-size:12px">application/json</code>
    </div>
    <div style="background:#fff;padding:10px;border-radius:6px;margin-bottom:6px">
      <small style="color:#6b7280">Secret (copia esatto)</small><br>
      <code style="font-size:12px;word-break:break-all" id="ws"><?= h($webhookSecret) ?></code>
      <button onclick="navigator.clipboard.writeText(document.getElementById('ws').textContent);this.textContent='✓ Copiato'" style="float:right;margin-top:-2px;background:#4f46e5;color:#fff;border:0;padding:4px 10px;border-radius:6px;cursor:pointer;font-size:11px">Copia</button>
    </div>
    <div style="background:#fff;padding:10px;border-radius:6px">
      <small style="color:#6b7280">Events</small><br>
      <code style="font-size:12px">Just the push event</code>
    </div>
  </div>

  <div class="err" style="background:#fef3c7;color:#92400e;border-radius:10px">
    <b>⚠ Sicurezza:</b> elimina <code>install.php</code> dal server (File Manager Hostinger → Delete) appena hai finito.
  </div>
  <a class="btn-link" href="/">Apri Life Manager →</a>

<?php else: ?>
  <div class="logo">●</div>
  <h1>Life Manager · Installazione</h1>
  <p class="muted">Inserisci i dati MySQL di Hostinger e crea il tuo account admin.</p>
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="step" value="2">

    <div class="section-title">Database MySQL (Hostinger)</div>
    <div class="field">
      <label>Host</label>
      <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? $_GET['db_host'] ?? 'localhost') ?>" required>
    </div>
    <div class="field">
      <label>Nome database</label>
      <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? $_GET['db_name'] ?? '') ?>" placeholder="u749757264_applavoro" required>
    </div>
    <div class="row">
      <div class="field">
        <label>Utente</label>
        <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? $_GET['db_user'] ?? '') ?>" placeholder="u749757264_applavoro" required>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="db_pass" required>
      </div>
    </div>

    <div class="section-title">Account admin</div>
    <div class="field">
      <label>Nome</label>
      <input type="text" name="admin_name" value="<?= h($_POST['admin_name'] ?? $_GET['admin_name'] ?? '') ?>" placeholder="Thomas">
    </div>
    <div class="row">
      <div class="field">
        <label>Email</label>
        <input type="email" name="admin_email" value="<?= h($_POST['admin_email'] ?? $_GET['admin_email'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Password (min 6)</label>
        <input type="password" name="admin_password" minlength="6" required>
      </div>
    </div>

    <div class="section-title">Opzioni</div>
    <div class="checkbox-row">
      <input type="checkbox" name="auto_verify" id="auto_verify" value="1" <?= !isset($_POST['db_host']) || !empty($_POST['auto_verify']) ? 'checked' : '' ?>>
      <label for="auto_verify">
        Verifica email automatica
        <small>I nuovi utenti possono accedere subito senza cliccare link di conferma. Disattivalo solo quando hai SMTP funzionante.</small>
      </label>
    </div>

    <button class="btn" type="submit">Installa</button>
  </form>
<?php endif; ?>
</div>
</body>
</html>
