<?php
// Shared presentation helpers; all mutations retain role, CSRF and write-lock checks.
function handle_professional_action($action){
 if(!in_array($action,['category_rename','category_access'],true))return;
 require_role('admin');$id=(int)($_POST['category_id']??0);
 db()->beginTransaction();$rows=q('SELECT * FROM categories ORDER BY id FOR UPDATE')->fetchAll();$current=null;
 foreach($rows as $row)if((int)$row['id']===$id)$current=$row;
 if(!$current)throw new RuntimeException('Category not found.');
 if(!hash_equals((string)$current['name'],(string)($_POST['previous_name']??'')) || (string)$current['active']!==(string)($_POST['previous_active']??''))throw new RuntimeException('This category changed. Refresh before saving.');
 if($action==='category_rename'){
  $name=field('name',2,80);if(q('SELECT id FROM categories WHERE name=? AND id<>?',[$name,$id])->fetch())throw new RuntimeException('That category name already exists, including archived categories.');
  q('UPDATE categories SET name=? WHERE id=?',[$name,$id]);audit('category.renamed','Category #'.$id.': '.$current['name'].' → '.$name);$message='Category renamed. Existing reports now show the new label.';
 }else{
  $next=$current['active']?0:1;
  if(!$next && count(array_filter($rows,function($r){return (int)$r['active']===1;}))<=1)throw new RuntimeException('Keep at least one active category for new reports.');
  q('UPDATE categories SET active=? WHERE id=?',[$next,$id]);audit('category.activation','Category #'.$id.' '.$current['name'].': '.($next?'restored':'archived'));$message=$next?'Category restored for new reports.':'Category archived. Existing reports and filters remain available.';
 }
 db()->commit();flash($message);go('index.php?page=users&tab=categories');
}
function workspace_filter_chips($values){
 $labels=['search'=>'Search','from'=>'From','to'=>'To','category'=>'Category','area'=>'Area','status'=>'Status','priority'=>'Priority','officer'=>'Officer','team'=>'Team','team_stage'=>'Team stage','view'=>'Queue','province'=>'Province','district'=>'District','town'=>'Town','scope'=>'Scope','tab'=>'Status group'];$chips=[];
 foreach($labels as $key=>$label){$value=$values[$key]??'';if($value===null||$value===''||$value===0||$value==='0'||in_array((string)$value,['all'],true))continue;
  $display=(string)$value;
  if(in_array($key,['category','area','officer','team','province','district','town'],true)){$table=['category'=>'categories','area'=>'service_areas','province'=>'geo_provinces','district'=>'geo_districts','town'=>'geo_towns'][$key]??'users';$role_sql=$table==='users'?" AND role='".($key==='team'?'maintenance':'officer')."'":'';$display=q('SELECT name FROM '.$table.' WHERE id=?'.$role_sql,[(int)$value])->fetchColumn()?:'Unknown';}
  if($key==='view')$display=['unassigned'=>'Unassigned','overdue'=>'Overdue','high'=>'High priority','approval'=>'Awaiting approval'][$display]??$display;
  $query=$_GET;unset($query[$key],$query['p']);$chips[]=[$label,$display,'?'.http_build_query($query)];
 }
 if(!$chips)return;
 ?><nav class="workspace-filter-chips" aria-label="Applied filters"><?php foreach($chips as $chip):?><a href="<?=e($chip[2])?>" aria-label="<?=e('Remove '.$chip[0].' filter: '.$chip[1])?>"><?=e($chip[0])?>: <strong><?=e($chip[1])?></strong> <span aria-hidden="true">×</span></a><?php endforeach;?></nav><?php
}
function workspace_date_shortcuts(){
 ?><nav class="workspace-date-shortcuts" aria-label="Report date shortcuts"><span>Reported:</span><?php foreach(['today'=>'Today','week'=>'Last 7 days','month'=>'This month'] as $key=>$label):$query=$_GET;$query['from']=$key==='today'?date('Y-m-d'):($key==='week'?date('Y-m-d',strtotime('-6 days')):date('Y-m-01'));$query['to']=date('Y-m-d');unset($query['p']);?><a class="text-link" href="?<?=e(http_build_query($query))?>"><?=$label?></a><?php endforeach;$query=$_GET;unset($query['from'],$query['to'],$query['p']);?><a class="text-link" href="?<?=e(http_build_query($query))?>">All dates</a></nav><?php
}
function workspace_status_help(){
 ?><details class="workspace-status-help"><summary>What do report statuses mean?</summary><dl><?php foreach(['Submitted'=>'Waiting for an administrator to review the report.','Verified'=>'Accepted after review; assignment may still need confirmation.','Assigned'=>'An officer has responsibility for the report.','In Progress'=>'Work is underway.','Awaiting Approval'=>'Completion evidence is waiting for final administrator approval.','Resolved'=>'An administrator approved the completed work.','Rejected'=>'The report was not accepted. See the review reason in its history.','Duplicate'=>'Linked to an existing report; follow the original for updates.'] as $status=>$text):?><div><dt><?=badge($status)?></dt><dd><?=e($text)?></dd></div><?php endforeach;?></dl></details><?php
}
function workspace_event_label($kind){return ['routing'=>'Province / district / town routing','submitted'=>'Report submitted','review'=>'Administrator review','status'=>'Status / assignment update','edited'=>'Report details edited','comment'=>'Community comment','progress'=>'Work progress','resolution'=>'Completion review','feedback'=>'Resolution feedback','reopened'=>'Report reopened','duplicate'=>'Duplicate link','maintenance'=>'Maintenance update','team'=>'Maintenance update'][$kind]??ucwords(str_replace('_',' ',$kind));}
function workspace_report_list($rows){
 ?><div class="panel no-pad"><div class="table-wrap"><table><thead><tr><th>Report / location</th><th>Status</th><th>Officer</th><th>Last updated</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $i):?><tr><td><a class="table-title" href="index.php?page=issue&amp;id=<?=(int)$i['id']?>">#<?=(int)$i['id']?> · <?=e($i['title'])?></a><small><?=e($i['category'])?> · <?=e($i['address'])?></small></td><td><?=badge($i['status'])?></td><td><?=e($i['officer']?:'Awaiting assignment')?></td><td><time><?=e(date('d M Y, H:i',strtotime($i['updated_at'])))?></time></td><td><a class="button secondary" href="index.php?page=issue&amp;id=<?=(int)$i['id']?>">View details →</a></td></tr><?php endforeach;?></tbody></table></div></div><?php
}
