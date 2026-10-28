<?php
declare(strict_types=1);
namespace app\jobs\activity\pink;

use app\services\activity\combination\CombinationFulfillmentServices;
use app\services\activity\combination\CombinationRefundServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Db;
use think\facade\Log;

/** Existing periodic group task rechecks unknown refunds and repairs missed completion issuance. */
class CombinationRecoveryJob extends BaseJobs
{
    use QueueTrait;
    public function doJob(): bool
    {
        // Oldest-first bounded work prevents a permanently pending receipt starving the next batch.
        $rows = Db::name('combination_order_refund')->whereIn('state', ['sending','pending','confirmed'])
            ->where('lease_until', '<=', time())->order('updated_at')->limit(20)->select()->toArray();
        foreach ($rows as $row) {
            try { (new CombinationRefundServices())->refundOrder((int)$row['order_id'], (string)$row['reason'], (int)$row['operator_id'], false); }
            catch (\Throwable $error) { Log::warning('拼团退款恢复待处理 oid=' . (int)$row['order_id']); }
        }
        $ids = Db::name('combination_order_fulfillment')->alias('f')
            ->join('store_pink p', 'p.order_id_key=f.order_id')
            ->where('f.state', 'pending')->where('p.status', 2)->where('p.is_refund', 0)
            ->order('f.order_id')->limit(20)->column('f.order_id');
        foreach ($ids as $id) {
            try { (new CombinationFulfillmentServices())->issue((int)$id); }
            catch (\Throwable $error) { Log::warning('拼团权益恢复待处理 oid=' . (int)$id); }
        }
        return true;
    }
}
