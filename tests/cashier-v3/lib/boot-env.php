<?php
/**
 * 必须在 new think\App / initialize 之前加载。
 * 把缓存切到 file，避免测试容器去连仓库 .env 里的 redis 主机名。
 * run-all 另将最小 TEMP_ENV 只读叠到 /var/www/html/.env（不改宿主机真实 .env）。
 */
$redisHost = getenv('REDIS_HOSTNAME') ?: '127.0.0.1';
foreach ([
  'CACHE_DRIVER' => 'file',
  'cache.driver' => 'file',
  'DRIVER' => 'file',
  'REDIS_HOSTNAME' => $redisHost,
  'REDIS_REDIS_HOSTNAME' => $redisHost,
] as $k => $v) {
  putenv($k . '=' . $v);
  $_ENV[$k] = $v;
  $_SERVER[$k] = $v;
}
