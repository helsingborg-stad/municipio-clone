<?php

namespace MunicipioClone\Cli\BatchCloneConfigurationResolver;

class ResolveConfigurationFromConst implements BatchCloneConfigurationResolverInterface
{
    public function __construct(
        private string $const,
        private ?BatchCloneConfigurationResolverInterface $next = null,
    ) {}

    public function resolve(): ?string
    {
        $content = defined($this->const) ? constant($this->const) : null;

        if($content !== null && strlen(trim($content)) > 0) {
            return $content;    
        }

        return $this->next?->resolve();
    }
}
