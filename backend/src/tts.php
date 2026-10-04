<?php

/**
 * 语音合成网关：PHP → scripts/tts.py
 *
 * 设计要点：
 *  - 合成结果按 (provider|voice|rate|text) 缓存成 mp3，重复朗读同一段不再走网络
 *  - 音色列表也缓存（列一次要联网 1~2 秒），默认 7 天
 *  - 失败不抛异常，返回 ['ok'=>false,'error','hint']，由前端决定回退浏览器内置语音
 *  - 语速：服务端 TTS 用音频元素的 playbackRate 调，不重新合成（改 rate 会改音色且要重算）
 */

declare(strict_types=1);

function tts_dir(): string
{
    $d = dirname(__DIR__) . '/data/tts';
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
    return $d;
}

function tts_providers(): array
{
    return [
        'edge' => ['label' => 'Edge 在线语音', 'note' => '微软 Azure Neural 音色，免费无需 Key，需联网；日语音质最好', 'needs' => []],
        'openai' => ['label' => 'OpenAI 语音', 'note' => '需要 OPENAI_API_KEY', 'needs' => ['OPENAI_API_KEY']],
        'azure' => ['label' => 'Azure 语音', 'note' => '需要 AZURE_SPEECH_KEY + AZURE_SPEECH_REGION', 'needs' => ['AZURE_SPEECH_KEY', 'AZURE_SPEECH_REGION']],
        // MiniMax（海螺）：日/泰/中/英都自然，音色自带性格，很适合给虚拟老师配音。
        // 计费按字符：国际站 turbo $60/百万、hd $100/百万；国内站更便宜。
        'minimax' => ['label' => 'MiniMax 海螺', 'note' => '日/泰/中/英极自然，音色带性格；需要 MINIMAX_API_KEY（可选 MINIMAX_GROUP_ID）', 'needs' => ['MINIMAX_API_KEY']],
        // CosyVoice（阿里云百炼）：中文自然度公认第一梯队，且最便宜（v3-flash ≈ ¥100/百万字符）。
        // 64 个系统音色含粤语 3、方言 3（东北/陕西/闽南）、童声 4、台式 1，另有日语 5。
        'cosyvoice' => ['label' => 'CosyVoice 百炼', 'note' => '阿里通义，中文最自然也最便宜（≈¥80-100/百万字符）；需要 DASHSCOPE_API_KEY', 'needs' => ['DASHSCOPE_API_KEY']],
        // 豆包语音 2.0（火山）：音色最多，很多就是抖音/豆包/剪映里听过的声音；比 CosyVoice 贵。
        // ⚠️ Key 来自「火山引擎 → 语音技术控制台 → 创建应用」，和火山方舟大模型的 API Key 不是一回事。
        'doubao' => ['label' => '豆包语音 2.0', 'note' => '火山引擎，100+ 音色（抖音/剪映同款）；需要 DOUBAO_API_KEY（语音技术应用，非方舟 Key）', 'needs' => ['DOUBAO_API_KEY']],
        'browser' => ['label' => '浏览器内置', 'note' => '完全离线，但系统音色质量一般（日语尤其差）', 'needs' => []],
    ];
}

/** 哪些引擎在当前环境是「可用」的（只用环境变量判断，不实际发请求） */
function tts_provider_status(): array
{
    $out = [];
    foreach (tts_providers() as $k => $p) {
        $missing = [];
        foreach ($p['needs'] as $env) {
            $v = $_ENV[$env] ?? getenv($env);
            if (!$v) {
                $missing[] = $env;
            }
        }
        $out[$k] = [
            'id' => $k,
            'label' => $p['label'],
            'note' => $p['note'],
            'needs' => $p['needs'],
            'missing' => $missing,
            'configured' => count($missing) === 0,
        ];
    }
    return $out;
}

/** 跑一次 tts.py，返回解析后的数组 */
function tts_run(array $args, int $timeout = 90): array
{
    $py = langlab_python();
    $script = dirname(__DIR__) . '/scripts/tts.py';
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'tts.py 不存在'];
    }
    $cmd = array_merge([$py, $script], $args);
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

    // 容错：截取第一个 { 到最后一个 }（库的告警可能混进 stdout）
    $a = strpos($out, '{');
    $b = strrpos($out, '}');
    if ($a === false || $b === false || $b < $a) {
        return ['ok' => false, 'error' => '合成器输出无法解析：' . trim(mb_substr($out . ' ' . $errS, 0, 300))];
    }
    $res = json_decode(substr($out, $a, $b - $a + 1), true);
    return is_array($res) ? $res : ['ok' => false, 'error' => '合成结果 JSON 解析失败'];
}

