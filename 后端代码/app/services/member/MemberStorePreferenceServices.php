<?php
declare(strict_types=1);
namespace app\services\member;
use think\facade\Db;

/** 会员首页与购买页共用默认门店：有效归属优先，定位最近，其余取稳定的可用门店。 */
final class MemberStorePreferenceServices
{
    public function choose(int $uid, array $stores, array $position = []): int
    {
        $primary = $uid > 0 ? (int)Db::name('user')->where('uid', $uid)->value('belong_store_id') : 0;
        return self::select($primary, $stores, $position);
    }

    /** 输入仅允许已筛出的可用门店；客户端坐标只影响默认排序，不改变购买权限。 */
    public static function select(int $primary, array $stores, array $position = []): int
    {
        $lat = $position['latitude'] ?? null; $lng = $position['longitude'] ?? null;
        $located = is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180
            && ((float)$lat != 0 || (float)$lng != 0);
        $best = 0; $distance = INF;
        foreach ($stores as $store) {
            $id = (int)$store['id'];
            if ($id === $primary) return $id;
            $score = INF;
            if ($located && is_numeric($store['latitude'] ?? null) && is_numeric($store['longitude'] ?? null)) {
                $a = deg2rad((float)$store['latitude'] - (float)$lat);
                $b = deg2rad((float)$store['longitude'] - (float)$lng);
                $score = sin($a / 2) ** 2 + cos(deg2rad((float)$lat)) * cos(deg2rad((float)$store['latitude'])) * sin($b / 2) ** 2;
            }
            if (!$best || $score < $distance || ($score === $distance && $id < $best)) { $best = $id; $distance = $score; }
        }
        return $best;
    }
}
