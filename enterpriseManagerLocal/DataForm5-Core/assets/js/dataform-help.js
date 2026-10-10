/**
 * DataForm 5 Help Client
 *
 * Usage:
 *   DataFormHelp.show("recordset.afterSave", "#dataform-help-panel");
 *   DataFormHelp.open("recordset.afterSave");
 */
window.DataFormHelp = (() => {
    const config = {
        apiUrl: "/api/dataform-help.php",
        documentUrl: "/help.php"
    };

    function configure(options = {}) {
        Object.assign(config, options);
    }

    async function load(helpId) {
        const response = await fetch(
            config.apiUrl + "?id=" + encodeURIComponent(helpId),
            {
                method: "GET",
                headers: {
                    "Accept": "application/json"
                },
                credentials: "same-origin"
            }
        );

        const data = await response.json();

        if (!response.ok) {
            throw new Error(
                data?.message ?? "Hilfe konnte nicht geladen werden."
            );
        }

        return data;
    }

    function open(helpId) {
        const url =
            config.documentUrl
            + "?id="
            + encodeURIComponent(helpId);

        window.open(
            url,
            "_blank",
            "noopener,noreferrer"
        );
    }

    async function show(helpId, target) {
        const element = resolveTarget(target);

        if (!element) {
            throw new Error(
                "Help-Ziel wurde nicht gefunden."
            );
        }

        element.innerHTML =
            '<div class="df-help-loading">Hilfe wird geladen…</div>';

        try {
            const help = await load(helpId);
            render(element, help, helpId);
        } catch (error) {
            element.innerHTML =
                '<div class="df-help-error"></div>';

            element.querySelector(".df-help-error").textContent =
                error instanceof Error
                    ? error.message
                    : String(error);
        }
    }

    function render(element, help, helpId) {
        element.innerHTML = "";

        const root = document.createElement("section");
        root.className = "df-help";

        const heading = document.createElement("h3");
        heading.className = "df-help-title";
        heading.textContent =
            help.title
            ?? help._registry?.title
            ?? helpId;

        const tabs = document.createElement("div");
        tabs.className = "df-help-tabs";

        const content = document.createElement("div");
        content.className = "df-help-content";

        const modes = [
            {
                key: "short",
                label: "Kurzhilfe",
                render: () => renderShort(help)
            },
            {
                key: "steps",
                label: "Schritt für Schritt",
                render: () => renderSteps(help)
            },
            {
                key: "expert",
                label: "Expertenmodus",
                render: () => renderExpert(help)
            }
        ];

        function activate(mode) {
            tabs.querySelectorAll("button").forEach(button => {
                button.classList.toggle(
                    "is-active",
                    button.dataset.mode === mode.key
                );
            });

            content.replaceChildren(mode.render());
        }

        for (const mode of modes) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "df-help-tab";
            button.dataset.mode = mode.key;
            button.textContent = mode.label;
            button.addEventListener(
                "click",
                () => activate(mode)
            );
            tabs.appendChild(button);
        }

        const actions = document.createElement("div");
        actions.className = "df-help-actions";

        const docButton = document.createElement("button");
        docButton.type = "button";
        docButton.className = "df-help-action";
        docButton.textContent = "Vollständige Dokumentation";
        docButton.addEventListener(
            "click",
            () => open(helpId)
        );

        const exampleButton = document.createElement("button");
        exampleButton.type = "button";
        exampleButton.className = "df-help-action";
        exampleButton.textContent = "JavaScript-Beispiele";
        exampleButton.addEventListener(
            "click",
            () => open("dataformContext.examples")
        );

        actions.append(docButton, exampleButton);

        root.append(
            heading,
            tabs,
            content,
            actions
        );

        element.appendChild(root);

        activate(modes[0]);
    }

    function renderShort(help) {
        const p = document.createElement("p");
        p.textContent =
            help.short?.text
            ?? "Keine Kurzhilfe vorhanden.";
        return p;
    }

    function renderSteps(help) {
        const ol = document.createElement("ol");
        const steps = Array.isArray(help.steps)
            ? help.steps
            : [];

        if (!steps.length) {
            const li = document.createElement("li");
            li.textContent =
                "Keine Schritt-für-Schritt-Hilfe vorhanden.";
            ol.appendChild(li);
            return ol;
        }

        for (const step of steps) {
            const li = document.createElement("li");
            li.textContent = String(step);
            ol.appendChild(li);
        }

        return ol;
    }

    function renderExpert(help) {
        const p = document.createElement("p");
        p.textContent =
            help.expert?.text
            ?? "Keine Expertenhilfe vorhanden.";
        return p;
    }

    function resolveTarget(target) {
        if (target instanceof Element) {
            return target;
        }

        if (typeof target === "string") {
            return document.querySelector(target);
        }

        return null;
    }

    return {
        configure,
        load,
        show,
        open
    };
})();
