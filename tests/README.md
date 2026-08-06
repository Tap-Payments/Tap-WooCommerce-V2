# Tests

```bash
./bin/run-tests.sh
```

No WordPress installation, no Composer, no network. Runs in about a second.

## What these cover

Pure logic only — the parts that can be exercised against stubs:

| File | Covers |
| --- | --- |
| `test-core.php` | Currency precision, country dialing codes, identifier validation, response parsing, log redaction, multibyte text handling |
| `test-signature.php` | Checkout hash generation, webhook verification, amount-rendering variants |
| `test-order-processor.php` | Exception hierarchy, settlement, replay/duplicate guards, idempotency, locking, exception safety |

## What these do NOT cover

**Read this before trusting a green run.** The stubs in `bootstrap.php` encode
*our assumptions* about WooCommerce. If an assumption is wrong, the stub
implements the wrong behaviour and the test passes anyway.

Everything below has to be verified against a real install:

- **Hook ordering.** Block themes fire `woocommerce_receipt_*` *before*
  `wp_enqueue_scripts`; classic themes do the opposite. This difference caused a
  real bug (`wp_localize_script()` silently dropping its data) that every unit
  test passed straight through.
- **Script and style registration**, and asset URLs under symlinked plugins.
- **Template rendering** and the block checkout.
- **`WC_Order` semantics** — particularly which statuses
  `payment_complete()` acts on, and when `date_paid` is set.
- **HPOS** meta reads and writes.
- **The live Tap API**: real charges, authorizations, refunds, and the webhook
  signature scheme.

## Manual checklist before release

1. Redirect checkout: pay, then refund from the order screen.
2. Popup checkout: pay, then refund.
3. Authorize mode: confirm the order lands **on-hold**, not pending.
4. A declined card: confirm the order lands **failed** with the reason shown.
5. A webhook: confirm settlement with the browser closed immediately after
   paying, so the return handler cannot be what settles the order.
6. Check `WooCommerce > Status > Logs`, source `tap`, for warnings.
