<?php
/**
 * Redis compatibility wrapper for Termux/Android
 * Эмулирует класс Redis через Predis с автопереподключением
 */
if (!class_exists('Redis') && class_exists('Predis\Client')) {
    class Redis {
        private $client = null;
        private $host = '127.0.0.1';
        private $port = 6379;
        private $timeout = 2.5;

        public function pconnect($host, $port = 6379, $timeout = 2.5) {
            $this->host = $host;
            $this->port = $port;
            $this->timeout = $timeout;
            return $this->doConnect();
        }

        public function connect($host, $port = 6379, $timeout = 2.5) {
            return $this->pconnect($host, $port, $timeout);
        }

        private function doConnect() {
            try {
                $this->client = new Predis\Client([
                    'scheme'  => 'tcp',
                    'host'    => $this->host,
                    'port'    => $this->port,
                    'timeout' => $this->timeout,
                ]);
                $this->client->ping();
                return true;
            } catch (Throwable $e) {
                $this->client = null;
                return false;
            }
        }

        private function execute($method, ...$args) {
            try {
                if ($this->client === null) $this->doConnect();
                return $this->client->$method(...$args);
            } catch (Throwable $e) {
                try {
                    $this->doConnect();
                    return $this->client->$method(...$args);
                } catch (Throwable $e2) {
                    return false;
                }
            }
        }

        public function auth($auth) {
            try {
                $this->client->auth(is_array($auth) ? $auth[1] : $auth);
                return true;
            } catch (Throwable $e) { return false; }
        }

        public function select($db) {
            try {
                $this->client->select($db);
                return true;
            } catch (Throwable $e) { return false; }
        }

        // Строковые операции
        public function get($key)           { return $this->execute('get', $key); }
        public function set($key, $value)   { return $this->execute('set', $key, $value); }
        public function del($key)           { return $this->execute('del', $key); }
        public function exists($key)        { return $this->execute('exists', $key); }
        public function keys($pattern)      { return $this->execute('keys', $pattern) ?: []; }
        public function flushDB()           { return $this->execute('flushdb'); }
        public function ping()              { return $this->execute('ping'); }
        public function expire($key, $ttl) { return $this->execute('expire', $key, $ttl); }
        public function ttl($key)           { return $this->execute('ttl', $key); }

        // Списочные операции (для очереди mjd:queue:*)
        public function lLen($key)              { $r = $this->execute('llen', $key); return $r === false ? 0 : (int)$r; }
        public function lPop($key)              { return $this->execute('lpop', $key); }
        public function rPush($key, $value)     { return $this->execute('rpush', $key, $value); }
    }
}
