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
namespace app\controller\api\v1\publics;

use app\services\article\ArticleCategoryServices;
use mohe\services\CacheService;

/**
 * 文章分类类
 * Class ArticleCategory
 * @package app\controller\api\publics
 */
class ArticleCategory
{
    /**
     * @var ArticleCategoryServices
     */
    protected $services;

    /**
     * ArticleCategory constructor.
     * @param ArticleCategoryServices $services
     */
    public function __construct(ArticleCategoryServices $services)
    {
        $this->services = $services;
    }
    /**
     * 文章分类列表
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function lst()
    {
        $cateInfo = CacheService::get('ARTICLE_CATEGORY', function () {
            $cateInfo = $this->services->getArticleCategory();
            array_unshift($cateInfo, ['id' => 0, 'title' => '热门']);
            return $cateInfo;
        });
        return app('json')->successful($cateInfo);
    }
}
