<?php

/**
 * langlab API
 *
 * 约定：
 *  - 所有响应 JSON_UNESCAPED_UNICODE
 *  - 统一 respond($res, $data, $status)
 *  - 日期字段为字符串 Y-m-d（SQLite 无原生日期类型）
 */

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/../src/db.php';
require __DIR__ . '/../src/tokenizer.php';
require __DIR__ . '/../src/tts.php';
require __DIR__ . '/../src/episode.php';

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$err = $app->addErrorMiddleware(false, true, true);
$err->setDefaultErrorHandler(function (Request $req, Throwable $e) {
    $res = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse(500);
    $res->getBody()->write(json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
});

function respond(Response $res, $data, int $status = 200): Response
{
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
}

function ok(Response $res, $data = []): Response
{
    return respond($res, ['ok' => true] + (is_array($data) ? $data : ['data' => $data]));
}

function jd(?string $json): mixed
{
    if ($json === null || $json === '') {
        return null;
    }
    return json_decode($json, true);
}

$TODAY = date('Y-m-d');
$NOW = date('Y-m-d H:i:s');

/* ============================================================ 基础信息 */

$app->get('/api/health', function (Request $req, Response $res) {
    $tables = ['languages', 'courses', 'card_sets', 'cards', 'card_progress', 'materials',
        'segments', 'annotations', 'notes', 'dict_entries', 'dict_occurrences', 'study_sessions'];
    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = Capsule::table($t)->count();
    }
    return ok($res, ['counts' => $counts, 'time' => date('Y-m-d H:i:s')]);
});

$app->get('/api/languages', function (Request $req, Response $res) {
    $langs = Capsule::table('languages')->orderBy('sort')->get();
    $courseCount = [];
    foreach (Capsule::table('courses')->get() as $c) {
        $courseCount[$c->lang_code] = ($courseCount[$c->lang_code] ?? 0) + 1;
    }
    $cardCount = [];
    foreach (Capsule::table('cards')->selectRaw('lang_code, count(*) as n')->groupBy('lang_code')->get() as $r) {
        $cardCount[$r->lang_code] = (int) $r->n;
    }
    $out = [];
    foreach ($langs as $l) {
        $out[] = [
            'code' => $l->code,
            'name_zh' => $l->name_zh,
            'name_native' => $l->name_native,
            'script' => $l->script,
            'tts_lang' => $l->tts_lang,
            'tokenizer' => $l->tokenizer,
            'enabled' => (int) $l->enabled,
            'note' => $l->note,
            'courses' => $courseCount[$l->code] ?? 0,
            'cards' => $cardCount[$l->code] ?? 0,
        ];
    }
    return ok($res, ['languages' => $out]);
});

$app->get('/api/courses', function (Request $req, Response $res) {
    $lang = $_GET['lang'] ?? null;
    $q = Capsule::table('courses');
    if ($lang && $lang !== 'all') {
        $q->where('lang_code', $lang);
    }
    $rows = $q->orderBy('lang_code')->orderBy('sort')->get();

    $stats = [];
    foreach (Capsule::table('card_sets')->selectRaw('course_id, count(*) as n')->groupBy('course_id')->get() as $r) {
        $stats[$r->course_id]['sets'] = (int) $r->n;
    }
    foreach (Capsule::table('cards')->selectRaw('course_id, count(*) as n')->groupBy('course_id')->get() as $r) {
        $stats[$r->course_id]['cards'] = (int) $r->n;
    }
    foreach (Capsule::table('courses')->get() as $c) {
        $stats[$c->id]['materials'] = Capsule::table('materials')->where('course_id', $c->id)->count();
    }

    $out = [];
    foreach ($rows as $c) {
        $out[] = [
            'id' => $c->id,
            'lang_code' => $c->lang_code,
            'name' => $c->name,
            'kind' => $c->kind,
            'description' => $c->description,
            'config' => jd($c->config),
            'sets' => $stats[$c->id]['sets'] ?? 0,
            'cards' => $stats[$c->id]['cards'] ?? 0,
            'materials' => $stats[$c->id]['materials'] ?? 0,
        ];
    }
    return ok($res, ['courses' => $out]);
});

/* ============================================================ 卡片 */

/**
 * 「自建卷」= 用户自己攒出来的卡组，而不是课程预置内容。
 *   anno-<courseId> —— 阅读/看剧时划词批注产生的卡
 *   my-<courseId>   —— 卡片页「就地建卡」产生的卡
 * 这类内容与「课程」维度正交：不管你在学哪门课，都应该看得到、也进得了复习队列。
 * 这里是**唯一的判定口径**，三处（card-sets / cards / cards/due）共用。
 */
const SELF_BUILT_SET_SQL = "(id LIKE 'anno-%' OR id LIKE 'my-%')";
const SELF_BUILT_CARD_SQL = "(c.set_id LIKE 'anno-%' OR c.set_id LIKE 'my-%')";

/**
 * 给某个卡组里「还没有进度行」的卡补一行（state=new，今天到期）。
 * 为什么需要：/api/cards/{id}/review 遇到缺行会懒创建，但那是被动兜底 ——
 * 主动补齐能让 card_progress 与 cards 始终一一对应，也避免同类 bug 再犯。
 * ⚠️ card_progress **没有 created_at 列**，别加。
 */
function ensure_progress_rows(string $setId): int
{
    $have = Capsule::table('card_progress')->pluck('card_id')->all();
    $haveSet = array_flip($have);
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach (Capsule::table('cards')->where('set_id', $setId)->select('id')->get() as $c) {
        if (isset($haveSet[$c->id])) {
            continue;
        }
        Capsule::table('card_progress')->insert([
            'card_id' => $c->id, 'state' => 'new', 'review_count' => 0,
            'last_review_at' => null, 'updated_at' => $now,
            'due_date' => $today, 'ease' => 2.5, 'interval' => 0,
            'repetitions' => 0, 'lapses' => 0,
        ]);
        $n++;
    }
    return $n;
}

$app->get('/api/card-sets', function (Request $req, Response $res) {
    $course = $_GET['course'] ?? null;
    $q = Capsule::table('card_sets');
    if ($course && $course !== 'all') {
        // ⚠️ 课程卷按课程过滤，但**自建卷（语料批注 anno-* / 我的生词 my-*）跨课程始终可见**。
        //    「我划词攒的卡」和「课程」是两个正交的维度 —— 把自建卡锁在素材所属课程里，
        //    会出现「在美剧里划词建了卡，回到合同英语的卡片页却看不到」（2026-10-04 修）。
        $q->where(function ($w) use ($course) {
            $w->where('course_id', $course)->orWhereRaw(SELF_BUILT_SET_SQL);
        });
    }
    // 自建卷排在课程卷之后，不打断课程内的编排
    $rows = $q->orderByRaw("CASE WHEN id LIKE 'anno-%' OR id LIKE 'my-%' THEN 1 ELSE 0 END")
        ->orderBy('sort')->get();

    $total = [];
    $done = [];
    foreach (Capsule::table('cards')->selectRaw('set_id, count(*) as n')->groupBy('set_id')->get() as $r) {
        $total[$r->set_id] = (int) $r->n;
    }
    foreach (Capsule::table('cards as c')
        ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
        ->where('p.state', 'mastered')
        ->selectRaw('c.set_id, count(*) as n')->groupBy('c.set_id')->get() as $r) {
        $done[$r->set_id] = (int) $r->n;
    }

    $out = [];
    foreach ($rows as $s) {
        $out[] = [
            'id' => $s->id,
            'course_id' => $s->course_id,
            'lang_code' => $s->lang_code,
            'title' => $s->title,
            'sub' => $s->sub,
            'kind' => $s->kind,
            'cats' => jd($s->cats),
            'total' => $total[$s->id] ?? 0,
            'mastered' => $done[$s->id] ?? 0,
            // 前端可据此标注「这是你自己攒的卡」/「不属于当前课程」
            'self_built' => (str_starts_with($s->id, 'anno-') || str_starts_with($s->id, 'my-')),
            'here' => ($course === null || $course === 'all' || $s->course_id === $course),
        ];
    }
    return ok($res, ['sets' => $out]);
});

