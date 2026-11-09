<?php
// Copy to operations_config.php. Keep this file private; do not share SMTP passwords.
return [
 'email_verification_required' => false, // Enable only after SMTP and app_url are configured/tested.
 'app_url' => 'http://localhost/community_issue_system', // Use your HTTPS origin in production.
 'mail' => [
  'host' => '', 'port' => 587, 'encryption' => 'tls',
  'username' => '', 'password' => '',
  'from_email' => '', 'from_name' => 'CivicCare',
 ],
 'backup_max_bytes' => 100 * 1024 * 1024,
 'backup_max_uncompressed_bytes' => 300 * 1024 * 1024,
 // Optional overrides use [maximum attempts, window seconds].
 'rate_limits' => [
  'report' => [5,900], 'comment' => [10,60], 'edit' => [10,60],
  'support' => [30,60], 'register' => [5,3600],
  'email' => [3,3600], 'admin_reauth' => [10,900], 'team_work' => [10,60],
 ],
];
