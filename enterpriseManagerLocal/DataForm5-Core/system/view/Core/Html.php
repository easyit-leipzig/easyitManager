<?php
declare(strict_types=1);
namespace DataForm5\View\Core;
final class Html
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
