<?php
function oversight_period(){
 $key=(string)($_GET['period']??'month');$labels=['today'=>'Today','week'=>'Last 7 days','month'=>'This month','all'=>'All dates'];if(!isset($labels[$key]))$key='month';
 $from=$key==='today'?date('Y-m-d'):($key==='week'?date('Y-m-d',strtotime('-6 days')):($key==='month'?date('Y-m-01'):null));
 return ['key'=>$key,'label'=>$labels[$key],'from'=>$from,'to'=>date('Y-m-d',strtotime('+1 day'))];
}
function oversight_routing_checks(){
 return [
  'location' => [
   "i.province_id IS NULL OR i.district_id IS NULL OR i.town_id IS NULL OR NOT EXISTS(SELECT 1 FROM geo_districts d JOIN geo_towns t ON t.district_id=d.id AND t.active=1 WHERE d.id=i.district_id AND d.province_id=i.province_id AND t.id=i.town_id)",
   'Location is missing, mismatched or inactive', 'System Admin: confirm and correct the report location.', 'issue'
  ],
  'province' => [
   "i.province_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM geo_provinces p JOIN users u ON u.id=p.admin_id AND u.active=1 AND u.role='admin' AND u.is_system_admin=0 WHERE p.id=i.province_id)",
   'Active Province Admin missing', 'Province Team Admin: map an active Province Admin.', 'province'
  ],
  'district' => [
   "i.district_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM geo_districts d JOIN users u ON u.id=d.officer_id AND u.active=1 AND u.role='officer' WHERE d.id=i.district_id)",
   'Active District Officer missing', 'District Officer Admin: map an active officer to the district.', 'district'
  ],
  'team' => [
   "i.town_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users u JOIN team_categories tc ON tc.team_id=u.id AND tc.category_id=i.category_id JOIN categories c ON c.id=tc.category_id AND c.active=1 WHERE u.role='maintenance' AND u.active=1 AND u.town_id=i.town_id)",
   'No active team covers this town and category', 'Maintenance Team Admin: configure a team and its service category, then the District Officer can dispatch.', 'town'
  ],
  'officer_assignment' => [
   "i.officer_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users u JOIN geo_districts d ON d.officer_id=u.id WHERE u.id=i.officer_id AND u.active=1 AND u.role='officer' AND d.id=i.district_id)",
   'Current officer is unavailable or outside the district', 'Province Admin: review the officer assignment after district coverage is restored.', 'issue'
  ],
  'team_assignment' => [
   "i.maintenance_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM users u JOIN team_categories tc ON tc.team_id=u.id AND tc.category_id=i.category_id JOIN categories c ON c.id=tc.category_id AND c.active=1 WHERE u.id=i.maintenance_id AND u.role='maintenance' AND u.active=1 AND u.town_id=i.town_id)",
   'Current team does not cover this town and category', 'District Officer: review the team assignment and choose an eligible team for active work.', 'issue'
  ]
 ];
}
function oversight_exception_sql(){
 $checks = array_map(function($check){return '('.$check[0].')';},oversight_routing_checks());
 return "i.status NOT IN ('Resolved','Rejected','Duplicate') AND (".implode(' OR ',$checks).')';
}
function oversight_reasons($i){
 $checks=oversight_routing_checks();
 $columns=[];
 foreach($checks as $key=>$check)$columns[]='('.$check[0].') AS '.$key;
 $flags=geo_raw('SELECT '.implode(', ',$columns).' FROM issues i WHERE i.id=?',[$i['id']])->fetch();
 return array_intersect_key($checks,array_filter($flags?:[]));
}
function oversight_run_reminders(){
 geo_system_required();ops_write_lock();$sent=0;$escalations=0;$day=date('Y-m-d');
 db()->beginTransaction();
 try{
 $rows=geo_raw("SELECT i.* FROM issues i WHERE ".staff_overdue_sql()." ORDER BY i.id FOR UPDATE")->fetchAll();
 foreach($rows as $i){
  $due=$i['due_date']?:date('Y-m-d',strtotime($i['created_at'].' +7 days'));$escalated=strtotime($due.' 00:00:00')<=strtotime('-7 days',strtotime($day));
  $recipients=[];
  $admin=geo_raw("SELECT u.id FROM geo_provinces p JOIN users u ON u.id=p.admin_id WHERE p.id=? AND u.active=1 AND u.role='admin' AND u.is_system_admin=0",[$i['province_id']])->fetchColumn();if($admin)$recipients[]=(int)$admin;
  $officer=geo_raw("SELECT u.id FROM geo_districts d JOIN users u ON u.id=d.officer_id WHERE d.id=? AND d.province_id=? AND u.id=? AND u.active=1 AND u.role='officer'",[$i['district_id'],$i['province_id'],$i['officer_id']])->fetchColumn();if($officer)$recipients[]=(int)$officer;
  $team=null;
  if(in_array($i['status'],['Assigned','In Progress'],true)&&in_array($i['maintenance_stage'],['Assigned','In Progress','Returned','Blocked'],true)){
   $team=geo_raw("SELECT id FROM users WHERE id=? AND town_id=? AND role='maintenance' AND active=1",[$i['maintenance_id'],$i['town_id']])->fetchColumn();
   if($team)$recipients[]=(int)$team;
  }
  if($escalated||(!$admin&&!$officer&&!$team))foreach(geo_raw("SELECT id FROM users WHERE role='admin' AND is_system_admin=1 AND active=1")->fetchAll() as $u)$recipients[]=(int)$u['id'];
  $key='Overdue follow-up '.$day.': report #'.$i['id'].'. ';$message=$key.($escalated?'Escalation: ':'Reminder: ').'passed its '.($i['due_date']?'due date ':'7-day attention date ').$due.'. ';
  foreach(array_unique($recipients) as $uid){if(geo_raw('SELECT id FROM notifications WHERE user_id=? AND issue_id=? AND message LIKE ?',[$uid,$i['id'],$key.'%'])->fetchColumn())continue;geo_raw('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$uid,$i['id'],$message.($team&&(int)$team===$uid?'Open your assigned work to update progress, submit evidence, or review the blocker.':'Open the report to review its next action and current owner.')]);$sent++;if($escalated)$escalations++;}
 }
 if($sent)audit('system.overdue_run',json_encode(['date'=>$day,'notifications'=>$sent,'escalations'=>$escalations]));db()->commit();return ['sent'=>$sent,'escalations'=>$escalations];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function oversight_action($action){
 if($action==='oversight_reminders'){geo_system_required();$result=oversight_run_reminders();flash($result['sent'].' in-app overdue notifications created. Existing notifications for today were skipped.');go('index.php?page=oversight&tab=reminders');}
 if($action==='validate_backup'){geo_system_required();ops_admin_password();$f=$_FILES['backup_file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Select a backup ZIP within the PHP upload limit.');$summary=ops_restore_backup($f['tmp_name'],true);audit('system.backup_validated','Archive checks passed. SHA-256: '.hash_file('sha256',$f['tmp_name']).'; backup date: '.$summary['created_at']);flash('Backup archive checks passed. No live reports, accounts or photos were replaced. A restore rehearsal on a separate database is still recommended.');go('index.php?page=system&tab=backup');}
}
function oversight_audit_change($action,$target,$before,$after,$reason){audit($action,json_encode(['format'=>'civic-change-1','target'=>$target,'before'=>$before,'after'=>$after,'reason'=>$reason],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
