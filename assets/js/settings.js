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
    const API_SETTINGS = 'api/settings.php';
    const API_CATALOG = 'api/catalog.php';
    const API_TRANSCRIBE = 'api/transcribe.php';
    const API_BLOCKS = 'api/context_blocks.php';

    const el = {
        list: document.getElementById('healthList'),
        summary: document.getElementById('healthSummary'),
        recheckBtn: document.getElementById('recheckBtn'),
        aiLiveBtn: document.getElementById('aiLiveBtn'),
        aiLiveResult: document.getElementById('aiLiveResult'),
        toastStack: document.getElementById('toastStack'),
        // AI settings form
        aiForm: document.getElementById('aiForm'),
        autoForm: document.getElementById('autoForm'),
        autoEnabled: document.getElementById('autoEnabled'),
        autoMax: document.getElementById('autoMax'),
        autoQuiet: document.getElementById('autoQuiet'),
        autoSaveBtn: document.getElementById('autoSaveBtn'),
        automationScope: document.getElementById('automationScope'),
        automationLastRun: document.getElementById('automationLastRun'),
        aiModel: document.getElementById('aiModel'),
        aiModelList: document.getElementById('aiModelList'),
        aiModelHelp: document.getElementById('aiModelHelp'),
        aiTranscribeModel: document.getElementById('aiTranscribeModel'),
        transcribeBtn: document.getElementById('transcribeBtn'),
        transcribeNote: document.getElementById('transcribeNote'),
        transcribeResult: document.getElementById('transcribeResult'),
        aiSaveBtn: document.getElementById('aiSaveBtn'),
        // Context blocks
        blockList: document.getElementById('blockList'),
        blockAddBtn: document.getElementById('blockAddBtn'),
        blockCount: document.getElementById('blockCount'),
        // Catalog
        catalogInfo: document.getElementById('catalogInfo'),
        catalogInput: document.getElementById('catalogInput'),
        catalogPickBtn: document.getElementById('catalogPickBtn'),
        catalogViewLink: document.getElementById('catalogViewLink'),
        catalogRemoveBtn: document.getElementById('catalogRemoveBtn'),
    };

    /** Filled from the server so "Restore default" matches PHP exactly. */
    let defaults = {};

    const STATUS_LABEL = { ok: 'OK', warn: 'Check', fail: 'Problem', pending: 'Checking…' };

    function toast(message, variant = 'success') {
        const node = document.createElement('div');
        node.className = `toast toast--${variant}`;
        node.textContent = message;
        el.toastStack.appendChild(node);
        setTimeout(() => node.remove(), 2900);
    }

    async function api(url, options = {}) {
        const res = await fetch(url, { headers: { 'Content-Type': 'application/json' }, ...options });
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

    el.aiLiveBtn.addEventListener('click', async () => {
        el.aiLiveBtn.disabled = true;
        el.aiLiveResult.hidden = false;
        applyResult(el.aiLiveResult, {
            label: 'Live draft test',
            status: 'pending',
            summary: 'Asking OpenAI for a draft…',
            detail: [],
            hint: '',
        });

        try {
            const data = await api(`${API_HEALTH}?check=ai_live`);
            if (data) applyResult(el.aiLiveResult, data.result);
        } catch (err) {
            applyError(el.aiLiveResult, 'Live draft test', err.message);
        } finally {
            el.aiLiveBtn.disabled = false;
        }
    });

    // ------------------------------------------------------------------
    // AI settings form
    // ------------------------------------------------------------------

    async function loadSettings() {
        try {
            const data = await api(API_SETTINGS);
            if (!data) return;

            defaults = data.defaults || {};
            el.aiModel.value = data.settings.ai_model || '';
            el.aiTranscribeModel.value = data.settings.ai_transcribe_model || '';
            paintAutomation(data.settings);

            // Autocomplete from the account's real model list. Free text
            // still works, so an empty list only costs the suggestions.
            el.aiModelList.textContent = '';
            (data.models || []).forEach((id) => {
                const option = document.createElement('option');
                option.value = id;
                el.aiModelList.appendChild(option);
            });

            if (!data.models || data.models.length === 0) {
                el.aiModelHelp.textContent =
                    'Could not list models from OpenAI — check the API key below. '
                    + 'You can still type a model id by hand.';
            }
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    el.aiForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        el.aiSaveBtn.disabled = true;
        el.aiSaveBtn.textContent = 'Saving…';

        try {
            const data = await api(API_SETTINGS, {
                method: 'PUT',
                body: JSON.stringify({
                    ai_model: el.aiModel.value.trim(),
                    ai_transcribe_model: el.aiTranscribeModel.value.trim(),
                }),
            });

            if (data) {
                // Show what was actually stored, so saving a blank prompt
                // visibly comes back as the restored default.
                el.aiModel.value = data.settings.ai_model || '';
                el.aiTranscribeModel.value = data.settings.ai_transcribe_model || '';
                toast('AI settings saved.');
            }
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.aiSaveBtn.disabled = false;
            el.aiSaveBtn.textContent = 'Save';
        }
    });

    // ------------------------------------------------------------------
    // Automation
    // ------------------------------------------------------------------

    function paintAutomation(settings) {
        el.autoEnabled.checked = settings.auto_reply_enabled === '1';
        el.autoMax.value = settings.auto_reply_max || '2';
        el.autoQuiet.value = settings.auto_reply_quiet_seconds || '120';
        paintScope(settings.auto_reply_since, settings.auto_reply_enabled === '1');
        paintLastRun(settings.automation_last_run, settings.automation_last_result);
    }

    /**
     * Which conversations are in scope.
     *
     * Switching automation on draws a line under everything before it, so
     * it answers chats that start from then on rather than every customer
     * already waiting. Said out loud, because "why did it not reply to
     * that one?" is otherwise an unanswerable question.
     */
    function paintScope(since, enabled) {
        const box = el.automationScope;
        if (!enabled) {
            box.textContent = 'Switching this on will answer only conversations that start after that moment — never the ones already waiting.';
            return;
        }
        if (!since) {
            box.textContent = 'Answering every new chat in the 24-hour window, including ones that started before this was switched on.';
            return;
        }
        const when = new Date(since);
        box.textContent = Number.isNaN(when.getTime())
            ? `Answering conversations that started after ${since}.`
            : `Answering only conversations that started after ${when.toLocaleString()} — when this was switched on.`;
    }

    /**
     * Whether the schedule is actually running.
     *
     * The single most useful thing this page can say. Switching
     * automation on does nothing at all unless a cron job is calling
     * cron/run.php, and those are two completely separate places to get
     * wrong -- so "on" is never reported as working on its own.
     */
    function paintLastRun(lastRun, lastResult) {
        const box = el.automationLastRun;
        box.classList.remove('is-ok', 'is-warn');

        if (!lastRun) {
            box.classList.add('is-warn');
            box.textContent = 'Never run. The cron job is not set up yet, so nothing will be answered.';
            return;
        }

        const when = new Date(lastRun);
        if (Number.isNaN(when.getTime())) {
            box.textContent = `Last run: ${lastRun}`;
            return;
        }

        const minutes = Math.floor((Date.now() - when.getTime()) / 60000);
        const ago = minutes < 1 ? 'less than a minute ago'
            : minutes === 1 ? '1 minute ago'
            : minutes < 90 ? `${minutes} minutes ago`
            : `${Math.round(minutes / 60)} hours ago`;

        // A schedule that should tick every 3 minutes and last ran 20
        // minutes ago is broken, whatever the switch says.
        box.classList.add(minutes > 15 ? 'is-warn' : 'is-ok');
        box.textContent = `Last run ${ago}${lastResult ? ` — ${lastResult}` : ''}.`;
    }

    el.autoForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        el.autoSaveBtn.disabled = true;
        el.autoSaveBtn.textContent = 'Saving…';

        try {
            const data = await api(API_SETTINGS, {
                method: 'PUT',
                body: JSON.stringify({
                    auto_reply_enabled: el.autoEnabled.checked ? '1' : '0',
                    auto_reply_max: String(Math.max(0, parseInt(el.autoMax.value, 10) || 0)),
                    auto_reply_quiet_seconds: String(Math.max(0, parseInt(el.autoQuiet.value, 10) || 0)),
                }),
            });

            if (data) {
                // Repainted from what was stored, so a value the server
                // clamped or rejected is visible rather than assumed.
                paintAutomation(data.settings);
                toast(el.autoEnabled.checked
                    ? 'Automation saved and switched on.'
                    : 'Automation saved and switched off.');
            }
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.autoSaveBtn.disabled = false;
            el.autoSaveBtn.textContent = 'Save';
        }
    });

    // ------------------------------------------------------------------
    // Context blocks
    //
    // What the AI knows, in named pieces. Replaces the single prompt box:
    // one long field meant editing the brand paragraph put the shipping
    // paragraph at risk, and there was no way to switch the product list
    // off for an afternoon without cutting it somewhere safe first.
    //
    // Title and body save when the field loses focus and something
    // actually changed. Toggling, moving and deleting apply at once --
    // those are single clicks with nothing to lose by committing them.
    // ------------------------------------------------------------------

    let blockLimits = { max: 40, title: 80, body: 20000 };

    async function loadBlocks() {
        try {
            const data = await api(API_BLOCKS);
            if (data) paintBlocks(data);
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function paintBlocks(data) {
        blockLimits = data.limits || blockLimits;
        const blocks = data.blocks || [];

        el.blockList.textContent = '';

        if (blocks.length === 0) {
            el.blockList.appendChild(buildBlocksEmptyState());
        } else {
            blocks.forEach((block, i) => {
                el.blockList.appendChild(buildBlockCard(block, i, blocks.length));
            });
        }

        if (data.using_fallback && blocks.length > 0) {
            el.blockList.appendChild(buildBlocksWarning(
                'Nothing here is switched on, so the AI is running on the built-in '
                + 'prompt. Turn a block on, or add one.',
            ));
        }

        el.blockCount.textContent = blocks.length === 0
            ? ''
            : `${blocks.length} of ${blockLimits.max} · ${data.composed_length.toLocaleString()} characters sent to the model`;
        el.blockAddBtn.disabled = blocks.length >= blockLimits.max;
    }

    function buildBlocksWarning(text) {
        const node = document.createElement('p');
        node.className = 'blocks__warning';
        node.textContent = text;
        return node;
    }

    /**
     * What a fresh install sees.
     *
     * The built-in prompt lives in PHP, not in the database, so there is
     * nothing for the migration to have carried over here. Offering it as
     * a starting block is better than seeding one behind their back --
     * which would also grow back every time they deleted it.
     */
    function buildBlocksEmptyState() {
        const wrap = document.createElement('div');
        wrap.className = 'blocks__empty';

        const line = document.createElement('p');
        line.textContent = 'No blocks yet, so the AI is running on the built-in prompt.';
        wrap.appendChild(line);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn--ghost';
        btn.textContent = 'Start from the built-in prompt';
        btn.addEventListener('click', () => {
            createBlock('General', defaults.ai_system_prompt || '');
        });
        wrap.appendChild(btn);

        return wrap;
    }

    function buildBlockCard(block, index, total) {
        const card = document.createElement('article');
        card.className = 'block' + (block.enabled ? '' : ' is-off');
        card.dataset.id = String(block.id);

        const head = document.createElement('header');
        head.className = 'block__head';

        const title = document.createElement('input');
        title.type = 'text';
        title.className = 'block__title';
        title.value = block.title;
        title.maxLength = blockLimits.title;
        title.placeholder = 'Brand, Products, Shipping…';
        title.addEventListener('blur', () => {
            if (title.value.trim() === block.title) return;
            saveBlock(block.id, { title: title.value }, card);
        });
        head.appendChild(title);

        // The page's own switch, not a bare checkbox: a global
        // `input { appearance: none }` leaves one of those rendering as
        // nothing at all.
        const toggle = document.createElement('label');
        toggle.className = 'switch switch--sm block__toggle';

        const check = document.createElement('input');
        check.type = 'checkbox';
        check.checked = block.enabled;
        check.addEventListener('change', () => {
            saveBlock(block.id, { enabled: check.checked }, card);
        });

        const track = document.createElement('span');
        track.className = 'switch__track';
        track.setAttribute('aria-hidden', 'true');

        const toggleText = document.createElement('span');
        toggleText.className = 'switch__label';
        toggleText.textContent = block.enabled ? 'On' : 'Off';

        toggle.append(check, track, toggleText);
        head.appendChild(toggle);

        const moves = document.createElement('div');
        moves.className = 'block__moves';
        moves.appendChild(buildBlockButton('↑', 'Move up', index === 0,
            () => moveBlock(block.id, 'up')));
        moves.appendChild(buildBlockButton('↓', 'Move down', index === total - 1,
            () => moveBlock(block.id, 'down')));
        const del = buildBlockButton('✕', 'Delete this block', false,
            () => removeBlock(block.id, block.title));
        del.classList.add('block__btn--danger');
        moves.appendChild(del);
        head.appendChild(moves);

        card.appendChild(head);

        const body = document.createElement('textarea');
        body.className = 'block__body';
        body.rows = 6;
        body.spellcheck = false;
        body.value = block.body;
        body.maxLength = blockLimits.body;
        body.placeholder = 'What the AI should know about this.';
        body.addEventListener('blur', () => {
            if (body.value.trim() === block.body.trim()) return;
            saveBlock(block.id, { body: body.value }, card);
        });
        card.appendChild(body);

        return card;
    }

    function buildBlockButton(glyph, label, disabled, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'block__btn';
        btn.textContent = glyph;
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.disabled = disabled;
        btn.addEventListener('click', onClick);
        return btn;
    }

    /**
     * Repaints from the whole list the server returns, not from the one
     * row that was touched: a move renumbers several blocks at once, and
     * rebuilding from a single row would show an order the database does
     * not have.
     */
    async function sendBlocks(options, card, url = API_BLOCKS) {
        if (card) card.classList.add('is-saving');
        try {
            const data = await api(url, options);
            if (data) paintBlocks(data);
        } catch (err) {
            toast(err.message, 'error');
            // The field still holds what they typed; the list is only
            // repainted on success, so nothing they wrote is thrown away.
            if (card) card.classList.remove('is-saving');
        }
    }

    function saveBlock(id, fields, card) {
        return sendBlocks({ method: 'PUT', body: JSON.stringify({ id, ...fields }) }, card);
    }

    function moveBlock(id, move) {
        return sendBlocks({ method: 'PUT', body: JSON.stringify({ id, move }) });
    }

    function createBlock(title, body) {
        return sendBlocks({ method: 'POST', body: JSON.stringify({ title, body }) });
    }

    function removeBlock(id, title) {
        const name = title ? `“${title}”` : 'this block';
        if (!window.confirm(`Delete ${name}? The AI will stop being told about it.`)) return;
        // The id rides in the query string: a DELETE body is legal but
        // not every host forwards one, and this needs no more than an id.
        return sendBlocks({ method: 'DELETE' }, null, `${API_BLOCKS}?id=${encodeURIComponent(id)}`);
    }

    el.blockAddBtn.addEventListener('click', () => createBlock('New block', ''));

    // ------------------------------------------------------------------
    // Catalog
    //
    // Uploaded through api/catalog.php rather than saved with the form
    // above: one of the values behind it is a path inside storage/, and
    // an endpoint that took a path from a JSON body would be letting the
    // browser choose a file for api/send.php to open.
    // ------------------------------------------------------------------

    function formatBytes(bytes) {
        const n = Number(bytes);
        if (!Number.isFinite(n) || n <= 0) return '';
        if (n < 1024) return `${n} B`;
        if (n < 1048576) return `${(n / 1024).toFixed(0)} KB`;
        return `${(n / 1048576).toFixed(1)} MB`;
    }

    function paintCatalog(catalog) {
        const available = catalog?.available === true;

        el.catalogViewLink.hidden = !available;
        el.catalogRemoveBtn.hidden = !available;
        el.catalogPickBtn.textContent = available ? 'Replace it' : 'Upload a file';

        el.catalogInfo.textContent = available
            ? `${catalog.name} · ${formatBytes(catalog.size)}`
            : 'No catalog uploaded yet. Until there is one, the “Send catalog” item in the inbox is greyed out.';
        el.catalogInfo.classList.toggle('catalog__info--empty', !available);
    }

    async function loadCatalog() {
        try {
            const data = await api(API_CATALOG);
            if (data) paintCatalog(data.catalog);
        } catch (err) {
            el.catalogInfo.textContent = err.message;
        }
    }

    el.catalogPickBtn.addEventListener('click', () => el.catalogInput.click());

    el.catalogInput.addEventListener('change', async () => {
        const file = el.catalogInput.files?.[0];
        // Reset immediately so picking the same file twice still fires.
        el.catalogInput.value = '';
        if (!file) return;

        el.catalogPickBtn.disabled = true;
        el.catalogInfo.textContent = 'Uploading…';

        try {
            const body = new FormData();
            body.append('file', file);
            // Not through api(): that forces application/json, and the
            // browser has to set the multipart boundary itself.
            const res = await fetch(API_CATALOG, { method: 'POST', body });
            const data = await res.json();
            if (!res.ok || data.success === false) {
                throw new Error(data.error || 'That file could not be saved.');
            }

            paintCatalog(data.catalog);
            toast('Catalog uploaded.');
        } catch (err) {
            toast(err.message, 'error');
            loadCatalog();
        } finally {
            el.catalogPickBtn.disabled = false;
        }
    });

    el.catalogRemoveBtn.addEventListener('click', async () => {
        el.catalogRemoveBtn.disabled = true;

        try {
            const data = await api(API_CATALOG, { method: 'DELETE' });
            if (data) {
                paintCatalog(data.catalog);
                toast('Catalog removed.');
            }
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            el.catalogRemoveBtn.disabled = false;
        }
    });

    // ------------------------------------------------------------------
    // Backfilling transcripts
    //
    // Only ever about catching up: voice notes that arrive from now on
    // are transcribed by the inbound webhook. Batched and manual because
    // every one is a paid model call and this app has no job queue to
    // pace them with.
    // ------------------------------------------------------------------

    async function loadTranscribeStatus() {
        try {
            const data = await api(API_TRANSCRIBE);
            if (!data) return;

            if (!data.available) {
                el.transcribeNote.textContent = 'Needs an OpenAI API key';
                el.transcribeBtn.disabled = true;
                return;
            }
            if (data.pending === 0) {
                el.transcribeNote.textContent = 'Nothing waiting — all voice notes are transcribed';
                el.transcribeBtn.disabled = true;
                return;
            }
            el.transcribeNote.textContent =
                `${data.pending} waiting · does ${data.batch} per press, each costs a model call`;
            el.transcribeBtn.disabled = false;
        } catch (err) {
            el.transcribeNote.textContent = err.message;
        }
    }

    el.transcribeBtn.addEventListener('click', async () => {
        el.transcribeBtn.disabled = true;
        el.transcribeResult.hidden = false;
        applyResult(el.transcribeResult, {
            label: 'Transcribing',
            status: 'pending',
            summary: 'Sending voice notes to OpenAI…',
            detail: [],
            hint: '',
        });

        try {
            const data = await api(API_TRANSCRIBE, { method: 'POST' });
            if (data) {
                applyResult(el.transcribeResult, {
                    label: 'Transcribing',
                    // Skipped items are not a failure of the run: a file
                    // Meta has since expired can never be transcribed,
                    // and saying so is the useful part.
                    status: data.done > 0 ? 'ok' : (data.skipped.length ? 'warn' : 'ok'),
                    summary: `${data.done} transcribed, ${data.pending} still waiting`,
                    detail: data.skipped,
                    hint: data.pending > 0 ? 'Press again to do the next batch.' : '',
                });
            }
        } catch (err) {
            applyError(el.transcribeResult, 'Transcribing', err.message);
        } finally {
            loadTranscribeStatus();
        }
    });

    // loadSettings() first and awaited: the empty state's "start from the
    // built-in prompt" button needs `defaults`, which only that call
    // fills in.
    loadSettings().then(loadBlocks);
    loadCatalog();
    loadTranscribeStatus();
    runAll();
})();
