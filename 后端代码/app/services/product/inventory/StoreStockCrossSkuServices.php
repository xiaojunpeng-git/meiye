<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\BaseServices;
use mohe\traits\ServicesTrait;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 跨主体同源 SKU 映射（平台 pid + suk）
 * P2：支持总部仓（type=0,rid=0，商品 id 即平台源）↔ 门店副本
 */
class StoreStockCrossSkuServices extends BaseServices
{
    use ServicesTrait;

    /** 单张请货/调拨明细上限，防止超大单卡顿 */
    public const MAX_DETAIL_LINES = 200;

    /**
     * 解析双方门店已存在且参与库存的同源 SKU（兼容旧签名：双方均为门店）
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
    public function resolvePairsBatch(
        array $pairs,
        int $requestStoreId,
        int $supplyStoreId,
        string $reqLabel = '请货门店',
        string $supLabel = '供货门店',
        string $requestParty = StockPartyServices::PARTY_STORE,
        string $supplyParty = StockPartyServices::PARTY_STORE
    ): array {
        $normalized = $this->normalizePairKeys($pairs);
        $map = $this->batchLoadPartySkus(
            $normalized,
            $requestParty,
            $requestStoreId,
            $supplyParty,
            $supplyStoreId,
            $reqLabel,
            $supLabel
        );
        $out = [];
        foreach ($normalized as $item) {
            $key = $item['key'];
            $req = $map['a'][$key] ?? null;
            $sup = $map['b'][$key] ?? null;
            if (!$req) {
                throw new ValidateException($reqLabel . '缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . '，请先同步商品到该门店并开启库存');
            }
            if (!$sup) {
                throw new ValidateException($supLabel . '缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk'] . ($supplyParty === StockPartyServices::PARTY_HQ ? '（总部仓）' : '，请先同步商品到该门店并开启库存'));
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
                'decimal_scale' => max((int)($req['decimal_scale'] ?? 0), (int)($sup['decimal_scale'] ?? 0)) > 0 ? 2 : 0,
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
    public function resolveTransferPairsBatch(
        array $pairs,
        int $fromStoreId,
        int $toStoreId,
        string $fromParty = StockPartyServices::PARTY_STORE,
        string $toParty = StockPartyServices::PARTY_STORE
    ): array {
        $normalized = $this->normalizePairKeys($pairs);
        $map = $this->batchLoadPartySkus(
            $normalized,
            $fromParty,
            $fromStoreId,
            $toParty,
            $toStoreId,
            '调出方',
            '调入方'
        );
        $out = [];
        foreach ($normalized as $item) {
            $key = $item['key'];
            $from = $map['a'][$key] ?? null;
            $to = $map['b'][$key] ?? null;
            if (!$from) {
                throw new ValidateException('调出方缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk']);
            }
            if (!$to) {
                throw new ValidateException('调入方缺少同源商品/规格：平台商品ID ' . $item['pid'] . ' / ' . $item['suk']);
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
                'decimal_scale' => max((int)($from['decimal_scale'] ?? 0), (int)($to['decimal_scale'] ?? 0)) > 0 ? 2 : 0,
                'from_stock' => $from['stock'],
                'to_stock' => $to['stock'],
            ];
        }
        return $out;
    }

    /**
     * 可选同源 SKU：支持门店↔门店，或门店↔总部仓
     * store_a / store_b 为门店 ID；party_*=hq 时对应侧 store 传 0
     */
    public function listSharedSkus(
        int $storeA,
        int $storeB,
        string $keyword = '',
        int $page = 1,
        int $limit = 20,
        string $partyA = StockPartyServices::PARTY_STORE,
        string $partyB = StockPartyServices::PARTY_STORE
    ): array {
        $partyA = strtolower(trim($partyA)) ?: StockPartyServices::PARTY_STORE;
        $partyB = strtolower(trim($partyB)) ?: StockPartyServices::PARTY_STORE;
        if ($partyA === StockPartyServices::PARTY_HQ && $partyB === StockPartyServices::PARTY_HQ) {
            throw new ValidateException('双方不能都是总部仓');
        }
        // 兼容旧双店
        if ($partyA === StockPartyServices::PARTY_STORE && $partyB === StockPartyServices::PARTY_STORE) {
            return $this->listSharedSkusStoreStore($storeA, $storeB, $keyword, $page, $limit);
        }
        // 门店 + 总部：store 侧必须 >0，hq 侧 store=0
        if ($partyA === StockPartyServices::PARTY_HQ) {
            return $this->listSharedSkusHqStore($storeB, $keyword, $page, $limit, true);
        }
        return $this->listSharedSkusHqStore($storeA, $keyword, $page, $limit, false);
    }

