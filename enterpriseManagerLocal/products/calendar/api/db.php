<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_once __DIR__.'/runtime.php';
function app_config(): array { $ctx=easyit_calendar_project_context(); return ['db'=>['driver'=>easyit_calendar_driver($ctx)],'default_user_id'=>(int)($_SESSION['enterprise_user_id']??1),'attachments_path'=>'documents/attachments']; }
function db(): PDO { static $pdo=null; if($pdo instanceof PDO)return $pdo; $ctx=null;$pdo=easyit_calendar_db($ctx);$schema=easyit_calendar_schema_status($pdo,$ctx);if(!$schema['ready'])throw new RuntimeException('Calendar-Datenschema ist nicht initialisiert. Öffnen Sie Calendar Setup.',409);return $pdo; }
function json_response(mixed $data,int $status=200): never {if(ob_get_level()>0){ob_clean();}http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function request_json(): array {$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return [];$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new InvalidArgumentException('Der JSON-Body muss ein Objekt sein.');return $data;}
