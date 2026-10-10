// easyIT DataForm Project Runtime – PUBLISH13
(()=>{
  const body=document.body;
  const childPanel=document.querySelector('[data-child-collections]');
  async function loadChildren(id){
    if(!childPanel)return;
    if(!id){childPanel.innerHTML='<section class="card empty-children">Eltern-Datensatz auswählen, um die zugehörigen Kinddatensätze anzuzeigen.</section>';return;}
    const u=new URL(location.href);u.searchParams.set('child_fragment','1');u.searchParams.set('parent_record',String(id));u.searchParams.delete('record');
    try{childPanel.setAttribute('aria-busy','true');const r=await fetch(u,{headers:{'X-Requested-With':'XMLHttpRequest'}});if(!r.ok)throw new Error('HTTP '+r.status);childPanel.innerHTML=await r.text();}
    catch(e){childPanel.innerHTML='<section class="card notice error">Kinddatensätze konnten nicht geladen werden.</section>';}
    finally{childPanel.removeAttribute('aria-busy');}
  }
  function select(id){
    id=String(id||'');body.dataset.currentRecord=id;
    document.querySelectorAll('[data-record-row]').forEach(row=>{const active=row.dataset.recordRow===id;row.classList.toggle('active-row',active);const img=row.querySelector('[data-record-pointer] img');if(img)img.src='../assets/img/'+(active?'aktueller_ds':'normaler_ds')+'.png';});
    const u=new URL(location.href);if(id)u.searchParams.set('active_record',id);else u.searchParams.delete('active_record');u.searchParams.delete('child_fragment');history.replaceState({},'',u);
    loadChildren(id);
    document.dispatchEvent(new CustomEvent('easyit-dataform-current-record',{detail:{recordId:Number(id||0)}}));
  }
  document.addEventListener('click',e=>{const p=e.target.closest('[data-record-pointer],[data-select-record]');if(!p)return;e.preventDefault();select(p.dataset.recordPointer||p.dataset.selectRecord);});
  const all=document.querySelector('[data-select-all]');if(all)all.addEventListener('change',()=>document.querySelectorAll('input[name="selected[]"]').forEach(x=>x.checked=all.checked));
})();

