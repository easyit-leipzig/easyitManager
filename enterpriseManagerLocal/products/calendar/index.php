<?php
declare(strict_types=1);
$enterpriseRoot=dirname(__DIR__,2);
require_once $enterpriseRoot.'/system/app/bootstrap.php';
$user=enterprise_require_auth('../../');
$projectId=(int)($_GET['project']??0);
if($projectId<1){http_response_code(400);exit('Projekt fehlt.');}
$adminPdo=enterprise_pdo();enterprise_upgrade($adminPdo);
$st=$adminPdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');$st->execute([$projectId]);$enterpriseProject=$st->fetch(PDO::FETCH_ASSOC);
if(!$enterpriseProject||(string)$enterpriseProject['product_type']!=='calendar'){http_response_code(404);exit('Calendar-Projekt nicht gefunden.');}
enterprise_require_project_scope($user,$projectId,false);
enterprise_require_project_product($user,$projectId,'calendar',(string)$enterpriseProject['slug']);
$_SESSION['easyit_calendar_project_id']=$projectId;
require_once $enterpriseRoot.'/system/app/calendar_project.php';
require_once __DIR__.'/api/runtime.php';
require_once __DIR__.'/system/CalendarDataFormBridge.php';
$calendarCfg=enterprise_calendar_config_load($enterpriseProject);
// HF105: Bestehende Calendar-Projekte werden beim Öffnen idempotent auf die
// gemeinsame Calendar/DataForm-Struktur angehoben.
try{
    $calendarEnv=enterprise_env($enterpriseRoot.'/DataForm5-Core/.env');
    $calendarPdo=enterprise_project_store_for_project($calendarEnv,$enterpriseProject);
    $calendarCtx=['project'=>$enterpriseProject,'env'=>$calendarEnv,'config'=>$calendarCfg];
    $calendarIntegration=easyit_calendar_dataform_ensure($calendarPdo,$calendarCtx);
    foreach((array)($calendarIntegration['bindings']??[]) as $logical=>$binding)$calendarCfg['dataforms'][$logical]=(int)($binding['id']??0);
    $calendarCfg['term_dialog']['dataform_id']=(int)($calendarCfg['dataforms']['events']??0);
    $calendarCfg['term_dialog']['use_dataform']=true;
    enterprise_calendar_config_save($enterpriseProject,$calendarCfg);
}catch(Throwable $calendarIntegrationError){
    $calendarIntegrationErrorMessage=$calendarIntegrationError->getMessage();
}
$termDialogCfg=is_array($calendarCfg['term_dialog']??null)?$calendarCfg['term_dialog']:[];
$termDataformId=max(0,(int)($termDialogCfg['dataform_id']??0));
$useDataformDialog=$termDataformId>0;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['easyit_calendar_csrf'])) {
    $_SESSION['easyit_calendar_csrf'] = bin2hex(random_bytes(32));
}
$easyITCalendarCsrf = $_SESSION['easyit_calendar_csrf'];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>easyITCalendar – Stundenplanung</title>
  <link rel="stylesheet" href="assets/css/calendar.css?v=20261009-153">
