<?php
/**
 * CLOUD DATABASE BRIDGE FOR SUPABASE (POSTGRESQL REST API)
 * Lưu trữ vĩnh viễn dữ liệu (JSON) trên Supabase, chống mất dữ liệu khi Render tắt/ngủ.
 */

class CloudDB {
    private static $url = null;
    private static $key = null;
    private static $bootstrapped = false;

    /**
     * Khởi tạo và đọc cấu hình từ biến môi trường hoặc file config
     */
    public static function init() {
        if (self::$url !== null) return;

        // 1. Thử lấy từ Biến môi trường hệ thống (Render, Railway, Docker)
        $url = getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? ($_SERVER['SUPABASE_URL'] ?? ''));
        $key = getenv('SUPABASE_KEY') ?: ($_ENV['SUPABASE_KEY'] ?? ($_SERVER['SUPABASE_KEY'] ?? ''));

        // 2. Thử lấy từ file cấu hình nội bộ nếu có
        $configFile = __DIR__ . DIRECTORY_SEPARATOR . 'supabase_config.php';
        if (file_exists($configFile)) {
            $conf = include $configFile;
            if (is_array($conf)) {
                if (empty($url) && !empty($conf['url'])) $url = $conf['url'];
                if (empty($key) && !empty($conf['key'])) $key = $conf['key'];
            }
        }

        self::$url = rtrim(trim($url), '/');
        self::$key = trim($key);
    }

    /**
     * Kiểm tra xem cơ sở dữ liệu Supabase đã được cấu hình hay chưa
     */
    public static function isEnabled() {
        self::init();
        return !empty(self::$url) && !empty(self::$key);
    }

    /**
     * Gửi HTTP Request tới Supabase REST API bằng cURL
     */
    private static function request($endpoint, $method = 'GET', $body = null, $extraHeaders = []) {
        if (!self::isEnabled()) return false;

        $url = self::$url . '/rest/v1/' . ltrim($endpoint, '/');
        $ch = curl_init();

        $headers = array_merge([
            'apikey: ' . self::$key,
            'Authorization: Bearer ' . self::$key,
            'Content-Type: application/json',
            'Accept: application/json'
        ], $extraHeaders);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if ($body !== null) {
            $jsonBody = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        }

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return json_decode($res, true);
        }
        return false;
    }

    /**
     * Lấy key lưu trữ từ đường dẫn tệp (VD: /var/www/html/exams_data.json -> exams_data)
     */
    public static function pathToKey($path) {
        $filename = basename($path);
        if (preg_match('/^([a-zA-Z0-9_\-]+)\.json$/i', $filename, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Đọc dữ liệu từ Supabase theo key
     */
    public static function get($key) {
        if (!self::isEnabled()) return null;
        $res = self::request('app_storage?key=eq.' . urlencode($key) . '&select=value');
        if (is_array($res) && count($res) > 0 && isset($res[0]['value'])) {
            return $res[0]['value'];
        }
        return null;
    }

    /**
     * Lưu/Cập nhật dữ liệu lên Supabase (Upsert)
     */
    public static function set($key, $content) {
        if (!self::isEnabled()) return false;

        $parsed = is_string($content) ? json_decode($content, true) : $content;
        $valueToSave = ($parsed !== null) ? $parsed : $content;

        $payload = [
            [
                'key' => $key,
                'value' => $valueToSave,
                'updated_at' => date('c')
            ]
        ];

        $res = self::request(
            'app_storage', 
            'POST', 
            $payload, 
            ['Prefer: resolution=merge-duplicates']
        );

        return ($res !== false);
    }

    /**
     * Khởi động & Đồng bộ dữ liệu 2 chiều giữa Supabase và Container
     * - Khi Render mới khởi động: Tải toàn bộ dữ liệu từ Supabase về đĩa để website hoạt động mượt mà.
     * - Khi Supabase chưa có dữ liệu: Đẩy toàn bộ dữ liệu có sẵn từ máy chủ/local lên Supabase.
     */
    public static function bootstrap() {
        if (self::$bootstrapped || !self::isEnabled()) return;
        self::$bootstrapped = true;

        $baseDir = __DIR__;
        $dataFiles = glob($baseDir . DIRECTORY_SEPARATOR . '*.json');
        
        // 1. Tải tất cả các bản ghi từ Supabase
        $cloudRows = self::request('app_storage?select=key,value,updated_at');

        if (is_array($cloudRows) && count($cloudRows) > 0) {
            // Đã có dữ liệu trên đám mây -> Đồng bộ từ Supabase về local disk nếu file local rỗng hoặc cũ hơn
            $cloudKeys = [];
            foreach ($cloudRows as $row) {
                $k = $row['key'] ?? '';
                if (empty($k)) continue;
                $cloudKeys[$k] = true;
                $targetFile = $baseDir . DIRECTORY_SEPARATOR . $k . '.json';
                $localSize = file_exists($targetFile) ? filesize($targetFile) : 0;

                // Nếu file local chưa tồn tại hoặc rỗng (Render mới tạo container), ghi dữ liệu đám mây vào
                if (!file_exists($targetFile) || $localSize <= 3) {
                    $val = $row['value'];
                    $jsonStr = is_string($val) ? $val : json_encode($val, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    @file_put_contents($targetFile, $jsonStr);
                    clearstatcache(true, $targetFile);
                }
            }

            // Đồng bộ ngược lại các file local có dữ liệu lớn (như exams_data 1.1MB) nếu Supabase chưa có
            foreach ($dataFiles as $df) {
                $k = self::pathToKey($df);
                if (!$k || isset($cloudKeys[$k]) || basename($df) === 'vercel.json') continue;
                if (filesize($df) > 3) {
                    $raw = @file_get_contents($df);
                    if ($raw) self::set($k, $raw);
                }
            }
        } else if ($cloudRows !== false && count($cloudRows) === 0) {
            // Supabase đang trống hoàn toàn (lần chạy đầu tiên) -> Đẩy toàn bộ dữ liệu hiện tại lên Supabase
            foreach ($dataFiles as $df) {
                $k = self::pathToKey($df);
                if (!$k || basename($df) === 'vercel.json') continue;
                if (filesize($df) > 3) {
                    $raw = @file_get_contents($df);
                    if ($raw) self::set($k, $raw);
                }
            }
        }
    }
}

// Tự động kiểm tra và khởi động đồng bộ nếu có cấu hình
if (CloudDB::isEnabled()) {
    CloudDB::bootstrap();
}
