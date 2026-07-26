<?php
/* A small, dependency-free, ten-minute sharing service. */
const SHARE_TTL = 600;
const MAX_FILE_SIZE = 100 * 1024 * 1024;
// Some shared hosts deploy application files read-only. Prefer local storage, but
// transparently fall back to PHP's writable temporary directory when necessary.
define('DATA_DIR', is_writable(__DIR__) ? __DIR__ . '/.share_data' : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/cpuck_share_data');

date_default_timezone_set('Asia/Shanghai');
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '101M');
ini_set('max_execution_time', '120');

function bootStorage()
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('无法创建存储目录');
    }
    $guard = DATA_DIR . '/.htaccess';
    if (!is_file($guard)) {
        @file_put_contents($guard, "Require all denied\nDeny from all\n");
    }
}

function cleanExpired()
{
    foreach (glob(DATA_DIR . '/*.json') ?: [] as $metaFile) {
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (!is_array($meta) || (int) ($meta['expires'] ?? 0) <= time()) {
            if (is_array($meta) && isset($meta['stored'])) {
                @unlink(DATA_DIR . '/' . basename((string) $meta['stored']));
            }
            @unlink($metaFile);
        }
    }
}

function redirectWith($key, $value)
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'share.php', '?') . '?' . http_build_query([$key => $value]), true, 303);
    exit;
}

function loadShare($id)
{
    if (!preg_match('/^[a-f0-9]{20}$/', $id)) return null;
    $path = DATA_DIR . '/' . $id . '.json';
    $meta = json_decode((string) @file_get_contents($path), true);
    if (!is_array($meta) || (int) ($meta['expires'] ?? 0) <= time()) return null;
    return $meta;
}

function publicUrl($id)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/share.php', '?');
    return ($https ? 'https' : 'http') . '://' . $host . $path . '?s=' . $id;
}

$bootError = '';
try {
    bootStorage();
    cleanExpired();
} catch (Exception $e) {
    // Render a useful message instead of allowing a blank HTTP 500 page.
    $bootError = '临时存储不可用，请检查 PHP 对存储目录的写入权限。';
}

if (isset($_GET['file'])) {
    $id = (string) $_GET['file'];
    $meta = loadShare($id);
    if (!$meta || ($meta['kind'] ?? '') !== 'file') { http_response_code(404); exit('分享不存在或已过期'); }
    $path = DATA_DIR . '/' . basename((string) $meta['stored']);
    if (!is_file($path)) { http_response_code(404); exit('文件不存在'); }
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . (string) $meta['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename*=UTF-8\'\'' . rawurlencode((string) $meta['name']));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = bin2hex(random_bytes(10));
        $now = time();
        $meta = ['id' => $id, 'created' => $now, 'expires' => $now + SHARE_TTL];
        $text = trim((string) ($_POST['content'] ?? ''));
        $file = $_FILES['file'] ?? null;

        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if ((int) $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('上传失败，请确认文件不超过 100 MB');
            if ((int) $file['size'] < 1 || (int) $file['size'] > MAX_FILE_SIZE) throw new RuntimeException('文件大小须在 100 MB 以内');
            if (class_exists('finfo')) {
                $detector = new finfo(FILEINFO_MIME_TYPE);
                $mime = $detector->file((string) $file['tmp_name']);
            } elseif (function_exists('mime_content_type')) {
                $mime = mime_content_type((string) $file['tmp_name']);
            } else {
                throw new RuntimeException('服务器未启用文件类型检测扩展');
            }
            $mime = $mime ?: 'application/octet-stream';
            $safeMimes = [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
                'video/mp4', 'video/webm', 'video/quicktime', 'video/x-matroska',
                'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/webm', 'audio/flac',
                'text/plain', 'text/markdown', 'application/pdf',
            ];
            $allowed = in_array($mime, $safeMimes, true);
            if (!$allowed) throw new RuntimeException('仅支持图像、视频、音频、文本和 PDF 文件');
            $stored = $id . '.bin';
            if (!move_uploaded_file((string) $file['tmp_name'], DATA_DIR . '/' . $stored)) throw new RuntimeException('保存文件失败');
            $originalName = basename((string) $file['name']);
            $safeName = function_exists('mb_substr') ? mb_substr($originalName, 0, 180, 'UTF-8') : substr($originalName, 0, 180);
            $meta += ['kind' => 'file', 'stored' => $stored, 'name' => $safeName, 'mime' => $mime, 'size' => (int) $file['size']];
        } elseif ($text !== '') {
            $textLength = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($textLength > 50000) throw new RuntimeException('文本不能超过 50,000 字');
            $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
            $isSafeLink = in_array($scheme, ['http', 'https'], true) && filter_var($text, FILTER_VALIDATE_URL);
            $meta += ['kind' => $isSafeLink ? 'link' : 'text', 'content' => $text];
        } else {
            throw new RuntimeException('请选择文件或输入分享内容');
        }

        if (file_put_contents(DATA_DIR . '/' . $id . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            if (isset($stored)) @unlink(DATA_DIR . '/' . $stored);
            throw new RuntimeException('创建分享失败');
        }
        redirectWith('created', $id);
    } catch (Throwable $e) {
        redirectWith('error', $e->getMessage());
    }
}

