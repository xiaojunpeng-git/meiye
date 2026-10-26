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

namespace app\dao\other\Import;


use app\dao\BaseDao;
use app\model\other\import\ImportRecord;

/**
 * 导入数据
 * Class ImportRecordDao
 * @package app\dao\other
 */
class ImportRecordDao extends BaseDao
{

    /**
     * @return string
     */
    public function setModel(): string
    {
        return ImportRecord::class;
    }

    /**
     * 搜索
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where)->when(isset($where['keyword']) && $where['keyword'], function ($query) use ($where) {
            $query->where(function ($query) use ($where) {
                $query->whereLike('name|id|admin_id|admin_name', "%{$where['keyword']}%");
            });
        });
    }
    /**
     * 获取列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $order
     * @param array $with
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page = 0, int $limit = 0, string $order = '', array $with = [])
    {
        return $this->search($where)->order(($order ? $order . ' ,' : '') . 'id desc')
            ->when(count($with), function ($query) use ($with) {
                $query->with($with);
            })->when($page != 0 && $limit != 0, function ($query) use ($page, $limit) {
                $query->page($page, $limit);
            })->select()->toArray();
    }
}
