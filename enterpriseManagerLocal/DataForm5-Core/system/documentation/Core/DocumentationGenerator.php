<?php
declare(strict_types=1);
namespace DataForm5\Documentation\Core;
use RuntimeException;
final class DocumentationGenerator
{
    public function __construct(private string $basePath,private ComponentCatalog $catalog){}
    /** @return array<string,mixed> */
    public function generate(?string $target=null): array
    {
        $target??=$this->basePath.'/storage/documentation';if(!is_dir($target)&&!mkdir($target,0775,true)&&!is_dir($target))throw new RuntimeException('Dokumentationsverzeichnis konnte nicht erstellt werden.');
        $components=$this->catalog->scan();$summary=$this->catalog->summary($components);$version=trim((string)@file_get_contents($this->basePath.'/VERSION'));
        $payload=['project'=>'DataForm5-Core','build'=>'build0026','version'=>$version,'generated_at'=>gmdate(DATE_ATOM),'summary'=>$summary,'components'=>$components];
        file_put_contents($target.'/component-catalog.json',json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL,LOCK_EX);
        $md="# DataForm5-Core – Komponenten-Katalog\n\n**Build:** build0026  \n**Version:** {$version}  \n**Erzeugt:** {$payload['generated_at']}\n\n";
        $md.="## Übersicht\n\n- Komponenten: {$summary['total']}\n- Klassen: {$summary['classes']}\n- Interfaces: {$summary['interfaces']}\n- Traits: {$summary['traits']}\n- Layer: {$summary['layers']}\n\n## Komponenten\n\n| Layer | Typ | Klasse | Öffentliche Methoden |\n|---|---|---|---|\n";
        foreach($components as $c){$methods=$c['methods']===[]?'–':implode(', ',$c['methods']);$md.='| '.$c['layer'].' | '.$c['kind'].' | `'.$c['class'].'` | '.str_replace('|','\\|',$methods)." |\n";}
        file_put_contents($target.'/component-catalog.md',$md,LOCK_EX);
        $this->writeBuildOverview($target,$summary,$version);
        return $payload;
    }
    private function writeBuildOverview(string $target,array $summary,string $version):void
    {
        $text="# Entwicklerportal – DataForm5-Core\n\n## Aktueller Stand\n\n- Build: **build0026**\n- Version: **{$version}**\n- Kernkomponenten: **{$summary['total']}**\n- System-Layer: **{$summary['layers']}**\n\n## Erzeugte Artefakte\n\n- `component-catalog.json` – maschinenlesbarer Systemkatalog\n- `component-catalog.md` – lesbare Komponentenübersicht\n- `build-overview.md` – Build- und Einstiegspunkt\n\n## Erzeugung\n\n```bash\nphp bin/dataform docs:generate\n```\n";
        file_put_contents($target.'/build-overview.md',$text,LOCK_EX);
    }
}
