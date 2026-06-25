<?php
// Tests the reusable settlement loop end to end against an in-memory store and a faked chain. This
// is the logic every cart adapter shares: resume from a checkpoint, accumulate matches, mature
// confirmations across runs, settle once, never twice.
//   php tests/settler.test.php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeScanner.php';

use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;
use XmrPay\Adapter\ArrayOrderStore;

$cfg  = ['address' => '4test', 'view_key' => '0', 'network' => 'stagenet', 'nodes' => 'http://x:38089'];
$opts = ['min_confirmations' => 5];

// ---- node down: nothing settles, report says so ----
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']]);
$g     = (new Gateway($cfg))->setScanner(new FakeScanner(null));
$rep   = (new Settler($g, $store, $opts))->run();
ok('node-error: status reported, nothing checked', $rep['status'] === 'node-error' && $rep['settled'] === 0, json_encode($rep));

// ---- birthday never set (node was down at checkout): the run sets it and moves on ----
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 0, 'xmr_amount' => '0.05']]);
$g     = (new Gateway($cfg))->setScanner(new FakeScanner(1004));
$rep   = (new Settler($g, $store, $opts))->run();
// birthday was unset (node down at checkout) -> set with a lookback BELOW the tip, so a payment in
// the gap is still scanned (not jumped to the current tip, which would skip it).
ok('birthday unset -> set with lookback below tip, not settled yet', $store->get(1)['birthday_height'] > 0 && $store->get(1)['birthday_height'] < 1004 && $rep['settled'] === 0, (string) $store->get(1)['birthday_height']);

// ---- the core scenario: a payment arrives, immature on run 1, matures by run 2 ----
$pay   = ['sub:1' => [FakeScanner::match(1002, 0.05, 'x')]];   // 0.05 XMR to order 1's subaddress at height 1002
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']]);
$g     = new Gateway($cfg);

// run 1 — tip 1004, only 2 confirmations (< 5): seen but not settled, checkpoint advances
$g->setScanner(new FakeScanner(1004, $pay));
$r1 = (new Settler($g, $store, $opts))->run();
ok('run 1: payment seen but immature -> not settled', $r1['checked'] === 1 && $r1['settled'] === 0, json_encode($r1));
ok('run 1: checkpoint advanced to tip', $store->get(1)['scanned_to'] === 1004, (string) $store->get(1)['scanned_to']);
ok('run 1: match accumulated for next run', count($store->get(1)['matches']) === 1);
// the engine only counts confirmed funds: while the payment is immature it reads as 0 received,
// even though the tx already sits in a block. it matures on a later run without rescanning.
ok('run 1: immature payment recorded as 0 received', $store->get(1)['received_pico'] === '0', $store->get(1)['received_pico']);

// run 2 — tip 1010, now 8 confirmations (>= 5): settles
$g->setScanner(new FakeScanner(1010, $pay));
$r2 = (new Settler($g, $store, $opts))->run();
ok('run 2: matured payment settles', $r2['settled'] === 1, json_encode($r2));
ok('run 2: order marked paid', $store->get(1)['status'] === 'paid');
ok('run 2: txid recorded from the verdict', ($store->get(1)['txid'] ?? '') === 'txx', $store->get(1)['txid'] ?? '');

// run 3 — idempotency: a paid order is no longer pending, so nothing is double-credited
$g->setScanner(new FakeScanner(1020, $pay));
$r3 = (new Settler($g, $store, $opts))->run();
ok('run 3: settled order is not rechecked or recredited', $r3['checked'] === 0 && $r3['settled'] === 0, json_encode($r3));

// ---- an order whose amount was never locked (rate down at checkout) must NEVER settle ----
$unlocked = ['sub:1' => [FakeScanner::match(1002, 0.5, 'u')]];   // a real, well-confirmed payment arrives
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '']]);   // but no locked amount
$g     = (new Gateway($cfg))->setScanner(new FakeScanner(1050, $unlocked));
$rep   = (new Settler($g, $store, $opts))->run();
ok('unlocked (empty) amount never settles, even with a confirmed payment', $rep['settled'] === 0 && $store->get(1)['status'] === 'pending', json_encode($rep));

// ---- underpayment never settles, however long it sits ----
$short = ['sub:1' => [FakeScanner::match(1002, 0.03, 'y')]];   // only 0.03 of the 0.05 owed
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']]);
$g     = (new Gateway($cfg))->setScanner(new FakeScanner(1050, $short));
$rep   = (new Settler($g, $store, $opts))->run();
ok('underpayment with deep confirmations still does not settle', $rep['settled'] === 0 && $store->get(1)['status'] === 'pending', json_encode($rep));

// ---- settleOrder: the single-order fast path the checkout poll uses ----
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']]);
$g     = (new Gateway($cfg))->setScanner(new FakeScanner(1004, $pay));   // immature: 2 confs
$row   = $store->get(1);
$rep   = (new Settler($g, $store, $opts))->settleOrder($row);
ok('settleOrder: immature -> not paid yet, but scan state persisted', $rep['paid'] === false && $store->get(1)['scanned_to'] === 1004, json_encode($rep));

$g->setScanner(new FakeScanner(1010, $pay));   // matured: 8 confs
$rep = (new Settler($g, $store, $opts))->settleOrder($store->get(1));
ok('settleOrder: matured -> paid now, order settled immediately', $rep['paid'] === true && $rep['settled'] === 1 && $store->get(1)['status'] === 'paid', json_encode($rep));

$g->setScanner(new FakeScanner(null));   // node down
$rep = (new Settler($g, $store, $opts))->settleOrder(['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']);
ok('settleOrder: node down -> node-error, nothing checked', $rep['status'] === 'node-error' && $rep['checked'] === 0, json_encode($rep));

// ---- dedup guard: if the txid was already credited, markPaid is skipped ----
$store = new ArrayOrderStore([['id' => 1, 'birthday_height' => 1000, 'xmr_amount' => '0.05']]);
$store->markPaid(99, 'txx', []);   // pretend txx was already credited elsewhere
$store->saveProgress(1, []);       // order 1 still pending
$g = (new Gateway($cfg))->setScanner(new FakeScanner(1010, $pay));
$rep = (new Settler($g, $store, $opts))->run();
ok('dedup: an already-credited txid is not counted again', $rep['settled'] === 0, json_encode($rep));

done_();
