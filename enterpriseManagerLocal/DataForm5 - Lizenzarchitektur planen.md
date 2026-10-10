---
title: "DataForm5 - Lizenzarchitektur planen"
source: "https://chatgpt.com/g/g-p-6a69de4fa8b48191b8e73cacad0a5911/c/6aa953b7-2d74-83eb-be35-33902260e2c9"
---

**You:**

die kundendaten sind mir irgendwie zuwenig. es muss klar sein von welchen domänen/ip die oberfläche heraus erscheinen darf. zusätzlich muss es eine oberfläche im magaer geben, in der der kunde seine eigenen daten pflegen kann.

* * *

**ChatGPT:**

Umgesetzt. Der neue Stand ist **HF83 – Customer Surface & Portal**.

Die Kundenverwaltung enthält jetzt deutlich mehr Stammdaten: rechtlicher Firmenname, Ansprechpartner, allgemeine E-Mail, Telefon/Mobil, Website, vollständige Anschrift, Land/Region, USt-ID, Rechnungs-E-Mail, technische E-Mail und interne Notizen.

Zusätzlich gibt es jetzt pro Kunde eine echte **Herkunftsrichtlinie**. Du kannst festlegen, welche Domains und welche IP-Adressen bzw. CIDR-Netze zugelassen sind, z. B.:

```
easyit-kunde.de
*.easyit-kunde.de

203.0.113.17
203.0.113.0/24
2001:db8::/48
```

Die Prüfung erfolgt serverseitig bei Runtime-Start, Runtime-Renew, Runtime-Aktionen und Designer-Aufrufen. Der Enterprise-Client sendet dazu den aktuellen Surface-Origin signiert mit.

Im Superadmin findest du beim Kunden jetzt den Bereich:

```
Domains / IP / Kundenlogin
```

Dort kannst du erlaubte Domains und IP-Netze pflegen und Kundenportal-Benutzer anlegen.

Zusätzlich gibt es die Option:

```
Kunde darf Domains/IPs im Kundenportal selbst pflegen
```

Damit entscheidest **du**, ob der Kunde nur sehen oder auch selbst ändern darf.

### Kundenportal

Neu ist:

```
https://easyit-leipzig.de/enterpriseManager/easyit-license-server/customer/
```

Dort kann der Kunde ausschließlich seine eigenen Daten verwalten:

* eigene Stammdaten
* Ansprechpartner und Kontaktdaten
* Rechnungs-/Technik-E-Mail
* Anschrift
* eigenes Kennwort
* eigene Lizenzen
* verwendete DataForms
* freigegebene Domains/IPs
* Domains/IPs selbst ändern, sofern du dies erlaubt hast

Der Kunde sieht keine anderen Kunden und hat keinen Zugriff auf die Superadmin-Verwaltung.

### Wichtig beim Update

Da die Domainprüfung nun produktiv eingebunden ist, gilt für bestehende Kunden standardmäßig:

```
Domainprüfung = erforderlich
```

Du solltest deshalb vor dem ersten Runtime-Test beim Kunden mindestens eine erlaubte Domain hinterlegen, beispielsweise:

```
localhost
```

für lokale Tests oder die tatsächliche Produktivdomain.

### Server-Update für deinen bestehenden Stand

Dieses Paket über deinen vorhandenen Lizenzserver kopieren. `config/app.php` und `secure/` werden nicht ersetzt:

HF83 Server-Update

Vollständiger Serverstand:

HF83 vollständiger Lizenzserver

### Enterprise-Seite ebenfalls aktualisieren

Damit der lokale Enterprise-Manager seine aktuelle Domain an den Lizenzserver übermittelt, muss zusätzlich dieses kleine Update eingespielt werden:

HF83 Enterprise Surface Update

Deine vorhandene `config/licensing.php` wird dabei absichtlich nicht überschrieben.

Normalerweise wird die Domain automatisch erkannt. Für Sonderfälle, etwa Reverse Proxy oder CLI, kannst du optional setzen:

```PHP
'surface_origin' => 'https://kunde.example.de',
```

### Prüfungen

Alle Prüfungen sind bestanden:

