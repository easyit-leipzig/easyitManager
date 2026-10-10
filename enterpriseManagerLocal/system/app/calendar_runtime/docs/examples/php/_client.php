<?php
declare(strict_types=1);
$configFile=__DIR__.'/_config.php';
if(!is_file($configFile)){http_response_code(500);exit('Bitte _config.php.example nach _config.php kopieren und Token/Basisadresse eintragen.');}
$config=require $configFile;
function calendar_api(string $method,string $path,?array $body=null):array{
    global $config;
    $url=rtrim((string)$config['base_url'],'/').'/'.ltrim($path,'/');
    $ch=curl_init($url);
    $headers=['Authorization: Bearer '.(string)$config['token'],'Accept: application/json'];
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15];
    if($body!==null){$headers[]='Content-Type: application/json';$opts[CURLOPT_HTTPHEADER]=$headers;$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if($raw===false){$msg=curl_error($ch);curl_close($ch);throw new RuntimeException($msg);}
    curl_close($ch);$data=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
    if($status<200||$status>=300)throw new RuntimeException('HTTP '.$status.': '.($data['error']['message']??$raw));
    return $data;
}
