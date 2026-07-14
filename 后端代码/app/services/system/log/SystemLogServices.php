<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\system\log;


use app\dao\system\log\SystemLogDao;
use app\services\BaseServices;
use app\services\system\SystemMenusServices;

/**
 * 系统日志
 * Class SystemLogServices
 * @package app\services\system\log
 * @mixin SystemLogDao
 */
class SystemLogServices extends BaseServices
{

    /**
     * 构造方法
     * SystemLogServices constructor.
     * @param SystemLogDao $dao
     */
    public function __construct(SystemLogDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 记录访问日志
     * @param int $adminId
     * @param string $adminName
     * @param string $method
     * @param string $rule
     * @param string $ip
     * @param string $type
     * @return bool
     */
    public function recordAdminLog(int $adminId, string $adminName, string $method, string $rule, string $ip, string $type)
    {
        /** @var SystemMenusServices $service */
        $service = app()->make(SystemMenusServices::class);
        $data = [
            'method' => $method,
            'add_time' => time(),
            'admin_name' => $adminName,
            'path' => $rule,
            'page' => $service->getVisitName(str_replace([
				'adminapi/',
				'storeapi/',
				'supplierapi/',
				'cashierapi/'
			], '', $rule)) ?: '未知',
            'ip' => $ip,
            'type' => $type
        ];
        if ($type == 'store') {
            $data['store_id'] = $adminId;
        } else {
            $data['admin_id'] = $adminId;
        }
        if ($this->dao->save($data)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 获取系统日志列表
     * @param array $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getLogList(array $where, int $level)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getLogList($where, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }
}
