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
    $sources = [];
    foreach (($config['sources'] ?? []) as $sid => $src) { if (is_string($sid) && preg_match('/\A[a-z0-9_-]{1,40}\z/', $sid) && is_array($src)) $sources[$sid] = $src; }
    if (!isset($sources['zuhause']) && !empty($config['zuhause']['export_token'])) $sources['zuhause'] = ['name' => 'Zuhause', 'url' => 'https://zuhause.estrategia.site/export.php', 'token' => (string)$config['zuhause']['export_token']];
    if ($action === 'sources_list' && $method === 'GET') {
        if (!$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
        $list = [];
        foreach ($sources as $sid => $src) $list[] = ['id' => $sid, 'name' => (string)($src['name'] ?? $sid)];
        respond(200, ['ok' => true, 'sources' => $list]);
    }
    if ($action === 'source_data' && $method === 'GET') {
        if (!$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
        $sid = $_GET['id'] ?? '';
        $view = $_GET['view'] ?? '';
        if (!is_string($view) || !in_array($view, ['events', 'summary', 'customers'], true)) respond(400, ['ok' => false, 'code' => 'invalid_view']);
        if (!is_string($sid) || !preg_match('/\A[a-z0-9_-]{1,40}\z/', $sid) || !isset($sources[$sid])) respond(404, ['ok' => false, 'code' => 'source_not_found']);
        $srcToken = (string)($sources[$sid]['token'] ?? '');
        if (strlen($srcToken) < 32) respond(503, ['ok' => false, 'code' => 'source_not_configured']);
        $srcUrl = (string)($sources[$sid]['url'] ?? '');
        $srcParts = parse_url($srcUrl);
        $srcHost = strtolower((string)($srcParts['host'] ?? ''));
        if (!is_array($srcParts) || ($srcParts['scheme'] ?? '') !== 'https' || !str_ends_with($srcHost, '.estrategia.site')) respond(503, ['ok' => false, 'code' => 'source_invalid_url']);
        if ($view !== 'events') $srcUrl .= (str_contains($srcUrl, '?') ? '&' : '?') . 'view=' . $view;
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 15, 'ignore_errors' => true,
            'header' => "Authorization: Bearer " . $srcToken . "\r\nAccept: application/json\r\n"]]);
        $raw = @file_get_contents($srcUrl, false, $ctx);
        $srcStatus = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})(\s|$)/', $http_response_header[0], $mm)) $srcStatus = (int)$mm[1];
        if ($srcStatus === 404) respond(501, ['ok' => false, 'code' => 'view_not_implemented']);
        if ($raw === false || $srcStatus !== 200 || strlen($raw) > 5000000) respond(502, ['ok' => false, 'code' => 'source_unavailable']);
        try { $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { respond(502, ['ok' => false, 'code' => 'source_unavailable']); }
        if (!is_array($decoded) || ($view === 'events' && !is_array($decoded['events'] ?? null))) respond(502, ['ok' => false, 'code' => 'source_unavailable']);
        respond(200, ['ok' => true, 'view' => $view, 'data' => $decoded]);
    }
    if ($action === 'source_action') {
        if ($method !== 'POST') { header('Allow: POST'); respond(405, ['ok' => false, 'code' => 'method_not_allowed']); }
        if (!$auth->current()) respond(401, ['ok' => false, 'code' => 'session_expired']);
        if (!Centro\Security::validCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) respond(403, ['ok' => false, 'code' => 'csrf_invalid']);
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) respond(415, ['ok' => false, 'code' => 'json_required']);
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if ($raw === false || strlen($raw) > 4096) respond(413, ['ok' => false, 'code' => 'request_too_large']);
        try { $in = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); } catch (Throwable) { respond(400, ['ok' => false, 'code' => 'invalid_request']); }
        $sid = is_array($in) ? ($in['id'] ?? null) : null;
        $cuenta = is_array($in) ? ($in['cuenta'] ?? null) : null;
        $accion = is_array($in) ? ($in['accion'] ?? null) : null;
        $valor = is_array($in) ? ($in['valor'] ?? null) : null;
        if (!is_string($sid) || !preg_match('/\A[a-z0-9_-]{1,40}\z/', $sid) || !isset($sources[$sid])) respond(400, ['ok' => false, 'code' => 'invalid_request']);
        $badReq = fn() => respond(400, ['ok' => false, 'code' => 'invalid_request']);
        $strOk = fn($v, int $min, int $max) => is_string($v) && mb_strlen($v) >= $min && mb_strlen($v) <= $max;
        if (!is_string($accion) || !in_array($accion, ['set_acceso', 'link_clave', 'crear_gracia', 'dar_gracia', 'quitar_gracia', 'resync'], true)) $badReq();
        $payload = ['accion' => $accion];
        if ($accion !== 'crear_gracia') {
            if (!is_int($cuenta) || $cuenta < 1 || $cuenta > 999999999) $badReq();
            $payload['cuenta'] = $cuenta;
        }
        if ($accion === 'set_acceso') {
            if (!is_string($valor) || !in_array($valor, ['auto', 'activo', 'bloqueado'], true)) $badReq();
            $payload['valor'] = $valor;
        }
        if ($accion === 'crear_gracia' || $accion === 'dar_gracia') {
            $dias = $in['dias'] ?? ($accion === 'crear_gracia' ? 7 : null);
            if (!is_int($dias) || $dias < 1 || $dias > 3650) $badReq();
            $payload['dias'] = $dias;
        }
        if ($accion === 'crear_gracia') {
            if (!$strOk($in['nombre'] ?? null, 1, 120) || !$strOk($in['email'] ?? null, 1, 190)) $badReq();
            $payload['nombre'] = $in['nombre'];
            $payload['email'] = $in['email'];
            if (isset($in['motivo']) && $in['motivo'] !== '') { if (!$strOk($in['motivo'], 1, 80)) $badReq(); $payload['motivo'] = $in['motivo']; }
            if (isset($in['clave']) && $in['clave'] !== '') { if (!$strOk($in['clave'], 1, 200)) $badReq(); $payload['clave'] = $in['clave']; }
        }
        $srcToken = (string)($sources[$sid]['token'] ?? '');
        if (strlen($srcToken) < 32) respond(503, ['ok' => false, 'code' => 'source_not_configured']);
        $srcUrl = (string)($sources[$sid]['url'] ?? '');
        $srcParts = parse_url($srcUrl);
        $srcHost = strtolower((string)($srcParts['host'] ?? ''));
        if (!is_array($srcParts) || ($srcParts['scheme'] ?? '') !== 'https' || !str_ends_with($srcHost, '.estrategia.site')) respond(503, ['ok' => false, 'code' => 'source_invalid_url']);
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
            'header' => "Authorization: Bearer " . $srcToken . "\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($payload, JSON_THROW_ON_ERROR)]]);
        $resp = @file_get_contents($srcUrl, false, $ctx);
        $srcStatus = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})(\s|$)/', $http_response_header[0], $mm)) $srcStatus = (int)$mm[1];
        $decoded = null;
        if (is_string($resp) && strlen($resp) <= 5000000) { try { $decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { $decoded = null; } }
        if ($resp === false || $srcStatus !== 200 || !is_array($decoded)) {
            $fail = ['ok' => false, 'code' => 'source_unavailable'];
            if (is_array($decoded) && is_string($decoded['error'] ?? null) && preg_match('/\A[A-Za-z0-9_ .-]{1,80}\z/', $decoded['error'])) { $fail['code'] = 'source_error'; $fail['detalle'] = $decoded['error']; }
            respond(502, $fail);
        }
        respond(200, ['ok' => true, 'result' => $decoded]);
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
