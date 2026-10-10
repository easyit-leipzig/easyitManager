<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Binding;

use PDO;

final class BindingSettingsRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array{inheritParentValue:bool,boundFieldReadonly:bool}
     */
    public function get(
        string $relationId
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT inherit_parent_value, bound_field_readonly
             FROM df_bound_form_binding_settings
             WHERE relation_id = :relation_id'
        );

        $stmt->execute([
            ':relation_id' => $relationId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'inheritParentValue' => true,
                'boundFieldReadonly' => true,
            ];
        }

        return [
            'inheritParentValue' =>
                (bool)$row['inherit_parent_value'],
            'boundFieldReadonly' =>
                (bool)$row['bound_field_readonly'],
        ];
    }

    public function save(
        string $relationId,
        bool $boundFieldReadonly
    ): void {
        // inherit_parent_value bleibt für echte Bindungen verbindlich TRUE.
        $driver = (string)$this->pdo->getAttribute(
            PDO::ATTR_DRIVER_NAME
        );

        if ($driver === 'sqlite') {
            $sql = '
                INSERT INTO df_bound_form_binding_settings
                    (
                        relation_id,
                        inherit_parent_value,
                        bound_field_readonly
                    )
                VALUES
                    (
                        :relation_id,
                        1,
                        :bound_field_readonly
                    )
                ON CONFLICT(relation_id)
                DO UPDATE SET
                    inherit_parent_value = 1,
                    bound_field_readonly = excluded.bound_field_readonly,
                    updated_at = CURRENT_TIMESTAMP
            ';
        } else {
            $sql = '
                INSERT INTO df_bound_form_binding_settings
                    (
                        relation_id,
                        inherit_parent_value,
                        bound_field_readonly
                    )
                VALUES
                    (
                        :relation_id,
                        1,
                        :bound_field_readonly
                    )
                ON DUPLICATE KEY UPDATE
                    inherit_parent_value = 1,
                    bound_field_readonly = VALUES(bound_field_readonly),
                    updated_at = CURRENT_TIMESTAMP
            ';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':relation_id' => $relationId,
            ':bound_field_readonly' =>
                $boundFieldReadonly ? 1 : 0,
        ]);
    }
}
