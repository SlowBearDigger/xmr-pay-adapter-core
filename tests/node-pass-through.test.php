<?php

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeScanner.php';

use XmrPay\Adapter\Gateway;

class ScannerSpyGateway extends Gateway
{
    public $capturedNodes;

    protected function createScanner($nodes, string $network, int $timeout)
    {
        $this->capturedNodes = $nodes;
        return new FakeScanner(1);
    }
}

$nodes = [[
    'url'                 => 'http://127.0.0.1:38093',
    'auth'                => 'digest',
    'username'            => 'digest-user',
    'password'            => 'digest-password',
    'allow_insecure_http' => true,
]];

$gateway = new ScannerSpyGateway([
    'nodes'        => $nodes,
    'network'      => 'stagenet',
    'http_timeout' => 7,
]);
$gateway->scanner();

ok('structured nodes pass to Scanner unchanged', $gateway->capturedNodes === $nodes, json_encode($gateway->capturedNodes));

$public = $gateway->nodeConfig();
$encoded = json_encode($public);
ok('public node config keeps URL and auth', $public[0]['url'] === $nodes[0]['url'] && $public[0]['auth'] === 'digest', $encoded);
ok('public node config redacts username', strpos($encoded, 'digest-user') === false, $encoded);
ok('public node config redacts password', strpos($encoded, 'digest-password') === false, $encoded);

done_();
