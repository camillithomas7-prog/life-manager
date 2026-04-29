<?php
$db = new PDO('sqlite:' . __DIR__ . '/data/life.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Tabella blocchi settimanali (template ricorrente Lun-Dom)
$db->exec("CREATE TABLE IF NOT EXISTS schedule_blocks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  weekday INTEGER NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  type TEXT NOT NULL,
  label TEXT NOT NULL,
  color TEXT DEFAULT '#6366f1',
  notes TEXT,
  active INTEGER DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Aggiunta colonne ai task (idempotente)
function addCol($db, $table, $col, $type) {
  $cols = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
  $names = array_column($cols, 'name');
  if (!in_array($col, $names)) {
    $db->exec("ALTER TABLE $table ADD COLUMN $col $type");
    echo "+ $table.$col\n";
  }
}

addCol($db, 'tasks', 'estimated_minutes', 'INTEGER DEFAULT 30');
addCol($db, 'tasks', 'scheduled_date', 'DATE');
addCol($db, 'tasks', 'scheduled_start', 'TEXT');
addCol($db, 'tasks', 'scheduled_end', 'TEXT');

// Seed settimana tipo se vuota
$count = $db->query("SELECT COUNT(*) FROM schedule_blocks")->fetchColumn();
if ($count == 0) {
  // Tipi: sonno, sport, pasto, lavoro, personale, libero
  // Colori coerenti col tema chiaro
  $C = [
    'sonno' => '#94a3b8',
    'sport' => '#f59e0b',
    'pasto' => '#10b981',
    'lavoro' => '#4f46e5',
    'personale' => '#8b5cf6',
    'libero' => '#06b6d4',
  ];

  // Lun-Ven: giornata lavorativa (weekday 0-4)
  $weekday_blocks = [
    ['00:00','07:00','sonno','Sonno', $C['sonno']],
    ['07:00','07:30','personale','Sveglia & colazione', $C['personale']],
    ['07:30','09:00','sport','Palestra', $C['sport']],
    ['09:00','09:30','personale','Doccia & preparazione', $C['personale']],
    ['09:30','13:00','lavoro','Lavoro mattina', $C['lavoro']],
    ['13:00','14:00','pasto','Pranzo', $C['pasto']],
    ['14:00','18:00','lavoro','Lavoro pomeriggio', $C['lavoro']],
    ['18:00','19:30','personale','Tempo libero', $C['personale']],
    ['19:30','20:30','pasto','Cena', $C['pasto']],
    ['20:30','23:00','libero','Sera libera', $C['libero']],
    ['23:00','24:00','sonno','Sonno', $C['sonno']],
  ];

  // Sab-Dom: weekend (5-6)
  $weekend_blocks = [
    ['00:00','08:00','sonno','Sonno', $C['sonno']],
    ['08:00','09:00','personale','Sveglia & colazione', $C['personale']],
    ['09:00','11:00','sport','Sport / Outdoor', $C['sport']],
    ['11:00','13:00','personale','Tempo libero', $C['personale']],
    ['13:00','14:30','pasto','Pranzo', $C['pasto']],
    ['14:30','17:00','libero','Riposo / Hobby', $C['libero']],
    ['17:00','19:30','personale','Tempo libero', $C['personale']],
    ['19:30','21:00','pasto','Cena', $C['pasto']],
    ['21:00','24:00','libero','Sera libera', $C['libero']],
  ];

  $stmt = $db->prepare("INSERT INTO schedule_blocks (weekday, start_time, end_time, type, label, color) VALUES (?,?,?,?,?,?)");

  for ($wd = 0; $wd <= 4; $wd++) {
    foreach ($weekday_blocks as $b) $stmt->execute([$wd, $b[0], $b[1], $b[2], $b[3], $b[4]]);
  }
  for ($wd = 5; $wd <= 6; $wd++) {
    foreach ($weekend_blocks as $b) $stmt->execute([$wd, $b[0], $b[1], $b[2], $b[3], $b[4]]);
  }

  echo "+ Settimana tipo seedata: " . $db->query("SELECT COUNT(*) FROM schedule_blocks")->fetchColumn() . " blocchi\n";
}

// Aggiorna durata stimata per task esistenti senza valore
$db->exec("UPDATE tasks SET estimated_minutes = 30 WHERE estimated_minutes IS NULL OR estimated_minutes = 0");

echo "✓ Migrazione completata\n";
echo "  Blocchi settimanali: " . $db->query("SELECT COUNT(*) FROM schedule_blocks")->fetchColumn() . "\n";
echo "  Task: " . $db->query("SELECT COUNT(*) FROM tasks")->fetchColumn() . "\n";
