<?php
require __DIR__.'/../app/admin_workspace.php';
$cases=[
 ['issue.assigned','Issue #4 officer #16',[4]],
 ['issue.routing_changed','{"format":"civic-change-1","target":"Issue #42","before":{},"after":{}}',[42]],
 ['routing.responsibility_changed','{"format":"civic-change-1","target":"District #42"}',[]],
 ['issue.routed','Issue #4 routed to province #9, district #25, town #43',[4]],
 ['issue.merged','#4 → #16',[4,16]],
 ['issue.merged','#4 → #4',[4]],
 ['issue.updated','Issue #42 status Verified',[42]],
 ['issue.created','Issue #4',[4]],
 ['issue.deleted_by_reporter','Issue #4 deleted by reporter. Title: Issue #999',[4]],
 ['user.created','Issue #4',[]],
 ['issue.assigned','officer #16',[]],
 ['issue.created','Issue #0',[]]
];
foreach($cases as $case){if(admin_audit_issue_ids($case[0],$case[1])!==$case[2])throw new RuntimeException('Incorrect report references: '.$case[1]);}
echo count($cases)." audit reference checks passed.\n";
