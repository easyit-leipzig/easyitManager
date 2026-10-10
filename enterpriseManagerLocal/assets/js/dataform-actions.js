(function (global) {
    'use strict';

    var aliases = {open: 'show', view: 'show', create: 'new', prev: 'previous'};

    function normalizeAction(action) {
        return aliases[action] || action;
    }

    function actionDefinition(config, action) {
        var normalized = normalizeAction(action);
        return config && config.actions ? (config.actions[normalized] || null) : null;
    }

    function canRun(config, action, snapshot) {
        var normalized = normalizeAction(action);
        var definition = actionDefinition(config, normalized);
        if (!definition) { return false; }
        snapshot = snapshot || {};
        var currentRecord = snapshot.currentRecord || {};
        var recordId = snapshot.recordId !== undefined ? snapshot.recordId : currentRecord.id;
        if (definition.requiresRecord && (recordId === undefined || recordId === null || recordId === '')) {
            return false;
        }
        var mode = snapshot.dataForm && snapshot.dataForm.mode ? snapshot.dataForm.mode : null;
        if (mode && Array.isArray(definition.allowedModes) && definition.allowedModes.length && definition.allowedModes.indexOf(mode) === -1) {
            return false;
        }
        return true;
    }

    async function dispatch(action, snapshot, actionConfig, eventConfig, implementation) {
        var normalized = normalizeAction(action);
        if (!canRun(actionConfig, normalized, snapshot)) {
            throw new Error('DataForm-Aktion ist im aktuellen Zustand nicht erlaubt: ' + normalized);
        }
        if (typeof implementation !== 'function') {
            throw new Error('Für DataForm-Aktion fehlt die Implementierung: ' + normalized);
        }

        var events = global.easyITDataFormEvents;
        if (!events || typeof events.buildContext !== 'function') {
            throw new Error('easyITDataFormEvents ist nicht geladen.');
        }

        if (normalized === 'save') {
            var beforeSave = await events.invoke('beforeSave', normalized, eventConfig || {}, snapshot || {});
            if (!beforeSave.allowed) {
                return {executed: false, allowed: false, blockedBy: 'beforeSave', context: beforeSave.context};
            }
            var saveResult = await implementation(beforeSave.context);
            var afterSave = await events.invoke('afterSave', normalized, eventConfig || {}, snapshot || {});
            return {executed: true, allowed: true, result: saveResult, context: afterSave.context};
        }

        if (normalized === 'delete') {
            var beforeDelete = await events.invoke('beforeDelete', normalized, eventConfig || {}, snapshot || {});
            if (!beforeDelete.allowed) {
                return {executed: false, allowed: false, blockedBy: 'beforeDelete', context: beforeDelete.context};
            }
            var deleteResult = await implementation(beforeDelete.context);
            var afterDelete = await events.invoke('afterDelete', normalized, eventConfig || {}, snapshot || {});
            return {executed: true, allowed: true, result: deleteResult, context: afterDelete.context};
        }

        var context = events.buildContext('action', normalized, snapshot || {});
        var result = await implementation(context);
        return {executed: true, allowed: true, result: result, context: context};
    }

    function buttonMetadata(config, action) {
        var definition = actionDefinition(config, action);
        if (!definition) { return null; }
        return {
            action: definition.id,
            buttonKey: definition.buttonKey,
            title: definition.title,
            ariaLabel: definition.ariaLabel,
            assetRoot: config && config.buttonRegistry ? config.buttonRegistry.assetRoot : 'assets/img/'
        };
    }

    global.easyITDataFormActions = {
        normalizeAction: normalizeAction,
        canRun: canRun,
        dispatch: dispatch,
        buttonMetadata: buttonMetadata
    };
}(window));
