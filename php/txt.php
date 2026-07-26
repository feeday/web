<?php
/**
 * ==========================================
 * 🛠️ 赛博风 Web 端引擎 (PHP) - 纯净多模态 AI 工具箱
 * ==========================================
 */

// 基础运行环境优化配置
ini_set('memory_limit', '256M');
ini_set('post_max_size', '100M');
ini_set('upload_max_filesize', '100M');
date_default_timezone_set('Asia/Shanghai');

// 🔒 核心安全区 - 后端基础密钥配置 (支持通过环境变量或在此直接修改)
$config = [
    'poixeApiKey'        => getenv('POIXE_API_KEY') ?: 'sk-',
    'apimartApiKey'      => getenv('APIMART_API_KEY') ?: 'x',
    'imgurClientId'      => getenv('IMGUR_CLIENT_ID') ?: '203da2f300125a1',
    'enforceWechatOnly'  => false,                       // 是否强制仅允许微信内打开
    'enforceAntihack'    => false,                       // 是否开启域名白名单防盗链防护
    'redirectUrl'        => 'https://tcq233.com/btv',
    'allowedDomains'     => [
        'feeday.cn'                                      // 允许被嵌套引用打开的域名
    ]
];

/**
 * 🛠️ 全局高兼容性工具函数组
 */
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

function sendJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function performCurl($url, $method, $headers, $body = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception($error_msg);
    }
    curl_close($ch);
    return ['status' => $status, 'body' => $response];
}

function resolvePublicRemoteHost($host) {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $host : false;
    }

    $addresses = gethostbynamel($host);
    if (!$addresses) return false;
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return $addresses[0];
}

function relayRemoteImage($url) {
    for ($redirects = 0; $redirects <= 4; $redirects++) {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $publicAddress = $host ? resolvePublicRemoteHost($host) : false;
        if (!in_array($scheme, ['http', 'https'], true) || !$publicAddress) {
            throw new RuntimeException('仅支持公网 HTTP/HTTPS 图像网址');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 QRImageReader/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Pin the validated public address so DNS rebinding cannot reach an internal service.
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$publicAddress}"],
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 20 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = strtolower(trim(explode(';', (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) throw new RuntimeException('远程图像不能超过 20 MB');
        if ($status >= 300 && $status < 400 && $redirectUrl) {
            $url = $redirectUrl;
            continue;
        }
        if ($curlError || $status < 200 || $status >= 300) {
            throw new RuntimeException('远程图像读取失败' . ($status ? "（HTTP {$status}）" : ''));
        }
        if (strpos($contentType, 'image/') !== 0) {
            throw new RuntimeException('TXT 中的网址不是可识别的图像');
        }

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: private, max-age=300');
        echo $body;
        exit;
    }
    throw new RuntimeException('远程图像重定向次数过多');
}

// 解析路由请求
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$apiParam = isset($_GET['api']) ? $_GET['api'] : null;
$isApiRequest = !empty($apiParam) || (strpos($requestUri, '/api/') !== false);

/**
 * 🛡️ 全局中间件：微信环境阻断与防盗链系统
 */
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
$checkUrl = !empty($referer) ? $referer : $origin;

if ($config['enforceWechatOnly'] && !$isApiRequest) {
    if (strpos($userAgent, 'micromessenger') === false) {
        header("Location: " . $config['redirectUrl']);
        exit;
    }
}

if ($config['enforceAntihack'] && !empty($checkUrl)) {
    $refererHost = $checkUrl;
    $parsedUrl = parse_url($checkUrl);
    if (isset($parsedUrl['host'])) {
        $refererHost = $parsedUrl['host'];
    } else {
        $refererHost = preg_replace('/^https?:\/\//i', '', $checkUrl);
        $refererHost = explode('/', $refererHost)[0];
    }

    $isAllowed = false;
    foreach ($config['allowedDomains'] as $domain) {
        $pattern = '/(^|\.)' . str_replace('.', '\.', $domain) . '$/i';
        if (preg_match($pattern, $refererHost)) {
            $isAllowed = true;
            break;
        }
    }

    $hostHeader = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $hostName = explode(':', $hostHeader)[0];
    $selfAllowed = (!empty($hostHeader) && $refererHost === $hostName);

    if (!$isAllowed && !selfAllowed) {
        if ($isApiRequest) {
            sendJson(['error' => ['message' => "防盗链拦截: 域名 {$refererHost} 未授权"]], 403);
        }
        header("Location: " . $config['redirectUrl']);
        exit;
    }
} elseif ($config['enforceAntihack'] && empty($checkUrl) && $isApiRequest) {
    sendJson(['error' => ['message' => '防盗链拦截: 缺少来源凭证']], 403);
}

/**
 * ⚡ API 转发及中台处理逻辑 (?api= 路由机制)
 */
if ($apiParam !== null) {
    $requestHeaders = getallheaders();

    if ($apiParam === 'qr_image') {
        try {
            $remoteUrl = trim((string) ($_GET['url'] ?? ''));
            if ($remoteUrl === '') throw new RuntimeException('请在 TXT 中输入图像网址');
            relayRemoteImage($remoteUrl);
        } catch (Throwable $error) {
            sendJson(['error' => ['message' => $error->getMessage()]], 400);
        }
    }

    if ($apiParam === 'share_image') {
        try {
            $file = isset($_FILES['image']) && is_array($_FILES['image']) ? $_FILES['image'] : null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('请选择需要分享的图像');
            }
            if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 50 * 1024 * 1024) {
                throw new RuntimeException('图像大小须在 50 MB 以内');
            }

            $mime = function_exists('mime_content_type') ? mime_content_type((string) $file['tmp_name']) : (string) ($file['type'] ?? '');
            if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw new RuntimeException('分享图像仅支持 PNG 和 JPG');
            }
            if (!function_exists('curl_init') || !class_exists('CURLFile')) {
                throw new RuntimeException('服务器未启用图像分享所需的 cURL 扩展');
            }

            $ch = curl_init('https://api.imgur.com/3/image');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_HTTPHEADER => ['Authorization: Client-ID ' . $config['imgurClientId']],
                CURLOPT_POSTFIELDS => [
                    'image' => new CURLFile((string) $file['tmp_name'], $mime, basename((string) ($file['name'] ?? 'shared-image'))),
                ],
            ]);
            $imgurResponse = curl_exec($ch);
            $imgurStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $imgurError = curl_error($ch);
            curl_close($ch);
            $imgurPayload = json_decode((string) $imgurResponse, true);
            if ($imgurStatus < 200 || $imgurStatus >= 300 || empty($imgurPayload['success']) || empty($imgurPayload['data']['link'])) {
                $reason = $imgurPayload['data']['error'] ?? ($imgurError ?: 'Imgur 接口暂时不可用');
                throw new RuntimeException('图像分享失败：' . (is_string($reason) ? $reason : '请求被拒绝'));
            }
            sendJson(['url' => (string) $imgurPayload['data']['link']]);
        } catch (Throwable $error) {
            sendJson(['error' => ['message' => $error->getMessage()]], 400);
        }
    }
    
    $userTextKey = isset($requestHeaders['X-User-Key']) ? $requestHeaders['X-User-Key'] : (isset($requestHeaders['x-user-key']) ? $requestHeaders['x-user-key'] : '');
    $userImageKey = isset($requestHeaders['X-Image-Key']) ? $requestHeaders['X-Image-Key'] : (isset($requestHeaders['x-image-key']) ? $requestHeaders['x-image-key'] : '');
    $userProxy = isset($requestHeaders['X-Proxy-Url']) ? $requestHeaders['X-Proxy-Url'] : (isset($requestHeaders['x-proxy-url']) ? $requestHeaders['x-proxy-url'] : '');
    
    $finalPoixeKey = $userTextKey ? $userTextKey : $config['poixeApiKey'];
    $finalApimartKey = $userImageKey ? $userImageKey : $config['apimartApiKey'];
    $cleanProxy = rtrim($userProxy, '/');
    
    $bodyRaw = file_get_contents('php://input');

    try {
        if ($apiParam === 'chat') {
            $body = json_decode($bodyRaw, true) ?: [];
            if (!isset($body['model'])) $body['model'] = 'deepseek-v4-flash';

            if ($body['model'] !== 'deepseek-v4-flash' && !$userTextKey) {
                sendJson(['error' => ['message' => '该模型需要提供专属的 API Key 授权验证。']], 401);
            }

            $fetchUrl = $cleanProxy ? "{$cleanProxy}/v1/chat/completions" : 'https://api.poixe.com/v1/chat/completions';
            $curlRes = performCurl($fetchUrl, 'POST', [
                'Content-Type: application/json',
                "Authorization: Bearer {$finalPoixeKey}"
            ], json_encode($body));
            
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($curlRes['status']);
            echo $curlRes['body'];
            exit;
        }

        if ($apiParam === 'image') {
            if (!$userTextKey && !$config['poixeApiKey']) {
                sendJson(['error' => ['message' => '该功能需要提供专属的 API Key 授权验证。']], 401);
            }
            $model = isset($_GET['model']) ? $_GET['model'] : 'gemini-2.5-flash-image';
            $fetchUrl = $cleanProxy ? "{$cleanProxy}/v1beta/models/{$model}:generateContent" : "https://api.poixe.com/v1beta/models/{$model}:generateContent";
            
            $curlRes = performCurl($fetchUrl, 'POST', [
                'Content-Type: application/json',
                "x-goog-api-key: {$finalPoixeKey}"
            ], $bodyRaw);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($curlRes['status']);
            echo $curlRes['body'];
            exit;
        }

        if ($apiParam === 'gpt_image_create') {
            if (!$userImageKey && !$config['apimartApiKey']) {
                sendJson(['error' => ['message' => '该功能需要提供专属的 APIMart Key 授权验证。']], 401);
            }
            $fetchUrl = $cleanProxy ? "{$cleanProxy}/v1/images/generations" : 'https://api.apimart.ai/v1/images/generations';
            
            $curlRes = performCurl($fetchUrl, 'POST', [
                'Content-Type: application/json',
                "Authorization: Bearer {$finalApimartKey}"
            ], $bodyRaw);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($curlRes['status']);
            echo $curlRes['body'];
            exit;
        }

        if ($apiParam === 'gpt_image_query') {
            $taskId = isset($_GET['task_id']) ? $_GET['task_id'] : '';
            if (!$taskId) sendJson(['error' => '缺少 task_id'], 400);
            
            $fetchUrl = $cleanProxy ? "{$cleanProxy}/v1/tasks/" . rawurlencode($taskId) : "https://api.apimart.ai/v1/tasks/" . rawurlencode($taskId);
            $curlRes = performCurl($fetchUrl, 'GET', [
                "Authorization: Bearer {$finalApimartKey}"
            ]);

            header('Content-Type: application/json; charset=utf-8');
            http_response_code($curlRes['status']);
            echo $curlRes['body'];
            exit;
        }

        sendJson(['error' => '未知的 API 路由'], 404);

    } catch (Exception $error) {
        sendJson(['error' => ['message' => "服务端内部网络异常: " . $error->getMessage()]], 500);
    }
}

