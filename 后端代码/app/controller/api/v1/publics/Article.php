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

use app\Request;
use app\services\article\ArticleServices;

/**
 * 文章类
 * Class Article
 * @package app\controller\api\publics
 */
class Article
{
    /**
     * @var ArticleServices
     */
    protected $services;

    /**
     * Article constructor.
     * @param ArticleServices $services
     */
    public function __construct(ArticleServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 文章列表
	 * @param $cid
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function lst($cid)
    {
        return app('json')->successful($this->services->cidByArticleList(['cid' => $cid], "id,title,image_input,visit,likes,from_unixtime(add_time,'%Y-%m-%d %H:%i') as add_time,synopsis,url"));
    }

	/**
	 * 文章点赞 添加、取消
	 * @param Request $request
	 * @param $id
	 * @return \think\Response
	 */
	public function userArticleLikes(Request $request, $id)
	{
		$where = $request->getMore([
			['status', 0]
		]);
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('文章ID参数错误');
        }
		$uid = $request->uid();
		$info = $this->services->userArticleLikes($id, $where, $uid);
		return app('json')->successful($info);
	}

	/**
	 * 文章详情
	 * @param $id
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\ModelNotFoundException
	 * @throws \think\exception\DbException
	 */
	public function details(Request $request, $id)
	{
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('文章ID参数错误');
        }
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$info = $this->services->getInfo($uid, (int)$id);
		return app('json')->successful($info);
	}

	/**
	 * 获取热门文章
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function hot()
    {
        return app('json')->successful($this->services->cidByArticleList(['is_hot' => 1]));
    }

    /**
     * 获取最新文章
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function new()
    {
        return app('json')->successful($this->services->cidByArticleList());
    }

    /**
     * 获取顶部banner文章
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function banner()
    {
        return app('json')->successful($this->services->cidByArticleList(['is_banner' => 1]));
    }
}
