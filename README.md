# Mail Relay

A lightweight PHP mail relay for sending emails through an SMTP server using a simple HTTP API.

Mail Relay is designed for applications and services that need a small, self-hosted email delivery endpoint without running a full mail infrastructure.

It works well with shared hosting, VPS environments, serverless applications, webhooks, and backend services that need to send transactional emails through an existing SMTP provider.

## Features

* Simple HTTP API for sending emails
* SMTP-based email delivery
* Shared-secret authentication
* Environment variable (or `.env`) configuration
* Shared hosting support via `config.local.php`
* Custom sender name and address
* Configurable STARTTLS / SMTPS encryption
* Configurable per-IP rate limiting
* Lightweight PHP implementation
* Easy to deploy and self-host
* No database required

## How It Works

```text
Your Application
       │
       │ HTTPS Request
       ▼
┌─────────────────┐
│   Mail Relay    │
│                 │
│ Authentication  │
│ Validation      │
│ SMTP Client     │
└────────┬────────┘
         │
         │ SMTP
         ▼
┌─────────────────┐
│  SMTP Provider  │
└────────┬────────┘
         │
         ▼
     Recipient
```

Your application sends an authenticated HTTP request to Mail Relay.

Mail Relay validates the request and forwards the message to the configured SMTP server for delivery.

## Requirements

* PHP 8.1+
* Composer
* An SMTP account
* HTTPS-enabled hosting recommended

## Installation

Clone the repository:

```bash
git clone https://github.com/benahmetkaplan/mail-relay.git
cd mail-relay
```

Install dependencies:

```bash
composer install --no-dev
```

Create your environment configuration:

```bash
cp .env.example .env
```

Then configure your SMTP credentials.

## Configuration

Example `.env`:

```env
# SMTP Configuration
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=noreply@example.com
SMTP_PASSWORD=your-smtp-password

# Sender Configuration
FROM_EMAIL=noreply@example.com
FROM_NAME="Your App"

# Mail Relay Authentication
MAIL_RELAY_SECRET=your-long-random-secret

# Rate Limiting (optional)
RATE_LIMIT_MAX=60
RATE_LIMIT_WINDOW=60
```

### SMTP settings

