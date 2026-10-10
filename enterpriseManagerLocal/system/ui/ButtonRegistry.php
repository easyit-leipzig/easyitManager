<?php
declare(strict_types=1);

/**
 * easyIT Enterprise button registry.
 *
 * HF76-FIX10: the supplied buttonset is the only visual source for action
 * buttons. Button colours/gradients are forbidden. Titles, image mapping and
 * semantic aliases are centrally defined here.
 */
function easyit_button_registry(): array
{
    static $registry = [
        'neu' => ['title' => 'Neu anlegen', 'titles' => ['dataform' => 'Neues DataForm anlegen', 'form' => 'Neues Formular anlegen', 'table' => 'Neue Tabelle anlegen', 'record' => 'Neuen Datensatz anlegen', 'project' => 'Neues Projekt anlegen', 'relation' => 'Neue Beziehung anlegen', 'setup_db_create' => 'Datenbanken anlegen'], 'image' => 'assets/img/neu.png', 'aliases' => ['neu', 'new', 'create', 'add', 'anlegen', 'erstellen', 'hinzufügen', 'hinzufuegen', 'neuer datensatz', 'datensatz anlegen', 'neues projekt anlegen', 'projekt anlegen', 'projekt vollständig anlegen', 'projekt vollstaendig anlegen', 'lizenz anlegen', 'rolle anlegen', 'benutzer anlegen', 'status anlegen', 'übergang anlegen', 'uebergang anlegen', 'aktion hinzufügen', 'aktion hinzufuegen', 'element hinzufügen', 'element hinzufuegen', 'kinddatensatz', 'neue datenquelle', 'dataform aus tabelle erstellen', 'feld hinzufügen', 'feld hinzufuegen', 'neues feld', 'tabelle anlegen', 'dataform erzeugen', 'dataform anlegen', 'bericht anlegen', 'anwendung anlegen', 'navigation hinzufügen', 'navigation hinzufuegen', 'widget hinzufügen', 'widget hinzufuegen', 'api anlegen', 'endpunkt anlegen', 'schlüssel erzeugen', 'schluessel erzeugen', 'job einreihen']],
        'bearbeiten' => ['title' => 'Bearbeiten', 'titles' => ['record' => 'Datensatz bearbeiten', 'dataform' => 'DataForm bearbeiten', 'project' => 'Projekt bearbeiten'], 'image' => 'assets/img/bearbeiten.png', 'aliases' => ['bearbeiten', 'edit', 'update', 'ändern', 'aendern', 'datensatz bearbeiten', 'felder verwalten', 'benutzer verwalten', 'rollen verwalten', 'grafischer designer', 'workflow']],
        'loeschen' => ['title' => 'Löschen', 'titles' => ['record' => 'Datensatz löschen', 'bulk' => 'Ausgewählte Datensätze löschen', 'dataform' => 'DataForm löschen', 'project' => 'Projekt löschen', 'filter' => 'Filter löschen'], 'image' => 'assets/img/loeschen.png', 'aliases' => ['löschen', 'loeschen', 'delete', 'remove', 'drop', 'entfernen', 'ausgewählte löschen', 'ausgewaehlte loeschen', 'entziehen', 'widerrufen', 'tabelle löschen', 'tabelle loeschen', 'bericht löschen', 'bericht loeschen', 'benutzer löschen', 'benutzer loeschen', 'rolle löschen', 'rolle loeschen']],
        'speichern' => ['title' => 'Änderungen speichern', 'titles' => ['record' => 'Datensatz speichern', 'filter' => 'Filter speichern', 'settings' => 'Einstellungen speichern'], 'image' => 'assets/img/speichern.png', 'aliases' => ['speichern', 'save', 'submit', 'übernehmen', 'uebernehmen', 'anwenden', 'profil speichern', 'listenansicht speichern', 'datensatz speichern', 'änderungen speichern', 'aenderungen speichern', 'konfiguration speichern', 'vertrauen speichern', 'rolle speichern', 'snapshot speichern', 'speicherverhalten speichern', 'filter speichern', 'datenquelle speichern', 'eigenschaften speichern', 'positionen speichern']],
        'rueckgaengig' => ['title' => 'Rückgängig', 'titles' => ['import' => 'Importlauf zurücknehmen'], 'image' => 'assets/img/rueckgaengig.png', 'aliases' => ['rückgängig', 'rueckgaengig', 'undo', 'zurücknehmen', 'zuruecknehmen', 'rollback', 'rückgängig machen', 'rueckgaengig machen', 'importlauf zurücknehmen', 'importlauf zuruecknehmen', 'änderung zurücknehmen', 'aenderung zuruecknehmen']],
        'wiederholen' => ['title' => 'Wiederholen', 'image' => 'assets/img/wiederholen.png', 'aliases' => ['wiederholen', 'redo', 'erneut ausführen', 'erneut ausfuehren', 'nochmals ausführen', 'nochmals ausfuehren', 'wiederholen lassen']],
        'zurueck' => ['title' => 'Zurück', 'image' => 'assets/img/zurueck.png', 'aliases' => ['zurück', 'zurueck', 'back', 'zurück zu schritt', 'zurueck zu schritt', 'zurück zum tutorial', 'zurueck zum tutorial', 'zurück zum dashboard', 'zurueck zum dashboard', 'zurück zur startseite', 'zurueck zur startseite', 'zurück zum developer dashboard', 'zurueck zum developer dashboard', 'zurück zum eltern-datensatz', 'zurueck zum eltern-datensatz', 'zur projektliste', 'zur projektverwaltung', 'zur dataform-liste', 'zur datensatzliste', 'zur startseite']],
        'weiter' => ['title' => 'Weiter', 'image' => 'assets/img/weiter.png', 'aliases' => ['weiter', 'next', 'nächster schritt', 'naechster schritt', 'weiter zu schritt', 'fortsetzen', 'administrator anlegen']],
        'projekt_registrieren' => ['title' => 'Vorhandenes Projekt registrieren', 'image' => 'assets/img/projekt_registrieren.png', 'aliases' => ['vorhandenes projekt registrieren', 'projekt registrieren']],
        'setup' => ['title' => 'Installation / Setup öffnen', 'image' => 'assets/img/setup.png', 'aliases' => ['installation / setup', 'installation / setup öffnen', 'setup öffnen']],
        'datenbank_assistent' => ['title' => 'Datenbank-Assistent öffnen', 'image' => 'assets/img/datenbank_assistent.png', 'aliases' => ['datenbank-assistent', 'datenbank-assistent öffnen']],
        'security_benutzer' => ['title' => 'Benutzer verwalten', 'image' => 'assets/img/security_benutzer.png', 'aliases' => ['benutzer verwalten', 'benutzer']],
        'database_benutzer' => ['title' => 'Datenbank-Benutzer verwalten', 'image' => 'assets/img/security_benutzer.png', 'aliases' => ['datenbank-benutzer', 'db-benutzer', 'datenbank-benutzer verwalten']],
        'security_rollen' => ['title' => 'Rollen verwalten', 'image' => 'assets/img/security_rollen.png', 'aliases' => ['rollen verwalten', 'rollen']],
        'security_capabilities' => ['title' => 'Capabilities verwalten', 'image' => 'assets/img/security_capabilities.png', 'aliases' => ['capabilities', 'capability-matrix']],
        'system_reparieren' => ['title' => 'Systemkonfiguration prüfen und reparieren', 'image' => 'assets/img/system_reparieren.png', 'aliases' => ['datenbanken / .env prüfen und reparieren', 'datenbanken / .env pruefen und reparieren']],
        'auswahl_alle' => ['title' => 'Alle auswählen', 'image' => 'assets/img/auswahl_alle.png', 'aliases' => ['alle auswählen', 'alle auswaehlen']],
        'auswahl_keine' => ['title' => 'Auswahl aufheben', 'image' => 'assets/img/auswahl_keine.png', 'aliases' => ['keine auswählen', 'keine auswaehlen', 'auswahl aufheben']],
        'export_csv' => ['title' => 'Als CSV exportieren', 'image' => 'assets/img/export_csv.png', 'aliases' => ['csv exportieren', 'als csv exportieren']],
        'export_word' => ['title' => 'Als Word exportieren', 'image' => 'assets/img/export_word.png', 'aliases' => ['word exportieren', 'als word exportieren']],
        'export_html' => ['title' => 'Als HTML exportieren', 'image' => 'assets/img/export_html.png', 'aliases' => ['html exportieren', 'als html exportieren']],
        'export_json' => ['title' => 'Als JSON exportieren', 'image' => 'assets/img/export_json.png', 'aliases' => ['json exportieren', 'als json exportieren']],

        'abbrechen' => ['title' => 'Vorgang abbrechen', 'image' => 'assets/img/abbrechen.png', 'aliases' => ['abbrechen', 'cancel', 'vorschau verwerfen', 'vorbereitung verwerfen', 'verwerfen']],
        'anzeigen' => ['title' => 'Anzeigen', 'titles' => ['password_toggle' => 'Kennwort anzeigen oder verbergen', 'password_show' => 'Passwort anzeigen', 'password_hide' => 'Passwort verbergen', 'record' => 'Datensatz anzeigen', 'project' => 'Projekt anzeigen', 'dataform_records' => 'Datensätze anzeigen', 'details' => 'Details anzeigen', 'overview' => 'Übersicht anzeigen', 'audit_newer' => 'Neuere Audit-Einträge anzeigen', 'audit_older' => 'Ältere Audit-Einträge anzeigen'], 'image' => 'assets/img/anzeigen.png', 'aliases' => ['anzeigen', 'show', 'view', 'detail', 'details', 'vorschau', 'datensätze anzeigen', 'datensaetze anzeigen', 'öffnen', 'oeffnen', 'details öffnen', 'details oeffnen', 'datensätze', 'datensaetze', 'projektübersicht', 'projektuebersicht', 'betriebszentrale', 'service container inspector', 'event inspector', 'hook inspector', 'profiler', 'sdk-konsole', 'test center', 'dependency injection']],
        'kopieren' => ['title' => 'Kopieren', 'image' => 'assets/img/kopieren.png', 'aliases' => ['kopieren', 'copy']],
        'duplizieren' => ['title' => 'Duplizieren', 'image' => 'assets/img/duplizieren.png', 'aliases' => ['duplizieren', 'duplicate', 'clone']],
        'schliessen' => ['title' => 'Ansicht schließen', 'image' => 'assets/img/schliessen.png', 'aliases' => ['schließen', 'schliessen', 'close', 'details schließen', 'details schliessen', '×']],
        'mehr' => ['title' => 'Weitere Aktionen anzeigen', 'image' => 'assets/img/mehr.png', 'aliases' => ['mehr', 'more', 'weitere aktionen']],
        'suchen' => ['title' => 'Suchen', 'titles' => ['setup_db_test' => 'Datenbankverbindung prüfen'], 'image' => 'assets/img/suchen.png', 'aliases' => ['suchen', 'search', 'volltextsuche', 'prüfen', 'pruefen', 'system prüfen', 'system pruefen', 'systemprüfung', 'systempruefung', 'verbindung testen', 'datenbanken / .env prüfen und reparieren', 'datenbanken / .env pruefen und reparieren', 'paket prüfen', 'paket pruefen', 'duplikate prüfen', 'duplikate pruefen', 'datei prüfen und vorschau erzeugen', 'datei pruefen und vorschau erzeugen']],
        'filter' => ['title' => 'Filter anwenden', 'image' => 'assets/img/filter.png', 'aliases' => ['filter', 'filter anwenden', 'filtern', 'filterzeile']],
        'filter_loeschen' => ['title' => 'Filter zurücksetzen', 'image' => 'assets/img/filter_loeschen.png', 'aliases' => ['filter löschen', 'filter loeschen', 'filter zurücksetzen', 'filter zuruecksetzen', 'zurücksetzen', 'zuruecksetzen', 'reset', 'setup-zustand zurücksetzen', 'setup-zustand zuruecksetzen']],
        'aktualisieren' => ['title' => 'Ansicht aktualisieren', 'image' => 'assets/img/aktualisieren.png', 'aliases' => ['aktualisieren', 'refresh', 'reload', 'neu laden', 'snapshot aktualisieren', 'neu einlesen', 'neue events einlesen', 'retry']],
        'sortieren_auf' => ['title' => 'Aufsteigend sortieren', 'image' => 'assets/img/sortieren_auf.png', 'aliases' => ['aufsteigend', 'sortieren auf', 'sort asc', 'ascending', 'nach oben', '↑', 'hoch']],
        'sortieren_ab' => ['title' => 'Absteigend sortieren', 'image' => 'assets/img/sortieren_ab.png', 'aliases' => ['absteigend', 'sortieren ab', 'sort desc', 'descending', 'nach unten', '↓', 'runter']],
        'spalten' => ['title' => 'Spalten auswählen', 'image' => 'assets/img/spalten.png', 'aliases' => ['spalten', 'columns', 'listenspalten']],
        'tabelle' => ['title' => 'Tabellenansicht öffnen', 'image' => 'assets/img/tabelle.png', 'aliases' => ['tabelle', 'table', 'tabellenansicht']],
        'formular' => ['title' => 'Formular öffnen', 'titles' => ['dataform' => 'DataForm öffnen'], 'image' => 'assets/img/formular.png', 'aliases' => ['formular', 'form', 'formularansicht', 'formular-designer', 'designer', 'dataform öffnen', 'dataform oeffnen', 'form öffnen', 'form oeffnen', 'formular gestalten', 'zugehöriges dataform öffnen', 'zugehoeriges dataform oeffnen', 'dataforms']],
        'importieren' => ['title' => 'Daten importieren', 'image' => 'assets/img/importieren.png', 'aliases' => ['importieren', 'import', 'csv-import', 'datei prüfen und vorschau erzeugen', 'datei pruefen und vorschau erzeugen', 'geprüftes paket importieren', 'geprueftes paket importieren', 'geprüftes paket installieren', 'geprueftes paket installieren', 'import jetzt ausführen', 'import jetzt ausfuehren', 'zip installieren', 'update installieren'], 'titles' => ['package' => 'Paket importieren']],
        'exportieren' => ['title' => 'Daten exportieren', 'image' => 'assets/img/exportieren.png', 'aliases' => ['exportieren', 'export', 'csv', 'als csv', 'gefilterte treffer als csv', 'änderungsprotokoll als csv', 'aenderungsprotokoll als csv', 'fehlerbericht als csv', 'word', 'html', 'json', 'openapi json', 'build-zip erzeugen'], 'titles' => ['report' => 'Bericht exportieren', 'project_package' => 'HTML5-Anwenderanwendung exportieren']],
        'hochladen' => ['title' => 'Datei hochladen', 'image' => 'assets/img/hochladen.png', 'aliases' => ['hochladen', 'upload']],
        'herunterladen' => ['title' => 'Datei herunterladen', 'image' => 'assets/img/herunterladen.png', 'aliases' => ['herunterladen', 'download', 'fehlerbericht als csv herunterladen']],
        'drucken' => ['title' => 'Ansicht drucken', 'image' => 'assets/img/drucken.png', 'aliases' => ['drucken', 'print', 'druck-/pdf-vorschau', 'drucken / als pdf speichern']],
        'erster_ds' => ['title' => 'Zum ersten Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/erster_ds.png', 'aliases' => ['erster ds', '1. ds', 'erster datensatz', 'first record']],
        'vorheriger_ds' => ['title' => 'Zum vorherigen Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/vorheriger_ds.png', 'aliases' => ['vorheriger ds', 'vorheriger datensatz', 'previous record', 'zurück blättern', 'zurueck blaettern']],
        'aktueller_ds' => ['title' => 'Aktueller Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/aktueller_ds.png', 'aliases' => ['aktueller ds', 'aktueller datensatz', 'current record']],
        'naechster_ds' => ['title' => 'Zum nächsten Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/naechster_ds.png', 'aliases' => ['nächster ds', 'naechster ds', 'nächster datensatz', 'naechster datensatz', 'next record']],
        'letzter_ds' => ['title' => 'Zum letzten Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/letzter_ds.png', 'aliases' => ['letzter ds', 'letzter datensatz', 'last record']],
        'neuer_ds' => ['title' => 'Neuer Datensatz', 'scope' => 'record_navigation', 'image' => 'assets/img/neuer_ds.png', 'aliases' => ['neuer ds', 'neuer datensatz']],
        'normaler_ds' => ['title' => 'Datensatz auswählen', 'scope' => 'record_navigation', 'image' => 'assets/img/normaler_ds.png', 'aliases' => ['normaler ds', 'normaler datensatz', 'platzhalter']],
        'auswaehlen' => ['title' => 'Auswählen', 'titles' => ['project_switch' => 'Projekt wechseln'], 'image' => 'assets/img/auswaehlen.png', 'aliases' => ['auswählen', 'auswaehlen', 'select', 'wählen', 'waehlen', 'alle', 'keine', 'alle gruppen', 'alle kategorien', 'projekt wechseln']],
        'beziehung' => ['title' => 'Beziehungen anzeigen', 'image' => 'assets/img/beziehung.png', 'aliases' => ['beziehung', 'beziehungen', 'relation', 'relations']],
        'beziehung_neu' => ['title' => 'Beziehung anlegen', 'image' => 'assets/img/beziehung_neu.png', 'aliases' => ['beziehung anlegen', 'beziehung neu', 'create relation', 'relation anlegen']],
        'beziehung_loeschen' => ['title' => 'Beziehung entfernen', 'image' => 'assets/img/beziehung_loeschen.png', 'aliases' => ['beziehung löschen', 'beziehung loeschen', 'beziehung entfernen', 'delete relation', 'remove relation']],
        'lookup' => ['title' => 'Referenz auswählen', 'image' => 'assets/img/lookup.png', 'aliases' => ['lookup', 'referenz auswählen', 'referenz auswaehlen', 'lookup-datensatz wählen', 'lookup-datensatz waehlen']],
        'backup' => ['title' => 'Datenbank sichern', 'image' => 'assets/img/backup.png', 'aliases' => ['backup', 'sichern', 'datenbank sichern', 'vollbackup jetzt erstellen']],
        'restore' => ['title' => 'Datenbank wiederherstellen', 'image' => 'assets/img/restore.png', 'aliases' => ['restore', 'datenbank wiederherstellen', 'projekt restore', 'recovery / reset', 'projekt vollständig wiederherstellen', 'projekt vollstaendig wiederherstellen', 'projektsicherung wiederherstellen']],
        'archivieren' => ['title' => 'Datensatz archivieren', 'image' => 'assets/img/archivieren.png', 'aliases' => ['archivieren', 'archive']],
        'wiederherstellen' => ['title' => 'Archivierten Datensatz wiederherstellen', 'image' => 'assets/img/wiederherstellen.png', 'aliases' => ['wiederherstellen', 'unarchive', 'archivierten datensatz wiederherstellen']],
        'sperren' => ['title' => 'Sperren / deaktivieren', 'image' => 'assets/img/sperren.png', 'aliases' => ['sperren', 'lock', 'deaktivieren', 'disable']],
        'entsperren' => ['title' => 'Entsperren / aktivieren', 'image' => 'assets/img/entsperren.png', 'aliases' => ['entsperren', 'unlock', 'aktivieren', 'enable']],
        'einstellungen' => ['title' => 'Einstellungen öffnen', 'image' => 'assets/img/einstellungen.png', 'aliases' => ['einstellungen', 'settings', 'konfiguration', 'configure', 'installation / setup öffnen', 'installation / setup oeffnen', 'installation / setup', 'modul-registry öffnen', 'modul-registry oeffnen', 'cluster-sicherheit', 'capabilities', 'rollen', 'benutzer'], 'titles' => ['configuration' => 'Konfiguration öffnen', 'setup' => 'Setup durchführen', 'security' => 'Sicherheitsverwaltung öffnen']],
        'hilfe' => ['title' => 'Hilfe anzeigen', 'image' => 'assets/img/hilfe.png', 'aliases' => ['hilfe', 'help', 'setup-tutorial', 'setup-tutorial starten']],
        'info' => ['title' => 'Informationen anzeigen', 'image' => 'assets/img/info.png', 'aliases' => ['info', 'information', 'informationen']],
        'warnung' => ['title' => 'Warnhinweis anzeigen', 'image' => 'assets/img/warnung.png', 'aliases' => ['warnung', 'warning', 'konflikt']],
        'bestaetigen' => ['title' => 'Aktion bestätigen', 'image' => 'assets/img/bestaetigen.png', 'aliases' => ['bestätigen', 'bestaetigen', 'confirm', 'ok', 'ja', 'ausführen', 'ausfuehren', 'starten', 'vollreset durchführen', 'vollreset durchfuehren', 'installation ausführen', 'installation ausfuehren', 'heartbeat für diesen node schreiben', 'heartbeat fuer diesen node schreiben', 'laden', 'modul-metadaten publizieren', 'worker jetzt ausführen', 'worker jetzt ausfuehren', 'jetzt fällige tasks ausführen', 'jetzt faellige tasks ausfuehren', 'administrator sicher anlegen', 'datenbanken anlegen und prüfen', 'datenbanken anlegen und pruefen', 'schemas installieren und .env speichern', 'administrator anlegen', 'vorschau ausführen', 'vorschau ausfuehren', 'regel vorbereiten', 'weiter zu schritt', '1. verbindung testen', '2. datenbanken anlegen und prüfen', '2. datenbanken anlegen und pruefen', '3. schemas installieren und .env speichern'], 'titles' => ['execute' => 'Aktion ausführen', 'install' => 'Installation ausführen', 'setup_db_install' => 'Datenbank-Setup abschließen', 'setup_admin_create' => 'Administrator anlegen']],
        'start' => ['title' => 'Start- oder Übersichtsseite öffnen', 'image' => 'assets/img/start.png', 'aliases' => ['start', 'startseite', 'home', 'zum dashboard', 'enterprise-dashboard', 'zum workspace'], 'titles' => ['dashboard' => 'Dashboard öffnen', 'workspace' => 'Workspace öffnen']],
        'anmelden' => ['title' => 'Anmelden', 'image' => 'assets/img/anmelden.png', 'aliases' => ['anmelden', 'login', 'sign in', 'zum enterprise-login', 'zur anmeldung']],
        'registrieren' => ['title' => 'Kundenkonto registrieren', 'image' => 'assets/img/neu.png', 'aliases' => ['registrieren', 'konto registrieren', 'kundenkonto registrieren', 'registrieren und demo öffnen']],
        'abmelden' => ['title' => 'Abmelden', 'image' => 'assets/img/abmelden.png', 'aliases' => ['abmelden', 'logout', 'sign out']],
        'verlauf' => ['title' => 'Änderungsverlauf anzeigen', 'image' => 'assets/img/verlauf.png', 'aliases' => ['verlauf', 'history', 'änderungsverlauf', 'aenderungsverlauf', 'audit-protokoll', 'events']],
        'favorit' => ['title' => 'Als Favorit oder primär markieren', 'image' => 'assets/img/favorit.png', 'aliases' => ['favorit', 'favorite', 'favourite', 'merken', 'primär', 'primaer', 'primary']],
    ];
    return $registry;
}

