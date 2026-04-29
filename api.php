<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function lm_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $f = __DIR__ . '/config.php';
  $cfg = file_exists($f) ? (require $f) : [];
  return $cfg;
}

function lm_db() {
  static $db = null;
  if ($db !== null) return $db;
  $cfg = lm_config();
  $driver = $cfg['db_driver'] ?? 'sqlite';

  if ($driver === 'mysql') {
    $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
    $db = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
  } else {
    if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0755, true);
    $db = new PDO('sqlite:' . __DIR__ . '/data/life.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA foreign_keys = ON");
  }
  return $db;
}

function lm_date_ago_sql($days) {
  $db = lm_db();
  $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
  $days = (int)$days;
  return $driver === 'mysql'
    ? "DATE_SUB(CURDATE(), INTERVAL $days DAY)"
    : "date('now','-$days days')";
}

$db = lm_db();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

function ok($data = []) { echo json_encode(['ok' => true] + (is_array($data) ? $data : ['data' => $data])); exit; }
function err($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

// Helper: ritorna il valore se non vuoto, altrimenti null (no warning per chiave mancante)
function nv($a, $k) { return (isset($a[$k]) && $a[$k] !== '') ? $a[$k] : null; }

function getBearerToken() {
  $h = '';
  if (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
  } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $h = $_SERVER['HTTP_AUTHORIZATION'];
  } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }
  if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
  return $_GET['token'] ?? null;
}

function generateToken() {
  return bin2hex(random_bytes(32));
}

// ============= AUTH (azioni pubbliche) =============
$publicActions = ['register', 'login', 'verify_email', 'resend_verification'];

$USER = null;
$USER_ID = null;

if (!in_array($action, $publicActions)) {
  $token = getBearerToken();
  if (!$token) err('Non autenticato', 401);

  $stmt = $db->prepare("SELECT u.* FROM sessions s JOIN users u ON s.user_id=u.id WHERE s.token=? AND u.active=1");
  $stmt->execute([$token]);
  $USER = $stmt->fetch();
  if (!$USER) err('Sessione non valida', 401);
  if (!$USER['email_verified']) err('Email non verificata', 403);

  $USER_ID = (int)$USER['id'];
  $db->prepare("UPDATE sessions SET last_used=CURRENT_TIMESTAMP WHERE token=?")->execute([$token]);
}

