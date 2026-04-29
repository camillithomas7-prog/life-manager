# Deploy su Hostinger — Guida rapida

## Step 1 — Crea il database MySQL

Pannello Hostinger → **Database → Database MySQL** → "Create".
Annota: nome DB, utente, password (host = `localhost`).

## Step 2 — Carica i file sul server

**Opzione A (consigliata): Git da Hostinger**

Hostinger → **Website → Git** → Connect repository:
- Repository: `https://github.com/camillithomas7-prog/life-manager.git`
- Branch: `main`
- Install path: `/public_html` (o sottocartella, es. `/public_html/life`)

**Opzione B: File Manager / FTP**

Scarica lo ZIP da GitHub → estrai → carica via File Manager nella root del sito (`public_html/`).

## Step 3 — Esegui l'installer

Apri nel browser:

```
https://aquamarine-dogfish-804095.hostingersite.com/install.php
```

Compila il form:
- **Host**: `localhost`
- **Nome DB**: `u749757264_applavoro`
- **Utente DB**: `u749757264_applavoro`
- **Password DB**: la tua password
- **Account admin**: nome, email, password (questo sarà il tuo account)
- **Verifica email automatica**: ✓ tienilo attivo finché non configuri SMTP

Click **Installa**. L'installer:
1. Crea tutte le tabelle MySQL
2. Crea il tuo account admin (verificato)
3. Pre-popola la settimana tipo (palestra 7:30-9, lavoro 9:30-13 + 14-18)
4. Scrive `config.php` (mai versionato in git)

## Step 4 — Sicurezza

Una volta completato, **elimina `install.php`** dal server (File Manager Hostinger → tasto destro → Delete). L'installer si auto-protegge se `config.php` esiste già, ma meglio rimuoverlo.

## Step 5 — Apri l'app

```
https://aquamarine-dogfish-804095.hostingersite.com/
```

Login con l'email/password admin. Dalla home può registrarsi chiunque (con auto-verify attivo possono accedere subito; con auto-verify disattivato ricevono link verifica via mail).

## Step 6 (opzionale) — Dominio custom + SMTP

- **Dominio**: Hostinger → "Domains" → punta un dominio al sito.
- **SMTP**: una volta che hai dominio + email Hostinger configurata, modifica `config.php` e setta `auto_verify_email => false`. L'invio email userà la `mail()` di PHP che funziona out-of-the-box su Hostinger con un dominio reale.
- **HTTPS**: attivato automaticamente da Hostinger su tutti i sottodomini `.hostingersite.com` e dominio custom (Let's Encrypt).

## Aggiornamenti futuri

Se hai connesso Git: pannello Hostinger → Git → "Deploy" per pullare le ultime modifiche dal repo.
Se hai usato File Manager: ricarica i file modificati. **Non toccare `config.php`** (è la tua configurazione locale).

## Troubleshooting

- **"Connection refused"** all'installer → verifica host (su Hostinger è quasi sempre `localhost`), nome DB e password
- **"Access denied"** → l'utente DB non ha permessi sul database; ricreane uno nuovo
- **PWA non installabile su mobile** → serve HTTPS (Hostinger lo dà gratis, attivalo su Domain → SSL)
- **Email verifica non arrivano** → tieni `auto_verify_email => true` finché non hai un dominio reale; `mail()` PHP non funziona dai sottodomini gratuiti `.hostingersite.com`
