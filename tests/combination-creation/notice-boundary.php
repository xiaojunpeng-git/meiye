<?php
declare(strict_types=1);
/** Local MySQL rollback fixtures only; notice failure cannot undo a paid participation or acknowledge failed creation. */
putenv('R61_MODE=library');
require dirname(__DIR__) . '/combination-r61/local-acceptance.php';
use think\facade\Db;
use think\facade\Event;
use app\services\activity\combination\StorePinkServices;
use app\services\order\StoreOrderServices;
use app\jobs\activity\pink\PinkJob;
Db::startTrans();
try {
    $f = fixture(6, 2, 'weixin');
    Db::name('combination_group_origin')->where('group_id', $f['pinkId'])->delete();
    Db::name('store_pink')->where('id', $f['pinkId'])->delete();
    Db::name('store_order')->where('id', $f['oid'])->update(['pink_id' => 0, 'pay_time' => time() - 120]);
    $order = Db::name('store_order')->where('id', $f['oid'])->find();
    $order['cartInfo'] = [$f['cart']];
    $notices = 0;
    Event::listen('notice.notice', function ($event) use (&$notices) {
        if (!in_array($event[1], ['open_pink_success', 'can_pink_success'], true)) return;
        ++$notices;
        throw new RuntimeException('local injected notification failure');
    });
    $service = app()->make(StorePinkServices::class);
    check($service->createPink($order) === true, 'notice failure preserves successful creation');
    $pink = Db::name('store_pink')->where('order_id_key', $f['oid'])->find();
    check((bool)$pink && (int)$pink['status'] === 1, 'paid leader remains an actual pending group, not fabricated success');
    check((int)$pink['add_time'] === (int)$order['pay_time'] && (int)$pink['stop_time'] === (int)$order['pay_time'] + 3600, 'recovery uses original payment time without extending the group deadline');
    check((int)Db::name('store_order')->where('id', $f['oid'])->value('pink_id') === (int)$pink['id'], 'order links to the saved group');
    check(Db::name('combination_group_origin')->where('group_id', $pink['id'])->count() === 1, 'deadline and activity origin persist with the group');
    check($service->createPink($order) === true && $notices === 1, 'payment replay neither duplicates participation nor notification');
    check(Db::name('store_pink')->where('order_id_key', $f['oid'])->count() === 1, 'one order has exactly one participant');
    $saved = Db::name('store_order')->where('id', $f['oid'])->find();
    check((int)$saved['paid'] === 1 && (string)$saved['pay_price'] === (string)$order['pay_price'], 'formation preserves paid money and payment truth');
    $reader = new class extends \app\services\activity\combination\CombinationGroupListServices {
        public $store;
        public function allowedStores(array $admin): ?array { return [$this->store]; }
    };
    $reader->store = (int)$order['store_id'];
    $page = $reader->page(['group_id' => (int)$pink['id']], ['id' => 1]);
    check($page['count'] === 1 && count($page['list']) === 1, 'valid-store new group appears in the existing scoped platform list');
    $memberPage = (new \app\services\activity\combination\MemberCombinationRecordServices())->getList((int)$order['uid'], 'pending', 1, 20);
    check($memberPage['count'] === 1, 'same participation appears in the member pending tab');
    $member = fixture(6, 2, 'weixin');
    $leader = $f; $leader['pinkId'] = (int)$pink['id'];
    joinFixture($leader, $member);
    Db::name('combination_group_origin')->where('group_id', $member['pinkId'])->delete();
    Db::name('store_pink')->where('id', $member['pinkId'])->delete();
    $memberOrder = Db::name('store_order')->where('id', $member['oid'])->find();
    $memberOrder['cartInfo'] = [$member['cart']];
    check($service->createPink($memberOrder) === true, 'second paid participant joins despite its join-notice failure');
    check(Db::name('store_pink')->where('id|k_id', $pink['id'])->where('status', 2)->count() === 2, 'only a full group becomes successful for both members');
    check(Db::name('combination_order_fulfillment')->whereIn('order_id', [$f['oid'], $member['oid']])->where('state', 'issued')->count() === 2, 'successful group still issues both original project rights');
    // An infrastructure-free rejection models a failed attempt; the queue must retain its retry semantics.
    app()->instance(StorePinkServices::class, new class extends StorePinkServices {
        public function __construct() {}
        public function createPink(array $orderInfo) { return false; }
    });
    app()->instance(StoreOrderServices::class, new class extends StoreOrderServices {
        public function __construct() {}
        public function tidyOrder($order, bool $detail = false, bool $isPic = false) { return $order; }
    });
    check((new PinkJob())->createPink(['id' => $f['oid']]) === false, 'failed formation is retryable rather than acknowledged as successful');
} finally {
    Event::remove('notice.notice');
    Db::rollback();
}
