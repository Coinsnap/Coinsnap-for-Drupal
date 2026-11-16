# Changelog

## 1.2.0

- Security: Webhooks are verified with the timestamped `X-Coinsnap-Signature` header (Coinsnap) or `BTCPay-Sig` (BTCPay Server), with a timing-safe comparison. A missing webhook secret rejects every webhook.
- Security: A payment is completed only when the invoice is settled, was created by this module for that order (paid with this gateway), and matches the amount and currency the module asked for. Before, a payment was completed for any invoice in the store with a matching order ID, already at "Processing", for the full order total.
- Security: A completed payment is never voided by a later webhook, and each invoice completes a payment once. BTCPay invoices marked settled by hand are not accepted.
- Security: Webhook bodies, request headers (including the signature) and the webhook secret are no longer written to the Drupal log, and the webhook reply no longer echoes the request.
- Security: The library no longer sends an `Access-Control-Allow-Origin: *` header on every page that loads it.
- Fix: The Bitcoin discount read a field that Commerce does not have; it now uses the order's payment gateway.
- Fix: Checking an existing webhook read the stored webhook without decoding it, so it was always re-registered; a payment server outage no longer replaces the webhook.
- Fix: A failed invoice creation shows an error instead of a fatal error (`PaymentGatewayException` was not imported).
- Added: BTCPay Server support and the Bitcoin discount (from 1.1.0).
