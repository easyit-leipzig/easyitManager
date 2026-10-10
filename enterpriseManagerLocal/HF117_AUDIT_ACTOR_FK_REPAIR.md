# HF117 – Audit Actor / Foreign-Key Repair

## Fehlerbild
Beim Anlegen eines Projekts konnte `audit_log.user_id` mit der numerischen ID eines zentralen Kundenkontos befüllt werden. Diese ID stammt vom Lizenzserver und ist kein Primärschlüssel der lokalen Tabelle `easyit_license.users`. MySQL/MariaDB blockierte den Audit-Insert deshalb mit Fehler 1452 (`fk_audit_user`). Da der Audit-Eintrag innerhalb der Projekt-Provisionierung ausgeführt wurde, wurde anschließend auch das neue Projekt zurückgerollt.

## Verbindlicher Identitätsvertrag
`audit_log.user_id` verweist ausschließlich auf lokale technische Administratoren in `users.id`. Kundenkonten sind zentrale Lizenzserver-Identitäten und werden nicht in diesen lokalen Foreign Key geschrieben. Für Kundenaktionen bleibt `user_id` daher `NULL`; die zentrale Kunden-ID wird ausschließlich im JSON-Audit-Kontext als `_actor.scope=customer` und `_actor.remote_user_id` protokolliert.

Bei lokalen Administratoren wird die übergebene ID vor dem Audit-Insert gegen `users.id` geprüft. Existiert sie nicht mehr, wird der Audit-Datensatz mit `user_id=NULL` geschrieben und die nicht mehr auflösbare ID unter `_actor.scope=unmapped_local` dokumentiert. Eine veraltete Session kann damit keinen fachlich erfolgreichen Vorgang mehr durch einen Audit-FK-Fehler abbrechen.

## Auswirkung auf die Projekterstellung
Die bestehende Projekt-Provisionierung bleibt unverändert transaktional/rollback-fähig. Nur die Actor-Auflösung des zentralen `enterprise_audit()` wurde korrigiert. Damit können DataForm- und Calendar-Projekte auch aus einem zentral angemeldeten Kundenkonto heraus angelegt werden, ohne `fk_audit_user` zu verletzen.
