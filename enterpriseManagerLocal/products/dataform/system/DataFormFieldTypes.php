<?php
declare(strict_types=1);

/**
 * HF76: Central DataForm field type registry, normalization and media storage.
 *
 * This class is intentionally dependency-light so the Designer, Record CRUD,
 * CSV import and API schema generator can all share one canonical type model.
 */
final class DataFormFieldTypeRegistry
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        static $types=null;
        if ($types!==null) return $types;
        $types=[
            'text'=>self::d('Text','Text','string','text',['mysql'=>'VARCHAR(255)','pgsql'=>'VARCHAR(255)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(255)','mssql'=>'NVARCHAR(255)','csv'=>'string']),
            'textarea'=>self::d('Mehrzeiliger Text','Text','string','textarea',['mysql'=>'TEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'richtext'=>self::d('Formatierter Text','Text','string','richtext',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'markdown'=>self::d('Markdown','Text','string','markdown',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'integer'=>self::d('Ganzzahl','Zahlen','integer','number',['mysql'=>'BIGINT','pgsql'=>'BIGINT','sqlite'=>'INTEGER','oracle'=>'NUMBER(19)','mssql'=>'BIGINT','csv'=>'integer'],['step'=>'1']),
            'number'=>self::d('Zahl','Zahlen','number','number',['mysql'=>'DECIMAL(20,6)','pgsql'=>'DECIMAL(20,6)','sqlite'=>'REAL','oracle'=>'NUMBER(20,6)','mssql'=>'DECIMAL(20,6)','csv'=>'number'],['step'=>'any']),
            'decimal'=>self::d('Dezimalzahl','Zahlen','decimal','number',['mysql'=>'DECIMAL(20,6)','pgsql'=>'DECIMAL(20,6)','sqlite'=>'NUMERIC','oracle'=>'NUMBER(20,6)','mssql'=>'DECIMAL(20,6)','csv'=>'decimal'],['precision'=>20,'scale'=>6,'step'=>'0.000001']),
            'currency'=>self::d('Währung','Zahlen','decimal','number',['mysql'=>'DECIMAL(20,4)','pgsql'=>'DECIMAL(20,4)','sqlite'=>'NUMERIC','oracle'=>'NUMBER(20,4)','mssql'=>'DECIMAL(20,4)','csv'=>'decimal'],['precision'=>20,'scale'=>4,'currency'=>'EUR','step'=>'0.01']),
            'percentage'=>self::d('Prozentwert','Zahlen','decimal','number',['mysql'=>'DECIMAL(9,4)','pgsql'=>'DECIMAL(9,4)','sqlite'=>'NUMERIC','oracle'=>'NUMBER(9,4)','mssql'=>'DECIMAL(9,4)','csv'=>'decimal'],['precision'=>9,'scale'=>4,'min'=>0,'max'=>100,'step'=>'0.01']),
            'boolean'=>self::d('Ja/Nein','Auswahl und Beziehungen','boolean','checkbox',['mysql'=>'TINYINT(1)','pgsql'=>'SMALLINT','sqlite'=>'INTEGER','oracle'=>'NUMBER(1)','mssql'=>'BIT','csv'=>'boolean']),
            'checkbox'=>self::d('Kontrollkästchen','Auswahl und Beziehungen','boolean','checkbox',['mysql'=>'TINYINT(1)','pgsql'=>'SMALLINT','sqlite'=>'INTEGER','oracle'=>'NUMBER(1)','mssql'=>'BIT','csv'=>'boolean']),
            'date'=>self::d('Datum','Datum und Zeit','date','date',['mysql'=>'DATE','pgsql'=>'DATE','sqlite'=>'TEXT','oracle'=>'DATE','mssql'=>'DATE','csv'=>'date']),
            'time'=>self::d('Uhrzeit','Datum und Zeit','time','time',['mysql'=>'TIME','pgsql'=>'TIME','sqlite'=>'TEXT','oracle'=>'VARCHAR2(8)','mssql'=>'TIME(6)','csv'=>'time']),
            'datetime'=>self::d('Datum und Uhrzeit','Datum und Zeit','datetime','datetime-local',['mysql'=>'DATETIME','pgsql'=>'TIMESTAMP','sqlite'=>'TEXT','oracle'=>'TIMESTAMP','mssql'=>'DATETIME2(6)','csv'=>'datetime']),
            'email'=>self::d('E-Mail','Kontakt und Verweise','email','email',['mysql'=>'VARCHAR(320)','pgsql'=>'VARCHAR(320)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(320)','mssql'=>'NVARCHAR(320)','csv'=>'string']),
            'phone'=>self::d('Telefonnummer','Kontakt und Verweise','string','tel',['mysql'=>'VARCHAR(64)','pgsql'=>'VARCHAR(64)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(64)','mssql'=>'NVARCHAR(64)','csv'=>'string']),
            'url'=>self::d('URL','Kontakt und Verweise','url','url',['mysql'=>'TEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'link'=>self::d('Link','Kontakt und Verweise','json','link',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json'],['target'=>'_self']),
            'select'=>self::d('Auswahlliste','Auswahl und Beziehungen','string','select',['mysql'=>'VARCHAR(255)','pgsql'=>'VARCHAR(255)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(255)','mssql'=>'NVARCHAR(255)','csv'=>'string']),
            'multiselect'=>self::d('Mehrfachauswahl','Auswahl und Beziehungen','json','multiselect',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json']),
            'tags'=>self::d('Schlagwörter','Auswahl und Beziehungen','json','tags',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json']),
            'lookup'=>self::d('Datensatz-Auswahl','Auswahl und Beziehungen','string','lookup',['mysql'=>'BIGINT','pgsql'=>'BIGINT','sqlite'=>'INTEGER','oracle'=>'NUMBER(19)','mssql'=>'BIGINT','csv'=>'string']),
            'multi_lookup'=>self::d('Mehrfach-Datensatz-Auswahl','Auswahl und Beziehungen','json','multi_lookup',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json']),
            'derived_multienum'=>self::d('Abgeleitete Mehrfachauswahl','Auswahl und Beziehungen','csv','derived_multienum',['mysql'=>'TEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'json'=>self::d('JSON','Strukturierte Daten','json','json',['mysql'=>'JSON','pgsql'=>'JSONB','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json'],['pretty'=>true]),
            'uuid'=>self::d('UUID','Strukturierte Daten','uuid','text',['mysql'=>'CHAR(36)','pgsql'=>'CHAR(36)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(36)','mssql'=>'UNIQUEIDENTIFIER','csv'=>'string']),
            'coordinates'=>self::d('Koordinaten','Strukturierte Daten','json','coordinates',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json']),
            'file'=>self::d('Datei','Dateien und Medien','media','file',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json'],self::mediaDefaults('application/pdf,text/plain,text/csv,application/zip,application/json,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')),
            'image'=>self::d('Bild','Dateien und Medien','media','file',['mysql'=>'LONGTEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'json'],self::mediaDefaults('image/jpeg,image/png,image/webp,image/gif')),
            'color'=>self::d('Farbe','Spezialfelder','color','color',['mysql'=>'CHAR(7)','pgsql'=>'CHAR(7)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(7)','mssql'=>'NCHAR(7)','csv'=>'string']),
            'computed'=>self::d('Berechnetes Feld','Spezialfelder','computed','text',['mysql'=>'TEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string'],['template'=>'']),
            'hidden'=>self::d('Verstecktes Feld','Spezialfelder','string','hidden',['mysql'=>'TEXT','pgsql'=>'TEXT','sqlite'=>'TEXT','oracle'=>'CLOB','mssql'=>'NVARCHAR(MAX)','csv'=>'string']),
            'password'=>self::d('Passwort / Geheimwert','Spezialfelder','secret','password',['mysql'=>'VARCHAR(255)','pgsql'=>'VARCHAR(255)','sqlite'=>'TEXT','oracle'=>'VARCHAR2(255)','mssql'=>'NVARCHAR(255)','csv'=>'string']),
        ];
        return $types;
    }

    /** @return array<string,mixed> */
    private static function d(string $label,string $group,string $valueType,string $widget,array $schema,array $defaults=[]): array
    {
        return ['label'=>$label,'group'=>$group,'value_type'=>$valueType,'widget'=>$widget,'schema'=>$schema,'defaults'=>$defaults];
    }

    /** @return array<string,mixed> */
    private static function mediaDefaults(string $accept): array
    {
        return [
            'storage_driver'=>'filesystem',
            'accept'=>$accept,
            'max_bytes'=>10*1024*1024,
            'image_max_width'=>0,
            'image_max_height'=>0,
            'image_preview'=>true,
        ];
    }

    public static function has(string $type): bool { return isset(self::all()[$type]); }
    /** @return string[] */
    public static function allowedTypes(): array { return array_keys(self::all()); }
    public static function label(string $type): string { return (string)(self::all()[$type]['label']??$type); }
    public static function isMedia(string $type): bool { return in_array($type,['file','image'],true); }
    public static function isBoolean(string $type): bool { return in_array($type,['checkbox','boolean'],true); }
    public static function isNumeric(string $type): bool { return in_array($type,['integer','number','decimal','currency','percentage'],true); }
    public static function isJsonLike(string $type): bool { return in_array($type,['json','link','multiselect','tags','multi_lookup','coordinates'],true); }
    public static function isTextarea(string $type): bool { return in_array($type,['textarea','richtext','markdown','json'],true); }

    /** @return array<string,array<int,array{value:string,label:string}>> */
    public static function groupedOptions(): array
    {
        $groups=[];
        foreach (self::all() as $value=>$meta) {
            $groups[(string)$meta['group']][]=['value'=>$value,'label'=>(string)$meta['label']];
        }
        return $groups;
    }

    /** @return array<string,mixed> */
    public static function settings(string $type,array $configuration=[]): array
    {
        $defaults=(array)(self::all()[$type]['defaults']??[]);
        $saved=isset($configuration['type_settings']) && is_array($configuration['type_settings'])
            ? $configuration['type_settings'] : [];
        $settings=array_replace($defaults,$saved);
        if (self::isMedia($type)) {
            $settings['storage_driver']=in_array((string)($settings['storage_driver']??'filesystem'),['filesystem','database'],true)
                ? (string)$settings['storage_driver'] : 'filesystem';
            $settings['max_bytes']=max(1024,min(100*1024*1024,(int)($settings['max_bytes']??10*1024*1024)));
            $settings['accept']=trim((string)($settings['accept']??''));
            $settings['image_max_width']=max(0,min(20000,(int)($settings['image_max_width']??0)));
            $settings['image_max_height']=max(0,min(20000,(int)($settings['image_max_height']??0)));
            $settings['image_preview']=!array_key_exists('image_preview',$settings) || (bool)$settings['image_preview'];
        }
        if (in_array($type,['decimal','currency','percentage'],true)) {
            $settings['precision']=max(1,min(65,(int)($settings['precision']??20)));
            $settings['scale']=max(0,min((int)$settings['precision'],(int)($settings['scale']??6)));
        }
        if ($type==='currency') {
            $currency=strtoupper(trim((string)($settings['currency']??'EUR')));
            $settings['currency']=preg_match('/^[A-Z]{3}$/',$currency)===1?$currency:'EUR';
        }
        if ($type==='link') {
            $settings['target']=in_array((string)($settings['target']??'_self'),['_self','_blank'],true)
                ? (string)$settings['target'] : '_self';
        }
        if ($type==='computed') {
            $settings['template']=self::substrText((string)($settings['template']??''),0,4000);
        }
        return $settings;
    }

    public static function normalizeConfiguration(array $configuration,string $type): array
    {
        if (!self::has($type)) $type='text';
        $configuration['type_settings']=self::settings($type,$configuration);
        if ($type==='hidden') $configuration['hidden']=true;
        if ($type==='computed') $configuration['readonly']=true;
        if ($type==='password') {
            $configuration['searchable']=false;
            $configuration['filterable']=false;
            $configuration['sortable']=false;
            $configuration['list_visible']=false;
        }
        if (self::isMedia($type)) {
            $configuration['searchable']=false;
            $configuration['sortable']=false;
        }
        return $configuration;
    }

    public static function applyPostSettings(array $configuration,string $type,array $post): array
    {
        $settings=self::settings($type,$configuration);
        if (self::isMedia($type)) {
            $settings['storage_driver']=in_array((string)($post['type_storage_driver']??$settings['storage_driver']),['filesystem','database'],true)
                ? (string)($post['type_storage_driver']??$settings['storage_driver']) : 'filesystem';
            $accept=trim((string)($post['type_allowed_mime']??''));
            if ($accept!=='') $settings['accept']=$accept;
            $maxMb=max(1,min(100,(int)($post['type_max_mb']??max(1,(int)ceil(((int)$settings['max_bytes'])/1048576)))));
            $settings['max_bytes']=$maxMb*1048576;
            $settings['image_max_width']=max(0,min(20000,(int)($post['type_image_max_width']??$settings['image_max_width']??0)));
            $settings['image_max_height']=max(0,min(20000,(int)($post['type_image_max_height']??$settings['image_max_height']??0)));
            $settings['image_preview']=isset($post['type_image_preview']);
        }
        if (in_array($type,['decimal','currency','percentage'],true)) {
            $precisionRaw=trim((string)($post['type_precision']??''));
            $scaleRaw=trim((string)($post['type_scale']??''));
            $precision=$precisionRaw===''?(int)($settings['precision']??20):max(1,min(65,(int)$precisionRaw));
            $scale=$scaleRaw===''?(int)($settings['scale']??6):max(0,min($precision,(int)$scaleRaw));
            $settings['precision']=$precision;
            $settings['scale']=$scale;
        }
        if ($type==='currency') {
            $currency=strtoupper(trim((string)($post['type_currency']??$settings['currency']??'EUR')));
            $settings['currency']=preg_match('/^[A-Z]{3}$/',$currency)===1?$currency:'EUR';
        }
        if ($type==='link') {
            $target=(string)($post['type_link_target']??$settings['target']??'_self');
            $settings['target']=in_array($target,['_self','_blank'],true)?$target:'_self';
        }
        if ($type==='json') {
            $settings['pretty']=isset($post['type_json_pretty']);
        }
        if ($type==='computed') {
            $settings['template']=self::substrText((string)($post['type_computed_template']??$settings['template']??''),0,4000);
        }
        $configuration['type_settings']=$settings;
        return self::normalizeConfiguration($configuration,$type);
    }

    public static function htmlInputType(string $type): string
    {
        return match($type) {
            'integer','number','decimal','currency','percentage'=>'number',
            'date'=>'date','time'=>'time','datetime'=>'datetime-local',
            'email'=>'email','phone'=>'tel','url','link'=>'url',
            'color'=>'color','password'=>'password',
            default=>'text',
        };
    }

    public static function sqlType(string $type,string $driver='mysql',array $configuration=[]): string
    {
        $driver=strtolower($driver);
        $meta=self::all()[$type]??self::all()['text'];
        $settings=self::settings($type,$configuration);
        if (in_array($type,['decimal','currency','percentage'],true)) {
            $p=(int)$settings['precision']; $s=(int)$settings['scale'];
            return match($driver) {
                'mysql','mariadb'=>"DECIMAL($p,$s)",
                'pgsql','postgres','postgresql'=>"NUMERIC($p,$s)",
                'oracle'=>"NUMBER($p,$s)",
                'mssql'=>"DECIMAL($p,$s)",
                'sqlite'=>'NUMERIC',
                default=>'decimal',
            };
        }
        return (string)($meta['schema'][$driver]??$meta['schema']['mysql']??'TEXT');
    }

    /**
     * Normalize and validate a scalar/form value. Media values must already be
     * converted to their descriptor by DataFormFieldStorageManager.
     */
    public static function normalizeValue(string $type,mixed $raw,array $configuration,string $label,string $existing=''): string
    {
        if (!self::has($type)) $type='text';
        $settings=self::settings($type,$configuration);
        if ($type==='computed') return $existing;
        if ($type==='password') {
            $value=is_scalar($raw)?(string)$raw:'';
            if ($value==='') return $existing;
            if (strlen($value)>4096) throw new RuntimeException('Das Feld „'.$label.'“ ist zu lang.');
            return password_hash($value,PASSWORD_DEFAULT);
        }
        if (self::isMedia($type)) {
            return is_scalar($raw)?(string)$raw:$existing;
        }
        if (self::isBoolean($type)) {
            if (is_bool($raw)) return $raw?'1':'0';
            $s=self::lower(trim(is_scalar($raw)?(string)$raw:''));
            return in_array($s,['1','true','yes','ja','on','x'],true)?'1':'0';
        }
        if ($type==='link') {
            $parts=is_array($raw)?$raw:self::decodeObject((string)$raw);
            $url=trim((string)($parts['url']??''));
            $caption=trim((string)($parts['label']??''));
            $target=(string)($parts['target']??$settings['target']??'_self');
            if ($url==='' && $caption==='') return '';
            if ($url==='' || filter_var($url,FILTER_VALIDATE_URL)===false) throw new RuntimeException('Das Feld „'.$label.'“ enthält keine gültige Link-URL.');
            if (!in_array($target,['_self','_blank'],true)) $target='_self';
            return json_encode(['url'=>$url,'label'=>$caption,'target'=>$target],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if ($type==='coordinates') {
            $parts=is_array($raw)?$raw:self::decodeObject((string)$raw);
            $lat=trim((string)($parts['lat']??''));
            $lng=trim((string)($parts['lng']??''));
            if ($lat==='' && $lng==='') return '';
            if (!is_numeric(str_replace(',','.',$lat)) || !is_numeric(str_replace(',','.',$lng))) throw new RuntimeException('Das Feld „'.$label.'“ benötigt numerische Breiten-/Längengrade.');
            $latf=(float)str_replace(',','.',$lat); $lngf=(float)str_replace(',','.',$lng);
            if ($latf < -90 || $latf > 90 || $lngf < -180 || $lngf > 180) throw new RuntimeException('Das Feld „'.$label.'“ enthält Koordinaten außerhalb des gültigen Bereichs.');
            return json_encode(['lat'=>$latf,'lng'=>$lngf],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if (in_array($type,['multiselect','multi_lookup'],true)) {
            $values=is_array($raw)?$raw:self::decodeArray((string)$raw);
            $values=array_values(array_unique(array_values(array_filter(array_map(static fn($v):string=>trim(is_scalar($v)?(string)$v:''),$values),static fn(string $v):bool=>$v!==''))));
            if ($type==='multiselect' && !empty($configuration['options'])) {
                $allowed=array_map('strval',(array)$configuration['options']);
                foreach($values as $v) if(!in_array($v,$allowed,true)) throw new RuntimeException('Das Feld „'.$label.'“ enthält eine unbekannte Auswahloption.');
            }
            return json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if ($type==='tags') {
            if (is_array($raw)) $values=$raw;
            else $values=preg_split('/[,;\r\n]+/u',(string)$raw)?:[];
            $values=array_values(array_unique(array_values(array_filter(array_map(static fn($v):string=>trim(is_scalar($v)?(string)$v:''),$values),static fn(string $v):bool=>$v!==''))));
            return json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if ($type==='json') {
            if (is_array($raw) || is_object($raw)) $decoded=$raw;
            else {
                $text=trim(is_scalar($raw)?(string)$raw:'');
                if ($text==='') return '';
                try { $decoded=json_decode($text,true,512,JSON_THROW_ON_ERROR); }
                catch(Throwable $e){ throw new RuntimeException('Das Feld „'.$label.'“ enthält ungültiges JSON: '.$e->getMessage()); }
            }
            $flags=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
            if (!empty($settings['pretty'])) $flags|=JSON_PRETTY_PRINT;
            return json_encode($decoded,$flags);
        }

        $value=is_scalar($raw)?(string)$raw:'';
        if (!empty($configuration['trim'])) $value=trim($value);
        if ($value==='') return '';

        if ($type==='email' && filter_var($value,FILTER_VALIDATE_EMAIL)===false) throw new RuntimeException('Das Feld „'.$label.'“ enthält keine gültige E-Mail-Adresse.');
        if ($type==='url' && filter_var($value,FILTER_VALIDATE_URL)===false) throw new RuntimeException('Das Feld „'.$label.'“ enthält keine gültige URL.');
        if ($type==='uuid' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',$value)!==1) throw new RuntimeException('Das Feld „'.$label.'“ enthält keine gültige UUID.');
        if ($type==='color' && preg_match('/^#[0-9a-f]{6}$/i',$value)!==1) throw new RuntimeException('Das Feld „'.$label.'“ muss eine Farbe im Format #RRGGBB enthalten.');
        if ($type==='integer' && filter_var($value,FILTER_VALIDATE_INT)===false) throw new RuntimeException('Das Feld „'.$label.'“ muss eine Ganzzahl enthalten.');
        if (in_array($type,['number','decimal','currency','percentage'],true)) {
            $number=str_replace(',','.',$value);
            if (!is_numeric($number)) throw new RuntimeException('Das Feld „'.$label.'“ muss eine Zahl enthalten.');
            if (isset($settings['min']) && (float)$number < (float)$settings['min']) throw new RuntimeException('Das Feld „'.$label.'“ unterschreitet den Minimalwert.');
            if (isset($settings['max']) && (float)$number > (float)$settings['max']) throw new RuntimeException('Das Feld „'.$label.'“ überschreitet den Maximalwert.');
            if (in_array($type,['decimal','currency','percentage'],true)) return self::decimalString($number,(int)$settings['scale']);
            return $number;
        }
        if ($type==='date') {
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$dt || $dt->format('Y-m-d')!==$value) throw new RuntimeException('Das Feld „'.$label.'“ muss ein gültiges Datum enthalten.');
        }
        if ($type==='time') {
            $dt=DateTimeImmutable::createFromFormat('!H:i',$value) ?: DateTimeImmutable::createFromFormat('!H:i:s',$value);
            if (!$dt) throw new RuntimeException('Das Feld „'.$label.'“ muss eine gültige Uhrzeit enthalten.');
            return $dt->format('H:i:s');
        }
        if ($type==='datetime') {
            $candidate=str_replace(' ','T',$value);
            $dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$candidate) ?: DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s',$candidate);
            if (!$dt) throw new RuntimeException('Das Feld „'.$label.'“ muss ein gültiges Datum mit Uhrzeit enthalten.');
            return $dt->format('Y-m-d H:i:s');
        }
        if ($type==='select' && !empty($configuration['options']) && !in_array($value,array_map('strval',(array)$configuration['options']),true)) {
            throw new RuntimeException('Das Feld „'.$label.'“ enthält eine unbekannte Auswahloption.');
        }
        if ($type==='richtext') return self::sanitizeRichText($value);
        return $value;
    }

    public static function computeValue(array $configuration,array $data): string
    {
        $settings=self::settings('computed',$configuration);
        $template=(string)($settings['template']??'');
        if ($template==='') return '';
        return preg_replace_callback('/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/',static function(array $m) use($data): string {
            $v=$data[$m[1]]??'';
            if (!is_scalar($v)) return '';
            return (string)$v;
        },$template) ?? $template;
    }

    public static function displayText(string $type,mixed $value,array $configuration=[]): string
    {
        $raw=is_scalar($value)?(string)$value:'';
        if ($raw==='') return '';
        if (self::isBoolean($type)) return in_array(self::lower(trim($raw)),['1','true','yes','ja','on'],true)?'Ja':'Nein';
        if ($type==='password') return '••••••••';
        if ($type==='link') {
            $m=self::decodeObject($raw); return trim((string)($m['label']??''))!==''?(string)$m['label']:(string)($m['url']??$raw);
        }
        if ($type==='coordinates') {
            $m=self::decodeObject($raw); return isset($m['lat'],$m['lng']) ? (string)$m['lat'].', '.(string)$m['lng'] : $raw;
        }
        if (in_array($type,['multiselect','tags','multi_lookup'],true)) {
            $a=self::decodeArray($raw); return implode(', ',array_map('strval',$a));
        }
        if ($type==='json') {
            try { $d=json_decode($raw,true,512,JSON_THROW_ON_ERROR); return json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR); }
            catch(Throwable){ return $raw; }
        }
        if (self::isMedia($type)) {
            $m=DataFormFieldStorageManager::descriptor($raw);
            if ($m!==null) {
                $size=isset($m['size']) ? self::humanBytes((int)$m['size']) : '';
                return trim((string)($m['name']??'Datei').($size!==''?' · '.$size:''));
            }
        }
        if ($type==='currency') {
            $currency=(string)(self::settings($type,$configuration)['currency']??'EUR');
            return $raw.' '.$currency;
        }
        if ($type==='percentage') return $raw.' %';
        return $raw;
    }

    public static function openApiSchema(string $type): array
    {
        return match($type) {
            'integer','lookup'=>['type'=>'integer'],
            'number','decimal','currency','percentage'=>['type'=>'number'],
            'checkbox','boolean'=>['type'=>'boolean'],
            'date'=>['type'=>'string','format'=>'date'],
            'time'=>['type'=>'string','format'=>'time'],
            'datetime'=>['type'=>'string','format'=>'date-time'],
            'email'=>['type'=>'string','format'=>'email'],
            'url'=>['type'=>'string','format'=>'uri'],
            'uuid'=>['type'=>'string','format'=>'uuid'],
            'json','link','coordinates'=>['type'=>'object'],
            'multiselect','tags','multi_lookup'=>['type'=>'array','items'=>['type'=>'string']],
            'file','image'=>['type'=>'object','description'=>'DataForm media descriptor'],
            default=>['type'=>'string'],
        };
    }

    /** @return array<string,mixed> */
    public static function decodeObject(string $raw): array
    {
        if (trim($raw)==='') return [];
        try { $v=json_decode($raw,true,512,JSON_THROW_ON_ERROR); return is_array($v)?$v:[]; }
        catch(Throwable){ return []; }
    }
    /** @return array<int,mixed> */
    public static function decodeArray(string $raw): array
    {
        $v=self::decodeObject($raw);
        return array_is_list($v)?$v:[];
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value);
    }

    public static function substrText(string $value,int $start,int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value,$start,$length,'UTF-8') : substr($value,$start,$length);
    }

    private static function decimalString(string $number,int $scale): string
    {
        $negative=str_starts_with($number,'-');
        if ($negative) $number=substr($number,1);
        [$whole,$fraction]=array_pad(explode('.',$number,2),2,'');
        $whole=ltrim($whole,'0'); if ($whole==='') $whole='0';
        $fraction=preg_replace('/\D/','',$fraction)??'';
        $fraction=substr(str_pad($fraction,$scale,'0'),0,$scale);
        return ($negative?'-':'').$whole.($scale>0?'.'.$fraction:'');
    }

    public static function sanitizeRichTextForDisplay(string $html): string
    {
        return self::sanitizeRichText($html);
    }

    private static function sanitizeRichText(string $html): string
    {
        $html=strip_tags($html,'<p><br><strong><b><em><i><u><ul><ol><li><blockquote><code><pre><h1><h2><h3><hr>');
        return preg_replace_callback('/<\/?([a-z0-9]+)\b[^>]*>/i',static function(array $m): string {
            $tag=strtolower($m[1]); $closing=str_starts_with($m[0],'</');
            if ($tag==='br' || $tag==='hr') return '<'.$tag.'>';
            return $closing?'</'.$tag.'>':'<'.$tag.'>';
        },$html) ?? '';
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes>=1048576) return number_format($bytes/1048576,1,',','.').' MB';
        if ($bytes>=1024) return number_format($bytes/1024,1,',','.').' KB';
        return $bytes.' B';
    }
}

final class DataFormFieldStorageManager
{
    private const DESCRIPTOR_VERSION=2;
    private const SAFE_INLINE_IMAGE_MIMES=['image/jpeg','image/png','image/webp','image/gif'];

    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed>|null */
    public static function descriptor(string $value): ?array
    {
        if (trim($value)==='') return null;
        try { $m=json_decode($value,true,512,JSON_THROW_ON_ERROR); }
        catch(Throwable){ return null; }
        if (!is_array($m)) return null;
        $storage=(string)($m['storage']??'');
        $name=(string)($m['name']??'');
        $mime=(string)($m['mime']??'');
        $sha=(string)($m['sha256']??'');
        $size=$m['size']??null;
        if (!in_array($storage,['filesystem','database'],true)) return null;
        if ($name==='' || str_contains($name,"\0")) return null;
        if (preg_match('/^[A-Za-z0-9.+-]+\/[A-Za-z0-9.+-]+$/',$mime)!==1) return null;
        if (!is_int($size) && !(is_string($size) && preg_match('/^\d+$/',$size)===1)) return null;
        if ((int)$size<0) return null;
        if (preg_match('/^[a-f0-9]{64}$/i',$sha)!==1) return null;
        if ($storage==='database') {
            if (!isset($m['data_base64']) || !is_string($m['data_base64'])) return null;
        } else {
            $path=str_replace('\\','/',(string)($m['path']??''));
            if ($path==='' || preg_match('#(^|/)\.\.(/|$)#',$path)===1 || !(str_starts_with($path,'storage/dataform/uploads/') || preg_match('#^storage/projects/prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}/documents/dataform/#',$path)===1)) return null;
            $m['path']=$path;
        }
        $m['version']=max(1,(int)($m['version']??1));
        $m['size']=(int)$size;
        return $m;
    }

    /** @return array<string,mixed>|null Descriptor without physical path / embedded bytes. */
    public static function publicDescriptor(string $value): ?array
    {
        $m=self::descriptor($value);
        if ($m===null) return null;
        unset($m['path'],$m['data_base64']);
        return $m;
    }

    public static function inlinePreviewAllowed(string $fieldType,string $mime,array $configuration=[]): bool
    {
        if ($fieldType!=='image') return false;
        $settings=DataFormFieldTypeRegistry::settings('image',$configuration);
        return !empty($settings['image_preview']) && in_array(strtolower($mime),self::SAFE_INLINE_IMAGE_MIMES,true);
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null */
    public static function nestedUpload(array $files,string $fieldName): ?array
    {
        foreach (['name','type','tmp_name','error','size'] as $key) if (!isset($files[$key]) || !is_array($files[$key])) return null;
        if (!array_key_exists($fieldName,$files['name'])) return null;
        return [
            'name'=>(string)$files['name'][$fieldName],
            'type'=>(string)($files['type'][$fieldName]??''),
            'tmp_name'=>(string)($files['tmp_name'][$fieldName]??''),
            'error'=>(int)($files['error'][$fieldName]??UPLOAD_ERR_NO_FILE),
            'size'=>(int)($files['size'][$fieldName]??0),
        ];
    }

    public function storeUpload(array $file,string $fieldType,array $configuration,array $context=[]): string
    {
        if (!DataFormFieldTypeRegistry::isMedia($fieldType)) throw new RuntimeException('Upload-Feldtyp ist ungültig.');
        $settings=DataFormFieldTypeRegistry::settings($fieldType,$configuration);
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if ($error===UPLOAD_ERR_NO_FILE) return '';
        if ($error!==UPLOAD_ERR_OK) throw new RuntimeException($this->uploadErrorMessage($error));
        $tmp=(string)($file['tmp_name']??'');
        if ($tmp==='' || !is_file($tmp)) throw new RuntimeException('Temporäre Upload-Datei fehlt.');
        if (PHP_SAPI!=='cli' && !is_uploaded_file($tmp)) throw new RuntimeException('Die Datei stammt nicht aus einem gültigen HTTP-Upload.');

        $actualSize=filesize($tmp);
        if ($actualSize===false || $actualSize<0) throw new RuntimeException('Die Dateigröße kann nicht bestimmt werden.');
        if ($actualSize>(int)$settings['max_bytes']) throw new RuntimeException('Datei überschreitet die erlaubte Maximalgröße.');

        $finfo=new finfo(FILEINFO_MIME_TYPE);
        $mime=strtolower(trim((string)$finfo->file($tmp)));
        if ($mime==='' || !$this->mimeAccepted($mime,(string)$settings['accept'])) throw new RuntimeException('MIME-Typ „'.$mime.'“ ist für dieses Feld nicht erlaubt.');

        $imageMeta=[];
        if ($fieldType==='image') {
            // SVG und andere aktive Formate werden bewusst nicht als Bild akzeptiert.
            // Sie können – falls gewünscht – als normaler Dateityp gespeichert werden.
            if (!in_array($mime,self::SAFE_INLINE_IMAGE_MIMES,true)) throw new RuntimeException('Für Bildfelder sind nur JPEG, PNG, WebP und GIF erlaubt.');
            $image=@getimagesize($tmp);
            if ($image===false) throw new RuntimeException('Die Bilddatei kann nicht gelesen werden.');
            $maxW=(int)($settings['image_max_width']??0); $maxH=(int)($settings['image_max_height']??0);
            if ($maxW>0 && (int)$image[0]>$maxW) throw new RuntimeException('Das Bild ist breiter als erlaubt.');
            if ($maxH>0 && (int)$image[1]>$maxH) throw new RuntimeException('Das Bild ist höher als erlaubt.');
            $imageMeta=['width'=>(int)$image[0],'height'=>(int)$image[1]];
        }

        $bytes=file_get_contents($tmp);
        if ($bytes===false) throw new RuntimeException('Upload kann nicht gelesen werden.');
        if (strlen($bytes)!==$actualSize) throw new RuntimeException('Upload konnte nicht vollständig gelesen werden.');

        $name=$this->safeFilename((string)($file['name']??'upload.bin'));
        $meta=array_merge([
            'version'=>self::DESCRIPTOR_VERSION,
            'storage'=>(string)$settings['storage_driver'],
            'name'=>$name,
            'mime'=>$mime,
            'size'=>strlen($bytes),
            'sha256'=>hash('sha256',$bytes),
            'stored_at'=>gmdate('c'),
        ],$imageMeta);

        if ($meta['storage']==='database') {
            $meta['data_base64']=base64_encode($bytes);
            return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if ($meta['storage']!=='filesystem') throw new RuntimeException('Unbekannter Media-Speichertreiber.');

        $project=max(0,(int)($context['project_id']??0));
        $dataform=max(0,(int)($context['dataform_id']??0));
        $field=$this->safeSegment((string)($context['field_name']??'field'));
        $baseDir=$this->projectDataFormBase($project);
        $this->ensureStorageProtection($baseDir);
        $dir=$baseDir.'/dataform-'.$dataform.'/'.$field;
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Upload-Verzeichnis kann nicht erstellt werden.');

        $ext=$this->extensionFor($mime);
        $token=bin2hex(random_bytes(20));
        $target=$dir.'/'.$token.'.'.$ext;
        $part=$dir.'/.'.$token.'.part';
        try {
            if (file_put_contents($part,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Datei kann nicht vollständig gespeichert werden.');
            @chmod($part,0660);
            if (!@rename($part,$target)) throw new RuntimeException('Datei kann nicht finalisiert werden.');
            @chmod($target,0660);
        } catch (Throwable $e) {
            if (is_file($part)) @unlink($part);
            if (is_file($target)) @unlink($target);
            throw $e;
        }

        $root=rtrim(str_replace('\\','/',$this->root),'/');
        $targetNorm=str_replace('\\','/',$target);
        $meta['path']=ltrim(substr($targetNorm,strlen($root)),'/');
        return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    /**
     * Store trusted transport bytes (REST/CSV/package import) through the same
     * validation and storage policy as an HTTP upload. The caller supplies raw
     * bytes, never a target path. MIME is detected server-side.
     */
    public function storeBytes(string $bytes,string $clientName,string $fieldType,array $configuration,array $context=[]): string
    {
        if (!DataFormFieldTypeRegistry::isMedia($fieldType)) throw new RuntimeException('Media-Feldtyp ist ungültig.');
        $settings=DataFormFieldTypeRegistry::settings($fieldType,$configuration);
        $size=strlen($bytes);
        if ($size<1) throw new RuntimeException('Die übertragene Datei ist leer.');
        if ($size>(int)$settings['max_bytes']) throw new RuntimeException('Datei überschreitet die erlaubte Maximalgröße.');

        $finfo=new finfo(FILEINFO_MIME_TYPE);
        $mime=strtolower(trim((string)$finfo->buffer($bytes)));
        if ($mime==='' || !$this->mimeAccepted($mime,(string)$settings['accept'])) throw new RuntimeException('MIME-Typ „'.$mime.'“ ist für dieses Feld nicht erlaubt.');

        $imageMeta=[];
        if ($fieldType==='image') {
            if (!in_array($mime,self::SAFE_INLINE_IMAGE_MIMES,true)) throw new RuntimeException('Für Bildfelder sind nur JPEG, PNG, WebP und GIF erlaubt.');
            $image=@getimagesizefromstring($bytes);
            if ($image===false) throw new RuntimeException('Die Bilddatei kann nicht gelesen werden.');
            $maxW=(int)($settings['image_max_width']??0); $maxH=(int)($settings['image_max_height']??0);
            if ($maxW>0 && (int)$image[0]>$maxW) throw new RuntimeException('Das Bild ist breiter als erlaubt.');
            if ($maxH>0 && (int)$image[1]>$maxH) throw new RuntimeException('Das Bild ist höher als erlaubt.');
            $imageMeta=['width'=>(int)$image[0],'height'=>(int)$image[1]];
        }

        $name=$this->safeFilename($clientName!==''?$clientName:'transport.bin');
        $meta=array_merge([
            'version'=>self::DESCRIPTOR_VERSION,
            'storage'=>(string)$settings['storage_driver'],
            'name'=>$name,
            'mime'=>$mime,
            'size'=>$size,
            'sha256'=>hash('sha256',$bytes),
            'stored_at'=>gmdate('c'),
        ],$imageMeta);

        if ($meta['storage']==='database') {
            $meta['data_base64']=base64_encode($bytes);
            return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        if ($meta['storage']!=='filesystem') throw new RuntimeException('Unbekannter Media-Speichertreiber.');

        $project=max(0,(int)($context['project_id']??0));
        $dataform=max(0,(int)($context['dataform_id']??0));
        $field=$this->safeSegment((string)($context['field_name']??'field'));
        $baseDir=$this->projectDataFormBase($project);
        $this->ensureStorageProtection($baseDir);
        $dir=$baseDir.'/dataform-'.$dataform.'/'.$field;
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Upload-Verzeichnis kann nicht erstellt werden.');

        $ext=$this->extensionFor($mime);
        $token=bin2hex(random_bytes(20));
        $target=$dir.'/'.$token.'.'.$ext;
        $part=$dir.'/.'.$token.'.part';
        try {
            if (file_put_contents($part,$bytes,LOCK_EX)!==$size) throw new RuntimeException('Datei kann nicht vollständig gespeichert werden.');
            @chmod($part,0660);
            if (!@rename($part,$target)) throw new RuntimeException('Datei kann nicht finalisiert werden.');
            @chmod($target,0660);
        } catch (Throwable $e) {
            if (is_file($part)) @unlink($part);
            if (is_file($target)) @unlink($target);
            throw $e;
        }

        $root=rtrim(str_replace('\\','/',$this->root),'/');
        $targetNorm=str_replace('\\','/',$target);
        $meta['path']=ltrim(substr($targetNorm,strlen($root)),'/');
        return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }

    public function deleteManagedValue(string $value): bool
    {
        $m=self::descriptor($value);
        if ($m===null || ($m['storage']??'')!=='filesystem') return false;
        $candidate=$this->managedFilesystemPath((string)($m['path']??''));
        if ($candidate===null || !is_file($candidate) || is_link($candidate)) return false;
        $deleted=@unlink($candidate);
        if ($deleted) $this->cleanupEmptyUploadDirectories(dirname($candidate));
        return $deleted;
    }

    /** @return array{bytes:string,mime:string,name:string,sha256:string,size:int,meta:array<string,mixed>}|null */
    public function payload(string $value): ?array
    {
        $m=self::descriptor($value); if ($m===null) return null;
        $bytes='';
        if (($m['storage']??'')==='database') {
            $decoded=base64_decode((string)($m['data_base64']??''),true);
            if ($decoded===false) return null;
            $bytes=$decoded;
        } elseif (($m['storage']??'')==='filesystem') {
            $candidate=$this->managedFilesystemPath((string)($m['path']??''));
            if ($candidate===null || !is_file($candidate) || is_link($candidate)) return null;
            $read=file_get_contents($candidate); if ($read===false) return null; $bytes=$read;
        } else return null;
        if (strlen($bytes)!==(int)$m['size']) return null;
        $sha=hash('sha256',$bytes);
        if (!hash_equals((string)$m['sha256'],$sha)) return null;
        $public=$m; unset($public['path'],$public['data_base64']);
        return [
            'bytes'=>$bytes,
            'mime'=>(string)$m['mime'],
            'name'=>$this->safeFilename((string)$m['name']),
            'sha256'=>$sha,
            'size'=>strlen($bytes),
            'meta'=>$public,
        ];
    }

    private function ensureStorageProtection(string $baseDir): void
    {
        if (!is_dir($baseDir) && !mkdir($baseDir,0770,true) && !is_dir($baseDir)) throw new RuntimeException('Media-Speicher kann nicht erstellt werden.');
        $htaccess=$baseDir.'/.htaccess';
        if (!is_file($htaccess)) {
            $rules="Options -Indexes -ExecCGI\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\nRemoveHandler .php .phtml .php3 .php4 .php5 .phar .cgi .pl .py .sh\n";
            if (file_put_contents($htaccess,$rules,LOCK_EX)===false) throw new RuntimeException('Media-Speicherschutz kann nicht geschrieben werden.');
            @chmod($htaccess,0660);
        }
        $index=$baseDir.'/index.html';
        if (!is_file($index)) @file_put_contents($index,'',LOCK_EX);
    }

    private function managedFilesystemPath(string $relative): ?string
    {
        $relative=str_replace('\\','/',ltrim($relative,'/\\'));
        if ($relative==='' || preg_match('#(^|/)\.\.(/|$)#',$relative)===1) return null;
        $isLegacy=str_starts_with($relative,'storage/dataform/uploads/');
        $isProject=preg_match('#^storage/projects/prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}/documents/dataform/#',$relative)===1;
        if (!$isLegacy && !$isProject) return null;

        $raw=$this->root.'/'.$relative;
        if (is_link($raw)) return null;
        $candidate=realpath($raw);
        if ($candidate!==false) {
            $allowedBase=$isProject ? $this->root.'/storage/projects' : $this->root.'/storage/dataform/uploads';
            $base=realpath($allowedBase);
            if ($base!==false && str_starts_with($candidate,$base.DIRECTORY_SEPARATOR)) return $candidate;
        }

        // Legacy descriptors remain readable after migration: map
        // storage/dataform/uploads/project-N/... into the new project document tree.
        if ($isLegacy && preg_match('#^storage/dataform/uploads/project-([0-9]+)/(.+)$#',$relative,$m)===1) {
            $projectId=(int)$m[1];
            $tail=(string)$m[2];
            $base=$this->projectDataFormBase($projectId,false);
            if ($base!==null) {
                $mapped=$base.'/'.$tail;
                if (!is_link($mapped)) {
                    $real=realpath($mapped);
                    $baseReal=realpath($base);
                    if ($real!==false && $baseReal!==false && str_starts_with($real,$baseReal.DIRECTORY_SEPARATOR)) return $real;
                }
            }
        }
        return null;
    }

    private function projectDataFormBase(int $projectId,bool $create=true): ?string
    {
        if ($projectId>0 && function_exists('enterprise_project_storage_documents_path_by_id')) {
            $base=enterprise_project_storage_documents_path_by_id($projectId,'dataform');
            if (is_string($base) && $base!=='') {
                if ($create) $this->ensureStorageProtection($base);
                return $base;
            }
        }
        // Stand-alone exported runtimes do not have the Enterprise project registry.
        $cfgFile=$this->root.'/config.php';
        if ($projectId>0 && is_file($cfgFile)) {
            try {
                $cfg=require $cfgFile;
                $storageKey=is_array($cfg)?trim((string)($cfg['storage_key']??'')):'';
                if ($storageKey!=='' && preg_match('/^prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}$/',$storageKey)===1) {
                    $base=$this->root.'/storage/projects/'.$storageKey.'/documents/dataform';
                    if ($create) $this->ensureStorageProtection($base);
                    return $base;
                }
            } catch (Throwable) {}
        }
        if (!$create) return null;
        throw new RuntimeException('Projekt-Storage-Key fehlt. Neue Dateien dürfen nicht mehr im Legacy-Uploadbereich gespeichert werden. Storage-Migration bzw. aktuelles Anwenderpaket erforderlich.');
    }

    private function cleanupEmptyUploadDirectories(string $dir): void
    {
        $normalized=str_replace('\\','/',$dir);
        $bases=[];
        foreach([$this->root.'/storage/dataform/uploads',$this->root.'/storage/projects'] as $candidate){
            $real=realpath($candidate); if($real!==false)$bases[]=$real;
        }
        while (is_dir($dir)) {
            $real=realpath($dir);
            if ($real===false) break;
            $stop=false;
            foreach($bases as $base){ if($real===$base){$stop=true;break;} }
            if($stop)break;
            if(str_ends_with(str_replace('\\','/',$real),'/documents/dataform')) break;
            $items=@scandir($real);
            if (!is_array($items) || count(array_diff($items,['.','..']))>0) break;
            if (!@rmdir($real)) break;
            $dir=dirname($real);
        }
    }

    private function mimeAccepted(string $mime,string $accept): bool
    {
        $accepted=array_values(array_filter(array_map('trim',explode(',',$accept)),static fn(string $v):bool=>$v!==''));
        if (!$accepted) return true;
        foreach($accepted as $rule) {
            $rule=strtolower($rule);
            if ($rule===$mime) return true;
            if (str_ends_with($rule,'/*') && str_starts_with($mime,substr($rule,0,-1))) return true;
        }
        return false;
    }

    private function safeFilename(string $name): string
    {
        $name=str_replace(["\r","\n","\0"],' ',basename($name));
        $n=preg_replace('/[^A-Za-z0-9._ -]+/u','_',$name)?:'download.bin';
        $n=trim(preg_replace('/\s+/u',' ',$n)??$n," .");
        if ($n==='') $n='download.bin';
        return DataFormFieldTypeRegistry::substrText($n,0,180);
    }
    private function safeSegment(string $value): string { $v=preg_replace('/[^A-Za-z0-9._-]+/u','_',trim($value))?:'field'; return substr($v,0,80); }

    private function extensionFor(string $mime): string
    {
        $map=[
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif',
            'application/pdf'=>'pdf','text/plain'=>'txt','text/csv'=>'csv','application/zip'=>'zip','application/json'=>'json',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
        ];
        // Unknown/custom MIME types deliberately get .bin so an uploaded file can
        // never acquire an executable extension merely from its client filename.
        return $map[$mime]??'bin';
    }

    private function uploadErrorMessage(int $error): string
    {
        return match($error) {
            UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE=>'Upload fehlgeschlagen: Datei ist größer als das serverseitige Upload-Limit.',
            UPLOAD_ERR_PARTIAL=>'Upload fehlgeschlagen: Datei wurde nur teilweise übertragen.',
            UPLOAD_ERR_NO_TMP_DIR=>'Upload fehlgeschlagen: temporäres Server-Verzeichnis fehlt.',
            UPLOAD_ERR_CANT_WRITE=>'Upload fehlgeschlagen: Datei kann auf dem Server nicht geschrieben werden.',
            UPLOAD_ERR_EXTENSION=>'Upload fehlgeschlagen: eine PHP-Erweiterung hat den Upload gestoppt.',
            default=>'Upload fehlgeschlagen (Code '.$error.').',
        };
    }
}
