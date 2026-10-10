<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/ui/layout.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'background.view');

$pdo=enterprise_pdo();
enterprise_upgrade($pdo);
$admin=enterprise_module_background_admin();
$message='';
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_require_capability($user,'background.manage');
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');

        if($action==='dispatch'){
            $job=trim((string)($_POST['job']??''));
            if($job==='') throw new RuntimeException('Bitte einen Job auswählen.');
            $payloadText=trim((string)($_POST['payload']??''));
            $payload=[];
            if($payloadText!==''){
                $decoded=json_decode($payloadText,true,64,JSON_THROW_ON_ERROR);
                if(!is_array($decoded)) throw new RuntimeException('Payload muss ein JSON-Objekt sein.');
                $payload=$decoded;
            }
            $id=$admin->dispatch($job,$payload);
            enterprise_audit($pdo,(int)($user['id']??0),'background.dispatch','job',$job,['job_id'=>$id]);
            $message="Job {$job} wurde eingereiht ({$id}).";
        }elseif($action==='work'){
            $count=$admin->work('file',max(1,min(500,(int)($_POST['max_jobs']??25))));
            enterprise_audit($pdo,(int)($user['id']??0),'background.work','queue','file',['processed'=>$count]);
            $message="Queue-Worker hat {$count} Job(s) verarbeitet.";
        }elseif($action==='run_due'){
            $results=$admin->runDue();
            enterprise_audit($pdo,(int)($user['id']??0),'background.run_due','scheduler','module',['results'=>$results]);
            $message=$results===[]?'Keine geplanten Tasks waren fällig.':'Scheduler ausgeführt: '.count($results).' Task(s).';
        }elseif($action==='retry_failed'){
            $id=trim((string)($_POST['id']??''));
            if(!$admin->retryFailed($id,'file')) throw new RuntimeException('Fehlgeschlagener Job wurde nicht gefunden.');
            enterprise_audit($pdo,(int)($user['id']??0),'background.retry','job',$id);
            $message="Fehlgeschlagener Job {$id} wurde erneut eingereiht.";
        }elseif($action==='delete_failed'){
            $id=trim((string)($_POST['id']??''));
            if(!$admin->deleteFailed($id,'file')) throw new RuntimeException('Fehlgeschlagener Job wurde nicht gefunden.');
            enterprise_audit($pdo,(int)($user['id']??0),'background.delete_failed','job',$id);
            $message="Fehlgeschlagener Job {$id} wurde gelöscht.";
        }else{
            throw new RuntimeException('Unbekannte Hintergrundaktion.');
        }
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$state=$admin->overview('file');
$canManage=enterprise_can($user,'background.manage');

ob_start();
?>
<h1>Hintergrundprozesse</h1>
<p>Queue-, Worker- und Scheduler-Status der Enterprise-Module.</p>
<?php if($message!==''):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error!==''):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<section class="card">
<h2>Queue-Status</h2>
<div class="stat-grid">
  <div><strong><?=e((string)$state['stats']['pending'])?></strong><span>Wartend</span></div>
  <div><strong><?=e((string)$state['stats']['processing'])?></strong><span>In Verarbeitung</span></div>
  <div><strong><?=e((string)$state['stats']['failed'])?></strong><span>Fehlgeschlagen</span></div>
</div>
<?php if($canManage):?>
<form method="post" style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="work">
<label>Max. Jobs<br><input type="number" min="1" max="500" name="max_jobs" value="25"></label>
<button class="button" type="submit">Worker jetzt ausführen</button>
</form>
<?php endif;?>
</section>

<section class="card">
<h2>Registrierte Modul-Jobs</h2>
<?php if($state['jobs']===[]):?><p>Keine Modul-Jobs registriert.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Job</th><th>Modul</th><th>Handler</th><th>Queue</th><th>Versuche</th></tr></thead><tbody>
<?php foreach($state['jobs'] as $job):?><tr>
<td><strong><?=e((string)$job['name'])?></strong></td>
<td><?=e((string)$job['module'])?></td>
<td><code><?=e((string)$job['handler'])?></code></td>
<td><?=e((string)($job['queue']?:'Standard'))?></td>
<td><?=e((string)$job['max_attempts'])?></td>
</tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
<?php if($canManage && $state['jobs']!==[]):?>
<h3>Job manuell starten</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="dispatch">
<p><label>Job<br><select name="job" required><?php foreach($state['jobs'] as $job):?><option value="<?=e((string)$job['name'])?>"><?=e((string)$job['name'])?></option><?php endforeach;?></select></label></p>
<p><label>Payload (JSON)<br><textarea name="payload" rows="5" placeholder='{"project": 12}'></textarea></label></p>
<button class="button" type="submit">Job einreihen</button>
</form>
<?php endif;?>
</section>

