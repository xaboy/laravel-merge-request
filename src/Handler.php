<?php
/**
 * MergeRequest 合并请求
 * Author: xaboy
 * Github: https://github.com/xaboy/laravel-merge-request
 */

namespace Xaboy\MergeRequest;


use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteUrlGenerator;
use Symfony\Component\HttpFoundation\FileBag;
use Symfony\Component\HttpFoundation\ParameterBag;

class Handler
{
    /**
     * @var array
     */
    private $rule;

    /**
     * @var string|number
     */
    private $key;

    /**
     * @var Request
     */
    public $globalRequest;

    /**
     * @var Request
     */
    public $request;

    /**
     * @var Route
     */
    public $route;

    /**
     * @var array
     */
    protected $replace = [];

    /**
     * @var MergeRequest
     */
    protected $mergeRequest;

    /**
     * @var bool
     */
    protected $loaded = false;

    /**
     * @var RouteUrlGenerator
     */
    protected static $routeUrlGenerator;

    /**
     * @var string
     */
    protected static $method = 'GET';


    /**
     * Handler constructor.
     *
     * @param string       $key
     * @param array|string $rule
     * @param MergeRequest $mergeRequest
     */
    public function __construct($key, $rule, MergeRequest $mergeRequest)
    {
        $this->mergeRequest = $mergeRequest;
        $this->key = $key;
        $this->rule = $this->parseRule($rule);
        $this->checkTmp($this->rule);
        $this->globalRequest = app('request');
    }

    protected function parseRule($rule)
    {
        if (is_string($rule)) {
            $rule = [
                'path' => $rule
            ];
        } else if (is_array($rule) && !isset($rule['path'])) {
            $rule['path'] = '';
        }

        if ($rule['path'] && $rule['path'][0] != '/') {
            $rule['path'] = '/' . $rule['path'];
        }

        return $rule;
    }

    /**
     * 解析规则内模板变量
     *
     * @param array $rule
     */
    protected function checkTmp(array &$rule)
    {
        foreach ($rule as $k => &$value) {
            if (is_string($value)) {
                preg_match('/^\${mr\:([\w+\.]*\w+)}$/', trim($value), $data);
                if (isset($data[1])) {
                    $this->setReplace($data[1], $rule, $k);
                }
            } else if (is_array($value))
                $this->checkTmp($value);
        }
    }

    protected function setReplace(string $tmp, &$node, $key)
    {
        $path = explode('.', trim($tmp));

        //验证模板变量是否存在,是否为自身.避免死循环
        if ($this->mergeRequest->issetField($path[0]) && $path[0] != $this->key) {
            $this->replace[] = [
                'path' => $path,
                'node' => &$node,
                'key' => $key
            ];
        } else {
            $node[$key] = null;
        }

    }


    /**
     * 替换规则内模板变量
     *
     * @param array $mergeData
     */
    public function replace(array $mergeData)
    {
        if (!count($mergeData) || !$this->isDefer())
            return;

        foreach ($this->replace as $k => $item) {
            $_data = $mergeData;

            if (!$this->mergeRequest->getRequest($item['path'][0])->loaded()) continue;

            foreach ($item['path'] as $key) {
                if (isset($_data[$key]))
                    $_data = $_data[$key];
                else {
                    $_data = null;
                    break;
                }
            }

            $item['node'][$item['key']] = $_data;
            unset($this->replace[$k]);
        }
    }

    /**
     * 是否还有未替换的模板变量,如果有就会延迟执行
     *
     * @return bool
     */
    public function isDefer()
    {
        return count($this->replace) > 0;
    }

    /**
     * 解析路由规则
     * 匹配对应的 Route 类
     * 生成对应的 Request 类
     *
     * @throws \Illuminate\Routing\Exceptions\UrlGenerationException
     */
    protected function resolutionPath()
    {
        $routes = $this->getRules();
        $route = $this->getRule();
        $rule = $this->rule;

        if ($route) {
            $request = $this->makeRequest(
                $this->makeRouteUrl()->to($route, $rule['route'] ?? []),
                $rule['method'] ?? $route->methods[0] ?? self::$method
            );
        } else {
            $method = $rule['method'] ?? self::$method;
            $request = $this->makeRequest($rule['path'], $method);
            $route = $routes->match($request);
        }

        //解析路由参数
        $route->bind($request);

        $this->request = $request;
        $this->route = $route;
    }

    /**
     * @return RouteCollection
     */
    protected function getRules()
    {
        return app('router')->getRoutes();
    }

    /**
     * @return Route|null
     */
    protected function getRule()
    {
        $routes = $this->getRules();
        $rule = $this->rule;
        $path = $rule['path'];

        $route = $routes->getByName($path) ?? $routes->getByAction($path);
        if ($route) return $route;

        $method = $rule['method'] ?? self::$method;
        $methodRoutes = $routes->get(strtoupper($method));

        return $methodRoutes[$path] ?? null;
    }

    /**
     * 根据规则生成 Request
     *
     * @param string $path
     * @param string $method
     * @return Request
     */
    protected function makeRequest(string $path, string $method)
    {
        $parameter = $this->getAttr('parameter');
        $files = $this->getAttr('files');
        $get = $this->getAttr('get');
        $post = $this->getAttr('post');
        $globalRequest = $this->globalRequest;

        $request = Request::create(
            $path, strtoupper($method),
            [], [], [],
            $globalRequest->server->all()

        );

        $request->files = is_null($files) ? $globalRequest->files : new FileBag($files);
        $request->attributes = is_null($parameter) ? $globalRequest->attributes : new ParameterBag($parameter);
        $request->query = is_null($get) ? $globalRequest->query : new ParameterBag($get);
        $request->request = is_null($post) ? $globalRequest->request : new ParameterBag($post);
        $request->cookies = $globalRequest->cookies;

        $request->setUserResolver($globalRequest->getUserResolver());

        $request->setRouteResolver(function () {
            return $this->route;
        });

        return $request;
    }

    /**
     * @param $name
     * @return array|null
     */
    protected function getAttr($name)
    {
        return isset($this->rule[$name]) && is_array($this->rule[$name]) ? $this->rule[$name] : null;
    }

    /**
     * @return RouteUrlGenerator
     */
    protected function makeRouteUrl()
    {
        if (is_null(self::$routeUrlGenerator))
            self::$routeUrlGenerator = new RouteUrlGenerator(app('url'), $this->globalRequest);

        return self::$routeUrlGenerator;
    }

    /**
     * 是否已经执行
     *
     * @return bool
     */
    public function loaded()
    {
        return $this->loaded;
    }

    /**
     * 执行请求,返回结果
     *
     * @return mixed|null
     * @throws \Illuminate\Routing\Exceptions\UrlGenerationException
     */
    public function run()
    {
        $this->resolutionPath();

        $route = $this->route;

        $this->loaded = true;

        //没有匹配到路由规则,直接返回
        if (!$route)
            return null;

        app()->singleton('request', function () {
            return $this->request;
        });

        $response = $route->run();

        app()->singleton('request', function () {
            return $this->globalRequest;
        });

        if ($response instanceof Response)
            return $response->getOriginalContent();
        else
            return $response;
    }

}