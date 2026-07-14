<?php
namespace app\controller\store\staff;

use app\controller\store\AuthController;
use app\services\store\StoreStaffScheduleServices;
use think\facade\App;

/**
 * 员工排班/休息
 */
class StaffSchedule extends AuthController
{
    /** @var StoreStaffScheduleServices */
    protected $services;

    public function __construct(App $app, StoreStaffScheduleServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 排班列表（按月）
     */
    public function index()
    {
        [$month, $staff_id] = $this->request->getMore([
            ['month', date('Y-m')],
            [['staff_id', 'd'], 0],
        ], true);
        $list = $this->services->getMonthList((int)$this->storeId, (string)$month, (int)$staff_id);
        return app('json')->success([
            'list' => $list,
            'schedule_manage' => $this->services->isScheduleManageEnabled() ? 1 : 0,
        ]);
    }

    /**
     * 保存排班/休息
     */
    public function save()
    {
        $data = $this->request->postMore([
            [['staff_id', 'd'], 0],
            ['schedule_date', ''],
            [['schedule_type', 'd'], 1],
            ['start_time', ''],
            ['end_time', ''],
            ['mark', ''],
        ]);
        $this->services->saveSchedule((int)$this->storeId, $data);
        return app('json')->success('保存成功');
    }

    /**
     * 批量保存
     */
    public function batchSave()
    {
        [$list] = $this->request->postMore([
            ['list', []],
        ], true);
        if (!is_array($list) || !$list) {
            return app('json')->fail('请提交排班数据');
        }
        $this->services->batchSave((int)$this->storeId, $list);
        return app('json')->success('保存成功');
    }

    /**
     * 删除
     */
    public function delete($id)
    {
        $this->services->deleteSchedule((int)$this->storeId, (int)$id);
        return app('json')->success('删除成功');
    }

    /**
     * 月历看板
     */
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

    /**
     * 某日排班详情
     */
    public function dayDetail()
    {
        [$date] = $this->request->getMore([
            ['date', ''],
        ], true);
        return app('json')->success($this->services->getDayDetail((int)$this->storeId, (string)$date));
    }

    /**
     * 按班次保存某日排班
     */
    public function saveDay()
    {
        [$date, $shifts] = $this->request->postMore([
            ['schedule_date', ''],
            ['shifts', []],
        ], true);
        $this->services->saveDayByShifts((int)$this->storeId, (string)$date, is_array($shifts) ? $shifts : []);
        return app('json')->success('保存成功');
    }

    /**
     * 可选排班员工
     */
    public function selectableStaff()
    {
        [$keyword, $position_id] = $this->request->getMore([
            ['keyword', ''],
            [['position_id', 'd'], 0],
        ], true);
        $list = $this->services->getSelectableStaff((int)$this->storeId, (string)$keyword, (int)$position_id);
        return app('json')->success($list);
    }
}
