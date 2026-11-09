<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/layout.php';
require __DIR__.'/app/actions.php';
$page=(string)($_GET['page']??(isset($_SESSION['uid'])?'dashboard':'home'));
try {if(!q('SELECT id FROM users LIMIT 1')->fetch())go('setup.php');}catch(Throwable $ex){http_response_code(503);head_html('Setup required');echo '<div class="setup-card"><h1>Welcome to CivicCare</h1><p>Configure config.php, create the database and import database/schema.sql. Then open setup.php to create your administrator.</p><a class="button" href="setup.php">Open setup</a></div></body></html>';exit;}
try{q('SELECT category_id FROM category_service_targets LIMIT 1');q('SELECT id FROM temporary_cover LIMIT 1');q('SELECT scope_id FROM geo_service_boundaries LIMIT 1');q('SELECT issue_id FROM issue_flow LIMIT 1');q('SELECT team_id FROM team_categories LIMIT 1');q('SELECT id FROM issue_internal_notes LIMIT 1');q('SELECT province_id,district_id,town_id,public_photo FROM issues LIMIT 1');q('SELECT management_role,is_system_admin,town_id FROM users LIMIT 1');q('SELECT id FROM geo_towns LIMIT 1');q('SELECT active FROM categories LIMIT 1');q('SELECT auth_version FROM users LIMIT 1');q('SELECT id FROM recovery_codes LIMIT 1');q('SELECT issue_id FROM issue_feedback LIMIT 1');q('SELECT area_id,due_date FROM issues LIMIT 1');q('SELECT id FROM issue_progress_photos LIMIT 1');q('SELECT officer_id FROM officer_areas LIMIT 1');q('SELECT email_verified_at FROM users LIMIT 1');q('SELECT id FROM admin_reset_tokens LIMIT 1');q('SELECT key_hash FROM action_rate_buckets LIMIT 1');q('SELECT id FROM email_verifications LIMIT 1');q('SELECT maintenance_id,maintenance_version FROM issues LIMIT 1');}catch(Throwable $ex){http_response_code(503);head_html('Database update required');echo '<main class="setup-card"><h1>Database update required</h1><p>Select your existing database in phpMyAdmin and import database/citizen_upgrade.sql (if not yet installed), then staff_upgrade.sql and reliability_upgrade.sql, then maintenance_upgrade.sql, professional_upgrade.sql and province_routing_upgrade.sql, then workflow_upgrade.sql and workflow_followup_upgrade.sql and management_roles_upgrade.sql and photo_visibility_upgrade.sql from the database folder of the update packages. Then refresh this page. Your existing reports and accounts are kept.</p></main></body></html>';exit;}
$GLOBALS['geo_ready']=true;
if($_SERVER['REQUEST_METHOD']==='POST') {
 try{if(ops_bytes(ini_get('post_max_size'))>0 && (int)($_SERVER['CONTENT_LENGTH']??0)>ops_bytes(ini_get('post_max_size')))throw new RuntimeException('Upload exceeds PHP post_max_size. Check System health and increase the server limit before retrying.');handle_action();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();error_log('CivicCare request failed: '.get_class($ex).' code '.$ex->getCode());$message=$ex instanceof RuntimeException && !($ex instanceof PDOException)?$ex->getMessage():'The request could not be completed. Please try again.';
  if(team_upload_request()){
   http_response_code($ex instanceof RuntimeException && !($ex instanceof PDOException)?422:500);
   header('Content-Type: application/json');
   echo json_encode(['error'=>$message]);
   exit;
  }
  flash($message,'error');}
}
if(!isset($_GET['page']) && user())$page=management_home(user());
if($page==='verify'){require __DIR__.'/app/verification_page.php';exit;}
if($page==='forgot'){recovery_page();exit;}
if(in_array($page,['home','login','register'],true)) {require __DIR__.'/app/public.php';exit;}
require_login();
if(in_array($page,['management','management_setup'],true)){require __DIR__.'/app/management_page.php';exit;}
if(in_array($page,['system','audit','audit_export'],true))geo_system_required();
if($page==='readiness'){geo_system_required();require __DIR__.'/app/followup_page.php';exit;}
if (in_array($page,['dashboard','assignments','map','reports','routing','flow','issue','issues','mine','report','edit','users','areas'],true) && management_without_province()) {
 go('index.php?page=management');
}
if($page==='flow'){require_role(['admin','officer']);require __DIR__.'/app/flow_page.php';exit;}
if($page==='oversight'){geo_system_required();require __DIR__.'/app/oversight_page.php';exit;}
if (in_array($page,['dashboard','assigned','map','reports','routing','issue','issues','mine','report'],true) && management_without_district()) {
 go('index.php?page=management');
}
if($page==='routing'){require __DIR__.'/app/geography_page.php';exit;}
if(!geo_system() && user()['role']==='admin' && in_array($page,['users','areas'],true))go('index.php?page=routing');
if(user()['role']==='citizen'){if($page==='dashboard'){citizen_overview();exit;}if(in_array($page,['issues','mine','map'],true)){citizen_listing($page);exit;}}
if(user()['role']==='officer'){if(in_array($page,['issues','mine','report'],true))go('index.php?page='.($page==='issues'?'reports':'assigned'));if(in_array($page,['assigned','map'],true)){officer_work_page($page==='map');exit;}}
if (in_array($page,['dashboard','team_work','issue'],true) && management_without_town()) {
 go('index.php?page=management');
}
if(user()['role']==='maintenance'){if($page==='dashboard')go('index.php?page=team_work');if($page==='issue')go('index.php?page=team_work&id='.(int)($_GET['id']??0));if(!in_array($page,['team_work','profile','notifications'],true)){http_response_code(403);exit('Maintenance teams can access only their assigned work and account pages.');}}
if($page==='team_work'){require __DIR__.'/app/maintenance_page.php';exit;}
// Keep old dashboard queue bookmarks useful after moving detailed lists.
if($page==='dashboard' && user()['role']==='admin' && in_array($_GET['view']??'', ['unassigned','overdue','high','approval'],true)){$query=$_GET;$query['page']=$query['view']==='unassigned'?'assignments':'reports';if($query['page']==='assignments')$query['view']='unassigned';go('index.php?'.http_build_query($query));}
if($page==='profile'){require __DIR__.'/app/profile_page.php';exit;}
if(in_array($page,['users','areas','audit'],true)){require_role('admin');require __DIR__.'/app/admin_'.['users'=>'people','areas'=>'areas','audit'=>'audit'][$page].'.php';exit;}
if($page==='audit_export'){require_role('admin');try{admin_audit_export();}catch(RuntimeException $e){http_response_code(422);echo e($e->getMessage());}exit;}
if(user()['role']==='admin' && $page==='map'){require __DIR__.'/app/admin_map.php';exit;}
if($page==='assignments'){require __DIR__.'/app/assignment_page.php';exit;}
if($page==='system'){require __DIR__.'/app/system_page.php';exit;}
if($page==='officer_options'){require_role('admin');header('Content-Type: application/json');try{$area=chosen_area($_GET['area']??null);$rows=array_values(array_filter(officer_workloads($area),function($o)use($area){return !$area || !$o['area_count'] || $o['area_match'];}));echo json_encode($rows);}catch(RuntimeException $ex){http_response_code(422);echo json_encode(['error'=>$ex->getMessage()]);}exit;}
if($page==='duplicates') {
 header('Content-Type: application/json');$lat=$_GET['lat']??'';$lng=$_GET['lng']??'';
 if(!coords($lat,$lng)){http_response_code(422);echo json_encode(['error'=>'Invalid coordinates']);exit;}
 echo json_encode(duplicate_candidates((int)($_GET['category']??0),$lat,$lng));exit;
}
if($page==='staff_export'){try{staff_csv(staff_filter());}catch(RuntimeException $ex){http_response_code(422);header('Content-Type: text/plain; charset=utf-8');echo $ex instanceof PDOException?'The export could not be generated. Check the database update.':$ex->getMessage();}exit;}
if($page==='export') {
 require_role('admin');header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="civiccare-reports.csv"');
 $out=fopen('php://output','w');fputcsv($out,['ID','Title','Category','Status','Priority','Address','Latitude','Longitude','Officer','Supporters','Reported','Resolved']);
 foreach(q(issue_query().' ORDER BY i.id DESC')->fetchAll() as $i){$row=[$i['id'],$i['title'],$i['category'],$i['status'],$i['priority'],$i['address'],$i['latitude'],$i['longitude'],$i['officer'],$i['support_count'],$i['created_at'],$i['resolved_at']];foreach($row as &$v){if(preg_match('/^[=+@\-\t\r\n]/',(string)$v))$v="'".$v;}unset($v);fputcsv($out,$row);}fclose($out);exit;
}
$pages=['dashboard','issues','mine','assigned','map','report','edit','issue','users','notifications','profile','audit','areas','reports'];
if(!in_array($page,$pages,true)){http_response_code(404);layout_start('Page not found');echo '<p>This page is unavailable.</p>';layout_end();exit;}
if(in_array($page,['areas','reports'],true) || ($page==='dashboard' && in_array(user()['role'],['admin','officer'],true))){require __DIR__.'/app/staff_pages.php';exit;}
require __DIR__.'/app/pages.php';
