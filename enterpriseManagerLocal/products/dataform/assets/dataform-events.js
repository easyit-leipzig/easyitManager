// easyIT DataForm5 – STAND 4 strict DataForm / RecordSet event separation
(()=>{
  const parseJson=id=>{try{return JSON.parse(document.getElementById(id)?.textContent||'{}')}catch(e){console.error('DataForm JSON',e);return {}}};
  const dataformHandlers=parseJson('df-event-handlers');
  const recordHandlers=parseJson('df-recordset-event-handlers');
  const serverContext=parseJson('df-action-context');
  const clone=value=>{try{return structuredClone(value)}catch(e){return JSON.parse(JSON.stringify(value||{}))}};

  const base=serverContext&&serverContext.schema?serverContext:{
    schema:'easyit.dataform.action-context',schema_version:'1.0',object_name:'dataformContext',
    action:{name:'open',phase:'after',trigger:'user',timestamp:new Date().toISOString()},
    project:window.DF_CONTEXT_META?.project||{},dataform:window.DF_CONTEXT_META?.dataform||{},
    context:{mode:'view',is_new_record:false,is_dirty:false,preview:false,current_record:{id:Number(document.body?.dataset.currentRecord||0),page:1},parent:null,relation:null},
    record:{id:Number(document.body?.dataset.currentRecord||0),values:{},original_values:{},changes:{},dirty_fields:[]},
    fields:{},validation:{valid:true,errors:[],warnings:[]},
    ui:{view_mode:document.body?.dataset.viewMode||'table',page:1,records_per_page:20,search:'',active_record_id:Number(document.body?.dataset.currentRecord||0),dialog_open:false},
    event:{cancel:false,message:null,data:{}},result:null
  };

  function valuesFromForm(form){
    const out={}; if(!form)return out;
    const fd=new FormData(form);
    for(const [key,value] of fd.entries()){
      if(['csrf','action','project','dataform','record','active_record','mode','embed','preview','parent_relation','parent_record','recordset_key'].includes(key))continue;
      const m=key.match(/^values\[([^\]]+)\](?:\[\])?$/); if(!m)continue;
      const name=m[1]; const val=value instanceof File?value.name:value;
      if(Object.prototype.hasOwnProperty.call(out,name)){if(!Array.isArray(out[name]))out[name]=[out[name]];out[name].push(val);}else out[name]=val;
    }
    return out;
  }

  function applyValuesToForm(form,values){
    if(!form||!values||typeof values!=='object')return;
    Object.entries(values).forEach(([name,value])=>{
      const escaped=(window.CSS&&CSS.escape)?CSS.escape(name):name.replace(/(["\\])/g,'\\$1');
      const nodes=[...document.querySelectorAll('[name="values['+escaped+']"],[name="values['+escaped+'][]"]')].filter(n=>n.form===form||n.getAttribute('form')===form.id);
      nodes.forEach(node=>{
        if(node.type==='checkbox'||node.type==='radio'){
          const vals=Array.isArray(value)?value.map(String):[String(value??'')];node.checked=vals.includes(String(node.value));
        }else if(node.multiple&&Array.isArray(value)){
          [...node.options].forEach(o=>o.selected=value.map(String).includes(String(o.value)));
        }else if(node.type!=='file') node.value=value==null?'':String(value);
      });
    });
  }

  function dataformContextFor(name,phase='before'){
    const ctx=clone(base);ctx.action=ctx.action||{};ctx.action.name=name.replace(/^before_|^after_/,'');ctx.action.phase=phase;ctx.action.timestamp=new Date().toISOString();ctx.object_name='dataformContext';ctx.event={...(ctx.event||{}),cancel:false};return ctx;
  }

  function recordSetFor(name,phase='before',form=null,extra={}){
    const df=clone(base);const values=form?valuesFromForm(form):clone(df.record?.values||{});const id=Number(form?.querySelector('[name="record"]')?.value||extra.recordId||df.record?.id||document.body?.dataset.currentRecord||0);
    return {
      schema:'easyit.dataform.recordset-context',schema_version:'1.0',object_name:'recordSet',recordset_key:'main',
      action:{name:name.replace(/^before_|^after_/,''),event:name,phase,trigger:'user',timestamp:new Date().toISOString()},
      project:clone(df.project||{}),dataform:clone(df.dataform||{}),
      current:{id,values,original_values:clone(df.record?.original_values||{}),changes:clone(df.record?.changes||{}),dirty_fields:clone(df.record?.dirty_fields||[])},
      fields:clone(df.fields||{}),state:{mode:id>0?'edit':'create',is_new_record:id<1,is_dirty:!!form},
      navigation:{current_id:id,previous_id:Number(document.body?.dataset.currentRecord||0),page:Number(df.ui?.page||1)},
      selection:{ids:[]},parent:clone(df.context?.parent||null),relation:clone(df.context?.relation||null),
      validation:clone(df.validation||{valid:true,errors:[],warnings:[]}),ui:clone(df.ui||{}),event:{cancel:false,message:null,data:{}},result:clone(df.result||null),...extra
    };
  }

  function runDataForm(name,ctx=null,event=null){
    const code=String(dataformHandlers[name]||'').trim();if(!code)return true;
    const dataformContext=ctx||dataformContextFor(name,name.startsWith('after_')?'after':'before');
    try{const result=(new Function('dataformContext','detail','event','"use strict";\n'+code))(dataformContext,dataformContext,event);return result!==false&&dataformContext?.event?.cancel!==true;}catch(err){console.error('DataForm event '+name,err,dataformContext);return true;}
  }

  function runRecord(name,recordSet=null,event=null){
    const code=String(recordHandlers[name]||'').trim();if(!code)return true;
    const rs=recordSet||recordSetFor(name,name.startsWith('after_')?'after':'before');
    try{const result=(new Function('recordSet','detail','event','"use strict";\n'+code))(rs,rs,event);return result!==false&&rs?.event?.cancel!==true;}catch(err){console.error('RecordSet event '+name,err,rs);return true;}
  }

  window.easyitDataFormEvent=(name,ctx=null,event=null)=>runDataForm(name,ctx,event);
  window.easyitRecordSetEvent=(name,rs=null,event=null)=>runRecord(name,rs,event);
  window.dataformContext=base;

  function runBeforeSave(form,event){
    const action=form?.querySelector('[name="action"]')?.value||'';if(action!=='save_record')return true;
    const id=Number(form.querySelector('[name="record"]')?.value||0);const operation=id>0?'update':'insert';
    let rs=recordSetFor(id>0?'before_update':'before_new','before',form);
    if(id<1&&!runRecord('before_new',rs,event))return false;
    rs=recordSetFor('before_validate','before',form);if(!runRecord('before_validate',rs,event))return false;applyValuesToForm(form,rs.current.values);
    rs=recordSetFor('before_save','before',form);if(!runRecord('before_save',rs,event))return false;applyValuesToForm(form,rs.current.values);
    rs=recordSetFor(operation==='insert'?'before_insert':'before_update','before',form);if(!runRecord(operation==='insert'?'before_insert':'before_update',rs,event))return false;applyValuesToForm(form,rs.current.values);
    return true;
  }

  function start(){
    runDataForm('open',dataformContextFor('open','after'));

    if(serverContext?.action?.name==='save'&&serverContext?.action?.phase==='after'){
      const operation=serverContext?.result?.operation==='create'?'insert':'update';
      const rs=recordSetFor('after_save','after',null,{current:{id:Number(serverContext?.record?.id||0),values:clone(serverContext?.record?.values||{}),original_values:clone(serverContext?.record?.original_values||{}),changes:clone(serverContext?.record?.changes||{}),dirty_fields:clone(serverContext?.record?.dirty_fields||[])},result:clone(serverContext.result||null)});
      runRecord('after_validate',clone(rs));runRecord(operation==='insert'?'after_insert':'after_update',clone(rs));runRecord('after_save',clone(rs));if(operation==='insert')runRecord('after_new',clone(rs));
    }

    document.addEventListener('easyit-dataform-current-record',e=>{const id=Number(e.detail?.recordId||0);const rs=recordSetFor('after_current_change','after',null,{current:{id,values:{},original_values:{},changes:{},dirty_fields:[]},navigation:{current_id:id,previous_id:Number(document.body?.dataset.currentRecord||0),page:Number(base.ui?.page||1)}});runRecord('after_current_change',rs,e);});

    document.addEventListener('submit',e=>{
      const action=e.target.querySelector('[name="action"]')?.value||'';
      if(action==='save_record'&&!runBeforeSave(e.target,e)){e.preventDefault();e.stopImmediatePropagation();return;}
      if((action==='delete_record'||action==='bulk_delete')){
        const ids=action==='bulk_delete'?[...e.target.querySelectorAll('[name="selected[]"]:checked')].map(x=>Number(x.value)).filter(Boolean):[Number(e.target.querySelector('[name="record"]')?.value||0)].filter(Boolean);
        const rs=recordSetFor('before_delete','before',e.target,{selection:{ids}});if(!runRecord('before_delete',rs,e)){e.preventDefault();e.stopImmediatePropagation();}
      }
    },true);

    const previous=new WeakMap();
    document.addEventListener('focusin',e=>{const n=e.target;if(n&&n.name&&/^values\[/.test(n.name))previous.set(n,n.type==='checkbox'||n.type==='radio'?n.checked:n.value);},true);
    document.addEventListener('change',e=>{const n=e.target;if(!n?.name||!/^values\[/.test(n.name))return;const m=n.name.match(/^values\[([^\]]+)\]/);if(!m)return;const form=n.form;if(!form)return;const rs=recordSetFor('before_field_change','before',form,{field:{name:m[1],old_value:previous.get(n),new_value:n.type==='checkbox'||n.type==='radio'?n.checked:n.value}});if(!runRecord('before_field_change',rs,e)){const old=previous.get(n);if(n.type==='checkbox'||n.type==='radio')n.checked=!!old;else n.value=old??'';e.stopImmediatePropagation();return;}runRecord('after_field_change',recordSetFor('after_field_change','after',form,{field:{name:m[1],old_value:previous.get(n),new_value:n.type==='checkbox'||n.type==='radio'?n.checked:n.value}}),e);},true);

    window.addEventListener('pagehide',e=>runDataForm('close',dataformContextFor('close','before'),e));
    window.addEventListener('beforeunload',e=>runDataForm('before_view_change',dataformContextFor('before_view_change','before'),e));
    window.addEventListener('pageshow',e=>runDataForm('view_change',dataformContextFor('view_change','after'),e));
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