$app->get('/api/cards', function (Request $req, Response $res) {
    $course = $_GET['course'] ?? null;
    $set = $_GET['set'] ?? null;
    $kind = $_GET['kind'] ?? null;
    $cat = $_GET['cat'] ?? null;
    $state = $_GET['state'] ?? null;   // mastered | new | all
    $q = trim((string) ($_GET['q'] ?? ''));
    $limit = min(2000, max(1, (int) ($_GET['limit'] ?? 500)));

    $query = Capsule::table('cards as c')
        ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
        ->selectRaw('c.id, c.set_id, c.course_id, c.lang_code, c.kind, c.card_key, c.sort, c.data,
            COALESCE(p.state, "new") as state, p.review_count, p.last_review_at,
            COALESCE(p.due_date, "1970-01-01") as due_date,
            COALESCE(p.ease, 2.5) as ease,
            COALESCE(p.interval, 0) as interval_days,
            COALESCE(p.repetitions, 0) as repetitions,
            COALESCE(p.lapses, 0) as lapses');

    // ⚠️ 显式指定卷时，**卷优先于课程** —— 否则「跨课程打开某个自建卷」会被 course 过滤成 0 张。
    //    卷 id 已经唯一确定了卡的范围，课程过滤此时是冗余且有害的（2026-10-04 修）。
    if ($set && $set !== 'all') {
        $query->where('c.set_id', $set);
    } elseif ($course && $course !== 'all') {
        // 未指定卷 → 按课程筛（含该课程的卡 + 跨课程的自建卡）
        $query->where(function ($w) use ($course) {
            $w->where('c.course_id', $course)->orWhereRaw(SELF_BUILT_CARD_SQL);
        });
    }
    if ($kind && $kind !== 'all') {
        $query->where('c.kind', $kind);
    }
    // ⚠️ 分类必须放在 SQL 里筛，不能等取回后再 continue ——
    // 那样会在 limit 之后再过滤，卡量一大就静默少返回（2026-10-03 修）
    if ($cat && $cat !== 'all') {
        $query->whereRaw("json_extract(c.data,'$.cat') = ?", [$cat]);
    }
    if ($q !== '') {
        $query->where('c.data', 'like', '%' . $q . '%');
    }
    if ($state === 'mastered') {
        $query->where('p.state', 'mastered');
    } elseif ($state === 'new') {
        $query->where(function ($w) {
            $w->whereNull('p.state')->orWhere('p.state', '!=', 'mastered');
        });
    }

    $rows = $query->orderBy('c.sort')->limit($limit)->get();

    $out = [];
    foreach ($rows as $r) {
        $data = jd($r->data) ?? [];
        if ($cat && $cat !== 'all' && ($data['cat'] ?? null) !== $cat) {
            continue;
        }
        $out[] = [
            'id' => (int) $r->id,
            'set_id' => $r->set_id,
            'course_id' => $r->course_id,
            'lang_code' => $r->lang_code,
            'kind' => $r->kind,
            'key' => $r->card_key,
            'state' => $r->state,
            'review_count' => (int) ($r->review_count ?? 0),
            'due_date' => $r->due_date,
            'ease' => (float) $r->ease,
            'interval_days' => (int) $r->interval_days,
            'repetitions' => (int) $r->repetitions,
            'lapses' => (int) $r->lapses,
            'data' => $data,
        ];
    }
    return ok($res, ['cards' => $out, 'count' => count($out)]);
});

// 今天该复习的卡：due_date <= 今天且未标记掌握（SM-2 调度队列）
// 按遗忘曲线排序：留存率 retention = exp(-已流逝天数 / 稳定性) 越低越紧急，排最前
$app->get('/api/cards/due', function (Request $req, Response $res) {
    $course = $_GET['course'] ?? null;
    $today = date('Y-m-d');
    $todayTs = strtotime($today);
    // v2 分阶段放行：按「计划第几周」只放行对应卡组，避免首日被 800+ 张卡淹没
    // （他有外语焦虑，一开就被淹没 = 直接放弃）
    //   ?all=1  临时看全部（调试用）
    $planStart = Capsule::table('settings')->where('key', 'plan_start')->value('value') ?: '2026-10-05';
    $psTs = strtotime($planStart);
    $weekNo = $psTs ? max(1, (int) floor(($todayTs - $psTs) / 604800) + 1) : 1;
    $allowAll = isset($_GET['all']) && (string) $_GET['all'] !== '0';

    // leftJoin：新同步进来的卡可能还没有 card_progress 行（sync-annotations 只插 cards），
    // 用 INNER JOIN 会把它们永久挡在复习队列外。缺进度行 = 全新卡 = 今天该学。
    $q = Capsule::table('cards as c')
        ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
        ->leftJoin('card_sets as s', 's.id', '=', 'c.set_id')
        ->where(function ($q2) use ($today) {
            $q2->whereNull('p.due_date')->orWhere('p.due_date', '<=', $today);
        })
        ->where(function ($q2) {
            $q2->whereNull('p.state')->orWhere('p.state', '!=', 'mastered');
        })
        ->selectRaw('c.id, c.set_id, c.course_id, c.lang_code, c.kind, c.card_key, c.sort, c.data,
            s.release_week, s.title as set_title, s.kind as set_kind,
            p.due_date, p.ease, p.interval, p.repetitions, p.lapses, p.state, p.last_review_at');
    if ($course && $course !== 'all') {
        // ⚠️ 自建卡（语料批注 / 我的生词）**跨课程进复习队列** —— 那是用户自己攒的卡，
        //    不该因为「在学合同英语」就永远不出现（2026-10-04 修）。
        $q->where(function ($w2) use ($course) {
            $w2->where('c.course_id', $course)->orWhereRaw(SELF_BUILT_CARD_SQL);
        });
    }
    if (!$allowAll) {
        // 未设 release_week 的卡组视为第 1 周放行
        $q->where(function ($q2) use ($weekNo) {
            $q2->whereNull('s.release_week')->orWhere('s.release_week', '<=', $weekNo);
        });
    }
    $rows = $q->get();

    $out = [];
    $overdueCount = 0;
    $dueTodayCount = 0;
    foreach ($rows as $r) {
        $dueTs = $r->due_date ? strtotime($r->due_date) : $todayTs;
        $overdueDays = max(0, (int) round(($todayTs - $dueTs) / 86400));
        if ($overdueDays > 0) {
            $overdueCount++;
        } else {
            $dueTodayCount++;
        }

        // 遗忘曲线：留存率 ≈ exp(-已流逝天数 / 稳定性)，稳定性 ≈ 间隔 × 易度
        $interval = (int) $r->interval;
        $ease = (float) $r->ease ?: 2.5;
        $stability = max(1, $interval * $ease);
        if ($r->last_review_at) {
            $elapsed = max(0, ($todayTs - strtotime($r->last_review_at)) / 86400);
        } else {
            // 从未复习过 → 视为已大幅衰减，排在最前
            $elapsed = $stability + $overdueDays + 1;
        }
        $retention = exp(-$elapsed / $stability); // 0~1，越低越紧急
        $urgency = $retention < 0.35 ? 'high' : ($retention < 0.65 ? 'mid' : 'low');

        $data = jd($r->data) ?? [];
        $out[] = [
            'id' => (int) $r->id,
            'set_id' => $r->set_id,
            'course_id' => $r->course_id,
            'lang_code' => $r->lang_code,
            'kind' => $r->kind,
            'key' => $r->card_key,
            'state' => $r->state,
            'due_date' => $r->due_date,
            'ease' => $ease,
            'interval_days' => $interval,
            'repetitions' => (int) $r->repetitions,
            'lapses' => (int) $r->lapses,
            'overdue_days' => $overdueDays,
            'retention' => (float) round($retention, 3),
            'urgency' => $urgency,
            'data' => $data,
        ];
    }
    // 遗忘曲线排序：逾期卡优先（最该立刻复习的排最前），组内再按留存率升序
    usort($out, function ($a, $b) {
        $ag = $a['overdue_days'] > 0 ? 0 : 1;
        $bg = $b['overdue_days'] > 0 ? 0 : 1;
        if ($ag !== $bg) {
            return $ag <=> $bg;
        }
        return $a['retention'] <=> $b['retention'];
    });

    // v2 每日上限：一次只吐 N 张，做完再刷新拿下一批，避免被队列淹没
    //   ?limit=0 取消上限
    $totalDue = count($out);
    $lim = $_GET['limit'] ?? Capsule::table('settings')->where('key', 'daily_card_limit')->value('value');
    $lim = ($lim === null || $lim === '') ? 60 : (int) $lim;
    if ($lim > 0) {
        $out = array_slice($out, 0, $lim);
    }

    return ok($res, [
        'cards' => $out,
        'count' => count($out),
        'total_due' => $totalDue,
        'limit' => $lim > 0 ? $lim : null,
        'remaining' => max(0, $totalDue - count($out)),
        'overdue_count' => $overdueCount,
        'due_today_count' => $dueTodayCount,
        'today' => $today,
        // v2：放行状态（前端可显示"本周只放行这些卡组"）
        'release' => [
            'week_no' => $weekNo,
            'plan_start' => $planStart,
            'all' => $allowAll,
        ],
    ]);
});

// 卡片互查：给一个英文单词，找出对应的卡片（用于「点例句里的词跳到那张卡」）
// 三级匹配：① 卡片主词完全相等 ② 中文一词多形卡的 forms[].en ③ 前缀（短语卡）
$app->get('/api/cards/lookup', function (Request $req, Response $res) {
    $w = trim((string) ($_GET['w'] ?? ''));
    if ($w === '') {
        return ok($res, ['cards' => [], 'count' => 0]);
    }
    $norm = mb_strtolower(trim(preg_replace("/[^A-Za-z0-9\\-' ]/u", '', $w)));
    if ($norm === '') {
        return ok($res, ['cards' => [], 'count' => 0]);
    }
    // 容错：把用户实际犯过的「按声音拼」错误也接到正确的词上
    // （依据：他 9 次实测失误全是语音性的 —— dew/due、negoration/negotiation、afflication/affiliate…）
    $ALIAS = [
        'dew' => 'due', 'flaugellent' => 'fraudulent', 'behavie' => 'behaviour',
        'negoration' => 'negotiation', 'negotation' => 'negotiation',
        'comfort' => 'comply', 'afflication' => 'affiliation', 'affiliate' => 'affiliate',
        'authorications' => 'authentication', 'advertisment' => 'advertisement',
        'basicall' => 'basically', 'euroup' => 'europe', 'legals' => 'law',
    ];
    if (isset($ALIAS[$norm])) {
        $norm = $ALIAS[$norm];
    }

    $base = function () {
        return Capsule::table('cards as c')
            ->leftJoin('card_sets as s', 's.id', '=', 'c.set_id')
            ->selectRaw("c.id, c.set_id, c.course_id, c.kind, c.data, s.title as set_title, s.release_week");
    };

    $rows = $base()->whereRaw("lower(trim(json_extract(c.data,'$.w'))) = ?", [$norm])->limit(6)->get();

    if (!count($rows)) {
        $rows = $base()->whereRaw(
            "exists (select 1 from json_each(json_extract(c.data,'$.forms')) fe "
            . "where lower(trim(json_extract(fe.value,'$.en'))) = ?)", [$norm])->limit(6)->get();
    }
    if (!count($rows)) {
        $rows = $base()->whereRaw("lower(trim(json_extract(c.data,'$.w'))) like ?", [$norm . '%'])
            ->limit(3)->get();
    }

    $out = [];
    foreach ($rows as $r) {
        $d = jd($r->data) ?? [];
        $out[] = [
            'id' => (int) $r->id,
            'set_id' => $r->set_id,
            'set_title' => $r->set_title,
            'course_id' => $r->course_id,
            'kind' => $r->kind,
            'release_week' => $r->release_week,
            'w' => $d['w'] ?? '',
            'cn' => $d['cn'] ?? '',
            'ipa' => $d['ipa'] ?? '',
            'plain' => $d['plain'] ?? '',
            'en' => $d['en'] ?? '',
            'src' => $d['src'] ?? '',
            'colloc' => $d['colloc'] ?? '',
        ];
    }
    return ok($res, ['cards' => $out, 'query' => $norm, 'count' => count($out)]);
});

// 回到原文：给出某张卡在源文档里的段落 + 前后文（供前端弹窗显示）
$app->get('/api/cards/{id}/source', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $c = Capsule::table('cards')->where('id', $id)->first();
    if (!$c) {
        return ok($res, ['found' => false, 'why' => 'card not found']);
    }
    $d = jd($c->data) ?? [];
    $mid = (int) ($d['material_id'] ?? 0);
    $seq = (int) ($d['segment_seq'] ?? 0);

    // 批注卡只有 segment_id（没有 segment_seq）→ 回查它属于哪一段
    if (!$seq && !empty($d['segment_id'])) {
        $sg = Capsule::table('segments')->where('id', (int) $d['segment_id'])->first();
        if ($sg) {
            $seq = (int) $sg->seq;
            if (!$mid) {
                $mid = (int) $sg->material_id;
            }
        }
    }

    if (!$mid || !$seq) {
        // 没有链回材料的卡片，退化为显示它自己的出处信息
        return ok($res, [
            'found' => false,
            'why' => 'no source link',
            'en' => $d['en'] ?? '',
            'zh' => $d['zh'] ?? '',
            'src_file' => $d['src_file'] ?? '',
            'cite' => $d['cite'] ?? '',
            'w' => $d['w'] ?? '',
            'cn' => $d['cn'] ?? '',
            'note' => $d['note'] ?? '',
            'eg' => $d['eg'] ?? '',
            'material_id' => $mid,
        ]);
    }

    $m = Capsule::table('materials')->where('id', $mid)->first();
    $lo = max(1, $seq - 3);
    $rows = Capsule::table('segments')->where('material_id', $mid)
        ->whereBetween('seq', [$lo, $seq + 3])->orderBy('seq')->get();
    $out = [];
    foreach ($rows as $s) {
        $out[] = [
            'seq' => (int) $s->seq,
            'text' => $s->text,
            'is_hit' => ((int) $s->seq === $seq),
        ];
    }
    return ok($res, [
        'found' => true,
        'material_id' => $mid,
        'material_title' => $m ? $m->title : '',
        'material_type' => $m ? $m->type : '',
        'hit_seq' => $seq,
        'segments' => $out,
        'en' => $d['en'] ?? '',
        'src_file' => $d['src_file'] ?? '',
        'w' => $d['w'] ?? '',
    ]);
});

// 复习一张卡：again/hard/good/easy → 用 SM-2 算下次到期
$app->post('/api/cards/{id}/review', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $b = (array) $req->getParsedBody();
    $grade = strtolower((string) ($b['grade'] ?? 'good'));
    if (!in_array($grade, ['again', 'hard', 'good', 'easy'], true)) {
        return respond($res, ['ok' => false, 'error' => 'grade 必须是 again/hard/good/easy'], 400);
    }
    $card = Capsule::table('card_progress')->where('card_id', $id)->first();
    if (!$card) {
        // ⚠️ card_progress **没有 created_at 列**（只有 updated_at）。
        //    这里曾经插了 created_at → 任何「还没有进度行」的卡一复习就报
        //    `table card_progress has no column named created_at`。
        //    自建卡（anno-* / my-*）正是这种卡 —— 它们只插 cards 不插 progress（2026-10-04 修）。
        $now0 = date('Y-m-d H:i:s');
        Capsule::table('card_progress')->insert([
            'card_id' => $id, 'state' => 'new', 'due_date' => date('Y-m-d'),
            'ease' => 2.5, 'interval' => 0, 'repetitions' => 0, 'lapses' => 0,
            'review_count' => 0, 'last_review_at' => null, 'updated_at' => $now0,
        ]);
        $card = Capsule::table('card_progress')->where('card_id', $id)->first();
    }

    $ease = (float) $card->ease;
    $interval = (int) $card->interval;
    $reps = (int) $card->repetitions;
    $lapses = (int) $card->lapses;

    if ($grade === 'again') {
        $reps = 0;
        $interval = 0;
        $ease = max(1.3, $ease - 0.20);
        $lapses += 1;
    } elseif ($grade === 'hard') {
        $interval = ($reps === 0) ? 1 : max(1, (int) round($interval * 1.2));
    } elseif ($grade === 'good') {
        $interval = ($reps === 0) ? 1 : max(1, (int) round($interval * $ease));
        $reps += 1;
    } elseif ($grade === 'easy') {
        $interval = ($reps === 0) ? 4 : max(1, (int) round($interval * $ease * 1.3));
        $reps += 1;
        $ease = $ease + 0.15;
    }

    $state = ($reps >= 2) ? 'review' : 'learning';
    $due = date('Y-m-d', strtotime("+$interval days"));
    $now = date('Y-m-d H:i:s');

    Capsule::table('card_progress')->where('card_id', $id)->update([
        'due_date' => $due,
        'ease' => $ease,
        'interval' => $interval,
        'repetitions' => $reps,
        'lapses' => $lapses,
        'state' => $state,
        'review_count' => (int) $card->review_count + 1,
        'last_review_at' => $now,
        'updated_at' => $now,
    ]);

    // 复习流水（revlog）—— 2026-10-04 · 档 1：**只记录，不参与调度**（排期仍是上面的 SM-2）。
    // FSRS 的参数拟合需要 (card_id, rating, reviewed_at) 的完整序列 —— 这是唯一「过期作废」的资产。
    // ⚠️ 用 try/catch 包住：记录失败**绝不能**影响复习本身（表还没迁移时也不该让复习挂掉）。
    //    `$card` 是 UPDATE 之前查出来的 → 它的 state/interval/ease 正是「复习前」的值。
    try {
        Capsule::table('card_reviews')->insert([
            'card_id'         => $id,
            'rating'          => ['again' => 1, 'hard' => 2, 'good' => 3, 'easy' => 4][$grade],
            'reviewed_at'     => $now,
            'state_before'    => (string) ($card->state ?? 'new'),
            'interval_before' => (int) ($card->interval ?? 0),
            'ease_before'     => (float) ($card->ease ?? 2.5),
            'interval_after'  => $interval,
            'due_after'       => $due,
            'source'          => (string) ($b['source'] ?? 'cards'),
            'created_at'      => $now,
        ]);
    } catch (\Throwable $e) {
        // 静默失败：复习已经成功，流水记录不该拖累它
    }

    return ok($res, [
        'card_id' => $id,
        'grade' => $grade,
        'due_date' => $due,
        'interval_days' => $interval,
        'ease' => round($ease, 3),
        'repetitions' => $reps,
        'lapses' => $lapses,
        'state' => $state,
    ]);
});

// 批量写掌握状态：{ items:[{id, state}], course_id? }
$app->post('/api/cards/progress', function (Request $req, Response $res) {
    $body = (array) $req->getParsedBody();
    $items = $body['items'] ?? [];
    if (!is_array($items) || !$items) {
        return respond($res, ['ok' => false, 'error' => 'items 不能为空'], 400);
    }
    $now = date('Y-m-d H:i:s');
    $today = date('Y-m-d');
    $future = date('Y-m-d', strtotime('+3650 days'));
    $n = 0;
    foreach ($items as $it) {
        $id = (int) ($it['id'] ?? 0);
        $state = (string) ($it['state'] ?? 'new');
        if ($id <= 0) {
            continue;
        }
        if (!in_array($state, ['new', 'learning', 'mastered'], true)) {
            continue;
        }
        $exists = Capsule::table('card_progress')->where('card_id', $id)->first();
        if ($exists) {
            $up = [
                'state' => $state,
                'review_count' => (int) $exists->review_count + 1,
                'last_review_at' => $now,
                'updated_at' => $now,
            ];
            // 手动标记掌握 → 退出复习队列；取消掌握 → 重新进入今天的队列
            if ($state === 'mastered') {
                $up['due_date'] = $future;
            } elseif ($exists->state === 'mastered') {
                $up['due_date'] = $today;
            }
            Capsule::table('card_progress')->where('card_id', $id)->update($up);
        } else {
            Capsule::table('card_progress')->insert([
                'card_id' => $id, 'state' => $state, 'review_count' => 1,
                'last_review_at' => $now, 'updated_at' => $now,
                'due_date' => ($state === 'mastered') ? $future : $today,
                'ease' => 2.5, 'interval' => 0, 'repetitions' => 0, 'lapses' => 0,
            ]);
        }
        $n++;
    }
    return ok($res, ['updated' => $n]);
});

/* ============================================================ 学习时长/活动埋点 */

$app->post('/api/sessions', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    if (empty($b['lang_code']) || empty($b['kind'])) {
        return respond($res, ['ok' => false, 'error' => 'lang_code / kind 必填'], 400);
    }
    Capsule::table('study_sessions')->insert([
        'lang_code' => (string) $b['lang_code'],
        'course_id' => $b['course_id'] ?? null,
        'kind' => (string) $b['kind'],
        'ref_id' => isset($b['ref_id']) ? (string) $b['ref_id'] : null,
        'item_count' => (int) ($b['item_count'] ?? 0),
        'duration_sec' => (int) ($b['duration_sec'] ?? 0),
        'day' => $b['day'] ?? date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return ok($res, []);
});

/* ============================================================ 批注 → 卡片复习闭环 */

$app->post('/api/courses/{id}/sync-annotations', function (Request $req, Response $res, array $args) {
    $r = sync_annotation_cards((string) $args['id']);
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error']], 400);
    }
    return ok($res, $r);
});

/* ============================================================ 文件导入 */

const TEXTY = ['epub', 'pdf', 'docx', 'txt', 'md', 'markdown', 'html', 'htm', 'srt', 'vtt', 'ass', 'sub', 'script'];
const MIME = [
    'epub' => 'application/epub+zip',
    'pdf' => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'html' => 'text/html; charset=utf-8',
    'htm' => 'text/html; charset=utf-8',
    'srt' => 'text/plain; charset=utf-8',
    'vtt' => 'text/vtt; charset=utf-8',
    'script' => 'text/plain; charset=utf-8',
];

/**
 * 把抽取出来的 parts 落成 segments。
 * chapter 存进 segments.chapter，便于「精读」视图按章分组显示。
 */
function ingest_parts(int $materialId, array $parts, string $lang): array
{
    Capsule::table('segments')->where('material_id', $materialId)->delete();
    $rows = [];
    $seq = 0;
    $chars = 0;
    $words = 0;
    foreach ($parts as $p) {
        $chapter = trim((string) ($p['title'] ?? ''));
        foreach (segmentize((string) ($p['text'] ?? '')) as $seg) {
            $seq++;
            $chars += mb_strlen($seg, 'UTF-8');
            $words += str_starts_with($lang, 'ja') || str_starts_with($lang, 'th')
                ? (int) round(mb_strlen(preg_replace('/\s+/u', '', $seg), 'UTF-8') / 2)
                : count(preg_split('/\s+/u', trim($seg)) ?: []);
            $rows[] = [
                'material_id' => $materialId, 'seq' => $seq,
                'locator' => ($chapter !== '' ? $chapter . ' · ' : '') . 'p' . $seq,
                'text' => $seg, 'char_count' => mb_strlen($seg, 'UTF-8'),
                'tokens' => null, 'chapter' => $chapter !== '' ? $chapter : null,
            ];
        }
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        Capsule::table('segments')->insert($chunk);
    }
    Capsule::table('materials')->where('id', $materialId)->update([
        'segment_count' => count($rows), 'word_count' => $words,
    ]);
    // 标题里若带 S04E01 这类结构，顺手把「剧名/季/集」拆出来落库（供三级剧集浏览）
    apply_episode_fields($materialId);
    return ['segments' => count($rows), 'words' => $words, 'chars' => $chars];
}

/** 上传文件（multipart）→ 存副本 → 抽文本 → 切段 → 建索引 */
$app->post('/api/materials/upload', function (Request $req, Response $res) {
    $files = $req->getUploadedFiles();
    $file = $files['file'] ?? null;
    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $code = $file ? $file->getError() : -1;
        $hint = $code === UPLOAD_ERR_INI_SIZE
            ? '（超出 upload_max_filesize，启动后端时需加 -d upload_max_filesize=128M -d post_max_size=128M）' : '';
        return respond($res, ['ok' => false, 'error' => '上传失败，错误码 ' . $code . $hint], 400);
    }
    $data = (array) $req->getParsedBody();
    $lang = (string) ($data['lang_code'] ?? '');
    $course = $data['course_id'] ?? null;
    if ($lang === '') {
        return respond($res, ['ok' => false, 'error' => 'lang_code 必填'], 400);
    }

    $orig = (string) $file->getClientFilename();
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, TEXTY, true)) {
        return respond($res, ['ok' => false, 'error' => '暂不支持 .' . $ext . '，可支持：' . implode(' / ', TEXTY)], 400);
    }

    $dir = dirname(__DIR__) . '/data/files';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $name = date('Ymd-His') . '-' . substr(sha1($orig . microtime()), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $file->moveTo($dest);
    if (!is_file($dest)) {
        return respond($res, ['ok' => false, 'error' => '文件落盘失败'], 500);
    }

    return respond($res, ingest_file($dest, $ext, $lang, $course, (string) ($data['title'] ?? ''), $orig), 200);
});

