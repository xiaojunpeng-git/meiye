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

namespace app\dao\system\attachment;

use app\dao\BaseDao;
use app\model\system\attachment\SystemAttachment;

/**
 *
 * Class SystemAttachmentDao
 * @package app\dao\attachment
 */
class SystemAttachmentDao extends BaseDao
{

    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemAttachment::class;
    }

    /**
     * 搜索附件分类search
     * @param array $where
     * @return \mohe\basic\BaseModel|mixed|\think\Model
     */
    public function search(array $where = [])
    {
        return parent::search($where);
    }

    /**
     * 获取图片列表
     * @param array $where
     * @param int $page
     * @param int $limit
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getList(array $where, int $page, int $limit)
    {
        return $this->search($where)->where('module_type', 1)->page($page, $limit)->order('att_id DESC')->select()->toArray();
    }

    /**
     * 移动图片
     * @param array $data
     * @return \mohe\basic\BaseModel
     */
    public function move(array $data)
    {
        return $this->getModel()->whereIn('att_id', $data['images'])->update(['pid' => $data['pid']]);
    }

    /**
     * 获取昨日系统生成
     * @param int $type
     * @param int $relationId
     * @return \think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getYesterday(int $type = 1, $relationId = 0)
    {
        return $this->getModel()->where('type', $type)->when($relationId, function ($query) use ($relationId) {
            $query->where('relation_id', $relationId);
        })->whereTime('time', 'yesterday')->where('module_type', 2)->field(['name', 'att_dir', 'att_id', 'image_type'])->select();
    }

    /**
     * 删除昨日生成海报
     * @throws \Exception
     */
    public function delYesterday()
    {
        $this->getModel()->whereTime('time', 'yesterday')->where('module_type', 2)->delete();
    }

    /**
     * 获取扫码上传的图片数据
     * @param $scan_token
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function scanUploadImage($scan_token)
    {
        return $this->getModel()->where('scan_token', $scan_token)->field('att_dir,att_id')->select()->toArray();
    }
}
