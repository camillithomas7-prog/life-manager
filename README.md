## Live

🌐 https://aquamarine-dogfish-804095.hostingersite.com (auto-deploy attivo via webhook GitHub → Hostinger)

# Life Manager

PWA multi-utente per organizzare progetti, attività, ritmi giornalieri e finanze.
Stack: PHP + SQLite (locale) o MySQL (hosting).

## Funzionalità

- **Oggi**: timeline cronologica con blocchi orari + task auto-pianificate nei blocchi lavoro
- **Ritmi**: settimana tipo (sonno / sport / pasto / lavoro / personale / libero)
- **Attività**: con durata stimata + auto-scheduler greedy che le distribuisce nei blocchi lavoro
- **Progetti** (con stato, priorità, prossima azione, link)
- **Routine** (giornaliere/settimanali con tracking 7 giorni)
- **Calendario / Obiettivi / Bilancio / Note**
- **Auth multi-utente**: registrazione email + password, sessione persistente in localStorage
- **Pannello admin**: gestione utenti
- **PWA installabile** su iOS/Android (Add to Home Screen → fullscreen)

## Avvio locale

```bash
php setup.php          # crea data/life.db e seed iniziale
php migrate.php        # aggiunge tabelle ritmi/blocchi
php migrate_auth.php   # aggiunge users + sessions + user_id
php generate_icons.php # genera icone PWA
./start.sh             # server su localhost:8095 (e IP locale per mobile)
```

Login admin di default (cambialo subito): `samuelehk@gmail.com` / `thomas2026`.

## Deploy su Hostinger (MySQL)

Vedere [`DEPLOY_HOSTINGER.md`](DEPLOY_HOSTINGER.md) per lo schema MySQL pronto e le istruzioni.

## Struttura

```
life-manager/
├── index.html          # SPA shell (auth screen + app)
├── api.php             # API + auth wrapper (filtri user_id)
├── manifest.json       # PWA manifest
├── sw.js               # Service worker (cache offline)
├── setup.php           # Schema iniziale + seed
├── migrate.php         # Migrazione blocchi orari
├── migrate_auth.php    # Migrazione users/sessions
├── generate_icons.php  # Generatore icone PWA
├── start.sh            # Avvio dev locale
├── data/               # SQLite + log (escluso da git)
└── assets/
    ├── style.css       # Tema chiaro mobile-first
    ├── app.js          # SPA logic, viste, modali
    └── icon-*.png      # Icone PWA
```
