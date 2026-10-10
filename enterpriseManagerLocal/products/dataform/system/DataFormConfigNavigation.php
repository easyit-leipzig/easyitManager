<?php
declare(strict_types=1);

/**
 * Central cross-navigation for all DataForm configuration surfaces.
 * One setting has one primary edit location; other surfaces link to it.
 */
function dataform_config_sections(int $projectId, int $dataformId): array
{
    $q='project='.$projectId.'&dataform='.$dataformId;
    return [
        'settings'=>['label'=>'Einstellungen','href'=>'index.php?project='.$projectId.'&section=dataform&dataform='.$dataformId.'#dataform-settings','hint'=>'Ansicht, Seiten, CRUD, CSS und Runtime-Events'],
        'fields'=>['label'=>'Felder','href'=>'index.php?project='.$projectId.'&section=dataform&dataform='.$dataformId.'#fields','hint'=>'Typ, Pflicht, Suche, Liste und Validierung'],
        'designer'=>['label'=>'Formular','href'=>'index.php?project='.$projectId.'&section=designer&dataform='.$dataformId,'hint'=>'Feldbreite und Formularanordnung'],
        'layout'=>['label'=>'Layout','href'=>'foundation.php?'.$q.'&mode=layout','hint'=>'Seiten, Gruppen, Tabs und Struktur'],
        'behavior'=>['label'=>'Verhalten','href'=>'foundation.php?'.$q.'&mode=behavior','hint'=>'Deklarative Regeln und Reaktionen'],
        'workflow'=>['label'=>'Workflow','href'=>'workflow.php?'.$q,'hint'=>'Status, Übergänge und Folgeaktionen'],
        'relations'=>['label'=>'Beziehungen','href'=>'relations.php?'.$q,'hint'=>'1:n, n:1/Lookup und n:m'],
        'preview'=>['label'=>'Realvorschau','href'=>'index.php?project='.$projectId.'&section=dataform&dataform='.$dataformId.'#real-preview','hint'=>'Gespeicherte Runtime mit echten Daten'],
        'records'=>['label'=>'Datensätze','href'=>'records.php?'.$q,'hint'=>'Tatsächliche Datenansicht und CRUD'],
        'versions'=>['label'=>'Versionen','href'=>'foundation.php?'.$q.'&mode=versions','hint'=>'Konfigurationsstände sichern'],
    ];
}

function dataform_config_map(int $projectId, int $dataformId, string $active=''): string
{
    if($projectId<1||$dataformId<1)return '';
    $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<section class="df-config-map" aria-label="DataForm-Konfigurationskarte"><div class="df-config-map-head"><strong>DataForm konfigurieren</strong><span>Jede Einstellung hat einen Hauptort. Die Querverweise bleiben auf allen Konfigurationsseiten gleich.</span></div><nav class="df-config-map-grid">';
    $n=0;
    foreach(dataform_config_sections($projectId,$dataformId) as $key=>$item){
        $n++;
        $cls=$key===$active?' active':'';
        $html.='<a class="df-config-map-item'.$cls.'" href="'.$e($item['href']).'"><span class="df-config-map-no">'.$n.'</span><span><strong>'.$e($item['label']).'</strong><small>'.$e($item['hint']).'</small></span></a>';
    }
    return $html.'</nav></section>';
}

