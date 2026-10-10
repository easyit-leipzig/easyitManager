<?php
declare(strict_types=1);
$config=require dirname(__DIR__).'/bootstrap.php';

use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Service\LicenseService;
use EasyIT\LicenseServer\Support\Id;

$pdo=Connection::create($config['database']);
(new MigrationRunner($pdo))->migrate();
$licenseService=new LicenseService($pdo);
$args=[];
foreach(array_slice($argv,2) as $a){
    if(str_starts_with($a,'--')&&str_contains($a,'=')){
        [$k,$v]=explode('=',substr($a,2),2);$args[$k]=$v;
    }
}
$cmd=$argv[1]??'help';$now=time();
$usage=function():never{echo <<<TXT
easyIT License Server Admin

Commands:
  customer:create --name=NAME [--company=COMPANY] [--email=MAIL] [--number=CUS-...]
  customer:list

  license:create --customer=ID [--number=LIC-...] [--days=365] [--max-installations=1]
                 [--max-dataforms=10|unlimited]
                 [--modules=dataform,designer]
                 [--capabilities=dataset.read,dataset.create,dataset.update,dataset.delete,dataform.search,dataform.filter]
  license:list
  license:policy --license=ID|NUMBER [--max-dataforms=10|unlimited] [--capabilities=cap1,cap2,...]
  license:policy:show --license=ID|NUMBER
  license:status --license=ID|NUMBER --status=active|suspended|revoked

  capability:list
  capability:set --license=ID|NUMBER --capability=dataset.delete --enabled=1|0
  dataform:capability:set --license=ID|NUMBER --project=ID --dataform=ID --capability=dataset.delete --enabled=1|0

  dataform:list --license=ID|NUMBER
  dataform:release --license=ID|NUMBER --project=ID --dataform=ID

  installation:list [--license=ID|NUMBER]
  installation:status --installation=INST-... --status=active|blocked|retired|replaced
  module:set --license=ID|NUMBER --module=dataform --enabled=1|0

TXT;exit(0);};
$getLicense=function(string $value)use($pdo):array{
    $st=$pdo->prepare('SELECT * FROM licenses WHERE license_id=? OR license_number=? LIMIT 1');$st->execute([$value,$value]);$r=$st->fetch();if(!$r)throw new RuntimeException('LICENSE_NOT_FOUND');return$r;
};
$getCapabilities=function()use($pdo):array{
    $rows=$pdo->query('SELECT capability_code FROM capability_registry WHERE active=1 ORDER BY capability_code')->fetchAll();
    return array_map(static fn($r)=>(string)$r['capability_code'],$rows);
};
$validateCapabilities=function(array $caps)use($getCapabilities):array{
    $known=array_fill_keys($getCapabilities(),true);$out=[];
    foreach($caps as $c){$c=trim((string)$c);if($c==='')continue;if(!isset($known[$c]))throw new RuntimeException('CAPABILITY_UNKNOWN_'.$c);$out[]=$c;}
    return array_values(array_unique($out));
};
$parseMaxDf=function(string $v):?int{
    $v=strtolower(trim($v));
    if($v===''||$v==='unlimited'||$v==='null'||$v==='*')return null;
    if(!preg_match('/^\d+$/',$v))throw new RuntimeException('MAX_DATAFORMS_INVALID');
    return max(0,(int)$v);
};
$writePolicy=function(array $lic,?int $maxDataforms,?array $enabledCaps)use($pdo,$now,$getCapabilities):void{
    $sel=$pdo->prepare('SELECT license_id FROM license_policies WHERE license_id=?');$sel->execute([$lic['license_id']]);
    if($sel->fetch()){$st=$pdo->prepare('UPDATE license_policies SET max_dataforms=?,updated_at=? WHERE license_id=?');$st->execute([$maxDataforms,$now,$lic['license_id']]);}
    else{$st=$pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)');$st->execute([$lic['license_id'],$maxDataforms,$now,$now]);}
    if($enabledCaps!==null){
        $all=$getCapabilities();$enabledMap=array_fill_keys($enabledCaps,true);
        $select=$pdo->prepare('SELECT capability_code FROM license_capabilities WHERE license_id=? AND capability_code=?');
        $insert=$pdo->prepare('INSERT INTO license_capabilities (license_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?)');
        $update=$pdo->prepare('UPDATE license_capabilities SET enabled=?,updated_at=? WHERE license_id=? AND capability_code=?');
        foreach($all as $code){$enabled=isset($enabledMap[$code])?1:0;$select->execute([$lic['license_id'],$code]);if($select->fetch())$update->execute([$enabled,$now,$lic['license_id'],$code]);else$insert->execute([$lic['license_id'],$code,$enabled,$now,$now]);}
    }
};

