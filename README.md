# InfyniPay WHMCS Plugin

> A comprehensive payment gateway integration for WHMCS that enables seamless payment processing through InfyniPay's platform with support for one-time payments, recurring subscriptions, and automated payment handling.

[![WHMCS](https://img.shields.io/badge/WHMCS-Compatible-green.svg)](https://www.whmcs.com/)
[![InfyniPay](https://img.shields.io/badge/InfyniPay-Integration-blue.svg)](https://infynipay.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net/)

---

## 🌟 Features

### Core Payment Capabilities
- **One-Time Payments**: Process single invoice payments
- **Recurring Subscriptions**: Automatic payment handling for subscription-based services
- **Auto-Capture**: Immediate payment capture upon authorization
- **Multi-Currency Support**: Process payments in various currencies

### Advanced Functionality
- **Machine-to-Machine Communication**: Secure server-to-server integration between WHMCS and InfyniPay core servers
- **Webhook Support**: Real-time payment status notifications
- **Automated Payment Processing**: Cron-based auto-payment for due invoices
- **Payment Method Management**: Customer portal for managing saved payment methods
- **Invoice Synchronization**: Automatic status updates based on payment state
- **Duplicate Payment Prevention**: Intelligent handling of duplicate payment attempts

### Security & Reliability
- **Webhook Authentication**: Bearer token validation for incoming webhooks, plus HMAC signature validation when a signing secret is stored
- **Secure Credential Storage**: Encrypted storage of API credentials
- **Comprehensive Logging**: Detailed module call logging for debugging
- **Error Handling**: Graceful fallback mechanisms

---

## 📁 Project Structure

```
InfyniPay.Plugins.WHMCS/
│
├── README.md                                                    # This file
│
├── includes/
│   └── hooks/
│       ├── infynipay-checkout-label-hook.php                  # Checkout label customization hook
│       ├── infynipay-cron-hook.php                            # Cron job hook for automated payments
│       └── infynipay-payment-profiles-hook.php                # Payment profiles management hook
│
└── modules/
    ├── addons/
    │   └── infynipay/
    │       ├── infynipayhandler.php                           # Core handler class (business logic)
    │       └── templates/
    │           └── manage-subscription.tpl                      # Customer-facing subscription UI
    │
    ├── gateways/
    │   ├── infynipay.php                                      # Gateway configuration & entry point
    │   ├── callback/
    │   │   └── infynipay.php                                  # Webhook & return URL handler
    │   └── infynipay/
    │       ├── get-payment-profiles.php                         # Payment profiles API endpoint
    │       ├── icon-proxy.php                                   # Payment provider icon proxy
    │       ├── infynipay-logo.png                             # Module logo
    │       └── whmcs.json                                       # Module metadata for Apps & Integrations
    │
    └── reports/
        └── infynipay_transactions.php                         # Transactions report module
```

### Component Details

#### 🔧 **Gateway Module** (`modules/gateways/infynipay/infynipay.php`)
The main gateway configuration file that defines:
- Gateway metadata and display name
- App ID and access token management for Sandbox and Live
- Webhook token registration
- Payment link generation for invoices

**Key Functions:**
- `infynipay_config()` - Gateway configuration settings
- `infynipay_link()` - Generates payment button/link for invoices
- `infynipay_handleActivationReturn()` - Processes OAuth callback
- `infynipay_syncWebhookToken()` - Registers webhook endpoints with InfyniPay

---

#### 🔄 **Callback Handler** (`modules/gateways/callback/infynipay.php`)
Handles payment notifications and return URLs:
- **POST requests**: Webhook notifications from InfyniPay
- **GET requests**: Customer return after payment

**Payment State Processing:**
- `Captured` → Marks invoice as paid, applies payment
- `Failed` → Cancels invoice, logs failure
- `Processing` → Maintains pending state

**Security Features:**
- Webhook token validation via Bearer authentication (constant-time comparison)
- Webhook signature validation (HMAC-SHA256 over the timestamp and the raw request body, 5 minute window) when a signing secret is stored for the active app. A webhook with a missing, malformed, stale or wrong signature is refused with HTTP 401 and the invoice is not touched. Without a stored secret only the Bearer token is checked, as before.
- The webhook body is not trusted for payment state: the callback re-reads the payment from the InfyniPay API
- Payment is recorded once per InfyniPay transaction, under a per-invoice lock
- A captured payment is not recorded automatically if the invoice is Cancelled or in a different currency; it is logged for admin review
- HTTPS-only communication

---

#### 🎯 **Core Handler** (`modules/addons/infynipay/infynipayhandler.php`)
Central business logic class containing:

**Payment Processing:**
- `handleInvoice()` - Main invoice payment flow
- `createAutoPayment()` - Automated recurring payments
- `checkInvoiceStatus()` - Payment state verification

**System Integration:**
- `getGatewayCredentials()` - Retrieves stored API credentials
- `getSystemUrl()` - Dynamic URL generation
- `callApi()` - Generic API communication wrapper

**Subscription Detection:**
- Automatically identifies recurring services
- Shows appropriate payment method options in checkout

---

#### ⏰ **Cron Hook** (`includes/hooks/infynipay-cron-hook.php`)
Automated background task that:
- Runs after each WHMCS cron execution
- Identifies unpaid invoices with due dates matching subscription renewal dates
- Attempts automatic payment using saved payment methods
- Processes only invoices with recurring billing cycles
- Logs all auto-payment attempts

**Targeted Invoices:**
- Status: `Unpaid`
- Due date matches hosting next due date
- Billing cycle: Not `Free` or `One Time`
- Has recurring services attached

---

#### 🎨 **Customer Portal** (`modules/addons/infynipay/templates/manage-subscription.tpl`)
Smarty template for subscription management:
- Displays verified payment methods
- Shows provider and masked card numbers
- Links to InfyniPay customer panel
- Add new payment methods interface

---

## 🚀 Installation

### Prerequisites
- WHMCS 8.0 or higher. Compatible with the latest WHMCS, 9.1 (tested on 9.1.0: activation, one-time payment and webhook signature verification)
- PHP 7.4 or higher (WHMCS 9.0 and later themselves require PHP 8.2 or higher)
- cURL extension enabled
- MySQL/MariaDB database
- Valid InfyniPay merchant account

### Step 1: File Installation
1. Download the plugin files
2. **IMPORTANT**: Upload ALL directories and files to your WHMCS installation root while preserving the exact folder structure:
   ```
   /path/to/whmcs/includes/hooks/infynipay-checkout-label-hook.php
   /path/to/whmcs/includes/hooks/infynipay-cron-hook.php
   /path/to/whmcs/includes/hooks/infynipay-payment-profiles-hook.php
   /path/to/whmcs/modules/addons/infynipay/infynipayhandler.php
   /path/to/whmcs/modules/addons/infynipay/templates/manage-subscription.tpl
   /path/to/whmcs/modules/gateways/infynipay.php
   /path/to/whmcs/modules/gateways/callback/infynipay.php
   /path/to/whmcs/modules/gateways/infynipay/get-payment-profiles.php
   /path/to/whmcs/modules/gateways/infynipay/icon-proxy.php
   /path/to/whmcs/modules/gateways/infynipay/infynipay-logo.png
   /path/to/whmcs/modules/gateways/infynipay/whmcs.json
   /path/to/whmcs/modules/reports/infynipay_transactions.php
   ```
3. Verify that the `modules/addons/infynipay/` directory exists with the `infynipayhandler.php` file inside it
4. Ensure all PHP files have proper read permissions (typically 644)

### Step 2: WHMCS Configuration
1. Log in to WHMCS Admin Panel
2. Navigate to **System Settings → Payment Gateways**
3. Click on **All Payment Gateways** tab
4. Find **InfyniPay** and click **Activate**

### Step 3: InfyniPay Activation
1. After activating, click the **Activate InfyniPay** button
2. You'll be redirected to InfyniPay Console
3. Sign in with your merchant credentials
4. Grant authorization to the WHMCS app
5. You'll be redirected back to WHMCS with confirmation

### Step 4: Verify Installation
- Check **System Logs** for successful activation entries
- Ensure "Account is activated" message appears in gateway settings
- Test with a sample invoice in Sandbox or Live

---

## ⚙️ Configuration

### Gateway Settings
The gateway automatically configures after OAuth activation:
- **App ID**: Your InfyniPay application identifier
- **Access Token**: OAuth-generated bearer token
- **Webhook Token**: Auto-generated for webhook authentication
- **Webhook Signing Secret**: Received from InfyniPay at activation, one per app (sandbox and live). Stored with the app credentials, never shown back and never logged

### Webhook Configuration
Webhooks are automatically registered during activation:
- **Endpoint**: `https://yourdomain.com/modules/gateways/callback/infynipay.php`
- **Authentication**: Bearer token (auto-generated), plus the HMAC signature when a signing secret is stored
- **Events**: Payment state changes

The signing secret is fetched only during activation. If the secret is rotated in InfyniPay, run **Activate InfyniPay** again so WHMCS picks up the new one; until then signed webhooks are refused with 401. Plugin installs that have not been re-activated since signing was introduced keep working on the Bearer token alone.

### Cron Configuration
Ensure WHMCS cron is running regularly:
```bash
*/5 * * * * php -q /path/to/whmcs/crons/cron.php
```

The InfyniPay cron hook runs automatically after each WHMCS cron execution.

---

## 💳 Payment Flow

### Customer Payment Journey

#### One-Time Payment
1. Customer views unpaid invoice
2. Clicks "Pay Now with InfyniPay" button
3. Redirected to InfyniPay hosted checkout
4. Enters payment details
5. Payment processed and captured
6. Redirected back to WHMCS with success message
7. Invoice automatically marked as paid

#### Recurring Subscription
1. System detects invoice with recurring service
2. Cron job identifies due invoice
3. Attempts auto-payment with saved payment method
4. If successful, invoice marked as paid
5. If failed, customer receives payment reminder
6. Customer can manually pay or update payment method

## 🛠️ Development & Debugging

### Logging
All operations are logged via WHMCS's `logModuleCall()`:
- Navigate to **Utilities → Logs → Module Log**
- Filter by module: **infynipay**

### Common Log Actions
- `link called` - Payment button generation
- `callback-status-check` - Webhook received
- `processUnpaidInvoices` - Cron execution
- `gateway activation return` - OAuth callback

### Testing Checklist
- [ ] Gateway activation successful
- [ ] Manual payment creates hosted page
- [ ] Webhook authentication works
- [ ] Payment state updates invoice
- [ ] Cron processes due invoices
- [ ] Duplicate payment handling
- [ ] Customer portal displays payment methods

---

## 🔐 Security Considerations

### Best Practices
1. **HTTPS Required**: All communication must use TLS/SSL
2. **Webhook Validation**: Always validates the Bearer token; also validates the HMAC signature when a signing secret is stored
3. **SQL Injection Prevention**: Uses Capsule ORM with parameterized queries
4. **XSS Protection**: All output is HTML-escaped
5. **CSRF Protection**: WHMCS built-in token validation
6. **Credential Storage**: Tokens stored in database, never logged

### Token Security
- Access tokens never exposed to frontend
- Webhook tokens use cryptographically secure random generation
- The webhook signing secret is used as the literal `whsec_...` string and is compared in constant time; a refusal is logged with its reason (`missing`, `malformed`, `outside_window`, `mismatch`), never with the secret, the header or the body
- Bearer authentication for all API calls

---

## 🐛 Troubleshooting

### Issue: Activation Button Doesn't Work
**Solution**: Check that your WHMCS system URL is correctly configured in **Setup → General Settings → General**.

### Issue: Webhooks Not Received
**Solutions**:
1. Verify webhook URL is accessible externally
2. Check firewall/security rules
3. Ensure webhook token was registered during activation
4. Review module logs for authentication failures

### Issue: Duplicate Payment Errors
**Solution**: The plugin automatically handles duplicates by checking existing payment state. If invoice was cancelled, it can be recreated.

### Issue: Auto-Payments Not Running
**Solutions**:
1. Verify WHMCS cron is executing
2. Check that services have non-free billing cycles
3. Ensure invoice due date matches hosting next due date
4. Review cron hook logs

### Issue: Payment Methods Not Showing
**Solution**: Ensure customer has completed at least one payment with saved payment method option selected.

---

## 📊 Database Tables Used

The plugin interacts with standard WHMCS tables:
- `tblpaymentgateways` - Gateway configuration storage
- `tblinvoices` - Invoice status updates
- `tblinvoiceitems` - Subscription detection
- `tblhosting` - Billing cycle information
- `tblmodulelog` - Debug logging
- `tblconfiguration` - System URL retrieval

---

## 🤝 Support & Contribution

### Getting Help
- **InfyniPay Support**: support@infynipay.com
- **WHMCS Forums**: Include "InfyniPay" in your post title

### Contributing
Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Follow PSR-12 coding standards
4. Include comprehensive comments
5. Test thoroughly before submitting PR

---

## About InfyniPay

[InfyniPay](https://www.infynipay.com) is a payment orchestration platform that connects your store to 30+ payment providers through a single integration — with automatic routing, failover, webhook verification and server-side confirmation handled for you. Add or switch providers from a dashboard without touching code, and charge one-time or recurring invoices on WHMCS.

- 🔌 **One integration, 30+ providers** — PayPal, Stripe, Adyen, Paystack, Razorpay, Binance Pay and more
- 🆓 **Free** — no per-transaction fee from InfyniPay; you pay only your provider's processing fees
- 🔁 **Automatic routing & failover** between providers
- 🧾 **Recurring / subscription billing** with automatic renewals
- 🛡️ **Signed webhooks + server-side verification** built in

**Links:** [Website](https://www.infynipay.com) · [WHMCS integration](https://www.infynipay.com/integrations/whmcs/) · [Installation guide](https://www.infynipay.com/integrations/whmcs/installation/) · [Docs](https://docs.infynipay.com) · [Create a free account](https://console.infynipay.com/auth/signup)

## 📄 License

This plugin is proprietary software provided by InfyniPay. All rights reserved.

---

## 🔄 Version History

### Version 1.3.0 (Current)
- New: webhook signature verification. When InfyniPay returns a webhook signing secret at activation, the callback checks the HMAC signature of every webhook and refuses an invalid one with HTTP 401 before touching the invoice. Without a stored secret only the Bearer token is checked, as before. Re-run **Activate InfyniPay** to fetch the secret.
- Fix: a captured payment is recorded once per transaction under a per-invoice lock, and is not recorded automatically for a Cancelled invoice, a different currency or an invalid amount; it is logged for admin review.
- Logging: webhook signature results are logged (`callback_webhook_signature_checked`, `callback_webhook_signature_refused`); authorization codes, signing secrets and the webhook token are no longer written to the module log.

### Version 1.2.4
- Fix: the gateway module no longer changes PHP's global error settings (`display_errors`, `display_startup_errors`, `error_reporting`) when WHMCS loads it. This stops cart and checkout pages from answering HTTP 500 on unrelated PHP warnings and avoids exposing server paths to visitors.
- See the GitHub releases page for the full changelog: https://github.com/infynipay/InfyniPay.Plugins.WHMCS/releases

### Version 1.2.3
- See the GitHub releases page for the full changelog: https://github.com/infynipay/InfyniPay.Plugins.WHMCS/releases

### Version 1.0.0
- Initial release
- OAuth2 activation flow
- Hosted payment pages
- Webhook integration
- Auto-payment for subscriptions
- Customer payment method management

---

## 📞 Contact

**InfyniPay**  
Website: https://infynipay.com  
Email: support@infynipay.com  

---

<div align="center">
  <strong>Built with ❤️ for seamless payment processing</strong>
</div>