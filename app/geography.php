<?php
function geo_system(){return user() && user()['role']==='admin' && !empty(user()['is_system_admin']);}
function geo_system_required(){require_role('admin');if(!geo_system()){http_response_code(403);exit('Only the System Admin can manage global settings and accounts.');}}
function geo_raw($sql,$params=[]){$s=db()->prepare($sql);$s->execute($params);return $s;}
function geo_province_ids(){return array_map('intval',array_column(geo_raw('SELECT id FROM geo_provinces WHERE admin_id=?',[user()['id']])->fetchAll(),'id'));}
function geo_district_ids(){return array_map('intval',array_column(geo_raw('SELECT id FROM geo_districts WHERE officer_id=?',[user()['id']])->fetchAll(),'id'));}
function geo_issue_predicate(){
 $u=user();$id=(int)$u['id'];
 if($u['role']==='admin')return 'province_id IN (SELECT id FROM geo_provinces WHERE admin_id='.$id.')';
 if($u['role']==='officer')return 'district_id IN (SELECT id FROM geo_districts WHERE officer_id='.$id.') AND officer_id='.$id;
 if($u['role']==='maintenance')return 'maintenance_id='.$id.' AND town_id='.(int)($u['town_id']??0);
 return '1=1';
}
function geo_wrap_table($sql,$table,$predicate){
 $keywords='WHERE|LEFT|RIGHT|INNER|OUTER|CROSS|JOIN|ON|ORDER|GROUP|LIMIT|FOR|UNION|HAVING|OFFSET|SET|USING';
 return preg_replace_callback('/\b(FROM|JOIN)\s+'.preg_quote($table,'/').'\b(?:\s+(?:AS\s+)?((?!(?:'.$keywords.')\b)[a-z_][a-z0-9_]*))?/i',function($m)use($table,$predicate){return $m[1].' (SELECT * FROM '.$table.' WHERE '.$predicate.') '.(!empty($m[2])?$m[2]:$table);},$sql);
}
function geo_scope_read($sql){
 // Scope every issue read, including counts, maps, exports and photo lookups.
 // Locking writes use raw rows and the explicit guard below, under the global write lock.
 if(empty($GLOBALS['geo_ready']) || !preg_match('/^\s*SELECT\b/i',$sql) || stripos($sql,'FOR UPDATE')!==false)return $sql;
 if(!preg_match('/\b(?:FROM|JOIN)\s+issues\b/i',$sql) && !preg_match("/\brole\s*=\s*'(officer|maintenance)'/i",$sql))return $sql;
 $u=user();if(!$u||$u['role']==='citizen'||geo_system())return $sql;
 if(preg_match('/\b(?:FROM|JOIN)\s+issues\b/i',$sql))$sql=geo_wrap_table($sql,'issues',geo_issue_predicate());
 // Staff selectors must also stay within the actor's province/district.
 if(preg_match("/\brole\s*=\s*'(officer|maintenance)'/i",$sql) && preg_match('/\bFROM\s+users\b/i',$sql)){
  $uid=(int)$u['id'];$districts=$u['role']==='admin'?'SELECT d.id FROM geo_districts d JOIN geo_provinces p ON p.id=d.province_id WHERE p.admin_id='.$uid:'SELECT id FROM geo_districts WHERE officer_id='.$uid;
  $scope='id IN (SELECT officer_id FROM geo_districts WHERE id IN ('.$districts.')) OR town_id IN (SELECT id FROM geo_towns WHERE district_id IN ('.$districts.'))';
  $sql=geo_wrap_table($sql,'users','('.$scope.')');
 }
 return $sql;
}
function geo_can_manage($i){
 if(!$i||!user())return false;if(geo_system())return true;$u=user();
 if($u['role']==='admin')return in_array((int)$i['province_id'],geo_province_ids(),true);
 if($u['role']==='officer')return (int)$i['officer_id']===(int)$u['id'] && in_array((int)$i['district_id'],geo_district_ids(),true);
 if($u['role']==='maintenance')return (int)$i['maintenance_id']===(int)$u['id'] && (int)$i['town_id']===(int)$u['town_id'];
 return false;
}
function geo_require_issue($id){$i=geo_raw('SELECT * FROM issues WHERE id=?',[(int)$id])->fetch();if(!geo_can_manage($i))throw new RuntimeException('This report is outside your assigned province, district or town.');return $i;}
function geo_validate_location($input){
 foreach(['province_id','district_id','town_id'] as $key)if(!isset($input[$key])||!is_scalar($input[$key])||!ctype_digit((string)$input[$key]))throw new RuntimeException('Choose a valid Province → District → Town combination.');
 $province=(int)($input['province_id']??0);$district=(int)($input['district_id']??0);$town=(int)($input['town_id']??0);
 $r=geo_raw('SELECT t.id,t.district_id,d.province_id FROM geo_towns t JOIN geo_districts d ON d.id=t.district_id WHERE t.id=? AND t.active=1 AND d.id=? AND d.province_id=?',[$town,$district,$province])->fetch();
 if(!$r)throw new RuntimeException('Choose a valid Province → District → Town combination.');if(isset($input['latitude'],$input['longitude'])&&coords($input['latitude'],$input['longitude']))followup_check_location(['province_id'=>$province,'district_id'=>$district,'town_id'=>$town,'latitude'=>$input['latitude'],'longitude'=>$input['longitude']]);return [$province,$district,$town];
}
function geo_notify_admin($i,$message){
 if(empty($i['province_id']))return;
 $admins=geo_raw("SELECT u.id FROM geo_provinces p JOIN users u ON u.id=p.admin_id AND u.active=1 AND u.role='admin' AND u.is_system_admin=0 WHERE p.id=?",[$i['province_id']])->fetchAll();
 foreach($admins as $admin)if((int)$admin['id']!==(int)user()['id'])q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$admin['id'],$i['id'],$message]);
}
function geo_account_label(){
 if(geo_system())return 'System Admin';if(management_without_district())return 'District Officer Admin · Nationwide';if(management_without_province())return 'Province Team Admin · Nationwide';$u=user();if($u['role']==='admin'){$names=geo_raw('SELECT name FROM geo_provinces WHERE admin_id=?',[$u['id']])->fetchAll();return $names?'Province Admin · '.implode(', ',array_column($names,'name')):'Province Admin · scope not configured';}
 if($u['role']==='officer'){$names=geo_raw('SELECT name FROM geo_districts WHERE officer_id=?',[$u['id']])->fetchAll();return $names?'District Officer · '.implode(', ',array_column($names,'name')):'District Officer · scope not configured';}
 if($u['role']==='maintenance')return 'Town Maintenance Team · '.(geo_raw('SELECT name FROM geo_towns WHERE id=?',[(int)$u['town_id']])->fetchColumn()?:'town not configured');return 'Citizen';
}
function geo_check_action($action){
 if(!user())return;
 $global=['create_user','toggle_user','admin_edit_user','create_area','officer_coverage','category','category_rename','category_access','download_backup','restore_backup','issue_admin_reset'];
 if(in_array($action,$global,true))geo_system_required();
 if(user()['role']!=='citizen' && isset($_POST['issue_id']) && !in_array($action,['open_notification','geo_classify','geo_relocate'],true))geo_require_issue((int)$_POST['issue_id']);
 if($action==='merge'){
  $a=geo_require_issue((int)($_POST['issue_id']??0));$b=geo_require_issue((int)($_POST['target_id']??0));
  if((int)$a['province_id']!==(int)$b['province_id']||(int)$a['district_id']!==(int)$b['district_id']||(int)$a['town_id']!==(int)$b['town_id'])throw new RuntimeException('Duplicate reports must be in the same province, district and town.');
 }
 if($action==='team_review'||($action==='team_assign'&&!geo_system()))require_role('officer');
 if($action==='assign_officer'){
  $i=geo_require_issue((int)($_POST['issue_id']??0));
  $officer=(int)($_POST['officer_id']??0);
  if(!$i['town_id']||!geo_raw('SELECT id FROM geo_districts WHERE id=? AND province_id=? AND officer_id=?',[$i['district_id'],$i['province_id'],$officer])->fetch())throw new RuntimeException('Assign this report to the configured officer for its own district.');
 }
 if($action==='team_assign'){
  $i=geo_require_issue((int)($_POST['issue_id']??0));$team=(int)($_POST['maintenance_id']??0);
  if(!geo_raw("SELECT id FROM users WHERE id=? AND role='maintenance' AND active=1 AND town_id=?",[$team,$i['town_id']])->fetch())throw new RuntimeException('Choose an active maintenance team for this report’s town.');
 }
 if($action==='update_issue'){
  $i=geo_require_issue((int)($_POST['issue_id']??0));$status=(string)($_POST['status']??'');
  if(isset($_POST['officer_id']) && (int)$_POST['officer_id']!==(int)$i['officer_id'])throw new RuntimeException('Use Assign issues to change the District Officer.');
  if($status!==$i['status'] && $status==='In Progress' && $i['status']!=='Awaiting Approval')throw new RuntimeException('The assigned town maintenance team starts the work.');
  if($status!==$i['status'] && $status==='Awaiting Approval')throw new RuntimeException('Review the town team’s completion using the officer review controls.');
 }
}
function geo_location_selector($values=[],$required=true,$manage=false){
 $allowed_p=$manage&&!geo_system()?(user()['role']==='admin'?geo_province_ids():array_column(geo_raw('SELECT DISTINCT province_id FROM geo_districts WHERE officer_id=?',[user()['id']])->fetchAll(),'province_id')):null;$allowed_d=$manage&&user()['role']==='officer'?geo_district_ids():null;
 $province=(int)($values['province_id']??0);$district=(int)($values['district_id']??0);$town=(int)($values['town_id']??0);
 ?><div class="geo-selector citizen-field-grid" data-geo-selector><label>Province<select name="province_id" data-geo-province <?=$required?'required':''?>><option value="">Choose province</option><?php foreach(geo_raw('SELECT id,name FROM geo_provinces ORDER BY name')->fetchAll() as $r):if($allowed_p!==null&&!in_array((int)$r['id'],array_map('intval',$allowed_p),true))continue;?><option value="<?=$r['id']?>" <?=$province===(int)$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select></label><label>District<select name="district_id" data-geo-district <?=$required?'required':''?>><option value="">Choose district</option><?php foreach(geo_raw('SELECT d.*,p.name province FROM geo_districts d JOIN geo_provinces p ON p.id=d.province_id ORDER BY p.name,d.name')->fetchAll() as $r):if(($allowed_p!==null&&!in_array((int)$r['province_id'],array_map('intval',$allowed_p),true))||($allowed_d!==null&&!in_array((int)$r['id'],$allowed_d,true)))continue;?><option value="<?=$r['id']?>" data-province="<?=$r['province_id']?>" <?=$district===(int)$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select></label><label>Town / maintenance area<select name="town_id" data-geo-town <?=$required?'required':''?>><option value="">Choose town</option><?php foreach(geo_raw('SELECT * FROM geo_towns WHERE active=1 ORDER BY name')->fetchAll() as $r):?><option value="<?=$r['id']?>" data-district="<?=$r['district_id']?>" <?=$town===(int)$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select></label></div><?php
}
function geo_action($action,$delegated=false){
 if(strpos($action,'geo_')!==0)return;
 if(!$delegated)require_role(['admin','officer']);
 if(in_array($action,['geo_classify','geo_relocate'],true)){
  geo_system_required();$id=(int)($_POST['issue_id']??0);$loc=geo_validate_location($_POST);$reason=field('note',5,2000);db()->beginTransaction();$i=geo_raw('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$i||($action==='geo_classify'&&$i['province_id']&&$i['district_id']&&$i['town_id']))throw new RuntimeException('Only unclassified legacy reports can be routed here.');
  if((string)$i['updated_at']!==(string)($_POST['previous_updated']??''))throw new RuntimeException('This report changed. Refresh before routing.');
  if($i['maintenance_id'])maintenance_release($i,'Legacy report routed into the province/district/town workflow.');
  $status=in_array($i['status'],['Resolved','Rejected','Duplicate'],true)?$i['status']:'Submitted';
  q('UPDATE issues SET province_id=?,district_id=?,town_id=?,officer_id=NULL,status=? WHERE id=?',array_merge($loc,[$status,$id]));geo_raw("UPDATE issue_flow SET location_state='pending',location_hash=NULL,accepted_by=NULL,accepted_at=NULL,block_kind=NULL,block_reason=NULL,next_review=NULL,version=version+1 WHERE issue_id=?",[$id]);event_add($id,'routing',($action==='geo_classify'?'Legacy location classified. ':'Routing corrected. Previous province/district/town: '.($i['province_id']?:'none').'/'.($i['district_id']?:'none').'/'.($i['town_id']?:'none').'. Previous officer: '.($i['officer_id']?:'none').'. ').$reason);if($action==='geo_relocate')geo_notify_admin($i,'Report #'.$id.' routing changed by System Admin.');oversight_audit_change('issue.routing_changed','Issue #'.$id,['province'=>$i['province_id'],'district'=>$i['district_id'],'town'=>$i['town_id'],'officer'=>$i['officer_id'],'status'=>$i['status']],['province'=>$loc[0],'district'=>$loc[1],'town'=>$loc[2],'officer'=>null,'status'=>$status],$reason);audit('issue.routed','Issue #'.$id.' routed to province #'.$loc[0].', district #'.$loc[1].', town #'.$loc[2]);$i['province_id']=$loc[0];geo_notify_admin($i,'New report routed to your province: #'.$id);db()->commit();flash('Report routed to its province. Active work returns to review; evidence and history are retained.');go($action==='geo_classify'?'index.php?page=routing&tab=legacy':'index.php?page=issue&id='.$id);
 }
 if($action==='geo_create_town'){
  $district=(int)($_POST['district_id']??0);$province=(int)($_POST['province_id']??0);$name=field('name',2,100);
  $d=geo_raw('SELECT * FROM geo_districts WHERE id=? AND province_id=?',[$district,$province])->fetch();
  if(!$d || (!geo_system() && !(user()['role']==='admin'&&in_array($province,geo_province_ids(),true))) && !(user()['role']==='officer'&&in_array($district,geo_district_ids(),true)))throw new RuntimeException('Choose a district you manage.');
  if(geo_raw('SELECT id FROM geo_towns WHERE district_id=? AND name=?',[$district,$name])->fetch())throw new RuntimeException('That town already exists in the selected district.');
  db()->beginTransaction();q('INSERT INTO geo_towns(district_id,name) VALUES(?,?)',[$district,$name]);audit('town.created',$name.' in district #'.$district);db()->commit();flash('Town added.');go('index.php?page=routing&tab=towns');
 }
 if($action==='geo_create_account'){
  if(!$delegated&&!geo_system())require_role('admin');$kind=(string)($_POST['account_kind']??'');if(!in_array($kind,['province','district','town'],true))throw new RuntimeException('Choose an account type.');
  if($delegated&&!management_allows($kind))throw new RuntimeException('You can create accounts only for your team administration scope.');
  if(!$delegated&&$kind==='province'&&!geo_system())throw new RuntimeException('Only the System Admin creates Province Admin accounts.');
  $province=(int)($_POST['province_id']??0);$district=(int)($_POST['district_id']??0);$town=(int)($_POST['town_id']??0);
  $p=geo_raw('SELECT * FROM geo_provinces WHERE id=?',[$province])->fetch();if(!$p||(!$delegated&&!geo_system()&&!in_array($province,geo_province_ids(),true)))throw new RuntimeException('Choose your assigned province.');
  $d=$kind!=='province'?geo_raw('SELECT * FROM geo_districts WHERE id=? AND province_id=?',[$district,$province])->fetch():null;
  if($kind!=='province'&&!$d)throw new RuntimeException('Choose a district in this province.');
  if($kind==='town')geo_validate_location($_POST);
  if(($kind==='province'&&$p['admin_id'])||($kind==='district'&&$d['officer_id']))throw new RuntimeException('This province/district already has an account. Use its existing login or configure an existing account below.');
  $name=field('name',2,100);$email=valid_email();$pass=password_input();if(geo_raw('SELECT id FROM users WHERE email=?',[$email])->fetch())throw new RuntimeException('Email is already registered.');$role=['province'=>'admin','district'=>'officer','town'=>'maintenance'][$kind];
  db()->beginTransaction();q('INSERT INTO users(name,email,password,role,is_system_admin,town_id) VALUES(?,?,?,?,0,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role,$kind==='town'?$town:null]);$uid=(int)db()->lastInsertId();
  if($kind==='province')q('UPDATE geo_provinces SET admin_id=? WHERE id=?',[$uid,$province]);elseif($kind==='district')q('UPDATE geo_districts SET officer_id=? WHERE id=?',[$uid,$district]);
  audit('routing.account_created',ucfirst($kind).' account #'.$uid.'; province #'.$province.'; district #'.$district.'; town #'.$town);db()->commit();flash('Scoped account created. Give the temporary credentials privately; change the password in My profile.');go($delegated?'index.php?page=management':'index.php?page=routing&tab=accounts');
 }
 if($action==='geo_map_account'){
  if(!$delegated)geo_system_required();$changeReason=field('change_reason',5,2000);$kind=(string)($_POST['mapping_kind']??'');$uid=(int)($_POST['user_id']??0);$target=(int)($_POST['target_id']??0);if(!in_array($kind,['province','district','town'],true))throw new RuntimeException('Choose a mapping type.');
  if($delegated && (!management_allows($kind) || $uid===(int)user()['id'])){
   throw new RuntimeException('Choose an account within your management scope. Only System Admin can change your own routing.');
  }
  if($delegated && $kind!=='town' && geo_raw("SELECT id FROM temporary_cover WHERE scope_kind=? AND scope_id=? AND status='active'",[$kind,$target])->fetchColumn()){
   throw new RuntimeException('Ask System Admin to close the active temporary cover before changing responsibility.');
  }
  db()->beginTransaction();$u=geo_raw('SELECT * FROM users WHERE id=? FOR UPDATE',[$uid])->fetch();$role=['province'=>'admin','district'=>'officer','town'=>'maintenance'][$kind];
  if(!$u||$u['role']!==$role||!$u['active'])throw new RuntimeException('Choose an active account with the correct role.');if($delegated && $u['is_system_admin'])throw new RuntimeException('System Admin accounts are excluded.');if($kind==='province'&&!$delegated){if($uid===(int)user()['id'])throw new RuntimeException('Keep your current System Admin account for central setup. Map a separate Province Admin account.');ops_admin_password();q('UPDATE users SET is_system_admin=0 WHERE id=?',[$uid]);}
  $oldMapping=$kind==='town'?['account'=>$uid,'town'=>$u['town_id']]:geo_raw('SELECT id,'.($kind==='province'?'admin_id':'officer_id').' responsible_account FROM '.($kind==='province'?'geo_provinces':'geo_districts').' WHERE id=?',[$target])->fetch();
  if($kind==='town'){
   if(!geo_raw('SELECT id FROM geo_towns WHERE id=? AND active=1',[$target])->fetch())throw new RuntimeException('Choose a valid town.');
   if(geo_raw("SELECT id FROM issues WHERE maintenance_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') AND (town_id IS NULL OR town_id<>?) LIMIT 1",[$uid,$target])->fetch())throw new RuntimeException('Finish or reassign existing open work before moving this team to another town.');
   q('UPDATE users SET town_id=?,auth_version=auth_version+1 WHERE id=?',[$target,$uid]);
  }else{
   $table=$kind==='province'?'geo_provinces':'geo_districts';$column=$kind==='province'?'admin_id':'officer_id';$row=geo_raw('SELECT * FROM '.$table.' WHERE id=? FOR UPDATE',[$target])->fetch();if(!$row)throw new RuntimeException('Choose a valid target.');
   if(geo_raw('SELECT id FROM '.$table.' WHERE '.$column.'=? AND id<>?',[$uid,$target])->fetch())throw new RuntimeException('This account already manages another province/district. Use a separate account.');
   if($kind==='district' && $row[$column] && (int)$row[$column]!==$uid){
    $work=geo_raw("SELECT * FROM issues WHERE district_id=? AND officer_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') FOR UPDATE",[$target,$row[$column]])->fetchAll();
    if($work){if(($_POST['transfer_open']??'')!=='1')throw new RuntimeException('This district has open work. Confirm transfer and provide a handover reason before replacing its District Officer.');$handover=field('handover_note',5,2000);
     foreach($work as $report){q('UPDATE issues SET officer_id=?,maintenance_version=maintenance_version+1 WHERE id=?',[$uid,$report['id']]);geo_raw('UPDATE issue_flow SET accepted_by=NULL,accepted_at=NULL,version=version+1 WHERE issue_id=?',[$report['id']]);oversight_audit_change('issue.officer_handover','Issue #'.$report['id'],['officer'=>$row[$column]],['officer'=>$uid],$handover);event_add($report['id'],'routing','District responsibility transferred from officer #'.$row[$column].' to #'.$uid.'. '.$handover);$report['officer_id']=$uid;notify_issue($report,'District Officer responsibility updated for report #'.$report['id']);q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$row[$column],$report['id'],'Report #'.$report['id'].' transferred to the new District Officer.']);}
    }
   }
   q('UPDATE '.$table.' SET '.$column.'=? WHERE id=?',[$uid,$target]);q('UPDATE users SET auth_version=auth_version+1 WHERE id IN (?,?)',[$uid,$row[$column]]);
  }
  oversight_audit_change('routing.responsibility_changed',ucfirst($kind).' #'.$target,$oldMapping?:[],['target'=>$target,'responsible_account'=>$uid],$changeReason);audit('routing.account_mapped',ucfirst($kind).' #'.$target.' → account #'.$uid);db()->commit();flash('Account scope saved. That account must sign in again.');go($delegated?'index.php?page=management':'index.php?page=routing&tab=accounts');
 }
 throw new RuntimeException('Unknown routing action.');
}
function geo_validate_backup($data){
 $users=[];foreach($data['users'] as $u)$users[(int)$u['id']]=$u;$provinces=[];$districts=[];$towns=[];
 foreach($data['geo_provinces'] as $p){$provinces[(int)$p['id']]=$p;if($p['admin_id']!==null){$u=$users[(int)$p['admin_id']]??null;if(!$u||$u['role']!=='admin'||!empty($u['is_system_admin']))throw new RuntimeException('Backup has an invalid Province Admin account.');}}
 foreach($data['geo_districts'] as $d){$districts[(int)$d['id']]=$d;if(!isset($provinces[(int)$d['province_id']]))throw new RuntimeException('Backup district has an invalid province.');if($d['officer_id']!==null&&($users[(int)$d['officer_id']]['role']??'')!=='officer')throw new RuntimeException('Backup has an invalid District Officer.');}
 foreach($data['geo_towns'] as $t){$towns[(int)$t['id']]=$t;if(!isset($districts[(int)$t['district_id']]))throw new RuntimeException('Backup town has an invalid district.');}
 if(count($provinces)!==9||count($districts)!==25)throw new RuntimeException('Backup must contain the 9 configured provinces and 25 districts.');
 foreach($users as $u)if($u['town_id']!==null && ($u['role']!=='maintenance'||!isset($towns[(int)$u['town_id']])))throw new RuntimeException('Backup team has an invalid town.');
 foreach($data['issues'] as $i){
  if($i['province_id']===null&&$i['district_id']===null&&$i['town_id']===null)continue; // Retained legacy report.
  $p=$provinces[(int)$i['province_id']]??null;$d=$districts[(int)$i['district_id']]??null;$t=$towns[(int)$i['town_id']]??null;
  if(!$p||!$d||!$t||(int)$d['province_id']!==(int)$p['id']||(int)$t['district_id']!==(int)$d['id'])throw new RuntimeException('Backup report has an inconsistent Province → District → Town path.');
  if(!in_array($i['status'],['Resolved','Rejected','Duplicate'],true)){
   if($i['officer_id']!==null&&(int)$i['officer_id']!==(int)$d['officer_id'])throw new RuntimeException('Backup active report has the wrong District Officer.');
   if($i['maintenance_id']!==null && (int)($users[(int)$i['maintenance_id']]['town_id']??0)!==(int)$t['id'])throw new RuntimeException('Backup active work has the wrong town maintenance team.');
  }
 }
}
function geo_filter_controls($f){
 $pwhere='';$dwhere='';$params=[];
 if(!geo_system()&&user()['role']==='admin'){$ids=geo_province_ids();$pwhere=' WHERE id IN ('.implode(',',$ids?:[0]).')';$dwhere=' WHERE province_id IN ('.implode(',',$ids?:[0]).')';}
 elseif(user()['role']==='officer'){$ids=geo_district_ids();$dwhere=' WHERE id IN ('.implode(',',$ids?:[0]).')';$pwhere=' WHERE id IN (SELECT province_id FROM geo_districts'.$dwhere.')';}
 $provinces=geo_raw('SELECT id,name FROM geo_provinces'.$pwhere.' ORDER BY name')->fetchAll();$districts=geo_raw('SELECT id,name,province_id FROM geo_districts'.$dwhere.' ORDER BY name')->fetchAll();$towns=geo_raw('SELECT t.id,t.district_id,d.province_id,CONCAT(d.name,\' / \',t.name) name FROM geo_towns t JOIN geo_districts d ON d.id=t.district_id WHERE t.active=1'.($dwhere?' AND d.id IN (SELECT id FROM geo_districts'.$dwhere.')':'').' ORDER BY d.name,t.name')->fetchAll();
 ?><div class="admin-filter-grid section-space" data-geo-selector data-geo-filter><?php foreach(['province'=>['Province',$provinces],'district'=>['District',$districts],'town'=>['Town',$towns]] as $key=>$info):?><label><?=e($info[0])?><select name="<?=$key?>" data-geo-<?=$key?>><option value="">All permitted <?=strtolower($info[0])?>s</option><?php foreach($info[1] as $r):?><option value="<?=$r['id']?>" data-province="<?=(int)($r['province_id']??0)?>" data-district="<?=(int)($r['district_id']??0)?>" <?=(int)($f[$key]??0)===(int)$r['id']?'selected':''?>><?=e($r['name'])?></option><?php endforeach;?></select></label><?php endforeach;?></div><?php
}
