<?php
define('XMRPAY_HOST', true);
$uri = 'monero:4test';
$sub = '4test';
$xmr = '0.1';
$fiat = 'USD';
$err = false;
$qrLibUrl = '/qr.js';
$pollUrl = '/poll</script><script>HAX';
$returnUrl = 'javascript:alert(1)</script><script>HAX';
ob_start();
include __DIR__ . '/../views/pay_card.php';
$html = ob_get_clean();
if (strpos($html, '<script>HAX') !== false || strpos($html, '\u003C\/script\u003E') === false
    || strpos($html, 'document.createTextNode(msg)') === false
    || strpos($html, 'target.origin===location.origin') === false) {
    throw new RuntimeException('payment card left script or response text injectable');
}
echo "payment card output safe\n";
