# Mail Relay

A lightweight PHP mail relay for sending emails through an SMTP server using a simple HTTP API.

Mail Relay is designed for applications and services that need a small, self-hosted email delivery endpoint without running a full mail infrastructure.

It works well with shared hosting, VPS environments, serverless applications, webhooks, and backend services that need to send transactional emails through an existing SMTP provider.

## Features

* Simple HTTP API for sending emails
* SMTP-based email delivery
* Shared-secret authentication
* Environment variable configuration
* Shared hosting support via `config.local.php`
* Custom sender name and address
* TLS/STARTTLS compatible SMTP configuration
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

* PHP 8+
* An SMTP account
* HTTPS-enabled hosting recommended

## Installation

Clone the repository:

```bash
git clone https://github.com/benahmetkaplan/mail-relay.git
cd mail-relay
```

Install the required dependencies if the project uses Composer:

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
```

### SMTP settings

| Variable            | Description                                |
| ------------------- | ------------------------------------------ |
| `SMTP_HOST`         | SMTP server hostname                       |
| `SMTP_PORT`         | SMTP server port, commonly `587`           |
| `SMTP_SECURE`       | Enable the configured secure SMTP mode     |
| `SMTP_USER`         | SMTP authentication username               |
| `SMTP_PASSWORD`     | SMTP authentication password               |
| `FROM_EMAIL`        | Default sender email address               |
| `FROM_NAME`         | Default sender name                        |
| `MAIL_RELAY_SECRET` | Secret used to authenticate relay requests |

Never commit real SMTP credentials or relay secrets to Git.

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
];
```

`config.local.php` should never be committed to the repository.

Real environment variables take precedence over values defined in `config.local.php`.

## Security

Mail Relay should always be deployed behind HTTPS.

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

## Example Request

A typical request to the relay might look like:

```bash
curl -X POST https://mail.example.com/ \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_MAIL_RELAY_SECRET" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Hello from Mail Relay",
    "text": "This email was sent through Mail Relay."
  }'
```

> The exact request structure and authentication header should match the implementation in this repository.

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
