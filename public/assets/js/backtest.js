(() => {
    'use strict';

    const byId = (id) => document.getElementById(id);
    const money = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        maximumFractionDigits: 2,
    });

    function number(value) {
        const parsed = Number.parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function percent(value, digits = 1) {
        const parsed = number(value);
        return parsed === null ? '\u2014' : `${(parsed * 100).toFixed(digits)}%`;
    }

    function signedPercent(value, digits = 1) {
        const parsed = number(value);
        return parsed === null ? '\u2014' : `${parsed >= 0 ? '+' : ''}${(parsed * 100).toFixed(digits)}%`;
    }

    function signedMoney(value) {
        const parsed = number(value);
        if (parsed === null) {
            return '\u2014';
        }
        return `${parsed >= 0 ? '+' : '-'}${money.format(Math.abs(parsed))}`;
    }

    function showAlert(message, type = 'danger') {
        const area = byId('backtestAlert');
        area.innerHTML = `<div class="alert alert-${type}" role="alert"></div>`;
        area.firstElementChild.textContent = message;
    }

    function clearAlert() {
        byId('backtestAlert').innerHTML = '';
    }

    function render(result) {
        if (!result?.summary) {
            byId('emptyState').classList.remove('d-none');
            byId('resultsArea').classList.add('d-none');
            return;
        }

        byId('emptyState').classList.add('d-none');
        byId('resultsArea').classList.remove('d-none');
        const summary = result.summary;
        const pnl = number(summary.net_pnl) ?? 0;

        byId('resultPnl').textContent = signedMoney(pnl);
        byId('resultPnl').className = `performance-value ${pnl > 0 ? 'text-success' : pnl < 0 ? 'text-danger' : ''}`;
        byId('resultBalance').textContent = `${money.format(summary.starting_balance)} to ${money.format(summary.ending_balance)}`;
        byId('resultRoi').textContent = percent(summary.return_on_staked);
        byId('resultStaked').textContent = `${money.format(summary.total_staked)} total simulated stake`;
        byId('resultWinRate').textContent = percent(summary.win_rate);
        byId('resultWins').textContent = `${summary.wins} won / ${summary.losses} lost / ${summary.trades} trades`;
        byId('resultDrawdown').textContent = money.format(summary.maximum_drawdown);
        byId('resultCoverage').textContent = `${summary.analyzed_markets}/${summary.requested_markets} markets analyzed; ${summary.skipped_no_data} skipped`;
        byId('runTimestamp').textContent = `Run ${result.run_id ?? ''} \u00b7 ${new Date(result.generated_at).toLocaleString()}`;

        const modelBrier = number(summary.model_brier);
        const marketBrier = number(summary.market_brier);
        byId('modelBrier').textContent = modelBrier === null ? '\u2014' : modelBrier.toFixed(4);
        byId('marketBrier').textContent = marketBrier === null ? '\u2014' : marketBrier.toFixed(4);
        byId('modelBrierBar').style.width = `${modelBrier === null ? 0 : Math.min(100, modelBrier * 200)}%`;
        byId('marketBrierBar').style.width = `${marketBrier === null ? 0 : Math.min(100, marketBrier * 200)}%`;

        if (modelBrier !== null && marketBrier !== null) {
            byId('brierVerdict').textContent = modelBrier < marketBrier
                ? 'The baseline probabilities scored better than the historical market proxy in this small window.'
                : 'The historical market proxy scored at least as well as the baseline in this window.';
        } else {
            byId('brierVerdict').textContent = 'Not enough probability observations for comparison.';
        }

        renderEquity(result.equity_curve || []);
        renderMarkets(result.markets || []);
        renderAssumptions(result.assumptions || []);
    }

    function renderEquity(points) {
        const chart = byId('equityChart');
        chart.innerHTML = '';

        if (!Array.isArray(points) || points.length === 0) {
            chart.textContent = 'No simulated trades produced an equity path.';
            chart.classList.add('text-secondary');
            return;
        }

        chart.classList.remove('text-secondary');
        const balances = points.map((point) => number(point.balance)).filter((value) => value !== null);
        const minimum = Math.min(...balances);
        const maximum = Math.max(...balances);
        const range = Math.max(1, maximum - minimum);
        const visiblePoints = points.length > 80
            ? points.filter((_, index) => index % Math.ceil(points.length / 80) === 0 || index === points.length - 1)
            : points;

        visiblePoints.forEach((point) => {
            const balance = number(point.balance) ?? minimum;
            const height = 24 + ((balance - minimum) / range) * 76;
            const column = document.createElement('div');
            column.className = `equity-column ${balance >= balances[0] ? 'equity-positive' : 'equity-negative'}`;
            column.style.height = `${height}%`;
            column.title = `${point.label}: ${money.format(balance)}`;
            chart.appendChild(column);
        });
    }

    function renderMarkets(markets) {
        const body = byId('backtestRows');
        body.innerHTML = '';

        if (!Array.isArray(markets) || markets.length === 0) {
            body.innerHTML = '<tr><td colspan="7" class="text-secondary text-center py-4">No analyzable markets.</td></tr>';
            return;
        }

        [...markets].reverse().forEach((market) => {
            const row = document.createElement('tr');
            const bet = String(market.decision || '').startsWith('BET');
            const pnl = number(market.pnl) ?? 0;
            row.innerHTML = `
                <td class="ended"></td>
                <td class="decision"></td>
                <td class="actual"></td>
                <td class="model-up"></td>
                <td class="market-up"></td>
                <td class="edge"></td>
                <td class="pnl text-end"></td>`;
            row.querySelector('.ended').textContent = new Date(market.ended_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            row.querySelector('.decision').textContent = market.decision;
            row.querySelector('.decision').className = `decision ${bet ? 'fw-semibold text-info' : 'text-secondary'}`;
            row.querySelector('.actual').textContent = market.actual_outcome;
            row.querySelector('.model-up').textContent = percent(market.model_probability_up);
            row.querySelector('.market-up').textContent = percent(market.market_midpoint_up);
            row.querySelector('.edge').textContent = signedPercent(market.net_edge);
            row.querySelector('.pnl').textContent = bet ? signedMoney(pnl) : '\u2014';
            row.querySelector('.pnl').className = `pnl text-end ${pnl > 0 ? 'text-success' : pnl < 0 ? 'text-danger' : ''}`;
            body.appendChild(row);
        });
    }

    function renderAssumptions(assumptions) {
        const list = byId('assumptionList');
        list.innerHTML = '';
        assumptions.forEach((assumption) => {
            const item = document.createElement('li');
            item.textContent = assumption;
            list.appendChild(item);
        });
    }

    async function loadLatest() {
        try {
            const response = await fetch('api/backtest.php', { cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || 'Unable to load the latest backtest.');
            }
            render(result);
        } catch (error) {
            showAlert(error instanceof Error ? error.message : 'Unable to load the latest backtest.');
        }
    }

    byId('backtestForm').addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = byId('runBacktest');
        button.disabled = true;
        button.textContent = 'Downloading and replaying history...';
        byId('runStatus').textContent = 'Collecting completed events, one-second BTC prices, and public trade history. Keep this page open.';
        clearAlert();

        try {
            const response = await fetch('api/backtest.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    market_count: Number.parseInt(byId('marketCount').value, 10),
                    entry_seconds: Number.parseInt(byId('entrySeconds').value, 10),
                    minimum_edge: Number.parseFloat(byId('minimumEdge').value) / 100,
                    position_size: Number.parseFloat(byId('positionSize').value),
                    slippage: Number.parseFloat(byId('slippage').value) / 100,
                    assumed_spread: Number.parseFloat(byId('assumedSpread').value) / 100,
                }),
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                throw new Error(result.error || `Backtest returned HTTP ${response.status}`);
            }
            render(result);
            byId('runStatus').textContent = `Completed run ${result.run_id}. Change one assumption at a time when comparing results.`;
            showAlert('Approximate historical replay completed. Treat the result as a screening test, not proof of executable profit.', 'info');
        } catch (error) {
            showAlert(error instanceof Error ? error.message : 'The historical replay failed.');
            byId('runStatus').textContent = 'The run did not complete. Your previous saved result remains available.';
        } finally {
            button.disabled = false;
            button.textContent = 'Run approximate backtest';
        }
    });

    loadLatest();
})();
