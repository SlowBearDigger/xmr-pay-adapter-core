<?php
// Verify native cart state transitions against controlled chain evidence.
$bootstrap=getenv('XMRPAY_JOOMLA_BOOTSTRAP');
if(!$bootstrap || !is_file($bootstrap)){fwrite(STDERR,"Set XMRPAY_JOOMLA_BOOTSTRAP to a disposable Joomla bootstrap.\n");exit(2);}
require $bootstrap;
require_once JPATH_ADMINISTRATOR.'/components/com_hikashop/helpers/helper.php';
require_once JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
VmConfig::loadConfig();
if(!defined('JPATH_COMPONENT'))define('JPATH_COMPONENT',JPATH_ADMINISTRATOR.'/components/com_virtuemart');
require_once JPATH_PLUGINS.'/hikashoppayment/xmrpay/HikashopOrderStore.php';
require_once JPATH_PLUGINS.'/vmpayment/xmrpay/VirtueMartOrderStore.php';
require __DIR__.'/FakeScanner.php';
use XmrPay\Adapter\Gateway;
use XmrPay\Adapter\Settler;
class DiagnosticVmStore extends VirtueMartOrderStore {
 public function markPaid(int $id,string $tx,array $v):void {try{parent::markPaid($id,$tx,$v);}catch(Throwable $e){$GLOBALS['fixture_errors'][]=$e->getMessage();throw $e;}}
}
$results=[];
function check($name,$condition,$detail=null){global $results;$results[]=['case'=>$name,'passed'=>(bool)$condition,'detail'=>$detail];}
function status($table,$pk,$id){global $db;$db->setQuery('SELECT order_status FROM '.$db->quoteName($table).' WHERE '.$db->quoteName($pk).'='.(int)$id);return $db->loadResult();}
function cloneRow($table,$pk,$source,$patch){global $db;$db->setQuery('SELECT * FROM '.$db->quoteName($table).' WHERE '.$db->quoteName($pk).'='.(int)$source);$row=$db->loadObject();unset($row->$pk);foreach($patch as $k=>$v)$row->$k=$v;$db->insertObject($table,$row,$pk);$id=(int)$row->$pk;
if($table==='#__virtuemart_orders'){$db->setQuery('SELECT * FROM #__virtuemart_order_userinfos WHERE virtuemart_order_id='.(int)$source);foreach($db->loadObjectList() as $info){unset($info->virtuemart_order_userinfo_id);$info->virtuemart_order_id=$id;$db->insertObject('#__virtuemart_order_userinfos',$info);}}
return $id;}
$db->transactionStart();
try {
 foreach(['hika','vm'] as $kind){
  $isH=$kind==='hika';$table=$isH?'#__hikashop_order':'#__virtuemart_orders';$pk=$isH?'order_id':'virtuemart_order_id';$pending=$isH?'created':'U';$paid=$isH?'confirmed':'C';
  $id=cloneRow($table,$pk,$isH?1:2,['order_status'=>$pending,'order_number'=>'coverage-'.bin2hex(random_bytes(4))]);
  if($isH){$store=new HikashopOrderStore(['notify_email'=>false]);$db->setQuery('UPDATE #__hikashop_order SET order_payment_params='.$db->quote(serialize((object)[])).' WHERE order_id='.$id)->execute();$store->saveProgress($id,['xmr_amount'=>'0.02','birthday_height'=>1000]);}
  else {$db->setQuery('SELECT * FROM #__virtuemart_payment_plg_xmrpay WHERE virtuemart_order_id=2');$row=$db->loadObject();unset($row->id);$row->virtuemart_order_id=$id;$row->xmr_txid=null;$row->xmr_amount='0.02';$row->xmr_birthday_height=1000;$row->xmr_scanned_to=0;$row->xmr_matches='[]';$row->xmr_received_pico='0';$db->insertObject('#__virtuemart_payment_plg_xmrpay',$row);$store=new DiagnosticVmStore();}
  $cfg=['address'=>'4fixture','view_key'=>'0','network'=>'stagenet'];$g=new Gateway($cfg);$settler=new Settler($g,$store,['min_confirmations'=>5]);
  $partial=FakeScanner::match(1002,'0.007',$kind.'-partial');$topup=FakeScanner::match(1003,'0.015',$kind.'-topup');
  $g->setScanner(new FakeScanner(1010,['sub:'.$id=>[$partial]]));$settler->settleOrder($store->loadOne($id));$o=$store->loadOne($id);
  check($kind.' partial persisted',status($table,$pk,$id)===$pending && $o['received_pico']==='7000000000',$o['received_pico']);
  $g->setScanner(new FakeScanner(null));$before=$store->loadOne($id);$r=$settler->settleOrder($before);check($kind.' node outage preserves state',$r['status']==='node-error' && $store->loadOne($id)===$before);
  $g->setScanner(new FakeScanner(1011,[]));$settler->settleOrder($store->loadOne($id));$o=$store->loadOne($id);check($kind.' removed unspent match after simulated reorg',$o['matches']===[] && $o['received_pico']==='0' && status($table,$pk,$id)===$pending);
  $partial['block_height']=1009;$topup['block_height']=1010;
  $g->setScanner(new FakeScanner(1012,['sub:'.$id=>[$partial,$topup]]));$settler->settleOrder($store->loadOne($id));
  check($kind.' unconfirmed total stays pending',status($table,$pk,$id)===$pending);
  $g->setScanner(new FakeScanner(2000,[]));$settler->settleOrder($store->loadOne($id));$o=$store->loadOne($id);
  check($kind.' long outage removes orphaned cached payment',status($table,$pk,$id)===$pending && $o['matches']===[] && $o['received_pico']==='0');
  $partial['block_height']=1998;$topup['block_height']=1999;
  $g->setScanner(new FakeScanner(2005,['sub:'.$id=>[$partial,$topup]]));$r=$settler->settleOrder($store->loadOne($id));$o=$store->loadOne($id);check($kind.' topup and excess settle',$r['paid'] && status($table,$pk,$id)===$paid && $o['received_pico']==='22000000000',$r);
  $db->setQuery('UPDATE '.$db->quoteName($table).' SET order_status='.$db->quote($isH?'refunded':'R').' WHERE '.$db->quoteName($pk).'='.$id)->execute();
  $r=$settler->settleOrder($store->loadOne($id));check($kind.' refunded order stays refunded',status($table,$pk,$id)===($isH?'refunded':'R'));
  // New unpaid fixture: confirmed evidence must not reopen a cancelled order.
  $cancelId=cloneRow($table,$pk,$id,['order_status'=>$isH?'cancelled':'X','order_number'=>'coverage-'.bin2hex(random_bytes(4))]);
  if($isH){$db->setQuery('UPDATE #__hikashop_order SET order_payment_params='.$db->quote(serialize((object)[])).' WHERE order_id='.$cancelId)->execute();$store->saveProgress($cancelId,['xmr_amount'=>'0.02','birthday_height'=>1000]);}
  else {$row->virtuemart_order_id=$cancelId;unset($row->id);$db->insertObject('#__virtuemart_payment_plg_xmrpay',$row);}
  $late=FakeScanner::match(1004,'0.02',$kind.'-late');$g->setScanner(new FakeScanner(1012,['sub:'.$cancelId=>[$late]]));$r=$settler->settleOrder($store->loadOne($cancelId));check($kind.' cancelled order does not reopen',status($table,$pk,$cancelId)===($isH?'cancelled':'X'),$r);
 }
} finally {$db->transactionRollback();}
echo json_encode(['chain'=>'simulated','native_models'=>true,'rolled_back'=>true,'errors'=>$GLOBALS['fixture_errors']??[],'checks'=>$results],JSON_PRETTY_PRINT)."\n";
exit(count(array_filter($results,fn($r)=>!$r['passed']))?1:0);
