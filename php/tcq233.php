<?php

ini_set('memory_limit', '512M'); // 提升内存以支持大图 base64 转换
ini_set('post_max_size', '100M');
ini_set('upload_max_filesize', '100M');
date_default_timezone_set('Asia/Shanghai');

// 🔒 核心安全区 - 后端基础密钥配置
$config = [
    // ✨ 默认 API 密钥 (无代理/密钥时默认使用)
    'poixeApiKey'        => getenv('POIXE_API_KEY') ?: 'sk-',
    'imageApiKey'        => 'sk-',
    'enforceWechatOnly'  => false,                        // 是否强制仅允许微信内打开
    'enforceAntihack'    => false,                       // 是否开启域名白名单防盗链防护
    'redirectUrl'        => 'https://cloud.tencent.com/act/cps/redirect?redirect=6544&cps_key=615609c54e8bcced8b02c202a43b5570&from=console',
    'allowedDomains'     => [
        'feeday.cn'                                      // 允许被嵌套引用打开的域名
    ]
];

// ✨ 吐槽墙 CSV 存储初始化
$csvFile = __DIR__ . '/comments.csv';
if (!file_exists($csvFile)) {
    @file_put_contents($csvFile, "IP,Time,Content\n", LOCK_EX);
}

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

    if (!$isAllowed && !$selfAllowed) {
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
 * 🚀 吐槽墙独立 API 模块
 */
if ($apiParam === 'messages_list' || strpos($requestUri, '/api/messages_list') !== false) {
    try {
        if (!file_exists($csvFile)) {
            sendJson(['ok' => true, 'data' => ['items' => []]]);
        }
        $lines = file($csvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) > 0) array_shift($lines); // 移除表头
        
        $last3Lines = array_reverse(array_slice($lines, -3));
        $items = [];
        
        foreach ($last3Lines as $line) {
            if (preg_match('/,"(.*)"$/', $line, $match)) {
                $items[] = ['content' => str_replace('""', '"', $match[1])];
            } else {
                $items[] = ['content' => "解析异常记录"];
            }
        }
        sendJson(['ok' => true, 'data' => ['items' => $items]]);
    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => '读取失败'], 500);
    }
}

if ($apiParam === 'messages_create' || strpos($requestUri, '/api/messages_create') !== false) {
    try {
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $rawContent = isset($rawInput['content']) ? $rawInput['content'] : (isset($_POST['content']) ? $_POST['content'] : '');
        
        if (!trim($rawContent)) {
            sendJson(['ok' => false, 'error' => '内容不能为空']);
        }

        $rawIp = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
        $ip = trim(explode(',', $rawIp)[0]);
        $time = date('Y/m/d H:i:s');
        $cleanContent = str_replace('"', '""', preg_replace('/\r?\n/', ' ', $rawContent));
        
        $csvLine = "{$ip},{$time},\"{$cleanContent}\"\n";
        // 捕获写入结果
        $writeResult = file_put_contents($csvFile, $csvLine, FILE_APPEND | LOCK_EX);
        
        if ($writeResult === false) {
            sendJson(['ok' => false, 'error' => '服务端写入失败，请检查 comments.csv 及所在目录的读写权限！']);
        }
        
        sendJson(['ok' => true]);

    } catch (Exception $e) {
        sendJson(['ok' => false, 'error' => '写入失败'], 500);
    }
}

/**
 * ⚡ 统一 API 转发逻辑 (文本使用代理，图像直连 APIMart)
 */
