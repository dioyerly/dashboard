<?php
declare(strict_types=1);
// Worker process for block 5.3F. Opens its own PDO connection, signals readiness, waits for the start barrier, then runs its work.
// Started only by financial_concurrency_53f.php. The spec file path is the only argument.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';
$specFile=$argv[1];$dir=dirname($specFile);
$spec=json_decode((string)file_get_contents($specFile),true,64,JSON_THROW_ON_ERROR);
$i=(int)$spec['index'];
$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$s=new Centro\FinancialService($p);
touch($dir.DIRECTORY_SEPARATOR.'ready_'.$i);
$deadline=microtime(true)+30;
while(!is_file($dir.DIRECTORY_SEPARATOR.'go')){if(microtime(true)>$deadline)exit(3);usleep(500);}
$out=['index'=>$i,'results'=>[],'released_at'=>microtime(true)];
if($spec['kind']==='read'){
    // Reader: each snapshot reads allocations and receipt movements inside one transaction; both must agree.
    $sale=$p->quote((string)$spec['sale']);$pairs=[];
    for($k=0;$k<(int)$spec['n'];$k++){
        $p->beginTransaction();
        $a=(string)$p->query("SELECT COALESCE(SUM(amount),0) FROM fin_sale_allocations WHERE environment_id='a1' AND sale_id=$sale")->fetchColumn();
        $m=(string)$p->query("SELECT COALESCE(SUM(amount),0) FROM fin_movements WHERE environment_id='a1' AND sale_id=$sale AND kind='receipt'")->fetchColumn();
        $p->commit();$pairs[]=[$a,$m];usleep(2000);
    }
    $out['pairs']=$pairs;
}else{
    foreach($spec['ops'] as $o){
        try{$r=$s->execute('a1',$o['op'],1,$o['cmd']);$row=['op'=>$o['op'],'ok'=>true,'replayed'=>$r['replayed'],'result'=>$r['result']];}
        catch(Throwable $e){$row=['op'=>$o['op'],'ok'=>false,'reason'=>$e instanceof Centro\BusinessError?$e->reason:get_class($e),'message'=>substr($e->getMessage(),0,120)];}
        $row['open']=$p->inTransaction();if($row['open'])$p->rollBack();
        $row['finished_ms']=round((microtime(true)-$out['released_at'])*1000,1);
        $out['results'][]=$row;
    }
}
file_put_contents($dir.DIRECTORY_SEPARATOR.'result_'.$i.'.json',json_encode($out,JSON_THROW_ON_ERROR));