/** 音色列表（带文件缓存） */
function tts_voices(string $provider, string $lang = ''): array
{
    $cache = tts_dir() . '/voices-' . preg_replace('/[^a-z0-9_-]/i', '', $provider)
        . '-' . preg_replace('/[^a-z0-9_-]/i', '', $lang) . '.json';
    // 音色写死在本地的引擎（cosyvoice / doubao）缓存 1 天就够 —— 改了名单能较快生效；
    // 联网拉列表的引擎缓存 7 天，避免每次都去打对方的接口。
    $ttl = in_array($provider, ['cosyvoice', 'doubao'], true) ? 86400 : 7 * 86400;
    if (is_file($cache) && (time() - filemtime($cache)) < $ttl) {
        $j = json_decode((string) file_get_contents($cache), true);
        if (is_array($j) && !empty($j['voices'])) {
            $j['cached'] = true;
            return $j;
        }
    }
    $args = ['--list', '--provider', $provider];
    if ($lang !== '') {
        $args[] = '--lang';
        $args[] = $lang;
    }
    $r = tts_run($args);
    if (!empty($r['ok']) && !empty($r['voices'])) {
        file_put_contents($cache, json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    return $r;
}

/* ---------------- 口音标注：把 en-US 这种 locale 翻译成人话 ----------------
   缓存里存的是原始列表，标注在读取时再做 —— 这样以后改文案不用清缓存。 */

const TTS_LANG_ZH = [
    'en' => '英语', 'ja' => '日语', 'zh' => '中文', 'ko' => '韩语', 'es' => '西班牙语',
    'fr' => '法语', 'de' => '德语', 'it' => '意大利语', 'pt' => '葡萄牙语', 'ru' => '俄语',
    'ar' => '阿拉伯语', 'th' => '泰语', 'vi' => '越南语', 'hi' => '印地语', 'id' => '印尼语',
];

const TTS_REGION_ZH = [
    'US' => '美国', 'GB' => '英国', 'AU' => '澳大利亚', 'CA' => '加拿大', 'IN' => '印度',
    'IE' => '爱尔兰', 'NZ' => '新西兰', 'ZA' => '南非', 'KE' => '肯尼亚', 'NG' => '尼日利亚',
    'TZ' => '坦桑尼亚', 'PH' => '菲律宾', 'SG' => '新加坡', 'MY' => '马来西亚',
    'HK' => '中国香港', 'TW' => '中国台湾', 'CN' => '中国大陆', 'MO' => '中国澳门',
    'JP' => '日本', 'KR' => '韩国', 'ES' => '西班牙', 'MX' => '墨西哥', 'AR' => '阿根廷',
    'CO' => '哥伦比亚', 'CL' => '智利', 'PE' => '秘鲁', 'VE' => '委内瑞拉', 'UY' => '乌拉圭',
    'BR' => '巴西', 'PT' => '葡萄牙', 'FR' => '法国', 'BE' => '比利时', 'CH' => '瑞士',
    'AT' => '奥地利', 'DE' => '德国', 'IT' => '意大利', 'NL' => '荷兰', 'PL' => '波兰',
    'RU' => '俄罗斯', 'UA' => '乌克兰', 'TR' => '土耳其', 'SA' => '沙特', 'EG' => '埃及',
    'AE' => '阿联酋', 'IL' => '以色列', 'TH' => '泰国', 'VN' => '越南', 'ID' => '印度尼西亚',
    'SE' => '瑞典', 'NO' => '挪威', 'DK' => '丹麦', 'FI' => '芬兰', 'CZ' => '捷克',
    'HU' => '匈牙利', 'RO' => '罗马尼亚', 'GR' => '希腊', 'IR' => '伊朗', 'PK' => '巴基斯坦',
    'BD' => '孟加拉', 'LK' => '斯里兰卡', 'NP' => '尼泊尔',
];

/** 英语的常见口音有专门叫法，别写成「美国英语」 */
const TTS_EN_ACCENT = [
    'US' => '美式', 'GB' => '英式', 'AU' => '澳式', 'CA' => '加式',
];

/** 中文的几个方言/地区音色，Edge 用的是 zh-CN-<地区> 这种写法 */
const TTS_ZH_VARIANT = [
    'liaoning' => '东北官话', 'shaanxi' => '中原官话', 'CN' => '普通话',
    'HK' => '粤语', 'TW' => '国语',
];

function tts_accent_of(string $locale): string
{
    $parts = explode('-', str_replace('_', '-', $locale));
    if (count($parts) < 2) {
        return TTS_LANG_ZH[strtolower($parts[0])] ?? $locale;
    }
    $lang = strtolower($parts[0]);
    $region = strtoupper($parts[1]);
    $variant = isset($parts[2]) ? strtolower($parts[2]) : '';

    if ($lang === 'zh') {
        $k = $variant !== '' ? $variant : $region;
        return (TTS_ZH_VARIANT[$k] ?? (TTS_REGION_ZH[$region] ?? $region) . '方言');
    }
    if ($lang === 'en') {
        if (isset(TTS_EN_ACCENT[$region])) {
            return TTS_EN_ACCENT[$region] . '英语';
        }
        return (TTS_REGION_ZH[$region] ?? $region) . '英语';
    }
    if ($lang === 'ja') {
        return $region === 'JP' ? '日语标准语' : (TTS_REGION_ZH[$region] ?? $region) . '日语';
    }
    $ln = TTS_LANG_ZH[$lang] ?? $parts[0];
    $rn = TTS_REGION_ZH[$region] ?? $region;
    return $rn . $ln;
}

/**
 * 给音色列表补上口音标注、排序，并把「和当前素材同口音」的排前面
 * @param string $wantLocale 素材的语言，如 en-US / ja-JP
 */
function tts_label_voices(array $voices, string $wantLocale = ''): array
{
    foreach ($voices as &$v) {
        $loc = (string) ($v['lang'] ?? '');
        $v['locale'] = $loc;
        $v['accent'] = tts_accent_of($loc);
        $v['core'] = ($wantLocale !== '' && strcasecmp($loc, $wantLocale) === 0);
        // 多语言音色是新一代自然音色，能读多种语言，值得单独标出来
        $v['multilingual'] = str_contains((string) ($v['id'] ?? ''), 'Multilingual');
        $sex = $v['gender'] === 'Female' ? '女' : ($v['gender'] === 'Male' ? '男' : '—');
        // 选项文字里带口音，即使列表被拉平（比如浏览器原生下拉）也能看懂
        $v['label'] = ($v['name'] ?? '') . '（' . $sex . '·' . $v['accent'] . '）';
        $v['tag'] = $v['multilingual'] ? '多语言' : '';
    }
    unset($v);
    usort($voices, function ($a, $b) use ($wantLocale) {
        // 同口音 > 多语言 > 其他；同组内女声优先（Azure 主推音色多为女声），再按名字
        $k = fn($x) => [$x['core'] ? 0 : 1, $x['multilingual'] ? 0 : 1,
            ($x['gender'] === 'Female' ? 0 : 1), (string) $x['name']];
        $ka = $k($a);
        $kb = $k($b);
        for ($i = 0; $i < 3; $i++) {
            if ($ka[$i] !== $kb[$i]) {
                return $ka[$i] <=> $kb[$i];
            }
        }
        return strcmp($ka[3], $kb[3]);
    });
    return $voices;
}

/** 按口音分组：给前端做 <optgroup> */
function tts_group_voices(array $voices): array
{
    $groups = [];
    foreach ($voices as $v) {
        $key = $v['locale'];
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'locale' => $key,
                'accent' => $v['accent'],
                'label' => $v['accent'] . ' · ' . $key,
                'core' => $v['core'],
                'voices' => [],
            ];
        }
        $groups[$key]['voices'][] = $v;
    }
    $out = array_values($groups);
    usort($out, fn($a, $b) => ($a['core'] ? 0 : 1) <=> ($b['core'] ? 0 : 1) ?: strcmp($a['locale'], $b['locale']));
    return $out;
}