/** 登记一个本地路径（不复制文件，原文件原地不动） */
$app->post('/api/materials/link', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    $path = trim((string) ($b['path'] ?? ''));
    $lang = (string) ($b['lang_code'] ?? '');
    if ($path === '' || $lang === '') {
        return respond($res, ['ok' => false, 'error' => 'path / lang_code 必填'], 400);
    }
    $path = str_replace('\\', '/', $path);
    if (!is_file($path)) {
        return respond($res, ['ok' => false, 'error' => '找不到文件：' . $path], 404);
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    // 允许显式指定类型：.txt 里可能是台词本（一行一句），也可能是散文
    $typeOverride = strtolower(trim((string) ($b['type'] ?? '')));
    if ($typeOverride !== '') {
        if (!in_array($typeOverride, TEXTY, true)) {
            return respond($res, ['ok' => false, 'error' => '不支持的 type：' . $typeOverride], 400);
        }
        $ext = $typeOverride;
    }
    if (!in_array($ext, TEXTY, true)) {
        return respond($res, ['ok' => false, 'error' => '暂不支持 .' . $ext], 400);
    }
    return ok($res, ingest_file($path, $ext, $lang, $b['course_id'] ?? null, (string) ($b['title'] ?? ''), basename($path)));
});

/** 共用：抽文本 → 建素材 → 切段 → 建索引 */
function ingest_file(string $path, string $ext, string $lang, $courseId, string $title, string $origName): array
{
    set_time_limit(600);
    $ex = extract_file_text($path, $ext);
    if (empty($ex['ok'])) {
        return ['ok' => false, 'error' => $ex['error'] ?? '抽取失败', 'attachment_path' => $path];
    }
    $parts = $ex['parts'] ?? [];
    if (!$parts) {
        return ['ok' => false, 'error' => '没抽到正文'];
    }
    if ($title === '') {
        $title = (string) ($ex['title'] ?? pathinfo($origName, PATHINFO_FILENAME));
    }
    // 扩展名是 txt/md 但内容被抽取器认成台词本时，type 记成 script：
    // 否则同一批台词本里，显式指定 type 的显示「台词本」、没指定的显示「纯文本」，列表和筛选都会割裂。
    $type = $ext;
    if (in_array($ext, ['txt', 'md', 'html', 'htm'], true) && ($ex['detected'] ?? '') === 'script') {
        $type = 'script';
    }
    $now = date('Y-m-d H:i:s');
    $id = (int) Capsule::table('materials')->insertGetId([
        'course_id' => $courseId ?: null,
        'lang_code' => $lang,
        'type' => $type,
        'title' => $title,
        'author' => ($ex['author'] ?? '') !== '' ? (string) $ex['author'] : null,
        'source' => 'file',
        'source_url' => null,
        'attachment_path' => $path,
        'meta' => json_encode([
            'chars' => (int) ($ex['chars'] ?? 0),
            'parts' => (int) ($ex['part_count'] ?? count($parts)),
            'meta_title' => $ex['meta_title'] ?? null,
            'original_name' => $origName,
        ], JSON_UNESCAPED_UNICODE),
        'progress' => json_encode(['read_seq' => 0], JSON_UNESCAPED_UNICODE),
        'status' => 'ready', 'word_count' => 0, 'segment_count' => 0,
        'file_size' => is_file($path) ? filesize($path) : null,
        'created_at' => $now,
    ]);

    $ing = ingest_parts($id, $parts, $lang);
    $idx = $ing['segments'] <= 400 ? index_material($id) : null;

    return [
        'ok' => true,
        'material_id' => $id,
        'title' => $title,
        'type' => $type,
        'parts' => count($parts),
        'segments' => $ing['segments'],
        'words' => $ing['words'],
        'chars' => $ing['chars'],
        'attachment_path' => $path,
        'index' => $idx,
        'message' => '《' . $title . '》导入成功：' . count($parts) . ' 个部分 / ' . $ing['segments']
            . ' 段 / ' . $ing['words'] . ' 词。'
            . ($idx && !empty($idx['ok']) ? $idx['message'] : '（段数较多，未自动建索引，可点「生成词条」）'),
    ];
}

/** 原文流式返回（供内嵌阅读器） */
$app->get('/api/materials/{id}/file', function (Request $req, Response $res, array $args) {
    $m = Capsule::table('materials')->where('id', (int) $args['id'])->first();
    if (!$m || !$m->attachment_path || !is_file($m->attachment_path)) {
        return respond($res, ['ok' => false, 'error' => '没有原始文件'], 404);
    }
    $ext = strtolower(pathinfo((string) $m->attachment_path, PATHINFO_EXTENSION));
    $mime = MIME[$ext] ?? 'application/octet-stream';
    $res = $res->withHeader('Content-Type', $mime)
        ->withHeader('Content-Length', (string) filesize($m->attachment_path))
        ->withHeader('Content-Disposition', 'inline; filename="' . rawurlencode(basename((string) $m->attachment_path)) . '"')
        ->withHeader('Accept-Ranges', 'bytes')
        ->withHeader('Cache-Control', 'private, max-age=3600');
    $stream = new \Slim\Psr7\Stream(fopen((string) $m->attachment_path, 'rb'));
    return $res->withBody($stream);
});

/**
 * GET /api/materials/{id}/video —— 影视跟读：把绑定的视频流式吐给前端 <video>。
 *
 * 必须支持 Range（206）：这些文件动辄几百 MB，没有断点拖动就只能等整个下载完，
 * 「点台词跳帧」会完全不能用。所以这里按 Range 头切片段返回。
 * 注意 PHP 内置单线程服务器 php -S 在传输期间会挡住其它请求，若发现拖动时整体卡顿，
 * 可改用前端静态托管（把 videos 挂到 frontend/public 下用 Vite 直出）。
 */
$app->get('/api/materials/{id}/video', function (Request $req, Response $res, array $args) {
    $m = Capsule::table('materials')->where('id', (int) $args['id'])->first();
    $p = $m && $m->video_path ? (string) $m->video_path : '';
    if (!$p || !is_file($p)) {
        return respond($res, ['ok' => false, 'error' => '没有绑定视频'], 404);
    }
    $size = filesize($p);
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $mime = ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm'][$ext] ?? 'video/mp4';

    $fh = fopen($p, 'rb');
    $range = $req->getHeaderLine('Range');

    // 有 Range：返回 206 + 指定字节区间
    if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $mm)) {
        $start = ($mm[1] === '' || $mm[1] === null) ? 0 : (int) $mm[1];
        $end = ($mm[2] === '' || $mm[2] === null) ? $size - 1 : min((int) $mm[2], $size - 1);
        if ($start > $end || $start >= $size) {
            $start = 0; $end = $size - 1;
        }
        $len = $end - $start + 1;
        $tmp = fopen('php://temp', 'r+');
        fseek($fh, $start);
        $written = 0;
        while ($written < $len && !feof($fh)) {
            $chunk = fread($fh, min(1048576, $len - $written));
            if ($chunk === false || $chunk === '') { break; }
            $written += strlen($chunk);
            fwrite($tmp, $chunk);
        }
        rewind($tmp);
        $res = $res->withStatus(206)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
            ->withHeader('Content-Length', (string) $written)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', 'private, max-age=3600');
        return $res->withBody(new \Slim\Psr7\Stream($tmp));
    }

    // 无 Range：整段返回，但仍宣告支持 Range，供浏览器决定是否重发带 Range 的请求
    $res = $res->withHeader('Content-Type', $mime)
        ->withHeader('Content-Length', (string) $size)
        ->withHeader('Accept-Ranges', 'bytes')
        ->withHeader('Cache-Control', 'private, max-age=3600');
    return $res->withBody(new \Slim\Psr7\Stream($fh));
});

/* ============================================================ 素材 */

$app->get('/api/materials', function (Request $req, Response $res) {
    $lang = $_GET['lang'] ?? null;
    $course = $_GET['course'] ?? null;
    $type = $_GET['type'] ?? null;
    $q = Capsule::table('materials');
    if ($lang && $lang !== 'all') {
        $q->where('lang_code', $lang);
    }
    if ($course && $course !== 'all') {
        $q->where('course_id', $course);
    }
    if ($type && $type !== 'all') {
        $q->where('type', $type);
    }
    $rows = $q->orderByDesc('id')->get();
    $out = [];
    foreach ($rows as $m) {
        $out[] = [
            'id' => (int) $m->id,
            'course_id' => $m->course_id,
            'lang_code' => $m->lang_code,
            'type' => $m->type,
            'title' => $m->title,
            'author' => $m->author,
            'source' => $m->source,
            'source_url' => $m->source_url,
            'attachment_path' => $m->attachment_path,
            'meta' => jd($m->meta),
            'progress' => jd($m->progress),
            'status' => $m->status,
            'word_count' => (int) $m->word_count,
            'segment_count' => (int) $m->segment_count,
            'file_size' => $m->file_size ? (int) $m->file_size : null,
            'has_file' => $m->attachment_path && is_file((string) $m->attachment_path),
            // 剧集字段（供「影视剧集」三级浏览；非剧集素材为 null）
            'series' => $m->series,
            'season' => $m->season !== null ? (int) $m->season : null,
            'ep' => $m->ep,
            'ep_title' => $m->ep_title,
            'ep_sort' => $m->ep_sort !== null ? (float) $m->ep_sort : null,
            'created_at' => $m->created_at,
        ];
    }
    return ok($res, ['materials' => $out]);
});

// 导入素材：粘贴文本 / URL / 本地文件
$app->post('/api/materials', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    $lang = (string) ($b['lang_code'] ?? '');
    $course = $b['course_id'] ?? null;
    $type = (string) ($b['type'] ?? 'paste');
    $title = trim((string) ($b['title'] ?? ''));
    $text = (string) ($b['text'] ?? '');
    $url = trim((string) ($b['url'] ?? ''));

    if ($lang === '') {
        return respond($res, ['ok' => false, 'error' => 'lang_code 必填'], 400);
    }
    if (!in_array($type, ['paste', 'url', 'md', 'txt'], true)) {
        return respond($res, ['ok' => false, 'error' => '骨架版支持 paste / url / md / txt；epub / pdf / docx / script 等文件请走「上传文件」或「登记本地路径」'], 400);
    }

    // URL：抓正文（去标签），大陆网络不通时给出明确提示
    if ($type === 'url' && $url !== '') {
        $html = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 langlab'],
        ]));
        if ($html === false) {
            return respond($res, ['ok' => false, 'error' => '抓取失败（网络不可达或被拦）。可改为粘贴正文。', 'url' => $url], 502);
        }
        $t = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', ' ', $html);
        $t = preg_replace('/<[^>]+>/', ' ', (string) $t);
        $t = html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $t));
        if ($title === '') {
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $mm)) {
                $title = trim(html_entity_decode($mm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } else {
                $title = $url;
            }
        }
    }

    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);
    if ($text === '') {
        return respond($res, ['ok' => false, 'error' => '正文为空'], 400);
    }
    if ($title === '') {
        $title = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 30, 'UTF-8');
    }

    $now = date('Y-m-d H:i:s');
    $id = (int) Capsule::table('materials')->insertGetId([
        'course_id' => $course ?: null,
        'lang_code' => $lang,
        'type' => $type,
        'title' => $title,
        'source' => $type === 'url' ? 'web' : 'manual',
        'source_url' => $url ?: null,
        'meta' => json_encode(['raw_chars' => mb_strlen($text, 'UTF-8')], JSON_UNESCAPED_UNICODE),
        'progress' => json_encode(['read_seq' => 0], JSON_UNESCAPED_UNICODE),
        'status' => 'ready',
        'word_count' => 0,
        'segment_count' => 0,
        'created_at' => $now,
    ]);

    $chunks = segmentize($text);
    $rows = [];
    $seq = 0;
    foreach ($chunks as $c) {
        $seq++;
        $rows[] = [
            'material_id' => $id, 'seq' => $seq, 'locator' => 'p' . $seq,
            'text' => $c, 'char_count' => mb_strlen($c, 'UTF-8'), 'tokens' => null,
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        Capsule::table('segments')->insert($chunk);
    }
    Capsule::table('materials')->where('id', $id)->update([
        'segment_count' => count($rows),
        'word_count' => count_words($text, $lang),
    ]);
    apply_episode_fields($id);   // 标题带 S04E01 结构时自动归类到剧集

    $words = count_words($text, $lang);
    // 导入后立刻建索引（小素材才自动跑，大素材交给手动按钮，避免请求超时）
    $idx = null;
    if (count($rows) <= 400) {
        $idx = index_material($id);
    }
    return ok($res, [
        'material_id' => $id,
        'segments' => count($rows),
        'word_count' => $words,
        'index' => $idx,
        'message' => '已导入。切段 ' . count($rows) . ' 段 / ' . $words . ' 词。'
            . ($idx && !empty($idx['ok']) ? ($idx['message']) : '（未自动建索引，可点「生成词条」）'),
    ]);
});

$app->get('/api/materials/{id}', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $m = Capsule::table('materials')->where('id', $id)->first();
    if (!$m) {
        return respond($res, ['ok' => false, 'error' => '素材不存在'], 404);
    }
    $segs = Capsule::table('segments')->where('material_id', $id)->orderBy('seq')->get();
    $segsOut = [];
    foreach ($segs as $s) {
        $segsOut[] = ['id' => (int) $s->id, 'seq' => (int) $s->seq, 'locator' => $s->locator, 'text' => $s->text,
            'char_count' => (int) $s->char_count, 'chapter' => $s->chapter,
            // 影视跟读用：该句在视频里的起止时间（秒）与说话人
            'start_sec' => $s->start_sec !== null ? (float) $s->start_sec : null,
            'end_sec' => $s->end_sec !== null ? (float) $s->end_sec : null,
            'speaker' => $s->speaker];
    }
    $anns = Capsule::table('annotations')->where('material_id', $id)->orderByDesc('id')->get();
    return ok($res, [
        'material' => [
            'id' => (int) $m->id, 'title' => $m->title, 'type' => $m->type,
            'author' => $m->author, 'source_url' => $m->source_url,
            'lang_code' => $m->lang_code, 'course_id' => $m->course_id,
            'word_count' => (int) $m->word_count, 'segment_count' => (int) $m->segment_count,
            'attachment_path' => $m->attachment_path,
            'has_file' => $m->attachment_path && is_file((string) $m->attachment_path),
            'file_size' => $m->file_size ? (int) $m->file_size : null,
            // 影视跟读：视频成品路径。前端靠它决定是否渲染 <video> ——
            // 之前这里漏了 video_path，导致素材明明已绑视频、前端却显示"未绑定视频文件"。
            'video_path' => $m->video_path,
            'has_video' => $m->video_path && is_file((string) $m->video_path),
            'series' => $m->series, 'season' => $m->season !== null ? (int) $m->season : null,
            'ep' => $m->ep, 'ep_title' => $m->ep_title,
            'ep_sort' => $m->ep_sort !== null ? (float) $m->ep_sort : null,
            'progress' => jd($m->progress), 'created_at' => $m->created_at,
        ],
        'segments' => $segsOut,
        'annotations' => $anns,
        'episode' => episode_context($m),   // 同剧同季的上/下一集（非剧集素材返回 null）
    ]);
});

/**
 * 阅读页用的剧集上下文：当前是第几集、同季共几集、上一集/下一集是谁。
 * 非剧集素材返回 null —— 前端据此决定要不要显示「上一集/下一集」。
 */
