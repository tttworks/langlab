<?php

/**
 * 批量登记本地素材 —— 走 HTTP API（复用与界面完全相同的导入路径，不复制代码）
 * 幂等：同 course + 同 title 已存在就跳过
 *
 * 用法：
 *   D:\php82\php.exe scripts\link_dir.php <目录> <课程id> <语言> [type] [apiBase]
 * 例：
 *   D:\php82\php.exe scripts\link_dir.php "D:/.../S04" en-drama en-US script
 */

declare(strict_types=1);

$dir = $argv[1] ?? '';
$courseId = $argv[2] ?? '';
$lang = $argv[3] ?? 'en-US';
$type = $argv[4] ?? '';
$api = rtrim($argv[5] ?? 'http://127.0.0.1:8001', '/');

if ($dir === '' || $courseId === '' || !is_dir($dir)) {
    fwrite(STDERR, "用法: php link_dir.php <目录> <课程id> <语言> [type] [apiBase]\n");
    exit(1);
}

function api(string $url, ?array $body = null, int $timeout = 300): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'error' => '请求失败：' . $url];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : ['ok' => false, 'error' => '返回不是 JSON：' . mb_substr($raw, 0, 120)];
}

$files = glob(rtrim($dir, '/\\') . '/*.{txt,md,srt,vtt,epub,pdf,docx,html,htm}', GLOB_BRACE) ?: [];
sort($files);
// 目录说明文件不当素材
$files = array_values(array_filter($files, function ($f) {
    return !preg_match('/^(readme|index|说明|目录|索引)\b/i', pathinfo($f, PATHINFO_FILENAME));
}));
echo "接口：{$api}\n目录：{$dir}\n文件：" . count($files) . " 个（type=" . ($type ?: '按扩展名') . "）\n\n";

// 先拉一遍已有素材，做跳过判断
$existing = [];
$r = api($api . '/api/materials?course=' . urlencode($courseId));
foreach (($r['materials'] ?? []) as $m) {
    $existing[$m['title']] = true;
}

$ok = 0;
$skip = 0;
$fail = 0;
foreach ($files as $f) {
    $title = pathinfo($f, PATHINFO_FILENAME);
    if (isset($existing[$title])) {
        printf("  跳过（已有）%s\n", mb_substr($title, 0, 48));
        $skip++;
        continue;
    }
    $body = ['path' => str_replace('\\', '/', $f), 'lang_code' => $lang, 'course_id' => $courseId, 'title' => $title];
    if ($type !== '') {
        $body['type'] = $type;
    }
    $res = api($api . '/api/materials/link', $body);
    if (empty($res['ok'])) {
        printf("  ✗ %s  %s\n", mb_substr($title, 0, 40), $res['error'] ?? '');
        $fail++;
        continue;
    }
    printf("  ✓ %-52s %5d 段 / %6d 词\n", mb_substr($title, 0, 50), $res['segments'] ?? 0, $res['words'] ?? 0);
    $existing[$title] = true;
    $ok++;
    usleep(200000);
}

echo "\n完成：新增 {$ok} / 跳过 {$skip} / 失败 {$fail}\n";