$shareId = (string) ($_GET['s'] ?? $_GET['created'] ?? '');
$share = $shareId !== '' ? loadShare($shareId) : null;
$created = isset($_GET['created']) && $share;
$error = $bootError ?: (string) ($_GET['error'] ?? '');
$url = $share ? publicUrl($shareId) : '';
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark"><title>十分钟 · 临时分享</title>
<style>
:root{--bg:#080b12;--card:#111722;--line:#263044;--text:#f4f7fb;--muted:#8e9bb0;--accent:#7c5cff;--cyan:#32d5e8;--danger:#ff7882}*{box-sizing:border-box}body{margin:0;min-height:100vh;color:var(--text);font:15px/1.6 system-ui,-apple-system,"PingFang SC",sans-serif;background:radial-gradient(circle at 20% 0,#1d1742 0,transparent 34rem),radial-gradient(circle at 90% 80%,#092c35 0,transparent 30rem),var(--bg);display:grid;place-items:center;padding:30px 18px}.wrap{width:min(680px,100%)}header{display:flex;align-items:center;gap:14px;margin-bottom:26px}.logo{width:48px;height:48px;border-radius:15px;display:grid;place-items:center;background:linear-gradient(135deg,var(--accent),var(--cyan));box-shadow:0 12px 35px #735cff55;font-size:23px}h1{font-size:23px;line-height:1.2;margin:0}header p{margin:4px 0 0;color:var(--muted)}.card{background:#111722df;border:1px solid var(--line);border-radius:24px;padding:28px;box-shadow:0 24px 70px #0008;backdrop-filter:blur(16px)}.notice{padding:11px 14px;border-radius:12px;margin-bottom:18px;background:#ff788219;color:#ffc1c5;border:1px solid #ff788244}.tabs{display:flex;padding:4px;background:#090d15;border-radius:13px;margin-bottom:20px}.tab{flex:1;border:0;background:transparent;color:var(--muted);padding:10px;border-radius:10px;cursor:pointer}.tab.active{background:#20283a;color:white}.drop{border:1.5px dashed #39465e;border-radius:17px;padding:32px 18px;text-align:center;cursor:pointer;transition:.2s}.drop:hover,.drop.drag{border-color:var(--cyan);background:#32d5e80a}.drop strong{display:block;font-size:17px}.drop span,.fine{color:var(--muted);font-size:13px}.file-name{color:var(--cyan);margin-top:10px;min-height:24px}textarea{width:100%;min-height:170px;resize:vertical;background:#090d15;color:white;border:1px solid var(--line);border-radius:15px;padding:15px;font:inherit;outline:none}textarea:focus{border-color:var(--accent)}button.primary,.action{width:100%;border:0;border-radius:14px;padding:13px 18px;margin-top:18px;background:linear-gradient(100deg,var(--accent),#5e8cff);color:white;font-weight:700;cursor:pointer;text-decoration:none;text-align:center;display:block}.fine{text-align:center;margin:12px 0 0}.hidden{display:none}.pill{display:inline-flex;gap:7px;align-items:center;color:#b9c4d7;background:#20283a;padding:5px 10px;border-radius:20px;font-size:12px}.share-head{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:20px}.share-head h2{margin:0;font-size:20px}.preview{background:#090d15;border:1px solid var(--line);border-radius:16px;overflow:hidden}.preview img,.preview video{display:block;max-width:100%;max-height:440px;margin:auto}.preview audio{width:100%;display:block;margin:20px 0}.text-content{padding:22px;white-space:pre-wrap;overflow-wrap:anywhere;max-height:420px;overflow:auto}.link-content{padding:26px;text-align:center}.link-content a{color:#7de7f2;overflow-wrap:anywhere}.urlbox{display:flex;gap:8px;margin-top:18px}.urlbox input{min-width:0;flex:1;background:#090d15;border:1px solid var(--line);border-radius:12px;color:#b9c4d7;padding:11px}.urlbox button{border:0;border-radius:12px;padding:0 17px;background:#283249;color:white;cursor:pointer}.expired{text-align:center;padding:25px 0}.expired b{display:block;font-size:20px;margin-bottom:7px}@media(max-width:520px){.card{padding:20px}.share-head{align-items:flex-start;flex-direction:column}.urlbox{flex-direction:column}.urlbox button{padding:11px}}
</style>
</head><body><main class="wrap"><header><div class="logo">↗</div><div><h1>十分钟 · 临时分享</h1><p>无需登录，阅后即焚，轻松传递</p></div></header><section class="card">
<?php if ($share): ?>
  <div class="share-head"><h2><?= $created ? '分享已创建' : '收到一份临时分享' ?></h2><span class="pill">◷ <span id="countdown"></span> 后删除</span></div>
  <?php if ($share['kind'] === 'text'): ?><div class="preview text-content"><?= htmlspecialchars((string)$share['content'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php elseif ($share['kind'] === 'link'): ?><div class="preview link-content"><div>分享链接</div><a href="<?= htmlspecialchars((string)$share['content'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)$share['content'], ENT_QUOTES, 'UTF-8') ?></a></div>
  <?php else: $src = '?file=' . urlencode($shareId); ?><div class="preview">
    <?php if (strpos($share['mime'], 'image/') === 0): ?><img src="<?= $src ?>" alt="分享的图像">
    <?php elseif (strpos($share['mime'], 'video/') === 0): ?><video src="<?= $src ?>" controls playsinline></video>
    <?php elseif (strpos($share['mime'], 'audio/') === 0): ?><audio src="<?= $src ?>" controls></audio>
    <?php elseif (strpos($share['mime'], 'text/') === 0): ?><iframe src="<?= $src ?>" style="width:100%;height:360px;border:0;background:white" title="文本预览"></iframe>
    <?php else: ?><div class="text-content"><?= htmlspecialchars($share['name']) ?> · <?= number_format($share['size']/1024, 1) ?> KB</div><?php endif; ?>
  </div><a class="action" href="<?= $src ?>&amp;download=1">下载 <?= htmlspecialchars($share['name']) ?></a><?php endif; ?>
  <?php if ($created): ?><div class="urlbox"><input id="shareUrl" readonly value="<?= htmlspecialchars($url, ENT_QUOTES) ?>"><button type="button" onclick="copyUrl(this)">复制链接</button></div><?php endif; ?>
<?php elseif ($shareId !== ''): ?><div class="expired"><b>分享已消失</b><span class="fine">它可能已超过十分钟，或链接不正确。</span><a class="action" href="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? 'share.php','?')) ?>">创建新分享</a></div>
<?php else: ?>
  <?php if ($error): ?><div class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div class="tabs"><button class="tab active" type="button" data-tab="file">文件</button><button class="tab" type="button" data-tab="text">文本 / 链接</button></div>
  <form method="post" enctype="multipart/form-data"><div id="filePane"><label class="drop" id="drop"><input id="file" name="file" type="file" accept="image/*,video/*,audio/*,.txt,.md,.pdf" hidden><strong>拖入文件，或点击选择</strong><span>图像 · 视频 · 音频 · 文本 · PDF</span><div class="file-name" id="fileName"></div></label></div><div id="textPane" class="hidden"><textarea name="content" placeholder="粘贴文本或分享链接…" maxlength="50000"></textarea></div><button class="primary" type="submit">创建 10 分钟分享</button><p class="fine">最大 100 MB · 内容将在 10 分钟后自动永久删除</p></form>
<?php endif; ?></section></main>
<script>
document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab').forEach(x=>x.classList.toggle('active',x===b));filePane.classList.toggle('hidden',b.dataset.tab!=='file');textPane.classList.toggle('hidden',b.dataset.tab!=='text');file.disabled=b.dataset.tab!=='file'});
if(window.file){file.onchange=()=>fileName.textContent=file.files[0]?.name||'';['dragenter','dragover'].forEach(e=>drop.addEventListener(e,x=>{x.preventDefault();drop.classList.add('drag')}));['dragleave','drop'].forEach(e=>drop.addEventListener(e,x=>{x.preventDefault();drop.classList.remove('drag')}));drop.addEventListener('drop',e=>{file.files=e.dataTransfer.files;fileName.textContent=file.files[0]?.name||''})}
function copyUrl(btn){navigator.clipboard.writeText(shareUrl.value).then(()=>{btn.textContent='已复制';setTimeout(()=>btn.textContent='复制链接',1500)})}
<?php if ($share): ?>const expires=<?= (int)$share['expires'] ?>*1000;const tick=()=>{const n=Math.max(0,Math.ceil((expires-Date.now())/1000)),m=String(Math.floor(n/60)).padStart(2,'0'),s=String(n%60).padStart(2,'0');countdown.textContent=m+':'+s;if(n<=0)location.reload()};tick();setInterval(tick,1000);<?php endif; ?>
</script></body></html>
