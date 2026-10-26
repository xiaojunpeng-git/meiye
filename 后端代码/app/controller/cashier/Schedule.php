<?php
namespace app\controller\cashier;

use app\services\store\StoreStaffScheduleServices;
use think\facade\App;

/**
 * 收银台排班管理
 */
class Schedule extends AuthController
{
    /** @var StoreStaffScheduleServices */
    protected $services;

    public function __construct(App $app, StoreStaffScheduleServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    public function calendar()
    {
        [$month, $keyword, $position_id] = $this->request->getMore([
            ['month', date('Y-m')],
            ['keyword', ''],
            [['position_id', 'd'], 0],
        ], true);
        $data = $this->services->getCalendarBoard((int)$this->storeId, (string)$month, (string)$keyword, (int)$position_id);
        return app('json')->success($data);
    }

    public function dayDetail()
    {
        [$date] = $this->request->getMore([['date', '']], true);
        return app('json')->success($this->services->getDayDetail((int)$this->storeId, (string)$date));
    }

    public function saveDay()
    {
        [$date, $shifts] = $this->request->postMore([
            ['schedule_date', ''],
            ['shifts', []],
        ], true);
        $this->services->saveDayByShifts((int)$this->storeId, (string)$date, is_array($shifts) ? $shifts : []);
        return app('json')->success('保存成功');
    }

    public function selectableStaff()
    {
        [$keyword, $position_id] = $this->request->getMore([
            ['keyword', ''],
            [['position_id', 'd'], 0],
        ], true);
        return app('json')->success($this->services->getSelectableStaff((int)$this->storeId, (string)$keyword, (int)$position_id));
    }

    public function exportList()
    {
        [$month, $keyword, $position_id] = $this->request->getMore([
            ['month', date('Y-m')],
            ['keyword', ''],
            [['position_id', 'd'], 0],
        ], true);
        $list = $this->services->getScheduleExportList(
            (int)$this->storeId,
            (string)$month,
            (string)$keyword,
            (int)$position_id
        );
        return app('json')->success(['list' => $list, 'month' => $month]);
    }
}
