/**
 * DataForm 5 – Recordset Layout v1.0.1
 *
 * Verbindliche Regel:
 * Die Paginierung steht IMMER unterhalb der Datensätze.
 * Sie gehört nicht in den horizontal scrollbaren Datenbereich.
 */
window.DataFormRecordsetLayout = (() => {
    const paginationSelector = [
        '[data-role="recordset-pagination"]',
        '.df-recordset-pagination',
        '.recordset-pagination',
        '.df-pagination'
    ].join(",");

    const dataSelector = [
        '[data-role="recordset-data"]',
        '[data-role="recordset-records"]',
        '.df-recordset-data',
        '.recordset-data',
        '.df-recordset-scroll'
    ].join(",");

    function ensurePaginationBelow(recordsetRoot) {
        if (!(recordsetRoot instanceof HTMLElement)) {
            throw new TypeError(
                "Recordset-Root fehlt."
            );
        }

        const pagination =
            recordsetRoot.querySelector(
                paginationSelector
            );

        if (!(pagination instanceof HTMLElement)) {
            return false;
        }

        const dataArea =
            recordsetRoot.querySelector(
                dataSelector
            );

        if (!(dataArea instanceof HTMLElement)) {
            // Fallback: Paginierung als letztes Element im Recordset.
            recordsetRoot.appendChild(pagination);
            pagination.dataset.dfPaginationPlacement = "below";
            return true;
        }

        // Paginierung aus Scroll-/Datensatzcontainer herausziehen.
        dataArea.insertAdjacentElement(
            "afterend",
            pagination
        );

        pagination.dataset.dfPaginationPlacement = "below";

        return true;
    }

    function ensureAll(root = document) {
        const recordsets = root.querySelectorAll(
            '[data-role="recordset"],' +
            '.df-recordset,' +
            '.recordset'
        );

        recordsets.forEach(
            ensurePaginationBelow
        );

        return recordsets.length;
    }

    /**
     * Beobachtet spätere AJAX-/Render-Änderungen und stellt die Position
     * nach jedem Re-Render wieder her.
     */
    function observe(recordsetRoot) {
        ensurePaginationBelow(recordsetRoot);

        const observer = new MutationObserver(() => {
            ensurePaginationBelow(recordsetRoot);
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

    return {
        ensurePaginationBelow,
        ensureAll,
        observe
    };
})();
