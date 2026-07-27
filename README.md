# Lead Gen SaaS

A complete, white-label B2B lead generation platform. Search Google Maps for business leads, enrich them with emails and social profiles via AI, manage leads with a built-in CRM, export to CSV, share public lead lists, and push contacts directly to GoHighLevel (GHL). Includes Stripe billing, credit system, admin panel, API key management, and more.

---

## Quick Start (5 Minutes)

### 1. Upload Files

Upload the entire `lead-gen-saas` folder to your web server's public directory (e.g., `public_html/`).

### 2. Install Composer Dependencies

SSH into your server and run:

```bash
cd /home/uobldmqj/public_html/
composer install
```

This installs Stripe PHP SDK and PHPMailer.

### 3. Run the Setup Wizard

Open your browser and go to:

```
https://yourdomain.com/install.php
```

The wizard will walk you through:
- **Database** — Enter your MySQL credentials. The wizard creates all tables automatically.
- **App Branding** — Set your app name, URL, and emails.
- **SMTP** — Configure email so password resets and notifications work.
- **Stripe** — Enter your Stripe API keys and subscription price IDs.
- **API Keys** — Set up RapidAPI (for Google Maps search) and Replicate (for website enrichment).
- **Admin Account** — Create your first admin user.

### 4. Delete install.php

**Important:** After setup, delete or rename `install.php` for security:

```bash
rm install.php
```

### 5. Set Up Cron Job

The GHL drip import feature requires a cron job. Add this to your server's crontab:

```bash
* * * * * php /path/to/lead-gen-saas/cron_drip.php >> /dev/null 2>&1
```

### 6. Set Up Stripe Webhook