```
36 Server-PHP-Dateien          PASS
836 Enterprise-PHP-Dateien     PASS
Runtime-Gate                   PASS
License-Policy-Gate            PASS
Admin-Web-Gate                 PASS
Customer-Surface-Gate          PASS
ZIP-Integrität                 PASS
```

Validierungsbericht HF83

SHA-256-Prüfsummen

Der nächste notwendige Schritt ist dann die von dir zuvor geforderte **individuelle DF-Token-Lizenzierung**: jedes neu angelegte DataForm bekommt eine eigene unveränderliche DF-ID und einen eigenen Lizenzstatus, und beim Löschen wird der Lizenzplatz serverseitig wieder freigegeben.

HF83_VALIDATION.txt

HF83_SHA256.txt

* * *

**You:**

der vorgang müsste wie folgt aussehen->es wird das dashboard [domain]/enterpriseManager/ aufgerufen und eine anmeldung gefordert. die anmeldung muss auf der kunden-email beruhen, ist ein konto vorhanden, wird der manager geöffnet und entsprechend der vergebenen lizenz konfiguriert, ist noch kein konto vorhanden, muss eines angefordert werden in dr 1 anmeldemaske, die lizenz wird manell durch den superadmin freigegeben

* * *

**ChatGPT:**

Ja. Das ist der sauberere Gesamtfluss. Der bisherige separate Einstieg über `/customer/` sollte damit **nicht mehr der primäre Kundenweg** sein. Der gesamte Kundenprozess beginnt unter:

```
https://[domain]/enterpriseManager/
```

Dort erscheint immer zuerst eine gemeinsame Anmeldemaske.

Der Ablauf sollte verbindlich so aussehen:

```
[domain]/enterpriseManager/
        ↓
Anmeldung mit Kunden-E-Mail
        │
        ├── Konto vorhanden + freigegeben
        │       ↓
        │   Passwort prüfen
        │       ↓
        │   Lizenzserver prüfen
        │       ↓
        │   Lizenz gültig?
        │       ↓
        │   Enterprise-Manager öffnen
        │       ↓
        │   Funktionen entsprechend Lizenz
        │
        └── Konto nicht vorhanden
                ↓
        „Zugang anfordern“
                ↓
        Antrag erfassen
                ↓
        Status = pending
                ↓
        Superadmin prüft
                ↓
        Kunde + Lizenz manuell freigeben
                ↓
        erst danach produktiver Login
```

Wichtig ist: **Die Registrierung erzeugt niemals automatisch eine aktive Lizenz.**

Die erste Maske sollte daher zwei Bereiche besitzen:

```
Anmelden

E-Mail-Adresse
Passwort

[Anmelden]

Noch kein Zugang?
[Zugang anfordern]
```

Bei `Zugang anfordern` würde ich nur die notwendigen Daten erfassen:

```
E-Mail-Adresse
Firmenname
Vorname
Nachname
Telefon optional

gewünschte Domain
optional Bemerkung
```

und danach:

```
Anfrage erfolgreich übermittelt.
Ihr Zugang muss zunächst durch den Administrator freigegeben werden.
```

Der neue Datensatz bekommt:

```
account_status = pending
license_status = none
```

Nicht:

```
active
```

## Zustände des Kundenkontos

Ich würde verbindlich folgende Kontozustände verwenden:

```
pending
active
blocked
rejected
```

Zusätzlich getrennt davon die Lizenz:

```
none
active
expired
suspended
revoked
```

Damit kann beispielsweise ein Benutzerkonto weiterhin existieren, während die Lizenz abgelaufen ist.

## Superadmin-Ablauf

Im zentralen Lizenzmanager erscheint dann beispielsweise:

```
Neue Zugangsanforderungen

max@example.de
Muster GmbH
angefordert: 17.09.2026

[Prüfen]
```

Der Superadmin öffnet den Antrag und legt fest:

```
Kunde
Domain/IP-Regeln
Anzahl Installationen
Anzahl DataForms

CRUD:
Read
Create
Update
Delete

Suche
Filter
Relationen
Events
Designer
Export
API
...
```

