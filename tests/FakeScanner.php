<?php
// A no-network stand-in for XmrPay\Scanner used by the core tests. It returns canned subaddresses
// and canned match rows; the verdict itself is still computed by the real engine (Util) inside
// Gateway::summarizeMatches, so the logic under test is real — only the chain is faked.

class FakeScanner
{
    private ?int $tip;
    /** @var array<string,array> subaddress => match rows (each with a block_height) */
    private array $byAddress;

    public function __construct(?int $tip, array $byAddress = [])
    {
        $this->tip       = $tip;
        $this->byAddress = $byAddress;
    }

    public function tip_height()
    {
        return $this->tip;
    }

    public function verify_keys($address, $viewKey): array
    {
        return ['address_valid' => true, 'key_match' => true];
    }

    /** Deterministic fake subaddress per minor index, so scan_all can key off it. */
    public function subaddress($major, $minor, $viewKey, $primary): array
    {
        return ['address' => 'sub:' . $minor];
    }

    /** Return the canned rows for this subaddress that fall inside [from, to]. */
    public function scan_all($address, $viewKey, $from, $to, $opts = []): array
    {
        $rows = [];
        foreach ($this->byAddress[$address] ?? [] as $r) {
            $h = (int) ($r['block_height'] ?? 0);
            if ($h >= $from && $h <= $to) {
                $rows[] = $r;
            }
        }
        return ['matches' => $rows, 'scanned_to' => (int) $to];
    }

    /** Build a match row the engine's summarize_payments accepts. */
    public static function match(int $height, $xmr, string $key): array
    {
        return [
            'txid'          => 'tx' . $key,
            'amount_atomic' => (string) \XmrPay\Util::xmr_to_pico((string) $xmr),
            'output_index'  => 0,
            'confirmations' => 0,
            'in_pool'       => false,
            'locked'        => false,
            'out_key'       => $key,
            'commitment_ok' => true,
            'block_height'  => $height,
        ];
    }
}
