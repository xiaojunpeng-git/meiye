<?php
declare(strict_types=1);
namespace app\services\user;

use think\facade\Db;
use think\facade\Env;
use think\exception\ValidateException;

/** 两端共用事务/幂等边界；传入的 actor 必须来自服务端已认证会话。 */
class MemberManagementCommandServices
{
    public function execute(string $actorType, int $actorId, string $action, array $payload, callable $write): array
    {
        if (!in_array($actorType, ['ADMIN', 'STORE'], true) || $actorId <= 0) throw new ValidateException('操作账号无效');
        $key = (string)($payload['requestId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $key)) throw new ValidateException('操作凭证缺失，请刷新重试');
        $hmac = $this->fingerprint(['action' => $action, 'payload' => $payload]);
        return Db::transaction(function () use ($actorType, $actorId, $action, $payload, $write, $key, $hmac): array {
            $where = ['actor_type' => $actorType, 'actor_id' => $actorId, 'request_key' => $key];
            $table = Db::name('member_management_receipt')->getTable();
            // MySQL唯一键等待并发同请求；失败事务会撤掉占位，不能留下假成功回执。
            Db::execute("INSERT IGNORE INTO {$table} (actor_type,actor_id,request_key,request_hmac,action,member_id,response_json,created_at) VALUES (?,?,?,?,?,?,?,?)", [
                $actorType, $actorId, $key, $hmac, $action, (int)($payload['memberId'] ?? 0), '', time(),
            ]);
            $receipt = Db::name('member_management_receipt')->where($where)->lock(true)->find();
            if (!$receipt || !hash_equals((string)$receipt['request_hmac'], $hmac)) throw new ValidateException('同一操作凭证不能用于不同内容');
            if ($receipt['response_json'] !== '') return (array)json_decode($receipt['response_json'], true);
            $response = $write();
            Db::name('member_management_receipt')->where('id', (int)$receipt['id'])->update(['response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            return $response;
        });
    }

    /** 使用现有服务端认证密钥作HMAC；指纹不泄露可离线猜测的密码哈希。 */
    public function fingerprint(array $value): string
    {
        return hash_hmac('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string)(Env::get('app.app_key', 'mohe_app_key') ?: 'mohe_app_key'));
    }
}
