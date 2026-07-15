<?php
declare(strict_types=1);

namespace app\controller\admin\v1\product\inventory;

use app\controller\admin\AuthController;
use app\services\product\inventory\StoreProjectConsumableRecipeServices;
use think\facade\App;

/**
 * 平台端·项目耗材配方（院装）
 */
class StoreProjectConsumableRecipe extends AuthController
{
    public function __construct(App $app, StoreProjectConsumableRecipeServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        $where = $this->request->getMore([
            ['status', ''],
            ['project_product_id', ''],
        ]);
        return $this->success($this->services->getList($where, 0));
    }

    public function info($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        return $this->success($this->services->detail((int)$id, 0));
    }

    public function save($id = 0)
    {
        $data = $this->request->postMore([
            ['project_product_id', 0],
            ['project_unique', ''],
            ['status', 1],
            ['details', []],
        ]);
        $newId = $this->services->save((int)$id, $data, (int)$this->adminId, 0);
        return $this->success('保存成功', ['id' => $newId]);
    }

    public function setStatus($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        [$status] = $this->request->postMore([['status', 1]], true);
        $this->services->setStatus((int)$id, (int)$status, 0);
        return $this->success('操作成功');
    }

    public function delete($id)
    {
        if (!$id) {
            return $this->fail('缺少参数');
        }
        $this->services->delete((int)$id, 0);
        return $this->success('删除成功');
    }
}
