<?php
$roles = management_roles();
if ($page === 'management_setup') {
 geo_system_required();
 $accounts = geo_raw("SELECT id,name,email,role,active,management_role FROM users WHERE is_system_admin=0 AND role IN ('admin','officer','maintenance') ORDER BY name,id")->fetchAll();
 layout_start('Team admin roles','Grant or remove nationwide responsibility for a single type of staff account.');
?>
<div class="admin-workspace">
 <section class="panel">
  <h2>Assign an existing account</h2>
  <p>Choose a maintenance account for Maintenance Team Admin, an officer for District Officer Admin, or a Province Admin for Province Team Admin.</p>
  <form method="post" data-confirm="Save this team administration role? The account holder must sign in again.">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="management_grant">
   <div class="two-col">
    <label>Staff account<select name="user_id" required><option value="">Choose account</option>
     <?php foreach ($accounts as $account): ?>
      <option value="<?=(int)$account['id']?>"><?=e($account['name'].' · '.$account['email'].' · '.$account['role'].($account['management_role'] ? ' · '.$roles[$account['management_role']][0] : '').(!$account['active'] ? ' · Disabled' : ''))?></option>
     <?php endforeach; ?>
    </select></label>
    <label>Administration role<select name="management_role" required><option value="">Choose role</option>
     <?php foreach ($roles as $key=>$role): ?><option value="<?=e($key)?>"><?=e($role[0])?></option><?php endforeach; ?>
     <option value="remove">Remove team administration access</option>
    </select></label>
   </div>
   <label>Your System Admin password<input type="password" name="admin_password" autocomplete="current-password" required></label>
   <button class="button">Save role</button>
  </form>
 </section>
 <section class="panel section-space">
  <h2>Create a Province Team Admin</h2>
  <form method="post">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="management_create_province_lead">
   <div class="two-col">
    <label>Name<input name="name" minlength="2" maxlength="100" required></label>
    <label>Email<input name="email" type="email" maxlength="190" required></label>
    <label>Temporary password<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
    <label>Your System Admin password<input name="admin_password" type="password" autocomplete="current-password" required></label>
   </div>
   <button class="button">Create Province Team Admin</button>
  </form>
 </section>
</div>
<?php
 layout_end();
 return;
}

