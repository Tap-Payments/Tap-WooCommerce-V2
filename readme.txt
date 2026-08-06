=== WooCommerce Tap Payment Gateway ===
Contributors: tappayments
Tags: woocommerce, payment gateway, tap, credit card, mada
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 10.0
Stable tag: 3.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card and wallet payments on your WooCommerce store through Tap Payments.

== Description ==

Adds Tap Payments as a WooCommerce payment gateway.

* Redirect checkout (customer pays on a Tap-hosted page) or popup checkout (Tap opens over your store).
* Charge or Authorize transaction modes.
* Full and partial refunds from the WooCommerce order screen.
* English and Arabic checkout.
* Cart & Checkout Blocks support.
* High-Performance Order Storage (HPOS) compatible.

= Security =

Before an order is updated, the transaction is independently re-fetched from the
Tap API using your secret key, and only that response decides the outcome. A
posted notification is never trusted on its own, so a forged one cannot mark an
order as paid. Every transaction is also bound to the order it references, so a
real transaction cannot be replayed against a different order.

The signature on the request is checked as well, and a mismatch is logged. It is
advisory rather than binding by default, because the API re-fetch is what
establishes authenticity and a signature scheme mismatch would otherwise strand
paid orders. Once you have confirmed the scheme against your own traffic you can
make it binding:

    add_filter( 'wc_tap_enforce_webhook_signature', '__return_true' );

== Installation ==

1. Upload the plugin to `wp-content/plugins/` or install it through **Plugins > Add New > Upload Plugin**.
2. Activate it through the **Plugins** screen.
3. Go to **WooCommerce > Settings > Payments**, enable **Tap Gateway**, and click **Manage**.
4. Enter the publishable and secret keys from your Tap dashboard, and your Merchant ID.
5. Choose your payment mode and checkout mode, then save.

No webhook configuration is required: the plugin sends its notification URL with
every transaction.

== Frequently Asked Questions ==

= Where are the logs? =

**WooCommerce > Status > Logs**, under the `tap` source. Errors are always
logged. Enable **Debug log** in the gateway settings for detailed diagnostics.
API keys and card numbers are redacted before anything is written.

= Why is the gateway not showing at checkout? =

The gateway hides itself when the publishable or secret key for the active mode
(test or live) is missing. Check that the keys for the mode you are using are
filled in.

= A customer was charged but the order is still pending. =

Open the order and check the notes. If the payment reached Tap, the notification
will settle the order; the plugin also re-checks cancelled and failed Tap orders
against the Tap API a minute after they change status and restores any that were
in fact paid.

== Changelog ==

= 3.0.4 =

Fixed:
* A payment landing on an order WooCommerce had already cancelled was
  discarded. The hold-stock cron cancels unpaid orders on a timer and can fire
  while the customer is still paying; the return page then sent them to the
  failure page without checking the transaction, leaving them charged with no
  transaction id recorded. Cancelled and failed orders are now verified against
  Tap like any other, and an order restored this way is flagged in its notes so
  stock and fulfilment can be checked.

= 3.0.3 =

Fixed:
* Popup checkout was rejected by Tap with "Request values are empty". The
  notification URL was being sent as an object; the checkout SDK forwards this
  field to Tap's API expecting a bare URL string. As well as failing the
  payment, this meant no webhook was ever registered, so popup orders could not
  settle on their own.

Added:
* Checkout errors reported by the browser are now written to the WooCommerce log
  and recorded as an order note, instead of only being visible in the customer's
  browser console.

= 3.0.2 =

Fixed:
* Popup checkout failed every payment with "The payment was not completed."
  The SDK returns the transaction id in an undocumented shape, and when it
  could not be read the browser returned with an empty identifier, which the
  server read as an abandoned payment. The id is now located wherever it
  appears in the payload.
* A payment can no longer be failed just because its id could not be read. The
  order is left for the webhook to settle, and the id recorded during checkout
  is used as a fallback.
