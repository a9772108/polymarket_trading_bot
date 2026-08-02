(() => {
    'use strict';

    const state = {
        dashboard: null,
        model: null,
        endDate: null,
        chainlink: null,
        binance: null,
        socket: null,
        reconnectTimer: null,
        pingTimer: null,
        dashboardPending: false,
        observationPending: false,
        accountPending: false,
    };

    const byId = (id) => document.getElementById(id);
    const money = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    });
    const btcPrice = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function number(value) {
        const parsed = Number.parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function cents(value, digits = 1) {
        const parsed = number(value);
        return parsed === null ? '\u2014' : `${(parsed * 100).toFixed(digits)}\u00a2`;
    }

    function percent(value, digits = 1, signed = false) {
        const parsed = number(value);
        if (parsed === null) {
            return '\u2014';
        }
        const prefix = signed && parsed >= 0 ? '+' : '';
        return `${prefix}${(parsed * 100).toFixed(digits)}%`;
    }

    function showAlert(message) {
        byId('liveAlert').innerHTML = '<div class="alert alert-danger" role="alert"></div>';
        byId('liveAlert').firstElementChild.textContent = message;
    }

    function clearAlert() {
        byId('liveAlert').innerHTML = '';
    }

    function renderPositions(positions) {
        const body = byId('realPositionsBody');
        body.innerHTML = '';

        if (!Array.isArray(positions) || positions.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="text-secondary text-center py-3">No open international Polymarket positions.</td></tr>';
            byId('realPositionsSummary').textContent = 'No open positions returned by the read-only account API.';
            return;
        }

        byId('realPositionsSummary').textContent = `${positions.length} public position${positions.length === 1 ? '' : 's'} returned by international Polymarket.`;
        positions.slice(0, 20).forEach((position) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class="position-market"></td>
                <td class="position-side"></td>
                <td class="position-value text-end"></td>
                <td class="position-realized text-end"></td>`;
            row.querySelector('.position-market').textContent = position.market || 'Unnamed market';
            row.querySelector('.position-side').textContent = `${position.outcome || '\u2014'}${number(position.size) === null ? '' : ` \u00b7 ${number(position.size).toFixed(2)} shares`}`;
            row.querySelector('.position-value').textContent = number(position.current_value) === null
                ? '\u2014'
                : money.format(number(position.current_value));
            const pnl = number(position.cash_pnl);
            row.querySelector('.position-realized').textContent = pnl === null
                ? '\u2014'
                : `${pnl >= 0 ? '+' : '-'}${money.format(Math.abs(pnl))}`;
            row.querySelector('.position-realized').className = `position-realized text-end ${pnl > 0 ? 'text-success' : pnl < 0 ? 'text-danger' : ''}`;
            body.appendChild(row);
        });
    }

    function renderInternationalAccount(data) {
        const balanceCard = byId('realBalanceCard');
        if (!data.configured) {
            byId('realAccountBalance').textContent = 'Setup required';
            byId('realAccountBalanceDetail').textContent = 'Add your public Profile Address to the local configuration';
            byId('realPositionValue').textContent = '\u2014';
            byId('liveAccountDot').className = 'status-dot status-warning';
            byId('liveAccountText').textContent = 'Public profile address not configured';
            byId('realPositionsSummary').textContent = 'Read-only setup is required before positions can be displayed.';
            byId('realPositionsBody').innerHTML = '<tr><td colspan="4" class="text-secondary text-center py-3">Add the public 0x Profile Address to connect. No secret is required.</td></tr>';
            balanceCard.className = 'real-balance-unavailable';
            return;
        }

        if (!data.available) {
            byId('realAccountBalance').textContent = 'Unavailable';
            byId('realAccountBalanceDetail').textContent = data.message || 'Public portfolio data is not available';
            byId('realPositionValue').textContent = '\u2014';
            byId('liveAccountDot').className = 'status-dot status-warning';
            byId('liveAccountText').textContent = data.message || 'Read-only connection unavailable';
            byId('realPositionsSummary').textContent = 'The public international portfolio could not be loaded.';
            balanceCard.className = 'real-balance-unavailable';
            return;
        }

        const positionValue = number(data.portfolio?.position_value);
        const walletBalance = number(data.portfolio?.wallet_balance);
        const walletBalanceAvailable = data.portfolio?.wallet_balance_available !== false;
        const address = String(data.portfolio?.profile_address || '');
        byId('realAccountBalance').textContent = walletBalance === null ? '\u2014' : money.format(walletBalance);
        byId('realAccountBalanceDetail').textContent = walletBalanceAvailable
            ? `${address.slice(0, 6)}\u2026${address.slice(-4)} \u00b7 pUSD on Polygon`
            : 'Polygon balance service temporarily unavailable';
        byId('realPositionValue').textContent = positionValue === null ? '\u2014' : money.format(positionValue);
        byId('liveAccountDot').className = 'status-dot status-ok';
        byId('liveAccountText').textContent = 'Public portfolio connected; execution remains disabled';
        balanceCard.className = 'real-balance-connected';
        renderPositions(data.portfolio?.positions || []);
    }

    function findToken(outcome) {
        const tokens = state.dashboard?.market?.tokens;
        if (!Array.isArray(tokens)) {
            return null;
        }
        return tokens.find((token) => String(token.outcome || '').toLowerCase() === outcome.toLowerCase()) || null;
    }

    function renderCountdown() {
        if (!state.endDate) {
            byId('liveCountdown').textContent = '\u2014';
            return;
        }
        const remaining = state.endDate.getTime() - Date.now();
        if (remaining <= 0) {
            byId('liveCountdown').textContent = 'Closing';
            return;
        }
        const seconds = Math.floor(remaining / 1000);
        byId('liveCountdown').textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
    }

    function renderDashboard(data) {
        state.dashboard = data;
        byId('marketDataDot').className = 'status-dot status-ok';
        byId('marketDataText').textContent = 'Online and read-only';
        byId('liveLastMarketUpdate').textContent = new Date(data.generated_at).toLocaleTimeString();

        const market = data.market;
        if (!market) {
            state.endDate = null;
            byId('liveMarketQuestion').textContent = 'No active BTC five-minute market';
            byId('liveMarketDates').textContent = 'The public scanner will retry automatically.';
            byId('liveLiquidity').textContent = '\u2014';
            byId('liveUpAsk').textContent = '\u2014';
            byId('liveDownAsk').textContent = '\u2014';
            return;
        }

        state.endDate = market.end_date ? new Date(market.end_date) : null;
        byId('liveMarketQuestion').textContent = market.question || 'Unnamed market';
        const start = market.interval_start ? new Date(market.interval_start).toLocaleTimeString() : 'unknown';
        const end = market.end_date ? new Date(market.end_date).toLocaleTimeString() : 'unknown';
        byId('liveMarketDates').textContent = `Observed interval ${start} to ${end}`;
        byId('liveLiquidity').textContent = number(market.liquidity) === null ? '\u2014' : money.format(number(market.liquidity));
        byId('liveUpAsk').textContent = cents(findToken('Up')?.best_ask);
        byId('liveDownAsk').textContent = cents(findToken('Down')?.best_ask);
        renderCountdown();
    }

    function decisionBadge(decision) {
        if (decision === 'PAPER BET') {
            return ['PAPER QUALIFIED', 'text-bg-success'];
        }
        if (decision === 'WATCH') {
            return ['WATCHING', 'text-bg-info'];
        }
        if (decision === 'SKIP' || decision === 'RISK STOP') {
            return [decision, 'text-bg-danger'];
        }
        return [decision || 'WAITING', 'text-bg-secondary'];
    }

    function clearProposal(reason) {
        byId('proposalHeading').textContent = 'No hypothetical order';
        byId('proposalReason').textContent = reason;
        byId('proposalBadge').textContent = 'NO PROPOSAL';
        byId('proposalBadge').className = 'badge text-bg-secondary proposal-badge';
        ['proposalSide', 'proposalAsk', 'proposalProbability', 'proposalEdge', 'proposalStake',
            'proposalShares', 'proposalLoss', 'proposalProfit'].forEach((id) => {
            byId(id).textContent = '\u2014';
        });
    }

    function renderModel(data) {
        state.model = data;
        const settings = data.settings || {};
        const signal = data.signal;
        const performance = data.performance || {};
        const metrics = data.metrics || {};

        const balance = number(performance.balance);
        const realizedPnl = number(performance.realized_pnl);
        const exposure = number(performance.open_exposure);
        byId('livePaperBalance').textContent = balance === null ? '\u2014' : money.format(balance);
        byId('livePaperPnl').textContent = realizedPnl === null
            ? '\u2014'
            : `${realizedPnl >= 0 ? '+' : '-'}${money.format(Math.abs(realizedPnl))}`;
        byId('livePaperPnl').className = realizedPnl > 0 ? 'text-success' : realizedPnl < 0 ? 'text-danger' : '';
        byId('liveWinLoss').textContent = `${performance.wins ?? 0} won / ${performance.losses ?? 0} lost`;
        byId('livePaperExposure').textContent = exposure === null ? '\u2014' : money.format(exposure);
        byId('liveOpenTrades').textContent = `${performance.open_trades ?? 0} open paper trade${Number(performance.open_trades) === 1 ? '' : 's'}`;
        byId('liveFlowEvidence').textContent = String(metrics.flow_resolved_markets ?? 0);
        byId('liveFlowMatch').textContent = number(metrics.flow_direction_match) === null
            ? 'Starts after newly recorded markets resolve'
            : `${percent(metrics.flow_direction_match)} directional match at ~60s`;

        byId('gateMinimumEdge').textContent = percent(settings.minimum_edge);
        byId('gateMaximumSpread').textContent = cents(settings.maximum_spread, 2);
        byId('gateSlippage').textContent = cents(settings.slippage, 2);
        byId('gateLossLimit').textContent = money.format(number(settings.daily_loss_limit) ?? 0);

        if (!signal || (state.dashboard?.market?.id && String(signal.market_id) !== String(state.dashboard.market.id))) {
            byId('modelReadyDot').className = 'status-dot status-warning';
            byId('modelReadyText').textContent = 'Waiting for this market';
            byId('gateDecision').textContent = 'WAIT';
            byId('liveFlowVolume').textContent = '\u2014';
            byId('liveFlowChange').textContent = '\u2014';
            byId('liveFlowRate').textContent = 'Waiting for history';
            byId('liveFlowImbalance').textContent = '\u2014';
            byId('liveFlowLabel').textContent = 'Waiting for book sizes';
            byId('liveFlowMarker').style.left = '50%';
            clearProposal('Waiting for the first current-market observation.');
            return;
        }

        const decision = signal.decision || 'WARMING UP';
        const [badgeText, badgeClass] = decisionBadge(decision);
        const side = signal.recommended_outcome;
        byId('modelReadyDot').className = `status-dot ${decision === 'WARMING UP' ? 'status-warning' : 'status-ok'}`;
        byId('modelReadyText').textContent = `${decision}: ${signal.reason || 'Signal available'}`;
        byId('gateDecision').textContent = decision;
        byId('liveLastModelTick').textContent = new Date(signal.observed_at).toLocaleTimeString();
        byId('liveStatusSummary').textContent = signal.reason || 'The paper model is monitoring the current market.';

        const marketVolume = number(signal.market_volume);
        const volumeChange = number(signal.volume_change_30s);
        const volumeRate = number(signal.volume_per_second);
        const imbalance = number(signal.order_book_imbalance_up);
        byId('liveFlowVolume').textContent = marketVolume === null ? 'Unavailable' : money.format(marketVolume);
        byId('liveFlowChange').textContent = volumeChange === null ? '\u2014' : `+${money.format(volumeChange)}`;
        byId('liveFlowRate').textContent = volumeRate === null
            ? (marketVolume === null ? 'Volume feed unavailable' : 'Collecting 30 seconds of history')
            : `${money.format(volumeRate)} per second`;
        byId('liveFlowImbalance').textContent = imbalance === null ? '\u2014' : percent(imbalance, 1, true);
        byId('liveFlowLabel').textContent = imbalance === null
            ? 'Waiting for book sizes'
            : imbalance > 0.05
                ? 'More size waiting to buy Up'
                : imbalance < -0.05
                    ? 'More size waiting to sell Up'
                    : 'Top of book is approximately balanced';
        byId('liveFlowMarker').style.left = `${imbalance === null ? 50 : Math.max(1, Math.min(99, (imbalance + 1) * 50))}%`;

        if (!side) {
            clearProposal(signal.reason || 'The model has not selected a side.');
            return;
        }

        const isUp = String(side).toLowerCase() === 'up';
        const ask = number(isUp ? signal.up_ask : signal.down_ask);
        const probability = number(isUp ? signal.probability_up : signal.probability_down);
        const edge = number(signal.recommended_edge);
        const stake = number(settings.position_size) ?? 0;
        const slippage = number(settings.slippage) ?? 0;
        const feeRate = number(state.dashboard?.market?.fee_rate) ?? 0.07;
        const feePerShare = ask === null ? null : feeRate * ask * (1 - ask);
        const effectiveCost = ask === null || feePerShare === null ? null : ask + feePerShare + slippage;
        const shares = effectiveCost && effectiveCost > 0 && effectiveCost < 1 ? stake / effectiveCost : null;
        const maximumProfit = shares === null ? null : shares - stake;

        byId('proposalHeading').textContent = `${side} order preview`;
        byId('proposalReason').textContent = signal.reason || 'Hypothetical order prepared from the current paper signal.';
        byId('proposalBadge').textContent = badgeText;
        byId('proposalBadge').className = `badge ${badgeClass} proposal-badge`;
        byId('proposalSide').textContent = side;
        byId('proposalAsk').textContent = cents(ask);
        byId('proposalProbability').textContent = percent(probability);
        byId('proposalEdge').textContent = percent(edge, 1, true);
        byId('proposalEdge').className = edge !== null && edge >= (number(settings.minimum_edge) ?? 0) ? 'text-success' : 'text-secondary';
        byId('proposalStake').textContent = money.format(stake);
        byId('proposalShares').textContent = shares === null ? '\u2014' : shares.toFixed(2);
        byId('proposalLoss').textContent = stake > 0 ? `-${money.format(stake)}` : '\u2014';
        byId('proposalProfit').textContent = maximumProfit === null ? '\u2014' : `+${money.format(maximumProfit)}`;
        byId('proposalProfit').className = maximumProfit !== null && maximumProfit > 0 ? 'text-success' : '';
    }

    async function loadDashboard() {
        if (state.dashboardPending) {
            return;
        }
        state.dashboardPending = true;
        byId('liveRefreshButton').disabled = true;
        try {
            const response = await fetch('api/dashboard.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Market data returned HTTP ${response.status}`);
            }
            renderDashboard(data);
            clearAlert();
        } catch (error) {
            byId('marketDataDot').className = 'status-dot status-error';
            byId('marketDataText').textContent = 'Unavailable';
            showAlert(error instanceof Error ? error.message : 'Unable to load public market data.');
        } finally {
            state.dashboardPending = false;
            byId('liveRefreshButton').disabled = false;
        }
    }

    async function loadModel() {
        try {
            const response = await fetch('api/model.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Model data returned HTTP ${response.status}`);
            }
            renderModel(data);
        } catch (error) {
            byId('modelReadyDot').className = 'status-dot status-error';
            byId('modelReadyText').textContent = error instanceof Error ? error.message : 'Model unavailable';
        }
    }

    async function loadInternationalAccount() {
        if (state.accountPending) {
            return;
        }
        state.accountPending = true;
        try {
            const response = await fetch('api/polymarket-account.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || `International portfolio returned HTTP ${response.status}`);
            }
            renderInternationalAccount(data);
        } catch (error) {
            byId('realAccountBalance').textContent = 'Connection error';
            byId('realAccountBalanceDetail').textContent = 'No account data was exposed';
            byId('realPositionValue').textContent = '\u2014';
            byId('liveAccountDot').className = 'status-dot status-error';
            byId('liveAccountText').textContent = error instanceof Error ? error.message : 'Read-only account unavailable';
            byId('realPositionsSummary').textContent = 'Unable to load private account data.';
        } finally {
            state.accountPending = false;
        }
    }

    async function submitObservation() {
        if (state.observationPending || !state.dashboard?.market || !state.chainlink) {
            return;
        }
        if (Date.now() - state.chainlink.timestamp > 15_000) {
            updateFeedStatus();
            return;
        }

        state.observationPending = true;
        try {
            const response = await fetch('api/model.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    market: state.dashboard.market,
                    feeds: {
                        chainlink_price: state.chainlink.value,
                        chainlink_timestamp: state.chainlink.timestamp,
                        binance_price: state.binance?.value ?? null,
                        binance_timestamp: state.binance?.timestamp ?? null,
                    },
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Observation returned HTTP ${response.status}`);
            }
            renderModel(data);
        } catch (error) {
            byId('modelReadyDot').className = 'status-dot status-error';
            byId('modelReadyText').textContent = error instanceof Error ? error.message : 'Unable to update the model';
        } finally {
            state.observationPending = false;
        }
    }

    function updateFeedStatus() {
        const fresh = state.chainlink && Date.now() - state.chainlink.timestamp < 15_000;
        byId('resolutionFeedDot').className = `status-dot ${fresh ? 'status-ok' : 'status-warning'}`;
        byId('resolutionFeedText').textContent = fresh
            ? `${btcPrice.format(state.chainlink.value)} at ${new Date(state.chainlink.timestamp).toLocaleTimeString()}`
            : 'Waiting for a fresh price';
        byId('liveFeedBadge').textContent = fresh ? 'FEED LIVE' : 'FEED WAITING';
        byId('liveFeedBadge').className = `badge ${fresh ? 'text-bg-success' : 'text-bg-warning'}`;
    }

    function connectFeeds() {
        if (state.socket && [WebSocket.OPEN, WebSocket.CONNECTING].includes(state.socket.readyState)) {
            return;
        }

        const socket = new WebSocket('wss://ws-live-data.polymarket.com');
        state.socket = socket;
        socket.addEventListener('open', () => {
            socket.send(JSON.stringify({
                action: 'subscribe',
                subscriptions: [
                    { topic: 'crypto_prices_chainlink', type: '*', filters: '{"symbol":"btc/usd"}' },
                    { topic: 'crypto_prices', type: 'update', filters: 'btcusdt' },
                ],
            }));
            window.clearInterval(state.pingTimer);
            state.pingTimer = window.setInterval(() => {
                if (socket.readyState === WebSocket.OPEN) {
                    socket.send('PING');
                }
            }, 5000);
        });

        socket.addEventListener('message', (event) => {
            if (event.data === 'PONG') {
                return;
            }
            let messages;
            try {
                const parsed = JSON.parse(event.data);
                messages = Array.isArray(parsed) ? parsed : [parsed];
            } catch {
                return;
            }
            messages.forEach((item) => {
                const payload = item?.payload;
                const value = number(payload?.value);
                if (value === null) {
                    return;
                }
                const rawTimestamp = number(payload.timestamp) ?? number(item.timestamp) ?? Date.now();
                const timestamp = rawTimestamp < 10_000_000_000 ? rawTimestamp * 1000 : rawTimestamp;
                if (item.topic === 'crypto_prices_chainlink' && String(payload.symbol).toLowerCase() === 'btc/usd') {
                    state.chainlink = { value, timestamp };
                } else if (item.topic === 'crypto_prices' && String(payload.symbol).toLowerCase() === 'btcusdt') {
                    state.binance = { value, timestamp };
                }
            });
            updateFeedStatus();
        });

        socket.addEventListener('close', () => {
            window.clearInterval(state.pingTimer);
            state.pingTimer = null;
            updateFeedStatus();
            window.clearTimeout(state.reconnectTimer);
            state.reconnectTimer = window.setTimeout(connectFeeds, 2500);
        });

        socket.addEventListener('error', () => {
            byId('liveFeedBadge').textContent = 'FEED RETRYING';
            byId('liveFeedBadge').className = 'badge text-bg-warning';
        });
    }

    byId('liveRefreshButton').addEventListener('click', async () => {
        await loadDashboard();
        await loadModel();
        await loadInternationalAccount();
    });

    window.setInterval(renderCountdown, 1000);
    window.setInterval(updateFeedStatus, 2000);
    window.setInterval(submitObservation, 2000);
    window.setInterval(loadDashboard, 10_000);
    window.setInterval(loadModel, 30_000);
    window.setInterval(loadInternationalAccount, 30_000);

    clearProposal('Waiting for the local model and public market data.');
    connectFeeds();
    Promise.all([loadDashboard(), loadModel(), loadInternationalAccount()]);
})();
