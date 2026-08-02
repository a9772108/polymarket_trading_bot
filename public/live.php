<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Read-only live trading readiness monitor for the BTC five-minute paper model.">
    <title>Live Trading Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar border-bottom border-secondary-subtle sticky-top">
    <div class="container py-2">
        <div>
            <span class="navbar-brand fw-bold mb-0">Live Trading Monitor</span>
            <span class="d-none d-md-inline text-secondary small">Read-only order preview</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a class="btn btn-sm btn-outline-light" href="index.php">Paper Model</a>
            <a class="btn btn-sm btn-outline-light" href="backtest.php">Backtest Lab</a>
            <span id="liveFeedBadge" class="badge text-bg-secondary">FEED CONNECTING</span>
            <span class="badge text-bg-danger">EXECUTION OFF</span>
        </div>
    </div>
</nav>

<main class="container py-4">
    <div id="liveAlert"></div>

    <section class="execution-lock mb-3" aria-labelledby="lockHeading">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <div class="eyebrow text-warning">Safety boundary</div>
                <h1 id="lockHeading" class="h3 mt-2 mb-1">Monitoring is live. Trading is not.</h1>
                <p class="mb-0 text-secondary">
                    This page prepares and explains a hypothetical order using public data. It has no wallet,
                    brokerage credentials, signing capability, or order-submission connection.
                </p>
            </div>
            <button class="btn btn-danger btn-lg execution-disabled" type="button" disabled>
                Order submission disabled
            </button>
        </div>
    </section>

    <section class="card account-strip shadow-sm mb-3" aria-labelledby="balanceHeading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <div class="eyebrow">Account overview</div>
                    <h2 id="balanceHeading" class="h5 mt-1 mb-0">Paper funds and connection status</h2>
                </div>
                <span class="badge text-bg-info">SIMULATED MONEY</span>
            </div>
            <div class="account-metrics">
                <div>
                    <span>Paper balance</span>
                    <strong id="livePaperBalance">&mdash;</strong>
                    <small>Starting balance plus settled paper P&amp;L</small>
                </div>
                <div>
                    <span>Realized paper P&amp;L</span>
                    <strong id="livePaperPnl">&mdash;</strong>
                    <small id="liveWinLoss">No resolved trades loaded</small>
                </div>
                <div>
                    <span>Open paper exposure</span>
                    <strong id="livePaperExposure">&mdash;</strong>
                    <small id="liveOpenTrades">No open paper trades loaded</small>
                </div>
                <div id="realBalanceCard" class="real-balance-unavailable">
                    <span>Wallet balance (pUSD)</span>
                    <strong id="realAccountBalance">Not connected</strong>
                    <small id="realAccountBalanceDetail">Public profile address not configured</small>
                </div>
                <div id="realPositionValueCard">
                    <span>Open position value</span>
                    <strong id="realPositionValue">&mdash;</strong>
                    <small>Current value of international positions</small>
                </div>
            </div>
            <div class="real-positions mt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <div class="metric-label">International Polymarket positions</div>
                        <div id="realPositionsSummary" class="small text-secondary mt-1">Waiting for read-only account setup.</div>
                    </div>
                    <span class="badge text-bg-dark border border-secondary">READ ONLY</span>
                </div>
                <div class="table-responsive mt-2">
                    <table class="table table-dark align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Market</th>
                            <th>Side</th>
                            <th class="text-end">Current value</th>
                            <th class="text-end">P&amp;L</th>
                        </tr>
                        </thead>
                        <tbody id="realPositionsBody">
                        <tr><td colspan="4" class="text-secondary text-center py-3">No private account data loaded.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="eyebrow">Observed market</div>
                            <h2 id="liveMarketQuestion" class="h4 mt-2 mb-2">Loading current market...</h2>
                            <div id="liveMarketDates" class="small text-secondary"></div>
                        </div>
                        <button id="liveRefreshButton" class="btn btn-outline-info btn-sm">Refresh</button>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Time remaining</div>
                            <div id="liveCountdown" class="metric-value">&mdash;</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Public liquidity</div>
                            <div id="liveLiquidity" class="metric-value">&mdash;</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Up ask</div>
                            <div id="liveUpAsk" class="metric-value">&mdash;</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Down ask</div>
                            <div id="liveDownAsk" class="metric-value">&mdash;</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="eyebrow">Readiness checks</div>
                    <h2 class="h5 mt-2">What is—and is not—connected</h2>
                    <div class="readiness-list mt-3">
                        <div class="readiness-item">
                            <span id="marketDataDot" class="status-dot"></span>
                            <div><strong>Public market data</strong><small id="marketDataText">Checking...</small></div>
                        </div>
                        <div class="readiness-item">
                            <span id="resolutionFeedDot" class="status-dot"></span>
                            <div><strong>Chainlink resolution feed</strong><small id="resolutionFeedText">Connecting...</small></div>
                        </div>
                        <div class="readiness-item">
                            <span id="modelReadyDot" class="status-dot"></span>
                            <div><strong>Paper model signal</strong><small id="modelReadyText">Loading...</small></div>
                        </div>
                        <div class="readiness-item">
                            <span id="liveAccountDot" class="status-dot status-warning"></span>
                            <div><strong>International public portfolio</strong><small id="liveAccountText">Checking read-only connection...</small></div>
                        </div>
                        <div class="readiness-item">
                            <span class="status-dot status-error"></span>
                            <div><strong>Order execution</strong><small>Intentionally unavailable</small></div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section class="card flow-card shadow-sm mb-3" aria-labelledby="liveFlowHeading">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="eyebrow">Order-flow research</div>
                    <h2 id="liveFlowHeading" class="h5 mt-1 mb-1">Volume and top-of-book pressure</h2>
                    <p class="small text-secondary mb-0">Recorded for accuracy testing; not used to place or change trades.</p>
                </div>
                <span class="badge text-bg-info">SHADOW MODE</span>
            </div>
            <div class="flow-grid mt-3">
                <div>
                    <span>Total market volume</span>
                    <strong id="liveFlowVolume">&mdash;</strong>
                    <small>Public cumulative volume</small>
                </div>
                <div>
                    <span>Recent volume</span>
                    <strong id="liveFlowChange">&mdash;</strong>
                    <small id="liveFlowRate">Waiting for history</small>
                </div>
                <div>
                    <span>Up-book imbalance</span>
                    <strong id="liveFlowImbalance">&mdash;</strong>
                    <small id="liveFlowLabel">Waiting for book sizes</small>
                </div>
                <div>
                    <span>Research evidence</span>
                    <strong id="liveFlowEvidence">0</strong>
                    <small id="liveFlowMatch">Starts after newly recorded markets resolve</small>
                </div>
            </div>
            <div class="imbalance-track mt-3" aria-label="Up order-book pressure">
                <div class="imbalance-center"></div>
                <div id="liveFlowMarker" class="imbalance-marker" style="left:50%"></div>
            </div>
            <div class="d-flex justify-content-between small text-secondary mt-1">
                <span>Ask-heavy</span><span>Balanced</span><span>Bid-heavy</span>
            </div>
        </div>
    </section>

    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <section class="card proposal-card shadow-sm h-100" aria-labelledby="proposalHeading">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="eyebrow">Hypothetical order ticket</div>
                            <h2 id="proposalHeading" class="h4 mt-2 mb-1">Waiting for a model signal</h2>
                            <p id="proposalReason" class="text-secondary mb-0">
                                The monitor will prepare a preview when current data is available.
                            </p>
                        </div>
                        <span id="proposalBadge" class="badge text-bg-secondary proposal-badge">NO PROPOSAL</span>
                    </div>

                    <div class="proposal-grid mt-4">
                        <div><span>Side</span><strong id="proposalSide">&mdash;</strong></div>
                        <div><span>Executable ask</span><strong id="proposalAsk">&mdash;</strong></div>
                        <div><span>Model probability</span><strong id="proposalProbability">&mdash;</strong></div>
                        <div><span>Net model edge</span><strong id="proposalEdge">&mdash;</strong></div>
                        <div><span>Paper position</span><strong id="proposalStake">&mdash;</strong></div>
                        <div><span>Estimated shares</span><strong id="proposalShares">&mdash;</strong></div>
                        <div><span>Maximum loss</span><strong id="proposalLoss" class="text-danger">&mdash;</strong></div>
                        <div><span>Maximum profit</span><strong id="proposalProfit">&mdash;</strong></div>
                    </div>

                    <div class="paper-ticket-note mt-4">
                        Preview calculations include the configured fee and slippage assumptions. They do not
                        guarantee that a real order would fill at this price.
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-xl-4">
            <section class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="eyebrow">Decision gates</div>
                    <h2 class="h5 mt-2">Paper rules applied</h2>
                    <dl class="row settings-list mt-3 mb-0">
                        <dt class="col-7">Decision</dt><dd id="gateDecision" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Minimum edge</dt><dd id="gateMinimumEdge" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Maximum spread</dt><dd id="gateMaximumSpread" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Assumed slippage</dt><dd id="gateSlippage" class="col-5 text-end">&mdash;</dd>
                        <dt class="col-7">Paper loss limit</dt><dd id="gateLossLimit" class="col-5 text-end">&mdash;</dd>
                    </dl>
                    <hr>
                    <p class="small text-secondary mb-0">
                        These are research safeguards, not a production risk system. A real-money integration
                        would require separate account, compliance, order confirmation, and emergency-stop controls.
                    </p>
                </div>
            </section>
        </div>
    </div>

    <section class="card method-card">
        <div class="card-body p-4 d-flex flex-column flex-md-row justify-content-between gap-3">
            <div>
                <div class="eyebrow">Current status</div>
                <p id="liveStatusSummary" class="mb-0 mt-2 text-secondary">
                    Connecting to the local model and public feeds.
                </p>
            </div>
            <div class="text-md-end small text-secondary">
                <div>Last market update: <span id="liveLastMarketUpdate">&mdash;</span></div>
                <div>Last model tick: <span id="liveLastModelTick">&mdash;</span></div>
            </div>
        </div>
    </section>
</main>

<script src="assets/js/live.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/live.js') ?>"></script>
</body>
</html>
