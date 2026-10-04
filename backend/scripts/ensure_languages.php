<?php

/**
 * 维护脚本：对齐语言的显示名（幂等，只改这几个字段，不动其他数据）
 *
 * 命名口径：**语言层只放语言名**（英语 / 日语 / …），"标准语 / 美式"这类变体信息归到课程层。
 * 用法：D:\php82\php.exe scripts\ensure_languages.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

use Illuminate\Database\Capsule\Manager as Capsule;

// 只列要改的；没列的字段保持原样
$want = [
    'ja-JP' => ['name_zh' => '日语', 'name_native' => '日本語'],
];

echo "== ensure_languages ==\n";
foreach ($want as $code => $fields) {
    $row = Capsule::table('languages')->where('code', $code)->first();
    if (!$row) {
        echo "  ! 语言不存在：{$code}\n";
        continue;
    }
    $diff = [];
    foreach ($fields as $k => $v) {
        if ((string) $row->$k !== $v) {
            $diff[$k] = $v;
        }
    }
    if (!$diff) {
        echo "  已是目标值：{$code}\n";
        continue;
    }
    Capsule::table('languages')->where('code', $code)->update($diff);
    echo "  ✓ {$code}：" . $row->name_zh . " → " . $fields['name_zh'] . "\n";
}

echo "\n语言现状：\n";
foreach (Capsule::table('languages')->orderBy('sort')->get() as $l) {
    printf("  %-7s %-16s %s\n", $l->code, $l->name_zh, $l->name_native ?: '');
}

echo "\n课程现状（子选项应保持不变）：\n";
foreach (Capsule::table('courses')->orderBy('lang_code')->orderBy('sort')->get() as $c) {
    printf("  %-12s %-7s %s\n", $c->id, $c->lang_code, $c->name);
}
