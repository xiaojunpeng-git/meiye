<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\jobs\product\StockRequestNoticeJob;
use app\services\BaseServices;
use app\services\store\SystemStoreStaffServices;
use mohe\traits\ServicesTrait;
use think\facade\Db;
use think\facade\Log;

/**
 * 请货通知：追踪、重试、待办计数
 *
 * - 供货方=门店：门店 notify 员工站内信（可重试）
 * - 供货方=总部仓：明确降级为「平台首页待办」（jnotice.unHandleStockRequest / pendingSupplyCount），
 *   禁止伪造 system_message（平台无对应用户站内信读取入口）
 */
class StoreStockRequestNoticeServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_SENT = 1;
    public const STATUS_FAILED = 2;

    public const MAX_RETRY = 5;

    /** 站内信 type：仅门店供货路径使用 */
    public const MSG_TYPE_STORE = 1;

    /** 总部仓通知渠道标识（写入 notice.last_error 作审计，非错误） */
    public const HQ_CHANNEL_PLATFORM_TODO = 'platform_homepage_todo';

    /**
     * 确认申请后入队通知（失败不影响主单）
     * @param int $storeId 供货门店ID；总部仓供货时传 0
     * @param string $supplyPartyType hq|store
     */
    public function enqueueForRequest(int $requestId, int $storeId, string $orderSn, string $supplyPartyType = StockPartyServices::PARTY_STORE): void
    {
        if ($requestId <= 0) {
            return;
        }
        $party = strtolower(trim($supplyPartyType));
        if ($party === '' || ($party !== StockPartyServices::PARTY_HQ && $party !== StockPartyServices::PARTY_STORE)) {
            $party = $storeId > 0 ? StockPartyServices::PARTY_STORE : StockPartyServices::PARTY_HQ;
        }
        if ($party === StockPartyServices::PARTY_HQ || $storeId <= 0) {
            $this->enqueueForHqSupply($requestId, $orderSn);
            return;
        }
        $this->enqueueForStoreSupply($requestId, $storeId, $orderSn);
    }

    /**
     * 门店供货：通知供货门店 notify=1 员工
     */
    protected function enqueueForStoreSupply(int $requestId, int $storeId, string $orderSn): void
    {
        try {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $adminList = $staffServices->getNotifyStoreStaffList($storeId) ?: [];
            $title = '库存请货待处理';
            $content = '收到请货单 ' . $orderSn . '（ID:' . $requestId . '），请及时处理调拨或驳回。';
            $time = time();
            if (!$adminList) {
                $id = $this->upsertNoticeRow($requestId, $storeId, 0, self::STATUS_FAILED, $title, $content, $time, '供货门店无可通知员工（notify=1）');
                Log::warning('请货通知无接收人 request_id=' . $requestId . ' notice_id=' . $id);
                return;
            }
            foreach ($adminList as $item) {
                $uid = (int)($item['uid'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $id = $this->upsertNoticeRow($requestId, $storeId, $uid, self::STATUS_PENDING, $title, $content, $time, '');
                if ($id > 0) {
                    StockRequestNoticeJob::dispatch([$id]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('请货通知入队失败(门店): ' . $e->getMessage());
        }
    }

    /**
     * 总部仓供货：明确降级为平台首页待办（禁止伪造站内信）
     *
     * 可见闭环：
     * - pendingSupplyCount(0) / jnotice.unHandleStockRequest
     * - 首页「总部仓请货待办」→ 请货列表 supply_party_type=hq
     *
     * 仍写一条 store_id=0、uid=0 的 SENT 审计行，证明已生成平台待办；不投递队列、不写 system_message。
     */
    protected function enqueueForHqSupply(int $requestId, string $orderSn): void
    {
        try {
            $title = '总部仓请货待处理';
            $content = '门店向总部仓请货 ' . $orderSn . '（ID:' . $requestId . '），已生成平台首页待办，请库存管理人员处理调拨或驳回。';
            $time = time();
            // uid=0：总部仓不按用户站内信投递；status=SENT 表示平台待办通道已生效
            $this->upsertHqPlatformTodoRow($requestId, $title, $content, $time);
        } catch (\Throwable $e) {
            Log::error('总部仓请货平台待办审计写入失败: ' . $e->getMessage());
            try {
                $this->upsertNoticeRow(
                    $requestId,
                    0,
                    0,
                    self::STATUS_FAILED,
                    '总部仓请货待处理',
                    '门店向总部仓请货 ' . $orderSn . '（ID:' . $requestId . '）',
                    time(),
                    mb_substr('平台待办审计写入异常: ' . $e->getMessage(), 0, 500)
                );
            } catch (\Throwable $inner) {
                Log::error('总部仓请货失败追踪写入异常: ' . $inner->getMessage());
            }
        }
    }

    /**
     * 总部仓平台待办审计行（幂等）：同一 request_id + uid=0 仅一条
     */
    protected function upsertHqPlatformTodoRow(int $requestId, string $title, string $content, int $time): int
    {
        $exist = Db::name('store_stock_request_notice')
            ->where(['request_id' => $requestId, 'uid' => 0, 'store_id' => 0])
            ->find();
        if ($exist) {
            $id = (int)$exist['id'];
            Db::name('store_stock_request_notice')->where('id', $id)->update([
                'status' => self::STATUS_SENT,
                'title' => $title,
                'content' => $content,
                'last_error' => self::HQ_CHANNEL_PLATFORM_TODO,
                'next_retry_time' => 0,
                'update_time' => $time,
            ]);
            return $id;
        }
        return (int)Db::name('store_stock_request_notice')->insertGetId([
            'request_id' => $requestId,
            'store_id' => 0,
            'uid' => 0,
            'status' => self::STATUS_SENT,
            'retry_count' => 0,
            'next_retry_time' => 0,
            'last_error' => self::HQ_CHANNEL_PLATFORM_TODO,
            'title' => $title,
            'content' => $content,
            'add_time' => $time,
            'update_time' => $time,
        ]);
    }

    /**
     * 发送一条通知（事务内锁记录，站内信与状态同事务，防并发重复）
     */
    public function sendOne(int $noticeId): bool
    {
        if ($noticeId <= 0) {
            return false;
        }
        try {
            return (bool)Db::transaction(function () use ($noticeId) {
                $row = Db::name('store_stock_request_notice')->where('id', $noticeId)->lock(true)->find();
                if (!$row) {
                    return false;
                }
                $status = (int)$row['status'];
                if ($status === self::STATUS_SENT) {
                    return true;
                }
                if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                    return false;
                }
                $time = time();
                // 总部仓：不写 system_message，统一收敛为平台首页待办
                if ((int)$row['store_id'] <= 0) {
                    Db::name('store_stock_request_notice')->where('id', $noticeId)->update([
                        'status' => self::STATUS_SENT,
                        'last_error' => self::HQ_CHANNEL_PLATFORM_TODO,
                        'next_retry_time' => 0,
                        'update_time' => $time,
                    ]);
                    return true;
                }
                $uid = (int)$row['uid'];
                if ($uid <= 0) {
                    Db::name('store_stock_request_notice')->where('id', $noticeId)->update([
                        'status' => self::STATUS_FAILED,
                        'retry_count' => (int)$row['retry_count'] + 1,
                        'next_retry_time' => $time + 300,
                        'last_error' => $row['last_error'] ?: '无接收人',
                        'update_time' => $time,
                    ]);
                    return false;
                }

                Db::name('system_message')->insert([
                    'mark' => 'stock_request_apply',
                    'uid' => $uid,
                    'title' => (string)$row['title'],
                    'content' => (string)$row['content'],
                    'look' => 0,
                    'type' => self::MSG_TYPE_STORE,
                    'add_time' => $time,
                    'is_del' => 0,
                ]);
                Db::name('store_stock_request_notice')->where('id', $noticeId)->update([
                    'status' => self::STATUS_SENT,
                    'last_error' => '',
                    'update_time' => $time,
                ]);
                return true;
            });
        } catch (\Throwable $e) {
            $this->markSendFailure($noticeId, $e->getMessage());
            Log::error('请货通知发送失败 notice_id=' . $noticeId . ' ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 扫描到期待重试（pending）
     */
    public function retryDue(int $limit = 50): int
    {
        $time = time();
        // 仅门店站内信可重试；总部仓走平台首页待办，不投递
        $rows = Db::name('store_stock_request_notice')
            ->where('status', self::STATUS_PENDING)
            ->where('store_id', '>', 0)
            ->where('next_retry_time', '<=', $time)
            ->where('retry_count', '<', self::MAX_RETRY)
            ->order('id', 'asc')
            ->limit($limit)
            ->column('id');
        $n = 0;
        foreach ($rows as $id) {
            StockRequestNoticeJob::dispatch([(int)$id]);
            $n++;
        }
        return $n;
    }

    /**
     * 手动重试最终失败记录（仅门店站内信）
     * @param int $storeId >0 指定门店；=0 表示不按总部仓重试（总部仓无站内信）
     */
    public function retryFailed(int $limit = 50, int $storeId = 0): int
    {
        // 总部仓已降级为平台首页待办，无站内信可重试
        if ($storeId <= 0) {
            return 0;
        }
        $rows = Db::name('store_stock_request_notice')
            ->where('status', self::STATUS_FAILED)
            ->where('uid', '>', 0)
            ->where('store_id', $storeId)
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        $n = 0;
        $time = time();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            Db::name('store_stock_request_notice')->where('id', $id)->where('status', self::STATUS_FAILED)->update([
                'status' => self::STATUS_PENDING,
                'next_retry_time' => $time,
                'last_error' => '',
                'update_time' => $time,
            ]);
            StockRequestNoticeJob::dispatch([$id]);
            $n++;
        }
        return $n;
    }

    /**
     * 供货方待处理请货数（角标/待办）
     * 门店：本店供货；平台(storeId=0)：总部仓供货
     */
    public function pendingSupplyCount(int $storeId = 0): int
    {
        $query = Db::name('store_stock_request')->whereIn('status', [
            StoreStockRequestServices::STATUS_APPLIED,
            StoreStockRequestServices::STATUS_PARTIAL,
        ]);
        if ($storeId > 0) {
            $query->where('supply_store_id', $storeId)
                ->where('supply_party_type', StockPartyServices::PARTY_STORE);
        } else {
            $query->where('supply_party_type', StockPartyServices::PARTY_HQ);
        }
        return (int)$query->count();
    }

    public function failedNoticeCount(int $storeId = 0): int
    {
        // 平台侧失败角标仅统计门店站内信失败；总部仓无站内信通道
        $query = Db::name('store_stock_request_notice')
            ->where('status', self::STATUS_FAILED)
            ->where('store_id', '>', 0);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        return (int)$query->count();
    }

    /**
     * @return int notice id（已 SENT 返回 0 表示无需再投递）
     */
    protected function upsertNoticeRow(
        int $requestId,
        int $storeId,
        int $uid,
        int $status,
        string $title,
        string $content,
        int $time,
        string $lastError
    ): int {
        $exist = Db::name('store_stock_request_notice')
            ->where(['request_id' => $requestId, 'uid' => $uid])
            ->find();
        if ($exist) {
            $id = (int)$exist['id'];
            if ((int)$exist['status'] === self::STATUS_SENT) {
                return 0;
            }
            Db::name('store_stock_request_notice')->where('id', $id)->update([
                'store_id' => $storeId,
                'status' => $status === self::STATUS_FAILED && $uid === 0 ? self::STATUS_FAILED : self::STATUS_PENDING,
                'title' => $title,
                'content' => $content,
                'last_error' => $lastError,
                'next_retry_time' => $time,
                'update_time' => $time,
            ]);
            return $uid > 0 ? $id : 0;
        }
        try {
            return (int)Db::name('store_stock_request_notice')->insertGetId([
                'request_id' => $requestId,
                'store_id' => $storeId,
                'uid' => $uid,
                'status' => $status,
                'retry_count' => 0,
                'next_retry_time' => $time,
                'last_error' => $lastError,
                'title' => $title,
                'content' => $content,
                'add_time' => $time,
                'update_time' => $time,
            ]);
        } catch (\Throwable $e) {
            $exist = Db::name('store_stock_request_notice')
                ->where(['request_id' => $requestId, 'uid' => $uid])
                ->find();
            if ($exist && (int)$exist['status'] !== self::STATUS_SENT && $uid > 0) {
                return (int)$exist['id'];
            }
            throw $e;
        }
    }

    protected function markSendFailure(int $noticeId, string $error): void
    {
        try {
            Db::transaction(function () use ($noticeId, $error) {
                $row = Db::name('store_stock_request_notice')->where('id', $noticeId)->lock(true)->find();
                if (!$row || (int)$row['status'] === self::STATUS_SENT) {
                    return;
                }
                $time = time();
                $retry = (int)$row['retry_count'] + 1;
                $status = $retry >= self::MAX_RETRY ? self::STATUS_FAILED : self::STATUS_PENDING;
                $next = $time + min(3600, 60 * max(1, $retry));
                Db::name('store_stock_request_notice')->where('id', $noticeId)->update([
                    'status' => $status,
                    'retry_count' => $retry,
                    'next_retry_time' => $next,
                    'last_error' => mb_substr($error, 0, 500),
                    'update_time' => $time,
                ]);
                if ($status === self::STATUS_PENDING) {
                    StockRequestNoticeJob::dispatchSece(max(60, 60 * $retry), [$noticeId]);
                }
            });
        } catch (\Throwable $e) {
            Log::error('请货通知标记失败异常 notice_id=' . $noticeId . ' ' . $e->getMessage());
        }
    }
}
