<?php
declare(strict_types=1);
if(strtolower((string)($_SERVER['HTTPS']??''))!=='on'){http_response_code(403);exit;}
header('Content-Type: application/json');
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
$raw=file_get_contents('php://input',false,null,0,8193);
if($raw===false||strlen($raw)>8192){http_response_code(413);exit;}
require __DIR__.'/_private/src/bootstrap.php';
require __DIR__.'/_private/src/pos_bridge.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
try{$b=pos_bridge_identity();}catch(Throwable $e){http_response_code(401);exit;}
try{
$d=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
if(!is_array($d))throw new InvalidArgumentException();
$out=match($d['action']??null){
'poll'=>['command'=>pos_bridge_poll($b)],
'report'=>pos_bridge_report($b,$d),
default=>throw new InvalidArgumentException()
};
echo json_encode($out,JSON_THROW_ON_ERROR);
}catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo '{}';}
catch(Throwable $e){http_response_code(409);echo '{}';}
