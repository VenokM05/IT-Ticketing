# Local WhatsApp webhook tester

`Send-WhatsAppWebhook.ps1` posts a correctly **HMAC-SHA256-signed** payload to the
local endpoint `POST /api/whatsapp/webhook`, exactly like Meta's WhatsApp Cloud API
does. Use it to exercise the inbound intake pipeline (event recording, employee
lookup, ticket creation, and the `CLOSE` command) **without** ngrok or a real Meta app.

## Prerequisites (one-time)

1. **Set a signing secret.** The app rejects every payload when the secret is empty
   (HTTP 401), so give `.env` a non-empty value — it just has to match what this
   script signs with:
   ```ini
   # app\.env
   WHATSAPP_APP_SECRET=local-dev-secret
   ```
   Then: `php artisan config:clear`
2. **Start the server:** `php artisan serve`  (default `http://127.0.0.1:8000`).
3. **A queue must process the job.** Intake runs through the queued
   `ProcessWhatsAppWebhook` job and `QUEUE_CONNECTION=database`, so either run a
   worker in a second terminal:
   ```powershell
   php artisan queue:work
   ```
   — or make it inline for the smoke test: set `QUEUE_CONNECTION=sync`, run
   `php artisan config:clear`, and restart `artisan serve`.
4. **A registered sender is required.** Inbound only creates a ticket for an *active*
   `Employee` whose number matches. The seed data (`php artisan migrate:fresh --seed`)
   provides these numbers; the script auto-selects the first one.
5. `WHATSAPP_ENABLED` can stay **false** — that flag only gates *outbound* sends to
   Meta. Inbound intake works regardless.

## Usage

```powershell
# Open a new ticket from the first active employee (default body):
.\scripts\Send-WhatsAppWebhook.ps1

# A custom new-ticket message:
.\scripts\Send-WhatsAppWebhook.ps1 -Body "NEW TICKET Projector in room 3 has no signal"

# Close that sender's most recent ticket (sends "CLOSE <ticket-number>"):
.\scripts\Send-WhatsAppWebhook.ps1 -CloseLatest

# Impersonate a specific number / id:
.\scripts\Send-WhatsAppWebhook.ps1 -From 639171234501 -Wamid wamid.MANUAL1
```

Expected happy path prints `=> HTTP 200 : {"status":"received"}`, and (once the queue
runs) a new `New` ticket appears on the admin dashboard, or the targeted ticket flips
to `Closed`.

## How the signature works

The controller computes `hash_hmac('sha256', <raw body>, WHATSAPP_APP_SECRET)` and
compares it (via `hash_equals`) to the `X-Hub-Signature-256: sha256=<hex>` header.
The script therefore signs the **exact UTF-8 bytes** it sends, so verification passes.

| Response | Meaning |
| --- | --- |
| `200` | Signature valid, event accepted (processing continues on the queue). |
| `401` | Signature mismatch — secret differs from the server, or config was cached. Re-run `php artisan config:clear`. |
| `422` | Body isn't a `whatsapp_business_account` event. |

## Notes / gotchas

- **Idempotency:** events are de-duplicated by message id, so re-sending the same
  `-Wamid` creates nothing new. Each run defaults to a fresh random id — pass your own
  to demo the duplicate-suppression path.
- **Ticket number format** is `IT-YYYY-NNNNNN`; `CLOSE` must reference the sender's
  own existing ticket.
- This is the **same endpoint** Meta will call once you expose it: point your
  `ngrok`/Cloudflare URL at `/api/whatsapp/webhook`, set `WHATSAPP_VERIFY_TOKEN`, and
  subscribe with the matching app secret.