function dataform_config_related(int $projectId,int $dataformId,string $active): string
{
    if($projectId<1||$dataformId<1)return '';
    $sections=dataform_config_sections($projectId,$dataformId);
    $matrix=[
        'settings'=>['title'=>'Diese Einstellungen wirken weiter','items'=>[
            ['preview','Ansicht, Seitenzahl, CRUD-Schalter und AddCSS sofort in der Realvorschau prüfen.'],
            ['layout','Struktur und Gruppen gehören ins Layout, nicht in AddCSS.'],
            ['behavior','Deklarative Regeln werden unter Verhalten gepflegt; JavaScript-Hooks bleiben hier.'],
            ['workflow','Statuswechsel und Folgeaktionen gehören in den Workflow.'],
        ]],
        'fields'=>['title'=>'Felder im Zusammenhang','items'=>[
            ['designer','Breite und Darstellung eines Feldes im Formular prüfen.'],
            ['relations','Lookup-/Fremdschlüsselfelder mit Beziehungen verbinden.'],
            ['preview','Feldtyp, Pflichtprüfung und Darstellung mit echten Daten testen.'],
        ]],
        'designer'=>['title'=>'Formular im Zusammenhang','items'=>[
            ['fields','Datentyp, Pflichtfeld, Suche und Listenverhalten werden am Feld definiert.'],
            ['layout','Übergeordnete Gruppen, Tabs und Seiten im Layout definieren.'],
            ['preview','Gespeicherte Wirkung in der echten Runtime kontrollieren.'],
        ]],
        'layout'=>['title'=>'Layout im Zusammenhang','items'=>[
            ['settings','Standardansicht, Dialoggröße und AddCSS werden in Einstellungen gepflegt.'],
            ['designer','Feldbreiten und konkrete Formularanordnung im Formular-Designer bearbeiten.'],
            ['preview','Layout mit echten Datensätzen prüfen.'],
        ]],
        'behavior'=>['title'=>'Verhalten im Zusammenhang','items'=>[
            ['settings','Speichermodus und JavaScript-Runtime-Events haben ihren Hauptort in Einstellungen.'],
            ['workflow','Geschäftliche Statusübergänge nicht als lokale Verhaltensregel modellieren.'],
            ['preview','Reaktionen in der Runtime prüfen.'],
        ]],
        'workflow'=>['title'=>'Workflow im Zusammenhang','items'=>[
            ['behavior','Feld-/Formularreaktionen unter Verhalten; Lebenszyklus hier im Workflow.'],
            ['relations','Folgeaktionen mit verknüpften DataForms gegen Beziehungen prüfen.'],
            ['preview','Runtime anschließend in der Realvorschau kontrollieren.'],
        ]],
        'relations'=>['title'=>'Beziehungen im Zusammenhang','items'=>[
            ['fields','FK-/Lookup-Felder müssen im Datenmodell vorhanden und passend typisiert sein.'],
            ['preview','Eltern-/Kind- und Lookup-Verhalten mit echten Datensätzen prüfen.'],
            ['records','Datenbestand auf konsistente Zuordnungen kontrollieren.'],
        ]],
        'preview'=>['title'=>'Von der Vorschau zurück zur Ursache','items'=>[
            ['settings','Ansicht, Seitenzahl, CRUD oder CSS ändern.'],
            ['designer','Feldanordnung ändern.'],
            ['layout','Gruppen/Tabs/Seiten ändern.'],
            ['relations','Fehlerhafte Eltern-/Kind- oder Lookup-Anzeige korrigieren.'],
        ]],
        'records'=>['title'=>'Datensätze im Zusammenhang','items'=>[
            ['settings','Standardansicht und Seitenzahl konfigurieren.'],
            ['fields','Listen-/Such-/Filtereigenschaften einzelner Felder ändern.'],
            ['relations','Fremdschlüssel und Kindbeziehungen konfigurieren.'],
        ]],
        'versions'=>['title'=>'Vor dem Snapshot prüfen','items'=>[
            ['settings','Runtime-Einstellungen kontrollieren.'],['layout','Layout kontrollieren.'],['behavior','Verhalten kontrollieren.'],['workflow','Workflow kontrollieren.'],['relations','Beziehungen kontrollieren.'],
        ]],
    ];
    $cfg=$matrix[$active]??null;
    if(!$cfg)return '';
    $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<section class="df-related-links"><h3>'.$e($cfg['title']).'</h3><ul>';
    foreach($cfg['items'] as [$key,$text]){
        if(!isset($sections[$key]))continue;
        $html.='<li><a href="'.$e($sections[$key]['href']).'">'.$e($sections[$key]['label']).'</a><span>'.$e($text).'</span></li>';
    }
    return $html.'</ul></section>';
}