$kind = management_kind(user());
if (!$kind) {
 http_response_code(403);
 exit('Team administration access is required.');
}
[$title,$baseRole,$group] = $roles[$kind];
$accounts = geo_raw('SELECT id,name,email,role,active,town_id,is_system_admin FROM users WHERE role=? AND is_system_admin=0 ORDER BY name,id', [$baseRole])->fetchAll();
$tables = [
 'town' => 'SELECT t.id,CONCAT(p.name," / ",d.name," / ",t.name) name,NULL owner FROM geo_towns t JOIN geo_districts d ON d.id=t.district_id JOIN geo_provinces p ON p.id=d.province_id WHERE t.active=1 ORDER BY name',
 'district' => 'SELECT d.id,CONCAT(p.name," / ",d.name) name,u.name owner,u.active owner_active FROM geo_districts d JOIN geo_provinces p ON p.id=d.province_id LEFT JOIN users u ON u.id=d.officer_id AND u.role="officer" ORDER BY name',
 'province' => 'SELECT p.id,p.name,u.name owner,u.active owner_active FROM geo_provinces p LEFT JOIN users u ON u.id=p.admin_id AND u.role="admin" AND u.is_system_admin=0 ORDER BY p.name',
];
$targets = geo_raw($tables[$kind])->fetchAll();
$teamId = $kind === 'town' ? max(0,(int)($_GET['team'] ?? 0)) : 0;
$team = null;
foreach ($accounts as $account) {
 if ((int)$account['id'] === $teamId) $team = $account;
}
$gaps=[];
if ($kind === 'town') {
 $townGaps=geo_raw("SELECT u.id,u.name,(t.id IS NULL) needs_town,
  NOT EXISTS(SELECT 1 FROM team_categories tc JOIN categories c ON c.id=tc.category_id AND c.active=1 WHERE tc.team_id=u.id) needs_categories
  FROM users u LEFT JOIN geo_towns t ON t.id=u.town_id AND t.active=1
  WHERE u.role='maintenance' AND u.active=1 AND (u.management_role IS NULL OR u.town_id IS NOT NULL)
  ORDER BY u.name,u.id")->fetchAll();
 foreach ($townGaps as $gap) {
  if ($gap['needs_town']) $gaps[] = [$gap['name'],'Assign an active town','#map-account'];
  if ($gap['needs_categories']) $gaps[] = [$gap['name'],'Configure active service categories','?page=management&team='.(int)$gap['id'].'#team-categories'];
 }
 $townGapCount=count(array_filter($townGaps,function($gap){return $gap['needs_town'];}));
 $categoryGapCount=count(array_filter($townGaps,function($gap){return $gap['needs_categories'];}));
 $coverageSummary=$townGapCount.' active team(s) missing a town · '.$categoryGapCount.' active team(s) missing service categories';
} else {
 foreach ($targets as $target) {
  if (empty($target['owner_active'])) $gaps[]=[$target['name'],'Map an active account','#map-account'];
 }
 $coverageSummary=count($gaps).' '.($kind==='province'?'province(s)':'district(s)').' without an active responsible account';
}
layout_start($title,'Manage '.$group.' across Sri Lanka.');
?>
<div class="admin-workspace">
 <section class="panel">
  <h2>Coverage summary</h2>
  <p><?=e($coverageSummary)?></p>
  <?php if ($gaps): ?>
   <details><summary>View gaps and next actions</summary>
    <div class="table-wrap"><table><thead><tr><th>Account / Area</th><th>Next action</th></tr></thead><tbody>
    <?php foreach($gaps as [$name,$action,$link]): ?>
     <tr><td><?=e($name)?></td><td><a class="text-link" href="<?=e($link)?>"><?=e($action)?> →</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
   </details>
  <?php endif; ?>
 </section>

 <?php if ($team): ?>
 <section class="panel section-space" id="team-categories">
  <h2>Service categories for <?=e($team['name'])?></h2>
  <?php $selected = array_map('intval',geo_raw('SELECT category_id FROM team_categories WHERE team_id=?',[$teamId])->fetchAll(PDO::FETCH_COLUMN)); ?>
  <form method="post">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="management_categories">
   <input type="hidden" name="team_id" value="<?=$teamId?>">
   <?php foreach (categories() as $category): $checked = in_array((int)$category['id'],$selected,true); if (!$checked && !$category['active']) continue; ?>
    <label class="check-label"><input type="checkbox" name="category_ids[]" value="<?=(int)$category['id']?>" <?=$checked?'checked':''?>> <?=e($category['name'].(!$category['active']?' (archived)':''))?></label>
   <?php endforeach; ?>
   <button class="button">Save service categories</button>
  </form>
 </section>
 <?php endif; ?>

 <details class="panel section-space" id="map-account" <?=$gaps?'open':''?>>
  <summary>Map an existing account</summary>
  <form method="post" data-confirm="Save this routing change? Check the current holder before replacing responsibility.">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="management_map">
   <input type="hidden" name="mapping_kind" value="<?=e($kind)?>">
   <div class="two-col">
    <label>Account<select name="user_id" required><option value="">Choose account</option>
     <?php foreach ($accounts as $account): if (!$account['active'] || (int)$account['id']===(int)user()['id']) continue; ?>
      <option value="<?=(int)$account['id']?>"><?=e($account['name'].' · '.$account['email'])?></option>
     <?php endforeach; ?>
    </select></label>
    <label>Assigned <?=e($kind)?><select name="target_id" required><option value="">Choose <?=e($kind)?></option>
     <?php foreach ($targets as $target): ?>
      <option value="<?=(int)$target['id']?>"><?=e($target['name'].($target['owner']?' · Current: '.$target['owner']:''))?></option>
     <?php endforeach; ?>
    </select></label>
   </div>
   <?php if ($kind === 'district'): ?>
    <label class="check-label"><input type="checkbox" name="transfer_open" value="1"> Transfer current open work if replacing an officer.</label>
    <label>Handover reason (required for open work transfer)<textarea name="handover_note" minlength="5" maxlength="2000"></textarea></label>
   <?php endif; ?>
   <label>Reason for changing responsibility<textarea name="change_reason" minlength="5" maxlength="2000" required></textarea></label>
   <button class="button">Save mapping</button>
  </form>
 </details>

 <details class="panel section-space">
  <summary>Create an account</summary>
  <form method="post">
   <?php csrf(); ?>
   <input type="hidden" name="action" value="management_create">
   <input type="hidden" name="account_kind" value="<?=e($kind)?>" data-geo-account-kind>
   <div class="two-col">
    <label>Name<input name="name" minlength="2" maxlength="100" required></label>
    <label>Email<input name="email" type="email" maxlength="190" required></label>
    <label>Temporary password<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
   </div>
   <?php geo_location_selector([],false); ?>
   <button class="button">Create scoped account</button>
  </form>
 </details>
 <?php if ($kind !== 'town'): ?>
 <section class="panel section-space">
  <h2>Responsibility by <?=e($kind)?></h2>
  <div class="table-wrap"><table>
   <thead><tr><th><?=e(ucfirst($kind))?></th><th>Account</th></tr></thead>
   <tbody><?php foreach ($targets as $target): ?>
    <tr><td><?=e($target['name'])?></td><td><?=e($target['owner'] ? $target['owner'].(empty($target['owner_active'])?' · Disabled':'') : 'Unassigned')?></td></tr>
   <?php endforeach; ?></tbody>
  </table></div>
 </section>
 <?php endif; ?>
 <section class="panel section-space">
  <h2><?=e($group)?></h2>
  <div class="table-wrap"><table>
   <thead><tr><th>Account</th><th>Current responsibility</th><th>Access</th><?php if($kind==='town'):?><th>Services</th><?php endif;?></tr></thead>
   <tbody><?php foreach ($accounts as $account): ?>
    <tr>
     <td><?=e($account['name'])?><br><small><?=e($account['email'])?></small></td>
     <td><?=e(admin_account_scope($account))?></td>
     <td><?=e($account['active'] ? 'Active' : 'Disabled')?></td>
     <?php if ($kind === 'town'): ?><td><a class="text-link" href="?page=management&amp;team=<?=(int)$account['id']?>#team-categories">Manage service categories →</a></td><?php endif; ?>
    </tr>
   <?php endforeach; ?></tbody>
  </table></div>
  <?php if (!$accounts): ?><p>No accounts in this group.</p><?php endif; ?>
 </section>

</div>
<?php layout_end(); ?>