/**
 * Resolve a literal action label through the same central registry metadata.
 * This is intentionally conservative: only registered aliases are accepted.
 */
function easyit_button_type_for_text(string $text): ?string
{
    $normalized = trim((string)preg_replace('/\s+/u', ' ', $text));
    $normalized = function_exists('mb_strtolower') ? mb_strtolower($normalized, 'UTF-8') : strtolower(strtr($normalized, ['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü','ẞ'=>'ß']));
    if ($normalized === '') return null;
    $matches = [];
    foreach (easyit_button_registry() as $type => $definition) {
        foreach ((array)($definition['aliases'] ?? []) as $alias) {
            $a = trim((string)$alias);
            $a = function_exists('mb_strtolower') ? mb_strtolower($a, 'UTF-8') : strtolower(strtr($a, ['Ä'=>'ä','Ö'=>'ö','Ü'=>'ü','ẞ'=>'ß']));
            if ($a === '') continue;
            if ($normalized === $a || str_contains($normalized, $a)) {
                $matches[] = ['type' => (string)$type, 'len' => (function_exists('mb_strlen') ? mb_strlen($a, 'UTF-8') : strlen($a))];
            }
        }
    }
    if ($matches === []) return null;
    usort($matches, static fn(array $a, array $b): int => $b['len'] <=> $a['len']);
    return (string)$matches[0]['type'];
}

