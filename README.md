# BTC 5-Minute Model Lab

Local PHP dashboard for collecting public Polymarket and crypto-price data, estimating a transparent baseline probability, and simulating paper trades on BTC five-minute and ETH fifteen-minute Up/Down markets.

The dashboard also includes an **ETH 15-minute** paper market. Use the BTC 5m / ETH 15m
switch in the header. Each mode keeps a separate local paper balance, trade history, model
evidence set, and SQLite database. Both modes remain paper-only and use the same configured
risk safeguards.

## Safety boundary

- Paper trading only
- Public market and price data only
- No wallet keys, order signing, deposits, or live orders
- Geoblock status remains visible
- Simulated trades must pass fee, slippage, spread, edge, timing, and daily-loss checks

## Requirements

- PHP 8.2 or newer
- PHP extensions: cURL, JSON, PDO, and PDO SQLite
- A modern browser with WebSocket support
- Internet access for public Polymarket, Chainlink, and Binance data

## Local XAMPP setup

From PowerShell:

```powershell
cd "$env:USERPROFILE\Documents\polymarket_trading_bot"
& "C:\xampp\php\php.exe" -S 127.0.0.1:8085 -t public
```

Then open `http://127.0.0.1:8085`.

The local paper database is created automatically at `storage/paper_trader.sqlite`. It is ignored by Git.

The read-only live order preview is available at `http://127.0.0.1:8085/live.php`.
It shows current signals, hypothetical order economics, and readiness checks, but contains no live account or execution connection.

### Optional international read-only portfolio

The Live Monitor can display the public pUSD wallet balance, position value, and open positions associated with an international
Polymarket profile without enabling execution.

1. Copy the public Profile Address shown in Polymarket settings.
2. Add that `0x...` address to `config/polymarket_international.local.php`.
3. Refresh the Live Monitor.

The connection uses Polymarket's public Data API plus a read-only Polygon contract call to the
official pUSD collateral contract. It requires no API secret, private key, wallet recovery phrase,
or signing permission.

The approximate historical replay is available at `http://127.0.0.1:8085/backtest.php`.
Its cached public history and saved runs are stored in `storage/backtests.sqlite`.

## How the baseline works

1. The browser receives public Chainlink BTC/USD and Binance BTC/USDT updates.
2. The dashboard captures the first Chainlink value close to each five-minute interval start.
3. Recent one-second price changes estimate short-horizon volatility.
4. Distance from the opening value is divided by expected movement over the remaining time.
5. A normal-distribution baseline converts that standardized distance into Up/Down probabilities.
6. The engine compares those probabilities with executable asks and deducts taker fees and assumed slippage.
7. A paper trade is simulated only in the configured decision window when all risk checks pass.
8. Completed markets are settled from Polymarket's public outcome data.

The model also records cumulative market volume, recent volume change, top-of-book sizes, and
Up-side bid/ask imbalance. These order-flow features run in shadow mode: they are evaluated
against resolved outcomes but do not alter probabilities or paper trades until they demonstrate
an out-of-sample improvement.

Each paper trade also runs an early-exit shadow study. After entry, the model records the
best executable bid observed before the interval ends and calculates the hypothetical exit
P&L after an estimated exit fee and slippage. This does not close the paper trade or change
the normal hold-to-settlement result. It is a hindsight opportunity measure based only on
snapshots captured while the dashboard was running, not an automatic take-profit strategy.

The dashboard also compares a 10-second loss-control shadow rule. At the first captured
snapshot with 10 seconds or less remaining, the rule records a hypothetical sale when
estimated exit P&L is negative; otherwise it holds to settlement. The primary paper trade
still settles normally so aggregate rule P&L can be compared with the same trades held.

If the dashboard starts midway through a market, that market remains in `WARMING UP` because its opening Chainlink value was not captured reliably. Leave the page open through the next interval.

## Interpretation

The baseline is intentionally simple and is not validated alpha. Its purpose is to build a clean dataset and provide a benchmark for later walk-forward models. Compare its Brier score and net paper P&L against the Polymarket market probability before adding more features.

## Approximate historical backtest

The Backtest Lab replays recent completed BTC five-minute markets using:

- exact Polymarket resolved outcomes and Chainlink opening targets;
- Binance BTC/USDT one-second changes as a proxy for unavailable historical Chainlink intrainterval ticks;
- the latest public Polymarket trade at or before the selected decision time as a midpoint proxy;
- an assumed spread to reconstruct a likely executable ask;
- crypto taker fees, slippage, the configured position cap, and daily paper-loss limit.

The replay is look-ahead-safe at the selected timestamp, but it cannot reconstruct historical order-book depth, queue position, partial fills, latency, or the exact Chainlink path. Use it to reject weak ideas and compare assumptions, not as proof of realizable profit.

## Configuration

BTC 5-minute mode includes an **On-chain Observer** link in the sticky header. It opens a paper-only panel that records public mempool activity and optional point-in-time exchange flows for later backtesting. The observer always reports `applied_to_trading: false`; its suggested adjustment is displayed but never used by the model.


Copy `config/config.example.php` to `config/config.php` if needed. The relevant paper settings are:

- starting balance
- maximum paper position
- daily paper-loss limit
- minimum net edge
- assumed slippage
- maximum spread
- decision-window timing

Never add private keys or live trading credentials to this project.
