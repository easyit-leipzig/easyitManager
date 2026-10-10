# HF87 Phase 4 – Produkt-/Projekt-Lizenzportal

Der zentrale Produktkatalog wird im Lizenzserver geführt. Ein Kunden-Dashboard zeigt mehrere Produkte unabhängig davon, ob sie lokal bereits installiert oder für ein Projekt lizenziert sind.

Die fachliche Bindung einer Freigabe lautet:

`customer_key + installation_id + project_id/project_slug + product_code`

Eine Dashboard-Anfrage erzeugt ausschließlich einen Datensatz in `project_product_requests`. Erst der Superadmin kann unter **Projektlizenzen** eine projektbezogene Freigabe erzeugen. Die Freigabe wird in `project_product_entitlements` gespeichert.

Der Enterprise-Manager lädt ein Produktmodul über `app/products/load.php`. Vor dem Laden wird die zentrale Projektfreigabe geprüft. Für ältere Lizenzen ohne irgendeinen projektbezogenen Entitlement-Datensatz bleibt der bisherige globale Modulzugriff als Legacy-Kompatibilität erhalten.
