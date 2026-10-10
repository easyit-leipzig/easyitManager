# HF87 Phase 3 – Differenzierte Lizenzanfrage

Registrierte Kunden können aus dem Enterprise-Manager einen strukturierten Lizenzantrag stellen. Die Anfrage enthält Produkte, Module, Capabilities, Installations- und DataForm-Kontingent, gewünschte Laufzeit, Domains/IP-Netze und Freitext.

Die Anfrage erzeugt keine Lizenz automatisch. Sie wird in `customer_license_requests` als `pending` gespeichert und im Lizenzserver unter **Lizenzanfragen** geprüft. Der Superadmin kann die beantragten Werte vor Freigabe verändern. Bei Freigabe wird entweder eine neue Lizenz erzeugt oder eine vorhandene aktive Lizenz desselben Kunden aktualisiert.

Der Kundenschlüssel bleibt ausschließlich die normalisierte E-Mail-Adresse (`customer_key`).
