<?php
// Reconcile stored payment requests against daemon evidence.

namespace XmrPay\Adapter;

use XmrPay\Util;

class Settler
{
    private Gateway $g;
    private OrderStore $store;
    private array $opts;

    public function __construct(Gateway $g, OrderStore $store, array $opts = [])
    {
        $this->g     = $g;
        $this->store = $store;
        $this->opts  = $opts;
    }

    public function run(): array
    {
        $report = ['checked' => 0, 'settled' => 0, 'status' => 'ok', 'orders' => []];

        if (!$this->g->cryptoReady()) {
            $report['status'] = 'crypto-missing';
            return $report;
        }
        $tip = $this->g->scanner()->tip_height();
        if ($tip === null) {
            $report['status'] = 'node-error';
            return $report;
        }

        $p = $this->params();
        foreach ($this->store->loadPending() as $o) {
            $report['checked']++;
            try {
                $result = $this->step($o, $tip, $p);
                $report['orders'][(int) $o['id']] = $result;
                if ($result['settled']) {
                    $report['settled']++;
                }
            } catch (\Throwable $e) {
                $report['status'] = 'error';
                $report['orders'][(int) $o['id']] = ['settled' => false, 'scan_status' => 'error'];
                continue;
            }
        }
        return $report;
    }

    public function settleOrder(array $order): array
    {
        $report = ['checked' => 1, 'settled' => 0, 'status' => 'ok', 'paid' => false];

        if (!$this->g->cryptoReady()) {
            $report['status'] = 'crypto-missing';
            $report['checked'] = 0;
            return $report;
        }
        $tip = $this->g->scanner()->tip_height();
        if ($tip === null) {
            $report['status'] = 'node-error';
            $report['checked'] = 0;
            return $report;
        }

        try {
            $r = $this->step($order, $tip, $this->params());
        } catch (\Throwable $e) {

            $report['status'] = 'error';
            return $report;
        }
        $report['settled'] = $r['settled'] ? 1 : 0;
        $report['paid']    = $r['settled'];
        $report['scan_status'] = $r['scan_status'] ?? 'incomplete';
        return $report;
    }

    private function params(): array
    {

        $minConf = max(1, (int) ($this->opts['min_confirmations'] ?? 10));
        return [
            'min_conf'    => $minConf,
            'reorg'       => max(15, $minConf + 5),
            'max_blocks'  => (int) ($this->opts['max_blocks'] ?? 1000),
            'time_budget' => (float) ($this->opts['time_budget'] ?? 20.0),

            'lookback'    => max(0, (int) ($this->opts['birthday_lookback'] ?? 1440)),
        ];
    }

