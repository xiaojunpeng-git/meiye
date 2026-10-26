<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\InventoryDocumentRemarkServices;
use think\facade\App;

/** Store session identity is authoritative; no store or tenant comes from the browser payload. */
final class InventoryDocumentRemark extends AuthController
{
    public function __construct(App $app, InventoryDocumentRemarkServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function detail(string $id)
    {
        try {
            return $this->success($this->services->detailForStore(
                (int)$this->storeId, (int)$this->storeStaffId, (string)$this->request->get('type', ''), $id
            ));
        } catch (\Throwable $exception) {
            return $this->fail('单据不存在或当前账号无权查看备注。');
        }
    }

    public function save(string $id)
    {
        try {
            $input = $this->request->post();
            // The store-session middleware adds this server-owned scope field to every POST;
            // the remark command itself derives scope from the authenticated session.
            if (is_array($input) && !empty($this->request->forceStoreSessionInjectedPostStoreId)) {
                unset($input['store_id']);
            }
            return $this->success($this->services->saveForStore(
                (int)$this->storeId, (int)$this->storeStaffId, (string)$this->request->get('type', ''), $id,
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
