# xmr-pay for Joomla — suite roadmap

Goal: a Monero payment suite for Joomla 5.4+, covering the major commerce extensions, all on the
shared engine + adapter core. "Universal" means the engine and this core are shared; each cart gets
a thin adapter, and they ship in one installable package.

Joomla itself has no payment concept — payments belong to a commerce extension. Helix Ultimate is a
template, not a cart, so it does not pick the integration point. The carts, in popularity order:
**HikaShop → VirtueMart → J2Store.**

## Phase 0 — Shared core ✅ DONE

`xmr-pay-adapter-core`: `Gateway`, `OrderStore`, `Settler`, `ArrayOrderStore`. Generalized from the
WHMCS core (invoice→order) and lifted the settlement loop out of the WHMCS cron so it is reusable.
30 unit tests green, WP-free, no network. This is the layer all three carts sit on.

After the cart-API validation below, two improvements folded in:
- `Settler::settleOrder($row)` — settle one order now (the checkout "is it paid yet?" poll path),
  not just the full `run()` sweep.
- `OrderStore` contract documents the dedup/concurrency rule (txid UNIQUE constraint, not the PHP
  check) and the "read the locked xmr_amount back, never re-derive" rule.

## Cart fit — validated 2026-06-24

Three doc+code reviews (one per cart) vs the contract. **Verdict: clean fit, no contract change
required.** Common findings: every cart has a stable integer order id, exposes total+currency at
checkout, natively supports "place pending → confirm later" (their offline/bank-transfer plugin is
the reference template), and confirms via a Joomla 5 `task` Scheduler plugin. None dedupes external
txids — that is the adapter's job (DB UNIQUE), which is exactly what `isSettled` isolates.

- **HikaShop** — `plgHikashoppaymentXmrpay extends hikashopPaymentPlugin`, group `hikashoppayment`.
  Order id `$order->order_id`; total `full_total->prices[0]->price_value_with_tax`; currency resolve
  `order_currency_id`→`currency_code`. Checkout: `onAfterOrderConfirm` → `showPage('end')`. Mark
  paid: `modifyOrder($id,'confirmed',$history,$email)`. Scan state on `order_payment_params` (no
  extra table) + small `#__xmrpay_txids` for dedup. Leave order in `created`, not built-in
  `pending`. Legacy plugin format still works on J5.4.
- **VirtueMart** — `plgVmPaymentXmrpay extends vmPSPlugin`, group `vmpayment`. Order id
  `virtuemart_order_id`; total `BT->order_total`; currency via `plgVmgetPaymentCurrency`. Checkout:
  `plgVmConfirmedOrder` sets status `'P'` + renders QR. Mark paid: `updateStatusForOneOrder($id,
  $order,'C')`. Scan state in the plugin's own table (`getTableSQLFields` + `storePSPluginInternalData`
  / `getDataByOrderId`) — add columns incl. `settled_txid` UNIQUE. Reference: bundled `standard`
  plugin. Task bootstrap: `VmConfig::loadConfig()`. VM4 on J5 needs the compatibility plugin.
- **J2Store** — `plgJ2StorePayment_xmrpay extends J2StorePaymentPlugin`, group `j2store`,
  `$_element='payment_xmrpay'`. Order id `$data['order_id']` (NOT `orderpayment_id`); total
  `$order->order_total`. Checkout: `_prePayment` renders QR; `_postPayment('display')` = waiting
  screen. Mark paid: `$order->payment_complete()` + `update_status()`, txid on `transaction_id`.
  No native order-meta → own table `#__xmrpay_order_scan` (order_id PK, scan state, `settled_txid`
  UNIQUE). Reference: offline/bank-transfer plugin. NOTE: J2Store rebranded to **J2Commerce 6** for
  the J5.4 line — verify group/base-class/tables on a live J2Commerce 6 install.

All version-specific specifics (namespaced vs legacy format, exact status codes, J2Commerce rebrand,
VM compatibility plugin) are flagged to re-verify against the live installs stood up in Phase 1.

## Phase 1 — Joomla dev environment ✅ DONE (2026-06-24)

`~/Documents/xmr-pay-joomla-dev` — Docker stack, Joomla 5.4.6 (PHP 8.3, ships gmp+bcmath) + MariaDB,
headless auto-install, port 8091. Core + engine bind-mounted read-only.
- **Engine + core proven in-runtime:** all 30 adapter-core tests green under the container's PHP 8.3
  with gmp/bcmath — the real target runtime, which WHMCS never had cleanly.
