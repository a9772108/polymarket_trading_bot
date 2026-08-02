(() => {
    'use strict';

    const marketMode = document.body.dataset.marketMode || 'btc-5m';
    const assetSymbol = document.body.dataset.assetSymbol || 'BTC';
    const assetName = document.body.dataset.assetName || 'Bitcoin';
    const chainlinkSymbol = (document.body.dataset.chainlinkSymbol || 'btc/usd').toLowerCase();
    const binanceSymbol = (document.body.dataset.binanceSymbol || 'btcusdt').toLowerCase();
    const intervalMinutes = Number.parseInt(document.body.dataset.intervalMinutes || '5', 10);
    const apiUrl = (path, parameters = {}) => {
        const query = new URLSearchParams({ market: marketMode, ...parameters });
        return `${path}?${query.toString()}`;
    };

    const state = {
        endDate: null,
        dashboard: null,
        model: null,
        feeds: {
            chainlink: null,
            binance: null,
        },
        websocket: null,
        reconnectTimer: null,
        pingTimer: null,
        observationPending: false,
        dashboardPending: false,
        tradeChart: null,
        tradeChartGeometry: [],
    };

    const money = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    });
    const assetPrice = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const byId = (id) => document.getElementById(id);

    function showAlert(message, type = 'danger') {
        const area = byId('alertArea');
        area.innerHTML = `<div class="alert alert-${type}" role="alert"></div>`;
        area.firstElementChild.textContent = message;
    }

    function clearAlert() {
        byId('alertArea').innerHTML = '';
    }

    function parseNumber(value) {
        const number = Number.parseFloat(value);
        return Number.isFinite(number) ? number : null;
    }

    function cents(value, digits = 1) {
        const number = parseNumber(value);
        return number === null ? '\u2014' : `${(number * 100).toFixed(digits)}\u00a2`;
    }

    function percent(value, digits = 1) {
        const number = parseNumber(value);
        return number === null ? '\u2014' : `${(number * 100).toFixed(digits)}%`;
    }

    function signedPercent(value, digits = 1) {
        const number = parseNumber(value);
        if (number === null) {
            return '\u2014';
        }
        return `${number >= 0 ? '+' : ''}${(number * 100).toFixed(digits)}%`;
    }

    function signedMoney(value) {
        const number = parseNumber(value);
        if (number === null) {
            return '\u2014';
        }
        return `${number >= 0 ? '+' : '-'}${money.format(Math.abs(number))}`;
    }

    function renderCountdown() {
        if (!state.endDate) {
            byId('countdown').textContent = '\u2014';
            return;
        }

        const remaining = state.endDate.getTime() - Date.now();
        if (remaining <= 0) {
            byId('countdown').textContent = 'Ended';
            byId('marketStatus').textContent = 'Closing';
            return;
        }

        const totalSeconds = Math.floor(remaining / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        byId('countdown').textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        byId('marketStatus').textContent = 'Active';
    }

    function renderOutcomes(tokens) {
        const container = byId('outcomeCards');
        container.innerHTML = '';

        if (!Array.isArray(tokens) || tokens.length === 0) {
            container.innerHTML = '<div class="col-12"><div class="alert alert-secondary mb-0">No executable order-book data was returned.</div></div>';
            return;
        }

        tokens.forEach((token) => {
            const midpoint = parseNumber(token.midpoint);
            const spread = parseNumber(token.spread);
            const bestBid = parseNumber(token.best_bid);
            const bestAsk = parseNumber(token.best_ask);
            const isUp = String(token.outcome || '').toLowerCase() === 'up';

            const column = document.createElement('div');
            column.className = 'col-12 col-md-6';
            column.innerHTML = `
                <section class="card outcome-card shadow-sm h-100 ${isUp ? 'outcome-up' : 'outcome-down'}">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="h5 mb-0 outcome-name"></h2>
                            <span class="badge text-bg-dark border border-secondary">Live CLOB</span>
                        </div>
                        <div class="display-5 fw-semibold mt-3 outcome-price"></div>
                        <div class="text-secondary">Order-book midpoint</div>
                        <div class="outcome-book mt-3">
                            <div><span>Best bid</span><strong class="best-bid"></strong></div>
                            <div><span>Best ask</span><strong class="best-ask"></strong></div>
                            <div><span>Spread</span><strong class="spread"></strong></div>
                        </div>
                    </div>
                </section>`;
            column.querySelector('.outcome-name').textContent = token.outcome || 'Outcome';
            column.querySelector('.outcome-price').textContent = cents(midpoint);
            column.querySelector('.best-bid').textContent = cents(bestBid);
            column.querySelector('.best-ask').textContent = cents(bestAsk);
            column.querySelector('.spread').textContent = cents(spread, 2);
            container.appendChild(column);
        });
    }

    function renderDashboard(data) {
        byId('apiStatus').textContent = 'Online';
        byId('apiStatus').className = 'col-5 text-end text-success';
        byId('lastUpdate').textContent = new Date(data.generated_at).toLocaleTimeString();

        const geo = data.geoblock || {};
        byId('geoStatus').textContent = geo.blocked ? 'Live trading blocked' : 'Location check passed';
        byId('geoStatus').className = `h6 mb-1 ${geo.blocked ? 'text-warning' : 'text-success'}`;
        byId('geoDetails').textContent = `${geo.country || 'Unknown'}${geo.region ? ` / ${geo.region}` : ''}. Research remains paper-only.`;
        byId('geoDot').className = `status-dot ${geo.blocked ? 'status-warning' : 'status-ok'}`;

        if (!data.market) {
            byId('marketQuestion').textContent = `No active ${assetSymbol} ${intervalMinutes}-minute market found`;
            byId('marketDates').textContent = 'The scanner will try again automatically.';
            byId('liquidity').textContent = '\u2014';
            byId('volume').textContent = '\u2014';
            byId('marketStatus').textContent = 'Waiting';
            state.endDate = null;
            renderOutcomes([]);
            return;
        }

        const market = data.market;
        byId('marketQuestion').textContent = market.question || 'Unnamed market';
        const start = market.interval_start ? new Date(market.interval_start).toLocaleTimeString() : 'unknown';
        const end = market.end_date ? new Date(market.end_date).toLocaleTimeString() : 'unknown';
        byId('marketDates').textContent = `Trading interval ${start} to ${end}`;
        byId('liquidity').textContent = parseNumber(market.liquidity) === null
            ? '\u2014'
            : money.format(parseNumber(market.liquidity));
        byId('volume').textContent = parseNumber(market.volume) === null
            ? '\u2014'
            : money.format(parseNumber(market.volume));
        state.endDate = market.end_date ? new Date(market.end_date) : null;
        renderCountdown();
        renderOutcomes(market.tokens);
    }

    function decisionClass(decision) {
        switch (decision) {
            case 'PAPER BET':
                return 'text-bg-success';
            case 'SKIP':
            case 'RISK STOP':
                return 'text-bg-danger';
            case 'WATCH':
                return 'text-bg-info';
            default:
                return 'text-bg-secondary';
        }
    }

    function renderModel(data) {
        state.model = data;
        byId('storageDot').className = 'status-dot status-ok';
        byId('storageStatus').textContent = 'Saving observations and paper results locally.';

        const settings = data.settings || {};
        byId('positionSize').textContent = money.format(parseNumber(settings.position_size) ?? 0);
        byId('minimumEdge').textContent = percent(settings.minimum_edge);
        byId('dailyLossLimit').textContent = money.format(parseNumber(settings.daily_loss_limit) ?? 0);
        byId('assumedSlippage').textContent = cents(settings.slippage, 2);
        byId('maximumSpread').textContent = cents(settings.maximum_spread, 2);

        const performance = data.performance || {};
        byId('paperBalance').textContent = money.format(parseNumber(performance.balance) ?? 0);
        byId('paperPnl').textContent = performance.total_trades
            ? `${signedMoney(performance.realized_pnl)} realized / ${money.format(parseNumber(performance.maximum_drawdown) ?? 0)} max drawdown`
            : 'No resolved trades';
        byId('paperPnl').className = `small ${parseNumber(performance.realized_pnl) > 0 ? 'text-success' : parseNumber(performance.realized_pnl) < 0 ? 'text-danger' : 'text-secondary'}`;
        byId('tradeCount').textContent = String(performance.total_trades ?? 0);
        byId('openTrades').textContent = `${performance.open_trades ?? 0} open / ${money.format(parseNumber(performance.open_exposure) ?? 0)} exposed`;
        byId('winRate').textContent = percent(performance.win_rate);
        byId('winLoss').textContent = `${performance.wins ?? 0} won / ${performance.losses ?? 0} lost`;

        const metrics = data.metrics || {};
        byId('modelSample').textContent = String(metrics.resolved_markets ?? 0);
        byId('modelEvidence').textContent = parseNumber(metrics.model_brier) === null
            ? (metrics.status || 'Collecting baseline evidence')
            : `Brier ${parseNumber(metrics.model_brier).toFixed(3)} vs market ${parseNumber(metrics.market_brier)?.toFixed(3) ?? '\u2014'}`;
        byId('flowEvidence').textContent = String(metrics.flow_resolved_markets ?? 0);
        byId('flowMatchRate').textContent = parseNumber(metrics.flow_direction_match) === null
            ? 'Starts after newly recorded markets resolve'
            : `${percent(metrics.flow_direction_match)} directional match at ~60s`;
        const earlyExit = data.early_exit || {};
        const trackedExits = Number(earlyExit.tracked_trades ?? 0);
        const profitableExits = Number(earlyExit.profitable_opportunities ?? 0);
        byId('earlyExitTracked').textContent = String(trackedExits);
        byId('earlyExitRate').textContent = percent(earlyExit.opportunity_rate);
        byId('earlyExitCount').textContent = trackedExits > 0
            ? `${profitableExits} of ${trackedExits} trades`
            : 'Waiting for later price observations';
        byId('earlyExitRescued').textContent = String(earlyExit.losing_trades_profitable_earlier ?? 0);
        byId('earlyExitBest').textContent = signedMoney(earlyExit.best_observed_pnl);
        const exit10 = data.exit_10_shadow || {};
        byId('exit10RulePnl').textContent = signedMoney(exit10.rule_pnl);
        byId('exit10HoldPnl').textContent = signedMoney(exit10.hold_pnl);
        byId('exit10Difference').textContent = signedMoney(exit10.difference);
        byId('exit10Difference').className = parseNumber(exit10.difference) > 0
            ? 'text-success'
            : parseNumber(exit10.difference) < 0
                ? 'text-danger'
                : '';
        byId('exit10Resolved').textContent = `${exit10.resolved_trades ?? 0} resolved trade${Number(exit10.resolved_trades) === 1 ? '' : 's'}`;
        byId('exit10Triggered').textContent = String(exit10.exits_triggered ?? 0);
        byId('exit10WinnersCut').textContent =
            `${exit10.eventual_winners_exited ?? 0} eventual winner${Number(exit10.eventual_winners_exited) === 1 ? '' : 's'} exited`;
        renderTradeHistory(data.recent_trades || []);

        const signal = data.signal;
        const currentMarketId = state.dashboard?.market?.id;
        if (!signal || (currentMarketId && String(signal.market_id) !== String(currentMarketId))) {
            renderEmptySignal('Waiting for the first current-market Chainlink observation.');
            return;
        }

        byId('lastModelTick').textContent = new Date(signal.observed_at).toLocaleTimeString();
        const probabilityUp = parseNumber(signal.probability_up);
        const probabilityDown = parseNumber(signal.probability_down);
        const decision = signal.decision || 'WARMING UP';
        const recommendation = signal.recommended_outcome ? ` ${signal.recommended_outcome}` : '';

        byId('signalHeading').textContent = decision === 'PAPER BET'
            ? `Paper bet${recommendation}`
            : decision.charAt(0) + decision.slice(1).toLowerCase();
        byId('decisionBadge').textContent = `${decision}${decision === 'SKIP' && recommendation ? recommendation.toUpperCase() : ''}`;
        byId('decisionBadge').className = `badge decision-badge ${decisionClass(decision)}`;
        byId('modelReason').textContent = signal.reason || '';

        byId('modelUpProbability').textContent = percent(probabilityUp);
        byId('modelDownProbability').textContent = percent(probabilityDown);
        byId('probabilityUpBar').style.width = `${probabilityUp === null ? 50 : Math.max(1, Math.min(99, probabilityUp * 100))}%`;
        byId('priceToBeat').textContent = parseNumber(signal.price_to_beat) === null ? '\u2014' : assetPrice.format(signal.price_to_beat);
        byId('chainlinkPrice').textContent = assetPrice.format(parseNumber(signal.chainlink_price) ?? 0);
        const distance = parseNumber(signal.distance);
        byId('priceDistance').textContent = distance === null
            ? '\u2014'
            : `${distance >= 0 ? '+' : '-'}${money.format(Math.abs(distance))}`;
        byId('priceDistance').className = `signal-metric ${distance > 0 ? 'text-info' : distance < 0 ? 'text-danger' : ''}`;
        byId('modelVolatility').textContent = parseNumber(signal.volatility_60s) === null
            ? '\u2014'
            : `${money.format(signal.volatility_60s)} / \u221as`;

        byId('compareUpModel').textContent = percent(probabilityUp);
        byId('compareDownModel').textContent = percent(probabilityDown);
        byId('compareUpAsk').textContent = cents(signal.up_ask);
        byId('compareDownAsk').textContent = cents(signal.down_ask);
        byId('compareUpEdge').textContent = signedPercent(signal.up_net_edge);
        byId('compareDoÛü¶‰žËkºwµç@€€…É¹ÍÉ½±±%¹Ñ½Y¥•Ü¡ì‰•¡…Ù¥½Èè€Íµ½½Ñ œ°‰±½¬è€¹•…É•ÍÐœô¤ì(€€€€€€€ô…Ñ €¡•ÉÉ½È¤ì(€€€€€€€€€€€ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐ€ô¹Õ±°ì(€€€€€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ!•…‘¥¹œœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€M•±±¥¹œµÁÉ¥”¡¥ÍÑ½ÉäÕ¹…Ù…¥±…‰±”œì(€€€€€€€€€€€‰å% ÑÉ…‘•¡…ÉÑA½¥¹Ñ•Èœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô•ÉÉ½È¥¹ÍÑ…¹•½˜ÉÉ½È(€€€€€€€€€€€€€€€€ü•ÉÉ½È¹µ•ÍÍ…”(€€€€€€€€€€€€€€€€è€U¹…‰±”Ñ¼±½…Ñ¡¥ÌÑÉ…‘”¡…ÉÐ¸œì(€€€€€€€ô(€€€ô((€€€™Õ¹Ñ¥½¸É•¹‘•ÉQÉ…‘•¡…ÉÐ¡‘…Ñ„¤ì(€€€€€€€½¹ÍÐÑÉ…‘”€ô‘…Ñ„¹ÑÉ…‘”ñðíôì(€€€€€€€½¹ÍÐÁ½¥¹ÑÌ€ôÉÉ…ä¹¥ÍÉÉ…ä¡‘…Ñ„¹Á½¥¹ÑÌ¤€ü‘…Ñ„¹Á½¥¹ÑÌ€èmtì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ!•…‘¥¹œœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€‘íÑÉ…‘”¹½ÕÑ½µ”ñð€œôÑÉ…‘”Í•±±¥¹œµÁÉ¥”Á…Ñ¡€ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑMÕ‰Ñ¥Ñ±”œ¤¹Ñ•áÑ½¹Ñ•¹Ð€ôÑÉ…‘”¹ÅÕ•ÍÑ¥½¸ñð€I•½É‘•ÑÉ…‘”½‰Í•ÉÙ…Ñ¥½¹Ìœì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ¹ÑÉäœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô•¹ÑÌ¡ÑÉ…‘”¹•¹ÑÉå}ÁÉ¥”¤ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ	•ÍÐœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ôÍ¥¹•‘5½¹•ä¡ÑÉ…‘”¹‰•ÍÑ}•á¥Ñ}Á¹°¤ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ	•ÍÐœ¤¹±…ÍÍ9…µ”€ôÁ…ÉÍ•9Õµ‰•È¡ÑÉ…‘”¹‰•ÍÑ}•á¥Ñ}Á¹°¤€ø€À€ü€Ñ•áÐµÍÕ•ÍÌœ€è€Ñ•áÐµÍ•½¹‘…Éäœì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ!½±œ¤¹Ñ•áÑ½¹Ñ•¹Ð€ôÑÉ…‘”¹¡½±‘}Á¹°€ôôô¹Õ±°€ü€MÑ¥±°½Á•¸œ€èÍ¥¹•‘5½¹•ä¡ÑÉ…‘”¹¡½±‘}Á¹°¤ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ!½±œ¤¹±…ÍÍ9…µ”€ôÁ…ÉÍ•9Õµ‰•È¡ÑÉ…‘”¹¡½±‘}Á¹°¤€ø€À€ü€Ñ•áÐµÍÕ•ÍÌœ€èÁ…ÉÍ•9Õµ‰•È¡ÑÉ…‘”¹¡½±‘}Á¹°¤€ð€À€ü€Ñ•áÐµ‘…¹•Èœ€è€œœì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑM…µÁ±•Ìœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ôMÑÉ¥¹œ¡Á½¥¹ÑÌ¹±•¹Ñ ¤ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑµÁÑäœ¤¹±…ÍÍ1¥ÍÐ¹Ñ½±” µ¹½¹”œ°Á½¥¹ÑÌ¹±•¹Ñ €ø€À¤ì(€€€€€€€‰å% ÑÉ…‘•á¥Ñ¡…ÉÐœ¤¹±…ÍÍ1¥ÍÐ¹Ñ½±” µ¹½¹”œ°Á½¥¹ÑÌ¹±•¹Ñ €ôôô€À¤ì((€€€€€€€¥˜€¡Á½¥¹ÑÌ¹±•¹Ñ €ôôô€À¤ì(€€€€€€€€€€€ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÑ•½µ•ÑÉä€ômtì(€€€€€€€€€€€‰å% ÑÉ…‘•¡…ÉÑA½¥¹Ñ•Èœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€9¼±…Ñ•È•á•ÕÑ…‰±”‰¥‘ÌÝ•É”…ÁÑÕÉ•™½ÈÑ¡¥ÌÑÉ…‘”¸œì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô((€€€€€€€‘É…ÝQÉ…‘•¡…ÉÐ ¤ì(€€€€€€€Í¡½ÝQÉ…‘•¡…ÉÑA½¥¹Ð¡Á½¥¹ÑÍmÁ½¥¹ÑÌ¹±•¹Ñ €´€Åt¤ì(€€€ô((€€€™Õ¹Ñ¥½¸‘É…ÝQÉ…‘•¡…ÉÐ ¤ì(€€€€€€€½¹ÍÐ‘…Ñ„€ôÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐì(€€€€€€€½¹ÍÐÁ½¥¹ÑÌ€ô‘…Ñ„ü¹Á½¥¹ÑÌñðmtì(€€€€€€€¥˜€ …‘…Ñ„ñðÁ½¥¹ÑÌ¹±•¹Ñ €ôôô€À¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô((€€€€€€€½¹ÍÐ…¹Ù…Ì€ô‰å% ÑÉ…‘•á¥Ñ¡…ÉÐœ¤ì(€€€€€€€½¹ÍÐÝ¥‘Ñ €ô5…Ñ ¹µ…à ÌÈÀ°…¹Ù…Ì¹Á…É•¹Ñ±•µ•¹Ð¹±¥•¹Ñ]¥‘Ñ ¤ì(€€€€€€€½¹ÍÐ¡•¥¡Ð€ô€ÌÈÀì(€€€€€€€½¹ÍÐÉ…Ñ¥¼€ôÝ¥¹‘½Ü¹‘•Ù¥•A¥á•±I…Ñ¥¼ñð€Äì(€€€€€€€…¹Ù…Ì¹Ý¥‘Ñ €ô5…Ñ ¹™±½½È¡Ý¥‘Ñ €¨É…Ñ¥¼¤ì(€€€€€€€…¹Ù…Ì¹¡•¥¡Ð€ô5…Ñ ¹™±½½È¡¡•¥¡Ð€¨É…Ñ¥¼¤ì(€€€€€€€…¹Ù…Ì¹ÍÑå±”¹Ý¥‘Ñ €ô€‘íÝ¥‘Ñ¡õÁá€ì(€€€€€€€…¹Ù…Ì¹ÍÑå±”¹¡•¥¡Ð€ô€‘í¡•¥¡ÑõÁá€ì(€€€€€€€½¹ÍÐ½¹Ñ•áÐ€ô…¹Ù…Ì¹•Ñ½¹Ñ•áÐ œÉœ¤ì(€€€€€€€½¹Ñ•áÐ¹Í•ÑQÉ…¹Í™½É´¡É…Ñ¥¼°€À°€À°É…Ñ¥¼°€À°€À¤ì(€€€€€€€½¹Ñ•áÐ¹±•…ÉI•Ð À°€À°Ý¥‘Ñ °¡•¥¡Ð¤ì((€€€€€€€½¹ÍÐÁ…‘‘¥¹œ€ôìÑ½Àè€ÈÈ°É¥¡Ðè€Äà°‰½ÑÑ½´è€ÐÈ°±•™Ðè€Ôàôì(€€€€€€€½¹ÍÐÁ±½Ñ]¥‘Ñ €ôÝ¥‘Ñ €´Á…‘‘¥¹œ¹±•™Ð€´Á…‘‘¥¹œ¹É¥¡Ðì(€€€€€€€½¹ÍÐÁ±½Ñ!•¥¡Ð€ô¡•¥¡Ð€´Á…‘‘¥¹œ¹Ñ½À€´Á…‘‘¥¹œ¹‰½ÑÑ½´ì(€€€€€€€½¹ÍÐ•¹ÑÉä€ôÁ…ÉÍ•9Õµ‰•È¡‘…Ñ„¹ÑÉ…‘”¹•¹ÑÉå}ÁÉ¥”¤€üü€À¸Ôì(€€€€€€€½¹ÍÐ‰¥‘Ì€ôÁ½¥¹ÑÌ¹µ…À ¡Á½¥¹Ð¤€ôøÁ…ÉÍ•9Õµ‰•È¡Á½¥¹Ð¹•á¥Ñ}‰¥¤¤¹™¥±Ñ•È ¡Ù…±Õ”¤€ôøÙ…±Õ”€„ôô¹Õ±°¤ì(€€€€€€€±•Ðµ¥¹¥µÕ´€ô5…Ñ ¹µ…à À°5…Ñ ¹µ¥¸¡•¹ÑÉä°€¸¸¹‰¥‘Ì¤€´€À¸ÀÐ¤ì(€€€€€€€±•Ðµ…á¥µÕ´€ô5…Ñ ¹µ¥¸ Ä°5…Ñ ¹µ…à¡•¹ÑÉä°€¸¸¹‰¥‘Ì¤€¬€À¸ÀÐ¤ì(€€€€€€€¥˜€¡µ…á¥µÕ´€´µ¥¹¥µÕ´€ð€À¸Ä¤ì(€€€€€€€€€€€½¹ÍÐ•¹Ñ•È€ô€¡µ…á¥µÕ´€¬µ¥¹¥µÕ´¤€¼€Èì(€€€€€€€€€€€µ¥¹¥µÕ´€ô5…Ñ ¹µ…à À°•¹Ñ•È€´€À¸ÀÔ¤ì(€€€€€€€€€€€µ…á¥µÕ´€ô5…Ñ ¹µ¥¸ Ä°•¹Ñ•È€¬€À¸ÀÔ¤ì(€€€€€€€ô((€€€€€€€½¹ÍÐá½È€ô€¡¥¹‘•à¤€ôøÁ…‘‘¥¹œ¹±•™Ð€¬€¡Á½¥¹ÑÌ¹±•¹Ñ €ôôô€Ä€üÁ±½Ñ]¥‘Ñ €¼€È€è¥¹‘•à€¼€¡Á½¥¹ÑÌ¹±•¹Ñ €´€Ä¤€¨Á±½Ñ]¥‘Ñ ¤ì(€€€€€€€½¹ÍÐå½È€ô€¡Ù…±Õ”¤€ôøÁ…‘‘¥¹œ¹Ñ½À€¬€¡µ…á¥µÕ´€´Ù…±Õ”¤€¼€¡µ…á¥µÕ´€´µ¥¹¥µÕ´¤€¨Á±½Ñ!•¥¡Ðì((€€€€€€€½¹Ñ•áÐ¹™½¹Ð€ô€œÄÉÁàÍåÍÑ•´µÕ¤°Í…¹ÌµÍ•É¥˜œì(€€€€€€€½¹Ñ•áÐ¹Ñ•áÑ	…Í•±¥¹”€ô€µ¥‘‘±”œì(€€€€€€€™½È€¡±•Ð¥¹‘•à€ô€Àì¥¹‘•à€ðô€Ðì¥¹‘•à€¬ô€Ä¤ì(€€€€€€€€€€€½¹ÍÐÙ…±Õ”€ôµ…á¥µÕ´€´€¡µ…á¥µÕ´€´µ¥¹¥µÕ´¤€¨¥¹‘•à€¼€Ðì(€€€€€€€€€€€½¹ÍÐä€ôÁ…‘‘¥¹œ¹Ñ½À€¬Á±½Ñ!•¥¡Ð€¨¥¹‘•à€¼€Ðì(€€€€€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­•MÑå±”€ô€É‰„ ÈÔÔ°ÈÔÔ°ÈÔÔ°À¸Àà¤œì(€€€€€€€€€€€½¹Ñ•áÐ¹‰•¥¹A…Ñ  ¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹µ½Ù•Q¼¡Á…‘‘¥¹œ¹±•™Ð°ä¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹±¥¹•Q¼¡Ý¥‘Ñ €´Á…‘‘¥¹œ¹É¥¡Ð°ä¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­” ¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹™¥±±MÑå±”€ô€É‰„ ÈÔÔ°ÈÔÔ°ÈÔÔ°À¸Ôà¤œì(€€€€€€€€€€€½¹Ñ•áÐ¹Ñ•áÑ±¥¸€ô€É¥¡Ðœì(€€€€€€€€€€€½¹Ñ•áÐ¹™¥±±Q•áÐ¡€‘ì¡Ù…±Õ”€¨€ÄÀÀ¤¹Ñ½¥á• À¥õqÔÀÁ„É€°Á…‘‘¥¹œ¹±•™Ð€´€ä°ä¤ì(€€€€€€€ô((€€€€€€€½¹ÍÐ•¹ÑÉåd€ôå½È¡•¹ÑÉä¤ì(€€€€€€€½¹Ñ•áÐ¹Í…Ù” ¤ì(€€€€€€€½¹Ñ•áÐ¹Í•Ñ1¥¹•…Í ¡lØ°€Õt¤ì(€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­•MÑå±”€ô€É‰„ ÈÔÔ°ÈÔÔ°ÈÔÔ°À¸ÔÔ¤œì(€€€€€€€½¹Ñ•áÐ¹‰•¥¹A…Ñ  ¤ì(€€€€€€€½¹Ñ•áÐ¹µ½Ù•Q¼¡Á…‘‘¥¹œ¹±•™Ð°•¹ÑÉåd¤ì(€€€€€€€½¹Ñ•áÐ¹±¥¹•Q¼¡Ý¥‘Ñ €´Á…‘‘¥¹œ¹É¥¡Ð°•¹ÑÉåd¤ì(€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­” ¤ì(€€€€€€€½¹Ñ•áÐ¹É•ÍÑ½É” ¤ì((€€€€€€€™½È€¡±•Ð¥¹‘•à€ô€Äì¥¹‘•à€ðÁ½¥¹ÑÌ¹±•¹Ñ ì¥¹‘•à€¬ô€Ä¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­•MÑå±”€ôÁ½¥¹ÑÍm¥¹‘•át¹ÁÉ½™¥Ñ…‰±”€ü€œŒÔÕäáˆœ€è€œŒÙ•”Ý˜Üœì(€€€€€€€€€€€½¹Ñ•áÐ¹±¥¹•]¥‘Ñ €ô€È¸Ôì(€€€€€€€€€€€½¹Ñ•áÐ¹‰•¥¹A…Ñ  ¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹µ½Ù•Q¼¡á½È¡¥¹‘•à€´€Ä¤°å½È¡Á…ÉÍ•9Õµ‰•È¡Á½¥¹ÑÍm¥¹‘•à€´€Åt¹•á¥Ñ}‰¥¤¤¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹±¥¹•Q¼¡á½È¡¥¹‘•à¤°å½È¡Á…ÉÍ•9Õµ‰•È¡Á½¥¹ÑÍm¥¹‘•át¹•á¥Ñ}‰¥¤¤¤ì(€€€€€€€€€€€½¹Ñ•áÐ¹ÍÑÉ½­” ¤ì(€€€€€€€ô((€€€€€€€½¹ÍÐ‰•ÍÑ%¹‘•à€ôÁ½¥¹ÑÌ¹É•‘Õ” ¡‰•ÍÐ°Á½¥¹Ð°¥¹‘•à¤€ôø(€€€€€€€€€€€Á…ÉÍ•9Õµ‰•È¡Á½¥¹Ð¹•ÍÑ¥µ…Ñ•‘}Á¹°¤€øÁ…ÉÍ•9Õµ‰•È¡Á½¥¹ÑÍm‰•ÍÑt¹•ÍÑ¥µ…Ñ•‘}Á¹°¤€ü¥¹‘•à€è‰•ÍÐ°€À¤ì(€€€€€€€½¹Ñ•áÐ¹™¥±±MÑå±”€ô€œ™™ŒÄÀÜœì(€€€€€€€½¹Ñ•áÐ¹‰•¥¹A…Ñ  ¤ì(€€€€€€€½¹Ñ•áÐ¹…ÉŒ¡á½È¡‰•ÍÑ%¹‘•à¤°å½È¡Á…ÉÍ•9Õµ‰•È¡Á½¥¹ÑÍm‰•ÍÑ%¹‘•át¹•á¥Ñ}‰¥¤¤°€Ô°€À°5…Ñ ¹A$€¨€È¤ì(€€€€€€€½¹Ñ•áÐ¹™¥±° ¤ì((€€€€€€€½¹Ñ•áÐ¹™¥±±MÑå±”€ô€É‰„ ÈÔÔ°ÈÔÔ°ÈÔÔ°À¸Ôà¤œì(€€€€€€€½¹Ñ•áÐ¹Ñ•áÑ±¥¸€ô€±•™Ðœì(€€€€€€€½¹Ñ•áÐ¹™¥±±Q•áÐ¡€‘íÁ½¥¹ÑÍlÁt¹Í•½¹‘Í}É•µ…¥¹¥¹õÌ±•™Ñ€°Á…‘‘¥¹œ¹±•™Ð°¡•¥¡Ð€´€ÄÜ¤ì(€€€€€€€½¹Ñ•áÐ¹Ñ•áÑ±¥¸€ô€É¥¡Ðœì(€€€€€€€½¹Ñ•áÐ¹™¥±±Q•áÐ¡€‘íÁ½¥¹ÑÍmÁ½¥¹ÑÌ¹±•¹Ñ €´€Åt¹Í•½¹‘Í}É•µ…¥¹¥¹õÌ±•™Ñ€°Ý¥‘Ñ €´Á…‘‘¥¹œ¹É¥¡Ð°¡•¥¡Ð€´€ÄÜ¤ì((€€€€€€€ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÑ•½µ•ÑÉä€ôÁ½¥¹ÑÌ¹µ…À ¡Á½¥¹Ð°¥¹‘•à¤€ôø€¡ì(€€€€€€€€€€€Á½¥¹Ð°(€€€€€€€€€€€àèá½È¡¥¹‘•à¤°(€€€€€€€€€€€äèå½È¡Á…ÉÍ•9Õµ‰•È¡Á½¥¹Ð¹•á¥Ñ}‰¥¤¤°(€€€€€€€ô¤¤ì(€€€ô((€€€™Õ¹Ñ¥½¸Í¡½ÝQÉ…‘•¡…ÉÑA½¥¹Ð¡Á½¥¹Ð¤ì(€€€€€€€¥˜€ …Á½¥¹Ð¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô(€€€€€€€½¹ÍÐÑ¥µ”€ô¹•Ü…Ñ”¡Á½¥¹Ð¹½‰Í•ÉÙ•‘}…Ð¤¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ¡mt°ì¡½ÕÈè€œÈµ‘¥¥Ðœ°µ¥¹ÕÑ”è€œÈµ‘¥¥Ðœ°Í•½¹è€œÈµ‘¥¥Ðœô¤ì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑA½¥¹Ñ•Èœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô(€€€€€€€€€€€€‘íÑ¥µ•ôè€‘í•¹ÑÌ¡Á½¥¹Ð¹•á¥Ñ}‰¥¥ôÍ•±±¥¹œ‰¥°€‘íÍ¥¹•‘5½¹•ä¡Á½¥¹Ð¹•ÍÑ¥µ…Ñ•‘}Á¹°¥ô•ÍÑ¥µ…Ñ•@™0°€‘íÁ½¥¹Ð¹Í•½¹‘Í}É•µ…¥¹¥¹õÌÉ•µ…¥¹¥¹œ¹€ì(€€€ô((€€€…Íå¹Œ™Õ¹Ñ¥½¸±½…‘…Í¡‰½…É ¤ì(€€€€€€€¥˜€¡ÍÑ…Ñ”¹‘…Í¡‰½…É‘A•¹‘¥¹œ¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô(€€€€€€€ÍÑ…Ñ”¹‘…Í¡‰½…É‘A•¹‘¥¹œ€ôÑÉÕ”ì(€€€€€€€‰å% …Á¥MÑ…ÑÕÌœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€1½…‘¥¹œœì(€€€€€€€‰å% É•™É•Í¡	ÕÑÑ½¸œ¤¹‘¥Í…‰±•€ôÑÉÕ”ì((€€€€€€€ÑÉäì(€€€€€€€€€€€½¹ÍÐÉ•ÍÁ½¹Í”€ô…Ý…¥Ð™•Ñ ¡…Á¥UÉ° …Á¤½‘…Í¡‰½…É¹Á¡Àœ¤°ì…¡”è€¹¼µÍÑ½É”œô¤ì(€€€€€€€€€€€½¹ÍÐ‘…Ñ„€ô…Ý…¥ÐÉ•ÍÁ½¹Í”¹©Í½¸ ¤ì(€€€€€€€€€€€¥˜€ …É•ÍÁ½¹Í”¹½¬ñð€…‘…Ñ„¹½¬¤ì(€€€€€€€€€€€€€€€Ñ¡É½Ü¹•ÜÉÉ½È¡‘…Ñ„¹•ÉÉ½Èñð…Í¡‰½…ÉA$É•ÑÕÉ¹•!QQ@€‘íÉ•ÍÁ½¹Í”¹ÍÑ…ÑÕÍõ€¤ì(€€€€€€€€€€€ô(€€€€€€€€€€€ÍÑ…Ñ”¹‘…Í¡‰½…É€ô‘…Ñ„ì(€€€€€€€€€€€É•¹‘•É…Í¡‰½…É¡‘…Ñ„¤ì(€€€€€€€€€€€±•…É±•ÉÐ ¤ì(€€€€€€€ô…Ñ €¡•ÉÉ½È¤ì(€€€€€€€€€€€‰å% …Á¥MÑ…ÑÕÌœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€ÉÉ½Èœì(€€€€€€€€€€€‰å% …Á¥MÑ…ÑÕÌœ¤¹±…ÍÍ9…µ”€ô€½°´ÔÑ•áÐµ•¹Ñ•áÐµ‘…¹•Èœì(€€€€€€€€€€€Í¡½Ý±•ÉÐ¡•ÉÉ½È¥¹ÍÑ…¹•½˜ÉÉ½È€ü•ÉÉ½È¹µ•ÍÍ…”€è€U¹…‰±”Ñ¼±½…‘…Í¡‰½…É‘…Ñ„¸œ¤ì(€€€€€€€ô™¥¹…±±äì(€€€€€€€€€€€ÍÑ…Ñ”¹‘…Í¡‰½…É‘A•¹‘¥¹œ€ô™…±Í”ì(€€€€€€€€€€€‰å% É•™É•Í¡	ÕÑÑ½¸œ¤¹‘¥Í…‰±•€ô™…±Í”ì(€€€€€€€ô(€€€ô((€€€…Íå¹Œ™Õ¹Ñ¥½¸±½…‘5½‘•° ¤ì(€€€€€€€ÑÉäì(€€€€€€€€€€€½¹ÍÐÉ•ÍÁ½¹Í”€ô…Ý…¥Ð™•Ñ ¡…Á¥UÉ° …Á¤½µ½‘•°¹Á¡Àœ¤°ì…¡”è€¹¼µÍÑ½É”œô¤ì(€€€€€€€€€€€½¹ÍÐ‘…Ñ„€ô…Ý…¥ÐÉ•ÍÁ½¹Í”¹©Í½¸ ¤ì(€€€€€€€€€€€¥˜€ …É•ÍÁ½¹Í”¹½¬ñð€…‘…Ñ„¹½¬¤ì(€€€€€€€€€€€€€€€Ñ¡É½Ü¹•ÜÉÉ½È¡‘…Ñ„¹•ÉÉ½Èñð5½‘•°A$É•ÑÕÉ¹•!QQ@€‘íÉ•ÍÁ½¹Í”¹ÍÑ…ÑÕÍõ€¤ì(€€€€€€€€€€€ô(€€€€€€€€€€€É•¹‘•É5½‘•°¡‘…Ñ„¤ì(€€€€€€€ô…Ñ €¡•ÉÉ½È¤ì(€€€€€€€€€€€‰å% ÍÑ½É…•½Ðœ¤¹±…ÍÍ9…µ”€ô€ÍÑ…ÑÕÌµ‘½ÐÍÑ…ÑÕÌµ•ÉÉ½Èœì(€€€€€€€€€€€‰å% ÍÑ½É…•MÑ…ÑÕÌœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô•ÉÉ½È¥¹ÍÑ…¹•½˜ÉÉ½È€ü•ÉÉ½È¹µ•ÍÍ…”€è€5½‘•°¡¥ÍÑ½Éä¥ÌÕ¹…Ù…¥±…‰±”¸œì(€€€€€€€ô(€€€ô((€€€…Íå¹Œ™Õ¹Ñ¥½¸ÍÕ‰µ¥Ñ=‰Í•ÉÙ…Ñ¥½¸ ¤ì(€€€€€€€¥˜€ (€€€€€€€€€€€ÍÑ…Ñ”¹½‰Í•ÉÙ…Ñ¥½¹A•¹‘¥¹œ(€€€€€€€€€€€ñð€…ÍÑ…Ñ”¹‘…Í¡‰½…Éü¹µ…É­•Ð(€€€€€€€€€€€ñð€…ÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬(€€€€€€€€¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô((€€€€€€€½¹ÍÐ™••‘”€ô…Ñ”¹¹½Ü ¤€´ÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬¹Ñ¥µ•ÍÑ…µÀì(€€€€€€€¥˜€¡™••‘”€ø€ÄÕ|ÀÀÀ¤ì(€€€€€€€€€€€ÕÁ‘…Ñ•••‘MÑ…ÑÕÌ ¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô((€€€€€€€ÍÑ…Ñ”¹½‰Í•ÉÙ…Ñ¥½¹A•¹‘¥¹œ€ôÑÉÕ”ì(€€€€€€€ÑÉäì(€€€€€€€€€€€½¹ÍÐÉ•ÍÁ½¹Í”€ô…Ý…¥Ð™•Ñ ¡…Á¥UÉ° …Á¤½µ½‘•°¹Á¡Àœ¤°ì(€€€€€€€€€€€€€€€µ•Ñ¡½è€A=MPœ°(€€€€€€€€€€€€€€€¡•…‘•ÉÌèì€½¹Ñ•¹ÐµQåÁ”œè€…ÁÁ±¥…Ñ¥½¸½©Í½¸œô°(€€€€€€€€€€€€€€€‰½‘äè)M=8¹ÍÑÉ¥¹¥™ä¡ì(€€€€€€€€€€€€€€€€€€€µ…É­•ÐèÍÑ…Ñ”¹‘…Í¡‰½…É¹µ…É­•Ð°(€€€€€€€€€€€€€€€€€€€™••‘Ìèì(€€€€€€€€€€€€€€€€€€€€€€€¡…¥¹±¥¹­}ÁÉ¥”èÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬¹Ù…±Õ”°(€€€€€€€€€€€€€€€€€€€€€€€¡…¥¹±¥¹­}Ñ¥µ•ÍÑ…µÀèÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬¹Ñ¥µ•ÍÑ…µÀ°(€€€€€€€€€€€€€€€€€€€€€€€‰¥¹…¹•}ÁÉ¥”èÍÑ…Ñ”¹™••‘Ì¹‰¥¹…¹”ü¹Ù…±Õ”€üü¹Õ±°°(€€€€€€€€€€€€€€€€€€€€€€€‰¥¹…¹•}Ñ¥µ•ÍÑ…µÀèÍÑ…Ñ”¹™••‘Ì¹‰¥¹…¹”ü¹Ñ¥µ•ÍÑ…µÀ€üü¹Õ±°°(€€€€€€€€€€€€€€€€€€€ô°(€€€€€€€€€€€€€€€ô¤°(€€€€€€€€€€€ô¤ì(€€€€€€€€€€€½¹ÍÐ‘…Ñ„€ô…Ý…¥ÐÉ•ÍÁ½¹Í”¹©Í½¸ ¤ì(€€€€€€€€€€€¥˜€ …É•ÍÁ½¹Í”¹½¬ñð€…‘…Ñ„¹½¬¤ì(€€€€€€€€€€€€€€€Ñ¡É½Ü¹•ÜÉÉ½È¡‘…Ñ„¹•ÉÉ½Èñð=‰Í•ÉÙ…Ñ¥½¸A$É•ÑÕÉ¹•!QQ@€‘íÉ•ÍÁ½¹Í”¹ÍÑ…ÑÕÍõ€¤ì(€€€€€€€€€€€ô(€€€€€€€€€€€É•¹‘•É5½‘•°¡‘…Ñ„¤ì(€€€€€€€ô…Ñ €¡•ÉÉ½È¤ì(€€€€€€€€€€€‰å% ÍÑ½É…•½Ðœ¤¹±…ÍÍ9…µ”€ô€ÍÑ…ÑÕÌµ‘½ÐÍÑ…ÑÕÌµ•ÉÉ½Èœì(€€€€€€€€€€€‰å% ÍÑ½É…•MÑ…ÑÕÌœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô•ÉÉ½È¥¹ÍÑ…¹•½˜ÉÉ½È€ü•ÉÉ½È¹µ•ÍÍ…”€è€U¹…‰±”Ñ¼Í…Ù”Ñ¡”µ½‘•°½‰Í•ÉÙ…Ñ¥½¸¸œì(€€€€€€€ô™¥¹…±±äì(€€€€€€€€€€€ÍÑ…Ñ”¹½‰Í•ÉÙ…Ñ¥½¹A•¹‘¥¹œ€ô™…±Í”ì(€€€€€€€ô(€€€ô((€€€™Õ¹Ñ¥½¸½¹¹•ÑAÉ¥•••‘Ì ¤ì(€€€€€€€¥˜€¡ÍÑ…Ñ”¹Ý•‰Í½­•Ð€˜˜m]•‰M½­•Ð¹=A8°]•‰M½­•Ð¹=99Q%9t¹¥¹±Õ‘•Ì¡ÍÑ…Ñ”¹Ý•‰Í½­•Ð¹É•…‘åMÑ…Ñ”¤¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô((€€€€€€€½¹ÍÐÍ½­•Ð€ô¹•Ü]•‰M½­•Ð ÝÍÌè¼½ÝÌµ±¥Ù”µ‘…Ñ„¹Á½±åµ…É­•Ð¹½´œ¤ì(€€€€€€€ÍÑ…Ñ”¹Ý•‰Í½­•Ð€ôÍ½­•Ðì(€€€€€€€‰å% ™••‘	…‘”œ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€=99Q%9œì(€€€€€€€‰å% ™••‘	…‘”œ¤¹±…ÍÍ9…µ”€ô€‰…‘”Ñ•áÐµ‰œµÍ•½¹‘…Éäœì((€€€€€€€Í½­•Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ½Á•¸œ°€ ¤€ôøì(€€€€€€€€€€€Í½­•Ð¹Í•¹¡)M=8¹ÍÑÉ¥¹¥™ä¡ì(€€€€€€€€€€€€€€€…Ñ¥½¸è€ÍÕ‰ÍÉ¥‰”œ°(€€€€€€€€€€€€€€€ÍÕ‰ÍÉ¥ÁÑ¥½¹Ìèl(€€€€€€€€€€€€€€€€€€€ì(€€€€€€€€€€€€€€€€€€€€€€€Ñ½Á¥Œè€ÉåÁÑ½}ÁÉ¥•Í}¡…¥¹±¥¹¬œ°(€€€€€€€€€€€€€€€€€€€€€€€ÑåÁ”è€œ¨œ°(€€€€€€€€€€€€€€€€€€€€€€€™¥±Ñ•ÉÌè)M=8¹ÍÑÉ¥¹¥™ä¡ìÍåµ‰½°è¡…¥¹±¥¹­Måµ‰½°ô¤°(€€€€€€€€€€€€€€€€€€€ô°(€€€€€€€€€€€€€€€€€€€ì(€€€€€€€€€€€€€€€€€€€€€€€Ñ½Á¥Œè€ÉåÁÑ½}ÁÉ¥•Ìœ°(€€€€€€€€€€€€€€€€€€€€€€€ÑåÁ”è€ÕÁ‘…Ñ”œ°(€€€€€€€€€€€€€€€€€€€€€€€™¥±Ñ•ÉÌè‰¥¹…¹•Måµ‰½°°(€€€€€€€€€€€€€€€€€€€ô°(€€€€€€€€€€€€€€€t°(€€€€€€€€€€€ô¤¤ì(€€€€€€€€€€€Ý¥¹‘½Ü¹±•…É%¹Ñ•ÉÙ…°¡ÍÑ…Ñ”¹Á¥¹Q¥µ•È¤ì(€€€€€€€€€€€ÍÑ…Ñ”¹Á¥¹Q¥µ•È€ôÝ¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°  ¤€ôøì(€€€€€€€€€€€€€€€¥˜€¡Í½­•Ð¹É•…‘åMÑ…Ñ”€ôôô]•‰M½­•Ð¹=A8¤ì(€€€€€€€€€€€€€€€€€€€Í½­•Ð¹Í•¹ A%9œ¤ì(€€€€€€€€€€€€€€€ô(€€€€€€€€€€€ô°€ÔÀÀÀ¤ì(€€€€€€€ô¤ì((€€€€€€€Í½­•Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È µ•ÍÍ…”œ°€¡•Ù•¹Ð¤€ôøì(€€€€€€€€€€€¥˜€¡•Ù•¹Ð¹‘…Ñ„€ôôô€A=9œ¤ì(€€€€€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€€€€€ô((€€€€€€€€€€€±•Ðµ•ÍÍ…”ì(€€€€€€€€€€€ÑÉäì(€€€€€€€€€€€€€€€µ•ÍÍ…”€ô)M=8¹Á…ÉÍ”¡•Ù•¹Ð¹‘…Ñ„¤ì(€€€€€€€€€€€ô…Ñ ì(€€€€€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€€€€€ô((€€€€€€€€€€€½¹ÍÐµ•ÍÍ…•Ì€ôÉÉ…ä¹¥ÍÉÉ…ä¡µ•ÍÍ…”¤€üµ•ÍÍ…”€èmµ•ÍÍ…•tì(€€€€€€€€€€€µ•ÍÍ…•Ì¹™½É…  ¡¥Ñ•´¤€ôøì(€€€€€€€€€€€€€€€½¹ÍÐÁ…å±½…€ô¥Ñ•´ü¹Á…å±½…ì(€€€€€€€€€€€€€€€¥˜€ …Á…å±½…ñðÁ…ÉÍ•9Õµ‰•È¡Á…å±½…¹Ù…±Õ”¤€ôôô¹Õ±°¤ì(€€€€€€€€€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€€€€€€€€€ô(€€€€€€€€€€€€€€€½¹ÍÐÑ¥µ•ÍÑ…µÀ€ôÁ…ÉÍ•9Õµ‰•È¡Á…å±½…¹Ñ¥µ•ÍÑ…µÀ¤€üüÁ…ÉÍ•9Õµ‰•È¡¥Ñ•´¹Ñ¥µ•ÍÑ…µÀ¤€üü…Ñ”¹¹½Ü ¤ì(€€€€€€€€€€€€€€€½¹ÍÐ¹½Éµ…±¥é•‘Q¥µ•ÍÑ…µÀ€ôÑ¥µ•ÍÑ…µÀ€ð€ÄÁ|ÀÀÁ|ÀÀÁ|ÀÀÀ€üÑ¥µ•ÍÑ…µÀ€¨€ÄÀÀÀ€èÑ¥µ•ÍÑ…µÀì((€€€€€€€€€€€€€€€¥˜€¡¥Ñ•´¹Ñ½Á¥Œ€ôôô€ÉåÁÑ½}ÁÉ¥•Í}¡…¥¹±¥¹¬œ€˜˜MÑÉ¥¹œ¡Á…å±½…¹Íåµ‰½°¤¹Ñ½1½Ý•É…Í” ¤€ôôô¡…¥¹±¥¹­Måµ‰½°¤ì(€€€€€€€€€€€€€€€€€€€ÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬€ôì(€€€€€€€€€€€€€€€€€€€€€€€Ù…±Õ”èÁ…ÉÍ•9Õµ‰•È¡Á…å±½…¹Ù…±Õ”¤°(€€€€€€€€€€€€€€€€€€€€€€€Ñ¥µ•ÍÑ…µÀè¹½Éµ…±¥é•‘Q¥µ•ÍÑ…µÀ°(€€€€€€€€€€€€€€€€€€€ôì(€€€€€€€€€€€€€€€ô•±Í”¥˜€¡¥Ñ•´¹Ñ½Á¥Œ€ôôô€ÉåÁÑ½}ÁÉ¥•Ìœ€˜˜MÑÉ¥¹œ¡Á…å±½…¹Íåµ‰½°¤¹Ñ½1½Ý•É…Í” ¤€ôôô‰¥¹…¹•Måµ‰½°¤ì(€€€€€€€€€€€€€€€€€€€ÍÑ…Ñ”¹™••‘Ì¹‰¥¹…¹”€ôì(€€€€€€€€€€€€€€€€€€€€€€€Ù…±Õ”èÁ…ÉÍ•9Õµ‰•È¡Á…å±½…¹Ù…±Õ”¤°(€€€€€€€€€€€€€€€€€€€€€€€Ñ¥µ•ÍÑ…µÀè¹½Éµ…±¥é•‘Q¥µ•ÍÑ…µÀ°(€€€€€€€€€€€€€€€€€€€ôì(€€€€€€€€€€€€€€€ô(€€€€€€€€€€€ô¤ì(€€€€€€€€€€€ÕÁ‘…Ñ•••‘MÑ…ÑÕÌ ¤ì(€€€€€€€ô¤ì((€€€€€€€Í½­•Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±½Í”œ°€ ¤€ôøì(€€€€€€€€€€€Ý¥¹‘½Ü¹±•…É%¹Ñ•ÉÙ…°¡ÍÑ…Ñ”¹Á¥¹Q¥µ•È¤ì(€€€€€€€€€€€ÍÑ…Ñ”¹Á¥¹Q¥µ•È€ô¹Õ±°ì(€€€€€€€€€€€ÕÁ‘…Ñ•••‘MÑ…ÑÕÌ ¤ì(€€€€€€€€€€€Ý¥¹‘½Ü¹±•…ÉQ¥µ•½ÕÐ¡ÍÑ…Ñ”¹É•½¹¹•ÑQ¥µ•È¤ì(€€€€€€€€€€€ÍÑ…Ñ”¹É•½¹¹•ÑQ¥µ•È€ôÝ¥¹‘½Ü¹Í•ÑQ¥µ•½ÕÐ¡½¹¹•ÑAÉ¥•••‘Ì°€ÈÔÀÀ¤ì(€€€€€€€ô¤ì((€€€€€€€Í½­•Ð¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È •ÉÉ½Èœ°€ ¤€ôøì(€€€€€€€€€€€‰å% ™••‘	…‘”œ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô€IQIe%9œì(€€€€€€€€€€€‰å% ™••‘	…‘”œ¤¹±…ÍÍ9…µ”€ô€‰…‘”Ñ•áÐµ‰œµÝ…É¹¥¹œœì(€€€€€€€ô¤ì(€€€ô((€€€™Õ¹Ñ¥½¸ÕÁ‘…Ñ•••‘MÑ…ÑÕÌ ¤ì(€€€€€€€½¹ÍÐ¡…¥¹±¥¹¬€ôÍÑ…Ñ”¹™••‘Ì¹¡…¥¹±¥¹¬ì(€€€€€€€½¹ÍÐ™É•Í €ô¡…¥¹±¥¹¬€˜˜…Ñ”¹¹½Ü ¤€´¡…¥¹±¥¹¬¹Ñ¥µ•ÍÑ…µÀ€ð€ÄÕ|ÀÀÀì(€€€€€€€‰å% ¡…¥¹±¥¹­½Ðœ¤¹±…ÍÍ9…µ”€ôÍÑ…ÑÕÌµ‘½Ð€‘í™É•Í €ü€ÍÑ…ÑÕÌµ½¬œ€è€ÍÑ…ÑÕÌµÝ…É¹¥¹œõ€ì(€€€€€€€‰å% ¡…¥¹±¥¹­MÑ…ÑÕÌœ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô™É•Í (€€€€€€€€€€€€ü€‘í…ÍÍ•ÑAÉ¥”¹™½Éµ…Ð¡¡…¥¹±¥¹¬¹Ù…±Õ”¥ôÉ••¥Ù•€‘í¹•Ü…Ñ”¡¡…¥¹±¥¹¬¹Ñ¥µ•ÍÑ…µÀ¤¹Ñ½1½…±•Q¥µ•MÑÉ¥¹œ ¥õ€(€€€€€€€€€€€€è]…¥Ñ¥¹œ™½È„™É•Í €‘í…ÍÍ•ÑMåµ‰½±ô½UMÕÁ‘…Ñ”¹€ì(€€€€€€€‰å% ™••‘	…‘”œ¤¹Ñ•áÑ½¹Ñ•¹Ð€ô™É•Í €ü€1%Yœ€è€]%Q%9œì(€€€€€€€‰å% ™••‘	…‘”œ¤¹±…ÍÍ9…µ”€ô‰…‘”€‘í™É•Í €ü€Ñ•áÐµ‰œµÍÕ•ÍÌœ€è€Ñ•áÐµ‰œµÝ…É¹¥¹œõ€ì(€€€ô((€€€‰å% É•™É•Í¡	ÕÑÑ½¸œ¤¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±¥¬œ°…Íå¹Œ€ ¤€ôøì(€€€€€€€…Ý…¥Ð±½…‘…Í¡‰½…É ¤ì(€€€€€€€…Ý…¥Ð±½…‘5½‘•° ¤ì(€€€ô¤ì(€€€‰å% ÑÉ…‘•¡…ÉÑ±½Í”œ¤¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±¥¬œ°€ ¤€ôøì(€€€€€€€‰å% ÑÉ…‘•¡…ÉÑ…Éœ¤¹±…ÍÍ1¥ÍÐ¹…‘ µ¹½¹”œ¤ì(€€€€€€€ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐ€ô¹Õ±°ì(€€€€€€€ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÑ•½µ•ÑÉä€ômtì(€€€ô¤ì(€€€‰å% ÑÉ…‘•¡…ÉÑI•™É•Í œ¤¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È ±¥¬œ°€ ¤€ôøì(€€€€€€€¥˜€¡ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐü¹ÑÉ…‘”ü¹¥¤ì(€€€€€€€€€€€±½…‘QÉ…‘•¡…ÉÐ¡ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐ¹ÑÉ…‘”¹¥¤ì(€€€€€€€ô(€€€ô¤ì(€€€‰å% ÑÉ…‘•á¥Ñ¡…ÉÐœ¤¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È Á½¥¹Ñ•Éµ½Ù”œ°€¡•Ù•¹Ð¤€ôøì(€€€€€€€¥˜€¡ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÑ•½µ•ÑÉä¹±•¹Ñ €ôôô€À¤ì(€€€€€€€€€€€É•ÑÕÉ¸ì(€€€€€€€ô(€€€€€€€½¹ÍÐ‰½Õ¹‘Ì€ô•Ù•¹Ð¹ÕÉÉ•¹ÑQ…É•Ð¹•Ñ	½Õ¹‘¥¹±¥•¹ÑI•Ð ¤ì(€€€€€€€½¹ÍÐÁ½¥¹Ñ•É`€ô•Ù•¹Ð¹±¥•¹Ñ`€´‰½Õ¹‘Ì¹±•™Ðì(€€€€€€€½¹ÍÐ¹•…É•ÍÐ€ôÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÑ•½µ•ÑÉä¹É•‘Õ” ¡‰•ÍÐ°…¹‘¥‘…Ñ”¤€ôø(€€€€€€€€€€€5…Ñ ¹…‰Ì¡…¹‘¥‘…Ñ”¹à€´Á½¥¹Ñ•É`¤€ð5…Ñ ¹…‰Ì¡‰•ÍÐ¹à€´Á½¥¹Ñ•É`¤€ü…¹‘¥‘…Ñ”€è‰•ÍÐ¤ì(€€€€€€€Í¡½ÝQÉ…‘•¡…ÉÑA½¥¹Ð¡¹•…É•ÍÐ¹Á½¥¹Ð¤ì(€€€ô¤ì(€€€Ý¥¹‘½Ü¹…‘‘Ù•¹Ñ1¥ÍÑ•¹•È É•Í¥é”œ°€ ¤€ôøì(€€€€€€€¥˜€¡ÍÑ…Ñ”¹ÑÉ…‘•¡…ÉÐ¤ì(€€€€€€€€€€€‘É…ÝQÉ…‘•¡…ÉÐ ¤ì(€€€€€€€ô(€€€ô¤ì((€€€Ý¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°¡É•¹‘•É½Õ¹Ñ‘½Ý¸°€ÄÀÀÀ¤ì(€€€Ý¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°¡ÕÁ‘…Ñ•••‘MÑ…ÑÕÌ°€ÈÀÀÀ¤ì(€€€Ý¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°¡ÍÕ‰µ¥Ñ=‰Í•ÉÙ…Ñ¥½¸°€ÈÀÀÀ¤ì(€€€Ý¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°¡±½…‘…Í¡‰½…É°€ÄÁ|ÀÀÀ¤ì(€€€Ý¥¹‘½Ü¹Í•Ñ%¹Ñ•ÉÙ…°¡±½…‘5½‘•°°€ÌÁ|ÀÀÀ¤ì((€€€É•¹‘•ÉµÁÑåM¥¹…° ]…¥Ñ¥¹œ™½ÈÑ¡”™¥ÉÍÐÕÉÉ•¹Ðµµ…É­•Ð¡…¥¹±¥¹¬½‰Í•ÉÙ…Ñ¥½¸¸œ¤ì(€€€½¹¹•ÑAÉ¥•••‘Ì ¤ì(€€€AÉ½µ¥Í”¹…±°¡m±½…‘…Í¡‰½…É ¤°±½…‘5½‘•° ¥t¤ì)ô¤ ¤ì