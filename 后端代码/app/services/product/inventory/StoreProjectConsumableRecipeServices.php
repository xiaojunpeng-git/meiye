<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\dao\product\inventory\StoreProjectConsumableRecipeDao;
use app\dao\product\inventory\StoreProjectConsumableRecipeDetailDao;
use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 项目耗材配方（院装 D2）
 *
 * 归属：type=0 平台(relation_id=0) / type=1 门店(relation_id=storeId)。
 * 每个「归属 + 项目SKU」至多一条配方；编辑替换明细并递增 version。
 * 配方后续修改不影响历史核销（历史用量以院装流水快照为准）。
 */
class StoreProjectConsumableRecipeServices extends BaseServices
{
    use ServicesTrait;

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    public $statusName = [
        0 => '已停用',
        1 => '启用中',
    ];

    /** @var StoreProjectConsumableRecipeDetailDao */
    protected $detailDao;

    public function __construct(StoreProjectConsumableRecipeDao $dao, StoreProjectConsumableRecipeDetailDao $detailDao)
    {
        $this->dao = $dao;
        $this->detailDao = $detailDao;
    }

    /** 归属解析：$storeScope>0 门店，否则平台 */
    protected function owner(int $storeScope): array
    {
        return $storeScope > 0 ? [1, $storeScope] : [0, 0];
    }

    public function getList(array $where, int $storeScope = 0): array
    {
        [$type, $relationId] = $this->owner($storeScope);
        $where['type'] = $type;
        $where['relation_id'] = $relationId;
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        $count = $this->dao->count($where);
        $productNames = $this->productNameMap(array_column($list, 'project_product_id'));
        $recipeIds = array_column($list, 'id');
        $detailCounts = $this->detailCountMap($recipeIds);
        foreach ($list as &$row) {
            $rid = (int)$row['id'];
            $row['status_name'] = $this->statusName[(int)$row['status']] ?? '';
            $row['project_name'] = $productNames[(int)$row['project_product_id']] ?? '';
            $row['consumable_count'] = $detailCounts[$rid] ?? 0;
            $row['add_time'] = $row['add_time'] ? date('Y-m-d H:i:s', (int)$row['add_time']) : '';
            $row['update_time'] = $row['update_time'] ? date('Y-m-d H:i:s', (int)$row['update_time']) : '';
        }
        unset($row);
        return compact('list', 'count');
    }

    public function detail(int $id, int $storeScope = 0): array
    {
        $info = $this->dao->get($id);
        if (!$info) {
            throw new ValidateException('配方不存在');
        }
        $info = is_array($info) ? $info : $info->toArray();
        $this->assertOwner($info, $storeScope);
        $details = $this->detailDao->getByRecipeId($id);
        $consumableNames = $this->productNameMap(array_column($details, 'consumable_product_id'));
        foreach ($details as &$d) {
            $d['consumable_name'] = $consumableNames[(int)$d['consumable_product_id']] ?? '';
            $d['qty_per_writeoff'] = $this->trimQty((string)$d['qty_per_writeoff']);
        }
        unset($d);
        $projectNames = $this->productNameMap([(int)$info['project_product_id']]);
        $info['status_name'] = $this->statusName[(int)$info['status']] ?? '';
        $info['project_name'] = $projectNames[(int)$info['project_product_id']] ?? '';
        $info['add_time'] = $info['add_time'] ? date('Y-m-d H:i:s', (int)$info['add_time']) : '';
        $info['update_time'] = $info['update_time'] ? date('Y-m-d H:i:s', (int)$info['update_time']) : '';
        $info['details'] = $details;
        return $info;
    }

