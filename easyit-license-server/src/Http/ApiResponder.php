<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Http;
use EasyIT\LicenseServer\Security\ServerSigner;
final class ApiResponder
{
 public function __construct(private ServerSigner $signer,private string $apiVersion='1.0'){}
 public function send(bool $success,array $payload=[],?array $error=null,int $status=200,?string $requestId=null):never{
   $body=['protocol'=>'easyit-license','version'=>$this->apiVersion,'request_id'=>$requestId,'success'=>$success,'server_time'=>time(),'server_trust'=>$this->signer->trustBundle()];if($success)$body['payload']=$payload;else $body['error']=$error??['code'=>'SERVER_INTERNAL_ERROR','message'=>'Die Anfrage konnte nicht verarbeitet werden.'];$json=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
   http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('X-EasyIT-Server-Key-ID: '.$this->signer->keyId());header('X-EasyIT-Server-Signature: '.$this->signer->sign($json));
   $alt=$this->signer->alternateSignatures($json);if($alt){header('X-EasyIT-Server-Alt-Key-ID: '.$alt[0]['key_id']);header('X-EasyIT-Server-Alt-Signature: '.$alt[0]['signature']);}
   echo $json;exit;
 }
}