| Variable            | Required | Default | Description                                             |
| ------------------- | -------- | ------- | --------------------------------------------------------|
| `SMTP_HOST`         | Yes      | —       | SMTP server hostname                                    |
| `SMTP_PORT`         | Yes      | —       | SMTP server port (commonly `587` for STARTTLS, `465` for SMTPS) |
| `SMTP_SECURE`       | No       | `false` | Encryption mode — see [SMTP Encryption](#smtp-encryption) |
| `SMTP_USER`         | Yes      | —       | SMTP authentication username                             |
| `SMTP_PASSWORD`     | Yes      | —       | SMTP authentication password                             |
| `FROM_EMAIL`        | Yes      | —       | Default sender email address                             |
| `FROM_NAME`         | No       | *(empty)* | Default sender name                                    |
| `MAIL_RELAY_SECRET` | Yes      | —       | Secret used to authenticate relay requests               |
| `RATE_LIMIT_MAX`    | No       | `60`    | Max requests allowed per IP per window — see [Rate Limiting](#rate-limiting) |
| `RATE_LIMIT_WINDOW` | No       | `60`    | Rate-limit window length, in seconds                     |

If a required value is missing, requests fail at send time with `502` (logged server-side as a configuration error, never detailed in the response). `RATE_LIMIT_MAX` / `RATE_LIMIT_WINDOW` silently fall back to their defaults instead of failing if unset, blank, or not a positive integer.

Never commit real SMTP credentials or relay secrets to Git.

## SMTP Encryption

`SMTP_SECURE` selects the PHPMailer encryption mode — it does not turn encryption on or off. Both settings result in an encrypted connection; they differ in *how* encryption is negotiated:

| `SMTP_SECURE` | PHPMailer mode              | Typical port | Behavior                                                        |
| -------------- | --------------------------- | ------------- | ---------------------------------------------------------------|
| `false` (default) | `ENCRYPTION_STARTTLS`   | `587`         | Connects in plaintext, then upgrades to TLS via the `STARTTLS` command. |
| `true`         | `ENCRYPTION_SMTPS`           | `465`         | Implicit TLS — the connection is encrypted from the first byte. |

Use whichever mode your SMTP provider expects for the port you're using. This setting is read at send time; changing it does not affect existing deployments unless you explicitly update the configuration.

## Shared Hosting

Some shared hosting environments do not provide a convenient way to configure environment variables.

For these environments, Mail Relay can use a local PHP configuration file.

Copy the provided configuration example:

```bash
cp config.example.php config.local.php
```

Then enter your real credentials in `config.local.php`.

```php
<?php

return [
    'SMTP_HOST' => 'smtp.example.com',
    'SMTP_PORT' => '587',
    'SMTP_SECURE' => 'false',
    'SMTP_USER' => 'noreply@example.com',
    'SMTP_PASSWORD' => 'your-smtp-password',

    'FROM_EMAIL' => 'noreply@example.com',
    'FROM_NAME' => 'Your App',

    'MAIL_RELAY_SECRET' => 'your-long-random-secret',

    'RATE_LIMIT_MAX' => '60',
    'RATE_LIMIT_WINDOW' => '60',
];
```

`config.local.php` should never be committed to the repository.

Configuration is resolved in this order, first match wins: real environment variables, then a project-root `.env` file (loaded to fill in anything not already set as a real environment variable), then `config.local.php`.

## Rate Limiting

Mail Relay applies a simple, file-based fixed-window rate limit per client IP (`REMOTE_ADDR`) — no Redis or database required. State is stored in `storage/ratelimit.json`.

Configure it with:

| Variable            | Default | Description                          |
| ------------------- | ------- | ------------------------------------ |
| `RATE_LIMIT_MAX`    | `60`    | Max requests allowed per IP per window |
| `RATE_LIMIT_WINDOW` | `60`    | Window length, in seconds            |

Both must be positive integers; any other value (missing, blank, zero, negative, non-numeric) falls back to the default of `60`.

Requests over the limit receive `429 Too Many Requests`.

**Fail-open behavior:** if the rate-limit storage file can't be opened or locked (e.g. a permissions problem), the limiter fails *open* — the request is allowed rather than rejected, so a storage issue can't take down mail delivery. When this happens it is logged to `storage/error.log`; it is not surfaced to the HTTP caller. If you see repeated rate-limiter failures in the log, check permissions on the `storage/` directory.

## Security

Mail Relay should always be deployed behind HTTPS. It rejects non-HTTPS requests with `400 HTTPS required`, detected via `$_SERVER['HTTPS']` or (for requests behind a reverse proxy) the `X-Forwarded-Proto` / `X-Forwarded-Ssl` headers. If you're behind a proxy or load balancer, make sure it is the one setting those headers and that clients cannot set them directly — otherwise a client could spoof HTTPS.

Generate a strong random value for `MAIL_RELAY_SECRET`. For example:

```bash
openssl rand -hex 32
```

Do not expose SMTP credentials to frontend applications.

The recommended architecture is:

```text
Frontend
   │
   ▼
Your Backend
   │
   ▼
Mail Relay
   │
   ▼
SMTP Provider
```

Avoid calling the relay directly from browser-side JavaScript because doing so would expose the relay secret.

### Before deploying

Make sure:

* HTTPS is enabled
* `MAIL_RELAY_SECRET` is strong and unique
* SMTP credentials are not committed to Git
* `.env` is excluded by `.gitignore`
* `config.local.php` is excluded by `.gitignore`
* directory listing is disabled
* error responses do not expose credentials or internal configuration

## API

### Authentication

Every request must include a bearer token matching `MAIL_RELAY_SECRET`:

```text
Authorization: Bearer <MAIL_RELAY_SECRET>
```

Requests without a valid token receive `401 Unauthorized`.

### Request

`POST /` with a JSON body, over HTTPS (see [Security](#security)):

```bash
curl -X POST https://mail.example.com/ \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_MAIL_RELAY_SECRET" \
  -d '{
    "to": {
      "email": "recipient@example.com",
      "name": "Recipient Name"
    },
    "subject": "Hello from Mail Relay",
    "text": "This email was sent through Mail Relay."
  }'
```

### Request fields

| Field           | Type   | Required | Description                                                                 |
| --------------- | ------ | -------- | ----------------------------------------------------------------------------|
| `to.email`      | string | Yes      | Recipient email address; must be a valid address                            |
| `to.name`       | string | No       | Recipient display name                                                      |
| `subject`       | string | Yes      | Email subject; must not contain line breaks                                 |
| `text`          | string | Conditional | Plain-text body. Required unless `html` is provided                      |
| `html`          | string | Conditional | HTML body. Required unless `text` is provided. If both are set, `text` is sent as the plain-text alternative part |
| `headers`       | object | No       | Extra headers to attach. Only `In-Reply-To` and `References` are allowed; values must not contain line breaks |

At least one of `text` or `html` is required. The request body is capped at 200 KB.

### Response

Success (`200`):

```json
{ "ok": true, "messageId": "<...@example.com>" }
```

Failure:

```json
{ "ok": false, "error": "Invalid recipient email" }
```

| Status | Meaning                                                        |
| ------ | ----------------------------------------------------------------|
| `400`  | Malformed or invalid request (bad JSON, missing/invalid fields, disallowed header, body too large) |
| `401`  | Missing or incorrect `Authorization` bearer token                |
| `405`  | Method other than `POST`                                        |
| `429`  | Rate limit exceeded — see [Rate Limiting](#rate-limiting)        |
| `502`  | SMTP delivery failed, or required configuration is missing (diagnostics are logged server-side, never returned to the caller) |

## Example Use Cases

Mail Relay can be useful for:

* Contact forms
* Transactional emails
* Application notifications
* Internal tools
* Serverless applications
* Webhook-driven email delivery
* Small SaaS applications
* Shared hosting environments

## Deployment

Mail Relay can be deployed anywhere PHP applications can run, including:

* Shared hosting
* VPS servers
* Docker-based environments
* Traditional Apache or Nginx/PHP-FPM servers

For production deployments, configure environment variables through your hosting platform whenever possible.

## Project Structure

A typical installation looks like:

```text
mail-relay/
├── src/
├── index.php
├── config.example.php
├── .env.example
├── .gitignore
├── composer.json
└── README.md
```

## Contributing

Contributions are welcome.

If you find a bug or have an improvement idea, feel free to open an issue or submit a pull request.

When contributing, please avoid including credentials, private domains, email addresses, or other sensitive information in commits, issues, or logs.

## License

This project is open source and available under the MIT License.

See the `LICENSE` file for details.

## Disclaimer

Mail Relay is intended to provide a simple interface between your applications and an SMTP provider.

You are responsible for securing your deployment, protecting SMTP credentials, preventing unauthorized relay access, and complying with your email provider's policies and applicable anti-spam regulations.
