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

namespace app\services\community;

use app\dao\community\CommunityRelevanceDao;
use app\services\BaseServices;
use mohe\traits\OptionTrait;
use think\exception\ValidateException;

/**
 * 社区关联
 * Class CommunityRelevanceServices
 * @package app\services\community
 * @mixin CommunityRelevanceDao
 */
class CommunityRelevanceServices extends BaseServices
{

    use OptionTrait;

    //社区关联商品
    const TYPE_COMMUNITY_PRODUCT = 'community_product';
    //社区关联话题
    const TYPE_COMMUNITY_TOPIC = 'community_topic';

    //社区帖子点赞
    const TYPE_COMMUNITY_LIKE = 'community_like';

    //帖子浏览
    const TYPE_COMMUNITY_BROWSE = 'community_browse';

    //社区评论点赞
    const TYPE_COMMUNITY_COMMENT_LIKE = 'community_comment_like';

    //关注
    const TYPE_COMMUNITY_INTEREST = 'community_interest';

    /**
     * CommunityRelevanceServices constructor.
     * @param CommunityRelevanceDao $dao
     */
    public function __construct(CommunityRelevanceDao $dao)
    {
        $this->dao = $dao;
    }

}
