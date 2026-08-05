(() => {
    'use strict';
    const byId = (id) => document.getElementById(id);
    const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 2 });
    let sessionId = null;
    let pending = false;
    let historyView = false;
    const signedMoney = (value) => { const amount = Number(value || 0); return `${amount >= 0 ? '+' : '-'}${money.format(Math.abs(amount))}`; };

    async function request(action, extras = {}) {
        const response = await fetch('api/wallet-follow.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, session_id: sessionId, ...extras }),
            cache: 'no-store',
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || `Follower API returned HTTP ${response.status}`);
        return data;
    }

    function render(data) {
        const session = data.session;
        const summary = data.summary || {};
        const positions = Array.isArray(data.positions) ? data.positions : [];
        sessionId = session?.id || null;
        historyView = Boolean(data.history_view);
        if (!session) {
            byId('followStatus').textContent = 'IDLE';
            return;
        }
        const shortWallet = `${session.wallet.slice(0, 6)}\u2026${session.wallet.slice(-4)}`;
        byId('followWallet').value = session.wallet;
        byId('followHeading').textContent = `${shortWallet} · ${session.date}`;
        byId('followDetails').textContent = `Started ${new Date(session.started_at).toLocaleTimeString()} · checks public activity every 10 seconds.`;
        byId('followStatus').className = `badge mt-1 ${session.status === 'ACTIVE' ? 'text-bg-success' : 'text-bg-secondary'}`;
        byId('followStatus').textContent = session.status;
        byId('followSectionLabel').textContent = historyView ? 'Saved wallet day' : 'Current session';
        if (historyView) {
            const startText = new Date(session.started_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            const endText = session.stopped_at ? new Date(session.stopped_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : null;
            const sessions = Number(session.session_count || 1);
            byId('followDetails').textContent = `${startText}${endText ? ` \u2013 ${endText}` : ''} \u00b7 ${sessions} test session${sessions === 1 ? '' : 's'} saved.`;
        }
        byId('followPoll').disabled = session.status !== 'ACTIVE';
        byId('followStop').disabled = session.status !== 'ACTIVE';
        byId('followPositions').textContent = String(summary.positions || 0);
        byId('followOpen').textContent = `${summary.open || 0} open · ${summary.closed || 0} closed`;
        byId('followPnl').textContent = signedMoney(summary.realized_pnl);
        byId('followPnl').className = Number(summary.realized_pnl || 0) >= 0 ? 'text-success' : 'text-danger';
        byId('followWinRate').textContent = summary.win_rate === null ? '\u2014' : `${Number(summary.win_rate).toFixed(1)}%`;
        byId('followWins').textContent = `${summary.wins || 0} wins · ${summary.losses || 0} losses`;
        byId('followStakeDisplay').textContent = money.format(session.stake);

        const body = byId('followBody');
        body.replaceChildren();
        if (!positions.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-secondary text-center py-4">Waiting for the followed wallet\'s next BTC five-minute trade.</td></tr>';
            return;
        }
        positions.forEach((position) => {
            const pnl = position.pnl === null ? null : Number(position.pnl);
            const row = document.createElement('tr');
            const values = [
                new Date(position.observed_at).toLocaleTimeString(),
                position.title,
                position.outcome,
                `${(Number(position.source_price) * 100).toFixed(1)}\u00a2`,
                `${(Number(position.execution_price) * 100).toFixed(1)}\u00a2`,
                money.format(position.stake),
                position.status === 'OPEN'
                    ? 'OPEN'
                    : position.close_reason === 'SETTLED'
                        ? 'SETTLED'
                        : position.close_reason === 'SOURCE_SOLD'
                            ? 'SOURCE SOLD'
                            : position.close_reason === 'DAY_END' ? 'DAY-END EXIT' : 'STOPPED',
                pnl === null ? '\u2014' : signedMoney(pnl),
            ];
            values.forEach((value, index) => {
                const cell = document.createElement('td'); cell.textContent = value;
                if ([3, 4, 5, 7].includes(index)) cell.classList.add('text-end');
                if (index === 7 && pnl !== null) cell.classList.add(pnl >= 0 ? 'text-success' : 'text-danger');
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
    }

    function renderHistory(data) {
        const days = Array.isArray(data.days) ? data.days : [];
        const body = byId('followHistoryBody');
        body.replaceChildren();
        if (!days.length) {
            body.innerHTML = '<tr><td colspan="7" class="text-secondary text-center py-4">Completed paper tests will appear here automatically.</td></tr>';
            return;
        }
        days.forEach((day) => {
            const row = document.createElement('tr');
            const walletButton = document.createElement('button');
            walletButton.type = 'button';
            walletButton.className = 'btn btn-link link-info p-0 font-monospace text-decoration-none';
            walletButton.textContent = `${day.wallet.slice(0, 6)}\u2026${day.wallet.slice(-4)}`;
            walletButton.title = `Open ${day.wallet} on ${day.date}`;
            walletButton.addEventListener('click', () => loadDay(day.wallet, day.date));
            const walletCell = document.createElement('td');
            walletCell.appendChild(walletButton);
            row.appendChild(walletCell);

            const started = new Date(day.started_at);
            const ended = new Date(day.ended_at);
            const durationMinutes = Math.max(0, Math.round((ended - started) / 60000));
            const values = [
                day.date,
                durationMinutes < 60 ? `${durationMinutes} min` : `${Math.floor(durationMinutes / 60)}h ${durationMinutes % 60}m`,
                String(day.positions || 0),
                `${day.wins || 0}W \u00b7 ${day.losses || 0}L`,
                signedMoney(day.realized_pnl),
                day.status,
            ];
            values.forEach((value, index) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                if ([2, 4].includes(index)) cell.classList.add('text-end');
                if (index === 4) cell.classList.add(Number(day.realized_pnl || 0) >= 0 ? 'text-success' : 'text-danger');
                if (index === 5) {
                    const badge = document.createElement('span');
                    badge.className = `badge ${day.status === 'ACTIVE' ? 'text-bg-success' : 'text-bg-secondary'}`;
                    badge.textContent = day.status;
                    cell.textContent = '';
                    cell.appendChild(badge);
                }
                row.appendChild(cell);
            });
            body.appendChild(row);
        });
    }

    async function loadHistory() {
        const response = await fetch('api/wallet-follow.php?view=history', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load saved wallet days.');
        renderHistory(data);
    }

    async function loadDay(wallet, date) {
        if (pending) return;
        pending = true;
        try {
            const query = new URLSearchParams({ view: 'day', wallet, date });
            const response = await fetch(`api/wallet-follow.php?${query}`, { cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load this wallet day.');
            render(data);
            byId('followSectionLabel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) {
            byId('followAlert').innerHTML = '<div class="alert alert-warning" role="alert"></div>';
            byId('followAlert').firstElementChild.textContent = error instanceof Error ? error.message : 'Unable to load this wallet day.';
        } finally { pending = false; }
    }

    async function loadLatest() {
        const response = await fetch('api/wallet-follow.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load the current session.');
        render(data);
    }

    async function perform(action, extras = {}) {
        if (pending) return;
        pending = true;
        try { render(await request(action, extras)); await loadHistory(); byId('followAlert').innerHTML = ''; }
        catch (error) { byId('followAlert').innerHTML = '<div class="alert alert-warning" role="alert"></div>'; byId('followAlert').firstElementChild.textContent = error instanceof Error ? error.message : 'Wallet follower request failed.'; }
        finally { pending = false; }
    }
    byId('followForm').addEventListener('submit', (event) => { event.preventDefault(); perform('start', { wallet: byId('followWallet').value.trim(), stake: byId('followStake').value }); });
    byId('followPoll').addEventListener('click', () => perform('poll'));
    byId('followStop').addEventListener('click', () => perform('stop'));
    byId('followLatest').addEventListener('click', () => loadLatest().catch(() => {}));
    window.setInterval(() => { if (!document.hidden && !historyView && sessionId && !byId('followPoll').disabled) perform('poll'); }, 10_000);
    Promise.all([loadLatest(), loadHistory()]).catch(() => {});
})();
