<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/system/app/bootstrap.php';
require_once $root.'/system/app/calendar_project.php';
$user=enterprise_require_auth('../../');
$id=(int)($_GET['project'] ?? $_SESSION['easyit_calendar_project_id'] ?? 0);
enterprise_require_project_scope($user,$id,true);
$pdo=enterprise_pdo(); enterprise_upgrade($pdo);
$st=$pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1'); $st->execute([$id]); $project=$st->fetch(PDO::FETCH_ASSOC);
if(!$project || (string)$project['product_type']!=='calendar'){http_response_code(404);exit('Calendar-Projekt nicht gefunden.');}
enterprise_require_project_product($user,$id,'calendar',(string)$project['slug']);
$_SESSION['easyit_calendar_project_id']=$id;

// HF99: Auch bereits vor HF99 angelegte Calendar-Projekte werden beim ersten
// Öffnen der Datenadministration automatisch auf die Calendar-Grundstruktur
// gebracht. Damit ist die Administration nie nur eine Ansicht.
require_once $root.'/system/app/project_store.php';
require_once $root.'/products/calendar/api/runtime.php';
$calendarEnv=enterprise_env($root.'/DataForm5-Core/.env');
$calendarPdo=enterprise_project_store_for_project($calendarEnv,$project);
$calendarCtx=['project'=>$project,'env'=>$calendarEnv,'config'=>enterprise_calendar_config_load($project)];
$calendarSchema=easyit_calendar_schema_status($calendarPdo,$calendarCtx);
if(!$calendarSchema['ready']){
    easyit_calendar_schema_install($calendarPdo,$calendarCtx);
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>easyITCalendar – Administration</title>
<style>body{font-family:Arial,sans-serif;margin:0;background:#eef3f8;color:#10233f}header{background:#12345b;color:#fff;padding:18px 28px;display:flex;justify-content:space-between}main{max-width:1300px;margin:24px auto;background:#fff;padding:24px;border-radius:12px}nav button,button{padding:9px 14px;margin:3px;border:1px solid #9bb1c8;border-radius:7px;background:#fff;cursor:pointer}nav button.active,.primary{background:#1d5fa7;color:#fff}.pane{display:none}.pane.active{display:block}.grid{display:grid;grid-template-columns:280px 1fr;gap:20px}.card{border:1px solid #ccd8e5;border-radius:9px;padding:16px;margin:12px 0}input,select,textarea{box-sizing:border-box;width:100%;padding:8px;margin:4px 0 10px;border:1px solid #b9c8d8;border-radius:5px}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border:1px solid #d9e1ea;padding:7px;text-align:left}th{background:#f3f6fa}.danger{color:#a00}.row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.status{padding:10px;background:#edf6ff;border-radius:6px}.mapping-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px}.mapping-line{border:1px solid #d7e1ec;border-radius:8px;padding:12px;margin:10px 0;background:#fbfdff}.mapping-line-grid{display:grid;grid-template-columns:1.1fr 1.5fr 1.5fr 1fr auto;gap:10px;align-items:end}.lookup-grid{display:grid;grid-template-columns:1.4fr 1fr 1.4fr;gap:10px;padding-top:8px}.mapping-line label{font-size:13px;font-weight:600}.mapping-line input,.mapping-line select{margin-bottom:0}.muted{color:#5d7088;font-size:13px}@media(max-width:900px){.mapping-line-grid,.lookup-grid,.row{grid-template-columns:1fr}}</style></head><body>
<header><strong>easyITCalendar – Datenadministration</strong><span><a style="color:white" href="index.php?project=<?= (int)$id ?>">Kalenderansicht</a> &nbsp; <a style="color:white" href="setup.php?project=<?= (int)$id ?>">Projekt-Setup</a> &nbsp; <a style="color:white" href="../../app/projects/view.php?id=<?= (int)$id ?>">Enterprise-Manager</a></span></header><main>
<nav><button class="tab active" data-p="tables">Datentabellen</button><button class="tab" data-p="data">Datenpflege</button><button class="tab" data-p="mapping">Calendar-Zuordnungen</button></nav><p id="status" class="status">Bereit.</p>
<section id="tables" class="pane active"><div class="grid"><div class="card"><h3>Tabellen</h3><select id="tableList" size="14"></select><h4>Neue Tabelle</h4><input id="newTable" placeholder="z. B. termine"><button id="createTable" class="primary">Tabelle anlegen</button><button id="dropTable" class="danger">Ausgewählte löschen</button></div><div class="card"><h3>Felder</h3><div id="columns"></div><h4>Feld hinzufügen</h4><div class="row"><input id="colName" placeholder="Feldname"><select id="colType"><option>VARCHAR</option><option>TEXT</option><option>INT</option><option>BIGINT</option><option>DECIMAL</option><option>DATE</option><option>DATETIME</option><option>TIME</option><option>TINYINT</option></select><label><input style="width:auto" id="nullable" type="checkbox"> NULL erlaubt</label></div><button id="addColumn" class="primary">Feld hinzufügen</button></div></div></section>
<section id="data" class="pane"><div class="card"><label>Tabelle<select id="dataTable"></select></label><button id="loadRows">Daten laden</button><button id="newRow">Neuer Datensatz</button><div id="rowEditor"></div><div style="overflow:auto" id="rows"></div></div></section>
<section id="mapping" class="pane"><div class="card"><h3>Kalender-Datenquelle und Feldzuordnung</h3><p>Die Kalenderlogik wird hier auf die Felder der Quelltabelle abgebildet. Neben den vier Pflichtrollen können beliebig viele zusätzliche Felder und Lookup-Beziehungen ergänzt werden.</p><label>Quelltabelle<select id="mapTable"></select></label><div class="mapping-head"><div><strong>Feldzuordnungen</strong><div class="muted">Pflicht: id, start, end, title. Zusätzliche Rollen wie location, category, resource oder eigene Felder sind frei erweiterbar.</div></div><button type="button" id="addMapping">+ Zuordnung hinzufügen</button></div><div id="mappingRows"></div><button id="saveMapping" class="primary">Alle Zuordnungen speichern</button></div></section>
</main><script>
const api='api/admin_handler.php', S=document.getElementById('status'); let tables=[];
async function call(action,extra={}){let r=await fetch(api,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,...extra})});let raw=await r.text(),j;try{j=JSON.parse(raw)}catch(e){throw Error('Calendar-API liefert kein gültiges JSON (HTTP '+r.status+'): '+raw.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').trim().slice(0,500))}if(!r.ok||!j.ok)throw Error(j.error||('HTTP '+r.status));return j} function msg(x){S.textContent=x}
document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab,.pane').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById(b.dataset.p).classList.add('active')});
async function refresh(){let j=await call('tables');tables=j.tables;['tableList','dataTable','mapTable'].forEach(id=>{let e=document.getElementById(id),v=e.value;e.innerHTML=tables.map(t=>`<option>${t}</option>`).join('');if(tables.includes(v))e.value=v});
  await showCols();
  if(dataTable.value){rowEditor.dataset.columns='';rowEditor.dataset.pk='';await loadRows();}
}
async function showCols(){let t=tableList.value;if(!t)return;let j=await call('columns',{table:t});columns.innerHTML='<table><tr><th>Name</th><th>Typ</th><th>NULL</th><th>Schlüssel</th><th></th></tr>'+j.columns.map(c=>`<tr><td>${c.COLUMN_NAME}</td><td>${c.COLUMN_TYPE}</td><td>${c.IS_NULLABLE}</td><td>${c.COLUMN_KEY}</td><td>${c.COLUMN_NAME==='id'?'':`<button onclick="dropCol('${c.COLUMN_NAME}')">löschen</button>`}</td></tr>`).join('')+'</table>'}
tableList.onchange=showCols;createTable.onclick=async()=>{await call('create_table',{name:newTable.value});newTable.value='';await refresh();msg('Tabelle angelegt.')};dropTable.onclick=async()=>{if(confirm('Tabelle wirklich löschen?')){await call('drop_table',{table:tableList.value});await refresh();msg('Tabelle gelöscht.')}};addColumn.onclick=async()=>{await call('add_column',{table:tableList.value,name:colName.value,type:colType.value,nullable:nullable.checked});colName.value='';await showCols();msg('Feld hinzugefügt.')};window.dropCol=async n=>{if(confirm('Feld '+n+' löschen?')){await call('drop_column',{table:tableList.value,name:n});await showCols()}};
async function loadRows(){
  let t=dataTable.value;
  if(!t){rows.innerHTML='<p>Keine Tabelle vorhanden.</p>';rowEditor.innerHTML='';return;}
  let j=await call('rows',{table:t});
  let names=j.columns.map(c=>c.COLUMN_NAME),pk=(j.columns.find(c=>c.COLUMN_KEY==='PRI')||{}).COLUMN_NAME;
  rowEditor.dataset.columns=JSON.stringify(j.columns);rowEditor.dataset.pk=pk||'';
  rows.innerHTML='<table><tr>'+names.map(n=>`<th>${esc(n)}</th>`).join('')+'<th>Aktion</th></tr>'+
    j.rows.map(r=>`<tr>${names.map(n=>`<td>${esc(r[n]??'')}</td>`).join('')}<td><button type="button" class="edit-record">bearbeiten</button>${pk?'<button type="button" class="delete-record danger">löschen</button>':''}</td></tr>`).join('')+'</table>';
  [...rows.querySelectorAll('tr')].slice(1).forEach((tr,i)=>{
    let r=j.rows[i];
    tr.querySelector('.edit-record')?.addEventListener('click',()=>editRow(r));
    tr.querySelector('.delete-record')?.addEventListener('click',()=>delRow(r[pk]));
  });
}
function esc(v){return String(v??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;')}
function inputFor(c,v){
  let n=c.COLUMN_NAME,val=esc(v??''),type='text';
  if(/date|time/i.test(c.DATA_TYPE||c.COLUMN_TYPE||'')) type=(/date.*time|datetime|timestamp/i.test(c.DATA_TYPE||c.COLUMN_TYPE||''))?'datetime-local':(/date/i.test(c.DATA_TYPE||'')?'date':'time');
  if(type==='datetime-local' && val) val=val.replace(' ','T').slice(0,16);
  let required=c.IS_NULLABLE==='NO' && !(c.EXTRA||'').includes('auto_increment')?' required':'';
  if(/text/i.test(c.DATA_TYPE||c.COLUMN_TYPE||'')) return `<label>${esc(n)}<textarea data-f="${esc(n)}"${required}>${val}</textarea></label>`;
  return `<label>${esc(n)}<input type="${type}" data-f="${esc(n)}" value="${val}"${required}></label>`;
}
async function ensureEditorColumns(){
  if(rowEditor.dataset.columns) return JSON.parse(rowEditor.dataset.columns);
  let t=dataTable.value;if(!t)throw Error('Bitte zuerst eine Tabelle auswählen.');
  let j=await call('columns',{table:t});rowEditor.dataset.columns=JSON.stringify(j.columns);
  rowEditor.dataset.pk=(j.columns.find(c=>c.COLUMN_KEY==='PRI')||{}).COLUMN_NAME||'';
  return j.columns;
}
async function editRow(r={}){
  let cs=await ensureEditorColumns(),pk=rowEditor.dataset.pk||'';
  rowEditor.innerHTML='<div class="card"><h4>'+(r&&Object.keys(r).length?'Datensatz bearbeiten':'Neuer Datensatz')+'</h4>'+
    cs.filter(c=>!(c.EXTRA||'').includes('auto_increment')).map(c=>inputFor(c,r[c.COLUMN_NAME])).join('')+
    '<button type="button" class="primary" id="saveRow">Speichern</button> <button type="button" id="cancelRow">Abbrechen</button></div>';
  document.getElementById('cancelRow').onclick=()=>rowEditor.innerHTML='';
  document.getElementById('saveRow').onclick=async()=>{
    let d={};rowEditor.querySelectorAll('[data-f]').forEach(i=>d[i.dataset.f]=i.value);
    await call('save_row',{table:dataTable.value,id:pk?(r[pk]??null):null,data:d});
    rowEditor.innerHTML='';await loadRows();msg('Datensatz gespeichert.');
  };
}
document.getElementById('loadRows').onclick=loadRows;
document.getElementById('newRow').onclick=()=>editRow({});
dataTable.onchange=async()=>{rowEditor.dataset.columns='';rowEditor.dataset.pk='';await loadRows()};
window.delRow=async id=>{if(confirm('Datensatz löschen?')){await call('delete_row',{table:dataTable.value,id});await loadRows();msg('Datensatz gelöscht.')}};
let mappingSourceColumns=[],mappingRowsState=[],mappingTargetCols={};const mappingCore=new Set(['id','start','end','title']);
function mappingOption(values,current){return values.map(v=>`<option value="${esc(v)}"${v===current?' selected':''}>${esc(v)}</option>`).join('')}
async function mappingColumns(table){if(!table)return[];if(mappingTargetCols[table])return mappingTargetCols[table];let j=await call('columns',{table});return mappingTargetCols[table]=j.columns.map(c=>c.COLUMN_NAME)}
async function syncLookupRow(i){let row=mappingRowsState[i],el=document.querySelector(`.mapping-line[data-i="${i}"]`);if(!row||!el)return;let box=el.querySelector('.lookup-grid');if(row.kind!=='lookup'){box.style.display='none';return;}box.style.display='grid';let tsel=box.querySelector('[data-k="target_table"]');tsel.innerHTML='<option value="">— Zieltabelle —</option>'+mappingOption(tables,row.target_table||'');if(row.target_table&&tables.includes(row.target_table))tsel.value=row.target_table;let cols=await mappingColumns(tsel.value);let key=box.querySelector('[data-k="target_key"]'),label=box.querySelector('[data-k="target_label"]');key.innerHTML='<option value="">— Schlüssel —</option>'+mappingOption(cols,row.target_key||'');label.innerHTML='<option value="">— Anzeige —</option>'+mappingOption(cols,row.target_label||'');if(cols.includes(row.target_key))key.value=row.target_key;if(cols.includes(row.target_label))label.value=row.target_label}
function renderMappingRows(){let host=document.getElementById('mappingRows');host.innerHTML=mappingRowsState.map((r,i)=>{let core=mappingCore.has(r.logical);return `<div class="mapping-line" data-i="${i}"><div class="mapping-line-grid"><label>Logischer Name<input data-k="logical" value="${esc(r.logical||'')}" ${core?'readonly':''} placeholder="z. B. location"></label><label>Bezeichnung<input data-k="label" value="${esc(r.label||'')}" placeholder="Anzeige"></label><label>Quellfeld<select data-k="field"><option value="">— Feld wählen —</option>${mappingOption(mappingSourceColumns,r.field||'')}</select></label><label>Typ<select data-k="kind"><option value="field"${(r.kind||'field')==='field'?' selected':''}>Feld</option><option value="lookup"${r.kind==='lookup'?' selected':''}>Lookup / Relation</option></select></label>${core?'<span class="muted">Pflichtfeld</span>':`<button type="button" class="danger remove-mapping">Entfernen</button>`}</div><div class="lookup-grid"><label>Zieltabelle<select data-k="target_table"></select></label><label>Zielschlüssel<select data-k="target_key"></select></label><label>Anzeigefeld<select data-k="target_label"></select></label></div></div>`}).join('');host.querySelectorAll('.mapping-line').forEach((el,i)=>{el.querySelectorAll('[data-k]').forEach(ctrl=>ctrl.addEventListener('change',async()=>{let k=ctrl.dataset.k;mappingRowsState[i][k]=ctrl.value;if(k==='kind'){if(ctrl.value!=='lookup'){delete mappingRowsState[i].target_table;delete mappingRowsState[i].target_key;delete mappingRowsState[i].target_label;}renderMappingRows();return;}if(k==='target_table'){mappingRowsState[i].target_key='';mappingRowsState[i].target_label='';await syncLookupRow(i);}}));el.querySelectorAll('input[data-k]').forEach(ctrl=>ctrl.addEventListener('input',()=>mappingRowsState[i][ctrl.dataset.k]=ctrl.value));el.querySelector('.remove-mapping')?.addEventListener('click',()=>{mappingRowsState.splice(i,1);renderMappingRows()});syncLookupRow(i)});}
async function loadSourceColumns(){mappingSourceColumns=await mappingColumns(mapTable.value);renderMappingRows()}
async function loadMapping(){let j=await call('get_mapping'),m=j.mapping||{};if(m.source_table&&tables.includes(m.source_table))mapTable.value=m.source_table;mappingSourceColumns=await mappingColumns(mapTable.value);mappingRowsState=Object.entries(m.fields||{}).map(([logical,d])=>({logical,label:d.label||logical,field:d.field||'',kind:d.kind||'field',target_table:d.target_table||'',target_key:d.target_key||'',target_label:d.target_label||''}));renderMappingRows()}
document.getElementById('addMapping').onclick=()=>{let n=1,key='custom_'+n;while(mappingRowsState.some(r=>r.logical===key)){n++;key='custom_'+n}mappingRowsState.push({logical:key,label:'',field:mappingSourceColumns[0]||'',kind:'field'});renderMappingRows()};
mapTable.onchange=async()=>{mappingTargetCols={};await loadSourceColumns()};saveMapping.onclick=async()=>{let fields=mappingRowsState.map(r=>({logical:(r.logical||'').trim(),label:(r.label||'').trim(),field:r.field||'',kind:r.kind||'field',target_table:r.target_table||'',target_key:r.target_key||'',target_label:r.target_label||''}));await call('save_mapping',{source_table:mapTable.value,fields});msg('Alle Calendar-Zuordnungen gespeichert.')};
refresh().then(loadMapping).then(()=>msg('Calendar-Grundstruktur geladen.')).catch(e=>msg(e.message));
</script></body></html>
