'use strict';

const menuButton = document.querySelector('.mobile-menu');
menuButton?.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    menuButton.setAttribute('aria-expanded', String(!expanded));
    document.querySelector('.navigation-content')?.classList.toggle('is-open', !expanded);
});

const refreshButton = document.getElementById('refresh-status');
refreshButton?.addEventListener('click', async () => {
    const feedback = document.getElementById('status-feedback');
    refreshButton.disabled = true;
    feedback.textContent = 'Checking system status…';
    try {
        const response = await fetch('/status.json', {
            credentials: 'same-origin', headers: { Accept: 'application/json' },
            cache: 'no-store', signal: AbortSignal.timeout(10000),
        });
        if (response.status === 401) {
            window.location.assign('/login');
            return;
        }
        if (!response.ok) throw new Error('Status request failed.');
        const data = await response.json();
        if (!Array.isArray(data.checks)) throw new Error('Invalid status response.');
        const container = document.getElementById('system-checks');
        const fragment = document.createDocumentFragment();
        for (const check of data.checks) {
            const column = document.createElement('div');
            column.className = 'col-md-6';
            const card = document.createElement('div');
            card.className = 'status-card';
            const heading = document.createElement('div');
            heading.className = 'd-flex align-items-center justify-content-between mb-2';
            const title = document.createElement('h2');
            title.textContent = String(check.name);
            const badge = document.createElement('span');
            badge.className = `badge ${check.ok ? 'healthy-badge' : 'attention-badge'}`;
            badge.textContent = check.ok ? 'Ready' : 'Review';
            const detail = document.createElement('p');
            detail.textContent = String(check.detail);
            heading.append(title, badge);
            card.append(heading, detail);
            column.append(card);
            fragment.append(column);
        }
        container.replaceChildren(fragment);
        if (data.queue) {
            document.querySelectorAll('[data-queue-count]').forEach(element => {
                element.textContent = String(data.queue.counts[element.dataset.queueCount]);
            });
            const values = {
                'queue-last-success': data.queue.last_success_at ?? 'No successful run recorded',
                'queue-processed': data.queue.processed_jobs,
                'queue-worker-errors': `${data.queue.worker_errors} / ${data.queue.unfinished_runs}`,
                'queue-duration': data.queue.longest_ms === null ? 'No measurement' : `${data.queue.longest_ms} ms`,
                'queue-memory': data.queue.peak_bytes === null ? 'No measurement' : `${(data.queue.peak_bytes / 1048576).toFixed(1)} MiB`,
            };
            for (const [id, value] of Object.entries(values)) {
                const element = document.getElementById(id);
                if (element) element.textContent = String(value);
            }
            const errors = data.queue.recent_errors.map(error => {
                const element = document.createElement('li');
                element.textContent = `${error.safe_error_code} · ${error.finished_at}`;
                return element;
            });
            if (!errors.length) {
                const element = document.createElement('li');
                element.textContent = 'No retained job errors';
                errors.push(element);
            }
            document.getElementById('queue-recent-errors')?.replaceChildren(...errors);
        }
        feedback.textContent = `Checked ${data.checked_at}`;
    } catch {
        feedback.textContent = 'Status could not be refreshed. Try again or reload the page.';
    } finally {
        refreshButton.disabled = false;
    }
});
