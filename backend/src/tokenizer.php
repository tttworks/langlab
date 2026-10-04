<?php

/**
 * 分词桥：PHP → Python（scripts/tokenizer_cli.py）
 *
 * 设计要点：
 *  - 失败绝不抛异常，只返回 ['ok'=>false,'error'=>...]，由调用方决定降级
 *  - 结果按 (lang, md5(text)) 在单次请求内缓存，避免同一段文本算两遍
 */

declare(strict_types=1);

function langlab_python(): string
{
    $env = $_ENV['PYTHON_EXE'] ?? getenv('PYTHON_EXE') ?: '';
    if ($env !== '' && is_file($env)) {
        return $env;
    }
    $candidates = [
        'python3',
        'python',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return 'python';
}

/** 一次调用可传多段（批量），减少进程启动开销 */
function tokenize_batch(string $lang, array $texts): array
{
    $py = langlab_python();
    $script = dirname(__DIR__) . '/scripts/tokenizer_cli.py';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'tokenizer_cli.py 不存在'];
    }

    // 协议：首行 = lang，其后每行 = 一段文本的 JSON 字符串
    //   —— 必须 JSON 编码：段落内部可能自带换行（EPUB 的 <br>、单换行分段），
    //      用裸换行分隔会串行，这是踩过的坑
    $lines = [$lang];
    foreach ($texts as $t) {
        $lines[] = json_encode((string) $t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $stdin = implode("\n", $lines) . "\n";

    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$py, $script, '--batch'], $desc, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => '无法启动 python（' . $py . '）'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $errS = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    $res = json_decode((string) $out, true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => 'python 输出无法解析：' . trim(mb_substr((string) $out . ' ' . (string) $errS, 0, 200))];
    }
    return $res;
}

/** 单段便捷入口（沿用具名参数风格，内部走 batch） */
function tokenize_text(string $lang, string $text): array
{
    $r = tokenize_batch($lang, [$text]);
    if (empty($r['ok'])) {
        return $r;
    }
    return [
        'ok' => true,
        'lang' => $lang,
        'engine' => $r['engine'] ?? '?',
        'tokens' => $r['results'][0]['tokens'] ?? [],
    ];
}

/**
 * 抽取文件正文：EPUB / PDF / DOCX / 字幕 / 纯文本
 * 返回 ['ok'=>bool,'parts'=>[{title,text}],'title','author','chars',...]
 */
function extract_file_text(string $path, string $type = ''): array
{
    $py = langlab_python();
    $script = dirname(__DIR__) . '/scripts/extract_text.py';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'extract_text.py 不存在'];
    }
    $cmd = [$py, $script, '--file', $path];
    if ($type !== '') {
        $cmd[] = '--type';
        $cmd[] = $type;
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => '无法启动 python（' . $py . '）'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $errS = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // 容错：库的告警可能混进 stdout，截取第一个 { 到最后一个 }
    $a = strpos($out, '{');
    $b = strrpos($out, '}');
    if ($a === false || $b === false || $b < $a) {
        return ['ok' => false, 'error' => '抽取器输出无法解析：' . trim(mb_substr($out . ' ' . $errS, 0, 300))];
    }
    $res = json_decode(substr($out, $a, $b - $a + 1), true);
    if (!is_array($res)) {
        return ['ok' => false, 'error' => '抽取结果 JSON 解析失败'];
    }
    return $res;
}
