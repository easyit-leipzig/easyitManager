(function (global) {
    'use strict';

    function cloneObject(value) {
        if (value === undefined) { return undefined; }
        return JSON.parse(JSON.stringify(value));
    }

    function diffRecords(original, current) {
        var result = {};
        var keys = new Set(Object.keys(original || {}).concat(Object.keys(current || {})));
        keys.forEach(function (key) {
            var before = Object.prototype.hasOwnProperty.call(original || {}, key) ? original[key] : null;
            var after = Object.prototype.hasOwnProperty.call(current || {}, key) ? current[key] : null;
            if (JSON.stringify(before) !== JSON.stringify(after)) {
                result[key] = {before: cloneObject(before), after: cloneObject(after)};
            }
        });
        return result;
    }

    function resolveHandler(path) {
        if (!path || typeof path !== 'string') { return null; }
        var cursor = global;
        var parts = path.split('.');
        for (var i = 0; i < parts.length; i += 1) {
            if (cursor == null || !Object.prototype.hasOwnProperty.call(cursor, parts[i])) {
                return null;
            }
            cursor = cursor[parts[i]];
        }
        return typeof cursor === 'function' ? cursor : null;
    }

    function buildContext(eventName, action, snapshot) {
        snapshot = snapshot || {};
        var original = snapshot.originalRecord || {};
        var current = snapshot.currentRecord || {};
        var changes = snapshot.changes || diffRecords(original, current);
        var dataForm = Object.assign({
            id: null,
            name: null,
            source: null,
            mode: null,
            isNewRecord: false,
            isDirty: Object.keys(changes).length > 0
        }, snapshot.dataForm || {});

        return {
            schema: 'easyit.dataform.action-context.v1',
            event: eventName,
            action: action,
            project: cloneObject(snapshot.project || {}),
            dataForm: cloneObject(dataForm),
            record: {
                id: snapshot.recordId !== undefined ? snapshot.recordId : (current.id !== undefined ? current.id : null),
                original: cloneObject(original),
                current: cloneObject(current)
            },
            changes: cloneObject(changes),
            relation: cloneObject(snapshot.relation || {}),
            pagination: cloneObject(snapshot.pagination || {}),
            operation: cloneObject(snapshot.operation || {}),
            ui: cloneObject(snapshot.ui || {}),
            meta: cloneObject(snapshot.meta || {})
        };
    }

    async function invoke(eventName, action, eventConfig, snapshot) {
        var definition = eventConfig && eventConfig.events ? eventConfig.events[eventName] : null;
        if (!definition || !definition.enabled) {
            return {executed: false, allowed: true, result: undefined, context: buildContext(eventName, action, snapshot)};
        }

        var handler = resolveHandler(definition.handler);
        if (!handler) {
            throw new Error('DataForm-Event-Handler nicht gefunden: ' + definition.handler);
        }

        var context = buildContext(eventName, action, snapshot);
        var result = await handler(context);
        var allowed = !(definition.blocking && result === false);
        return {executed: true, allowed: allowed, result: result, context: context};
    }

    global.easyITDataFormEvents = {
        buildContext: buildContext,
        resolveHandler: resolveHandler,
        invoke: invoke
    };
}(window));
