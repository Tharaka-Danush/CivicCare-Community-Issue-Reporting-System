<?php
function staff_statuses(){return ['Submitted','Verified','Assigned','In Progress','Awaiting Approval','Resolved','Rejected','Duplicate'];}
function service_areas(){return q('SELECT * FROM service_areas ORDER BY name')->fetchAll();}
function chosen_area($value){$id=(int)$value;if(!$id)return null;if(!q('SELECT id FROM service_areas WHERE id=?',[$id])->fetch())throw new RuntimeException('Choose a valid service area.');return $id;}
function valid_date($value,$label){if($value==='')return null;$d=DateTime::createFromFormat('!Y-m-d',$value);if(!$d || $d->format('Y-m-d')!==$value)throw new RuntimeException('Enter a valid '.$label.' date.');return $value;}
function staff_overdue_sql(){return "(i.status NOT IN ('Resolved','Rejected','Duplicate') AND ((i.due_date IS NOT NULL AND i.due_date<CURDATE()) OR (i.due_date IS NULL AND i.created_at<DATE_SUB(NOW(),INTERVAL 7 DAY))))";}
function staff_transitions($status,$admin){
 $map=['Submitted'=>['Verified','Rejected'],'Verified'=>['Assigned','Rejected'],'Assigned'=>['In Progress','Rejected'],'In Progress'=>['Awaiting Approval','Rejected'],'Awaiting Approval'=>['Resolved','In Progress','Rejected'],'Resolved'=>[],'Rejected'=>['Verified']];
 $next=$map[$status]??[];return $admin?$next:array_values(array_intersect($next,['In Progress','Awaiting Approval']));
}
function notify_admins($issue,$message){geo_notify_admin($issue,$message);}
function officer_workloads($area=null){
 $rows=q("SELECT u.id,u.name,(SELECT COUNT(*) FROM issues i WHERE i.officer_id=u.id AND i.status NOT IN ('Resolved','Rejected','Duplicate')) workload,(SELECT COUNT(*) FROM officer_areas oa WHERE oa.officer_id=u.id) area_count,EXISTS(SELECT 1 FROM officer_areas oa WHERE oa.officer_id=u.id AND oa.area_id=?) area_match,(SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ', ') FROM officer_areas oa JOIN service_areas a ON a.id=oa.area_id WHERE oa.officer_id=u.id) coverage FROM users u WHERE u.role='officer' AND u.active=1",[$area?:0])->fetchAll();
 usort($rows,function($a,$b)use($area){$ma=$area?(int)$a['area_match']:0;$mb=$area?(int)$b['area_match']:0;return ($mb<=>$ma)?:($a['workload']<=>$b['workload'])?:strcmp($a['name'],$b['name']);});return $rows;
}
function officer_eligible($officer,$area,$lock=false){if(!empty($GLOBALS['geo_ready']))return (bool)geo_raw("SELECT d.id FROM geo_districts d JOIN users u ON u.id=d.officer_id WHERE d.officer_id=? AND u.active=1",[$officer])->fetch();if(!$area)return true;$rows=q('SELECT area_id FROM officer_areas WHERE officer_id=?'.($lock?' FOR UPDATE':''),[$officer])->fetchAll();return !$rows || in_array((int)$area,array_map('intval',array_column($rows,'area_id')),true);}
function staff_filter(){
 require_role(['admin','officer']);$w=['1=1'];$p=[];$f=[];$f['search']=trim(substr((string)($_GET['search']??''),0,160));if($f['search']!==''){$w[]='(i.title LIKE ? OR i.address LIKE ? OR i.id=?)';$p[]='%'.$f['search'].'%';$p[]='%'.$f['search'].'%';$p[]=(int)ltrim($f['search'],'#');}
 if(user()['role']==='officer'){$w[]='i.officer_id=?';$p[]=user()['id'];}
 foreach(['from','to'] as $key)$f[$key]=valid_date(trim((string)($_GET[$key]??'')),$key);
 if($f['from']&&$f['to']&&$f['from']>$f['to'])throw new RuntimeException('The start date must be on or before the end date.');
 if($f['from']){$w[]='i.created_at>=?';$p[]=$f['from'].' 00:00:00';}
 if($f['to']){$w[]='i.created_at<DATE_ADD(?,INTERVAL 1 DAY)';$p[]=$f['to'];}
 foreach(['province'=>'province_id','district'=>'district_id','town'=>'town_id'] as $key=>$column){$f[$key]=max(0,(int)($_GET[$key]??0));if($f[$key]){$w[]='i.'.$column.'=?';$p[]=$f[$key];}}
 $f['status']=(string)($_GET['status']??'');if($f['status']!==''){if(!in_array($f['status'],staff_statuses(),true))throw new RuntimeException('Choose a valid status.');$w[]='i.status=?';$p[]=$f['status'];}
 $f['priority']=(string)($_GET['priority']??'');if($f['priority']!==''){if(!in_array($f['priority'],['Low','Medium','High'],true))throw new RuntimeException('Choose a valid priority.');$w[]='i.priority=?';$p[]=$f['priority'];}
 foreach(['category'=>'category_id','area'=>'area_id','officer'=>'officer_id'] as $key=>$column){$f[$key]=max(0,(int)($_GET[$key]??0));if($f[$key] && !($key==='officer'&&user()['role']==='officer')){$w[]='i.'.$column.'=?';$p[]=$f[$key];}}
 $f['team']=max(0,(int)($_GET['team']??0));if($f['team']){$w[]='i.maintenance_id=?';$p[]=$f['team'];}
 $f['team_stage']=(string)($_GET['team_stage']??'');if($f['team_stage']!==''){if($f['team_stage']==='none')$w[]='i.maintenance_id IS NULL';elseif(in_array($f['team_stage'],['Assigned','In Progress','Returned','Review','Approved'],true)){$w[]='i.maintenance_stage=?';$p[]=$f['team_stage'];}else throw new RuntimeException('Choose a valid team stage.');}
 $f['view']=(string)($_GET['view']??'all');
 if($f['view']==='unassigned')$w[]="i.officer_id IS NULL AND i.status NOT IN ('Resolved','Rejected','Duplicate')";
 elseif($f['view']==='overdue')$w[]=staff_overdue_sql();
 elseif($f['view']==='high')$w[]="i.priority='High' AND i.status NOT IN ('Resolved','Rejected','Duplicate')";
 elseif($f['view']==='approval')$w[]="i.status='Awaiting Approval'";
 else $f['view']='all';
 $sorts=['priority'=>"FIELD(i.priority,'High','Medium','Low'),i.created_at ASC,i.id ASC",'oldest'=>'i.created_at ASC,i.id ASC','newest'=>'i.created_at DESC,i.id DESC','due'=>"COALESCE(i.due_date,DATE_ADD(DATE(i.created_at),INTERVAL 7 DAY)) ASC,FIELD(i.priority,'High','Medium','Low'),i.id ASC"];
 $f['sort']=(string)($_GET['sort']??'priority');if(!isset($sorts[$f['sort']]))$f['sort']='priority';
 return ['sql'=>' WHERE '.implode(' AND ',$w),'params'=>$p,'values'=>$f,'order'=>$sorts[$f['sort']]];
}
function staff_csv($filter){
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="civiccare-filtered-reports.csv"');$out=fopen('php://output','w');
 fputcsv($out,['ID','Title','Category','Province','District','Town','Area','Status','Priority','Officer','Maintenance team','Team stage','Due date','Overdue','Reported','Resolved','Resolution days','Supporters']);
 $s=q(issue_query().$filter['sql'].' ORDER BY '.$filter['order'],$filter['params']);while($i=$s->fetch()){
  $overdue=!in_array($i['status'],['Resolved','Rejected','Duplicate'],true) && ($i['due_date']?strtotime($i['due_date'].' 23:59:59')<time():strtotime($i['created_at'])<time()-7*86400);
  $row=[$i['id'],$i['title'],$i['category'],$i['province_name'],$i['district_name'],$i['town_name'],$i['area_name'],$i['status'],$i['priority'],$i['officer'],$i['maintenance_team'],$i['maintenance_stage'],$i['due_date'],$overdue?'Yes':'No',$i['created_at'],$i['resolved_at'],$i['status']==='Resolved'&&$i['resolved_at']?round((strtotime($i['resolved_at'])-strtotime($i['created_at']))/86400,2):'',$i['support_count']];
  foreach($row as &$cell){if(preg_match('/^[\x00-\x20]*[=+@\-]/',(string)$cell))$cell="'".$cell;}unset($cell);fputcsv($out,$row);
 }fclose($out);
}
