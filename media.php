<?php
require __DIR__.'/app/bootstrap.php';
require_login();$GLOBALS['geo_ready']=true;$name=$_GET['file']??'';
if(!is_string($name)||!preg_match('/^[a-f0-9]{40}\.jpg$/',$name)){http_response_code(404);exit;}
if(user()['role']==='citizen' && !q("SELECT i.id FROM issues i WHERE
 (i.user_id=? AND (i.photo=? OR i.resolution_photo=? OR i.maintenance_photo=? OR EXISTS(SELECT 1 FROM issue_progress_photos p WHERE p.issue_id=i.id AND p.filename=?)))
 OR (i.photo=? AND i.public_photo=i.photo AND i.status NOT IN ('Rejected','Duplicate')) LIMIT 1",
 [user()['id'],$name,$name,$name,$name,$name])->fetchColumn()){
 http_response_code(404);exit;
}
if(!q('SELECT i.id FROM issues i WHERE i.photo=? OR i.resolution_photo=? OR i.maintenance_photo=? OR EXISTS(SELECT 1 FROM issue_progress_photos p WHERE p.issue_id=i.id AND p.filename=?) LIMIT 1',[$name,$name,$name,$name])->fetch()) {http_response_code(404);exit;}
if(user()['role']==='maintenance' && !q('SELECT i.id FROM issues i WHERE i.maintenance_id=? AND (i.photo=? OR i.resolution_photo=? OR i.maintenance_photo=? OR EXISTS(SELECT 1 FROM issue_progress_photos p WHERE p.issue_id=i.id AND p.filename=?)) LIMIT 1',[user()['id'],$name,$name,$name,$name])->fetch()){http_response_code(404);exit;}
$path=__DIR__.'/storage/uploads/'.$name;if(!is_file($path)){http_response_code(404);exit;}
session_write_close();header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));header('Content-Disposition: inline; filename="report-photo.jpg"');readfile($path);
