<?php
declare(strict_types=1);

namespace DataForm5\ORM\Core;

use DataForm\Database\Core\DatabaseManager;
use DataForm5\ORM\Contracts\ModelInterface;

final class OrmManager
{
    public function __construct(private readonly DatabaseManager $databases)
    {
    }

    /** @param class-string<ModelInterface> $modelClass */
    public function repository(string $modelClass, ?string $connection = null): ModelRepository
    {
        return new ModelRepository($this->databases->connection($connection), $modelClass);
    }
}
