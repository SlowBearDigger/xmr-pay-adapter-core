# xmr-pay adapter core

The platform-agnostic layer that sits between the [xmr-pay PHP engine](https://github.com/SlowBearDigger/xmr-pay-php)
and a cart's payment plugin. It holds the logic every Monero adapter repeats — a per-order
subaddress, a fiat→XMR amount, a `monero:` URI, and a poll-based settlement loop — so a new gateway
(HikaShop, VirtueMart, J2Store, …) is just config + checkout UI + a small storage class.

Non-custodial and view-key only: funds go straight to the merchant's wallet, the engine verifies in
pure PHP against a node of the merchant's choice, and there is no `monero-wallet-rpc` and no daemon.

## What's in here

| Piece | Role |
|-------|------|
| `Gateway` | Wraps the engine: `subaddressForOrder`, `xmrAmount`, `moneroUri`, `scanRange`, `summarizeMatches`. No platform calls. |
| `OrderStore` | A four-method storage contract the cart implements against its own order table. |
| `Settler` | The reusable "scan pending → mark paid" loop. Resumes from a checkpoint, matures confirmations across runs, settles once. |
| `ArrayOrderStore` | An in-memory reference store — copy its shape, and the fixture the tests drive. |

## Writing a cart adapter

```php
use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;

$g = new Gateway([
    'address' => $merchantAddress, 'view_key' => $viewKey,   // view key is the only secret
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

`MyCartStore` implements `OrderStore` — `loadPending`, `saveProgress`, `isSettled`, `markPaid` —
against the cart's tables. That class plus a checkout screen is the whole adapter.

`nodes` also accepts the legacy comma-separated URL string. Structured rows support `none`,
`basic`, and `digest` authentication. Authenticated plain HTTP is blocked unless that row sets
`allow_insecure_http` to `true`; use that opt-in only for a trusted private network. `nodeConfig()`
returns public URL/auth metadata for diagnostics and never returns usernames or passwords.

## Tests

Pure PHP, no Composer, no network — a `FakeScanner` stands in for the chain so the real engine logic
is exercised against canned blocks.

```
composer test      # or: php tests/gateway.test.php && php tests/settler.test.php
```

Requires PHP 7.4+ with the `gmp` and `bcmath` extensions. Point `XMRPAY_ENGINE` at the engine
checkout if it is not the sibling `../xmr-pay-php`.

## Hard rules (inherited from the engine)

- **View key only.** Never accept or store a spend key.
- **Never log or expose the view key.**
- **Two or more nodes** for real revenue, so a lagging or lying node can only delay, never confirm early.
- **Stagenet first.** Release goods only after the engine confirms a real on-chain payment.
