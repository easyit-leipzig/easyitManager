<?php
declare(strict_types=1);

final class RelationManager
{
    public const SEMANTICS = 'parent-child-v2';
    public const LOOKUP_SEMANTICS = 'lookup-n-to-one-v1';
    public const BASE_TABLE_LOOKUP_SEMANTICS = 'lookup-base-table-v1';

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dataform_relations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                relation_type VARCHAR(10) NOT NULL,
                source_dataform_id BIGINT UNSIGNED NOT NULL,
                source_field_id BIGINT UNSIGNED NULL,
                target_dataform_id BIGINT UNSIGNED NOT NULL,
                target_display_field_id BIGINT UNSIGNED NULL,
                junction_name VARCHAR(190) NULL,
                lookup_field_id BIGINT UNSIGNED NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                configuration_json LONGTEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_relation_source (source_dataform_id),
                INDEX idx_relation_target (target_dataform_id),
                UNIQUE KEY uq_relation_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * HF38 expects bound-table metadata to be synchronized by DataFormManager
     * before this method is called. It still performs an independent physical
     * validation so phantom metadata can never enter a relation.
     *
     * Returns only fields that are actually usable by the DataForm runtime.
     * For schema-bound DataForms a metadata-only field is deliberately
     * excluded when its physical column does not exist.
     */
    public static function selectableFields(PDO $pdo): array
    {
        $rows=$pdo->query(
            'SELECT id,dataform_id,name,label,field_type,configuration_json
             FROM dataform_fields
             ORDER BY dataform_id,position,id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $result=[];
        foreach ($rows as $row) {
            $state=self::fieldStorageStateByRow($pdo,$row);
            if (!$state['usable']) {
                continue;
            }
            $row['storage_column']=$state['column'];
            $row['storage_table']=$state['table'];
            $row['is_physical']=$state['physical'];
            $result[]=$row;
        }
        return $result;
    }


    public static function selectableBaseTables(PDO $pdo): array
    {
        $rows=$pdo->query(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE()
               AND TABLE_TYPE='BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        $result=[];
        foreach ($rows as $table) {
            $table=(string)$table;
            if (
                str_starts_with($table,'dataform_')
                || str_starts_with($table,'workflow_')
                || in_array($table,[
                    'migrations','data_sources','saved_filters',
                ],true)
            ) {
                continue;
            }
            $result[]=$table;
        }
        return $result;
    }

    public static function selectableBaseTableColumns(PDO $pdo,string $table): array
    {
        if (!self::tableExists($pdo,$table)) {
            throw new RuntimeException('Die gewählte Basistabelle existiert nicht.');
        }
        return array_values(self::physicalColumns($pdo,$table));
    }

    public static function relationConfiguration(array $relation): array
    {
        return self::decodeConfig($relation['configuration_json']??null);
    }

    public static function isBaseTableLookup(array $relation): bool
    {
        $cfg=self::relationConfiguration($relation);
        return (string)($cfg['lookup_source_kind']??'')==='base_table'
            || (string)($cfg['semantics']??'')===self::BASE_TABLE_LOOKUP_SEMANTICS;
    }

    public static function baseTableLookupDescriptor(array $relation): ?array
    {
        if (!self::isBaseTableLookup($relation)) {
            return null;
        }
        $cfg=self::relationConfiguration($relation);
        $base=is_array($cfg['base_table']??null)?$cfg['base_table']:[];
        $table=trim((string)($base['table']??''));
        $key=trim((string)($base['key_column']??'id'));
        $display=trim((string)($base['display_column']??''));
        if ($table==='' || $key==='') {
            return null;
        }
        return [
            'table'=>$table,
            'key_column'=>$key,
            'display_column'=>$display,
        ];
    }

    public static function baseTableLookupOptions(PDO $pdo,array $relation): array
    {
        $desc=self::baseTableLookupDescriptor($relation);
        if ($desc===null) {
            return [];
        }
        self::validateBaseTableLookupTarget(
            $pdo,
            $desc['table'],
            $desc['key_column'],
            $desc['display_column']
        );
        $table=self::quoteIdentifier($desc['table']);
        $key=self::quoteIdentifier($desc['key_column']);
        $display=$desc['display_column']!==''?self::quoteIdentifier($desc['display_column']):$key;
        $sql='SELECT '.$key.' AS lookup_id, '.$display.' AS lookup_caption '
            .'FROM '.$table.' ORDER BY '.$display.', '.$key.' LIMIT 5000';
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $out=[];
        foreach ($rows as $row) {
            $id=(string)($row['lookup_id']??'');
            if ($id==='') {
                continue;
            }
            $caption=trim((string)($row['lookup_caption']??''));
            $out[]=[
                'id'=>$id,
                'caption'=>$caption!==''?$caption:'#'.$id,
            ];
        }
        return $out;
    }

    public static function baseTableLookupValueExists(
        PDO $pdo,
        array $relation,
        string|int $value
    ): bool {
        $desc=self::baseTableLookupDescriptor($relation);
        if ($desc===null || (string)$value==='') {
            return false;
        }
        self::validateBaseTableLookupTarget(
            $pdo,
            $desc['table'],
            $desc['key_column'],
            $desc['display_column']
        );
        $sql='SELECT 1 FROM '.self::quoteIdentifier($desc['table'])
            .' WHERE '.self::quoteIdentifier($desc['key_column']).'=? LIMIT 1';
        $stmt=$pdo->prepare($sql);
        $stmt->execute([(string)$value]);
        return $stmt->fetchColumn()!==false;
    }

    public static function baseTableLookupCaption(
        PDO $pdo,
        array $relation,
        string|int $value
    ): ?string {
        $desc=self::baseTableLookupDescriptor($relation);
        if ($desc===null || (string)$value==='') {
            return null;
        }
        self::validateBaseTableLookupTarget(
            $pdo,
            $desc['table'],
            $desc['key_column'],
            $desc['display_column']
        );
        $display=$desc['display_column']!==''?$desc['display_column']:$desc['key_column'];
        $sql='SELECT '.self::quoteIdentifier($display).' FROM '
            .self::quoteIdentifier($desc['table']).' WHERE '
            .self::quoteIdentifier($desc['key_column']).'=? LIMIT 1';
        $stmt=$pdo->prepare($sql);
        $stmt->execute([(string)$value]);
        $caption=$stmt->fetchColumn();
        if ($caption===false) {
            return null;
        }
        $caption=trim((string)$caption);
        return $caption!==''?$caption:'#'.(string)$value;
    }

    public static function dataformLookupDescriptor(PDO $pdo,array $relation): ?array
    {
        if ((string)($relation['relation_type']??'')!=='n:1' || self::isBaseTableLookup($relation)) {
            return null;
        }
        $targetDataformId=(int)($relation['target_dataform_id']??0);
        if ($targetDataformId<1) {
            return null;
        }
        $cfg=self::relationConfiguration($relation);
        $keyFieldId=(int)($cfg['target_key_field_id']??0);
        $displayFieldId=(int)($relation['target_display_field_id']??($cfg['target_display_field_id']??0));
        $keyFieldName='';
        $displayFieldName='';
        if ($keyFieldId>0) {
            $keyField=self::fieldById($pdo,$keyFieldId);
            if ($keyField===null || (int)$keyField['dataform_id']!==$targetDataformId) {
                return null;
            }
            $keyFieldName=(string)$keyField['name'];
        }
        if ($displayFieldId>0) {
            $displayField=self::fieldById($pdo,$displayFieldId);
            if ($displayField!==null && (int)$displayField['dataform_id']===$targetDataformId) {
                $displayFieldName=(string)$displayField['name'];
            }
        }
        return [
            'target_dataform_id'=>$targetDataformId,
            'key_field_id'=>$keyFieldId,
            'key_field_name'=>$keyFieldName,
            'display_field_id'=>$displayFieldId,
            'display_field_name'=>$displayFieldName,
            'render_as_lookup'=>!array_key_exists('render_as_lookup',$cfg) || !empty($cfg['render_as_lookup']),
        ];
    }

    public static function dataformLookupOptions(PDO $pdo,array $relation): array
    {
        $desc=self::dataformLookupDescriptor($pdo,$relation);
        if ($desc===null) {
            return [];
        }
        $out=[];
        foreach (DataFormRecordStore::all($pdo,(int)$desc['target_dataform_id']) as $record) {
            $data=(array)($record['data']??[]);
            $value=$desc['key_field_id']>0
                ? trim((string)($data[$desc['key_field_name']]??''))
                : (string)(int)($record['id']??0);
            if ($value==='') {
                continue;
            }
            $caption=$desc['display_field_name']!==''
                ? trim((string)($data[$desc['display_field_name']]??''))
                : '';
            if ($caption==='') {
                $caption='#'.$value;
            }
            $out[]=['id'=>$value,'caption'=>$caption];
        }
        return $out;
    }

    public static function dataformLookupValueExists(PDO $pdo,array $relation,string|int $value): bool
    {
        $desc=self::dataformLookupDescriptor($pdo,$relation);
        if ($desc===null || (string)$value==='') {
            return false;
        }
        if ($desc['key_field_id']===0) {
            return (int)$value>0 && DataFormRecordStore::find(
                $pdo,
                (int)$desc['target_dataform_id'],
                (int)$value
            )!==null;
        }
        foreach (DataFormRecordStore::all($pdo,(int)$desc['target_dataform_id']) as $record) {
            if ((string)($record['data'][$desc['key_field_name']]??'')===(string)$value) {
                return true;
            }
        }
        return false;
    }

    public static function dataformLookupCaption(PDO $pdo,array $relation,string|int $value): ?string
    {
        $desc=self::dataformLookupDescriptor($pdo,$relation);
        if ($desc===null || (string)$value==='') {
            return null;
        }
        $record=null;
        if ($desc['key_field_id']===0) {
            $record=DataFormRecordStore::find($pdo,(int)$desc['target_dataform_id'],(int)$value);
        } else {
            foreach (DataFormRecordStore::all($pdo,(int)$desc['target_dataform_id']) as $candidate) {
                if ((string)($candidate['data'][$desc['key_field_name']]??'')===(string)$value) {
                    $record=$candidate;
                    break;
                }
            }
        }
        if ($record===null) {
            return null;
        }
        $data=(array)($record['data']??[]);
        $caption=$desc['display_field_name']!==''
            ? trim((string)($data[$desc['display_field_name']]??''))
            : '';
        return $caption!==''?$caption:'#'.(string)$value;
    }

    public static function rendersAsLookup(array $relation): bool
    {
        $cfg=self::relationConfiguration($relation);
        return !array_key_exists('render_as_lookup',$cfg) || !empty($cfg['render_as_lookup']);
    }

    /**
     * Validates the persisted relation against the current DataForm and
     * physical table schema. This prevents a metadata-only field from being
     * shown as a healthy foreign key.
     */
    public static function relationHealth(PDO $pdo,array $relation): array
    {
        $type=(string)($relation['relation_type']??'');
        if ($type==='n:1') {
            $sourceId=(int)($relation['source_dataform_id']??0);
            $targetId=(int)($relation['target_dataform_id']??0);
            $sourceFieldId=(int)($relation['source_field_id']??0);
            $displayId=(int)($relation['target_display_field_id']??0);

            if ($sourceId<1) {
                return ['valid'=>false,'message'=>'Das Ausgangs-DataForm ist ungültig.'];
            }
            if ($sourceFieldId<1) {
                return ['valid'=>false,'message'=>'Kein Zuordnungsfeld im Ausgangs-DataForm zugeordnet.'];
            }
            $sourceField=self::fieldById($pdo,$sourceFieldId);
            if ($sourceField===null || (int)$sourceField['dataform_id']!==$sourceId) {
                return ['valid'=>false,'message'=>'Das Zuordnungsfeld gehört nicht zum Ausgangs-DataForm.'];
            }
            $state=self::fieldStorageStateByRow($pdo,$sourceField);
            if (!$state['usable']) {
                return ['valid'=>false,'message'=>$state['message']!==''?$state['message']:'Das Zuordnungsfeld besitzt keinen gültigen Datenspeicher.'];
            }

            if (self::isBaseTableLookup($relation)) {
                $desc=self::baseTableLookupDescriptor($relation);
                if ($desc===null) {
                    return ['valid'=>false,'message'=>'Die Basistabellen-Lookup-Konfiguration ist unvollständig.'];
                }
                try {
                    self::validateBaseTableLookupTarget(
                        $pdo,
                        $desc['table'],
                        $desc['key_column'],
                        $desc['display_column']
                    );
                } catch (Throwable $e) {
                    return ['valid'=>false,'message'=>$e->getMessage()];
                }
                return ['valid'=>true,'message'=>''];
            }

            if ($targetId<1 || $sourceId===$targetId) {
                return ['valid'=>false,'message'=>'Ausgangs- oder Lookup-DataForm ist ungültig.'];
            }
            $cfg=self::relationConfiguration($relation);
            $targetKeyFieldId=(int)($cfg['target_key_field_id']??0);
            if ($targetKeyFieldId>0) {
                $targetKeyField=self::fieldById($pdo,$targetKeyFieldId);
                if ($targetKeyField===null || (int)$targetKeyField['dataform_id']!==$targetId) {
                    return ['valid'=>false,'message'=>'Das Lookup-Schlüsselfeld gehört nicht zum Lookup-DataForm.'];
                }
                $keyState=self::fieldStorageStateByRow($pdo,$targetKeyField);
                if (!$keyState['usable']) {
                    return ['valid'=>false,'message'=>'Das Lookup-Schlüsselfeld besitzt keinen gültigen Datenspeicher.'];
                }
            }
            if ($displayId>0) {
                $display=self::fieldById($pdo,$displayId);
                if ($display===null || (int)$display['dataform_id']!==$targetId) {
                    return ['valid'=>false,'message'=>'Das Anzeigefeld gehört nicht zum Lookup-DataForm.'];
                }
                $displayState=self::fieldStorageStateByRow($pdo,$display);
                if (!$displayState['usable']) {
                    return ['valid'=>false,'message'=>'Das Lookup-Anzeigefeld besitzt keinen gültigen Datenspeicher.'];
                }
            }
            return ['valid'=>true,'message'=>''];
        }

        if ($type !== '1:n') {
            return ['valid'=>true,'message'=>''];
        }

        $parentId=(int)($relation['source_dataform_id']??0);
        $childId=(int)($relation['target_dataform_id']??0);
        $lookupId=(int)($relation['lookup_field_id']??0);

        if ($parentId<1 || $childId<1 || $parentId===$childId) {
            return [
                'valid'=>false,
                'message'=>'Eltern- oder Kind-DataForm ist ungültig.',
            ];
        }
        if ($lookupId<1) {
            return [
                'valid'=>false,
                'message'=>'Kein Fremdschlüsselfeld im Kind zugeordnet.',
            ];
        }

        $field=self::fieldById($pdo,$lookupId);
        if ($field===null) {
            return [
                'valid'=>false,
                'message'=>'Das zugeordnete Kind-Fremdschlüsselfeld existiert nicht mehr.',
            ];
        }
        if ((int)$field['dataform_id'] !== $childId) {
            return [
                'valid'=>false,
                'message'=>'Das Fremdschlüsselfeld gehört nicht zum Kind-DataForm.',
            ];
        }

        $state=self::fieldStorageStateByRow($pdo,$field);
        if (!$state['usable']) {
            return [
                'valid'=>false,
                'message'=>$state['message'] !== ''
                    ? $state['message']
                    : 'Das Fremdschlüsselfeld besitzt keinen gültigen Datenspeicher.',
            ];
        }

        $displayId=(int)($relation['target_display_field_id']??0);
        if ($displayId>0) {
            $display=self::fieldById($pdo,$displayId);
            if ($display===null || (int)$display['dataform_id'] !== $parentId) {
                return [
                    'valid'=>false,
                    'message'=>'Das Eltern-Anzeigefeld gehört nicht zum Eltern-DataForm.',
                ];
            }
            $displayState=self::fieldStorageStateByRow($pdo,$display);
            if (!$displayState['usable']) {
                return [
                    'valid'=>false,
                    'message'=>'Das Eltern-Anzeigefeld besitzt keinen gültigen Datenspeicher.',
                ];
            }
        }

        return ['valid'=>true,'message'=>''];
    }

    public static function updateOneToMany(
        PDO $pdo,
        int $relationId,
        string $name,
        int $parentDataformId,
        int $childDataformId,
        int $parentDisplayFieldId,
        int $childFkFieldId,
        bool $createChildField,
        bool $required,
        string $requestedFieldName='',
        bool $boundFieldReadonly=true
    ): void {
        self::ensureSchema($pdo);
        if ($relationId<1) {
            throw new RuntimeException('Die zu bearbeitende Beziehung ist ungültig.');
        }
        $exists=$pdo->prepare(
            'SELECT is_enabled,configuration_json FROM dataform_relations WHERE id=? LIMIT 1'
        );
        $exists->execute([$relationId]);
        $existingRelation=$exists->fetch(PDO::FETCH_ASSOC);
        if (!$existingRelation) {
            throw new RuntimeException('Die zu bearbeitende Beziehung wurde nicht gefunden.');
        }
        $existingCfg=self::decodeConfig($existingRelation['configuration_json']??null);
        $restoreAfterRepair=!empty($existingCfg['invalid_auto_disabled_hf37']);
        $nextEnabled=$restoreAfterRepair?1:(int)($existingRelation['is_enabled']??1);
        self::assertUniqueRelationName($pdo,$name,$relationId);

        self::validateOneToManyEndpoints(
            $pdo,
            $parentDataformId,
            $childDataformId,
            $parentDisplayFieldId
        );

        $autoPhysical=false;
        if ($childFkFieldId>0) {
                self::assertFieldUsableInDataform(
                    $pdo,
                    $childFkFieldId,
                    $childDataformId,
                    'Das Fremdschlüsselfeld muss als reales Feld zum Kind-DataForm gehören.'
                );
            } elseif ($createChildField) {
                $created=self::createChildForeignKeyField(
                    $pdo,
                    $name,
                    $parentDataformId,
                    $childDataformId,
                    $parentDisplayFieldId,
                    $required,
                    $requestedFieldName
                );
                $childFkFieldId=(int)$created['id'];
                $autoPhysical=(bool)$created['physical'];
        } else {
            throw new RuntimeException(
                'Wählen Sie ein vorhandenes Fremdschlüsselfeld im Kind oder legen Sie ausdrücklich ein neues Feld an.'
            );
        }

        $pdo->beginTransaction();
        try {
            $cfg=[
                'semantics'=>self::SEMANTICS,
                'parent_key'=>'id',
                'child_fk_field_id'=>$childFkFieldId,
                'auto_form'=>$createChildField,
                'auto_physical'=>$autoPhysical,
                // PUBLISH18: Das gekoppelte Kind-FK ist standardmäßig read-only.
                'bound_field_readonly'=>$boundFieldReadonly,
            ];

            $stmt=$pdo->prepare(
                'UPDATE dataform_relations
                 SET name=?,relation_type=?,source_dataform_id=?,target_dataform_id=?,
                     source_field_id=NULL,target_display_field_id=?,lookup_field_id=?,junction_name=NULL,
                     is_required=?,is_enabled=?,configuration_json=?
                 WHERE id=?'
            );
            $stmt->execute([
                $name,
                '1:n',
                $parentDataformId,
                $childDataformId,
                $parentDisplayFieldId?:null,
                $childFkFieldId,
                $required?1:0,
                $nextEnabled,
                json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $relationId,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function repairLegacyOneToMany(PDO $pdo): array
    {
        self::ensureSchema($pdo);

        $relations=$pdo->query(
            "SELECT *
             FROM dataform_relations
             WHERE relation_type='1:n'
             ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $repaired=0;
        $warnings=[];

        foreach ($relations as $relation) {
            $cfg=self::decodeConfig($relation['configuration_json']??null);
            $relationId=(int)$relation['id'];
            $sourceId=(int)$relation['source_dataform_id'];
            $targetId=(int)$relation['target_dataform_id'];
            $lookupFieldId=(int)($relation['lookup_field_id']??0);

            // HF37: even relations already carrying the v2 semantics are
            // revalidated. Older releases could create a metadata-only
            // lookup which never existed as a physical child-table column.
            if (($cfg['semantics']??'') === self::SEMANTICS) {
                $health=self::relationHealth($pdo,$relation);
                if ($health['valid']) {
                    continue;
                }

                $candidates=self::fkCandidates(
                    $pdo,
                    $targetId,
                    $lookupFieldId>0?[$lookupFieldId]:[]
                );
                if (count($candidates)===1) {
                    $candidate=$candidates[0];
                    $cfg['child_fk_field_id']=(int)$candidate['id'];
                    $cfg['physical_fk_repaired']=true;
                    $cfg['invalid_lookup_field_id']=$lookupFieldId?:null;
                    $stmt=$pdo->prepare(
                        'UPDATE dataform_relations
                         SET lookup_field_id=?,configuration_json=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        (int)$candidate['id'],
                        json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        $relationId,
                    ]);

                    // Remove only a metadata-only synthetic lookup. Never
                    // remove a real physical column during automatic repair.
                    if ($lookupFieldId>0) {
                        $oldField=self::fieldById($pdo,$lookupFieldId);
                        if ($oldField!==null) {
                            $oldState=self::fieldStorageStateByRow($pdo,$oldField);
                            if (
                                !$oldState['usable']
                                && (string)$oldField['field_type']==='lookup'
                                && self::relationUseCount($pdo,$lookupFieldId,$relationId)===0
                            ) {
                                $delete=$pdo->prepare(
                                    'DELETE FROM dataform_fields WHERE id=?'
                                );
                                $delete->execute([$lookupFieldId]);
                            }
                        }
                    }
                    $repaired++;
                    continue;
                }

                if ((int)($relation['is_enabled']??0)===1) {
                    $cfg['invalid_auto_disabled_hf37']=true;
                    $cfg['invalid_reason_hf37']=$health['message'];
                    $stmt=$pdo->prepare(
                        'UPDATE dataform_relations SET is_enabled=0,configuration_json=? WHERE id=?'
                    );
                    $stmt->execute([
                        json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        $relationId,
                    ]);
                }
                $warnings[]='Beziehung #'.$relationId.' ist fehlerhaft und wurde vorsorglich deaktiviert: '
                    .$health['message'].' Bitte im Beziehungsdesigner bearbeiten.';
                continue;
            }

            if ($lookupFieldId < 1) {
                $warnings[]='Beziehung #'.$relationId
                    .' besitzt kein Kind-Fremdschlüsselfeld und muss im Beziehungsdesigner ergänzt werden.';
                continue;
            }

            $lookup=self::fieldById($pdo,$lookupFieldId);
            if ($lookup === null) {
                $warnings[]='Beziehung #'.$relationId
                    .' verweist auf ein nicht mehr vorhandenes Lookup-Feld.';
                continue;
            }

            $lookupFormId=(int)$lookup['dataform_id'];

            // Already stored in the target/child side. A table-bound
            // DataForm additionally requires the physical column to exist.
            if (
                $lookupFormId === $targetId
                && self::fieldStorageStateByRow($pdo,$lookup)['usable']
            ) {
                $cfg['semantics']=self::SEMANTICS;
                $cfg['parent_key']='id';
                $cfg['child_fk_field_id']=$lookupFieldId;

                $displayFieldId=(int)(
                    $relation['target_display_field_id']??0
                );
                if (
                    $displayFieldId>0
                    && !self::fieldBelongs(
                        $pdo,
                        $displayFieldId,
                        $sourceId
                    )
                ) {
                    $stmt=$pdo->prepare(
                        'UPDATE dataform_relations
                         SET target_display_field_id=NULL
                         WHERE id=?'
                    );
                    $stmt->execute([$relationId]);
                }

                self::writeConfig($pdo,$relationId,$cfg);
                $repaired++;
                continue;
            }

            if ($lookupFormId !== $sourceId) {
                $warnings[]='Beziehung #'.$relationId
                    .' ist nicht eindeutig zuordenbar.';
                continue;
            }

            $sourceCandidates=self::fkCandidates(
                $pdo,
                $sourceId,
                [$lookupFieldId]
            );
            $targetCandidates=self::fkCandidates(
                $pdo,
                $targetId,
                []
            );

            // Special but common case produced by the old UI:
            // The user selected Parent as "source" and Child as "target".
            // HF22 generated a synthetic lookup in the parent although the
            // real child already has exactly one *_id / to_*_id field.
            if (
                count($targetCandidates) === 1
                && count($sourceCandidates) === 0
            ) {
                $childFk=$targetCandidates[0];

                $pdo->beginTransaction();
                try {
                    $displayFieldId=(int)(
                        $relation['target_display_field_id']??0
                    );
                    $normalizedDisplayFieldId=(
                        $displayFieldId>0
                        && self::fieldBelongs(
                            $pdo,
                            $displayFieldId,
                            $sourceId
                        )
                    )
                        ? $displayFieldId
                        : null;

                    $stmt=$pdo->prepare(
                        'UPDATE dataform_relations
                         SET lookup_field_id=?,
                             target_display_field_id=?,
                             configuration_json=?
                         WHERE id=?'
                    );
                    $cfg['semantics']=self::SEMANTICS;
                    $cfg['parent_key']='id';
                    $cfg['child_fk_field_id']=(int)$childFk['id'];
                    $cfg['legacy_direction_repaired']=true;
                    $cfg['legacy_lookup_field_id']=$lookupFieldId;
                    $stmt->execute([
                        (int)$childFk['id'],
                        $normalizedDisplayFieldId,
                        json_encode(
                            $cfg,
                            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                        ),
                        $relationId,
                    ]);

                    if (
                        !empty($cfg['auto_form'])
                        && (string)$lookup['field_type']==='lookup'
                        && self::relationUseCount(
                            $pdo,
                            $lookupFieldId,
                            $relationId
                        )===0
                    ) {
                        $delete=$pdo->prepare(
                            'DELETE FROM dataform_fields
                             WHERE id=? AND dataform_id=? AND field_type=?'
                        );
                        $delete->execute([
                            $lookupFieldId,
                            $sourceId,
                            'lookup',
                        ]);
                    }

                    $pdo->commit();
                    $repaired++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                continue;
            }

            // Default migration for the documented legacy semantics:
            // old source = child, old target = parent. Swap both endpoints;
            // lookup_field_id already belongs to the child.
            $pdo->beginTransaction();
            try {
                $cfg['semantics']=self::SEMANTICS;
                $cfg['parent_key']='id';
                $cfg['child_fk_field_id']=$lookupFieldId;
                $cfg['legacy_direction_swapped']=true;

                $stmt=$pdo->prepare(
                    'UPDATE dataform_relations
                     SET source_dataform_id=?,
                         target_dataform_id=?,
                         configuration_json=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $targetId,
                    $sourceId,
                    json_encode(
                        $cfg,
                        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                    ),
                    $relationId,
                ]);
                $pdo->commit();
                $repaired++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return [
            'repaired'=>$repaired,
            'warnings'=>$warnings,
        ];
    }

    public static function createOneToMany(
        PDO $pdo,
        string $name,
        int $parentDataformId,
        int $childDataformId,
        int $parentDisplayFieldId,
        int $childFkFieldId,
        bool $createChildField,
        bool $required,
        string $requestedFieldName='',
        bool $boundFieldReadonly=true
    ): int {
        self::ensureSchema($pdo);
        self::assertUniqueRelationName($pdo,$name,0);
        self::validateOneToManyEndpoints(
            $pdo,
            $parentDataformId,
            $childDataformId,
            $parentDisplayFieldId
        );

        $autoPhysical=false;
        if ($childFkFieldId>0) {
                self::assertFieldUsableInDataform(
                    $pdo,
                    $childFkFieldId,
                    $childDataformId,
                    'Das Fremdschlüsselfeld muss als reales Feld zum Kind-DataForm gehören.'
                );
            } elseif ($createChildField) {
                $created=self::createChildForeignKeyField(
                    $pdo,
                    $name,
                    $parentDataformId,
                    $childDataformId,
                    $parentDisplayFieldId,
                    $required,
                    $requestedFieldName
                );
                $childFkFieldId=(int)$created['id'];
                $autoPhysical=(bool)$created['physical'];
        } else {
            throw new RuntimeException(
                'Wählen Sie ein vorhandenes Fremdschlüsselfeld im Kind oder legen Sie ausdrücklich ein neues Feld an.'
            );
        }

        $pdo->beginTransaction();
        try {
            $cfg=[
                'semantics'=>self::SEMANTICS,
                'parent_key'=>'id',
                'child_fk_field_id'=>$childFkFieldId,
                'auto_form'=>$createChildField,
                'auto_physical'=>$autoPhysical,
                // PUBLISH18: Das gekoppelte Kind-FK ist standardmäßig read-only.
                'bound_field_readonly'=>$boundFieldReadonly,
            ];

            $insert=$pdo->prepare(
                'INSERT INTO dataform_relations
                 (name,relation_type,source_dataform_id,target_dataform_id,
                  target_display_field_id,lookup_field_id,is_required,
                  configuration_json)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $insert->execute([
                $name,
                '1:n',
                $parentDataformId,
                $childDataformId,
                $parentDisplayFieldId?:null,
                $childFkFieldId,
                $required?1:0,
                json_encode(
                    $cfg,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                ),
            ]);

            $id=(int)$pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }


    public static function createManyToOne(
        PDO $pdo,
        string $name,
        int $sourceDataformId,
        int $targetDataformId,
        int $sourceFieldId,
        int $targetDisplayFieldId,
        bool $required,
        int $targetKeyFieldId = 0,
        bool $renderAsLookup = true
    ): int {
        self::ensureSchema($pdo);
        self::assertUniqueRelationName($pdo,$name,0);
        self::validateManyToOneEndpoints(
            $pdo,
            $sourceDataformId,
            $targetDataformId,
            $sourceFieldId,
            $targetDisplayFieldId
        );
        if ($targetKeyFieldId>0) {
            self::assertFieldUsableInDataform(
                $pdo,
                $targetKeyFieldId,
                $targetDataformId,
                'Das Schlüsselfeld muss als reales Feld zum Lookup-/Referenz-DataForm gehören.'
            );
        }

        $cfg=[
            'semantics'=>self::LOOKUP_SEMANTICS,
            'source_fk_field_id'=>$sourceFieldId,
            'target_key'=>$targetKeyFieldId>0?'field':'id',
            'target_key_field_id'=>$targetKeyFieldId?:null,
            'target_display_field_id'=>$targetDisplayFieldId?:null,
            'render_as_lookup'=>$renderAsLookup,
        ];

        $stmt=$pdo->prepare(
            'INSERT INTO dataform_relations
             (name,relation_type,source_dataform_id,source_field_id,
              target_dataform_id,target_display_field_id,lookup_field_id,
              junction_name,is_required,configuration_json)
             VALUES (?,?,?,?,?,?,NULL,NULL,?,?)'
        );
        $stmt->execute([
            $name,
            'n:1',
            $sourceDataformId,
            $sourceFieldId,
            $targetDataformId,
            $targetDisplayFieldId?:null,
            $required?1:0,
            json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateManyToOne(
        PDO $pdo,
        int $relationId,
        string $name,
        int $sourceDataformId,
        int $targetDataformId,
        int $sourceFieldId,
        int $targetDisplayFieldId,
        bool $required,
        int $targetKeyFieldId = 0,
        bool $renderAsLookup = true
    ): void {
        self::ensureSchema($pdo);
        if ($relationId<1) {
            throw new RuntimeException('Die zu bearbeitende Lookup-Beziehung ist ungültig.');
        }
        self::assertUniqueRelationName($pdo,$name,$relationId);
        self::validateManyToOneEndpoints(
            $pdo,
            $sourceDataformId,
            $targetDataformId,
            $sourceFieldId,
            $targetDisplayFieldId
        );
        if ($targetKeyFieldId>0) {
            self::assertFieldUsableInDataform(
                $pdo,
                $targetKeyFieldId,
                $targetDataformId,
                'Das Schlüsselfeld muss als reales Feld zum Lookup-/Referenz-DataForm gehören.'
            );
        }

        $state=$pdo->prepare('SELECT is_enabled FROM dataform_relations WHERE id=? LIMIT 1');
        $state->execute([$relationId]);
        $enabled=$state->fetchColumn();
        if ($enabled===false) {
            throw new RuntimeException('Die zu bearbeitende Lookup-Beziehung wurde nicht gefunden.');
        }

        $cfg=[
            'semantics'=>self::LOOKUP_SEMANTICS,
            'source_fk_field_id'=>$sourceFieldId,
            'target_key'=>$targetKeyFieldId>0?'field':'id',
            'target_key_field_id'=>$targetKeyFieldId?:null,
            'target_display_field_id'=>$targetDisplayFieldId?:null,
            'render_as_lookup'=>$renderAsLookup,
        ];

        $stmt=$pdo->prepare(
            'UPDATE dataform_relations
             SET name=?,relation_type=?,source_dataform_id=?,source_field_id=?,
                 target_dataform_id=?,target_display_field_id=?,
                 lookup_field_id=NULL,junction_name=NULL,is_required=?,
                 configuration_json=?
             WHERE id=?'
        );
        $stmt->execute([
            $name,
            'n:1',
            $sourceDataformId,
            $sourceFieldId,
            $targetDataformId,
            $targetDisplayFieldId?:null,
            $required?1:0,
            json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $relationId,
        ]);
    }


    public static function createManyToOneBaseTable(
        PDO $pdo,
        string $name,
        int $sourceDataformId,
        int $sourceFieldId,
        string $table,
        string $keyColumn,
        string $displayColumn,
        bool $required
    ): int {
        self::ensureSchema($pdo);
        self::assertUniqueRelationName($pdo,$name,0);
        self::assertDataform($pdo,$sourceDataformId);
        self::assertFieldUsableInDataform(
            $pdo,
            $sourceFieldId,
            $sourceDataformId,
            'Das Zuordnungsfeld muss als reales Feld zum Ausgangs-DataForm gehören.'
        );
        self::validateBaseTableLookupTarget(
            $pdo,
            $table,
            $keyColumn,
            $displayColumn
        );

        $cfg=[
            'semantics'=>self::BASE_TABLE_LOOKUP_SEMANTICS,
            'lookup_source_kind'=>'base_table',
            'source_fk_field_id'=>$sourceFieldId,
            'base_table'=>[
                'table'=>$table,
                'key_column'=>$keyColumn,
                'display_column'=>$displayColumn,
            ],
        ];

        // target_dataform_id is structurally NOT NULL in legacy schemas.
        // For a base-table lookup it acts only as a compatibility placeholder;
        // the real lookup target is stored exclusively in configuration_json.
        $stmt=$pdo->prepare(
            'INSERT INTO dataform_relations
             (name,relation_type,source_dataform_id,source_field_id,
              target_dataform_id,target_display_field_id,lookup_field_id,
              junction_name,is_required,configuration_json)
             VALUES (?,?,?,?,?,NULL,NULL,NULL,?,?)'
        );
        $stmt->execute([
            $name,
            'n:1',
            $sourceDataformId,
            $sourceFieldId,
            $sourceDataformId,
            $required?1:0,
            json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function updateManyToOneBaseTable(
        PDO $pdo,
        int $relationId,
        string $name,
        int $sourceDataformId,
        int $sourceFieldId,
        string $table,
        string $keyColumn,
        string $displayColumn,
        bool $required
    ): void {
        self::ensureSchema($pdo);
        if ($relationId<1) {
            throw new RuntimeException('Die zu bearbeitende Basistabellen-Lookup-Beziehung ist ungültig.');
        }
        self::assertUniqueRelationName($pdo,$name,$relationId);
        self::assertDataform($pdo,$sourceDataformId);
        self::assertFieldUsableInDataform(
            $pdo,
            $sourceFieldId,
            $sourceDataformId,
            'Das Zuordnungsfeld muss als reales Feld zum Ausgangs-DataForm gehören.'
        );
        self::validateBaseTableLookupTarget(
            $pdo,
            $table,
            $keyColumn,
            $displayColumn
        );

        $state=$pdo->prepare('SELECT is_enabled FROM dataform_relations WHERE id=? LIMIT 1');
        $state->execute([$relationId]);
        $enabled=$state->fetchColumn();
        if ($enabled===false) {
            throw new RuntimeException('Die zu bearbeitende Lookup-Beziehung wurde nicht gefunden.');
        }

        $cfg=[
            'semantics'=>self::BASE_TABLE_LOOKUP_SEMANTICS,
            'lookup_source_kind'=>'base_table',
            'source_fk_field_id'=>$sourceFieldId,
            'base_table'=>[
                'table'=>$table,
                'key_column'=>$keyColumn,
                'display_column'=>$displayColumn,
            ],
        ];

        $stmt=$pdo->prepare(
            'UPDATE dataform_relations
             SET name=?,relation_type=?,source_dataform_id=?,source_field_id=?,
                 target_dataform_id=?,target_display_field_id=NULL,
                 lookup_field_id=NULL,junction_name=NULL,is_required=?,
                 configuration_json=?
             WHERE id=?'
        );
        $stmt->execute([
            $name,
            'n:1',
            $sourceDataformId,
            $sourceFieldId,
            $sourceDataformId,
            $required?1:0,
            json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            $relationId,
        ]);
    }

    private static function validateBaseTableLookupTarget(
        PDO $pdo,
        string $table,
        string $keyColumn,
        string $displayColumn
    ): void {
        $table=trim($table);
        $keyColumn=trim($keyColumn);
        $displayColumn=trim($displayColumn);

        if (
            preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$table)!==1
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$keyColumn)!==1
            || (
                $displayColumn!==''
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$displayColumn)!==1
            )
        ) {
            throw new RuntimeException('Basistabelle oder Spaltenname ist ungültig.');
        }
        if (!self::tableExists($pdo,$table)) {
            throw new RuntimeException('Die Lookup-Basistabelle „'.$table.'“ existiert nicht.');
        }

        $columns=self::physicalColumns($pdo,$table,true);
        if (!isset($columns[strtolower($keyColumn)])) {
            throw new RuntimeException(
                'Das Schlüsselfeld „'.$keyColumn.'“ existiert nicht in der Basistabelle „'.$table.'“.'
            );
        }
        if (
            $displayColumn!==''
            && !isset($columns[strtolower($displayColumn)])
        ) {
            throw new RuntimeException(
                'Das Anzeigefeld „'.$displayColumn.'“ existiert nicht in der Basistabelle „'.$table.'“.'
            );
        }
    }

    private static function validateManyToOneEndpoints(
        PDO $pdo,
        int $sourceDataformId,
        int $targetDataformId,
        int $sourceFieldId,
        int $targetDisplayFieldId
    ): void {
        if ($sourceDataformId<1 || $targetDataformId<1 || $sourceDataformId===$targetDataformId) {
            throw new RuntimeException('Ausgangs- und Lookup-DataForm müssen unterschiedlich sein.');
        }
        self::assertDataform($pdo,$sourceDataformId);
        self::assertDataform($pdo,$targetDataformId);
        self::assertFieldUsableInDataform(
            $pdo,
            $sourceFieldId,
            $sourceDataformId,
            'Das Zuordnungsfeld muss als reales Feld zum Ausgangs-DataForm gehören.'
        );
        if ($targetDisplayFieldId>0) {
            self::assertFieldUsableInDataform(
                $pdo,
                $targetDisplayFieldId,
                $targetDataformId,
                'Das Anzeigefeld muss als reales Feld zum Lookup-DataForm gehören.'
            );
        }
    }

    private static function validateOneToManyEndpoints(
        PDO $pdo,
        int $parentDataformId,
        int $childDataformId,
        int $parentDisplayFieldId
    ): void {
        if ($parentDataformId<1 || $childDataformId<1
            || $parentDataformId===$childDataformId) {
            throw new RuntimeException(
                'Eltern- und Kind-DataForm müssen unterschiedlich sein.'
            );
        }

        self::assertDataform($pdo,$parentDataformId);
        self::assertDataform($pdo,$childDataformId);

        if ($parentDisplayFieldId>0) {
            self::assertFieldUsableInDataform(
                $pdo,
                $parentDisplayFieldId,
                $parentDataformId,
                'Das Anzeigefeld muss als reales Feld zum Eltern-DataForm gehören.'
            );
        }
    }

    private static function createChildForeignKeyField(
        PDO $pdo,
        string $relationName,
        int $parentDataformId,
        int $childDataformId,
        int $parentDisplayFieldId,
        bool $required,
        string $requestedFieldName
    ): array {
        $fieldName=trim($requestedFieldName);
        if ($fieldName==='') {
            $parentSlug=self::dataformSlug($pdo,$parentDataformId);
            $fieldName='to_'.preg_replace(
                '/[^a-z0-9_]+/',
                '_',
                str_replace('-','_',strtolower($parentSlug))
            ).'_id';
            $fieldName=trim($fieldName,'_');
            if ($fieldName==='to_id') {
                $fieldName='parent_id';
            }
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$fieldName)!==1) {
            throw new RuntimeException(
                'Der Name des neuen Fremdschlüsselfeldes ist als SQL-Feldname ungültig.'
            );
        }

        $check=$pdo->prepare(
            'SELECT COUNT(*) FROM dataform_fields WHERE dataform_id=? AND name=?'
        );
        $check->execute([$childDataformId,$fieldName]);
        if ((int)$check->fetchColumn()>0) {
            throw new RuntimeException(
                'Das Feld „'.$fieldName.'“ existiert im Kind-DataForm bereits. Wählen Sie es in der Feldliste aus.'
            );
        }

        $binding=self::dataformBinding($pdo,$childDataformId);
        $physical=$binding!==null;
        $fieldType='lookup';
        $fieldCfg=[
            'relation'=>[
                'parent_dataform_id'=>$parentDataformId,
                'parent_key'=>'id',
                'display_field_id'=>$parentDisplayFieldId?:null,
            ],
            'width'=>'100',
        ];

        if ($binding!==null) {
            $table=(string)$binding['table_name'];
            $columns=self::physicalColumns($pdo,$table,true);
            if (isset($columns[strtolower($fieldName)])) {
                throw new RuntimeException(
                    'Die physische Spalte „'.$fieldName.'“ existiert bereits. Synchronisieren Sie das DataForm und wählen Sie das vorhandene Feld.'
                );
            }
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table)
                .' ADD COLUMN '.self::quoteIdentifier($fieldName)
                ." BIGINT UNSIGNED NULL COMMENT 'easyIT relation FK'"
            );
            self::physicalColumns($pdo,$table,true);
            $fieldType='number';
            $fieldCfg['table_binding']=[
                'source_kind'=>(string)($binding['source_kind']??'system'),
                'table'=>$table,
                'column'=>$fieldName,
                'sql_type'=>'bigint unsigned',
            ];
            $fieldCfg['managed_relation_fk']=true;
        }

        $pos=$pdo->prepare(
            'SELECT COALESCE(MAX(position),0)+10 FROM dataform_fields WHERE dataform_id=?'
        );
        $pos->execute([$childDataformId]);

        $insert=$pdo->prepare(
            'INSERT INTO dataform_fields
             (dataform_id,name,label,field_type,position,is_required,configuration_json)
             VALUES (?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $childDataformId,
            $fieldName,
            $relationName,
            $fieldType,
            (int)$pos->fetchColumn(),
            $required?1:0,
            json_encode($fieldCfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);

        return [
            'id'=>(int)$pdo->lastInsertId(),
            'physical'=>$physical,
            'name'=>$fieldName,
        ];
    }

    private static function fkCandidates(
        PDO $pdo,
        int $dataformId,
        array $excludeIds
    ): array {
        $stmt=$pdo->prepare(
            'SELECT id,dataform_id,name,label,field_type,configuration_json
             FROM dataform_fields
             WHERE dataform_id=?
             ORDER BY position,id'
        );
        $stmt->execute([$dataformId]);

        $result=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            if (in_array((int)$field['id'],$excludeIds,true)) {
                continue;
            }
            $name=(string)$field['name'];
            if (
                $name!=='id'
                && preg_match('/^(?:to_.+_id|.+_id)$/i',$name)===1
                && self::fieldStorageStateByRow($pdo,$field)['usable']
            ) {
                $result[]=$field;
            }
        }
        return $result;
    }

    private static function relationUseCount(
        PDO $pdo,
        int $lookupFieldId,
        int $excludeRelationId
    ): int {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM dataform_relations
             WHERE lookup_field_id=? AND id<>?'
        );
        $stmt->execute([$lookupFieldId,$excludeRelationId]);
        return (int)$stmt->fetchColumn();
    }

    private static function assertFieldUsableInDataform(
        PDO $pdo,
        int $fieldId,
        int $dataformId,
        string $message
    ): void {
        $field=self::fieldById($pdo,$fieldId);
        if ($field===null || (int)$field['dataform_id']!==$dataformId) {
            throw new RuntimeException($message);
        }
        $state=self::fieldStorageStateByRow($pdo,$field);
        if (!$state['usable']) {
            throw new RuntimeException($message.' '.$state['message']);
        }
    }

    private static function fieldStorageStateByRow(PDO $pdo,array $field): array
    {
        $dataformId=(int)($field['dataform_id']??0);
        $binding=self::dataformBinding($pdo,$dataformId);
        if ($binding===null) {
            return [
                'usable'=>true,
                'physical'=>false,
                'table'=>'',
                'column'=>(string)($field['name']??''),
                'message'=>'',
            ];
        }

        $table=(string)$binding['table_name'];
        if (!self::tableExists($pdo,$table)) {
            return [
                'usable'=>false,
                'physical'=>true,
                'table'=>$table,
                'column'=>(string)($field['name']??''),
                'message'=>'Die gebundene Tabelle „'.$table.'“ existiert nicht.',
            ];
        }
        $cfg=self::decodeConfig($field['configuration_json']??null);
        $column=(string)($field['name']??'');
        if (isset($cfg['table_binding']) && is_array($cfg['table_binding'])) {
            $configured=trim((string)($cfg['table_binding']['column']??''));
            if ($configured!=='') {
                $column=$configured;
            }
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$column)!==1) {
            return [
                'usable'=>false,
                'physical'=>true,
                'table'=>$table,
                'column'=>$column,
                'message'=>'Das Feld besitzt eine ungültige physische Spaltenbindung.',
            ];
        }

        $columns=self::physicalColumns($pdo,$table);
        if (!isset($columns[strtolower($column)])) {
            return [
                'usable'=>false,
                'physical'=>true,
                'table'=>$table,
                'column'=>$column,
                'message'=>'Die Spalte „'.$column.'“ existiert nicht in der Kindtabelle „'.$table.'“.',
            ];
        }

        return [
            'usable'=>true,
            'physical'=>true,
            'table'=>$table,
            'column'=>$column,
            'message'=>'',
        ];
    }

    private static function dataformBinding(PDO $pdo,int $dataformId): ?array
    {
        if (!self::tableExists($pdo,'dataform_table_bindings')) {
            return null;
        }
        $stmt=$pdo->prepare(
            'SELECT dataform_id,source_kind,source_id,table_name,binding_mode
             FROM dataform_table_bindings WHERE dataform_id=? LIMIT 1'
        );
        $stmt->execute([$dataformId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $table=(string)$row['table_name'];
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$table)!==1) {
            return null;
        }
        return $row;
    }

    private static function tableExists(PDO $pdo,string $table): bool
    {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn()===1;
    }

    private static function physicalColumns(
        PDO $pdo,
        string $table,
        bool $refresh=false
    ): array {
        static $cache=[];
        $key=spl_object_id($pdo).':'.strtolower($table);
        if (!$refresh && isset($cache[$key])) {
            return $cache[$key];
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$table)!==1) {
            throw new RuntimeException('Ungültiger physischer Tabellenname.');
        }
        $rows=$pdo->query(
            'SHOW FULL COLUMNS FROM '.self::quoteIdentifier($table)
        )->fetchAll(PDO::FETCH_ASSOC);
        $columns=[];
        foreach ($rows as $row) {
            $name=strtolower((string)($row['Field']??''));
            if ($name!=='') {
                $columns[$name]=$row;
            }
        }
        $cache[$key]=$columns;
        return $columns;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/',$identifier)!==1) {
            throw new RuntimeException('Ungültiger SQL-Bezeichner.');
        }
        return '`'.str_replace('`','``',$identifier).'`';
    }

    private static function fieldById(
        PDO $pdo,
        int $fieldId
    ): ?array {
        $stmt=$pdo->prepare(
            'SELECT * FROM dataform_fields WHERE id=? LIMIT 1'
        );
        $stmt->execute([$fieldId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    private static function fieldBelongs(
        PDO $pdo,
        int $fieldId,
        int $dataformId
    ): bool {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM dataform_fields
             WHERE id=? AND dataform_id=?'
        );
        $stmt->execute([$fieldId,$dataformId]);
        return (int)$stmt->fetchColumn()===1;
    }

    private static function assertFieldBelongs(
        PDO $pdo,
        int $fieldId,
        int $dataformId,
        string $message
    ): void {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM dataform_fields
             WHERE id=? AND dataform_id=?'
        );
        $stmt->execute([$fieldId,$dataformId]);
        if ((int)$stmt->fetchColumn()!==1) {
            throw new RuntimeException($message);
        }
    }

    private static function assertUniqueRelationName(
        PDO $pdo,
        string $name,
        int $excludeId
    ): void {
        $name=trim($name);
        if ($name==='') {
            throw new RuntimeException('Der Beziehungsname darf nicht leer sein.');
        }
        $stmt=$pdo->prepare(
            'SELECT id FROM dataform_relations WHERE name=?'
        );
        $stmt->execute([$name]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingId) {
            if ((int)$existingId!==$excludeId) {
                throw new RuntimeException('Eine Beziehung mit diesem Namen existiert bereits.');
            }
        }
    }

    private static function assertDataform(
        PDO $pdo,
        int $dataformId
    ): void {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM dataforms WHERE id=?'
        );
        $stmt->execute([$dataformId]);
        if ((int)$stmt->fetchColumn()!==1) {
            throw new RuntimeException(
                'Ein ausgewähltes DataForm existiert nicht.'
            );
        }
    }

    private static function dataformSlug(
        PDO $pdo,
        int $dataformId
    ): string {
        $stmt=$pdo->prepare(
            'SELECT slug FROM dataforms WHERE id=?'
        );
        $stmt->execute([$dataformId]);
        return (string)($stmt->fetchColumn()?:'parent');
    }

    private static function decodeConfig(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw)==='') {
            return [];
        }
        $cfg=json_decode($raw,true);
        return is_array($cfg)?$cfg:[];
    }

    private static function writeConfig(
        PDO $pdo,
        int $relationId,
        array $cfg
    ): void {
        $stmt=$pdo->prepare(
            'UPDATE dataform_relations
             SET configuration_json=?
             WHERE id=?'
        );
        $stmt->execute([
            json_encode(
                $cfg,
                JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
            ),
            $relationId,
        ]);
    }
}
