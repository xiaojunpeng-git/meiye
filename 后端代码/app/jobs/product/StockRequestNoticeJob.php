<?php
declare(strict_types=1);

namespace app\jobs\product;

use app\services\product\inventory\StoreStockRequestNoticeServices;
use mohe\basic\BaseJobs;
use mohe\traits\QueueTrait;
use think\facade\Log;

/**
 * 请货通知重试
 */
class StockRequestNoticeJob extends BaseJobs
{
    use QueueTrait;

    /**
     * @param int $noticeId
     */
    public function doJob($noticeId = 0): bool
    {
        try {
            /** @var StoreStockRequestNoticeServices $services */
            $services = app()->make(StoreStockRequestNoticeServices::class);
            $services->sendOne((int)$noticeId);
        } catch (\Throwable $e) {
            Log::error('请货通知重试失败 notice_id=' . $noticeId . ' ' . $e->getMessage());
        }
        return true;
    }
}
