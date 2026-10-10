# HF110 – definierbare Datenquelle bei der Lizenzserver-Einrichtung

HF110 entfernt die fest verdrahtete Lizenzserver-Datenbank aus der Ersteinrichtung. Ein frischer Lizenzserver startet zuerst den Assistenten unter `setup/`.

## Unterstützte Datenquellen

Angeboten werden ausschließlich PDO-Treiber, die auf dem konkreten PHP-System installiert sind und vom Lizenzserver unterstützt werden:

- MySQL / MariaDB (`pdo_mysql`)
- PostgreSQL (`pdo_pgsql`)
- SQLite (`pdo_sqlite`)

Nicht installierte Treiber erscheinen nicht als auswählbare Option.

## Ablauf

1. Datenquellentyp wählen.
2. Verbindungsdaten eingeben.
3. Verbindung prüfen.
4. Datenquelle speichern und Lizenzserver initialisieren.
5. Fehlende MySQL/PostgreSQL-Datenbank optional automatisch anlegen.
6. PostgreSQL-Schema optional automatisch anlegen.
7. Migrationen ausführen.
8. Ed25519-Server-Schlüsselpaar initialisieren, wenn noch keines vorhanden ist.
9. Setup sperren und zur Superadmin-Einrichtung wechseln.

Die erzeugte lokale Datenquellenkonfiguration liegt in `config/database.local.php`. Sie wird nicht in Release-Archive aufgenommen und ist durch die Root-`.htaccess` vor direktem HTTP-Zugriff geschützt. Die Setup-Sperre liegt in `secure/license-server-setup.lock`.

## SQLite

SQLite erhält einen frei definierbaren Dateipfad. Relative Pfade werden relativ zum Lizenzserver aufgelöst. Der Zielordner wird bei Bedarf angelegt und muss für PHP beschreibbar sein.

## PostgreSQL

Datenbank und Schema sind getrennte Einstellungen. Nach dem Verbindungsaufbau setzt der Server den `search_path` auf das konfigurierte Schema.

## Bestehende Installationen

Das HF110-Update überschreibt keine vorhandene `config/app.php`, keine `config/database.local.php`, keine Datenbank und keinen `secure/`-Inhalt. Bestehende Installationen arbeiten deshalb weiter. Der neue Assistent kann für einen bewusst neu aufzubauenden Server verwendet werden.
