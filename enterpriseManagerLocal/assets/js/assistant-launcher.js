(function () {
    'use strict';

    function ownScript() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            if ((scripts[i].src || '').indexOf('assistant-launcher.js') !== -1) return scripts[i];
        }
        return null;
    }

    var script = ownScript();
    var apiUrl = script ? new URL('../../admin/assistants/integration-api.php', script.src).toString() : '/admin/assistants/integration-api.php';

    function contextFromElement(el) {
        var body = document.body || {};
        var d = el.dataset || {};
        var bd = body.dataset || {};
        return {
            surface: d.surface || d.eitAssistantSurface || bd.eitAssistantSurface || bd.assistantSurface || '',
            project_id: d.projectId || bd.projectId || '',
            dataform_id: d.dataformId || d.dataFormId || bd.dataformId || bd.dataFormId || '',
            record_id: d.recordId || bd.recordId || ''
        };
    }

    function text(tag, value, cls) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        node.textContent = value;
        return node;
    }

    function render(target, payload) {
        target.classList.add('eit-assistant-launcher');
        target.setAttribute('aria-label', 'Assistenten für ' + payload.surfaceTitle);
        target.replaceChildren();
        target.appendChild(text('span', 'Assistenten', 'eit-assistant-launcher-title'));
        var ul = document.createElement('ul');
        (payload.assistants || []).forEach(function (entry) {
            var li = document.createElement('li');
            if (entry.available) {
                var a = document.createElement('a');
                a.href = entry.url;
                a.setAttribute('data-button-key', entry.buttonKey || 'show');
                a.title = entry.linkTitle || entry.description || entry.title;
                a.setAttribute('aria-label', entry.ariaLabel || entry.title);
                a.textContent = entry.title;
                li.appendChild(a);
            } else {
                var span = text('span', entry.title, 'eit-assistant-launcher-disabled');
                span.setAttribute('aria-disabled', 'true');
                span.title = 'Fehlender Kontext: ' + (entry.missing || []).join(', ');
                li.appendChild(span);
            }
            ul.appendChild(li);
        });
        target.appendChild(ul);
    }

    async function mount(target, options) {
        if (!target) throw new Error('Assistant launcher target fehlt.');
        var ctx = Object.assign({}, contextFromElement(target), options || {});
        var params = new URLSearchParams();
        Object.keys(ctx).forEach(function (key) {
            if (ctx[key] !== null && ctx[key] !== undefined && String(ctx[key]) !== '') params.set(key, String(ctx[key]));
        });
        var response = await fetch(apiUrl + '?' + params.toString(), {credentials: 'same-origin'});
        var payload = await response.json();
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'Assistenten konnten nicht geladen werden.');
        render(target, payload);
        return payload;
    }

    window.easyITAssistantLauncher = {mount: mount, render: render, apiUrl: apiUrl};

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-eit-assistant-launcher]').forEach(function (target) {
            mount(target).catch(function (err) {
                target.textContent = 'Assistenten nicht verfügbar: ' + err.message;
                target.setAttribute('role', 'status');
            });
        });
    });
}());
