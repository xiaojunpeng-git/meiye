<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 跨店同源 SKU 映射（平台 pid + suk）
 */
class StoreStockCrossSkuServices extends BaseServices
{
    use ServicesTrait;

    /** 单张请货/调拨明细上限，防止超大单卡顿 */
    public const MAX_DETAIL_LINES = 200;

    /**
     * 解析双方门店已存在且参与库存的同源 SKU
     */
    public function resolvePair(int $pid, string $suk, int $requestStoreId, int $supplyStoreId): array
    {
        $rows = $this->resolvePairsBatch([['pid' => $pid, 'suk' => $suk]], $requestStoreId, $supplyStoreId, '请货门店', '供货门店');
        $key = $pid . '|' . trim($suk);
        if (!isset($rows[$key])) {
            throw new ValidateException('无法解析跨店规格：' . $key);
        }
        return $rows[$key];
    }

    /**
     * 自由调拨：from=调出(供货)，to=调入(请货)
     */
    public function resolveTransferPair(int $pid, string $suk, int $fromStoreId, int $toStoreId): array
    {
        $rows = $this->resolveTransferPairsBatch([['pid' => $pid, 'suk' => $suk]], $fromStoreId, $toStoreId);
        $key = $pid . '|' . trim($suk);
        if (!isset($rows[$key])) {
            throw new ValidateException('无法解析跨店规格：' . $key);
        }
        return $rows[$key];
    }

