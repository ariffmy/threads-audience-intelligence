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
        feedback.textContent = `Checked ${data.checked_at}`;
    } catch {
        feedback.textContent = 'Status could not be refreshed. Try again or reload the page.';
    } finally {
        refreshButton.disabled = false;
    }
});
