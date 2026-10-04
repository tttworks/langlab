<?php

/**
 * 导入演示卡片（示例数据）
 *
 *   php scripts/seed_demo.php
 *
 * 为什么有这个脚本：本仓库**不含任何真实语料**（见 README「数据要自己灌」）。
 * 这组示例是**人工编写**的通用法律英语（句型模板 + 概念），以 CC0 发布，
 * 让你 clone 下来就能立刻看到系统长什么样。
 *
 * 幂等：已存在的课程 / 卡组 / 卡片会跳过，可反复跑。
 */

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$file = __DIR__ . '/../data/demo_cards.json';
if (!is_file($file)) {
    fwrite(STDERR, "找不到 $file\n");
    exit(1);
}
$d = json_decode((string) file_get_contents($file), true);
if (!is_array($d) || empty($d['cards'])) {
    fwrite(STDERR, "demo_cards.json 解析失败\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');
$done = [];
$skip = [];

// ── 1. 课程（不存在就建一个最小的）─────────────────────────────
$courseId = (string) $d['course_id'];
$langCode = (string) $d['lang_code'];
if (!Capsule::table('courses')->where('id', $courseId)->exists()) {
    Capsule::table('courses')->insert([
        'id'          => $courseId,
        'lang_code'   => $langCode,
        'name'        => '合同英语（示例）',
        'kind'        => 'contract',
        'description' => '演示课程 —— 通用法律英语句型与概念，不含任何真实合同内容。',
        'config'      => json_encode(['source' => 'demo data'], JSON_UNESCAPED_UNICODE),
        'sort'        => 99,
        'created_at'  => $now,
    ]);
    $done[] = "课程 $courseId";
} else {
    $skip[] = "课程 $courseId";
}

// ── 2. 卡组 ────────────────────────────────────────────────────
foreach ($d['sets'] ?? [] as $s) {
    if (Capsule::table('card_sets')->where('id', $s['id'])->exists()) {
        $skip[] = "卡组 {$s['id']}";
        continue;
    }
    Capsule::table('card_sets')->insert([
        'id'           => $s['id'],
        'course_id'    => $s['course_id'],
        'lang_code'    => $s['lang_code'],
        'title'        => $s['title'],
        'sub'          => $s['sub'] ?? null,
        'kind'         => $s['kind'],
        'cats'         => json_encode($s['cats'] ?: new stdClass(), JSON_UNESCAPED_UNICODE),
        'sort'         => $s['sort'] ?? 0,
        'release_week' => 1,
    ]);
    $done[] = "卡组 {$s['id']}";
}

// ── 3. 卡片 + 进度行 ───────────────────────────────────────────
$nCard = 0;
$nSkip = 0;
foreach ($d['cards'] as $c) {
    $exists = Capsule::table('cards')
        ->where('set_id', $c['set_id'])
        ->where('card_key', $c['key'])
        ->exists();
    if ($exists) {
        $nSkip++;
        continue;
    }

    $id = Capsule::table('cards')->insertGetId([
        'set_id'     => $c['set_id'],
        'course_id'  => $c['course_id'],
        'lang_code'  => $c['lang_code'],
        'kind'       => $c['kind'],
        'card_key'   => $c['key'],
        'sort'       => $c['sort'] ?? 0,
        'data'       => json_encode($c['data'], JSON_UNESCAPED_UNICODE),
        'created_at' => $now,
    ]);

    // ⚠️ 新卡必须同时建一行进度行，否则不进复习队列
    //    （自建卡只插 cards 不插 card_progress，曾导致一复习就报错 —— 见踩坑记录）
    Capsule::table('card_progress')->insert([
        'card_id'        => $id,
        'state'          => 'new',
        'due_date'       => $today,
        'ease'           => 2.5,
        'interval'       => 0,
        'repetitions'    => 0,
        'lapses'         => 0,
        'review_count'   => 0,
        'last_review_at' => null,
        'updated_at'     => $now,
    ]);
    $nCard++;
}

echo "== demo 导入完成 ==\n";
if ($done) {
    echo '已新增：' . implode('、', $done) . "\n";
}
if ($skip) {
    echo '已存在：' . implode('、', $skip) . "\n";
}
echo "卡片：新增 {$nCard} 张，跳过 {$nSkip} 张\n";
echo "\n打开卡片学习器 → 选择课程「合同英语（示例）」即可看到这 {$nCard} 张卡。\n";
