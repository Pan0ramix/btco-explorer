# Install on a Linux host

1. Run [BitcoinOil Core v25.3.2](https://github.com/escapeneo/bitcoinoil/releases/tag/v25.3.2)
   as a dedicated explorer node with `server=1`, `txindex=1`, `prune=0`,
   `disablewallet=1`, and RPC authentication. Keep the RPC on loopback.
   Leave RPC serialization at its default to preserve the MWEB marker in the
   first HogEx, which may have zero inputs. Wait for the node and its transaction
   index to sync. Core uses RPC port 19711 on mainnet and 29711 on testnet.
2. Clone this repository. Copy `.env.example` to `.env`, set a strong node RPC
   password and update both RPC connection settings. Use percent encoding for
   reserved characters in the ElectrumX daemon URL. Keep `.env` private.
   Set `EXPLORER_HOST` to the hostname your users will visit.
3. Run `docker compose up -d --build`. Check `docker compose logs -f electrumx`
   until the index reports the correct chain tip. Once ready, check
   `curl -fsS http://127.0.0.1:8080/api/health` and try a real address.
4. Point your HTTPS reverse proxy at `http://127.0.0.1:8080`. Keep the node RPC
   private. The bundled ElectrumX listens on loopback for the explorer. If you
   also serve wallet clients, configure a separately published TLS endpoint
   using [ElectrumX services](https://electrumx.readthedocs.io/en/latest/environment.html)
   and test the intended wallet client. The bundled index covers transparent
   transactions and does not provide wallet-side MWEB scanning.

For testnet set `BTCO_NETWORK=testnet` and use port 29711 in both RPC settings.
Use a different Compose project name for a different network, for example
`docker compose -p btco-testnet up -d --build`. The default loopback ports 8080
and 50001 are shared by both profiles. Run one deployment per host with these
defaults, or change both service ports for an additional deployment.

The address index must finish syncing before address histories are usable.
Blocks and transactions come directly from Core. To check the transition,
compare the explorer's height, block hash, AuxPoW status and MWEB balance
against `getblockhash` and `getblock <hash> 1` on the same node.

## Public Electrum server

This opens the index to wallets on TLS port 50002. Keep the node RPC private.
The server was checked against mainnet through block 157807, but there is no
released BitcoinOil Electrum wallet yet: a wallet must parse AuxPoW headers and
verify the ASERT difficulty before it can use this server safely.

1. Reserve 2 vCPU, 2 GB RAM for the index and node, and 20 GB of SSD. The mainnet
   index itself used 36 MB on disk and under 300 MB of RAM in November 2026.
2. Put a certificate in a directory as `fullchain.pem` and `privkey.pem`, readable
   by uid 10001, and set `BTCO_ELECTRUM_CERTS` in `.env` to that directory.
   Restart the container after each renewal.
3. Start it:

   ```bash
   docker compose -f compose.yaml -f compose.electrum.yaml up -d --build electrumx
   ```

4. Open TCP port 50002 on the firewall or router. Do not open 50001 or the RPC port.
5. From another network:

   ```bash
   printf '%s\n' '{"id":0,"method":"server.version","params":["check","1.4"]}' '{"id":1,"method":"blockchain.headers.subscribe","params":[]}' | timeout 10 openssl s_client -quiet -connect YOUR_HOST:50002
   ```

   The reported height must match `bitcoinoil-cli getblockcount`.
