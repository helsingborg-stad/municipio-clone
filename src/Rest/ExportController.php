<?php

declare(strict_types=1);

namespace MunicipioClone\Rest;

use MunicipioClone\Authentication\ApiKeyAuthenticator;
use MunicipioClone\Contracts\ArtifactStorageInterface;
use MunicipioClone\Export\ArtifactManifest;
use MunicipioClone\Export\ExportService;
use MunicipioClone\Support\Config;
use WpService\WpService;

/**
 * Registers and serves the export REST API.
 */
class ExportController
{
    private int $authenticatedUserId = 0;

    public function __construct(
        private WpService $wpService,
        private ApiKeyAuthenticator $apiKeyAuthenticator,
        private ExportService $exportService,
        private ArtifactStorageInterface $artifactStorage,
    ) {
    }

    public function registerRoutes(): void
    {
        $this->wpService->registerRestRoute('municipio-clone/v1', '/export', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'handleExport'],
                'permission_callback' => [$this, 'permissionCheck'],
            ],
        ]);
        $this->wpService->registerRestRoute('municipio-clone/v1', '/export/(?P<artifact>[a-f0-9]{32})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'downloadArtifact'],
                'permission_callback' => [$this, 'permissionCheck'],
            ],
        ]);
    }

    public function permissionCheck(object $request): bool|\WP_Error
    {
        $result = $this->apiKeyAuthenticator->authenticate($request);
        if ($result instanceof \WP_Error) {
            return $result;
        }

        $this->authenticatedUserId = (int) $result['user_id'];

        return true;
    }

    public function handleExport(object $request): \WP_REST_Response|\WP_Error
    {
        if ($this->authenticatedUserId <= 0) {
            return new \WP_Error('municipio_clone_unauthenticated', 'Authentication state was not established before export handling.', ['status' => 401]);
        }

        $force = false;
        if (method_exists($request, 'get_json_params')) {
            $params = (array) $request->get_json_params();
            $force = (bool) ($params['force'] ?? false);
        }

        $sourceIdentifier = $this->wpService->getHomeUrl($this->wpService->getCurrentBlogId()) . '#' . $this->wpService->getCurrentBlogId();
        $export = $this->exportService->create($sourceIdentifier, $force, $this->authenticatedUserId);
        /** @var ArtifactManifest $manifest */
        $manifest = $export['manifest'];

        return new \WP_REST_Response([
            'artifact_id' => $manifest->artifactId,
            'checksum' => $manifest->checksum,
            'cache_status' => $export['cache_status'],
            'download_url' => $this->wpService->restUrl('municipio-clone/v1/export/' . $manifest->artifactId),
            'placeholder_url' => Config::placeholderUrl(),
            'source_url' => $manifest->sourceUrl,
            'source_blog_id' => $manifest->sourceBlogId,
            'source_table_prefix' => $manifest->sourceTablePrefix,
        ], 200);
    }

    public function downloadArtifact(object $request): \WP_REST_Response|\WP_Error
    {
        if ($this->authenticatedUserId <= 0) {
            return new \WP_Error('municipio_clone_unauthenticated', 'Authentication state was not established before artifact download.', ['status' => 401]);
        }

        $artifactId = method_exists($request, 'get_param') ? (string) $request->get_param('artifact') : '';
        if ($artifactId === '') {
            return new \WP_Error('municipio_clone_missing_artifact', 'Artifact id is required.', ['status' => 400]);
        }

        return new \WP_REST_Response($this->artifactStorage->retrieveContent($artifactId), 200, [
            'Content-Type' => 'application/sql',
        ]);
    }
}
