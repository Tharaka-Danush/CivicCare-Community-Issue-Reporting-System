<?php
function followup_text($key){$v=$_POST[$key]??'';if(!is_scalar($v))throw new RuntimeException('Enter a valid '.str_replace('_',' ',$key).'.');return (string)$v;}
function followup_id($key){$raw=followup_text($key);if(strlen($raw)>10||!preg_match('/^[0-9]+$/D',$raw)||(int)$raw<1||(int)$raw>4294967295)throw new RuntimeException('Choose a valid '.str_replace('_',' ',$key).'.');return (int)$raw;}
function followup_scope($kind){
 $map=['province'=>['geo_provinces','admin_id','admin'],'district'=>['geo_districts','officer_id','officer'],'town'=>['geo_towns',null,'maintenance']];
 if(!isset($map[$kind]))throw new RuntimeException('Choose Province, District or Town.');
 return $map[$kind];
}
function followup_list($value){return is_array($value)&&$value&&array_keys($value)===range(0,count($value)-1);}
function followup_geometry($raw){
 try{$g=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('The GeoJSON file is not valid JSON.');}
 if(!is_array($g))throw new RuntimeException('Choose a GeoJSON geometry object.');
 if(($g['type']??'')==='Feature')$g=$g['geometry']??[];
 if(!is_array($g)||!in_array($g['type']??'',['Polygon','MultiPolygon'],true)||!followup_list($g['coordinates']??null))throw new RuntimeException('Upload one Polygon or MultiPolygon geometry, or a single GeoJSON Feature.');
 $polys=$g['type']==='Polygon'?[$g['coordinates']]:$g['coordinates'];$count=0;
 if(!followup_list($polys)||count($polys)>1000)throw new RuntimeException('Invalid boundary polygon count.');
 foreach($polys as $poly){
  if(!followup_list($poly))throw new RuntimeException('A polygon needs an outer ring.');
  foreach($poly as $ring){
   if(!followup_list($ring)||count($ring)<4)throw new RuntimeException('Boundary rings need at least four coordinates.');
   foreach($ring as $pt){if(!is_array($pt)||array_keys($pt)!==[0,1]||(!is_int($pt[0])&&!is_float($pt[0]))||(!is_int($pt[1])&&!is_float($pt[1]))||!is_finite((float)$pt[0])||!is_finite((float)$pt[1])||abs((float)$pt[0])>180||abs((float)$pt[1])>90||++$count>40000)throw new RuntimeException('Use at most 40,000 WGS84 longitude/latitude pairs.');}
   if($ring[0]!=$ring[count($ring)-1])throw new RuntimeException('Boundary rings must be closed.');
   $area=0;for($v=0;$v<count($ring)-1;$v++)$area+=$ring[$v][0]*$ring[$v+1][1]-$ring[$v+1][0]*$ring[$v][1];
   if(abs($area)<1e-12)throw new RuntimeException('Boundary rings must enclose a non-zero area.');
  }
 }
 return ['type'=>$g['type'],'coordinates'=>$g['coordinates']];
}
function followup_in_ring($x,$y,$ring){
 $inside=false;$n=count($ring);
 for($i=0,$j=$n-1;$i<$n;$j=$i++){
  $a=$ring[$j];$b=$ring[$i];$cross=($x-$a[0])*($b[1]-$a[1])-($y-$a[1])*($b[0]-$a[0]);
  if(abs($cross)<1e-9&&$x>=min($a[0],$b[0])-1e-9&&$x<=max($a[0],$b[0])+1e-9&&$y>=min($a[1],$b[1])-1e-9&&$y<=max($a[1],$b[1])+1e-9)return true;
  if(($a[1]>$y)!==($b[1]>$y)&&$x<($b[0]-$a[0])*($y-$a[1])/($b[1]-$a[1])+$a[0])$inside=!$inside;
 }
 return $inside;
}
function followup_contains($geometry,$lat,$lng){
 $polys=$geometry['type']==='Polygon'?[$geometry['coordinates']]:$geometry['coordinates'];
 foreach($polys as $poly){if(!followup_in_ring($lng,$lat,$poly[0]))continue;$hole=false;foreach(array_slice($poly,1) as $ring)if(followup_in_ring($lng,$lat,$ring))$hole=true;if(!$hole)return true;}return false;
}
function followup_location_result($i){
 $configured=0;$outside=[];
 foreach(['province'=>'province_id','district'=>'district_id','town'=>'town_id'] as $kind=>$key){
  $g=geo_raw('SELECT geometry FROM geo_service_boundaries WHERE scope_kind=? AND scope_id=?',[$kind,(int)($i[$key]??0)])->fetchColumn();if(!$g)continue;$configured++;
  if(!followup_contains(json_decode($g,true,64,JSON_THROW_ON_ERROR),(float)$i['latitude'],(float)$i['longitude']))$outside[]=$kind;
 }
 return ['configured'=>$configured,'outside'=>$outside];
}
function followup_check_location($i){
 $r=followup_location_result($i);if($r['outside'])throw new RuntimeException('The map pin is outside the configured '.implode(', ',$r['outside']).' boundary. Correct the pin or selected area.');return $r;
}
function followup_apply_target($id,$category){
 $days=geo_raw('SELECT target_days FROM category_service_targets WHERE category_id=?',[$category])->fetchColumn();
 if(!$days)return;
 q('UPDATE issues SET due_date=DATE_ADD(DATE(created_at),INTERVAL '.(int)$days.' DAY) WHERE id=? AND due_date IS NULL',[$id]);
 event_add($id,'service_target','Configured category target: '.(int)$days.' calendar days from submission. An administrator can set a different due date.');
}
function followup_notify_once($uid,$iid,$key,$message){
 if(geo_raw('SELECT id FROM notifications WHERE user_id=? AND issue_id=? AND message LIKE ?',[$uid,$iid,$key.'%'])->fetchColumn())return 0;
 geo_raw('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$uid,$iid,$message]);return 1;
}
function followup_blocked(){
 geo_system_required();ops_write_lock();$sent=0;$day=date('Y-m-d');db()->beginTransaction();
 try{
  $rows=geo_raw("SELECT i.*,f.next_review FROM issues i JOIN issue_flow f ON f.issue_id=i.id WHERE i.status='In Progress' AND i.maintenance_stage='Blocked' AND f.next_review<=CURDATE() FOR UPDATE")->fetchAll();
  foreach($rows as $i){
   $ids=[];$admin=geo_raw("SELECT u.id FROM geo_provinces p JOIN users u ON u.id=p.admin_id WHERE p.id=? AND u.role='admin' AND u.is_system_admin=0 AND u.active=1",[$i['province_id']])->fetchColumn();if($admin)$ids[]=(int)$admin;
   $officer=geo_raw("SELECT u.id FROM geo_districts d JOIN users u ON u.id=d.officer_id WHERE d.id=? AND d.province_id=? AND u.id=? AND u.role='officer' AND u.active=1",[$i['district_id'],$i['province_id'],$i['officer_id']])->fetchColumn();if($officer)$ids[]=(int)$officer;
   $team=geo_raw("SELECT id FROM users WHERE id=? AND town_id=? AND role='maintenance' AND active=1",[$i['maintenance_id'],$i['town_id']])->fetchColumn();if($team)$ids[]=(int)$team;
   if(!$admin||!$officer)foreach(geo_raw("SELECT id FROM users WHERE role='admin' AND is_system_admin=1 AND active=1")->fetchAll() as $u)$ids[]=(int)$u['id'];
   $key='Blocked review '.$day.': report #'.$i['id'].'. ';
   foreach(array_unique($ids) as $uid)$sent+=followup_notify_once($uid,$i['id'],$key,$key.'Review date '.$i['next_review'].' has arrived. Review the blocker and update the next review date or resume work.');
  }
  if($sent)audit('system.blocked_reviews','Created '.$sent.' blocked-work review notifications.');db()->commit();return $sent;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function followup_cover_transfer($kind,$target,$from,$to,$reason){
 $scope=followup_scope($kind);$rows=$kind==='district'?geo_raw("SELECT * FROM issues WHERE district_id=? AND officer_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') FOR UPDATE",[$target,$from])->fetchAll():[];
 foreach($rows as $i){
  q('UPDATE issues SET officer_id=?,maintenance_version=maintenance_version+1 WHERE id=?',[$to,$i['id']]);
  geo_raw('UPDATE issue_flow SET accepted_by=NULL,accepted_at=NULL,version=version+1 WHERE issue_id=?',[$i['id']]);
  event_add($i['id'],'routing','Temporary District Officer responsibility: #'.$from.' → #'.$to.'. '.$reason);
  $i['officer_id']=$to;notify_issue($i,'Temporary responsibility updated for report #'.$i['id']);
 }
 geo_raw('UPDATE '.$scope[0].' SET '.$scope[1].'=? WHERE id=?',[$to,$target]);
 geo_raw('UPDATE users SET auth_version=auth_version+1 WHERE id IN (?,?)',[$from,$to]);
}
function followup_cover_return($id,$manual=false){
 geo_system_required();ops_write_lock();db()->beginTransaction();
 try{
  $c=geo_raw("SELECT * FROM temporary_cover WHERE id=? AND status IN ('active','attention') FOR UPDATE",[$id])->fetch();if(!$c){db()->commit();return 'unchanged';}
  if(!$manual&&strtotime($c['ends_at'])>time()){db()->commit();return 'unchanged';}
  $scope=followup_scope($c['scope_kind']);$current=geo_raw('SELECT '.$scope[1].' FROM '.$scope[0].' WHERE id=? FOR UPDATE',[$c['scope_id']])->fetchColumn();
  $original=geo_raw('SELECT id,role,active,is_system_admin FROM users WHERE id=? FOR UPDATE',[$c['original_id']])->fetch();$problem='';
  if((int)$current!==(int)$c['cover_id'])$problem='Responsibility was changed after this cover started. Review the current owner.';
  elseif(!$original||!$original['active']||$original['role']!==$scope[2]||($c['scope_kind']==='province'&&$original['is_system_admin']))$problem='Original account is disabled or no longer eligible.';
  elseif(geo_raw('SELECT id FROM '.$scope[0].' WHERE '.$scope[1].'=? AND id<>?',[$c['original_id'],$c['scope_id']])->fetchColumn())$problem='Original account now manages a different area.';
  if(!$problem&&$c['scope_kind']==='district'&&geo_raw("SELECT id FROM issues WHERE officer_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') AND (district_id IS NULL OR district_id<>?) LIMIT 1",[$c['original_id'],$c['scope_id']])->fetchColumn())$problem='Original officer now has open work outside this district.';
  if($problem){
   if($c['status']!=='attention'||$c['attention_reason']!==$problem)audit('routing.cover_attention','Cover #'.$id.': '.$problem);
   geo_raw("UPDATE temporary_cover SET status='attention',attention_reason=?,version=version+1 WHERE id=?",[$problem,$id]);db()->commit();return 'attention';
  }
  followup_cover_transfer($c['scope_kind'],$c['scope_id'],$c['cover_id'],$c['original_id'],'Temporary cover ended. Original owner restored.');
  geo_raw("UPDATE temporary_cover SET status='returned',returned_at=NOW(),attention_reason=NULL,version=version+1 WHERE id=?",[$id]);
  oversight_audit_change('routing.cover_returned',ucfirst($c['scope_kind']).' #'.$c['scope_id'],['account'=>$c['cover_id']],['account'=>$c['original_id']],'Cover #'.$id.' ended.');db()->commit();return 'returned';
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function followup_jobs($source='manual'){
 geo_system_required();ops_write_lock();$returned=0;$attention=0;
 foreach(geo_raw("SELECT id FROM temporary_cover WHERE status IN ('active','attention') AND ends_at<=NOW() ORDER BY id")->fetchAll() as $r){$state=followup_cover_return($r['id']);if($state==='returned')$returned++;if($state==='attention')$attention++;}
 $blocked=followup_blocked();$overdue=oversight_run_reminders();$result=['sent'=>$blocked+$overdue['sent'],'blocked'=>$blocked,'handbacks'=>$returned,'cover_attention'=>$attention,'escalations'=>$overdue['escalations']];
 audit($source==='scheduler'?'system.scheduler_run':'system.followup_run',json_encode($result));return $result;
}
function followup_action($action){
 if(!in_array($action,['followup_cover_account','followup_target','followup_boundary','followup_boundary_delete','followup_cover_start','followup_cover_return','followup_cover_cancel','followup_run'],true))return;
 geo_system_required();ops_write_lock();
 if($action==='followup_run'){$r=followup_jobs();flash($r['sent'].' notifications created; '.$r['handbacks'].' handbacks completed; '.$r['cover_attention'].' covers need attention.');go('index.php?page=readiness&tab=jobs');}
 if($action==='followup_cover_account'){
  ops_admin_password();$kind=followup_text('scope_kind');if(!in_array($kind,['province','district'],true))throw new RuntimeException('Choose Province or District cover.');
  $name=field('name',2,100);$email=valid_email();$password=password_input();if(geo_raw('SELECT id FROM users WHERE email=?',[$email])->fetchColumn())throw new RuntimeException('Email is already registered.');
  db()->beginTransaction();geo_raw('INSERT INTO users(name,email,password,role,is_system_admin) VALUES(?,?,?,?,0)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$kind==='province'?'admin':'officer']);audit('routing.cover_account_created',ucfirst($kind).' cover account created: '.$email);db()->commit();flash('Cover account created without an area assignment. Give credentials privately and ask the account holder to change the temporary password.');go('index.php?page=readiness&tab=cover&kind='.$kind);
 }
 if($action==='followup_target'){
  $id=followup_id('category_id');if(!geo_raw('SELECT id FROM categories WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('Choose a category.');
  $raw=followup_text('target_days');if($raw!==''&&(!ctype_digit($raw)||(int)$raw<1||(int)$raw>3650))throw new RuntimeException('Use 1–3650 calendar days, or leave blank to disable.');
  db()->beginTransaction();if($raw==='')geo_raw('DELETE FROM category_service_targets WHERE category_id=?',[$id]);else geo_raw('INSERT INTO category_service_targets(category_id,target_days,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE target_days=VALUES(target_days),updated_by=VALUES(updated_by)',[$id,(int)$raw,user()['id']]);
  audit('category.target_changed','Category #'.$id.' target '.($raw===''?'disabled':$raw.' calendar days').'; applies only to new reports.');db()->commit();flash('Target saved for future reports. Existing due dates are unchanged.');go('index.php?page=readiness&tab=targets');
 }
 if($action==='followup_boundary'||$action==='followup_boundary_delete'){
  $kind=followup_text('scope_kind');$scope=followup_scope($kind);$id=followup_id('scope_id');if(!geo_raw('SELECT id FROM '.$scope[0].' WHERE id=?',[$id])->fetchColumn())throw new RuntimeException('Choose a valid boundary area.');
  if($action==='followup_boundary_delete'){ops_admin_password();$geometry=null;$source='Boundary removed.';}
  else{$f=$_FILES['boundary_file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||$f['size']>2*1024*1024)throw new RuntimeException('Select one GeoJSON file up to 2 MB.');$source=field('source_note',5,500);$geometry=followup_geometry(file_get_contents($f['tmp_name']));}
  db()->beginTransaction();if($geometry)geo_raw('INSERT INTO geo_service_boundaries(scope_kind,scope_id,geometry,source_note,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE geometry=VALUES(geometry),source_note=VALUES(source_note),updated_by=VALUES(updated_by)',[$kind,$id,json_encode($geometry,JSON_THROW_ON_ERROR),$source,user()['id']]);else geo_raw('DELETE FROM geo_service_boundaries WHERE scope_kind=? AND scope_id=?',[$kind,$id]);
  geo_raw("UPDATE issue_flow f JOIN issues i ON i.id=f.issue_id SET f.location_state='pending',f.location_hash=NULL,f.version=f.version+1 WHERE i.".$kind.'_id=?',[$id]);
  audit('routing.boundary_changed',ucfirst($kind).' #'.$id.'. '.$source);db()->commit();flash('Boundary settings saved. Affected location checks require fresh review. Current work was not reassigned.');go('index.php?page=readiness&tab=boundaries');
 }
 if($action==='followup_cover_start'){
  ops_admin_password();$kind=followup_text('scope_kind');if(!in_array($kind,['province','district'],true))throw new RuntimeException('Choose Province or District.');$scope=followup_scope($kind);$target=followup_id('scope_id');$cover=followup_id('cover_id');$reason=field('note',5,2000);
  $end=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',followup_text('ends_at'));if(!$end||$end->format('Y-m-d\TH:i')!==($_POST['ends_at']??'')||$end->getTimestamp()<=time())throw new RuntimeException('Choose a valid future end time.');
  if(($_POST['confirm_transfer']??'')!=='1')throw new RuntimeException('Confirm transfer of current open work.');
  db()->beginTransaction();$row=geo_raw('SELECT * FROM '.$scope[0].' WHERE id=? FOR UPDATE',[$target])->fetch();$original=(int)($row[$scope[1]]??0);
  $owner=geo_raw('SELECT role,is_system_admin FROM users WHERE id=?',[$original])->fetch();if(!$owner||$owner['role']!==$scope[2]||($kind==='province'&&$owner['is_system_admin']))throw new RuntimeException('The current owner is not an eligible scoped account.');
  if(!$original||$original===$cover)throw new RuntimeException('The area needs an existing owner and a different cover account.');
  if(geo_raw("SELECT id FROM temporary_cover WHERE scope_kind=? AND scope_id=? AND status IN ('active','attention')",[$kind,$target])->fetchColumn())throw new RuntimeException('An active cover or unresolved cover exception already exists.');
  $u=geo_raw('SELECT id,active,role,is_system_admin FROM users WHERE id=? FOR UPDATE',[$cover])->fetch();if(!$u||!$u['active']||$u['role']!==$scope[2]||($kind==='province'&&$u['is_system_admin']))throw new RuntimeException('Choose an active eligible cover account. System Admin accounts cannot be used as Province cover.');
  if(geo_raw('SELECT id FROM '.$scope[0].' WHERE '.$scope[1].'=?',[$cover])->fetchColumn())throw new RuntimeException('Cover account already manages another area. Use a separate eligible account.');
  if($kind==='district'&&geo_raw("SELECT id FROM issues WHERE officer_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') AND (district_id IS NULL OR district_id<>?) LIMIT 1",[$cover,$target])->fetchColumn())throw new RuntimeException('Cover officer has open work outside this district.');
  followup_cover_transfer($kind,$target,$original,$cover,$reason);geo_raw('INSERT INTO temporary_cover(scope_kind,scope_id,original_id,cover_id,ends_at,reason,started_by) VALUES(?,?,?,?,?,?,?)',[$kind,$target,$original,$cover,$end->format('Y-m-d H:i:s'),$reason,user()['id']]);$cid=db()->lastInsertId();
  oversight_audit_change('routing.cover_started',ucfirst($kind).' #'.$target,['account'=>$original],['account'=>$cover,'ends_at'=>$end->format('Y-m-d H:i:s')],$reason.' Cover #'.$cid);db()->commit();flash('Temporary cover started. Both accounts must sign in again.');go('index.php?page=readiness&tab=cover');
 }
 $id=followup_id('cover_record');ops_admin_password();
 if($action==='followup_cover_return'){$state=followup_cover_return($id,true);flash($state==='returned'?'Responsibility returned to the original owner.':'Handback needs review. Current responsibility was preserved.',$state==='returned'?'success':'warning');}
 else{
  $c=geo_raw("SELECT * FROM temporary_cover WHERE id=? AND status='attention'",[$id])->fetch();if(!$c)throw new RuntimeException('Choose a cover exception.');$scope=followup_scope($c['scope_kind']);$current=geo_raw('SELECT '.$scope[1].' FROM '.$scope[0].' WHERE id=?',[$c['scope_id']])->fetchColumn();if((int)$current===(int)$c['cover_id'])throw new RuntimeException('Configure a valid permanent owner first; current cover responsibility cannot be abandoned.');
  geo_raw("UPDATE temporary_cover SET status='cancelled',version=version+1 WHERE id=?",[$id]);audit('routing.cover_cancelled','Cover #'.$id.' closed after manual responsibility correction.');flash('Cover exception closed. Current responsibility was preserved.');
 }
 go('index.php?page=readiness&tab=cover');
}

function followup_validate_backup($data){
 $users=array_column($data['users'],null,'id');$cats=array_column($data['categories'],null,'id');$areas=['province'=>array_column($data['geo_provinces'],null,'id'),'district'=>array_column($data['geo_districts'],null,'id'),'town'=>array_column($data['geo_towns'],null,'id')];$seen=[];
 foreach($data['category_service_targets'] as $r){if(!isset($cats[$r['category_id']],$users[$r['updated_by']])||isset($seen[$r['category_id']])||!ctype_digit((string)$r['target_days'])||(int)$r['target_days']<1||(int)$r['target_days']>3650)throw new RuntimeException('Invalid category target in backup.');$seen[$r['category_id']]=true;}
 $seen=[];$active=[];
 foreach($data['temporary_cover'] as $c){
  $kind=$c['scope_kind'];$role=$kind==='province'?'admin':'officer';$end=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$c['ends_at']);
  if(!in_array($kind,['province','district'],true)||!isset($areas[$kind][$c['scope_id']],$users[$c['original_id']],$users[$c['cover_id']],$users[$c['started_by']])||$c['original_id']==$c['cover_id']||$users[$c['original_id']]['role']!==$role||$users[$c['cover_id']]['role']!==$role||!in_array($c['status'],['active','attention','returned','cancelled'],true)||!$end||$end->format('Y-m-d H:i:s')!==$c['ends_at']||isset($seen[$c['id']]))throw new RuntimeException('Invalid temporary cover in backup.');
  $seen[$c['id']]=true;$key=$kind.':'.$c['scope_id'];if(in_array($c['status'],['active','attention'],true)){if(isset($active[$key]))throw new RuntimeException('Overlapping temporary cover records in backup.');$active[$key]=true;}
 }
 $seen=[];
 foreach($data['geo_service_boundaries'] as $b){$kind=$b['scope_kind'];$key=$kind.':'.$b['scope_id'];if(!isset($areas[$kind][$b['scope_id']],$users[$b['updated_by']])||isset($seen[$key]))throw new RuntimeException('Invalid boundary scope in backup.');followup_geometry($b['geometry']);$seen[$key]=true;}
}
