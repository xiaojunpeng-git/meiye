<?php
declare(strict_types=1);
/** Fixed-plan, one-order formation recovery. Inspect is read-only; apply needs the approved plan digest and local backups. */
set_exception_handler(function (Throwable $error) { fwrite(STDERR, $error->getMessage() . PHP_EOL); exit(1); });
$args = getopt('', ['plan:', 'mode:', 'approved-plan-sha:']);
$path = $args['plan'] ?? ''; $mode = $args['mode'] ?? 'inspect';
if (!in_array($mode, ['inspect','apply'], true) || !is_file($path)) throw new RuntimeException('Fixed plan and valid mode required');
$raw = file_get_contents($path); $plan = json_decode($raw, true);
if (!is_array($plan) || ($plan['site'] ?? '') !== '010') throw new RuntimeException('Only the named 010 recovery is allowed');
$root = '/www/wwwroot/010.cc3798.com';
if (hash_file('sha256', $root . '/.env') !== $plan['environment']) throw new RuntimeException('Instance configuration changed');
chdir($root); require $root . '/vendor/autoload.php'; (new think\App($root . '/'))->initialize();
if (config('database.connections.mysql.database') !== 'mohe_010_app' || (int)config('database.connections.mysql.hostport') !== 3307)
    throw new RuntimeException('Wrong database identity');
use think\facade\Db;
$id = (int)$plan['order_id'];
// Read the exact paid order; member identity is loaded from that order, never supplied by the operator.
Db::execute('SET SESSION TRANSACTION READ ONLY'); Db::startTrans();
try {
    $order = Db::name('store_order')->where('id', $id)->find();
    if (!$order || (string)$order['order_id'] !== $plan['order_no']) throw new RuntimeException('Wrong business order');
    $pink = Db::name('store_pink')->where('order_id_key', $id)->select()->toArray();
    if (count($pink) > 1) throw new RuntimeException('Duplicate participation requires separate reconciliation');
    $activity = Db::name('store_combination')->where('id', (int)$order['activity_id'])->find();
    $receipt = Db::name('combination_order_fulfillment')->where('order_id', $id)->find();
    $cards = (int)Db::name('user_card_holder')->where('oid', $id)->count();
    $cart = Db::name('store_order_cart_info')->where('oid', $id)->order('id')->select()->toArray();
    if (!$pink) {
        if ((int)$order['paid'] !== 1 || (int)$order['refund_status'] !== 0 || (int)$order['terminal_action'] !== 0
            || (int)$order['type'] !== 3 || (int)$order['pink_id'] !== 0 || (int)$order['store_id'] !== (int)$plan['store_id']
            || (int)$order['activity_id'] !== (int)$plan['activity_id'] || (int)$order['pay_time'] !== (int)$plan['paid_at']
            || !$activity || (int)$activity['people'] !== (int)$plan['people'] || (int)$activity['effective_time'] !== (int)$plan['effective_hours']
            || !$receipt || $receipt['state'] !== 'pending' || $cards !== 0 || !$cart)
            throw new RuntimeException('Paid order or original formation intent changed');
    }
    $view = ['site'=>'010','order_no'=>$plan['order_no'],'paid'=>1,'participation_exists'=>(bool)$pink,
        'store_id'=>(int)$order['store_id'],'target_people'=>(int)$plan['people'],
        'starts_at'=>date('Y-m-d H:i:s',(int)$plan['paid_at']),
        'deadline'=>date('Y-m-d H:i:s',(int)$plan['paid_at']+(int)$plan['effective_hours']*3600),
        'funds_commands'=>0,'notice_commands'=>0];
} finally { Db::rollback(); }
if ($mode === 'inspect' || $pink) { echo json_encode($view,JSON_UNESCAPED_UNICODE),PHP_EOL; exit; }
if (!hash_equals(hash('sha256',$raw),(string)($args['approved-plan-sha']??''))) throw new RuntimeException('Explicit approved plan digest required');
foreach ($plan['runtime_files'] as $file=>$hash) {
    if (strpos($file,'..')!==false || hash_file('sha256',$root.'/'.$file)!==$hash) throw new RuntimeException('Accepted formation code not deployed');
}
// Inspect mode leaves the session read-only; only the approved apply path may restore its normal write mode.
Db::execute('SET SESSION TRANSACTION READ WRITE');
$dir=$plan['backup_dir'];
if (!preg_match('~^/www/backups/010/combination-formation-[a-f0-9]{8}$~D',$dir)) throw new RuntimeException('Scoped backup directory required');
if (!is_dir($dir)) mkdir($dir,0700,true);
$lock=fopen($dir.'/recovery.lock','c'); if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Recovery already running');
$before=['order'=>$order,'receipt'=>$receipt,'cart'=>$cart,'card_count'=>$cards,'plan_sha256'=>hash('sha256',$raw)];
if (is_file($dir.'/before.json')) throw new RuntimeException('Existing recovery backup: inspect the previous attempt first');
file_put_contents($dir.'/before.json',json_encode($before,JSON_UNESCAPED_UNICODE)); chmod($dir.'/before.json',0600);
// Recovery restores the paid user's participation only. It sends no SMS/WeChat notice, charges no funds and forces no completion.
think\facade\Event::remove('notice.notice');
$tidy=app()->make(app\services\order\StoreOrderServices::class)->tidyOrder($order,true);
if (!app()->make(app\services\activity\combination\StorePinkServices::class)->createPink($tidy)) throw new RuntimeException('Formation did not persist; preserve the backup for diagnosis');
$after=Db::name('store_order')->where('id',$id)->find();
foreach(['paid','pay_type','pay_time','pay_price','trade_no','refund_status','terminal_action','inventory_handled','sales_handled']as$key)
    if((string)$after[$key] !== (string)$order[$key])throw new RuntimeException('Protected payment/inventory field changed: '.$key);
$saved=Db::name('store_pink')->where('order_id_key',$id)->select()->toArray();
if(count($saved)!==1||(int)$saved[0]['k_id']!==0||(int)$saved[0]['status']!==1||(int)$saved[0]['people']!==(int)$plan['people']
    ||(int)$saved[0]['add_time']!==(int)$plan['paid_at']||(int)$saved[0]['stop_time']!==(int)$plan['paid_at']+(int)$plan['effective_hours']*3600
    ||(int)$after['pink_id']!==(int)$saved[0]['id'])throw new RuntimeException('Formation verification failed');
if(Db::name('combination_order_fulfillment')->where('order_id',$id)->find()!==$receipt
    ||(int)Db::name('user_card_holder')->where('oid',$id)->count()!==$cards
    ||Db::name('store_order_cart_info')->where('oid',$id)->order('id')->select()->toArray()!==$cart)
    throw new RuntimeException('Rights/cart changed during pending formation');
$admin=Db::name('system_admin')->where('account',$plan['operator_account'])->find();
$page=(new app\services\activity\combination\CombinationGroupListServices())->page(['group_id'=>(int)$saved[0]['id']],$admin);
$member=(new app\services\activity\combination\MemberCombinationRecordServices())->detail((int)$order['uid'],(int)$saved[0]['id']);
if($page['count']!==1||$member['status']!=='pending')throw new RuntimeException('Existing platform/member readers do not reconcile');
$view+=['group_id'=>(int)$saved[0]['id'],'status'=>'pending','joined_people'=>1,'platform_visible'=>true,'member_visible'=>true];
file_put_contents($dir.'/after.json',json_encode($view,JSON_UNESCAPED_UNICODE));chmod($dir.'/after.json',0600);
echo json_encode($view,JSON_UNESCAPED_UNICODE),PHP_EOL;
