<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\InventoryDocumentRemarkServices;
use think\facade\App;

/** Platform note commands recheck page grants and HQ scope inside the domain service. */
final class InventoryPlatformDocumentRemark extends AuthController
{
    public function __construct(App $app, InventoryDocumentRemarkServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function detail(string $id)
    {
        try {
            $selection = (array)$this->request->get();
            return $this->success($this->services->detailForPlatform(
                (array)$this->adminInfo, (string)($selection['type'] ?? ''), $id, $selection
            ));
        } catch (\Throwable $exception) {
            return $this->fail('单据不存在或当前账号无权查看备注。');
        }
    }

    public function save(string $id)
    {
        try {
            $selection = (array)$this->request->get();
            $input = $this->request->post();
            return $this->success($this->services->saveForPlatform(
                (array)$this->adminInfo, (string)($selection['type'] ?? ''), $id, $selection,
                is_array($input) ? $input : []
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail('备注内容或明细行不合法，请刷新单据后重试。', ['code' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail($exception->getMessage() === 'inventory_document_remark_changed' ? '备注已被他人修改，请刷新后再编辑。' : '当前单据不可编辑备注。', ['code' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            return $this->fail('备注未保存，请稍后重试。');
        }
    }
}
