# BitcoinOil Explorer

A self-hosted explorer for BitcoinOil mainnet or testnet. BitcoinOil Core provides
blocks, transactions, mempool and public MWEB data. A separately indexed ElectrumX
instance provides transparent address histories, balances and unspent outputs.

- [BitcoinOil Core v25.3.1](https://github.com/escapeneo/bitcoinoil/releases/tag/v25.3.1)
- [Install the explorer and ElectrumX](DEPLOY.md)
- [ElectrumX BitcoinOil patch](electrumx/bitcoinoil.patch)

The block detail page identifies native and AuxPoW blocks and shows the MWEB
balance when the node reports one. The MWEB page follows the public peg boundary.
MWEB addresses cannot be queried for balances or history.

The initial deployment uses Docker Compose on Linux with an existing local node.
Each network needs its own ElectrumX database. The explorer is read-only and
serves an initial subset of the upstream Esplora and mempool API.

Based on [LitecoinExplorer.org](https://github.com/Tech1k/litecoinexplorer.org)
by Tech1k. The explorer is licensed under [AGPL-3.0](LICENSE). The ElectrumX
container builds the pinned upstream project under its own license.

## Validation

The initial containers were tested in isolation against RPC responses and raw
blocks captured from BitcoinOil Core on testnet. ElectrumX indexed blocks
0–148, through MWEB activation at 110. The explorer agreed with the node on
the hash and MWEB balance of AuxPoW block 148, displayed a peg-out and served
transparent address history from the index. PHP lint and Compose configuration
validation passed. Full mainnet synchronization was not part of this run.
