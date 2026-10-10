<?php
declare(strict_types=1);
namespace DataForm5\Http\Exceptions;
use RuntimeException;
class HttpException extends RuntimeException { public function __construct(public readonly int $status, string $message='HTTP error'){parent::__construct($message);} }
