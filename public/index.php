<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/MarketMode.php';
$marketMode = MarketMode::fromRequest($_GET['market'] ?? null);
$modeKey = (string) $marketMode['key'];
$symbol = (string) $marketMode['symbol'];
$modeLabel = (string) $marketMode['label'];
$intervalLabel = (int) $marketMode['interval_minutes'] . '-minute';
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Local, paper-only <?= htmlspecialchars($symbol) ?> <?= htmlspecialchars($intervalLabel) ?> probability model and performance dashboard.">
    <title><?= htmlspecialchars($modeLabel) ?> Model Lab</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body
    data-market-mode="<?= htmlspecialchars($modeKey) ?>"
    data-asset-symbol="<?= htmlspecialchars($symbol) ?>"
    data-asset-name="<?= htmlspecialchars((string) $marketMode['asset_name']) ?>"
    data-chainlink-symbol="<?= htmlspecialchars((string) $marketMode['chainlink_symbol']) ?>"
    data-binance-symbol="<?= htmlspecialchars((string) $marketMode['binance_symbol']) ?>"
    data-interval-minutes="<?= (int) $marketMode['interval_minutes'] ?>"
>
<nav class="navbar border-bottom border-secondary-subtle sticky-top">
    <div class="container py-2">
        <div>
            <span class="navbar-brand fw-bold mb-0"><?= htmlspecialchars($modeLabel) ?> Model Lab</span>
            <span class="d-none d-md-inline text-secondary small">Local paper-trading research</span>
        </div>
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
            <div class="btn-group btn-group-sm" role="group" aria-label="Paper market">
                <a class="btn <?= $modeKey === 'btc-5m' ? 'btn-info' : 'btn-outline-info' ?>" href="?market=btc-5m">BTC 5m</a>
                <a class="btn <?= $modeKey === 'eth-15m' ? 'btn-info' : 'btn-outline-info' ?>" href="?market=eth-15m">ETH 15m</a>
            </div>
            <a class="btn btn-sm btn-outline-info" href="live.php">BTC Live Monitor</a>
            <a class="btn btn-sm btn-outline-light" href="backtest.php">BTC Backtest</a>
            <span id="feedBadge" class="badge text-bg-secondary">FEED CONNECTING</span>
            <span class="badge text-bg-warning">PAPER ONLY</span>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div id="alertArea"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <section class="card h-100 shadow-sm">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="eyebrow">Active market</div>
                            <h1 id="marketQuestion" class="h3 mt-2 mb-2">Loading market...</h1>
                            <div id="marketDates" class="text-secondary small"></div>
                        </div>
                        <button id="refreshButton" class="btn btn-outline-info btn-sm">Refresh</button>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Countdown</div>
                            <div id="countdown" class="metric-value">--:--</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Liquidity</div>
                            <div id="liquidity" class="metric-value">&mdash;</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Volume</div>
                            <div id="volume" class="metric-value">&mdash;</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Market status</div>
                            <div id="marketStatus" class="metric-value">Loading</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-4">
            <section class="card h-100 shadow-sm">
                <div class="card-body p-4">
                    <div class="eyebrow">Safety and data</div>
                    <div class="status-row mt-3">
                        <span class="status-dot" id="geoDot"></span>
                        <div>
                            <h2 id="geoStatus" class="h6 mb-1">Checking location...</h2>
                            <p id="geoDetails" class="small text-secondary mb-0"></p>
                        </div>
                    </div>
                    <div class="status-row mt-3">
                        <span class="status-dot" id="chainlinkDot"></span>
                        <div>
                            <h2 class="h6 mb-1">Chainlink <?= htmlspecialchars($symbol) ?>/USD</h2>
                            <p id="chainlinkStatus" class="small text-secondary mb-0">Connecting to the resolution feed...</p>
                        </div>
                    </div>
                    <div class="status-row mt-3">
                        <span class="status-dot" id="storageDot"></span>
                        <div>
                            <h2 class="h6 mb-1">Local model history</h2>
                            <p id="storageStatus" class="small text-secondary mb-0">Opening the paper database...</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section class="card signal-card shadow-sm mb-3" aria-labelledby="signalHeading">
        <div class="card-body p-4">
            <div class="row g-4">
                <div class="col-12 col-xl-4">
                    <div class="eyebrow">Baseline decision</div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                        <h2 id="signalHeading" class="display-6 fw-bold mb-0">Warming up</h2>
                        <span id="decisionBadge" class="badge decision-badge text-bg-secondary">WAIT</span>
                    </div>
                    <p id="modelReason" class="text-secondary mt-3 mb-3">
                        Waiting for a verified opening price and enough live observations.
                    </p>
                    <div class="notice-box">
                        This is a transparent statistical baseline, not a guarantee or a live order.
                    </div>
                </div>

                <div class="col-12 col-xl-4 border-xl-start">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="fw-semibold text-info">Up <span id="modelUpProbability">&mdash;</span></span>
                        <span class="fw-semibold text-danger">Down <span id="modelDownProbability">&mdash;</span></span>
                    </div>
                    <div class="probability-track" role="img" aria-label="Model probability split">
                        <div id="probabilityUpBar" class="probability-up" style="width:50%"></div>
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-6">
                            <div class="metric-label">Price to beat</div>
                            <div id="priceToBeat" class="signal-metric">&mdash;</div>
                        </div>
                        <div class="col-6">
                            <div class="metric-label">Chainlink now</div>
                            <div id="chainlinkPrice" class="signal-metric">&mdash;</div>
                        </div>
                        <div class="col-6">
                            <div class="metric-label">Distance</div>
                            <div id="priceDistance" class="signal-metric">&mdash;</div>
                        </div>
                        <div class="col-6">
                            <div class="metric-label">60s volatility</div>
                            <div id="modelVolatility" class="signal-metric">&mdash;</div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-4 border-xl-start">
                    <div class="eyebrow mb-2">Executable comparison</div>
                    <div class="comparison-grid">
                        <div></div><div>Up</div><div>Down</div>
                        <div>Model</div><strong id="compareUpModel">&mdash;</strong><strong id="compareDownModel">&mdash;</strong>
                        <div>Best ask</div><strong id="compareUpAsk">&mdash;</strong><strong id="compareDownAsk">&mdash;</strong>
                        <div>Net edge</div><strong id="compareUpEdge">&mdash;</strong><strong id="compareDownEdge">&mdash;</strong>
                    </div>
                    <div class="small text-secondary mt-3">
                        Net edge deducts the executable ask, crypto taker fee, and configured slippage.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3" id="outcomeCards"></div>

    <section class="card flow-card shadow-sm mb-3" aria-labelledby="flowHeading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="eyebrow">Order-flow research</div>
                    <h2 id="flowHeading" class="h5 mt-1 mb-1">Volume and top-of-book pressure</h2>
                    <p class="small text-secondary mb-0">
                        These features are being recorded for evaluation. They do not change paper-trading decisions yet.
                    </p>
                </div>
                <span class="badge text-bg-info">SHADOW MODE</span>
            </div>
            <div class="flow-grid mt-3">
                <div>
                    <span>Total market volume</span>
                    <strong id="flowMarketVolume">&mdash;</strong>
                    <small>Public cumulative volume</small>
                </div>
                <div>
                    <span>Recent volume</span>
                    <strong id="flowVolumeChange">&mdash;</strong>
                    <small id="flowVolumeRate">Waiting for 30 seconds of history</small>
                </div>
                <div>
                    <span>Up-book imbalance</span>
                    <strong id="flowImbalance">&mdash;</strong>
                    <small id="flowImbalanceLabel">Waiting for bid and ask sizes</small>
                </div>
                <div>
                    <span>Research evidence</span>
                    <strong id="flowEvidence">0</strong>
                    <small id="flowMatchRate">Starts after newly recorded markets resolve</small>
                </div>
            </div>
            <div class="imbalance-track mt-3" aria-label="Up order-book pressure">
                <div class="imbalance-center"></div>
                <div id="flowImbalanceMarker" class="imbalance-marker" style="left:50%"></div>
            </div>
            <div class="d-flex justify-content-between small text-secondary mt-1">
                <span>Ask-heavy</span><span>Balanced</span><span>Bid-heavy</span>
            </div>
        </div>
    </section>

    <section class="card exit-rule-card shadow-sm mb-3" aria-labelledby="exit10Heading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="eyebrow">10-second loss-control test</div>
                    <h2 id="exit10Heading" class="h5 mt-1 mb-1">Sell at a loss; otherwise hold</h2>
                    <p class="small text-secondary mb-0">
                        At the first recorded snapshot with 10 seconds or less remaining, this shadow rule sells only when estimated P&amp;L is negative.
                    </p>
                </div>
                <span class="badge text-bg-info">SHADOW MODE</span>
            </div>
            <div class="flow-grid mt-3">
                <div>
                    <span>10-second rule P&amp;L</span>
                    <strong id="exit10RulePnl">&mdash;</strong>
                    <small id="exit10Resolved">No resolved samples yet</small>
                </div>
                <div>
                    <span>Hold P&amp;L</span>
                    <strong id="exit10HoldPnl">&mdash;</strong>
                    <small>Same eligible trades</small>
                </div>
                <div>
                    <span>Difference</span>
                    <strong id="exit10Difference">&mdash;</strong>
                    <small>Rule result minus hold result</small>
                </div>
                <div>
                    <span>Exit decisions</span>
                    <strong id="exit10Triggered">0</strong>
                    <small id="exit10WinnersCut">0 eventual winners exited</small>
                </div>
            </div>
            <div class="notice-box mt-3">
                This does not close the normal paper trade. It preserves both outcomes for an honest comparison and assumes the recorded bid was available for the full simulated position.
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <section class="card performance-card h-100">
                <div class="card-body">
                    <div class="metric-label">Paper balance</div>
                    <div id="paperBalance" class="performance-value">&mdash;</div>
                    <div id="paperPnl" class="small text-secondary">No resolved trades</div>
                </div>
            </section>
        </div>
        <div class="col-6 col-xl-3">
            <section class="card performance-card h-100">
                <div class="card-body">
                    <div class="metric-label">Paper trades</div>
                    <div id="tradeCount" class="performance-value">0</div>
                    <div id="openTrades" class="small text-secondary">0 open</div>
                </div>
            </section>
        </div>
        <div class="col-6 col-xl-3">
            <section class="card performance-card h-100">
                <div class="card-body">
                    <div class="metric-label">Win rate</div>
                    <div id="winRate" class="performance-value">&mdash;</div>
                    <div id="winLoss" class="small text-secondary">No resolved trades</div>
                </div>
            </section>
        </div>
        <div class="col-6 col-xl-3">
            <section class="card performance-card h-100">
                <div class="card-body">
                    <div class="metric-label">Model evidence</div>
                    <div id="modelSample" class="performance-value">0</div>
                    <div id="modelEvidence" class="small text-secondary">Collecting baseline evidence</div>
                </div>
            </section>
        </div>
    </div>

    <section class="card shadow-exit-card shadow-sm mb-3" aria-labelledby="earlyExitHeading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="eyebrow">Early-exit shadow test</div>
                    <h2 id="earlyExitHeading" class="h5 mt-1 mb-1">Could the trade have been sold for a profit?</h2>
                    <p class="small text-secondary mb-0">
                        This observes the best available bid after entry. Paper trades still remain open until settlement.
                    </p>
                </div>
                <span class="badge text-bg-info">OBSERVATION ONLY</span>
            </div>
            <div class="flow-grid mt-3">
                <div>
                    <span>Trades observed</span>
                    <strong id="earlyExitTracked">0</strong>
                    <small>Trades with at least one later bid</small>
                </div>
                <div>
                    <span>Profitable at some point</span>
                    <strong id="earlyExitRate">&mdash;</strong>
                    <small id="earlyExitCount">Waiting for observations</small>
                </div>
                <div>
                    <span>Losses profitable earlier</span>
                    <strong id="earlyExitRescued">0</strong>
                    <small>Lost at settlement, but had a profitable exit</small>
                </div>
                <div>
                    <span>Best observed opportunity</span>
                    <strong id="earlyExitBest">&mdash;</strong>
                    <small>After estimated exit fee and slippage</small>
                </div>
            </div>
            <div class="notice-box mt-3">
                This is a hindsight opportunity study, not an automatic sell rule. It only knows prices captured while the dashboard was collecting data.
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="eyebrow">Paper history</div>
                            <h2 class="h5 mt-1 mb-0">Recent simulated trades</h2>
                        </div>
                        <span class="badge text-bg-dark border border-secondary">No wallet access</span>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Time</th>
                                <th>Side</th>
                                <th>Entry</th>
                                <th>Edge</th>
                                <th>Status</th>
                                <th>Best early exit</th>
                                <th class="text-end">P&amp;L</th>
                            </tr>
                            </thead>
                            <tbody id="tradeHistory">
                            <tr><td colspan="7" class="text-secondary py-4 text-center">No paper trades yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="eyebrow">Configured safeguards</div>
                    <h2 class="h5 mt-1">Paper risk controls</h2>
                    <dl class="row mb-0 mt-3 settings-list">
                        <dt class="col-7">Position size</dt><dd id="positionSize" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Minimum net edge</dt><dd id="minimumEdge" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Daily loss limit</dt><dd id="dailyLossLimit" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Assumed slippage</dt><dd id="assumedSlippage" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Maximum spread</dt><dd id="maximumSpread" class="col-5 text-end">&mdash;</dd>
                    </dl>
                    <hr>
                    <dl class="row mb-0 settings-list">
                        <dt class="col-7">Dashboard API</dt><dd id="apiStatus" class="col-5 text-end">Loading</dd>
                        <dt class="col-7">Last market update</dt><dd id="lastUpdate" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Last model tick</dt><dd id="lastModelTick" class="col-5 text-end">&mdash;</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <section id="tradeChartCard" class="card trade-chart-card shadow-sm mb-3 d-none" aria-labelledby="tradeChartHeading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="eyebrow">Recorded selling-price path</div>
                    <h2 id="tradeChartHeading" class="h5 mt-1 mb-1">Trade exit chart</h2>
                    <p id="tradeChartSubtitle" class="small text-secondary mb-0">Select a trade to view its observed bids.</p>
                </div>
                <div class="d-flex gap-2">
                    <button id="tradeChartRefresh" type="button" class="btn btn-sm btn-outline-info">Refresh chart</button>
                    <button id="tradeChartClose" type="button" class="btn btn-sm btn-outline-secondary">Close</button>
                </div>
            </div>
            <div class="trade-chart-metrics mt-3">
                <div><span>Entry price</span><strong id="tradeChartEntry">&mdash;</strong></div>
                <div><span>Best early-exit P&amp;L</span><strong id="tradeChartBest">&mdash;</strong></div>
                <div><span>Hold result</span><strong id="tradeChartHold">&mdash;</strong></div>
                <div><span>Recorded prices</span><strong id="tradeChartSamples">0</strong></div>
            </div>
            <div class="trade-chart-wrap mt-3">
                <canvas id="tradeExitChart" height="320" aria-label="Observed selling-price chart"></canvas>
                <div id="tradeChartEmpty" class="trade-chart-empty d-none">No later selling prices were recorded for this trade.</div>
            </div>
            <div class="d-flex flex-wrap gap-3 small mt-2">
                <span><i class="chart-key chart-key-price"></i>Observed selling bid</span>
                <span><i class="chart-key chart-key-profit"></i>Profitable after estimated costs</span>
                <span><i class="chart-key chart-key-entry"></i>Entry price</span>
                <span><i class="chart-key chart-key-best"></i>Best observed opportunity</span>
            </div>
            <div id="tradeChartPointer" class="notice-box mt-3">
                Move over the chart to inspect a recorded price.
            </div>
            <p class="small text-secondary mb-0 mt-2">
                The chart contains snapshots recorded while this dashboard was running; it is not a complete tick-by-tick market history.
            </p>
        </div>
    </section>

    <section class="card method-card">
        <div class="card-body p-4">
            <div class="eyebrow">How this baseline works</div>
            <p class="mb-0 mt-2 text-secondary">
                The model compares the current Chainlink <?= htmlspecialchars($symbol) ?>/USD value with the captured interval opening,
                scales that distance by recent one-second volatility and time remaining, and converts it into
                an Up/Down probability. A simulated trade is allowed only inside the final decision window
                when net edge remains above the configured margin after executable price, fee, slippage,
                spread, and daily risk checks.
            </p>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