</head>
<body>
  <header class="app-header">
    <a class="brand" href="index.php" aria-label="easyITCalendar Startseite">
      <img class="brand-logo" src="assets/img/easyITCalendar-logo.png" alt="easyITCalendar">
      <span class="brand-text">
        <strong>easyITCalendar</strong>
        <small>Stundenplanung im 30-Minuten-Raster</small>
      </span>
    </a>
    <nav class="toolbar" aria-label="Kalendersteuerung">
      <div class="view-switch" role="group" aria-label="Kalenderansicht">
        <button id="dayView" type="button" aria-pressed="false">Tag</button>
        <button id="weekView" class="active" type="button" aria-pressed="true">Woche</button>
        <button id="monthView" type="button" aria-pressed="false">Monat</button>
      </div>
      <button id="prevWeek" type="button" aria-label="Vorheriger Zeitraum">‹</button>
      <button id="today" type="button">Heute</button>
      <button id="nextWeek" type="button" aria-label="Nächster Zeitraum">›</button>
      <button id="blockMode" type="button" aria-pressed="false">Zeit blockieren</button>
      <a class="help-button" href="setup.php?project=<?= (int)$projectId ?>">Setup</a>
      <button id="helpButton" class="help-button" type="button" aria-haspopup="dialog">Hilfe</button>
      <strong id="weekLabel" aria-live="polite"></strong>
    </nav>
  </header>

  <main class="calendar-shell">
    <p id="calendarStatus" class="calendar-status info" role="status" aria-live="polite">In der Tages- und Wochenansicht können Termine verschoben und Zeiten blockiert werden. Die Monatsansicht zeigt ausschließlich alle Termine ohne Drag-and-drop.</p>
    <section class="calendar" aria-label="Wochenkalender">
      <div id="calendarGrid" class="calendar-grid"></div>
    </section>
  </main>

  <dialog id="dataFormLessonDialog" class="dataform-lesson-dialog">
    <div class="dataform-dialog-shell">
      <header><strong id="dataFormLessonTitle">Termin</strong><button id="closeDataFormLesson" type="button" aria-label="Schließen">×</button></header>
      <iframe id="dataFormLessonFrame" title="Termindatensatz bearbeiten"></iframe>
    </div>
  </dialog>
  <?php if(!empty($calendarIntegrationErrorMessage)): ?><p class="calendar-status error" role="alert">Calendar/DataForm-Integration: <?= htmlspecialchars($calendarIntegrationErrorMessage,ENT_QUOTES,'UTF-8') ?></p><?php endif; ?>


  <dialog id="blockDialog" class="block-dialog" aria-labelledby="blockDialogTitle">
    <form id="blockForm" method="dialog">
      <header class="block-dialog-header"><div><h2 id="blockDialogTitle">Zeitraum blockieren</h2><p id="blockRangeLabel"></p></div><button id="closeBlockDialog" type="button" aria-label="Blockierungsdialog schließen">×</button></header>
      <input type="hidden" id="blockId" value="">
      <label>Kategorie<select id="blockCategory" required></select></label>
      <label>Person<select id="blockPerson"><option value="0">Alle Personen</option></select></label>
      <small class="block-person-help">Keine Person gewählt = Blockierung gilt für alle Personen.</small>
      <label>Grund / Bemerkung<textarea id="blockReason" rows="3" maxlength="255"></textarea></label>
      <p id="blockError" class="error" role="alert"></p>
      <div class="dialog-actions"><button id="deleteBlock" class="danger" type="button" hidden>Blockierung löschen</button><span></span><button id="cancelBlock" type="button">Abbrechen</button><button id="saveBlock" class="primary" type="submit">Speichern</button></div>
    </form>
  </dialog>

  <dialog id="helpDialog" class="help-dialog" aria-labelledby="helpDialogTitle">
    <div class="help-dialog-shell">
      <header class="help-dialog-header">
        <div>
          <h2 id="helpDialogTitle">Kalenderhilfe</h2>
          <p id="helpDialogSubtitle">Bedienungsanleitung</p>
        </div>
        <button id="closeHelp" type="button" aria-label="Hilfefenster schließen">×</button>
      </header>
      <iframe id="helpFrame" title="Bedienungsanleitung des Kalenders" src="help/index.html"></iframe>
    </div>
  </dialog>

  <script src="assets/js/nojquery.core.js?v=20261003-131"></script>
  <script src="assets/js/nojquery.post.js?v=20261003-131"></script>
  <script>
    window.easyITCalendarHandler = "api/calendar_handler.php";
    window.easyITCalendarCsrf = <?= json_encode($easyITCalendarCsrf, JSON_UNESCAPED_SLASHES) ?>;
    window.easyITCalendarProjectId = <?= (int)$projectId ?>;
    window.easyITCalendarTermDataFormId = <?= (int)$termDataformId ?>;
    window.easyITCalendarUseDataFormDialog = <?= $useDataformDialog ? 'true':'false' ?>;
    window.easyITCalendarCloseDataFormAfterSave = <?= !empty($termDialogCfg['close_after_save']) ? 'true':'false' ?>;
    window.easyITCalendarDataFormRecordUrl = "../dataform/records.php";
    window.easyITCalendarProjectCalendarConfig = <?= json_encode($calendarCfg['calendar']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script src="assets/js/bootstrap.js?v=20261003-131"></script>
</body>
</html>
