<?php
declare(strict_types=1);
namespace DataForm5\Help\Contracts;
interface HelpProviderInterface { /** @return array<int,array<string,mixed>> */ public function topics(): array; }
