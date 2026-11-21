<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
$network = getenv('BTCO_NETWORK') ?: 'mainnet';
if (!in_array($network, ['mainnet', 'testnet'], true)) {
    throw new RuntimeException('BTCO_NETWORK must be mainnet or testnet.');
}
return [
    'debug' => false,
    'canonical_host' => getenv('EXPLORER_HOST') ?: 'localhost:8080',
    'cache_db' => __DIR__ . "/db/btco-$network.sqlite",
    'cors_origin' => '*',
    'trust_cf_ip' => false,
    'extern_links' => false,
    'price' => ['enabled' => false],
    'networks' => [
        "btco-$network" => [
            'enabled' => true,
            'rpc' => [
                'url' => getenv('BTCO_RPC_URL') ?: 'http://127.0.0.1:19711/',
                'user' => getenv('BTCO_RPC_USER') ?: '',
                'pass' => getenv('BTCO_RPC_PASSWORD') ?: '',
                'verbosity2' => false,
            ],
            'electrum' => [
                'host' => getenv('BTCO_ELECTRUM_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('BTCO_ELECTRUM_PORT') ?: 50001),
                'tls' => false,
                'timeout' => 3,
            ],
            'mweb' => ['enabled' => true],
        ],
    ],
];
