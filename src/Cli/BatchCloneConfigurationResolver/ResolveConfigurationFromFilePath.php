<?php

namespace MunicipioClone\Cli\BatchCloneConfigurationResolver;

class ResolveConfigurationFromFilePath implements BatchCloneConfigurationResolverInterface
{
    public function __construct(
        private string $filePath,
        private ?BatchCloneConfigurationResolverInterface $next = null,
    ) {}

    public function resolve(): ?string
    {
        if(file_exists($this->filePath)) {
            $content = file_get_contents($this->filePath);

            if($content !== false && strlen(trim($content)) > 0) {
                return $content;
            }            
        }

        return $this->next?->resolve();
    }
}