<section class="card">
<h2>Geplante Tasks</h2>
<?php if($state['tasks']===[]):?><p>Keine Scheduler-Tasks registriert.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Task</th><th>Cron</th><th>Fällig</th><th>Overlap-Schutz</th><th>Lock TTL</th></tr></thead><tbody>
<?php foreach($state['tasks'] as $task):?><tr>
<td><strong><?=e((string)$task['name'])?></strong></td>
<td><code><?=e((string)$task['cron'])?></code></td>
<td><?=$task['due']?'<strong>ja</strong>':'nein'?></td>
<td><?=$task['without_overlapping']?'ja':'nein'?></td>
<td><?=e((string)$task['lock_ttl'])?> s</td>
</tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
<?php if($canManage):?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="run_due">
<button class="button" type="submit">Jetzt fällige Tasks ausführen</button>
</form>
<?php endif;?>
</section>

<section class="card">
<h2>Fehlgeschlagene Jobs</h2>
<?php if($state['failed']===[]):?><p>Keine fehlgeschlagenen Jobs.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Jobklasse</th><th>Versuche</th><th>Fehler</th><th>Zeit</th><?php if($canManage):?><th>Aktionen</th><?php endif;?></tr></thead><tbody>
<?php foreach($state['failed'] as $failed): $id=(string)($failed['id']??''); $err=is_array($failed['error']??null)?$failed['error']:[]; ?>
<tr>
<td><code><?=e($id)?></code></td>
<td><code><?=e((string)($failed['job']??'—'))?></code></td>
<td><?=e((string)($failed['attempts']??0))?></td>
<td><strong><?=e((string)($err['class']??'Fehler'))?></strong><br><?=e((string)($err['message']??''))?></td>
<td><?=e((string)($failed['failed_at']??'—'))?></td>
<?php if($canManage):?><td>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="retry_failed">
<input type="hidden" name="id" value="<?=e($id)?>">
<button class="button secondary" type="submit">Retry</button>
</form>
<form method="post" style="display:inline" onsubmit="return confirm('Fehlgeschlagenen Job endgültig löschen?');">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="delete_failed">
<input type="hidden" name="id" value="<?=e($id)?>">
<button class="button secondary" type="submit">Löschen</button>
</form>
</td><?php endif;?>
</tr>
<?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>

<section class="card">
<h2>Scheduler-Historie</h2>
<?php if($state['history']===[]):?><p>Noch keine Scheduler-Ausführungen protokolliert.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Task</th><th>Status</th><th>Start</th><th>Ende</th><th>Fehler</th></tr></thead><tbody>
<?php foreach($state['history'] as $row):?><tr>
<td><?=e((string)($row['task']??'—'))?></td>
<td><?=e((string)($row['status']??'—'))?></td>
<td><?=e((string)($row['started_at']??'—'))?></td>
<td><?=e((string)($row['finished_at']??'—'))?></td>
<td><?=e((string)(is_array($row['error']??null)?($row['error']['message']??''):'—'))?></td>
</tr><?php endforeach;?>
</tbody></table></div>
<?php endif;?>
</section>
<?php
$content=ob_get_clean();

render_page([
    'title'=>'Hintergrundprozesse',
    'active'=>'background',
    'base'=>'../../',
    'content'=>$content,
    'app_nav'=>true,
    'user'=>$user,
    'help'=>[
        'title'=>'Queue & Scheduler',
        'location'=>'Enterprise → Hintergrundprozesse',
        'short'=>'Zeigt Jobs, Queue-Status, Scheduler-Aufgaben und Fehlerhistorie.',
        'goal'=>'Hintergrundprozesse zentral überwachen und kontrolliert ausführen.',
        'next'=>'Prüfen Sie zuerst wartende oder fehlgeschlagene Jobs.',
        'steps'=>['Queue-Status prüfen.','Fehler analysieren.','Bei Bedarf Retry ausführen.','Scheduler-Historie kontrollieren.'],
        'tips'=>['Produktiv sollte module-schedule-run.php regelmäßig durch Cron/Task Scheduler gestartet werden.','Retry setzt die Versuchsanzahl eines fehlgeschlagenen Jobs zurück.','Fehlerdetails zeigen bewusst keinen Stacktrace in der Weboberfläche.']
    ]
]);