* The "completing payment" spinner disappeared immediately. The SDK closes its
  popup after a successful payment, and the close handler was hiding the
  spinner that had just been shown.
* The abandoned-payment path now writes to the log instead of failing silently.

Added:
* Browser-side diagnostics, printed only when "Debug log" is enabled.

= 3.0.1 =

Fixed:
* The popup checkout did not open under block themes. `woocommerce_receipt_*`
  fires before `wp_enqueue_scripts` there, so the checkout configuration was
  silently discarded by `wp_localize_script()` and the script had nothing to
  work with. Asset registration no longer depends on hook order.
* Webhook signature verification rejected genuine notifications. JSON carries
  the amount as a number, so `65.00` decoded to `65` and was hashed as "65"
  while Tap signed "65.00". Every plausible rendering is now tried.
* A signature mismatch no longer rejects the notification. Authenticity is
  established by re-fetching the transaction from the Tap API, so a mismatch is
  logged with diagnostics rather than stranding a paid order. Enforcement is
  available via the `wc_tap_enforce_webhook_signature` filter.

Added:
* A standalone test suite (`./bin/run-tests.sh`) that needs no WordPress.

= 3.0.0 =

Security:
* Hardened payment notification handling. Notifications are now authenticated
  and independently verified against the Tap API before an order is updated.
* Each transaction is bound to the order it belongs to.
* Identifiers received from the browser are validated before use.
* Order-writing endpoints now require proof of order ownership.
* Output escaping and input sanitisation applied throughout.
* Secret keys are stored in password fields rather than plain text inputs.
* Customer IP addresses are no longer stored by default, and any stored value is
  covered by the WordPress personal data exporter and eraser.

All merchants should upgrade. Full details of the issues addressed will be
published once merchants have had time to update.

Fixed:
* Order completion no longer skips `payment_complete()`, so the transaction id,
  paid date, and `woocommerce_payment_complete` integrations all work.
* Refunds work. They previously sent an array as the charge id, mangled the
  request body, and returned no reason on failure.
* A failed API call is reported as a failed payment instead of a silent success.
* Authorized payments are placed on hold instead of being left pending, where
  WooCommerce's unpaid-order cron would cancel them.
* Stock is no longer reduced twice on notification.
* One shared list of three-decimal currencies. BHD, OMR and JOD orders could
  previously be auto-refunded as an amount mismatch.
* Multibyte-safe truncation of product descriptions, fixing Arabic catalogues.
* The request body is no longer passed through `stripslashes()`, which corrupted
  the JSON for any customer whose details contained a quote or backslash.
* Items are no longer duplicated in the cart after a failed payment.
* The statement descriptor is the store name, not the literal "Sample".
* Activating without WooCommerce shows a notice instead of fataling the site.
* The gateway no longer breaks the checkout when no checkout mode is configured.
* The Blocks checkout logo resolves in any plugin directory.

Changed:
* Rewritten around single-responsibility classes under `includes/`.
* HTTP calls use the WordPress HTTP API, with retries, an idempotency key, and
  HTTP status handling.
* HPOS declared and supported; no post meta access on orders.
* Full internationalisation under the `wc-tap-gateway` text domain.
* Structured logging with correlation ids and secret redaction.
* Declined payments now use the `failed` status rather than `cancelled`.
* Removed the unused "Post URL" setting; added "Popup theme" and "Debug log".

= 2.1.1 =
* Previous release.

== Upgrade Notice ==

= 3.0.4 =
Fixes payments being discarded on orders auto-cancelled by the hold-stock timer.

= 3.0.3 =
Fixes popup checkout being rejected by Tap. Required for anyone using popup mode.

= 3.0.2 =
Fixes popup checkout failing every payment. Required for anyone using popup mode.

= 3.0.1 =
Fixes the popup checkout failing to open on block themes, and webhooks being
rejected as unsigned. Recommended for everyone on 3.0.0.

= 3.0.0 =
Important security update. All merchants should upgrade. Requires PHP 8.1 and
WooCommerce 8.0.