function episode_context($m): ?array
{
    if (!$m->series || $m->season === null) {
        return null;
    }
    $sib = Capsule::table('materials')
        ->where('course_id', $m->course_id)
        ->where('series', $m->series)
        ->where('season', $m->season)
        ->orderBy('ep_sort')->orderBy('id')
        ->get();
    $list = [];
    foreach ($sib as $s) {
        $list[] = [
            'id' => (int) $s->id,
            'ep' => $s->ep,
            'label' => episode_label($s->ep, $s->ep_title),
            'ep_title' => $s->ep_title,
            'title' => $s->title,
        ];
    }
    $idx = null;
    foreach ($list as $i => $x) {
        if ($x['id'] === (int) $m->id) {
            $idx = $i;
            break;
        }
    }
    return [
        'series' => $m->series,
        'season' => (int) $m->season,
        'label' => episode_label($m->ep, $m->ep_title),
        'ep_title' => $m->ep_title,
        'index' => $idx === null ? null : $idx + 1,
        'total' => count($list),
        'prev' => ($idx !== null && $idx > 0) ? $list[$idx - 1] : null,
        'next' => ($idx !== null && $idx < count($list) - 1) ? $list[$idx + 1] : null,
    ];
}

// 更新阅读进度：read_seq（精读，段号）/ page（PDF，页码）/ cfi（EPUB，定位串）
$app->post('/api/materials/{id}/progress', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $b = (array) $req->getParsedBody();
    $m = Capsule::table('materials')->where('id', $id)->first();
    if (!$m) {
        return respond($res, ['ok' => false, 'error' => '素材不存在'], 404);
    }
    $pr = jd($m->progress) ?? [];
    foreach (['read_seq', 'page', 'total_pages'] as $k) {
        if (isset($b[$k])) {
            $pr[$k] = max((int) ($pr[$k] ?? 0), (int) $b[$k]);
        }
    }
    if (!empty($b['cfi'])) {
        $pr['cfi'] = (string) $b['cfi'];
    }
    if (!empty($b['percent'])) {
        $pr['percent'] = round((float) $b['percent'], 2);
    }
    $pr['updated_at'] = date('Y-m-d H:i:s');
    Capsule::table('materials')->where('id', $id)->update([
        'progress' => json_encode($pr, JSON_UNESCAPED_UNICODE),
    ]);
    return ok($res, ['progress' => $pr]);
});

/* ============================================================ 批注 */

$app->get('/api/annotations', function (Request $req, Response $res) {
    $materialId = (int) ($_GET['material_id'] ?? 0);
    $lang = $_GET['lang'] ?? null;
    $q = Capsule::table('annotations');
    if ($materialId > 0) {
        $q->where('material_id', $materialId);
    }
    $rows = $q->orderByDesc('id')->limit(500)->get();
    $out = [];
    foreach ($rows as $a) {
        $out[] = [
            'id' => (int) $a->id, 'material_id' => (int) $a->material_id,
            'segment_id' => $a->segment_id ? (int) $a->segment_id : null,
            'kind' => $a->kind, 'start_off' => $a->start_off, 'end_off' => $a->end_off,
            'target_text' => $a->target_text, 'gloss' => jd($a->gloss),
            'note' => $a->note, 'tags' => $a->tags, 'created_at' => $a->created_at,
        ];
    }
    return ok($res, ['annotations' => $out, 'count' => count($out)]);
});

// 新建批注：{ material_id, segment_id, kind, start_off, end_off, target_text, gloss?, note?, tags? }
$app->post('/api/annotations', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    if (empty($b['material_id']) || empty($b['target_text'])) {
        return respond($res, ['ok' => false, 'error' => 'material_id / target_text 必填'], 400);
    }
    $gloss = $b['gloss'] ?? null;
    if (is_string($gloss)) {
        $gloss = json_decode($gloss, true);
    }
    $id = Capsule::table('annotations')->insertGetId([
        'material_id' => (int) $b['material_id'],
        'segment_id' => isset($b['segment_id']) ? (int) $b['segment_id'] : null,
        'kind' => (string) ($b['kind'] ?? 'word'),
        'start_off' => isset($b['start_off']) ? (int) $b['start_off'] : null,
        'end_off' => isset($b['end_off']) ? (int) $b['end_off'] : null,
        'target_text' => (string) $b['target_text'],
        'gloss' => $gloss ? json_encode($gloss, JSON_UNESCAPED_UNICODE) : null,
        'note' => $b['note'] ?? null,
        'tags' => isset($b['tags']) ? (is_array($b['tags']) ? implode(',', $b['tags']) : (string) $b['tags']) : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    // 立刻同步成复习卡（读 → 标注 → 复习 闭环）
    $sync = null;
    $mat = Capsule::table('materials')->where('id', (int) $b['material_id'])->first();
    if ($mat && $mat->course_id) {
        $sync = sync_annotation_cards((string) $mat->course_id);
    }
    return ok($res, ['annotation_id' => $id, 'sync' => $sync]);
});

$app->delete('/api/annotations/{id}', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $a = Capsule::table('annotations')->where('id', $id)->first();
    $courseId = null;
    if ($a) {
        $mat = Capsule::table('materials')->where('id', (int) $a->material_id)->first();
        $courseId = $mat ? $mat->course_id : null;
    }
    Capsule::table('annotations')->where('id', $id)->delete();
    $sync = $courseId ? sync_annotation_cards((string) $courseId) : null;
    return ok($res, ['deleted' => $id, 'sync' => $sync]);
});

/* ============================================================ 语音合成 */

// 引擎清单与配置状态
$app->get('/api/tts/providers', function (Request $req, Response $res) {
    return ok($res, ['providers' => tts_provider_status()]);
});

// 音色列表：?provider=edge&lang=ja&locale=ja-JP
$app->get('/api/tts/voices', function (Request $req, Response $res) {
    $provider = (string) ($_GET['provider'] ?? 'edge');
    $lang = (string) ($_GET['lang'] ?? '');
    $locale = (string) ($_GET['locale'] ?? '');     // 素材的语言，用来标注「同口音」
    if ($provider === 'browser') {
        return ok($res, ['ok' => true, 'provider' => 'browser', 'voices' => [], 'groups' => [],
            'note' => '浏览器内置音色由前端枚举']);
    }
    $r = tts_voices($provider, $lang);
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'] ?? '取音色失败', 'hint' => $r['hint'] ?? ''], 502);
    }
    $voices = tts_label_voices($r['voices'] ?? [], $locale);
    $r['voices'] = $voices;
    $r['groups'] = tts_group_voices($voices);
    $r['core_locale'] = $locale;
    return ok($res, $r);
});

// 合成：返回 audio/mpeg 字节。同文本命中缓存直接吐文件
$app->post('/api/tts', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    $provider = (string) ($b['provider'] ?? 'edge');
    $voice = (string) ($b['voice'] ?? '');
    $text = (string) ($b['text'] ?? '');
    $rate = (string) ($b['rate'] ?? '+0%');
    $lang = (string) ($b['lang'] ?? '');

    if ($voice === '') {
        $voice = str_starts_with($lang, 'ja') ? 'ja-JP-NanamiNeural' : 'en-US-AriaNeural';
    }
    $r = tts_synthesize($provider, $voice, $text, $rate);
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'], 'hint' => $r['hint'] ?? '', 'fallback' => 'browser'], 502);
    }
    // 顺手清理过大的缓存
    if (random_int(1, 40) === 1) {
        tts_gc();
    }
    $stream = new \Slim\Psr7\Stream(fopen($r['path'], 'rb'));
    return $res->withHeader('Content-Type', 'audio/mpeg')
        ->withHeader('Content-Length', (string) filesize($r['path']))
        ->withHeader('Cache-Control', 'private, max-age=86400')
        ->withHeader('X-TTS-Cached', !empty($r['cached']) ? '1' : '0')
        ->withHeader('X-TTS-Voice', $voice)
        ->withBody($stream);
});

// 词级时间戳：给「跟读横线」用。与合成同参数，命中缓存时几乎零成本
$app->post('/api/tts/timings', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    $provider = (string) ($b['provider'] ?? 'edge');
    $voice = (string) ($b['voice'] ?? '');
    $text = (string) ($b['text'] ?? '');
    $lang = (string) ($b['lang'] ?? '');
    if ($voice === '') {
        $voice = str_starts_with($lang, 'ja') ? 'ja-JP-NanamiNeural' : 'en-US-AriaNeural';
    }
    $r = tts_timings($provider, $voice, $text, (string) ($b['rate'] ?? '+0%'));
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'] ?? '取时间戳失败'], 200);   // 前端降级，不算错误
    }
    return ok($res, ['words' => $r['words'] ?? [], 'chars' => $r['chars'] ?? 0]);
});

// 试听：合成成功与否的 JSON 结果（不返回音频，给设置面板用）
$app->post('/api/tts/test', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    $provider = (string) ($b['provider'] ?? 'edge');
    $voice = (string) ($b['voice'] ?? '');
    $lang = (string) ($b['lang'] ?? '');
    if ($voice === '') {
        $voice = str_starts_with($lang, 'ja') ? 'ja-JP-NanamiNeural' : 'en-US-AriaNeural';
    }
    $sample = $b['text'] ?? (str_starts_with($lang, 'ja') ? 'これは音声のテストです。' : 'This is a voice test.');
    $r = tts_synthesize($provider, $voice, (string) $sample);
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'], 'hint' => $r['hint'] ?? ''], 502);
    }
    return ok($res, ['provider' => $provider, 'voice' => $voice, 'bytes' => $r['bytes'],
        'cached' => !empty($r['cached']), 'ms' => $r['ms'] ?? null]);
});

/* ============================================================ 语音合成 end */

/* ============================================================ 分词建索引 */

// POST /api/materials/{id}/index  —— 对一份素材跑分词，生成字典条目与出现位置
$app->post('/api/materials/{id}/index', function (Request $req, Response $res, array $args) {
    $r = index_material((int) $args['id']);
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'] ?? '建索引失败'], 400);
    }
    return ok($res, $r);
});

$app->delete('/api/materials/{id}', function (Request $req, Response $res, array $args) {    $id = (int) $args['id'];
    Capsule::table('segments')->where('material_id', $id)->delete();
    Capsule::table('annotations')->where('material_id', $id)->delete();
    $entryIds = [];
    foreach (Capsule::table('dict_occurrences')->where('material_id', $id)->get() as $o) {
        $entryIds[] = (int) $o->entry_id;
    }
    Capsule::table('dict_occurrences')->where('material_id', $id)->delete();
    Capsule::table('materials')->where('id', $id)->delete();
    // 清理已无出现位置的词条
    foreach (array_unique($entryIds) as $eid) {
        if (Capsule::table('dict_occurrences')->where('entry_id', $eid)->count() === 0) {
            Capsule::table('dict_entries')->where('id', $eid)->where('source', 'auto')->delete();
        }
    }
    return ok($res, ['deleted' => $id]);
});

/* ============================================================ 字典 */

// scope=general 全部词条；scope=professional 仅术语（is_term=1）或按课程切
$app->get('/api/dict', function (Request $req, Response $res) {
    $lang = $_GET['lang'] ?? 'en-US';
    $scope = $_GET['scope'] ?? 'general';
    $course = $_GET['course'] ?? null;
    $q = trim((string) ($_GET['q'] ?? ''));
    $sort = $_GET['sort'] ?? 'freq';
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 200)));

    $query = Capsule::table('dict_entries as d')->where('d.lang_code', $lang);
    if ($scope === 'professional') {
        // 专业/术语字典 = 术语条目；若指定课程，再收窄到"该课程素材里真实出现过"的范围
        $query->where('d.is_term', 1);
        if ($course && $course !== 'all') {
            $query->whereIn('d.id', function ($sub) use ($course) {
                $sub->select('o.entry_id')
                    ->from('dict_occurrences as o')
                    ->leftJoin('materials as m', 'm.id', '=', 'o.material_id')
                    ->where('m.course_id', $course);
            });
        }
    }
    if ($q !== '') {
        $query->where(function ($w) use ($q) {
            $w->where('d.lemma', 'like', '%' . $q . '%')
                ->orWhere('d.display', 'like', '%' . $q . '%')
                ->orWhere('d.gloss_zh', 'like', '%' . $q . '%')
                ->orWhere('d.reading', 'like', '%' . $q . '%');
        });
    }
    // 按课程切：只保留在该课程素材中出现过的词条
    if ($course && $course !== 'all') {
        $query->whereIn('d.id', function ($sub) use ($course) {
            $sub->select('o.entry_id')
                ->from('dict_occurrences as o')
                ->leftJoin('materials as m', 'm.id', '=', 'o.material_id')
                ->where('m.course_id', $course);
        });
    }
    $query->orderBy($sort === 'alpha' ? 'd.lemma' : 'd.freq', $sort === 'alpha' ? 'asc' : 'desc');

    $rows = $query->limit($limit)->selectRaw('d.*')->get();
    $out = [];
    foreach ($rows as $r) {
        $occ = Capsule::table('dict_occurrences')->where('entry_id', $r->id)->count();
        $out[] = [
            'id' => (int) $r->id, 'lemma' => $r->lemma, 'display' => $r->display,
            'reading' => $r->reading, 'pos' => $r->pos,
            'gloss_zh' => $r->gloss_zh, 'gloss_en' => $r->gloss_en,
            'freq' => (int) $r->freq, 'is_term' => (int) $r->is_term,
            'source' => $r->source, 'occurrences' => $occ,
        ];
    }

    $stats = [
        'total' => Capsule::table('dict_entries')->where('lang_code', $lang)->count(),
        'terms' => Capsule::table('dict_entries')->where('lang_code', $lang)->where('is_term', 1)->count(),
        'occurrences' => Capsule::table('dict_occurrences')->count(),
    ];
    return ok($res, ['entries' => $out, 'count' => count($out), 'stats' => $stats]);
});

/* ============================================================ 笔记 */

$app->get('/api/notes', function (Request $req, Response $res) {
    $lang = $_GET['lang'] ?? null;
    $q = Capsule::table('notes');
    if ($lang && $lang !== 'all') {
        $q->where('lang_code', $lang);
    }
    $rows = $q->orderByDesc('id')->limit(500)->get();
    return ok($res, ['notes' => $rows]);
});

