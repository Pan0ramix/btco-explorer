<?php
/**
 * Network registry. Static chain parameters for Litecoin mainnet, merged with the
 * per-network runtime config (rpc/electrum credentials) from config.php.
 *
 * This is a single-chain Litecoin mainnet explorer served at the site root, so
 * URLs carry no network prefix:
 *   /                    explorer home
 *   /block|tx|address/…  HTML views
 *   /api/…               Esplora / mempool.space REST API
 *
 * The registry keeps a 'slug' as an internal namespace (cache keys + the SQLite
 * `net` column); it never appears in a URL. lx_u()/lx_net_url() return '' so every
 * link is root-relative.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Static chain parameters, keyed by network slug. */
function lx_net_params(): array
{
    $main = [
        'slug' => 'btco-mainnet', 'kind' => 'utxo', 'coin' => 'btco',
        'network' => 'mainnet', 'label' => 'BitcoinOil', 'short' => 'BitcoinOil',
        'ticker' => 'BTCO', 'unit' => 'BTCO', 'decimals' => 8,
        'accent' => '#4c84d6', 'p2pkh' => 26, 'p2sh' => 25,
        'bech32' => 'btco', 'mweb_hrp' => 'btcomweb',
        'mweb_activation' => 157500, 'has_taproot' => true,
        'spacing' => 360,
        'genesis' => '00000000baccc78f2852f3943bb19ff89df6d374252e72f6b487e68c0d839d54',
    ];
    return [
        'btco-mainnet' => $main,
        'btco-testnet' => array_replace($main, [
            'slug' => 'btco-testnet', 'network' => 'testnet', 'label' => 'BitcoinOil Testnet',
            'p2pkh' => 51, 'p2sh' => 50, 'bech32' => 'tbtco', 'mweb_hrp' => 'tbtcomweb',
            'mweb_activation' => 110,
            'genesis' => '00000000f130184486dad044d14fff1d39665f09551dfafccd5a8f938c05df57',
        ]),
    ];
}

/**
 * Resolve a network slug to its full merged config (static params + runtime
 * rpc/electrum), or null if unknown or disabled.
 */
function lx_net(?string $slug): ?array
{
    if ($slug === null) {
        return null;
    }
    $params = lx_net_params();
    if (!isset($params[$slug])) {
        return null;
    }
    $cfg = lx_config()['networks'][$slug] ?? null;
    if (!$cfg || empty($cfg['enabled'])) {
        return null;
    }
    // Runtime config wins for rpc/electrum/mweb; static params for everything else.
    return array_merge($params[$slug], $cfg);
}

/**
 * The single enabled network for this root-served explorer. Returns the first
 * enabled net (there is only one), or null if none is configured/enabled.
 */
function lx_net_default(): ?array
{
    $nets = lx_networks();
    return $nets ? reset($nets) : null;
}

/** Resolve a coin + network to its net, e.g. lx_net_from_path('ltc','mainnet'). */
function lx_net_from_path(string $coin, string $network): ?array
{
    return lx_net($coin . '-' . $network);
}

/** All enabled networks, in display order. */
function lx_networks(): array
{
    $out = [];
    foreach (array_keys(lx_net_params()) as $slug) {
        $n = lx_net($slug);
        if ($n) {
            $out[$slug] = $n;
        }
    }
    return $out;
}

/** Absolute URL base for the current request (https + sane Host). */
function lx_base_url(): string
{
    $cfg = lx_config();
    $canonical = $cfg['canonical_host'] ?? 'localhost';
    // Only reflect a Host we trust into canonical/OG URLs. An allowlist
    // prevents canonical/cache poisoning from a spoofed Host header.
    $allowed = array_merge([$canonical], $cfg['allowed_hosts'] ?? []);
    $host = $_SERVER['HTTP_HOST'] ?? $canonical;
    if (!in_array($host, $allowed, true)) {
        $host = $canonical;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    return $scheme . '://' . $host;
}

/**
 * Root-relative UI base for a network. Empty string in single-chain root mode:
 * every caller appends '/segment...', so '' yields clean root URLs (e.g.
 * '' . '/block/<hash>' -> '/block/<hash>').
 */
function lx_net_url(array $net): string
{
    return '';
}
