<?php

/**
 * langlab 数据库初始化 / 重置
 *
 *   D:\php82\php.exe scripts\init.php
 *
 * ⚠️ 会 DROP 并重建全部表（现有学习进度会清空）。
 * 正式使用后请改用增量迁移，不要直接 drop。
 */

declare(strict_types=1);

require __DIR__ . '/../src/db.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$schema = Capsule::schema();
$now = date('Y-m-d H:i:s');

$TABLES = [
    'settings', 'study_sessions', 'notes', 'dict_occurrences', 'dict_entries',
    'annotations', 'segments', 'materials', 'card_progress', 'cards',
    'card_sets', 'courses', 'languages',
];

echo "== langlab init ==\n";

foreach ($TABLES as $t) {
    $schema->dropIfExists($t);
}
echo "已清空旧表（" . count($TABLES) . " 张）\n";

// ---------------------------------------------------------------- languages
Capsule::statement('CREATE TABLE languages (
    code TEXT PRIMARY KEY,
    name_zh TEXT NOT NULL,
    name_native TEXT,
    script TEXT,
    tts_lang TEXT,
    tokenizer TEXT,
    enabled INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0,
    note TEXT
)');

// ---------------------------------------------------------------- courses
Capsule::statement('CREATE TABLE courses (
    id TEXT PRIMARY KEY,
    lang_code TEXT NOT NULL,
    name TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT "custom",
    description TEXT,
    config TEXT,
    sort INTEGER NOT NULL DEFAULT 0,
    created_at TEXT
)');

// ---------------------------------------------------------------- card_sets / cards
Capsule::statement('CREATE TABLE card_sets (
    id TEXT PRIMARY KEY,
    course_id TEXT NOT NULL,
    lang_code TEXT NOT NULL,
    title TEXT NOT NULL,
    sub TEXT,
    kind TEXT NOT NULL,
    cats TEXT,
    sort INTEGER NOT NULL DEFAULT 0
)');

Capsule::statement('CREATE TABLE cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    set_id TEXT NOT NULL,
    course_id TEXT NOT NULL,
    lang_code TEXT NOT NULL,
    kind TEXT NOT NULL,
    card_key TEXT NOT NULL,
    sort INTEGER NOT NULL DEFAULT 0,
    data TEXT NOT NULL,
    created_at TEXT,
    UNIQUE (set_id, card_key)
)');
Capsule::statement('CREATE INDEX idx_cards_course ON cards (course_id)');
Capsule::statement('CREATE INDEX idx_cards_set ON cards (set_id)');

Capsule::statement('CREATE TABLE card_progress (
    card_id INTEGER PRIMARY KEY,
    state TEXT NOT NULL DEFAULT "new",
    review_count INTEGER NOT NULL DEFAULT 0,
    last_review_at TEXT,
    updated_at TEXT
)');

// ---------------------------------------------------------------- materials / segments
Capsule::statement('CREATE TABLE materials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id TEXT,
    lang_code TEXT NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    author TEXT,
    source TEXT,
    source_url TEXT,
    attachment_path TEXT,
    cover TEXT,
    meta TEXT,
    progress TEXT,
    status TEXT NOT NULL DEFAULT "ready",
    word_count INTEGER NOT NULL DEFAULT 0,
    segment_count INTEGER NOT NULL DEFAULT 0,
    file_size INTEGER,
    created_at TEXT
)');
Capsule::statement('CREATE INDEX idx_materials_course ON materials (course_id)');
Capsule::statement('CREATE INDEX idx_materials_lang ON materials (lang_code)');

Capsule::statement('CREATE TABLE segments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    material_id INTEGER NOT NULL,
    seq INTEGER NOT NULL,
    locator TEXT,
    text TEXT NOT NULL,
    char_count INTEGER NOT NULL DEFAULT 0,
    tokens TEXT,
    chapter TEXT,
    start_sec REAL,
    end_sec REAL,
    speaker TEXT,
    translation TEXT,
    UNIQUE (material_id, seq)
)');
Capsule::statement('CREATE INDEX idx_segments_material ON segments (material_id)');
Capsule::statement('CREATE INDEX idx_segments_chapter ON segments (material_id, chapter)');

