/**
 * DataForm 5 – Parent→Child Binding Runtime v1.0.1
 *
 * Korrektur:
 * 1. In einer NEUEN gebundenen Kindzeile wird childField unmittelbar
 *    mit der ID des aktuellen Parent-Datensatzes belegt.
 *    Ein Placeholder wie "Eltern-Datensatz wählen" ist bei vorhandenem
 *    Parent ausdrücklich unzulässig.
 * 2. boundFieldReadonly=true bleibt der Standard.
 */
window.DataFormBoundBinding = (() => {
    const HIDDEN_ATTR = "data-df-bound-hidden";
    const BOUND_ATTR = "data-df-bound-field";

    function parentValue(binding, parentRecord) {
        return parentRecord?.[binding.parentField] ?? null;
    }

    function displayValue(value) {
        if (value === null || value === "") {
            return "";
        }
        return "#" + String(value);
    }

    function canCreateChild(binding, parentRecord) {
        const value = parentValue(binding, parentRecord);
        return value !== null && value !== "";
    }

    function newRecordDefaults(binding, parentRecord, defaults = {}) {
        const value = parentValue(binding, parentRecord);

        if (value === null || value === "") {
            throw new Error(
                "Hauptdatensatz zuerst speichern. " +
                "Danach können gebundene Datensätze angelegt werden."
            );
        }

        return {
            ...defaults,
            [binding.childField]: value
        };
    }

    /**
     * Sorgt bei SELECT dafür, dass der echte Parent-Wert als Option
     * existiert und ausgewählt ist.
     *
     * Beispiel:
     * value = 9201  -> sichtbarer Text = "#9201"
     */
    function ensureSelectParentOption(select, value) {
        const stringValue = String(value);

        let option = Array.from(select.options)
            .find(item => item.value === stringValue);

        if (!option) {
            option = new Option(
                displayValue(value),
                stringValue,
                true,
                true
            );
            select.add(option);
        }

        // Falls die bestehende Option nur einen generischen Text trägt,
        // wird bei einer gebundenen Parent-ID die ID sichtbar gemacht.
        if (
            !option.textContent?.trim() ||
            /Eltern-Datensatz\s+wählen/i.test(option.textContent)
        ) {
            option.textContent = displayValue(value);
        }

        select.value = stringValue;
        option.selected = true;

        return option;
    }

    function setFieldValue(field, value) {
        if (field instanceof HTMLSelectElement) {
            ensureSelectParentOption(field, value);
        } else if (
            field instanceof HTMLInputElement ||
            field instanceof HTMLTextAreaElement
        ) {
            field.value = value == null
                ? ""
                : String(value);
        } else {
            // Read-only-Textdarstellung
            field.textContent = displayValue(value);
        }

        field.setAttribute(BOUND_ATTR, "1");
        field.dataset.dfBoundValue =
            value == null ? "" : String(value);

        field.dispatchEvent(
            new Event("change", { bubbles: true })
        );
    }

    function lockField(field) {
        field.dataset.dfBoundReadonly = "true";
        field.setAttribute("aria-readonly", "true");

        if (
            field instanceof HTMLInputElement &&
            !["checkbox", "radio", "file", "button", "submit"]
                .includes(field.type)
        ) {
            field.readOnly = true;
            return;
        }

        if (field instanceof HTMLTextAreaElement) {
            field.readOnly = true;
            return;
        }

        if (
            field instanceof HTMLSelectElement ||
            "disabled" in field
        ) {
            field.disabled = true;
        }
    }

    function unlockField(field) {
        field.dataset.dfBoundReadonly = "false";
        field.removeAttribute("aria-readonly");

        if ("readOnly" in field) {
            try {
                field.readOnly = false;
            } catch (_) {}
        }

        if ("disabled" in field) {
            field.disabled = false;
        }
    }

    function removeHiddenMirror(field) {
        const parent = field.parentElement;
        if (!parent) return;

        const name = field.getAttribute?.("name");
        if (!name) return;

        parent.querySelectorAll(
            `input[type="hidden"][${HIDDEN_ATTR}="1"]`
        ).forEach(hidden => {
            if (hidden.name === name) {
                hidden.remove();
            }
        });
    }

    function createHiddenMirror(field, value) {
        const name = field.getAttribute("name");
        if (!name) return;

        removeHiddenMirror(field);

        const hidden = document.createElement("input");
        hidden.type = "hidden";
        hidden.name = name;
        hidden.value = value == null ? "" : String(value);
        hidden.setAttribute(HIDDEN_ATTR, "1");

        field.insertAdjacentElement("afterend", hidden);
    }

    /**
     * Belegt ein konkretes Child-Feld mit der Parent-ID.
     */
    function applyToField(field, value, readonly = true) {
        if (!(field instanceof HTMLElement)) {
            throw new TypeError(
                "Gebundenes Feld wurde nicht gefunden."
            );
        }

        if (value === null || value === "") {
            throw new Error(
                "Gebundenes Feld kann ohne Parent-ID nicht initialisiert werden."
            );
        }

        setFieldValue(field, value);
        removeHiddenMirror(field);

        if (readonly) {
            lockField(field);

            // Disabled SELECTs werden bei normalem Browser-Submit nicht
            // übertragen. Deshalb immer Hidden-Mirror anlegen.
            if (
                field instanceof HTMLSelectElement ||
                field.disabled
            ) {
                createHiddenMirror(field, value);
            }
        } else {
            unlockField(field);
        }

        return {
            value,
            displayValue: displayValue(value),
            readonly: !!readonly
        };
    }

    function escapeCss(value) {
        if (window.CSS?.escape) {
            return window.CSS.escape(value);
        }

        return String(value).replace(
            /["\\]/g,
            "\\$&"
        );
    }

    /**
     * Findet das gebundene Feld innerhalb EINER neuen Kindzeile.
     */
    function findBoundField(row, binding) {
        const field = escapeCss(binding.childField);

        return row.querySelector(
            `[data-field="${field}"],` +
            `[name="${field}"],` +
            `[name$="[${field}]"]`
        );
    }

    /**
     * Dies ist der zentrale Fix für die im Screenshot sichtbare NEU-Zeile.
     *
     * Sobald die neue Zeile existiert, wird "Eltern-Datensatz wählen"
     * durch die reale Parent-ID ersetzt.
     */
    function bindNewRow(row, binding, parentRecord) {
        if (!(row instanceof HTMLElement)) {
            throw new TypeError(
                "Neue Kindzeile fehlt."
            );
        }

        const value = parentValue(binding, parentRecord);

        if (value === null || value === "") {
            row.dataset.dfBoundBlocked = "true";
            return {
                canCreateChild: false,
                parentValue: null
            };
        }

        const field = findBoundField(row, binding);

        if (!(field instanceof HTMLElement)) {
            throw new Error(
                "Gebundenes Kindfeld '" +
                binding.childField +
                "' wurde in der neuen Zeile nicht gefunden."
            );
        }

        const state = applyToField(
            field,
            value,
            binding.boundFieldReadonly !== false
        );

        row.dataset.dfBoundParentValue =
            String(value);

        row.dataset.dfBoundBlocked = "false";

        return {
            canCreateChild: true,
            parentValue: value,
            fieldState: state
        };
    }

    /**
     * Bindet alle vorhandenen NEU-Zeilen eines Child-Recordsets.
     */
    function bindExistingNewRows(
        recordsetRoot,
        binding,
        parentRecord
    ) {
        if (!(recordsetRoot instanceof HTMLElement)) {
            return [];
        }

        const selectors = [
            '[data-record-state="new"]',
            '[data-record-new="true"]',
            '.df-record-new',
            '.record-new',
            '[data-row-type="new"]'
        ].join(",");

        return Array.from(
            recordsetRoot.querySelectorAll(selectors)
        ).map(row => bindNewRow(
            row,
            binding,
            parentRecord
        ));
    }

    /**
     * Beobachtet dynamisch erzeugte New-Record-Zeilen.
     * Dadurch gilt die Parent-Belegung auch dann, wenn die *-Zeile erst
     * nach einem AJAX-/Render-Schritt in das DOM eingefügt wird.
     */
    function observeNewRows(
        recordsetRoot,
        binding,
        getParentRecord
    ) {
        if (!(recordsetRoot instanceof HTMLElement)) {
            throw new TypeError(
                "Recordset-Root fehlt."
            );
        }

        const isNewRow = element =>
            element.matches?.(
                '[data-record-state="new"],' +
                '[data-record-new="true"],' +
                '.df-record-new,' +
                '.record-new,' +
                '[data-row-type="new"]'
            );

        const tryBind = element => {
            if (!(element instanceof HTMLElement)) return;

            if (isNewRow(element)) {
                bindNewRow(
                    element,
                    binding,
                    getParentRecord()
                );
            }

            element.querySelectorAll?.(
                '[data-record-state="new"],' +
                '[data-record-new="true"],' +
                '.df-record-new,' +
                '.record-new,' +
                '[data-row-type="new"]'
            ).forEach(row => {
                bindNewRow(
                    row,
                    binding,
                    getParentRecord()
                );
            });
        };

        // Bereits vorhandene New-Zeile sofort korrigieren.
        bindExistingNewRows(
            recordsetRoot,
            binding,
            getParentRecord()
        );

        const observer = new MutationObserver(mutations => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach(node => {
                    if (node instanceof HTMLElement) {
                        tryBind(node);
                    }
                });
            }
        });

        observer.observe(
            recordsetRoot,
            {
                childList: true,
                subtree: true
            }
        );

        return observer;
    }

    /**
     * Parent-Navigation:
     * - Child-Filter = Parent-ID
     * - New-Record-Default = Parent-ID
     * - vorhandene New-Zeile = Parent-ID
     */
    function applyParentChange({
        binding,
        parentRecord,
        recordsetRoot = null,
        childField = null,
        childNewButton = null,
        onFilter = null,
        onDefaults = null
    }) {
        const value = parentValue(
            binding,
            parentRecord
        );

        const persisted =
            value !== null && value !== "";

        if (childNewButton instanceof HTMLElement) {
            childNewButton.toggleAttribute(
                "disabled",
                !persisted
            );

            childNewButton.setAttribute(
                "aria-disabled",
                persisted ? "false" : "true"
            );

            childNewButton.title = persisted
                ? "Neuen gebundenen Datensatz anlegen"
                : "Hauptdatensatz zuerst speichern";
        }

        if (!persisted) {
            onFilter?.({
                [binding.childField]: null
            });
            onDefaults?.({});

            return {
                canCreateChild: false,
                parentValue: null
            };
        }

        if (childField instanceof HTMLElement) {
            applyToField(
                childField,
                value,
                binding.boundFieldReadonly !== false
            );
        }

        if (recordsetRoot instanceof HTMLElement) {
            bindExistingNewRows(
                recordsetRoot,
                binding,
                parentRecord
            );
        }

        const filter = {
            [binding.childField]: value
        };

        const defaults = {
            [binding.childField]: value
        };

        onFilter?.(filter);
        onDefaults?.(defaults);

        return {
            canCreateChild: true,
            parentValue: value,
            filter,
            defaults
        };
    }

    return {
        parentValue,
        displayValue,
        canCreateChild,
        newRecordDefaults,
        applyToField,
        findBoundField,
        bindNewRow,
        bindExistingNewRows,
        observeNewRows,
        applyParentChange
    };
})();
