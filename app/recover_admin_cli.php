<?php
// Offline recovery for a filesystem administrator. Never available over HTTP.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(empty($argv[1])||!filter_var($argv[1],FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php app/recover_admin_cli.php admin-email@example.com\n");exit(1);}
require __DIR__.'/bootstrap.php';
try{
 ops_write_lock();db()->beginTransaction();$u=q("SELECT id,email FROM users WHERE email=? AND role='admin' AND active=1 FOR UPDATE",[strtolower($argv[1])])->fetch();if(!$u)throw new RuntimeException('No active administrator has that email.');
 $raw=strtoupper(bin2hex(random_bytes(16)));q('DELETE FROM admin_reset_tokens WHERE user_id=?',[$u['id']]);q('INSERT INTO admin_reset_tokens(user_id,issued_by,code_hash,reason,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))',[$u['id'],$u['id'],hash('sha256',$raw),'Offline filesystem administrator recovery']);q('INSERT INTO audit_log(actor_id,action,details) VALUES(?,?,?)',[$u['id'],'account.offline_admin_recovery','Local CLI issued a one-use 30-minute reset code.']);db()->commit();
 echo "Use Forgot password with this email and code. The code expires in 30 minutes and is shown once.\n".$u['email']."\n".implode('-',str_split($raw,4))."\nKeep this code private. No password was changed by this command.\n";
}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();fwrite(STDERR,"Administrator recovery could not be issued. Check the account email and database configuration.\n");exit(1);}