    /**
     * 批量解析请货双方映射（一次查商品 + 一次查 SKU）
     * @return array keyed by "pid|suk"
     */
    public function resolvePairsBatch(array $pairs, int $requestStoreId, int $supplyStoreId, string $reqLabel = '请货门店', string $supLabel = '供货门店'): array
    {
        $normalized = $this->normalizePairKeys($pairs);
        $map = $this->batchLoadStoreSkus($normalized, $requestStoreId, $supplyStoreId, $reqLabel, $supLabel);
        $out = [];
        foreach ($normalized as $item) {
            $key = $item['key'];
            $req = $map['a'][$key] ?? null;
            $sup = $map['b'][$key] ?? null;
            if (!$req) {
                throw new ValidateException($reqLabel . '缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . '，请先同步商品到该门店并开启库存');
            }
            if (!$sup) {
                throw new ValidateException($supLabel . '缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . '，请先同步商品到该门店并开启库存');
            }
            $out[$key] = [
                'pid' => $item['pid'],
                'suk' => $item['suk'],
                'request_product_id' => $req['product_id'],
                'request_unique' => $req['unique'],
                'supply_product_id' => $sup['product_id'],
                'supply_unique' => $sup['unique'],
                'product_name' => $req['product_name'] ?: $sup['product_name'],
                'stock_unit' => $req['stock_unit'] ?: $sup['stock_unit'],
                'request_stock' => $req['stock'],
                'supply_stock' => $sup['stock'],
            ];
        }
        return $out;
    }

    /**
     * 批量解析调拨双方映射
     * @return array keyed by "pid|suk"
     */
    public function resolveTransferPairsBatch(array $pairs, int $fromStoreId, int $toStoreId): array
    {
        $normalized = $this->normalizePairKeys($pairs);
        $map = $this->batchLoadStoreSkus($normalized, $fromStoreId, $toStoreId, '调出门店', '调入门店');
        $out = [];
        foreach ($normalized as $item) {
            $key = $item['key'];
            $from = $map['a'][$key] ?? null;
            $to = $map['b'][$key] ?? null;
            if (!$from) {
                throw new ValidateException('调出门店缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . '，请先同步商品到该门店并开启库存');
            }
            if (!$to) {
                throw new ValidateException('调入门店缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . '，请先同步商品到该门店并开启库存');
            }
            $out[$key] = [
                'pid' => $item['pid'],
                'suk' => $item['suk'],
                'from_product_id' => $from['product_id'],
                'from_unique' => $from['unique'],
                'to_product_id' => $to['product_id'],
                'to_unique' => $to['unique'],
                'product_name' => $from['product_name'] ?: $to['product_name'],
                'stock_unit' => $from['stock_unit'] ?: $to['stock_unit'],
                'from_stock' => $from['stock'],
                'to_stock' => $to['stock'],
            ];
        }
        return $out;
    }

    /**
     * 双店可选同源 SKU：JOIN + 数据库 count/分页（禁止全量加载后 PHP 分页）
     */
    public function listSharedSkus(int $storeA, int $storeB, string $keyword = '', int $page = 1, int $limit = 20): array
    {
        if ($storeA <= 0 || $storeB <= 0 || $storeA === $storeB) {
            throw new ValidateException('请选择两个不同门店');
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $kw = trim($keyword);
        $offset = ($page - 1) * $limit;

        $baseSql = '
            FROM `eb_store_product` pa
            INNER JOIN `eb_store_product` pb
                ON pb.pid = pa.pid AND pb.type = 1 AND pb.relation_id = ? AND pb.is_del = 0 AND pb.is_inventory = 1 AND pb.pid > 0
            INNER JOIN `eb_store_product_attr_value` sa
                ON sa.product_id = pa.id AND sa.type = 0 AND sa.suk <> \'\'
            INNER JOIN `eb_store_product_attr_value` sb
                ON sb.product_id = pb.id AND sb.type = 0 AND sb.suk = sa.suk
            WHERE pa.type = 1 AND pa.relation_id = ? AND pa.is_del = 0 AND pa.is_inventory = 1 AND pa.pid > 0
        ';
        $binds = [$storeB, $storeA];
        if ($kw !== '') {
            $baseSql .= ' AND pa.store_name LIKE ? ';
            $binds[] = '%' . $kw . '%';
        }

        $countRow = Db::query('SELECT COUNT(*) AS cnt ' . $baseSql, $binds);
        $count = (int)($countRow[0]['cnt'] ?? 0);
        if ($count <= 0) {
            return ['list' => [], 'count' => 0];
        }

        $listSql = '
            SELECT
                pa.pid AS pid,
                sa.suk AS suk,
                pa.store_name AS product_name,
                IFNULL(sa.stock_unit, IFNULL(sb.stock_unit, \'\')) AS stock_unit,
                IFNULL(sa.bar_code, \'\') AS bar_code,
                IFNULL(sa.code, \'\') AS code,
                pa.id AS store_a_product_id,
                sa.unique AS store_a_unique,
                sa.stock AS store_a_stock,
                pb.id AS store_b_product_id,
                sb.unique AS store_b_unique,
                sb.stock AS store_b_stock
        ' . $baseSql . ' ORDER BY pa.pid ASC, sa.suk ASC LIMIT ' . (int)$offset . ', ' . (int)$limit;

        $rows = Db::query($listSql, $binds);
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'pid' => (int)$row['pid'],
                'suk' => (string)$row['suk'],
                'product_name' => (string)$row['product_name'],
                'stock_unit' => (string)$row['stock_unit'],
                'bar_code' => (string)$row['bar_code'],
                'code' => (string)$row['code'],
                'store_a_product_id' => (int)$row['store_a_product_id'],
                'store_a_unique' => (string)$row['store_a_unique'],
                'store_a_stock' => (string)$row['store_a_stock'],
                'store_b_product_id' => (int)$row['store_b_product_id'],
                'store_b_unique' => (string)$row['store_b_unique'],
                'store_b_stock' => (string)$row['store_b_stock'],
            ];
        }
        return compact('list', 'count');
    }

    public function assertDetailLimit(array $details, string $label = '明细'): void
    {
        if (count($details) > self::MAX_DETAIL_LINES) {
            throw new ValidateException($label . '最多 ' . self::MAX_DETAIL_LINES . ' 行，请拆单后再提交');
        }
    }

    /**
     * 校验并规范化数量（非法格式友好提示）
     */
    public function parseQty($qty, string $rowLabel): string
    {
        if ($qty === null || $qty === '') {
            throw new ValidateException($rowLabel . '：请填写数量');
        }
        if (is_string($qty)) {
            $qty = trim($qty);
        }
        if (!is_numeric($qty)) {
            throw new ValidateException($rowLabel . '：数量格式不正确，请填写数字（最多4位小数）');
        }
        $num = bcadd((string)$qty, '0', 4);
        if (bccomp($num, '0', 4) <= 0) {
            throw new ValidateException($rowLabel . '：数量必须大于0');
        }
        return $num;
    }

    protected function normalizePairKeys(array $pairs): array
    {
        if (!$pairs) {
            throw new ValidateException('请选择商品规格');
        }
        if (count($pairs) > self::MAX_DETAIL_LINES) {
            throw new ValidateException('明细最多 ' . self::MAX_DETAIL_LINES . ' 行，请拆单后再提交');
        }
        $out = [];
        $seen = [];
        foreach ($pairs as $idx => $row) {
            $pid = (int)($row['pid'] ?? 0);
            $suk = trim((string)($row['suk'] ?? ''));
            $rowLabel = '第' . ($idx + 1) . '行';
            if ($pid <= 0) {
                throw new ValidateException($rowLabel . '：门店自产商品（无平台源）不支持跨店请货/调拨，请先同步平台商品');
            }
            if ($suk === '') {
                throw new ValidateException($rowLabel . '：规格不能为空');
            }
            $key = $pid . '|' . $suk;
            if (isset($seen[$key])) {
                throw new ValidateException($rowLabel . '：同一规格不可重复');
            }
            $seen[$key] = true;
            $out[] = ['pid' => $pid, 'suk' => $suk, 'key' => $key];
        }
        return $out;
    }

    /**
     * 批量加载两店商品+SKU，返回 ['a'=>[pid|suk=>...], 'b'=>...]
     */
    protected function batchLoadStoreSkus(array $normalized, int $storeA, int $storeB, string $labelA, string $labelB): array
    {
        $pids = [];
        $suks = [];
        foreach ($normalized as $item) {
            $pids[$item['pid']] = $item['pid'];
            $suks[$item['suk']] = $item['suk'];
        }
        $pidList = array_values($pids);
        $sukList = array_values($suks);

        $productsA = Db::name('store_product')
            ->where(['type' => 1, 'relation_id' => $storeA, 'is_del' => 0])
            ->whereIn('pid', $pidList)
            ->field('id,pid,store_name,is_inventory')
            ->select()->toArray();
        $productsB = Db::name('store_product')
            ->where(['type' => 1, 'relation_id' => $storeB, 'is_del' => 0])
            ->whereIn('pid', $pidList)
            ->field('id,pid,store_name,is_inventory')
            ->select()->toArray();

        $aByPid = [];
        foreach ($productsA as $p) {
            if ((int)($p['is_inventory'] ?? 0) !== 1) {
                throw new ValidateException($labelA . '商品未开启参与库存管理：' . ($p['store_name'] ?? $p['pid']));
            }
            $aByPid[(int)$p['pid']] = $p;
        }
        $bByPid = [];
        foreach ($productsB as $p) {
            if ((int)($p['is_inventory'] ?? 0) !== 1) {
                throw new ValidateException($labelB . '商品未开启参与库存管理：' . ($p['store_name'] ?? $p['pid']));
            }
            $bByPid[(int)$p['pid']] = $p;
        }

        $productIds = [];
        foreach ($aByPid as $p) {
            $productIds[] = (int)$p['id'];
        }
        foreach ($bByPid as $p) {
            $productIds[] = (int)$p['id'];
        }
        $skuRows = [];
        if ($productIds && $sukList) {
            $skuRows = Db::name('store_product_attr_value')
                ->whereIn('product_id', $productIds)
                ->whereIn('suk', $sukList)
                ->where('type', 0)
                ->field('product_id,unique,suk,stock,stock_unit')
                ->select()->toArray();
        }
        $skuMap = [];
        foreach ($skuRows as $sku) {
            $skuMap[(int)$sku['product_id'] . '|' . (string)$sku['suk']] = $sku;
        }

        $a = [];
        $b = [];
        foreach ($normalized as $item) {
            $pid = $item['pid'];
            $suk = $item['suk'];
            $key = $item['key'];
            if (isset($aByPid[$pid])) {
                $pa = $aByPid[$pid];
                $sku = $skuMap[(int)$pa['id'] . '|' . $suk] ?? null;
                if ($sku) {
                    $a[$key] = [
                        'product_id' => (int)$pa['id'],
                        'unique' => (string)$sku['unique'],
                        'product_name' => (string)$pa['store_name'],
                        'stock_unit' => (string)($sku['stock_unit'] ?? ''),
                        'stock' => (string)$sku['stock'],
                    ];
                }
            }
            if (isset($bByPid[$pid])) {
                $pb = $bByPid[$pid];
                $sku = $skuMap[(int)$pb['id'] . '|' . $suk] ?? null;
                if ($sku) {
                    $b[$key] = [
                        'product_id' => (int)$pb['id'],
                        'unique' => (string)$sku['unique'],
                        'product_name' => (string)$pb['store_name'],
                        'stock_unit' => (string)($sku['stock_unit'] ?? ''),
                        'stock' => (string)$sku['stock'],
                    ];
                }
            }
        }
        return ['a' => $a, 'b' => $b];
    }
}
