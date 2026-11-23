# xmr-pay adapter core

This release pairs with the XMRPay PHP engine `^0.2.0`.
It requires exact invoice amounts, output identities and persisted height/hash
checkpoints supplied by that tagged engine release.

The platform-agnostic layer that sits between the [xmr-pay PHP engine](https://github.com/SlowBearDigger/xmr-pay-php)
and a cart's payment plugin. It holds the logic every Monero adapter repeats: a per-order
subaddress, a fiat→XMR amount, a `monero:` URI, and a poll-based settlement loop: so a new gateway
(HikaShop, VirtueMart, J2Store, …) is just config + checkout UI + a small storage class.

Non-custodial and view-key only: funds go straight to the merchant's wallet, the engine verifies in
pure PHP against a node of the merchant's choice. No wallet RPC or separate payment daemon is
required; chain reads use the Monero node's HTTP endpoints.

## Install

The cart packages bundle this library. For a separate Composer application, add
its GitHub VCS repository, then require the tagged version:

```sh
composer config repositories.xmrpay-adapter-core vcs https://github.com/SlowBearDigger/xmr-pay-adapter-core
composer require 'slowbeardigger/xmr-pay-adapter-core:^0.2.0'
```

## Native scan diagnostics

`Gateway::checkOrder()` exposes `scan_status`, `scan_complete`, and `node_error`
separately from its financial `status` and `paid` result. A complete scan with no
receipts remains unpaid.

`Settler::run()` includes an `orders` map with each order's `settled` and
`scan_status`. Storage exceptions produce an error report without exposing exception
text. Invoice amounts must be positive exact decimal strings. The store must serialize
concurrent settlement and make fulfillment idempotent.

## What's in here

| Piece | Role |
|-------|------|
| `Gateway` | Wraps the engine: `subaddressForOrder`, `xmrAmount`, `moneroUri`, `scanRange`, `summarizeMatches`. No platform calls. |
| `OrderStore` | A four-method storage contract the cart implements against its own order table. |
| `OutputOrderStore` | Optional atomic output claims for batched transactions and multi-transaction invoices. |
| `Settler` | The reusable "scan pending → mark paid" loop. Resumes from a checkpoint, matures confirmations across runs, settles once. |
| `ArrayOrderStore` | An in-memory reference store; production adapters need durable storage. |

## Writing a cart adapter

```php
use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

$g = new Gateway([
    'address' => $merchantAddress, 'view_key' => $viewKey,   // protect the view key and node credentials
    'nodes' => [
        ['url' => 'https://node-a:18081', 'auth' => 'digest', 'username' => $nodeUser, 'password' => $nodePassword],
        ['url' => 'https://node-b:18081', 'auth' => 'none'],
    ],
    'network' => 'mainnet', 'min_confirmations' => 10,
]);

// at checkout
$sub = $g->subaddressForOrder($orderId);                      // unique receiving address
$amt = $g->xmrAmount($fiatTotal, 'USD');                      // lock this on the order
$uri = $g->moneroUri($sub, $amt['xmr'], "Order $orderId");    // QR / wallet deep link

// on a schedule (a Joomla task, a WHMCS cron, a WP-cron event)
$report = (new Settler($g, new MyCartStore(), ['min_confirmations' => 10]))->run();
```

`MyCartStore` implements `OrderStore`: `loadPending`, `saveProgress`, `isSettled`, `markPaid` -
against the cart's tables. The legacy `markPaid` must claim the transaction atomically and
make platform fulfillment idempotent; `isSettled` alone cannot close a race.

`saveProgress` must retain `scan_checkpoint`, `partial_block`, `scan_complete` and
the invoice policy fields, including explicit `null` resets. `received_pico` counts
confirmed funds; `seen_pico` also includes observed contributions awaiting confirmation.
For a complete scan of an unpaid order, `Gateway::paymentFeedback($expectedXmr,
$receivedPico, $seenPico)` returns `pending`, `partial` with an exact remaining
amount, or `processing` without payment instructions. Incomplete or failed scans
must withhold payment instructions until verification succeeds.

For batched payments, implement `OutputOrderStore` with `isOutputSettled` and
`markPaidFromOutputs`. Claims require independent uniqueness of `(txid, output_index)`
and `out_key`, written atomically with paid status and fulfillment intent.
Existing `OrderStore` implementations remain compatible and reserve the entire txid;
they must opt into the output contract to settle multiple invoices from one transaction.

`InvoicePolicy::assertPayable($order, $payment, true)` permits explicit acceptance
of an expired request already marked for review. Automatic settlement keeps the
default `false`. The calling integration must authorize the staff action, refresh
the complete canonical scan, verify confirmed outputs and atomically record the
review reason, actor, output claims and accounting. Never forward a client-supplied
flag into this argument. Cancelled, invalid, underfunded and incomplete requests
remain ineligible.

`nodes` also accepts the legacy comma-separated URL string. Structured rows support `none`,
`basic`, and `digest` authentication. Authenticated plain HTTP is blocked unless that row sets
`allow_insecure_http` to `true`; use that opt-in only for a trusted private network. `nodeConfig()`
returns public URL/auth metadata for diagnostics and never returns usernames or passwords.

Custom price feeds must use a public HTTPS endpoint with a public IPv4 address. The adapter rejects
private or reserved destinations and redirects. Custom feeds require cURL with DNS
pinning support; there is no unpinned stream fallback for them.

## Runtime requirements

Requires PHP 7.4+ with the `gmp`, `bcmath` and `mbstring` extensions and the matching
PHP engine `^0.2.0`. Validation suites and integration laboratories are
maintained separately from the runtime package.

Completed scans with a matching checkpoint resume at the following block. The scanner
validates that checkpoint before and after reading new blocks; a changed branch clears
the saved evidence for rescanning. Legacy progress can still require a broader rescan.
Paid orders remain settled; confirmation depth determines the accepted risk of later,
deeper reorganizations.

## Hard rules (inherited from the engine)

- **View key only.** Never accept or store a spend key.
- **Never log or expose the view key.**
- **Healthy, agreeing nodes.** The default `minimum_nodes = 1` accepts a single trusted endpoint and provides no independent cross-check. Set `minimum_nodes = 2` when requiring distinct endpoints. Every configured node must answer and agree on the evidence before settlement.
- **Stagenet first.** Release goods only after the engine confirms a real on-chain payment.
