# xmr-pay adapter core

The platform-agnostic layer that sits between the [xmr-pay PHP engine](https://github.com/SlowBearDigger/xmr-pay-php)
and a cart's payment plugin. It holds the logic every Monero adapter repeats: a per-order
subaddress, a fiat→XMR amount, a `monero:` URI, and a poll-based settlement loop: so a new gateway
(HikaShop, VirtueMart, J2Store, …) is just config + checkout UI + a small storage class.

Non-custodial and view-key only: funds go straight to the merchant's wallet, the engine verifies in
pure PHP against a node of the merchant's choice, and there is no `monero-wallet-rpc` and no daemon.

## What's in here

| Piece | Role |
|-------|------|
| `Gateway` | Wraps the engine: `subaddressForOrder`, `xmrAmount`, `moneroUri`, `scanRange`, `summarizeMatches`. No platform calls. |
| `OrderStore` | A four-method storage contract the cart implements against its own order table. |
| `Settler` | The reusable "scan pending → mark paid" loop. Resumes from a checkpoint, matures confirmations across runs, settles once. |
| `ArrayOrderStore` | An in-memory reference store: copy its shape, and the fixture the tests drive. |

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
against the cart's tables. `markPaid` must claim the transaction atomically and
make platform fulfillment idempotent; `isSettled` alone cannot close a race.

`nodes` also accepts the legacy comma-separated URL string. Structured rows support `none`,
`basic`, and `digest` authentication. Authenticated plain HTTP is blocked unless that row sets
`allow_insecure_http` to `true`; use that opt-in only for a trusted private network. `nodeConfig()`
returns public URL/auth metadata for diagnostics and never returns usernames or passwords.

Custom price feeds must use a public HTTPS endpoint with a public IPv4 address. The adapter rejects
private or reserved destinations and redirects. Custom feeds require cURL with DNS
pinning support; there is no unpinned stream fallback for them.

## Tests

The standalone checks need no Composer installation. A `FakeScanner` supplies controlled
chain evidence; transport checks use local HTTP fixtures.

```
composer test      # or: php tests/gateway.test.php && php tests/settler.test.php
```

Requires PHP 7.4+ with the `gmp` and `bcmath` extensions. Point `XMRPAY_ENGINE` at the engine
checkout if it is not the sibling `../xmr-pay-php`.

For native HikaShop and VirtueMart boundary checks, use a disposable Joomla installation
with both plugins installed and run
`XMRPAY_JOOMLA_BOOTSTRAP=/path/to/bootstrap.php php tests/joomla-boundaries.test.php`.
The bootstrap must initialize administrator services and the database. The test uses
controlled chain evidence, native order models and a database transaction that is rolled back.

After an outage, settlement rescans the overlap before its saved checkpoint. Custom scan
budgets must allow that overlap (at least 15 blocks, or minimum confirmations plus five)
and forward progress. Paid orders remain settled; confirmation depth determines the
accepted risk of later, deeper reorganizations.

## Hard rules (inherited from the engine)

- **View key only.** Never accept or store a spend key.
- **Never log or expose the view key.**
- **Two or more independent nodes** for real revenue. Settlement requires every configured node to answer and agree on each transaction and its block height. If a node is unavailable or disagrees, confirmation pauses until the nodes agree.
- **Stagenet first.** Release goods only after the engine confirms a real on-chain payment.

Run `XMRPAY_JOOMLA_BOOTSTRAP=/path/to/bootstrap.php php tests/joomla-concurrency.test.php` only on a disposable Joomla database with InnoDB tables. It uses separate PHP processes, creates temporary orders from the staging fixtures and deletes them afterward. It verifies concurrent cancellation and rollback after a native write failure.
