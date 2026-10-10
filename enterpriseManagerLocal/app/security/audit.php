<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'permissions.manage');
$pdo=enterprise_pdo();
enterprise_upgrade($pdo);

function audit_redact_value(mixed $value, ?string $key=null): mixed {
    $sensitive=['password','passwd','pass','password_hash','secret','token','api_key','apikey','authorization','cookie','session','csrf','private_key','access_key'];
    $normalized=$key===null?'':strtolower(preg_replace('/[^a-z0-9]+/i','_',$key));
    foreach($sensitive as $needle){
        if($normalized!=='' && (str_contains($normalized,$needle) || $normalized===$needle)) return '[REDACTED]';
    }
    if(is_array($value)){
        $out=[]; foreach($value as $k=>$v)$out[$k]=audit_redact_value($v,(string)$k); return $out;
    }
    if(is_object($value)) return audit_redact_value((array)$value,$key);
    return $value;
}
function audit_context_display(?string $json): string {
    if($json===null || trim($json)==='') return '';
    $decoded=json_decode($json,true);
    if(!is_array($decoded)) return '[invalid or legacy context]';
    return (string)json_encode(audit_redact_value($decoded),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

$action=trim((string)($_GET['action']??''));
$actor=trim((string)($_GET['actor']??''));
$type=trim((string)($_GET['object_type']??''));
$q=trim((string)($_GET['q']??''));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$page=max(1,(int)($_GET['page']??1));
$perPage=50; $where=[]; $params=[];
if($action!==''){$where[]='a.action LIKE ?';$params[]='%'.$action.'%';}
if($actor!==''){$where[]='(u.username LIKE ? OR CAST(a.user_id AS CHAR) LIKE ?)';$params[]='%'.$actor.'%';$params[]='%'.$actor.'%';}
if($type!==''){$where[]='a.object_type = ?';$params[]=$type;}
if($q!==''){$where[]='(a.action LIKE ? OR a.object_type LIKE ? OR a.object_id LIKE ? OR a.context_json LIKE ? OR u.username LIKE ?)';for($i=0;$i<5;$i++)$params[]='%'.$q.'%';}
if($dateFrom!==''){$where[]='a.created_at >= ?';$params[]=$dateFrom.' 00:00:00';}
if($dateTo!==''){$where[]='a.created_at <= ?';$params[]=$dateTo.' 23:59:59';}
$whereSql=$where?' WHERE '.implode(' AND ',$where):'';
$count=$pdo->prepare('SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id=a.user_id'.$whereSql);
$count->execute($params); $total=(int)$count->fetchColumn();
$pages=max(1,(int)ceil($total/$perPage)); if($page>$pages)$page=$pages; $offset=($page-1)*$perPage;
$sql='SELECT a.*,u.username FROM audit_log a LEFT JOIN users u ON u.id=a.user_id'.$whereSql.' ORDER BY a.id DESC LIMIT '.$perPage.' OFFSET '.$offset;
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
$types=$pdo->query("SELECT DISTINCT object_type FROM audit_log WHERE object_type IS NOT NULL AND object_type<>'' ORDER BY object_type")->fetchAll(PDO::FETCH_COLUMN);

ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'../dashboard.php'],['label'=>'Benutzer & Rechte','href'=>'users.php'],['label'=>'Audit-Protokoll','href'=>'']]);
?>
<section class="hero"><span class="badge">HF10 · Security Audit</span><h1>Audit-Protokoll</h1><p>Sicherheits- und Administrationsereignisse nachvollziehen. Sensible Kontextwerte werden bei der Anzeige automatisch redigiert.</p><div class="actions"><a class="button secondary" <?= easyit_button_attributes('security_benutzer') ?> href="users.php">Benutzer</a><a class="button secondary" <?= easyit_button_attributes('security_rollen') ?> href="roles.php">Rollen</a><a class="button secondary" <?= easyit_button_attributes('security_capabilities') ?> href="capabilities.php">Capabilities</a></div></section>
<section class="card"><h2>Filter</h2><form method="get" class="form-grid">
<label>Von<input type="date" name="date_from" value="<?=e($dateFrom)?>"></label><label>Bis<input type="date" name="date_to" value="<?=e($dateTo)?>"></label><label>Akteur<input name="actor" value="<?=e($actor)?>" placeholder="Benutzername oder ID"></label><label>Aktion<input name="action" value="<?=e($action)?>" placeholder="security.user"></label><label>Objekttyp<select name="object_type"><option value="">Alle</option><?php foreach($types as $t):?><option value="<?=e((string)$t)?>" <?=$type===(string)$t?'selected':''?>><?=e((string)$t)?></option><?php endforeach;?></select></label><label>Volltext<input name="q" value="<?=e($q)?>" placeholder="Aktion, Objekt, Kontext …"></label><div><button class="button" <?= easyit_button_attributes('filter','filter') ?> type="submit">Filtern</button> <a class="button secondary" <?= easyit_button_attributes('filter_loeschen','filter') ?> href="audit.php">Zurücksetzen</a></div>
</form></section>
<section class="card"><h2>Ereignisse</h2><p><?=number_format($total,0,',','.')?> Treffer · Seite <?=$page?> von <?=$pages?></p><div style="overflow:auto"><table><thead><tr><th>Zeitpunkt</th><th>Akteur</th><th>Aktion</th><th>Objekt</th><th>Details</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="5">Keine Audit-Ereignisse für diesen Filter.</td></tr><?php endif;?>
<?php foreach($rows as $row):$safe=audit_context_display($row['context_json']??null);?><tr><td><?=e((string)$row['created_at'])?></td><td><?=e((string)($row['username']??('User #'.($row['user_id']??'–'))))?></td><td><code><?=e((string)$row['action'])?></code></td><td><?=e((string)($row['object_type']??'–'))?><?=($row['object_id']??'')!==''?' #'.e((string)$row['object_id']):''?></td><td><?php if($safe!==''):?><details><summary>Kontext anzeigen</summary><pre><?=e($safe)?></pre></details><?php else:?>–<?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
<?php $query=$_GET;unset($query['page']);$baseQuery=http_build_query($query);$sep=$baseQuery===''?'?':'?'.$baseQuery.'&';?><div class="actions" style="margin-top:1rem"><?php if($page>1):?><a class="button" <?= easyit_button_attributes('zurueck') ?> href="audit.php<?=$sep?>page=<?=$page-1?>">← Neuere</a><?php endif;?><?php if($page<$pages):?><a class="button" <?= easyit_button_attributes('weiter') ?> href="audit.php<?=$sep?>page=<?=$page+1?>">Ältere →</a><?php endif;?></div></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'Audit-Protokoll','active'=>'audit','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user,'help'=>['title'=>'Audit-Protokoll','location'=>'Enterprise → Benutzer & Rechte → Audit-Protokoll','short'=>'Administrations- und Sicherheitsereignisse suchen, filtern und nachvollziehen.','goal'=>'Änderungen revisionsfähig nachvollziehen, ohne Geheimnisse oder Kennwörter offenzulegen.','tips'=>['Filter können kombiniert werden.','Passwort-, Token- und Secret-Felder werden in der Anzeige redigiert.','Das Audit-Protokoll ist nur mit permissions.manage zugänglich.']]]);
