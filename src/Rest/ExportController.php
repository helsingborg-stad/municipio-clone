<?php

declare(strict_types=1);

namespace MunicipioClone\Rest;

use MunicipioClone\Capability\CapabilityRegistrar;
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
    public function __construct(
        private WpService $wpService,
        private ExportService $exportService,
        private ArtifactStorageInterface $artifactStorage,
    ) {
    }

    public function registerRoutes(): void
    {
        $this->wpService->addFilter('rest_pre_serve_request', [$this, 'streamArtifactDownload'], 10, 4);
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
        $userId = $this->wpService->getCurrentUserId();
        if ($userId <= 0) {
            return new \WP_Error('municipio_clone_unauthenticated', 'WordPress authentication is required.', ['status' => 401]);
        }

        if (!$this->wpService->currentUserCan(CapabilityRegistrar::CAPABILITY)) {
            return new \WP_Error('municipio_clone_forbidden', 'The authenticated user does not have export access.', ['status' => 403]);
        }

        $this->storeAuthenticatedUserId($request, $userId);

        return true;
    }

    public function handleExport(object $request): \WP_REST_Response|\WP_Error
    {
        $authenticatedUserId = $this->getAuthenticatedUserId($request);
        if ($authenticatedUserId <= 0) {
            return new \WP_Error('municipio_clone_unauthenticated', 'Authentication state was not established before export handling.', ['status' => 401]);
        }

        $force = false;
        if (method_exists($request, 'get_json_params')) {
            $params = (array) $request->get_json_params();
            $force = (bool) ($params['force'] ?? false);
        }

        $this->extendRuntimeForExport();

        $sourceIdentifier = $this->wpService->getHomeUrl($this->wpService->getCurrentBlogId()) . '#' . $this->wpService->getCurrentBlogId();
        $export = $this->exportService->create($sourceIdentifier, $force, $authenticatedUserId);
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
        if ($this->getAuthenticatedUserId($request) <= 0) {
            return new \WP_Error('municipio_clone_unauthenticated', 'Authentication state was not established before artifact download.', ['status' => 401]);
        }

        $artifactId = method_exists($request, 'get_param') ? (string) $request->get_param('artifact') : '';
        if ($artifactId === '') {
            return new \WP_Error('municipio_clone_missing_artifact', 'Artifact id is required.', ['status' => 400]);
        }

        return new \WP_REST_Response(['artifact_id' => $artifactId]);
    }

    public function streamArtifactDownload(bool $served, object $result, object $request, object $server): bool
    {
        if ($served || !method_exists($request, 'get_route') || !preg_match('#^/municipio-clone/v1/export/[a-f0-9]{32}$#', (string) $request->get_route())) {
            return $served;
        }

        $data = method_exists($result, 'get_data') ? $result->get_data() : null;
        $artifactId = is_array($data) ? (string) ($data['artifact_id'] ?? '') : '';
        if ($artifactId === '') {
            return $served;
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), 'municipio_clone_download_');
        if ($tempFilePath === false) {
            throw new \RuntimeException('Failed to create a temporary artifact download file.');
        }

        try {
            $this->artifactStorage->writeContentToFile($artifactId, $tempFilePath);
            $fileSize = filesize($tempFilePath);
            if (method_exists($server, 'send_header')) {
                $server->send_header('Content-Type', 'application/sql');
                $server->send_header('Content-Disposition', sprintf('attachment; filename="municipio-clone-%s.sql"', $artifactId));
                if ($fileSize !== false) {
                    $server->send_header('Content-Length', (string) $fileSize);
                }
            }

            $handle = fopen($tempFilePath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Failed to open the artifact download file.');
            }

            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
            }
        } finally {
            @unlink($tempFilePath);
        }

        return true;
    }

    /**
     * Raises PHP's execution time limit so large exports do not 500 mid-request.
     */
    private function extendRuntimeForExport(): void
    {
        $timeLimit = Config::exportTimeLimit();
        if (function_exists('set_time_limit') && !$this->isPhpFunctionDisabled('set_time_limit')) {
            set_time_limit($timeLimit);
        }

        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
    }

    private function isPhpFunctionDisabled(string $functionName): bool
    {
        $disabledFunctions = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return in_array($functionName, $disabledFunctions, true);
    }

    private function storeAuthenticatedUserId(object $request, int $userId): void
    {
        if (method_exists($request, 'set_param')) {
            $request->set_param('_municipio_clone_user_id', $userId);

            return;
        }

        $request->_municipio_clone_user_id = $userId;
    }

    private function getAuthenticatedUserId(object $request): int
    {
        if (method_exists($request, 'get_param')) {
            return (int) $request->get_param('_municipio_clone_user_id');
        }

        return (int) ($request->_municipio_clone_user_id ?? 0);
    }
}