    private function step(array $o, int $tip, array $p): array
    {
        $id = (int) $o['id'];

        $expected = $o['xmr_amount'] ?? '';
        try {
            $expectedAtomic = Util::xmr_to_pico_exact($expected);
        } catch (\InvalidArgumentException $error) {
            return ['settled' => false, 'scan_status' => 'invalid-amount'];
        }
        if (gmp_cmp($expectedAtomic, '0') <= 0) {
            return ['settled' => false, 'scan_status' => 'invalid-amount'];
        }

        $birthday = (int) ($o['birthday_height'] ?? 0);
        if ($birthday <= 0) {

            $this->store->saveProgress($id, ['birthday_height' => max(1, $tip - $p['lookback'])]);
            return ['settled' => false, 'scan_status' => 'waiting-birthday'];
        }

        $prevScanned = (int) ($o['scanned_to'] ?? 0);

        $from = $prevScanned > 0 ? max($birthday, min($prevScanned + 1, $tip) - $p['reorg']) : $birthday;
        if ($prevScanned > 0 && !isset($o['scan_checkpoint'])) {
            $from = $birthday;
        }
        if (($o['scan_complete'] ?? true) === false && isset($o['scan_checkpoint'])) {
            $from = $prevScanned + 1;
        }
        if (($o['scan_complete'] ?? false) === true && ($o['partial_block'] ?? null) === null
            && $prevScanned < $tip && ($o['scan_checkpoint']['height'] ?? null) === $prevScanned) {
            $from = $prevScanned + 1;
        }
        $accumulated = isset($o['matches']) && is_array($o['matches']) ? $o['matches'] : [];
        foreach ($accumulated as $match) {
            if (!empty($match['locked']) && !isset($match['unlock_time'])) {
                $from = $birthday;
                break;
            }
        }

        $scanOptions = [
            'max_blocks' => $p['max_blocks'],
            'time_budget' => $p['time_budget'],
            'restore_height' => $birthday,
            'tip' => $tip,
        ];
        if (isset($o['scan_checkpoint'])) {
            $scanOptions['checkpoint'] = $o['scan_checkpoint'];
        }
        if (!empty($o['partial_block'])) {
            $scanOptions['partial_block'] = $o['partial_block'];
        }

        try {
            $res = $this->g->scanRange($id, $from, $tip - 1, $scanOptions);
        } catch (\Throwable $e) {
            return ['settled' => false, 'scan_status' => 'error'];
        }

        $scanStatus = $res['scan_status'] ?? (Gateway::scanComplete($res, $tip) ? 'complete' : 'incomplete');

        if (!empty($res['checkpoint_error'])) {
            return ['settled' => false, 'scan_status' => $scanStatus];
        }
        if (!empty($res['reorg_detected'])) {
            $this->store->saveProgress($id, [
                'matches' => [],
                'scanned_to' => $birthday - 1,
                'scan_checkpoint' => null,
                'partial_block' => null,
                'scan_complete' => false,
                'received_pico' => '0',
                'funded_at' => null,
                'funded_amount_atomic' => null,
            ]);
            return ['settled' => false, 'scan_status' => $scanStatus];
        }

        $merged      = Gateway::mergeMatches($accumulated, $res['matches'] ?? [], $from);
        $scannedTo   = (int) ($res['scanned_to'] ?? $from);

        $verdict = $this->g->summarizeMatches($merged, (string) $o['xmr_amount'], $tip, $p['min_conf']);
        $complete = Gateway::scanComplete($res, $tip);
        $invoice = InvoicePolicy::evaluate(array_merge($o, ['scan_complete' => $complete]), $verdict, $expectedAtomic, time());

        $this->store->saveProgress($id, array_merge([
            'matches'       => $merged,
            'scanned_to'    => $scannedTo,
            'scan_checkpoint' => $res['checkpoint'] ?? null,
            'partial_block' => $res['partial_block'] ?? null,
            'scan_complete' => $complete,
            'received_pico' => $verdict['received_pico'] ?? '0',
        ], $invoice['patch']));

        if (!$invoice['may_settle']) {
            return ['settled' => false, 'scan_status' => $scanStatus, 'invoice_status' => $invoice['patch']['invoice_status']];
        }

        if (!Gateway::scanComplete($res, $tip) || empty($verdict['paid'])) {
            return ['settled' => false, 'scan_status' => $scanStatus];
        }

        if (empty($verdict['txids'])) {
            return ['settled' => false, 'scan_status' => $scanStatus];
        }
        if ($this->store instanceof OutputOrderStore) {
            $receipts = $this->g->confirmedOutputs($merged, $tip, $p['min_conf']);
            if (!$receipts) {
                return ['settled' => false, 'scan_status' => 'invalid-receipts'];
            }
            foreach ($receipts as $receipt) {
                if ($this->store->isOutputSettled($receipt)) {
                    return ['settled' => false, 'scan_status' => 'outputs-already-settled'];
                }
            }
            $verdict['txids'] = array_values(array_unique(array_column($receipts, 'txid')));
            $this->store->markPaidFromOutputs($id, $receipts, $verdict);
            return ['settled' => true, 'scan_status' => $scanStatus];
        }
        $txid = (string) $verdict['txids'][0];
        if (!$this->store->isSettled($txid)) {
            $this->store->markPaid($id, $txid, $verdict);
            return ['settled' => true, 'scan_status' => $scanStatus];
        }
        return ['settled' => false, 'scan_status' => $scanStatus];
    }
}