Dann:

```
[Freigeben und Lizenz erzeugen]
```

Erst dabei wird:

```
account_status = active
license_status = active
```

gesetzt.

Optional kann dabei auch sofort festgelegt werden:

```
max_dataforms = 10
```

und die Capability-Liste.

## Anmeldung nach Freigabe

Beim nächsten Login mit der Kunden-E-Mail:

```
E-Mail
        ↓
Konto aktiv?
        ↓
Passwort korrekt?
        ↓
Lizenzserver erreichbar?
        ↓
Lizenz aktiv?
        ↓
Domain/IP erlaubt?
        ↓
Installation gültig?
        ↓
Manager öffnen
```

Der Manager darf dabei nicht einfach alle Menüs laden und nur Buttons verstecken.

Stattdessen liefert der Lizenzserver eine effektive Policy:

```JSON
{
  "max_dataforms": 10,
  "capabilities": {
    "dataset.read": true,
    "dataset.create": true,
    "dataset.update": true,
    "dataset.delete": false,
    "dataform.search": true,
    "dataform.filter": true,
    "designer.publish": false,
    "export.execute": false
  }
}
```

Daraus wird der Manager serverseitig aufgebaut.

Beispielsweise:

```
Projektverwaltung       sichtbar
DataForms               sichtbar
Designer                nicht sichtbar
Export                   nicht sichtbar
API                      nicht sichtbar
```

und innerhalb eines DataForms:

```
Neu          ja
Bearbeiten   ja
Löschen      nein
Suche        ja
Filter       ja
```

## Kundendatenpflege

Die Pflege der eigenen Daten gehört dann ebenfalls **in den normalen Manager**.

Zum Beispiel:

```
Mein Konto
├── Stammdaten
├── Ansprechpartner
├── Rechnungsdaten
├── technische Kontakte
├── Domains / IPs
├── Lizenz
├── Installationen
└── DataForms
```

Dort sieht der Kunde ausschließlich seinen eigenen Mandanten.

Das separate:

```
/easyit-license-server/customer/
```

kann damit entweder entfallen oder nur noch als technischer Legacy-/Support-Einstieg bestehen.

## DataForm-Lizenzierung passt genau hinein

Wenn der Kunde ein neues DataForm anlegt:

```
DataForm anlegen
      ↓
df_uuid erzeugen
      ↓
Status = unlicensed/pending
      ↓
Lizenzserver registriert DF
      ↓
freier DF-Platz vorhanden?
```

Je nach gewünschtem Modell kann dann entweder sofort innerhalb der bestehenden Lizenzkapazität freigegeben werden oder – wenn du jede einzelne DF-Freigabe manuell möchtest – erst nach Superadmin-Bestätigung.

Nach deiner bisherigen Vorgabe würde ich es so machen:

```
DataForm erstellt
      ↓
erscheint im Lizenzmanager
      ↓
Superadmin:
„DataForm freigeben“
      ↓
signierter DF-Lizenztoken
      ↓
DataForm produktiv nutzbar
```

Beim Löschen:

```
DataForm löschen
      ↓
DF-Token widerrufen
      ↓
Lizenzplatz wird frei
      ↓
z. B. 10/10 → 9/10
```

Dann kann ein neues DataForm wieder freigegeben werden.

## Konsequenz für den bestehenden Stand

Der nächste Umbau sollte daher nicht am Lizenzserver beginnen, sondern am **Root von `enterpriseManager`**:

```
/enterpriseManager/index.php
```

Dieser Einstieg wird zum zentralen Auth-Gateway.

Der bisherige Ablauf:

```
Enterprise öffnen
→ eventuell Adminlogin
→ Projekte
```

wird ersetzt durch:

```
Enterprise öffnen
→ E-Mail-Login / Zugang anfordern
→ Lizenzprüfung
→ kundenspezifischer Manager
```

Der Superadmin bleibt davon getrennt und verwaltet zentral:

```
Anträge
Kunden
Konten
Lizenzen
Domains/IPs
Installationen
DataForms
Capabilities
Audit
```

