<?php
/**
 * proxy.php — 极简 m3u 抓取代理（供 PHP 静态主机，如 Hostinger）
 * 用法:  proxy.php?url=<URL编码的目标m3u链接>
 * 部署:  把本文件与 M3UEditor.html 放在同一目录即可，无需任何配置。
 * 作用:  在服务器端直连目标 m3u（服务端无 CORS 限制），再把内容返回给浏览器，
 *        从而让纯静态 HTML 也能加载任意跨域 m3u 链接。
 */

// 允许跨域（同源部署本不需要，保留以便从其他页面调用）
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
// 仅允许 http/https，防止 SSRF 滥用
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo 'only http(s) allowed';
    exit;
}

const MAX_BYTES = 25 * 1024 * 1024;   // 25 MB 上限，防止拉取超大文件
$ua = 'Mozilla/5.0 (compatible; m3u-proxy/1.0)';

$body = false;
$code = 0;
$ct = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
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
