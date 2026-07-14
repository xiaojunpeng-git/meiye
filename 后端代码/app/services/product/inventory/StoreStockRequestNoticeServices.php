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
 */
class StoreStockRequestNoticeServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_PENDING = 0;
    public const STATUS_SENT = 1;
    public const STATUS_FAILED = 2;

    public const MAX_RETRY = 5;

    /**
     * 确认申请后入队通知（失败不影响主单）
     */
    public function enqueueForRequest(int $requestId, int $storeId, string $orderSn): void
    {
        if ($requestId <= 0 || $storeId <= 0) {
            return;
        }
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
            Log::error('请货通知入队失败: ' . $e->getMessage());
        }
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
                // 已成功：幂等返回
                if ($status === self::STATUS_SENT) {
                    return true;
                }
                // 仅待发送或最终失败（手动重试）可发
                if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                    return false;
                }
                $time = time();
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

                // 站内信与状态更新同一事务：失败则整笔回滚，可安全重试且不产生半成功
                Db::name('system_message')->insert([
                    'mark' => 'stock_request_apply',
                    'uid' => $uid,
                    'title' => (string)$row['title'],
                    'content' => (string)$row['content'],
                    'look' => 0,
                    'type' => 1,
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
            // 事务已回滚；在外层记录失败并安排重试（不再持有锁）
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
        $rows = Db::name('store_stock_request_notice')
            ->where('status', self::STATUS_PENDING)
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
     * 手动重试最终失败记录（产品/运营触发）
     */
    public function retryFailed(int $limit = 50, int $storeId = 0): int
    {
        $query = Db::name('store_stock_request_notice')
            ->where('status', self::STATUS_FAILED)
            ->where('uid', '>', 0)
            ->order('id', 'asc')
            ->limit($limit);
        if ($storeId > 0) {
            $query->where('store_id', $storeId);
        }
        $rows = $query->select()->toArray();
        $n = 0;
        $time = time();
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            Db::name('store_stock_request_notice')->where('id', $id)->where('status', self::STATUS_FAILED)->update([
                'status' => self::STATUS_PENDING,
                'next_retry_time' => $time,
                'last_error' => '',
                'update_time' => $time,
                // 手动重试不累计到自动上限外：保留 retry_count 供追踪，但允许再发
            ]);
            StockRequestNoticeJob::dispatch([$id]);
            $n++;
        }
        return $n;
    }

    public function pendingSupplyCount(int $storeId = 0): int
    {
        $query = Db::name('store_stock_request')->whereIn('status', [
            StoreStockRequestServices::STATUS_APPLIED,
            StoreStockRequestServices::STATUS_PARTIAL,
        ]);
        if ($storeId > 0) {
            $query->where('supply_store_id', $storeId);
        }
        return (int)$query->count();
    }

    public function failedNoticeCount(int $storeId = 0): int
    {
        $query = Db::name('store_stock_request_notice')->where('status', self::STATUS_FAILED);
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
            // 已有 pending/failed：刷新内容并回到 pending 再投递（幂等发送由 sendOne 保证）
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
            // 唯一约束冲突：再读一次
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