In your [Stripe Dashboard](https://dashboard.stripe.com/webhooks):

1. Click **Add endpoint**
2. Enter URL: `https://yourdomain.com/stripe_webhook.php`
3. Select events:
   - `checkout.session.completed`
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
4. Copy the **Signing secret** (starts with `whsec_`) and add it to your `.env` file as `STRIPE_WEBHOOK_SECRET`

### 7. Create Stripe Products

In your [Stripe Dashboard](https://dashboard.stripe.com/products):

1. Create 3 subscription products (e.g., Starter, Growth, Enterprise)
2. Set recurring prices for each
3. Copy the **Price IDs** (start with `price_`) into your `.env` file

---

## Configuration

All configuration is in the `.env` file (created by the setup wizard). You can edit it directly:

### App Branding
```env
APP_NAME="Your App Name"
APP_URL="https://yourdomain.com"
APP_LOGO="/assets/logo.svg"
ADMIN_EMAIL="admin@yourdomain.com"
SUPPORT_EMAIL="support@yourdomain.com"
```

### Changing the Logo
Replace `/assets/logo.svg` with your own logo file. Update `APP_LOGO` in `.env` if you use a different filename.

### Changing the Site Name
Update `APP_NAME` in `.env`. The name automatically updates across the entire app — homepage, dashboard, emails, admin panel, etc.

### Plans & Pricing
```env
PLAN_STARTER_PRICE=499
PLAN_GROWTH_PRICE=999
PLAN_ENTERPRISE_PRICE=2999
PLAN_STARTER_CREDITS=5000
PLAN_GROWTH_CREDITS=15000
PLAN_ENTERPRISE_CREDITS=100000
FREE_SIGNUP_CREDITS=3
```

### Email (SMTP)
```env
SMTP_HOST=mail.yourdomain.com
SMTP_PORT=465
SMTP_SECURE=ssl
SMTP_USER=admin@yourdomain.com
SMTP_PASS=your_password
SMTP_FROM_EMAIL=admin@yourdomain.com
SMTP_FROM_NAME="Your App Name"
```

---

## Features

### For Users
- **Google Maps Lead Search** — Search any business type + city, get up to 500 leads per search
- **AI Email Enrichment** — Automatically search business websites to find emails and social profiles
- **Lead List CRM** — Organize leads into lists with pipeline stages (New → Contacted → Engaged → Client)
- **CSV Export** — Download any lead list as a CSV file
- **Public Sharing** — Generate shareable links for lead lists (with CSV download)
- **GHL Integration** — Push leads directly to GoHighLevel with tags, workflows, and drip scheduling
- **API Keys** — Generate API keys for programmatic access
- **Stripe Billing** — Subscribe to plans, purchase credits

### For Admins
- **Admin Dashboard** — Revenue stats, user management, activity feed
- **User Management** — View all users, add credits, change plans, impersonate users
- **GHL Import Monitoring** — Track all GHL imports across users
- **API Endpoint Management** — Create and manage API endpoints
- **Email Users** — Send emails directly from the admin panel
- **Transaction History** — View all credit purchases and API usage

---

## File Structure

```
lead-gen-saas/
├── .env.example          # Template for configuration
├── .env                  # Your actual config (created by install.php)
├── .htaccess             # URL rewrites and security headers
├── composer.json          # PHP dependencies
├── install.php           # One-time setup wizard
├── README.md             # This file
│
├── config/
│   ├── app.php           # App branding constants (reads from .env)
│   ├── database.php      # PDO database connection
│   ├── env_loader.php    # .env file parser
│   ├── rapidapi.php      # RapidAPI + Replicate keys
│   ├── stripe_config.php # Stripe initialization
│   └── subscription_config.php  # Plan pricing + credit amounts
│
├── includes/
│   ├── auth.php          # Session management, isLoggedIn(), getCurrentUser()
│   └── email_service.php # All email functions (welcome, reset, credits, etc.)
│
├── assets/
│   └── logo.svg          # Your app logo (replace with your own)
│
├── index.php             # Public homepage / landing page
├── login.php             # Login page
├── register.php          # Registration API endpoint
├── logout.php            # Logout handler
├── forgot_password.php   # Password reset request page
├── reset_password.php    # Password reset form
├── dashboard.php         # Main app dashboard (sidebar + iframe shell)
├── leadlists.php         # Core lead list management (the main product)
├── public_list.php       # Public lead list viewer + CSV download
├── pricing.php           # Plans & pricing page
├── subscription.php      # Subscription management
├── admin.php             # Admin panel
├── api_keys.php          # API key management UI
├── api_wrapper.php       # API proxy (handles /api/* requests)
├── api_dashboard.php     # Admin API endpoint management
├── create_checkout_session.php    # Stripe one-time payment
├── create_subscription_session.php # Stripe subscription checkout
├── stripe_webhook.php    # Stripe webhook handler
├── webhook_scrape.php    # Replicate enrichment webhook (file kept for compatibility)
└── cron_drip.php         # GHL drip import cron job
```

---

## Server Requirements

- **PHP** 7.4+ (8.0+ recommended)
- **MySQL** 5.7+ or MariaDB 10.3+
- **Composer** (for installing dependencies)
- **Apache** with `mod_rewrite` enabled (for clean URLs)
- **SSL Certificate** (required for Stripe)
- **Cron access** (for GHL drip imports)

---

## Third-Party Services

| Service | Purpose | Sign Up |
|---------|---------|---------|
| **Stripe** | Payment processing | [stripe.com](https://stripe.com) |
| **RapidAPI** | Google Maps data | [rapidapi.com](https://rapidapi.com) — Subscribe to "Google Maps Data" API |
| **Replicate** | Website search for enrichment | [replicate.com](https://replicate.com) |
| **SMTP Provider** | Transactional email | Use your hosting provider's SMTP, or services like SendGrid, Mailgun, etc. |

---

## GoHighLevel (GHL) Integration

Users can connect their GHL account from within the Lead Lists interface:

1. Go to **Lead Lists** in the dashboard
2. Click the **GHL** icon in the toolbar
3. Add a connection with their GHL API key and Location ID
4. Select leads and click **Import to GHL**
5. Configure tags, workflows, and drip settings

The drip import feature sends leads in batches over time (requires the cron job).

---

## API Access

Users can generate API keys from the dashboard and use them to access configured API endpoints:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" \
     "https://yourdomain.com/api/endpoint-name?param=value"
```

Admins can create and manage API endpoints from the **API Management Dashboard** (`/api_dashboard`).

---

## Troubleshooting

### Database connection failed
- Check your `.env` file has the correct DB_HOST, DB_NAME, DB_USER, DB_PASS
- Ensure the MySQL user has full privileges on the database

### Emails not sending
- Verify SMTP credentials in `.env`
- Check your hosting provider allows outbound SMTP on the configured port
- Look at PHP error logs for PHPMailer errors

### Stripe payments not working
- Ensure you're using the correct Stripe keys (test vs live)
- Check that the webhook URL is configured correctly in Stripe Dashboard
- Verify STRIPE_WEBHOOK_SECRET matches the signing secret from Stripe

### Clean URLs not working
- Ensure `mod_rewrite` is enabled: `a2enmod rewrite && systemctl restart apache2`
- Make sure `.htaccess` is being read (AllowOverride All in Apache config)

### GHL drip not running
- Verify the cron job is set up: `crontab -l`
- Check cron logs: `grep CRON /var/log/syslog`
- Ensure the PHP path in the cron command is correct

---

## Security Notes

- **Delete `install.php`** after initial setup
- The `.htaccess` file blocks direct access to `.env`
- All passwords are hashed with `password_hash()` (bcrypt)
- API keys are 64-character cryptographic hex strings
- Stripe webhook signatures are verified
- Admin actions require `is_admin` flag in the database
- CSRF protection should be added for production deployments

---

## License

This is a commercial product. All rights reserved.