Das wäre der richtige nächste kumulative Stand: **HF84 – Unified Customer Login & Manual License Approval**.

* * *

**You:**

umsetzen (auch phasenweise bei zu umfangreichen änderungen)

* * *

**ChatGPT:**

Umgesetzt als **HF84 – Unified Customer Login & Manual Approval**. Wegen des Umfangs habe ich die ersten beiden Phasen zusammen umgesetzt; die individuelle DF-Token-Vergabe bleibt der nächste getrennte Schritt.

Der neue Ablauf ist jetzt:

```
[domain]/enterpriseManager/
        ↓
Kunden-E-Mail + Kennwort
        │
        ├─ Konto aktiv + Lizenz aktiv
        │      ↓
        │   Domain prüfen
        │      ↓
        │   Lizenz laden
        │      ↓
        │   Enterprise-Manager öffnen
        │
        └─ noch kein Konto
               ↓
          Zugang anfordern
               ↓
          status = pending
               ↓
          Superadmin → Anfragen
               ↓
          manuelle Prüfung
               ↓
          Kunde + Konto + Lizenz erzeugen
               ↓
          Anmeldung möglich
```

### 1. Zuerst Lizenzserver aktualisieren

Für deinen bereits laufenden Lizenzserver:

HF84 Lizenzserver-Update

Das Update überschreibt **nicht**:

```
config/app.php
secure/
Server-Schlüssel
```

Beim nächsten Admin- oder API-Aufruf werden automatisch ergänzt:

```
customer_access_requests
customer_account_sessions

customer_users.email
customer_users.license_id
```

Danach erscheint im Lizenzmanager zusätzlich:

```
Dashboard
Anfragen        ← NEU
Kunden
Lizenzen
Installationen
Audit
```

Unter **Anfragen** sieht der Superadmin jede neue Kundenanforderung und vergibt dort manuell:

* erlaubte Domain
* maximale Installationen
* maximale DataForms
* Gültigkeit
* DataForm-Modul
* CRUD
* Suche/Filter/Paginierung
* Relationen
* Designer und weitere Capabilities

Erst **„Freigeben und Lizenz erzeugen“** aktiviert Konto und Lizenz.

### 2. Danach Enterprise-Manager aktualisieren

HF84 Enterprise-Manager Update

Das Paket überschreibt ausdrücklich **nicht**:

```
config/licensing.php
```

Deine bestehende Verbindung zum Lizenzserver bleibt also erhalten.

Danach ist:

```
https://kunde.example.de/enterpriseManager/
```

die Kundenanmeldung.

Die bisherige lokale technische Administration ist weiterhin erreichbar unter:

```
https://kunde.example.de/enterpriseManager/admin-login.php
```

Sie ist damit vom Kundenlogin getrennt.

### Zugang anfordern

Auf der ersten Seite gibt es nun gleichzeitig:

```
ANMELDEN
Kunden-E-Mail
Kennwort
[Anmelden]
```

und:

```
NOCH KEIN ZUGANG?

E-Mail
Firma
Vorname
Nachname
Telefon
Domain
Kennwort
Kennwort wiederholen

[Zugang anfordern]
```

Der Antrag legt **keine automatische Lizenz** an.

Der Benutzer erhält nur:

```
Ihre Zugangsanforderung wurde gespeichert.
Sie können den Enterprise-Manager erst nach
der manuellen Freigabe durch den Superadmin verwenden.
```

### Nach der Freigabe

Beim Login werden zentral geprüft:

```
Konto aktiv?
Lizenz zugeordnet?
Lizenz aktiv?
Lizenz nicht abgelaufen?
Domain freigegeben?
IP-Regel erfüllt?
```

Erst danach wird der Manager geöffnet.

Der Kundenmanager ist bereits reduziert auf:

```
Dashboard
Projekte
Mein Konto
Abmelden
```

Die technischen Superadmin-Bereiche wie:

```
Lizenzen
Benutzer & Rechte
DB-Benutzer
Audit
Module
Betrieb
Setup
DB-Assistent
Developer
```

werden Kunden nicht mehr in der Navigation angeboten.

### „Mein Konto“