// ---------------------------------------------------------------- annotations / notes
Capsule::statement('CREATE TABLE annotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    material_id INTEGER NOT NULL,
    segment_id INTEGER,
    kind TEXT NOT NULL DEFAULT "word",
    start_off INTEGER,
    end_off INTEGER,
    target_text TEXT NOT NULL,
    gloss TEXT,
    note TEXT,
    tags TEXT,
    created_at TEXT
)');
Capsule::statement('CREATE INDEX idx_annotations_material ON annotations (material_id)');

Capsule::statement('CREATE TABLE notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lang_code TEXT NOT NULL,
    course_id TEXT,
    material_id INTEGER,
    segment_id INTEGER,
    annotation_id INTEGER,
    title TEXT,
    content TEXT NOT NULL,
    tags TEXT,
    day TEXT,
    created_at TEXT
)');
Capsule::statement('CREATE INDEX idx_notes_lang ON notes (lang_code)');
Capsule::statement('CREATE INDEX idx_notes_day ON notes (day)');

// ---------------------------------------------------------------- dictionary
Capsule::statement('CREATE TABLE dict_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lang_code TEXT NOT NULL,
    lemma TEXT NOT NULL,
    display TEXT,
    reading TEXT,
    pos TEXT,
    gloss_zh TEXT,
    gloss_en TEXT,
    level TEXT,
    freq INTEGER NOT NULL DEFAULT 0,
    is_term INTEGER NOT NULL DEFAULT 0,
    source TEXT NOT NULL DEFAULT "auto",
    created_at TEXT,
    UNIQUE (lang_code, lemma)
)');
Capsule::statement('CREATE INDEX idx_dict_lang ON dict_entries (lang_code)');
Capsule::statement('CREATE INDEX idx_dict_term ON dict_entries (is_term)');

Capsule::statement('CREATE TABLE dict_occurrences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id INTEGER NOT NULL,
    material_id INTEGER,
    segment_id INTEGER,
    surface TEXT,
    start_off INTEGER
)');
Capsule::statement('CREATE INDEX idx_occ_entry ON dict_occurrences (entry_id)');
Capsule::statement('CREATE INDEX idx_occ_material ON dict_occurrences (material_id)');

// ---------------------------------------------------------------- sessions / settings
Capsule::statement('CREATE TABLE study_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lang_code TEXT NOT NULL,
    course_id TEXT,
    kind TEXT NOT NULL,
    ref_id TEXT,
    item_count INTEGER NOT NULL DEFAULT 0,
    duration_sec INTEGER NOT NULL DEFAULT 0,
    day TEXT NOT NULL,
    created_at TEXT
)');
Capsule::statement('CREATE INDEX idx_sessions_day ON study_sessions (day)');
Capsule::statement('CREATE INDEX idx_sessions_lang ON study_sessions (lang_code)');

Capsule::statement('CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT
)');

echo "表结构已建立\n";

// ================================================================ 种子数据

// ---- 语言
Capsule::table('languages')->insert([
    [
        'code' => 'en-US', 'name_zh' => '英语 · 美式标准语', 'name_native' => 'American English',
        'script' => 'latin', 'tts_lang' => 'en-US', 'tokenizer' => 'simple', 'enabled' => 1, 'sort' => 1,
        'note' => null,
    ],
    [
        'code' => 'ja-JP', 'name_zh' => '日语', 'name_native' => '日本語',
        'script' => 'japanese', 'tts_lang' => 'ja-JP', 'tokenizer' => 'sudachi', 'enabled' => 1, 'sort' => 2,
        'note' => '形态素解析走服务端 SudachiPy（未安装时字典功能自动降级）',
    ],
    [
        'code' => 'es-ES', 'name_zh' => '西班牙语', 'name_native' => 'Español',
        'script' => 'latin', 'tts_lang' => 'es-ES', 'tokenizer' => 'simple', 'enabled' => 1, 'sort' => 8,
        'note' => '预留：尚未添加课程',
    ],
    [
        'code' => 'th-TH', 'name_zh' => '泰语', 'name_native' => 'ไทย',
        'script' => 'thai', 'tts_lang' => 'th-TH', 'tokenizer' => 'none', 'enabled' => 1, 'sort' => 9,
        'note' => '预留：泰语无词间空格，需要专门的分词器，暂未接入',
    ],
]);

