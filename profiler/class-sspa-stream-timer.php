<?php
defined('ABSPATH') || exit;

/** Explicit diagnostic replacement: PHP cannot preserve caller-scoped response headers. */
class SSPA_Stream_Timer {
    public $context;
    private $resource;
    private $index;
    private static $timer;
    private static $calls = array();
    private static $enabled = false;

    public static function install($timer) {
        self::$timer = $timer;
        foreach (array('http', 'https') as $scheme) {
            if (!stream_wrapper_unregister($scheme) || !stream_wrapper_register($scheme, __CLASS__)) {
                throw new RuntimeException('Could not install the explicitly requested ' . $scheme . ' stream diagnostic.');
            }
        }
        self::$enabled = true;
    }

    public static function report() {
        return array('enabled' => self::$enabled, 'header_scope_changed' => self::$enabled,
            'calls' => self::$calls, 'total_ms' => round(array_sum(array_column(self::$calls, 'ms')), 2));
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        $source = array('file' => '', 'line' => 0);
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            if (!empty($frame['file']) && $frame['file'] !== __FILE__) { $source = $frame; break; }
        }
        // Keep URL values, credentials and fragments out of the diagnostic evidence.
        $url = parse_url($path);
        $safe_url = ($url['scheme'] ?? 'http') . '://' . ($url['host'] ?? '') . (isset($url['port']) ? ':' . $url['port'] : '') . ($url['path'] ?? '/');
        $this->index = count(self::$calls);
        self::$calls[] = array('url' => $safe_url, 'file' => $source['file'], 'line' => (int) $source['line'], 'ms' => 0, 'bytes' => 0, 'opened' => false);
        $scheme = $url['scheme'];
        $this->measure(function () use ($path, $mode, $options, $scheme) {
            if (!stream_wrapper_restore($scheme)) throw new RuntimeException('Could not restore native stream transport.');
            try {
                $this->resource = is_resource($this->context)
                    ? fopen($path, $mode, (bool) ($options & STREAM_USE_PATH), $this->context)
                    : fopen($path, $mode, (bool) ($options & STREAM_USE_PATH));
            } finally {
                if (!stream_wrapper_unregister($scheme) || !stream_wrapper_register($scheme, __CLASS__)) throw new RuntimeException('Could not resume requested stream diagnostic.');
            }
        });
        self::$calls[$this->index]['opened'] = is_resource($this->resource);
        return is_resource($this->resource);
    }

    private function measure($operation) {
        $start = microtime(true);
        try { return $operation(); }
        finally {
            $end = microtime(true);
            self::$calls[$this->index]['ms'] += ($end - $start) * 1000;
            if (self::$timer) self::$timer->stream_interval($start, $end, self::$calls[$this->index]);
        }
    }

    public function stream_read($count) {
        $data = $this->measure(function () use ($count) { return fread($this->resource, $count); });
        if (false !== $data) self::$calls[$this->index]['bytes'] += strlen($data);
        return $data;
    }
    public function stream_eof() { return feof($this->resource); }
    public function stream_stat() { return fstat($this->resource); }
    public function stream_tell() { return ftell($this->resource); }
    public function stream_seek($offset, $whence) { return 0 === fseek($this->resource, $offset, $whence); }
    public function stream_flush() { return fflush($this->resource); }
    public function stream_cast($cast_as) { return $this->resource; }
    public function stream_write($data) { return $this->measure(function () use ($data) { return fwrite($this->resource, $data); }); }
    public function stream_close() { $this->measure(function () { fclose($this->resource); }); }
    public function stream_set_option($option, $arg1, $arg2) {
        if (STREAM_OPTION_BLOCKING === $option) return stream_set_blocking($this->resource, $arg1);
        if (STREAM_OPTION_READ_TIMEOUT === $option) return stream_set_timeout($this->resource, $arg1, $arg2);
        if (STREAM_OPTION_WRITE_BUFFER === $option) return 0 === stream_set_write_buffer($this->resource, $arg2);
        return false;
    }
    public function url_stat($path, $flags) {
        $scheme = parse_url($path, PHP_URL_SCHEME);
        if (!stream_wrapper_restore($scheme)) throw new RuntimeException('Could not restore native stream stat.');
        try { return ($flags & STREAM_URL_STAT_LINK) ? lstat($path) : stat($path); }
        finally {
            if (!stream_wrapper_unregister($scheme) || !stream_wrapper_register($scheme, __CLASS__)) throw new RuntimeException('Could not resume stream diagnostic after stat.');
        }
    }
}
