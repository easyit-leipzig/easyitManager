<?php
declare(strict_types=1);
namespace DataForm5\Help\Core;
use InvalidArgumentException;
final class HelpRenderer
{
    public const MODES=['short','steps','expert'];
    public function render(HelpTopic $topic,string $mode='short'):array
    {
        if(!in_array($mode,self::MODES,true))throw new InvalidArgumentException('Unbekannter Hilfemodus.');
        $content=match($mode){'short'=>$topic->short,'steps'=>$topic->steps,'expert'=>$topic->expert};
        return ['topic'=>$topic->id,'title'=>$topic->title,'mode'=>$mode,'content'=>$content,'examples'=>$topic->examples];
    }
}
