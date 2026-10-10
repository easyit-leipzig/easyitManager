<?php
declare(strict_types=1);
namespace DataForm5\ReleaseCandidate\Exceptions;
use RuntimeException;
final class ReleaseCandidateException extends RuntimeException
{
    public function __construct(public readonly array $report)
    {
        parent::__construct('Der DataForm5-Core ist noch nicht als Release Candidate freigegeben.');
    }
}
