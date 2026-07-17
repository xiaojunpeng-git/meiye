<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\dao\product\inventory\StoreStockTransferDao;
use app\dao\product\inventory\StoreStockTransferDetailDao;
use app\services\BaseServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\admin\SystemAdminServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 库存调拨（自由调拨 / 请货转调拨 / 冲销）
 */
class StoreStockTransferServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_DRAFT = 0;
    public const STATUS_CONFIRMED = 1;
    public const STATUS_CANCELLED = 2;

    /** 入库类型：调拨入库 */
    public const IN_ORDER_TYPE_TRANSFER = 8;
    /** 出库类型：调拨出库 */
    public const OUT_ORDER_TYPE_TRANSFER = 9;

    public $statusName = [
        0 => '草稿',
        1 => '已确认',
        2 => '已取消',
    ];

    /** @var StoreStockTransferDetailDao */
    protected $detailDao;

    public function __construct(StoreStockTransferDao $dao, StoreStockTransferDetailDao $detailDao)
    {
        $this->dao = $dao;
        $this->detailDao = $detailDao;
    }

    public function getList(array $where, int $storeScope = 0): array
    {
        if ($storeScope > 0) {
            $where['store_scope'] = $storeScope;
        }
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
            throw new ValidateException('调拨单不存在');
        }
        $info = is_array($info) ? $info : $info->toArray();
        $this->assertStoreAccess($info, $storeScope);
        $details = $this->detailDao->getByTransferId($id);
        $this->attachSkuDecimalScale($details, 'from_product_id', 'from_unique');
        foreach ($details as &$d) {
            $scale = (int)($d['decimal_scale'] ?? 0) > 0 ? 2 : 0;
            $d['reversible_qty'] = bcsub((string)$d['qty'], (string)$d['reversed_qty'], $scale);
        }
        unset($d);
        $rows = [$info];
        $this->enrichRows($rows);
        $info = $rows[0];
        $info['details'] = $details;
        return $info;
    }

    /**
     * 保存草稿（自由调拨或请货转调拨）
     */
    public function saveDraft(int $id, array $data, int $adminId, int $storeScope = 0): int
    {
        /** @var StockPartyServices $partyServices */
        $partyServices = app()->make(StockPartyServices::class);
        $requestId = (int)($data['request_id'] ?? 0);
        $fromStoreId = (int)($data['from_store_id'] ?? 0);
        $toStoreId = (int)($data['to_store_id'] ?? 0);
        $fromPartyType = (string)($data['from_party_type'] ?? StockPartyServices::PARTY_STORE);
        $toPartyType = (string)($data['to_party_type'] ?? StockPartyServices::PARTY_STORE);
        $remark = trim((string)($data['remark'] ?? ''));
        $details = $data['details'] ?? [];
        $originTransferId = (int)($data['origin_transfer_id'] ?? 0);
        $transferStaffId = (int)($data['transfer_staff_id'] ?? 0);
        $transferDate = $this->parseTransferDate($data['transfer_date'] ?? '');

        if ($originTransferId > 0) {
            throw new ValidateException('冲销请使用冲销接口，不能当普通草稿保存');
        }

        if ($requestId > 0) {
            /** @var StoreStockRequestServices $reqServices */
            $reqServices = app()->make(StoreStockRequestServices::class);
            $req = $reqServices->detail($requestId, $storeScope);
            // 请货转调拨：供货方→调出，请货方→调入
            $fromPartyType = (string)($req['supply_party_type'] ?? StockPartyServices::PARTY_STORE);
            $toPartyType = (string)($req['request_party_type'] ?? StockPartyServices::PARTY_STORE);
            $fromStoreId = (int)$req['supply_store_id'];
            $toStoreId = (int)$req['request_store_id'];
            $status = (int)$req['status'];
            if (!in_array($status, [StoreStockRequestServices::STATUS_APPLIED, StoreStockRequestServices::STATUS_PARTIAL], true)) {
                throw new ValidateException('请货单当前状态不可转调拨');
            }
            // 总部供货：仅平台可建请货转调拨；门店供货：供货门店或平台可建
            if ($fromPartyType === StockPartyServices::PARTY_HQ && $storeScope > 0) {
                throw new ValidateException('供货方为总部仓时，仅平台可创建调拨单');
            }
            if ($storeScope > 0) {
                $ok = ($fromPartyType === StockPartyServices::PARTY_STORE && $fromStoreId === $storeScope)
                    || ($toPartyType === StockPartyServices::PARTY_STORE && $toStoreId === $storeScope);
                if (!$ok) {
                    throw new ValidateException('无权操作该请货单转调拨');
                }
            }
            $details = $this->normalizeFromRequest($req, $details);
        } else {
            $fromParty = $partyServices->normalize($fromPartyType, $fromStoreId, '调出方');
            $toParty = $partyServices->normalize($toPartyType, $toStoreId, '调入方');
            $fromPartyType = $fromParty['party_type'];
            $toPartyType = $toParty['party_type'];
            $fromStoreId = $fromParty['store_id'];
            $toStoreId = $toParty['store_id'];
            if ($fromPartyType === $toPartyType && $fromStoreId === $toStoreId) {
                throw new ValidateException('调出方与调入方不能相同');
            }
            // P2：自由调拨暂不允许总部作为调入方（仅总部→门店 / 门店↔门店）
            if ($toPartyType === StockPartyServices::PARTY_HQ) {
                throw new ValidateException('调入方暂不支持总部仓');
            }
            if ($fromPartyType === StockPartyServices::PARTY_HQ && $storeScope > 0) {
                throw new ValidateException('调出方为总部仓时，仅平台可创建调拨单');
            }
            if ($storeScope > 0) {
                $ok = ($fromPartyType === StockPartyServices::PARTY_STORE && $fromStoreId === $storeScope)
                    || ($toPartyType === StockPartyServices::PARTY_STORE && $toStoreId === $storeScope);
                if (!$ok) {
                    throw new ValidateException('门店只能创建与自己相关的调拨单');
                }
            }
            $details = $this->normalizeFreeDetails($fromStoreId, $toStoreId, $details, $fromPartyType, $toPartyType);
        }

        $this->assertTransferStaff($fromStoreId, $toStoreId, $transferStaffId, $fromPartyType, $toPartyType);
        $time = time();
        $createType = $storeScope > 0 ? 1 : 0;
        return (int)$this->transaction(function () use ($id, $requestId, $fromStoreId, $toStoreId, $fromPartyType, $toPartyType, $remark, $details, $adminId, $storeScope, $time, $transferStaffId, $transferDate, $createType) {
            if ($id > 0) {
                $info = $this->dao->lockById($id);
                if (!$info) {
                    throw new ValidateException('调拨单不存在');
                }
                $this->assertStoreAccess($info, $storeScope);
                if ((int)$info['status'] !== self::STATUS_DRAFT) {
                    throw new ValidateException('仅草稿可编辑');
                }
                if ((int)$info['origin_transfer_id'] > 0) {
                    throw new ValidateException('冲销单不可编辑');
                }
                $this->dao->update($id, [
                    'request_id' => $requestId,
                    'from_store_id' => $fromStoreId,
                    'from_party_type' => $fromPartyType,
                    'to_store_id' => $toStoreId,
                    'to_party_type' => $toPartyType,
                    'remark' => $remark,
                    'transfer_staff_id' => $transferStaffId,
                    'transfer_date' => $transferDate,
                    'admin_id' => $adminId,
                    'update_time' => $time,
                ]);
                $this->detailDao->deleteByTransferId($id);
                $this->saveDetails($id, $details, $time);
                return $id;
            }
            $orderSn = $this->makeSn('TF');
            $res = $this->dao->save([
                'order_sn' => $orderSn,
                'request_id' => $requestId,
                'origin_transfer_id' => 0,
                'from_store_id' => $fromStoreId,
                'from_party_type' => $fromPartyType,
                'to_store_id' => $toStoreId,
                'to_party_type' => $toPartyType,
                'status' => self::STATUS_DRAFT,
                'remark' => $remark,
                'admin_id' => $adminId,
                'transfer_staff_id' => $transferStaffId,
                'transfer_date' => $transferDate,
                'create_uid' => $adminId,
                'create_type' => $createType,
                'add_time' => $time,
                'update_time' => $time,
            ]);
            $newId = (int)$res->id;
            $this->saveDetails($newId, $details, $time);
            return $newId;
        });
    }

    public function deleteDraft(int $id, int $storeScope = 0): bool
    {
        return (bool)$this->transaction(function () use ($id, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('调拨单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new ValidateException('仅草稿可删除（单据状态已变更，请刷新）');
            }
            if ((int)$info['origin_transfer_id'] > 0) {
                throw new ValidateException('冲销草稿请使用取消，不可直接删除');
            }
            $this->detailDao->deleteByTransferId($id);
            $this->dao->delete($id);
            return true;
        });
    }

    public function cancelDraft(int $id, int $adminId, int $storeScope = 0): bool
    {
        return (bool)$this->transaction(function () use ($id, $adminId, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('调拨单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new ValidateException('仅草稿可取消');
            }
            $this->dao->update($id, [
                'status' => self::STATUS_CANCELLED,
                'admin_id' => $adminId,
                'update_time' => time(),
            ]);
            return true;
        });
    }

    /**
     * 确认调拨：双边库存同事务
     */
    public function confirm(int $id, int $adminId, int $storeScope = 0): array
    {
        return $this->transaction(function () use ($id, $adminId, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('调拨单不存在');
            }
            $this->assertStoreAccess($info, $storeScope);
            if ((int)$info['status'] === self::STATUS_CONFIRMED) {
                // 幂等：已确认直接返回
                return [
                    'id' => $id,
                    'out_stock_order_id' => (int)$info['out_stock_order_id'],
                    'in_stock_order_id' => (int)$info['in_stock_order_id'],
                    'idempotent' => 1,
                ];
            }
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new ValidateException('仅草稿可确认调拨');
            }

            $details = $this->detailDao->lockByTransferId($id);
            if (!$details) {
                throw new ValidateException('调拨明细为空');
            }

            /** @var StockPartyServices $partyServices */
            $partyServices = app()->make(StockPartyServices::class);
            $fromPartyType = $partyServices->partyFromRow($info['from_party_type'] ?? '', $info['from_store_id'] ?? 0);
            $toPartyType = $partyServices->partyFromRow($info['to_party_type'] ?? '', $info['to_store_id'] ?? 0);
            $fromStoreId = (int)$info['from_store_id'];
            $toStoreId = (int)$info['to_store_id'];
            // 门店端仅调出方可确认；总部仓调出仅平台可确认
            $this->assertConfirmPermission($fromPartyType, $fromStoreId, $storeScope);
            $fromOwner = $partyServices->inventoryOwner($fromPartyType, $fromStoreId);
            $toOwner = $partyServices->inventoryOwner($toPartyType, $toStoreId);
            $requestId = (int)$info['request_id'];
            $originId = (int)$info['origin_transfer_id'];
            $isReverse = $originId > 0;

            // 冲销：校验原单可冲销数量
            if ($isReverse) {
                $this->assertReverseQty($originId, $details);
            }

            // 请货转调拨：校验剩余数量并预留回写数据
            $requestLines = [];
            if ($requestId > 0 && !$isReverse) {
                foreach ($details as $d) {
                    $requestLines[] = [
                        'pid' => (int)$d['pid'],
                        'suk' => (string)$d['suk'],
                        'qty' => (string)$d['qty'],
                    ];
                }
            }

            $time = time();
            $stockTime = date('Y-m-d H:i:s', $time);
            /** @var StoreProductStockOrderServices $stockOrderServices */
            $stockOrderServices = app()->make(StoreProductStockOrderServices::class);

            $outDetail = [];
            $inDetail = [];
            $allUniques = [];
            foreach ($details as $d) {
                $qty = bcadd((string)$d['qty'], '0', 4);
                if (bccomp($qty, '0', 4) <= 0) {
                    throw new ValidateException('调拨数量必须大于0，请检查明细数量格式');
                }
                $fromUnique = (string)$d['from_unique'];
                $toUnique = (string)$d['to_unique'];
                if ($fromUnique !== '') {
                    $allUniques[$fromUnique] = $fromUnique;
                }
                if ($toUnique !== '') {
                    $allUniques[$toUnique] = $toUnique;
                }
                $outDetail[] = [
                    'product_id' => (int)$d['from_product_id'],
                    'unique' => $fromUnique,
                    'stock' => $qty,
                    'defective_stock' => '0',
                ];
                $inDetail[] = [
                    'product_id' => (int)$d['to_product_id'],
                    'unique' => $toUnique,
                    'stock' => $qty,
                    'defective_stock' => '0',
                ];
            }

            // 防死锁：先统一按 SKU id 升序锁定双方全部 SKU，再按商品 id 升序锁定全部商品主表，最后出入库
            $allProductIds = [];
            foreach ($details as $d) {
                $fp = (int)$d['from_product_id'];
                $tp = (int)$d['to_product_id'];
                if ($fp > 0) {
                    $allProductIds[$fp] = $fp;
                }
                if ($tp > 0) {
                    $allProductIds[$tp] = $tp;
                }
            }
            if ($allUniques) {
                app()->make(\app\dao\product\sku\StoreProductAttrValueDao::class)
                    ->lockAttrValuesByUniques(array_values($allUniques), 0);
            }
            if ($allProductIds) {
                $pids = array_values($allProductIds);
                sort($pids, SORT_NUMERIC);
                // 按 id 升序逐行锁，保证并发方向无关时锁序一致
                foreach ($pids as $pid) {
                    Db::name('store_product')->where('id', $pid)->lock(true)->field('id')->find();
                }
            }

            // 先出库再入库；外层已有事务，isTran=false；SKU+商品主表均已按全局顺序预锁
            // 供货方=总部仓时出库挂 type=0,rid=0；入库挂请货门店 type=1
            $outOrderId = (int)$stockOrderServices->saveData(2, [
                'order_type' => self::OUT_ORDER_TYPE_TRANSFER,
                'stock_time' => $stockTime,
                'remark' => '调拨出库#' . ($info['order_sn'] ?? $id),
                'out_product_detail' => $outDetail,
            ], (int)$fromOwner['type'], (int)$fromOwner['relation_id'], $adminId, true, false);

            $inOrderId = (int)$stockOrderServices->saveData(1, [
                'order_type' => self::IN_ORDER_TYPE_TRANSFER,
                'stock_time' => $stockTime,
                'remark' => '调拨入库#' . ($info['order_sn'] ?? $id),
                'in_product_detail' => $inDetail,
            ], (int)$toOwner['type'], (int)$toOwner['relation_id'], $adminId, true, false);

            if ($outOrderId <= 0 || $inOrderId <= 0) {
                throw new ValidateException('生成调拨出入库单失败');
            }

            // 条件更新防重复确认
            $affected = Db::name('store_stock_transfer')->where([
                'id' => $id,
                'status' => self::STATUS_DRAFT,
            ])->update([
                'status' => self::STATUS_CONFIRMED,
                'confirm_time' => $time,
                'admin_id' => $adminId,
                'out_stock_order_id' => $outOrderId,
                'in_stock_order_id' => $inOrderId,
                'update_time' => $time,
            ]);
            if ($affected === 0) {
                throw new ValidateException('调拨单状态已变更，请刷新后重试');
            }

            /** @var StoreStockRequestServices $reqServices */
            $reqServices = app()->make(StoreStockRequestServices::class);
            if ($requestId > 0 && !$isReverse && $requestLines) {
                $reqServices->applyTransferredQty($requestId, $requestLines);
            }
            if ($isReverse) {
                $this->applyOriginReversedQty($originId, $details);
                // 冲销方向：原调拨是 from->to，冲销单也是 from->to 但数量方向在草稿创建时已互换门店
                // 若冲销单关联了原请货，需回退请货累计
                $origin = $this->dao->get($originId);
                $origin = is_array($origin) ? $origin : ($origin ? $origin->toArray() : []);
                $originRequestId = (int)($origin['request_id'] ?? 0);
                if ($originRequestId > 0) {
                    $revertLines = [];
                    foreach ($details as $d) {
                        $revertLines[] = [
                            'pid' => (int)$d['pid'],
                            'suk' => (string)$d['suk'],
                            'qty' => (string)$d['qty'],
                        ];
                    }
                    $reqServices->revertTransferredQty($originRequestId, $revertLines);
                }
            }

            return [
                'id' => $id,
                'out_stock_order_id' => $outOrderId,
                'in_stock_order_id' => $inOrderId,
                'idempotent' => 0,
            ];
        });
    }

    /**
     * 从已确认原单创建冲销草稿并可选立即确认
     * 冲销：原调入门店出库、原调出门店入库（门店对调）
     */
    public function createReverse(int $originId, array $lines, int $adminId, int $storeScope = 0, bool $autoConfirm = false): array
    {
        $origin = $this->dao->get($originId);
        if (!$origin) {
            throw new ValidateException('原调拨单不存在');
        }
        $origin = is_array($origin) ? $origin : $origin->toArray();
        $this->assertStoreAccess($origin, $storeScope);
        if ((int)$origin['status'] !== self::STATUS_CONFIRMED) {
            throw new ValidateException('仅已确认调拨可冲销');
        }
        if ((int)$origin['origin_transfer_id'] > 0) {
            throw new ValidateException('冲销单不能再次冲销，请对原单操作');
        }

        $originDetails = $this->detailDao->getByTransferId($originId);
        $originMap = [];
        foreach ($originDetails as $d) {
            $originMap[(int)$d['id']] = $d;
        }
        if (!$lines) {
            throw new ValidateException('请填写冲销明细');
        }

        // 冲销：from=原to，to=原from（主体类型一并互换）
        /** @var StockPartyServices $partyServices */
        $partyServices = app()->make(StockPartyServices::class);
        $fromPartyType = $partyServices->partyFromRow($origin['to_party_type'] ?? '', $origin['to_store_id'] ?? 0);
        $toPartyType = $partyServices->partyFromRow($origin['from_party_type'] ?? '', $origin['from_store_id'] ?? 0);
        $fromStoreId = (int)$origin['to_store_id'];
        $toStoreId = (int)$origin['from_store_id'];
        // 冲销若调出为总部：仅平台可操作
        if ($fromPartyType === StockPartyServices::PARTY_HQ && $storeScope > 0) {
            throw new ValidateException('冲销涉及总部仓出库时，仅平台可操作');
        }
        $normalized = [];
        $originDetails = array_values($originMap);
        $this->attachSkuDecimalScale($originDetails, 'from_product_id', 'from_unique');
        $scaleMap = [];
        foreach ($originDetails as $odScale) {
            $scaleMap[(int)$odScale['id']] = (int)($odScale['decimal_scale'] ?? 0) > 0 ? 2 : 0;
        }
        /** @var StoreStockCrossSkuServices $cross */
        $cross = app()->make(StoreStockCrossSkuServices::class);
        foreach ($lines as $idx => $line) {
            $detailId = (int)($line['detail_id'] ?? 0);
            if ($detailId <= 0 || !isset($originMap[$detailId])) {
                throw new ValidateException('第' . ($idx + 1) . '行：冲销明细无效');
            }
            $od = $originMap[$detailId];
            $scale = $scaleMap[$detailId] ?? 0;
            $qty = $cross->parseQty($line['qty'] ?? '', '第' . ($idx + 1) . '行', $scale);
            $reversible = bcsub((string)$od['qty'], (string)$od['reversed_qty'], $scale > 0 ? 2 : 0);
            if (bccomp($qty, $reversible, 4) > 0) {
                throw new ValidateException('第' . ($idx + 1) . '行：冲销数量超过可冲销数量');
            }
            // 冲销后：原 to 出库、原 from 入库 → 商品 ID 对调
            $normalized[] = [
                'pid' => (int)$od['pid'],
                'suk' => (string)$od['suk'],
                'from_product_id' => (int)$od['to_product_id'],
                'from_unique' => (string)$od['to_unique'],
                'to_product_id' => (int)$od['from_product_id'],
                'to_unique' => (string)$od['from_unique'],
                'qty' => $qty,
                'reversed_qty' => '0',
                'stock_unit' => (string)($od['stock_unit'] ?? ''),
                '_origin_detail_id' => $detailId,
            ];
        }

        $time = time();
        $newId = (int)$this->transaction(function () use ($originId, $origin, $fromStoreId, $toStoreId, $fromPartyType, $toPartyType, $normalized, $adminId, $time) {
            $orderSn = $this->makeSn('TFR');
            $res = $this->dao->save([
                'order_sn' => $orderSn,
                'request_id' => 0, // 冲销不直接挂请货，确认时按原单回退
                'origin_transfer_id' => $originId,
                'from_store_id' => $fromStoreId,
                'from_party_type' => $fromPartyType,
                'to_store_id' => $toStoreId,
                'to_party_type' => $toPartyType,
                'status' => self::STATUS_DRAFT,
                'remark' => '冲销原单#' . ($origin['order_sn'] ?? $originId),
                'admin_id' => $adminId,
                'add_time' => $time,
                'update_time' => $time,
            ]);
            $tid = (int)$res->id;
            $rows = [];
            foreach ($normalized as $d) {
                unset($d['_origin_detail_id']);
                $rows[] = array_merge($d, [
                    'transfer_id' => $tid,
                    'add_time' => $time,
                ]);
            }
            $this->detailDao->saveAll($rows);
            return $tid;
        });

        if ($autoConfirm) {
            $result = $this->confirm($newId, $adminId, $storeScope);
            $result['reverse_id'] = $newId;
            return $result;
        }
        return ['id' => $newId, 'reverse_id' => $newId];
    }

    protected function assertReverseQty(int $originId, array $reverseDetails): void
    {
        $originDetails = $this->detailDao->lockByTransferId($originId);
        $map = [];
        foreach ($originDetails as $d) {
            $key = (int)$d['pid'] . '|' . (string)$d['suk'];
            $map[$key] = $d;
        }
        foreach ($reverseDetails as $d) {
            $key = (int)$d['pid'] . '|' . (string)$d['suk'];
            if (!isset($map[$key])) {
                throw new ValidateException('冲销明细不在原调拨单内');
            }
            $od = $map[$key];
            $reversible = bcsub((string)$od['qty'], (string)$od['reversed_qty'], 4);
            if (bccomp((string)$d['qty'], $reversible, 4) > 0) {
                throw new ValidateException('冲销数量超过可冲销数量');
            }
        }
    }

    protected function applyOriginReversedQty(int $originId, array $reverseDetails): void
    {
        $originDetails = $this->detailDao->lockByTransferId($originId);
        $map = [];
        foreach ($originDetails as $d) {
            $map[(int)$d['pid'] . '|' . (string)$d['suk']] = $d;
        }
        foreach ($reverseDetails as $d) {
            $key = (int)$d['pid'] . '|' . (string)$d['suk'];
            if (!isset($map[$key])) {
                continue;
            }
            $od = $map[$key];
            $newReversed = bcadd((string)$od['reversed_qty'], (string)$d['qty'], 4);
            if (bccomp($newReversed, (string)$od['qty'], 4) > 0) {
                throw new ValidateException('冲销累计超过原调拨数量');
            }
            Db::name('store_stock_transfer_detail')->where('id', (int)$od['id'])->update([
                'reversed_qty' => $newReversed,
            ]);
        }
    }

    protected function normalizeFromRequest(array $req, array $details): array
    {
        $reqDetails = $req['details'] ?? [];
        $scaleRows = [];
        foreach ($reqDetails as $d) {
            $scaleRows[] = [
                'from_product_id' => (int)($d['supply_product_id'] ?? 0),
                'from_unique' => (string)($d['supply_unique'] ?? ''),
            ];
        }
        $this->attachSkuDecimalScale($scaleRows, 'from_product_id', 'from_unique');
        $scaleBySupply = [];
        foreach ($scaleRows as $sr) {
            $scaleBySupply[(int)$sr['from_product_id'] . '|' . (string)$sr['from_unique']] = (int)($sr['decimal_scale'] ?? 0);
        }
        $map = [];
        foreach ($reqDetails as $d) {
            $map[(int)$d['id']] = $d;
            $map[(int)$d['pid'] . '|' . (string)$d['suk']] = $d;
        }
        if (!$details) {
            throw new ValidateException('请填写调拨数量');
        }
        /** @var StoreStockCrossSkuServices $cross */
        $cross = app()->make(StoreStockCrossSkuServices::class);
        $cross->assertDetailLimit($details, '调拨明细');
        $out = [];
        $seen = [];
        foreach ($details as $idx => $row) {
            $detailId = (int)($row['request_detail_id'] ?? $row['detail_id'] ?? 0);
            $pid = (int)($row['pid'] ?? 0);
            $suk = trim((string)($row['suk'] ?? ''));
            $rd = null;
            if ($detailId > 0 && isset($map[$detailId])) {
                $rd = $map[$detailId];
            } elseif ($pid > 0 && $suk !== '' && isset($map[$pid . '|' . $suk])) {
                $rd = $map[$pid . '|' . $suk];
            }
            if (!$rd) {
                throw new ValidateException('第' . ($idx + 1) . '行：不在请货明细内');
            }
            $scaleKey = (int)$rd['supply_product_id'] . '|' . (string)$rd['supply_unique'];
            $scale = $scaleBySupply[$scaleKey] ?? 0;
            $qty = $cross->parseQty($row['qty'] ?? '', '第' . ($idx + 1) . '行', $scale);
            $remain = bcsub((string)$rd['qty'], (string)$rd['transferred_qty'], $scale > 0 ? 2 : 0);
            if (bccomp($qty, $remain, 4) > 0) {
                throw new ValidateException('第' . ($idx + 1) . '行：超过请货剩余数量（剩余 ' . $remain . '）');
            }
            $key = (int)$rd['pid'] . '|' . (string)$rd['suk'];
            if (isset($seen[$key])) {
                throw new ValidateException('第' . ($idx + 1) . '行：规格重复');
            }
            $seen[$key] = true;
            $out[] = [
                'pid' => (int)$rd['pid'],
                'suk' => (string)$rd['suk'],
                'from_product_id' => (int)$rd['supply_product_id'],
                'from_unique' => (string)$rd['supply_unique'],
                'to_product_id' => (int)$rd['request_product_id'],
                'to_unique' => (string)$rd['request_unique'],
                'qty' => $qty,
                'reversed_qty' => '0',
                'stock_unit' => (string)($rd['stock_unit'] ?? ''),
            ];
        }
        return $out;
    }

    protected function normalizeFreeDetails(
        int $fromStoreId,
        int $toStoreId,
        array $details,
        string $fromParty = StockPartyServices::PARTY_STORE,
        string $toParty = StockPartyServices::PARTY_STORE
    ): array {
        if (!$details) {
            throw new ValidateException('请选择调拨商品');
        }
        /** @var StoreStockCrossSkuServices $cross */
        $cross = app()->make(StoreStockCrossSkuServices::class);
        $cross->assertDetailLimit($details, '调拨明细');
        $pairs = [];
        $rawQtys = [];
        foreach ($details as $row) {
            $pairs[] = [
                'pid' => (int)($row['pid'] ?? 0),
                'suk' => trim((string)($row['suk'] ?? '')),
            ];
            $rawQtys[] = $row['qty'] ?? '';
        }
        $resolved = $cross->resolveTransferPairsBatch($pairs, $fromStoreId, $toStoreId, $fromParty, $toParty);
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
                'from_product_id' => $r['from_product_id'],
                'from_unique' => $r['from_unique'],
                'to_product_id' => $r['to_product_id'],
                'to_unique' => $r['to_unique'],
                'qty' => $qty,
                'reversed_qty' => '0',
                'stock_unit' => $r['stock_unit'],
            ];
        }
        return $out;
    }

    protected function saveDetails(int $transferId, array $details, int $time): void
    {
        $rows = [];
        foreach ($details as $d) {
            $rows[] = [
                'transfer_id' => $transferId,
                'pid' => (int)$d['pid'],
                'suk' => (string)$d['suk'],
                'from_product_id' => (int)$d['from_product_id'],
                'from_unique' => (string)$d['from_unique'],
                'to_product_id' => (int)$d['to_product_id'],
                'to_unique' => (string)$d['to_unique'],
                'qty' => (string)$d['qty'],
                'reversed_qty' => (string)($d['reversed_qty'] ?? '0'),
                'stock_unit' => (string)($d['stock_unit'] ?? ''),
                'add_time' => $time,
            ];
        }
        if ($rows) {
            $this->detailDao->saveAll($rows);
        }
    }

    /**
     * 确认权限：
     * - 平台可确认任意单
     * - 总部仓调出：仅平台可确认
     * - 门店调出：仅当前门店=调出方可确认（禁止调入店代确认扣他店库存）
     */
    protected function assertConfirmPermission(string $fromPartyType, int $fromStoreId, int $storeScope): void
    {
        if ($storeScope <= 0) {
            return;
        }
        if ($fromPartyType === StockPartyServices::PARTY_HQ) {
            throw new ValidateException('供货方/调出方为总部仓时，仅平台可确认调拨，门店不能扣减总部库存');
        }
        if ($fromPartyType !== StockPartyServices::PARTY_STORE || $fromStoreId !== $storeScope) {
            throw new ValidateException('仅调出门店可确认调拨，调入门店不可扣减其他门店库存');
        }
    }

    /** 调拨人必须是门店侧在职店员（总部侧无店员） */
    protected function assertTransferStaff(
        int $fromStoreId,
        int $toStoreId,
        int $staffId,
        string $fromParty = StockPartyServices::PARTY_STORE,
        string $toParty = StockPartyServices::PARTY_STORE
    ): void {
        if ($staffId <= 0) {
            throw new ValidateException('请选择调拨人');
        }
        /** @var SystemStoreStaffServices $staffServices */
        $staffServices = app()->make(SystemStoreStaffServices::class);
        $staff = $staffServices->get($staffId, ['id', 'store_id', 'staff_name', 'is_del', 'status']);
        if (!$staff || (int)$staff['is_del'] === 1) {
            throw new ValidateException('调拨人不存在');
        }
        $staffStoreId = (int)$staff['store_id'];
        $allowed = [];
        if ($fromParty === StockPartyServices::PARTY_STORE && $fromStoreId > 0) {
            $allowed[$fromStoreId] = 1;
        }
        if ($toParty === StockPartyServices::PARTY_STORE && $toStoreId > 0) {
            $allowed[$toStoreId] = 1;
        }
        if (!$allowed || !isset($allowed[$staffStoreId])) {
            throw new ValidateException('调拨人必须是调出或调入门店的员工');
        }
        if (isset($staff['status']) && (int)$staff['status'] !== 1) {
            throw new ValidateException('调拨人已停用');
        }
    }

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
            $fromParty = $partyServices->partyFromRow($row['from_party_type'] ?? '', $row['from_store_id'] ?? 0);
            $toParty = $partyServices->partyFromRow($row['to_party_type'] ?? '', $row['to_store_id'] ?? 0);
            $row['from_party_type'] = $fromParty;
            $row['to_party_type'] = $toParty;
            $row['status_name'] = $this->statusName[(int)$row['status']] ?? '';
            $row['from_store_name'] = $partyServices->label(
                $fromParty,
                (int)$row['from_store_id'],
                (string)($storeNames[(int)$row['from_store_id']] ?? '')
            );
            $row['to_store_name'] = $partyServices->label(
                $toParty,
                (int)$row['to_store_id'],
                (string)($storeNames[(int)$row['to_store_id']] ?? '')
            );
            $row['is_reverse'] = (int)($row['origin_transfer_id'] ?? 0) > 0 ? 1 : 0;
            $row['transfer_staff_name'] = $staffNames[(int)($row['transfer_staff_id'] ?? 0)] ?? '';
            $row['create_admin_name'] = $creatorNames[$this->creatorKey($row)] ?? '';
            $row['transfer_date'] = !empty($row['transfer_date']) ? date('Y-m-d', (int)$row['transfer_date']) : '';
            $row['add_time'] = !empty($row['add_time']) ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
            $row['confirm_time'] = !empty($row['confirm_time']) ? date('Y-m-d H:i:s', (int)$row['confirm_time']) : '';
        }
        unset($row);
    }

    protected function parseTransferDate($value): int
    {
        if ($value === null || $value === '') {
            return (int)strtotime(date('Y-m-d'));
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return (int)strtotime(date('Y-m-d'));
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ValidateException('调拨时间格式不正确');
        }
        return (int)strtotime(date('Y-m-d', $ts));
    }

    protected function creatorKey(array $row): string
    {
        return (int)($row['create_type'] ?? 0) . ':' . (int)($row['create_uid'] ?? 0);
    }

    protected function staffNameMap(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            $sid = (int)($row['transfer_staff_id'] ?? 0);
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
        $fromParty = $partyServices->partyFromRow($info['from_party_type'] ?? '', $info['from_store_id'] ?? 0);
        $toParty = $partyServices->partyFromRow($info['to_party_type'] ?? '', $info['to_store_id'] ?? 0);
        $ok = ($fromParty === StockPartyServices::PARTY_STORE && (int)$info['from_store_id'] === $storeScope)
            || ($toParty === StockPartyServices::PARTY_STORE && (int)$info['to_store_id'] === $storeScope);
        if (!$ok) {
            throw new ValidateException('无权操作该调拨单');
        }
    }

    protected function storeNameMap(array $list): array
    {
        $ids = [];
        foreach ($list as $row) {
            $ids[(int)($row['from_store_id'] ?? 0)] = 1;
            $ids[(int)($row['to_store_id'] ?? 0)] = 1;
        }
        unset($ids[0]);
        if (!$ids) {
            return [];
        }
        return Db::name('system_store')->whereIn('id', array_keys($ids))->column('name', 'id') ?: [];
    }

    protected function makeSn(string $prefix): string
    {
        return $prefix . date('YmdHis') . substr((string)microtime(true), -4) . random_int(100, 999);
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