Neu im normalen Enterprise-Manager:

```
Enterprise → Mein Konto
```

Der Kunde kann dort seine eigenen Daten pflegen:

* Firmen-/Stammdaten
* Ansprechpartner
* Telefon/Mobil
* Website
* Anschrift
* Rechnungs-E-Mail
* technische E-Mail
* USt-ID
* Lizenzstatus
* DataForm-Nutzung
* Module
* Domains

Ist vom Superadmin:

```
Kunde darf Domains selbst pflegen = JA
```

gesetzt, kann er dort Domains selbst hinzufügen und entfernen.

### Zentrale Account-Session

Nach erfolgreicher Anmeldung erzeugt der Lizenzserver jetzt zusätzlich eine zeitlich begrenzte zentrale Kunden-Session. Das Enterprise-System speichert deshalb nicht einfach nur lokal:

```
kunde = angemeldet
```

sondern besitzt eine vom zentralen Server ausgegebene Account-Sitzung. Profiländerungen und Domainänderungen werden wieder über den zentralen Server ausgeführt.

### Pakete

Für ein Update deiner bestehenden Installation brauchst du nur diese beiden:

HF84 Lizenzserver-Update

HF84 Enterprise-Update

Für eine vollständige neue Lizenzserver-Installation:

HF84 vollständiger Lizenzserver

Validierungsbericht HF84

SHA-256-Prüfsummen

Geprüft wurden **38 Server-PHP-Dateien** und **838 Enterprise-PHP-Dateien**, jeweils ohne PHP-Syntaxfehler. Alle drei ZIPs bestehen den Integritätstest.

### Noch nicht Bestandteil von HF84

Die bereits festgelegte **individuelle DataForm-Token-Lizenzierung** ist bewusst die nächste Phase. Der nächste Stand muss daher:

```
DataForm anlegen
→ dauerhafte DF-UUID
→ zunächst pending/unlicensed
→ im Lizenzmanager einzeln freigeben
→ signierter DF-Lizenztoken
→ Lizenzplatz belegt

DataForm löschen
→ DF-Token widerrufen
→ Lizenzplatz freigeben
→ neues DF kann freigegeben werden
```

umsetzen. Das wird **HF85 – Individual DataForm License Token**.

HF84_UNIFIED_CUSTOMER_LOGIN_VALIDATION.txt

HF84_SHA256.txt

* * *

**You:**

weiter

* * *

**ChatGPT:**

Ich gehe mit **HF85 – Individual DataForm License Token** weiter. Ziel dieser Phase: Jedes angelegte DataForm bekommt eine dauerhafte DF-UUID, ist zunächst `pending/unlicensed`, erscheint im zentralen Lizenzmanager zur manuellen Freigabe und erhält erst danach einen signierten DF-Lizenztoken. Beim Löschen wird genau dieser Platz wieder freigegeben.

Untersuchte Dataform-, Lizenzierungs- und Verwaltungslogik einschließlich Token- und Löschabläufen

Ich setze die Tokenlogik bewusst **nicht** als automatische Platzbelegung beim ersten Öffnen um. Das wäre gegen deine Vorgabe. Ab HF85 gilt daher: `DataForm angelegt → pending → Superadmin gibt genau dieses DF frei → erst dann Runtime/Publish`. Ein Löschen wird zuerst dem Lizenzserver bestätigt; danach wird der Platz freigegeben und erst dann lokal gelöscht.

Erstellen der HF85-Arbeitskopie

* * *

**You:**

nochmal

* * *

**ChatGPT:**

Prüfen der Admin-Datenform-Navigation

* * *

**ChatGPT:**

Ich setze HF85 noch einmal **vollständig und sauber** auf dem HF84-Stand um. Diesmal schließe ich alle vier Teile ab: dauerhafte DF-UUID im Kundenprojekt, Registrierung als `pending`, manuelle Freigabe im Lizenzmanager mit signiertem DF-Token sowie Freigabe des Lizenzplatzes beim Löschen. Danach liefere ich dir Update-Pakete für Lizenzserver und Enterprise.

Prüfen und Beheben der AdminApp-Aktionslogik