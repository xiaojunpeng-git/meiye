<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryPlatformHqReadModelServices;
use app\services\query\UnifiedQueryException;
use think\facade\App;

final class InventoryPlatformHqReadModel extends AuthController
{
    public function __construct(App $app, InventoryPlatformHqReadModelServices $services) { parent::__construct($app); $this->services = $services; }
    public function productSummary()
    {
        try {
            [$locationId, $keyword, $page, $limit] = $this->request->getMore([['hq_location_id', 0], ['keyword', ''], ['page', 1], ['limit', 20]], true);
            return $this->success($this->services->productSummary((array)$this->adminInfo, (int)$locationId, (string)$keyword, (int)$page, (int)$limit));
        } catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('总部库存查询条件不合法。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail('总部库存读取失败，请确认总部仓范围后重试。', ['code' => $exception->getMessage()]); }
    }
    public function productDetail(int $productId)
    {
        try { return $this->success($this->services->productDetail((array)$this->adminInfo, (int)$this->request->get('hq_location_id', 0), $productId)); }
        catch (UnifiedQueryException $exception) { return $this->fail($exception->getMessage(), ['code' => $exception->getErrorCode()]); }
        catch (\InvalidArgumentException $exception) { return $this->fail('总部库存详情参数不合法。', ['code' => $exception->getMessage()]); }
        catch (\RuntimeException $exception) { return $this->fail('总部库存详情不存在或无权查看。', ['code' => $exception->getMessage()]); }
    }
}