$app->post('/api/notes', function (Request $req, Response $res) {
    $b = (array) $req->getParsedBody();
    if (empty($b['lang_code']) || empty($b['content'])) {
        return respond($res, ['ok' => false, 'error' => 'lang_code / content 必填'], 400);
    }
    $id = Capsule::table('notes')->insertGetId([
        'lang_code' => (string) $b['lang_code'],
        'course_id' => $b['course_id'] ?? null,
        'material_id' => isset($b['material_id']) ? (int) $b['material_id'] : null,
        'segment_id' => isset($b['segment_id']) ? (int) $b['segment_id'] : null,
        'annotation_id' => isset($b['annotation_id']) ? (int) $b['annotation_id'] : null,
        'title' => $b['title'] ?? null,
        'content' => (string) $b['content'],
        'tags' => isset($b['tags']) ? (is_array($b['tags']) ? implode(',', $b['tags']) : (string) $b['tags']) : null,
        'day' => $b['day'] ?? date('Y-m-d'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return ok($res, ['note_id' => $id]);
});

/* ============================================================ 数据大盘 */

$app->get('/api/dashboard', function (Request $req, Response $res) use ($TODAY) {
    $lang = $_GET['lang'] ?? 'all';
    $scopeLang = ($lang && $lang !== 'all') ? $lang : null;

    $courses = Capsule::table('courses')->get()->all();
    if ($scopeLang) {
        $courses = array_values(array_filter($courses, fn($c) => $c->lang_code === $scopeLang));
    }
    $courseIds = array_map(fn($c) => $c->id, $courses);

    $cardQuery = Capsule::table('cards');
    if ($scopeLang) {
        $cardQuery->where('lang_code', $scopeLang);
    }
    $cardsTotal = $cardQuery->count();

    $masteredQuery = Capsule::table('cards as c')
        ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
        ->where('p.state', 'mastered');
    if ($scopeLang) {
        $masteredQuery->where('c.lang_code', $scopeLang);
    }
    $cardsMastered = $masteredQuery->count();

    // 按课程
    $byCourse = [];
    foreach ($courses as $c) {
        $t = Capsule::table('cards')->where('course_id', $c->id)->count();
        $m = Capsule::table('cards as cc')
            ->leftJoin('card_progress as p', 'p.card_id', '=', 'cc.id')
            ->where('cc.course_id', $c->id)->where('p.state', 'mastered')->count();
        $byCourse[] = [
            'id' => $c->id, 'name' => $c->name, 'lang_code' => $c->lang_code, 'kind' => $c->kind,
            'cards_total' => $t, 'cards_mastered' => $m,
            'rate' => $t > 0 ? round($m / $t * 100, 1) : 0.0,
            'materials' => Capsule::table('materials')->where('course_id', $c->id)->count(),
        ];
    }

    // 按语言
    $byLang = [];
    foreach (Capsule::table('languages')->orderBy('sort')->get() as $l) {
        $t = Capsule::table('cards')->where('lang_code', $l->code)->count();
        $m = Capsule::table('cards as cc')
            ->leftJoin('card_progress as p', 'p.card_id', '=', 'cc.id')
            ->where('cc.lang_code', $l->code)->where('p.state', 'mastered')->count();
        $byLang[] = [
            'code' => $l->code, 'name_zh' => $l->name_zh, 'script' => $l->script,
            'cards_total' => $t, 'cards_mastered' => $m,
            'rate' => $t > 0 ? round($m / $t * 100, 1) : 0.0,
            'courses' => Capsule::table('courses')->where('lang_code', $l->code)->count(),
            'materials' => Capsule::table('materials')->where('lang_code', $l->code)->count(),
            'enabled' => (int) $l->enabled,
        ];
    }

    // 会话（活动）
    $sessQuery = Capsule::table('study_sessions');
    if ($scopeLang) {
        $sessQuery->where('lang_code', $scopeLang);
    }
    $sessions = $sessQuery->get();

    $dayAgg = [];
    foreach ($sessions as $s) {
        $d = $s->day;
        if (!isset($dayAgg[$d])) {
            $dayAgg[$d] = ['count' => 0, 'minutes' => 0, 'items' => 0];
        }
        $dayAgg[$d]['count']++;
        $dayAgg[$d]['minutes'] += (int) $s->duration_sec / 60;
        $dayAgg[$d]['items'] += (int) $s->item_count;
    }

    // 连续天数
    $streak = 0;
    $cursor = new DateTimeImmutable($TODAY);
    if (!isset($dayAgg[$TODAY])) {
        $cursor = $cursor->modify('-1 day'); // 今天还没学不算断
    }
    while (isset($dayAgg[$cursor->format('Y-m-d')])) {
        $streak++;
        $cursor = $cursor->modify('-1 day');
    }

    // 分阶段对比
    $stageDefs = [
        ['key' => '7d', 'label' => '近 7 天', 'days' => 7],
        ['key' => '30d', 'label' => '近 30 天', 'days' => 30],
        ['key' => '90d', 'label' => '近 90 天', 'days' => 90],
        ['key' => 'all', 'label' => '全部', 'days' => null],
    ];
    $stages = [];
    $progQuery = Capsule::table('cards as c')
        ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
        ->where('p.state', 'mastered');
    if ($scopeLang) {
        $progQuery->where('c.lang_code', $scopeLang);
    }
    $progRows = $progQuery->selectRaw('p.last_review_at, p.updated_at')->get();

    foreach ($stageDefs as $sd) {
        $from = $sd['days'] ? (new DateTimeImmutable($TODAY))->modify('-' . ($sd['days'] - 1) . ' days')->format('Y-m-d') : null;
        $added = 0;
        foreach ($progRows as $r) {
            $d = substr((string) ($r->last_review_at ?? $r->updated_at ?? ''), 0, 10);
            if ($from === null || ($d !== '' && $d >= $from)) {
                $added++;
            }
        }
        $minutes = 0.0;
        $items = 0;
        $activeDays = 0;
        foreach ($dayAgg as $d => $v) {
            if ($from !== null && $d < $from) {
                continue;
            }
            $activeDays++;
            $minutes += $v['minutes'];
            $items += $v['items'];
        }
        $spanDays = $sd['days'] ?? max(1, count($dayAgg));
        $stages[] = [
            'key' => $sd['key'], 'label' => $sd['label'], 'from' => $from,
            'mastered_added' => $added,
            'active_days' => $activeDays,
            'minutes' => round($minutes, 1),
            'items' => $items,
            'per_active_day' => $activeDays > 0 ? round($items / $activeDays, 1) : 0.0,
            'mastered_per_day' => round($added / $spanDays, 2),
            'intensity' => round($activeDays / $spanDays * 100, 1), // 活跃度：有学习的天数占比
        ];
    }

    // 热力图（近 182 天）
    $heat = [];
    $start = (new DateTimeImmutable($TODAY))->modify('-181 days');
    $maxMin = 0;
    for ($i = 0; $i < 182; $i++) {
        $d = $start->modify('+' . $i . ' days')->format('Y-m-d');
        $v = $dayAgg[$d] ?? null;
        $mins = $v ? round($v['minutes'], 0) : 0;
        $maxMin = max($maxMin, $mins);
        $heat[] = ['day' => $d, 'minutes' => $mins, 'items' => $v['items'] ?? 0, 'sessions' => $v['count'] ?? 0];
    }

    // 素材推进率
    $matTotal = 0;
    $matRead = 0;
    $matWords = 0;
    $mq = Capsule::table('materials');
    if ($scopeLang) {
        $mq->where('lang_code', $scopeLang);
    }
    foreach ($mq->get() as $m) {
        $matTotal++;
        $matWords += (int) $m->word_count;
        $pr = jd($m->progress);
        $matRead += (int) ($pr['read_seq'] ?? 0);
    }
    $segTotal = Capsule::table('segments')->count();
    $dictEntries = Capsule::table('dict_entries')->count();

    // 生词密度 / 复现率：需要素材 + 字典，尚无数据时返回 null
    $density = null;
    $recurrence = null;
    if ($segTotal > 0 && $dictEntries > 0) {
        $occTotal = Capsule::table('dict_occurrences')->count();
        $density = round($dictEntries / max(1, $matWords) * 100, 1);
        $recurrence = round($occTotal / max(1, $dictEntries), 2);
    }

    return ok($res, [
        'scope_lang' => $scopeLang ?: 'all',
        'overall' => [
            'courses' => count($courses),
            'card_sets' => Capsule::table('card_sets')->count(),
            'cards_total' => $cardsTotal,
            'cards_mastered' => $cardsMastered,
            'mastery_rate' => $cardsTotal > 0 ? round($cardsMastered / $cardsTotal * 100, 1) : 0.0,
            'materials' => $matTotal,
            'material_segments' => $segTotal,
            'material_words' => $matWords,
            'material_progress_rate' => $segTotal > 0 ? round($matRead / $segTotal * 100, 1) : 0.0,
            'dict_entries' => $dictEntries,
            'notes' => Capsule::table('notes')->count(),
            'active_days' => count($dayAgg),
            'streak' => $streak,
            'minutes_total' => round(array_sum(array_column($dayAgg, 'minutes')), 0),
            'new_word_density' => $density,
            'recurrence_rate' => $recurrence,
        ],
        'by_lang' => $byLang,
        'by_course' => $byCourse,
        'stages' => $stages,
        'heatmap' => ['start' => $start->format('Y-m-d'), 'max' => $maxMin, 'days' => $heat],
        'notes_pending' => [
            'reader' => '阅读器（EPUB/PDF 内嵌）在下一阶段接入，素材表与切段已完成',
            'dictionary' => $dictEntries === 0 ? '字典为空：导入素材后由分词引擎生成' : null,
        ],
    ]);
});

/**
 * 把某课程的批注同步成一张卡片集（set_id = anno-{course}），形成「读 → 标注 → 复习」闭环。
 * 幂等：卡片键 = anno::{annotation_id}，重跑只更新不重复；批注删了卡片也跟着删。
 * 卡片保留 card_progress，所以同步不会清掉复习记录。
 */
function sync_annotation_cards(string $courseId): array
{
    $course = Capsule::table('courses')->where('id', $courseId)->first();
    if (!$course) {
        return ['ok' => false, 'error' => '课程不存在：' . $courseId];
    }
    $setId = 'anno-' . $courseId;
    $now = date('Y-m-d H:i:s');

    // 该课程下所有素材的批注
    $rows = Capsule::table('annotations as a')
        ->leftJoin('materials as m', 'm.id', '=', 'a.material_id')
        ->leftJoin('segments as s', 's.id', '=', 'a.segment_id')
        ->where('m.course_id', $courseId)
        ->selectRaw('a.id, a.material_id, a.segment_id, a.kind, a.target_text, a.gloss, a.note, a.created_at,
                     m.title as m_title, m.lang_code as m_lang, s.seq as seg_seq, s.text as seg_text')
        ->orderBy('a.id')
        ->get();

    // 字典兜底：批注没写释义时，用词库里的释义补上
    // 匹配顺序：① 全等（含词形还原后的单字） ② 目标文本里"包含"的最长 lemma
    //   —— 因为划选出来的常是「indemnify, defend and hold harmless」，而词库里的 lemma 是「hold harmless」
    $dict = [];
    $dictLemmas = [];
    foreach (Capsule::table('dict_entries')->where('lang_code', $course->lang_code)->get() as $e) {
        $dict[$e->lemma] = ['zh' => $e->gloss_zh, 'reading' => $e->reading, 'pos' => $e->pos];
        if ($e->gloss_zh) {
            $dictLemmas[] = $e->lemma;
        }
    }
    usort($dictLemmas, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8')); // 长的优先

    $lookup = function (string $target) use ($dict, $dictLemmas) {
        $t = trim($target);
        if ($t === '') {
            return null;
        }
        $lower = mb_strtolower($t, 'UTF-8');
        if (isset($dict[$lower]) && $dict[$lower]['zh']) {
            return $dict[$lower];
        }
        foreach ($dictLemmas as $lm) {
            if (mb_strpos($lower, mb_strtolower($lm, 'UTF-8'), 0, 'UTF-8') !== false) {
                return $dict[$lm];
            }
        }
        return null;
    };

    $cards = [];
    $i = 0;
    foreach ($rows as $a) {
        $i++;
        $gloss = jd($a->gloss) ?? [];
        $zh = trim((string) ($gloss['zh'] ?? ''));
        $reading = '';
        $pos = '';
        $hit = $lookup((string) $a->target_text);
        if ($hit) {
            if ($zh === '') {
                $zh = (string) ($hit['zh'] ?? '');
            }
            $reading = (string) ($hit['reading'] ?? '');
            $pos = (string) ($hit['pos'] ?? '');
        }
        $ctx = trim((string) ($a->seg_text ?? ''));
        $cards[] = [
            'set_id' => $setId,
            'course_id' => $courseId,
            'lang_code' => (string) ($a->m_lang ?: $course->lang_code),
            'kind' => 'anno',
            'card_key' => 'anno::' . $a->id,
            'sort' => $i,
            'data' => json_encode([
                'w' => (string) $a->target_text,
                'cn' => $zh,
                'reading' => $reading,
                'pos' => $pos,
                'note' => (string) ($a->note ?? ''),
                'ctx' => $ctx,
                'hint' => (string) $a->kind,
                'cite' => trim((string) $a->m_title) . ($a->seg_seq ? ' · 第 ' . $a->seg_seq . ' 段' : ''),
                'material_id' => (int) $a->material_id,
                'segment_id' => $a->segment_id ? (int) $a->segment_id : null,
                'annotation_id' => (int) $a->id,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ];
    }

    // 卡片集：有卡片才建，没卡片就删掉（避免空卷）
    $setExists = Capsule::table('card_sets')->where('id', $setId)->exists();
    if (!$cards) {
        if ($setExists) {
            Capsule::table('card_sets')->where('id', $setId)->delete();
            Capsule::table('cards')->where('set_id', $setId)->delete();
        }
        return ['ok' => true, 'set_id' => $setId, 'cards' => 0, 'message' => '该课程暂无批注，未生成卡片集'];
    }
    if (!$setExists) {
        Capsule::table('card_sets')->insert([
            'id' => $setId, 'course_id' => $courseId,
            'lang_code' => (string) ($cards[0]['lang_code'] ?: $course->lang_code),
            // 带上课程名：自建卷跨课程可见，多个「语料批注」同名会让用户分不清哪来的
            'title' => '语料批注 · ' . (string) $course->name,
            'sub' => '你在阅读器里划词标注的每一个点，都会自动变成这里的复习卡。',
            'kind' => 'anno', 'cats' => null, 'sort' => 99,
        ]);
    }

    // upsert（保留已存在的 card_progress）
    $keys = array_column($cards, 'card_key');
    foreach ($cards as $c) {
        $hit = Capsule::table('cards')->where('set_id', $c['set_id'])->where('card_key', $c['card_key'])->first();
        if ($hit) {
            Capsule::table('cards')->where('id', $hit->id)->update([
                'data' => $c['data'], 'sort' => $c['sort'], 'lang_code' => $c['lang_code'],
            ]);
        } else {
            Capsule::table('cards')->insert($c);
        }
    }
    // ⚠️ 同时补进度行：批注卡只插 cards 不插 progress，会留下「缺行」状态 ——
    //    复习时走懒创建分支，曾经因为写了不存在的 created_at 列而报错（2026-10-04）。
    //    这里主动补齐，让 card_progress 与 cards 始终一一对应。
    ensure_progress_rows((string) $setId);

    // 清掉已不存在的批注对应的卡片
    $stale = Capsule::table('cards')->where('set_id', $setId)->whereNotIn('card_key', $keys)->pluck('id');
    foreach ($stale as $cid) {
        Capsule::table('card_progress')->where('card_id', $cid)->delete();
        Capsule::table('cards')->where('id', $cid)->delete();
    }

    return [
        'ok' => true, 'set_id' => $setId, 'cards' => count($cards),
        'removed' => count($stale),
        'message' => '已同步 ' . count($cards) . ' 张批注卡' . (count($stale) ? '，清理 ' . count($stale) . ' 张失效卡' : ''),
    ];
}

/* ============================================================ 工具函数 */

/**
 * 对一份素材建字典索引：
 *   1) 分段送进分词器（英语=规则词形还原；日语=Sudachi 形态素解析）
 *   2) 单字词条 upsert + 记录出现位置
 *   3) 用该课程的卡片反哺释义（词条命中卡片 → 填 ipa / 词性 / 中文释义，并标为术语）
 *   4) 卡片里的多词短语（如 liability cap）若在正文中出现，单独建短语词条
 */
function index_material(int $materialId): array
{
    $m = Capsule::table('materials')->where('id', $materialId)->first();
    if (!$m) {
        return ['ok' => false, 'error' => '素材不存在'];
    }
    $segTotal = Capsule::table('segments')->where('material_id', $materialId)->count();
    if ($segTotal === 0) {
        return ['ok' => false, 'error' => '该素材没有切段，先重新导入'];
    }

    $lang = (string) $m->lang_code;
    $now = date('Y-m-d H:i:s');
    $CHUNK = 400;   // 分块处理：一本 6000 段的书不能一次性塞进内存

    // lemma => entry id（含本次新建）
    $entryId = [];
    foreach (Capsule::table('dict_entries')->where('lang_code', $lang)->get(['id', 'lemma']) as $e) {
        $entryId[$e->lemma] = (int) $e->id;
    }
    $metaOf = [];
    $freqAdd = [];
    $newEntries = 0;
    $tokenCount = 0;
    $engine = '?';

    Capsule::table('dict_occurrences')->where('material_id', $materialId)->delete();

    for ($offset = 0; $offset < $segTotal; $offset += $CHUNK) {
        $segs = Capsule::table('segments')->where('material_id', $materialId)
            ->orderBy('seq')->offset($offset)->limit($CHUNK)->get();
        $chunkTexts = [];
        foreach ($segs as $s) {
            $chunkTexts[] = (string) $s->text;
        }
        $t = tokenize_batch($lang, $chunkTexts);
        if (empty($t['ok'])) {
            return ['ok' => false, 'error' => $t['error'] ?? '分词失败'];
        }
        $engine = $t['engine'] ?? $engine;
        $results = $t['results'] ?? [];

        // ① 先算出这一块里出现过的 lemma（含位置），暂不落库
        $tokensOfSeg = [];
        $chunkLemmas = [];
        foreach ($segs as $i => $s) {
            $list = [];
            $text = (string) $s->text;
            $cursor = 0;
            foreach (($results[$i]['tokens'] ?? []) as $tk) {
                $lemma = (string) ($tk['l'] ?? '');
                $surface = (string) ($tk['s'] ?? '');
                if ($lemma === '' || $surface === '') {
                    continue;
                }
                if (!isset($entryId[$lemma])) {
                    $entryId[$lemma] = 0;
                }
                if (!isset($metaOf[$lemma])) {
                    $metaOf[$lemma] = [(string) ($tk['r'] ?? ''), (string) ($tk['p'] ?? '')];
                }
                $freqAdd[$lemma] = ($freqAdd[$lemma] ?? 0) + 1;
                $chunkLemmas[$lemma] = true;
                $tokenCount++;
                $pos = mb_strpos($text, $surface, $cursor, 'UTF-8');
                if ($pos === false) {
                    $pos = 0;
                } else {
                    $cursor = $pos + mb_strlen($surface, 'UTF-8');
                }
                $list[] = ['l' => $lemma, 's' => $surface, 'p' => $pos];
            }
            $tokensOfSeg[$i] = ['id' => (int) $s->id, 'list' => $list];
        }

        // ② 补建这一块里新出现的词条，并拿到 id
        $pending = [];
        foreach (array_keys($chunkLemmas) as $lemma) {
            if (($entryId[$lemma] ?? 0) === 0) {
                $pending[] = $lemma;
            }
        }
        if ($pending) {
            $rows = [];
            foreach ($pending as $lemma) {
                $mv = $metaOf[$lemma] ?? ['', ''];
                $rows[] = [
                    'lang_code' => $lang, 'lemma' => $lemma, 'display' => $lemma,
                    'reading' => $mv[0] !== '' ? $mv[0] : null,
                    'pos' => $mv[1] !== '' ? $mv[1] : null,
                    'gloss_zh' => null, 'gloss_en' => null, 'level' => null,
                    'freq' => 0, 'is_term' => 0, 'source' => 'auto', 'created_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $c) {
                Capsule::table('dict_entries')->insertOrIgnore($c);
            }
            foreach (array_chunk($pending, 300) as $g) {
                foreach (Capsule::table('dict_entries')->where('lang_code', $lang)->whereIn('lemma', $g)->get(['id', 'lemma']) as $e) {
                    if (($entryId[$e->lemma] ?? 0) === 0) {
                        $entryId[$e->lemma] = (int) $e->id;
                        $newEntries++;
                    }
                }
            }
        }

        // ③ 落这一块的出现位置
        $occInsert = [];
        foreach ($tokensOfSeg as $tv) {
            foreach ($tv['list'] as $tk) {
                $eid = $entryId[$tk['l']] ?? 0;
                if ($eid <= 0) {
                    continue;
                }
                $occInsert[] = [
                    'entry_id' => $eid, 'material_id' => $materialId,
                    'segment_id' => $tv['id'], 'surface' => $tk['s'], 'start_off' => $tk['p'],
                ];
            }
        }
        foreach (array_chunk($occInsert, 300) as $c) {
            Capsule::table('dict_occurrences')->insert($c);
        }
        unset($tokensOfSeg, $occInsert, $results);
    }

    // 已有条目但读音/词性为空时补齐
    foreach ($metaOf as $lemma => $mi) {
        if ($mi[0] === '' && $mi[1] === '') {
            continue;
        }
        $row = Capsule::table('dict_entries')->where('lang_code', $lang)->where('lemma', $lemma)->first();
        if ($row && ($row->reading === null || $row->reading === '' || $row->pos === null || $row->pos === '')) {
            Capsule::table('dict_entries')->where('id', $row->id)->update([
                'reading' => (($row->reading === null || $row->reading === '') && $mi[0] !== '') ? $mi[0] : $row->reading,
                'pos' => (($row->pos === null || $row->pos === '') && $mi[1] !== '') ? $mi[1] : $row->pos,
            ]);
        }
    }

    // ---------- 用课程卡片反哺释义 ----------
    $cards = Capsule::table('cards')->where('course_id', $m->course_id)->get();
    $single = [];
    $phrases = [];
    foreach ($cards as $c) {
        $d = json_decode((string) $c->data, true) ?: [];
        $w = trim((string) ($d['w'] ?? $d['n'] ?? ''));
        if ($w === '') {
            continue;
        }
        if (strpos($w, ' ') === false) {
            $single[mb_strtolower($w, 'UTF-8')] = $d;
        } else {
            $phrases[mb_strtolower($w, 'UTF-8')] = $d;
        }
    }

    $enriched = 0;
    foreach ($single as $lemma => $d) {
        $eid = $entryId[$lemma] ?? 0;
        if ($eid <= 0) {
            continue;
        }
        Capsule::table('dict_entries')->where('id', $eid)->update([
            'display' => (string) ($d['w'] ?? $lemma),
            'reading' => $d['ipa'] ?? null,
            'pos' => $d['pos'] ?? null,
            'gloss_zh' => $d['cn'] ?? null,
            'is_term' => 1,
            'source' => 'auto+card',
        ]);
        $enriched++;
    }

    // 短语词条：卡片里的多词表达若在正文出现，单独建条
    // 全文按块拼（不一次性把所有段读进内存，但也别为短语检测反复查库）
    $fullText = '';
    for ($offset = 0; $offset < $segTotal; $offset += 500) {
        foreach (Capsule::table('segments')->where('material_id', $materialId)
            ->orderBy('seq')->offset($offset)->limit(500)->pluck('text') as $tx) {
            $fullText .= "\n" . $tx;
        }
    }
    $lowerFull = mb_strtolower($fullText, 'UTF-8');
    $phraseAdded = 0;
    foreach ($phrases as $ph => $d) {
        $p = mb_strpos($lowerFull, $ph, 0, 'UTF-8');
        if ($p === false) {
            continue;
        }
        $eid = $entryId[$ph] ?? 0;
        if ($eid <= 0) {
            $eid = (int) Capsule::table('dict_entries')->insertGetId([
                'lang_code' => $m->lang_code, 'lemma' => $ph, 'display' => (string) ($d['w'] ?? $ph),
                'reading' => $d['ipa'] ?? null, 'pos' => $d['pos'] ?? null,
                'gloss_zh' => $d['cn'] ?? null, 'gloss_en' => null, 'level' => null,
                'freq' => 1, 'is_term' => 1, 'source' => 'auto+card', 'created_at' => $now,
            ]);
            $entryId[$ph] = $eid;
            $phraseAdded++;
        } else {
            Capsule::table('dict_entries')->where('id', $eid)->update([
                'display' => (string) ($d['w'] ?? $ph),
                'reading' => $d['ipa'] ?? null, 'pos' => $d['pos'] ?? null,
                'gloss_zh' => $d['cn'] ?? null, 'is_term' => 1, 'source' => 'auto+card',
            ]);
        }
        Capsule::table('dict_occurrences')->insert([
            'entry_id' => $eid, 'material_id' => $materialId, 'segment_id' => null,
            'surface' => (string) ($d['w'] ?? $ph), 'start_off' => $p,
        ]);
    }

    $totalEntries = Capsule::table('dict_entries')->where('lang_code', $lang)->count();
    $totalOcc = Capsule::table('dict_occurrences')->where('material_id', $materialId)->count();

    // 词频：按「出现记录」重算而不是累加 ——
    //   累加的话，同一份素材重复建索引会把频次翻倍；重算是幂等的
    Capsule::statement(
        'UPDATE dict_entries SET freq = (SELECT COUNT(*) FROM dict_occurrences WHERE entry_id = dict_entries.id)
         WHERE lang_code = ?',
        [$lang]
    );
    // 自净：清掉「自动生成且已不再出现」的词条。
    //   分词规则收紧（例如过滤纯数字）之后旧词条不会自己消失，必须靠这一步。
    //   带卡片释义的（auto+card）不动。
    $purged = Capsule::table('dict_entries')->where('lang_code', $lang)->where('source', 'auto')->where('freq', 0)->delete();
    if ($purged > 0) {
        $totalEntries = Capsule::table('dict_entries')->where('lang_code', $lang)->count();
    }

    return [
        'ok' => true,
        'engine' => $engine,
        'segments' => $segTotal,
        'tokens' => $tokenCount,
        'new_entries' => $newEntries,
        'enriched_from_cards' => $enriched,
        'phrase_entries' => $phraseAdded,
        'occurrences' => $totalOcc,
        'dict_total' => $totalEntries,
        'message' => '分词完成（' . $engine . '）：' . $segTotal . ' 段 / ' . $tokenCount . ' 个词次 / 新建词条 '
            . $newEntries . ' / 卡片反哺释义 ' . $enriched . ' / 短语词条 ' . $phraseAdded,
    ];
}

/** 切段：空行分段，过长的段再按句号切 */
function segmentize(string $text): array
{
    $paras = preg_split('/\n\s*\n/', $text) ?: [];
    $out = [];
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (mb_strlen($p, 'UTF-8') <= 600) {
            $out[] = $p;
            continue;
        }
        $parts = preg_split('/(?<=[.．。！？!?])\s*/u', $p) ?: [$p];
        $buf = '';
        foreach ($parts as $s) {
            if (mb_strlen($buf . $s, 'UTF-8') > 400 && $buf !== '') {
                $out[] = trim($buf);
                $buf = '';
            }
            $buf .= $s;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
    }
    return $out;
}

/** 粗口径词数：拉丁语按空白切；日语按字符数估算（形态素分词在下一阶段） */
function count_words(string $text, string $lang): int
{
    if (str_starts_with($lang, 'ja') || str_starts_with($lang, 'th')) {
        return (int) round(mb_strlen(preg_replace('/\s+/u', '', $text), 'UTF-8') / 2);
    }
    return count(preg_split('/\s+/u', trim($text)) ?: []);
}

/* ============================================================ 虚拟老师 */

/**
 * 读 backend/data/.env（虚拟老师的 key 放这里，不硬编码、不进代码）
 */
function env_get(string $k, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $f = dirname(__DIR__) . '/data/.env';
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$kk, $vv] = explode('=', $line, 2);
                $cache[trim($kk)] = trim($vv);
            }
        }
    }
    return $cache[$k] ?? $default;
}

function llm_post_json(string $url, array $payload, string $key, int $timeout = 120): array
{
    // 本机网络会被代理 MITM（证书链里是自签根证书），PHP 的 CA 包不认它，
    // 开校验必然失败（实测 verify=true 一律 error 19，关掉即 200）。
    // 所以默认关闭，但留开关：把 MITM 根证书装进 curl.cainfo 后，在 .env 设
    // LLM_SSL_VERIFY=1 即可恢复严格校验。
    $verify = filter_var(env_get('LLM_SSL_VERIFY', '0'), FILTER_VALIDATE_BOOLEAN);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        throw new \RuntimeException('调用大模型失败：' . $err);
    }
    if ($code >= 400) {
        throw new \RuntimeException('大模型 HTTP ' . $code . '：' . substr((string) $body, 0, 300));
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new \RuntimeException('大模型返回非 JSON：' . substr((string) $body, 0, 200));
    }
    return $data;
}

/** 回复正文与中文讲解的分隔符 */
const TEACHER_ZH_DELIM = '---ZH---';
/** 回复正文与「可以再往下想」的分隔符（仅中文思维伙伴模式） */
const TEACHER_POINTS_DELIM = '---POINTS---';
/** 中文讲解与纠错块的分隔符 —— 前端按它切开：前面朗读，后面渲染成纠错卡片 */
const TEACHER_DELIM = '---CORRECTIONS---';

/** 允许的目标语言。zh-CN 是特例：不是「教中文」，是「用中文一起想问题」。 */
const TEACHER_LANGS = ['en-US', 'ja-JP', 'th-TH', 'zh-CN'];

function teacher_lang_name(string $lang): string
{
    return match ($lang) {
        'ja-JP' => 'Japanese',
        'th-TH' => 'Thai',
        'zh-CN' => 'Chinese',
        default => 'English',
    };
}

/**
 * 讲解模式：
 *  bilingual —— 先外语回复，再用中文讲解（默认，最像真人老师）
 *  target    —— 沉浸模式，纯外语，不给中文
 *  zh        —— 外语短句 + 较详细的中文讲解
 */
function teacher_system(string $lang, string $explain = 'bilingual'): string
{
    // ---- 中文思维伙伴模式：不教语言，陪你把一件事想清楚 ----
    if ($lang === 'zh-CN') {
        return "你是用户的中文思维伙伴。他来找你不是为了学语言，而是为了把一件事想清楚。\n"
            . "他说一句，你输出两部分。\n\n"
            . "第一部分 —— 用中文回应：\n"
            . "- 先用一句话接住他真正的意思：别复述，要点出他自己还没说出口的那一层。\n"
            . "- 给出一个他大概率没想到的角度，或者指出他这个想法里最值得怀疑的那个假设。\n"
            . "  有不同意见就直说，不要一味附和，也不要说「你说得对」这种空话。\n"
            . "- 如果这件事可操作，给 1-2 个具体、马上能做的建议。\n"
            . "  不要「可以再调研一下」这种正确的废话，要具体到动作。\n"
            . "- 结尾抛一个能继续往下挖的问题。\n"
            . "- 3-6 句，别写成长篇报告。不要小标题，不要打分，不要纠正他的表达，不要过度夸奖。\n\n"
            . "第二部分 —— 另起一行只写 " . TEACHER_POINTS_DELIM . "，然后接一个 JSON 数组，\n"
            . "给 2-3 个「可以再往下想」的方向：\n"
            . "[{\"t\":\"换个角度：……\",\"d\":\"……\"}]\n"
            . "- t 是一句点睛的方向（20 字以内），d 是一两句说明为什么值得想。\n"
            . "- 不要 markdown，不要代码块。";
    }

    // ---- 语言教练模式（英 / 日 / 泰）----
    $L = teacher_lang_name($lang);
    $zh = ($explain !== 'target');

    $s = "You are a spoken-language coach. A Chinese-speaking learner is practicing {$L} "
        . "through FREE CONVERSATION.\n\n"
        . "PART 1 — your reply in {$L}: 1-3 short, natural sentences. Keep the conversation alive; "
        . "occasionally ask a follow-up question. Plain text only, no markdown.\n\n";

    if ($zh) {
        $detail = ($explain === 'zh')
            ? "Give a fuller teaching note: explain key words/phrases, why this phrasing is natural, "
              . "and one tip the learner can reuse."
            : "Briefly explain in Chinese: the meaning of your reply, plus 1-2 key words or phrases worth learning.";
        $s .= "PART 2 — a Simplified Chinese explanation of your PART 1 reply. Start with a line containing ONLY "
            . TEACHER_ZH_DELIM . " then write the Chinese. {$detail}\n\n";
    }

    $s .= "PART " . ($zh ? '3' : '2') . " — corrections of the learner's LAST utterance. Start with a line containing ONLY "
        . TEACHER_DELIM . " followed by a JSON array.\n"
        . "Item format: {\"wrong\":\"...\",\"fixed\":\"...\",\"note\":\"...\"}\n"
        . "- Fix grammar, word choice, unnatural phrasing, and register (too formal/too casual).\n"
        . "- \"note\" MUST be brief Simplified Chinese explaining why.\n"
        . "- If the utterance is fine, output [].\n"
        . "- If the learner wrote in Chinese instead of {$L}, correct it into natural {$L}.\n"
        . "- If the learner mixed Chinese into an {$L} sentence, show the fully natural {$L} version.\n"
        . "- No markdown, no headings, no code fences.";
    return $s;
}

/** 工具定义：让老师能真正读到本系统的学习数据 */
function teacher_tools(): array
{
    return [
        ['type' => 'function', 'function' => [
            'name' => 'get_due_cards',
            'description' => '取今天该复习的单词卡（SM-2 到期队列）',
            'parameters' => ['type' => 'object', 'properties' => [
                'course' => ['type' => 'string', 'description' => '课程 id，如 en-contract / en-drama；留空=全部'],
                'limit' => ['type' => 'integer'],
            ], 'required' => []],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_drama_lines',
            'description' => '取影视台词，可按角色名筛选。用于举例或让学习者模仿某角色的说法',
            'parameters' => ['type' => 'object', 'properties' => [
                'show' => ['type' => 'string', 'description' => '剧名关键字，如 Good Wife / 半泽'],
                'speaker' => ['type' => 'string', 'description' => '角色名，如 Alicia Florrick'],
                'limit' => ['type' => 'integer'],
            ], 'required' => []],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_vocab',
            'description' => '取某课程（如合同英语 en-contract）的词汇卡样本',
            'parameters' => ['type' => 'object', 'properties' => [
                'course' => ['type' => 'string'], 'limit' => ['type' => 'integer'],
            ], 'required' => []],
        ]],
        ['type' => 'function', 'function' => [
            'name' => 'get_progress',
            'description' => '取学习总览：各课程卡片数、今天到期数、掌握情况、剧集时间轴覆盖',
            // 空属性必须写成对象：PHP 的空数组会被 json_encode 成 []，
            // 而大模型 API 要求这里是 {}（实测报 "[] is not of type object"）
            'parameters' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
        ]],
    ];
}

/** 工具实现：直接查 langlab 自己的库 */
function teacher_call_tool(string $name, array $args): array
{
    $limit = (int) ($args['limit'] ?? 8);
    $limit = max(1, min($limit, 30));
    $today = date('Y-m-d');

    if ($name === 'get_due_cards') {
        $q = Capsule::table('cards as c')
            ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
            ->where('p.due_date', '<=', $today)
            ->where(function ($w) { $w->where('p.state', '<>', 'mastered')->orWhereNull('p.state'); });
        if (!empty($args['course'])) {
            $q->where('c.course_id', $args['course']);
        }
        // cards 表没有 front/back，内容在 JSON 列 data 里：w=词, cn=中文, en=例句, plain=释义
        $rows = $q->limit($limit)->get(['c.data', 'c.course_id', 'p.due_date']);
        return ['count' => count($rows), 'cards' => $rows->map(function ($r) {
            $d = json_decode((string) $r->data, true) ?: [];
            return ['course' => $r->course_id, 'word' => $d['w'] ?? '',
                'meaning' => $d['cn'] ?? ($d['plain'] ?? ''), 'example' => $d['en'] ?? '', 'due' => $r->due_date];
        })->all()];
    }

    if ($name === 'get_drama_lines') {
        $q = Capsule::table('segments as s')
            ->join('materials as m', 'm.id', '=', 's.material_id')
            ->whereNotNull('s.speaker');
        if (!empty($args['show'])) {
            $q->where('m.series', 'like', '%' . $args['show'] . '%');
        }
        if (!empty($args['speaker'])) {
            $q->where('s.speaker', 'like', '%' . $args['speaker'] . '%');
        }
        $rows = $q->inRandomOrder()->limit($limit)->get(['s.text', 's.speaker', 'm.series']);
        return ['count' => count($rows), 'lines' => $rows->map(function ($r) {
            return ['speaker' => $r->speaker, 'text' => $r->text, 'show' => $r->series];
        })->all()];
    }

    if ($name === 'get_vocab') {
        $course = $args['course'] ?? 'en-contract';
        // 同上：内容在 JSON 列 data
        $rows = Capsule::table('cards')->where('course_id', $course)
            ->inRandomOrder()->limit($limit)->get(['data']);
        return ['course' => $course, 'count' => count($rows),
            'vocab' => $rows->map(function ($r) {
                $d = json_decode((string) $r->data, true) ?: [];
                return ['word' => $d['w'] ?? '', 'meaning' => $d['cn'] ?? ($d['plain'] ?? ''),
                    'example' => $d['en'] ?? ''];
            })->all()];
    }

    if ($name === 'get_progress') {
        $out = [];
        foreach (Capsule::table('courses')->get() as $c) {
            $total = Capsule::table('cards')->where('course_id', $c->id)->count();
            $due = Capsule::table('cards as c')
                ->leftJoin('card_progress as p', 'p.card_id', '=', 'c.id')
                ->where('c.course_id', $c->id)->where('p.due_date', '<=', $today)->count();
            $out[] = ['course' => $c->id, 'name' => $c->name, 'cards' => $total, 'due_today' => $due];
        }
        $dramaSegs = Capsule::table('segments as s')
            ->join('materials as m', 'm.id', '=', 's.material_id')
            ->where('m.course_id', 'like', '%-drama')->count();
        $dramaTc = Capsule::table('segments as s')
            ->join('materials as m', 'm.id', '=', 's.material_id')
            ->where('m.course_id', 'like', '%-drama')->whereNotNull('s.start_sec')->count();
        return ['courses' => $out, 'drama_segments' => $dramaSegs, 'drama_with_timecode' => $dramaTc];
    }

    return ['error' => '未知工具：' . $name];
}

/** 前端自检：key 配好了吗 */
$app->get('/api/teacher/status', function (Request $req, Response $res) {
    $key = env_get('DEEPSEEK_API_KEY');
    return ok($res, [
        'configured' => $key !== '',
        'model' => env_get('DEEPSEEK_MODEL', 'deepseek-chat'),
        'tools' => array_map(function ($t) { return $t['function']['name']; }, teacher_tools()),
    ]);
});

/**
 * POST /api/teacher/chat —— 虚拟老师：自由对话 + 实时纠错。
 *
 * 入参 { messages:[{role,content}], lang:'en-US'|'ja-JP', explain:'bilingual'|'target'|'zh' }
 * 出参 { reply, zh, corrections:[{wrong,fixed,note}], tools_used:[] }
 *   reply —— 外语正文（用外语音色朗读）
 *   zh    —— 中文讲解（用中文音色朗读；explain=target 时为空）
 *
 * 带一轮工具调用：模型若要求读学习数据，就本地查库再把结果喂回去，最多 3 轮。
 * 用非流式（不做 SSE）：开了 function calling 后再做流式，中途要吞掉首轮输出，
 * 复杂度陡增而收益很小——老师的回复本来也是整段送去朗读的。
 */
$app->post('/api/teacher/chat', function (Request $req, Response $res) {
    $b = json_decode((string) $req->getBody(), true) ?: [];
    $history = $b['messages'] ?? [];
    $lang = (string) ($b['lang'] ?? 'en-US');
    if (!in_array($lang, TEACHER_LANGS, true)) {
        $lang = 'en-US';
    }
    $explain = (string) ($b['explain'] ?? 'bilingual');
    if (!in_array($explain, ['bilingual', 'target', 'zh'], true)) {
        $explain = 'bilingual';
    }
    $charId = (string) ($b['char_id'] ?? '');
    $sessionId = (int) ($b['session_id'] ?? 0);

    $key = env_get('DEEPSEEK_API_KEY');
    if ($key === '') {
        return respond($res, ['ok' => false, 'error' => '未配置 DEEPSEEK_API_KEY（backend/data/.env）'], 500);
    }
    $base = rtrim(env_get('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'), '/');
    $model = env_get('DEEPSEEK_MODEL', 'deepseek-chat');

    // 只保留最近 20 条，控 token
    $history = array_values(array_filter($history, function ($m) {
        return is_array($m) && in_array(($m['role'] ?? ''), ['user', 'assistant'], true) && !empty($m['content']);
    }));
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }
    if (!count($history)) {
        return respond($res, ['ok' => false, 'error' => '没有对话内容'], 400);
    }

    $msgs = array_merge([['role' => 'system', 'content' => teacher_system($lang, $explain)]], $history);
    $tools = teacher_tools();
    $toolsUsed = [];

    try {
        $content = '';
        for ($round = 0; $round < 3; $round++) {
            $payload = [
                'model' => $model,
                'messages' => $msgs,
                'temperature' => 0.7,
                'max_tokens' => 700,
            ];
            if ($round === 0) {
                $payload['tools'] = $tools;     // 只在首轮给工具，避免死循环
            }
            $data = llm_post_json($base . '/chat/completions', $payload, $key);
            $choice = $data['choices'][0]['message'] ?? null;
            if (!$choice) {
                throw new \RuntimeException('大模型返回为空');
            }

            if (!empty($choice['tool_calls'])) {
                $msgs[] = $choice;
                foreach ($choice['tool_calls'] as $tc) {
                    $fn = $tc['function']['name'] ?? '';
                    $args = json_decode((string) ($tc['function']['arguments'] ?? '{}'), true) ?: [];
                    $result = teacher_call_tool($fn, $args);
                    $toolsUsed[] = $fn;
                    $msgs[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '',
                        'name' => $fn, 'content' => json_encode($result, JSON_UNESCAPED_UNICODE)];
                }
                continue;
            }

            $content = (string) ($choice['content'] ?? '');
            break;
        }

        // 切开：正文 → （中文讲解 或 延伸思考） → 纠错 JSON
        $parts = explode(TEACHER_DELIM, $content, 2);
        $head = $parts[0];
        $zh = '';
        $points = [];
        $reply = $head;
        if (strpos($head, TEACHER_POINTS_DELIM) !== false) {
            // 中文思维伙伴模式：正文 + 可以再往下想
            [$reply, $p] = explode(TEACHER_POINTS_DELIM, $head, 2);
            $points = array_values(array_filter(teacher_json_arr($p), function ($x) {
                return is_array($x) && ($x['t'] ?? '') !== '';
            }));
        } elseif (strpos($head, TEACHER_ZH_DELIM) !== false) {
            // 语言教练模式：外语正文 + 中文讲解
            [$reply, $zh] = explode(TEACHER_ZH_DELIM, $head, 2);
        }
        // 模型偶尔会在分隔符前多留一串横线，尾部清掉
        $reply = trim(rtrim(trim($reply), "\r\n-"));
        $zh = trim(rtrim(trim($zh), "\r\n-"));
        $corr = [];
        if (isset($parts[1])) {
            $corr = array_values(array_filter(teacher_json_arr($parts[1]), function ($x) {
                return is_array($x) && (($x['wrong'] ?? '') !== '' || ($x['fixed'] ?? '') !== '');
            }));
        }
        // ---- 落库：会话不存在就自动建一个，标题取第一条发言 ----
        $now = date('Y-m-d H:i:s');
        if ($sessionId <= 0 || !Capsule::table('teacher_sessions')->where('id', $sessionId)->exists()) {
            $sessionId = Capsule::table('teacher_sessions')->insertGetId([
                'title' => null, 'lang' => $lang, 'char_id' => $charId,
                'explain_mode' => $explain, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $lastUser = '';
        foreach (array_reverse($history) as $h) {
            if (($h['role'] ?? '') === 'user') { $lastUser = (string) $h['content']; break; }
        }
        Capsule::table('teacher_messages')->insert([
            'session_id' => $sessionId, 'role' => 'me', 'content' => $lastUser,
            'zh' => null, 'corrections' => null, 'tools' => null, 'created_at' => $now,
        ]);
        Capsule::table('teacher_messages')->insert([
            'session_id' => $sessionId, 'role' => 'ai', 'content' => $reply, 'zh' => $zh,
            'corrections' => json_encode($corr, JSON_UNESCAPED_UNICODE),
            'tools' => json_encode(array_values(array_unique($toolsUsed)), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);
        $title = (string) (Capsule::table('teacher_sessions')->where('id', $sessionId)->value('title') ?? '');
        if ($title === '') {
            $title = teacher_title_from($lastUser);
        }
        Capsule::table('teacher_sessions')->where('id', $sessionId)
            ->update(['title' => $title, 'lang' => $lang, 'char_id' => $charId,
                'explain_mode' => $explain, 'updated_at' => $now]);

        return ok($res, [
            'reply' => $reply,
            'zh' => $zh,
            'points' => $points,
            'corrections' => $corr,
            'tools_used' => array_values(array_unique($toolsUsed)),
            'session_id' => $sessionId,
            'title' => $title,
        ]);
    } catch (\Throwable $e) {
        return respond($res, ['ok' => false, 'error' => $e->getMessage()], 502);
    }
});

/* ------------------------------------------------------------------ *
 * DELETE /api/cards/{id} —— 删掉自己随手建的卡
 *
 * ⚠️ 只允许删 `my-*` 卷（「我的生词 · 手动添加」）里的卡。
 *    语料批注卡、语料卡这些有来源的，一律不允许从这里删 —— 它们的生命周期归素材/批注管。
 * ------------------------------------------------------------------ */
$app->delete('/api/cards/{id}', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $c = Capsule::table('cards')->where('id', $id)->first();
    if (!$c) {
        return respond($res, ['ok' => false, 'error' => '卡片不存在'], 404);
    }
    if (!str_starts_with((string) $c->set_id, 'my-')) {
        return respond($res, ['ok' => false,
            'error' => '只允许删除「我的生词」卷里的卡（这张属于 ' . $c->set_id . '，请到它的来源处删）'], 403);
    }
    Capsule::table('card_progress')->where('card_id', $id)->delete();
    Capsule::table('cards')->where('id', $id)->delete();
    $left = Capsule::table('cards')->where('set_id', $c->set_id)->count();
    if ($left === 0) {
        Capsule::table('card_sets')->where('id', $c->set_id)->delete();   // 空卷不留下
    }
    return ok($res, ['deleted' => $id, 'set_left' => $left]);
});

/* ------------------------------------------------------------------ *
 * POST /api/cards/quick —— 卡片页「就地建卡」
 *
 * 场景：在卡片里点某个词 → 「没有这个词的卡片」→ 以前只能跳去阅读器划词。
 * 现在可以直接在这里建：塞进该课程的「我的生词 · 手动添加」卷。
 *
 * 入参 { word, ctx?, cn?, ipa?, pos?, from?, course_id?, lang_code?, auto_gloss? }
 *   ctx        —— 你看到它的那句话（会当「出处原句」，方便日后回忆语境）
 *   from       —— 来源说明，如「卡片：Client's obligations」
 *   auto_gloss —— cn 为空时，用大模型补释义+音标+词性（默认 true）
 * 出参 { ok, created, set_id, card }
 *
 * ⚠️ 词库帮不上忙：本地词库的释义覆盖率通常极低。所以手动建卡必须自己给释义，
 *    否则就是一张空卡 —— auto_gloss 就是为这个补的。
 * ------------------------------------------------------------------ */
$app->post('/api/cards/quick', function (Request $req, Response $res) {
    $b = json_decode((string) $req->getBody(), true) ?: [];
    $word = trim((string) ($b['word'] ?? ''));
    if ($word === '') {
        return respond($res, ['ok' => false, 'error' => 'word 必填'], 400);
    }
    $courseId = (string) ($b['course_id'] ?? '');
    $course = $courseId !== '' ? Capsule::table('courses')->where('id', $courseId)->first() : null;
    if (!$course) {
        $course = Capsule::table('courses')->orderBy('sort')->first();
    }
    if (!$course) {
        return respond($res, ['ok' => false, 'error' => '还没有任何课程'], 400);
    }
    $courseId = (string) $course->id;
    $lang = trim((string) ($b['lang_code'] ?? '')) ?: (string) $course->lang_code;
    $ctx = trim((string) ($b['ctx'] ?? ''));
    $from = trim((string) ($b['from'] ?? ''));
    $cn = trim((string) ($b['cn'] ?? ''));
    $ipa = trim((string) ($b['ipa'] ?? ''));
    $pos = trim((string) ($b['pos'] ?? ''));
    $plain = '';
    $glossSrc = $cn !== '' ? 'manual' : '';

    // ① 词库兜底（术语词多半有）
    if ($cn === '') {
        $row = Capsule::table('dict_entries')
            ->where('lang_code', $lang)
            ->where(function ($w) use ($word) {
                $w->where('lemma', mb_strtolower($word, 'UTF-8'))->orWhere('display', $word);
            })
            ->whereNotNull('gloss_zh')->where('gloss_zh', '!=', '')
            ->first();
        if ($row) {
            $cn = (string) $row->gloss_zh;
            if ($ipa === '') { $ipa = (string) $row->reading; }
            if ($pos === '') { $pos = (string) $row->pos; }
            $glossSrc = 'dict';
        }
    }

    // ② 还是没有 → 让大模型补（失败就算了，卡照建，只是释义空着）
    $aiErr = '';
    $auto = !isset($b['auto_gloss']) || $b['auto_gloss'];
    if ($cn === '' && $auto) {
        $key = env_get('DEEPSEEK_API_KEY');
        if ($key !== '') {
            $base = rtrim(env_get('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'), '/');
            $model = env_get('DEEPSEEK_MODEL', 'deepseek-chat');
            $sys = '你是英汉词典。只输出一行 JSON，不要解释、不要代码围栏：'
                . '{"zh":"中文释义（≤18字，法律/商务语境优先）","plain":"一句大白话解释（≤30字）",'
                . '"ipa":"国际音标，**美式发音（General American）**，带斜杠","pos":"词性缩写，如 n./v./adj."}';
            $usr = '词：' . $word . ($ctx !== '' ? "\n它出现的句子：" . mb_substr($ctx, 0, 200) : '');
            try {
                $data = llm_post_json($base . '/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $usr]],
                    'temperature' => 0.2,
                    'max_tokens' => 220,
                ], $key);
                $txt = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
                if (preg_match('/\{[\s\S]*\}/', $txt, $mm)) {
                    $j = json_decode($mm[0], true) ?: [];
                    $cn = trim((string) ($j['zh'] ?? ''));
                    $plain = trim((string) ($j['plain'] ?? ''));
                    if ($ipa === '') { $ipa = trim((string) ($j['ipa'] ?? '')); }
                    if ($pos === '') { $pos = trim((string) ($j['pos'] ?? '')); }
                    if ($cn !== '') { $glossSrc = 'ai'; }
                }
            } catch (\Throwable $e) {
                $aiErr = $e->getMessage();
            }
        } else {
            $aiErr = '未配置 DEEPSEEK_API_KEY';
        }
    }

    // ③ 卡片卷：my-<courseId>，有卡片才建
    $setId = 'my-' . $courseId;
    if (!Capsule::table('card_sets')->where('id', $setId)->exists()) {
        Capsule::table('card_sets')->insert([
            'id' => $setId, 'course_id' => $courseId, 'lang_code' => $lang,
            'title' => '我的生词 · ' . (string) $course->name,
            'sub' => '你在卡片里随手点出来、当场建的生词。跟语料批注卡分开，方便单独过一遍。',
            'kind' => 'word', 'cats' => json_encode(['我的生词' => '我的生词'], JSON_UNESCAPED_UNICODE),
            'sort' => 98, 'release_week' => 1,
        ]);
    }

    // ④ 去重键：同卷同词只留一张（再点一次 = 更新，不重复堆）
    $slug = mb_strtolower($word, 'UTF-8');
    $slug = trim((string) preg_replace('/[^0-9a-z\x{4e00}-\x{9fff}]+/u', '-', $slug), '-');
    if ($slug === '') { $slug = substr(sha1($word), 0, 8); }
    $cardKey = 'my::' . $slug;

    $hit = Capsule::table('cards')->where('set_id', $setId)->where('card_key', $cardKey)->first();
    $sort = $hit ? (int) $hit->sort
        : (int) (Capsule::table('cards')->where('set_id', $setId)->max('sort') ?? 0) + 1;

    $data = [
        'w' => $word,
        'cn' => $cn,
        'plain' => $plain,
        'ipa' => $ipa,
        'pos' => $pos,
        'en' => $ctx,                    // 出处原句 = 你看到它的那句话
        'cite' => $from !== '' ? $from : '手动添加',
        'src' => $glossSrc === 'ai' ? 'AI 补义' : ($glossSrc === 'dict' ? '词库' : '手动'),
        'cat' => '我的生词',
    ];
    $now = date('Y-m-d H:i:s');
    if ($hit) {
        // 只覆盖非空字段，别把已有内容擦掉
        $old = jd($hit->data) ?? [];
        foreach ($data as $k => $v) {
            if ($v === '' || $v === null) { $data[$k] = $old[$k] ?? ''; }
        }
        Capsule::table('cards')->where('id', $hit->id)->update([
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'sort' => $sort, 'lang_code' => $lang,
        ]);
        $cardId = (int) $hit->id;
    } else {
        $cardId = Capsule::table('cards')->insertGetId([
            'set_id' => $setId, 'course_id' => $courseId, 'lang_code' => $lang,
            'kind' => 'word', 'card_key' => $cardKey, 'sort' => $sort,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => $now,
        ]);
    }

    ensure_progress_rows((string) $setId);   // 同上：别留「缺进度行」的卡

    $total = Capsule::table('cards')->where('set_id', $setId)->count();
    return ok($res, [
        'created' => !$hit, 'card_id' => $cardId, 'set_id' => $setId, 'set_total' => $total,
        'card' => array_merge(['id' => $cardId, 'set_id' => $setId, 'kind' => 'word'], $data),
        'gloss_source' => $glossSrc, 'ai_error' => $aiErr,
    ]);
});

/* ------------------------------------------------------------------ *
 * 虚拟老师 · 发音诊断
 *
 * POST /api/teacher/assess（multipart：audio=<音频文件>, lang=<en|ja|zh|th>, ref=<可选参考文本>）
 *
 * 背景：虚拟老师原来走浏览器 Web Speech API，只拿回文本、音频不留，
 * 于是老师无法判断「他是念错了，还是念对了但识别错」——正是他要解决的问题。
 * 本接口把音频留下来，用本地 whisper 做词级分析，输出「哪里念得吃力/含糊/卡壳」。
 *
 * 引擎：backend/scripts/assess_pron.py（本地 whisper-small，零成本、RTF≈0.04）
 * 能力边界：能抓念错词 / 含糊 / 吞音 / 卡壳 / 语速；抓不到音标级细节（θ 发成 s 这类）。
 * ------------------------------------------------------------------ */
$app->post('/api/teacher/assess', function (Request $req, Response $res) {
    $files = $req->getUploadedFiles();
    $file = $files['audio'] ?? null;
    if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
        $code = $file ? $file->getError() : -1;
        return respond($res, ['ok' => false, 'error' => '音频上传失败，错误码 ' . $code], 400);
    }
    $body = (array) $req->getParsedBody();
    $lang = (string) ($body['lang'] ?? 'en');
    $lang = in_array($lang, ['en', 'ja', 'zh', 'th'], true) ? $lang : 'en';
    $ref = (string) ($body['ref'] ?? '');

    $dir = dirname(__DIR__) . '/data/rec';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    // 扩展名只认白名单，避免上传任意后缀
    $orig = (string) $file->getClientFilename();
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ['webm', 'ogg', 'opus', 'm4a', 'mp4', 'wav', 'mp3', 'aac'], true)) {
        $ext = 'webm';
    }
    $name = date('Ymd-His') . '-' . substr(sha1($orig . microtime()), 0, 8) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $file->moveTo($dest);
    if (!is_file($dest)) {
        return respond($res, ['ok' => false, 'error' => '音频落盘失败'], 500);
    }

    $py = langlab_python();
    $script = dirname(__DIR__) . '/scripts/assess_pron.py';
    if (!is_file($script)) {
        return respond($res, ['ok' => false, 'error' => 'assess_pron.py 不存在'], 500);
    }
    $args = [$py, $script, '--in', $dest, '--lang', $lang];
    if ($ref !== '') {
        $args[] = '--ref';
        $args[] = $ref;
    }
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($args, $desc, $pipes);
    if (!is_resource($proc)) {
        return respond($res, ['ok' => false, 'error' => '无法启动 python（' . $py . '）'], 500);
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $errS = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    // 容错：截取第一个 { 到最后一个 }（库告警可能混进 stdout）
    $a = strpos($out, '{');
    $b = strrpos($out, '}');
    if ($a === false || $b === false || $b < $a) {
        return respond($res, ['ok' => false,
            'error' => '诊断器输出无法解析：' . trim(mb_substr($out . ' ' . $errS, 0, 300))], 502);
    }
    $r = json_decode(substr($out, $a, $b - $a + 1), true);
    if (!is_array($r)) {
        return respond($res, ['ok' => false, 'error' => '诊断结果不是 JSON'], 502);
    }
    if (empty($r['ok'])) {
        return respond($res, ['ok' => false, 'error' => $r['error'] ?? '诊断失败'], 502);
    }
    $r['file'] = $name;
    return ok($res, $r);
});

/* ------------------------------------------------------------------ *
 * 虚拟老师 · 发音点评
 *
 * POST /api/teacher/pronounce
 *   入参 { lang, text, flags:[{w,reason,p,dur}] }
 *   出参 { tip }
 *
 * 只做一件事：把「发音诊断挑出来的那几个词」变成具体可执行的纠正建议。
 * 刻意不复用 /api/teacher/chat —— 那条路带着系统提示词、工具、会话历史，
 * 为了几十个字的点评付那个代价不划算，也会污染对话记录。
 * 无状态、不落库：点评展示在诊断卡里，随本轮对话存在。
 * ------------------------------------------------------------------ */
$app->post('/api/teacher/pronounce', function (Request $req, Response $res) {
    $b = json_decode((string) $req->getBody(), true) ?: [];
    $text = trim((string) ($b['text'] ?? ''));
    $flags = $b['flags'] ?? [];
    $lang = (string) ($b['lang'] ?? 'en');
    if (!is_array($flags) || !count($flags)) {
        return ok($res, ['tip' => '']);
    }
    $key = env_get('DEEPSEEK_API_KEY');
    if ($key === '') {
        return respond($res, ['ok' => false, 'error' => '未配置 DEEPSEEK_API_KEY'], 500);
    }
    $base = rtrim(env_get('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'), '/');
    $model = env_get('DEEPSEEK_MODEL', 'deepseek-chat');

    // 只取前 5 个，按严重度（置信度升序）排 —— 一次讲太多他记不住
    usort($flags, fn($a, $c) => ($a['p'] ?? 1) <=> ($c['p'] ?? 1));
    $lines = [];
    foreach (array_slice($flags, 0, 5) as $f) {
        $w = (string) ($f['w'] ?? '');
        if ($w === '') {
            continue;
        }
        $why = ($f['reason'] ?? '') === 'long_dur' ? '拖了很久' : '识别置信度低';
        $lines[] = sprintf('- %s（%s，置信度 %.0f%%，耗时 %ss）', $w, $why,
            (float) ($f['p'] ?? 0) * 100, $f['dur'] ?? '?');
    }
    if (!count($lines)) {
        return ok($res, ['tip' => '']);
    }

    $sys = '你是英语发音教练，服务对象是一位中文母语者。'
        . '他的典型问题是「按声音记词」——把词念成听起来像的另一个词（如 due 念成 dew、fraudulent 念成 flaugellent），'
        . '而不是音标细节不到位。'
        . '下面是他刚说的一句话里，语音识别最吃力/最拖沓的几个词。'
        . '请给出具体、可执行的纠正建议：每个词用 1 行，格式「词 /IPA/ — 一句话说清怎么念 + 最容易错在哪」。'
        . '**必须标国际音标**。若某词是常用词却识别吃力，优先怀疑元音长度、重音位置、词尾辅音被吞。'
        . '不要客套、不要总起句、不要总结句，直接列。总长不超过 150 字。';

    $user = "他说的话：" . ($text !== '' ? $text : '（未取到文本）') . "\n\n识别吃力的词：\n" . implode("\n", $lines);

    try {
        $data = llm_post_json($base . '/chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
            'temperature' => 0.3,
            'max_tokens' => 340,
        ], $key);
        $tip = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        return ok($res, ['tip' => $tip]);
    } catch (\Throwable $e) {
        return respond($res, ['ok' => false, 'error' => $e->getMessage()], 502);
    }
});

/* ------------------------------------------------------------------ *
 * 虚拟老师 · 多会话历史（一次对话 = 一个 session，可回看、可继续、可改名/删除）
 * ------------------------------------------------------------------ */

/** 从模型输出里抠出 JSON 数组：容忍 markdown 代码围栏和前后口水话 */
function teacher_json_arr(string $s): array
{
    $c = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($s)));
    if (preg_match('/\[[\s\S]*\]/', $c, $mm)) {
        $c = $mm[0];
    }
    $decoded = json_decode($c, true);
    return is_array($decoded) ? $decoded : [];
}