    /**
     * 保存配方（新建或编辑），同一归属+项目SKU唯一
     */
    public function save(int $id, array $data, int $adminId, int $storeScope = 0): int
    {
        [$type, $relationId] = $this->owner($storeScope);
        $projectProductId = (int)($data['project_product_id'] ?? 0);
        $projectUnique = trim((string)($data['project_unique'] ?? ''));
        $status = (int)($data['status'] ?? self::STATUS_ENABLED) === self::STATUS_DISABLED ? self::STATUS_DISABLED : self::STATUS_ENABLED;
        $details = is_array($data['details'] ?? null) ? $data['details'] : [];

        $this->assertProject($type, $relationId, $projectProductId, $projectUnique);
        $normalized = $this->normalizeDetails($type, $relationId, $details);
        $time = time();

        return (int)$this->transaction(function () use ($id, $type, $relationId, $projectProductId, $projectUnique, $status, $normalized, $adminId, $time) {
            if ($id > 0) {
                $info = $this->dao->lockById($id);
                if (!$info) {
                    throw new ValidateException('配方不存在');
                }
                if ((int)$info['type'] !== $type || (int)$info['relation_id'] !== $relationId) {
                    throw new ValidateException('无权操作该配方');
                }
                // 编辑允许改绑项目SKU；改绑后需保证唯一
                $dup = $this->dao->findByOwnerProject($type, $relationId, $projectProductId, $projectUnique);
                if ($dup && (int)$dup['id'] !== $id) {
                    throw new ValidateException('该项目规格已存在配方，请勿重复创建');
                }
                $this->dao->update($id, [
                    'project_product_id' => $projectProductId,
                    'project_unique' => $projectUnique,
                    'status' => $status,
                    'version' => (int)$info['version'] + 1,
                    'update_time' => $time,
                ]);
                $this->detailDao->deleteByRecipeId($id);
                $this->saveDetails($id, $normalized, $time);
                return $id;
            }
            $dup = $this->dao->findByOwnerProject($type, $relationId, $projectProductId, $projectUnique);
            if ($dup) {
                throw new ValidateException('该项目规格已存在配方，请勿重复创建');
            }
            $res = $this->dao->save([
                'type' => $type,
                'relation_id' => $relationId,
                'project_product_id' => $projectProductId,
                'project_unique' => $projectUnique,
                'version' => 1,
                'status' => $status,
                'add_time' => $time,
                'update_time' => $time,
            ]);
            $newId = (int)$res->id;
            $this->saveDetails($newId, $normalized, $time);
            return $newId;
        });
    }

