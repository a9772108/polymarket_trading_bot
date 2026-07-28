<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Polymarket Paper Trader</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg border-bottom border-secondary-subtle">
    <div class="container py-2">
        <span class="navbar-brand fw-bold">Polymarket Paper Trader</span>
        <span class="badge text-bg-warning">PAPER MODE</span>
    </div>
</nav>

<main class="container py-4">
    <div id="alertArea"></div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-8">
            <section class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <div class="text-secondary small text-uppercase">Active market</div>
                            <h1 id="marketQuestion" class="h4 mt-1">Loading market…</h1>
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
                            <div id="liquidity" class="metric-value">—</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Volume</div>
                            <div id="volume" class="metric-value">—</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="metric-label">Status</div>
                            <div id="marketStatus" class="metric-value">Loading</div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-lg-4">
            <section class="card h-100 shadow-sm">
                <div class="card-body">
                    <div class="text-secondary small text-uppercase">Geographic check</div>
                    <h2 id="geoStatus" class="h5 mt-2">Checking…</h2>
                    <p id="geoDetails" class="text-secondary mb-0"></p>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-3" id="outcomeCards"></div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Paper-trading controls</h2>
                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label" for="positionSize">Position size</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input id="positionSize" class="form-control" type="number" min="0.01" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="minimumEdge">Minimum edge</label>
                            <div class="input-group">
                                <input id="minimumEdge" class="form-control" type="number" min="0" max="1" step="0.01">
                                <span class="input-group-text">prob.</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="dailyLossLimit">Daily loss limit</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input id="dailyLossLimit" class="form-control" type="number" min="0" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        This first version monitors public data only. It does not submit live orders or use wallet keys.
                    </div>
                </div>
            </section>
        </div>
        <div class="col-12 col-lg-5">
            <section class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5">System status</h2>
                    <dl class="row mb-0 mt-3">
                        <dt class="col-6 text-secondary">Mode</dt><dd class="col-6 text-end">Paper</dd>
                        <dt class="col-6 text-secondary">Last update</dt><dd id="lastUpdate" class="col-6 text-end">—</dd>
                        <dt class="col-6 text-secondary">API</dt><dd id="apiStatus" class="col-6 text-end">Loading</dd>
                    </dl>
                </div>
            </section>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
