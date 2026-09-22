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
    public function testRewritesAttachmentUrlsToTheSourceMultisitePath(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 3, true);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'https://media.example.test/uploads/networks/1/sites/4/2026/09/image.jpg',
            42,
        );

        $this->assertSame('https://media.example.test/uploads/networks/1/sites/3/2026/09/image.jpg', $url);
    }

    public function testLeavesAttachmentUrlsUnchangedWhenRemoteMediaIsDisabled(): void
    {
        $wpService = new MutableWpService();
        $wpService->currentBlogId = 4;
        $rewriter = new RemoteMediaUrlRewriter($wpService);

        $rewriter->configure(4, 3, false);
        $wpService->currentBlogId = 4;

        $url = $rewriter->filterAttachmentUrl(
            'https://media.example.test/uploads/networks/1/sites/4/2026/09/image.jpg',
            42,
        );

        $this->assertSame('https://media.example.test/uploads/networks/1/sites/4/2026/09/image.jpg', $url);
    }
}