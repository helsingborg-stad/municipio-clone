<?php

namespace MunicipioClone\Cli\BatchCloneConfigurationResolver;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ResolveConfigurationFromFilePathTest extends TestCase
{
    #[TestDox('returns null if no configuration file is found')]
    public function testReturnsNullIfNoConfigurationFileIsFound(): void
    {
        $filePath = '/path/to/nonexistent/config/file';
        $resolver = new ResolveConfigurationFromFilePath($filePath);
        static::assertNull($resolver->resolve());
    }

    #[TestDox('returns null if the configuration file is empty')]
    public function testReturnsNullIfConfigurationFileIsEmpty(): void
    {
        $filePath = __DIR__ . '/Test/empty-file.json';
        $resolver = new ResolveConfigurationFromFilePath($filePath);
        static::assertNull($resolver->resolve());
    }

    #[TestDox('returns the file content')]
    public function testReturnsFileContent(): void
    {
        $filePath = __DIR__ . '/Test/non-empty-file.json';
        $resolver = new ResolveConfigurationFromFilePath($filePath);
        static::assertSame(file_get_contents($filePath), $resolver->resolve());
    }

    #[TestDox('returns inner resolver content instead of null if provided')]
    public function testReturnsInnerResolverContentInsteadOfNullIfProvided(): void {
        $innerResolver = new class implements BatchCloneConfigurationResolverInterface {
            public function resolve(): ?string
            {
                return 'inner_value';
            }
        };
        
        $resolver = new ResolveConfigurationFromFilePath('/path/to/nonexistent/config/file', $innerResolver);
        static::assertSame('inner_value', $resolver->resolve());    
    }
}
