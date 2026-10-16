<?php
declare(strict_types=1);
// Recover forward, never DROP or recreate an existing target table.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/Database.php';
$pdo=null;$lock=null;
function statements(string $file): array {
    $sql=preg_replace('/--[^\r\n]*/','',(string)file_get_contents($file));
    return array_values(array_filter(array_map('trim',explode(';',$sql))));
}
function fingerprint(PDO $p,string $table): ?string {
    $q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
    if (!(int)$q->fetchColumn()) return null;
    $ddl=$p->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
    // Row count/next sequence is data, not the table definition.
    return hash('sha256',preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl));
}
try {
    $root=dirname(__DIR__);$c=require $root.'/config/settings.php';
    if ($c['environment']!=='local' || $c['db']['host']!=='127.0.0.1' || !preg_match('/\Acentro_auth_[a-z0-9_]+\z/D',$c['db']['name'])) throw new RuntimeException('Local isolated database required');
    $pdo=Centro\Database::connection($c['db']);$lock='catalog-migration-'.substr(hash('sha256',$c['db']['name']),0,40);
    $q=$pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Migration lock unavailable');
    $o=getopt('',['backup:','stop-after:','migration:']);$selected=$o['migration']??'003_catalogs_products';
    $chain=['001_auth','002_business_foundation','003_catalogs_products','004_project_stats_optional_origin','005_configuration_rates','006_historical_absence'];
    if(!in_array($selected,array_slice($chain,2),true))throw new RuntimeException('Unknown migration');
    if(in_array($selected,['005_configuration_rates','006_historical_absence'],true)){
        $marker=$root.'/.runtime/mariadb/import-targets/'.$c['db']['name'].'.json';
        if(!is_file($marker))throw new RuntimeException('Registered synthetic target required');
        $registered=json_decode(file_get_contents($marker),true,16,JSON_THROW_ON_ERROR);
        if(($registered['purpose']??null)!=='synthetic-backup-import' || ($registered['database']??null)!==$c['db']['name']
            || ($registered['port']??null)!==$c['db']['port'])throw new RuntimeException('Synthetic registration mismatch');
    }
    $files=array_slice($chain,0,array_search($selected,$chain,true)+1);
    $hashes=[];
    foreach($files as $file)$hashes[$file]=hash_file('sha256',$root.'/database/migrations/'.$file.'.sql');
    $q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE version=?');
    foreach(array_slice($files,0,-1) as $file){$q->execute([$file]);if (!hash_equals($hashes[$file],(string)$q->fetchColumn()))throw new RuntimeException('Baseline checksum mismatch');}
    $q->execute([$selected]);$applied=$q->fetchColumn();
    if ($applied!==false) {
        if(!hash_equals($hashes[$selected],$applied))throw new RuntimeException('Applied checksum mismatch');
        echo "$selected already applied; checksum verified.\n";
    } else {
        $backup=realpath((string)($o['backup']??''));$runtime=realpath($root.'/.runtime');
        $norm=static fn($s)=>strtolower(str_replace('\\','/',$s));
        if (!$backup || !$runtime || !str_starts_with($norm($backup),$norm($runtime).'/') || !is_file($backup.'.json'))throw new RuntimeException('Private prior backup required');
        $b=json_decode((string)file_get_contents($backup.'.json'),true,16,JSON_THROW_ON_ERROR);
        if (($b['database']??null)!==$c['db']['name'] || !hash_equals((string)($b['sha256']??''),hash_file('sha256',$backup)))throw new RuntimeException('Backup manifest mismatch');
        $steps=statements($root.'/database/migrations/'.$selected.'.sql');$targets=[];
        foreach($steps as $sql){if(!preg_match('/\A(?:CREATE TABLE|ALTER TABLE) ([a-z_]+)/',$sql,$m))throw new RuntimeException('Unsupported DDL');$targets[]=$m[1];}
        $dir=$runtime.'/mariadb/migration-journals';if(!is_dir($dir))mkdir($dir,0700,true);
        $journal=$dir.'/'.$c['db']['name'].'-'.$selected.'.json';
        $legacy=$dir.'/'.$c['db']['name'].'-003.json';
        if($selected==='003_catalogs_products' && !is_file($journal) && is_file($legacy))$journal=$legacy;
        if(is_file($journal)) {
            $j=json_decode((string)file_get_contents($journal),true,32,JSON_THROW_ON_ERROR);
            if($j['database']!==$c['db']['name'] || $j['hashes']!==$hashes || $j['backup_sha256']!==$b['sha256'])throw new RuntimeException('Recovery manifest mismatch');
        } else {
            // Build exact expected definitions on a separate local scratch schema using
            // the same server and unchanged migration files, including real foreign keys.
            $scratch='centro_auth_probe_'.bin2hex(random_bytes(8));$pdo->exec('CREATE DATABASE `'.$scratch.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $sc=$c['db'];$sc['name']=$scratch;$probe=Centro\Database::connection($sc);
            foreach(array_slice($files,0,-1) as $file)foreach(statements($root.'/database/migrations/'.$file.'.sql') as $sql)$probe->exec($sql);
            $baseline=[];foreach($targets as $table)$baseline[$table]=fingerprint($probe,$table);
            foreach($steps as $sql)$probe->exec($sql);
            $expected=[];foreach($targets as $table)$expected[$table]=fingerprint($probe,$table);
            $j=['database'=>$c['db']['name'],'hashes'=>$hashes,'backup_sha256'=>$b['sha256'],'scratch'=>$scratch,'baseline'=>$baseline,'expected'=>$expected,'completed'=>[]];
            file_put_contents($journal,json_encode($j,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);
        }
        // Preflight all existing definitions before executing even one missing step.
        foreach($targets as $i=>$table){
            $actual=fingerprint($pdo,$table);
            if(isset($j['completed'][$i]) && $actual===null)throw new RuntimeException('Completed migration object is missing; manual investigation required');
            if(str_starts_with($steps[$i],'ALTER TABLE')) {
                if($actual!==$j['expected'][$table]) {
                    if($actual!==$j['baseline'][$table])throw new RuntimeException('Divergent partial ALTER; manual investigation required');
                }
            } elseif($actual!==null && $actual!==$j['expected'][$table])throw new RuntimeException('Divergent existing table; no destructive recovery allowed');
        }
        foreach($steps as $i=>$sql){
            $table=$targets[$i];
            if(fingerprint($pdo,$table)!==$j['expected'][$table]) {
                $pdo->exec($sql);
                if(isset($o['stop-after']) && (int)$o['stop-after']===$i+1)throw new RuntimeException('Injected interruption after DDL before journal acknowledgment');
            }
            if(fingerprint($pdo,$table)!==$j['expected'][$table])throw new RuntimeException('Post-DDL fingerprint mismatch');
            $j['completed'][$i]=hash('sha256',$sql);file_put_contents($journal,json_encode($j,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);
        }
        $q=$pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)');$q->execute([$selected,$hashes[$selected]]);
        echo "$selected completed/recovered forward; definitions and checksum verified.\n";
    }
} catch(Throwable $error) {
    if(isset($root) && is_dir($root.'/.runtime/mariadb'))file_put_contents($root.'/.runtime/mariadb/catalog-migration-error.log',get_class($error).': '.$error->getMessage()."\n",FILE_APPEND|LOCK_EX);
    fwrite(STDERR,"Catalog migration stopped without deleting target data. Check backup, journal and schema definitions before resuming.\n");$exitCode=1;
} finally {
    if($pdo && $lock){$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
exit($exitCode??0);
