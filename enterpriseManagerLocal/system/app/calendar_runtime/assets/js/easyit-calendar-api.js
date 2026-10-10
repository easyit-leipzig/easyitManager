(function(global){'use strict';
class EasyITCalendarAPI{
 constructor(options={}){this.baseUrl=String(options.baseUrl||'api-v1.php').replace(/\/$/,'');this.token=String(options.token||'');this.fetch=options.fetch||global.fetch.bind(global);}
 setToken(token){this.token=String(token||'');return this;}
 async request(path,options={}){const headers=Object.assign({'Accept':'application/json'},options.headers||{});if(this.token)headers.Authorization='Bearer '+this.token;if(options.body!==undefined){headers['Content-Type']='application/json';options.body=JSON.stringify(options.body);}const r=await this.fetch(this.baseUrl+'/'+String(path).replace(/^\//,''),Object.assign({},options,{headers}));let data;try{data=await r.json();}catch(_){data={ok:false,error:{code:'INVALID_RESPONSE',message:'Server returned invalid JSON.'}};}if(!r.ok){const e=new Error(data?.error?.message||('HTTP '+r.status));e.status=r.status;e.code=data?.error?.code||'HTTP_ERROR';e.response=data;throw e;}return data;}
 meta(){return this.request('meta');}
 listEvents(start,end){const q=new URLSearchParams();if(start)q.set('start',start);if(end)q.set('end',end);return this.request('events?'+q.toString());}
 getEvent(id){return this.request('events/'+Number(id));}
 createEvent(event){return this.request('events',{method:'POST',body:event});}
 updateEvent(id,event){return this.request('events/'+Number(id),{method:'PUT',body:event});}
 deleteEvent(id){return this.request('events/'+Number(id),{method:'DELETE'});}
 listBlocks(){return this.request('blocks');}
 createBlock(block){return this.request('blocks',{method:'POST',body:block});}
 updateBlock(id,block){return this.request('blocks/'+Number(id),{method:'PUT',body:block});}
 deleteBlock(id){return this.request('blocks/'+Number(id),{method:'DELETE'});}
 listBlockCategories(){return this.request('block-categories');}
 createBlockCategory(data){return this.request('block-categories',{method:'POST',body:data});}
 updateBlockCategory(id,data){return this.request('block-categories/'+Number(id),{method:'PUT',body:data});}
 deleteBlockCategory(id){return this.request('block-categories/'+Number(id),{method:'DELETE'});}
 listPlaces(){return this.request('places');}
 createPlace(data){return this.request('places',{method:'POST',body:data});}
 updatePlace(id,data){return this.request('places/'+Number(id),{method:'PUT',body:data});}
 deletePlace(id){return this.request('places/'+Number(id),{method:'DELETE'});}
}
global.EasyITCalendarAPI=EasyITCalendarAPI;
})(window);
