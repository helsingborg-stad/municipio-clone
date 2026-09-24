<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Import;

use MunicipioClone\Import\RemoteMediaUrlRewriter;
use MunicipioClone\Tests\TestDoubles\MutableWpService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Import\RemoteMediaUrlRewriter
 */
class RemoteMediaUrlRewriterTest extends TestCase
{
    public function testRewritesAttachmentUrlsToAnExternalSourceDomain(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'http://localhost:8080/target-site/wp-content/uploads';
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 'https://source-site.example.test/wp-content/uploads', true);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'http://localhost:8080/target-site/wp-content/uploads/2026/09/image-300x200.jpg',
            42,
        );

        $this->assertSame('https://source-site.example.test/wp-content/uploads/2026/09/image-300x200.jpg', $url);
    }

    public function testRewritesAttachmentUrlsServedFromAnUnrelatedCdnDomain(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'http://localhost:8080/target-site/wp-content/uploads';
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 'https://media-cdn.example.test/uploads/networks/5/sites/196', true);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'http://localhost:8080/target-site/wp-content/uploads/2022/09/photo-1024x576.webp',
            42,
        );

        $this->assertSame(
            'https://media-cdn.example.test/uploads/networks/5/sites/196/2022/09/photo-1024x576.webp',
            $url,
        );
    }

    public function testRewritesAttachmentUrlsWithinTheSameMultisiteDomain(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'https://media.example.test/sites/4';
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 'https://media.example.test/sites/3', true);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'https://media.example.test/sites/4/2026/09/image.jpg',
            42,
        );

        $this->assertSame('https://media.example.test/sites/3/2026/09/image.jpg', $url);
    }

    public function testLeavesAttachmentUrlsUnchangedWhenRemoteMediaIsDisabled(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'http://localhost:8080/target-site/wp-content/uploads';
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 'https://source-site.example.test/wp-content/uploads', false);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'http://localhost:8080/target-site/wp-content/uploads/2026/09/image.jpg',
            42,
        );

        $this->assertSame('http://localhost:8080/target-site/wp-content/uploads/2026/09/image.jpg', $url);
    }

    public function testLeavesAttachmentUrlsUnchangedWhenTheyDoNotMatchTheTargetUploadsBaseUrl(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'http://localhost:8080/target-site/wp-content/uploads';
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 'https://source-site.example.test/wp-content/uploads', true);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'https://cdn.example.test/wp-content/uploads/2026/09/image.jpg',
            42,
        );

        $this->assertSame('https://cdn.example.test/wp-content/uploads/2026/09/image.jpg', $url);
    }
}