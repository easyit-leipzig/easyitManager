<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

final class AssistantSurfaceResolver
{
    public static function resolve(?string $route, ?string $explicit = null): string
    {
        $explicit = trim((string) $explicit);
        if ($explicit !== '') { return $explicit; }

        $path = strtolower((string) parse_url((string) $route, PHP_URL_PATH));
        if ($path === '') { return 'assistant.center'; }
        if (str_contains($path, '/admin/assistants')) { return 'assistant.center'; }

        // Spezifische DataForm-Unterseiten immer vor dem allgemeinen DataForm-Muster prüfen.
        if (preg_match('~(?:relation|relationship|beziehung)~', $path)) { return 'dataform.relations'; }
        if (preg_match('~(?:field|felder?)~', $path)) { return 'dataform.fields'; }
        if (preg_match('~(?:event|ereignis)~', $path)) { return 'dataform.events'; }
        if (preg_match('~(?:action|button|aktion)~', $path)) { return 'dataform.actions'; }
        if (preg_match('~(?:diagnos|test|health)~', $path)) { return 'dataform.diagnostics'; }
        if (preg_match('~(?:dataform|forms?/designer|formdesigner)~', $path)) { return 'dataform'; }
        if (preg_match('~(?:datasource|database|datenquelle)~', $path)) { return 'datasource'; }

        // Detailroute vor der Projektübersicht unterscheiden; index/list/manage bleiben Übersichtsseiten.
        if (preg_match('~/admin/projects?/(?!index\.php(?:/|$)|list\.php(?:/|$)|manage\.php(?:/|$))[^/]+(?:/|$)~', $path) || str_contains($path, '/project/')) { return 'project.detail'; }
        if (preg_match('~/admin/projects?(?:/|$)~', $path)) { return 'project.management'; }
        return 'assistant.center';
    }
}