/**
 * 合成并返回缓存文件路径
 * @return array{ok:bool,path?:string,bytes?:int,cached?:bool,error?:string,hint?:string,ms?:int}
 */
/**
 * 合成并返回缓存文件路径
 *
 * ⚠️ 命中缓存时必须**立刻返回**，绝不能顺手做别的事。
 * 曾经这里有一段「音频在、时间戳不在 → 重跑一次合成把时间戳补上」的逻辑，
 * 结果是：预合成好的 1200+ 个缓存，每次播放都白跑一遍完整合成（2.5–5 秒），
 * 现象是「明明缓存命中了却还是要等 3 秒」（2026-10-03 修）。
 * 时间戳改由 tts_timings() 用 $force 显式补 —— 谁真正需要谁付代价。
 *
 * @return array{ok:bool,path?:string,bytes?:int,cached?:bool,error?:string,hint?:string,ms?:int}
 */
function tts_synthesize(string $provider, string $voice, string $text, string $rate = '+0%', bool $force = false): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => '文本为空'];
    }
    $file = tts_path_for($provider, $voice, $rate, $text);
    $timings = tts_timings_path($file);

    if (!$force && is_file($file) && filesize($file) > 512) {
        return ['ok' => true, 'path' => $file, 'bytes' => (int) filesize($file), 'cached' => true];
    }

    // 并发去重：同一段文本被两个请求同时要（前端并行取音频+时间戳、或用户连点两次），
    // 不做锁就会合成两遍、抢写同一个文件。这里让后到的等前一个写完。
    $lock = $file . '.lock';
    if (is_file($lock) && (time() - filemtime($lock)) < 60) {
        for ($i = 0; $i < 300; $i++) {           // 最多等 30 秒
            usleep(100000);
            if (is_file($file) && filesize($file) > 512) {
                return ['ok' => true, 'path' => $file, 'bytes' => (int) filesize($file), 'cached' => true, 'waited' => true];
            }
            if (!is_file($lock)) {
                break;                            // 前一个失败了，自己来
            }
        }
    }
    @touch($lock);

    $args = ['--provider', $provider, '--voice', $voice, '--text', $text, '--out', $file];
    // 只有 edge 支持词级时间戳（默认只回 SentenceBoundary，得显式要 WordBoundary）
    if ($provider === 'edge') {
        $args[] = '--timings-out';
        $args[] = $timings;
    }
    if ($provider === 'edge' || $provider === 'azure') {
        $args[] = '--rate';
        $args[] = $rate;
    }
    $r = tts_run($args);
    @unlink($lock);

    if (empty($r['ok'])) {
        @unlink($file);   // 半截文件不要留下
        @unlink($timings);
        return ['ok' => false, 'error' => $r['error'] ?? '合成失败', 'hint' => $r['hint'] ?? ''];
    }
    if (!is_file($file) || filesize($file) < 512) {
        return ['ok' => false, 'error' => '合成结果异常（文件过小）'];
    }
    return ['ok' => true, 'path' => $file, 'bytes' => (int) filesize($file), 'cached' => false, 'ms' => $r['ms'] ?? null];
}

