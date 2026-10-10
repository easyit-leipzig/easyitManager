<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Forms\Contracts\FieldInterface;
use InvalidArgumentException;
class Field implements FieldInterface
{
    private mixed $value = null;
    public function __construct(
        private readonly string $name,
        private readonly string $type = 'text',
        private readonly string $label = '',
        private readonly string|array $validationRules = [],
        private readonly array $attributes = [],
        private readonly array $options = [],
        mixed $value = null,
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name)) throw new InvalidArgumentException('Ungültiger Feldname: '.$name);
        $this->value = $value;
    }
    public function name(): string { return $this->name; }
    public function type(): string { return $this->type; }
    public function label(): string { return $this->label !== '' ? $this->label : ucfirst(str_replace(['_','-'], ' ', $this->name)); }
    public function rules(): string|array { return $this->validationRules; }
    public function attributes(): array { return $this->attributes; }
    public function options(): array { return $this->options; }
    public function value(): mixed { return $this->value; }
    public function withValue(mixed $value): static { $clone = clone $this; $clone->value = $value; return $clone; }
    public function toArray(): array { return ['name'=>$this->name,'type'=>$this->type,'label'=>$this->label(),'rules'=>$this->validationRules,'attributes'=>$this->attributes,'options'=>$this->options,'value'=>$this->value]; }
}
