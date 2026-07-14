<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\dao\product\inventory\StoreStockRequestDao;
use app\dao\product\inventory\StoreStockRequestDetailDao;
use app\services\BaseServices;
use app\services\store\SystemStoreServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 库存请货
 */
class StoreStockRequestServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_DRAFT = 0;
    public const STATUS_APPLIED = 1;
    public const STATUS_PARTIAL = 2;
    public const STATUS_DONE = 3;
    public const STATUS_REJECTED = 4;
    public const STATUS_CANCELLED = 5;

    public $statusName = [
        0 => '草稿',
        1 => '已申请',
        2 => '部分调拨',
        3 => '已完成',
        4 => '已驳回',
        5 => '已取消',
    ];

    /** @var StoreStockRequestDetailDao */
    protected $detailDao;

    public function __construct(StoreStockRequestDao $dao, StoreStockRequestDetailDao $detailDao)
    {
        $this->dao = $dao;
        $this->detailDao = $detailDao;
    }

    public function getList(array $where, int $storeScope = 0): array
    {
        if ($storeScope > 0) {
            $where['store_scope'] = $storeScope;
        }
        // 关键字若像业务单号，走 order_sn 精确/前缀查询
        if (!empty($where['keyword']) && empty($where['order_sn'])) {
            $kw = trim((string)$where['keyword']);
            if (preg_match('/^(RQ|TF|TFR)[0-9A-Za-z]+$/', $kw)) {
                $where['order_sn'] = $kw;
                unset($where['keyword']);
            }
        }
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        $count = $this->dao->count($where);
        $storeNames = $this->storeNameMap($list);
        foreach ($list as &$row) {
            $row['status_name'] = $this->statusName[(int)$row['status']] ?? '';
            $row['request_store_name'] = $storeNames[(int)$row['request_store_id']] ?? '';
            $row['supply_store_name'] = $storeNames[(int)$row['supply_store_id']] ?? '';
            $row['add_time'] = $row['add_time'] ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
            $row['confirm_time'] = !empty($row['confirm_time']) ? date('Y-m-d H:i:s', (int)$row['confirm_time']) : '';
        }
        unset($row);
        return compact('list', 'count');
    }

    public function detail(int $id, int $storeScope = 0): array
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('请货单不存在');
        }
        $info = is_array($info) ? $info : $info->toArray();
        $this->assertStoreAccess($info, $storeScope);
        $details = $this->detailDao->getByRequestId($id);
        foreach ($details as &$d) {
            $d['remain_qty'] = bcsub((string)$d['qty'], (string)$d['transferred_qty'], 4);
        }
        unset($d);
        $storeNames = $this->storeNameMap([$info]);
        $info['status_name'] = $this->statusName[(int)$info['status']] ?? '';
        $info['request_store_name'] = $storeNames[(int)$info['request_store_id']] ?? '';
        $info['supply_store_name'] = $storeNames[(int)$info['supply_store_id']] ?? '';
        $info['add_time'] = $info['add_time'] ? date('Y-m-d H:i:s', (int)$info['add_time']) : '';
        $info['confirm_time'] = !empty($info['confirm_time']) ? date('Y-m-d H:i:s', (int)$info['confirm_time']) : '';
        $info['details'] = $details;
        return $info;
    }

    /**
     * 保存草稿（新建或编辑）
     */
    public function saveDraft(int $id, array $data, int $adminId, int $storeScope = 0): int
    {
        $requestStoreId = (int)($data['request_store_id'] ?? 0);
        $supplyStoreId = (int)($data['supply_store_id'] ?? 0);
        $remark = trim((string)($data['remark'] ?? ''));
        $details = $data['details'] ?? [];
        if ($storeScope > 0) {
            // 门店端只能以自己为请货门店新建/编辑
            $requestStoreId = $storeScope;
        }
        $this->assertNormalStores($requestStoreId, $supplyStoreId);
        if ($requestStoreId === $supplyStoreId) {
            throw new ValidateException('请货门店与供货门店不能相同');
        }
        $normalized = $this->normalizeDetails($requestStoreId, $supplyStoreId, $details);
        $time = time();

        return (int)$this->transaction(function () use ($id, $requestStoreId, $supplyStoreId, $remark, $normalized, $adminId, $storeScope, $time) {
            if ($id > 0) {
                $info = $this->dao->lockById($id);
                if (!$info) {
                    throw new ValidateException('请货单不存在');
                }
                $this->assertStoreAccess($info, $storeScope);
                if ((int)$info['status'] !== self::STATUS_DRAFT) {
                    throw new ValidateException('仅草稿可编辑');
                }
                if ($storeScope > 0 && (int)$info['request_store_id'] !== $storeScope) {
                    throw new ValidateException('仅请货门店可编辑草稿');
                }
                $this->dao->update($id, [
                    'request_store_id' => $requestStoreId,
                    'supply_store_id' => $supplyStoreId,
                    'remark' => $remark,
                    'admin_id' => $adminId,
                    'update_time' => $time,
                    'version' => (int)$info['version'] + 1,
                ]);
                $this->detailDao->deleteByRequestId($id);
                $this->saveDetails($id, $normalized, $time);
                return $id;
            }
            $orderSn = $this->makeSn('RQ');
            $res = $this->dao->save([
                'order_sn' => $orderSn,
                'request_store_id' => $requestStoreId,
                'supply_store_id' => $supplyStoreId,
                'status' => self::STATUS_DRAFT,
                'remark' => $remark,
                'admin_id' => $adminId,
                'version' => 0,
                'add_time' => $time,
                'update_time' => $time,
            ]);
            $newId = (int)$res->id;
            $this->saveDetails($newId, $normalized, $time);
            return $newId;
        });
    }

    public function deleteDraft(int $id, int $storeScope = 0): bool
    {
        return (bool)$this->transaction(function () use ($id, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('请货单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new ValidateException('仅草稿可删除（单据状态已变更，请刷新）');
            }
            if ($storeScope > 0 && (int)$info['request_store_id'] !== $storeScope) {
                throw new ValidateException('仅请货门店可删除草稿');
            }
            $this->detailDao->deleteByRequestId($id);
            $this->dao->delete($id);
            return true;
        });
    }

    /**
     * 确认申请：不改库存，通知供货门店
     */
    public function confirmApply(int $id, int $adminId, int $storeScope = 0): bool
    {
        $orderSn = '';
        $supplyStoreId = 0;
        $this->transaction(function () use ($id, $adminId, $storeScope, &$orderSn, &$supplyStoreId) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('请货单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new ValidateException('仅草稿可确认申请');
            }
            if ($storeScope > 0 && (int)$info['request_store_id'] !== $storeScope) {
                throw new ValidateException('仅请货门店可确认申请');
            }
            $details = $this->detailDao->getByRequestId($id);
            if (!$details) {
                throw new ValidateException('请货明细不能为空');
            }
            $time = time();
            $this->dao->update($id, [
                'status' => self::STATUS_APPLIED,
                'confirm_time' => $time,
                'admin_id' => $adminId,
                'update_time' => $time,
                'version' => (int)$info['version'] + 1,
            ]);
            $orderSn = (string)$info['order_sn'];
            $supplyStoreId = (int)$info['supply_store_id'];
        });
        $this->notifySupplyStore($supplyStoreId, $orderSn, $id);
        return true;
    }

    public function reject(int $id, string $reason, int $adminId, int $storeScope = 0): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new ValidateException('驳回必须填写原因');
        }
        return (bool)$this->transaction(function () use ($id, $reason, $adminId, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('请货单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            $status = (int)$info['status'];
            if (!in_array($status, [self::STATUS_APPLIED, self::STATUS_PARTIAL], true)) {
                throw new ValidateException('当前状态不可驳回');
            }
            if ($storeScope > 0 && (int)$info['supply_store_id'] !== $storeScope) {
                throw new ValidateException('仅供货门店可驳回');
            }
            $this->dao->update($id, [
                'status' => self::STATUS_REJECTED,
                'reject_reason' => mb_substr($reason, 0, 500),
                'admin_id' => $adminId,
                'update_time' => time(),
                'version' => (int)$info['version'] + 1,
            ]);
            return true;
        });
    }

    public function cancel(int $id, int $adminId, int $storeScope = 0): bool
    {
        return (bool)$this->transaction(function () use ($id, $adminId, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('请货单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            $status = (int)$info['status'];
            if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_APPLIED], true)) {
                throw new ValidateException('当前状态不可取消（已有调拨请走调拨冲销）');
            }
            if ($storeScope > 0 && (int)$info['request_store_id'] !== $storeScope) {
                throw new ValidateException('仅请货门店可取消');
            }
            $this->dao->update($id, [
                'status' => self::STATUS_CANCELLED,
                'admin_id' => $adminId,
                'update_time' => time(),
                'version' => (int)$info['version'] + 1,
            ]);
            return true;
        });
    }

    /**
     * 调拨确认后回写累计数量与状态（同事务内调用）
     * @param array $lines [['pid'=>,'suk'=>,'qty'=>], ...]
     */
    public function applyTransferredQty(int $requestId, array $lines): void
    {
        if ($requestId <= 0 || !$lines) {
            return;
        }
        $info = $this->dao->lockById($requestId);
        if (!$info) {
            throw new ValidateException('关联请货单不存在');
        }
        $status = (int)$info['status'];
        if (!in_array($status, [self::STATUS_APPLIED, self::STATUS_PARTIAL], true)) {
            throw new ValidateException('请货单状态不允许继续调拨');
        }
        $details = $this->detailDao->getByRequestId($requestId);
        $map = [];
        foreach ($details as $d) {
            $key = (int)$d['pid'] . '|' . (string)$d['suk'];
            $map[$key] = $d;
        }
        $allDone = true;
        foreach ($lines as $line) {
            $key = (int)$line['pid'] . '|' . (string)$line['suk'];
            if (!isset($map[$key])) {
                throw new ValidateException('调拨明细不在请货单内：' . $key);
            }
            $d = $map[$key];
            $qty = bcadd((string)$line['qty'], '0', 4);
            if (bccomp($qty, '0', 4) <= 0) {
                throw new ValidateException('调拨数量必须大于0');
            }
            $newTransferred = bcadd((string)$d['transferred_qty'], $qty, 4);
            if (bccomp($newTransferred, (string)$d['qty'], 4) > 0) {
                throw new ValidateException('调拨数量超过请货剩余：' . ($d['product_name'] ?: $key));
            }
            Db::name('store_stock_request_detail')->where('id', (int)$d['id'])->update([
                'transferred_qty' => $newTransferred,
            ]);
            $map[$key]['transferred_qty'] = $newTransferred;
        }
        foreach ($map as $d) {
            if (bccomp((string)$d['transferred_qty'], (string)$d['qty'], 4) < 0) {
                $allDone = false;
                break;
            }
        }
        $this->dao->update($requestId, [
            'status' => $allDone ? self::STATUS_DONE : self::STATUS_PARTIAL,
            'update_time' => time(),
            'version' => (int)$info['version'] + 1,
        ]);
    }

    /**
     * 冲销时回退请货累计（同事务）
     */
    public function revertTransferredQty(int $requestId, array $lines): void
    {
        if ($requestId <= 0 || !$lines) {
            return;
        }
        $info = $this->dao->lockById($requestId);
        if (!$info) {
            return;
        }
        $details = $this->detailDao->getByRequestId($requestId);
        $map = [];
        foreach ($details as $d) {
            $map[(int)$d['pid'] . '|' . (string)$d['suk']] = $d;
        }
        foreach ($lines as $line) {
            $key = (int)$line['pid'] . '|' . (string)$line['suk'];
            if (!isset($map[$key])) {
                continue;
            }
            $d = $map[$key];
            $qty = bcadd((string)$line['qty'], '0', 4);
            $newTransferred = bcsub((string)$d['transferred_qty'], $qty, 4);
            if (bccomp($newTransferred, '0', 4) < 0) {
                $newTransferred = '0';
            }
            Db::name('store_stock_request_detail')->where('id', (int)$d['id'])->update([
                'transferred_qty' => $newTransferred,
            ]);
            $map[$key]['transferred_qty'] = $newTransferred;
        }
        $hasAny = false;
        $allDone = true;
        foreach ($map as $d) {
            if (bccomp((string)$d['transferred_qty'], '0', 4) > 0) {
                $hasAny = true;
            }
            if (bccomp((string)$d['transferred_qty'], (string)$d['qty'], 4) < 0) {
                $allDone = false;
            }
        }
        $newStatus = self::STATUS_APPLIED;
        if ($hasAny && $allDone) {
            $newStatus = self::STATUS_DONE;
        } elseif ($hasAny) {
            $newStatus = self::STATUS_PARTIAL;
        }
        // 已驳回/已取消不改
        if (in_array((int)$info['status'], [self::STATUS_APPLIED, self::STATUS_PARTIAL, self::STATUS_DONE], true)) {
            $this->dao->update($requestId, [
                'status' => $newStatus,
                'update_time' => time(),
                'version' => (int)$info['version'] + 1,
            ]);
        }
    }

    protected function normalizeDetails(int $requestStoreId, int $supplyStoreId, array $details): array
    {
        if (!$details) {
            throw new ValidateException('请选择请货商品');
        }
        /** @var StoreStockCrossSkuServices $cross */
        $cross = app()->make(StoreStockCrossSkuServices::class);
        $cross->assertDetailLimit($details, '请货明细');
        $pairs = [];
        $qtys = [];
        foreach ($details as $idx => $row) {
            $rowLabel = '第' . ($idx + 1) . '行';
            $pid = (int)($row['pid'] ?? 0);
            $suk = trim((string)($row['suk'] ?? ''));
            $qty = $cross->parseQty($row['qty'] ?? '', $rowLabel);
            $pairs[] = ['pid' => $pid, 'suk' => $suk];
            $qtys[] = $qty;
        }
        $resolved = $cross->resolvePairsBatch($pairs, $requestStoreId, $supplyStoreId);
        $out = [];
        foreach ($pairs as $i => $pair) {
            $key = (int)$pair['pid'] . '|' . trim((string)$pair['suk']);
            $r = $resolved[$key];
            $out[] = [
                'pid' => $r['pid'],
                'suk' => $r['suk'],
                'request_product_id' => $r['request_product_id'],
                'request_unique' => $r['request_unique'],
                'supply_product_id' => $r['supply_product_id'],
                'supply_unique' => $r['supply_unique'],
                'product_name' => $r['product_name'],
                'stock_unit' => $r['stock_unit'],
                'qty' => $qtys[$i],
                'transferred_qty' => '0',
            ];
        }
        return $out;
    }

    protected function saveDetails(int $requestId, array $normalized, int $time): void
    {
        $rows = [];
        foreach ($normalized as $d) {
            $rows[] = array_merge($d, [
                'request_id' => $requestId,
                'add_time' => $time,
            ]);
        }
        if ($rows) {
            $this->detailDao->saveAll($rows);
        }
    }

    protected function assertNormalStores(int $a, int $b): void
    {
        if ($a <= 0 || $b <= 0) {
            throw new ValidateException('请选择请货门店和供货门店');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        foreach ([$a, $b] as $sid) {
            $store = $storeServices->get($sid, ['id', 'name', 'is_show', 'is_del']);
            if (!$store || (int)$store['is_del'] === 1 || (int)$store['is_show'] !== 1) {
                throw new ValidateException('门店不存在或未营业：' . $sid);
            }
        }
    }

    public function assertStoreAccess(array $info, int $storeScope): void
    {
        if ($storeScope <= 0) {
            return;
        }
        if ((int)$info['request_store_id'] !== $storeScope && (int)$info['supply_store_id'] !== $storeScope) {
            throw new ValidateException('无权操作该请货单');
        }
    }

    protected function storeNameMap(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            $ids[(int)($row['request_store_id'] ?? 0)] = 1;
            $ids[(int)($row['supply_store_id'] ?? 0)] = 1;
            $ids[(int)($row['from_store_id'] ?? 0)] = 1;
            $ids[(int)($row['to_store_id'] ?? 0)] = 1;
        }
        unset($ids[0]);
        if (!$ids) {
            return [];
        }
        $rows = Db::name('system_store')->whereIn('id', array_keys($ids))->column('name', 'id');
        return $rows ?: [];
    }

    protected function makeSn(string $prefix): string
    {
        return $prefix . date('YmdHis') . substr((string)microtime(true), -4) . random_int(100, 999);
    }

    protected function notifySupplyStore(int $storeId, string $orderSn, int $requestId): void
    {
        /** @var StoreStockRequestNoticeServices $noticeServices */
        $noticeServices = app()->make(StoreStockRequestNoticeServices::class);
        $noticeServices->enqueueForRequest($requestId, $storeId, $orderSn);
    }

    /**
     * 供货方待处理请货数（角标/待办）
     */
    public function pendingSupplyCount(int $storeId = 0): int
    {
        /** @var StoreStockRequestNoticeServices $noticeServices */
        $noticeServices = app()->make(StoreStockRequestNoticeServices::class);
        return $noticeServices->pendingSupplyCount($storeId);
    }
}
