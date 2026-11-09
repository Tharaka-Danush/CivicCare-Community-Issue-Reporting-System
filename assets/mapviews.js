'use strict';
// Shared by the picker, community map and saved report map.
// Switching a base layer never creates, moves or removes report markers.
(()=>{
 const OSM='https://tile.openstreetmap.org/{z}/{x}/{y}.png';
 const IMAGERY='https://services.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
 const AUTO_ZOOM=16;
 function attach(map){
  const road=L.tileLayer(OSM,{maxZoom:20,maxNativeZoom:19,referrerPolicy:'strict-origin-when-cross-origin',attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'});
  const satellite=L.tileLayer(IMAGERY,{maxZoom:20,maxNativeZoom:16,referrerPolicy:'strict-origin-when-cross-origin',attribution:'Imagery &copy; <a href="https://www.esri.com/">Esri</a>, Vantor, Earthstar Geographics, and the GIS User Community'});
  let mode='auto',active=null,errors=0,unavailable=false,status=null;
  const buttons={};
  function render(){
   const desired=mode==='satellite'||(mode==='auto'&&map.getZoom()>=AUTO_ZOOM)?satellite:road;
   const next=unavailable&&desired===satellite?road:desired;
   if(next!==active){if(active)map.removeLayer(active);next.addTo(map);active=next;errors=0;}
   Object.entries(buttons).forEach(([value,button])=>button.setAttribute('aria-pressed',String(value===mode)));
   if(status){
    status.textContent=unavailable&&desired===satellite?'Satellite unavailable · showing Map. Try Satellite again.':(next===satellite?(map.getZoom()>16?'Satellite (magnified)':'Satellite'):'Map')+(mode==='auto'?(globalThis.CivicStreetView?.isEnabled?.()?' · Auto (satellite 16+, Street View '+globalThis.CivicStreetView.getAutoZoom()+'+)':' · Auto · Street View not configured'):' · Manual');
    status.classList.toggle('is-error',unavailable&&desired===satellite);
   }
  }
  function setMode(value){if(!['auto','map','satellite'].includes(value))return;mode=value;unavailable=false;errors=0;render();map.fire('civicbasemode',{mode});}
  const controls=L.control({position:'topright'});
  controls.onAdd=()=>{
   const container=L.DomUtil.create('div','civic-map-control');container.setAttribute('role','group');container.setAttribute('aria-label','Map display mode');
   const row=L.DomUtil.create('div','civic-map-buttons',container);
   [['auto','Auto'],['map','Map'],['satellite','Satellite']].forEach(([value,label])=>{
    const button=L.DomUtil.create('button','civic-map-button',row);button.type='button';button.textContent=label;
    button.title=value==='auto'?'Automatically show satellite imagery at zoom 16 or closer':'Keep '+label.toLowerCase()+' view while zooming';
    button.setAttribute('aria-pressed',String(value===mode));button.addEventListener('click',()=>setMode(value));buttons[value]=button;
   });
   status=L.DomUtil.create('div','civic-map-status',container);status.setAttribute('role','status');status.setAttribute('aria-live','polite');
   L.DomEvent.disableClickPropagation(container);L.DomEvent.disableScrollPropagation(container);render();return container;
  };
  map.on('zoomend',render);
  satellite.on('tileerror',()=>{if(active!==satellite)return;errors++;if(errors>=3){unavailable=true;render();}});
  satellite.on('tileload',()=>{errors=0;});
  controls.addTo(map);render();
  const view={setMode,getMode:()=>mode,getLayer:()=>active===satellite?'satellite':'map'};globalThis.CivicStreetView?.attach(map,view);return view;
 }
 globalThis.CivicMapViews={attach};
})();
