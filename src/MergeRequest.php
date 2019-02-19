<?php
/**
 * MergeRequest 合并请求
 * Author: xaboy
 * Github: https://github.com/xaboy/laravel-merge-request
 */

namespace Xaboy\MergeRequest;


use Illuminate\Support\Facades\Event;

class MergeRequest
{

    /**
     * @var array
     */
    public $fields = [];

    /**
     * @var Handler[]
     */
    protected $handlers = [];

    /**
     * @var array
     */
    protected $result = [];

    /**
     * @var bool
     */
    protected $loaded = false;

    protected $observer;

    public function __construct(array $rules = [])
    {
        $this->fields = array_keys($rules);

        foreach ($rules as $k => $rule) {
            $this->handlers[$k] = new Handler($k, $rule, $this);
        }
    }

    /**
     * @param string|int   $key
     * @param string|array $rule
     * @return $this
     */
    public function add($key, $rule)
    {
        $this->handlers[$key] = new Handler($key, $rule, $this);
        if (!in_array($key, $this->fields, true))
            $this->fields[] = $key;

        return $this;
    }

    public static function crete(array $rule)
    {

        return new self($rule);
    }

    /**
     * @param string $field
     * @return bool
     */
    public function issetField(string $field)
    {
        return in_array($field, $this->fields);
    }

    /**s
     *
     * @param string $field
     * @return Handler|null
     */
    public function getRequest(string $field)
    {
        return $this->handlers[$field] ?? null;
    }

    protected function pullResult()
    {
        foreach ($this->handlers as $k => $item) {
            if (!isset($this->result[$k]))
                $this->result[$k] = null;
        }
    }

    protected function fireEvent(string $event, array $payload = [])
    {
        Event::fire('mergeRequest.' . $event, $payload);
    }

    public function observer($class)
    {

        $className = is_string($class) ? $class : get_class($class);

        foreach ($this->getObservableEvents() as $event) {
            if (method_exists($className, $event))
                Event::listen('mergeRequest.' . $event, $className . '@' . $event);
        }
    }

    protected function getObservableEvents()
    {
        return [
            'executing', 'executed', 'loaded'
        ];
    }

    /**
     * @return array
     * @throws \Illuminate\Routing\Exceptions\UrlGenerationException
     */
    public function run()
    {
        $res = $this->result;

        if ($this->loaded)
            return $res;

        $isDefer = false;
        $isChange = false;

        foreach ($this->handlers as $k => $handler) {
            //如果已经请求,直接跳过
            if ($handler->loaded()) continue;

            //替换规则内部变量`${mr:val}`
            $handler->replace($res);

            $_isDefer = $handler->isDefer();
            $isDefer = $isDefer || $_isDefer;

            if (!$_isDefer) {

                //触发执行前事件
                $this->fireEvent('executing', [$k, &$res]);

                $res[$k] = $handler->run();
                $isChange = true;

                //触发执行后事件
                $this->fireEvent('executed', [$res[$k], $k, &$res]);
            }
        }

        $this->result = $res;

        //避免死循环,没有执行新的的请求.直接返回
        if (!$isChange) {
            $this->pullResult();
            $isDefer = false;
        }

        //有未执行的请求,继续递归请求
        if ($isDefer)
            return $this->run();

        $this->loaded = true;

        //触发加载完成事件
        $this->fireEvent('loaded', [&$this->result]);

        return $this->result;
    }
}