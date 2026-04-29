<?php
$db = new PDO('sqlite:' . __DIR__ . '/data/life.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  name TEXT,
  email_verified INTEGER DEFAULT 0,
  verification_token TEXT,
  reset_token TEXT,
  reset_expires DATETIME,
  is_admin INTEGER DEFAULT 0,
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME
)");

$db->exec("CREATE TABLE IF NOT EXISTS sessions (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
  user_agent TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
)");

function addCol($db, $table, $col, $type) {
  $cols = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_column($cols, 'name');
  if (!in_array($col, $names)) {
    $db->exec("ALTER TABLE $table ADD COLUMN $col $type");
    echo "+ $table.$col\n";
  }
}

// Aggiungi user_id a tutte le tabelle dati
foreach (['projects','tasks','routines','routine_logs','events','notes','goals','finances','schedule_blocks'] as $t) {
  addCol($db, $t, 'user_id', 'INTEGER');
}

// Crea utente admin Thomas con i dati esistenti
$exists = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($exists == 0) {
  $stmt = $db->prepare("INSERT INTO users (id, email, password_hash, name, email_verified, is_admin) VALUES (1, ?, ?, ?, 1, 1)");
  $stmt->execute([
    'samuelehk@gmail.com',
    password_hash('thomas2026', PASSWORD_DEFAULT),
    'Thomas'
  ]);
  echo "+ Utente admin creato: samuelehk@gmail.com / thomas2026\n";
}

// Migra dati esistenti (senza user_id) all'admin
foreach (['projects','tasks','routines','events','notes','goals','finances','schedule_blocks'] as $t) {
  $n = $db->exec("UPDATE $t SET user_id=1 WHERE user_id IS NULL");
  if ($n > 0) echo "  → $n righe migrate in $t\n";
}
// routine_logs eredita da routines
$db->exec("UPDATE routine_logs SET user_id=(SELECT user_id FROM routines WHERE routines.id=routine_logs.routine_id) WHERE user_id IS NULL");

// Indici per performance
$db->exec("CREATE INDEX IF NOT EXISTS idx_projects_user ON projects(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_tasks_user ON tasks(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_routines_user ON routines(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_events_user ON events(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_notes_user ON notes(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_goals_user ON goals(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_finances_user ON finances(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_blocks_user ON schedule_blocks(user_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id)");

echo "\n✓ Migrazione auth completata\n";
echo "  Utenti: " . $db->query("SELECT COUNT(*) FROM users")->fetchColumn() . "\n";
echo "  Sessioni: " . $db->query("SELECT COUNT(*) FROM sessions")->fetchColumn() . "\n";
