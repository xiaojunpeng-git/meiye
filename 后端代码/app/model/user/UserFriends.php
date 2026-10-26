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

namespace app\model\user;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

class UserFriends extends BaseModel
{

    use ModelTrait;

    /**
     * 表明
     * @var string
     */
    protected $name = 'user_friends';

    /**
     * 主键
     * @var string
     */
    protected $pk = 'id';

    /**
     *
     * @return \think\model\relation\HasOne
     */
    public function level()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field(['uid', 'level'])->bind([
            'level' => 'level'
        ]);
    }

    /**
     * @return \think\model\relation\HasOne
     */
    public function nickname()
    {
        return $this->hasOne(User::class, 'uid', 'uid')->field(['uid', 'nickname'])->bind([
            'nickname' => 'nickname'
        ]);
    }

    /**
     * uid搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        $query->where('uid', $value);
    }

    /**
     * 修改添加时间
     * @param $value
     * @return false|string
     */
    public function getAddTimeAttr($value)
    {
        return date('Y-m-d H:i:s', $value);
    }
}
