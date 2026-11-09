<?php
require_role(['admin','officer']);$tab=(string)($_GET['tab']??'queues');if(!in_array($tab,['queues','teams','handover'],true))$tab='queues';layout_start('Workflow queues','Follow up location checks, blockers, acceptance and reopen requests.');?><div class="admin-workspace"><?php admin_tabs('flow',['queues'=>'Follow-up','teams'=>'Team categories','handover'=>'Absence / handover'],$tab);
if ($tab === 'teams'):
 $search = is_string($_GET['search'] ?? null) ? trim(substr($_GET['search'], 0, 160)) : '';
 $setup = $_GET['setup'] ?? '';
 $conditions = [
  'town' => 'NOT EXISTS (SELECT 1 FROM geo_towns t WHERE t.id=u.town_id AND t.active=1)',
  'categories' => 'NOT EXISTS (SELECT 1 FROM team_categories tc JOIN categories c ON c.id=tc.category_id WHERE tc.team_id=u.id AND c.active=1)',
 ];
 $w = "u.role='maintenance'";
 $params = [];
 if (is_string($setup) && isset($conditions[$setup])) {
  $w .= ' AND u.active=1 AND '.$conditions[$setup];
 } else {
  $setup = '';
 }
 if ($search !== '') {
  $w .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
  $params = ['%'.$search.'%', '%'.$search.'%'];
 }
 $total = (int)q('SELECT COUNT(*) FROM users u WHERE '.$w, $params)->fetchColumn();
 $pages = max(1, (int)ceil($total / 15));
 $pn = min($pages, max(1, (int)($_GET['p'] ?? 1)));
 $teams = q('SELECT u.* FROM users u WHERE '.$w.' ORDER BY u.name,u.id LIMIT 15 OFFSET '.(($pn-1)*15), $params)->fetchAll();
?>
<form method="get" class="panel row">
 <input type="hidden" name="page" value="flow">
 <input type="hidden" name="tab" value="teams">
 <label>Search teams<input name="search" maxlength="160" value="<?=e($search)?>"></label>
 <label>Setup status<select name="setup">
  <option value="">All teams</option>
  <option value="town" <?=$setup==='town'?'selected':''?>>Missing active town</option>
  <option value="categories" <?=$setup==='categories'?'selected':''?>>Missing active categories</option>
 </select></label>
 <button class="button secondary">Filter</button>
</form>
<?php if ($setup === 'town' && geo_system()): ?>
 <p><a class="text-link" href="index.php?page=routing&amp;tab=accounts&amp;mapping=town">Open town and team assignments →</a></p>
<?php endif; ?>
<?php if (!$teams): ?><p>No teams match these filters.</p><?php endif; ?><p>Configure actual capabilities before new dispatches. Existing work keeps its evidence and assignment.</p><?php foreach($teams as $t):$ids=array_map('intval',array_column(geo_raw('SELECT category_id FROM team_categories WHERE team_id=?',[$t['id']])->fetchAll(),'category_id'));?><details class="panel section-space" <?=$setup!==''?'open':''?>><summary><?=e($t['name'])?> · <?=count($ids)?> categories</summary><?php if ($setup === 'town' && geo_system()): ?>
 <p><a class="text-link" href="index.php?page=routing&amp;tab=accounts&amp;mapping=town&amp;user_id=<?=(int)$t['id']?>">Assign an active town →</a></p>
<?php endif; ?><p><?=e(admin_account_scope($t))?></p><form method="post"><?php csrf();?><input type="hidden" name="action" value="flow_team_categories"><input type="hidden" name="team_id" value="<?=(int)$t['id']?>"><?php foreach(categories() as $c):if(empty($c['active'])&&!in_array((int)$c['id'],$ids,true))continue;?><label class="check-label"><input type="checkbox" name="category_ids[]" value="<?=(int)$c['id']?>" <?=in_array((int)$c['id'],$ids)?'checked':''?>> <?=e($c['name'])?></label><?php endforeach;?><button class="button secondary">Save categories</button></form></details><?php endforeach;admin_pager($pn,$pages,$total);
elseif($tab==='handover'):?><section class="panel"><h2>One accountable owner</h2><?php if(geo_system()):?><p><a class="text-link" href="index.php?page=readiness&amp;tab=cover">Manage temporary cover →</a></p><?php endif;?><p>Use Province routing → Scoped accounts for a confirmed handover with a reason and explicit transfer of open work. Evidence and team assignments remain.</p><p>For temporary cover, ask the System Admin to use Workflow readiness → Temporary cover. The scheduled runner attempts handback after its end time and pauses conflicting cases for review. Ordinary permanent mappings do not expire; they still require explicit handover.</p><a class="text-link" href="index.php?page=routing&amp;tab=accounts">Open responsibility mapping →</a></section><?php
else:$queue=(string)($_GET['queue']??'location');$choices=['location'=>['Location review',"i.status IN ('Submitted','Verified') AND (f.location_state IS NULL OR f.location_state<>'reviewed')"],'blocked'=>['Blocked work',"i.status='In Progress' AND i.maintenance_stage='Blocked'"],'returned'=>['Province returned',"i.status='In Progress' AND i.maintenance_stage='Officer Return'"],'reopen'=>['Reopen requests',"i.status='Resolved' AND f.reopen_state='pending'"],'accept'=>['Officer acceptance',"i.status IN ('Assigned','In Progress') AND (f.accepted_by IS NULL OR f.accepted_by<>i.officer_id)"]];if(!isset($choices[$queue]))$queue='location';$sql=' LEFT JOIN issue_flow f ON f.issue_id=i.id WHERE '.$choices[$queue][1];$total=(int)q('SELECT COUNT(*) FROM issues i'.$sql)->fetchColumn();$pages=max(1,(int)ceil($total/20));$pn=min($pages,max(1,(int)($_GET['p']??1)));$rows=q(issue_query().$sql.' ORDER BY i.created_at,i.id LIMIT 20 OFFSET '.(($pn-1)*20))->fetchAll();?><nav class="report-tabs"><?php foreach($choices as $key=>$choice):?><a class="report-tab <?=$queue===$key?'selected':''?>" href="index.php?page=flow&amp;queue=<?=$key?>"><?=e($choice[0])?></a><?php endforeach;?></nav><section class="panel"><h2><?=$total?> · <?=e($choices[$queue][0])?></h2><?php if(!$rows):?><p>No reports in this queue.</p><?php endif;foreach($rows as $i):?><div class="dashboard-health-row"><span>#<?=(int)$i['id']?> · <?=e($i['title'])?><small><?=e($i['province_name'].' / '.$i['district_name'].' / '.$i['town_name'])?></small></span><a class="button secondary" href="index.php?page=issue&amp;id=<?=(int)$i['id']?>#workflow">Review →</a></div><?php endforeach;admin_pager($pn,$pages,$total);?></section><?php endif;?></div><?php layout_end();
