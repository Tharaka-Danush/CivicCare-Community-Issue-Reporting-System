<?php
function handle_admin_workspace_action($action){
 if($action==='change_password'){
  require_login();$password_account=user();if(!password_verify((string)($_POST['current_password']??''),user()['password']))throw new RuntimeException('Current password is incorrect.');$password=password_input();if(!hash_equals($password,(string)($_POST['password_confirm']??'')))throw new RuntimeException('New passwords do not match.');
  db()->beginTransaction();q('UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),user()['id']]);audit('account.password_changed','Password changed; other sessions invalidated.');db()->commit();$_SESSION['auth_version']=(int)user()['auth_version']+1;session_regenerate_id(true);flash('Password changed.');go('index.php?page=profile'.($password_account['role']==='maintenance'?'&tab=security':''));
 }
 if(!in_array($action,['admin_review_issue','admin_edit_user'],true))return;require_role('admin');
 if($action==='admin_edit_user'){
  $name=field('name',2,100);$uid=(int)($_POST['user_id']??0);if(!q('SELECT id FROM users WHERE id=?',[$uid])->fetch())throw new RuntimeException('Account not found.');db()->beginTransaction();q('UPDATE users SET name=? WHERE id=?',[$name,$uid]);audit('user.updated','Account #'.$uid.' name updated.');db()->commit();flash('Account name updated.');go('index.php?page=users');
 }
 $id=(int)($_POST['issue_id']??0);$note=field('note',3,2000);$decision=(string)($_POST['decision']??'');if(!in_array($decision,['Verified','Rejected'],true))throw new RuntimeException('Choose a valid review decision.');db()->beginTransaction();$i=q('SELECT * FROM issues WHERE id=? FOR UPDATE',[$id])->fetch();
 if(!$i||$i['status']!=='Submitted'||$i['duplicate_of'])throw new RuntimeException('Only a Submitted original report can be reviewed here.');if(!hash_equals(assignment_revision($i),(string)($_POST['previous_revision']??'')))throw new RuntimeException('This report changed. Refresh before reviewing.');maintenance_release($i);if($decision==='Rejected')q('UPDATE issues SET public_photo=NULL WHERE id=?',[$id]);q('UPDATE issues SET status=?,officer_id=NULL WHERE id=?',[$decision,$id]);event_add($id,'review','Submitted → '.$decision.'. '.$note);audit('issue.reviewed','Issue #'.$id.' '.$decision);notify_issue($i,'Report #'.$id.' reviewed: '.$decision);db()->commit();flash('Report reviewed.');go('index.php?page=assignments&view='.($decision==='Verified'?'ready':'review').'&id='.$id);
}
