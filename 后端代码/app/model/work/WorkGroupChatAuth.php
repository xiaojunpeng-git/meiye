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

namespace app\model\work;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\model\concern\SoftDelete;

/**
 * 企业微信自动拉群
 * Class WorkGroupChatAuth
 * @package app\model\work
 */
class WorkGroupChatAuth extends BaseModel
{

    use ModelTrait, SoftDelete;

    /**
     * @var string
     */
    protected $name = 'work_group_chat_auth';

    /**
     * @var string
     */
    protected $key = 'id';

    /**
     * @var string
     */
    protected $autoWriteTimestamp = 'int';

    /**
     * @param $value
     * @return false|string
     */
    public function setWelcomeWordsAttr($value)
    {
        return json_encode($value);
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getWelcomeWordsAttr($value)
    {
        return json_decode($value, true);
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setAdminUserAttr($value)
    {
        return json_encode($value);
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getAdminUserAttr($value)
    {
        return json_decode($value, true);
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setChatIdAttr($value)
    {
        return json_encode($value);
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getChatIdAttr($value)
    {
        return json_decode($value, true);
    }

    /**
     * @param $value
     * @return false|string
     */
    public function setLabelAttr($value)
    {
        return json_encode($value);
    }

    /**
     * @param $value
     * @return mixed
     */
    public function getLabelAttr($value)
    {
        return json_decode($value, true);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchNameAttr($query, $value)
    {
        if ('' !== $value) {
            $query->whereLike('name', '%' . $value . '%');
        }
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchCreateTimeAttr($query, $value)
    {
        if ('' !== $value) {
            $this->searchTimeAttr($query, $value, ['timeKey' => 'create_time']);
        }
    }
}
