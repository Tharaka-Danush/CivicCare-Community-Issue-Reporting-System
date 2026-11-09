'use strict';
// Street View is a separate, opaque viewer in the SAME map region. It never writes
// report coordinates or changes report markers. Provider imagery is not persisted.
(()=>{
 let loading=null,settings=null;
 function options(){if(settings)return settings;try{settings=JSON.parse(document.getElementById('civic-street-config')?.textContent||'{}');}catch{settings={};}return settings;}
 function loadGoogle(key){
  if(globalThis.google?.maps?.StreetViewService&&globalThis.google?.maps?.StreetViewPanorama)return Promise.resolve(globalThis.google.maps);
  if(loading)return loading;
  loading=new Promise((resolve,reject)=>{
   const script=document.createElement('script');let settled=false;
   const finish=(error)=>{if(settled)return;settled=true;clearTimeout(timer);if(error)reject(new Error('Street View could not load. Check the Google Maps API key, billing and internet connection.'));else if(globalThis.google?.maps?.StreetViewService)resolve(globalThis.google.maps);else reject(new Error('Street View service is unavailable.'));};
   const timer=setTimeout(()=>finish(true),15000);globalThis.__civicStreetReady=()=>finish(false);script.onerror=()=>finish(true);script.async=true;script.referrerPolicy='strict-origin-when-cross-origin';script.src='https://maps.googleapis.com/maps/api/js?'+new URLSearchParams({key,loading:'async',callback:'__civicStreetReady',v:'quarterly'});document.head.append(script);
  });return loading;
 }
 function attach(map,base){
  const config=options(),key=String(config.apiKey||'');
  if(!key){
   const host=map.getContainer(),notice=document.createElement('div');notice.className='civic-street-notice';notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');host.append(notice);
   const update=()=>{notice.hidden=base.getMode()!=='auto'||map.getZoom()<(Number(config.zoom)||19);notice.textContent=config.setupState==='disabled'?'Street View is disabled. Ask the administrator to enable it. The map remains available.':'Street View is not configured. Ask the administrator to add a Google Maps API key and enable billing. Satellite zoom does not create street-level photos.';};
   map.on('zoomend civicbasemode',update);map.on('remove',()=>notice.remove());update();return null;
  }
  const threshold=Number(config.zoom)||19,radius=Number(config.radius)||50,delay=Number(config.delay)||800;
  const host=map.getContainer(),panel=document.createElement('section'),viewer=document.createElement('div'),tools=document.createElement('div'),back=document.createElement('button'),note=document.createElement('p'),notice=document.createElement('div');
  panel.className='civic-street-panel';panel.hidden=true;panel.setAttribute('aria-label','Street View road imagery');viewer.className='civic-street-viewer';tools.className='civic-street-tools';back.type='button';back.className='civic-street-back';back.textContent='← Back to map';note.textContent='Nearby road imagery · Your report pin stays unchanged.';tools.append(back,note);panel.append(viewer,tools);notice.className='civic-street-notice';notice.hidden=true;notice.setAttribute('role','status');notice.setAttribute('aria-live','polite');host.append(panel,notice);
  panel.addEventListener('keydown',event=>event.stopPropagation());
  if(globalThis.L?.DomEvent){L.DomEvent.disableClickPropagation(panel);L.DomEvent.disableScrollPropagation(panel);}
  let panorama=null,service=null,shown=false,generation=0,timer=null,disposed=false,lastSignature='',blocked=false,pending=false,windowStart=Date.now(),attempts=0;const previousInert=new Map();
  const message=text=>{notice.textContent=text;notice.hidden=!text;};
  const eligible=()=>!disposed&&!blocked&&base.getMode()==='auto'&&map.getZoom()>=threshold&&(!host.getClientRects||host.getClientRects().length>0);
  function restoreMap(text=''){
   generation++;pending=false;if(panorama)panorama.setVisible(false);shown=false;panel.hidden=true;host.classList.remove('civic-street-open');previousInert.forEach((value,element)=>{element.inert=value;});previousInert.clear();message(text);map.invalidateSize();
  }
  function show(maps,data){
   if(!panorama){panorama=new maps.StreetViewPanorama(viewer,{visible:false,addressControl:false,linksControl:true,panControl:true,zoomControl:true,fullscreenControl:false,enableCloseButton:false,motionTracking:false,motionTrackingControl:false,showRoadLabels:true,imageDateControl:true,scrollwheel:true});panorama.addListener('status_changed',()=>{if(shown&&panorama.getStatus()&&panorama.getStatus()!=='OK'){blocked=true;restoreMap('Street View imagery could not be displayed. The map remains available.');}});}
   Array.from(host.children).forEach(child=>{if(child!==panel&&child!==notice){previousInert.set(child,child.inert||false);child.inert=true;}});
   shown=true;panel.hidden=false;notice.hidden=true;host.classList.add('civic-street-open');panorama.setPano(data.location.pano);panorama.setPov({heading:0,pitch:0});panorama.setZoom(1);panorama.setVisible(true);maps.event.trigger(panorama,'resize');back.focus();
  }
  async function check(){
   if(!eligible()||shown||pending)return;const point=map.getCenter(),signature=Number(point.lat).toFixed(5)+','+Number(point.lng).toFixed(5);if(signature===lastSignature)return;
   if(Date.now()-windowStart>=60000){windowStart=Date.now();attempts=0;}if(attempts>=10){message('Street View checks paused. Use the map and try again in a minute.');return;}attempts++;lastSignature=signature;const mine=++generation;pending=true;message('Checking nearby Street View coverage…');
   try{
    const maps=await loadGoogle(key);if(mine!==generation||!eligible())return;service=service||new maps.StreetViewService();
    const data=await new Promise((resolve,reject)=>{let done=false;const timeout=setTimeout(()=>{if(!done){done=true;reject(new Error('Street View lookup timed out. Use the map or try another location.'));}},12000);service.getPanorama({location:{lat:Number(point.lat),lng:Number(point.lng)},radius,preference:maps.StreetViewPreference?.NEAREST||'nearest',source:maps.StreetViewSource?.OUTDOOR||'outdoor'},(data,status)=>{if(done)return;done=true;clearTimeout(timeout);if(status==='OK'&&data?.location?.pano)resolve(data);else if(status==='ZERO_RESULTS')resolve(null);else reject(new Error('Street View is unavailable. Check Google Maps settings or use the map.'));});});
    if(mine!==generation||!eligible())return;if(data)show(maps,data);else message('No nearby road-level Street View imagery here. Keeping the map.');
   }catch(error){if(mine===generation&&!disposed)message(error.message||'Street View unavailable. Keeping the map.');}
   finally{if(mine===generation)pending=false;}
  }
  function schedule(){
   clearTimeout(timer);if(disposed)return;
   if(map.getZoom()<threshold){lastSignature='';blocked=false;if(shown||pending)restoreMap();else message('');generation++;pending=false;return;}
   if(base.getMode()!=='auto'){if(shown||pending)restoreMap();else message('');generation++;pending=false;return;}
   // Invalidate a coverage lookup when the user pans/zooms before its result.
   if(pending){generation++;pending=false;}if(eligible()&&!shown)timer=setTimeout(check,delay);
  }
  back.addEventListener('click',()=>{blocked=true;restoreMap('Back on map. Zoom out and in again to reopen Street View.');map.setZoom(Math.min(map.getZoom(),threshold-1));host.focus();});
  map.on('zoomend moveend civicbasemode',schedule);map.on('resize',()=>{if(shown&&globalThis.google?.maps)google.maps.event.trigger(panorama,'resize');});
  map.on('remove',()=>{disposed=true;generation++;clearTimeout(timer);if(panorama){panorama.setVisible(false);globalThis.google?.maps?.event?.clearInstanceListeners(panorama);}panel.remove();notice.remove();});
  schedule();return {close:()=>{blocked=true;restoreMap();},isVisible:()=>shown};
 }
 globalThis.CivicStreetView={attach,isEnabled:()=>Boolean(options().apiKey),getAutoZoom:()=>Number(options().zoom)||19};
})();
