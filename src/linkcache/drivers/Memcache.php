<?php

/**
 * linkcache - 一个灵活高效的PHP缓存工具库
 *
 * @author      Dong Nan <hidongnan@gmail.com>
 * @copyright   (c) Dong Nan http://idongnan.cn All rights reserved.
 * @link        http://git.oschina.net/dongnan/LinkCache
 * @license     BSD (http://www.freebsd.org/copyright/freebsd-license.html)
 */

namespace linkcache\drivers;

use linkcache\CacheDriverInterface;
use linkcache\CacheDriverExtendInterface;
use \Exception;

/**
 * Memcache
 */
class Memcache implements CacheDriverInterface, CacheDriverExtendInterface {

    use \linkcache\CacheDriverTrait;

    /**
     * 配置信息
     * @var array 
     */
    private $config = [];

    /**
     * Memcache 对象
     * @var \Memcache 
     */
    private $handler;

    /**
     * 是否连接server
     * @var boolean 
     */
    private $isConnected = false;

    /**
     * 是否使用备用缓存
     * @var boolean
     */
    private $fallback = false;

    /**
     * 压缩参数
     * @var int
     */
    private $compress = 0;

    /**
     * 构造函数
     * @param array $config 配置
     * @throws \Exception   异常
     */
    public function __construct($config = []) {
        if (!extension_loaded('memcache')) {
            throw new \Exception("memcache extension is not exists!");
        }
        $this->handler = new \Memcache();
        $this->config = $config;
        $this->initServers();
    }

    /**
     * 初始化servers
     */
    public function initServers() {
        if (empty($this->config['servers'])) {
            $servers = [
                ['host' => '127.0.0.1', 'port' => 11211, 'weight' => 1, 'persistent' => true, 'timeout' => 1, 'retry_interval' => 15, 'status' => true],
            ];
        } else {
            $servers = $this->config['servers'];
        }
        foreach ($servers as $server) {
            $host = isset($server['host']) ? $server['host'] : '127.0.0.1';
            $port = isset($server['port']) ? $server['port'] : 11211;
            $persistent = isset($server['persistent']) ? $server['persistent'] : null;
            $weight = isset($server['weight']) ? $server['weight'] : null;
            $timeout = isset($server['timeout']) ? $server['timeout'] : null;
            $retry_interval = isset($server['retry_interval']) ? $server['retry_interval'] : null;
            $status = isset($server['status']) ? $server['status'] : null;
            $failure_callback = isset($server['failure_callback']) ? $server['failure_callback'] : null;
            $this->handler->addserver($host, $port, $persistent, $weight, $timeout, $retry_interval, $status, $failure_callback);
        }
        if (!empty($this->config['compress'])) {
            $threshold = isset($this->config['compress']['threshold']) ? $this->config['compress']['threshold'] : 2000;
            $min_saving = isset($this->config['compress']['min_saving']) ? $this->config['compress']['min_saving'] : 0.2;
            $this->handler->setcompressthreshold($threshold, $min_saving);
            $this->compress = MEMCACHE_COMPRESSED;
        }
        //如果获取服务器池的统计信息返回false,说明服务器池中有不可用服务器
        if ($this->handler->getStats() === false) {
            $this->isConnected = false;
        } else {
            $this->isConnected = true;
        }
    }

    /**
     * 检查连接状态
     * @return boolean
     */
    private function checkConnection() {
        if (!$this->isConnected && !$this->fallback) {
            if ($this->handler->getStats() !== false) {
                $this->isConnected = true;
            } else {
                $this->handler->initServers();
            }
            if (!$this->isConnected) {
                $this->fallback = true;
            }
        }
        return $this->isConnected;
    }

    /**
     * 获取handler(Memcached实例)
     * @return \Memcached
     */
    public function getHandler() {
        return $this->handler;
    }

    /**
     * 根据键值获取压缩参数
     * @param mixed $value
     * @return int
     */
    private function compress($value) {
        if ($this->compress) {
            //如果是数字,则不压缩
            if (is_numeric($value)) {
                return 0;
            }
        }
        return $this->compress;
    }

    /**
     * 设置键值
     * @param string $key   键名
     * @param mixed $value  键值
     * @param int $time     过期时间,默认为-1,不设置过期时间;为0则设置为永不过期
     * @return boolean      是否成功
     */
    public function set($key, $value, $time = -1) {
        if ($this->checkConnection()) {
            $value = self::setValue($value);
            try {
                if ($time > 0) {
                    if ($this->handler->set($key, $value, $this->compress($value), time() + $time * 2)) {
                        $this->handler->set(self::timeKey($key), $time + time(), 0, time() + $time * 2);
                        return true;
                    }
                    return false;
                }
                //如果存在timeKey且已过期，则删除timeKey
                $expireTime = $this->handler->get(self::timeKey($key));
                if (($expireTime && $expireTime - time() <= 0) || $time == 0) {
                    $this->handler->delete(self::timeKey($key));
                }
                return $this->handler->set($key, $value, $this->compress($value));
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->set($key, $value, $time);
            }
        } else {
            return self::backup()->set($key, $value, $time);
        }
    }

