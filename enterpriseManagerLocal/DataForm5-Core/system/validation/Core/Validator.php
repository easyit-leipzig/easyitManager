<?php
declare(strict_types=1);
namespace DataForm5\Validation\Core;
use DataForm5\Validation\Contracts\RuleInterface;
use InvalidArgumentException;
final class Validator
{
    /** @var array<string,callable> */
    private array $extensions = [];
    /** @var array<string,string> */
    private array $messages;
    public function __construct(array $messages = []) { $this->messages = $messages; }
    public function extend(string $name, callable $callback): void { $this->extensions[strtolower($name)] = $callback; }
    public function validate(array $data, array $rules, array $messages = []): ValidationResult
    {
        $errors = new ErrorBag(); $validated = [];
        foreach ($rules as $attribute => $definition) {
            $exists = $this->exists($data, (string)$attribute); $value = $this->get($data, (string)$attribute);
            $items = is_array($definition) ? $definition : explode('|', (string)$definition);
            $nullable = $this->containsRule($items, 'nullable');
            if ($nullable && (!$exists || $value === null || $value === '')) { $validated[(string)$attribute] = $value; continue; }
            foreach ($items as $item) {
                if ($item instanceof RuleInterface) {
                    if (!$item->passes((string)$attribute, $value, $data)) $errors->add((string)$attribute, $item->message((string)$attribute));
                    continue;
                }
                [$rule, $parameters] = $this->parse((string)$item);
                if ($rule === '' || $rule === 'nullable') continue;
                $passed = $this->apply($rule, $attribute, $value, $exists, $parameters, $data);
                if (!$passed) $errors->add((string)$attribute, $this->message((string)$attribute, $rule, $parameters, $messages));
                if (!$passed && $rule === 'required') break;
            }
            if (!$errors->has((string)$attribute) && $exists) $validated[(string)$attribute] = $value;
        }
        return new ValidationResult($validated, $errors);
    }
    private function containsRule(array $items, string $needle): bool
    {
        foreach ($items as $item) if (is_string($item) && strtolower(explode(':', $item, 2)[0]) === $needle) return true;
        return false;
    }
    private function parse(string $rule): array
    {
        [$name, $args] = array_pad(explode(':', trim($rule), 2), 2, '');
        return [strtolower($name), $args === '' ? [] : str_getcsv($args)];
    }
    private function apply(string $rule, string $attribute, mixed $value, bool $exists, array $p, array $data): bool
    {
        if (isset($this->extensions[$rule])) return (bool)($this->extensions[$rule])($attribute, $value, $p, $data);
        return match ($rule) {
            'required' => $exists && $value !== null && $value !== '' && (!is_array($value) || $value !== []),
            'present' => $exists,
            'string' => is_string($value),
            'integer', 'int' => is_int($value) || (is_string($value) && filter_var($value, FILTER_VALIDATE_INT) !== false),
            'numeric' => is_numeric($value),
            'boolean', 'bool' => is_bool($value) || in_array($value, [0,1,'0','1','true','false'], true),
            'array' => is_array($value),
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'min' => $this->size($value) >= (float)($p[0] ?? 0),
            'max' => $this->size($value) <= (float)($p[0] ?? INF),
            'between' => $this->size($value) >= (float)($p[0] ?? 0) && $this->size($value) <= (float)($p[1] ?? INF),
            'in' => in_array((string)$value, array_map('strval', $p), true),
            'not_in' => !in_array((string)$value, array_map('strval', $p), true),
            'same' => $value === $this->get($data, (string)($p[0] ?? '')),
            'different' => $value !== $this->get($data, (string)($p[0] ?? '')),
            'regex' => isset($p[0]) && is_string($value) && @preg_match($p[0], $value) === 1,
            default => throw new InvalidArgumentException("Unbekannte Validierungsregel: {$rule}"),
        };
    }
    private function size(mixed $value): float
    {
        if (is_numeric($value)) return (float)$value;
        if (is_array($value)) return count($value);
        if (is_string($value)) return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return 0;
    }
    private function message(string $attribute, string $rule, array $p, array $custom): string
    {
        $key = $attribute . '.' . $rule;
        $template = $custom[$key] ?? $custom[$rule] ?? $this->messages[$rule] ?? ':attribute ist ungültig.';
        $replace = [':attribute'=>$attribute, ':value'=>(string)($p[0] ?? ''), ':min'=>(string)($p[0] ?? ''), ':max'=>(string)($p[1] ?? $p[0] ?? ''), ':other'=>(string)($p[0] ?? '')];
        return strtr($template, $replace);
    }
    private function get(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) { if (!is_array($value) || !array_key_exists($segment, $value)) return null; $value = $value[$segment]; }
        return $value;
    }
    private function exists(array $data, string $path): bool
    {
        $value = $data;
        foreach (explode('.', $path) as $segment) { if (!is_array($value) || !array_key_exists($segment, $value)) return false; $value = $value[$segment]; }
        return true;
    }
}
