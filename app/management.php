<?php
function management_roles() {
 return [
  'town' => ['Maintenance Team Admin', 'maintenance', 'Maintenance Teams'],
  'district' => ['District Officer Admin', 'officer', 'District Officers'],
  'province' => ['Province Team Admin', 'admin', 'Province Admins'],
 ];
}

function management_kind($account) {
 $kind = $account['management_role'] ?? '';
 $roles = management_roles();
 return isset($roles[$kind]) && $roles[$kind][1] === $account['role']
  && empty($account['is_system_admin']) ? $kind : '';
}

function management_allows($kind) {
 return user() && $kind !== '' && management_kind(user()) === $kind;
}

function management_without_town() {
 return management_allows('town') && !team_scope();
}

function management_without_district() {
 static $unmapped;
 if ($unmapped === null) {
  $unmapped = management_allows('district') && !geo_district_ids();
 }
 return $unmapped;
}

function management_without_province() {
 static $unmapped;
 if ($unmapped === null) {
  $unmapped = management_allows('province') && !geo_province_ids();
 }
 return $unmapped;
}

function management_home($account) {
 return management_kind($account) ? 'management' : maintenance_home($account['role']);
}

function management_action($action) {
 if (strpos($action, 'management_') !== 0) return;
 require_login();

 if ($action === 'management_create' || $action === 'management_map') {
  geo_action($action === 'management_create' ? 'geo_create_account' : 'geo_map_account', true);
  return;
 }
 if ($action === 'management_categories') {
  flow_action('flow_team_categories', true);
  return;
 }
 if ($action !== 'management_grant' && $action !== 'management_create_province_lead') {
  throw new RuntimeException('Unknown team administration action.');
 }
 geo_system_required();
 ops_admin_password();
 db()->beginTransaction();

 if ($action === 'management_create_province_lead') {
  $name = field('name', 2, 100);
  $email = valid_email();
  if (geo_raw('SELECT id FROM users WHERE email=?', [$email])->fetchColumn()) {
   throw new RuntimeException('This email already exists. Use the role assignment form to grant access to an existing Province Admin.');
  }
  $hash = password_hash(password_input(), PASSWORD_DEFAULT);
  q("INSERT INTO users (name,email,password,role,is_system_admin,management_role) VALUES (?,?,?,'admin',0,'province')", [$name,$email,$hash]);
  $uid = (int)db()->lastInsertId();
  audit('management.account_created', 'Province Team Admin account #'.$uid.' created.');
 } else {
  $uid = (int)($_POST['user_id'] ?? 0);
  $kind = $_POST['management_role'] ?? '';
  $roles = management_roles();
  if ($kind === 'remove') $kind = '';
  if (!is_string($kind) || ($kind !== '' && !isset($roles[$kind]))) {
   throw new RuntimeException('Choose a valid team administration role.');
  }
  $account = geo_raw('SELECT * FROM users WHERE id=? FOR UPDATE', [$uid])->fetch();
  if (!$account || $account['is_system_admin'] || ($kind !== '' && (!$account['active'] || $roles[$kind][1] !== $account['role']))) {
   throw new RuntimeException('Choose an active account with the matching staff role. System Admin accounts are excluded.');
  }
  q('UPDATE users SET management_role=?,auth_version=auth_version+1 WHERE id=?', [$kind === '' ? null : $kind,$uid]);
  audit('management.role_changed', 'Account #'.$uid.' team administration role: '.($kind ?: 'removed'));
 }

 db()->commit();
 flash('Team administration access saved. Existing account holders must sign in again.');
 go('index.php?page=management_setup');
}
