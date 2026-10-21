<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/Database.php';
require dirname(__DIR__).'/app/HistoricalConversion.php';
try{
 $c=require dirname(__DIR__).'/config/settings.php';$c['root']=dirname(__DIR__);$path=$c['root'].'/.runtime/mariadb/import-targets/'.$c['db']['name'].'.json';
 $marker=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
 $service=new Centro\HistoricalConversion(Centro\Database::connection($c['db']),$c,$marker);
 $input=Centro\ControlledBackup::decode(stream_get_contents(STDIN));$env=$input['environment']??'';
 $data=match($input['action']??''){'preview'=>$service->preview($env,1),'confirm'=>$service->confirm($env,1,$input['confirmation_sha256']??'',$input['selected']??[]),default=>throw new Centro\BusinessError('unsupported_action')};
 echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){echo json_encode(['ok'=>false,'code'=>$e instanceof Centro\BusinessError?$e->reason:'historical_conversion_rejected'],JSON_THROW_ON_ERROR).PHP_EOL;exit(1);}
