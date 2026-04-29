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

## Auto-deploy GitHub → Hostinger (push automatico)

L'installer ha già generato un `webhook_secret` e configurato `deploy.php` come endpoint webhook.
Devi solo collegarli su GitHub:

### 1. Recupera il secret
Lo trovi al termine dell'installazione (schermo finale di `install.php`) oppure dentro `config.php` sul server:
```php
'webhook_secret' => 'xxxxxxxx...',
```

### 2. Aggiungi il webhook su GitHub
Vai su: **https://github.com/camillithomas7-prog/life-manager/settings/hooks/new**

Compila:
| Campo | Valore |
|---|---|
| **Payload URL** | `https://aquamarine-dogfish-804095.hostingersite.com/deploy.php` |
| **Content type** | `application/json` |
| **Secret** | il valore di `webhook_secret` |
| **SSL verification** | enable |
| **Which events?** | Just the push event |
| **Active** | ✓ |

Click **Add webhook**.

### 3. Verifica il ping
GitHub invia un evento `ping` automaticamente. Vai su Settings → Webhooks → click sul webhook → tab **Recent Deliveries**: deve essere ✓ verde con response `pong`.

Da ora in poi, ogni `git push` su `main`:
1. GitHub manda webhook a `deploy.php`
2. `deploy.php` verifica firma HMAC
3. Esegue `git pull` sulla cartella del sito
4. Logga in `data/deploy.log`

### Test manuale
Modifica un file qualsiasi in locale, poi:
```bash
git add . && git commit -m "test deploy" && git push
```
Entro pochi secondi il sito è aggiornato. Verifica su Hostinger File Manager → `data/deploy.log`.

### Troubleshooting auto-deploy
- **GitHub mostra 401** → secret nel webhook diverso da `config.php`
- **GitHub mostra 503** → `config.php` mancante o `webhook_secret` vuoto
- **Webhook risponde "shell_exec disabled"** → contatta supporto Hostinger per abilitarlo, oppure usa il deploy manuale (Hostinger → Git → Deploy)
- **`git pull` fallisce con "permission denied"** → la cartella del sito non è un repo git. Devi clonarla con il Git integration di Hostinger, non caricarla via File Manager.

## Aggiornamenti manuali (alternativa)

Se preferisci controllare quando aggiornare:
- **Con Git integration**: pannello Hostinger → Git → click su "Deploy" quando vuoi pullare
- **Con File Manager**: ricarica i file modificati a mano. **Non toccare `config.php`**.

## Troubleshooting

- **"Connection refused"** all'installer → verifica host (su Hostinger è quasi sempre `localhost`), nome DB e password
- **"Access denied"** → l'utente DB non ha permessi sul database; ricreane uno nuovo
- **PWA non installabile su mobile** → serve HTTPS (Hostinger lo dà gratis, attivalo su Domain → SSL)
- **Email verifica non arrivano** → tieni `auto_verify_email => true` finché non hai un dominio reale; `mail()` PHP non funziona dai sottodomini gratuiti `.hostingersite.com`
