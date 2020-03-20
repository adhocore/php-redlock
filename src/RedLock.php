<?php

namespace RedLock;

use Redis;

/**
 * Forked from https://github.com/ronnylt/redlock-php
 */
class RedLock
{
    protected $retryDelay;
    protected $retryCount;
    protected $clockDriftFactor = 0.01;

    protected $quorum;

    protected $servers   = [];
    protected $instances = [];

    public function __construct(array $servers, int $retryDelay = 200, int $retryCount = 3)
    {
        $this->servers = $servers;

        $this->retryDelay = $retryDelay;
        $this->retryCount = $retryCount;

        $this->quorum = \min(\count($servers), (\count($servers) / 2 + 1));
    }

    public function lock(string $resource, int $ttl)
    {
        $this->initInstances();

        $token = \uniqid();
        $retry = $this->retryCount;

        do {
            $n = 0;

            $startTime = \microtime(true) * 1000;

            foreach ($this->instances as $instance) {
                if ($this->lockInstance($instance, $resource, $token, $ttl)) {
                    $n++;
                }
            }

            # Add 2 milliseconds to the drift to account for Redis expires
            # precision, which is 1 millisecond, plus 1 millisecond min drift
            # for small TTLs.
            $drift = ($ttl * $this->clockDriftFactor) + 2;

            $validityTime = $ttl - (\microtime(true) * 1000 - $startTime) - $drift;

            if ($n >= $this->quorum && $validityTime > 0) {
                return [
                    'validity' => $validityTime,
                    'resource' => $resource,
                    'token'    => $token,
                ];

            } else {
                foreach ($this->instances as $instance) {
                    $this->unlockInstance($instance, $resource, $token);
                }
            }

            // Wait a random delay before to retry
            $delay = \mt_rand(\floor($this->retryDelay / 2), $this->retryDelay);
            \usleep($delay * 1000);

            $retry--;

        } while ($retry > 0);

        return false;
    }

    public function unlock(array $lock)
    {
        $this->initInstances();

        $resource = $lock['resource'];
        $token    = $lock['token'];

        foreach ($this->instances as $instance) {
            $this->unlockInstance($instance, $resource, $token);
        }
    }

    protected function initInstances()
    {
        if (!empty($this->instances)) {
            return;
        }

        foreach ($this->servers as $server) {
            list($host, $port, $timeout) = $server;

            $redis = new Redis;
            $redis->connect($host, $port, $timeout);
            if (isset($server[3])) {
                $redis->select((int) $server[3]);
            }

            $this->instances[] = $redis;
        }
    }

    protected function lockInstance(Redis $instance, string $resource, string $token, int $ttl)
    {
        return $instance->set($resource, $token, ['NX', 'PX' => $ttl]);
    }

    protected function unlockInstance(Redis $instance, string $resource, string $token)
    {
        $script = '
            if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else
                return 0
            end
        ';

        return $instance->eval($script, [$resource, $token], 1);
    }
}
