<?php

$start_time = microtime(true);
error_reporting(0);

// =====================
// 配置区
// =====================
$TIMEZONE = 'Asia/Shanghai';
$SHOW_FULL_IP = true; // 是否完整显示 IP

$CARD_W = 300;
$CARD_H = 126;

// =====================
// 核心函数
// =====================
function get_client_ip() {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    $fallback_ip = '0.0.0.0';
    
    foreach ($keys as $k) {
        if (empty($_SERVER[$k])) continue;
        $ips = explode(',', trim((string)$_SERVER[$k]));
        
        foreach ($ips as $ip) {
            $ip = trim($ip);
            // 处理 IPv4 映射的 IPv6
            if (stripos($ip, '::ffff:') === 0) {
                $ip = substr($ip, 7);
            }
            
            // 验证是否为合法IP (不论是v4还是v6)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                // 优先返回公网IP，排除内网(局域网)和保留IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip; 
                }
                // 记录第一个合法但可能是内网的IP作为托底
                if ($fallback_ip === '0.0.0.0') {
                    $fallback_ip = $ip;
                }
            }
        }
    }
    return $fallback_ip;
}

function format_ip($ip, $showFull) {
    // 处理 IPv6
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $ip = inet_ntop(inet_pton($ip)); // 规范化并压缩连续的 0
        $parts = explode(':', $ip);
        
        if (!$showFull) {
            // 隐藏模式：保留前三段，其余打码
            return implode(':', array_slice($parts, 0, 3)) . ':****';
        } else {
            // 完整模式：如果段数过多(大于5段)，则折叠中间部分，防止撑破 SVG 宽度
            if (count($parts) > 5) {
                $c = count($parts);
                return $parts[0] . ':' . $parts[1] . ':...:' . $parts[$c-2] . ':' . $parts[$c-1];
            }
            return $ip;
        }
    }
    
    // 处理 IPv4
    if (!$showFull && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        if (count($p) === 4) { $p[3] = '*'; return implode('.', $p); }
    }
    
    return $ip;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function parse_ua($ua) {
    $os = 'Unknown';
    if (preg_match('/Windows NT 10\.0/i', $ua)) $os = 'Windows 10/11';
    else if (preg_match('/Windows NT 6\.1/i', $ua)) $os = 'Windows 7';
    else if (preg_match('/Windows NT 6\.[23]/i', $ua)) $os = 'Windows 8';
    else if (preg_match('/Android/i', $ua)) $os = 'Android';
    else if (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
    else if (preg_match('/Mac OS X/i', $ua)) $os = 'macOS';
    else if (preg_match('/Linux/i', $ua)) $os = 'Linux';

    $browser = 'Browser'; $engine = '';
    if (preg_match('/wxwork\/([\d.]+)/i', $ua, $m)) { $browser = 'WeCom'; $engine = 'WebView'; }
    else if (preg_match('/MicroMessenger\/([\d.]+)/i', $ua, $m)) { $browser = 'WeChat'; $engine = 'WebView'; }
    else if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) { $browser = 'Edge ' . explode('.', $m[1])[0]; $engine = 'Blink'; }
    else if (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) { $browser = 'Chrome ' . explode('.', $m[1])[0]; $engine = 'Blink'; }
    else if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) { $browser = 'Firefox ' . explode('.', $m[1])[0]; $engine = 'Gecko'; }
    else if (preg_match('/Safari\//i', $ua) && preg_match('/Version\/([\d.]+)/i', $ua, $m)) { $browser = 'Safari ' . explode('.', $m[1])[0]; $engine = 'WebKit'; }
    
    return [$os, $browser, $engine];
}

