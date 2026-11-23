# Changelog

## [0.2.1] - 2026-11-23

- Update the checkout amount, wallet link and QR code to the confirmed remaining balance after a partial payment.
- Keep payment instructions hidden while observed funds await confirmation or payment verification is unavailable.

## [0.2.0] - 2026-11-21

- Recheck the overlap before the saved checkpoint after an outage so orphaned cached matches cannot mature into a payment.
- Report failed clipboard writes without claiming that the payment address was copied.
- Require the PHP engine at ^0.2.0 instead of an unrestricted version.
- Restrict custom rate feeds to public HTTPS destinations with pinned DNS and no redirects.
- Escape checkout output and retain reorganisation-aware scan state. Custom feeds require cURL support.
