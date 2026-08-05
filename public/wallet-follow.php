<?php

declare(strict_types=1);

$wallet = strtolower(trim((string) ($_GET['wallet'] ?? '')));
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>BTC 5m Wallet Paper Follower</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= (int) filemtime(__DIR__ . '/assets/css/app.css') ?>" rel="stylesheet">
</head>
<body data-prefill-wallet="<?= htmlspecialchars($wallet) ?>">
<nav class="navbar border-bottom border-secondary-subtle sticky-top">
    <div class="container py-2">
        <div><span class="navbar-brand fw-bold mb-0">BTC 5m Wallet Follower</span><span class="d-none d-md-inline text-secondary small">One-day paper imitation</span></div>
        <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-light" href="index.php?market=btc-5m">Model Lab</a><a class="btn btn-sm btn-outline-info" href="wallets.php?market=btc-5m">Trader Research</a><span class="badge text-bg-warning align-self-center">PAPER ONLY</span></div>
    </div>
</nav>
<main class="container py-4">
    <div id="followAlert"></div>
    <section class="card shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="eyebrow">Choose a public wallet</div>
            <h1 class="h3 mt-2">Follow new BTC five-minute activity today</h1>
            <p class="text-secondary">The follower starts from the moment you press Start and stops automatically when the local date changes.</p>
            <form id="followForm" class="row g-3 align-items-end">
                <div class="col-12 col-lg-8"><label for="followWallet" class="form-label">Public wallet address</label><input id="followWallet" class="form-control bg-dark text-light" required pattern="0x[a-fA-F0-9]{40}" placeholder="0x..." value="<?= htmlspecialchars($wallet) ?>"></div>
                <div class="col-6 col-lg-2"><label for="followStake" class="form-label">Paper stake</label><div class="input-group"><span class="input-group-text">$</span><input id="followStake" type="number" class="form-control bg-dark text-light" min="1" max="5" step="0.50" value="5"></div></div>
                <div class="col-6 col-lg-2 d-grid"><button id="followStart" class="btn btn-info" type="submit">Start today</button></div>
            </form>
            <div class="notice-box mt-3">No wallet connection and no real orders. Keep this page open. One paper entry is allowed per market/outcome, even when the followed trader splits an order into many fills.</div>
        </div>
    </section>
    <section class="card shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between gap-3"><div><div id="followSectionLabel" class="eyebrow">Current session</div><h2 id="followHeading" class="h4 mt-2">No session started</h2><p id="followDetails" class="text-secondary mb-0">Choose a wallet above.</p></div><div class="d-flex gap-2 align-items-start"><button id="followPoll" class="btn btn-sm btn-outline-info" type="button" disabled>Check now</button><button id="followStop" class="btn btn-sm btn-outline-danger" type="button" disabled>Stop</button><span id="followStatus" class="badge text-bg-secondary mt-1">IDLE</span></div></div>
            <div class="flow-grid mt-4">
                <div><span>Paper positions</span><strong id="followPositions">0</strong><small id="followOpen">0 open</small></div>
                <div><span>Realized paper P&amp;L</span><strong id="followPnl">$0.00</strong><small>Current session only</small></div>
                <div><span>Win rate</span><strong id="followWinRate">&mdash;</strong><small id="followWins">No closed positions</small></div>
                <div><span>Paper stake</span><strong id="followStakeDisplay">&mdash;</strong><small>Per market/outcome</small></div>
            </div>
            <div class="table-responsive mt-4"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Observed</th><th>Market</th><th>Outcome</th><th class="text-end">Source fill</th><th class="text-end">Paper entry</th><th class="text-end">Stake</th><th>Status</th><th class="text-end">P&amp;L</th></tr></thead><tbody id="followBody"><tr><td colspan="8" class="text-secondary text-center py-4">No copied paper positions.</td></tr></tbody></table></div>
        </div>
    </section>
    <section class="card shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div><div class="eyebrow">Saved paper testing</div><h2 class="h4 mt-2 mb-1">Wallet history by day</h2><p class="text-secondary mb-0">Click a wallet to open every paper position saved for that day.</p></div>
                <button id="followLatest" class="btn btn-sm btn-outline-info" type="button">Show current session</button>
            </div>
            <div class="table-responsive mt-4"><table class="table table-dark table-hover align-middle mb-0"><thead><tr><th>Wallet</th><th>Date</th><th>Test time</th><th class="text-end">Positions</th><th>Results</th><th class="text-end">P&amp;L</th><th>Status</th></tr></thead><tbody id="followHistoryBody"><tr><td colspan="7" class="text-secondary text-center py-4">Loading saved wallet days&hellip;</td></tr></tbody></table></div>
        </div>
    </section>
</main>
<script src="assets/js/wallet-follow.js?v=<?= (int) filemtime(__DIR__ . '/assets/js/wallet-follow.js') ?>"></script>
</body>
</html>
