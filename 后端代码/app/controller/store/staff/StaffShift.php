<?php
namespace app\controller\store\staff;

use app\controller\store\AuthController;
use app\services\store\StoreStaffShiftServices;
use think\facade\App;

/**
 * 员工班次模板
 */
class StaffShift extends AuthController
{
    /** @var StoreStaffShiftServices */
    protected $services;

    public function __construct(App $app, StoreStaffShiftServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function index()
    {
        return app('json')->success($this->services->getList((int)$this->storeId));
    }

    public function save()
    {
        $data = $this->request->postMore([
            [['id', 'd'], 0],
            ['name', ''],
            ['start_time', ''],
            ['end_time', ''],
            ['break_periods', []],
            [['sort', 'd'], 0],
            [['apply_all_stores', 'd'], 0],
        ]);
        $res = $this->services->saveShift((int)$this->storeId, $data);
        return app('json')->success('保存成功', $res);
    }

    public function delete($id)
    {
        $this->services->deleteShift((int)$this->storeId, (int)$id);
        return app('json')->success('删除成功');
    }
}
