<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\dao\product\inventory\StoreStockRequestDao;
use app\dao\product\inventory\StoreStockRequestDetailDao;
use app\services\BaseServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\admin\SystemAdminServices;
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
        $this->enrichRows($list);
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
        $this->attachSkuDecimalScale($details, 'request_product_id', 'request_unique');
        foreach ($details as &$d) {
            $scale = (int)($d['decimal_scale'] ?? 0) > 0 ? 2 : 0;
            $d['remain_qty'] = bcsub((string)$d['qty'], (string)$d['transferred_qty'], $scale);
        }
        unset($d);
        $rows = [$info];
        $this->enrichRows($rows);
        $info = $rows[0];
        $info['details'] = $details;
        return $info;
    }

    /**
     * 保存草稿（新建或编辑）
     * @param int $adminId 当前登录操作人（平台管理员或门店店员）
     * @param int $storeScope 门店端门店ID，平台为0
     */
    public function saveDraft(int $id, array $data, int $adminId, int $storeScope = 0): int
    {
        /** @var StockPartyServices $partyServices */
        $partyServices = app()->make(StockPartyServices::class);
        // 请货方目前仅支持门店（门店向总部或其他店请货）
        $requestPartyType = (string)($data['request_party_type'] ?? StockPartyServices::PARTY_STORE);
        $supplyPartyType = (string)($data['supply_party_type'] ?? StockPartyServices::PARTY_STORE);
        $requestStoreId = (int)($data['request_store_id'] ?? 0);
        $supplyStoreId = (int)($data['supply_store_id'] ?? 0);
        $remark = trim((string)($data['remark'] ?? ''));
        $details = $data['details'] ?? [];
        $requestDate = $this->parseRequestDate($data['request_date'] ?? '');
        $requestStaffId = (int)($data['request_staff_id'] ?? 0);
        if ($storeScope > 0) {
            // 门店端只能以自己为请货门店新建/编辑
            $requestStoreId = $storeScope;
            $requestPartyType = StockPartyServices::PARTY_STORE;
        }
        $requestParty = $partyServices->normalize($requestPartyType, $requestStoreId, '请货方');
        $supplyParty = $partyServices->normalize($supplyPartyType, $supplyStoreId, '供货方');
        if ($requestParty['party_type'] === StockPartyServices::PARTY_HQ) {
            throw new ValidateException('请货方不能是总部仓');
        }
        if ($requestParty['party_type'] === $supplyParty['party_type']
            && $requestParty['store_id'] === $supplyParty['store_id']) {
            throw new ValidateException('请货方与供货方不能相同');
        }
        $requestStoreId = $requestParty['store_id'];
        $supplyStoreId = $supplyParty['store_id'];
        $requestPartyType = $requestParty['party_type'];
        $supplyPartyType = $supplyParty['party_type'];
        $this->assertRequestStaff($requestStoreId, $requestStaffId);
        $normalized = $this->normalizeDetails(
            $requestStoreId,
            $supplyStoreId,
            $details,
            $requestPartyType,
            $supplyPartyType
        );
        $time = time();
        $createType = $storeScope > 0 ? 1 : 0;

        return (int)$this->transaction(function () use ($id, $requestStoreId, $supplyStoreId, $requestPartyType, $supplyPartyType, $remark, $normalized, $adminId, $storeScope, $time, $requestDate, $requestStaffId, $createType) {
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
                    'request_party_type' => $requestPartyType,
                    'supply_store_id' => $supplyStoreId,
                    'supply_party_type' => $supplyPartyType,
                    'request_date' => $requestDate,
                    'remark' => $remark,
                    'request_staff_id' => $requestStaffId,
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
                'request_party_type' => $requestPartyType,
                'supply_store_id' => $supplyStoreId,
                'supply_party_type' => $supplyPartyType,
                'request_date' => $requestDate,
                'status' => self::STATUS_DRAFT,
                'remark' => $remark,
                'admin_id' => $adminId,
                'request_staff_id' => $requestStaffId,
                'create_uid' => $adminId,
                'create_type' => $createType,
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
        $supplyPartyType = StockPartyServices::PARTY_STORE;
        $this->transaction(function () use ($id, $adminId, $storeScope, &$orderSn, &$supplyStoreId, &$supplyPartyType) {
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
            /** @var StockPartyServices $partyServices */
            $partyServices = app()->make(StockPartyServices::class);
            $supplyPartyType = $partyServices->partyFromRow($info['supply_party_type'] ?? '', $info['supply_store_id'] ?? 0);
        });
        $this->notifySupplyStore($supplyStoreId, $orderSn, $id, $supplyPartyType);
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
            /** @var StockPartyServices $partyServices */
            $partyServices = app()->make(StockPartyServices::class);
            $supParty = $partyServices->partyFromRow($info['supply_party_type'] ?? '', $info['supply_store_id'] ?? 0);
            if ($supParty === StockPartyServices::PARTY_HQ) {
                if ($storeScope > 0) {
                    throw new ValidateException('供货方为总部仓时仅平台可驳回');
                }
            } elseif ($storeScope > 0 && (int)$info['supply_store_id'] !== $storeScope) {
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

    protected function normalizeDetails(
        int $requestStoreId,
        int $supplyStoreId,
        array $details,
        string $requestParty = StockPartyServices::PARTY_STORE,
        string $supplyParty = StockPartyServices::PARTY_STORE
    ): array {
        if (!$details) {
            throw new ValidateException('请选择请货商品');
        }
        /** @var StoreStockCrossSkuServices $cross */
        $cross = app()->make(StoreStockCrossSkuServices::class);
        $cross->assertDetailLimit($details, '请货明细');
        $pairs = [];
        $rawQtys = [];
        foreach ($details as $idx => $row) {
            $pairs[] = [
                'pid' => (int)($row['pid'] ?? 0),
                'suk' => trim((string)($row['suk'] ?? '')),
            ];
            $rawQtys[] = $row['qty'] ?? '';
        }
        $resolved = $cross->resolvePairsBatch(
            $pairs,
            $requestStoreId,
            $supplyStoreId,
            '请货门店',
            $supplyParty === StockPartyServices::PARTY_HQ ? '总部仓' : '供货门店',
            $requestParty,
            $supplyParty
        );
        $out = [];
        foreach ($pairs as $i => $pair) {
            $rowLabel = '第' . ($i + 1) . '行';
            $key = (int)$pair['pid'] . '|' . trim((string)$pair['suk']);
            $r = $resolved[$key];
            $scale = (int)($r['decimal_scale'] ?? 0) > 0 ? 2 : 0;
            $qty = $cross->parseQty($rawQtys[$i], $rowLabel, $scale);
            $out[] = [
                'pid' => $r['pid'],
                'suk' => $r['suk'],
                'request_product_id' => $r['request_product_id'],
                'request_unique' => $r['request_unique'],
                'supply_product_id' => $r['supply_product_id'],
                'supply_unique' => $r['supply_unique'],
                'product_name' => $r['product_name'],
                'stock_unit' => $r['stock_unit'],
                'qty' => $qty,
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

    /**
     * 请货人必须是请货门店在职店员
     */
    protected function assertRequestStaff(int $requestStoreId, int $staffId): void
    {
        if ($staffId <= 0) {
            throw new ValidateException('请选择请货人');
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->get($staffId, ['id', 'store_id', 'staff_name', 'is_del', 'status']);
        if (!$staff || (int)$staff['is_del'] === 1) {
            throw new ValidateException('请货人不存在');
        }
        if ((int)$staff['store_id'] !== $requestStoreId) {
            throw new ValidateException('请货人必须是请货门店的员工');
        }
        if (isset($staff['status']) && (int)$staff['status'] !== 1) {
            throw new ValidateException('请货人已停用');
        }
    }

    /**
     * 填充列表/详情展示字段
     */
    protected function enrichRows(array &$list): void
    {
        if (!$list) {
            return;
        }
        $storeNames = $this->storeNameMap($list);
        $staffNames = $this->staffNameMap($list);
        $creatorNames = $this->creatorNameMap($list);
        /** @var StockPartyServices $partyServices */
        $partyServices = app()->make(StockPartyServices::class);
        foreach ($list as &$row) {
            $reqParty = $partyServices->partyFromRow($row['request_party_type'] ?? '', $row['request_store_id'] ?? 0);
            $supParty = $partyServices->partyFromRow($row['supply_party_type'] ?? '', $row['supply_store_id'] ?? 0);
            $row['request_party_type'] = $reqParty;
            $row['supply_party_type'] = $supParty;
            $row['status_name'] = $this->statusName[(int)$row['status']] ?? '';
            $row['request_store_name'] = $partyServices->label(
                $reqParty,
                (int)$row['request_store_id'],
                (string)($storeNames[(int)$row['request_store_id']] ?? '')
            );
            $row['supply_store_name'] = $partyServices->label(
                $supParty,
                (int)$row['supply_store_id'],
                (string)($storeNames[(int)$row['supply_store_id']] ?? '')
            );
            $row['request_staff_name'] = $staffNames[(int)($row['request_staff_id'] ?? 0)] ?? '';
            // 兼容旧字段：请货人优先用 request_staff_name
            $row['admin_name'] = $row['request_staff_name'] !== ''
                ? $row['request_staff_name']
                : ($staffNames[(int)($row['admin_id'] ?? 0)] ?? '');
            $row['create_admin_name'] = $creatorNames[$this->creatorKey($row)] ?? '';
            $row['request_date'] = !empty($row['request_date']) ? date('Y-m-d', (int)$row['request_date']) : '';
            $row['add_time'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
            $row['confirm_time'] = !empty($row['confirm_time']) ? date('Y-m-d H:i:s', (int)$row['confirm_time']) : '';
        }
        unset($row);
    }

    protected function creatorKey(array $row): string
    {
        return (int)($row['create_type'] ?? 0) . ':' . (int)($row['create_uid'] ?? 0);
    }

    protected function staffNameMap(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            $sid = (int)($row['request_staff_id'] ?? 0);
            if ($sid > 0) {
                $ids[$sid] = 1;
            }
        }
        if (!$ids) {
            return [];
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        return $staffServices->getColumn(['id' => array_keys($ids)], 'staff_name', 'id') ?: [];
    }

    protected function creatorNameMap(array $list): array
    {
        $adminIds = [];
        $staffIds = [];
        foreach ($list as $row) {
            $uid = (int)($row['create_uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            if ((int)($row['create_type'] ?? 0) === 1) {
                $staffIds[$uid] = 1;
            } else {
                $adminIds[$uid] = 1;
            }
        }
        $map = [];
        if ($adminIds) {
            /** @var SystemAdminServices $systemAdminServices */
            $systemAdminServices = app()->make(SystemAdminServices::class);
            $names = $systemAdminServices->getColumn(['id' => array_keys($adminIds)], 'real_name', 'id') ?: [];
            foreach ($names as $id => $name) {
                $map['0:' . (int)$id] = (string)$name;
            }
        }
        if ($staffIds) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            $names = $staffServices->getColumn(['id' => array_keys($staffIds)], 'staff_name', 'id') ?: [];
            foreach ($names as $id => $name) {
                $map['1:' . (int)$id] = (string)$name;
            }
        }
        return $map;
    }

    public function assertStoreAccess(array $info, int $storeScope): void
    {
        if ($storeScope <= 0) {
            return;
        }
        /** @var StockPartyServices $partyServices */
        $partyServices = app()->make(StockPartyServices::class);
        $reqParty = $partyServices->partyFromRow($info['request_party_type'] ?? '', $info['request_store_id'] ?? 0);
        $supParty = $partyServices->partyFromRow($info['supply_party_type'] ?? '', $info['supply_store_id'] ?? 0);
        $isRequestStore = $reqParty === StockPartyServices::PARTY_STORE && (int)$info['request_store_id'] === $storeScope;
        $isSupplyStore = $supParty === StockPartyServices::PARTY_STORE && (int)$info['supply_store_id'] === $storeScope;
        if (!$isRequestStore && !$isSupplyStore) {
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

    protected function adminNameMap(array $list, int $operatorType = 0): array
    {
        $adminIds = [];
        foreach ($list as $row) {
            $adminId = (int)($row['admin_id'] ?? 0);
            if ($adminId > 0) {
                $adminIds[$adminId] = 1;
            }
        }
        unset($adminIds[0]);
        if (!$adminIds) {
            return [];
        }
        $ids = array_keys($adminIds);
        if ($operatorType === 1) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            return $staffServices->getColumn(['id' => $ids], 'staff_name', 'id') ?: [];
        }
        /** @var SystemAdminServices $systemAdminServices */
        $systemAdminServices = app()->make(SystemAdminServices::class);
        return $systemAdminServices->getColumn(['id' => $ids], 'real_name', 'id') ?: [];
    }

    protected function parseRequestDate($value): int
    {
        if ($value === null || $value === '') {
            return strtotime(date('Y-m-d'));
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return strtotime(date('Y-m-d'));
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ValidateException('请货日期格式不正确');
        }
        return (int)strtotime(date('Y-m-d', $ts));
    }

    protected function makeSn(string $prefix): string
    {
        return $prefix . date('YmdHis') . substr((string)microtime(true), -4) . random_int(100, 999);
    }

    protected function notifySupplyStore(int $storeId, string $orderSn, int $requestId, string $supplyPartyType = StockPartyServices::PARTY_STORE): void
    {
        /** @var StoreStockRequestNoticeServices $noticeServices */
        $noticeServices = app()->make(StoreStockRequestNoticeServices::class);
        $noticeServices->enqueueForRequest($requestId, $storeId, $orderSn, $supplyPartyType);
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

    /**
     * 为明细附加 SKU decimal_scale（院装 2 / 其它 0）
     */
    protected function attachSkuDecimalScale(array &$details, string $productIdKey, string $uniqueKey): void
    {
        if (!$details) {
            return;
        }
        $productIds = [];
        $uniques = [];
        foreach ($details as $d) {
            $pid = (int)($d[$productIdKey] ?? 0);
            $unique = (string)($d[$uniqueKey] ?? '');
            if ($pid > 0 && $unique !== '') {
                $productIds[$pid] = $pid;
                $uniques[$unique] = $unique;
            }
        }
        if (!$productIds || !$uniques) {
            return;
        }
        $rows = Db::name('store_product_attr_value')
            ->whereIn('product_id', array_values($productIds))
            ->whereIn('unique', array_values($uniques))
            ->where('type', 0)
            ->field('product_id,unique,decimal_scale')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['product_id'] . '|' . (string)$row['unique']] = (int)($row['decimal_scale'] ?? 0) > 0 ? 2 : 0;
        }
        foreach ($details as &$d) {
            $key = (int)($d[$productIdKey] ?? 0) . '|' . (string)($d[$uniqueKey] ?? '');
            $d['decimal_scale'] = $map[$key] ?? 0;
        }
        unset($d);
    }
}
