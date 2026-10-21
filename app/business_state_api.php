<?php
declare(strict_types=1);
if (!$auth->current()) respond(401,['ok'=>false,'code'=>'session_expired']);
if (!$config['sql_api_allowed'] || !$config['business_api_enabled'] || $config['business_storage']!=='sql') respond(404,['ok'=>false,'code'=>'not_found']);
require_once __DIR__.'/BusinessState.php';
try {
    $service=new Centro\BusinessState(Centro\Database::connection($config['db']),!($config['migration_read_only']??false));
    if($method==='GET'){
        if(!is_string($_GET['environment']??null))respond(400,['ok'=>false,'code'=>'invalid_request']);
        respond(200,['ok'=>true,...$service->load($_GET['environment'])]);
    }
    if($method!=='POST'){header('Allow: GET, POST');respond(405,['ok'=>false,'code'=>'method_not_allowed']);}
    if(!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??null))respond(403,['ok'=>false,'code'=>'csrf_invalid']);
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))respond(415,['ok'=>false,'code'=>'json_required']);
    $raw=file_get_contents('php://input',false,null,0,16*1024*1024+1);
    if($raw===false||strlen($raw)>16*1024*1024)respond(413,['ok'=>false,'code'=>'request_too_large']);
    try{$body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){respond(400,['ok'=>false,'code'=>'invalid_json']);}
    if(!is_array($body)||array_diff(array_keys($body),['environment','operation_id','expected_version','state'])||!is_string($body['environment']??null)||!is_string($body['operation_id']??null)||!is_int($body['expected_version']??null)||!is_array($body['state']??null))respond(400,['ok'=>false,'code'=>'invalid_request']);
    if($config['financial_api_enabled'])foreach(['sales','expenses','treasury',...((new Centro\CatalogState(Centro\Database::connection($config['db'])))->ready()?['subs']:[])] as $collection)if(!empty($body['state'][$collection]))respond(422,['ok'=>false,'code'=>'financial_state_requires_commands']);
    $result=$service->save($body['environment'],$body['operation_id'],(int)$auth->current()['id'],$body['state'],$body['expected_version']);
    respond(200,['ok'=>true,...$result]);
}catch(Centro\BusinessError $e){respond($e->status,['ok'=>false,'code'=>$e->reason]);}
