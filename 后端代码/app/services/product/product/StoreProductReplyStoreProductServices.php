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
declare (strict_types=1);

namespace app\services\product\product;

use app\services\BaseServices;
use app\dao\product\product\StoreProductReplyStoreProductDao;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

/**
 *
 * Class StoreProductReplyStoreProductServices
 * @package app\services\product\product
 * @mixin StoreProductReplyStoreProductDao
 */
class StoreProductReplyStoreProductServices extends BaseServices
{

    /**
     * StoreProductReplyStoreProductServices constructor.
     * @param StoreProductReplyStoreProductDao $dao
     */
    public function __construct(StoreProductReplyStoreProductDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 获取评论列表
     * @param array $where
     * @param array $with
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getProductReplyList(array $where, array $with = [])
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getProductReplyList($where, $page, $limit, $with);
        $count = $this->dao->replyCount($where);
        return compact('list', 'count');
    }
}