try{
 switch($cmd){
  case 'customer:create':
   $name=trim((string)($args['name']??''));if($name==='')throw new RuntimeException('NAME_REQUIRED');$id=Id::make('CUS');$number=(string)($args['number']??('CUS-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(4)))));$st=$pdo->prepare('INSERT INTO customers (customer_id,customer_number,name,company,email,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');$st->execute([$id,$number,$name,$args['company']??null,$args['email']??null,'active',$now,$now]);echo "customer_id=$id\ncustomer_number=$number\n";break;
  case 'customer:list':
   foreach($pdo->query('SELECT customer_id,customer_number,name,company,status FROM customers ORDER BY created_at DESC')->fetchAll() as $r)echo implode("\t",array_map(fn($v)=>(string)$v,[$r['customer_id'],$r['customer_number'],$r['name'],$r['company']??'',$r['status']]))."\n";break;

  case 'license:create':
   $customer=(string)($args['customer']??'');if($customer==='')throw new RuntimeException('CUSTOMER_REQUIRED');$cs=$pdo->prepare('SELECT customer_id FROM customers WHERE customer_id=? OR customer_number=? LIMIT 1');$cs->execute([$customer,$customer]);$cr=$cs->fetch();if(!$cr)throw new RuntimeException('CUSTOMER_NOT_FOUND');
   $mods=array_values(array_filter(array_map('trim',explode(',',(string)($args['modules']??'dataform')))));foreach($mods as $m){$check=$pdo->prepare('SELECT module_code FROM modules WHERE module_code=? AND active=1');$check->execute([$m]);if(!$check->fetch())throw new RuntimeException('MODULE_UNKNOWN_'.$m);}
   $allCaps=$getCapabilities();$caps=array_key_exists('capabilities',$args)?$validateCapabilities(explode(',',(string)$args['capabilities'])):$allCaps;
   $maxDf=$parseMaxDf((string)($args['max-dataforms']??'unlimited'));
   $id=Id::make('LICID');$number=(string)($args['number']??('LIC-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(5)))));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$days=max(1,(int)($args['days']??365));$max=max(1,(int)($args['max-installations']??1));$lease=max(60,(int)($args['lease-seconds']??3600));$grace=max($lease,(int)($args['grace-seconds']??86400));
   $pdo->beginTransaction();try{
       $st=$pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');$st->execute([$id,$number,password_hash($secret,PASSWORD_DEFAULT),$cr['customer_id'],'active',$now-60,$now+$days*86400,$max,$lease,$grace,1,$now,$now]);
       $lm=$pdo->prepare('INSERT INTO license_modules (license_id,module_code,enabled,valid_from,valid_until,configuration) VALUES (?,?,?,?,?,?)');foreach($mods as $m)$lm->execute([$id,$m,1,null,null,null]);
       $writePolicy(['license_id'=>$id],$maxDf,$caps);
       $pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   echo "license_id=$id\nlicense_number=$number\nmax_dataforms=".($maxDf===null?'unlimited':$maxDf)."\ncapabilities=".implode(',',$caps)."\nlicense_key=$number.$secret\nIMPORTANT: license_key is shown once; store it securely.\n";break;

  case 'license:list':
   $q='SELECT l.license_id,l.license_number,l.status,l.valid_until,l.max_installations,c.customer_number,c.name FROM licenses l JOIN customers c ON c.customer_id=l.customer_id ORDER BY l.created_at DESC';
   foreach($pdo->query($q)->fetchAll() as $r){$pol=$licenseService->policySummary((string)$r['license_id']);echo implode("\t",[$r['license_id'],$r['license_number'],$r['status'],date('Y-m-d H:i:s',(int)$r['valid_until']),$r['max_installations'],($pol['max_dataforms']===null?'unlimited':$pol['max_dataforms']),$pol['used_dataforms'],$r['customer_number'],$r['name']])."\n";}break;

  case 'license:policy':
   $lic=$getLicense((string)($args['license']??''));$current=$licenseService->policySummary((string)$lic['license_id']);
   $maxDf=array_key_exists('max-dataforms',$args)?$parseMaxDf((string)$args['max-dataforms']):$current['max_dataforms'];
   $caps=array_key_exists('capabilities',$args)?$validateCapabilities(explode(',',(string)$args['capabilities'])):null;
   $pdo->beginTransaction();try{$writePolicy($lic,$maxDf,$caps);$pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$lic['license_id']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   $pol=$licenseService->policySummary((string)$lic['license_id']);echo "license={$lic['license_number']} generation=".((int)$lic['generation']+1)." max_dataforms=".($pol['max_dataforms']===null?'unlimited':$pol['max_dataforms'])." used_dataforms={$pol['used_dataforms']}\ncapabilities=".implode(',',$pol['capabilities'])."\n";break;

  case 'license:policy:show':
   $lic=$getLicense((string)($args['license']??''));$pol=$licenseService->policySummary((string)$lic['license_id']);echo json_encode(['license'=>$lic['license_number'],'policy'=>$pol],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;break;

  case 'license:status':
   $lic=$getLicense((string)($args['license']??''));$status=(string)($args['status']??'');if(!in_array($status,['active','suspended','revoked'],true))throw new RuntimeException('STATUS_INVALID');$st=$pdo->prepare('UPDATE licenses SET status=?,generation=generation+1,updated_at=? WHERE license_id=?');$st->execute([$status,$now,$lic['license_id']]);echo "license={$lic['license_number']} status=$status generation=".((int)$lic['generation']+1)."\n";break;

  case 'capability:list':
   foreach($pdo->query('SELECT capability_code,name,category FROM capability_registry WHERE active=1 ORDER BY category,capability_code')->fetchAll() as $r)echo implode("\t",[$r['category'],$r['capability_code'],$r['name']])."\n";break;

  case 'capability:set':
   $lic=$getLicense((string)($args['license']??''));$cap=(string)($args['capability']??'');$validateCapabilities([$cap]);$enabled=(int)($args['enabled']??1)?1:0;
   $sel=$pdo->prepare('SELECT capability_code FROM license_capabilities WHERE license_id=? AND capability_code=?');$sel->execute([$lic['license_id'],$cap]);
   if($sel->fetch()){$pdo->prepare('UPDATE license_capabilities SET enabled=?,updated_at=? WHERE license_id=? AND capability_code=?')->execute([$enabled,$now,$lic['license_id'],$cap]);}
   else{$pdo->prepare('INSERT INTO license_capabilities (license_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$lic['license_id'],$cap,$enabled,$now,$now]);}
   $pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$lic['license_id']]);echo "license={$lic['license_number']} capability=$cap enabled=$enabled generation=".((int)$lic['generation']+1)."\n";break;

  case 'dataform:capability:set':
   $lic=$getLicense((string)($args['license']??''));$project=(string)($args['project']??'');$df=(string)($args['dataform']??'');$cap=(string)($args['capability']??'');if($project===''||$df==='')throw new RuntimeException('DATAFORM_REQUIRED');$validateCapabilities([$cap]);$enabled=(int)($args['enabled']??1)?1:0;
   if($enabled===1){$global=$licenseService->globalCapabilityMap((string)$lic['license_id']);if($global!==null && empty($global[$cap]))throw new RuntimeException('CAPABILITY_GLOBAL_DENY');}
   $sel=$pdo->prepare('SELECT capability_code FROM license_dataform_capabilities WHERE license_id=? AND project_id=? AND dataform_id=? AND capability_code=?');$sel->execute([$lic['license_id'],$project,$df,$cap]);
   if($sel->fetch()){$pdo->prepare('UPDATE license_dataform_capabilities SET enabled=?,updated_at=? WHERE license_id=? AND project_id=? AND dataform_id=? AND capability_code=?')->execute([$enabled,$now,$lic['license_id'],$project,$df,$cap]);}
   else{$pdo->prepare('INSERT INTO license_dataform_capabilities (license_id,project_id,dataform_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$lic['license_id'],$project,$df,$cap,$enabled,$now,$now]);}
   $pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$lic['license_id']]);echo "license={$lic['license_number']} project=$project dataform=$df capability=$cap enabled=$enabled generation=".((int)$lic['generation']+1)."\n";break;

  case 'dataform:list':
   $lic=$getLicense((string)($args['license']??''));$st=$pdo->prepare('SELECT project_id,dataform_id,status,first_used_at,last_used_at,released_at FROM license_dataform_usage WHERE license_id=? ORDER BY status,project_id,dataform_id');$st->execute([$lic['license_id']]);foreach($st->fetchAll() as $r)echo implode("\t",[$r['project_id'],$r['dataform_id'],$r['status'],$r['first_used_at']?date('Y-m-d H:i:s',(int)$r['first_used_at']):'',$r['last_used_at']?date('Y-m-d H:i:s',(int)$r['last_used_at']):''])."\n";$pol=$licenseService->policySummary((string)$lic['license_id']);echo "used={$pol['used_dataforms']} max=".($pol['max_dataforms']===null?'unlimited':$pol['max_dataforms'])." available=".($pol['available_dataforms']===null?'unlimited':$pol['available_dataforms'])."\n";break;

  case 'dataform:release':
   $lic=$getLicense((string)($args['license']??''));$project=(string)($args['project']??'');$df=(string)($args['dataform']??'');if($project===''||$df==='')throw new RuntimeException('DATAFORM_REQUIRED');$released=$licenseService->releaseDataform((string)$lic['license_id'],$project,$df);$pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$lic['license_id']]);echo "license={$lic['license_number']} project=$project dataform=$df released=".($released?'1':'0')." generation=".((int)$lic['generation']+1)."\n";break;

  case 'installation:list':
   if(isset($args['license'])){$lic=$getLicense((string)$args['license']);$st=$pdo->prepare('SELECT installation_id,status,generation,product_code,product_version,activated_at,last_seen_at FROM installations WHERE license_id=? ORDER BY activated_at DESC');$st->execute([$lic['license_id']]);$rows=$st->fetchAll();}else{$rows=$pdo->query('SELECT installation_id,status,generation,product_code,product_version,activated_at,last_seen_at FROM installations ORDER BY activated_at DESC')->fetchAll();}foreach($rows as $r)echo implode("\t",[$r['installation_id'],$r['status'],$r['generation'],$r['product_code'],$r['product_version']??'',date('Y-m-d H:i:s',(int)$r['activated_at']),$r['last_seen_at']?date('Y-m-d H:i:s',(int)$r['last_seen_at']):''])."\n";break;

  case 'installation:status':
   $iid=(string)($args['installation']??'');$status=(string)($args['status']??'');if($iid===''||!in_array($status,['active','blocked','retired','replaced'],true))throw new RuntimeException('STATUS_INVALID');$st=$pdo->prepare('UPDATE installations SET status=?,generation=generation+1,updated_at=? WHERE installation_id=?');$st->execute([$status,$now,$iid]);if($st->rowCount()===0)throw new RuntimeException('INSTALLATION_NOT_FOUND');echo "installation=$iid status=$status\n";break;

  case 'module:set':
   $lic=$getLicense((string)($args['license']??''));$module=(string)($args['module']??'');$enabled=(int)($args['enabled']??1)?1:0;if($module==='')throw new RuntimeException('MODULE_REQUIRED');$check=$pdo->prepare('SELECT module_code FROM modules WHERE module_code=?');$check->execute([$module]);if(!$check->fetch())throw new RuntimeException('MODULE_UNKNOWN');$sel=$pdo->prepare('SELECT module_code FROM license_modules WHERE license_id=? AND module_code=?');$sel->execute([$lic['license_id'],$module]);if($sel->fetch()){$st=$pdo->prepare('UPDATE license_modules SET enabled=? WHERE license_id=? AND module_code=?');$st->execute([$enabled,$lic['license_id'],$module]);}else{$st=$pdo->prepare('INSERT INTO license_modules (license_id,module_code,enabled,valid_from,valid_until,configuration) VALUES (?,?,?,?,?,?)');$st->execute([$lic['license_id'],$module,$enabled,null,null,null]);}$pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$lic['license_id']]);echo "license={$lic['license_number']} module=$module enabled=$enabled\n";break;

  default:$usage();
 }
}catch(Throwable $e){fwrite(STDERR,'ERROR '.$e->getMessage().PHP_EOL);exit(1);}
