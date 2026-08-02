<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Approximate historical replay for BTC five-minute Polymarket research.">
    <title>Approximate Backtest Lab</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar border-bottom border-secondary-subtle sticky-top">
    <div class="container py-2">
        <div>
            <span class="navbar-brand fw-bold mb-0">Approximate Backtest Lab</span>
            <span class="d-none d-md-inline text-secondary small">Historical BTC five-minute replay</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-light" href="index.php">Paper Model</a>
            <a class="btn btn-sm btn-outline-info" href="live.php">Live Monitor</a>
            <span class="badge text-bg-warning">APPROXIMATE</span>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div id="backtestAlert"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-5">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="eyebrow">Replay configuration</div>
                    <h1 class="h3 mt-2">Test the baseline on recent completed markets</h1>
                    <p class="text-secondary">
                        The replay uses only data timestamped at or before each selected entry point.
                        Start small; cached data makes later comparisons faster.
                    </p>

                    <form id="backtestForm" class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="marketCount">Completed markets</label>
                            <select id="marketCount" class="form-select">
                                <option value="12">12 (1 hour)</option>
                                <option value="24" selected>24 (2 hours)</option>
                                <option value="48">48 (4 hours)</option>
                                <option value="96">96 (8 hours)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="entrySeconds">Enter before expiry</label>
                            <select id="entrySeconds" class="form-select">
                                <option value="60" selected>60 seconds</option>
                                <option value="30">30 seconds</option>
                                <option value="15">15 seconds</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="minimumEdge">Minimum net edge</label>
                            <div class="input-group">
                                <input id="minimumEdge" class="form-control" type="number" value="5" min="0" max="25" step="0.5">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="positionSize">Position size</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input id="positionSize" class="form-control" type="number" value="5" min="0.5" max="5" step="0.5">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="slippage">Slippage</label>
                            <div class="input-group">
                                <input id="slippage" class="form-control" type="number" value="1" min="0" max="5" step="0.25">
                                <span class="input-group-text">&cent;</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="assumedSpread">Assumed spread</label>
                            <div class="input-group">
                                <input id="assumedSpread" class="form-control" type="number" value="1" min="0" max="5" step="0.25">
                                <span class="input-group-text">&cent;</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <button id="runBacktest" class="btn btn-info w-100 fw-semibold" type="submit">
                                Run approximate backtest
                            </button>
                        </div>
                    </form>
                    <div id="runStatus" class="small text-secondary mt-3">
                        The first run may take 15&ndash;45 seconds while public history is downloaded.
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12 col-xl-7">
            <section class="card assumption-card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="eyebrow">Important interpretation</div>
                    <h2 class="h4 mt-2">Useful for screening, not proof of executable profit</h2>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="assumption-block">
                                <strong>Exact inputs</strong>
                                <p>Resolved outcome and Chainlink opening target from completed Polymarket events.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="assumption-block">
                                <strong>Approximate inputs</strong>
                                <p>Binance one-second path and a reconstructed ask based on last trade plus assumed spread.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="assumption-block">
                                <strong>No look-ahead</strong>
                                <p>Signals and market prices are selected only at or before the configured entry time.</p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="assumption-block">
                                <strong>Costs included</strong>
                                <p>Crypto taker fee, slippage, spread proxy, position cap, and daily paper-loss limit.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section id="emptyState" class="card shadow-sm">
        <div class="card-body p-5 text-center">
            <div class="display-6 mb-2">No historical run yet</div>
            <p class="text-secondary mb-0">Choose conservative assumptions and run a small replay first.</p>
        </div>
    </section>

    <div id="resultsArea" class="d-none">
        <div class="row g-3 mb-3">
            <div class="col-6 col-xl-3">
                <section class="card performance-card h-100">
                    <div class="card-body">
                        <div class="metric-label">Net P&amp;L</div>
                        <div id="resultPnl" class="performance-value">&mdash;</div>
                        <div id="resultBalance" class="small text-secondary"></div>
                    </div>
                </section>
            </div>
            <div class="col-6 col-xl-3">
                <section class="card performance-card h-100">
                    <div class="card-body">
                        <div class="metric-label">Return on staked</div>
                        <div id="resultRoi" class="performance-value">&mdash;</div>
                        <div id="resultStaked" class="small text-secondary"></div>
                    </div>
                </section>
            </div>
            <div class="col-6 col-xl-3">
                <section class="card performance-card h-100">
                    <div class="card-body">
                        <div class="metric-label">Win rate</div>
                        <div id="resultWinRate" class="performance-value">&mdash;</div>
                        <div id="resultWins" class="small text-secondary"></div>
                    </div>
                </section>
            </div>
            <div class="col-6 col-xl-3">
                <section class="card performance-card h-100">
                    <div class="card-body">
                        <div class="metric-label">Maximum drawdown</div>
                        <div id="resultDrawdown" class="performance-value">&mdash;</div>
                        <div id="resultCoverage" class="small text-secondary"></div>
                    </div>
                </section>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-xl-8">
                <section class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-end gap-3">
                            <div>
                                <div class="eyebrow">Equity path</div>
                                <h2 class="h5 mt-1 mb-0">Balance after each simulated trade</h2>
                            </div>
                            <span id="runTimestamp" class="small text-secondary"></span>
                        </div>
                        <div id="equityChart" class="equity-chart mt-4" aria-label="Approximate backtest equity chart"></div>
                    </div>
                </section>
            </div>
            <div class="col-12 col-xl-4">
                <section class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <div class="eyebrow">Probability quality</div>
                        <h2 class="h5 mt-1">Brier score comparison</h2>
                        <p class="small text-secondary">Lower is better. Profitability still depends on entry costs.</p>
                        <div class="score-row mt-3">
                            <span>Baseline model</span><strong id="modelBrier">&mdash;</strong>
                        </div>
                        <div class="score-track"><div id="modelBrierBar" class="score-fill score-model"></div></div>
                        <div class="score-row mt-3">
                            <span>Market proxy</span><strong id="marketBrier">&mdash;</strong>
                        </div>
                        <div class="score-track"><div id="marketBrierBar" class="score-fill score-market"></div></div>
                        <div id="brierVerdict" class="notice-box mt-4"></div>
                    </div>
                </section>
            </div>
        </div>

        <section class="card shadow-sm mb-3">
            <div class="card-body p-4">
                <div class="eyebrow">Market-by-market replay</div>
                <h2 class="h5 mt-1">Recent analyzed decisions</h2>
                <div class="table-responsive mt-3">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Ended</th>
                            <th>Decision</th>
                            <th>Actual</th>
                            <th>Model Up</th>
                            <th>Market Up</th>
                            <th>Net edge</th>
                            <th class="text-end">P&amp;L</th>
                        </tr>
                        </thead>
                        <tbody id="backtestRows"></tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="card method-card">
            <div class="card-body p-4">
                <div class="eyebrow">Run assumptions</div>
                <ul id="assumptionList" class="text-secondary mb-0 mt-2"></ul>
            </div>
        </section>
    </div>
</main>

<script src="assets/js/backtest.js"></script>
</body>
</html>
