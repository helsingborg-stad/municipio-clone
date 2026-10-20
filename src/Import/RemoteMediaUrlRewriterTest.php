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

    public function testKeepsImportedMediaRemoteAndNewUploadsLocal(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $wpService->uploadDirBaseUrl = 'http://localhost:8080/wp-content/uploads/sites/4';
        $rewriter = new RemoteMediaUrlRewriter($wpService);
        $rewriter->configure(4, 'https://media.example.test/uploads/sites/4', true);
        $wpService->currentBlogId = 4;

        $rewriter->recordLocalAttachment(100);
        $importedUrl = $rewriter->filterAttachmentUrl('http://localhost:8080/wp-content/uploads/sites/4/old.jpg', 99);
        $firstLocalUrl = $rewriter->filterAttachmentUrl('http://localhost:8080/wp-content/uploads/sites/4/new.jpg', 100);
        $laterLocalUrl = $rewriter->filterAttachmentUrl('http://localhost:8080/wp-content/uploads/sites/4/later.jpg', 101);

        $this->assertSame('https://media.example.test/uploads/sites/4/old.jpg', $importedUrl);
        $this->assertSame('http://localhost:8080/wp-content/uploads/sites/4/new.jpg', $firstLocalUrl);
        $this->assertSame('http://localhost:8080/wp-content/uploads/sites/4/later.jpg', $laterLocalUrl);
        $this->assertSame(100, $wpService->options[4]['municipio_clone_first_local_attachment_id']);
    }

    public function testDoesNotMarkAttachmentsWhenRemoteMediaIsDisabled(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $rewriter = new RemoteMediaUrlRewriter($wpService);
        $rewriter->configure(4, 'https://media.example.test/uploads', false);
        $wpService->currentBlogId = 4;

        $rewriter->recordLocalAttachment(100);

        $this->assertSame(0, $wpService->options[4]['municipio_clone_first_local_attachment_id']);
    }

    public function testKeepsTheFirstLocalIdIsolatedPerSiteAndResetsItOnReimport(): void
    {
        $wpService = new MutableWpService();
        $rewriter = new RemoteMediaUrlRewriter($wpService);
        $rewriter->configure(4, 'https://media.example.test/site-4', true);
        $rewriter->configure(5, 'https://media.example.test/site-5', true);
        $wpService->currentBlogId = 4;
        $rewriter->recordLocalAttachment(100);
        $rewriter->recordLocalAttachment(101);
        $wpService->currentBlogId = 5;
        $rewriter->recordLocalAttachment(200);

        $this->assertSame(100, $wpService->options[4]['municipio_clone_first_local_attachment_id']);
        $this->assertSame(200, $wpService->options[5]['municipio_clone_first_local_attachment_id']);

        $rewriter->configure(4, 'https://media.example.test/site-4', true);
        $wpService->currentBlogId = 4;
        $this->assertSame(0, $wpService->options[4]['municipio_clone_first_local_attachment_id']);
        $rewriter->recordLocalAttachment(300);
        $this->assertSame('https://media.example.test/site-4/new.jpg', $rewriter->filterAttachmentUrl(
            $wpService->uploadDirBaseUrl . '/new.jpg',
            299,
        ));
        $this->assertSame(300, $wpService->options[4]['municipio_clone_first_local_attachment_id']);
        $this->assertSame(200, $wpService->options[5]['municipio_clone_first_local_attachment_id']);
    }

    public function testReportsFailureToRecordLocalAttachmentBoundary(): void
    {
        $wpService = new class() extends MutableWpService {
            public function updateOption(string $option, mixed $value, bool|null $autoload = null): bool
            {
                if ($option === 'municipio_clone_first_local_attachment_id' && $value === 100) {
                    return false;
                }

                return parent::updateOption($option, $value, $autoload);
            }
        };
        $rewriter = new RemoteMediaUrlRewriter($wpService);
        $rewriter->configure(4, 'https://media.example.test/uploads', true);
        $wpService->currentBlogId = 4;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not record the first local attachment ID');
        $rewriter->recordLocalAttachment(100);
    }

    public function testRejectsAFailedResetOfTheLocalAttachmentBoundary(): void
    {
        $wpService = new class() extends MutableWpService {
            public function updateOption(string $option, mixed $value, bool|null $autoload = null): bool
            {
                if ($option === 'municipio_clone_first_local_attachment_id') {
                    return false;
                }

                return parent::updateOption($option, $value, $autoload);
            }
        };
        $wpService->options[4]['municipio_clone_first_local_attachment_id'] = 90;
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not configure remote media URLs');
        try {
            $rewriter->configure(4, 'https://media.example.test/uploads', true);
        } finally {
            $this->assertSame(1, $wpService->currentBlogId);
        }
    }
}