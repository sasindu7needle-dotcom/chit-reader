const processingMonitor = document.getElementById('processing-monitor');
if (processingMonitor) {
    let checking = false;

    async function checkProcessing() {
        if (document.hidden || checking || document.querySelector('#review-dialog[open]')) return;
        checking = true;
        try {
            const pageUrl = new URL(window.location.href);
            pageUrl.searchParams.set('_processing_refresh', Date.now().toString());
            const pageResponse = await fetch(pageUrl, {
                headers: {Accept: 'text/html'},
                credentials: 'same-origin',
                cache: 'no-store',
                signal: AbortSignal.timeout(15000),
            });
            if (!pageResponse.ok || pageResponse.redirected) return;
            const page = new DOMParser().parseFromString(await pageResponse.text(), 'text/html');
            if (!page.getElementById('processing-monitor') || document.querySelector('#review-dialog[open]')) return;

            const selectedRows = new Set([...document.querySelectorAll('.row-select:checked')].map(input => input.getAttribute('aria-label')));
            const selectAll = document.getElementById('select-all');
            for (const selector of ['#processing-notices', '.sidebar nav', '.stats-grid', '.table-card .section-heading h2', '.table-toolbar .tabs', '.table-card tbody', '.table-footer']) {
                const existing = document.querySelector(selector);
                const replacement = page.querySelector(selector);
                if (existing && replacement) existing.replaceChildren(...replacement.childNodes);
            }
            document.querySelectorAll('.row-select').forEach(input => {
                input.checked = selectAll?.checked || selectedRows.has(input.getAttribute('aria-label'));
            });
        } catch (error) {
            console.warn('Processing updates could not be loaded; retrying automatically.', error);
        } finally {
            checking = false;
        }
    }

    setInterval(checkProcessing, 5000);
    document.addEventListener('visibilitychange', checkProcessing);
    document.getElementById('review-dialog')?.addEventListener('close', checkProcessing);
    checkProcessing();
}
