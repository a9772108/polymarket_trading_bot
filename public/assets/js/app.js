(() => {
    'use strict';

    const state = { endDate: null, countdownTimer: null };
    const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

    const byId = (id) => document.getElementById(id);

    function showAlert(message, type = 'danger') {
        byId('alertArea').innerHTML = `<div class="alert alert-${type}" role="alert"></div>`;
        byId('alertArea').firstElementChild.textContent = message;
    }

    function clearAlert() {
        byId('alertArea').innerHTML = '';
    }

    function parseNumber(value) {
        const number = Number.parseFloat(value);
        return Number.isFinite(number) ? number : null;
    }

    function renderCountdown() {
        if (!state.endDate) {
            byId('countdown').textContent = '—';
            return;
        }

        const remaining = state.endDate.getTime() - Date.now();
        if (remaining <= 0) {
            byId('countdown').textContent = 'Ended';
            byId('marketStatus').textContent = 'Closed';
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
            container.innerHTML = '<div class="col-12"><div class="alert alert-secondary mb-0">No CLOB token data was returned for this market.</div></div>';
            return;
        }

        tokens.forEach((token) => {
            const midpoint = parseNumber(token.midpoint);
            const spread = parseNumber(token.spread);
            const probability = midpoint === null ? '—' : `${(midpoint * 100).toFixed(1)}¢`;
            const spreadText = spread === null ? '—' : `${(spread * 100).toFixed(2)}¢`;

            const column = document.createElement('div');
            column.className = 'col-12 col-md-6';
            column.innerHTML = `
                <section class="card outcome-card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2 class="h5 mb-0"></h2>
                            <span class="badge text-bg-primary">Public data</span>
                        </div>
                        <div class="display-5 fw-semibold mt-3 outcome-price"></div>
                        <div class="text-secondary">Estimated midpoint / share price</div>
                        <hr>
                        <div class="d-flex justify-content-between"><span class="text-secondary">Spread</span><strong class="spread"></strong></div>
                    </div>
                </section>`;
            column.querySelector('h2').textContent = token.outcome || 'Outcome';
            column.querySelector('.outcome-price').textContent = probability;
            column.querySelector('.spread').textContent = spreadText;
            container.appendChild(column);
        });
    }

    function applyRiskSettings(risk) {
        byId('positionSize').value = risk.max_position_usd ?? 5;
        byId('minimumEdge').value = risk.minimum_edge ?? 0.05;
        byId('dailyLossLimit').value = risk.daily_loss_limit_usd ?? 10;
    }

    async function loadDashboard() {
        byId('apiStatus').textContent = 'Loading';
        byId('refreshButton').disabled = true;
        clearAlert();

        try {
            const response = await fetch('api/dashboard.php', { cache: 'no-store' });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Dashboard API returned HTTP ${response.status}`);
            }

            byId('apiStatus').textContent = 'Online';
            byId('lastUpdate').textContent = new Date(data.generated_at).toLocaleTimeString();

            const geo = data.geoblock || {};
            byId('geoStatus').textContent = geo.blocked ? 'Live trading blocked' : 'Location check passed';
            byId('geoStatus').className = `h5 mt-2 ${geo.blocked ? 'text-warning' : 'text-success'}`;
            byId('geoDetails').textContent = `${geo.country || 'Unknown'}${geo.region ? ` / ${geo.region}` : ''}. Paper mode remains available.`;

            if (!data.market) {
                byId('marketQuestion').textContent = 'No active BTC five-minute market found';
                byId('marketDates').textContent = 'The scanner will try again when refreshed.';
                byId('liquidity').textContent = '—';
                byId('volume').textContent = '—';
                byId('marketStatus').textContent = 'Waiting';
                renderOutcomes([]);
            } else {
                const market = data.market;
                byId('marketQuestion').textContent = market.question || 'Unnamed market';
                const start = market.start_date ? new Date(market.start_date).toLocaleString() : 'unknown';
                const end = market.end_date ? new Date(market.end_date).toLocaleString() : 'unknown';
                byId('marketDates').textContent = `${start} → ${end}`;
                byId('liquidity').textContent = parseNumber(market.liquidity) === null ? '—' : money.format(parseNumber(market.liquidity));
                byId('volume').textContent = parseNumber(market.volume) === null ? '—' : money.format(parseNumber(market.volume));
                state.endDate = market.end_date ? new Date(market.end_date) : null;
                renderCountdown();
                renderOutcomes(market.tokens);
            }

            applyRiskSettings(data.risk || {});
        } catch (error) {
            byId('apiStatus').textContent = 'Error';
            showAlert(error instanceof Error ? error.message : 'Unable to load dashboard data.');
        } finally {
            byId('refreshButton').disabled = false;
        }
    }

    byId('refreshButton').addEventListener('click', loadDashboard);
    state.countdownTimer = window.setInterval(renderCountdown, 1000);
    loadDashboard();
})();
