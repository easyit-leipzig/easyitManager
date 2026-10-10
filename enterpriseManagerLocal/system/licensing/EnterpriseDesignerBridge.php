<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;
require_once __DIR__.'/LicenseConfigStore.php';

final class EnterpriseDesignerBridge
{
    public static function publishCurrent(string $root, object $db, string|int $projectId, int $dataformId): array
    {
        $cfg=\EnterpriseLicenseConfigStore::load($root);
        if (empty($cfg['enabled'])) throw new \RuntimeException('DATAFORM_LICENSE_DISABLED');

        $definition = DataFormDefinitionBuilder::build($db, $dataformId);
        $api = new LicenseApiClient($cfg);
        $client = new DataFormDesignerClient($api);
        $validation = $client->validate((string)$projectId, (string)$dataformId, $definition);
        if (empty($validation['valid'])) throw new \RuntimeException('DATAFORM_DEFINITION_INVALID');
        $compiled = $client->compile((string)$projectId, (string)$dataformId, $definition);
        if (empty($compiled['definition_id'])) throw new \RuntimeException('DATAFORM_COMPILE_FAILED');
        $published = $client->publish((string)$compiled['definition_id'], (string)$projectId, (string)$dataformId);
        return ['validation'=>$validation,'compiled'=>$compiled,'published'=>$published];
    }
}
