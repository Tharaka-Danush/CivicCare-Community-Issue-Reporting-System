<?php
require __DIR__.'/staff_actions.php';
require __DIR__.'/operations_actions.php';
require __DIR__.'/assignment_actions.php';
require __DIR__.'/maintenance_actions.php';
require __DIR__.'/admin_actions.php';
function handle_action() {
 check_csrf();ops_write_lock();$action=(string)($_POST['action']??'');
 management_action($action);
 if(user() && user()['role']==='maintenance' && !in_array($action,['team_start','team_progress','team_submit','logout','profile','change_password','generate_recovery','read_notifications','open_notification','request_verification','verify_email','flow_block','flow_resume','flow_internal_note'],true)){http_response_code(403);exit('This action is not available to maintenance teams.');}
 geo_check_action($action);
 photo_visibility_action($action);
 followup_action($action);
 oversight_action($action);
 flow_guard($action);
 flow_action($action);
 geo_action($action);
 handle_professional_action($action);
 handle_admin_workspace_action($action);
 handle_maintenance_action($action);
 handle_operations_action($action);
 handle_assignment_action($action);
 if($action==='login') {
  $email=valid_email();$key=hash('sha256',$email.'|'.($_SERVER['REMOTE_ADDR']??''));
  $attempt=q('SELECT * FROM login_attempts WHERE key_hash=?',[$key])->fetch();
  if($attempt && $attempt['blocked_until'] && strtotime($attempt['blocked_until'])>time())throw new RuntimeException('Too many attempts. Try again in 15 minutes.');
  $u=q('SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
  if(!$u || !password_verify((string)($_POST['password']??''),$u['password'])) {
   q("INSERT INTO login_attempts(key_hash,failures) VALUES(?,1) ON DUPLICATE KEY UPDATE failures=IF(updated_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,failures+1)",[$key]);
   q('UPDATE login_attempts SET blocked_until=DATE_ADD(NOW(),INTERVAL 15 MINUTE) WHERE key_hash=? AND failures>=5',[$key]);
   throw new RuntimeException('Email or password is incorrect.');
  }
  q('DELETE FROM login_attempts WHERE key_hash=?',[$key]);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['auth_version']=(int)$u['auth_version'];$_SESSION['csrf']=bin2hex(random_bytes(32));go('index.php?page='.management_home($u));
 }
 if($action==='register') {
  $name=field('name',2,100);$email=valid_email();$pass=password_input();
  if(q('SELECT id FROM users WHERE email=?',[$email])->fetch())throw new RuntimeException('This email is already registered.');
  ops_rate_limit('register',true);$verification=ops_options()['email_verification_required'];
  if($verification && !ops_mail_ready())throw new RuntimeException('Email verification is temporarily unavailable. Contact the administrator.');
  try{db()->beginTransaction();q('INSERT INTO users(name,email,password) VALUES(?,?,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);$uid=(int)db()->lastInsertId();if($verification)ops_verification_email($uid,$email);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  flash($verification?'Account created. Check your email to verify your address, then sign in.':'Account created. Sign in to report your first issue.');go('index.php?page=login');
 }
 if($action==='recover_password') {
  $email=valid_email();$pass=password_input();
  if(!hash_equals($pass,(string)($_POST['password_confirm']??'')))throw new RuntimeException('Passwords do not match.');
  $key=hash('sha256','recovery|'.$email.'|'.($_SERVER['REMOTE_ADDR']??''));
  $attempt=q('SELECT * FROM login_attempts WHERE key_hash=?',[$key])->fetch();
  if($attempt && $attempt['blocked_until'] && strtotime($attempt['blocked_until'])>time())throw new RuntimeException('Too many recovery attempts. Try again in 15 minutes.');
  $raw=strtoupper(preg_replace('/[\s-]+/','',(string)($_POST['recovery_code']??'')));
  db()->beginTransaction();
  try {
   $u=q('SELECT * FROM users WHERE email=? AND active=1 FOR UPDATE',[$email])->fetch();
   $code=$u && preg_match('/^[A-F0-9]{32}$/',$raw)?q('SELECT id FROM recovery_codes WHERE user_id=? AND code_hash=? AND used_at IS NULL FOR UPDATE',[$u['id'],hash('sha256',$raw)])->fetch():false;
   $adminCode=false;if(!$code && $u && preg_match('/^[A-F0-9]{32}$/',$raw))$adminCode=q('SELECT id FROM admin_reset_tokens WHERE user_id=? AND code_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[$u['id'],hash('sha256',$raw)])->fetch();
   if(!$code && !$adminCode){db()->rollBack();q("INSERT INTO login_attempts(key_hash,failures) VALUES(?,1) ON DUPLICATE KEY UPDATE failures=IF(updated_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE),1,failures+1)",[$key]);q('UPDATE login_attempts SET blocked_until=DATE_ADD(NOW(),INTERVAL 15 MINUTE) WHERE key_hash=? AND failures>=5',[$key]);throw new RuntimeException('Email or recovery code is incorrect, expired or already used.');}
   if($adminCode){q('UPDATE admin_reset_tokens SET used_at=NOW() WHERE id=?',[$adminCode['id']]);q('DELETE FROM recovery_codes WHERE user_id=?',[$u['id']]);q('DELETE FROM email_verifications WHERE user_id=?',[$u['id']]);}else q('UPDATE recovery_codes SET used_at=NOW() WHERE id=?',[$code['id']]);
   q('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=?',[password_hash($pass,PASSWORD_DEFAULT),$u['id']]);
   q('DELETE FROM login_attempts WHERE key_hash=?',[$key]);
   q('INSERT INTO audit_log(actor_id,action,details) VALUES(?,?,?)',[$u['id'],'account.password_recovered',$adminCode?'Administrator-issued reset code verified; old recovery codes and sessions invalidated.':'Single-use recovery code verified; prior sessions invalidated.']);
   db()->commit();
  }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
  unset($_SESSION['uid'],$_SESSION['auth_version']);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));flash('Password reset. Sign in with your new password.');go('index.php?page=login');
 }
 require_login();
 handle_staff_action($action);
 if($action==='logout') {$_SESSION=[];session_destroy();go('index.php');}
 if($action==='generate_recovery') {
  if(!password_verify((string)($_POST['current_password']??''),user()['password']))throw new RuntimeException('Current password is incorrect.');
  db()->beginTransaction();q('SELECT id FROM users WHERE id=? FOR UPDATE',[user()['id']]);
  $codes=recovery_batch(user()['id']);audit('account.recovery_codes_generated','Previous recovery codes invalidated.');db()->commit();$_SESSION['new_recovery_codes']=$codes;go('index.php?page=profile'.(user()['role']==='maintenance'?'&tab=recovery':''));
 }
 if($action==='edit_issue') {
  ops_rate_limit('edit');
  $id=(int)($_POST['issue_id']??0);$photo=null;$oldPhoto=null;
  try{
   db()->beginTransaction();$issue=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
   if(($_POST['details_checked']??'')!=='1')throw new RuntimeException('Check the report details and photo before saving.');
   if(!$issue || !editable_report($issue))throw new RuntimeException('You can edit only your own unassigned Submitted reports without linked duplicates.');
   $version=(int)q('SELECT COALESCE(MAX(id),0) FROM issue_events WHERE issue_id=?',[$id])->fetchColumn();
   if((string)$version!==(string)($_POST['edit_version']??''))throw new RuntimeException('This report changed while you were editing. Reload the page before saving.');
   $geo=geo_validate_location($_POST);if(user()['role']==='admin'&&!geo_system()&&!in_array($geo[0],geo_province_ids(),true))throw new RuntimeException('Choose your assigned province.');$title=field('title',8,160);$description=field('description',20,5000);$address=field('address',3,255);$cat=(int)($_POST['category_id']??0);$area=chosen_area($_POST['area_id']??null);
   if(!q('SELECT id FROM categories WHERE id=? AND (active=1 OR id=?)',[$cat,$issue['category_id']])->fetch())throw new RuntimeException('Choose an active category.');
   $lat=$_POST['latitude']??'';$lng=$_POST['longitude']??'';if(!coords($lat,$lng))throw new RuntimeException('Choose valid coordinates.');
   $photo=photo_upload('photo');$oldPhoto=$issue['photo'];
   q('UPDATE issues SET title=?,description=?,address=?,category_id=?,latitude=?,longitude=?,photo=?,public_photo=NULL,area_id=?,province_id=?,district_id=?,town_id=? WHERE id=?',[$title,$description,$address,$cat,$lat,$lng,$photo?:$oldPhoto,$area,$geo[0],$geo[1],$geo[2],$id]);
   geo_raw("UPDATE issue_flow SET location_state='pending',location_hash=NULL,version=version+1 WHERE issue_id=?",[$id]);event_add($id,'edited','Reporter updated the report details'.($photo?' and photo':'').'.');audit('issue.edited_by_reporter','Issue #'.$id);notify_issue($issue,'Reporter updated issue #'.$id);$issue['province_id']=$geo[0];geo_notify_admin($issue,'Updated report routed to your province: #'.$id);db()->commit();
  }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();remove_photo($photo);throw $ex;}
  if($photo)remove_photo($oldPhoto);$_SESSION['clear_report_draft']='edit-'.$id;flash('Your report has been updated.');go('index.php?page=issue&id='.$id);
 }
 if($action==='feedback') {
  $id=(int)($_POST['issue_id']??0);$rating=$_POST['rating']??'';$comment=field('feedback_comment',0,1000);
  if(!in_array($rating,['Satisfied','Partly satisfied','Not satisfied'],true))throw new RuntimeException('Choose a feedback rating.');
  db()->beginTransaction();$issue=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
  if(!$issue || user()['role']!=='citizen' || (int)$issue['user_id']!==(int)user()['id'] || $issue['status']!=='Resolved')throw new RuntimeException('Only the reporter can leave feedback on a resolved report.');
  q('INSERT INTO issue_feedback(issue_id,user_id,rating,comment) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comment=VALUES(comment),updated_at=NOW()',[$id,user()['id'],$rating,$comment]);
  event_add($id,'feedback','Reporter feedback: '.$rating.($comment!==''?'. '.$comment:''));audit('issue.feedback','Issue #'.$id.': '.$rating);
  if($issue['officer_id'])q('INSERT INTO notifications(user_id,issue_id,message) VALUES(?,?,?)',[$issue['officer_id'],$id,'Reporter left resolution feedback on issue #'.$id]);
  db()->commit();flash('Thank you. Your feedback has been saved.');go('index.php?page=issue&id='.$id);
 }
 if($action==='delete_issue') {
  if(user()['role']!=='citizen')throw new RuntimeException('Only citizens can use this delete action.');
  if(($_POST['confirm_delete']??'')!=='1')throw new RuntimeException('Confirm that you want to permanently delete this report.');
  $id=filter_var($_POST['issue_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$id)throw new RuntimeException('Invalid report ID.');
  try {
   db()->beginTransaction();
   // Lock the parent first, as status updates and duplicate linking do.
   // This also prevents new FK-linked comments/supports during the deletion.
   $issue=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$issue)throw new RuntimeException('This report no longer exists.');
   if((int)$issue['user_id']!==(int)user()['id'])throw new RuntimeException('You can delete only your own reports.');
   if($issue['status']!=='Submitted' || $issue['officer_id']!==null || $issue['duplicate_of']!==null)throw new RuntimeException('This report is already under review or assigned. Only unassigned Submitted reports can be deleted.');
   if(q('SELECT id FROM issues WHERE duplicate_of=? LIMIT 1',[$id])->fetch())throw new RuntimeException('Other reports are linked to this issue. Please ask an administrator to review it.');
   audit('issue.deleted_by_reporter','Issue #'.$id.' deleted by reporter. Title: '.$issue['title']);
   q('DELETE FROM notifications WHERE issue_id=?',[$id]);
   q('DELETE FROM supports WHERE issue_id=?',[$id]);
   q('DELETE FROM issue_events WHERE issue_id=?',[$id]);
   q('DELETE FROM issues WHERE id=?',[$id]);
   db()->commit();
  } catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
  // Remove files only after the database transaction succeeds.
  remove_photo($issue['photo']);remove_photo($issue['resolution_photo']);
  flash('Your report has been permanently deleted.');go('index.php?page=mine');
 }
 if($action==='create_issue') {
  require_role(['citizen','admin']);
  ops_require_verified();ops_rate_limit('report');if(($_POST['details_checked']??'')!=='1')throw new RuntimeException('Check the report details and photo before submitting.');
  $geo=geo_validate_location($_POST);if(user()['role']==='admin'&&!geo_system()&&!in_array($geo[0],geo_province_ids(),true))throw new RuntimeException('Choose your assigned province.');$title=field('title',8,160);$description=field('description',20,5000);$address=field('address',3,255);$cat=(int)($_POST['category_id']??0);$area=chosen_area($_POST['area_id']??null);
  if(!q('SELECT id FROM categories WHERE id=? AND active=1',[$cat])->fetch())throw new RuntimeException('Choose an active category.');
  $lat=$_POST['latitude']??'';$lng=$_POST['longitude']??'';
  if(!coords($lat,$lng))throw new RuntimeException('Select a location on the map or enter valid coordinates.');
  $photo=photo_upload('photo',true);
  try {db()->beginTransaction();q('INSERT INTO issues(user_id,category_id,title,description,address,latitude,longitude,photo,area_id,province_id,district_id,town_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[user()['id'],$cat,$title,$description,$address,$lat,$lng,$photo,$area,$geo[0],$geo[1],$geo[2]]);$id=db()->lastInsertId();followup_apply_target($id,$cat);event_add($id,'submitted','Report submitted.');audit('issue.created','Issue #'.$id);geo_notify_admin(['id'=>$id,'province_id'=>$geo[0]],'New report in your province: #'.$id);db()->commit();}
  catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();remove_photo($photo);throw $ex;}
  $_SESSION['clear_report_draft']='new';flash('Report submitted. Your community can now follow its progress.');go('index.php?page=issue&id='.$id);
 }
 if($action==='support' || $action==='comment' || $action==='update_issue' || $action==='reopen' || $action==='merge') {
  $photo=null;
  try {
   db()->beginTransaction();$id=(int)($_POST['issue_id']??0);$issue=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
   if(!$issue)throw new RuntimeException('Issue not found.');
   if($action==='support') {
    ops_rate_limit('support');
    if($issue['status']==='Duplicate')throw new RuntimeException('Support the linked original report instead.');
    if($issue['user_id']==user()['id'])throw new RuntimeException('You are already the reporter of this issue.');
    if(q('SELECT 1 FROM supports WHERE issue_id=? AND user_id=?',[$id,user()['id']])->fetch())q('DELETE FROM supports WHERE issue_id=? AND user_id=?',[$id,user()['id']]);
    else q('INSERT INTO supports(issue_id,user_id) VALUES(?,?)',[$id,user()['id']]);
   }
   if($action==='comment') {ops_require_verified();ops_rate_limit('comment');if($issue['status']==='Duplicate')throw new RuntimeException('Please comment on the linked original report.');$body=field('message',2,2000);event_add($id,'comment',$body);notify_issue($issue,'New comment on issue #'.$id);}
   if($action==='update_issue') {
    $admin=user()['role']==='admin';
    if(!$admin && !(user()['role']==='officer' && $issue['officer_id']==user()['id']))throw new RuntimeException('This issue is not assigned to you.');
    if($issue['status']==='Duplicate')throw new RuntimeException('Update the original report instead.');
    $status=(string)($_POST['status']??'');$note=field('note',3,2000);
    $next=staff_transitions($issue['status'],$admin);
    if(!$admin && !in_array($issue['status'],['Assigned','In Progress','Awaiting Approval'],true))throw new RuntimeException('This report is not open for officer updates.');
    if($status!==$issue['status'] && !in_array($status,$next,true))throw new RuntimeException('That status transition is not allowed. Completion must be submitted for admin approval.');
    if(!$admin && $status==='Resolved')throw new RuntimeException('Only an administrator can approve a resolution.');
    $officer=$issue['officer_id'];$priority=$issue['priority'];$area=$issue['area_id'];$due=$issue['due_date'];
    if($admin){
     $officer=array_key_exists('officer_id',$_POST)?((int)$_POST['officer_id']?:null):$issue['officer_id'];$priority=$_POST['priority']??$issue['priority'];
     $area=chosen_area($_POST['area_id']??$issue['area_id']);$due=valid_date(trim((string)($_POST['due_date']??$issue['due_date']??'')),'due');
     if(!in_array($priority,['Low','Medium','High'],true))throw new RuntimeException('Invalid priority.');
     if($officer && !q("SELECT id FROM users WHERE id=? AND role='officer' AND active=1 FOR UPDATE",[$officer])->fetch())throw new RuntimeException('Choose an active officer.');
     if($officer && ((int)$officer!==(int)$issue['officer_id'] || (int)$area!==(int)$issue['area_id']) && !officer_eligible($officer,$area,true))throw new RuntimeException('This officer does not cover the selected area. Choose a matching or unrestricted officer.');
    }
    if($admin && $status==='Verified' && $officer && array_key_exists('officer_id',$_POST))$status='Assigned';
    if(in_array($status,['Assigned','In Progress','Awaiting Approval','Resolved'],true) && !$officer)throw new RuntimeException('Assign an officer before using this status.');
    if($status==='Awaiting Approval' && $issue['maintenance_id'] && $issue['maintenance_stage']!=='Approved')throw new RuntimeException('Review the maintenance team completion before sending it for administrator approval.');
    $photo=photo_upload('resolution_photo',$status==='Awaiting Approval' && $issue['status']!=='Awaiting Approval');
    $evidence=$photo?:$issue['resolution_photo'];
    if($issue['status']==='Awaiting Approval' && $status==='In Progress'){archive_resolution_evidence($issue);$evidence=null;}
    if($status==='Resolved' && !$evidence)throw new RuntimeException('Completion evidence is required before approval.');
    if((int)$officer!==(int)$issue['officer_id'] || ($issue['status']==='Rejected' && in_array($status,['Verified','Assigned'],true)))maintenance_release($issue,'Maintenance assignment cleared for a new officer or a renewed review.');
    if($issue['maintenance_id'] && $issue['status']==='Awaiting Approval' && $status==='In Progress')q("UPDATE issues SET maintenance_stage='Officer Return',maintenance_photo=NULL,maintenance_note=?,maintenance_version=maintenance_version+1 WHERE id=?",[$note,$id]);
    if($status==='Rejected')q('UPDATE issues SET public_photo=NULL WHERE id=?',[$id]);
    q('UPDATE issues SET status=?,officer_id=?,priority=?,area_id=?,due_date=?,resolution_photo=?,resolved_at=? WHERE id=?',[$status,$officer,$priority,$area,$due,$evidence,$status==='Resolved'?($issue['resolved_at']?:date('Y-m-d H:i:s')):null,$id]);
    if($photo)q('INSERT INTO issue_progress_photos(issue_id,user_id,filename,note,phase) VALUES(?,?,?,?,?)',[$id,user()['id'],$photo,$note,$status==='Awaiting Approval'?'completion':'progress']);
    event_add($id,'status',$issue['status'].' → '.$status.'. Priority: '.$priority.'. Officer: '.($officer?'#'.$officer:'unassigned').'. Area: '.($area?'#'.$area:'unspecified').'. Due: '.($due?:'7-day attention indicator').'. '.$note);
    $issue['officer_id']=$officer;notify_issue($issue,'Issue #'.$id.' updated: '.$status);
    if($status==='Awaiting Approval' && $issue['status']!=='Awaiting Approval')notify_admins($issue,'Completion awaiting approval: issue #'.$id);
    audit($status==='Resolved' && $issue['status']!=='Resolved'?'issue.resolution_approved':'issue.updated','Issue #'.$id.' status '.$status);
   }
   if($action==='reopen') {
    if($issue['user_id']!=user()['id'] && user()['role']!=='admin')throw new RuntimeException('Only the reporter or admin can reopen an issue.');
    if($issue['status']!=='Resolved')throw new RuntimeException('Only resolved issues can be reopened.');
    $note=field('note',5,2000);maintenance_release($issue,'Maintenance assignment cleared because the report was reopened for review.');q('DELETE FROM issue_feedback WHERE issue_id=?',[$id]);q("UPDATE issues SET status='Verified',officer_id=NULL,resolved_at=NULL WHERE id=?",[$id]);geo_notify_admin($issue,'Report reopened in your province: #'.$id);event_add($id,'reopened',$note);notify_issue($issue,'Issue #'.$id.' reopened for review.');audit('issue.reopened','Issue #'.$id);
   }
   if($action==='merge') {
    if(user()['role']!=='admin')throw new RuntimeException('Admin access required.');
    $target=(int)($_POST['target_id']??0);
    $original=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$target])->fetch();
    if(!$original || $target===$id || $original['duplicate_of'] || $issue['duplicate_of'] || q('SELECT id FROM issues WHERE duplicate_of=? LIMIT 1',[$id])->fetch())throw new RuntimeException('Choose a different original issue. Reports with linked duplicates cannot be merged.');
    if(in_array($original['status'],['Rejected','Duplicate'],true) || $issue['category_id']!=$original['category_id'])throw new RuntimeException('The original must be an accepted report in the same category.');
    q("UPDATE issues SET status='Duplicate',public_photo=NULL,duplicate_of=? WHERE id=?",[$target,$id]);
    q('INSERT IGNORE INTO supports(issue_id,user_id) SELECT ?,user_id FROM supports WHERE issue_id=? AND user_id<>?',[$target,$id,$original['user_id']]);
    if($issue['user_id']!=$original['user_id'])q('INSERT IGNORE INTO supports(issue_id,user_id) VALUES(?,?)',[$target,$issue['user_id']]);
    event_add($id,'duplicate','Linked to original report #'.$target.'. '.field('note',3,2000));event_add($target,'duplicate','Report #'.$id.' linked as a duplicate.');notify_issue($issue,'Issue #'.$id.' linked to original #'.$target);audit('issue.merged','#'.$id.' → #'.$target);
   }
   db()->commit();
  } catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();remove_photo($photo);throw $ex;}
  flash('Changes saved.');go('index.php?page=issue&id='.$id);
 }
 if($action==='read_notifications') {q('UPDATE notifications SET is_read=1 WHERE user_id=?',[user()['id']]);flash('Notifications marked as read.');go('index.php?page=notifications');}
 if($action==='profile') {
  $name=field('name',2,100);$old=(string)($_POST['current_password']??'');
  if(!password_verify($old,user()['password']))throw new RuntimeException('Current password is incorrect.');
  $hash=user()['password'];if(!empty($_POST['password'])){if(!hash_equals((string)$_POST['password'],(string)($_POST['password_confirm']??'')))throw new RuntimeException('New passwords do not match.');$hash=password_hash(password_input(),PASSWORD_DEFAULT);}
  q('UPDATE users SET name=?,password=?,auth_version=auth_version+1 WHERE id=?',[$name,$hash,user()['id']]);$_SESSION['auth_version']=(int)user()['auth_version']+1;session_regenerate_id(true);flash('Profile updated.');go('index.php?page=profile');
 }
 require_role('admin');
 if($action==='create_user') {
  $name=field('name',2,100);$email=valid_email();$pass=password_input();$role=$_POST['role']??'';
  if(!in_array($role,['citizen','officer','admin','maintenance'],true))throw new RuntimeException('Invalid role.');
  if(q('SELECT id FROM users WHERE email=?',[$email])->fetch())throw new RuntimeException('Email is already registered.');
  db()->beginTransaction();q('INSERT INTO users(name,email,password,role) VALUES(?,?,?,?)',[$name,$email,password_hash($pass,PASSWORD_DEFAULT),$role]);audit('user.created',$email.' ('.$role.')');db()->commit();flash('User created.');go('index.php?page=users');
 }
 if($action==='toggle_user') {
  $uid=(int)($_POST['user_id']??0);if($uid==user()['id'])throw new RuntimeException('You cannot deactivate your own account.');
  db()->beginTransaction();$target=q('SELECT * FROM users WHERE id=? FOR UPDATE',[$uid])->fetch();
  if(!$target || $target['role']==='admin')throw new RuntimeException('Other administrator accounts cannot be disabled here.');
  if($target['active'] && q("SELECT id FROM issues WHERE maintenance_id=? AND status NOT IN ('Resolved','Rejected','Duplicate') LIMIT 1",[$uid])->fetch())throw new RuntimeException('Reassign this maintenance team’s open work before deactivating the account.');
  if($target['active'] && q("SELECT id FROM issues WHERE officer_id=? AND status IN ('Assigned','In Progress','Awaiting Approval') LIMIT 1",[$uid])->fetch())throw new RuntimeException('Reassign this officer’s open work before deactivating their account.');
  q('UPDATE users SET active=1-active WHERE id=?',[$uid]);audit('user.activation','User #'.$uid);db()->commit();flash('User access updated.');go('index.php?page=users');
 }
 if($action==='category') {$categoryName=field('name',2,80);if(q('SELECT id FROM categories WHERE name=?',[$categoryName])->fetch())throw new RuntimeException('That category already exists.');db()->beginTransaction();q('INSERT INTO categories(name) VALUES(?)',[$categoryName]);audit('category.created',$categoryName);db()->commit();flash('Category added.');go('index.php?page=users&tab=categories');}
 throw new RuntimeException('Unknown action.');
}
