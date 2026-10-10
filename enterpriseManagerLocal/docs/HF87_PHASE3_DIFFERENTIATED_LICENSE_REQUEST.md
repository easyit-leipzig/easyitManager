# HF87 Phase 3 – Differenzierte Lizenzanfrage

Im Kundenkonto steht eine strukturierte Lizenzanfrage zur Verfügung. Sie erfasst Produkte, Module, Capabilities, Installations- und DataForm-Kontingente, Laufzeit, Domains/IP-Netze sowie Zusatzhinweise.

Ein Antrag erzeugt keine Lizenz automatisch. Der Lizenzserver speichert ihn als `pending`. Der Superadmin prüft ihn unter **Lizenzanfragen** und kann den endgültigen Umfang vor Freigabe verändern. Erst die Freigabe erzeugt eine neue Lizenz oder aktualisiert eine vorhandene aktive Lizenz desselben Kunden.

Die fachliche Zuordnung erfolgt ausschließlich über `customer_key`, also die normalisierte Kunden-E-Mail-Adresse.
