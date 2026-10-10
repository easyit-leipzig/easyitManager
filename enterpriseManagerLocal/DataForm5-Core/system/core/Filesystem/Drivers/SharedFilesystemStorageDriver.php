<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem\Drivers;

use DataForm5\Core\Filesystem\Filesystem;

final class SharedFilesystemStorageDriver extends LocalStorageDriver
{
    public function health(): array
    {
        $health=parent::health();
        $health['driver']='shared-filesystem';
        $health['shared']=true;
        return $health;
    }
}
