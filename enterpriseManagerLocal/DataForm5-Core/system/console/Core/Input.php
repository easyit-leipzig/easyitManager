<?php
declare(strict_types=1);
namespace DataForm5\Console\Core;
use DataForm5\Console\Exceptions\ConsoleException;
final class Input
{
    /** @param list<string> $tokens */
    public function __construct(private array $tokens = []) {}
    /** @return list<string> */ public function tokens(): array { return $this->tokens; }
    public function command(): string { return $this->tokens[0] ?? 'list'; }
    /** @return array<string,mixed> */
    public function parseArguments(array $definitions): array
    {
        $values=[];$positionals=[];
        foreach(array_slice($this->tokens,1) as $token){if(!str_starts_with($token,'-'))$positionals[]=$token;}
        $i=0;foreach($definitions as $name=>$description){$values[$name]=$positionals[$i]??null;$i++;}
        return $values;
    }
    /** @param array<string,array{description:string,default:mixed,requires_value:bool}> $definitions @return array<string,mixed> */
    public function parseOptions(array $definitions): array
    {
        $values=[];foreach($definitions as $name=>$definition)$values[$name]=$definition['default'];
        $tokens=array_slice($this->tokens,1);
        for($i=0;$i<count($tokens);$i++){
            $token=$tokens[$i];if(!str_starts_with($token,'--'))continue;
            $raw=substr($token,2);[$name,$inline]=array_pad(explode('=',$raw,2),2,null);
            if(!isset($definitions[$name]))throw new ConsoleException("Unbekannte Option --{$name}.");
            if($definitions[$name]['requires_value']){
                $value=$inline??($tokens[$i+1]??null);
                if($value===null||str_starts_with((string)$value,'--'))throw new ConsoleException("Option --{$name} benötigt einen Wert.");
                if($inline===null)$i++;$values[$name]=$value;
            }else{$values[$name]=$inline===null?true:$inline;}
        }
        return $values;
    }
}