/** 缓存文件路径：内容决定，同一段永远同一个文件 */
function tts_path_for(string $provider, string $voice, string $rate, string $text): string
{
    return tts_dir() . '/' . sha1($provider . '|' . $voice . '|' . $rate . '|' . $text) . '.mp3';
}

function tts_timings_path(string $mp3Path): string
{
    return preg_replace('/\.mp3$/', '.words.json', $mp3Path);
}

/**
 * 取词级时间戳；音频已缓存但时间戳缺失时补跑一次合成
 * @return array{ok:bool,words?:array,chars?:int,error?:string}
 */
function tts_timings(string $provider, string $voice, string $text, string $rate = '+0%'): array
{
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => '文本为空'];
    }
    if ($provider !== 'edge') {
        return ['ok' => false, 'error' => '这个引擎不提供词级时间戳'];
    }
    $mp3 = tts_path_for($provider, $voice, $rate, $text);
    $tj = tts_timings_path($mp3);

    if (!is_file($tj)) {
        // 音频在、时间戳不在 → 显式强制重跑一次（$force=true）。
        // 注意：只有真正需要时间戳的人（阅读器的跟读横线）才走到这里；
        // 单纯播放音频的路径在 tts_synthesize 里已经直接返回了，不会为此白等。
        $r = tts_synthesize($provider, $voice, $text, $rate, true);
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => $r['error'] ?? '合成失败'];
        }
    }
    if (!is_file($tj)) {
        return ['ok' => false, 'error' => '该语音没有返回词级时间戳'];
    }
    $j = json_decode((string) file_get_contents($tj), true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => '时间戳解析失败'];
    }
    return ['ok' => true] + $j;
}

/** 轻量清理：缓存文件超过上限就删最旧的（mp3 与同名 json 一起清） */
function tts_gc(int $keep = 4000): int
{
    $files = glob(tts_dir() . '/*.mp3') ?: [];
    if (count($files) <= $keep) {
        return 0;
    }
    usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
    $del = array_slice($files, 0, count($files) - $keep);
    foreach ($del as $f) {
        @unlink($f);
        @unlink(tts_timings_path($f));
    }
    return count($del);
}