    /**
     * 当键名不存在时设置键值
     * @param string $key   键名
     * @param mixed $value  键值
     * @param int $time     过期时间,默认为-1,不设置过期时间;为0则设置为永不过期
     * @return boolean      是否成功
     */
    public function setnx($key, $value, $time = -1) {
        if ($this->checkConnection()) {
            $value = self::setValue($value);
            try {
                if ($time > 0) {
                    if ($this->handler->add($key, $value, $this->compress($value), time() + $time * 2)) {
                        $ret = $this->handler->set(self::timeKey($key), $time + time(), 0, time() + $time * 2);
                        //如果执行失败，则尝试删除key
                        if ($ret === false) {
                            $this->handler->delete($key);
                        }
                        return $ret !== false ? true : false;
                    }
                    return false;
                }
                return $this->handler->add($key, $value, $this->compress($value));
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->setnx($key, $value, $time);
            }
        } else {
            return self::backup()->setnx($key, $value, $time);
        }
    }

    /**
     * 获取键值
     * @param string $key   键名
     * @return mixed        键值
     */
    public function get($key) {
        if ($this->checkConnection()) {
            try {
                $expireTime = $this->handler->get(self::timeKey($key));
                //如果过期，则返回false
                if ($expireTime && $expireTime - time() <= 0) {
                    return false;
                }
                $value = $this->handler->get($key);
                return $this->getValue($value);
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->get($key);
            }
        } else {
            return self::backup()->get($key);
        }
    }

    /**
     * 二次获取键值,在get方法没有获取到值时，调用此方法将有可能获取到
     * 此方法是为了防止惊群现象发生,配合lock和isLock方法,设置新的缓存
     * @param string $key   键名
     * @return mixed        键值
     */
    public function getTwice($key) {
        if ($this->checkConnection()) {
            try {
                $value = $this->handler->get($key);
                return $this->getValue($value);
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->getTwice($key);
            }
        } else {
            return self::backup()->getTwice($key);
        }
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
        if ($this->checkConnection()) {
            try {
                return $this->handler->set(self::lockKey($key), 1, 0, time() + $time);
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->lock($key, $time);
            }
        } else {
            return self::backup()->lock($key, $time);
        }
    }

    /**
     * 对指定键名解锁
     * 此方法可用于防止惊群现象发生,在get方法获取键值无效时,判断键名是否加锁
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function isLock($key) {
        if ($this->checkConnection()) {
            try {
                return (boolean) $this->handler->get(self::lockKey($key));
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->isLock($key);
            }
        } else {
            return self::backup()->isLock($key);
        }
    }

    /**
     * 删除键值
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function del($key) {
        if ($this->checkConnection()) {
            try {
                $this->handler->delete(self::timeKey($key));
                return $this->handler->delete($key);
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->del($key);
            }
        } else {
            return self::backup()->del($key);
        }
    }

    /**
     * 是否存在键值
     * @param string $key   键名
     * @return boolean      是否存在
     */
    public function has($key) {
        if ($this->checkConnection()) {
            try {
                $value = $this->handler->get($key);
                if ($value === false) {
                    return false;
                }
                return true;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->has($key);
            }
        } else {
            return self::backup()->has($key);
        }
    }

    /**
     * 获取生存剩余时间
     * @param string $key   键名
     * @return int          生存剩余时间(单位:秒) -1表示永不过期,-2表示键值不存在
     */
    public function ttl($key) {
        if ($this->checkConnection()) {
            try {
                $expireTime = $this->handler->get(self::timeKey($key));
                if ($expireTime) {
                    return $expireTime > time() ? $expireTime - time() : -2;
                }
                $value = $this->handler->get($key);
                if ($value === false) {
                    return -2;
                } else {
                    return -1;
                }
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->ttl($key);
            }
        } else {
            return self::backup()->ttl($key);
        }
    }

    /**
     * 设置过期时间
     * @param string $key   键名
     * @param int $time     过期时间(单位:秒)。不大于0，则设为永不过期
     * @return boolean      是否成功
     */
    public function expire($key, $time) {
        if ($this->checkConnection()) {
            try {
                $value = $this->handler->get($key);
                //值不存在,直接返回 false
                if ($value === false) {
                    return false;
                }
                //设为永不过期
                if ($time <= 0) {
                    if ($this->handler->set($key, $value, $this->compress($value))) {
                        $expire = $this->handler->get(self::timeKey($key));
                        if ($expire) {
                            return $this->handler->delete(self::timeKey($key));
                        }
                        return true;
                    }
                    return false;
                }
                //设置新的过期时间
                if ($this->handler->set($key, $value, $this->compress($value), time() + $time * 2)) {
                    $this->handler->set(self::timeKey($key), $time + time(), 0, time() + $time * 2);
                    return true;
                }
                return false;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->expire($key, $time);
            }
        } else {
            return self::backup()->expire($key, $time);
        }
    }

