/**
 * assets/js/settings.js
 *
 * Drives settings.php. Same house style as app.js: plain DOM, fetch, no
 * framework. Kept separate because it shares nothing with the inbox and
 * loading 1,700 lines of chat logic on a diagnostics page would be silly.
 *
 * Every check is its own request, fired in parallel, so one hung service
 * cannot stop the others from reporting.
 */
(() => {
    'use strict';

    const API_HEALTH = 'api/health.php';

    const el = {
        list: document.getElementById('healthList'),
        summary: document.getElementById('healthSummary'),
        recheckBtn: document.getElementById('recheckBtn'),
        n8nLiveBtn: document.getElementById('n8nLiveBtn'),
        n8nLiveResult: document.getElementById('n8nLiveResult'),
        toastStack: document.getElementById('toastStack'),
    };

    const STATUS_LABEL = { ok: 'OK', warn: 'Check', fail: 'Problem', pending: 'Checking…' };

    function toast(message, variant = 'success') {
        const node = document.createElement('div');
        node.className = `toast toast--${variant}`;
        node.textContent = message;
        el.toastStack.appendChild(node);
        setTimeout(() => node.remove(), 2900);
    }

    async function api(url) {
        const res = await fetch(url, { headers: { 'Content-Type': 'application/json' } });
        let data;
        try {
            data = await res.json();
        } catch {
            throw new Error('Unexpected server response.');
        }
        if (!res.ok || data.success === false) {
            // A 401 here means the session lapsed while the page sat open.
            if (res.status === 401) {
                window.location.href = 'login.php';
                return null;
            }
            throw new Error(data.error || 'Something went wrong.');
        }
        return data;
    }

    /**
     * Builds one row. Text goes in through textContent throughout — some
     * of these strings carry provider error bodies, which are exactly the
     * kind of thing that must never be parsed as markup.
     */
    function buildRow(key, label) {
        const li = document.createElement('li');
        li.className = 'health-item is-pending';
        li.id = `health-${key}`;

        const badge = document.createElement('span');
        badge.className = 'health-item__badge';
        badge.textContent = STATUS_LABEL.pending;

        const body = document.createElement('div');
        body.className = 'health-item__body';

        const title = document.createElement('div');
        title.className = 'health-item__label';
        title.textContent = label;

        const summary = document.createElement('div');
        summary.className = 'health-item__summary';
        summary.textContent = 'Running…';

        body.append(title, summary);
        li.append(badge, body);
        return li;
    }

    /** Repaints a row (or a standalone panel) with a finished result. */
    function applyResult(node, result) {
        node.className = `health-item is-${result.status}`;
        if (node.id === 'n8nLiveResult') node.classList.add('health-item--standalone');
        node.innerHTML = '';

        const badge = document.createElement('span');
        badge.className = 'health-item__badge';
        badge.textContent = STATUS_LABEL[result.status] || result.status;

        const body = document.createElement('div');
        body.className = 'health-item__body';

        const title = document.createElement('div');
        title.className = 'health-item__label';
        title.textContent = result.label;

        const summary = document.createElement('div');
        summary.className = 'health-item__summary';
        summary.textContent = result.summary;

        body.append(title, summary);

        if (Array.isArray(result.detail) && result.detail.length) {
            const details = document.createElement('ul');
            details.className = 'health-item__detail';
            result.detail.forEach((line) => {
                const item = document.createElement('li');
                item.textContent = line;
                details.appendChild(item);
            });
            body.appendChild(details);
        }

        if (result.hint) {
            const hint = document.createElement('div');
            hint.className = 'health-item__hint';
            hint.textContent = result.hint;
            body.appendChild(hint);
        }

        node.append(badge, body);
    }

    function applyError(node, label, message) {
        applyResult(node, {
            label,
            status: 'fail',
            summary: 'The check could not run',
            detail: [message],
            hint: 'This usually means the CRM itself errored. Check the PHP error log.',
        });
    }

    /** One line at the top: how many of each, so the verdict is instant. */
    function renderSummary(results) {
        const counts = { ok: 0, warn: 0, fail: 0 };
        results.forEach((r) => { counts[r.status] = (counts[r.status] || 0) + 1; });

        el.summary.hidden = false;
        el.summary.className = 'health-summary';
        el.summary.textContent = '';

        let text;
        if (counts.fail > 0) {
            el.summary.classList.add('is-fail');
            text = `${counts.fail} problem${counts.fail > 1 ? 's' : ''} found — the CRM will not work correctly until these are fixed.`;
        } else if (counts.warn > 0) {
            el.summary.classList.add('is-warn');
            text = `Everything reachable, but ${counts.warn} item${counts.warn > 1 ? 's need' : ' needs'} a look.`;
        } else {
            el.summary.classList.add('is-ok');
            text = 'All connections healthy.';
        }
        el.summary.textContent = text;
    }

    async function runAll() {
        el.recheckBtn.disabled = true;
        el.summary.hidden = true;
        el.list.textContent = '';

        let checks;
        try {
            const data = await api(API_HEALTH);
            if (!data) return;
            checks = data.checks;
        } catch (err) {
            toast(err.message, 'error');
            el.recheckBtn.disabled = false;
            return;
        }

        // Render every row up front so the page shows its full shape
        // immediately, then let each fill itself in.
        checks.forEach(({ key, label }) => el.list.appendChild(buildRow(key, label)));

        const results = await Promise.all(checks.map(async ({ key, label }) => {
            const node = document.getElementById(`health-${key}`);
            try {
                const data = await api(`${API_HEALTH}?check=${encodeURIComponent(key)}`);
                if (!data) return { status: 'fail' };
                applyResult(node, data.result);
                return data.result;
            } catch (err) {
                applyError(node, label, err.message);
                return { status: 'fail' };
            }
        }));

        renderSummary(results);
        el.recheckBtn.disabled = false;
    }

    el.recheckBtn.addEventListener('click', runAll);

    el.n8nLiveBtn.addEventListener('click', async () => {
        el.n8nLiveBtn.disabled = true;
        el.n8nLiveResult.hidden = false;
        applyResult(el.n8nLiveResult, {
            label: 'Live draft test',
            status: 'pending',
            summary: 'Asking n8n for a draft… this can take up to 45 seconds.',
            detail: [],
            hint: '',
        });

        try {
            const data = await api(`${API_HEALTH}?check=n8n_live`);
            if (data) applyResult(el.n8nLiveResult, data.result);
        } catch (err) {
            applyError(el.n8nLiveResult, 'Live draft test', err.message);
        } finally {
            el.n8nLiveBtn.disabled = false;
        }
    });

    runAll();
})();
