<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/FinancialService.php';
$r=new ReflectionClass(Centro\FinancialService::class);$m=$r->getMethod('money');$m->setAccessible(true);$o=$r->newInstanceWithoutConstructor();$ok=0;$bad=0;
$cases=['0.01'=>'0.01','1.2'=>'1.20','1.20'=>'1.20','999999999999999999.99'=>'999999999999999999.99'];
foreach($cases as $in=>$expected){try{$actual=$m->invoke($o,$in);if($actual!==$expected)throw new RuntimeException($actual);$ok++;}catch(Throwable $e){$bad++;fwrite(STDERR,"FAIL $in\n");}}
foreach(['0','-1','1.001','1000000000000000000.00','abc'] as $in){try{$m->invoke($o,$in);$bad++;fwrite(STDERR,"FAIL accepted $in\n");}catch(Throwable){$ok++;}}
echo json_encode(['passed'=>$ok,'failed'=>$bad,'bcmath'=>function_exists('bcadd')],JSON_THROW_ON_ERROR).PHP_EOL;exit($bad?1:0);