/** 用第一条发言自动命名会话 */
function teacher_title_from(string $text): string
{
    $t = trim((string) preg_replace('/\s+/', ' ', $text));
    if ($t === '') {
        return '新对话';
    }
    return mb_substr($t, 0, 22, 'UTF-8') . (mb_strlen($t, 'UTF-8') > 22 ? '…' : '');
}

/** GET /api/teacher/sessions —— 会话列表（新的在前） */
$app->get('/api/teacher/sessions', function (Request $req, Response $res) {
    if (!Capsule::schema()->hasTable('teacher_sessions')) {
        return ok($res, []);
    }
    $rows = Capsule::select(
        'SELECT s.id, s.title, s.lang, s.char_id, s.explain_mode, s.updated_at, '
        . '(SELECT COUNT(*) FROM teacher_messages m WHERE m.session_id = s.id) AS msg_count, '
        . '(SELECT m.content FROM teacher_messages m WHERE m.session_id = s.id ORDER BY m.id LIMIT 1) AS first_msg '
        . 'FROM teacher_sessions s ORDER BY s.id DESC LIMIT 200'
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r->id,
            'title' => $r->title ?: teacher_title_from((string) $r->first_msg),
            'lang' => $r->lang, 'char_id' => $r->char_id, 'explain' => $r->explain_mode,
            'msg_count' => (int) $r->msg_count, 'updated_at' => $r->updated_at,
        ];
    }
    // 列表一律包一层键名：ok() 用 ['ok'=>true] + $data，直接给数字下标的数组会散成 "0","1"...
    return ok($res, ['sessions' => $out]);
});