- **HikaShop 6.5.0 (Starter, free) installed & enabled** via `extension:install --path` (Joomla 5.4
  console installer). Phase 2 ready. Reference offline plugins present (bank transfer, check) to copy
  the pending→confirm flow from. Free edition is "Starter" not "Essential"; payment API is
  edition-independent.
- **VirtueMart 4.6.4** core registered but CLI install errors on its post-install `redirect()` (VM
  installs only in a web request) — finish first-run setup + AIO via the admin UI in Phase 3.
- **J2Store/J2Commerce** deferred to Phase 4 (rebranded J2Commerce 6, download likely gated).
- Reuse the engine's stagenet config (node + view-key PoC wallet) for end-to-end tests in Phase 2.

## Phase 2 — HikaShop adapter ✅ COMPLETE (2026-06-24)

Built `~/Documents/xmr-pay-hikashop` — payment plugin (`plgHikashoppaymentXmrpay`) + task plugin
(`plg_task_xmrpaysettle`) + `HikashopOrderStore` + vendored engine + client-side QR. Installs on
Joomla 5.4.6 + HikaShop 6.5.0. **Settlement proven against real stagenet via both paths**: the
checkout poll and the scheduled **Web Cron** sweep each scanned the live node, found the payment,
flipped the order to confirmed, and deduped the txid (re-poll → `already-paid`, no double credit).
**Full checkout walkthrough in a browser** renders the Monero payment screen with the locked amount,
real subaddress, `monero:` deep link, a client-side QR, and the live poll. Three bugs found+fixed in
the process (curl-first rate fetch, inline body scripts, hardcoded strings). HikaShop is web-app only
so the task runs under Web Cron, not the CLI scheduler. See the package README.

### Original plan

- `plg_hikashoppayment_xmrpay`: config (address / view_key / nodes / network / min_confirmations),
  checkout view (subaddress + QR from `moneroUri` + locked XMR amount + live poll).
- A `HikashopOrderStore implements OrderStore` over HikaShop's order tables.
- A Joomla 5 **Scheduler task plugin** that runs `Settler::run()` — replaces the WHMCS cron.
- An AJAX endpoint for the checkout "is it paid yet?" poll.
- Verify end-to-end with a real stagenet payment, same bar as the WHMCS proof.

## Phase 3 — VirtueMart adapter ✅ COMPLETE (2026-06-24)

`~/Documents/xmr-pay-virtuemart` — `plgVmPaymentXmrpay extends vmPSPlugin` + `plg_task_xmrpaysettle_vm`
+ `VirtueMartOrderStore` + `post_payment.php` view (QR + poll) + vendored engine + QR lib. Verified
against VirtueMart 4.6.4 source. **Full checkout proven**: a real
VirtueMart checkout (product → cart → shipment + Monero → Confirm Purchase) renders the Monero payment
screen with the locked amount, real subaddress, `monero:` deep link, client-side QR, and live poll;
the order is created pending (status `U`), scan state stored in `#__virtuemart_payment_plg_xmrpay`, the
poll advanced the checkpoint, and the **Web Cron task ran clean** (`last_exit_code=0`). Per-order scan
state in the plugin's own table; mark-paid via `updateStatusForOneOrder`; dedup via the `xmr_txid`
column; poll via the vmplg `pluginNotification` route → `Settler::settleOrder`. Fixed one signature bug
(`plgVmDeclarePluginParamsPaymentVM3` takes 1 arg). VM needed AIO + sample-data install to be
operational; `payment_params` use VM's pipe `key="value"|` format. See the package README.

## Phase 4 — J2Store adapter

- J2Store payment plugin + a `J2StoreOrderStore`. Same shared pieces.

## Phase 5 — Suite packaging

- `pkg_xmrpay`: one installable bundling the engine (vendored), this core, the three cart plugins,
  and the scheduler task. The installer enables only the plugin for a cart that is present.
- Per-cart docs, stagenet-first guidance, the view-key-only security note.

## Phase 6 — Release

- Joomla Extension Directory (JED) listing. Mirror the engine's honest comparison framing.

## What each cart actually costs

The Monero work is done. Per cart the new code is: a config form, a checkout template, and an
`OrderStore` (~the four methods of `ArrayOrderStore`). The scheduler task and the settlement logic
are written once, here. A cart adapter is roughly a weekend, most of it the cart's own scaffolding.
