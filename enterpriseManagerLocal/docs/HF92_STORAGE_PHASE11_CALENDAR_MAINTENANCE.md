# HF92 – Storage Phase 11: Calendar-Datenpflege

Phase 11 ergänzt die projektbezogene Calendar-Datenpflege für Personen, Ressourcen, Kategorien und Rollen. Die Pflege arbeitet ausschließlich über die im Calendar-Projekt konfigurierte Datenquelle und damit auch über die CSV-Engine. Personen, Ressourcen und Kategorien werden standardmäßig logisch deaktiviert, damit bestehende Terminreferenzen erhalten bleiben. Die Systemrollen Administrator, Tutor und Student sind geschützt. Zusätzlich wurde die Calendar-Schemaerzeugung für PostgreSQL auf TIMESTAMP und für MSSQL auf DATETIME2 korrigiert.
