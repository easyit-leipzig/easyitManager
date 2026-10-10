# HF87 Phase 5 – DB-Engine / lokale Kundendaten

Der Lizenzserver führt `db-engine` als eigenes Produkt. Projektfreigaben bleiben an `customer_key + installation_id + project_id/project_slug + product_code` gebunden.

Für den Export stellt `/api/v1/account/project-product/token` einen Ed25519-signierten Projekt-/Produkt-Token aus. Der Token ist an `db-engine`, das konkrete Projekt und die genehmigten Capabilities gebunden.
