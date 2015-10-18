<?php

/**
 * LinkCache - 一个灵活高效的PHP缓存工具库
 *
 * @author      Dong Nan <hidongnan@gmail.com>
 * @copyright   (c) Dong Nan http://idongnan.cn All rights reserved.
 * @link        http://git.oschina.net/dongnan/LinkCache
 * @license     BSD (http://www.freebsd.org/copyright/freebsd-license.html)
 */

namespace linkcache;

/**
 * 缓存类
 */
class Cache {

    use traits\Cache;

    /**
     * 缓存类型
     * @var string 
     */
    private $type;

    /**
     * 缓存驱动
     * @var linkcache\interfaces\driver\Base
     */
    private $driver;

    /**
     * 默认配置
     * @var array 
     */
    static private $config = [
        'default' => 'files',
        //当前缓存驱动失效时，采用的备份驱动
        'fallback' => 'files',
        'memcache' => [
            //host,port,weight,persistent,timeout,retry_interval,status,failure_callback
            'servers' => [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1, 'persistent' => true, 'timeout' => 1, 'retry_interval' => 15, 'status' => true],
            ],
            'compress' => ['threshold' => 2000, 'min_saving' => 0.2],
        ],
        'memcached' => [
            'servers' => [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1],
            ],
            //参考 Memcached::setOptions
            'options' => [],
        ],
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => '',
            'timeout' => ''
        ],
        'ssdb' => [
            'host' => '127.0.0.1',
            'port' => 8888,
            'password' => '',
            'timeoutms' => ''
        ],
    ];

    /**
     * 缓存驱动实例集合
     * @var array
     */
    static private $_drivers = [];

    /**
     * 缓存类实例集合
     * @var array 
     */
    static private $_instances = [];

    /**
     * 构造缓存
     * @param string $type  缓存驱动类型
     * @param array $config 驱动配置
     * @throws \Exception   异常
     */
    public function __construct($type = '', $config = []) {
        if (empty($type)) {
            $type = self::$config['default'] ? : 'files';
        }
        $key = $type . md5(serialize($config));
        if (!isset(self::$_drivers[$key])) {
            $class = strpos($type, '\\') ? $type : 'linkcache\\drivers\\' . ucwords(strtolower($type));
            if (class_exists($class)) {
                if (!empty($type) && isset(self::$config[$type])) {
                    $config = array_merge(self::$config[$type], $config);
                }
                self::$_drivers[$key] = new $class($config);
            } else {
                throw new \Exception("{$class} is not exists!");
            }
        }
        $this->type = $type;
        $this->driver = self::$_drivers[$key];
    }

    /**
     * 获取缓存驱动实例
     * @return linkcache\interfaces\driver\Base
     */
    public function getDriver() {
        return $this->driver;
    }

    /**
     * 设置默认配置
     * @param array $config 配置信息
     */
    static public function setConfig($config) {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * 获取配置信息
     * @param string $name      键名
     * @return array $config    配置信息
     */
    static public function getConfig($name = '') {
        if (empty($name)) {
            return self::$config;
        } else {
            return isset(self::$config[$name]) ? self::$config[$name] : null;
        }
    }

    /**
     * 获取缓存类实例
     * @param string $type  缓存驱动类型
     * @param array $config 驱动配置
     * @return Cache        缓存类实例
     * @throws \Exception   异常
     */
    static public function getInstance($type = '', $config = []) {
        $key = $type . md5(serialize($config));
        if (!isset(self::$_instances[$key])) {
            self::$_instances[$key] = new Cache($type, $config);
        }
        return self::$_instances[$key];
    }

    /**
     * 设置键值
     * @param string $key   键名
     * @param mixed $value  键值
     * @param int $time     过期时间,默认为-1,不设置过期时间;为0则设置为永不过期
     * @return boolean      是否成功
     */
    public function set($key, $value, $time = -1) {
        if ($this->driver->checkDriver()) {
            return $this->driver->set($key, $value, $time);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->set($key, $value, $time);
        }
        return false;
    }

    /**
     * 当键名不存在时设置键值
     * @param string $key   键名
     * @param mixed $value  键值
     * @param int $time     过期时间,默认为-1,不设置过期时间;为0则设置为永不过期
     * @return boolean      是否成功
     */
    public function setnx($key, $value, $time = -1) {
        if ($this->driver->checkDriver()) {
            return $this->driver->setnx($key, $value, $time);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->setnx($key, $value, $time);
        }
        return false;
    }

    /**
     * 获取键值
     * @param string $key   键名
     * @return mixed|false  键值,失败返回false
     */
    public function get($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->get($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->get($key);
        }
        return false;
    }

    /**
     * 二次获取键值,在get方法没有获取到值时，调用此方法将有可能获取到
     * 此方法是为了防止惊群现象发生,配合lock和isLock方法,设置新的缓存
     * @param string $key   键名
     * @return mixed|false  键值,失败返回false
     */
    public function getTwice($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->getTwice($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->getTwice($key);
        }
        return false;
    }

    /**
     * 删除键值
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function del($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->del($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->del($key);
        }
        return false;
    }

    /**
     * 是否存在键值
     * @param string $key   键名
     * @return boolean      是否存在
     */
    public function has($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->has($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->has($key);
        }
        return false;
    }

    /**
     * 获取生存剩余时间
     * @param string $key   键名
     * @return int|false    生存剩余时间(单位:秒) -1表示永不过期,-2表示键值不存在,失败返回false
     */
    public function ttl($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->ttl($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->ttl($key);
        }
        return false;
    }

    /**
     * 设置过期时间
     * @param string $key   键名
     * @param int $time     过期时间(单位:秒)。不大于0，则设为永不过期
     * @return boolean      是否成功
     */
    public function expire($key, $time) {
        if ($this->driver->checkDriver()) {
            return $this->driver->expire($key, $time);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->expire($key, $time);
        }
        return false;
    }

    /**
     * 设置过期时间戳
     * @param string $key   键名
     * @param int $time     过期时间戳
     * @return boolean      是否成功
     */
    public function expireAt($key, $time) {
        $difftime = $time - time();
        if ($this->driver->checkDriver()) {
            if ($difftime) {
                return $this->driver->expire($key, $difftime);
            } else {
                return $this->driver->del($key);
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->expireAt($key, $time);
        }
        return false;
    }

    /**
     * 移除指定键值的过期时间
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function persist($key) {
        if ($this->driver->checkDriver()) {
            return $this->driver->persist($key);
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->persist($key);
        }
        return false;
    }

    /**
     * 对指定键名加锁（此锁并不对键值做修改限制,仅为键名的锁标记）
     * 此方法可用于防止惊群现象发生,在get方法获取键值无效时,先判断键名是否加锁,
     * 如果已加锁,则不获取新值;如果未加锁,则获取新值,设置新的缓存
     * @param string $key   键名
     * @param int $time     加锁时间
     * @return boolean      是否成功
     */
    public function lock($key, $time = 60) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'lock')) {
                return $this->driver->lock($key, $time);
            } else {
                return $this->driver->set(self::lockKey($key), 1, $time);
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->lock($key, $time);
        }
        return false;
    }

    /**
     * 对指定键名解锁
     * 此方法可用于防止惊群现象发生,在get方法获取键值无效时,判断键名是否加锁
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function isLock($key) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'isLock')) {
                return $this->driver->isLock($key);
            } else {
                return $this->driver->has(self::lockKey($key));
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->isLock($key);
        }
        return false;
    }

    /**
     * 递增
     * @param string $key   键名
     * @param int $step     递增步长
     * @return int|false    递增后的值,失败返回false
     */
    public function incr($key, $step = 1) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'incr')) {
                return $this->driver->incr($key, $step);
            } else {
                $value = $this->driver->get($key);
                if (($value !== false && !is_int($value)) || !is_int($step)) {
                    return false;
                }
                if ($this->driver->set($key, $value += $step)) {
                    return $value;
                }
                return false;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->incr($key, $step);
        }
        return false;
    }

    /**
     * 浮点数递增
     * @param string $key   键名
     * @param float $float  递增步长
     * @return float|false  递增后的值,失败返回false
     */
    public function incrByFloat($key, $float) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'incrByFloat')) {
                return $this->driver->incrByFloat($key, $float);
            } else {
                $value = $this->driver->get($key);
                if (($value !== false && !is_numeric($value)) || !is_numeric($float)) {
                    return false;
                }
                if ($this->driver->set($key, $value += $float)) {
                    return $value;
                }
                return false;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->incrByFloat($key, $float);
        }
        return false;
    }

    /**
     * 递减
     * @param string $key   键名
     * @param int $step     递减步长
     * @return int|false    递减后的值,失败返回false
     */
    public function decr($key, $step = 1) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'decr')) {
                return $this->driver->decr($key, $step);
            } else {
                $value = $this->driver->get($key);
                if (($value !== false && !is_int($value)) || !is_int($step)) {
                    return false;
                }
                if ($this->driver->set($key, $value -= $step)) {
                    return $value;
                }
                return false;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->decr($key, $step);
        }
        return false;
    }

    /**
     * 批量设置键值
     * @param array $sets   键值数组
     * @return boolean      是否成功
     */
    public function mSet($sets) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'mSet')) {
                return $this->driver->mSet($sets);
            } else {
                $oldSets = [];
                $status = true;
                foreach ($sets as $key => $value) {
                    $oldSets[$key] = $this->driver->get($key);
                    $status = $this->driver->set($key, $value);
                    if (!$status) {
                        break;
                    }
                }
                //如果失败，尝试回滚，但不保证成功
                if (!$status) {
                    foreach ($oldSets as $key => $value) {
                        if ($value === false) {
                            $this->driver->del($key);
                        } else {
                            $this->driver->set($key, $value);
                        }
                    }
                }
                return $status;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->mSet($sets);
        }
        return false;
    }

    /**
     * 批量设置键值(当键名不存在时)
     * 只有当键值全部设置成功时,才返回true,否则返回false并尝试回滚
     * @param array $sets   键值数组
     * @return boolean      是否成功
     */
    public function mSetNX($sets) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'mSetNX')) {
                return $this->driver->mSetNX($sets);
            } else {
                $keys = [];
                $status = true;
                foreach ($sets as $key => $value) {
                    $status = $this->driver->setnx($key, $value);
                    if ($status) {
                        $keys[] = $key;
                    } else {
                        break;
                    }
                }
                //如果失败，尝试回滚，但不保证成功
                if (!$status) {
                    foreach ($keys as $key) {
                        $this->driver->del($key);
                    }
                }
                return $status;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->mSetNX($sets);
        }
        return false;
    }

    /**
     * 批量获取键值
     * @param array $keys   键名数组
     * @return array|false  键值数组,失败返回false
     */
    public function mGet($keys) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, 'mGet')) {
                return $this->driver->mGet($keys);
            } else {
                $values = [];
                foreach ($keys as $key) {
                    $values[$key] = $this->driver->get($key);
                }
                return $values;
            }
        }
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            return $this->driver->backup()->mGet($keys);
        }
        return false;
    }

    public function __set($name, $value) {
        return $this->set($name, $value);
    }

    public function __get($name) {
        return $this->get($name);
    }

    public function __unset($name) {
        $this->del($name);
    }

    /**
     * Call the cache driver's method
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws \Exception
     */
    public function __call($method, $args) {
        if ($this->driver->checkDriver()) {
            if (method_exists($this->driver, $method)) {
                return call_user_func_array(array($this->driver, $method), $args);
            } else {
                throw new \Exception(__CLASS__ . ":{$method} is not exists!");
            }
        }
        //fallback执行中出现异常直接捕获
        if ($this->driver->isFallback() && $this->type !== self::$config['fallback']) {
            try {
                return call_user_func_array(array($this->driver->backup(), $method), $args);
            } catch (\Exception $ex) {
                CacheDriverTrait::exception($ex);
            }
        }
        return false;
    }

}
