<?php
/**
 * Address labels: a small curated map of well-known addresses -> human label + category, surfaced
 * as a badge on the address page and the rich list (the feature chainz/cryptoid has that we didn't).
 *
 * IMPORTANT - this is a MONEY tool: a wrong label misattributes funds. Every entry MUST be an
 * operator-VERIFIED address (from the entity's own published address, a signed message, or a
 * reputable public tag source). This file ships intentionally near-empty; do NOT paste unverified
 * "this looks like Binance" guesses. Add entries you can stand behind.
 *
 * To add a label: put a lowercase-key entry in lx_address_labels() below:
 *   'Laddr...' => ['name' => 'Litecoin Foundation', 'kind' => 'foundation'],
 * Recognised kinds (drive the badge colour): exchange, pool, foundation, service, burn, contract.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The curated label map, keyed by address (exact match). Seeded empty by default - populate with
 * VERIFIED addresses only. Memoized for the request. An optional config override
 * ('address_labels' => ['addr' => ['name'=>, 'kind'=>], ...]) is merged so a deployment can keep
 * its own list in config.php without editing this file.
 */
function lx_address_labels(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    // Ship no built-in entries: every label must be operator-verified (see the file header).
    $builtin = [
        // 'Laddr...' => ['name' => 'Example Exchange (cold)', 'kind' => 'exchange'],
    ];
    $extra = lx_config()['address_labels'] ?? [];
    $map = is_array($extra) ? array_merge($builtin, $extra) : $builtin;
    return $map;
}

/** Label for one address, or null. Returns ['name'=>string, 'kind'=>string]. */
function lx_address_label(string $addr): ?array
{
    if ($addr === '') {
        return null;
    }
    $l = lx_address_labels()[$addr] ?? null;
    if (!is_array($l) || !isset($l['name'])) {
        return null;
    }
    $kind = (string) ($l['kind'] ?? 'service');
    static $kinds = ['exchange', 'pool', 'foundation', 'service', 'burn', 'contract'];
    if (!in_array($kind, $kinds, true)) {
        $kind = 'service';
    }
    return ['name' => (string) $l['name'], 'kind' => $kind];
}

/** True if any labels are configured (lets views cheaply skip the lookup/column when empty). */
function lx_has_address_labels(): bool
{
    return lx_address_labels() !== [];
}

/** Render a label badge (safe HTML) for an address, or '' if unlabeled. */
function lx_address_label_badge(string $addr): string
{
    $l = lx_address_label($addr);
    if ($l === null) {
        return '';
    }
    return '<span class="addr-label lbl-' . h($l['kind']) . '" title="' . h(ucfirst($l['kind'])) . '">'
        . h($l['name']) . '</span>';
}
