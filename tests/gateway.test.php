<?php
// Pure-logic tests for the platform-agnostic Gateway: order->index mapping, fiat->XMR, the monero:
// URI, and the merge/summarize primitives. No network — a FakeScanner stands in where one is needed.
//   php tests/gateway.test.php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeScanner.php';

use XmrPay\Adapter\Gateway;

$cfg = ['address' => '4test', 'view_key' => '0', 'network' => 'stagenet', 'nodes' => 'http://x:38089'];

// ---- order -> subaddress index (0 reserved for the primary address) ----
$g = (new Gateway($cfg))->setScanner(new FakeScanner(0));
ok('legacy node URL remains available as public metadata', $g->nodeConfig()[0]['url'] === 'http://x:38089' && $g->nodeConfig()[0]['auth'] === 'none');
ok('indexForOrder: 1 -> 1', $g->indexForOrder(1) === 1);
ok('indexForOrder: 42 -> 42', $g->indexForOrder(42) === 42);
$gOff = (new Gateway($cfg + ['index_offset' => 1000]))->setScanner(new FakeScanner(0));
ok('index_offset shifts: 5 -> 1005', $gOff->indexForOrder(5) === 1005);
ok('subaddressForOrder derives from the index', $g->subaddressForOrder(7) === 'sub:7', $g->subaddressForOrder(7));

// ---- fiat -> XMR (injected rate, no network) and the XMR-priced identity path ----
$r = $g->xmrAmount(10.0, 'USD', function ($c) { return 0.0066; });   // 10 * 0.0066
ok('xmrAmount(10 USD @ 0.0066) -> 0.066', $r['xmr'] === '0.066', $r['xmr']);
ok('xmrAmount returns the locked rate', $r['rate'] === '0.0066', (string) $r['rate']);
// regression: a comma-decimal locale (de/es/fr) must not corrupt the XMR amount. sprintf('%.12f')
// is LC_NUMERIC-aware and would yield "0,066" -> parsed as 0; number_format with explicit '.' is safe.
$savedLocale = setlocale(LC_NUMERIC, 0);
if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'es_ES.UTF-8', 'fr_FR.UTF-8', 'de_DE.utf8')) {
    $rl = $g->xmrAmount(10.0, 'USD', function ($c) { return 0.0066; });
    setlocale(LC_NUMERIC, $savedLocale ?: 'C');
    ok('xmrAmount is locale-safe (comma LC_NUMERIC still yields 0.066)', $rl['xmr'] === '0.066', $rl['xmr']);
} else {
    echo "SKIP  locale-safe xmrAmount — no comma-decimal locale installed on this host\n";
}
$rx = $g->xmrAmount(0.5, 'XMR');
ok('xmrAmount(0.5 XMR) -> identity, no rate', $rx['xmr'] === '0.5' && $rx['rate'] === null, $rx['xmr']);
$threw = false;
try { $g->xmrAmount(10.0, 'USD', function ($c) { return 0.0; }); } catch (\RuntimeException $e) { $threw = true; }
ok('xmrAmount throws when no rate is available', $threw);

// ---- merchant pricing source: fixed rate / custom url / default ----
ok('rateFetcher(coingecko) and rateFetcher([]) use the default (null)', Gateway::rateFetcher(['rate_source' => 'coingecko']) === null && Gateway::rateFetcher([]) === null);
$rf = Gateway::rateFetcher(['rate_source' => 'fixed', 'fixed_rate' => '100']);   // 1 XMR = 100 of the store currency
ok('rateFetcher(fixed) returns a callable', is_callable($rf));
$rfx = $g->xmrAmount(10.0, 'USD', $rf);
ok('fixed rate: 10 @ (1 XMR = 100) -> 0.1 XMR, no network', $rfx['xmr'] === '0.1', $rfx['xmr']);
ok('fixed rate prices ANY currency code the same', $g->xmrAmount(10.0, 'CUP', $rf)['xmr'] === '0.1');
$threwF = false;
try { $g->xmrAmount(10.0, 'USD', Gateway::rateFetcher(['rate_source' => 'fixed', 'fixed_rate' => '0'])); } catch (\RuntimeException $e) { $threwF = true; }
ok('misconfigured fixed rate (0) fails closed — throws, never a wrong amount', $threwF);
ok('rateFetcher(custom) returns a callable', is_callable(Gateway::rateFetcher(['rate_source' => 'custom', 'rate_url' => 'https://x/{currency}'])));

// ---- poll token (storage-free, HMAC over order id keyed on the view key) ----
$tk = Gateway::orderToken(42, 'viewkeyA');
ok('orderToken stable for same (order, secret)', $tk === Gateway::orderToken(42, 'viewkeyA'));
ok('orderToken differs by order id', $tk !== Gateway::orderToken(43, 'viewkeyA'));
ok('orderToken differs by secret', $tk !== Gateway::orderToken(42, 'viewkeyB'));
ok('orderToken is 24 hex chars', preg_match('/^[0-9a-f]{24}$/', $tk) === 1, $tk);

// ---- monero: URI ----
ok('moneroUri carries the amount', $g->moneroUri('sub:1', '0.066') === 'monero:sub:1?tx_amount=0.066', $g->moneroUri('sub:1', '0.066'));
ok('moneroUri appends an encoded label', $g->moneroUri('sub:1', '0.066', 'Order 9') === 'monero:sub:1?tx_amount=0.066&recipient_name=Order%209');

// ---- mergeMatches keeps deep history, replaces the rescanned window ----
$mk  = ['FakeScanner', 'match'];
$acc = [call_user_func($mk, 100, 0.01, 'a'), call_user_func($mk, 200, 0.02, 'b')];
ok('mergeMatches: from=200 keeps rows below 200 + appends fresh', count(Gateway::mergeMatches($acc, [call_user_func($mk, 205, 0.03, 'c')], 200)) === 2);
ok('mergeMatches: from=201 preserves the row at 200', count(Gateway::mergeMatches($acc, [call_user_func($mk, 205, 0.03, 'c')], 201)) === 3);

// ---- summarizeMatches recomputes confirmations from the tip ----
$one = [call_user_func($mk, 1000, 0.05, 'x')];
ok('summarizeMatches: matured (tip 1010, conf 10 >= 5) settles', !empty($g->summarizeMatches($one, '0.05', 1010, 5)['paid']));
ok('summarizeMatches: young (tip 1003, conf 3 < 5) does not settle', empty($g->summarizeMatches($one, '0.05', 1003, 5)['paid']));
ok('summarizeMatches: short payment does not settle', empty($g->summarizeMatches($one, '0.06', 1010, 5)['paid']));

// ---- partialFeedback: the buyer-facing "you sent X, send Y more" figures ----
ok('partialFeedback: nothing received -> null (plain waiting state)', Gateway::partialFeedback('0.5', '0') === null);
ok('partialFeedback: empty received -> null', Gateway::partialFeedback('0.5', '') === null);
$pf = Gateway::partialFeedback('0.79', '100000000000');   // owed 0.79, got 0.1
ok('partialFeedback: underpayment -> received + shortfall in XMR', $pf !== null && $pf['received'] === '0.1' && $pf['shortfall'] === '0.69', json_encode($pf));
ok('partialFeedback: exact amount -> null (settles, not partial)', Gateway::partialFeedback('0.1', '100000000000') === null);
ok('partialFeedback: overpayment -> null (settles, not partial)', Gateway::partialFeedback('0.05', '100000000000') === null);

done_();
