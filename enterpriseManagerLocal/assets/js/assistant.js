(function () {
    'use strict';

    window.easyITAssistant = window.easyITAssistant || {};

    window.easyITAssistant.run = async function (assistantId, stepId, context) {
        var payload = Object.assign({}, context || {}, {assistant: assistantId, step: stepId || ''});
        var body = new URLSearchParams(payload);
        var response = await fetch('/admin/assistants/api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin'
        });
        var data = await response.json();
        if (!response.ok) {
            var message = data && data.errors ? data.errors.join('; ') : 'Assistant-Aufruf fehlgeschlagen.';
            throw new Error(message);
        }
        return data;
    };

    function normalize(value) {
        return String(value || '').toLocaleLowerCase('de-DE').trim();
    }

    function mountCenterFilter() {
        var input = document.getElementById('eit-assistant-filter');
        if (!input) return;
        var cards = Array.prototype.slice.call(document.querySelectorAll('[data-assistant-card]'));
        var categories = Array.prototype.slice.call(document.querySelectorAll('.eit-assistant-category'));
        function apply() {
            var needle = normalize(input.value);
            cards.forEach(function (card) {
                var haystack = normalize(card.getAttribute('data-search') || card.textContent);
                card.hidden = needle !== '' && haystack.indexOf(needle) === -1;
            });
            categories.forEach(function (category) {
                var visible = category.querySelectorAll('[data-assistant-card]:not([hidden])').length;
                category.hidden = visible === 0;
            });
        }
        input.addEventListener('input', apply);
        apply();
    }

    document.addEventListener('DOMContentLoaded', mountCenterFilter);
}());
