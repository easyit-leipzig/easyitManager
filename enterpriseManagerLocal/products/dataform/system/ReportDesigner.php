<?php
declare(strict_types=1);

final class ReportDesigner
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            description TEXT NULL,
            source_type VARCHAR(30) NOT NULL DEFAULT 'dataform',
            source_id BIGINT UNSIGNED NOT NULL,
            page_format VARCHAR(20) NOT NULL DEFAULT 'A4',
            orientation VARCHAR(20) NOT NULL DEFAULT 'portrait',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_reports_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NULL,
            position INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_report_pages_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_elements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id BIGINT UNSIGNED NOT NULL,
            page_id BIGINT UNSIGNED NULL,
            element_type VARCHAR(30) NOT NULL,
            title VARCHAR(190) NULL,
            config_json LONGTEXT NOT NULL,
            position INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_report_elements_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_parameters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            label VARCHAR(190) NOT NULL,
            parameter_type VARCHAR(30) NOT NULL DEFAULT 'text',
            default_value TEXT NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 1,
            UNIQUE KEY uq_report_parameter (report_id,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS report_exports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id BIGINT UNSIGNED NOT NULL,
            export_format VARCHAR(20) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            row_count INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_report_exports_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        return trim($value, '-') ?: 'report-'.date('YmdHis');
    }

    public static function sourceRows(PDO $pdo, array $report, array $parameters = []): array
    {
        if (($report['source_type'] ?? 'dataform') === 'query') {
            $stmt=$pdo->prepare('SELECT dataform_id,definition_json FROM dataform_queries WHERE id=?');
            $stmt->execute([(int)$report['source_id']]);
            $query=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$query) return [];
            require_once __DIR__.'/VisualQueryBuilder.php';
            $definition=json_decode((string)$query['definition_json'],true)?:[];
            return VisualQueryBuilder::execute($pdo,(int)$query['dataform_id'],$definition)['rows'];
        }
        $stmt=$pdo->prepare('SELECT id,data_json,created_at,updated_at FROM dataform_records WHERE dataform_id=? ORDER BY id DESC LIMIT 500');
        $stmt->execute([(int)$report['source_id']]);
        $rows=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $data=json_decode((string)$row['data_json'],true)?:[];
            $rows[]=array_merge(['id'=>$row['id'],'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']],$data);
        }
        return $rows;
    }

    public static function renderHtml(array $report, array $elements, array $rows): string
    {
        ob_start();
        echo '<article class="report-document report-'.htmlspecialchars((string)$report['orientation'],ENT_QUOTES,'UTF-8').'">';
        foreach($elements as $element){
            $config=json_decode((string)$element['config_json'],true)?:[];
            $title=htmlspecialchars((string)($element['title']??''),ENT_QUOTES,'UTF-8');
            switch($element['element_type']){
                case 'title': echo '<h1>'.$title.'</h1>'; break;
                case 'text': echo '<div class="report-text">'.nl2br(htmlspecialchars((string)($config['text']??''),ENT_QUOTES,'UTF-8')).'</div>'; break;
                case 'line': echo '<hr>'; break;
                case 'page_break': echo '<div class="report-page-break"></div>'; break;
                case 'kpi':
                    $field=(string)($config['field']??'');$sum=0.0;
                    foreach($rows as $row)$sum+=(float)($row[$field]??0);
                    echo '<section class="report-kpi"><strong>'.$title.'</strong><span>'.htmlspecialchars(number_format($sum,2,',','.'),ENT_QUOTES,'UTF-8').'</span></section>'; break;
                case 'table':
                    $fields=(array)($config['fields']??[]);
                    if(!$fields && $rows)$fields=array_keys($rows[0]);
                    echo '<section><h2>'.$title.'</h2><div class="table-wrap"><table><thead><tr>';
                    foreach($fields as $field)echo '<th>'.htmlspecialchars((string)$field,ENT_QUOTES,'UTF-8').'</th>';
                    echo '</tr></thead><tbody>';
                    foreach($rows as $row){echo '<tr>';foreach($fields as $field)echo '<td>'.htmlspecialchars((string)($row[$field]??''),ENT_QUOTES,'UTF-8').'</td>';echo '</tr>';}
                    echo '</tbody></table></div></section>'; break;
                case 'chart':
                    $field=(string)($config['field']??'');$counts=[];
                    foreach($rows as $row){$key=(string)($row[$field]??'–');$counts[$key]=($counts[$key]??0)+1;}
                    arsort($counts);$max=max($counts?:[1]);
                    echo '<section><h2>'.$title.'</h2><div class="report-chart">';
                    foreach(array_slice($counts,0,12,true) as $label=>$count){$width=(int)round($count/$max*100);echo '<div class="report-chart-row"><span>'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</span><i style="width:'.$width.'%"></i><b>'.$count.'</b></div>';}
                    echo '</div></section>'; break;
            }
        }
        echo '</article>';
        return (string)ob_get_clean();
    }
}
