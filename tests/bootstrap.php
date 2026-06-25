<?php
// Test bootstrap. Loads the xmr-pay-php engine WITHOUT Composer (manual requires in dependency
// order, same as the engine's own tests) and then this package's src, so the suite runs under plain
// `php` with no install step. Point XMRPAY_ENGINE at the engine checkout if it is not the sibling
// default ~/Documents/xmr-pay-php.

$engine = getenv('XMRPAY_ENGINE');
if (!$engine || !is_dir($engine)) {
    $engine = dirname(__DIR__, 2) . '/xmr-pay-php';   // sibling checkout
}
if (!is_dir($engine)) {
    fwrite(STDERR, "engine not found at $engine — set XMRPAY_ENGINE\n");
    exit(2);
}

foreach ([
    'third-party/monero/base58.php',
    'third-party/monero/Varint.php',
    'third-party/monero/Keccak.php',
    'third-party/monero/ed25519.php',
    'third-party/monero/Cryptonote.php',
    'src/Util.php',
    'src/Scanner.php',
] as $f) {
    require_once "$engine/$f";
}

foreach (['Gateway', 'OrderStore', 'ArrayOrderStore', 'Settler'] as $c) {
    require_once dirname(__DIR__) . "/src/$c.php";
}

// tiny shared assert helper, same style as the engine suite
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function ok($n, $c, $x = '')
{
    if ($c) {
        $GLOBALS['__pass']++;
        echo "PASS  $n\n";
    } else {
        $GLOBALS['__fail']++;
        echo "FAIL  $n" . ($x !== '' ? "  — $x" : '') . "\n";
    }
}
function done_()
{
    $f = $GLOBALS['__fail'];
    echo "\n" . ($f ? "FAILED ($f)" : 'ALL GREEN') . "  {$GLOBALS['__pass']} passed, $f failed\n";
    exit($f ? 1 : 0);
}
