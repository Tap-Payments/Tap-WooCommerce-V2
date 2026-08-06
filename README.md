# WooCommerce Tap Payment Gateway

Accept card and wallet payments on your WooCommerce store through [Tap Payments](https://tap.company/).

**Requires:** PHP 8.1+ · WordPress 6.4+ · WooCommerce 8.0+ (tested to 10.0)

## Features

- **Redirect** checkout (Tap-hosted page) or **popup** checkout (Tap over your store)
- **Charge** or **Authorize** transaction modes
- Full and partial refunds from the WooCommerce order screen
- English and Arabic checkout
- Cart & Checkout Blocks support
- HPOS (High-Performance Order Storage) compatible

## Installation

1. Download the plugin zip.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and click **Install Now**.
3. Activate the plugin from **Plugins → Installed Plugins**.
4. Go to **WooCommerce → Settings → Payments**, enable **Tap Gateway**, and click **Manage**.
5. Enter your Tap publishable key, secret key, and Merchant ID, choose your payment and checkout modes, and save.

The notification (webhook) URL is sent to Tap with every transaction, so there is nothing to configure in the Tap dashboard.

## Configuration

| Setting | Notes |
| --- | --- |
| Test mode | Uses your `pk_test_` / `sk_test_` keys. |
| Payment mode | `Charge` captures immediately. `Authorize` places a hold; those orders go **on hold** for you to capture in the Tap dashboard. |
| Checkout mode | `Redirect` or `Popup`. |
| Success / Failure page | Optional. Leave unset to use the standard WooCommerce order-received page and checkout. |
| Debug log | Detailed diagnostics in **WooCommerce → Status → Logs** under the `tap` source. Errors are always logged. Keys and card numbers are redacted. |

## Architecture

```
tap.php                                 Bootstrap: header, constants, guards, autoloader, HPOS declaration
includes/
  class-tap-plugin.php                  Every add_action/add_filter in the plugin
  class-wc-tap-gateway.php              WooCommerce gateway contract only
  class-tap-settings.php                Settings form definition
  class-tap-order-processor.php         The single place an order's status changes
  class-tap-webhook-handler.php         POST /wc-api/tap_webhook
  class-tap-return-handler.php          Customer's return from Tap
  class-tap-receipt-renderer.php        Popup checkout on the order-pay page
  class-tap-ajax.php                    Records the transaction id mid-payment
  class-tap-cancellation-handler.php    Cancellation audit trail + paid-order safety net
  class-tap-signature.php               HMAC generation and verification
  class-tap-validator.php               Id format, order binding, amount matching
  class-tap-currency.php                Currency precision (one source of truth)
  class-tap-countries.php               Dialing code lookup
  class-tap-logger.php                  Levels, correlation ids, secret redaction
  class-tap-privacy.php                 GDPR export and erasure
  api/
    class-tap-api-client.php            WordPress HTTP API, retries, idempotency
    class-tap-request-builder.php       Payload construction
    class-tap-response.php              Typed response wrapper
  blocks/
    class-wc-tap-blocks-support.php     Cart & Checkout Blocks registration
  data/country-dial-codes.php
assets/
  css/tap-payment.css
  js/tap-checkout.js                    Popup checkout
  js/blocks/tap-blocks.js               Blocks payment method (plain ES5, no build step)
languages/wc-tap-gateway.pot
bin/make-pot.php                        Regenerates the translation template
```

### How a payment is verified

A posted notification is never trusted on its own. Before an order changes status:

1. The request signature is verified when Tap supplies one.
2. The transaction is re-fetched from the Tap API with the merchant's secret key. **Only that response** decides the outcome.
3. The transaction must reference the order being settled, and must not already have been used to pay a different one.
4. Amount and currency must match at the currency's own precision.

Both the webhook and the customer's return route through `Tap_Order_Processor`, which holds a per-order lock and is idempotent, so the two cannot race or double-process.

## Development

```bash
composer install
composer run lint      # PHPCS: WordPress standards + PHPCompatibilityWP
composer run analyse   # PHPStan level 5
composer run check     # both
```

Regenerate the translation template after changing any user-facing string:

```bash
php bin/make-pot.php
```

Use `wp i18n make-pot . languages/wc-tap-gateway.pot` instead where WP-CLI is available — it parses JavaScript properly.

## Security

To report a vulnerability, contact Tap Payments directly rather than opening a public issue.
