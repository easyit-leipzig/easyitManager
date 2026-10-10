(() => {
    const endpoint = document.documentElement.dataset.developerOverlayEndpoint;
    if (!endpoint) return;

    let panel = null;
    const create = () => {
        if (panel) return panel;
        panel = document.createElement('aside');
        panel.id = 'easyit-developer-overlay';
        panel.style.cssText = 'position:fixed;left:1rem;bottom:1rem;z-index:99999;max-width:420px;background:#111;color:#fff;padding:12px 14px;border-radius:10px;font:12px/1.45 monospace;box-shadow:0 8px 30px rgba(0,0,0,.35);display:none';
        document.body.appendChild(panel);
        return panel;
    };

    const refresh = async () => {
        const p = create();
        try {
            const response = await fetch(endpoint, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const s = await response.json();
            p.innerHTML =
                '<strong>easyIT Developer</strong><br>' +
                'Runtime: ' + Number(s.runtime_ms).toFixed(2) + ' ms<br>' +
                'Peak: ' + (Number(s.memory_peak_bytes)/1048576).toFixed(2) + ' MB<br>' +
                'Module: ' + s.modules + '<br>' +
                'Services: ' + s.services_resolved + '/' + s.services_total + '<br>' +
                'Queue: ' + (s.queue?.pending ?? 0) + ' pending / ' + (s.queue?.failed ?? 0) + ' failed<br>' +
                'Scheduler: ' + s.scheduler_tasks;
        } catch (e) {
            p.textContent = 'Developer Overlay: ' + e.message;
        }
    };

    document.addEventListener('keydown', async (event) => {
        if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'd') {
            event.preventDefault();
            const p = create();
            if (p.style.display === 'none') {
                p.style.display = 'block';
                await refresh();
            } else {
                p.style.display = 'none';
            }
        }
    });
})();
