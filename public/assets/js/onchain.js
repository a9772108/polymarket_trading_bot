(() => {
    'use strict';

    const byId = (id) => document.getElementById(id);
    if (!byId('onchain-experiment')) {
        return;
    }

    let requestPending = false;

    function render(experiment) {
        const status = experiment?.status || 'unavailable';
        const statusElement = byId('onchainStatus');
        const statusStyles = {
            observing: 'text-bg-success',
            degraded: 'text-bg-warning',
            unavailable: 'text-bg-danger',
            disabled: 'text-bg-secondary',
        };
        statusElement.className = `badge ${statusStyles[status] || 'text-bg-secondary'}`;
        statusElement.textContent = status.toUpperCase();

        const count = Number(experiment?.mempool?.transaction_count);
        byId('mempoolCount').textContent = Number.isFinite(count) ? count.toLocaleString() : '\u2014';

        const largest = Number(experiment?.recent_transactions?.largest_output_btc);
        byId('largestRecentOutput').textContent = Number.isFinite(largest) ? `${largest.toFixed(2)} BTC` : '\u2014';

        const flow = experiment?.flow_signal || {};
        byId('flowDirection').textContent = flow.available ? String(flow.direction || 'neutral').toUpperCase() : 'Neutral';
        byId('flowAvailability').textContent = flow.available
            ? `Net exchange outflow ${Number(flow.net_outflow_btc_5m || 0).toFixed(2)} BTC / 5m`
            : 'No exchange-flow feed configured';

        const adjustment = Number(flow.suggested_probability_adjustment || 0) * 100;
        byId('flowAdjustment').textContent = `${adjustment >= 0 ? '+' : ''}${adjustment.toFixed(2)}%`;

        const details = [flow.note || 'No directional feed configured.'];
        if (experiment?.snapshot_recorded) {
            details.push('Point-in-time snapshot recorded.');
        }
        if (Array.isArray(experiment?.errors) && experiment.errors.length > 0) {
            details.push(experiment.errors.join(' '));
        }
        details.push(`Updated ${new Date(experiment.generated_at || Date.now()).toLocaleTimeString()}.`);
        byId('onchainDetails').textContent = details.join(' ');
    }

    async function refresh() {
        if (requestPending) {
            return;
        }
        requestPending = true;
        byId('onchainRefresh').disabled = true;

        try {
            const response = await fetch('api/onchain.php', { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || `Observer API returned HTTP ${response.status}`);
            }
            render(data.onchain_experiment || {});
        } catch (error) {
            byId('onchainStatus').className = 'badge text-bg-danger';
            byId('onchainStatus').textContent = 'UNAVAILABLE';
            byId('onchainDetails').textContent = error instanceof Error ? error.message : 'Unable to refresh the on-chain observer.';
        } finally {
            requestPending = false;
            byId('onchainRefresh').disabled = false;
        }
    }

    byId('onchainRefresh').addEventListener('click', refresh);
    window.setInterval(() => {
        if (!document.hidden) {
            refresh();
        }
    }, 30_000);
    refresh();
})();
