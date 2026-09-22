<?php
// Exercise native cancellation while settlement holds a previously read order state.
$bootstrap = getenv('XMRPAY_JOOMLA_BOOTSTRAP');
if (!$bootstrap || !is_file($bootstrap)) exit(2);
require $bootstrap;
require_once JPATH_ADMINISTRATOR.'/components/com_hikashop/helpers/helper.php';
require_once JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
VmConfig::loadConfig();
if (!defined('JPATH_COMPONENT')) define('JPATH_COMPONENT', JPATH_ADMINISTRATOR.'/components/com_virtuemart');
require_once JPATH_PLUGINS.'/hikashoppayment/xmrpay/HikashopOrderStore.php';
require_once JPATH_PLUGINS.'/vmpayment/xmrpay/VirtueMartOrderStore.php';

function waitFile($file) {
    $until = microtime(true) + 15;
    do { clearstatcache(true, $file); if (is_file($file)) return; usleep(10000); } while (microtime(true) < $until);
    throw new RuntimeException('Timed out waiting for '.basename($file));
}
class RaceMonitor implements Joomla\Database\QueryMonitorInterface {
    private $dir, $role, $table, $guard = false;
    public function __construct($dir, $role, $table) { $this->dir=$dir; $this->role=$role; $this->table=$table; }
    public function startQuery(string $sql, ?array $boundParams = null): void {
        $this->guard = $this->role === 'pay' && strpos($sql, 'SELECT order_status FROM') === 0 && strpos($sql, $this->table) !== false;
        if ($this->role === 'cancel' && stripos($sql, 'UPDATE') === 0 && strpos($sql, $this->table) !== false) touch($this->dir.'/cancel-writing');
    }
    public function stopQuery(): void {
        if ($this->guard) { $this->guard=false; touch($this->dir.'/guard-read'); waitFile($this->dir.'/release'); }
    }
}
function cancelNative($kind, $id) {
    if ($kind === 'hika') hikashop_get('class.order')->modifyOrder($id, 'cancelled', 'xmrpay', ['notified'=>0], null);
    else VmModel::getModel('orders')->updateStatusForOneOrder($id, ['virtuemart_order_id'=>$id,'order_status'=>'X','customer_notified'=>0,'comments'=>'Concurrency fixture cancellation'], true);
}
if (isset($argv[1])) {
    [$script,$role,$kind,$id,$dir] = $argv;
    $id=(int)$id;
    $db->setMonitor(new RaceMonitor($dir,$role,$kind === 'hika' ? 'hikashop_order' : 'virtuemart_orders'));
    try {
        if ($role === 'pay') {
            $store=$kind === 'hika' ? new HikashopOrderStore(['notify_email'=>false]) : new VirtueMartOrderStore();
            $store->markPaid($id, hash('sha256',$dir), ['received_pico'=>'2000000000']);
        } else cancelNative($kind,$id);
        echo json_encode(['ok'=>true]);
    } catch (Throwable $e) { echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(1); }
    exit;
}
function cloneRecord($table,$pk,$source,$patch) {
    global $db;
    $db->setQuery('SELECT * FROM '.$db->quoteName($table).' WHERE '.$db->quoteName($pk).'='.(int)$source);
    $row=$db->loadObject(); unset($row->$pk);
    foreach ($patch as $k=>$v) $row->$k=$v;
    $db->insertObject($table,$row,$pk);
    return (int)$row->$pk;
}
function launch($role,$kind,$id,$dir) {
    $pipes=[];
    $proc=proc_open([PHP_BINARY,__FILE__,$role,$kind,(string)$id,$dir],[0=>['pipe','r'],1=>['file',$dir.'/'.$role.'.out','w'],2=>['file',$dir.'/'.$role.'.err','w']],$pipes);
    fclose($pipes[0]); return $proc;
}
$results=[];
foreach (['hika','vm'] as $kind) {
    $isH=$kind==='hika'; $table=$isH?'#__hikashop_order':'#__virtuemart_orders'; $pk=$isH?'order_id':'virtuemart_order_id';
    $dir=sys_get_temp_dir().'/xmrpay-race-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
    $id=cloneRecord($table,$pk,$isH?1:2,['order_status'=>$isH?'created':'U','order_number'=>basename($dir)]);
    $pay=null; $cancel=null;
    try {
        if ($isH) {
            $db->setQuery('UPDATE #__hikashop_order SET order_payment_params='.$db->quote(serialize((object)[])).' WHERE order_id='.$id)->execute();
        } else {
            cloneRecord('#__virtuemart_payment_plg_xmrpay','id',1,['virtuemart_order_id'=>$id,'xmr_txid'=>null]);
            $db->setQuery('SELECT * FROM #__virtuemart_order_userinfos WHERE virtuemart_order_id=2');
            foreach ($db->loadObjectList() as $row) { unset($row->virtuemart_order_userinfo_id); $row->virtuemart_order_id=$id; $db->insertObject('#__virtuemart_order_userinfos',$row); }
        }
        $pay=launch('pay',$kind,$id,$dir); waitFile($dir.'/guard-read');
        $cancel=launch('cancel',$kind,$id,$dir); waitFile($dir.'/cancel-writing');
        // Give the native cancellation a chance to finish while settlement is paused.
        $until=microtime(true)+1;
        do { $running=proc_get_status($cancel)['running']; if (!$running) break; usleep(10000); } while(microtime(true)<$until);
        touch($dir.'/release');
        proc_close($pay); $pay=null; proc_close($cancel); $cancel=null;
        $db->setQuery('SELECT order_status FROM '.$db->quoteName($table).' WHERE '.$db->quoteName($pk).'='.$id); $status=$db->loadResult();
        $payResult=json_decode(file_get_contents($dir.'/pay.out'),true); $cancelResult=json_decode(file_get_contents($dir.'/cancel.out'),true);
        $stderr=file_get_contents($dir.'/pay.err').file_get_contents($dir.'/cancel.err');
        $results[]=['cart'=>$kind,'case'=>'concurrent cancellation','cancel_waited'=>$running,'status'=>$status,'passed'=>$status===($isH?'cancelled':'X') && !empty($payResult['ok']) && !empty($cancelResult['ok']) && $stderr==='','pay'=>$payResult,'cancel'=>$cancelResult,'stderr'=>$stderr];
        $db->setQuery('UPDATE '.$db->quoteName($table).' SET order_status='.$db->quote($isH?'created':'U').' WHERE '.$db->quoteName($pk).'='.$id)->execute();
        if ($isH) $db->setQuery('DELETE FROM #__xmrpay_txids WHERE order_id='.$id)->execute();
        else $db->setQuery('UPDATE #__virtuemart_payment_plg_xmrpay SET xmr_txid=NULL WHERE virtuemart_order_id='.$id)->execute();
        $historyTable=$isH?'#__hikashop_history':'#__virtuemart_order_histories'; $historyKey=$isH?'history_order_id':$pk;
        $historyQuery='SELECT COUNT(*) FROM '.$db->quoteName($historyTable).' WHERE '.$db->quoteName($historyKey).'='.$id;
        $db->setQuery($historyQuery); $beforeHistory=(int)$db->loadResult();
        $db->setMonitor(new class implements Joomla\Database\QueryMonitorInterface {
            public function startQuery(string $sql, ?array $boundParams=null): void {
                if (strpos($sql,'SELECT order_status FROM')===0 && strpos($sql,'FOR UPDATE')===false) throw new RuntimeException('Controlled failure after native write');
            }
            public function stopQuery(): void {}
        });
        $failed=false;
        try { ($isH?new HikashopOrderStore(['notify_email'=>false]):new VirtueMartOrderStore())->markPaid($id,hash('sha256',$dir.'-rollback'),[]); }
        catch (Throwable $e) { $failed=$e->getMessage()==='Controlled failure after native write'; }
        finally { $db->setMonitor(null); }
        $db->setQuery('SELECT order_status FROM '.$db->quoteName($table).' WHERE '.$db->quoteName($pk).'='.$id); $afterStatus=$db->loadResult();
        $db->setQuery($historyQuery); $afterHistory=(int)$db->loadResult();
        $db->setQuery($isH?'SELECT COUNT(*) FROM #__xmrpay_txids WHERE order_id='.$id:'SELECT COUNT(*) FROM #__virtuemart_payment_plg_xmrpay WHERE xmr_txid IS NOT NULL AND virtuemart_order_id='.$id); $claims=(int)$db->loadResult();
        $results[]=['cart'=>$kind,'case'=>'failure after native write rolls back','passed'=>$failed && $afterStatus===($isH?'created':'U') && $afterHistory===$beforeHistory && $claims===0];
    } finally {
        touch($dir.'/release');
        if (is_resource($pay)) { proc_terminate($pay); proc_close($pay); }
        if (is_resource($cancel)) { proc_terminate($cancel); proc_close($cancel); }
        $tables=$isH?['#__xmrpay_txids'=>'order_id','#__hikashop_history'=>'history_order_id',$table=>$pk]:['#__virtuemart_order_histories'=>$pk,'#__virtuemart_order_userinfos'=>$pk,'#__virtuemart_payment_plg_xmrpay'=>$pk,$table=>$pk];
        foreach ($tables as $t=>$key) $db->setQuery('DELETE FROM '.$db->quoteName($t).' WHERE '.$db->quoteName($key).'='.$id)->execute();
        foreach (glob($dir.'/*') as $f) unlink($f); rmdir($dir);
    }
}
echo json_encode(['native_models'=>true,'fixtures_removed'=>true,'checks'=>$results],JSON_PRETTY_PRINT)."\n";
exit(count(array_filter($results,fn($r)=>!$r['passed']))?1:0);
