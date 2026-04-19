# Webhooks

Outbound webhooks let Simple PHP IPAM send HTTP POST notifications to external systems whenever an address or subnet is created, updated, or deleted. Payloads are signed with HMAC-SHA256 so receivers can verify authenticity.

---

## Events

| Event | Fired when |
|-------|-----------|
| `address.create` | A new address is added to a subnet |
| `address.update` | An address field is changed |
| `address.delete` | An address is deleted |
| `subnet.create` | A new subnet is created |
| `subnet.update` | A subnet is edited |
| `subnet.delete` | A subnet is deleted |

---

## Payload format

Every delivery is an HTTP POST with `Content-Type: application/json`:

```json
{
  "event": "address.create",
  "timestamp": "2026-01-01T12:00:00Z",
  "version": "3.3.0",
  "actor": {
    "user_id": 1,
    "username": "admin"
  },
  "data": {
    "id": 42,
    "ip": "10.0.0.10",
    "subnet_id": 3
  }
}
```

`timestamp` is always UTC ISO 8601. `data` contains the key fields for the mutated entity.

---

## Request headers

| Header | Example value |
|--------|--------------|
| `Content-Type` | `application/json` |
| `X-IPAM-Signature` | `sha256=a1b2c3…` |
| `X-IPAM-Event` | `address.create` |
| `User-Agent` | `SimpleIPAM/3.3.0` |

---

## Verifying the signature

The `X-IPAM-Signature` header contains `sha256=` followed by the HMAC-SHA256 hex digest of the raw request body, signed with your webhook secret.

**PHP:**
```php
$sig = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (!hash_equals($sig, $_SERVER['HTTP_X_IPAM_SIGNATURE'])) {
    http_response_code(403);
    exit;
}
```

**Python:**
```python
import hmac, hashlib

sig = 'sha256=' + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
if not hmac.compare_digest(sig, request.headers['X-IPAM-Signature']):
    abort(403)
```

**bash / curl (for testing):**
```bash
SECRET="your-secret"
BODY='{"event":"test.ping",...}'
EXPECTED="sha256=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')"
```

Always use a constant-time comparison (`hash_equals` / `hmac.compare_digest`) to prevent timing attacks.

---

## Security

### SSRF protection

Webhook URLs are validated before each delivery. Private, loopback, link-local, and ULA IP ranges are blocked by default:

- `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`
- `127.0.0.0/8`, `169.254.0.0/16`
- `::1/128`, `fc00::/7`, `fe80::/10`

To allow private-IP targets (e.g. internal Slack-compatible endpoints in a lab), set `webhook.allow_private_ips = true` in Settings → Webhooks.

### Secret rotation

Generate a new secret in the webhook edit form (click **Generate**). Update your receiver before saving the new secret — old deliveries will have a different signature and will fail verification on the new secret.

### Timestamp validation (recommended)

Replay attacks can be mitigated by rejecting payloads with a `timestamp` older than 5 minutes:

```php
$body = json_decode($rawBody, true);
if (abs(time() - strtotime($body['timestamp'])) > 300) {
    http_response_code(400);
    exit('Stale payload');
}
```

---

## Retry behaviour

When a delivery fails (non-2xx response or connection error), the cron runner retries automatically:

| Attempt | Delay after first failure |
|---------|--------------------------|
| 2nd | ≥ 1 minute |
| 3rd | ≥ 5 minutes |

After 3 failed attempts, the delivery is not retried further. You can manually retry any delivery from the Delivery log view in Admin → Webhooks.

---

## Delivery log retention

Delivery records older than `webhook.retention_days` (default 30) are pruned by the cron runner. Set to `0` to disable pruning. Configure in Settings → Webhooks.

---

## Admin UI walkthrough

1. Go to **Admin → Webhooks**.
2. Click **+ Add webhook**.
3. Enter a name, target URL, and select one or more events to subscribe to.
4. Click **Generate** to create a random secret, or paste your own.
5. Save the webhook.
6. Use **Test** to fire a `test.ping` delivery and verify the signature in the result panel.
7. Click the delivery count badge to open the delivery log for a webhook.

---

## Webhook management via API

Webhook management is admin-UI only in v3.3.0. There is no REST API resource for webhooks. The read-only REST API (`api.php`) is unaffected.