function lunar_data() {
    static $data = null;
    if ($data !== null) return $data;
    return [
        'lunarInfo' => [0x04bd8,0x04ae0,0x0a570,0x054d5,0x0d260,0x0d950,0x16554,0x056a0,0x09ad0,0x055d2,0x04ae0,0x0a5b6,0x0a4d0,0x0d250,0x1d255,0x0b540,0x0d6a0,0x0ada2,0x095b0,0x14977,0x04970,0x0a4b0,0x0b4b5,0x06a50,0x06d40,0x1ab54,0x02b60,0x09570,0x052f2,0x04970,0x06566,0x0d4a0,0x0ea50,0x06e95,0x05ad0,0x02b60,0x186e3,0x092e0,0x1c8d7,0x0c950,0x0d4a0,0x1d8a6,0x0b550,0x056a0,0x1a5b4,0x025d0,0x092d0,0x0d2b2,0x0a950,0x0b557,0x06ca0,0x0b550,0x15355,0x04da0,0x0a5d0,0x14573,0x052d0,0x0a9a8,0x0e950,0x06aa0,0x0aea6,0x0ab50,0x04b60,0x0aae4,0x0a570,0x05260,0x0f263,0x0d950,0x05b57,0x056a0,0x096d0,0x04dd5,0x04ad0,0x0a4d0,0x0d4d4,0x0d250,0x0d558,0x0b540,0x0b5a0,0x195a6,0x095b0,0x049b0,0x0a974,0x0a4b0,0x0b27a,0x06a50,0x06d40,0x0af46,0x0ab60,0x09570,0x04af5,0x04970,0x064b0,0x074a3,0x0ea50,0x06b58,0x05ac0,0x0ab60,0x096d5,0x092e0,0x0c960,0x0d954,0x0d4a0,0x0da50,0x07552,0x056a0,0x0abb7,0x025d0,0x092d0,0x0cab5,0x0a950,0x0b4a0,0x0baa4,0x0ad50,0x055d9,0x04ba0,0x0a5b0,0x15176,0x052b0,0x0a930,0x07954,0x06aa0,0x0ad50,0x05b52,0x04b60,0x0a6e6,0x0a4e0,0x0d260,0x0ea65,0x0d530,0x05aa0,0x076a3,0x096d0,0x04bd7,0x04ad0,0x0a4d0,0x1d0b6,0x0d250,0x0d520,0x0dd45,0x0b5a0,0x056d0,0x055b2,0x049b0,0x0a577,0x0a4b0,0x0aa50,0x1b255,0x06d20,0x0ada0],
        'Gan' => ['甲','乙','丙','丁','戊','己','庚','辛','壬','癸'],
        'Zhi' => ['子','丑','寅','卯','辰','巳','午','未','申','酉','戌','亥'],
        'Animals' => ['鼠','牛','虎','兔','龙','蛇','马','羊','猴','鸡','狗','猪'],
        'cnMonth' => ['正','二','三','四','五','六','七','八','九','十','冬','腊'],
        'cnDay' => ['初一','初二','初三','初四','初五','初六','初七','初八','初九','初十','十一','十二','十三','十四','十五','十六','十七','十八','十九','二十','廿一','廿二','廿三','廿四','廿五','廿六','廿七','廿八','廿九','三十']
    ];
}
function leap_month($y) { return lunar_data()['lunarInfo'][$y - 1900] & 0xF; }
function leap_days($y) { $lm = leap_month($y); return $lm ? ((lunar_data()['lunarInfo'][$y - 1900] & 0x10000) ? 30 : 29) : 0; }
function month_days($y, $m) { return (lunar_data()['lunarInfo'][$y - 1900] & (0x10000 >> $m)) ? 30 : 29; }
function lyear_days($y) { $sum = 348; for ($i = 0x8000; $i > 0x8; $i >>= 1) $sum += (lunar_data()['lunarInfo'][$y - 1900] & $i) ? 1 : 0; return $sum + leap_days($y); }
function solar_to_lunar($dt) {
    $d = lunar_data();
    $offset = (int)floor(($dt->getTimestamp() - strtotime('1900-01-31 00:00:00')) / 86400);
    $temp = 0; 
    for ($i = 1900; $i < 2101 && $offset > 0; $i++) { $temp = lyear_days($i); $offset -= $temp; }
    if ($offset < 0) { $offset += $temp; $i--; }
    $year = $i; $leap = leap_month($year); $isLeap = false;
    for ($m = 1; $m < 13 && $offset > 0; $m++) {
        if ($leap > 0 && $m === ($leap + 1) && !$isLeap) { $m--; $isLeap = true; $temp = leap_days($year); }
        else { $temp = month_days($year, $m); }
        $offset -= $temp;
        if ($isLeap && $m === ($leap + 1)) $isLeap = false;
    }
    if ($offset < 0) { $offset += $temp; $m--; }
    
    $mName = ($isLeap ? '闰' : '') . $d['cnMonth'][$m - 1] . '月';
    return [
        'ganzhiYear' => $d['Gan'][($year - 4) % 10] . $d['Zhi'][($year - 4) % 12], 
        'zodiac' => $d['Animals'][($year - 4) % 12], 
        'cnMonth' => $mName, 
        'cnDay' => $d['cnDay'][$offset]
    ];
}

