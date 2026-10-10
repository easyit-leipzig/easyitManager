<?php
declare(strict_types=1);

final class VisualQueryBuilder
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_queries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            dataform_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            definition_json LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dataform_query_name (dataform_id,name),
            KEY idx_dataform_queries_form (dataform_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function normalizeDefinition(array $input, array $fieldMap): array
    {
        $selected = [];
        foreach ((array)($input['fields'] ?? []) as $name) {
            $name = (string)$name;
            if (isset($fieldMap[$name])) $selected[] = $name;
        }
        if (!$selected) $selected = array_slice(array_keys($fieldMap), 0, 5);
        $filters = [];
        foreach ((array)($input['filters'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['field'] ?? '');
            $operator = (string)($row['operator'] ?? 'equals');
            if (!isset($fieldMap[$field]) || !in_array($operator, ['equals','not_equals','contains','starts_with','empty','not_empty','greater','less'], true)) continue;
            $filters[] = ['field'=>$field,'operator'=>$operator,'value'=>(string)($row['value'] ?? '')];
        }
        $sortField = (string)($input['sort_field'] ?? 'id');
        if ($sortField !== 'id' && $sortField !== 'updated_at' && !isset($fieldMap[$sortField])) $sortField = 'id';
        $direction = strtoupper((string)($input['sort_direction'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $limit = max(1, min(200, (int)($input['limit'] ?? 25)));
        return ['fields'=>array_values(array_unique($selected)),'filters'=>$filters,'sort_field'=>$sortField,'sort_direction'=>$direction,'limit'=>$limit];
    }

    public static function execute(PDO $pdo, int $dataformId, array $definition): array
    {
        $select = ['id','created_at','updated_at'];
        foreach ($definition['fields'] as $field) {
            $select[] = "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.\"".str_replace('"','\\"',$field)."\"')) AS `".str_replace('`','',$field)."`";
        }
        $where = ['dataform_id = :dataform_id'];
        $params = ['dataform_id'=>$dataformId];
        foreach ($definition['filters'] as $i=>$filter) {
            $expr = "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.\"".str_replace('"','\\"',$filter['field'])."\"'))";
            $key = 'v'.$i;
            switch ($filter['operator']) {
                case 'equals': $where[]="$expr = :$key"; $params[$key]=$filter['value']; break;
                case 'not_equals': $where[]="COALESCE($expr,'') <> :$key"; $params[$key]=$filter['value']; break;
                case 'contains': $where[]="$expr LIKE :$key"; $params[$key]='%'.$filter['value'].'%'; break;
                case 'starts_with': $where[]="$expr LIKE :$key"; $params[$key]=$filter['value'].'%'; break;
                case 'empty': $where[]="COALESCE($expr,'') = ''"; break;
                case 'not_empty': $where[]="COALESCE($expr,'') <> ''"; break;
                case 'greater': $where[]="CAST($expr AS DECIMAL(30,8)) > CAST(:$key AS DECIMAL(30,8))"; $params[$key]=$filter['value']; break;
                case 'less': $where[]="CAST($expr AS DECIMAL(30,8)) < CAST(:$key AS DECIMAL(30,8))"; $params[$key]=$filter['value']; break;
            }
        }
        $sort = in_array($definition['sort_field'], ['id','updated_at'], true)
            ? $definition['sort_field']
            : "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.\"".str_replace('"','\\"',$definition['sort_field'])."\"'))";
        $sql='SELECT '.implode(',',$select).' FROM dataform_records WHERE '.implode(' AND ',$where).' ORDER BY '.$sort.' '.$definition['sort_direction'].' LIMIT '.(int)$definition['limit'];
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        return ['sql'=>$sql,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}
