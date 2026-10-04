<?php

/**
 * 剧集字段解析 —— 从素材标题里认出「剧名 / 第几季 / 第几集」。
 *
 * 为什么要有这个：影视类素材是一集一份（`The Good Wife S04E01 …`、`半泽直树2 S02E01`），
 * 想让界面按「类别 → 剧名·第几季 → 每集」三级浏览，就必须先把标题里的结构取出来。
 * 放在这里而不是前端做，是因为**导入时就该落库**——前端再解析一遍迟早两边不一致。
 *
 * 入库字段（materials 表）：
 *   series    剧名（不含季号，如「半泽直树」「The Good Wife」）
 *   season    季号（int）
 *   ep        集号原文（字符串，如 '01' / '07.5'，保留小数用于特别篇）
 *   ep_title  集标题或标记（如 'I Fought the Law' / 'finale' / 'SP'）
 *   ep_sort   排序用（float，'07.5' → 7.5）
 *
 * 认不出来就整组返回 null —— 调用方遇到 null 要当作「不是剧集素材」处理，不要瞎猜。
 */

declare(strict_types=1);

/**
 * 解析标题，返回 ['series'=>?string,'season'=>?int,'ep'=>?string,'ep_title'=>?string,'ep_sort'=>?float]。
 * 不匹配时全部为 null。
 */
function parse_episode(string $title): array
{
    $none = ['series' => null, 'season' => null, 'ep' => null, 'ep_title' => null, 'ep_sort' => null];

    $t = trim($title);
    if ($t === '') {
        return $none;
    }

    // 主模式：…… S04E01 ……（大小写、全半角冒号都容忍；E 后面允许小数，用于 7.5 这种特别篇）
    if (!preg_match('/^(?<head>.*?)[\s·\-–—:：]*\bS\s*(?<season>\d{1,2})\s*E\s*(?<ep>\d{1,3}(?:\.\d+)?)\b(?<tail>.*)$/iu', $t, $m)) {
        // 退一步：只有季没有集（整季打包）—— 也算剧集，但 ep 留空
        if (preg_match('/^(?<head>.*?)[\s·\-–—:：]*\bS\s*(?<season>\d{1,2})\b(?<tail>.*)$/iu', $t, $m2)) {
            $series = normalize_series($m2['head'], (int) $m2['season']);
            if ($series === '') {
                return $none;
            }
            return [
                'series' => $series,
                'season' => (int) $m2['season'],
                'ep' => null,
                'ep_title' => trim_tail($m2['tail']),
                'ep_sort' => null,
            ];
        }
        return $none;
    }

    $season = (int) $m['season'];
    $series = normalize_series($m['head'], $season);
    if ($series === '') {
        return $none;
    }

    $ep = $m['ep'];
    // '01' 保留两位；'7.5' 不动；'100' 不动
    $epNum = (float) $ep;
    if (strpos($ep, '.') === false && strlen($ep) < 2) {
        $ep = str_pad($ep, 2, '0', STR_PAD_LEFT);
    }

    return [
        'series' => $series,
        'season' => $season,
        'ep' => $ep,
        'ep_title' => trim_tail($m['tail']),
        'ep_sort' => $epNum,
    ];
}

/**
 * 剧名规范化：主要处理「半泽直树2 S02E01」这种**剧名里带季号**的写法。
 * 只有在尾部数字正好等于季号时才剥掉，避免误伤「SEAL Team 6」这类真名字。
 */
function normalize_series(string $head, int $season): string
{
    $s = trim($head);
    // 去掉包裹的书名号/引号
    $s = preg_replace('/^[《「『"\'（(]+|[》」』"\'）)]+$/u', '', $s) ?? $s;
    $s = trim($s, " \t·-–—:：");
    if ($s === '') {
        return '';
    }
    // 尾部数字 == 季号 → 剥掉（'半泽直树2' + S02 → '半泽直树'）
    if (preg_match('/^(?<base>.+?)\s*[·\-–—]?\s*0*(?<n>\d{1,2})$/u', $s, $m)
        && (int) $m['n'] === $season
        && $m['base'] !== '') {
        $base = trim($m['base'], " \t·-–—");
        if ($base !== '') {
            return $base;
        }
    }
    return $s;
}

/**
 * 集标题清理：把 `(finale)` / `- The Seven Day Rule` / `第1話 …` 之类整理成纯文本。
 */
function trim_tail(string $tail): ?string
{
    $s = trim($tail);
    $s = trim($s, " \t·-–—:：,，");
    // 全括号包裹 → 取括号内
    if (preg_match('/^[（(]\s*(.+?)\s*[)）]$/u', $s, $m)) {
        $s = $m[1];
    }
    // 去掉残留的空括号
    $s = preg_replace('/[（(]\s*[)）]/u', '', $s) ?? $s;
    $s = trim($s, " \t·-–—:：,，");
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, 120, 'UTF-8');
}

/**
 * 把解析结果写回 materials（幂等；title 变化时会重算）。
 */
function apply_episode_fields(int $materialId): void
{
    $row = \Illuminate\Database\Capsule\Manager::table('materials')->find($materialId);
    if (!$row) {
        return;
    }
    $p = parse_episode((string) $row->title);
    \Illuminate\Database\Capsule\Manager::table('materials')->where('id', $materialId)->update([
        'series' => $p['series'],
        'season' => $p['season'],
        'ep' => $p['ep'],
        'ep_title' => $p['ep_title'],
        'ep_sort' => $p['ep_sort'],
    ]);
}

/**
 * 显示用集号标签：`第 4 集` / `特别篇` / `最终回`。
 * 前端也有一份同样的规则（保持字面一致即可），这里主要给后端脚本/导出用。
 */
function episode_label(?string $ep, ?string $epTitle): string
{
    $t = (string) $epTitle;
    if ($t !== '') {
        if (preg_match('/finale|最终|最終/u', $t)) {
            return '最终回';
        }
        if (preg_match('/^sp$|special|特别|特別|生放送/u', $t)) {
            return '特别篇';
        }
    }
    if ($ep === null || $ep === '') {
        return '全季';
    }
    $n = (float) $ep;
    return '第 ' . (fmod($n, 1.0) === 0.0 ? (string) (int) $n : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.')) . ' 集';
}