// DataForm runtime settings/events + strict RecordSet event separation (STAND 4)
(()=>{
  const body=document.body;if(!body)return;
  const parse=id=>{try{return JSON.parse(document.getElementById(id)?.textContent||'{}')}catch(e){return {}}};
  const dfHandlers=parse('df-event-handlers');
  const rsHandlers=parse('df-recordset-event-handlers');
  const settings=window.DF_RUNTIME_SETTINGS||{};
  const clone=v=>{try{return structuredClone(v)}catch(e){return JSON.parse(JSON.stringify(v||{}))}};
  const base=settings.action_context&&settings.action_context.schema?settings.action_context:{schema:'easyit.dataform.action-context',schema_version:'1.0',object_name:'dataformContext',action:{name:'open',phase:'after',trigger:'user',timestamp:new Date().toISOString()},project:{},dataform:{id:Number(body.dataset.dataformId||0),view_mode:body.dataset.viewMode||'table'},context:{mode:'view',is_new_record:false,is_dirty:false,preview:false,current_record:{id:Number(body.dataset.currentRecord||0),page:1},parent:null,relation:null},record:{id:Number(body.dataset.currentRecord||0),values:{},original_values:{},changes:{},dirty_fields:[]},fields:{},validation:{valid:true,errors:[],warnings:[]},ui:{view_mode:body.dataset.viewMode||'table',page:1,records_per_page:20,search:'',active_record_id:Number(body.dataset.currentRecord||0),dialog_open:false},event:{cancel:false,message:null,data:{}},result:null};
  const formValues=form=>{const out={};if(!form)return out;for(const [key,val] of new FormData(form).entries()){if(['csrf','action','record','active_record','parent_relation','parent_record'].includes(key))continue;const name=key.replace(/^values\[([^\]]+)\](?:\[\])?$/,'$1').replace(/\[\]$/,'');if(key.startsWith('values[')){if(Object.hasOwn(out,name)){if(!Array.isArray(out[name]))out[name]=[out[name]];out[name].push(val instanceof File?val.name:val)}else out[name]=val instanceof File?val.name:val}}return out};
  const makeDf=(name,phase='before')=>{const ctx=clone(base);ctx.action={...(ctx.action||{}),name:name.replace(/^before_|^after_/,''),phase,trigger:'user',timestamp:new Date().toISOString()};ctx.object_name='dataformContext';ctx.event={...(ctx.event||{}),cancel:false};return ctx};
  const makeRs=(name,phase='before',form=null,extra={})=>{const id=Number(form?.querySelector('[name="record"]')?.value||body.dataset.currentRecord||0);return {schema:'easyit.dataform.recordset-context',schema_version:'1.0',object_name:'recordSet',recordset_key:'main',action:{name:name.replace(/^before_|^after_/,''),event:name,phase,trigger:'user',timestamp:new Date().toISOString()},project:clone(base.project||{}),dataform:clone(base.dataform||{}),current:{id,values:form?formValues(form):clone(base.record?.values||{}),original_values:clone(base.record?.original_values||{}),changes:clone(base.record?.changes||{}),dirty_fields:clone(base.record?.dirty_fields||[])},fields:clone(base.fields||{}),state:{mode:id>0?'edit':'create',is_new_record:id<1,is_dirty:!!form},navigation:{current_id:id,page:Number(base.ui?.page||1)},selection:{ids:[]},parent:clone(base.context?.parent||null),relation:clone(base.context?.relation||null),validation:clone(base.validation||{valid:true,errors:[],warnings:[]}),ui:clone(base.ui||{}),event:{cancel:false,message:null,data:{}},result:clone(base.result||null),...extra}};
  const runDf=(name,ctx=null,event=null)=>{const code=String(dfHandlers[name]||'').trim();if(!code)return true;ctx=ctx||makeDf(name,name.startsWith('after_')?'after':'before');try{const r=(new Function('dataformContext','detail','event','"use strict";\n'+code))(ctx,ctx,event);return r!==false&&ctx?.event?.cancel!==true}catch(err){console.error('DataForm event '+name,err);return true}};
  const runRs=(name,rs=null,event=null)=>{const code=String(rsHandlers[name]||'').trim();if(!code)return true;rs=rs||makeRs(name,name.startsWith('after_')?'after':'before');try{const r=(new Function('recordSet','detail','event','"use strict";\n'+code))(rs,rs,event);return r!==false&&rs?.event?.cancel!==true}catch(err){console.error('RecordSet event '+name,err);return true}};
  window.easyitDataFormEvent=runDf;window.easyitRecordSetEvent=runRs;window.dataformContext=base;
  runDf('open',makeDf('open','after'));
  if(settings.last_action==='after_save'&&settings.action_context){const op=settings.action_context?.result?.operation==='create'?'insert':'update';const rs=makeRs('after_save','after',null,{current:{id:Number(settings.action_context?.record?.id||0),values:clone(settings.action_context?.record?.values||{}),original_values:clone(settings.action_context?.record?.original_values||{}),changes:clone(settings.action_context?.record?.changes||{}),dirty_fields:clone(settings.action_context?.record?.dirty_fields||[])},result:clone(settings.action_context.result||null)});runRs(op==='insert'?'after_insert':'after_update',clone(rs));runRs('after_save',clone(rs));if(op==='insert')runRs('after_new',clone(rs));}
  if(settings.last_action==='after_delete')runRs('after_delete',makeRs('after_delete','after'));
  window.addEventListener('pagehide',e=>runDf('close',makeDf('close','before'),e));
  document.addEventListener('easyit-dataform-current-record',e=>{const id=Number(e.detail?.recordId||0);runRs('after_current_change',makeRs('after_current_change','after',null,{current:{id,values:{},original_values:{},changes:{},dirty_fields:[]}}),e)});
  document.addEventListener('submit',e=>{const a=e.target.querySelector('[name="action"]')?.value||'';if(a==='create'||a==='update'){const id=Number(e.target.querySelector('[name="record"]')?.value||0);if(id<1&&!runRs('before_new',makeRs('before_new','before',e.target),e)){e.preventDefault();return}if(!runRs('before_validate',makeRs('before_validate','before',e.target),e)||!runRs('before_save',makeRs('before_save','before',e.target),e)||!runRs(id<1?'before_insert':'before_update',makeRs(id<1?'before_insert':'before_update','before',e.target),e))e.preventDefault()}if((a==='delete'||a==='bulk_delete')&&!runRs('before_delete',makeRs('before_delete','before',e.target),e))e.preventDefault()},true);
  if(settings.allow_delete===false)document.querySelectorAll('button[form^="delete-"],.delete-form').forEach(x=>x.hidden=true);
  if(settings.allow_create===false)document.querySelectorAll('.new-action,#new-record').forEach(x=>x.hidden=true);

  if(settings.view_mode!=='dialog')return;
  const dialog=document.getElementById('runtime-dialog');const dialogBody=dialog?.querySelector('[data-dialog-body]');const dialogTitle=dialog?.querySelector('[data-dialog-title]');if(!dialog||!dialogBody)return;
  function openTemplate(id,isNew=false){const template=document.getElementById(isNew?'dialog-new-record':'dialog-record-'+id);if(!template)return;dialogBody.replaceChildren(template.content.cloneNode(true));if(dialogTitle)dialogTitle.textContent=isNew?'Neuer Datensatz':'Datensatz #'+id;if(typeof dialog.showModal==='function'){if(!dialog.open)dialog.showModal()}else dialog.setAttribute('open','open')}
  function close(){if(typeof dialog.close==='function'&&dialog.open)dialog.close();else dialog.removeAttribute('open');dialogBody.replaceChildren()}
  dialog.querySelector('[data-dialog-close]')?.addEventListener('click',close);dialog.addEventListener('click',e=>{if(e.target===dialog)close()});document.addEventListener('click',e=>{const r=e.target.closest('[data-dialog-record]');if(r){e.preventDefault();openTemplate(Number(r.dataset.dialogRecord||0),false);return}const n=e.target.closest('[data-dialog-create]');if(n){e.preventDefault();openTemplate(0,true)}});document.addEventListener('easyit-dataform-current-record',e=>{const id=Number(e.detail?.recordId||0);if(id>0)openTemplate(id,false)});const active=Number(body.dataset.currentRecord||0);if(active>0)openTemplate(active,false);
})();

// compatibility marker for PUBLISH8 regression: view_mode==='dialog'
