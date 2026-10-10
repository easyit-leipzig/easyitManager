<?php
declare(strict_types=1);
namespace DataForm5\UI\Core;
final class Attributes
{
    /** @param array<string, scalar|null> $attributes */
    public static function render(array $attributes): string
    {
        $parts=[];
        foreach($attributes as $name=>$value){
            if($value===null || $value===false) continue;
            $safeName=preg_replace('/[^a-zA-Z0-9_:\-.]/','',(string)$name) ?? '';
            if($safeName==='') continue;
            if($value===true){$parts[]=$safeName;continue;}
            $parts[]=$safeName.'="'.htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';
        }
        return $parts ? ' '.implode(' ',$parts) : '';
    }
}
