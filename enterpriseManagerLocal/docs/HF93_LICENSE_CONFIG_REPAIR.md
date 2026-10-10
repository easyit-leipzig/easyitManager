# HF93 – Upgrade-sichere Lizenzserver-Konfiguration

Die zentrale Lizenzserver-Konfiguration wird ab diesem Reparaturstand persistent unter `storage/system/config/licensing.php` gespeichert. Eine vorhandene ältere `config/licensing.php` wird beim ersten Zugriff automatisch dorthin migriert. Dadurch liegt die betriebliche Serverkonfiguration nicht mehr im austauschbaren Anwendungscode.

Wichtig beim Upgrade: Den vorhandenen `storage/`-Ordner niemals löschen. Wird eine Installation vollständig gelöscht und nur aus einem Release-ZIP neu aufgebaut, können geheime bzw. installationsspezifische Serverparameter nicht aus dem Release rekonstruiert werden und müssen über die lokale Administration erneut gesetzt werden.
