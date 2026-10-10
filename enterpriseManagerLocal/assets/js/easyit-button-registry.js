/* easyIT Enterprise HF76-FIX10 - project-wide canonical image button adapter.
 * Titles and images come exclusively from system/ui/ButtonRegistry.php via
 * #easyit-button-registry-data. Local title attributes are overwritten.
 */
(function(){
  'use strict';
  function loadRegistry(){
    const node=document.getElementById('easyit-button-registry-data');
    if(!node) return {};
    try{
      const parsed=JSON.parse(node.textContent||'{}');
      return parsed && typeof parsed==='object' ? parsed : {};
    }catch(_error){
      return {};
    }
  }
  const REGISTRY=loadRegistry();
  const SCRIPT_URL=(document.currentScript && document.currentScript.src) ? document.currentScript.src : '';
  const PROJECT_ROOT=SCRIPT_URL ? SCRIPT_URL.replace(/\/assets\/js\/easyit-button-registry\.js(?:\?.*)?$/,'/') : '/';
  function imageUrl(def){
    const rel=String((def&&def.image)||'').replace(/^\/+/, '');
    try{return new URL(rel, PROJECT_ROOT || window.location.origin + '/').href;}catch(_error){return rel;}
  }
  const CRUD={create:'neu',new:'neu',add:'neu',edit:'bearbeiten',update:'bearbeiten',delete:'loeschen',remove:'loeschen',drop:'loeschen',save:'speichern',show:'anzeigen',view:'anzeigen',detail:'anzeigen'};
  const RECORD_NAV_TYPES=new Set(['erster_ds','vorheriger_ds','aktueller_ds','naechster_ds','letzter_ds','neuer_ds','normaler_ds']);
  function isRecordNavigationControl(el){
    return el.classList.contains('df-record-pointer') ||
      el.classList.contains('df-pagination-record-jump') ||
      !!el.closest('.df-record-pointer-cell,.df-pagination-records,.records-table,.df-record-new-row');
  }
  const ACTION_RULES=[
    [/relation.*(?:delete|remove)|(?:delete|remove).*relation|beziehung.*(?:loesch|entfern)/i,'beziehung_loeschen'],
    [/relation.*(?:create|add|new)|(?:create|add|new).*relation|beziehung.*(?:neu|anleg)/i,'beziehung_neu'],
    [/restore/i,'restore'],[/backup/i,'backup'],[/archive/i,'archivieren'],[/unlock/i,'entsperren'],[/lock/i,'sperren'],
    [/logout|signout/i,'abmelden'],[/login|signin/i,'anmelden'],[/upload/i,'hochladen'],[/download/i,'herunterladen'],
    [/import/i,'importieren'],[/export/i,'exportieren'],[/refresh|reload/i,'aktualisieren'],[/filter.*(?:delete|reset|clear)/i,'filter_loeschen'],
    [/filter/i,'filter'],[/search|find/i,'suchen'],[/duplicate|clone/i,'duplizieren'],[/copy/i,'kopieren'],
    [/delete|remove|drop|revoke|withdraw/i,'loeschen'],[/create|add|new|register/i,'neu'],[/update|edit|manage/i,'bearbeiten'],[/save|apply|persist/i,'speichern'],
    [/close/i,'schliessen'],[/cancel|discard|back/i,'abbrechen'],[/check|test|validate|analyse|analyze/i,'suchen'],
    [/install|execute|run|approve|confirm|heartbeat|retry/i,'bestaetigen']
  ];
  const ALIASES=[];
  Object.entries(REGISTRY).forEach(([type,def])=>{
    (def.aliases||[]).forEach(alias=>ALIASES.push([String(alias).toLocaleLowerCase('de-DE'),type]));
  });
  ALIASES.sort((a,b)=>b[0].length-a[0].length);
  function clean(v){return String(v||'').replace(/\s+/g,' ').trim().toLocaleLowerCase('de-DE');}
  function fromText(text){
    const t=clean(text); if(!t)return null;
    for(const [alias,type] of ALIASES){if(t===alias || t.includes(alias))return type;}
    return null;
  }
  function fromAction(action){
    const a=String(action||''); if(!a)return null;
    for(const [re,type] of ACTION_RULES){if(re.test(a))return type;}
    return null;
  }
  function originalLabel(el){
    return String(el.getAttribute('aria-label')||el.getAttribute('title')||(el instanceof HTMLInputElement?el.value:el.textContent)||'').replace(/\s+/g,' ').trim();
  }
  function detect(el){
    const explicit=clean(el.getAttribute('data-button'));
    if(el.hasAttribute('data-button-fixed') && explicit && REGISTRY[explicit]){
      if(RECORD_NAV_TYPES.has(explicit) && !isRecordNavigationControl(el))return null;
      return explicit;
    }
    if(explicit && REGISTRY[explicit]){
      // DS images are reserved exclusively for real record-navigation controls.
      if(RECORD_NAV_TYPES.has(explicit) && !isRecordNavigationControl(el))return null;
      return explicit;
    }
    const crud=clean(el.getAttribute('data-crud')); if(crud && CRUD[crud])return CRUD[crud];
    const ownAction=fromAction((el.getAttribute('name')||'')+' '+(el.getAttribute('value')||'')+' '+(el.getAttribute('formaction')||''));
    if(ownAction)return ownAction;
    if(el.classList.contains('df-record-pointer')){
      if(el.classList.contains('new'))return 'neuer_ds';
      if(el.classList.contains('active'))return 'aktueller_ds';
      return 'normaler_ds';
    }
    // Anchors are navigation/actions in their own right.  They must never
    // inherit a surrounding form action (e.g. the bulk_delete form around
    // the records table), otherwise "Anzeigen" can be mis-decorated as delete.
    const href=el.getAttribute('href')||'';
    if(href){
      if(/(?:^|[?&])export=|download=/i.test(href))return /download=/i.test(href)?'herunterladen':'exportieren';
      if(/mode=create|#record-new-row|#new-/i.test(href))return 'neu';
      if(/mode=edit|(?:[?&])edit=/i.test(href))return 'bearbeiten';
      if(/mode=detail|(?:[?&])view=/i.test(href))return 'anzeigen';
      if(/relations?/i.test(href))return 'beziehung';
      if(/import/i.test(href))return 'importieren';
      if(/reports?.*(?:print|pdf)/i.test(href)||/[?&]print=1/i.test(href))return 'drucken';
      if(/reports?|export=(?:csv|word|html|json)/i.test(href))return 'exportieren';
      if(/settings?|config/i.test(href))return 'einstellungen';
      if(/documentation|tutorial|help/i.test(href))return 'hilfe';
      if(/dashboard|workspace/i.test(href))return 'start';
      if(/logout/i.test(href))return 'abmelden';
      if(/login/i.test(href))return 'anmelden';
    }
    if(!(el instanceof HTMLAnchorElement)){
      const form=el.closest('form');
      if(form){
        const action=form.querySelector('input[name="action"]');
        const byAction=fromAction(action && action.value); if(byAction)return byAction;
      }
    }
    return fromText(originalLabel(el));
  }
  function decorate(el){
    if(!(el instanceof HTMLElement))return;
    if(el.matches('.df-menu button,[data-button-skip]'))return;
    const candidate=el.matches('button,input[type="submit"],input[type="button"],input[type="reset"],a.button,[role="button"]');
    if(!candidate)return;
    const label=originalLabel(el);
    const type=detect(el);
    if(!type || !REGISTRY[type]){el.setAttribute('data-button-unresolved','1');return;}
    el.removeAttribute('data-button-unresolved');
    let context=clean(el.getAttribute('data-button-context'));
    if(el.classList.contains('password-toggle') && !context)context='password_toggle';
    const contextTitles=(REGISTRY[type].titles&&typeof REGISTRY[type].titles==='object')?REGISTRY[type].titles:{};
    const centralTitle=String((context&&contextTitles[context])||REGISTRY[type].title||'').trim();
    if(!centralTitle)return;
    el.setAttribute('data-button',type);
    el.classList.add('easyit-image-button');
    // Historical visual state classes must not paint a second background behind the PNG.
    el.classList.remove('primary','secondary','danger','success','warning','error');
    if(label && label!==centralTitle && !el.hasAttribute('data-button-original-label')){
      el.setAttribute('data-button-original-label',label);
    }
    // HF76-FIX5: title and accessible name are canonical registry metadata.
    el.setAttribute('title',centralTitle);
    el.setAttribute('aria-label',centralTitle);

    const src=imageUrl(REGISTRY[type]);
    el.style.setProperty('--easyit-button-image', 'url("'+src.replace(/"/g,'\\"')+'")');
    if(el instanceof HTMLInputElement){
      // Inputs cannot contain child nodes. They use the same canonical PNG as
      // a forced background image; every other action control gets a real IMG.
      el.classList.add('easyit-image-button-input');
      return;
    }
    let img=el.querySelector(':scope > img.easyit-button-image');
    if(!img){
      img=document.createElement('img');
      img.className='easyit-button-image';
      img.alt='';
      img.setAttribute('aria-hidden','true');
      img.setAttribute('draggable','false');
      el.insertBefore(img,el.firstChild);
    }
    if(img.getAttribute('src')!==src) img.setAttribute('src',src);
  }
  function scan(root){
    if(root instanceof Element)decorate(root);
    (root.querySelectorAll?root.querySelectorAll('button,input[type="submit"],input[type="button"],input[type="reset"],a.button,[role="button"]'):[]).forEach(decorate);
  }
  function init(){
    scan(document);
    new MutationObserver(ms=>ms.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType===1)scan(n);}))).observe(document.documentElement,{subtree:true,childList:true});
  }
  window.EasyITButtonRegistry=Object.freeze(REGISTRY);
  window.EasyITButtons=Object.freeze({decorate,scan,detect});
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
