<?php
declare(strict_types=1);
namespace Centro;
require_once __DIR__.'/ControlledBackup.php';
/** Content comparison only: never chooses, merges or writes a source. */
final class SourceNumber{public function __construct(public readonly string $lexeme){}}
final class SourceComparison
{
 private static function canonical(mixed $v):mixed{if($v instanceof SourceNumber)return ['number',$v->lexeme];if(is_object($v)){$a=get_object_vars($v);ksort($a,SORT_STRING);return ['object',array_map([self::class,'canonical'],$a)];}if(is_array($v))return ['array',array_map([self::class,'canonical'],$v)];return [get_debug_type($v),$v];}
 private static function decode(string $raw):mixed{
  preg_match_all('/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null/su',$raw,$m);$tokens=$m[0];$i=0;
  $read=function()use(&$read,&$i,$tokens):mixed{$token=$tokens[$i++];if($token==='{'){$o=new \stdClass();if(($tokens[$i]??null)!=='}')do{$key=json_decode($tokens[$i++],true,4,JSON_THROW_ON_ERROR);$i++;$o->$key=$read();$comma=($tokens[$i]??null)===',';if($comma)$i++;}while($comma);$i++;return $o;}if($token==='['){$a=[];if(($tokens[$i]??null)!==']')do{$a[]=$read();$comma=($tokens[$i]??null)===',';if($comma)$i++;}while($comma);$i++;return $a;}return $token[0]==='"'||in_array($token,['true','false','null'],true)?json_decode($token,true,4,JSON_THROW_ON_ERROR):new SourceNumber($token);};return $read();
 }
 private static function hash(mixed $v):string{return hash('sha256',json_encode(self::canonical($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION));}
 private static function fields(mixed $a,mixed $b,array $path=[]):array{
  if(self::hash($a)===self::hash($b))return [];$out=[];
  if(is_object($a)&&is_object($b)||is_array($a)&&is_array($b)){foreach(array_unique([...array_keys((array)$a),...array_keys((array)$b)]) as $key){$x=(array)$a;$y=(array)$b;if(!array_key_exists($key,$x)||!array_key_exists($key,$y))$out[]=[...$path,$key];else array_push($out,...self::fields($x[$key],$y[$key],[...$path,$key]));}return $out;}
  return [$path];
 }
 public static function compare(array $archive):array{
  $out=[];$copies=$archive['snapshots'];
  for($i=0;$i<count($copies);$i++)for($j=$i+1;$j<count($copies);$j++){
   $a=$copies[$i];$b=$copies[$j];if($a['environment']!==$b['environment'])continue;
   // Quote number lexemes before decoding, retaining exact decimal distinctions.
   $left=self::decode($a['payload']);$right=self::decode($b['payload']);$differences=[];
   foreach(array_unique([...array_keys((array)$left),...array_keys((array)$right)]) as $collection){$l=$left->$collection??null;$r=$right->$collection??null;if(self::hash($l)===self::hash($r))continue;
    if(is_array($l)&&is_array($r)){
     $index=static function($rows){$map=[];foreach($rows as $ordinal=>$record){$id=is_object($record)&&is_string($record->id??null)?$record->id:'ordinal:'.$ordinal;$map[$id][]= ['ordinal'=>$ordinal,'value'=>$record];}return $map;};$li=$index($l);$ri=$index($r);
     foreach(array_unique([...array_keys($li),...array_keys($ri)]) as $id){$x=$li[$id]??[];$y=$ri[$id]??[];if(self::hash($x)===self::hash($y))continue;$differences[]=['collection'=>$collection,'id'=>$id,'left_count'=>count($x),'right_count'=>count($y),'duplicate_identity'=>count($x)>1||count($y)>1,'left_sha256'=>self::hash($x),'right_sha256'=>self::hash($y),'changed_paths'=>self::fields($x,$y)];}
    }else $differences[]=['collection'=>$collection,'left_sha256'=>self::hash($l),'right_sha256'=>self::hash($r),'changed_paths'=>self::fields($l,$r)];
   }
   $out[]=['environment'=>$a['environment'],'left'=>$a['id'],'right'=>$b['id'],'bytes_identical'=>$a['sha256']===$b['sha256'],'differences'=>$differences];
  }
  return ['selection'=>null,'merge_performed'=>false,'sources_modified'=>false,'pairs'=>$out];
 }
}
