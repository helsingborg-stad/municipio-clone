<?php

declare(strict_types=1);

namespace MunicipioClone\Authentication;

use MunicipioClone\Capability\CapabilityRegistrar;
use WpService\WpService;

/**
 * Authenticates REST requests using per-user API keys.
 */
class ApiKeyAuthenticator
{
    private const LOOKUP_META_KEY = 'municipio_clone_api_key_lookup';

    public function __construct(private WpService $wpService)
    {
    }

    public function authenticate(object $request): array|\WP_Error
    {
        $apiKey = $this->extractApiKey($request);
        if ($apiKey === '') {
            return new \WP_Error('municipio_clone_missing_api_key', 'Missing Municipio Clone API key.', ['status' => 401]);
        }

        $lookup = $this->buildLookupFingerprint($apiKey);
        foreach ($this->wpService->getUsers(['meta_key' => self::LOOKUP_META_KEY, 'meta_value' => $lookup]) as $user) {
            $userId = (int) ($user->ID ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $keys = $this->wpService->getUserMeta($userId, 'municipio_clone_api_keys', true);
            if (!is_array($keys)) {
                continue;
            }

            foreach ($keys as $keyHash) {
                if (is_string($keyHash) && password_verify($apiKey, $keyHash)) {
                    if ($this->wpService->userCan($userId, CapabilityRegistrar::CAPABILITY) || $this->wpService->isSuperAdmin($userId)) {
                        return ['user_id' => $userId];
                    }

                    return new \WP_Error('municipio_clone_forbidden', 'The supplied API key does not grant export access.', ['status' => 403]);
                }
            }
        }

        return new \WP_Error('municipio_clone_invalid_api_key', 'The supplied API key is invalid.', ['status' => 401]);
    }

    public function issueKey(int $userId): string
    {
        $apiKey = bin2hex(random_bytes(24));
        $existingKeys = $this->wpService->getUserMeta($userId, 'municipio_clone_api_keys', true);
        $keys = is_array($existingKeys) ? $existingKeys : [];
        $keys[] = password_hash($apiKey, PASSWORD_DEFAULT);
        $this->wpService->updateUserMeta($userId, 'municipio_clone_api_keys', $keys);
        $existingLookup = $this->wpService->getUserMeta($userId, self::LOOKUP_META_KEY, true);
        $lookupKeys = is_array($existingLookup) ? $existingLookup : [];
        $lookupKeys[] = $this->buildLookupFingerprint($apiKey);
        $this->wpService->updateUserMeta($userId, self::LOOKUP_META_KEY, $lookupKeys);

        return $apiKey;
    }

    private function buildLookupFingerprint(string $apiKey): string
    {
        return hash_hmac('sha256', $apiKey, 'municipio-clone');
    }

    private function extractApiKey(object $request): string
    {
        $header = '';
        if (method_exists($request, 'get_header')) {
            $header = (string) ($request->get_header('X-Municipio-Clone-Key') ?: '');
            if ($header === '') {
                $authorizationHeader = (string) ($request->get_header('Authorization') ?: '');
                if (str_starts_with($authorizationHeader, 'Bearer ')) {
                    $header = substr($authorizationHeader, 7);
                }
            }
        }

        return trim($header);
    }
}
