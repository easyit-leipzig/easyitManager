# HF143 – Bootstrap-Installationsplatz bei lokaler Neuinstallation freigeben

## Problem

Die technische Lizenz `BOOTSTRAP-MANAGER` besitzt absichtlich nur einen Installationsplatz. Nach einer früheren, noch nicht an eine Kundenlizenz gebundenen Managerinstallation blieb dieser Platz als `active` oder `blocked` belegt. Eine erneute lokale Managerinstallation erhielt deshalb `INSTALLATION_LIMIT_REACHED`.

## Korrektur

Der ausschließlich loopback-erreichbare Endpoint `/api/v1/bootstrap/local-activate` übergibt jetzt die aktuelle Installations-ID an `BootstrapActivationService::issue()`. Beim Ausstellen des neuen lokalen Bootstrap-Schlüssels werden ausschließlich andere Installationen der technischen Bootstrap-Lizenz mit Status `active` oder `blocked` auf `replaced` gesetzt. Ihre aktiven Installationsschlüssel und Runtime-Sitzungen werden widerrufen.

Normale Kundenlizenzen und deren Installationen werden nicht verändert. Eine idempotente Wiederholung mit derselben Installations-ID bleibt erhalten.
