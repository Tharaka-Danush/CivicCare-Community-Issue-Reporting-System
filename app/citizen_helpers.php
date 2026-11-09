<?php
function editable_report($issue) {
 return user()['role']==='citizen' && (int)$issue['user_id']===(int)user()['id'] && $issue['status']==='Submitted' && $issue['officer_id']===null && !$issue['duplicate_of'] && !q('SELECT id FROM issues WHERE duplicate_of=? LIMIT 1',[$issue['id']])->fetch();
}
function recovery_batch($uid) {
 q('DELETE FROM recovery_codes WHERE user_id=?',[$uid]);$codes=[];
 for($i=0;$i<6;$i++){$raw=strtoupper(bin2hex(random_bytes(16)));q('INSERT INTO recovery_codes(user_id,code_hash) VALUES(?,?)',[$uid,hash('sha256',$raw)]);$codes[]=implode('-',str_split($raw,4));}
 return $codes;
}
function recovery_page() {
 head_html('Reset your password');?><main class="setup-card"><a class="brand" href="index.php">CivicCare</a><h1 style="margin-top:25px">Reset your password</h1><p class="muted">Use a saved single-use recovery code or a valid 30-minute reset code issued by an administrator after checking your identity.</p><?php show_flash();?><form method="post"><?php csrf();?><input type="hidden" name="action" value="recover_password"><label>Account email<input type="email" name="email" maxlength="190" autocomplete="email" required></label><label>Recovery / administrator reset code<input name="recovery_code" maxlength="60" autocomplete="off" spellcheck="false" required placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX"></label><label>New password<input type="password" name="password" minlength="10" maxlength="72" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="password_confirm" minlength="10" maxlength="72" autocomplete="new-password" required></label><button class="button full">Reset password</button></form><p><a class="text-link" href="index.php?page=login">Back to sign in</a></p></main></body></html><?php
}