try {
  switch ($action) {

    // ============= REGISTER =============
    case 'register': {
      $email = strtolower(trim($input['email'] ?? ''));
      $pwd = $input['password'] ?? '';
      $name = trim($input['name'] ?? '');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Email non valida');
      if (strlen($pwd) < 6) err('La password deve avere almeno 6 caratteri');

      $exists = $db->prepare("SELECT id FROM users WHERE email=?");
      $exists->execute([$email]);
      if ($exists->fetch()) err('Questa email è già registrata');

      $vtoken = generateToken();
      $cfg = lm_config();
      $autoVerify = !empty($cfg['auto_verify_email']) || file_exists(__DIR__ . '/data/dev_mode');
      $hash = password_hash($pwd, PASSWORD_DEFAULT);
      $stmt = $db->prepare("INSERT INTO users (email, password_hash, name, email_verified, verification_token) VALUES (?,?,?,?,?)");
      $stmt->execute([$email, $hash, $name ?: null, $autoVerify ? 1 : 0, $vtoken]);
      $uid = (int)$db->lastInsertId();

      // Seed iniziale per il nuovo utente: settimana tipo standard
      seedNewUser($db, $uid);

      // Genera link di verifica
      $base = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
      $verifyUrl = $base . '/?verify=' . $vtoken;
      // Log su file (in attesa SMTP)
      file_put_contents(__DIR__ . '/data/verifications.log',
        date('Y-m-d H:i:s') . " - $email - $verifyUrl\n", FILE_APPEND);

      // Se SMTP configurato (env SMTP_HOST), prova a inviare
      $sent = false;
      if (getenv('SMTP_HOST')) {
        $sent = @mail($email, 'Verifica la tua email · Life Manager',
          "Ciao" . ($name ? " $name" : "") . ",\n\nClicca qui per verificare la tua email:\n$verifyUrl\n\nGrazie,\nLife Manager",
          "From: noreply@" . $_SERVER['HTTP_HOST']);
      }

      if ($autoVerify) {
        // Crea sessione subito
        $stoken = generateToken();
        $db->prepare("INSERT INTO sessions (token, user_id, user_agent) VALUES (?,?,?)")
          ->execute([$stoken, $uid, $_SERVER['HTTP_USER_AGENT'] ?? '']);
        ok(['token' => $stoken, 'user' => ['id'=>$uid,'email'=>$email,'name'=>$name,'is_admin'=>0], 'auto_verified' => true]);
      }

      ok(['need_verification' => true, 'email_sent' => $sent, 'verification_url' => $verifyUrl, 'message' => 'Registrazione completata. Verifica la tua email per accedere.']);
    }

    case 'verify_email': {
      $token = $_GET['token'] ?? $input['token'] ?? '';
      if (!$token) err('Token mancante');
      $stmt = $db->prepare("SELECT id FROM users WHERE verification_token=?");
      $stmt->execute([$token]);
      $u = $stmt->fetch();
      if (!$u) err('Token non valido o già usato');
      $db->prepare("UPDATE users SET email_verified=1, verification_token=NULL WHERE id=?")->execute([$u['id']]);
      // crea sessione automaticamente
      $stoken = generateToken();
      $db->prepare("INSERT INTO sessions (token, user_id, user_agent) VALUES (?,?,?)")
        ->execute([$stoken, $u['id'], $_SERVER['HTTP_USER_AGENT'] ?? '']);
      $usr = $db->prepare("SELECT id, email, name, is_admin FROM users WHERE id=?");
      $usr->execute([$u['id']]);
      ok(['token' => $stoken, 'user' => $usr->fetch()]);
    }

    case 'login': {
      $email = strtolower(trim($input['email'] ?? ''));
      $pwd = $input['password'] ?? '';
      if (!$email || !$pwd) err('Email e password richieste');
      $stmt = $db->prepare("SELECT * FROM users WHERE email=? AND active=1");
      $stmt->execute([$email]);
      $u = $stmt->fetch();
      if (!$u || !password_verify($pwd, $u['password_hash'])) err('Email o password errate');
      if (!$u['email_verified']) err('Email non verificata. Controlla la tua casella.');

      $stoken = generateToken();
      $db->prepare("INSERT INTO sessions (token, user_id, user_agent) VALUES (?,?,?)")
        ->execute([$stoken, $u['id'], $_SERVER['HTTP_USER_AGENT'] ?? '']);
      $db->prepare("UPDATE users SET last_login=CURRENT_TIMESTAMP WHERE id=?")->execute([$u['id']]);
      ok(['token' => $stoken, 'user' => ['id'=>$u['id'],'email'=>$u['email'],'name'=>$u['name'],'is_admin'=>(int)$u['is_admin']]]);
    }

    case 'me': {
      ok(['user' => ['id'=>$USER['id'],'email'=>$USER['email'],'name'=>$USER['name'],'is_admin'=>(int)$USER['is_admin']]]);
    }

    case 'logout': {
      $token = getBearerToken();
      if ($token) $db->prepare("DELETE FROM sessions WHERE token=?")->execute([$token]);
      ok();
    }

    case 'update_profile': {
      $name = trim($input['name'] ?? '');
      $db->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name ?: null, $USER_ID]);
      ok();
    }

    case 'change_password': {
      $cur = $input['current'] ?? '';
      $new = $input['new'] ?? '';
      if (strlen($new) < 6) err('La nuova password deve avere almeno 6 caratteri');
      if (!password_verify($cur, $USER['password_hash'])) err('Password attuale errata');
      $db->prepare("UPDATE users SET password_hash=? WHERE id=?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), $USER_ID]);
      ok();
    }

    // ============= ADMIN =============
    case 'admin_users_list': {
      if (!$USER['is_admin']) err('Accesso negato', 403);
      $rows = $db->query("SELECT id, email, name, email_verified, is_admin, active, created_at, last_login FROM users ORDER BY created_at DESC")->fetchAll();
      ok(['data' => $rows]);
    }

    case 'admin_user_toggle_active': {
      if (!$USER['is_admin']) err('Accesso negato', 403);
      $id = (int)$input['id'];
      if ($id === $USER_ID) err('Non puoi disattivare te stesso');
      $db->prepare("UPDATE users SET active = 1 - active WHERE id=?")->execute([$id]);
      ok();
    }

    case 'admin_user_verify': {
      if (!$USER['is_admin']) err('Accesso negato', 403);
      $db->prepare("UPDATE users SET email_verified=1, verification_token=NULL WHERE id=?")->execute([(int)$input['id']]);
      ok();
    }

    // ============= DASHBOARD =============
    case 'dashboard': {
      $today = date('Y-m-d');
      $monthStart = date('Y-m-01');

      $projects_active = (int)$db->prepare("SELECT COUNT(*) FROM projects WHERE archived=0 AND status='attivo' AND user_id=?")->execute([$USER_ID]) ?: 0;
      $r = $db->prepare("SELECT COUNT(*) FROM projects WHERE archived=0 AND status='attivo' AND user_id=?"); $r->execute([$USER_ID]); $projects_active = (int)$r->fetchColumn();
      $r = $db->prepare("SELECT COUNT(*) FROM projects WHERE archived=0 AND user_id=?"); $r->execute([$USER_ID]); $projects_total = (int)$r->fetchColumn();
      $r = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status!='done' AND user_id=? AND (due_date=? OR due_date IS NULL)"); $r->execute([$USER_ID, $today]); $tasks_today = (int)$r->fetchColumn();
      $r = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status!='done' AND user_id=?"); $r->execute([$USER_ID]); $tasks_open = (int)$r->fetchColumn();
      $r = $db->prepare("SELECT COUNT(*) FROM tasks WHERE status!='done' AND user_id=? AND due_date<? AND due_date IS NOT NULL"); $r->execute([$USER_ID, $today]); $tasks_overdue = (int)$r->fetchColumn();

      $r = $db->prepare("SELECT * FROM events WHERE user_id=? AND start_date>=? ORDER BY start_date, start_time LIMIT 5");
      $r->execute([$USER_ID, $today]); $events_upcoming = $r->fetchAll();

      $r = $db->prepare("SELECT t.*, p.name as project_name, p.color FROM tasks t LEFT JOIN projects p ON t.project_id=p.id WHERE t.user_id=? AND t.status!='done' ORDER BY
        CASE WHEN t.scheduled_date=? THEN 0 ELSE 1 END,
        t.scheduled_start ASC,
        CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.priority ASC LIMIT 8");
      $r->execute([$USER_ID, $today]); $top_tasks = $r->fetchAll();

      $r = $db->prepare("SELECT * FROM projects WHERE user_id=? AND archived=0 AND status='attivo' ORDER BY priority ASC, updated_at DESC LIMIT 6");
      $r->execute([$USER_ID]); $top_projects = $r->fetchAll();

      $r = $db->prepare("SELECT routines.*, routine_logs.done as done_today
        FROM routines LEFT JOIN routine_logs ON routine_logs.routine_id=routines.id AND routine_logs.date=?
        WHERE routines.user_id=? AND active=1 ORDER BY
        CASE frequency WHEN 'daily' THEN 1 WHEN 'weekly' THEN 2 ELSE 3 END, time");
      $r->execute([$today, $USER_ID]); $routines = $r->fetchAll();

      $r = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finances WHERE user_id=? AND type='entrata' AND date>=?");
      $r->execute([$USER_ID, $monthStart]); $income = (float)$r->fetchColumn();
      $r = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM finances WHERE user_id=? AND type='uscita' AND date>=?");
      $r->execute([$USER_ID, $monthStart]); $expense = (float)$r->fetchColumn();

      ok([
        'stats' => compact('projects_active','projects_total','tasks_today','tasks_open','tasks_overdue') + ['income_month'=>$income, 'expense_month'=>$expense],
        'top_tasks' => $top_tasks,
        'top_projects' => $top_projects,
        'events_upcoming' => $events_upcoming,
        'routines' => $routines,
      ]);
    }

    // ============= PROJECTS =============
    case 'projects_list': {
      $stmt = $db->prepare("SELECT p.*,
        (SELECT COUNT(*) FROM tasks WHERE project_id=p.id AND user_id=p.user_id AND status!='done') AS open_tasks,
        (SELECT COUNT(*) FROM tasks WHERE project_id=p.id AND user_id=p.user_id AND status='done') AS done_tasks
        FROM projects p WHERE p.user_id=? AND p.archived=0 ORDER BY p.priority ASC, p.name ASC");
      $stmt->execute([$USER_ID]);
      ok(['data' => $stmt->fetchAll()]);
    }

    case 'project_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE projects SET name=?, category=?, status=?, priority=?, path=?, url=?, description=?, next_action=?, revenue=?, cost=?, color=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?");
        $stmt->execute([$input['name'], $input['category']??'', $input['status']??'attivo', $input['priority']??3, $input['path']??'', $input['url']??'', $input['description']??'', $input['next_action']??'', $input['revenue']??0, $input['cost']??0, $input['color']??'#6366f1', $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO projects (user_id, name, category, status, priority, path, url, description, next_action, revenue, cost, color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['name'], $input['category']??'', $input['status']??'attivo', $input['priority']??3, $input['path']??'', $input['url']??'', $input['description']??'', $input['next_action']??'', $input['revenue']??0, $input['cost']??0, $input['color']??'#6366f1']);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'project_archive': {
      $db->prepare("UPDATE projects SET archived=1 WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    case 'project_delete': {
      $db->prepare("DELETE FROM projects WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= TASKS =============
    case 'tasks_list': {
      $filter = $_GET['filter'] ?? 'all';
      $project_id = $_GET['project_id'] ?? null;
      $where = "t.user_id=?";
      $params = [$USER_ID];
      if ($filter === 'open') $where .= " AND t.status!='done'";
      if ($filter === 'today') { $where .= " AND t.status!='done' AND (t.due_date=? OR t.due_date IS NULL)"; $params[] = date('Y-m-d'); }
      if ($filter === 'overdue') { $where .= " AND t.status!='done' AND t.due_date<? AND t.due_date IS NOT NULL"; $params[] = date('Y-m-d'); }
      if ($filter === 'done') $where .= " AND t.status='done'";
      if ($project_id) { $where .= " AND t.project_id=?"; $params[] = $project_id; }

      $stmt = $db->prepare("SELECT t.*, p.name as project_name, p.color FROM tasks t LEFT JOIN projects p ON t.project_id=p.id WHERE $where ORDER BY
        CASE t.status WHEN 'done' THEN 2 ELSE 1 END,
        CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC, t.priority ASC, t.created_at DESC");
      $stmt->execute($params);
      ok(['data' => $stmt->fetchAll()]);
    }

    case 'task_save': {
      $em = (int)($input['estimated_minutes'] ?? 30);
      $sd = !empty($input['scheduled_date']) ? $input['scheduled_date'] : null;
      $ss = !empty($input['scheduled_start']) ? $input['scheduled_start'] : null;
      $se = $input['scheduled_end'] ?? null;
      // Auto-calc scheduled_end se ho data+inizio ma non fine
      if ($sd && $ss && !$se) {
        list($h, $m) = array_map('intval', explode(':', $ss));
        $endMin = $h * 60 + $m + max(15, $em);
        $se = sprintf('%02d:%02d', floor($endMin/60) % 24, $endMin % 60);
      }
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE tasks SET project_id=?, title=?, notes=?, priority=?, status=?, due_date=?, estimated_minutes=?, scheduled_date=?, scheduled_start=?, scheduled_end=? WHERE id=? AND user_id=?");
        $stmt->execute([nv($input,'project_id'), $input['title'], $input['notes']??'', $input['priority']??3, $input['status']??'todo', nv($input,'due_date'), $em, $sd, $ss, $se, $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO tasks (user_id, project_id, title, notes, priority, due_date, estimated_minutes, scheduled_date, scheduled_start, scheduled_end) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, nv($input,'project_id'), $input['title'], $input['notes']??'', $input['priority']??3, nv($input,'due_date'), $em, $sd, $ss, $se]);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'task_toggle': {
      $row = $db->prepare("SELECT status FROM tasks WHERE id=? AND user_id=?");
      $row->execute([$input['id'], $USER_ID]);
      $current = $row->fetchColumn();
      $new = $current === 'done' ? 'todo' : 'done';
      $completed = $new === 'done' ? date('Y-m-d H:i:s') : null;
      $db->prepare("UPDATE tasks SET status=?, completed_at=? WHERE id=? AND user_id=?")->execute([$new, $completed, $input['id'], $USER_ID]);
      ok(['status' => $new]);
    }

    case 'task_delete': {
      $db->prepare("DELETE FROM tasks WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= ROUTINES =============
    case 'routines_list': {
      $today = date('Y-m-d');
      $stmt = $db->prepare("SELECT routines.*, routine_logs.done as done_today
        FROM routines LEFT JOIN routine_logs ON routine_logs.routine_id=routines.id AND routine_logs.date=?
        WHERE routines.user_id=? AND active=1 ORDER BY
        CASE frequency WHEN 'daily' THEN 1 WHEN 'weekly' THEN 2 ELSE 3 END, time");
      $stmt->execute([$today, $USER_ID]);
      $rows = $stmt->fetchAll();
      foreach ($rows as &$r) {
        $ago = lm_date_ago_sql(6);
        $logs = $db->prepare("SELECT date, done FROM routine_logs WHERE routine_id=? AND date>=$ago ORDER BY date");
        $logs->execute([$r['id']]);
        $r['week'] = $logs->fetchAll();
      }
      ok(['data' => $rows]);
    }

    case 'routine_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE routines SET title=?, icon=?, frequency=?, time=?, category=?, active=? WHERE id=? AND user_id=?");
        $stmt->execute([$input['title'], $input['icon']??'✓', $input['frequency']??'daily', $input['time']??'', $input['category']??'', $input['active']??1, $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO routines (user_id, title, icon, frequency, time, category) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['title'], $input['icon']??'✓', $input['frequency']??'daily', $input['time']??'', $input['category']??'']);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'routine_check': {
      $today = date('Y-m-d');
      // verifica appartenenza
      $own = $db->prepare("SELECT id FROM routines WHERE id=? AND user_id=?");
      $own->execute([$input['id'], $USER_ID]);
      if (!$own->fetch()) err('Routine non trovata');

      $exists = $db->prepare("SELECT done FROM routine_logs WHERE routine_id=? AND date=?");
      $exists->execute([$input['id'], $today]);
      $cur = $exists->fetchColumn();
      if ($cur === false) {
        $db->prepare("INSERT INTO routine_logs (routine_id, date, done, user_id) VALUES (?,?,1,?)")->execute([$input['id'], $today, $USER_ID]);
      } else {
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE routine_logs SET done=? WHERE routine_id=? AND date=?")->execute([$new, $input['id'], $today]);
      }
      ok();
    }

    case 'routine_delete': {
      $db->prepare("DELETE FROM routines WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= EVENTS =============
    case 'events_list': {
      $month = $_GET['month'] ?? date('Y-m');
      $start = $month . '-01';
      $end = date('Y-m-t', strtotime($start));
      $stmt = $db->prepare("SELECT e.*, p.name as project_name, p.color FROM events e LEFT JOIN projects p ON e.project_id=p.id WHERE e.user_id=? AND e.start_date BETWEEN ? AND ? ORDER BY e.start_date, e.start_time");
      $stmt->execute([$USER_ID, $start, $end]);
      ok(['data' => $stmt->fetchAll()]);
    }

    case 'event_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE events SET title=?, description=?, start_date=?, start_time=?, end_date=?, end_time=?, category=?, project_id=? WHERE id=? AND user_id=?");
        $stmt->execute([$input['title'], $input['description']??'', $input['start_date'], $input['start_time']??null, nv($input,'end_date'), nv($input,'end_time'), $input['category']??'', nv($input,'project_id'), $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO events (user_id, title, description, start_date, start_time, end_date, end_time, category, project_id) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['title'], $input['description']??'', $input['start_date'], $input['start_time']??null, nv($input,'end_date'), nv($input,'end_time'), $input['category']??'', nv($input,'project_id')]);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'event_delete': {
      $db->prepare("DELETE FROM events WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= NOTES =============
    case 'notes_list': {
      $stmt = $db->prepare("SELECT * FROM notes WHERE user_id=? ORDER BY pinned DESC, updated_at DESC");
      $stmt->execute([$USER_ID]);
      ok(['data' => $stmt->fetchAll()]);
    }

    case 'note_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE notes SET title=?, content=?, tag=?, pinned=?, updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?");
        $stmt->execute([$input['title'], $input['content']??'', $input['tag']??'', $input['pinned']??0, $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO notes (user_id, title, content, tag, pinned) VALUES (?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['title'], $input['content']??'', $input['tag']??'', $input['pinned']??0]);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'note_delete': {
      $db->prepare("DELETE FROM notes WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= GOALS =============
    case 'goals_list': {
      $stmt = $db->prepare("SELECT * FROM goals WHERE user_id=? ORDER BY status, deadline ASC");
      $stmt->execute([$USER_ID]);
      ok(['data' => $stmt->fetchAll()]);
    }

    case 'goal_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE goals SET title=?, description=?, target_value=?, current_value=?, unit=?, deadline=?, category=?, status=? WHERE id=? AND user_id=?");
        $stmt->execute([$input['title'], $input['description']??'', $input['target_value']??0, $input['current_value']??0, $input['unit']??'', nv($input,'deadline'), $input['category']??'', $input['status']??'attivo', $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO goals (user_id, title, description, target_value, current_value, unit, deadline, category) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['title'], $input['description']??'', $input['target_value']??0, $input['current_value']??0, $input['unit']??'', nv($input,'deadline'), $input['category']??'']);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'goal_delete': {
      $db->prepare("DELETE FROM goals WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= FINANCES =============
    case 'finances_list': {
      $month = $_GET['month'] ?? date('Y-m');
      $start = $month . '-01';
      $end = date('Y-m-t', strtotime($start));
      $stmt = $db->prepare("SELECT f.*, p.name as project_name FROM finances f LEFT JOIN projects p ON f.project_id=p.id WHERE f.user_id=? AND date BETWEEN ? AND ? ORDER BY date DESC");
      $stmt->execute([$USER_ID, $start, $end]);
      $rows = $stmt->fetchAll();
      $income = 0; $expense = 0;
      foreach ($rows as $r) { if ($r['type']==='entrata') $income += $r['amount']; else $expense += $r['amount']; }
      ok(['data' => $rows, 'totals' => ['income' => $income, 'expense' => $expense, 'balance' => $income-$expense]]);
    }

    case 'finance_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE finances SET type=?, amount=?, description=?, category=?, project_id=?, date=? WHERE id=? AND user_id=?");
        $stmt->execute([$input['type'], $input['amount'], $input['description']??'', $input['category']??'', nv($input,'project_id'), $input['date'], $input['id'], $USER_ID]);
        ok();
      } else {
        $stmt = $db->prepare("INSERT INTO finances (user_id, type, amount, description, category, project_id, date) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['type'], $input['amount'], $input['description']??'', $input['category']??'', nv($input,'project_id'), $input['date']]);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'finance_delete': {
      $db->prepare("DELETE FROM finances WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    // ============= SCHEDULE BLOCKS =============
    case 'blocks_list': {
      $stmt = $db->prepare("SELECT * FROM schedule_blocks WHERE user_id=? AND active=1 ORDER BY weekday, start_time");
      $stmt->execute([$USER_ID]);
      $rows = $stmt->fetchAll();
      $byDay = [[], [], [], [], [], [], []];
      foreach ($rows as $r) $byDay[$r['weekday']][] = $r;
      ok(['data' => $byDay]);
    }

    case 'block_save': {
      if (!empty($input['id'])) {
        $stmt = $db->prepare("UPDATE schedule_blocks SET weekday=?, start_time=?, end_time=?, type=?, label=?, color=?, notes=? WHERE id=? AND user_id=?");
        $stmt->execute([$input['weekday'], $input['start_time'], $input['end_time'], $input['type'], $input['label'], $input['color']??'#6366f1', $input['notes']??'', $input['id'], $USER_ID]);
        ok(['id' => $input['id']]);
      } else {
        $stmt = $db->prepare("INSERT INTO schedule_blocks (user_id, weekday, start_time, end_time, type, label, color, notes) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$USER_ID, $input['weekday'], $input['start_time'], $input['end_time'], $input['type'], $input['label'], $input['color']??'#6366f1', $input['notes']??'']);
        ok(['id' => $db->lastInsertId()]);
      }
    }

    case 'block_delete': {
      $db->prepare("DELETE FROM schedule_blocks WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    case 'blocks_reset_default': {
      $db->prepare("DELETE FROM schedule_blocks WHERE user_id=?")->execute([$USER_ID]);
      seedScheduleOnly($db, $USER_ID);
      ok(['message' => 'Programma reimpostato']);
    }

    case 'blocks_copy_day': {
      $src = (int)$input['from'];
      $dst = (int)$input['to'];
      $db->prepare("DELETE FROM schedule_blocks WHERE weekday=? AND user_id=?")->execute([$dst, $USER_ID]);
      $rows = $db->prepare("SELECT * FROM schedule_blocks WHERE weekday=? AND user_id=?");
      $rows->execute([$src, $USER_ID]);
      $stmt = $db->prepare("INSERT INTO schedule_blocks (user_id, weekday, start_time, end_time, type, label, color, notes) VALUES (?,?,?,?,?,?,?,?)");
      foreach ($rows->fetchAll() as $b) {
        $stmt->execute([$USER_ID, $dst, $b['start_time'], $b['end_time'], $b['type'], $b['label'], $b['color'], $b['notes']]);
      }
      ok();
    }

    // ============= TODAY PLAN =============
    case 'today_plan': {
      $date = $_GET['date'] ?? date('Y-m-d');
      $weekday = (int)date('N', strtotime($date)) - 1;

      $bstmt = $db->prepare("SELECT * FROM schedule_blocks WHERE user_id=? AND weekday=? AND active=1 ORDER BY start_time");
      $bstmt->execute([$USER_ID, $weekday]);
      $blocks = $bstmt->fetchAll();

      $tstmt = $db->prepare("SELECT t.*, p.name as project_name, p.color as project_color FROM tasks t LEFT JOIN projects p ON t.project_id=p.id WHERE t.user_id=? AND t.scheduled_date=? AND t.status!='done' ORDER BY t.scheduled_start");
      $tstmt->execute([$USER_ID, $date]);
      $scheduled = $tstmt->fetchAll();

      $cstmt = $db->prepare("SELECT t.*, p.name as project_name, p.color as project_color FROM tasks t LEFT JOIN projects p ON t.project_id=p.id WHERE t.user_id=? AND t.scheduled_date=? AND t.status='done' ORDER BY t.scheduled_start");
      $cstmt->execute([$USER_ID, $date]);
      $completed = $cstmt->fetchAll();

      $rstmt = $db->prepare("SELECT routines.*, routine_logs.done as done_today FROM routines LEFT JOIN routine_logs ON routine_logs.routine_id=routines.id AND routine_logs.date=? WHERE routines.user_id=? AND active=1 AND (frequency='daily' OR (frequency='weekly' AND ?=0)) ORDER BY time");
      $rstmt->execute([$date, $USER_ID, $weekday]);
      $routines = $rstmt->fetchAll();

      $work_minutes = 0;
      foreach ($blocks as $b) if ($b['type'] === 'lavoro') $work_minutes += blockDuration($b['start_time'], $b['end_time']);
      $scheduled_minutes = 0;
      foreach ($scheduled as $t) $scheduled_minutes += (int)$t['estimated_minutes'];

      ok([
        'date' => $date, 'weekday' => $weekday,
        'blocks' => $blocks,
        'scheduled_tasks' => $scheduled,
        'completed_tasks' => $completed,
        'routines' => $routines,
        'work_minutes' => $work_minutes,
        'scheduled_minutes' => $scheduled_minutes,
      ]);
    }

    case 'plan_day': {
      $date = $input['date'] ?? date('Y-m-d');
      $weekday = (int)date('N', strtotime($date)) - 1;
      $force = !empty($input['force']);

      $bstmt = $db->prepare("SELECT * FROM schedule_blocks WHERE user_id=? AND weekday=? AND active=1 AND type='lavoro' ORDER BY start_time");
      $bstmt->execute([$USER_ID, $weekday]);
      $work_blocks = $bstmt->fetchAll();

      if (empty($work_blocks)) ok(['scheduled' => 0, 'message' => 'Nessun blocco lavoro per ' . $date]);

      if ($force) {
        $db->prepare("UPDATE tasks SET scheduled_date=NULL, scheduled_start=NULL, scheduled_end=NULL WHERE user_id=? AND scheduled_date=? AND status!='done'")->execute([$USER_ID, $date]);
      }

      $tstmt = $db->prepare("SELECT * FROM tasks WHERE user_id=? AND status!='done' AND (scheduled_date IS NULL OR scheduled_date>=?) ORDER BY priority ASC, CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date ASC, id ASC");
      $tstmt->execute([$USER_ID, $date]);
      $pending = $tstmt->fetchAll();
      $pending = array_values(array_filter($pending, fn($t) => empty($t['scheduled_date']) || $t['scheduled_date'] === $date));

      $count = 0;
      $update = $db->prepare("UPDATE tasks SET scheduled_date=?, scheduled_start=?, scheduled_end=? WHERE id=? AND user_id=?");
      foreach ($work_blocks as $block) {
        $cursor_min = timeToMin($block['start_time']);
        $end_min = timeToMin($block['end_time']);
        $available = $end_min - $cursor_min;
        foreach ($pending as $idx => $t) {
          if ($t === null) continue;
          $dur = max(15, (int)$t['estimated_minutes']);
          if ($dur <= $available) {
            $update->execute([$date, minToTime($cursor_min), minToTime($cursor_min + $dur), $t['id'], $USER_ID]);
            $cursor_min += $dur;
            $available -= $dur;
            $pending[$idx] = null;
            $count++;
          }
        }
      }
      $unsched = count(array_filter($pending));
      ok(['scheduled' => $count, 'unscheduled' => $unsched, 'message' => "Pianificate $count attività" . ($unsched ? ", $unsched non sono entrate" : "")]);
    }

    case 'task_unschedule': {
      $db->prepare("UPDATE tasks SET scheduled_date=NULL, scheduled_start=NULL, scheduled_end=NULL WHERE id=? AND user_id=?")->execute([$input['id'], $USER_ID]);
      ok();
    }

    case 'clear_day_plan': {
      $date = $input['date'] ?? date('Y-m-d');
      $db->prepare("UPDATE tasks SET scheduled_date=NULL, scheduled_start=NULL, scheduled_end=NULL WHERE user_id=? AND scheduled_date=? AND status!='done'")->execute([$USER_ID, $date]);
      ok();
    }

    default:
      err('Azione non riconosciuta: ' . $action);
  }
} catch (Exception $e) {
  err($e->getMessage());
}

// ============= HELPER FUNCTIONS =============
function timeToMin($t) {
  if (!$t) return 0;
  list($h, $m) = explode(':', $t);
  return (int)$h * 60 + (int)$m;
}
function minToTime($m) { return sprintf('%02d:%02d', floor($m / 60) % 24, $m % 60); }
function blockDuration($s, $e) {
  $sm = timeToMin($s); $em = timeToMin($e);
  if ($em <= $sm) $em += 1440;
  return $em - $sm;
}

function seedScheduleOnly($db, $uid) {
  // Settimana tipo Thomas-style: palestra 7:30-9, lavoro 9:30-13 + 14-18
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
}

function seedNewUser($db, $uid) {
  seedScheduleOnly($db, $uid);

  // Routine di default
  $rstmt = $db->prepare("INSERT INTO routines (user_id, title, icon, frequency, time, category) VALUES (?,?,?,?,?,?)");
  $rstmt->execute([$uid, 'Check ordini',          '📦', 'daily',  '09:00', 'Lavoro']);
  $rstmt->execute([$uid, 'Controllo metriche ads','📊', 'daily',  '10:00', 'Lavoro']);
  $rstmt->execute([$uid, 'Email & messaggi',      '✉️', 'daily',  '11:00', 'Lavoro']);
  $rstmt->execute([$uid, 'Allenamento',           '💪', 'daily',  '07:30', 'Salute']);
  $rstmt->execute([$uid, 'Lettura',               '📚', 'daily',  '21:00', 'Personale']);
  $rstmt->execute([$uid, 'Revisione settimanale', '🎯', 'weekly', '09:00', 'Lavoro']);
}
