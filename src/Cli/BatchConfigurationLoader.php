<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

/**
 * Loads and validates batch clone mappings from a JSON file.
 */
class BatchConfigurationLoader
{
    /**
     * @return CloneMapping[]
     */
    public function load(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(sprintf('Batch configuration file "%s" is not readable.', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read batch configuration file "%s".', $path));
        }

        try {
            $configuration = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(sprintf('Batch configuration file "%s" contains invalid JSON.', $path), 0, $exception);
        }

        if (!is_array($configuration) || !isset($configuration['mappings']) || !is_array($configuration['mappings']) || $configuration['mappings'] === []) {
            throw new \InvalidArgumentException('Batch configuration must contain a non-empty "mappings" array.');
        }

        $mappings = [];
        $targets = [];
        foreach ($configuration['mappings'] as $index => $mapping) {
            if (!is_array($mapping)) {
                throw new \InvalidArgumentException(sprintf('Batch mapping at index %d must be an object.', $index));
            }
            $this->assertAllowedKeys($mapping, $index);

            $sourceUrl = $this->requiredUrl($mapping, 'source_url', $index);
            $targetUrl = $this->requiredUrl($mapping, 'target', $index);
            if (isset($targets[$targetUrl])) {
                throw new \InvalidArgumentException(sprintf('Batch configuration contains duplicate target "%s".', $targetUrl));
            }
            $targets[$targetUrl] = true;

            $mappings[] = new CloneMapping(
                $sourceUrl,
                $targetUrl,
                $this->requiredEnvironmentVariable($mapping, 'username_env', $index),
                $this->requiredEnvironmentVariable($mapping, 'application_password_env', $index),
                $this->optionalBoolean($mapping, 'force', $index),
                $this->optionalBoolean($mapping, 'keep_remote_media_urls', $index),
            );
        }

        return $mappings;
    }

    private function assertAllowedKeys(array $mapping, int $index): void
    {
        $allowedKeys = [
            'source_url',
            'target',
            'username_env',
            'application_password_env',
            'force',
            'keep_remote_media_urls',
        ];
        $unexpectedKeys = array_diff(array_keys($mapping), $allowedKeys);
        if ($unexpectedKeys !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Batch mapping at index %d contains unsupported keys: %s.',
                $index,
                implode(', ', $unexpectedKeys),
            ));
        }
    }

    private function requiredUrl(array $mapping, string $key, int $index): string
    {
        $value = (string) ($mapping[$key] ?? '');
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('Batch mapping at index %d requires an absolute "%s" URL.', $index, $key));
        }

        return rtrim($value, '/');
    }

    private function requiredEnvironmentVariable(array $mapping, string $key, int $index): string
    {
        $value = (string) ($mapping[$key] ?? '');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Batch mapping at index %d requires "%s" to name an environment variable.', $index, $key));
        }

        return $value;
    }

    private function optionalBoolean(array $mapping, string $key, int $index): bool
    {
        if (!array_key_exists($key, $mapping)) {
            return false;
        }

        if (!is_bool($mapping[$key])) {
            throw new \InvalidArgumentException(sprintf('Batch mapping at index %d requires "%s" to be a boolean.', $index, $key));
        }

        return $mapping[$key];
    }
}