/** POST /api/teacher/sessions —— 新建会话 */
$app->post('/api/teacher/sessions', function (Request $req, Response $res) {
    $b = json_decode((string) $req->getBody(), true) ?: [];
    $now = date('Y-m-d H:i:s');
    $explain = (string) ($b['explain'] ?? 'bilingual');
    $id = Capsule::table('teacher_sessions')->insertGetId([
        'title' => null,
        'lang' => in_array(($b['lang'] ?? ''), TEACHER_LANGS, true) ? $b['lang'] : 'en-US',
        'char_id' => (string) ($b['char_id'] ?? ''),
        'explain_mode' => in_array($explain, ['bilingual', 'target', 'zh'], true) ? $explain : 'bilingual',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    return ok($res, ['id' => (int) $id]);
});

/** GET /api/teacher/sessions/{id} —— 取某个会话的全部消息 */
$app->get('/api/teacher/sessions/{id}', function (Request $req, Response $res, array $args) {
    $sid = (int) $args['id'];
    $s = Capsule::table('teacher_sessions')->where('id', $sid)->first();
    if (!$s) {
        return respond($res, ['ok' => false, 'error' => '会话不存在'], 404);
    }
    $msgs = [];
    foreach (Capsule::table('teacher_messages')->where('session_id', $sid)->orderBy('id')->get() as $m) {
        $msgs[] = [
            'role' => $m->role === 'ai' ? 'ai' : 'me',
            'text' => (string) $m->content,
            'zh' => (string) $m->zh,
            'corrections' => json_decode((string) $m->corrections, true) ?: [],
            'tools' => json_decode((string) $m->tools, true) ?: [],
        ];
    }
    return ok($res, [
        'session' => ['id' => (int) $s->id, 'title' => $s->title, 'lang' => $s->lang,
            'char_id' => $s->char_id, 'explain' => $s->explain_mode],
        'messages' => $msgs,
    ]);
});

/** PATCH /api/teacher/sessions/{id} —— 改标题 */
$app->patch('/api/teacher/sessions/{id}', function (Request $req, Response $res, array $args) {
    $b = json_decode((string) $req->getBody(), true) ?: [];
    $title = trim((string) ($b['title'] ?? ''));
    if ($title === '') {
        return respond($res, ['ok' => false, 'error' => '标题不能为空'], 400);
    }
    Capsule::table('teacher_sessions')->where('id', (int) $args['id'])
        ->update(['title' => mb_substr($title, 0, 40, 'UTF-8'), 'updated_at' => date('Y-m-d H:i:s')]);
    return ok($res, ['title' => mb_substr($title, 0, 40, 'UTF-8')]);
});

/** DELETE /api/teacher/sessions/{id} */
$app->delete('/api/teacher/sessions/{id}', function (Request $req, Response $res, array $args) {
    $sid = (int) $args['id'];
    Capsule::table('teacher_messages')->where('session_id', $sid)->delete();
    Capsule::table('teacher_sessions')->where('id', $sid)->delete();
    return ok($res, ['deleted' => $sid]);
});

$app->run();