    public function setStatus(int $id, int $status, int $storeScope = 0): bool
    {
        $status = $status === self::STATUS_DISABLED ? self::STATUS_DISABLED : self::STATUS_ENABLED;
        return (bool)$this->transaction(function () use ($id, $status, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('配方不存在');
            }
            $this->assertOwner($info, $storeScope);
            $this->dao->update($id, [
                'status' => $status,
                'version' => (int)$info['version'] + 1,
                'update_time' => time(),
            ]);
            return true;
        });
    }

    public function delete(int $id, int $storeScope = 0): bool
    {
        return (bool)$this->transaction(function () use ($id, $storeScope) {
            $info = $this->dao->lockById($id);
            if (!$info) {
                throw new ValidateException('配方不存在');
            }
            $this->assertOwner($info, $storeScope);
            $this->detailDao->deleteByRecipeId($id);
            $this->dao->delete($id);
            return true;
        });
    }

    protected function assertOwner(array $info, int $storeScope): void
    {
        [$type, $relationId] = $this->owner($storeScope);
        if ((int)$info['type'] !== $type || (int)$info['relation_id'] !== $relationId) {
            throw new ValidateException('无权操作该配方');
        }
    }

    protected function assertProject(int $type, int $relationId, int $projectProductId, string $projectUnique): void
    {
        if ($projectProductId <= 0 || $projectUnique === '') {
            throw new ValidateException('请选择项目规格');
        }
        $product = Db::name('store_product')->where('id', $projectProductId)->find();
        if (!$product || (int)($product['is_del'] ?? 0) === 1) {
            throw new ValidateException('项目不存在');
        }
        if ((int)$product['type'] !== $type || (int)$product['relation_id'] !== $relationId) {
            throw new ValidateException('项目不属于当前' . ($type > 0 ? '门店' : '平台'));
        }
        if ((int)$product['product_type'] !== 6) {
            throw new ValidateException('院装配方只能绑定项目');
        }
        $sku = Db::name('store_product_attr_value')->where([
            'product_id' => $projectProductId,
            'unique' => $projectUnique,
            'type' => 0,
        ])->find();
        if (!$sku) {
            throw new ValidateException('项目规格不存在');
        }
    }

    /**
     * 校验并规范化配方明细（含耗材归属/院装开关/SKU 有效性/用量精度）
     */
    protected function normalizeDetails(int $type, int $relationId, array $details): array
    {
        if (!$details) {
            throw new ValidateException('请至少添加一项耗材');
        }
        $normalized = [];
        $seen = [];
        foreach ($details as $d) {
            $pid = (int)($d['consumable_product_id'] ?? 0);
            $unique = trim((string)($d['consumable_unique'] ?? ''));
            $qty = trim((string)($d['qty_per_writeoff'] ?? ''));
            if ($pid <= 0 || $unique === '') {
                throw new ValidateException('耗材规格缺失');
            }
            $key = $pid . '|' . $unique;
            if (isset($seen[$key])) {
                throw new ValidateException('同一耗材规格不能重复添加');
            }
            $seen[$key] = true;

            if ($qty === '' || !is_numeric($qty) || bccomp($qty, '0', 4) <= 0) {
                throw new ValidateException('耗材单次用量必须大于0');
            }
            if (!preg_match('/^\d+(\.\d{1,4})?$/', $qty)) {
                throw new ValidateException('耗材单次用量最多4位小数');
            }

            $product = Db::name('store_product')->where('id', $pid)->find();
            if (!$product || (int)($product['is_del'] ?? 0) === 1) {
                throw new ValidateException('耗材商品不存在');
            }
            if ((int)$product['type'] !== $type || (int)$product['relation_id'] !== $relationId) {
                throw new ValidateException('耗材不属于当前' . ($type > 0 ? '门店' : '平台'));
            }
            if ((int)($product['is_inventory'] ?? 0) !== 1) {
                throw new ValidateException('耗材必须参与库存管理：' . ($product['store_name'] ?? $pid));
            }
            if ((int)($product['salon_stock_enabled'] ?? 0) !== 1) {
                throw new ValidateException('耗材未开启「可作为院装耗材」：' . ($product['store_name'] ?? $pid));
            }
            $sku = Db::name('store_product_attr_value')->where([
                'product_id' => $pid,
                'unique' => $unique,
                'type' => 0,
            ])->field('stock_unit')->find();
            if (!$sku) {
                throw new ValidateException('耗材规格不存在');
            }
            $normalized[] = [
                'consumable_product_id' => $pid,
                'consumable_unique' => $unique,
                'qty_per_writeoff' => bcadd($qty, '0', 4),
                'stock_unit' => (string)($sku['stock_unit'] ?? ''),
            ];
        }
        return $normalized;
    }

    protected function saveDetails(int $recipeId, array $normalized, int $time): void
    {
        $rows = [];
        foreach ($normalized as $n) {
            $rows[] = [
                'recipe_id' => $recipeId,
                'consumable_product_id' => $n['consumable_product_id'],
                'consumable_unique' => $n['consumable_unique'],
                'qty_per_writeoff' => $n['qty_per_writeoff'],
                'stock_unit' => $n['stock_unit'],
                'add_time' => $time,
            ];
        }
        if ($rows) {
            $this->detailDao->saveAll($rows);
        }
    }

    protected function productNameMap(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $rows = Db::name('store_product')->whereIn('id', $productIds)->column('store_name', 'id');
        $map = [];
        foreach ($rows as $id => $name) {
            $map[(int)$id] = (string)$name;
        }
        return $map;
    }

    protected function detailCountMap(array $recipeIds): array
    {
        $recipeIds = array_values(array_unique(array_filter(array_map('intval', $recipeIds))));
        if (!$recipeIds) {
            return [];
        }
        $rows = Db::name('store_project_consumable_recipe_detail')
            ->whereIn('recipe_id', $recipeIds)
            ->group('recipe_id')
            ->column('count(*) as c', 'recipe_id');
        $map = [];
        foreach ($rows as $rid => $c) {
            $map[(int)$rid] = (int)$c;
        }
        return $map;
    }

    protected function trimQty(string $qty): string
    {
        $formatted = number_format((float)$qty, 4, '.', '');
        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        return $formatted === '' ? '0' : $formatted;
    }
}
