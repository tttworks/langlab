<?php

/**
 * 增量迁移（幂等，可反复跑，不会清空数据）
 *
 *   D:\php82\php.exe scripts\migrate.php
 *
 * 规范：表结构一旦开始有真实数据，就只走这里 ADD COLUMN / CREATE INDEX，
 *       不要再跑 init.php（那个会 DROP 重建）。
 */

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$done = [];
$skip = [];

function columns(string $t): array
{
    $cols = [];
    foreach (Capsule::select("PRAGMA table_info($t)") as $c) {
        $cols[$c->name] = $c;
    }
    return $cols;
}

function hasTable(string $t): bool
{
    foreach (Capsule::select("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
        if ($r->name === $t) {
            return true;
        }
    }
    return false;
}

echo "== langlab migrate ==\n";

// segments.chapter —— 按章分组显示「精读」视图
if (hasTable('segments')) {
    $cols = columns('segments');
    if (!isset($cols['chapter'])) {
        Capsule::statement('ALTER TABLE segments ADD COLUMN chapter TEXT');
        $done[] = 'segments.chapter';
    } else {
        $skip[] = 'segments.chapter';
    }
}

// segments：时间轴（音视频字幕）、说话人、对照译文
// translation 用于双语语料（如日语原文 + 中译同段对位），阅读器可并排/对照显示
if (hasTable('segments')) {
    $cols = columns('segments');
    $newCols = [
        'start_sec'   => 'REAL',   // 起始秒
        'end_sec'     => 'REAL',   // 结束秒
        'speaker'     => 'TEXT',   // 说话人
        'translation' => 'TEXT',   // 对照译文（与 text 同一段）
    ];
    foreach ($newCols as $c => $type) {
        if (!isset($cols[$c])) {
            Capsule::statement("ALTER TABLE segments ADD COLUMN $c $type");
            $done[] = "segments.$c";
        } else {
            $skip[] = "segments.$c";
        }
    }
}

// card_sets.release_week —— v2 分阶段放行：按计划第几周逐步放出卡组
// 避免首日 800+ 张全新卡把复习队列压垮（用户有外语焦虑，被淹没＝放弃）
if (hasTable('card_sets')) {
    $cols = columns('card_sets');
    if (!isset($cols['release_week'])) {
        Capsule::statement('ALTER TABLE card_sets ADD COLUMN release_week INTEGER DEFAULT 1');
        $done[] = 'card_sets.release_week';
        // 回填：默认全放行到第 1 周之外的按"分阶段放行方案"设好
        $plan = [
            // 第 1-2 周：半年内真要用到的（投资术语 / 中文多形陷阱 / 复述框架 / 商务语块）
            'terms-invest' => 1, 'cn-one-to-many' => 1, 'frames-retell' => 1, 'phrases-deal' => 1,
            // 第 3 周：字形锚点 + 尽调提问
            'nouns-abstract' => 3, 'dd-questions' => 3,
            // 第 5 周：合同语域（术语先行，词汇随后）
            'terms-asd' => 5, 'collocations' => 6, 'patterns' => 6,
            'chinglish' => 7, 'concepts' => 7,
            // 第 9 周：合同单词卡（量最大，最后放）
            'words-asd' => 9,
            // 批注卡始终放行（用户自己划的）
            'anno-en-contract' => 1,
            'phrases-ja' => 1,
        ];
        foreach ($plan as $sid => $wk) {
            Capsule::table('card_sets')->where('id', $sid)->update(['release_week' => $wk]);
        }
        $done[] = 'card_sets.release_week 回填（' . count($plan) . ' 个卡组）';
    } else {
        $skip[] = 'card_sets.release_week';
    }
}

// materials: 便于按「是否有原文文件」筛选
if (hasTable('materials')) {
    $cols = columns('materials');
    if (!isset($cols['file_size'])) {
        Capsule::statement('ALTER TABLE materials ADD COLUMN file_size INTEGER');
        $done[] = 'materials.file_size';
        // 回填已有记录
        foreach (Capsule::table('materials')->whereNotNull('attachment_path')->get() as $m) {
            if (is_file((string) $m->attachment_path)) {
                Capsule::table('materials')->where('id', $m->id)->update(['file_size' => filesize((string) $m->attachment_path)]);
            }
        }
    } else {
        $skip[] = 'materials.file_size';
    }
}

// 数据回填：file_size（每次跑都对齐一次，幂等）
if (hasTable('materials')) {
    $n = 0;
    foreach (Capsule::table('materials')->whereNotNull('attachment_path')->get() as $m) {
        $p = (string) $m->attachment_path;
        $sz = is_file($p) ? filesize($p) : null;
        if ($sz !== null && (int) $m->file_size !== $sz) {
            Capsule::table('materials')->where('id', $m->id)->update(['file_size' => $sz]);
            $n++;
        }
    }
    if ($n) {
        $done[] = "回填 file_size（{$n} 条）";
    }
}

// 剧集字段：支持「类别 → 剧名·第几季 → 每集」三级浏览
// 注意：必须在建索引之前执行（索引里用到了 series/season/ep_sort）
if (hasTable('materials')) {
    $cols = columns('materials');
    $newCols = [
        'series' => 'TEXT',        // 剧名（不含季号）
        'season' => 'INTEGER',     // 季号
        'ep' => 'TEXT',            // 集号原文（'01' / '07.5'）
        'ep_title' => 'TEXT',      // 集标题或标记（'finale' / 'SP'）
        'ep_sort' => 'REAL',       // 排序用集号
        // ⚠️ init.php 建表时没有这一列，而 /api/materials/{id} 直接读它
        //    → 缺列会让 PHP 抛 Warning 混进 JSON，前端 JSON.parse 失败、阅读器整页打不开。
        'video_path' => 'TEXT',    // 影视跟读：视频文件路径
    ];
    foreach ($newCols as $c => $type) {
        if (!isset($cols[$c])) {
            Capsule::statement("ALTER TABLE materials ADD COLUMN $c $type");
            $done[] = "materials.$c";
        } else {
            $skip[] = "materials.$c";
        }
    }
}

// 卡片复习调度（SM-2）：给 card_progress 增加调度字段
// 必须在建索引之前执行（与 materials 剧集字段同理）
if (hasTable('card_progress')) {
    $cols = columns('card_progress');
    $newCols = [
        'due_date'    => 'TEXT',     // 到期复习日（Y-m-d），<= 今天即应复习
        'ease'        => 'REAL',     // 易度因子 EF，默认 2.5
        'interval'    => 'INTEGER',  // 当前间隔（天）
        'repetitions' => 'INTEGER',  // 连续答对次数
        'lapses'      => 'INTEGER',  // 失手次数
        // ↓ FSRS 预留字段（2026-10-04 · 档 1：只建位，不换调度）
        //   SM-2 仍照旧计算；这三列先空着，等切 FSRS 时写入，之后可拟合个性化参数。
        'stability'      => 'REAL',  // 记忆稳定性 S（天）
        'difficulty'     => 'REAL',  // 难度 D（1–10），与 S 解耦
        'retrievability' => 'REAL',  // 上次复习时的可提取率 R
    ];
    foreach ($newCols as $c => $type) {
        if (!isset($cols[$c])) {
            Capsule::statement("ALTER TABLE card_progress ADD COLUMN $c $type");
            $done[] = "card_progress.$c";
        } else {
            $skip[] = "card_progress.$c";
        }
    }

    // 回填：所有卡都纳入统一调度（路线 A 全卡统一）
    $today = date('Y-m-d');
    $future = date('Y-m-d', strtotime('+3650 days'));

    // 1) 已有进度行：把 NULL 字段补成默认值
    foreach (Capsule::table('card_progress')->get() as $row) {
        $up = [];
        if ($row->due_date === null) {
            $up['due_date'] = ($row->state === 'mastered') ? $future : $today;
        }
        if ($row->ease === null)         $up['ease'] = 2.5;
        if ($row->interval === null)      $up['interval'] = 0;
        if ($row->repetitions === null)  $up['repetitions'] = 0;
        if ($row->lapses === null)       $up['lapses'] = 0;
        if ($up) {
            Capsule::table('card_progress')->where('card_id', $row->card_id)->update($up);
        }
    }

    // 2) 完全没有进度行的卡（如新同步的批注卡）也建一行，默认今天到期 → 进入复习队列
    $have = Capsule::table('card_progress')->pluck('card_id')->all();
    $haveSet = array_flip($have);
    $n = 0;
    foreach (Capsule::table('cards')->select('id')->get() as $c) {
        if (isset($haveSet[$c->id])) {
            continue;
        }
        Capsule::table('card_progress')->insert([
            'card_id'        => $c->id,
            'state'          => 'new',
            'due_date'       => $today,
            'ease'           => 2.5,
            'interval'       => 0,
            'repetitions'   => 0,
            'lapses'         => 0,
            'review_count'   => 0,
            'last_review_at' => null,
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        $n++;
    }
    if ($n) {
        $done[] = "补建进度行（{$n} 张卡）";
    }
}

// 索引
$idx = [];
foreach (Capsule::select("SELECT name FROM sqlite_master WHERE type='index'") as $r) {
    $idx[$r->name] = true;
}
$want = [
    'idx_segments_chapter' => 'CREATE INDEX idx_segments_chapter ON segments (material_id, chapter)',
    'idx_dict_freq' => 'CREATE INDEX idx_dict_freq ON dict_entries (lang_code, freq)',
    'idx_materials_series' => 'CREATE INDEX idx_materials_series ON materials (course_id, series, season, ep_sort)',
];
foreach ($want as $name => $sql) {
    if (!isset($idx[$name])) {
        Capsule::statement($sql);
        $done[] = $name;
    } else {
        $skip[] = $name;
    }
}

// 剧集字段回填/校正：每次跑都对齐一次（幂等）。只处理能解析出剧集的素材，
// 解析不出来的保持原样，避免把「整季打包」误清成空。
if (hasTable('materials')) {
    require_once __DIR__ . '/../src/episode.php';
    $n = 0;
    foreach (Capsule::table('materials')->get() as $m) {
        $p = parse_episode((string) $m->title);
        if ($p['series'] === null) {
            continue;
        }
        $same = $m->series === $p['series']
            && (int) $m->season === (int) $p['season']
            && (string) $m->ep === (string) $p['ep']
            && (string) $m->ep_title === (string) $p['ep_title']
            && (float) $m->ep_sort === (float) $p['ep_sort'];
        if ($same) {
            continue;
        }
        Capsule::table('materials')->where('id', $m->id)->update([
            'series' => $p['series'],
            'season' => $p['season'],
            'ep' => $p['ep'],
            'ep_title' => $p['ep_title'],
            'ep_sort' => $p['ep_sort'],
        ]);
        $n++;
    }
    if ($n) {
        $done[] = "回填剧集字段（{$n} 条）";
    } else {
        $skip[] = '剧集字段回填（已是最新）';
    }
}

// ---------------------------------------------------------------- 虚拟老师：多会话历史
// 一次对话 = 一个 session（自带标题、语言、角色），消息逐条落库，可回看、可继续。
if (!hasTable('teacher_sessions')) {
    Capsule::statement('CREATE TABLE teacher_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        lang TEXT,
        char_id TEXT,
        explain_mode TEXT,
        created_at TEXT,
        updated_at TEXT
    )');
    $done[] = 'teacher_sessions';
} else {
    $skip[] = 'teacher_sessions';
}

if (!hasTable('teacher_messages')) {
    Capsule::statement('CREATE TABLE teacher_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        content TEXT,
        zh TEXT,
        corrections TEXT,
        tools TEXT,
        created_at TEXT
    )');
    Capsule::statement('CREATE INDEX idx_tm_session ON teacher_messages(session_id)');
    $done[] = 'teacher_messages';
} else {
    $skip[] = 'teacher_messages';
}

// ---------------------------------------------------------------- 复习流水（revlog）
// 2026-10-04 · 档 1：**只记录，不参与调度**（调度仍是 SM-2）。
// 为什么先建：FSRS 要 1000+ 条复习记录才能拟合个性化参数，
//   而记录是整条路线里**唯一「过期作废」的资产** —— 今天不记，今天的复习就永远丢了。
// 最小可用字段 = card_id + rating + reviewed_at；其余为复盘/调试用。
if (!hasTable('card_reviews')) {
    Capsule::statement('CREATE TABLE card_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        card_id INTEGER NOT NULL,
        rating INTEGER NOT NULL,
        reviewed_at TEXT NOT NULL,
        state_before TEXT,
        interval_before INTEGER,
        ease_before REAL,
        interval_after INTEGER,
        due_after TEXT,
        source TEXT,
        created_at TEXT
    )');
    Capsule::statement('CREATE INDEX idx_cr_card ON card_reviews(card_id, reviewed_at)');
    $done[] = 'card_reviews';
} else {
    $skip[] = 'card_reviews';
}

echo $done ? "已新增：" . implode(', ', $done) . "\n" : "没有需要新增的结构\n";
echo $skip ? "已存在：" . implode(', ', $skip) . "\n" : '';

echo "\n== 复核 ==\n";
foreach (['segments', 'materials'] as $t) {
    if (hasTable($t)) {
        echo "  $t: " . implode(', ', array_keys(columns($t))) . "\n";
    }
}
if (hasTable('materials')) {
    echo "\n== 已识别的剧集 ==\n";
    foreach (Capsule::select("SELECT course_id, series, season, COUNT(*) n FROM materials WHERE series IS NOT NULL GROUP BY course_id, series, season ORDER BY course_id, series, season") as $r) {
        echo "  [{$r->course_id}] {$r->series} · 第 {$r->season} 季 —— {$r->n} 集\n";
    }
}
echo "\n完成。\n";