/**
 * 🖥️ 前端 HTML 渲染引擎
 */
$wechatScript = '';
if ($config['enforceWechatOnly']) {
    $wechatScript = '
    <script>
        if (navigator.userAgent.toLowerCase().indexOf(\'micromessenger\') === -1) {
            window.location.replace("' . $config['redirectUrl'] . '");
        }
    </script>
    ';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<meta name="referrer" content="same-origin" />
<title>─=≡Σ((( つ•̀ω•́)つ</title>
<?php echo $wechatScript; ?>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/exifr/dist/full.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/lamejs@1.2.1/lame.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap" rel="stylesheet">

<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { 
    font-family: 'Share Tech Mono', -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif; 
    margin: 0; padding: 15px 10px; 
    background-color: #030303;
    background-image: 
        linear-gradient(rgba(0, 255, 65, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0, 255, 65, 0.03) 1px, transparent 1px);
    background-size: 40px 40px;
    color: #d4d4d4; 
    min-height: 100vh; 
}
.app-container { max-width: 600px; margin: 0 auto; padding-bottom: 20px; position: relative; z-index: 10; }

.card { 
    background: rgba(10, 10, 10, 0.8); 
    backdrop-filter: blur(10px);
    border-radius: 8px; 
    border: 1px solid #333;
    padding: 20px; 
    margin-bottom: 15px; 
    box-shadow: 0 0 20px rgba(0, 0, 0, 0.8); 
    position: relative;
}
.card::before { content: ''; position: absolute; top: -1px; left: -1px; width: 8px; height: 8px; border-top: 2px solid #00ff41; border-left: 2px solid #00ff41; border-top-left-radius: 4px; }
.card::after { content: ''; position: absolute; bottom: -1px; right: -1px; width: 8px; height: 8px; border-bottom: 2px solid #00ff41; border-right: 2px solid #00ff41; border-bottom-right-radius: 4px; }

.input-style { 
    width: 100%; padding: 12px 14px; margin-bottom: 12px; border-radius: 6px; 
    border: 1px solid #333; background: #000; font-size: 14px; outline: none; 
    transition: all 0.3s ease; color: #00f0ff; font-family: 'Share Tech Mono', monospace; 
}
.input-style:focus { border-color: #00f0ff; background: #050505; box-shadow: 0 0 10px rgba(0, 240, 255, 0.2); }
textarea.input-style { min-height: 90px; resize: vertical; line-height: 1.8; white-space: pre-wrap; overflow-x: hidden; }
textarea.input-style.is-empty { white-space: pre; overflow-x: auto; }
textarea.input-style.is-empty::placeholder { white-space: pre; }
textarea.input-style::placeholder { color: #555; font-family: 'Share Tech Mono', monospace; font-size: 12px; line-height: 1.4; }

.button-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(85px, 1fr)); gap: 10px; margin-bottom: 5px; }
.btn { 
    background: rgba(255, 255, 255, 0.05); color: #888; border: 1px solid #333; 
    padding: 10px 0; border-radius: 4px; font-size: 13px; font-weight: 500; 
    cursor: pointer; transition: 0.2s; text-align: center; display: inline-flex; 
    justify-content: center; align-items: center; user-select: none; font-family: 'Share Tech Mono', sans-serif; letter-spacing: 1px;
}
.btn:hover { background: rgba(255, 255, 255, 0.1); border-color: #555; color: #ccc; }
.btn:active { background: rgba(0, 240, 255, 0.1); transform: scale(0.98); border-color: #00f0ff; color: #00f0ff; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }

.btn-ai { border-color: #00ff41; color: #00ff41; background: rgba(0, 255, 65, 0.05); font-weight: bold; }
.btn-ai:hover, .btn-ai:active { background: rgba(0, 255, 65, 0.2); box-shadow: 0 0 10px rgba(0, 255, 65, 0.4); text-shadow: 0 0 5px #00ff41; }

.btn-tucao { border-color: #00f0ff; color: #00f0ff; background: rgba(0, 240, 255, 0.05); font-weight: bold; }
.btn-tucao:hover, .btn-tucao:active { background: rgba(0, 240, 255, 0.2); box-shadow: 0 0 10px rgba(0, 240, 255, 0.4); text-shadow: 0 0 5px #00f0ff; }

.btn-danger { border-color: #ff2a2a; color: #ff2a2a; background: rgba(255, 42, 42, 0.05); }
.btn-danger:hover, .btn-danger:active { background: rgba(255, 42, 42, 0.2); box-shadow: 0 0 10px rgba(255, 42, 42, 0.4); text-shadow: 0 0 5px #ff2a2a; }

#response { 
    white-space: pre-wrap; word-break: break-all; font-family: 'Share Tech Mono', ui-monospace, monospace; 
    background: #050505; color: #00ff41; border: 1px solid #00ff41; border-radius: 6px; 
    padding: 12px; font-size: 12px; max-height: 400px; overflow-y: auto; margin-top: 10px; 
    text-align: left; text-shadow: 0 0 2px rgba(0, 255, 65, 0.5);
}
#responseWrap { display: none; margin-top: 15px; }

#stats { 
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 5px; font-size: 11px; 
    color: #666; margin: 10px 0; padding: 10px 5px; background: #000; border-radius: 6px; 
    border: 1px dashed #333; text-align: center; 
}

.ping-container { padding: 15px; background: #0a0a0a; border: 1px dashed #333; border-radius: 8px; margin-top: 15px; }
.ping-header { font-size: 13px; font-weight: 600; color: #d4d4d4; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
.ping-box { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: #000; border: 1px solid #333; border-radius: 6px; font-size: 14px; cursor: pointer; transition: background 0.2s; color: #d4d4d4; }
.ping-box:active { background: #111; border-color: #00f0ff; }
.ping-site { font-weight: bold; color: #00f0ff; }
.ping-ms { color: #00ff41; font-family: 'Share Tech Mono', monospace; font-weight: bold; text-shadow: 0 0 5px rgba(0, 255, 65, 0.5); }
.ping-desc { font-size: 11px; color: #888; margin-top: 12px; }
.ping-desc span span { color: #d4d4d4 !important; }
#performance-result { background: #000 !important; border: 1px solid #333; color: #00ff41 !important; text-shadow: 0 0 3px rgba(0, 255, 65, 0.4); }

.ping-chart-wrap { width: 100%; height: 125px; margin-top: 15px; position: relative; background: #050505; border: 1px solid #333; border-radius: 6px; padding: 10px 0; }
canvas#chart { width: 100%; height: 100%; display: block; }

.ping-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; justify-content: center; align-items: flex-end; opacity: 0; transition: opacity 0.3s ease; }
.ping-modal.show { opacity: 1; display: flex; }
.ping-sheet { width: 100%; max-width: 600px; background: #0a0a0a; border: 1px solid #00ff41; border-bottom: none; border-radius: 12px 12px 0 0; overflow: hidden; transform: translateY(100%); transition: transform 0.3s ease; box-shadow: 0 -5px 20px rgba(0, 255, 65, 0.2); }
.ping-modal.show .ping-sheet { transform: translateY(0); }
.ping-sheet .item { padding: 16px; text-align: center; background: #0a0a0a; border-bottom: 1px solid #333; font-size: 15px; color: #00f0ff; cursor: pointer; font-family: 'Share Tech Mono'; letter-spacing: 2px;}
.ping-sheet .item:active { background: #111; color: #00ff41; }
.ping-sheet .item.cancel { margin-top: 8px; color: #ff2a2a; font-weight: bold; border-bottom: none; }

.footer-banner { margin-top: 20px; text-align: center; border-radius: 8px; overflow: hidden; }
audio { outline: none; filter: invert(0.8) hue-rotate(180deg); }
.sub-text { color: #888 !important; }


</style>
</head>
<body>
<div class="app-container">

  <!-- ========================================== -->
  <!-- 📢 GOOGLE ADSENSE MATRIX                   -->
  <!-- ========================================== -->

  <!-- ========================================== -->

  <div class="card">
    <input type="file" id="universalFile" class="input-style" accept="image/*,audio/*,video/*" />
    <div id="stats">
      <span id="total">总数: 0</span>
      <span id="chinese">汉字: 0</span>
      <span id="punctuation">标点: 0</span>
      <span id="alphabet">字母: 0</span>
      <span id="numbers">数字: 0</span>
      <span id="duplicate">重复: 0</span>
    </div>
    <textarea id="universalText" class="input-style is-empty" placeholder="🚀 系统就绪，等待输入指令或粘贴数据源..."></textarea>
  </div>

  <div class="card">
   <div class="button-grid" style="margin-top: 15px; grid-template-columns: 1fr 1fr 1fr;">
     <button class="btn " id="btnClearOutput" title="复制文本并彻底重置所有控制状态">📋 复制重置</button>
     <button class="btn btn-tucao" id="btnShareImage">图像分享</button>
     <button class="btn " id="btnCombinedAction">💬 AI 发送</button>   
      <button class="btn" id="btnExtractPoints">提炼要点</button>
      <button class="btn" id="btnExtractLinks">提链去重</button>
      <button class="btn" id="btnHtmlPreview">HTML预览</button>
      <button class="btn" id="btnFrames">视频分离</button>
      <button class="btn" id="optimize-btn">自然排版</button>
      <button class="btn" id="btnDecodeQR">识别二维码</button>
    </div>

    <div id="responseWrap">
      <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:5px;">
        <span class="sub-text" id="respStatus" style="font-size:12px; color:#888;">等待执行指令...</span>
        <button class="btn" id="btnHideResp" style="padding: 2px 8px; font-size:11px;">隐藏日志</button>
      </div>
      <div id="response"></div>
    </div>
  </div>

  <div class="card output-area" id="viewportCard">
    <div class="ping-box" onclick="openPingModal()">
            <div class="ping-site" id="siteName">百度</div>
            <div class="ping-ms" id="pingText">检测中...</div>
            <div style="color: #00f0ff; font-size: 12px;">切换节点 ⌄</div>
        </div>
        <div class="ping-desc" style="display: flex; justify-content: space-between; align-items: center;">
            <span>LINK LATENCY <span id="descSite" style="color:#d4d4d4; font-weight:bold;">百度</span>:</span>
            <span id="performance-result" style="background: #000; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, Consolas, monospace; font-size: 10px; color: #00ff41;">LOADING...</span>
        </div>
        <div class="ping-chart-wrap">
            <canvas id="chart"></canvas>
        </div>

    <div id="imageDisplayContainer" style="display:none; flex-direction:column; align-items:center; margin-top:15px; width: 100%;">
      <div id="originalImgWrapper" style="width: 100%; text-align: center; margin-bottom: 15px;">
        <img id="displayedImage" src="" style="max-width:100%; border-radius:6px; border: 1px solid #333;" />
      </div>

      <div id="modifiedImgWrapper" style="width: 100%; text-align: center; display: none; margin-bottom: 15px;">
        <img id="modifiedImage" src="" style="max-width:100%; border-radius:6px; border: 1px solid #00f0ff;" />
      </div>

      <div id="imageTimeLabel" class="sub-text"></div>
      <div id="framePager" class="sub-text" style="display:none; font-family: 'Share Tech Mono', monospace; margin-top: 5px; color: #888;"></div>
      <div class="button-grid" id="frameControls" style="display:none; margin-top: 15px; width:100%;">
        <button class="btn" id="prevButton">← 上一帧</button>
        <button class="btn" id="nextButton">下一帧 →</button>
      </div>

      <div class="button-grid" id="editButtons" style="display:none; margin-top:15px; width:100%;">
        <button class="btn" id="btnInvert">反色处理</button>
        <button class="btn" id="btnGray">灰度去除</button>
        <button class="btn" id="btnRestore">源图还原</button>
      </div>
    </div>
    
    <div id="mediaPreview" style="margin-top:15px; display:flex; flex-direction:column; align-items:center; width:100%;"></div>
  </div>

  <div class="footer-banner">
    <input type="text" id="apiProxy" class="input-style" placeholder="🌐 PROXY_URL (留空则默认官方通道)" />
    <input type="password" id="apiKey" class="input-style" placeholder="🔑 SECURE_KEY (Text/Gemini)" />
    <input type="password" id="imageApiKey" class="input-style" placeholder="🎨 SECURE_KEY (GPT Image)" style="display:none;" />
    
    <select id="imageResolution" class="input-style" style="display:none;">
        <option value="1k" selected>RES: 1K STANDARD</option>
        <option value="2k">RES: 2K HIGH-DEF</option>
        <option value="4k">RES: 4K ULTRA-HD</option>
    </select>

    <select id="unifiedModel" class="input-style" onchange="onModelChange()">
        <optgroup label="💬 文本对话与多模态提问">
            <option value="deepseek-v4-flash" selected>DeepSeek-V4 (Default)</option>
            <option value="gpt-5.5-2026-04-23">GPT-5.5 Core</option>
            <option value="gemini-3.1-pro-preview">Gemini-3.1 Pro</option>
        </optgroup>
        <optgroup label="🎨 图像生成与修改">
            <option value="gpt-image-2">GPT Image 2 Engine</option>
            <option value="gemini-2.5-flash-image">Gemini-2.5-Flash Vision</option>
        </optgroup>
    </select>
  </div>
</div>

<div class="ping-modal" id="pingModal" onclick="closePingModal(event)">
    <div class="ping-sheet" id="pingSheet">
        <div class="item" onclick="changeSite('https://www.baidu.com','baidu.com')">BAIDU_UPLINK</div>
        <div class="item" onclick="changeSite('https://weibo.com','weibo.com')">WEIBO_UPLINK</div>
        <div class="item" onclick="changeSite('https://www.douyin.com','douyin.com')">DOUYIN_UPLINK</div>
        <div class="item cancel" onclick="closePingModal()">ABORT</div>
    </div>
</div>

<script>
const universalText = document.getElementById("universalText");
const universalFile = document.getElementById("universalFile");
const response = document.getElementById("response");
const responseWrap = document.getElementById("responseWrap");
const respStatus = document.getElementById("respStatus");
const displayedImage = document.getElementById("displayedImage");
const imageDisplayContainer = document.getElementById("imageDisplayContainer");
const editButtons = document.getElementById("editButtons");
const mediaPreview = document.getElementById("mediaPreview");
const btnCombinedAction = document.getElementById("btnCombinedAction"); 
const btnAiAction = document.getElementById("btnAiAction");
const modifiedImgWrapper = document.getElementById("modifiedImgWrapper");
const modifiedImage = document.getElementById("modifiedImage");
const frameControls = document.getElementById("frameControls");
const framePager = document.getElementById("framePager");
const prevButton = document.getElementById("prevButton");
const nextButton = document.getElementById("nextButton");

const TEXT_MODELS = ['deepseek-v4-flash', 'gpt-5.5-2026-04-23', 'gemini-3.1-pro-preview'];
const IMAGE_MODELS = ['gpt-image-2', 'gemini-2.5-flash-image'];

window.addEventListener('load', () => {
    const savedKey = localStorage.getItem('suishouji_api_key') || localStorage.getItem('pixel_api_key');
    if (savedKey) document.getElementById('apiKey').value = savedKey;

    const savedImageKey = localStorage.getItem('suishouji_image_api_key');
    if (savedImageKey) document.getElementById('imageApiKey').value = savedImageKey;

    const savedRes = localStorage.getItem('suishouji_image_res');
    if (savedRes) document.getElementById('imageResolution').value = savedRes;

    const savedProxy = localStorage.getItem('suishouji_api_proxy') || localStorage.getItem('pixel_api_proxy');
    if (savedProxy) document.getElementById('apiProxy').value = savedProxy;

    const loadTime = performance.now();
    const perfElem = document.getElementById('performance-result');
    if(perfElem) {
        perfElem.innerHTML = "SYS_BOOT: <span style=\"color:#00ff41; font-weight:bold;\">" + Math.round(loadTime) + "ms</span>";
    }

    checkEmptyState();
    onModelChange(); 
});
  
function bindEvent(id, type, handler) {
    const el = document.getElementById(id); 
    if(el) el.addEventListener(type, handler);
}

function checkEmptyState() {
    if (universalText.value.length === 0) {
        universalText.classList.add('is-empty');
    } else {
        universalText.classList.remove('is-empty');
    }
}

function getCurrentTimeStr() {
    const now = new Date();
    return now.toTimeString().split(' ')[0];
}

let images = [];
let currentImageIndex = -1;
let currentFilter = "";
let originalImgSrc = "";    
let modifiedImgSrc = "";    
let isShowingModified = false; 

const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

function getApiKey() {
    const key = document.getElementById('apiKey').value.trim();
    if (key) localStorage.setItem('suishouji_api_key', key);
    else localStorage.removeItem('suishouji_api_key');
    return key;
}

function getImageApiKey() {
    const key = document.getElementById('imageApiKey').value.trim();
    if (key) localStorage.setItem('suishouji_image_api_key', key);
    else localStorage.removeItem('suishouji_image_api_key');
    return key;
}

function getApiProxy() {
    const proxy = document.getElementById('apiProxy').value.trim();
    if (proxy) localStorage.setItem('suishouji_api_proxy', proxy);
    else localStorage.removeItem('suishouji_api_proxy');
    return proxy;
}

document.getElementById('imageResolution').addEventListener('change', (e) => {
    localStorage.setItem('suishouji_image_res', e.target.value);
});

function checkKeyRequirement(model, isForcedGpt = false) {
    if (model === 'gpt-image-2') {
        getImageApiKey(); 
        return true; 
    } else {
        const key = getApiKey();
        if (!isForcedGpt && (model === 'deepseek-v4-flash')) {
            return true;
        }
        if (!key) {
            alert("SYS_WARNING: 默认通道仅支持 DeepSeek。\n\n如需使用 GPT/Gemini，请在页面底部输入您的安全密钥 (SECURE_KEY)！");
            const keyInput = document.getElementById('apiKey');
            if (keyInput) {
                keyInput.focus();
                keyInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
    }
    return true;
}

function onModelChange() {
    const model = document.getElementById('unifiedModel').value;
    const hasImage = originalImgSrc !== "" && imageDisplayContainer.style.display !== "none";

    const apiKeyInput = document.getElementById('apiKey');
    const imageApiKeyInput = document.getElementById('imageApiKey');
    const imageResolutionSelect = document.getElementById('imageResolution');
    const proxyInput = document.getElementById('apiProxy');

    if (TEXT_MODELS.includes(model)) {
        apiKeyInput.style.display = "block";
        imageApiKeyInput.style.display = "none";
        imageResolutionSelect.style.display = "none";
        proxyInput.style.display = "block";

        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = hasImage ? "🖼️ 图像问答" : "💬 AI 发送";
            btnCombinedAction.className = "btn btn-tucao";
        }
    } else if (IMAGE_MODELS.includes(model)) {
        proxyInput.style.display = "block";
        if (model === 'gpt-image-2') {
            apiKeyInput.style.display = "none";
            imageApiKeyInput.style.display = "block";
            imageResolutionSelect.style.display = "block";
        } else {
            apiKeyInput.style.display = "block";
            imageApiKeyInput.style.display = "none";
            imageResolutionSelect.style.display = "none";
        }

        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = hasImage ? "🎨 AI 改图" : "🎨 AI 生图";
            btnCombinedAction.className = "btn btn-ai";
        }
    }
}

function setupGeneratedImage(imageUrl, successMsg, isEdit = false) {
    if (isEdit) {
        modifiedImgSrc = imageUrl;
        modifiedImage.src = imageUrl;
        modifiedImgWrapper.style.display = "block";
        respStatus.textContent = "✨ " + successMsg + " (已显示在下方)";
    } else {
        originalImgSrc = imageUrl; 
        modifiedImgSrc = "";  
        displayedImage.src = imageUrl;
        modifiedImgWrapper.style.display = "none";
        respStatus.textContent = "✨ " + successMsg;
    }
    imageDisplayContainer.style.display = "flex";
    editButtons.style.display = "grid";
    onModelChange(); 
    respStatus.style.color = "#00ff41";
    responseWrap.style.display = "block";
    response.textContent = "✅ 操作执行成功。";
}

function copyToClipboard(text, btnElement) {
    function copySuccess() {
        if(!btnElement) return;
        const old = btnElement.innerHTML;
        const oldBg = btnElement.style.background;
        const oldColor = btnElement.style.color;
        btnElement.innerHTML = "✓ 复制成功";
        btnElement.style.background = "rgba(0, 255, 65, 0.2)"; 
        btnElement.style.color = "#00ff41";
        setTimeout(() => {
            btnElement.innerHTML = old;
            btnElement.style.background = oldBg;
            btnElement.style.color = oldColor;
        }, 2000);
    }
    function copyFallback() {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        try { document.execCommand('copy'); copySuccess(); } catch (err) {}
        textArea.remove();
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(copySuccess).catch(copyFallback);
    } else { copyFallback(); }
}

universalFile.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;

    if (file.type.startsWith("video/") && file.size > 100 * 1024 * 1024) {
        alert("⚠️ SYS_WARNING: 视频文件过大！最大仅支持操作 100MB 以内的视频。");
        e.target.value = ""; return;
    }

    const url = URL.createObjectURL(file);
    const mime = file.type;

    imageDisplayContainer.style.display = "none";
    mediaPreview.innerHTML = "";
    currentFilter = ""; displayedImage.style.filter = "";
    displayedImage.removeAttribute("title");

    images = []; currentImageIndex = -1;
    if(frameControls) frameControls.style.display = "none";
    if(framePager) framePager.style.display = "none";

    if (mime.startsWith("image/")) {
        originalImgSrc = url; 
        modifiedImgSrc = "";  
        isShowingModified = false; 
        
        modifiedImgWrapper.style.display = "none";
        modifiedImage.src = "";

        displayedImage.src = url;
        displayedImage.style.display = "block";
        displayedImage.style.cursor = "default";
        imageDisplayContainer.style.display = "flex";
        editButtons.style.display = "grid";
        
        if(btnAiAction) btnAiAction.innerHTML = "🎨 AI 改图";
        onModelChange();
    } else {
        originalImgSrc = "";
        if(btnAiAction) btnAiAction.innerHTML = "✨ 智能润色";
        onModelChange();
        
        if (mime.startsWith("audio/")) {
            const el = document.createElement('audio'); el.controls = true; el.src = url;
            mediaPreview.appendChild(el);
        } else if (mime.startsWith("video/")) {
            const logBox = document.createElement('div'); logBox.className = "sub-text";
            logBox.innerHTML = "VIDEO_LOADED: 请在上方输入帧率(FPS)并点击抽帧";
            mediaPreview.appendChild(logBox);
        }
    }
});

async function getOptimizedBase64(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => {
            const canvas = document.createElement('canvas');
            let w = img.width, h = img.height;
            const max = 1536; 
            if (w > max || h > max) {
                if (w > h) { h = Math.round((h * max) / w); w = max; } 
                else { w = Math.round((w * max) / h); h = max; }
            }
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, w, h);
            const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
            const base64 = dataUrl.split(',')[1];
            resolve({ mimeType: 'image/jpeg', base64Data: base64, fullUrl: dataUrl });
        };
        img.onerror = () => reject(new Error("图片加载失败"));
        img.crossOrigin = "Anonymous";
        img.src = src;
    });
}

async function pollGptTask(taskId, promptText, successPrefix, isEdit) {
    respStatus.textContent = "UPLINK QUEUED...";
    response.textContent = "✅ TASK_SUBMITTED (ID: " + taskId.substring(0,8) + "...) \nAWAITING 15 SECONDS...";
    await sleep(15000);

    let attempts = 0, finalImageUrl = "", queryData = null;
    while (attempts < 30) {
        attempts++;
        response.textContent += "\n[PING " + attempts + " STATUS...]";

        const imageApiKey = getImageApiKey(); 
        const apiProxy = getApiProxy();
        const queryRes = await fetch("?api=gpt_image_query&task_id=" + encodeURIComponent(taskId), {
            headers: { 'X-Image-Key': imageApiKey, 'X-Proxy-Url': apiProxy }
        });
        queryData = await queryRes.json();
        const status = queryData.data?.status || queryData.status || 'unknown';
        
        if (status === 'completed' || status === 'succeeded') {
            let rawUrl = queryData.data?.result?.images?.[0]?.url || 
                         queryData.data?.images?.[0]?.url || 
                         queryData.data?.result?.image_urls?.[0];
                         
            let baseImageUrl = Array.isArray(rawUrl) ? rawUrl[0] : rawUrl;
            if (baseImageUrl) {
                finalImageUrl = baseImageUrl + (baseImageUrl.includes('?') ? '&' : '?') + 't=' + new Date().getTime();
                break;
            } else { 
                throw new Error('SYS_ERR: 任务完成但未找到 URL'); 
            }
        } else if (status === 'failed') {
            throw new Error('SYS_ERR 渲染失败: ' + (queryData.data?.error?.message || '内部错误'));
        } else {
            await sleep(4000); 
        }
    }
    if (attempts >= 30) throw new Error('SYS_TIMEOUT 查询超时。');
    return { finalImageUrl, queryData };
}

if(btnCombinedAction) {
    btnCombinedAction.onclick = async () => {
        const content = universalText.value.trim();
        const model = document.getElementById('unifiedModel').value;
        const isImageMode = originalImgSrc && imageDisplayContainer.style.display !== "none";
        
        if (!checkKeyRequirement(model)) return;

        const apiKey = getApiKey();
        const imageApiKey = getImageApiKey(); 
        const apiProxy = getApiProxy();

        if (TEXT_MODELS.includes(model)) {
            if (!content && !isImageMode) return alert("SYS_PROMPT: 请输入对话内容！");
            
            const oldText = btnCombinedAction.innerHTML;
            btnCombinedAction.disabled = true;
            responseWrap.style.display = "block";

            btnCombinedAction.innerHTML = "⏳ 运算中...";
            respStatus.textContent = "ESTABLISHING UPLINK...";
            respStatus.style.color = "#00f0ff";
            response.textContent = "⏳ 等待核心算力响应...\n";

            try {
                let apiMessages = [];
                if (isImageMode) {
                    btnCombinedAction.innerHTML = "⏳ 提取特征...";
                    let targetImgSrc = modifiedImgSrc ? modifiedImgSrc : originalImgSrc;
                    let optimized = await getOptimizedBase64(targetImgSrc);
                    let fullBase64Url = optimized.fullUrl;

                    apiMessages = [{
                        role: "user",
                        content: [
                            { type: "text", text: content || "请详细描述这张图片的内容。" },
                            { type: "image_url", image_url: { url: fullBase64Url } }
                        ]
                    }];
                } else {
                    apiMessages = [{ role: "user", content: content }];
                }

                btnCombinedAction.innerHTML = "⏳ 运算中...";
                const requestPayload = { model: model, messages: apiMessages, stream: false, max_completion_tokens: 4096 };

                const startTime = Date.now();
                const createRes = await fetch("?api=chat", {
                    method: 'POST',
                    headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                if (!createRes.ok) {
                    const err = await createRes.json().catch(()=>({}));
                    throw new Error(err.error?.message || "HTTP " + createRes.status);
                }

                const data = await createRes.json();
                const duration = ((Date.now() - startTime) / 1000).toFixed(2);
                const tokens = data.usage?.total_tokens || "未知";
                const aiReply = data.choices?.[0]?.message?.content || "无有效返回内容";

                respStatus.textContent = "✅ UPLINK SECURED"; respStatus.style.color = "#00ff41";
                
                if (isImageMode) requestPayload.messages[0].content[1].image_url.url = "[Base64 Data Truncated]";
                
                const logHeader = "| ⏱️ " + duration + "s | 🪙 " + tokens + " Tokens |\n";
                const logBody = "[" + getCurrentTimeStr() + "] RESPONSE_LOG:\n📦 Payload:\n" + JSON.stringify(requestPayload) + "\n✅ Result:\n" + JSON.stringify(data);
                response.textContent = logHeader + logBody;
                
                universalText.value = aiReply;
                updateStats(); checkEmptyState();
            } catch (e) {
                respStatus.textContent = "❌ LINK FAILED"; respStatus.style.color = "#ff2a2a";
                response.textContent = "SYS_ERR: " + e.message;
            }
            btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
            return;
        }

        if (IMAGE_MODELS.includes(model)) {
            if (!content && !isImageMode) return alert("SYS_PROMPT: 请在文本框输入您的生图要求！");

            const oldText = btnCombinedAction.innerHTML;
            btnCombinedAction.disabled = true;
            responseWrap.style.display = "block";

            btnCombinedAction.innerHTML = "⏳ 构建任务..."; respStatus.textContent = "INITIALIZING RENDER...";
            response.textContent = "⏳ 构建渲染请求矩阵...\n指令: " + (content || "[处理当前底图]");

            try {
                let base64Data = "", mimeType = "image/png", fullBase64Url = "";
                if (isImageMode) {
                    btnCombinedAction.innerHTML = "⏳ 解析底图...";
                    let targetImgSrc = modifiedImgSrc ? modifiedImgSrc : originalImgSrc;
                    let optimized = await getOptimizedBase64(targetImgSrc);
                    mimeType = optimized.mimeType; base64Data = optimized.base64Data; fullBase64Url = optimized.fullUrl;
                }

                btnCombinedAction.innerHTML = "⏳ 渲染中...";
                const startTime = Date.now();

                if (model === 'gpt-image-2') {
                    const selectedResolution = document.getElementById('imageResolution').value || '1k';
                    const gptPayload = { model: "gpt-image-2", prompt: content || "请以提供的图片为基础生成", n: 1, resolution: selectedResolution, official_fallback: true };
                    if (isImageMode) gptPayload.image_urls = [ fullBase64Url ];

                    const createRes = await fetch("?api=gpt_image_create", { 
                        method: 'POST', 
                        headers: { 'X-Image-Key': imageApiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                        body: JSON.stringify(gptPayload) 
                    });
                    const createData = await createRes.json();
                    let taskId = (Array.isArray(createData.data) && createData.data.length > 0) ? (createData.data[0].task_id || createData.data[0].id) : (createData.data?.id || createData.data?.task_id || createData.task_id || createData.id);
                    
                    if (!createRes.ok || !taskId) throw new Error(createData.error?.message || JSON.stringify(createData));

                    const { finalImageUrl, queryData } = await pollGptTask(taskId, content, "改图完成",  isImageMode);
                    const duration = ((Date.now() - startTime) / 1000).toFixed(2);
                    
                    setupGeneratedImage(finalImageUrl, "RENDER_COMPLETE", isImageMode);
                    response.textContent = "| ⏱️ " + duration + "s |\n[" + getCurrentTimeStr() + "] APIMart_LOG:\n" + JSON.stringify(queryData);
                } else {
                    const requestParts = [];
                    if (isImageMode) requestParts.push({ inlineData: { mimeType: mimeType, data: base64Data } });
                    if (content) requestParts.push({ text: content });

                    const requestBody = { contents: [{ parts: requestParts }], generationConfig: { responseModalalities: ["Text", "Image"] } };
                    const createRes = await fetch("?api=image&model=" + model, {
                        method: 'POST',
                        headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestBody)
                    });

                    if (!createRes.ok) {
                        const err = await createRes.json().catch(()=>({}));
                        throw new Error(err.error?.message || "HTTP " + createRes.status);
                    }

                    const data = await createRes.json();
                    let imageUrl = "";
                    data.candidates[0].content.parts.forEach(p => {
                        if (p.inlineData) imageUrl = "data:" + p.inlineData.mimeType + ";base64," + p.inlineData.data;
                    });
                    if (!imageUrl) throw new Error("SYS_ERR: API未返回图片数据");

                    const duration = ((Date.now() - startTime) / 1000).toFixed(2);
                    setupGeneratedImage(imageUrl, "RENDER_COMPLETE", isImageMode);
                    
                    if(isImageMode) requestBody.contents[0].parts[0].inlineData.data = "[Base64 Data Truncated]";
                    response.textContent = "| ⏱️ " + duration + "s |\n📦 Payload:\n" + JSON.stringify(requestBody) + "\n-- SYS_SUCCESS";
                }
            } catch (e) {
                respStatus.textContent = "❌ ERROR"; respStatus.style.color = "#ff2a2a";
                response.textContent = "SYS_ERR: " + e.message;
            }
            btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
        }
    };
}

if(btnAiAction) {
    btnAiAction.onclick = async () => {
        const content = universalText.value.trim();
        if (!content) return alert("SYS_PROMPT: 请输入提示词或润色要求！");
        
        const isImageMode = originalImgSrc && imageDisplayContainer.style.display !== "none";
        
        if (isImageMode && !checkKeyRequirement(null, true)) return;

        const apiKey = getApiKey();
        const imageApiKey = getImageApiKey(); 
        const apiProxy = getApiProxy();

        const oldText = btnAiAction.innerHTML; btnAiAction.disabled = true; responseWrap.style.display = "block";

        if (isImageMode) {
            btnAiAction.innerHTML = "⏳ 解析中..."; respStatus.textContent = "INITIALIZING RENDER...";
            response.textContent = "⏳ 提取视觉矩阵并转换为算力指令...\n指令: " + content;

            try {
                let targetImgSrc = modifiedImgSrc ? modifiedImgSrc : originalImgSrc;
                let optimized = await getOptimizedBase64(targetImgSrc);
                const fullBase64Url = optimized.fullUrl;

                const selectedResolution = document.getElementById('imageResolution').value || '1k';

                btnAiAction.innerHTML = "⏳ 提交队列...";
                const gptPayload = { model: "gpt-image-2", prompt: content, n: 1, resolution: selectedResolution, official_fallback: true, image_urls: [ fullBase64Url ] };

                const createRes = await fetch("?api=gpt_image_create", { 
                    method: 'POST', 
                    headers: { 'X-Image-Key': imageApiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                    body: JSON.stringify(gptPayload) 
                });
                const createData = await createRes.json();
                let taskId = (Array.isArray(createData.data) && createData.data.length > 0) ? (createData.data[0].task_id || createData.data[0].id) : (createData.data?.id || createData.data?.task_id || createData.task_id || createData.id);
                
                if (!createRes.ok || !taskId) throw new Error(createData.error?.message || JSON.stringify(createData));

                btnAiAction.innerHTML = "⏳ 等待回传...";
                const { finalImageUrl } = await pollGptTask(taskId, content, "EDIT_COMPLETE", true);
                setupGeneratedImage(finalImageUrl, "EDIT_COMPLETE", true);
            } catch (e) {
                respStatus.textContent = "❌ ERROR"; respStatus.style.color = "#ff2a2a";
                response.textContent = "SYS_ERR: " + e.message;
            }
        } else {
            btnAiAction.innerHTML = "⏳ 运算中..."; respStatus.textContent = "DEEP_THINKING...";
            response.textContent = "🚀 正在穿透算力层，请稍候...";
            try {
                const res = await fetch('?api=chat', {
                    method: 'POST',
                    headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        messages: [
                            {role: "system", content: "你空间是一位文字精炼大师。请对用户提供的文本进行润色，要求：1. 保持原意不变；2. 优化逻辑结构；3. 增强文采。直接输出结果。"},
                            {role: "user", content: content}
                        ], temperature: 0.7
                    })
                });
                const data = await res.json();
                if(data.choices && data.choices[0].message) {
                    universalText.value = data.choices[0].message.content.trim();
                    updateStats(); checkEmptyState(); 
                    respStatus.textContent = "✨ TASK_COMPLETE"; respStatus.style.color = "#00ff41";
                    response.textContent = "✅ UPLINK_SUCCESS: 已替换文本缓冲区。";
                } else { throw new Error(JSON.stringify(data)); }
            } catch(e) {
                respStatus.textContent = "❌ FAILED"; respStatus.style.color = "#ff2a2a";
                response.textContent = "SYS_ERR: " + e.message;
            }
        }
        btnAiAction.innerHTML = oldText; btnAiAction.disabled = false;
    };
}

bindEvent("btnExtractPoints", "click", async () => {
    const content = universalText.value.trim();
    if (!content) return alert("SYS_PROMPT: 请在文本框输入需要提炼的文本内容！");
    const apiKey = getApiKey();
    const apiProxy = getApiProxy();

    const btn = document.getElementById("btnExtractPoints"); const oldText = btn.innerHTML;
    btn.innerHTML = "⏳ 提炼中..."; btn.disabled = true; responseWrap.style.display = "block";
    respStatus.textContent = "DEEP_THINKING..."; respStatus.style.color = "#00f0ff";
    response.textContent = "🚀 正在请求 DeepSeek 提炼核心要点...";

    try {
        const res = await fetch('?api=chat', {
            method: 'POST',
            headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                messages: [
                    {role: "system", content: "你是一个专业的文本 analysis 助手。请准确、简明地提炼用户提供文本的核心要点，使用结构化的列表形式输出，剥离冗余信息。"},
                    {role: "user", content: content}
                ], temperature: 0.3 
            })
        });
        const data = await res.json();
        if(data.choices && data.choices[0].message) {
            universalText.value = data.choices[0].message.content.trim();
            updateStats(); checkEmptyState(); 
            respStatus.textContent = "✨ TASK_COMPLETE"; respStatus.style.color = "#00ff41";
            response.textContent = "✅ UPLINK_SUCCESS: 核心要点已写入文本缓冲区。";
        } else { throw new Error(JSON.stringify(data)); }
    } catch(e) {
        respStatus.textContent = "❌ FAILED"; respStatus.style.color = "#ff2a2a";
        response.textContent = "SYS_ERR: " + e.message;
    } finally { btn.innerHTML = oldText; btn.disabled = false; }
});

bindEvent("btnExtractLinks", "click", () => {
    const text = universalText.value; if(!text.trim()) return alert("SYS_PROMPT: 请在文本框输入要处理的内容！");
    responseWrap.style.display = "block"; respStatus.textContent = "PARSING..."; respStatus.style.color = "#00f0ff";
    try {
        let results = new Set(); 
        const attrRegex = /(?:href|src)\s*=\s*["']([^"']+)["']/gi; let match;
        while ((match = attrRegex.exec(text)) !== null) {
            const url = match[1].trim();
            if(url && !url.startsWith('data:') && !url.startsWith('javascript:') && url !== '#') results.add(url);
        }
        const urlRegex = /(https?:\/\/[^\s"'<>]+)/gi;
        while ((match = urlRegex.exec(text)) !== null) { results.add(match[1].trim()); }

        if (results.size > 0) {
            universalText.value = Array.from(results).join('\n');
            response.textContent = "✅ PARSE_SUCCESS: 成功提取并去重，共得 " + results.size + " 个有效链接。";
        } else {
            const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            results = new Set(lines); 
            universalText.value = Array.from(results).join('\n');
            response.textContent = "⚠️ SYS_WARN: 未发现特定链接矩阵，已对文本【按行去重】，保留 " + results.size + " 行。";
        }
        updateStats(); checkEmptyState(); respStatus.textContent = "DONE"; respStatus.style.color = "#00ff41";
    } catch (e) { response.textContent = "SYS_ERR: " + e.message; respStatus.textContent = "❌ FAILED"; respStatus.style.color = "#ff2a2a"; }
});

function updateStats() {
    const text = universalText.value || "";
    document.getElementById("total").textContent = "总数: " + text.length;
    document.getElementById("chinese").textContent = "汉字: " + ((text.match(/[\u4e00-\u9fa5]/g) || []).length);
    document.getElementById("numbers").textContent = "数字: " + ((text.match(/\d/g) || []).length);
    document.getElementById("punctuation").textContent = "标点: " + ((text.match(/[.,\/#!$%\^&\*;:{}=\-_`~()。,、；：？！…—·「」『』（）［］【】《》〈〉"']/g) || []).length);
    document.getElementById("alphabet").textContent = "字母: " + ((text.match(/[a-zA-Z]/g) || []).length);
    const words = text.split(/[\s.,\/#!$%\^&\*;:{}=\-_`~()。,、；：？！…—·「」『』（）［］【】《》〈〉"']+/).filter(w => w.trim().length > 0);
    const wordCounts = {}; let duplicateCount = 0;
    words.forEach(w => { wordCounts[w] = (wordCounts[w] || 0) + 1; });
    for (let w in wordCounts) { if (wordCounts[w] > 1) duplicateCount += wordCounts[w]; }
    document.getElementById("duplicate").textContent = "重复: " + duplicateCount;
}

universalText.addEventListener("input", () => { updateStats(); checkEmptyState(); });

bindEvent("btnInvert", "click", () => { currentFilter += " invert(1)"; displayedImage.style.filter = currentFilter; });
bindEvent("btnGray", "click", () => { currentFilter += " grayscale(1)"; displayedImage.style.filter = currentFilter; });
bindEvent("btnRestore", "click", () => { currentFilter = ""; displayedImage.style.filter = ""; });
bindEvent("btnHideResp", "click", () => { responseWrap.style.display = "none"; });

bindEvent("optimize-btn", "click", () => {
    let text = universalText.value; if (!text) return;
    text = text.replace(/[*_]{1,3}([^*_]+)[*_]{1,3}/g, '$1').replace(/^([#\-*>+]+)\s+/gm, '').replace(/`/g, '');
    text = text.split('\n').map(line => line.trim()).join('\n').replace(/\n{3,}/g, '\n\n');
    universalText.value = text; updateStats(); checkEmptyState(); 
});

bindEvent("btnClearOutput", "click", () => {
    const textToCopy = universalText.value;
    const executeClear = (isCopied) => {
        universalText.value = ""; universalFile.value = ""; response.textContent = ""; responseWrap.style.display = "none";
        images = []; currentImageIndex = -1; currentFilter = "";
        originalImgSrc = ""; modifiedImgSrc = ""; isShowingModified = false;
        
        if(btnAiAction) btnAiAction.innerHTML = "✨ 智能润色";
        onModelChange();
        displayedImage.src = ""; displayedImage.style.filter = ""; displayedImage.style.cursor = "default";
        displayedImage.removeAttribute("title");
        
        if(modifiedImgWrapper) { modifiedImgWrapper.style.display = "none"; modifiedImage.src = ""; }
        imageDisplayContainer.style.display = "none"; editButtons.style.display = "none"; mediaPreview.innerHTML = "";
        if(frameControls) frameControls.style.display = "none";
        if(framePager) { framePager.style.display = "none"; framePager.textContent = ""; }

        updateStats(); checkEmptyState(); 
        
        const timeLabel = document.getElementById("imageTimeLabel");
        if(timeLabel) { timeLabel.innerHTML = ""; timeLabel.style.display = "none"; }
        
        const btn = document.getElementById("btnClearOutput");
        if(btn) {
            btn.textContent = isCopied ? "✓ 复制并重置" : "✓ 已重置"; 
            btn.style.color = "#00ff41"; btn.style.borderColor = "#00ff41";
            setTimeout(() => { btn.textContent = "📋 复制重置"; btn.style.color=""; btn.style.borderColor="";}, 1500);
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
        universalText.focus({ preventScroll: true }); 
    };

    if (textToCopy) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(textToCopy).then(() => executeClear(true)).catch(() => executeClear(false));
        } else {
            const textArea = document.createElement("textarea"); textArea.value = textToCopy; document.body.appendChild(textArea);
            textArea.select(); let success = false;
            try { success = document.execCommand('copy'); } catch (err) {}
            textArea.remove(); executeClear(success);
        }
    } else { executeClear(false); }
});

async function extractAudioToMP3(file, progressCallback) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = async (e) => {
            try {
                const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                const audioBuffer = await audioCtx.decodeAudioData(e.target.result);
                
                const channels = audioBuffer.numberOfChannels;
                const sampleRate = audioBuffer.sampleRate;
                const mp3encoder = new lamejs.Mp3Encoder(channels, sampleRate, 128); 
                const mp3Data = [];
                
                const left = audioBuffer.getChannelData(0);
                const right = channels > 1 ? audioBuffer.getChannelData(1) : left;
                
                const left16 = new Int16Array(left.length);
                const right16 = channels > 1 ? new Int16Array(right.length) : null;
                
                for (let i = 0; i < left.length; i++) {
                    left16[i] = left[i] < 0 ? left[i] * 0x8000 : left[i] * 0x7FFF;
                    if (channels > 1) {
                        right16[i] = right[i] < 0 ? right[i] * 0x8000 : right[i] * 0x7FFF;
                    }
                }
                
                const sampleBlockSize = 1152;
                let i = 0;
                
                function encodeNextChunk() {
                    const endTime = performance.now() + 25; 
                    while (i < left16.length && performance.now() < endTime) {
                        const leftChunk = left16.subarray(i, i + sampleBlockSize);
                        let mp3buf;
                        if (channels > 1) {
                            const rightChunk = right16.subarray(i, i + sampleBlockSize);
                            mp3buf = mp3encoder.encodeBuffer(leftChunk, rightChunk);
                        } else {
                            mp3buf = mp3encoder.encodeBuffer(leftChunk);
                        }
                        if (mp3buf.length > 0) mp3Data.push(mp3buf);
                        i += sampleBlockSize;
                    }
                    
                    if (i < left16.length) {
                        if (progressCallback) progressCallback(Math.floor((i / left16.length) * 100));
                        setTimeout(encodeNextChunk, 0); 
                    } else {
                        const mp3buf = mp3encoder.flush();
                        if (mp3buf.length > 0) mp3Data.push(mp3buf);
                        const blob = new Blob(mp3Data, { type: 'audio/mp3' });
                        resolve(URL.createObjectURL(blob));
                    }
                }
                
                encodeNextChunk();
            } catch (err) {
                reject(err);
            }
        };
        reader.onerror = reject;
        reader.readAsArrayBuffer(file);
    });
}

bindEvent("btnFrames", "click", async () => {
    const fps = parseInt(universalText.value); const file = universalFile.files[0];
    if(!file || isNaN(fps)) return alert("SYS_PROMPT: 需在文本框输入FPS抽帧率(数字)，并挂载视频文件");
    const btn = document.getElementById("btnFrames"); const old = btn.innerHTML;
    
    btn.innerHTML = "处理中..."; btn.disabled = true;
    
    responseWrap.style.display = "block"; 
    respStatus.textContent = "⏳ EXTRACTING..."; respStatus.style.color = "#00f0ff";
    response.textContent = "🎬 锁定目标: " + fps + " FPS\n正在分配本地算力引擎进行 [矩阵抽帧] 与 [音轨提取]...";

    const video = document.createElement('video'); video.src = URL.createObjectURL(file);
    
    video.onloadedmetadata = async () => {
        images = []; 
        const total = Math.floor(video.duration * fps);
        const startTime = performance.now();

        for(let i = 0; i <= total; i++) {
            video.currentTime = i / fps; await new Promise(r => video.onseeked = r);
            const canvas = document.createElement('canvas'); canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            images.push(canvas.toDataURL('image/jpeg', 0.8));
            
            if (i % 3 === 0 || i === total) {
                const currentCost = (performance.now() - startTime) / 1000;
                const realTimeSpeed = currentCost > 0 ? (i / currentCost).toFixed(1) : 0;
                respStatus.textContent = "⏳ 引擎运作: " + i + "/" + total + " 帧 | ⚡ " + realTimeSpeed + " FPS";
            }
        }

        const costTime = ((performance.now() - startTime) / 1000).toFixed(2);
        const processSpeed = costTime > 0 ? (images.length / costTime).toFixed(1) : 0;

        currentImageIndex = 0; mediaPreview.innerHTML = ""; 
        imageDisplayContainer.style.display = "flex"; editButtons.style.display = "grid";
        
        const timeLabel = document.getElementById("imageTimeLabel");
        if (timeLabel) {
            timeLabel.style.display = "block";
            timeLabel.style.color = "#00ff41";
            timeLabel.style.marginTop = "10px";
            timeLabel.innerHTML = "✅ 解析完毕: <b>" + images.length + "</b> 帧 | ⚡ <b>" + processSpeed + " 帧/秒</b>";
        }

        respStatus.textContent = "⏳ 分离音轨中..."; 
        response.textContent = "✅ 图像层提取成功！\n📊 矩阵数: " + images.length + " 帧\n⏱️ 耗时: " + costTime + " 秒\n⚡ 均速: " + processSpeed + " 帧/秒\n\n🎵 正在切分音轨通道并编码 MP3 格式...";
        
        showFrame(); 

        const safeName = file.name.split('.').slice(0, -1).join('.') || file.name;

        const resultDiv = document.createElement('div');
        resultDiv.style.marginTop = "20px";
        resultDiv.style.width = "100%";
        resultDiv.style.textAlign = "center";
        resultDiv.style.padding = "15px";
        resultDiv.style.background = "#050505";
        resultDiv.style.border = "1px solid #333";
        resultDiv.style.borderRadius = "6px";

        const isWechatClient = navigator.userAgent.toLowerCase().indexOf('micromessenger') !== -1;

        const zipBtn = document.createElement('button');
        zipBtn.className = "btn btn-ai";
        zipBtn.style.cssText = "margin-bottom:15px; width:100%; border:none;";
        
        if (isWechatClient) {
            zipBtn.style.display = 'none';
        }

        zipBtn.innerText = "📦 一键打包提取矩阵帧 (ZIP)";
        zipBtn.onclick = async () => {
            zipBtn.disabled = true;
            const oldZipText = zipBtn.innerText;
            try {
                const zip = new JSZip();
                const imgFolder = zip.folder("frames");
                for (let j = 0; j < images.length; j++) {
                    const b64 = images[j].split(',')[1];
                    const timeSec = (j / fps).toFixed(2);
                    const fName = "frame_" + String(j).padStart(4, '0') + "_" + timeSec + "s.jpg";
                    imgFolder.file(fName, b64, {base64: true});
                }
                zipBtn.innerText = "正在压缩...";
                const blob = await zip.generateAsync({type:"blob"}, (meta) => {
                    zipBtn.innerText = "📦 COMPRESSING... " + meta.percent.toFixed(0) + "%";
                });
                
                const zipUrl = URL.createObjectURL(blob);
                document.getElementById('response').textContent += "\n\n📦 [ZIP 矩阵包] 封装完成！";
                
                const a = document.createElement('a');
                a.href = zipUrl;
                a.download = safeName + "_frames.zip";
                a.click();
                
                zipBtn.innerText = "✅ 打包成功，链接已输出至终端日志";
                setTimeout(() => { zipBtn.innerText = oldZipText; zipBtn.disabled = false; }, 3000);
            } catch(e) {
                alert("SYS_ERR 打包失败: " + e.message);
                zipBtn.innerText = oldZipText; zipBtn.disabled = false;
            }
        };
        resultDiv.appendChild(zipBtn);

        try {
            const mp3Url = await extractAudioToMP3(file, (progress) => {
                respStatus.textContent = "🎵 编码 MP3: " + progress + "%";
            });
            
            respStatus.textContent = "✅ DONE"; respStatus.style.color = "#00ff41";
            response.textContent += "\n\n✅ 音频通道提取并转换 MP3 成功！";
            
            const audioLabel = document.createElement('div');
            audioLabel.style.cssText = "font-size:13px; font-weight:bold; color:#00f0ff; margin-bottom:10px; font-family:'Share Tech Mono';";
            audioLabel.innerText = "🎵 AUDIO_TRACK_ISOLATED";
            
            const audioEl = document.createElement('audio');
            audioEl.controls = true; 
            audioEl.src = mp3Url; 
            audioEl.style.cssText = "width:100%; height:40px; outline:none;";
            
            const audioBtn = document.createElement('button');
            audioBtn.className = "btn btn-tucao"; 
            audioBtn.style.cssText = "margin-top:10px; width:100%; border:none;";
            
            if (isWechatClient) {
                audioBtn.style.display = 'none';
            }

            audioBtn.innerText = "📥 保存 MP3 数据流";
            audioBtn.onclick = () => {
                const a = document.createElement('a');
                a.href = mp3Url;
                a.download = safeName + "_audio.mp3";
                a.click();
            };

            resultDiv.appendChild(audioLabel);
            resultDiv.appendChild(audioEl); 
            resultDiv.appendChild(audioBtn);

        } catch (err) {
            console.error(err);
            respStatus.textContent = "✅ 矩阵层提取完毕 (无音频)";
            response.textContent += "\n⚠️ SYS_WARN: 音轨通道为空或格式暂不支持。";
        }

        mediaPreview.appendChild(resultDiv);
        btn.innerHTML = old; btn.disabled = false;
    };
});

function showFrame() {
    if(images.length === 0) return; 
    displayedImage.src = images[currentImageIndex]; displayedImage.style.display = "block";
    originalImgSrc = images[currentImageIndex]; modifiedImgSrc = ""; onModelChange();

    if(frameControls) frameControls.style.display = "grid"; 
    if(framePager) {
        framePager.style.display = "block";
        framePager.textContent = "INDEX: " + String(currentImageIndex+1).padStart(4,'0') + " / " + String(images.length).padStart(4,'0') + " (支持方向键微调)";
    }
}

function goToPrevFrame() { if(currentImageIndex > 0) { currentImageIndex--; showFrame(); } }
function goToNextFrame() { if(currentImageIndex < images.length-1) { currentImageIndex++; showFrame(); } }

bindEvent("prevButton", "click", goToPrevFrame);
bindEvent("nextButton", "click", goToNextFrame);

document.addEventListener("keydown", (e) => {
    if (frameControls && frameControls.style.display !== "none" && images.length > 0) {
        if (e.key === "ArrowLeft") { e.preventDefault(); goToPrevFrame(); } 
        else if (e.key === "ArrowRight") { e.preventDefault(); goToNextFrame(); }
    }
});

bindEvent("btnHtmlPreview", "click", () => {
    const htmlContent = universalText.value; if(!htmlContent.trim()) return alert("SYS_PROMPT: 缓冲区为空，请装载 HTML 源码。");
    const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8' });
    window.open(URL.createObjectURL(blob), '_blank');
});

bindEvent("btnShareImage", "click", async () => {
    const btn = document.getElementById("btnShareImage");
    const selectedFile = universalFile.files[0];
    let imageBlob = selectedFile && selectedFile.type.startsWith("image/") ? selectedFile : null;
    let fileName = imageBlob ? imageBlob.name : "shared-image.png";

    try {
        if (!imageBlob) {
            const imageSource = modifiedImgSrc || originalImgSrc || displayedImage.src;
            if (!imageSource) throw new Error("请先挂载或生成一张图像");
            const sourceResponse = await fetch(imageSource);
            if (!sourceResponse.ok) throw new Error("无法读取当前图像，请先保存后重新挂载");
            imageBlob = await sourceResponse.blob();
        }
        if (!['image/png', 'image/jpeg'].includes(imageBlob.type)) {
            throw new Error("分享图像仅支持 PNG 和 JPG");
        }

        const oldText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "上传中...";
        const formData = new FormData();
        formData.append("image", imageBlob, fileName);
        const uploadResponse = await fetch("?api=share_image", { method: "POST", body: formData });
        const payload = await uploadResponse.json();
        if (!uploadResponse.ok || !payload.url) {
            throw new Error(payload.error?.message || "图像分享失败");
        }

        universalText.value = payload.url;
        universalText.dispatchEvent(new Event("input"));
        responseWrap.style.display = "block";
        respStatus.textContent = "✅ 图像分享成功";
        respStatus.style.color = "#00ff41";
        response.textContent = payload.url;
        btn.textContent = "✅ 链接已写入 TXT";
        setTimeout(() => { btn.textContent = oldText; btn.disabled = false; }, 2000);
    } catch (error) {
        alert("SYS_ERR: " + error.message);
        btn.textContent = "上传分享图像";
        btn.disabled = false;
    }
});

bindEvent("btnGenerateQR", "click", () => {
    const text = universalText.value.trim(); if(!text) return alert("SYS_PROMPT: 载入核心数据流块以生成二维码。");
    const qr = new QRious({ value: text, size: 250 });
    displayedImage.src = qr.toDataURL(); displayedImage.style.display = "block"; imageDisplayContainer.style.display = "flex";
    originalImgSrc = displayedImage.src; modifiedImgSrc = ""; 
    if(btnAiAction) btnAiAction.innerHTML = "🎨 AI 改图"; 
    onModelChange();
});

bindEvent("btnDecodeQR", "click", () => {
    const textVal = universalText.value.trim(); const file = universalFile.files[0];
    const urlMatch = textVal.match(/https?:\/\/[^\s<>"']+/i);
    let imgSrc = ""; const isUrl = !!urlMatch;
    
    if (isUrl) imgSrc = "?api=qr_image&url=" + encodeURIComponent(urlMatch[0]);
    else if (file && file.type.startsWith("image/")) imgSrc = URL.createObjectURL(file);
    else return alert("SYS_PROMPT: 请挂载二维码图片，或在 TXT 中输入图片网址。");

    const btn = document.getElementById("btnDecodeQR"); const old = btn.innerHTML; 
    btn.innerHTML = "解析中..."; btn.disabled = true;

    const img = new Image();
    
    img.onload = () => {
        try {
            const canvas = document.createElement('canvas'); canvas.width = img.width; canvas.height = img.height;
            const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: "attemptBoth" });
            if (code) { 
                universalText.value = code.data; updateStats(); checkEmptyState(); copyToClipboard(code.data, btn); 
            } else { alert("❌ SYS_ERR: 矩阵解密失败，未发现标准通讯码象。"); }
        } catch (err) {
            alert("❌ CORE_DUMP：" + err.message);
        }
        btn.innerHTML = old; btn.disabled = false;
    };
    img.onerror = () => { alert("❌ LOAD_FAIL: 物料装载失败。"); btn.innerHTML = old; btn.disabled = false; };
    img.src = imgSrc;
});
  
bindEvent("btnReadMeta", "click", async () => {
    const file = universalFile.files[0]; if (!file) return alert("SYS_PROMPT: 载入需要穿透解析的物料节点。");
    responseWrap.style.display = "block"; respStatus.textContent = "透视中..."; response.textContent = "⏳ DEEP_SCAN_INITIALIZED...\n";
    try {
        let metaText = "=== 📁 基础构造 ===\nSYS_NAME: " + file.name + "\nSYS_VOL: " + (file.size / 1024).toFixed(2) + " KB\n\n";
        try {
            const tags = await exifr.parse(file, true);
            if (tags) { metaText += "=== 🏷️ 隐秘元数据层 ===\n"; for (const [k, v] of Object.entries(tags)) metaText += k + ": " + v + "\n"; }
        } catch(e){}
        response.textContent = metaText; respStatus.textContent = "✅ 透视完成"; respStatus.style.color = "#00ff41";
    } catch (e) { response.textContent = "SYS_ERR: " + e.message; respStatus.style.color = "#ff2a2a"; }
});

let currentUrl = "https://www.baidu.com";
let pingData = []; 
const pingTextElem = document.getElementById("pingText");
const pingModal = document.getElementById("pingModal");

function openPingModal() {
    pingModal.style.display = "flex";
    void pingModal.offsetWidth; 
    pingModal.classList.add("show");
}

function closePingModal(e) {
    if (e && e.target.id === "pingSheet") return; 
    pingModal.classList.remove("show");
    setTimeout(() => { pingModal.style.display = "none"; }, 300);
}

function changeSite(url, name) {
    currentUrl = url;
    document.getElementById("siteName").innerText = name;
    document.getElementById("descSite").innerText = name;
    pingData = []; 
    drawChart();
    closePingModal();
    doPing();
}

async function doPing() {
    if(!pingTextElem) return;
    const img = new Image();
    const start = performance.now();
    let isFinished = false; 

    img.onload = function() {
        if (isFinished) return;
        isFinished = true;
        const end = performance.now();
        const ms = Math.floor(end - start);
        pingTextElem.innerText = ms + " ms";
        
        if (ms < 50) pingTextElem.style.color = "#00ff41";
        else if (ms < 100) pingTextElem.style.color = "#d9a23a";
        else pingTextElem.style.color = "#ff2a2a";

        addData(ms);
    };

    img.onerror = function() {
        if (isFinished) return;
        isFinished = true;
        pingTextElem.innerText = "UPLINK_DOWN";
        pingTextElem.style.color = "#ff2a2a"; 
        addData(500); 
    };

    setTimeout(() => {
        if (!isFinished) {
            isFinished = true;
            img.src = ""; 
            pingTextElem.innerText = "TIMEOUT";
            pingTextElem.style.color = "#ff2a2a";
            addData(500); 
        }
    }, 2000);

    img.src = currentUrl + "/favicon.ico?t=" + Date.now();
}

function addData(ms) {
    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                    now.getMinutes().toString().padStart(2, '0') + ':' + 
                    now.getSeconds().toString().padStart(2, '0');
                    
    pingData.push({ ms: ms, time: timeStr });
    
    if(pingData.length > 20) pingData.shift();
    drawChart();
}

function drawChart() {
    const canvas = document.getElementById("chart");
    if(!canvas) return;
    const ctx = canvas.getContext("2d");
    
    const rect = canvas.parentNode.getBoundingClientRect();
    canvas.width = rect.width * 2;
    canvas.height = rect.height * 2;
    ctx.scale(2, 2);
    
    const w = rect.width;
    const h = rect.height;

    ctx.clearRect(0, 0, w, h);

    const paddingLeft = 30;
    const paddingBottom = 20;
    const chartW = w - paddingLeft - 10; 
    const chartH = h - paddingBottom;

    const maxMs = pingData.length > 0 ? Math.max(...pingData.map(d => d.ms), 60) : 60; 
    
    ctx.strokeStyle = "#333"; 
    ctx.lineWidth = 1;
    ctx.fillStyle = "#666";   
    ctx.font = "10px 'Share Tech Mono', ui-monospace, sans-serif";
    ctx.textAlign = "right";
    ctx.textBaseline = "middle";

    const ySteps = 4; 
    for(let i=0; i<=ySteps; i++){
        let val = Math.round((maxMs / ySteps) * i);
        let y = chartH - (i * (chartH / ySteps));
        y = Math.max(y, 10);

        ctx.beginPath();
        ctx.moveTo(paddingLeft, y);
        ctx.lineTo(w, y);
        ctx.stroke();

        ctx.fillText(val, paddingLeft - 6, y);
    }

    if(pingData.length < 2) return;

    let points = [];
    pingData.forEach((d, i) => {
        let x = paddingLeft + i * (chartW / (pingData.length - 1));
        let y = chartH - (d.ms / maxMs) * (chartH - 10); 
        points.push({x: x, y: Math.max(10, Math.min(y, chartH))});
    });

    let linePath = new Path2D();
    linePath.moveTo(points[0].x, points[0].y);
    
    for (let i = 0; i < points.length - 1; i++) {
        let xMid = (points[i].x + points[i + 1].x) / 2;
        linePath.bezierCurveTo(xMid, points[i].y, xMid, points[i + 1].y, points[i + 1].x, points[i + 1].y);
    }

    let fillPath = new Path2D(linePath);
    fillPath.lineTo(points[points.length - 1].x, chartH); 
    fillPath.lineTo(points[0].x, chartH);                  
    fillPath.closePath();                                  

    const fillGradient = ctx.createLinearGradient(0, 0, 0, chartH);
    fillGradient.addColorStop(0, "rgba(0, 255, 65, 0.15)"); 
    fillGradient.addColorStop(1, "rgba(0, 255, 65, 0.0)");   

    ctx.fillStyle = fillGradient;
    ctx.fill(fillPath);

    const lineGradient = ctx.createLinearGradient(paddingLeft, 0, w, 0);
    lineGradient.addColorStop(0, "#00ff41");   
    lineGradient.addColorStop(0.5, "#00f0ff"); 
    lineGradient.addColorStop(1, "#00ff41");   

    ctx.strokeStyle = lineGradient;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.stroke(linePath);

    ctx.fillStyle = "#666";
    ctx.textBaseline = "top";
    
    ctx.textAlign = "left";
    ctx.fillText(pingData[0].time, paddingLeft, chartH + 5); 
    
    ctx.textAlign = "right";
    ctx.fillText(pingData[pingData.length - 1].time, points[points.length - 1].x, chartH + 5); 
}

setInterval(() => {
    if (document.visibilityState === 'visible') doPing();
}, 2000);

setTimeout(doPing, 1000);
</script>
</body>
</html>
