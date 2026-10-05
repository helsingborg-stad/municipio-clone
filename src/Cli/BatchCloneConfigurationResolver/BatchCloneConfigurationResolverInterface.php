<?php

namespace MunicipioClone\Cli\BatchCloneConfigurationResolver;

interface BatchCloneConfigurationResolverInterface
{
    /**
     * Resolves and returns the batch clone configuration.
     *
     * @return string|null The resolved configuration string or null if not available.
     */
    public function resolve(): ?string;
}