// ---- 课程
Capsule::table('courses')->insert([
    [
        'id' => 'en-contract', 'lang_code' => 'en-US', 'name' => '合同英语',
        'kind' => 'contract', 'sort' => 1, 'created_at' => $now,
        'description' => '以 某商业合同为语料，目标是能读懂、能谈判、能改条款。',
        'config' => json_encode(['source' => 'a sample commercial agreement', 'law' => 'Sample Jurisdiction'], JSON_UNESCAPED_UNICODE),
    ],
    [
        'id' => 'ja-standard', 'lang_code' => 'ja-JP', 'name' => '日语标准语',
        'kind' => 'textbook', 'sort' => 1, 'created_at' => $now,
        'description' => '日语标准语（共通語）主线。骨架已就位，待导入教材与素材。',
        'config' => null,
    ],
    [
        'id' => 'en-general', 'lang_code' => 'en-US', 'name' => '通用英语阅读',
        'kind' => 'reader', 'sort' => 2, 'created_at' => $now,
        'description' => '预留：读物、文章、逐字稿的通用阅读线，等素材导入后启用。',
        'config' => null,
    ],
    [
        'id' => 'en-drama', 'lang_code' => 'en-US', 'name' => '美剧台词',
        'kind' => 'script', 'sort' => 3, 'created_at' => $now,
        'description' => '影视台词本。口语、俚语、法庭与职场对话的真实语料——和合同英语完全不同的语域。',
        'config' => json_encode(['source' => 'TV / film transcripts', 'note' => '按「每行一句」切段'], JSON_UNESCAPED_UNICODE),
    ],
]);

// ---- 卡片集 + 卡片（从旧资料库迁移）
$seedFile = __DIR__ . '/../data/cards_seed.json';
if (!is_file($seedFile)) {
    echo "!! 未找到 cards_seed.json，跳过卡片迁移。\n";
    echo "   先跑： node scripts/export_cards.mjs\n";
} else {
    $seed = json_decode((string) file_get_contents($seedFile), true);
    if (!is_array($seed) || empty($seed['cards'])) {
        echo "!! cards_seed.json 解析失败。\n";
    } else {
        foreach ($seed['sets'] as $s) {
            Capsule::table('card_sets')->insert([
                'id' => $s['id'],
                'course_id' => $s['course_id'],
                'lang_code' => $s['lang_code'],
                'title' => $s['title'],
                'sub' => $s['sub'] ?? '',
                'kind' => $s['kind'],
                'cats' => $s['cats'] ? json_encode($s['cats'], JSON_UNESCAPED_UNICODE) : null,
                'sort' => $s['sort'] ?? 0,
            ]);
        }
        $rows = [];
        foreach ($seed['cards'] as $c) {
            $rows[] = [
                'set_id' => $c['set_id'],
                'course_id' => $c['course_id'],
                'lang_code' => $c['lang_code'],
                'kind' => $c['kind'],
                'card_key' => $c['key'],
                'sort' => $c['sort'] ?? 0,
                'data' => json_encode($c['data'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Capsule::table('cards')->insert($chunk);
        }
        echo "卡片迁移完成：" . count($seed['sets']) . " 个卡片集 / " . count($rows) . " 张卡\n";
    }
}

// ---- 设置
Capsule::table('settings')->insert([
    ['key' => 'daily_card_goal', 'value' => '20'],
    ['key' => 'daily_minute_goal', 'value' => '30'],
    ['key' => 'theme', 'value' => 'langlab'],
    ['key' => 'dict_scope_hint', 'value' => 'general=全部词条；professional=仅术语（is_term=1）或按课程切分'],
]);

// ================================================================ 复核
echo "\n== 复核 ==\n";
foreach (['languages', 'courses', 'card_sets', 'cards', 'card_progress', 'materials', 'segments', 'annotations', 'notes', 'dict_entries', 'dict_occurrences', 'study_sessions', 'settings'] as $t) {
    printf("  %-18s %d\n", $t, Capsule::table($t)->count());
}
echo "\n完成。数据库：backend/data/langlab.sqlite\n";
