<?php
$db = new PDO('sqlite:' . __DIR__ . '/data/life.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->exec("CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category TEXT,
  status TEXT DEFAULT 'attivo',
  priority INTEGER DEFAULT 3,
  path TEXT,
  url TEXT,
  description TEXT,
  next_action TEXT,
  revenue REAL DEFAULT 0,
  cost REAL DEFAULT 0,
  color TEXT DEFAULT '#6366f1',
  archived INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER,
  title TEXT NOT NULL,
  notes TEXT,
  priority INTEGER DEFAULT 3,
  status TEXT DEFAULT 'todo',
  due_date DATE,
  completed_at DATETIME,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(project_id) REFERENCES projects(id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS routines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  icon TEXT DEFAULT '✓',
  frequency TEXT DEFAULT 'daily',
  time TEXT,
  category TEXT,
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS routine_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  routine_id INTEGER NOT NULL,
  date DATE NOT NULL,
  done INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(routine_id, date),
  FOREIGN KEY(routine_id) REFERENCES routines(id) ON DELETE CASCADE
)");

$db->exec("CREATE TABLE IF NOT EXISTS events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT,
  start_date DATE NOT NULL,
  start_time TEXT,
  end_date DATE,
  end_time TEXT,
  category TEXT,
  project_id INTEGER,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  content TEXT,
  tag TEXT,
  pinned INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT,
  target_value REAL,
  current_value REAL DEFAULT 0,
  unit TEXT,
  deadline DATE,
  category TEXT,
  status TEXT DEFAULT 'attivo',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS finances (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  amount REAL NOT NULL,
  description TEXT,
  category TEXT,
  project_id INTEGER,
  date DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Seed iniziale progetti se vuoto
$count = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
if ($count == 0) {
  $seed = [
    ['Soprhan (Gestionale)', 'E-commerce', 'attivo', 1, '/Users/thomaspc/gestionale-shopify', '', 'Store Shopify principale - gestionale ordini COD e bilancio', 'Monitorare ordini giornalieri', '#10b981'],
    ['Luvrib Foot Sleeves', 'E-commerce', 'attivo', 1, '/Users/thomaspc/luvrib-foot', '', 'Funnel advertorial + product (US)', 'Test ads e ottimizzare conversion', '#8b5cf6'],
    ['Luvrib Shopify Theme', 'E-commerce', 'attivo', 2, '/Users/thomaspc/luvrib-shopify-theme', 'https://y0jzwz-zd.myshopify.com', 'Tema custom Luvrib', 'Pubblicare prodotto', '#a855f7'],
    ['AromaFit / HUSH', 'E-commerce', 'attivo', 2, '/Users/thomaspc/aromafit-shopify-theme', '', 'Tema Shopify diffusore aromaterapia US', 'Caricare ZIP definitivo', '#f59e0b'],
    ['Salvaria (legacy Luvrib)', 'E-commerce', 'pausa', 4, '/Users/thomaspc/salvaria-store', '', 'Cartella legacy brand Luvrib anti-soffocamento', 'Decidere se archiviare', '#64748b'],
    ['ReviewShield Broad', 'SaaS/Servizi', 'attivo', 2, '/Users/thomaspc/reviewshield-broad', 'https://midnightblue-pony-128540', 'Servizio rimozione recensioni Google', 'Generare lead', '#0ea5e9'],
    ['LDP Consulting', 'SaaS/Servizi', 'attivo', 2, '/Users/thomaspc/ldp-consulting', '', 'Gestionale PHP collegato Airtable+GCal+Meta', 'Manutenzione e nuove feature', '#06b6d4'],
    ['Ad Research Tool', 'Tool interno', 'pausa', 3, '/Users/thomaspc/ad-research', '', 'Tool Python FB Ad Library', 'Attesa Identity Confirmation Meta', '#6366f1'],
    ['Shopify Clone', 'Tool interno', 'attivo', 3, '/Users/thomaspc/shopify-clone', 'http://localhost:8090', 'Clone Shopify PHP+SQLite porta 8090', 'Test funzionalità', '#3b82f6'],
    ['Meme Coin Sniper', 'Trading', 'pausa', 4, '/Users/thomaspc/meme-coin-sniper', '', 'Bot trading meme coin Solana', 'Verificare strategia', '#ec4899'],
  ];
  $stmt = $db->prepare("INSERT INTO projects (name, category, status, priority, path, url, description, next_action, color) VALUES (?,?,?,?,?,?,?,?,?)");
  foreach ($seed as $p) $stmt->execute($p);
}

$rcount = $db->query("SELECT COUNT(*) FROM routines")->fetchColumn();
if ($rcount == 0) {
  $rseed = [
    ['Check ordini Soprhan', '📦', 'daily', '09:00', 'Lavoro'],
    ['Controllo metriche ads', '📊', 'daily', '10:00', 'Lavoro'],
    ['Email & messaggi', '✉️', 'daily', '11:00', 'Lavoro'],
    ['Allenamento', '💪', 'daily', '18:00', 'Salute'],
    ['Lettura', '📚', 'daily', '21:00', 'Personale'],
    ['Revisione settimanale progetti', '🎯', 'weekly', '09:00', 'Lavoro'],
    ['Bilancio mensile', '💰', 'monthly', '', 'Lavoro'],
  ];
  $stmt = $db->prepare("INSERT INTO routines (title, icon, frequency, time, category) VALUES (?,?,?,?,?)");
  foreach ($rseed as $r) $stmt->execute($r);
}

echo "✓ Database creato con successo\n";
echo "✓ Progetti caricati: " . $db->query("SELECT COUNT(*) FROM projects")->fetchColumn() . "\n";
echo "✓ Routine caricate: " . $db->query("SELECT COUNT(*) FROM routines")->fetchColumn() . "\n";