    protected function listSharedSkusStoreStore(int $storeA, int $storeB, string $keyword, int $page, int $limit): array
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
                ON pb.pid = pa.pid AND pb.type = 1 AND pb.relation_id = ? AND pb.is_del = 0
                AND pb.product_type = 0 AND pb.is_inventory = 1 AND pb.pid > 0
            INNER JOIN `eb_store_product_attr_value` sa
                ON sa.product_id = pa.id AND sa.type = 0 AND sa.suk <> \'\'
            INNER JOIN `eb_store_product_attr_value` sb
                ON sb.product_id = pb.id AND sb.type = 0 AND sb.suk = sa.suk
            WHERE pa.type = 1 AND pa.relation_id = ? AND pa.is_del = 0
                AND pa.product_type = 0 AND pa.is_inventory = 1 AND pa.pid > 0
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
                IF(IFNULL(sa.decimal_scale,0)>0 OR IFNULL(sb.decimal_scale,0)>0, 2, 0) AS decimal_scale,
                IFNULL(sa.bar_code, \'\') AS bar_code,
                IFNULL(sa.code, \'\') AS code,
                pa.id AS store_a_product_id,
                sa.unique AS store_a_unique,
                sa.stock AS store_a_stock,
                pb.id AS store_b_product_id,
                sb.unique AS store_b_unique,
                sb.stock AS store_b_stock
        ' . $baseSql . ' ORDER BY pa.pid ASC, sa.suk ASC LIMIT ' . (int)$offset . ', ' . (int)$limit;

        return $this->formatSharedList(Db::query($listSql, $binds), $count);
    }

    /**
     * 总部仓 ↔ 门店：平台商品 id 即 pid，门店副本 pid=总部商品id
     * @param bool $hqIsA true=A 为总部、B 为门店；false=A 为门店、B 为总部
     */
    protected function listSharedSkusHqStore(int $storeId, string $keyword, int $page, int $limit, bool $hqIsA): array
    {
        if ($storeId <= 0) {
            throw new ValidateException('请选择门店');
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $kw = trim($keyword);
        $offset = ($page - 1) * $limit;

        // ph=总部商品，ps=门店副本；两侧强制普通产品且参与库存
        $baseSql = '
            FROM `eb_store_product` ph
            INNER JOIN `eb_store_product` ps
                ON ps.pid = ph.id AND ps.type = 1 AND ps.relation_id = ? AND ps.is_del = 0
                AND ps.product_type = 0 AND ps.is_inventory = 1
            INNER JOIN `eb_store_product_attr_value` sh
                ON sh.product_id = ph.id AND sh.type = 0 AND sh.suk <> \'\'
            INNER JOIN `eb_store_product_attr_value` ss
                ON ss.product_id = ps.id AND ss.type = 0 AND ss.suk = sh.suk
            WHERE ph.type = 0 AND ph.relation_id = 0 AND ph.is_del = 0
                AND ph.product_type = 0 AND ph.is_inventory = 1
        ';
        $binds = [$storeId];
        if ($kw !== '') {
            $baseSql .= ' AND (ph.store_name LIKE ? OR ps.store_name LIKE ?) ';
            $binds[] = '%' . $kw . '%';
            $binds[] = '%' . $kw . '%';
        }

        $countRow = Db::query('SELECT COUNT(*) AS cnt ' . $baseSql, $binds);
        $count = (int)($countRow[0]['cnt'] ?? 0);
        if ($count <= 0) {
            return ['list' => [], 'count' => 0];
        }

        if ($hqIsA) {
            $listSql = '
                SELECT
                    ph.id AS pid,
                    sh.suk AS suk,
                    ph.store_name AS product_name,
                    IFNULL(sh.stock_unit, IFNULL(ss.stock_unit, \'\')) AS stock_unit,
                    IF(IFNULL(sh.decimal_scale,0)>0 OR IFNULL(ss.decimal_scale,0)>0, 2, 0) AS decimal_scale,
                    IFNULL(sh.bar_code, \'\') AS bar_code,
                    IFNULL(sh.code, \'\') AS code,
                    ph.id AS store_a_product_id,
                    sh.unique AS store_a_unique,
                    sh.stock AS store_a_stock,
                    ps.id AS store_b_product_id,
                    ss.unique AS store_b_unique,
                    ss.stock AS store_b_stock
            ' . $baseSql . ' ORDER BY ph.id ASC, sh.suk ASC LIMIT ' . (int)$offset . ', ' . (int)$limit;
        } else {
            $listSql = '
                SELECT
                    ph.id AS pid,
                    sh.suk AS suk,
                    ps.store_name AS product_name,
                    IFNULL(ss.stock_unit, IFNULL(sh.stock_unit, \'\')) AS stock_unit,
                    IF(IFNULL(sh.decimal_scale,0)>0 OR IFNULL(ss.decimal_scale,0)>0, 2, 0) AS decimal_scale,
                    IFNULL(ss.bar_code, \'\') AS bar_code,
                    IFNULL(ss.code, \'\') AS code,
                    ps.id AS store_a_product_id,
                    ss.unique AS store_a_unique,
                    ss.stock AS store_a_stock,
                    ph.id AS store_b_product_id,
                    sh.unique AS store_b_unique,
                    sh.stock AS store_b_stock
            ' . $baseSql . ' ORDER BY ph.id ASC, sh.suk ASC LIMIT ' . (int)$offset . ', ' . (int)$limit;
        }

        return $this->formatSharedList(Db::query($listSql, $binds), $count);
    }

    protected function formatSharedList(array $rows, int $count): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'pid' => (int)$row['pid'],
                'suk' => (string)$row['suk'],
                'product_name' => (string)$row['product_name'],
                'stock_unit' => (string)$row['stock_unit'],
                'decimal_scale' => (int)($row['decimal_scale'] ?? 0) > 0 ? 2 : 0,
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

    public function parseQty($qty, string $rowLabel, int $decimalScale = 0): string
    {
        if ($qty === null || $qty === '') {
            throw new ValidateException($rowLabel . '：请填写数量');
        }
        if (is_string($qty)) {
            $qty = trim($qty);
        }
        $scale = $decimalScale > 0 ? 2 : 0;
        /** @var StockQtyValidateServices $qtyValidate */
        $qtyValidate = app()->make(StockQtyValidateServices::class);
        try {
            $num = $qtyValidate->assertQty($qty, $scale, $rowLabel);
        } catch (\mohe\exceptions\AdminException $e) {
            throw new ValidateException($e->getMessage());
        }
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
     * 批量加载双方主体商品+SKU
     * @return array{a:array,b:array}
     */
    protected function batchLoadPartySkus(
        array $normalized,
        string $partyA,
        int $storeA,
        string $partyB,
        int $storeB,
        string $labelA,
        string $labelB
    ): array {
        $a = $this->loadSideSkus($normalized, $partyA, $storeA, $labelA);
        $b = $this->loadSideSkus($normalized, $partyB, $storeB, $labelB);
        return ['a' => $a, 'b' => $b];
    }

    protected function loadSideSkus(array $normalized, string $partyType, int $storeId, string $label): array
    {
        $partyType = strtolower(trim($partyType)) ?: StockPartyServices::PARTY_STORE;
        $pids = [];
        $suks = [];
        foreach ($normalized as $item) {
            $pids[$item['pid']] = $item['pid'];
            $suks[$item['suk']] = $item['suk'];
        }
        $pidList = array_values($pids);
        $sukList = array_values($suks);
        if (!$pidList) {
            return [];
        }

        if ($partyType === StockPartyServices::PARTY_HQ) {
            // 总部仓：商品 id 即平台源 pid；强制 product_type=0 且 is_inventory=1
            $products = Db::name('store_product')
                ->where([
                    'type' => 0,
                    'relation_id' => 0,
                    'is_del' => 0,
                    'product_type' => 0,
                    'is_inventory' => 1,
                ])
                ->whereIn('id', $pidList)
                ->field('id,pid,store_name,product_type,is_inventory')
                ->select()->toArray();
            $byPid = [];
            foreach ($products as $p) {
                $byPid[(int)$p['id']] = $p;
            }
        } else {
            if ($storeId <= 0) {
                throw new ValidateException($label . '门店无效');
            }
            $products = Db::name('store_product')
                ->where([
                    'type' => 1,
                    'relation_id' => $storeId,
                    'is_del' => 0,
                    'product_type' => 0,
                    'is_inventory' => 1,
                ])
                ->whereIn('pid', $pidList)
                ->field('id,pid,store_name,product_type,is_inventory')
                ->select()->toArray();
            $byPid = [];
            foreach ($products as $p) {
                $byPid[(int)$p['pid']] = $p;
            }
        }

        $productIds = [];
        foreach ($byPid as $p) {
            $productIds[] = (int)$p['id'];
        }
        $skuRows = [];
        if ($productIds && $sukList) {
            $skuRows = Db::name('store_product_attr_value')
                ->whereIn('product_id', $productIds)
                ->whereIn('suk', $sukList)
                ->where('type', 0)
                ->field('product_id,unique,suk,stock,stock_unit,decimal_scale')
                ->select()->toArray();
        }
        $skuMap = [];
        foreach ($skuRows as $sku) {
            $skuMap[(int)$sku['product_id'] . '|' . (string)$sku['suk']] = $sku;
        }

        $out = [];
        foreach ($normalized as $item) {
            $pid = $item['pid'];
            $suk = $item['suk'];
            $key = $item['key'];
            if (!isset($byPid[$pid])) {
                continue;
            }
            $pa = $byPid[$pid];
            $sku = $skuMap[(int)$pa['id'] . '|' . $suk] ?? null;
            if (!$sku) {
                continue;
            }
            $out[$key] = [
                'product_id' => (int)$pa['id'],
                'unique' => (string)$sku['unique'],
                'product_name' => (string)$pa['store_name'],
                'stock_unit' => (string)($sku['stock_unit'] ?? ''),
                'decimal_scale' => (int)($sku['decimal_scale'] ?? 0) > 0 ? 2 : 0,
                'stock' => (string)$sku['stock'],
            ];
        }
        return $out;
    }
}
