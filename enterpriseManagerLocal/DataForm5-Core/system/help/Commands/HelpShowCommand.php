<?php
declare(strict_types=1);
namespace DataForm5\Help\Commands;
use DataForm5\Console\Core\{AbstractCommand,Input,Output};use DataForm5\Help\Core\{HelpRenderer,HelpResolver};
final class HelpShowCommand extends AbstractCommand
{
    public function __construct(private HelpResolver $resolver,private HelpRenderer $renderer){}
    public function name():string{return 'help:show';} public function description():string{return 'Zeigt kontextbezogene Hilfe in einem der drei Modi.';}
    public function arguments():array{return ['context'=>'Seiten- oder Routenkontext'];}
    public function options():array{return ['mode'=>['description'=>'short, steps oder expert','default'=>'short','requires_value'=>true]];}
    public function execute(Input $input,Output $output):int{$context=(string)($this->argument($input,'context')??'/');$topic=$this->resolver->resolve($context);if($topic===null){$output->error('Kein Hilfethema für '.$context);return 1;}$data=$this->renderer->render($topic,(string)$this->option($input,'mode'));$output->line($data['title']);if(is_array($data['content']))foreach($data['content'] as $i=>$step)$output->line(($i+1).'. '.$step);else $output->line((string)$data['content']);if($data['examples']!==[])$output->line(json_encode($data['examples'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));return 0;}
}
