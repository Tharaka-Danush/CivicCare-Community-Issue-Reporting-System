<?php
function handle_operations_action($action){
 if($action==='open_notification'){
  require_login();$id=(int)($_POST['notification_id']??0);$n=q('SELECT issue_id FROM notifications WHERE id=? AND user_id=?',[$id,user()['id']])->fetch();if(!$n)throw new RuntimeException('Notification not found.');q('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?',[$id,user()['id']]);if(user()['role']==='maintenance'){
   $work=q('SELECT id FROM issues WHERE id=? AND maintenance_id=?',[$n['issue_id'],user()['id']])->fetchColumn();
   if(!$work){flash('This update is for work no longer assigned to your team.','info');go('index.php?page=notifications');}
   go('index.php?page=team_work&id='.$work);
  }
  go('index.php?page=issue&id='.$n['issue_id']);
 }
 if($action==='issue_admin_reset'){
  ops_admin_password();if(($_POST['identity_confirmed']??'')!=='1')throw new RuntimeException('Confirm that you have checked the account holder’s identity.');$email=valid_email();$reason=field('identity_note',10,1000);
  db()->beginTransaction();$target=q("SELECT id,email FROM users WHERE email=? AND role IN ('citizen','officer','maintenance') AND active=1 FOR UPDATE",[$email])->fetch();if(!$target)throw new RuntimeException('Choose an active citizen, officer or maintenance account. Administrator accounts use their own recovery codes.');
  $raw=strtoupper(bin2hex(random_bytes(16)));q('DELETE FROM admin_reset_tokens WHERE user_id=?',[$target['id']]);q('INSERT INTO admin_reset_tokens(user_id,issued_by,code_hash,reason,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$target['id'],user()['id'],hash('sha256',$raw),$reason]);audit('account.admin_reset_issued','Account #'.$target['id'].'. Identity check: '.$reason);db()->commit();
  $_SESSION['admin_issued_reset']=['email'=>$target['email'],'code'=>implode('-',str_split($raw,4)),'expires'=>date('Y-m-d H:i:s',time()+1800)];go('index.php?page=system&tab=recovery');
 }
 if($action==='request_verification'){
  require_login();if(user()['email_verified_at']){flash('Your email is already verified.');go('index.php?page=profile');}ops_rate_limit('email');
  try{db()->beginTransaction();q('SELECT id FROM users WHERE id=? FOR UPDATE',[user()['id']]);ops_verification_email(user()['id'],user()['email']);audit('account.verification_requested','Verification email requested.');db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  flash('Verification email accepted by the mail server. Check your inbox and spam folder.');go('index.php?page=profile');
 }
 if($action==='verify_email'){
  $raw=(string)($_POST['verification_token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$raw))throw new RuntimeException('This verification link is invalid or expired.');
  db()->beginTransaction();$row=q('SELECT v.*,u.active FROM email_verifications v JOIN users u ON u.id=v.user_id WHERE v.token_hash=? AND v.used_at IS NULL AND v.expires_at>NOW() AND u.active=1 FOR UPDATE',[hash('sha256',$raw)])->fetch();if(!$row)throw new RuntimeException('This verification link is invalid or expired.');
  q('UPDATE users SET email_verified_at=NOW() WHERE id=?',[$row['user_id']]);q('UPDATE email_verifications SET used_at=NOW() WHERE id=?',[$row['id']]);q('INSERT INTO audit_log(actor_id,action,details) VALUES(?,?,?)',[$row['user_id'],'account.email_verified','Email link verified.']);db()->commit();flash('Your email has been verified. You can now sign in and submit reports.');go('index.php?page=login');
 }
 if($action==='download_backup'){
  ops_admin_password();$path=ops_create_backup();register_shutdown_function(function()use($path){if(is_file($path))@unlink($path);});
  try{audit('system.backup_created','Database and referenced JPEG photos exported.');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="civiccare-backup-'.date('Ymd-His').'.zip"');header('Content-Length: '.filesize($path));session_write_close();readfile($path);}finally{@unlink($path);}exit;
 }
 if($action==='restore_backup'){
  ops_admin_password();if(($_POST['confirm_restore']??'')!=='RESTORE')throw new RuntimeException('Type RESTORE to confirm replacing the current database with this backup.');$f=$_FILES['backup_file']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)throw new RuntimeException('Backup upload failed. Check PHP upload_max_filesize and post_max_size on System health.');
  ops_restore_backup($f['tmp_name']);$_SESSION=[];session_regenerate_id(true);flash('Backup restored. All sessions and recovery/verification tokens were invalidated. Sign in with your current administrator password.');go('index.php?page=login');
 }
}
