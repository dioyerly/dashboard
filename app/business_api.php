<?php
declare(strict_types=1);
// Included only by api.php for explicitly opted-in, local isolated tests.
if (!$auth->current()) respond(401,['ok'=>false,'code'=>'session_expired']);
if (!$config['business_api_enabled'] || $config['environment']!=='local' || $config['db']['host']!=='127.0.0.1' || $config['auth_driver']!=='mysql') respond(404,['ok'=>false,'code'=>'not_found']);
require_once __DIR__.'/Business.php';
try {
    if ($action==='business_list') {
        if ($method!=='GET') { header('Allow: GET'); respond(405,['ok'=>false,'code'=>'method_not_allowed']); }
        if (!is_string($_GET['environment']??null) || !is_string($_GET['resource']??null)) respond(400,['ok'=>false,'code'=>'invalid_request']);
        $service=new Centro\Business(Centro\Database::connection($config['db']));
        if($service->extended()){
            $limit=$_GET['limit']??'100';$cursor=$_GET['cursor']??null;$archived=$_GET['archived']??'0';
            if(!is_string($limit) || !preg_match('/\A[1-9][0-9]{0,2}\z/D',$limit) || ($cursor!==null && !is_string($cursor)) || !in_array($archived,['0','1'],true))respond(400,['ok'=>false,'code'=>'invalid_pagination']);
            $filters=[];
            if(isset($_GET['parent_field']) || isset($_GET['parent_id'])){
                if(!is_string($_GET['parent_field']??null) || !is_string($_GET['parent_id']??null))respond(400,['ok'=>false,'code'=>'invalid_filter']);
                $filters[$_GET['parent_field']]=$_GET['parent_id'];
            }
            respond(200,['ok'=>true,...$service->page($_GET['environment'],$_GET['resource'],(int)$limit,$cursor,$archived==='1',$filters)]);
        }
        respond(200,['ok'=>true,'records'=>$service->list($_GET['environment'],$_GET['resource'],($_GET['archived']??'0')==='1')]);
    }
    if ($method!=='POST') { header('Allow: POST'); respond(405,['ok'=>false,'code'=>'method_not_allowed']); }
    if (!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??null)) respond(403,['ok'=>false,'code'=>'csrf_invalid']);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) respond(415,['ok'=>false,'code'=>'json_required']);
    $raw=file_get_contents('php://input',false,null,0,65537);
    if ($raw===false || strlen($raw)>65536) respond(413,['ok'=>false,'code'=>'request_too_large']);
    try { $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException) { respond(400,['ok'=>false,'code'=>'invalid_json']); }
    if (!is_array($body) || array_diff(array_keys($body),['environment','operation_id','command']) || !is_string($body['environment']??null) || !is_string($body['operation_id']??null) || !is_array($body['command']??null)) respond(400,['ok'=>false,'code'=>'invalid_request']);
    $user=$auth->current();
    $service=new Centro\Business(Centro\Database::connection($config['db']));
    respond(200,['ok'=>true,...$service->command($body['environment'],$body['operation_id'],$user['id'],$body['command'])]);
} catch (Centro\BusinessError $error) { respond($error->status,['ok'=>false,'code'=>$error->reason]); }
