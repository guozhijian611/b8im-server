<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use plugin\saimulti\exception\ApiException;
use plugin\saimulti\service\module\CustomerServiceService;
$expect=static function(int $code, callable $cb): void {
  try { $cb(); throw new RuntimeException('expected'); }
  catch (ApiException $e) { if ($e->getCode()!==$code) throw new RuntimeException('code '.$e->getCode()); }
};
$s=new CustomerServiceService();
$expect(422, static fn()=>$s->queueList(0,[]));
$expect(422, static fn()=>$s->conversationCreateByUser(0,'u1',[]));
$expect(422, static fn()=>$s->resolvePublicEntry('!!'));
echo "CustomerService validation tests passed\n";
