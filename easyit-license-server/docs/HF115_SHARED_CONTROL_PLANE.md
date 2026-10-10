# HF115 – gemeinsame easyit_license Control Plane

`easyit_license` ist die gemeinsame zentrale Administrationsdatenbank von Lizenzserver und Enterprise-Manager. Deshalb darf ein destruktiver Lizenzserver-Setup die Datenbank nicht anschließend nur mit den Lizenzserver-Tabellen hinterlassen.

HF115 definiert zwei Schemafamilien, die gemeinsam provisioniert und gemeinsam validiert werden:

- 39 Tabellen des Lizenzservers,
- 13 Tabellen der Enterprise-Manager-Control-Plane.

Der vollständige Gate-Vertrag umfasst damit 52 Pflichttabellen. Zu den Enterprise-Pflichttabellen gehören insbesondere `projects`, `users`, `roles`, `user_roles`, `audit_log` und `enterprise_licenses`. Zusätzlich werden die Kernstrukturen für Produkte, Module und Berechtigungen angelegt.

## Setup

Beim destruktiven Setup werden – entsprechend dem HF114-Vertrag – alle Tabellen der gewählten Zieldatenbank bzw. des PostgreSQL-Schemas gelöscht. Danach werden beide Schemafamilien vollständig neu angelegt. Der Setup-Lock wird erst geschrieben, wenn 52/52 Pflichttabellen real vorhanden sind.

## Reparatur

Bei einer bereits eingerichteten Datenbank ist kein Neuaufbau erforderlich. Der lokale Setup-Bereich bietet `Alle zentralen Tabellen prüfen & reparieren`. Diese Funktion ist nicht destruktiv und ergänzt fehlende Tabellen. Damit kann ein HF114-Stand, der bereits die Lizenzserver-Tabellen besitzt, nach HF115 um die fehlende Enterprise-Control-Plane ergänzt werden, ohne die Lizenzserverdaten erneut zu löschen.

## Folge nach einem absichtlichen Neuaufbau

Da der Neuaufbau alle Tabellen und Daten löscht, existiert anschließend noch kein Enterprise-Superadministrator und keine bestehende Lizenz. Der Enterprise-Manager führt deshalb nach dem Neuaufbau erneut durch die Administratoranlage; Kunden, Lizenzen, Aktivierungen und Projekte müssen ebenfalls neu angelegt bzw. wiederhergestellt werden.
