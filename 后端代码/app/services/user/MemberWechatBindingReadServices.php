<?php
declare(strict_types=1);
namespace app\services\user;

use think\facade\Db;

/** 已通过会员数据权限的 UID 批量读取器；不返回 openid、unionid 或微信密钥。 */
class MemberWechatBindingReadServices
{
    public function summaries(array $memberIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds), static function ($id) { return $id > 0; })));
        if (!$ids) return [];
        $channels = [];
        foreach (Db::name('wechat_user')->whereIn('uid', $ids)->where('is_del', 0)
            ->where('openid', '<>', '')->field('uid,user_type')->select()->toArray() as $row) {
            if (in_array($row['user_type'], ['routine', 'wechat'], true)) $channels[(int)$row['uid']][$row['user_type']] = true;
        }
        $result = [];
        foreach ($ids as $id) {
            $labels = [];
            if (!empty($channels[$id]['routine'])) $labels[] = '小程序已绑定';
            if (!empty($channels[$id]['wechat'])) $labels[] = '公众号已绑定';
            $result[$id] = $labels ? implode('、', $labels) : '未绑定';
        }
        return $result;
    }

    /** 仅登录资料编辑授权通过后调用；历史解绑行不作为当前绑定显示。 */
    public function bindings(int $memberId): array
    {
        $rows = Db::name('wechat_user')->where('uid', $memberId)->where('is_del', 0)->where('openid', '<>', '')
            ->whereIn('user_type', ['routine', 'wechat'])->field('id,user_type,nickname,add_time')->order('id', 'asc')->select()->toArray();
        return array_map(static function (array $row): array {
            return ['channel' => $row['user_type'], 'label' => $row['user_type'] === 'routine' ? '微信小程序' : '微信公众号',
                'nickname' => (string)$row['nickname'], 'boundAt' => (int)$row['add_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['add_time']) : ''];
        }, $rows);
    }
}
