<?php

/**
 * 维护脚本：确保课程存在（幂等，不动其他数据）
 * 用法：D:\php82\php.exe scripts\ensure_courses.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$now = date('Y-m-d H:i:s');

$courses = [
    [
        'id' => 'en-contract', 'lang_code' => 'en-US', 'name' => '合同英语', 'kind' => 'contract', 'sort' => 1,
        'description' => '以 某商业合同为语料，目标是能读懂、能谈判、能改条款。',
        'config' => json_encode(['source' => 'a sample commercial agreement', 'law' => 'Sample Jurisdiction'], JSON_UNESCAPED_UNICODE),
    ],
    [
        'id' => 'en-general', 'lang_code' => 'en-US', 'name' => '通用英语阅读', 'kind' => 'reader', 'sort' => 2,
        'description' => '预留：读物、文章、逐字稿的通用阅读线。',
        'config' => null,
    ],
    [
        'id' => 'en-drama', 'lang_code' => 'en-US', 'name' => '美剧台词', 'kind' => 'script', 'sort' => 3,
        'description' => '影视台词本。口语、俚语、法庭与职场对话的真实语料——和合同英语完全不同的语域。',
        'config' => json_encode(['source' => 'TV / film transcripts', 'note' => '按「每行一句」切段'], JSON_UNESCAPED_UNICODE),
    ],
    [
        'id' => 'en-speech', 'lang_code' => 'en-US', 'name' => '著名演讲', 'kind' => 'speech', 'sort' => 4,
        'description' => '公有领域的经典演讲。修辞密度最高的英语——排比、对照、呼告，适合朗读与背诵。',
        'config' => json_encode(['source' => 'Wikisource (public domain)'], JSON_UNESCAPED_UNICODE),
    ],
    [
        'id' => 'ja-drama', 'lang_code' => 'ja-JP', 'name' => '日剧台词', 'kind' => 'script', 'sort' => 2,
        'description' => '日剧字幕台词。职场口语、敬语与关西方言混杂的真实日语，按行切段。',
        'config' => json_encode(['source' => 'Japanese subtitles'], JSON_UNESCAPED_UNICODE),
    ],
    [
        'id' => 'ja-standard', 'lang_code' => 'ja-JP', 'name' => '日语标准语', 'kind' => 'textbook', 'sort' => 1,
        'description' => '日语标准语（共通語）主线。',
        'config' => null,
    ],
];

echo "== ensure_courses ==\n";
foreach ($courses as $c) {
    $hit = Capsule::table('courses')->where('id', $c['id'])->first();
    if ($hit) {
        echo "  已有 " . $c['id'] . "（" . $hit->name . "）\n";
        continue;
    }
    $c['created_at'] = $now;
    Capsule::table('courses')->insert($c);
    echo "  + 新建 " . $c['id'] . "（" . $c['name'] . "）\n";
}

echo "\n课程总数：" . Capsule::table('courses')->count() . "\n";
foreach (Capsule::table('courses')->orderBy('lang_code')->orderBy('sort')->get() as $c) {
    printf("  %-12s %-10s %s\n", $c->id, $c->lang_code, $c->name);
}
