<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Workflow;

final class WorkflowDefinition
{
    public const ID = 'workflow.standard';

    /** @return list<array{id:string,title:string,assistant:string,startStep:string,optional:bool,description:string}> */
    public static function steps(): array
    {
        return [
            ['id' => 'project', 'title' => '1. Projekt', 'assistant' => 'project.create', 'startStep' => 'identity', 'optional' => false, 'description' => 'Projekt auswählen oder neu anlegen.'],
            ['id' => 'datasource', 'title' => '2. Datenquelle', 'assistant' => 'datasource.configure', 'startStep' => 'profile', 'optional' => false, 'description' => 'Datenquelle konfigurieren, real testen und Hauptquelle auswählen.'],
            ['id' => 'dataform', 'title' => '3. DataForm', 'assistant' => 'dataform.create', 'startStep' => 'source', 'optional' => false, 'description' => 'DataForm-Grundkonfiguration einschließlich Suche, Filter, Pagination und CRUD erzeugen.'],
            ['id' => 'fields', 'title' => '4. Felder', 'assistant' => 'dataform.fields', 'startStep' => 'context', 'optional' => false, 'description' => 'Feldtypen, Lookup- und Derived-Enum-Konfiguration prüfen.'],
            ['id' => 'relations', 'title' => '5. Beziehungen', 'assistant' => 'dataform.relations', 'startStep' => 'relation', 'optional' => true, 'description' => '1:n-/n:m-Beziehungen und gebundene Eltern-/Kindwerte konfigurieren oder als nicht benötigt bestätigen.'],
            ['id' => 'events', 'title' => '6. Events', 'assistant' => 'dataform.events', 'startStep' => 'context', 'optional' => true, 'description' => 'before/after-Events konfigurieren oder als nicht benötigt bestätigen.'],
            ['id' => 'actions', 'title' => '7. Aktionen', 'assistant' => 'dataform.actions', 'startStep' => 'context', 'optional' => false, 'description' => 'Zentrale Aktionen und Button-Registry konfigurieren.'],
            ['id' => 'diagnostics', 'title' => '8. Diagnose', 'assistant' => 'dataform.diagnostics', 'startStep' => 'context', 'optional' => false, 'description' => 'Gesamtkonfiguration mit PASS/FAIL/WARN/SKIP prüfen.'],
        ];
    }
}
