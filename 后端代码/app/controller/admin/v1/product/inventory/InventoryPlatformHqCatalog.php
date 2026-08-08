<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformHqCatalogServices;
use think\facade\App;

final class InventoryPlatformHqCatalog extends AuthController
{
    public function __construct(App $app, InventoryPlatformHqCatalogServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function search()
    {
        try {
            [$locationId, $keyword, $page, $limit] = $this->request->getMore([
                ['hq_location_id', 0],
                ['keyword', ''],
                ['page', 1],
                ['limit', 20],
            ], true);
            $sourcePartyType = (string)$this->request->get('source_party_type', 'HQ');
            $sourceStoreId = (int)$this->request->get('source_store_id', 0);
            $availabilityPartyType = (string)$this->request->get('availability_party_type', $sourcePartyType);
            $availabilityStoreId = (int)$this->request->get('availability_store_id', $sourceStoreId);
            return $this->success($this->services->search(
                (array)$this->adminInfo,
                (int)$locationId,
                (string)$keyword,
                (int)$page,
                (int)$limit,
                $sourcePartyType,
                $sourceStoreId,
                $availabilityPartyType,
                $availabilityStoreId
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部商品查询条件不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail('总部商品目录读取失败，请确认总部仓范围后重试。', ['code' => $exception->getMessage()]);
        }
    }

    public function barcode()
    {
        try {
            $sourcePartyType = (string)$this->request->get('source_party_type', 'HQ');
            $sourceStoreId = (int)$this->request->get('source_store_id', 0);
            return $this->success($this->services->barcode(
                (array)$this->adminInfo,
                (int)$this->request->get('hq_location_id', 0),
                (string)$this->request->get('barcode', ''),
                $sourcePartyType,
                $sourceStoreId,
                (string)$this->request->get('availability_party_type', $sourcePartyType),
                (int)$this->request->get('availability_store_id', $sourceStoreId)
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('总部商品条码不合法。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage() === 'inventory_catalog_barcode_ambiguous' ? '该条码对应多个规格，请通过商品选择确认。' : '没有找到该条码对应的总部库存商品。';
            return $this->fail($message, ['code' => $exception->getMessage()]);
        }
    }
}
