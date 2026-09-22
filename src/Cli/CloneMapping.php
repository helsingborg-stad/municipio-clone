<?php

declare(strict_types=1);

namespace MunicipioClone\Cli;

/**
 * Describes one source-to-target clone operation in a batch configuration.
 */
class CloneMapping
{
    /**
     * @param string $sourceUrl Source site URL.
     * @param string $targetUrl Target site URL.
     * @param string $usernameEnvironmentVariable Environment variable containing the source username.
     * @param string $applicationPasswordEnvironmentVariable Environment variable containing the source application password.
     * @param bool $force Whether to request a fresh remote export.
     * @param bool $keepRemoteMediaUrls Whether to retain remote media URLs.
     */
    public function __construct(
        public readonly string $sourceUrl,
        public readonly string $targetUrl,
        private readonly string $usernameEnvironmentVariable,
        private readonly string $applicationPasswordEnvironmentVariable,
        public readonly bool $force,
        public readonly bool $keepRemoteMediaUrls,
    ) {
    }

    /**
     * Builds the existing single-clone command arguments using runtime credentials.
     *
     * @return array<string, string|bool>
     */
    public function toAssociativeArguments(): array
    {
        $username = getenv($this->usernameEnvironmentVariable);
        $applicationPassword = getenv($this->applicationPasswordEnvironmentVariable);
        if ($username === false || $username === '' || $applicationPassword === false || $applicationPassword === '') {
            throw new \RuntimeException(sprintf(
                'Missing credentials for source "%s". Set %s and %s.',
                $this->sourceUrl,
                $this->usernameEnvironmentVariable,
                $this->applicationPasswordEnvironmentVariable,
            ));
        }

        return array_filter([
            'source-url' => $this->sourceUrl,
            'target' => $this->targetUrl,
            'username' => $username,
            'application-password' => $applicationPassword,
            'force' => $this->force ?: null,
            'keep-remote-media-urls' => $this->keepRemoteMediaUrls ?: null,
            'yes' => true,
        ], static fn(mixed $value): bool => $value !== null);
    }
}