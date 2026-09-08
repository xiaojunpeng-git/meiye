<?php
namespace app\services\ai\config;

use RuntimeException;

/** Server-owned directory outside public; no conversation transcript is accepted. */
final class AiPrivateStorage
{
    private $root;
    public function __construct(string $root)
    {
        if ($root === '' || $root[0] !== '/' || strpos($root, "\0") !== false) throw new RuntimeException('AI_PRIVATE_STORAGE_INVALID');
        $this->root = rtrim($root, '/');
    }
    private function directory(): void
    {
        if (is_link($this->root)) throw new RuntimeException('AI_PRIVATE_STORAGE_INVALID');
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE');
    }
    public function monitoringDirectory(): string
    {
        $this->directory();
        return $this->root . '/monitoring';
    }
    public function signingKey(): string
    {
        $this->directory(); $path = $this->root . '/master.key';
        if (is_link($path)) throw new RuntimeException('AI_PRIVATE_STORAGE_INVALID');
        if (!is_file($path)) {
            $key = random_bytes(32); $old = umask(0077); $file = @fopen($path, 'x+b'); umask($old);
            if ($file) {
                if (!flock($file, LOCK_EX) || fwrite($file, $key) !== 32 || !fflush($file)) { fclose($file); throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE'); }
                flock($file, LOCK_UN); fclose($file);
            }
        }
        $file = fopen($path, 'rb'); if (!$file) throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE');
        flock($file, LOCK_SH); $key = stream_get_contents($file); flock($file, LOCK_UN); fclose($file);
        if (!is_string($key) || strlen($key) !== 32) throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE');
        return $key;
    }
    public function put(string $kind, array $data, int $expiresAt): string
    {
        if (!in_array($kind, ['answer', 'clarification', 'evidence'], true) || $expiresAt <= time() || $expiresAt > time() + 86400) throw new RuntimeException('AI_PRIVATE_OBJECT_INVALID');
        $this->directory(); $ref = $kind . '-' . bin2hex(random_bytes(24));
        $payload = json_encode(['kind' => $kind, 'expires_at' => $expiresAt, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false || strlen($payload) > 1048576) throw new RuntimeException('AI_PRIVATE_OBJECT_INVALID');
        $nonce = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($payload, 'aes-256-gcm', $this->signingKey(), OPENSSL_RAW_DATA, $nonce, $tag, $ref);
        if ($encrypted === false) throw new RuntimeException('AI_PRIVATE_OBJECT_INVALID');
        $old = umask(0077); $file = fopen($this->root . '/' . $ref, 'xb'); umask($old);
        if (!$file) throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE');
        $bytes = $nonce . $tag . $encrypted;
        $written = fwrite($file, $bytes); fflush($file); fclose($file);
        if ($written !== strlen($bytes)) throw new RuntimeException('AI_PRIVATE_STORAGE_UNAVAILABLE');
        return $ref;
    }
    public function read(string $ref): array
    {
        if (!preg_match('/^(answer|clarification|evidence)-[a-f0-9]{48}$/D', $ref)) throw new RuntimeException('AI_PRIVATE_OBJECT_INVALID');
        $path = $this->root . '/' . $ref;
        if (!is_file($path) || is_link($path) || filesize($path) < 29 || filesize($path) > 1048604) throw new RuntimeException('AI_PRIVATE_OBJECT_UNAVAILABLE');
        $bytes = file_get_contents($path);
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->signingKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), $ref);
        $object = $plain === false ? null : json_decode($plain, true);
        if (!is_array($object) || !is_int($object['expires_at'] ?? null) || !is_array($object['data'] ?? null)) throw new RuntimeException('AI_PRIVATE_OBJECT_UNAVAILABLE');
        if ($object['expires_at'] <= time()) throw new RuntimeException('AI_PRIVATE_OBJECT_EXPIRED');
        return $object['data'];
    }
    public function cleanup(): int
    {
        if (!is_dir($this->root)) return 0; $count = 0;
        // Infrastructure failure is not proof that every object is expired.
        $this->signingKey();
        foreach (new \DirectoryIterator($this->root) as $file) {
            $name = $file->getFilename();
            if (!$file->isFile() || $file->isLink() || !preg_match('/^(answer|clarification|evidence)-[a-f0-9]{48}$/D', $name)) continue;
            try { $this->read($name); } catch (RuntimeException $exception) {
                // Delete only this private namespace, never a business/export directory.
                if (($exception->getMessage()==='AI_PRIVATE_OBJECT_EXPIRED' || $file->getMTime()+86400<=time()) && unlink($file->getPathname())) $count++;
            }
        }
        return $count;
    }
}
