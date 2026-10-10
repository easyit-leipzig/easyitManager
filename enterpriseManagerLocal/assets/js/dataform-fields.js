(function (global) {
    'use strict';

    function decodeCommaSeparated(value) {
        var raw = Array.isArray(value) ? value : String(value == null ? '' : value).split(',');
        var result = [];
        raw.forEach(function (item) {
            String(item).split(',').forEach(function (part) {
                var normalized = part.trim();
                if (normalized && result.indexOf(normalized) === -1) {
                    result.push(normalized);
                }
            });
        });
        return result;
    }

    function encodeCommaSeparated(values) {
        if (Array.isArray(values)) {
            values.forEach(function (value) {
                if (String(value).indexOf(',') !== -1) {
                    throw new Error('Ein einzelner Derived-Enum-Schlüssel darf kein Komma enthalten.');
                }
            });
        }
        return decodeCommaSeparated(values).join(',');
    }

    function syncMultipleSelect(selectElement, hiddenInput) {
        var values = [];
        Array.prototype.forEach.call(selectElement.options || [], function (option) {
            if (option.selected) {
                values.push(option.value);
            }
        });
        var encoded = encodeCommaSeparated(values);
        if (hiddenInput) {
            hiddenInput.value = encoded;
        }
        return encoded;
    }

    global.EasyITDataFormFields = Object.freeze({
        decodeCommaSeparated: decodeCommaSeparated,
        encodeCommaSeparated: encodeCommaSeparated,
        normalizeDerivedEnumValue: function (value) { return encodeCommaSeparated(decodeCommaSeparated(value)); },
        syncMultipleSelect: syncMultipleSelect
    });
}(window));
