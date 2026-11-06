<?php
/**
 * proxy.php — 极简 m3u 抓取代理（供 PHP 静态主机，如 Hostinger）
 * 用法:  proxy.php?url=<URL编码的目标m3u链接>
 *        proxy.php?head=1&url=<URL编码的流地址>   （HEAD 探测：只判断可达性，不下载正文）
 * 部署:  把本文件与 M3UEditor.html 放在同一目录即可，无需任何配置。
 * 作用:  在服务器端直连目标（服务端无 CORS 限制），再把结果返回给浏览器。
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$url = isset($_GET['url']) ? trim((string)$_GET['url']) : '';
if ($url === '') {
    http_response_code(400);
    echo 'missing url';
    exit;
}
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo 'only http(s) allowed';
    exit;
}

$isHead = isset($_GET['head']) && $_GET['head'] === '1';   // HEAD 探测模式
const MAX_BYTES = 25 * 1024 * 1024;
$ua = 'Mozilla/5.0 (compatible; m3u-proxy/1.0)';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $isHead ? 20 : 60,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ];
    if ($isHead) {
        $opts[CURLOPT_NOBODY] = true;   // HEAD：不下载正文
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($isHead) {
        // 2xx/3xx 都视为可达；把上游状态码原样透传给前端判断
        http_response_code($code >= 200 && $code < 400 ? 200 : ($code >= 400 ? $code : 502));
        header('X-Upstream-Code: ' . $code);
        exit;
    }
    if ($body === false || $code >= 400) {
        http_response_code($code >= 400 ? $code : 502);
        echo $body === false ? ('proxy upstream error: ' . $err) : ('upstream http ' . $code);
        exit;
    }
} elseif (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(['http' => [
        'timeout' => 60,
        'ignore_errors' => true,
        'follow_location' => 1,
        'header' => "User-Agent: $ua\r\nAccept: */*\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        http_response_code(502);
        echo 'proxy upstream error';
        exit;
    }
    foreach ($http_response_header as $h) {
        if (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
        }
    }
} else {
    http_response_code(500);
    echo 'server has neither curl nor allow_url_fopen';
    exit;
}

if (strlen($body) > MAX_BYTES) {
    http_response_code(413);
    echo 'payload too large';
    exit;
}

header('Content-Type: ' . ($ct !== '' ? $ct : 'audio/x-mpegurl'));
header('Content-Length: ' . strlen($body));
echo $body;
