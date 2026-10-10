<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Forms\Contracts\FieldInterface;
use DataForm5\Security\Csrf\CsrfTokenManager;
final class FormRenderer
{
    public function __construct(private readonly ?CsrfTokenManager $csrf=null) {}
    public function render(Form $form, ?FormResult $result=null, string $action='', string $method='post', ?string $csrfToken=null): string
    {
        $method=strtoupper($method); $htmlMethod=in_array($method,['GET','POST'],true)?$method:'POST';
        $attrs=$this->attrs(array_merge(['method'=>strtolower($htmlMethod),'action'=>$action],$form->attributes()));
        $html='<form '.$attrs.'>';
        if($htmlMethod==='POST'){
            $token=$csrfToken??$this->csrf?->token();
            if($token!==null)$html.='<input type="hidden" name="_token" value="'.$this->e($token).'">';
            if($method!=='POST')$html.='<input type="hidden" name="_method" value="'.$this->e($method).'">';
        }
        foreach($form->fields() as $field)$html.=$this->renderField($field,$result);
        return $html.'</form>';
    }
    public function renderField(FieldInterface $field, ?FormResult $result=null): string
    {
        $d=$field->toArray(); $name=$d['name']; $id='field-'.preg_replace('/[^A-Za-z0-9_-]/','-',$name); $value=$d['value'];
        $html='<div class="df-field df-field--'.$this->e($d['type']).'"><label for="'.$id.'">'.$this->e($d['label']).'</label>';
        $attrs=$this->attrs(array_merge(['id'=>$id,'name'=>$name],$d['attributes']));
        if($d['type']==='select'){$html.='<select '.$attrs.'>';foreach($d['options'] as $k=>$label){$sel=((string)$k===(string)$value)?' selected':'';$html.='<option value="'.$this->e((string)$k).'"'.$sel.'>'.$this->e((string)$label).'</option>';}$html.='</select>';}
        elseif($d['type']==='checkbox'){$html.='<input type="checkbox" '.$attrs.' value="1"'.($value?' checked':'').'>';}
        elseif($d['type']==='textarea'){$html.='<textarea '.$attrs.'>'.$this->e((string)($value??'')).'</textarea>';}
        else{$type=(string)($d['attributes']['type']??$d['type']); $safe=in_array($type,['text','email','password','number','date','url','hidden'],true)?$type:'text'; unset($d['attributes']['type']); $attrs=$this->attrs(array_merge(['id'=>$id,'name'=>$name],$d['attributes'])); $html.='<input type="'.$safe.'" '.$attrs.' value="'.$this->e((string)($value??'')).'">';}
        if($result?->errors()->has($name))$html.='<div class="df-field__error" role="alert">'.$this->e((string)$result->errors()->first($name)).'</div>';
        return $html.'</div>';
    }
    private function attrs(array $attrs): string { $out=[];foreach($attrs as $k=>$v){if($v===false||$v===null)continue;$out[]=$this->e((string)$k).($v===true?'':'="'.$this->e((string)$v).'"');}return implode(' ',$out); }
    private function e(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
