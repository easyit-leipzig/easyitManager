# HF108 – Additive Lizenzerweiterung

HF108 führt für bestehende Kundenlizenzen einen technisch eigenständigen Erweiterungspfad ein.

Bei `request_type=extension` werden bestehende Module, Capabilities und Kontingente serverseitig mit dem beantragten Umfang vereinigt. Weder ein älterer Client noch eine versehentlich abgewählte Admin-Checkbox kann damit bestehende Freigaben entfernen. Bei befristeten Lizenzen wird die beantragte Verlängerungsdauer vom bestehenden Ablaufdatum aus berechnet. Unbefristete Lizenzen bleiben unbefristet.

Der normale Änderungspfad `request_type=change` behält seine bisherige Ersatzsemantik.
