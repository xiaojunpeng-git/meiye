<?php
declare(strict_types=1);

namespace app\controller\store\product\inventory;

use app\controller\store\AuthController;
use app\services\product\inventory\StoreProjectConsumableRecipeServices;
use think\facade\App;

/**
 * 门店端·项目耗材配方（院装）
 * 口径：配方仅总部创建/编辑/启停；门店暂时不可见、不可修改。
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
        return $this->fail('项目配方由总部统一维护，门店暂不可查看');
    }

    public function info($id)
    {
        return $this->fail('项目配方由总部统一维护，门店暂不可查看');
    }

    public function save($id = 0)
    {
        return $this->fail('项目配方由总部统一维护，门店暂不可修改');
    }

    public function setStatus($id)
    {
        return $this->fail('项目配方由总部统一维护，门店暂不可修改');
    }

    public function delete($id)
    {
        return $this->fail('项目配方由总部统一维护，门店暂不可修改');
    }
}
