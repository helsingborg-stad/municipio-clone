<?php

namespace MunicipioClone\Cli\BatchCloneConfigurationResolver;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ResolveConfigurationFromConstTest extends TestCase
{
    #[TestDox('returns null if const is not defined')]
    public function testReturnsNullIfConstIsNotDefined(): void
    {
        $resolver = new ResolveConfigurationFromConst('NON_EXISTENT_CONST');
        static::assertNull($resolver->resolve());
    }

    #[TestDox('returns null if the const is defined but empty')]
    public function testReturnsNullIfConstIsEmpty(): void
    {
        define('EMPTY_CONST', '');
        $resolver = new ResolveConfigurationFromConst('EMPTY_CONST');
        static::assertNull($resolver->resolve());
    }

    #[TestDox('returns the const content')]
    public function testReturnsConstContent(): void
    {
        define('NON_EMPTY_CONST', 'some_value');
        $resolver = new ResolveConfigurationFromConst('NON_EMPTY_CONST');
        static::assertSame('some_value' , $resolver->resolve());
    }

    #[TestDox('returns inner resolver content instead of null if provided')]
    public function testReturnsInnerResolverContentInsteadOfNullIfProvided(): void {
        $innerResolver = new class implements BatchCloneConfigurationResolverInterface {
            public function resolve(): ?string
            {
                return 'inner_value';
            }
        };
        
        $resolver = new ResolveConfigurationFromConst('NON_EXISTENT_CONST', $innerResolver);
        static::assertSame('inner_value', $resolver->resolve());
    }
}