function easyit_button_definition(string $type): ?array
{
    $type = strtolower(trim($type));
    $registry = easyit_button_registry();
    return $registry[$type] ?? null;
}

/**
 * Return the one canonical user-facing title for a button action.
 */
function easyit_button_title(string $type, ?string $context = null): string
{
    $definition = easyit_button_definition($type);
    if ($definition === null) {
        throw new InvalidArgumentException('Unknown easyIT button type: ' . $type);
    }
    $context = strtolower(trim((string)$context));
    $contextTitles = is_array($definition['titles'] ?? null) ? $definition['titles'] : [];
    $title = $context !== '' && isset($contextTitles[$context])
        ? trim((string)$contextTitles[$context])
        : trim((string)($definition['title'] ?? ''));
    if ($title === '') {
        throw new LogicException('Missing central title for easyIT button type: ' . $type);
    }
    return $title;
}

/**
 * Canonical server-side attributes.  Context names select another centrally
 * registered title; arbitrary/local title strings are not accepted.
 */
function easyit_button_attributes(string $type, ?string $context = null): string
{
    $title = easyit_button_title($type, $context);
    $type = strtolower(trim($type));
    $context = strtolower(trim((string)$context));
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $attributes = 'data-button="' . $esc($type) . '"';
    if ($context !== '') {
        $attributes .= ' data-button-context="' . $esc($context) . '"';
    }
    return $attributes . ' title="' . $esc($title) . '" aria-label="' . $esc($title) . '"';
}

/** Render the canonical registry PNG server-side. */
function easyit_button_image_html(string $type, string $base = ''): string
{
    $definition = easyit_button_definition($type);
    if ($definition === null) throw new InvalidArgumentException('Unknown easyIT button type: ' . $type);
    $image = ltrim((string)($definition['image'] ?? ''), '/');
    if ($image === '') throw new LogicException('Missing central image for easyIT button type: ' . $type);
    $src = rtrim($base, '/') . ($base !== '' ? '/' : '') . $image;
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<img class="easyit-button-image" src="' . $esc($src) . '" alt="" aria-hidden="true" draggable="false">';
}

/**
 * JSON payload consumed by the global browser adapter.  This is generated
 * from the PHP registry so title/image metadata has exactly one source.
 */
function easyit_button_registry_json(): string
{
    $json = json_encode(
        easyit_button_registry(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode easyIT button registry.');
    }
    return $json;
}

function easyit_button_registry_data_tag(): string
{
    return '<script type="application/json" id="easyit-button-registry-data">' . easyit_button_registry_json() . '</script>';
}
