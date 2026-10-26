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

namespace app\services\other\Import;


use app\dao\other\Import\ImportRecordErrorDao;
use app\services\BaseServices;

/**
 * 导入
 * Class ImportRecordErrorServices
 * @package app\services\other\import
 * @mixin ImportRecordErrorDao
 */
class ImportRecordErrorServices extends BaseServices
{

    /**
     * @var ImportRecordErrorDao
     */
    public function __construct(ImportRecordErrorDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 列表
     * @param $where
     * @param bool $is_limit
     * @return array
     * @throws \ReflectionException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getErrorList($where, bool $is_limit = false)
    {
        $page = $limit = 0;
        if ($is_limit) {
            [$page, $limit] = $this->getPageValue();
        }
        $list = $this->dao->getList($where,$page,$limit);
        $count = $this->dao->count($where);
        foreach ($list as &$item) {
            $item['original_data'] = json_decode($item['original_data'], true);
        }
        return compact('list', 'count');
    }
}
