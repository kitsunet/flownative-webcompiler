<?php
namespace Flownative\WebCompiler;

use Neos\Flow\Core\Bootstrap;

/**
 *
 */
class Package extends \Neos\Flow\Package\Package
{
    /**
     * @param Bootstrap $bootstrap
     */
    public function boot(Bootstrap $bootstrap)
    {
        $compileRequestHandler = new CompileRequestHandler($bootstrap);
        $bootstrap->registerRequestHandler($compileRequestHandler);
    }
}
