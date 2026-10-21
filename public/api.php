<?php
declare(strict_types=1);

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) respond(400, ['ok' => false, 'code' => 'invalid_request']);
    $method = $_SERVER['REQUEST_METHOD'];
    if(($config['migration_read_only']??false)&&$method==='POST'&&in_array($action,['business_command','business_state','financial_command'],true)){
        if(!$auth->current())respond(401,['ok'=>false,'code'=>'session_expired']);
        if(!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN']??null))respond(403,['ok'=>false,'code'=>'csrf_invalid']);
        respond(423,['ok'=>false,'code'=>'migration_preview_read_only']);
    }
    if (in_array($action, ['business_list','business_command'], true)) require dirname(__DIR__).'/app/business_api.php';
    if ($action === 'business_state') require dirname(__DIR__).'/app/business_state_api.php';
    if ($action === 'financial_command') {
        if ($method !== 'POST' || !$config['sql_api_allowed'] || !$config['financial_api_enabled']) respond(404,['ok'=>false,'code'=>'not_found']);
        if (!$auth->current()) respond(401,['ok'=>false,'code'=>'session_expired']);
        if (!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) respond(403,['ok'=>false,'code'=>'csrf_invalid']);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json')) respond(415,['ok'=>false,'code'=>'json_required']);
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>65536) respond(413,['ok'=>false,'code'=>'request_too_large']);
        $raw=file_get_contents('php://input',false,null,0,65537); if($raw===false||strlen($raw)>65536)respond(413,['ok'=>false,'code'=>'request_too_large']); try{$body=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){respond(400,['ok'=>false,'code'=>'invalid_json']);}
        if (!is_array($body)||array_diff(array_keys($body),['environment','operation_id','command'])||!is_string($body['environment']??null)||!is_string($body['operation_id']??null)||!is_array($body['command']??null)) respond(400,['ok'=>false,'code'=>'invalid_request']);
        if(in_array($body['command']['kind']??'', ['sale_edit','sale_void','sale_collect','cash_refund','sale_refund_commercial','expense_edit','expense_pay','subscription_register','subscription_renew','subscription_status'],true)&&!is_int($body['command']['expected_version']??null))respond(422,['ok'=>false,'code'=>'expected_version_required']);
        require_once dirname(__DIR__).'/app/FinancialService.php';
        try{$u=$auth->current();$s=new Centro\FinancialService(Centro\Database::connection($config['db']));respond(200,['ok'=>true,...$s->execute($body['environment'],$body['operation_id'],(int)$u['id'],$body['command'])]);}catch(Centro\BusinessError $e){respond($e->status,['ok'=>false,'code'=>$e->reason]);}
    }
    if ($action === 'financial_state' && $method === 'GET') {
        if (!$config['sql_api_allowed'] || !$config['financial_api_enabled']) respond(404,['ok'=>false,'code'=>'not_found']);
        if (!$auth->current()) respond(401,['ok'=>false,'code'=>'session_expired']);
        require_once dirname(__DIR__).'/app/FinancialState.php'; $env=$_GET['environment']??'';
        if (!is_string($env)||!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$env)) respond(400,['ok'=>false,'code'=>'invalid_environment']);
        respond(200,['ok'=>true,...(new Centro\FinancialState(Centro\Database::connection($config['db'])))->snapshot($env)]);
    }
    if ($action === 'session' && $method === 'GET') {
        $user = $auth->current();
        respond(200, ['ok' => true, 'authenticated' => $user !== null, 'csrf' => Centro\Security::csrf(), 'user' => $user,
            'setup_required' => !$auth->configured(), 'business_storage' => ($config['sql_api_allowed'] && $config['business_api_enabled'] && $config['business_storage']==='sql' ? 'sql' : 'local'), 'mysql_sync' => ($config['sql_api_allowed'] && $config['business_api_enabled'] && $config['business_storage']==='sql'), 'financial_api_enabled' => ($config['sql_api_allowed'] && $config['financial_api_enabled']), 'migration_read_only' => $config['migration_read_only']??false]);
    }
    if (!in_array($action, ['login', 'logout', 'touch'], true)) {
        if (!$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
        respond(404, ['ok' => false, 'code' => 'not_found']);
    }
    if ($method !== 'POST') { header('Allow: POST'); respond(405, ['ok' => false, 'code' => 'method_not_allowed']); }
    if ($action !== 'login' && !$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
    if (!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) respond(403, ['ok' => false, 'code' => 'csrf_invalid']);
    if ($action === 'logout') {
        Centro\Security::destroy();
        respond(200, ['ok' => true, 'next' => 'login.php']);
    }
    if ($action === 'touch') { $auth->current(true); respond(200, ['ok' => true]); }
    if (!$auth->configured()) respond(503, ['ok' => false, 'code' => 'setup_required']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) respond(413, ['ok' => false, 'code' => 'request_too_large']);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) respond(415, ['ok' => false, 'code' => 'json_required']);
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if ($raw === false || strlen($raw) > 4096) respond(413, ['ok' => false, 'code' => 'request_too_large']);
    try { $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { respond(400, ['ok' => false, 'code' => 'invalid_request']); }
    if (!is_array($input) || !is_string($input['email'] ?? null) || !is_string($input['password'] ?? null) || strlen($input['email']) > 254 || strlen($input['password']) > 72) {
        respond(400, ['ok' => false, 'code' => 'invalid_request']);
    }
    $result = $auth->login($input['email'], $input['password'], $_SERVER['REMOTE_ADDR'] ?? '');
    if (!$result['ok']) {
        if (isset($result['retry'])) header('Retry-After: ' . $result['retry']);
        respond($result['status'], ['ok' => false, 'code' => $result['code']]);
    }
    respond(200, ['ok' => true, 'next' => 'index.php']);
} catch (Throwable $error) {
    error_log('Centro authentication request failed [' . get_class($error) . ']');
    respond(503, ['ok' => false, 'code' => 'service_unavailable']);
}
