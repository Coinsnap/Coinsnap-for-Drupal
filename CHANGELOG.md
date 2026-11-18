# Changelog

## 1.2.0

- Security: Webhooks are verified with the timestamped `X-Coinsnap-Signature` header (Coinsnap) or `BTCPay-Sig` (BTCPay Server), with a timing-safe comparison. A missing webhook secret rejects every webhook.
- Security: A payment is completed only when the invoice is settled, was created by this module for that order (paid with this gateway), and matches the amount and currency the module asked for. Before, a payment was completed for any invoice in the store with a matching order ID, already at "Processing", for the full order total.
- Security: If the order total changes after the invoice was created (the cart was edited after going back from the payment page), the old invoice no longer completes the payment.
- Security: A completed payment is never voided by a later webhook, and each invoice completes a payment once. BTCPay invoices marked settled by hand are not accepted.
- Security: Webhook bodies, request headers (including the signature) and the webhook secret are no longer written to the Drupal log, and the webhook reply no longer echoes the request.
- Security: The API key fields no longer show the saved key; leave them empty to keep it. A BTCPay key is not kept when the BTCPay Server URL changes.
- Security: The library no longer sends an `Access-Control-Allow-Origin: *` header on every page that loads it.
- Security: Each payment gateway has its own webhook URL (Commerce's `/payment/notify/<gateway machine name>`), built from the new "Site address for the webhook" setting instead of the Host header of the admin request. Two Coinsnap gateways no longer share one URL, where only the gateway named `bitcoin_lightning` could verify webhooks. The module's own `/payment/notify/bitcoin_lightning` route is removed; Commerce's route serves the same paths.
- Security: The return page places the order only when the API says one of the order's invoices is paid or being paid. Before, the order was placed when the API could not be reached or the order had no recorded invoice. Otherwise the webhook completes the payment and Commerce places the order.
- Security: Orders started before 1.2.0 (no invoice record) are accepted from a webhook only for 24 hours after the update (`drush updb` / update.php).
- Fix: Saving the gateway settings no longer deletes the stored webhook and its secret (Commerce empties the configuration on save), which made every later webhook fail with 401.
- Fix: When the webhook is registered again, the old one is deleted only after the new one exists, and only webhooks with exactly this gateway's URL are removed.
- Fix: A BTCPay rates error no longer causes a fatal error (WordPress `esc_html()` call in the library).
- Fix: The exchange rate lookup gives up after 3 tries instead of retrying forever, which could hang checkout.
- Fix: The Bitcoin discount read a field that Commerce does not have; it now uses the order's payment gateway.
- Fix: Checking an existing webhook read the stored webhook without decoding it, so it was always re-registered; a payment server outage no longer replaces the webhook.
- Fix: A failed invoice creation shows an error instead of a fatal error (`PaymentGatewayException` was not imported).
- Added: BTCPay Server support and the Bitcoin discount (from 1.1.0).
