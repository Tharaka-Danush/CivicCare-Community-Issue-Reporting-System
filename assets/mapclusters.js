'use strict';
// Group nearby screen positions, preserving every original report coordinate.
(()=>{
 function group(rows,project,size=46){
  const buckets=new Map();
  rows.forEach(row=>{if(!row||String(row.latitude).trim()===''||String(row.longitude).trim()===''||row.latitude==null||row.longitude==null)return;const lat=Number(row.latitude),lng=Number(row.longitude);if(!Number.isFinite(lat)||!Number.isFinite(lng)||Math.abs(lat)>90||Math.abs(lng)>180)return;const point=project([lat,lng]),key=Math.floor(point.x/size)+':'+Math.floor(point.y/size);if(!buckets.has(key))buckets.set(key,[]);buckets.get(key).push(row);});
  return [...buckets.values()];
 }
 function attach(map,rows){
  const layer=L.layerGroup().addTo(map);
  function render(){
   layer.clearLayers();group(rows,point=>map.project(point,map.getZoom())).forEach(items=>{
    const many=items.length>1,first=items[0],center=many?[items.reduce((s,r)=>s+Number(r.latitude),0)/items.length,items.reduce((s,r)=>s+Number(r.longitude),0)/items.length]:[Number(first.latitude),Number(first.longitude)];
    const color=!many&&/^#[0-9a-f]{6}$/i.test(first.color||'')?first.color:'#173f37';
    const icon=L.divIcon({className:'workspace-map-pin',html:'<span style="background:'+color+'">'+(many?items.length:'')+'</span>',iconSize:many?[38,38]:[22,22],iconAnchor:many?[19,19]:[11,11]});
    const marker=L.marker(center,{icon,keyboard:true,title:many?items.length+' reports — select to view':'Report #'+Number(first.id)+': '+first.title,alt:many?items.length+' grouped reports':'Report #'+Number(first.id)}).addTo(layer);
    const box=document.createElement('div');box.className='workspace-map-popup';const heading=document.createElement('strong');heading.textContent=many?items.length+' reports in this area':'Report #'+Number(first.id);box.append(heading);
    if(many&&map.getZoom()<map.getMaxZoom()){const zoom=document.createElement('button');zoom.type='button';zoom.className='text-button';zoom.textContent='Zoom to these reports';zoom.addEventListener('click',()=>{map.closePopup();map.fitBounds(items.map(r=>[Number(r.latitude),Number(r.longitude)]),{padding:[40,40],maxZoom:Math.min(map.getMaxZoom(),map.getZoom()+3)});});box.append(zoom);}
    const list=document.createElement('ul');items.forEach(row=>{const item=document.createElement('li'),link=document.createElement('a'),status=document.createElement('small');link.href='index.php?page=issue&id='+Number(row.id);link.textContent='#'+Number(row.id)+' · '+row.title;status.textContent=row.status;item.append(link,status);list.append(item);});box.append(list);marker.bindPopup(box,{maxWidth:330,autoPan:false});
   });
  }
  map.on('zoomend moveend',render);map.on('remove',()=>layer.clearLayers());render();return {refresh:render};
 }
 globalThis.CivicMapClusters={group,attach};
})();
