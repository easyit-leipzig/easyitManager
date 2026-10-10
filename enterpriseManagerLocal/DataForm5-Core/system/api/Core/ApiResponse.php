<?php
declare(strict_types=1);
namespace DataForm5\Api\Core;
use DataForm5\Http\Core\Response;
final class ApiResponse
{
    public function success(mixed $data=null,int $status=200,array $meta=[]):Response { $body=['success'=>true,'data'=>$this->normalize($data)]; if($meta!==[])$body['meta']=$meta; return Response::json($body,$status); }
    public function created(mixed $data=null):Response{return $this->success($data,201);} public function noContent():Response{return new Response('',204);}
    public function error(string $message,int $status=400,array $errors=[],?string $code=null):Response { $error=['message'=>$message]; if($code!==null)$error['code']=$code; if($errors!==[])$error['details']=$errors; return Response::json(['success'=>false,'error'=>$error],$status); }
    public function paginated(Paginator $p, ?string $resourceClass=null):Response { $items=$p->items(); if($resourceClass!==null)$items=(new ResourceCollection($items,$resourceClass))->toArray()['data']; return Response::json(['success'=>true,'data'=>$items,'meta'=>$p->meta(),'links'=>$p->links()]); }
    private function normalize(mixed $data):mixed { if($data instanceof \JsonSerializable)return $data->jsonSerialize(); if(is_object($data)&&method_exists($data,'toArray'))return $data->toArray(); return $data; }
}
