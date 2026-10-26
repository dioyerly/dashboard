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
    if (in_array($action, ['business_list','business_command'], true)) require dirname(__DIR__).'/app/business_api.php';
    if ($action === 'financial_command') {
        if ($method !== 'POST' || !$config['financial_api_enabled'] || $config['environment'] !== 'local' || $config['db']['host'] !== '127.0.0.1' || $config['auth_driver'] !== 'mysql') respond(404,['ok'=>false,'code'=>'not_found']);
        if (!$auth->current()) respond(401,['ok'=>false,'code'=>'session_expired']);
        if (!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) respond(403,['ok'=>false,'code'=>'csrf_invalid']);
        $raw=file_get_contents('php://input',false,null,0,65537); try{$body=json_decode((string)$raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){respond(400,['ok'=>false,'code'=>'invalid_json']);}
        if (!is_array($body)||!is_string($body['environment']??null)||!is_string($body['operation_id']??null)||!is_array($body['command']??null)) respond(400,['ok'=>false,'code'=>'invalid_request']);
        require_once dirname(__DIR__).'/app/FinancialService.php';
        try{$u=$auth->current();$s=new Centro\FinancialService(Centro\Database::connection($config['db']));respond(200,['ok'=>true,...$s->execute($body['environment'],$body['operation_id'],(int)$u['id'],$body['command'])]);}catch(Centro\BusinessError $e){respond($e->status,['ok'=>false,'code'=>$e->reason]);}
    }
    if ($action === 'session' && $method === 'GET') {
        $user = $auth->current();
        respond(200, ['ok' => true, 'authenticated' => $user !== null, 'csrf' => Centro\Security::csrf(), 'user' => $user,
            'setup_required' => !$auth->configured(), 'business_storage' => 'local', 'mysql_sync' => false]);
    }
    if ($action === 'zuhause_events' && $method === 'GET') {
        if (!$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
        $zuhauseToken = (string)($config['zuhause']['export_token'] ?? '');
        if (empty($zuhauseToken) || strlen($zuhauseToken) < 32) respond(503, ['ok' => false, 'code' => 'zuhause_not_configured']);
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
            'header' => "Authorization: Bearer " . $zuhauseToken . "\r\nAccept: application/json\r\n"]]);
        $raw = @file_get_contents('https://zuhause.estrategia.site/export.php', false, $ctx);
        if ($raw === false || strlen($raw) > 5000000 || (isset($http_response_header) && strpos(($http_response_header[0] ?? ''), '200') === false)) {
            respond(502, ['ok' => false, 'code' => 'zuhause_unavailable']);
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !is_array($data['events'] ?? null)) respond(502, ['ok' => false, 'code' => 'zuhause_unavailable']);
            respond(200, ['ok' => true, 'events' => $data['events']]);
        } catch (Throwable) {
            respond(502, ['ok' => false, 'code' => 'zuhause_unavailable']);
        }
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
