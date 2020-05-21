<?php
/**
 * Copyright 2016 1f7.wizard@gmail.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace RunTracy\Middlewares;

use Slim\App;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Tracy\Debugger;
use RunTracy\Helpers\PanelSelector;

/**
 * Class TracyMiddleware
 * @package RunTracy\Middlewares
 */
class TracyMiddleware
{
    protected $container;
    protected $defcfg;
    protected $versions;

    public function __construct(App $app = null)
    {
        include_once realpath(__DIR__ . '/../') . '/shortcuts.php';

        if ($app instanceof App) {
            $this->container = $app->getContainer();
            $this->versions = [
                'slim' => App::VERSION,
            ];
            $this->loadConfig();
            $this->runCollectors();
        }
    }

    /**
     * @param $request \Psr\Http\Message\RequestInterface
     * @param $response \Psr\Http\Message\ResponseInterface
     * @param $next Callable
     * @return mixed
     */
    public function __invoke(Request $request, Response $response, callable $next)
    {
        $res = $next($request, $response);

        $cookies = json_decode($request->getCookieParam('tracyPanelsEnabled'));
        
        if (!empty($cookies)) {
            $def = array_fill_keys(array_keys($this->defcfg), null);
            $cookies = array_fill_keys($cookies, 1);
            $cfg = array_merge($def, $cookies);
        } else {
            $cfg = [];
        }

        if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            unset($this->defcfg['showEloquentORMPanel']);
        }

        if (isset($cfg['showEloquentORMPanel']) && $cfg['showEloquentORMPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\EloquentORMPanel(
                \Illuminate\Database\Capsule\Manager::getQueryLog()
            ));
        }

        if (!class_exists('\Twig\Profiler\Profile') || !$this->container->has('Twig\Profiler\Profile')) {
        	unset($this->defcfg['showTwigPanel']);
        }

        if (isset($cfg['showTwigPanel']) && $cfg['showTwigPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\TwigPanel(
                $this->container->get('Twig\Profiler\Profile')
            ));
        }

        if (isset($cfg['showPhpInfoPanel']) && $cfg['showPhpInfoPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\PhpInfoPanel());
        }

        if (isset($cfg['showSlimEnvironmentPanel']) && $cfg['showSlimEnvironmentPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\SlimEnvironmentPanel(
                \Tracy\Dumper::toHtml($this->container->get('environment')),
                $this->versions
            ));
        }

        if (isset($cfg['showSlimContainer']) && $cfg['showSlimContainer']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\SlimContainerPanel(
                \Tracy\Dumper::toHtml($this->container),
                $this->versions
            ));
        }

        if (isset($cfg['showSlimRouterPanel']) && $cfg['showSlimRouterPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\SlimRouterPanel(
                \Tracy\Dumper::toHtml($this->container->get('router')),
                $this->versions
            ));
        }

        if (isset($cfg['showSlimRequestPanel']) && $cfg['showSlimRequestPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\SlimRequestPanel(
                \Tracy\Dumper::toHtml($this->container->get('request')),
                $this->versions
            ));
        }

        if (isset($cfg['showSlimResponsePanel']) && $cfg['showSlimResponsePanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\SlimResponsePanel(
                \Tracy\Dumper::toHtml($this->container->get('response')),
                $this->versions
            ));
        }

        if (isset($cfg['showVendorVersionsPanel']) && $cfg['showVendorVersionsPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\VendorVersionsPanel());
        }

        if (isset($cfg['showXDebugHelper']) && $cfg['showXDebugHelper']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\XDebugHelper(
                $this->defcfg['configs']['XDebugHelperIDEKey']
            ));
        }

        if (isset($cfg['showIncludedFiles']) && $cfg['showIncludedFiles']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\IncludedFiles());
        }

        // check if enabled or blink if active critical value
        if ((isset($cfg['showConsolePanel']) && $cfg['showConsolePanel']) || (isset($this->defcfg['configs']['ConsoleNoLogin']) && $this->defcfg['configs']['ConsoleNoLogin'])) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\ConsolePanel(
                $this->defcfg['configs']
            ));
        }

        if (isset($cfg['showProfilerPanel']) && $cfg['showProfilerPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\ProfilerPanel(
                $this->defcfg['configs']['ProfilerPanel']
            ));
        }

        if (isset($cfg['showIdiormPanel']) && $cfg['showIdiormPanel']) {
            Debugger::getBar()->addPanel(new \RunTracy\Helpers\IdiormPanel());
        }

        if (!class_exists('\Doctrine\DBAL\Connection') || !$this->container->has('doctrineConfig')) {
        	unset($this->defcfg['showDoctrinePanel']);
        }

        if (isset($cfg['showDoctrinePanel']) && $cfg['showDoctrinePanel']) {
            Debugger::getBar()->addPanel(
                new \RunTracy\Helpers\DoctrinePanel(
                    $this->container->get('doctrineConfig')->getSQLLogger()->queries
                )
            );
        }

        // hardcoded without config prevent switch off
        if (!isset($this->defcfg) && !is_array($this->defcfg)) {
            $this->defcfg = [];
        }

        Debugger::getBar()->addPanel(new PanelSelector(
            $cfg,
            array_diff_key($this->defcfg, ['configs' => null])
        ));

        return $res;
    }

    protected function loadConfig()
    {
    	if($this->container->has('settings.tracy')) {
    		$this->defcfg = $this->container->get('settings.tracy');
	    } else {
    		$this->defcfg = $this->container->get('settings')['tracy'];
	    }
    }

    protected function runCollectors()
    {
        if (isset($this->defcfg['showIdiormPanel']) && $this->defcfg['showIdiormPanel'] > 0) {
            if (class_exists('\ORM')) {
                // no return values
                new \RunTracy\Collectors\IdormCollector();
            }
        }

        if (isset($this->defcfg['showDoctrinePanel']) && class_exists('\Doctrine\DBAL\Connection')) {
            new \RunTracy\Collectors\DoctrineCollector(
                $this->container,
                $this->defcfg['showDoctrinePanel']
            );
        }
    }
}
