<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/BackupImport.php';
try{
    $body=json_decode(stream_get_contents(STDIN),true,128,JSON_THROW_ON_ERROR);
    $config=require dirname(__DIR__).'/config/settings.php';
    $path=dirname(__DIR__).'/.runtime/mariadb/import-targets/'.$config['db']['name'].'.json';
    if(!is_file($path))throw new Centro\BusinessError('isolated_import_target_required');
    $marker=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    // Guard before even attempting a connection.
    if($config['environment']!=='local' || $config['db']['host']!=='127.0.0.1' || ($marker['database']??null)!==$config['db']['name'])throw new Centro\BusinessError('isolated_import_target_required');
    $s=new Centro\BackupImport(Centro\Database::connection($config['db']),$config,$marker);
    $result=match($body['action']??''){
        'preflight'=>['counts'=>array_map('count',$s->preflight($body['plan']))],
        'import'=>$s->import($body['plan'],$body['operation'],$body['backup'],(int)($body['fail_after']??0),(bool)($body['lose_ack']??false)),
        'export'=>$s->export($body['environment'],$body['operation']),
        default=>throw new Centro\BusinessError('invalid_import_action')};
    echo json_encode(['ok'=>true,'data'=>$result],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    $code=$e instanceof Centro\BusinessError?$e->reason:'import_failed';
    // No SQL, source values, credentials or exception messages on stdout/stderr.
    echo json_encode(['ok'=>false,'code'=>$code]);exit(1);
}
