<?php
/**
 * Redis compatibility wrapper for Termux/Android
 * Эмулирует класс Redis через Predis (чистый PHP, без расширения php-redis)
 * php-redis в Termux несовместим с текущей версией PHP API
 *
 * Установка Predis: composer require predis/predis
 * Репозиторий: https://github.com/YOUR_USER/majordomo-android
 */
if (!class_exists('Redis') && class_exists('Predis\Client')) {
    class Redis {
        private $client = null;

        public function pconnect($host, $port = 6379, $timeout = 2.5) {
            try {
                $this->client = new Predis\Client([
                    'scheme'  => 'tcp',
                    'host'    => $host,
                    'port'    => $port,
                    'timeout' => $timeout,
                ]);
                $this->client->ping();
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        public function connect($host, $port = 6379, $timeout = 2.5) {
            return $this->pconnect($host, $port, $timeout);
        }

        public function auth($auth) {
            try {
                $this->client->auth(is_array($auth) ? $auth[1] : $auth);
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        public function select($db) {
            try {
                $this->client->select($db);
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        public function get($key)           { return $this->client->get($key); }
        public function set($key, $value)   { return $this->client->set($key, $value); }
        public function del($key)           { return $this->client->del($key); }
        public function exists($key)        { return $this->client->exists($key); }
        public function keys($pattern)      { return $this->client->keys($pattern); }
        public function flushDB()           { return $this->client->flushdb(); }
        public function ping()              { return $this->client->ping(); }
        public function expire($key, $ttl) { return $this->client->expire($key, $ttl); }
        public function ttl($key)           { return $this->client->ttl($key); }
    }
}
