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
namespace app\dao\article;

use app\dao\BaseDao;
use app\model\article\Article;
use app\services\wechat\WechatNewsCategoryServices;

/**
 * 文章dao
 * Class ArticleDao
 * @package app\dao\article
 */
class ArticleDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return Article::class;
    }

	/**
	 * @param array $where
	 * @param bool $search
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
	public function search(array $where = [], bool $search = false)
	{
		return parent::search($where, false)->when(isset($where['ids']) && count($where['ids']), function ($query) use ($where) {
			$query->whereNotIn('id', $where['ids']);
		})->when(isset($where['cid']) && $where['cid'], function ($query) use ($where) {
			$query->where('cid', $where['cid']);
		})->when(isset($where['is_hot']) && $where['is_hot'], function ($query) use ($where) {
			$query->where('is_hot', $where['is_hot']);
		})->when(isset($where['is_banner']) && $where['is_banner'], function ($query) use ($where) {
			$query->where('is_banner', $where['is_banner']);
		});
	}

    /**
     * 获取文章列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return mixed
     */
    public function getList(array $where, int $page, int $limit)
    {
        return $this->search($where)->with(['contents', 'storeInfo', 'cateName'])->page($page, $limit)->order('sort desc,id desc')->select()->toArray();
    }

    /**
     * 获取一条数据
     * @param $id
     * @return mixed
     */
    public function read($id)
    {
        $data = $this->search()->with(['contents', 'storeInfo', 'cateName'])->find($id);
        if($data){
            $data['store_info'] = $data['storeInfo'];
        }
        return $data;
    }

    /**
     * @param array $where
     * @param int $page
     * @param int $limit
     * @param string $field
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cidByArticleList(array $where, int $page, int $limit, string $field = '*')
    {
		return $this->search($where)
			->when($page != 0, function ($query) use ($page, $limit) {
				$query->page($page, $limit);
			})->order('add_time desc')->field($field)->select()->toArray();
    }

    /**
	 * 新闻分类下的文章
     * @param $new_id
     * @return mixed
     */
    public function articleLists($new_id)
    {
        return $this->getModel()->where('hide', 0)->where('id', 'in', $new_id)->select();
    }

    /**
	 * 图文详情
     * @param $new_id
     * @return mixed
     */
    public function articleContentList($new_id)
    {
        return $this->getModel()->where('hide', 0)->where('id', 'in', $new_id)->with('contents')->select();
    }
}
