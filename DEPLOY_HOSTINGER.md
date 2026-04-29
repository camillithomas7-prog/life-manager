# Deploy su Hostinger

## 1. Carica i file

Carica tutto il contenuto di questo repo (escluso `data/`) nella cartella `public_html/` o in una sottocartella (es. `public_html/life/`).

## 2. Crea il database MySQL su Hostinger

Pannello Hostinger → **Database → Database MySQL** → crea database e utente. Annota:
- DB name (es. `u123_lifemanager`)
- DB user (es. `u123_lifeadmin`)
- DB password
- DB host (di solito `localhost` o `127.0.0.1`)

## 3. Importa lo schema

Hostinger → **phpMyAdmin** → seleziona il DB appena creato → tab **SQL** → incolla:

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120),
  email_verified TINYINT DEFAULT 0,
  verification_token VARCHAR(120),
  reset_token VARCHAR(120),
  reset_expires DATETIME,
  is_admin TINYINT DEFAULT 0,
  active TINYINT DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login DATETIME
);

CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(80) PRIMARY KEY,
  user_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME DEFAULT CURRENT_TIMESTAMP,
  user_agent VARCHAR(500),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS projects (
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
);

CREATE TABLE IF NOT EXISTS tasks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  project_id INT,
  title VARCHAR(255) NOT NULL,
  notes TEXT,
  priority INT DEFAULT 3,
  status VARCHAR(20) DEFAULT 'todo',
  due_date DATE,
  completed_at DATETIME,
  estimated_minutes INT DEFAULT 30,
  scheduled_date DATE,
  scheduled_start VARCHAR(10),
  scheduled_end VARCHAR(10),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
);

CREATE TABLE IF NOT EXISTS routines (
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
);

CREATE TABLE IF NOT EXISTS routine_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  routine_id INT NOT NULL,
  user_id INT,
  date DATE NOT NULL,
  done TINYINT DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_routine_date (routine_id, date),
  FOREIGN KEY(routine_id) REFERENCES routines(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  start_date DATE NOT NULL,
  start_time VARCHAR(10),
  end_date DATE,
  end_time VARCHAR(10),
  category VARCHAR(80),
  project_id INT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
);

CREATE TABLE IF NOT EXISTS notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  content TEXT,
  tag VARCHAR(80),
  pinned TINYINT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
);

CREATE TABLE IF NOT EXISTS goals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  target_value DECIMAL(14,2),
  current_value DECIMAL(14,2) DEFAULT 0,
  unit VARCHAR(40),
  deadline DATE,
  category VARCHAR(80),
  status VARCHAR(20) DEFAULT 'attivo',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
);

CREATE TABLE IF NOT EXISTS finances (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type VARCHAR(20) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  description VARCHAR(500),
  category VARCHAR(80),
  project_id INT,
  date DATE NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
);

CREATE TABLE IF NOT EXISTS schedule_blocks (
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
);
```

## 4. Configura le credenziali

Crea il file `config.php` nella root del progetto sul server (non versionato in git):

```php
<?php
return [
  'db_driver' => 'mysql',
  'db_host'   => 'localhost',     // o quello fornito da Hostinger
  'db_name'   => 'u123_lifemanager',
  'db_user'   => 'u123_lifeadmin',
  'db_pass'   => 'LA_PASSWORD',
  'smtp_host' => 'smtp.hostinger.com',
  'smtp_user' => 'noreply@tuodominio.it',
  'smtp_pass' => 'PASSWORD_EMAIL',
  'app_url'   => 'https://tuodominio.it',
];
```

## 5. Modifica `api.php` per usare MySQL

Sostituisci la riga di connessione PDO all'inizio di `api.php`:

```php
// Prima (SQLite locale):
$db = new PDO('sqlite:' . __DIR__ . '/data/life.db');

// Dopo (MySQL hosting):
$cfg = require __DIR__ . '/config.php';
$db = new PDO(
  "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4",
  $cfg['db_user'],
  $cfg['db_pass']
);
```

## 6. Crea l'utente admin

Una volta caricato tutto, registrati dal sito e poi su phpMyAdmin esegui:

```sql
UPDATE users SET is_admin=1, email_verified=1 WHERE email='tua@email.it';
```

## 7. Imposta SMTP per le email di verifica

In `api.php`, l'invio email usa `mail()` se `getenv('SMTP_HOST')` è settato. Su Hostinger basta che il dominio abbia un account email — la funzione `mail()` di PHP funziona out-of-the-box.

## 8. HTTPS obbligatorio

Per il PWA installabile (service worker) serve HTTPS. Hostinger fornisce certificato SSL gratuito Let's Encrypt: pannello → **SSL** → attivalo.

---

Una volta in produzione, ogni utente che si registra:
1. Riceve email di verifica
2. Clicca il link → `email_verified=1`
3. Login → token Bearer salvato nel browser → mai più password
4. Aggiunge l'app alla home → app fullscreen
