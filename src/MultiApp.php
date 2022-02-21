<?php
/** .-------------------------------------------------------------------
 * |  Site: www.skytosky.cn
 * |-------------------------------------------------------------------
 * |  Author: 虫不知 <2388456065@qq.com>
 * |  Copyright (c) 2021-2029, www.skytosky.cn. All Rights Reserved.
 * |
 * |  Thanks for topthink/think-multi-app.
 * '-------------------------------------------------------------------*/

declare (strict_types=1);

namespace yeagan\app;

use Closure;
use think\App;
use think\exception\HttpException;
use think\Request;
use think\Response;
use think\event\RouteLoaded;

/**
 * 多应用模式支持
 */
class MultiApp
{

    /** @var App */
    protected $app;

    /**
     * 应用名称
     * @var string
     */
    protected $name;

    /**
     * 应用名称
     * @var string
     */
    protected $appName;

    /**
     * 应用路径
     * @var string
     */
    protected $path;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->name = $this->app->http->getName();
        $this->path = $this->app->http->getPath();
    }

    /**
     * 多应用解析
     * @access public
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle($request, Closure $next)
    {
        if (!$this->parseMultiApp()) {
            return $next($request);
        }

        return $this->app->middleware->pipeline('app')
            ->send($request)
            ->then(function ($request) use ($next) {
                return $next($request);
            });
    }

    /**
     * 获取路由目录
     * @access protected
     * @return string
     */
    protected function getRoutePath(): string
    {
        return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
    }

    /**
     * 解析多应用
     * @return bool
     */
    protected function parseMultiApp(): bool
    {
        $scriptName = $this->getScriptName();
        $defaultApp = $this->app->config->get('app.default_app') ?: 'index';

        if ($this->name || ($scriptName && !in_array($scriptName, ['index', 'router', 'think']))) {
            $appName = $this->name ?: $scriptName;
            $this->app->http->setBind();
        } else {
            // 自动多应用识别
            $this->app->http->setBind(false);
            $appName = null;
            $this->appName = '';

            $bind = $this->app->config->get('app.domain_bind', []);

            if (!empty($bind)) {
                // 获取当前子域名
                $subDomain = $this->app->request->subDomain();
                $domain = $this->app->request->host(true);

                if (isset($bind[$domain])) {
                    $appName = $bind[$domain];
                    $this->app->http->setBind();
                } elseif (isset($bind[$subDomain])) {
                    $appName = $bind[$subDomain];
                    $this->app->http->setBind();
                } elseif (isset($bind['*'])) {
                    $appName = $bind['*'];
                    $this->app->http->setBind();
                }
            }

            if (!$appName) {
                $addonBind = $this->app->config->get('addons.domain_bind', []);
                if (!empty($addonBind)) {
                    // 获取当前子域名
                    $subDomain = $this->app->request->subDomain();
                    $domain = $this->app->request->host(true);

                    if (isset($addonBind[$domain])) {
                        $appName = $addonBind[$domain];
                    } elseif (isset($addonBind[$subDomain])) {
                        $appName = $addonBind[$subDomain];
                    }

                    // 插件域名 (插件前端访问)
                    if ($appName) {
                        $this->setAppByAddonBindFrontend($appName, 'frontend');
                        return true;
                    }
                }
            }

            if (!$this->app->http->isBind()) {
                $path = $this->app->request->pathinfo();
                $map = $this->app->config->get('app.app_map', []);
                $deny = $this->app->config->get('app.deny_app_list', []);
                $name = current(explode('/', $path));

                if (strpos($name, '.')) {
                    $name = strstr($name, '.', true);
                }

                if (isset($map[$name])) {
                    if ($map[$name] instanceof Closure) {
                        $result = call_user_func_array($map[$name], [$this->app]);
                        $appName = $result ?: $name;
                    } else {
                        $appName = $map[$name];
                    }
                } elseif ($name && (false !== array_search($name, $map) || in_array($name, $deny))) {
                    throw new HttpException(404, 'app not exists:' . $name);
                } elseif ($name && isset($map['*'])) {
                    $appName = $map['*'];
                } else {
                    $appName = $name ?: $defaultApp;
                    $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

                    if (!is_dir($appPath)) {
                        $express = $this->app->config->get('app.app_express', false);
                        if ($express) {
                            $this->setApp($defaultApp);
                            return true;
                        } else {

                            // 检查是否为插件后端
                            $path = $this->app->request->pathinfo();
                            $pathArray = explode('/', $path);
                            $addonFlag = current($pathArray);
                            if ($addonFlag == 'addons') {
                                $addonName = $pathArray[1] ?? '';
                                if (!empty($addonName)) {
                                    $addonBackendDir = $this->getAddonsRootPath() . $addonName;
                                    if (is_dir($addonBackendDir)) {
                                        // 插件后端
                                        $this->app->http->setBind();
                                        $this->app->request->setAddonName($addonName);
                                        $this->setAppByAddonBackend($addonName, 'backend');
                                        return true;
                                    }
                                }

                            } else {

                                $addonFrontendPath = $this->getAddonsRootPath() . $addonFlag . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR;
                                if (is_dir($addonFrontendPath)) {

                                    // 插件前端访问
                                    $this->app->request->setAddonName($appName);
                                    $this->setAppByAddonFrontend($addonFlag, 'frontend');
                                    return true;
                                }

                            }


                            return false;
                        }
                    }
                }

                if ($name) {
                    $this->app->request->setRoot('/' . $name);

                    //$pathInfo = strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '';
                    //$this->app->request->setPathinfo($pathInfo);
                }
            }
        }

        // app目录下的应用访问
        $this->setApp($appName ?: $defaultApp);
        return true;
    }

    /**
     * 获取当前运行入口名称
     * @access protected
     * @codeCoverageIgnore
     * @return string
     */
    protected function getScriptName(): string
    {
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $file = $_SERVER['SCRIPT_FILENAME'];
        } elseif (isset($_SERVER['argv'][0])) {
            $file = realpath($_SERVER['argv'][0]);
        }

        return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
    }

    /**
     * 设置应用
     * @param string $appName
     */
    protected function setApp(string $appName): void
    {
        $this->app->http->setBind();
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

        $this->app->setAppPath($appPath);
        // 设置应用命名空间
        $this->app->setNamespace($this->app->config->get('app.app_namespace') ?: 'app\\' . $appName);

        if (is_dir($appPath)) {
            $this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
            $this->app->http->setRoutePath($this->getRoutePath());

            //加载应用
            $this->loadApp($appName, $appPath);

            //加载所有插件路由
            $this->loadAddonRoute();
        }
    }

    /**
     * 加载应用文件
     * @param string $appName 应用名
     * @return void
     */
    protected function loadApp(string $appName, string $appPath): void
    {
        if (is_file($appPath . 'common.php')) {
            include_once $appPath . 'common.php';
        }

        $files = [];

        $files = array_merge($files, glob($appPath . 'config' . DIRECTORY_SEPARATOR . '*' . $this->app->getConfigExt()));

        foreach ($files as $file) {
            $this->app->config->load($file, pathinfo($file, PATHINFO_FILENAME));
        }

        if (is_file($appPath . 'event.php')) {
            $this->app->loadEvent(include $appPath . 'event.php');
        }

        if (is_file($appPath . 'middleware.php')) {
            $this->app->middleware->import(include $appPath . 'middleware.php', 'app');
        }

        if (is_file($appPath . 'provider.php')) {
            $this->app->bind(include $appPath . 'provider.php');
        }

        // 加载应用默认语言包
        $this->app->loadLangPack($this->app->lang->defaultLangSet());
    }

    /**
     * 设置插件后端应用
     * @param string $appName 插件名称
     * @param string $type
     */
    protected function setAppByAddonBackend(string $appName, $type = 'backend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonsRootPath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());
            $this->app->request->setRoot('/' . 'addons/' . $appName . '/' . $type);

            // 加载后台admin应用的初始化文件
            $adminAppPath = $this->app->getBasePath() . 'admin' . DIRECTORY_SEPARATOR;
            $this->loadApp('admin', $adminAppPath);

            // 加载后端admin的路由
            $this->loadAdminRoute();

            //加载插件后端应用
            //$this->loadApp($appName, $appPath);
        }
    }

    /**
     * 设置插件前端应用
     * @param string $appName
     * @param string $type
     */
    protected function setAppByAddonFrontend(string $appName, $type = 'frontend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonsRootPath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());
            $this->app->request->setRoot('/' . 'addons/' . $appName . '/' . $type);
            $path = $this->app->request->pathinfo();
            $path = strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '';
            $this->app->request->setPathinfo($path);

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }

    /**
     * 设置插件前端应用(插件域名绑定)
     * @param string $appName
     * @param string $type
     */
    protected function setAppByAddonBindFrontend(string $appName, $type = 'frontend'): void
    {
        $this->appName = $appName;
        $this->app->http->name($appName);

        $appPath = $this->path ?: $this->getAddonsRootPath() . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;
        $this->app->setAppPath($appPath);

        // 设置应用命名空间
        $nameSpace = ($this->app->config->get('app.addon_namespace') ?: 'addons\\' . $appName) . '\\' . $type;
        $this->app->setNamespace($nameSpace);

        if (is_dir($appPath)) {

            $runtimePath = $this->app->getRuntimePath() . 'addons' . DIRECTORY_SEPARATOR . $appName . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR;

            $this->app->setRuntimePath($runtimePath);

            $this->app->http->setRoutePath($this->getRoutePath());

            $this->app->request->setRoot('/' . 'addons/' . $appName . '/frontend');

            //加载应用
            $this->loadApp($appName, $appPath);
        }
    }

    /**
     * 获取所有插件的根目录
     * @return string
     */
    protected function getAddonsRootPath()
    {
        return $this->app->getRootPath() . 'addons' . DIRECTORY_SEPARATOR;
    }

    /**
     * 加载所有插件路由
     */
    protected function loadAddonRoute()
    {
        $this->app->event->listen(RouteLoaded::class, function () {
            $addonsPath = $this->getAddonsRootPath();
            $addonsDir = scandir($addonsPath);
            foreach ($addonsDir as $addon) {
                if (in_array($addon, ['.', '..'])) {
                    continue;
                }

                $routePath = $addonsPath . $addon . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'route' . DIRECTORY_SEPARATOR;
                if (is_dir($routePath)) {
                    $files = glob($routePath . '*.php');
                    foreach ($files as $file) {
                        include $file;
                    }
                }
            }
        });
    }

    /**
     * 加载admin后端路由
     * @param string $admin
     */
    protected function loadAdminRoute($admin = 'admin')
    {
        $this->app->event->listen(RouteLoaded::class, function ($admin) {
            $adminAppPath = $this->app->getBasePath() . $admin . DIRECTORY_SEPARATOR;
            $routePath = $adminAppPath . 'route' . DIRECTORY_SEPARATOR;
            if (is_dir($routePath)) {
                $files = glob($routePath . '*.php');
                foreach ($files as $file) {
                    include $file;
                }
            }
        });
    }
}
