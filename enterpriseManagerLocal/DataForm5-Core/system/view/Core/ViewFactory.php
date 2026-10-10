<?php
declare(strict_types=1);
namespace DataForm5\View\Core;
use DataForm5\View\Contracts\ViewFactoryInterface;
use DataForm5\View\Exceptions\ViewException;
final class ViewFactory implements ViewFactoryInterface
{
    private array $shared=[];
    public function __construct(private string $viewsPath, private ThemeManager $themes, private AssetManager $assets) {}
    public function share(string $key,mixed $value):void{$this->shared[$key]=$value;}
    public function exists(string $view):bool{return is_file($this->resolve($view));}
    public function make(string $view,array $data=[]):string
    {
        $data=$data+$this->shared; $context=new ViewContext($this,$data);
        $content=$this->evaluate($this->resolve($view),$data,$context);
        if($context->layout()!==null){$layout=new ViewContext($this,$data);$layout->importSections($context->sections()+['content'=>$content]);return $this->evaluate($this->resolve($context->layout()),$data,$layout);}return $content;
    }
    public function asset(string $path,bool $versioned=true):string{return $versioned?$this->assets->versioned($path):$this->assets->url($path);}
    public function theme():ThemeManager{return $this->themes;}
    private function resolve(string $view):string
    {
        if(str_contains($view,'..'))throw new ViewException('Ungültiger View-Pfad.');
        $relative=str_replace('.','/',$view).'.php';
        $theme=$this->themes->path('views/'.$relative);if(is_file($theme))return $theme;
        $file=rtrim($this->viewsPath,'/').'/'.$relative;if(!is_file($file))throw new ViewException("View '{$view}' wurde nicht gefunden.");return $file;
    }
    private function evaluate(string $file,array $data,ViewContext $view):string
    {
        $asset=fn(string $path,bool $versioned=true):string=>$this->asset($path,$versioned);
        extract($data,EXTR_SKIP);ob_start();try{require $file;return (string)ob_get_clean();}catch(\Throwable $e){ob_end_clean();throw $e;}
    }
}
