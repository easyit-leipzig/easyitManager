<?php
declare(strict_types=1);
namespace DataForm5\Help\Core;
use InvalidArgumentException;
final class HelpTopic
{
    /** @param list<string> $contexts @param array<string,mixed> $examples */
    public function __construct(public readonly string $id,public readonly string $title,public readonly array $contexts,public readonly string $short,public readonly array $steps=[],public readonly string $expert='',public readonly array $examples=[])
    { if($id===''||$title===''||$contexts===[])throw new InvalidArgumentException('Hilfethema benötigt ID, Titel und mindestens einen Kontext.'); }
    public static function fromArray(array $data):self{return new self((string)($data['id']??''),(string)($data['title']??''),array_values(array_map('strval',$data['contexts']??[])),(string)($data['short']??''),array_values(array_map('strval',$data['steps']??[])),(string)($data['expert']??''),is_array($data['examples']??null)?$data['examples']:[]);}
    public function toArray():array{return ['id'=>$this->id,'title'=>$this->title,'contexts'=>$this->contexts,'short'=>$this->short,'steps'=>$this->steps,'expert'=>$this->expert,'examples'=>$this->examples];}
}