    /**
     * 移除指定键值的过期时间
     * @param string $key   键名
     * @return boolean      是否成功
     */
    public function persist($key) {
        if ($this->checkConnection()) {
            try {
                $value = $this->handler->get($key);
                if ($value === false) {
                    return false;
                }
                if ($this->handler->set($key, $value)) {
                    $expire = $this->handler->get(self::timeKey($key));
                    if ($expire) {
                        return $this->handler->delete(self::timeKey($key));
                    }
                    return true;
                }
                return false;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->has($key);
            }
        } else {
            return self::backup()->has($key);
        }
    }

    /**
     * 递增
     * @param string $key   键名
     * @param int $step     递增步长
     * @return boolean      是否成功
     */
    public function incr($key, $step = 1) {
        if ($this->checkConnection()) {
            if (!is_int($step)) {
                return false;
            }
            try {
                $ret = $this->handler->increment($key, $step);
                //如果key不存在
                if (!$ret && !$this->has($key)) {
                    return $this->handler->set($key, $step, 0);
                }
                return $ret;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->incr($key, $step);
            }
        } else {
            return self::backup()->incr($key, $step);
        }
    }

    /**
     * 浮点数递增
     * @param string $key   键名
     * @param float $float  递增步长
     * @return boolean      是否成功
     */
    public function incrByFloat($key, $float) {
        if ($this->checkConnection()) {
            if (!is_numeric($float)) {
                return false;
            }
            try {
                $value = $this->handler->get($key);
                if (!is_numeric($value) || !is_numeric($float)) {
                    return false;
                }
                $expire = $this->handler->get(self::timeKey($key));
                if ($expire > 0) {
                    return $this->handler->set($key, $value + $float, 0, $expire);
                }
                return $this->handler->set($key, $value + $float, 0);
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->incrByFloat($key, $float);
            }
        } else {
            return self::backup()->incrByFloat($key, $float);
        }
    }

    /**
     * 递减
     * @param string $key   键名
     * @param int $step     递减步长
     * @return boolean      是否成功
     */
    public function decr($key, $step = 1) {
        if ($this->checkConnection()) {
            if (!is_int($step)) {
                return false;
            }
            try {
                $ret = $this->handler->decrement($key, $step);
                //如果key不存在
                if (!$ret && !$this->has($key)) {
                    return $this->handler->set($key, -$step, 0);
                }
                return $ret;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->decr($key, $step);
            }
        } else {
            return self::backup()->decr($key, $step);
        }
    }

    /**
     * 批量设置键值
     * @param array $sets   键值数组
     * @return boolean      是否成功
     */
    public function mSet($sets) {
        if ($this->checkConnection()) {
            try {
                $oldSets = [];
                $status = true;
                foreach ($sets as $key => $value) {
                    $oldSets[$key] = $this->handler->get($key);
                    $status = $this->handler->set($key, $value, $this->compress($value));
                    if (!$status) {
                        break;
                    }
                }
                //如果失败，尝试回滚，但不保证成功
                if (!$status) {
                    foreach ($oldSets as $key => $value) {
                        if ($value === false) {
                            $this->handler->delete($key);
                        } else {
                            $this->handler->set($key, $value, $this->compress($value));
                        }
                    }
                }
                return $status;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->mSet($sets);
            }
        } else {
            return self::backup()->mSet($sets);
        }
    }

    /**
     * 批量设置键值(当键名不存在时)
     * 只有当键值全部设置成功时,才返回true,否则返回false并尝试回滚
     * @param array $sets   键值数组
     * @return boolean      是否成功
     */
    public function mSetNX($sets) {
        if ($this->checkConnection()) {
            try {
                $keys = [];
                $status = true;
                foreach ($sets as $key => $value) {
                    $status = $this->handler->add($key, $value, $this->compress($value));
                    if ($status) {
                        $keys[] = $key;
                    } else {
                        break;
                    }
                }
                //如果失败，尝试回滚，但不保证成功
                if (!$status) {
                    foreach ($keys as $key) {
                        $this->handler->delete($key);
                    }
                }
                return $status;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->mSetNX($sets);
            }
        } else {
            return self::backup()->mSetNX($sets);
        }
    }

    /**
     * 批量获取键值
     * @param array $keys   键名数组
     * @return array        键值数组
     */
    public function mGet($keys) {
        if ($this->checkConnection()) {
            try {
                $ret = [];
                $values = $this->handler->get($keys);
                foreach ($keys as $key) {
                    $ret[$key] = isset($values[$key]) ? $values[$key] : false;
                }
                return $ret;
            } catch (Exception $ex) {
                self::exception($ex);
                //连接状态置为false
                $this->isConnected = false;
                return self::backup()->mGet($keys);
            }
        } else {
            return self::backup()->mGet($keys);
        }
    }

}
