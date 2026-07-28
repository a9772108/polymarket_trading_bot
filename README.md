# Polymarket Trading Bot

PHP 8.3 + Bootstrap 5 + JavaScript dashboard for monitoring Polymarket markets and paper-trading strategies on a VPS.

## Current scope

- Paper trading only
- Public market-data monitoring
- BTC 5-minute market dashboard foundation
- Configurable risk limits
- No private keys or live-order signing

## Requirements

- Ubuntu 22.04+
- Apache 2.4+
- PHP 8.3 with curl and json
- MySQL 8 or MariaDB 10.6+

## Setup

1. Clone the repository into your VPS web directory.
2. Copy `config/config.example.php` to `config/config.php`.
3. Import `database/schema.sql`.
4. Point Apache DocumentRoot to the `public/` directory.
5. Ensure Apache can write to `storage/logs/`.

## Security

This repository is currently public. Never commit wallet private keys, API credentials, database passwords, or production configuration. Keep secrets in `config/config.php` or environment variables outside Git.

## Roadmap

1. Discover active BTC Up/Down five-minute markets.
2. Stream or poll order-book data.
3. Record snapshots and calculate spread/liquidity.
4. Add configurable paper-trading strategies.
5. Add performance reporting and risk controls.
6. Consider live trading only after legal/geographic checks and extensive paper testing.
