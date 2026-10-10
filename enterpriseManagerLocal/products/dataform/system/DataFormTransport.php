<?php
declare(strict_types=1);

require_once __DIR__.'/DataFormFieldTypes.php';
require_once __DIR__.'/DataFormRecordStore.php';

/**
 * HF76: Canonical transport adapter for CSV, REST and project packages.
 *
 * Persisted DataForm values remain strings so generic JSON records and physical
 * SQL bindings keep one storage contract. At transport boundaries values are
 * converted to/from native JSON types and portable media payloads.
 */
final class DataFormTransport
{
    /** @return array<int,array<string,mixed>> */
    public static function fields(PDO $pdo,int $dataformId): array
    {
        $stmt=$pdo->prepare('SELECT id,name,label,field_type,is_required,configuration_json,position FROM dataform_fields WHERE dataform_id=? ORDER BY position,id');
        $stmt->execute([$dataformId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$field) {
            $field['configuration']=self::configuration($field['configuration_json']??null);
        }
        unset($field);
        return $rows;
    }

    /** @return array<string,mixed> */
    public static function configuration(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw)==='') return [];
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:[];
    }

    /**
     * Normalize an external record payload. Missing fields preserve existing
     * values. Returned media bookkeeping lets callers clean replacements only
     * after a successful commit and new files after failures.
     *
     * @return array{data:array<string,string>,created_media:string[],replaced_media:string[]}
     */
    public static function normalizeIncoming(
        array $fields,
        array $input,
        array $existing,
        DataFormFieldStorageManager $storage,
        array $context=[],
        bool $partial=true
    ): array {
        $data=$existing;
        $created=[];
        $replaced=[];
        try {
            foreach ($fields as $field) {
                $name=(string)$field['name'];
                $label=(string)$field['label'];
                $type=(string)$field['field_type'];
                $cfg=is_array($field['configuration']??null)?$field['configuration']:self::configuration($field['configuration_json']??null);
                $had=array_key_exists($name,$input);
                $old=is_scalar($existing[$name]??null)?(string)$existing[$name]:'';

                if ($type==='computed') {
                    continue;
                }
                if (!$had) {
                    if (!$partial && !array_key_exists($name,$data)) $data[$name]='';
                    continue;
                }

                if (DataFormFieldTypeRegistry::isMedia($type)) {
                    $incoming=$input[$name];
                    if ($incoming===null || $incoming==='') {
                        $data[$name]=$old;
                        continue;
                    }
                    if (is_string($incoming)) {
                        $trim=trim($incoming);
                        if ($trim==='') { $data[$name]=$old; continue; }
                        $decoded=json_decode($trim,true);
                        if (is_array($decoded)) $incoming=$decoded;
                        elseif (preg_match('#^data:([^;,]+);base64,(.+)$#s',$trim,$m)===1) {
                            $incoming=['name'=>'transport.bin','mime'=>$m[1],'data_base64'=>$m[2]];
                        } else {
                            throw new RuntimeException('Das Feld „'.$label.'“ erwartet ein Media-JSON-Objekt mit data_base64.');
                        }
                    }
                    if (!is_array($incoming)) throw new RuntimeException('Das Feld „'.$label.'“ erwartet ein Media-Objekt.');
                    if (!empty($incoming['remove'])) {
                        $data[$name]='';
                        if ($old!=='') $replaced[]=$old;
                        continue;
                    }
                    $b64=(string)($incoming['data_base64']??'');
                    $clientName=trim((string)($incoming['name']??'transport.bin'));
                    if ($b64==='') throw new RuntimeException('Das Feld „'.$label.'“ benötigt data_base64.');
                    $bytes=base64_decode($b64,true);
                    if ($bytes===false) throw new RuntimeException('Das Feld „'.$label.'“ enthält ungültige Base64-Daten.');
                    $descriptor=$storage->storeBytes($bytes,$clientName,$type,$cfg,[
                        'project_id'=>(int)($context['project_id']??0),
                        'dataform_id'=>(int)($context['dataform_id']??0),
                        'field_name'=>$name,
                    ]);
                    $data[$name]=$descriptor;
                    $created[]=$descriptor;
                    if ($old!=='' && !hash_equals($old,$descriptor)) $replaced[]=$old;
                    continue;
                }

                $data[$name]=DataFormFieldTypeRegistry::normalizeValue($type,$input[$name],$cfg,$label,$old);
            }

            // Computed fields are resolved after all supplied values have been normalized.
            foreach ($fields as $field) {
                if ((string)$field['field_type']!=='computed') continue;
                $name=(string)$field['name'];
                $cfg=is_array($field['configuration']??null)?$field['configuration']:self::configuration($field['configuration_json']??null);
                $data[$name]=DataFormFieldTypeRegistry::computeValue($cfg,$data);
            }

            foreach ($fields as $field) {
                if ((int)($field['is_required']??0)!==1) continue;
                $type=(string)$field['field_type'];
                if (DataFormFieldTypeRegistry::isBoolean($type) || $type==='computed') continue;
                $name=(string)$field['name'];
                if (trim((string)($data[$name]??''))==='') throw new RuntimeException('Pflichtfeld „'.(string)$field['label'].'“ ist leer.');
            }
        } catch (Throwable $e) {
            foreach ($created as $value) $storage->deleteManagedValue($value);
            throw $e;
        }
        return ['data'=>$data,'created_media'=>$created,'replaced_media'=>array_values(array_unique($replaced))];
    }

    /** @return array<string,mixed> */
    public static function externalize(array $fields,array $data,DataFormFieldStorageManager $storage,bool $includeMediaData=false): array
    {
        $out=[];
        foreach ($fields as $field) {
            $name=(string)$field['name'];
            $type=(string)$field['field_type'];
            $raw=is_scalar($data[$name]??null)?(string)$data[$name]:'';
            if ($type==='password') { $out[$name]=null; continue; }
            if (DataFormFieldTypeRegistry::isMedia($type)) {
                if ($raw==='') { $out[$name]=null; continue; }
                if ($includeMediaData) {
                    $payload=$storage->payload($raw);
                    if ($payload===null) { $out[$name]=null; continue; }
                    $meta=$payload['meta'];
                    $meta['data_base64']=base64_encode((string)$payload['bytes']);
                    $out[$name]=$meta;
                } else {
                    $out[$name]=DataFormFieldStorageManager::publicDescriptor($raw);
                }
                continue;
            }
            if (DataFormFieldTypeRegistry::isBoolean($type)) { $out[$name]=$raw==='1'; continue; }
            if ($type==='integer' || $type==='lookup') { $out[$name]=$raw===''?null:(int)$raw; continue; }
            if (in_array($type,['number','decimal','currency','percentage'],true)) { $out[$name]=$raw===''?null:(float)$raw; continue; }
            if (in_array($type,['json','link','coordinates'],true)) {
                if ($raw==='') { $out[$name]=null; continue; }
                $decoded=json_decode($raw,true);
                $out[$name]=json_last_error()===JSON_ERROR_NONE?$decoded:null;
                continue;
            }
            if (in_array($type,['multiselect','tags','multi_lookup'],true)) {
                $decoded=json_decode($raw,true);
                $out[$name]=is_array($decoded)?array_values($decoded):[];
                continue;
            }
            $out[$name]=$raw;
        }
        return $out;
    }

    public static function csvValue(array $field,mixed $raw,DataFormFieldStorageManager $storage): string
    {
        $type=(string)$field['field_type'];
        $value=is_scalar($raw)?(string)$raw:'';
        if ($type==='password') return '';
        if (DataFormFieldTypeRegistry::isMedia($type)) {
            if ($value==='') return '';
            $payload=$storage->payload($value);
            if ($payload===null) return '';
            $meta=$payload['meta'];
            $meta['data_base64']=base64_encode((string)$payload['bytes']);
            return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if (in_array($type,['json','link','coordinates','multiselect','tags','multi_lookup'],true)) {
            if ($value==='') return '';
            $decoded=json_decode($value,true);
            return json_last_error()===JSON_ERROR_NONE
                ? json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)
                : $value;
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function openApiRecordSchema(array $fields,bool $request=false): array
    {
        $properties=[]; $required=[];
        foreach ($fields as $field) {
            $type=(string)$field['field_type'];
            if ($request && $type==='computed') continue;
            $schema=DataFormFieldTypeRegistry::openApiSchema($type);
            $schema['title']=(string)$field['label'];
            if ($type==='password') $schema['writeOnly']=true;
            if (DataFormFieldTypeRegistry::isMedia($type)) {
                $mediaProperties=$request ? [
                    'name'=>['type'=>'string'],
                    'data_base64'=>['type'=>'string','format'=>'byte','writeOnly'=>true],
                    'remove'=>['type'=>'boolean','writeOnly'=>true],
                ] : [
                    'name'=>['type'=>'string','readOnly'=>true],
                    'mime'=>['type'=>'string','readOnly'=>true],
                    'size'=>['type'=>'integer','readOnly'=>true],
                    'sha256'=>['type'=>'string','readOnly'=>true],
                    'width'=>['type'=>'integer','readOnly'=>true],
                    'height'=>['type'=>'integer','readOnly'=>true],
                    'storage'=>['type'=>'string','enum'=>['filesystem','database'],'readOnly'=>true],
                ];
                $schema=[
                    'type'=>'object',
                    'title'=>(string)$field['label'],
                    'nullable'=>true,
                    'properties'=>$mediaProperties,
                    'additionalProperties'=>false,
                ];
            }
            $properties[(string)$field['name']]=$schema;
            if ($request && (int)($field['is_required']??0)===1 && !DataFormFieldTypeRegistry::isBoolean($type) && $type!=='computed') {
                $required[]=(string)$field['name'];
            }
        }
        $schema=['type'=>'object','properties'=>$properties,'additionalProperties'=>false];
        if ($required!==[]) $schema['required']=$required;
        return $schema;
    }

    /** @return string[] */
    public static function cleanup(array $values,DataFormFieldStorageManager $storage): array
    {
        $deleted=[];
        foreach (array_values(array_unique($values)) as $value) {
            if ($storage->deleteManagedValue((string)$value)) $deleted[]=(string)$value;
        }
        return $deleted;
    }
}
