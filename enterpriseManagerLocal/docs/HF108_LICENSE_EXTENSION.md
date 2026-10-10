# HF108 – Lizenzerweiterung

HF108 macht die Erweiterung einer bestehenden Kundenlizenz zu einem eigenen, direkt absendbaren Vorgang.

## Enterprise-Manager

- Bei aktiver Lizenz ist `Lizenz erweitern` der Standard-Antragstyp.
- Der Absende-Button steht direkt im sichtbaren oberen Bereich des Formulars und erneut am Formularende.
- Aktuelle Installations- und DataForm-Kontingente werden als Ausgangswerte vorbelegt.
- Bereits lizenzierte Module und Funktionen sind vorbelegt.
- Erweiterte Detailangaben (Capabilities, Domains/IPs, Hinweise) sind in einem aufklappbaren Bereich zusammengefasst.
- Die irreführende Option `Zusätzliche Lizenz` ist bei einer bereits aktiven Lizenz entfernt; Mehrlizenz-Auswahl ist ein eigener späterer Funktionsbereich.
- Nur Kundenadministratoren erhalten ein absendbares Erweiterungsformular.

## Lizenzserver

`request_type=extension` ist strikt additiv:

- max. Installationen können nicht unter den bestehenden Wert sinken,
- max. DataForms können nicht unter den bestehenden Wert sinken,
- bestehende Module bleiben aktiv,
- bestehende Capabilities bleiben aktiv,
- eine bereits unbefristete Lizenz bleibt unbefristet,
- eine zeitlich befristete Verlängerung wird auf das bestehende Lizenzende aufgeschlagen,
- der Superadmin kann einen kleineren Umfang als angefragt genehmigen, aber niemals den bereits aktiven Umfang reduzieren.

Eine normale `change`-Anfrage bleibt weiterhin eine bewusste Neu-Festlegung des Lizenzumfangs.
