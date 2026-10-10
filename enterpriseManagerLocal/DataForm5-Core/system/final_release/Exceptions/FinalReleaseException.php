<?php
declare(strict_types=1);
namespace DataForm5\FinalRelease\Exceptions;
final class FinalReleaseException extends \RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Die finale Release-Prüfung ist fehlgeschlagen.');
    }
}