// =====================
// 生成数据
// =====================
date_default_timezone_set($TIMEZONE);
$ip = $_GET['ip'] ?? get_client_ip();
[$os, $browser, $engine] = parse_ua($_SERVER['HTTP_USER_AGENT'] ?? '');

$now = new DateTime('now');
$weekday = ['日','一','二','三','四','五','六'][(int)$now->format('w')];
$lunar = solar_to_lunar($now);
$line3 = '今天是' . $now->format('Y年n月j日') . ' 星期' . $weekday . ' ' . $lunar['ganzhiYear'] . '(' . $lunar['zodiac'] . ')年' . $lunar['cnMonth'] . $lunar['cnDay'];

// 计算总耗时 (毫秒)
$load_time_us = round((microtime(true) - $start_time) * 1000000);
// =====================
// 布局与 SVG 输出
// =====================
$PadX = 5;
$PadY_Top = 4;
$PadY_Bottom = 12;

$CardW = $CARD_W - ($PadX * 2);
$CardH = $CARD_H - $PadY_Top - $PadY_Bottom;

$leftX  = $PadX + 16;
$rightX = $PadX + $CardW - 16;
$topY   = $PadY_Top + 36;
$gap    = 23;
$contentWidth = $rightX - $leftX;

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?=h($CARD_W)?>" height="<?=h($CARD_H)?>" viewBox="0 0 <?=h($CARD_W)?> <?=h($CARD_H)?>">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#ffffff"/>
      <stop offset="100%" stop-color="#f2f2f2"/>
    </linearGradient>
    <linearGradient id="stroke" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="#e6e6e6"/>
      <stop offset="100%" stop-color="#d6d6d6"/>
    </linearGradient>
    <filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="3" stdDeviation="4" flood-color="rgba(0,0,0,0.2)"/>
    </filter>
  </defs>

  <rect x="<?=h($PadX)?>" y="<?=h($PadY_Top)?>" width="<?=h($CardW)?>" height="<?=h($CardH)?>" 
        fill="white" filter="url(#shadow)" />
  <rect x="<?=h($PadX)?>" y="<?=h($PadY_Top)?>" width="<?=h($CardW)?>" height="<?=h($CardH)?>" 
        fill="url(#bg)" stroke="url(#stroke)" stroke-width="1"/>

  <style>
    text {
        font-family: "Microsoft YaHei", "PingFang SC", sans-serif;
        fill: #333;
        user-select: none;
        pointer-events: none;
    }
    .main { font-size: 10.5px; }
    .date { font-size: 9.5px; fill: #555; }
  </style>

  <text class="main" x="<?=h($leftX)?>" y="<?=h($topY)?>">T: <?=h($load_time_us)?>µs</text>  
  <text class="main" text-anchor="end" x="<?=h($rightX)?>" y="<?=h($topY)?>"><?=h($os)?></text>
  <text class="main" x="<?=h($leftX)?>" y="<?=h($topY + $gap)?>">IP: <?=h(format_ip($ip, $SHOW_FULL_IP))?></text>
  <text class="main" text-anchor="end" x="<?=h($rightX)?>" y="<?=h($topY + $gap)?>"><?=h($browser)?> (<?=h($engine)?>)</text>

  <text class="date" 
        x="<?=h($leftX)?>" 
        y="<?=h($topY + $gap*2)?>"
        textLength="<?=h($contentWidth)?>" 
        lengthAdjust="spacing"><?=h($line3)?></text>
</svg>
