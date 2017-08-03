<?php
namespace Flownative\WebCompiler;

use Neos\Flow\Aop\Builder\ProxyClassBuilder;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Http\Request;
use Neos\Flow\Http\Response;
use Neos\Flow\ObjectManagement\Proxy\Compiler;
use Neos\Flow\Utility\Environment;
use Neos\Flow\Persistence\Doctrine\Service as DoctrineService;
use Neos\Utility\Files;

/**
 *
 */
class CompileRequestHandler implements HttpRequestHandlerInterface
{
    /**
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @var \Closure
     */
    protected $exit;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var CacheManager
     */
    protected $cacheManager;

    /**
     * @var Compiler
     */
    protected $proxyClassCompiler;

    /**
     * @var ProxyClassBuilder
     */
    protected $aopProxyClassBuilder;

    /**
     * @var \Neos\Flow\ObjectManagement\DependencyInjection\ProxyClassBuilder
     */
    protected $dependencyInjectionProxyClassBuilder;

    /**
     * @var Environment
     */
    protected $environment;

    /**
     * @var DoctrineService
     */
    protected $doctrineService;

    /**
     * @param Bootstrap $bootstrap
     */
    public function __construct(Bootstrap $bootstrap)
    {
        $this->bootstrap = $bootstrap;
        $this->exit = function () {
            exit();
        };
    }

    public function getHttpRequest()
    {
        return $this->request;
    }

    public function getHttpResponse()
    {
        return $this->response;
    }

    /**
     *
     */
    public function handleRequest()
    {
        // Create the request very early so the Resource Management has a chance to grab it:
        $this->request = Request::createFromEnvironment();
        $this->response = new Response();

        $sequence = $this->bootstrap->buildCompiletimeSequence();
        $sequence->invoke($this->bootstrap);

        $this->resolveDependencies();
        $this->compileProxyClasses();

        $this->response->setHeader('X-Flow-Compilation', 'finished');
        $this->response->send();

        $this->bootstrap->shutdown(Bootstrap::RUNLEVEL_COMPILETIME);
        $this->exit->__invoke();
    }

    /**
     *
     */
    protected function compileProxyClasses()
    {
        $objectConfigurationCache = $this->cacheManager->getCache('Flow_Object_Configuration');
        /** @var PhpFrontend $classesCache */
        $classesCache = $this->cacheManager->getCache('Flow_Object_Classes');
        $this->proxyClassCompiler->injectClassesCache($classesCache);

        $this->aopProxyClassBuilder->injectObjectConfigurationCache($objectConfigurationCache);
        $this->aopProxyClassBuilder->build();
        $this->dependencyInjectionProxyClassBuilder->build();

        $classCount = $this->proxyClassCompiler->compile();

        $dataTemporaryPath = $this->environment->getPathToTemporaryDirectory();
        Files::createDirectoryRecursively($dataTemporaryPath);
        file_put_contents($dataTemporaryPath . 'AvailableProxyClasses.php', $this->proxyClassCompiler->getStoredProxyClassMap());

        $objectConfigurationCache->set('allCompiledCodeUpToDate', true);

        $classesCacheBackend = $classesCache->getBackend();
        if ($this->bootstrap->getContext()->isProduction() && $classesCacheBackend instanceof FreezableBackendInterface) {
            /** @var FreezableBackendInterface $backend */
            $backend = $classesCache->getBackend();
            $backend->freeze();
        }

        $this->emitFinishedCompilationRun($classCount);
    }

    /**
     * Compile the Doctrine proxy classes
     *
     * @return void
     */
    public function compileDoctrineProxies()
    {
        $this->doctrineService->compileProxies();
    }

    protected function resolveDependencies()
    {
        $this->cacheManager = $this->bootstrap->getObjectManager()->get(CacheManager::class);
        $this->proxyClassCompiler = $this->bootstrap->getObjectManager()->get(Compiler::class);
        $this->aopProxyClassBuilder = $this->bootstrap->getObjectManager()->get(ProxyClassBuilder::class);
        $this->dependencyInjectionProxyClassBuilder = $this->bootstrap->getObjectManager()->get(\Neos\Flow\ObjectManagement\DependencyInjection\ProxyClassBuilder::class);
        $this->environment = $this->bootstrap->getObjectManager()->get(Environment::class);
        $this->doctrineService = $this->bootstrap->getObjectManager()->get(DoctrineService::class);
    }

    /**
     * This request handler can handle any web request.
     *
     * @return boolean If the request is a web request, TRUE otherwise FALSE
     */
    public function canHandleRequest()
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }

        $requestUri = ltrim($_SERVER['REQUEST_URI'], '/');
        return ($requestUri === '~compile~' && $_SERVER['REQUEST_METHOD'] === 'POST');
    }

    /**
     * Returns the priority - how eager the handler is to actually handle the
     * request.
     *
     * @return integer The priority of the request handler.
     */
    public function getPriority()
    {
        return 400;
    }

    /**
     * Signals that the compile command was successfully finished.
     *
     * @param integer $classCount Number of compiled proxy classes
     * @return void
     */
    protected function emitFinishedCompilationRun($classCount)
    {
        $this->bootstrap->getSignalSlotDispatcher()->dispatch(__CLASS__, 'finishedCompilationRun', [$classCount]);
    }

}
