<?php
declare(strict_types=1);

final class WorkspaceController
{
    public static function explorerItems(): array
    {
        return [
            ['key' => 'welcome', 'label' => 'Übersicht', 'icon' => '⌂'],
            ['key' => 'dataforms', 'label' => 'Formulare', 'icon' => '▣'],
            ['key' => 'sources', 'label' => 'Datenquellen', 'icon' => '⛁'],
            ['key' => 'tables', 'label' => 'Tabellen', 'icon' => '▦'],
            ['key' => 'relations', 'label' => 'Beziehungen', 'icon' => '⇄', 'href' => 'relations.php'],
            ['key' => 'workflow', 'label' => 'Workflow', 'icon' => '◇', 'href' => 'workflow.php'],
            ['key' => 'modules', 'label' => 'Module', 'icon' => '⬡', 'href' => 'modules.php'],
            ['key' => 'queries', 'label' => 'Visual Query Builder', 'icon' => '⌕', 'href' => 'queries.php'],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => '▥', 'href' => 'reports.php'],
            ['key' => 'api', 'label' => 'REST API Designer', 'icon' => '⌁', 'href' => 'api.php'],
            ['key' => 'sql', 'label' => 'SQL', 'icon' => '›_'],
            ['key' => 'import', 'label' => 'Import', 'icon' => '⇣'],
            ['key' => 'export', 'label' => 'Export', 'icon' => '⇡'],
            ['key' => 'packages', 'label' => 'Projektpakete', 'icon' => '◫', 'href' => 'packages.php'],
            ['key' => 'applications', 'label' => 'Application Builder', 'icon' => '▤', 'href' => 'applications.php'],
            ['key' => 'settings', 'label' => 'Einstellungen', 'icon' => '⚙'],
        ];
    }

    public static function section(string $key): array
    {
        $sections = [
            'welcome' => ['title' => 'Willkommen im DataForm Workspace', 'text' => 'Hier verwalten Sie DataForms, Datenquellen, Tabellen und alle weiteren Projektbestandteile.'],
            'dataforms' => ['title' => 'Formulare', 'text' => 'Hier verwalten Sie die Formulare (DataForms) des aktiven Projekts und legen neue Formulare an.'],
            'dataform' => ['title' => 'DataForm bearbeiten', 'text' => 'Hier verwalten Sie die Felder und Eigenschaften des ausgewählten DataForms.'],
            'designer' => ['title' => 'Formular-Designer', 'text' => 'Hier gestalten Sie das Formular, bearbeiten Feldeigenschaften und prüfen die Live-Vorschau.'],
            'sources' => ['title' => 'Datenquellen', 'text' => 'MySQL/MariaDB, SQLite, CSV und Oracle als projektbezogene Datenquellen verwalten und testen.'],
            'tables' => ['title' => 'Tabellen', 'text' => 'Tabellen und Felder der Projekt-Datenbank verwalten sowie externe Datenquellen lesend untersuchen.'],
            'relations' => ['title' => 'Beziehungen', 'text' => 'Hier werden 1:n-, n:1/Lookup- und n:m-Beziehungen gepflegt.'],
            'queries' => ['title' => 'Visual Query Builder', 'text' => 'Erstellen und speichern Sie lesende Abfragen ohne handgeschriebenes SQL.'],
            'reports' => ['title' => 'Report Designer', 'text' => 'Berichte aus DataForms und gespeicherten Abfragen gestalten, prüfen und exportieren.'],
            'api' => ['title' => 'REST API Designer', 'text' => 'Versionierte REST-Endpunkte, API-Schlüssel und OpenAPI-Dokumentation verwalten.'],
            'sql' => ['title' => 'SQL', 'text' => 'Ein SQL-Arbeitsbereich wird später ergänzt.'],
            'import' => ['title' => 'Import', 'text' => 'CSV-Import mit Feldtypvalidierung, Duplikatabgleich, Importprofilen und Rücknahme – auch für physisch gebundene DataForms.'],
            'export' => ['title' => 'Export', 'text' => 'Portable CSV- und .dfpkg-Exporte einschließlich strukturierter Feldtypen sowie Datei-/Bildmedien.'],
            'packages' => ['title' => 'Pakete', 'text' => 'Module und Projektpakete werden künftig an dieser Stelle verwaltet.'],
            'applications' => ['title' => 'Application Builder', 'text' => 'Hier werden Navigation, Dashboard und veröffentlichbare Anwendungs-Builds konfiguriert.'],
            'settings' => ['title' => 'Einstellungen', 'text' => 'Projektbezogene Einstellungen werden in einer späteren Phase aktiviert.'],
        ];
        return $sections[$key] ?? $sections['welcome'];
    }
}
