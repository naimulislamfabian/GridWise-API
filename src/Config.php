<?php
declare(strict_types=1);

namespace GridWise;

final class Config
{
    private static bool $loaded = false;

    public static function loadEnv(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $path ??= dirname(__DIR__) . '/.env';
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function llm(): array
    {
        self::loadEnv();
        $provider = strtolower(trim((string)(getenv('LLM_PROVIDER') ?: 'openai_compat')));
        if (!in_array($provider, ['openai_compat', 'ollama'], true)) {
            throw new \RuntimeException("LLM_PROVIDER must be 'openai_compat' or 'ollama'");
        }

        $defaultBase = $provider === 'ollama' ? 'http://localhost:11434' : 'https://api.openai.com/v1';
        $baseUrl = rtrim((string)(getenv('LLM_BASE_URL') ?: $defaultBase), '/');
        $model = trim((string)(getenv('LLM_MODEL') ?: ''));
        $apiKey = getenv('LLM_API_KEY');
        $apiKey = $apiKey === false ? null : (string)$apiKey;
        $timeout = (float)(getenv('LLM_TIMEOUT_SECONDS') ?: '8');
        $repairs = (int)(getenv('LLM_REPAIR_ATTEMPTS') ?: '1');
        $cacheSize = (int)(getenv('LLM_CACHE_SIZE') ?: '256');
        $cacheDir = (string)(getenv('LLM_CACHE_DIR') ?: dirname(__DIR__) . '/var/cache');

        if ($model === '') {
            throw new \RuntimeException('LLM_MODEL is required');
        }
        if ($provider === 'openai_compat' && ($apiKey === null || trim($apiKey) === '')) {
            throw new \RuntimeException('LLM_API_KEY is required for openai_compat');
        }
        if ($timeout <= 0 || $timeout > 25) {
            throw new \RuntimeException('LLM_TIMEOUT_SECONDS must be > 0 and <= 25');
        }
        if ($repairs < 0 || $repairs > 2) {
            throw new \RuntimeException('LLM_REPAIR_ATTEMPTS must be between 0 and 2');
        }
        if ($cacheSize < 0) {
            throw new \RuntimeException('LLM_CACHE_SIZE must be non-negative');
        }

        return compact('provider', 'baseUrl', 'model', 'apiKey', 'timeout', 'repairs', 'cacheSize', 'cacheDir');
    }
}