if ($apiParam !== null && !in_array($apiParam, ['messages_list', 'messages_create'])) {
    $requestHeaders = getallheaders();
    
    $userTextKey = isset($requestHeaders['X-User-Key']) ? $requestHeaders['X-User-Key'] : (isset($requestHeaders['x-user-key']) ? $requestHeaders['x-user-key'] : '');
    $userProxy = isset($requestHeaders['X-Proxy-Url']) ? $requestHeaders['X-Proxy-Url'] : (isset($requestHeaders['x-proxy-url']) ? $requestHeaders['x-proxy-url'] : '');
    
    $finalPoixeKey = $userTextKey ? $userTextKey : $config['poixeApiKey'];
    
    $cleanProxy = rtrim($userProxy, '/');
    $baseProxy = $cleanProxy ? $cleanProxy : 'https://api.apimart.ai';
    
    $bodyRaw = file_get_contents('php://input');

    try {
        if ($apiParam === 'chat' || $apiParam === 'image_generate' || $apiParam === 'image_task') {
            $body = json_decode($bodyRaw, true) ?: [];
            $method = 'POST';
            $postData = json_encode($body);

            // ✨ 生图接口严格剥离：无视自定义代理，强制走 APIMart 官方端点
            if ($apiParam === 'image_generate') {
                $fetchUrl = 'https://api.apimart.ai/v1/images/generations';
            } elseif ($apiParam === 'image_task') {
                $taskId = isset($_GET['task_id']) ? $_GET['task_id'] : '';
                $fetchUrl = 'https://api.apimart.ai/v1/tasks/' . $taskId; 
                $method = 'GET';
                $postData = null; // GET 不带 Body
            } else {
                if (!isset($body['model'])) $body['model'] = 'gemini-3.5-flash';
                $fetchUrl = $baseProxy . '/v1/chat/completions';
            }

            // 👇 新增：鉴权 Key 分流逻辑
            $actualKey = $finalPoixeKey; 
            if ($apiParam === 'image_generate' || $apiParam === 'image_task') {
                // 如果是生图请求，强制覆盖为后端的隐藏 Key
                $actualKey = $config['imageApiKey'];
            }

            $curlRes = performCurl($fetchUrl, $method, [
                'Content-Type: application/json',
                "Authorization: Bearer {$actualKey}" // 👈 这里改为 $actualKey
            ], $postData);
            
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

<style>
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body { font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Helvetica Neue", Helvetica, Arial, sans-serif; margin: 0; padding: 15px 10px; background: #F7F8FA; color: #111; min-height: 100vh; }
.app-container { max-width: 600px; margin: 0 auto; padding-bottom: 20px; }
.card { background: #FFF; border-radius: 16px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03); }
.input-style { width: 100%; padding: 12px 14px; margin-bottom: 12px; border-radius: 8px; border: 2px solid transparent; background: #F0F2F5; font-size: 14px; outline: none; transition: all 0.3s ease; color: #333; }
.input-style:focus { border-color: rgba(7, 193, 96, 0.3); background: #FFF; box-shadow: 0 0 0 3px rgba(7, 193, 96, 0.05); }
textarea.input-style { min-height: 90px; resize: vertical; line-height: 1.8; white-space: pre-wrap; overflow-x: hidden; }
textarea.input-style.is-empty { white-space: pre; overflow-x: auto; }
textarea.input-style.is-empty::placeholder { white-space: pre; }
textarea.input-style::placeholder { color: #888; font-family: ui-monospace, Consolas, monospace; font-size: 12px; line-height: 1.4; }
.button-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(85px, 1fr)); gap: 10px; margin-bottom: 5px; }
.btn { background: #F0F2F5; color: #333; border: none; padding: 10px 0; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; transition: 0.2s; text-align: center; display: inline-flex; justify-content: center; align-items: center; user-select: none; }
.btn:active { background: #E4E6EB; transform: scale(0.98); }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-tucao { background: linear-gradient(45deg, #00c6ff, #0072ff); color: #FFF; box-shadow: 0 2px 8px rgba(0,114,255,0.3); }
.btn-tucao:active { filter: brightness(0.9); }
.btn-danger { background: #FFF0F0; color: #FA5151; }
#response { white-space: pre-wrap; word-break: break-all; font-family: ui-monospace, Consolas, monospace; background: #282C34; color: #98C379; border-radius: 8px; padding: 12px; font-size: 12px; max-height: 400px; overflow-y: auto; margin-top: 10px; text-align: left; }
#responseWrap { display: none; margin-top: 15px; }
#stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px 5px; font-size: 11px; color: #666; margin: 10px 0; padding: 10px 5px; background: #F8F9FA; border-radius: 8px; border: 1px dashed #E5E5E5; text-align: center; }
.ping-container { padding: 15px; background: #FFF; border: 1px dashed #E5E5E5; border-radius: 12px; margin-top: 15px; }
.ping-header { font-size: 13px; font-weight: 600; color: #333; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
.ping-box { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: #F0F2F5; border-radius: 8px; font-size: 14px; cursor: pointer; transition: background 0.2s; }
.ping-box:active { background: #E4E6EB; }
.ping-site { font-weight: bold; color: #111; }
.ping-ms { color: #07C160; font-family: ui-monospace, Consolas, monospace; font-weight: bold; }
.ping-desc { font-size: 11px; color: #888; margin-top: 12px; }
.ping-chart-wrap { width: 100%; height: 125px; margin-top: 15px; position: relative; background: #f2f8fc; border-radius: 8px; padding: 10px 0; }
canvas#chart { width: 100%; height: 100%; display: block; }
.ping-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: flex-end; opacity: 0; transition: opacity 0.3s ease; }
.ping-modal.show { opacity: 1; display: flex; }
.ping-sheet { width: 100%; max-width: 600px; background: #F7F8FA; border-radius: 16px 16px 0 0; overflow: hidden; transform: translateY(100%); transition: transform 0.3s ease; }
.ping-modal.show .ping-sheet { transform: translateY(0); }
.ping-sheet .item { padding: 16px; text-align: center; background: #FFF; border-bottom: 1px solid #F0F2F5; font-size: 15px; color: #333; cursor: pointer; }
.ping-sheet .item:active { background: #F0F2F5; }
.ping-sheet .item.cancel { margin-top: 8px; color: #FA5151; font-weight: bold; border-bottom: none; }
.footer-banner { margin-top: 20px; text-align: center; border-radius: 12px; overflow: hidden; }
.footer-banner img { width: 100%; height: auto; display: block; border-radius: 12px; }
audio { outline: none; }
  
  @media screen and (max-width: 768px) {
  #btnHtmlPreview, #btnDecodeQR, #btnImportMemory, #btnExportMemory, #btnClearMemory, #btnExtractLinks { display: none !important; }
  

/* 生图扩展区样式 */
#imageOptionsWrapper { display: none; margin-top: 10px; background: #F8F9FA; padding: 10px; border-radius: 8px; border: 1px dashed #E5E5E5; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  
  
  /* ================= 顶部搜索框特有样式 ================= */
.search-card { padding: 12px 15px; display: flex; align-items: center; justify-content: center; }
.search-wrapper { display: flex; align-items: center; width: 100%; position: relative; }
.custom-select { position: relative; display: flex; align-items: center; cursor: pointer; user-select: none; }
.select-trigger { display: flex; align-items: center; font-size: 14px; font-weight: bold; color: #333; }
.arrow-icon { width: 16px; height: 16px; fill: currentColor; margin-left: 2px; transition: transform 0.2s; }
.custom-select.open .arrow-icon { transform: rotate(180deg); }
.select-options { display: none; position: absolute; top: 100%; left: -5px; background: #FFF; border-radius: 8px; box-shadow: 0 4px 16px rgba(0,0,0,0.1); z-index: 1000; margin-top: 12px; overflow: hidden; min-width: 90px; border: 1px solid #E5E5E5; }
.custom-select.open .select-options { display: block; }
.option-item { padding: 10px 15px; font-size: 14px; color: #333; transition: background 0.2s; cursor: pointer; text-align: center; }
.option-item:active { background: #F0F2F5; }
@media (hover: hover) { .option-item:hover { background: #F0F2F5; } }
.option-item.selected { color: #0072ff; font-weight: bold; background: #f2f8fc; }
.search-inner-divider { width: 1px; height: 18px; background: #E5E5E5; margin: 0 12px; }
.search-input { flex: 1; border: none; outline: none; font-size: 14px; background: transparent; color: #333; min-width: 0; }
.search-input::placeholder { color: #888; font-family: inherit; font-size: 14px; }
.search-clear { font-size: 16px; color: #bbb; cursor: pointer; padding: 0 8px; display: none; user-select: none; }
.search-clear:active { color: #333; }
.external-search-btn { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; background: #F0F2F5; border-radius: 8px; cursor: pointer; color: #333; transition: 0.2s; margin-left: 5px; }
.external-search-btn:active { background: #E4E6EB; transform: scale(0.95); }
.external-search-btn svg { width: 18px; height: 18px; }
</style>
</head>
<body>
<div class="app-container">

  
  
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
    <textarea id="universalText" class="input-style is-empty" placeholder="🚀 获取最新留言中..."></textarea>
  </div>

  <div class="card">
   <div class="button-grid" style="margin-top: 15px; grid-template-columns: 1fr 1fr 1fr;">
     <button class="btn" id="btnClearOutput" title="复制文本并清空输出(不清空记忆)">📋 复制并重置</button>
     <button class="btn" id="btnAiAction">✨ 润色文本</button> 
     <button class="btn btn-tucao" id="btnCombinedAction">🚀 我要吐槽</button>  
      <button class="btn" id="btnExtractPoints">提炼要点</button>
      <button class="btn" id="btnExtractLinks">提链去重行</button>
      <button class="btn" id="btnHtmlPreview">网页预览</button>
      <button class="btn" id="btnFrames">视频抽帧+音频</button>
      <button class="btn" id="optimize-btn">自然排版</button>
      <button class="btn" id="btnDecodeQR">识别二维码</button>
      
      <button class="btn" id="btnImportMemory">📁 导入记忆</button>
      <button class="btn" id="btnExportMemory">💾 导出记忆</button>
      <button class="btn" id="btnClearMemory" style="color: #FA5151;">🗑️ 清空记忆</button>
      <input type="file" id="memoryFileInput" accept=".json" style="display: none;" />
    </div>

    <div id="responseWrap">
      <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:5px;">
        <span class="sub-text" id="respStatus" style="font-size:12px; color:#666;">等待处理中...</span>
        <button class="btn" id="btnHideResp" style="padding: 2px 8px; font-size:11px;">收起日志</button>
      </div>
      <div id="response"></div>
    </div>
  </div>

  <div class="card output-area" id="viewportCard">
    <div class="ping-box" onclick="openPingModal()">
            <div class="ping-site" id="siteName">百度</div>
            <div class="ping-ms" id="pingText">检测中...</div>
            <div style="color: #0072ff; font-size: 12px;">更换节点 ⌄</div>
        </div>
        <div class="ping-desc" style="display: flex; justify-content: space-between; align-items: center;">
            <span>您访问 <span id="descSite" style="color:#333; font-weight:bold;">百度</span> 的链路时延：</span>
            <span id="performance-result" style="background: #F0F2F5; padding: 2px 6px; border-radius: 4px; font-family: ui-monospace, Consolas, monospace; font-size: 10px; color: #666;">系统载入中...</span>
        </div>
        <div class="ping-chart-wrap">
            <canvas id="chart"></canvas>
        </div>

    <div id="imageDisplayContainer" style="display:none; flex-direction:column; align-items:center; margin-top:15px; width: 100%;">

      
      
      <div id="originalImgWrapper" style="width: 100%; text-align: center; margin-bottom: 15px;">
        <img id="displayedImage" src="" style="max-width:100%; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.1);" />
      </div>
      
<div id="imageTools" style="margin-top:10px; display:grid; grid-template-columns:1fr 1fr; gap:10px; width:100%;">
    <button class="btn" id="btnInvertColor">反色</button>
    <button class="btn" id="btnGrayscale">去色还原</button>
</div>
      
      
<div id="imageMetadataPreview" class="sub-text" style="margin-top:8px; font-size:11px; color:#888; max-width: 100%; word-break: break-all;"></div>
      
      
      <div id="imageTimeLabel" class="sub-text"></div>
      <div id="framePager" class="sub-text" style="display:none; font-family: monospace; margin-top: 5px; color: #666;"></div>
      <div class="button-grid" id="frameControls" style="display:none; margin-top: 15px; width:100%;">
        <button class="btn" id="prevButton">← 上一帧</button>
        <button class="btn" id="nextButton">下一帧 →</button>
      </div>
    </div>
    
    <div id="mediaPreview" style="margin-top:15px; display:flex; flex-direction:column; align-items:center; width:100%;"></div>
  </div>

  <div class="footer-banner">
    
    <div id="imageOptionsWrapper">
        <div class="grid-2">
            <select id="imgResolution" class="input-style" style="margin-bottom:0;">
                <option value="1k">1k</option>
                <option value="2k">2k</option>
                <option value="4k">4k</option>
            </select>
            <select id="imgSize" class="input-style" style="margin-bottom:0;">
                <option value="1:1">1:1 (正方)</option>
                <option value="3:2">3:2 (横图)</option>
                <option value="2:3">2:3 (竖图)</option>
                <option value="4:3">4:3 (横图)</option>
                <option value="3:4">3:4 (竖图)</option>
                <option value="16:9">16:9 (横图)</option>
                <option value="9:16">9:16 (竖图)</option>
                <option value="21:9">21:9 (宽屏)</option>
            </select>
        </div>
    </div>
    
    <input type="text" id="apiProxy" class="input-style" placeholder="🌐 自定义 API URL" />
    <input type="password" id="apiKey" class="input-style" placeholder="🔑 API Key" />
    
    <select id="unifiedModel" class="input-style" onchange="onModelChange()">
        <optgroup label="🚀 留言吐槽栏">
            <option value="comment-wall" selected>吐槽留言功能</option>
        </optgroup>
        <optgroup label="💬 文本对话">
            <option value="gemini-3.1-pro-preview">Gemini-3.1-pro-preview</option>
            <option value="claude-opus-4-8">Claude-opus-4-8</option>
            <option value="deepseek-v4-flash">DeepSeek-V4-flash</option>
  <option value="gpt4o-reverse-prompt">
        GPT4o-反推提示词
    </option>
        </optgroup>
            <optgroup label="🎨 图像生成">
            <option value="gpt-image-2">GPT-Image-2</option>
        </optgroup>
    </select>
    <a href="#">
    <img src="https://cpuck.com/ip/" title="吐槽墙" alt="吐槽墙" style="width: 100%; height: auto; display: block; border-radius: 8px;" />
  </a>
  </div>
</div>

<div class="ping-modal" id="pingModal" onclick="closePingModal(event)">
    <div class="ping-sheet" id="pingSheet">
        <div class="item" onclick="changeSite('https://www.baidu.com','baidu.com')">百度</div>
        <div class="item" onclick="changeSite('https://weibo.com','weibo.com')">微博</div>
        <div class="item" onclick="changeSite('https://www.douyin.com','douyin.com')">抖音</div>
        <div class="item cancel" onclick="closePingModal()">取消</div>
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
const mediaPreview = document.getElementById("mediaPreview");
const btnCombinedAction = document.getElementById("btnCombinedAction"); 
const btnAiAction = document.getElementById("btnAiAction");
const frameControls = document.getElementById("frameControls");
const framePager = document.getElementById("framePager");
const prevButton = document.getElementById("prevButton");
const nextButton = document.getElementById("nextButton");

// 文本模型列表更新
const TEXT_MODELS = ['gemini-3.5-flash','claude-opus-4-8','gpt-5.5-2026-04-23', 'gemini-3.1-pro-preview','gpt4o-reverse-prompt','deepseek-v4-flash'];

// ✨ 存储多轮对话记忆上下文
let chatContext = [];

window.addEventListener('load', () => {
    const savedKey = localStorage.getItem('suishouji_api_key') || localStorage.getItem('pixel_api_key');
    if (savedKey) document.getElementById('apiKey').value = savedKey;

    const savedProxy = localStorage.getItem('suishouji_api_proxy') || localStorage.getItem('pixel_api_proxy');
    if (savedProxy) document.getElementById('apiProxy').value = savedProxy;

    const loadTime = performance.now();
    const perfElem = document.getElementById('performance-result');
    if(perfElem) {
        perfElem.innerHTML = "⏱️ 页面加载耗时: <span style=\"color:#07C160; font-weight:bold;\">" + Math.round(loadTime) + "ms</span>";
    }

    checkEmptyState();
    fetchCommentsForPlaceholder(); 
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

// ✨ 本地留言功能缓存
async function fetchCommentsForPlaceholder() {
    try {
        const cached = localStorage.getItem('comment_wall_cache');
        if (cached) {
            try {
                const items = JSON.parse(cached);
                applyCommentsToPlaceholder(items);
            } catch(e) {}
        }
        
        const res = await fetch('?api=messages_list&_t=' + new Date().getTime());
        const json = await res.json();
        
        if (json.ok && json.data.items) {
            localStorage.setItem('comment_wall_cache', JSON.stringify(json.data.items));
            applyCommentsToPlaceholder(json.data.items);
        } else if (!cached) {
            universalText.placeholder = "在此输入你想说的话...";
        }
    } catch (err) { 
        if (!localStorage.getItem('comment_wall_cache')) {
            universalText.placeholder = "获取失败，在此输入文本..."; 
        }
    }
}

function applyCommentsToPlaceholder(items) {
    let placeholderText = "🚀 在此输入你想说的话\n";
    items.forEach(item => { 
        placeholderText += "吐槽墙 : " + item.content + "\n"; 
    });
    universalText.placeholder = placeholderText;
}

let images = [];
let currentImageIndex = -1;
let originalImgSrc = "";    

const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

function getApiKey() {
    const key = document.getElementById('apiKey').value.trim();
    if (key) localStorage.setItem('suishouji_api_key', key);
    else localStorage.removeItem('suishouji_api_key');
    return key;
}

function getApiProxy() {
    const proxy = document.getElementById('apiProxy').value.trim();
    if (proxy) localStorage.setItem('suishouji_api_proxy', proxy);
    else localStorage.removeItem('suishouji_api_proxy');
    return proxy;
}

function checkKeyRequirement(model) {
    if (model === 'comment-wall') return true; 
    const key = getApiKey();
    if (!key) return true;
    return true;
}

function onModelChange() {
    const model = document.getElementById('unifiedModel').value;
    const apiKeyInput = document.getElementById('apiKey');
    const proxyInput = document.getElementById('apiProxy');
    const imageOptionsWrapper = document.getElementById('imageOptionsWrapper');
    const isImageModel = model === 'gpt-image-2';

    imageOptionsWrapper.style.display = isImageModel ? 'block' : 'none';

    if (model === "comment-wall") {
        apiKeyInput.style.display = "none";
        proxyInput.style.display = "none";
        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = "🚀 我要吐槽";
            btnCombinedAction.className = "btn btn-tucao";
        }
    } else if (isImageModel) {
        apiKeyInput.style.display = "none";
        proxyInput.style.display = "none";
        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = "🎨 生成图像";
            btnCombinedAction.className = "btn btn-tucao";
        }
} else if (model === "gpt4o-reverse-prompt") {
        // 👇 新增：反推模型专属按钮状态
        apiKeyInput.style.display = "block";
        proxyInput.style.display = "block";
        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = "🖼️ 图像反推";
            btnCombinedAction.className = "btn btn-tucao";
        }
    } else if (TEXT_MODELS.includes(model)) {
        // 其他普通的文本对话模型
        apiKeyInput.style.display = "block";
        proxyInput.style.display = "block";
        if(btnCombinedAction) {
            btnCombinedAction.innerHTML = "💬 AI 对话";
            btnCombinedAction.className = "btn btn-tucao";
        }
    }
}

function copyToClipboard(text, btnElement) {
    function copySuccess() {
        if(!btnElement) return;
        const old = btnElement.innerHTML;
        const oldBg = btnElement.style.background;
        const oldColor = btnElement.style.color;
        btnElement.innerHTML = "✓ 复制成功";
        btnElement.style.background = "#07C160"; 
        btnElement.style.color = "#FFF";
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
        alert("⚠️ 视频文件过大！最大仅支持操作 100MB 以内的视频。");
        e.target.value = ""; return;
    }

    const url = URL.createObjectURL(file);
    const mime = file.type;

    imageDisplayContainer.style.display = "none";
    mediaPreview.innerHTML = "";
    displayedImage.removeAttribute("title");

    images = []; currentImageIndex = -1;
    if(frameControls) frameControls.style.display = "none";
    if(framePager) framePager.style.display = "none";

if (mime.startsWith("image/")) {
        originalImgSrc = url; 
        displayedImage.src = url;
        displayedImage.style.display = "block";
        displayedImage.style.cursor = "default";
        imageDisplayContainer.style.display = "flex";

        // 👇👇👇 新增：自动切换到图像反推模型 👇👇👇
        const modelSelect = document.getElementById('unifiedModel');
        if (modelSelect) {
            modelSelect.value = "gpt4o-reverse-prompt"; // 自动选中反推模型
            onModelChange(); // 刷新 UI 状态，确保按钮文字和输入框正确显示
        }
        // 👆👆👆 新增结束 👆👆👆

    } else {
        originalImgSrc = "";
        
        if (mime.startsWith("audio/")) {
            const el = document.createElement('audio'); el.controls = true; el.src = url;
            mediaPreview.appendChild(el);
        } else if (mime.startsWith("video/")) {
            const logBox = document.createElement('div'); logBox.className = "sub-text";
            logBox.innerHTML = "视频已加载，请在上方输入帧率并点击抽帧";
            mediaPreview.appendChild(logBox);
        }
    }
});

async function fetchAndParseJson(url, options) {
    const res = await fetch(url, options);
    const rawText = await res.text();
    let data;
    try {
        data = JSON.parse(rawText);
    } catch (e) {
        if(rawText.startsWith('data: ')) {
            throw new Error("接口强制返回了流式数据 (Stream)，无法自动解析。\n\n原始数据截取:\n" + rawText.substring(0, 300) + "...");
        }
        throw new Error("接口返回的格式不是有效 JSON:\n\n" + rawText.substring(0, 300) + "...");
    }
    if (!res.ok) {
        throw new Error(data.error?.message || "HTTP " + res.status + " - " + JSON.stringify(data));
    }
    return data;
}

if(btnCombinedAction) {
    btnCombinedAction.onclick = async () => {
        const content = universalText.value.trim();
        const model = document.getElementById('unifiedModel').value;
        
        if (!checkKeyRequirement(model)) return;

        // 1. 吐槽墙逻辑
        if (model === "comment-wall") {
            if (!content) return alert("请在文本框输入要吐槽的内容！");
            
            const oldText = btnCombinedAction.innerHTML;
            btnCombinedAction.innerHTML = "发送中..."; 
            btnCombinedAction.disabled = true;

            try {
                const res = await fetch('?api=messages_create', { 
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: content })
                });
                const json = await res.json();
                if (json.ok) {
                    universalText.value = ''; 
                    updateStats(); checkEmptyState(); fetchCommentsForPlaceholder(); 
                    btnCombinedAction.innerHTML = "✓ 发送成功"; 
                    btnCombinedAction.style.background = "#07C160"; btnCombinedAction.style.color = "#FFF";
                    setTimeout(() => { 
                        onModelChange();
                        btnCombinedAction.style.background = ""; btnCombinedAction.style.color = "";
                    }, 2000);
                } else { alert('发送失败: ' + (json.error || '未知错误')); onModelChange(); }
            } catch (err) { alert('网络异常'); onModelChange(); } finally { btnCombinedAction.disabled = false; }
            return;
        }

        const apiKey = getApiKey();
        const apiProxy = getApiProxy();

        // 2. 图像生成逻辑 (纯文生图 + 官方任务轮询 API)
        if (model === "gpt-image-2") {
            if (!content) return alert("请输入生图提示词！");
            
            const oldText = btnCombinedAction.innerHTML;
            btnCombinedAction.disabled = true;
            responseWrap.style.display = "block";

            btnCombinedAction.innerHTML = "🎨 提交绘图...";
            respStatus.textContent = "正在连接云端画棚...";
            respStatus.style.color = "#666";
            response.textContent = "⏳ 等待模型响应...\n";

            try {
                // 构建仅限文生图的请求体
                const payload = {
                    model: 'gpt-image-2',
                    prompt: content,
                    n: 1,
                    resolution: document.getElementById('imgResolution').value,
                    size: document.getElementById('imgSize').value,
                    official_fallback: true
                };

                const data = await fetchAndParseJson("?api=image_generate", {
                    method: 'POST',
                    headers: { 'X-User-Key': apiKey, 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                if (data.data && data.data.length > 0) {
                    const imgItem = data.data[0];
                    let taskId = imgItem.task_id || imgItem.id;
                    
                    if (imgItem.url) {
                        respStatus.textContent = "✅ 生图完成"; 
                        respStatus.style.color = "#07C160";
                        response.innerHTML = "✅ 生成成功！\n<br><img src='" + imgItem.url + "' style='max-width:100%; border-radius:8px; margin-top:10px;'/>\n<br><a href='"+ imgItem.url +"' target='_blank' style='color:#0072ff; text-decoration:none;'>[点击查看原图]</a>";
                        btnCombinedAction.innerHTML = oldText; 
                        btnCombinedAction.disabled = false;
                        
                    } else if (taskId) {
                        respStatus.textContent = "✅ 已接单，画师正在作画中..."; 
                        respStatus.style.color = "#d9a23a";
                        response.innerHTML = "⏳ <b>云端已开始绘制，自动监听进度中...</b><br>🎫 任务号: <code>" + taskId + "</code><br><i>请勿刷新页面，系统正在自动为您追踪...</i>";
                        
                        let attempts = 0;
                        const maxAttempts = 40;
                        const pollInterval = setInterval(async () => {
                            attempts++;
                            if (attempts > maxAttempts) { 
                                clearInterval(pollInterval);
                                respStatus.textContent = "❌ 等待超时"; respStatus.style.color = "#FA5151";
                                response.innerHTML += "<br><br>⚠️ 追踪超时。画图仍在后台进行，您可以稍后带任务号去平台后台查看。";
                                btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
                                return;
                            }
                            
                            try {
                                const pollData = await fetchAndParseJson(`?api=image_task&task_id=${taskId}`, {
                                    method: 'GET',
                                    headers: { 'X-User-Key': apiKey }
                                });
                                
                                let taskObj = pollData;
                                if (pollData.data) {
                                    taskObj = Array.isArray(pollData.data) ? pollData.data[0] : pollData.data;
                                }
                                
                                const status = (taskObj.status || "").toLowerCase();
                                const progress = taskObj.progress || 0;
                                
                                let finalUrl = "";
                                if (typeof taskObj.url === 'string') {
                                    finalUrl = taskObj.url;
                                } else if (typeof taskObj.image_url === 'string') {
                                    finalUrl = taskObj.image_url;
                                } else if (taskObj.result) {
                                    if (typeof taskObj.result === 'string' && taskObj.result.startsWith('http')) {
                                        finalUrl = taskObj.result;
                                    } else if (taskObj.result.images && taskObj.result.images.length > 0) {
                                        const imgData = taskObj.result.images[0];
                                        if (Array.isArray(imgData.url)) {
                                            finalUrl = imgData.url[0];
                                        } else if (typeof imgData.url === 'string') {
                                            finalUrl = imgData.url;
                                        }
                                    } else if (taskObj.result.url) {
                                        finalUrl = Array.isArray(taskObj.result.url) ? taskObj.result.url[0] : taskObj.result.url;
                                    } else if (taskObj.result.image_url) {
                                        finalUrl = taskObj.result.image_url;
                                    }
                                }

                                const isDone = status === "succeeded" || status === "success" || status === "finished" || status === "completed" || (!status && finalUrl);
                                const isFailed = status === "failed" || status === "error";

                                if (isDone) {
                                    clearInterval(pollInterval);
                                    if (finalUrl) {
                                        respStatus.textContent = "✅ 生图完成"; respStatus.style.color = "#07C160";
                                        response.innerHTML = "✅ 自动追踪成功，图片已送达！\n<br><img src='" + finalUrl + "' style='max-width:100%; border-radius:8px; margin-top:10px;'/>\n<br><a href='"+ finalUrl +"' target='_blank' style='color:#0072ff; text-decoration:none;'>[点击查看原图]</a>";
                                    } else {
                                        respStatus.textContent = "⚠️ 状态完成但未抓取到图片"; respStatus.style.color = "#d9a23a";
                                        response.innerHTML = "任务完成，但无法解析图片链接。原始数据：<br><pre style='white-space:pre-wrap;font-size:11px;'>" + JSON.stringify(pollData, null, 2) + "</pre>";
                                    }
                                    btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
                                    
                                } else if (isFailed) {
                                    clearInterval(pollInterval);
                                    respStatus.textContent = "❌ 云端绘制失败"; respStatus.style.color = "#FA5151";
                                    response.innerHTML += "<br><br>❌ 失败原因:<br><pre style='white-space:pre-wrap;font-size:11px;'>" + JSON.stringify(pollData, null, 2) + "</pre>";
                                    btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
                                } else {
                                    respStatus.textContent = `⏳ 作画中 [${status || '排队处理'}]... 第 ${attempts} 次追踪`;
                                }
                            } catch (err) {
                                console.log("轮询异常跳过:", err);
                            }
                        }, 3000); 
                        
                    } else {
                        respStatus.textContent = "⚠️ 格式未知";
                        response.textContent = "未知的返回结构:\n" + JSON.stringify(data, null, 2);
                        btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
                    }
                } else {
                    throw new Error("返回数据缺少有效内容:\n" + JSON.stringify(data, null, 2));
                }
            } catch (e) {
                respStatus.textContent = "❌ 请求异常"; 
                respStatus.style.color = "#FA5151";
                response.textContent = "错误: " + e.message;
                btnCombinedAction.innerHTML = oldText; 
                btnCombinedAction.disabled = false;
            }
            return;
        }

        // 3. 文本对话逻辑
// 3. 文本对话逻辑
        if (TEXT_MODELS.includes(model)) {
            // 👇 修改：如果不是反推模型，才拦截空文本
            if (!content && model !== "gpt4o-reverse-prompt") {
                return alert("请输入对话内容！");
            }
            
            const oldText = btnCombinedAction.innerHTML;
            btnCombinedAction.disabled = true;
            responseWrap.style.display = "block";

            btnCombinedAction.innerHTML = "⏳ 思考中...";
            respStatus.textContent = "正在处理对话...";
            respStatus.style.color = "#666";
            response.textContent = "⏳ 等待模型响应...\n";
            
            // 加入上下文记忆
            chatContext.push({ role: "user", content: content });

            try {

                let messagesPayload = [];

                // ======================================
                // GPT4o 图像反推 Prompt
                // ======================================
                if (model === "gpt4o-reverse-prompt") {
                    const file = universalFile.files[0];

                    if (!file) {
                        alert("请先上传图片！");
                        btnCombinedAction.disabled = false;
                        btnCombinedAction.innerHTML = oldText;
                        chatContext.pop();
                        return;
                    }

                    // 转 Base64
                    const toBase64 = (file) => new Promise((resolve, reject) => {
                        const reader = new FileReader();
                        reader.readAsDataURL(file);
                        reader.onload = () => resolve(reader.result);
                        reader.onerror = error => reject(error);
                    });

                    respStatus.textContent = "🖼️ 正在分析图片...";
                    respStatus.style.color = "#666";

                    const base64Image = await toBase64(file);

                    // 超强反推 Prompt
                    const reversePrompt = `
You are an expert visual describer and professional prompt engineer.
Write a beautifully flowing, highly detailed natural language paragraph describing this image. 

CRITICAL INSTRUCTIONS:
- Use complete, grammatically correct sentences. 
- ABSOLUTELY DO NOT use comma-separated tags, bullet points, or isolated keywords.
- Write EXACTLY ONE cohesive paragraph.

Your description should seamlessly weave together:
1. The main subject (age, appearance, expression, clothing).
2. The action and detailed environment.
3. The mood, lighting (e.g., soft cinematic lighting), and photography style/camera settings (e.g., captured with a prime lens, shallow depth of field).

Example of the requested style: "A young boy with short dark hair and a playful expression stands confidently in a brightly lit indoor gym. He is wearing a vivid blue t-shirt made of soft cotton..."

Output the paragraph in English ONLY.
`;

                    messagesPayload = [
                        {
                            role: "user",
                            content: [
                                { type: "text", text: reversePrompt },
                                { type: "image_url", image_url: { url: base64Image } }
                            ]
                        }
                    ];

                } else {
                    // 原有聊天上下文
                    messagesPayload = chatContext;
                }

                const requestPayload = { 
                    model: model === "gpt4o-reverse-prompt" ? "gpt-4o" : model,
                    messages: messagesPayload,
                    stream: false,   
                    max_tokens: 4096 
                };

                const startTime = Date.now();
                const data = await fetchAndParseJson("?api=chat", {
                    method: 'POST',
                    headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                    body: JSON.stringify(requestPayload)
                });

                const duration = ((Date.now() - startTime) / 1000).toFixed(2);
                const tokens = data.usage?.total_tokens || "未知";
                const aiReply = data.choices?.[0]?.message?.content || "无有效返回内容";

                // 记录助手回复
                chatContext.push({ role: "assistant", content: aiReply });

                respStatus.textContent = "✅ 对话完成"; respStatus.style.color = "#07C160";
                
                const logHeader = "📊 性能: ⏱️ " + duration + "s  |  🪙 " + tokens + " Tokens  |  🕒 " + getCurrentTimeStr() + "\n";
                const divider = "========================================\n";
                const aiText = "💬 模型回复 (纯文本预览):\n\n" + aiReply + "\n\n";
                const jsonStr = "⚙️ 原始完整报文 (JSON):\n\n" + JSON.stringify(data, null, 2);

                response.textContent = logHeader + divider + aiText + divider + jsonStr;                   
                
                universalText.value = aiReply;
                updateStats(); checkEmptyState();
            } catch (e) {
                chatContext.pop(); 
                respStatus.textContent = "❌ 请求异常"; respStatus.style.color = "#FA5151";
                response.textContent = "错误:\n" + e.message;
            }
            btnCombinedAction.innerHTML = oldText; btnCombinedAction.disabled = false;
            return;
        }
    };
}

// 润色文本
if(btnAiAction) {
    btnAiAction.onclick = async () => {
        const content = universalText.value.trim();
        if (!content) return alert("请在文本框输入您的提示词或润色要求！");
        
        const model = document.getElementById('unifiedModel').value;
        if (model === "comment-wall" || model === "gpt-image-2") return alert("润色功能仅支持选择有效的文本对话模型！");
        if (!checkKeyRequirement(model)) return;

        const apiKey = getApiKey();
        const apiProxy = getApiProxy();

        const oldText = btnAiAction.innerHTML; 
        btnAiAction.disabled = true; 
        responseWrap.style.display = "block";

        btnAiAction.innerHTML = "⏳ 润色中..."; 
        respStatus.textContent = "AI 深度思考中...";
        response.textContent = "🚀 正在请求 " + model + " 进行润色...\n";

        try {
            const startTime = Date.now();
            const data = await fetchAndParseJson('?api=chat', {
                method: 'POST',
                headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: model,
                    messages: [
                        {role: "user", content: "你是一位文字精炼大师。请对以下文本进行润色，要求：1. 保持原意不变；2. 优化逻辑结构；3. 增强文采。直接输出润色结果，无需任何解释废话。\n\n待润色文本：\n" + content}
                    ], 
                    temperature: 0.7,
                    max_tokens: 4096,
                    stream: false   
                })
            });

            const duration = ((Date.now() - startTime) / 1000).toFixed(2);
            const tokens = data.usage?.total_tokens || "未知";

            if(data.choices && data.choices[0].message) {
                const aiReply = data.choices[0].message.content.trim();
                universalText.value = aiReply;
                updateStats(); checkEmptyState(); 
                
                respStatus.textContent = "✨ 润色已完成"; respStatus.style.color = "#07C160";
                
                const logHeader = "📊 性能: ⏱️ " + duration + "s  |  🪙 " + tokens + " Tokens  |  🕒 " + getCurrentTimeStr() + "\n";
                const divider = "========================================\n";
                const aiText = "💬 模型润色结果:\n\n" + aiReply + "\n\n";
                const jsonStr = "⚙️ 原始完整报文 (JSON):\n\n" + JSON.stringify(data, null, 2);
                response.textContent = logHeader + divider + aiText + divider + jsonStr;
            } else { 
                throw new Error("返回数据结构异常:\n" + JSON.stringify(data, null, 2)); 
            }
        } catch(e) {
            respStatus.textContent = "❌ 润色失败"; respStatus.style.color = "#FA5151";
            response.textContent = "错误原因:\n" + e.message;
        }
        btnAiAction.innerHTML = oldText; btnAiAction.disabled = false;
    };
}

// 提炼要点
bindEvent("btnExtractPoints", "click", async () => {
    const content = universalText.value.trim();
    if (!content) return alert("请在文本框输入需要提炼的文本内容！");
    
    const model = document.getElementById('unifiedModel').value;
    if (model === "comment-wall" || model === "gpt-image-2") return alert("提炼功能仅支持选择有效的文本对话模型！");
    if (!checkKeyRequirement(model)) return;

    const apiKey = getApiKey();
    const apiProxy = getApiProxy();

    const btn = document.getElementById("btnExtractPoints"); 
    const oldText = btn.innerHTML;
    
    btn.innerHTML = "⏳ 提炼中..."; 
    btn.disabled = true; 
    responseWrap.style.display = "block";
    respStatus.textContent = "AI 深度思考中..."; respStatus.style.color = "#666";
    response.textContent = "🚀 正在请求 " + model + " 提炼核心要点...\n";

    try {
        const startTime = Date.now();
        const data = await fetchAndParseJson('?api=chat', {
            method: 'POST',
            headers: { 'X-User-Key': apiKey, 'X-Proxy-Url': apiProxy, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: model,
                messages: [
                    {role: "user", content: "你是一个专业的文本分析助手。请准确、简明地提炼以下文本的核心要点，使用结构化的列表形式输出，剥离冗余信息。\n\n待提炼文本：\n" + content}
                ], 
                temperature: 0.3,
                max_tokens: 4096,
                stream: false   
            })
        });

        const duration = ((Date.now() - startTime) / 1000).toFixed(2);
        const tokens = data.usage?.total_tokens || "未知";

        if(data.choices && data.choices[0].message) {
            const aiReply = data.choices[0].message.content.trim();
            universalText.value = aiReply;
            updateStats(); checkEmptyState(); 
            
            respStatus.textContent = "✨ 提炼已完成"; respStatus.style.color = "#07C160";
            
            const logHeader = "📊 性能: ⏱️ " + duration + "s  |  🪙 " + tokens + " Tokens  |  🕒 " + getCurrentTimeStr() + "\n";
            const divider = "========================================\n";
            const aiText = "💬 模型提炼结果:\n\n" + aiReply + "\n\n";
            const jsonStr = "⚙️ 原始完整报文 (JSON):\n\n" + JSON.stringify(data, null, 2);
            response.textContent = logHeader + divider + aiText + divider + jsonStr;
        } else { 
            throw new Error("返回数据结构异常:\n" + JSON.stringify(data, null, 2)); 
        }
    } catch(e) {
        respStatus.textContent = "❌ 提炼失败"; respStatus.style.color = "#FA5151";
        response.textContent = "错误原因:\n" + e.message;
    } finally { 
        btn.innerHTML = oldText; btn.disabled = false; 
    }
});

bindEvent("btnExtractLinks", "click", () => {
    const text = universalText.value; if(!text.trim()) return alert("请在文本框输入要处理的内容！");
    responseWrap.style.display = "block"; respStatus.textContent = "处理中..."; respStatus.style.color = "#666";
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
            response.textContent = "✅ 成功提取并去重，共得到 " + results.size + " 个有效链接！";
        } else {
            const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
            results = new Set(lines); 
            universalText.value = Array.from(results).join('\n');
            response.textContent = "⚠️ 未提取到特定链接，已对当前文本【按行去重】，共保留 " + results.size + " 行文本。";
        }
        updateStats(); checkEmptyState(); respStatus.textContent = "处理成功"; respStatus.style.color = "#07C160";
    } catch (e) { response.textContent = "提取出错: " + e.message; respStatus.textContent = "异常"; respStatus.style.color = "#FA5151"; }
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
        images = []; currentImageIndex = -1;
        originalImgSrc = ""; 
        // ⚠️ 修改：不再清空对话记忆 chatContext = [];
        
        onModelChange();
        displayedImage.src = ""; displayedImage.style.cursor = "default";
        displayedImage.removeAttribute("title");
       
        imageDisplayContainer.style.display = "none"; mediaPreview.innerHTML = "";
        if(frameControls) frameControls.style.display = "none";
        if(framePager) { framePager.style.display = "none"; framePager.textContent = ""; }

        updateStats(); checkEmptyState(); fetchCommentsForPlaceholder(); 
        
        const timeLabel = document.getElementById("imageTimeLabel");
        if(timeLabel) { timeLabel.innerHTML = ""; timeLabel.style.display = "none"; }
        
        const btn = document.getElementById("btnClearOutput");
        if(btn) {
            btn.textContent = isCopied ? "✓ 复制并重置" : "✓ 已重置"; 
            setTimeout(() => { btn.textContent = "📋 复制并重置"; }, 1500);
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

// ✨ 记忆导出功能
bindEvent("btnExportMemory", "click", () => {
    if (chatContext.length === 0) {
        alert("当前对话记忆为空，无需导出。");
        return;
    }
    const dataStr = JSON.stringify(chatContext, null, 2);
    const blob = new Blob([dataStr], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `chat_memory_${Date.now()}.json`;
    a.click();
    URL.revokeObjectURL(url);
});

// ✨ 记忆导入功能触发
bindEvent("btnImportMemory", "click", () => {
    document.getElementById("memoryFileInput").click();
});

// ✨ 处理选择记忆文件
document.getElementById("memoryFileInput").addEventListener("change", (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(event) {
        try {
            const importedData = JSON.parse(event.target.result);
            if (Array.isArray(importedData)) {
                chatContext = importedData;
                alert(`✅ 记忆导入成功！共加载了 ${importedData.length} 条对话上下文。`);
                
                responseWrap.style.display = "block";
                respStatus.textContent = "导入完成";
                respStatus.style.color = "#07C160";
                response.textContent = "📥 记忆已成功挂载入内存。接下来的对话 AI 将参考此上下文进行回复。";
            } else {
                throw new Error("JSON 顶层应为数组结构");
            }
        } catch (err) {
            alert("❌ 导入失败，不是有效的 JSON 格式或结构不兼容：" + err.message);
        }
        e.target.value = ''; 
    };
    reader.readAsText(file);
});

// ✨ 清空对话记忆
bindEvent("btnClearMemory", "click", () => {
    if (chatContext.length === 0) {
        alert("记忆已经是空状态。");
        return;
    }
    if (confirm("确定要清空当前的上下文对话记忆吗？清空后 AI 将重新开始对话。")) {
        chatContext = [];
        alert("🗑️ 对话记忆已彻底清空。");
    }
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
    if(!file || isNaN(fps)) return alert("请在文本框输入抽帧率(数字)，并选择视频");
    const btn = document.getElementById("btnFrames"); const old = btn.innerHTML;
    
    btn.innerHTML = "处理中..."; btn.disabled = true;
    
    responseWrap.style.display = "block"; 
    respStatus.textContent = "⏳ 视频解析中..."; respStatus.style.color = "#666";
    response.textContent = "🎬 目标: " + fps + " 帧/秒\n正在分配本地算力进行 [抽帧] 与 [提取MP3]...";

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
                respStatus.textContent = "⏳ 抽帧: " + i + "/" + total + " 帧 | ⚡ " + realTimeSpeed + " 帧/秒";
            }
        }

        const costTime = ((performance.now() - startTime) / 1000).toFixed(2);
        const processSpeed = costTime > 0 ? (images.length / costTime).toFixed(1) : 0;

        currentImageIndex = 0; mediaPreview.innerHTML = ""; 
        imageDisplayContainer.style.display = "flex";
        
        const timeLabel = document.getElementById("imageTimeLabel");
        if (timeLabel) {
            timeLabel.style.display = "block";
            timeLabel.style.color = "#07C160";
            timeLabel.style.marginTop = "10px";
            timeLabel.innerHTML = "✅ 抽帧完毕: <b>" + images.length + "</b> 帧 | ⚡ <b>" + processSpeed + " 帧/秒</b>";
        }

        respStatus.textContent = "⏳ 正在提取音频..."; 
        response.textContent = "✅ 抽帧成功！\n📊 总数: " + images.length + " 帧\n⏱️ 耗时: " + costTime + " 秒\n⚡ 均速: " + processSpeed + " 帧/秒\n\n🎵 正在从视频轨道分离音频并编码为 MP3...";
        
        showFrame(); 

        const safeName = file.name.split('.').slice(0, -1).join('.') || file.name;

        const resultDiv = document.createElement('div');
        resultDiv.style.marginTop = "20px";
        resultDiv.style.width = "100%";
        resultDiv.style.textAlign = "center";
        resultDiv.style.padding = "15px";
        resultDiv.style.background = "#F0F2F5";
        resultDiv.style.borderRadius = "10px";

        const isWechatClient = navigator.userAgent.toLowerCase().indexOf('micromessenger') !== -1;

        const zipBtn = document.createElement('button');
        zipBtn.className = "btn btn-ai";
        zipBtn.style.cssText = "margin-bottom:15px; width:100%; border:none;";
        
        if (isWechatClient) {
            zipBtn.style.display = 'none';
        }

        zipBtn.innerText = "📦 一键打包下载所有图像帧 (ZIP)";
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
                    zipBtn.innerText = "📦 打包中... " + meta.percent.toFixed(0) + "%";
                });
                
                const zipUrl = URL.createObjectURL(blob);
                document.getElementById('response').textContent += "\n\n📦 [ZIP 图像包] 提取完成！";
                
                const a = document.createElement('a');
                a.href = zipUrl;
                a.download = safeName + "_frames.zip";
                a.click();
                
                zipBtn.innerText = "✅ 打包成功，链接已输出至日志";
                setTimeout(() => { zipBtn.innerText = oldZipText; zipBtn.disabled = false; }, 3000);
            } catch(e) {
                alert("打包失败: " + e.message);
                zipBtn.innerText = oldZipText; zipBtn.disabled = false;
            }
        };
        resultDiv.appendChild(zipBtn);

        try {
            const mp3Url = await extractAudioToMP3(file, (progress) => {
                respStatus.textContent = "🎵 编码 MP3: " + progress + "%";
            });
            
            respStatus.textContent = "✅ 抽帧与音频全完成";
            response.textContent += "\n\n✅ 音频提取并转换 MP3 成功！";
            
            const audioLabel = document.createElement('div');
            audioLabel.style.cssText = "font-size:13px; font-weight:bold; color:#333; margin-bottom:10px;";
            audioLabel.innerText = "🎵 提取的 MP3 音频";
            
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

            audioBtn.innerText = "📥 保存 MP3 文件";
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
            respStatus.textContent = "✅ 抽帧完成 (无音频)";
            response.textContent += "\n⚠️ 音频提取跳过: 此视频可能没有音轨，或者格式暂不支持。";
        }

        mediaPreview.appendChild(resultDiv);
        btn.innerHTML = old; btn.disabled = false;
    };
});

function showFrame() {
    if(images.length === 0) return; 
    displayedImage.src = images[currentImageIndex]; displayedImage.style.display = "block";
    originalImgSrc = images[currentImageIndex]; onModelChange();

    if(frameControls) frameControls.style.display = "grid"; 
    if(framePager) {
        framePager.style.display = "block";
        framePager.textContent = "当前帧: " + (currentImageIndex+1) + " / " + images.length + " (支持键盘←→键进行穿透微调)";
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
    const htmlContent = universalText.value; if(!htmlContent.trim()) return alert("请在文本框输入要预览的 HTML 代码！");
    const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8' });
    window.open(URL.createObjectURL(blob), '_blank');
});

bindEvent("btnDecodeQR", "click", () => {
    const textVal = universalText.value.trim(); const file = universalFile.files[0];
    let imgSrc = ""; const isUrl = /^https?:\/\/.+/i.test(textVal);
    
    if (isUrl) imgSrc = textVal;
    else if (file && file.type.startsWith("image/")) imgSrc = URL.createObjectURL(file);
    else return alert("请上传包含二维码的图片，或者在上方文本框粘贴图片的超链接！");

    const btn = document.getElementById("btnDecodeQR"); const old = btn.innerHTML; 
    btn.innerHTML = "识别中..."; btn.disabled = true;

    const img = new Image();
    if (isUrl) img.crossOrigin = "Anonymous"; 
    
    img.onload = () => {
        try {
            const canvas = document.createElement('canvas'); canvas.width = img.width; canvas.height = img.height;
            const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: "attemptBoth" });
            if (code) { 
                universalText.value = code.data; updateStats(); checkEmptyState(); copyToClipboard(code.data, btn); 
            } else { alert("❌ 识别失败，未能找到有效二维码。"); }
        } catch (err) {
            if (err.name === "SecurityError" || err.message.includes("cross-origin")) alert("❌ 跨域限制：防盗链禁止直接读取。请保存到本地上传识别！");
            else alert("❌ 识别异常：" + err.message);
        }
        btn.innerHTML = old; btn.disabled = false;
    };
    img.onerror = () => { alert("❌ 图片加载失败。"); btn.innerHTML = old; btn.disabled = false; };
    img.src = imgSrc;
});
  
bindEvent("btnReadMeta", "click", async () => {
    const file = universalFile.files[0]; if (!file) return alert("请选择要分析的文件物料！");
    responseWrap.style.display = "block"; respStatus.textContent = "透视中..."; response.textContent = "⏳ 正在穿透解析...\n";
    try {
        let metaText = "=== 📁 基础属性 ===\n文件名: " + file.name + "\n大小: " + (file.size / 1024).toFixed(2) + " KB\n\n";
        try {
            const tags = await exifr.parse(file, true);
            if (tags) { metaText += "=== 🏷️ 扩展元数据 ===\n"; for (const [k, v] of Object.entries(tags)) metaText += k + ": " + v + "\n"; }
        } catch(e){}
        response.textContent = metaText; respStatus.textContent = "✅ 透视完成";
    } catch (e) { response.textContent = "异常: " + e.message; }
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
        
        if (ms < 50) pingTextElem.style.color = "#07C160";
        else if (ms < 100) pingTextElem.style.color = "#d9a23a";
        else pingTextElem.style.color = "#FA5151";

        addData(ms);
    };

    img.onerror = function() {
        if (isFinished) return;
        isFinished = true;
        pingTextElem.innerText = "断网/异常";
        pingTextElem.style.color = "#FA5151"; 
        addData(500); 
    };

    setTimeout(() => {
        if (!isFinished) {
            isFinished = true;
            img.src = ""; 
            pingTextElem.innerText = "请求超时";
            pingTextElem.style.color = "#FA5151";
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
    
    ctx.strokeStyle = "#e5eef2"; 
    ctx.lineWidth = 1;
    ctx.fillStyle = "#9ba5b1";   
    ctx.font = "10px ui-monospace, Consolas, sans-serif";
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
    fillGradient.addColorStop(0, "rgba(249, 168, 104, 0.15)"); 
    fillGradient.addColorStop(1, "rgba(108, 56, 161, 0.0)");   

    ctx.fillStyle = fillGradient;
    ctx.fill(fillPath);

    const lineGradient = ctx.createLinearGradient(paddingLeft, 0, w, 0);
    lineGradient.addColorStop(0, "#fca869");   
    lineGradient.addColorStop(0.5, "#ea6068"); 
    lineGradient.addColorStop(1, "#753aa7");   

    ctx.strokeStyle = lineGradient;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.stroke(linePath);

    ctx.fillStyle = "#9ba5b1";
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
  
// ==========================================
// 🛠️ 附加功能补丁：搜索栏交互逻辑与微信环境隐藏
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    // 1. 微信环境检测与隐藏搜索栏
    const isWechatClientEnv = navigator.userAgent.toLowerCase().indexOf('micromessenger') !== -1;
    const searchCardElem = document.querySelector('.search-card');
    if (isWechatClientEnv && searchCardElem) {
        searchCardElem.style.display = 'none';
    }

    // 2. 搜索框回车与按钮点击执行搜索
    const searchBtn = document.getElementById('externalSearchBtn');
    const searchInputElem = document.getElementById('searchInput');
    const searchClearBtn = document.getElementById('searchClear');

    function executeSearch() {
        const engineUrl = document.getElementById('searchEngineValue').value;
        const query = searchInputElem.value.trim();
        if (query) {
            window.open(engineUrl + encodeURIComponent(query), '_blank');
        }
    }

    if (searchBtn && searchInputElem) {
        searchBtn.addEventListener('click', executeSearch);
        searchInputElem.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') executeSearch();
        });
        searchInputElem.addEventListener('input', function() {
            if (searchClearBtn) {
                searchClearBtn.style.display = this.value.length > 0 ? 'block' : 'none';
            }
        });
    }

    // 3. 点击外部区域关闭搜索引擎下拉框
    document.addEventListener('click', function(event) {
        const customSelect = document.getElementById('customSelect');
        if (customSelect && !customSelect.contains(event.target)) {
            customSelect.classList.remove('open');
        }
    });
});

// 4. HTML 内联绑定的搜索引擎选择与清除函数
window.toggleSelect = function(event) {
    const customSelect = document.getElementById('customSelect');
    if (customSelect) customSelect.classList.toggle('open');
    if (event) event.stopPropagation();
};

window.selectEngine = function(url, name, element) {
    document.getElementById('searchEngineValue').value = url;
    document.getElementById('currentEngineText').innerText = name;
    
    const options = document.querySelectorAll('.option-item');
    options.forEach(opt => opt.classList.remove('selected'));
    if (element) element.classList.add('selected');
    
    const customSelect = document.getElementById('customSelect');
    if (customSelect) customSelect.classList.remove('open');
};

window.clearSearch = function() {
    const searchInputElem = document.getElementById('searchInput');
    if (searchInputElem) {
        searchInputElem.value = '';
        searchInputElem.focus();
    }
    const searchClearBtn = document.getElementById('searchClear');
    if (searchClearBtn) {
        searchClearBtn.style.display = 'none';
    }
};
  
// ==========================================
// 🛠️ 附加功能补丁：全媒体元数据透视 (最终修正版)
// ==========================================
(function() {
    const img = document.getElementById("displayedImage");
    const metaPreview = document.getElementById("imageMetadataPreview");
    
    // 强制元数据区域样式，不影响按钮
    if (metaPreview) {
        metaPreview.style.cssText = `
            margin-top: 15px; padding: 12px; background: #F8F9FA; border-radius: 8px; font-size: 12px; 
            color: #333; border: 1px solid #E5E5E5; text-align: left; line-height: 1.5; 
            font-family: ui-monospace, Consolas, monospace; max-height: 500px; 
            overflow: auto; white-space: pre; box-sizing: border-box; max-width: 100%;
        `.replace(/\s+/g, ' ');
    }

    // 绑定事件 (确保直接通过 ID 挂载，不注入内联 CSS 以防冲突)
    document.getElementById("btnInvertColor").onclick = () => {
        img.style.filter = img.style.filter.includes("invert(1)") ? "" : "invert(1)";
    };
    document.getElementById("btnGrayscale").onclick = () => {
        img.style.filter = img.style.filter.includes("grayscale(1)") ? "" : "grayscale(1)";
    };

    async function analyzeMedia() {
        const file = universalFile.files[0];
        if (!file || !file.type.startsWith("image/")) return;
        
        let displayStr = `=== 媒体元数据 (Metadata) ===\n`;
        displayStr += `file_name: ${file.name}\nfile_size: ${(file.size / 1024 / 1024).toFixed(2)} MB\n`;
        displayStr += `mime_type: ${file.type}\n`;

        try {
            const tags = await exifr.parse(file, true);
            if (tags) {
                const createDate = tags.CreateDate || tags.DateTimeOriginal || '未知';
                const modifyDate = tags.ModifyDate || '未知';
                
                displayStr += `📅 拍摄时间: ${createDate}\n`;
                displayStr += `🕒 修改时间: ${modifyDate}\n`;
                displayStr += `📏 图像尺寸: ${img.naturalWidth} x ${img.naturalHeight} px\n\n`;
                displayStr += `=== 完整元数据详情 ===\n`;

                for (const [k, v] of Object.entries(tags)) {
                    if (v instanceof ArrayBuffer || (typeof v === 'string' && v.length > 500)) {
                        displayStr += `${k}: (Binary/Large data)\n`;
                    } else if (v !== null && typeof v !== 'object') {
                        displayStr += `${k}: ${v}\n`;
                    }
                }
            }
        } catch (e) {
            displayStr += `\n(读取详细信息失败: ${e.message})`;
        }
        metaPreview.innerText = displayStr;
    }

    img.onload = analyzeMedia;
})();

</script>
</body>
</html>
