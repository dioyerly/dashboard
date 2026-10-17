<?php
declare(strict_types=1);
// Sale with a catalog-linked line, with automatic assertions. Requires the environment created by financial_sales_advanced.php in the same database.
require dirname(__DIR__).'/app/Database.php';require dirname(__DIR__).'/app/FinancialService.php';$c=require dirname(__DIR__).'/config/settings.php';$p=Centro\Database::connection($c['db']);
$p->exec("INSERT INTO auth_users(id,email,password_hash,active) VALUES(1,'lines@example.invalid','x',1) ON DUPLICATE KEY UPDATE active=1");
$p->exec("INSERT INTO brands(environment_id,id,name,color,dark,alt,description) VALUES('adv_sales','br1','Brand','#112233','#000000','#ffffff','')");
$p->exec("INSERT INTO product_categories(environment_id,id,brand_id,name) VALUES('adv_sales','cat1','br1','Cat')");
$p->exec("INSERT INTO products(environment_id,id,brand_id,category_id,category_label_snapshot,name,status,pending_updates) VALUES('adv_sales','prod1','br1','cat1','Cat','Product','publicado','')");
$p->exec("INSERT INTO product_versions(environment_id,id,product_id,format_label,language_label,version_label,price,currency_id) VALUES('adv_sales','ver1','prod1','','','1.0',10.00,'USD')");
$s=new Centro\FinancialService($p);
$r=$s->execute('adv_sales','sale_lines_operation_001',1,['kind'=>'sale_register','sale_id'=>'sale_lines_001','customer_id'=>'cust1','currency'=>'USD','amount'=>'10.00','date'=>'2026-10-16','lines'=>[['product_id'=>'prod1','version_id'=>'ver1','description'=>'Product','quantity'=>'1','unit_price'=>'10.00','line_total'=>'10.00']]]);
$lines=(int)$p->query("SELECT COUNT(*) FROM fin_sale_lines WHERE environment_id='adv_sales' AND sale_id='sale_lines_001'")->fetchColumn();
$checks=[
  ['check'=>'sale with line returns sale id','passed'=>($r['result']['sale_id']??null)==='sale_lines_001','observed'=>$r['result']['sale_id']??null],
  ['check'=>'exactly one line persisted','passed'=>$lines===1,'observed'=>$lines],
];
echo json_encode(['result'=>$r,'lines'=>$lines,'checks'=>$checks],JSON_THROW_ON_ERROR).PHP_EOL;
exit(array_filter($checks,fn($x)=>!$x['passed'])?1:0);
