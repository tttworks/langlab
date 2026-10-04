# -*- coding: utf-8 -*-
"""
档 1 回归：review 端点会写 card_reviews（revlog），且**不改变 SM-2 调度**。

    python tools/e2e_revlog.py

测完自动恢复（删除本次产生的流水 + 还原 card_progress），**不留测试污染** ——
因为 revlog 将来要用来拟合 FSRS 参数，假数据必须清掉。
"""
import json
import math
import sqlite3
import sys
import urllib.request

API = 'http://127.0.0.1:8001/api'
DB = r'/path/to/langlab/backend/data/langlab.sqlite'


def call(path, body=None, method='POST', timeout=20):
    data = json.dumps(body or {}).encode('utf-8')
    req = urllib.request.Request(API + path, data=data,
                                 headers={'Content-Type': 'application/json'},
                                 method=method)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode('utf-8'))


def php_round(x):
    """PHP round()：half away from zero（Python 的 round 是 banker's rounding，不同）"""
    return int(math.floor(abs(x) + 0.5)) * (1 if x >= 0 else -1)


con = sqlite3.connect(DB)
con.row_factory = sqlite3.Row

card = con.execute(
    "select * from card_progress where review_count = 0 order by card_id limit 1").fetchone()
cid = card['card_id']
before = dict(card)
rev0 = con.execute("select coalesce(max(id),0) from card_reviews").fetchone()[0]

print('目标卡:', cid)
print('复习前:', {k: before[k] for k in
                ('state', 'due_date', 'ease', 'interval', 'repetitions', 'lapses', 'review_count')})

grades = ['again', 'hard', 'good', 'easy']
passed = False
rows = []
try:
    print('\n--- 依次点四个评级 ---')
    for g in grades:
        out = call('/cards/%d/review' % cid, {'grade': g})
        print('  %-6s -> ok=%s interval=%s state=%s due=%s' % (
            g, out.get('ok'), out.get('interval_days'), out.get('state'), out.get('due_date')))

    rows = list(con.execute(
        "select * from card_reviews where id > ? and card_id = ? order by id", (rev0, cid)))
    print('\n--- card_reviews 新增 %d 条 ---' % len(rows))
    for r in rows:
        print('  rating=%s state_before=%s interval %s->%s due_after=%s src=%s at=%s' % (
            r['rating'], r['state_before'], r['interval_before'], r['interval_after'],
            r['due_after'], r['source'], r['reviewed_at']))

    assert len(rows) == 4, 'revlog 应新增 4 条，实际 %d' % len(rows)
    assert [r['rating'] for r in rows] == [1, 2, 3, 4], 'rating 应依次为 1/2/3/4'
    assert all(r['reviewed_at'] for r in rows), 'reviewed_at 不应为空'
    assert rows[0]['state_before'] == before['state'], 'state_before 应为复习前状态'

    # 独立复算 SM-2（与 index.php 的公式一致）—— 证明调度逻辑没被影响
    e, iv, rp, lp = 2.5, 0, 0, 0
    expect = []
    for g in grades:
        if g == 'again':
            rp, iv, lp = 0, 0, lp + 1
            e = max(1.3, e - 0.20)
        elif g == 'hard':
            iv = 1 if rp == 0 else max(1, php_round(iv * 1.2))
        elif g == 'good':
            iv = 1 if rp == 0 else max(1, php_round(iv * e))
            rp += 1
        elif g == 'easy':
            iv = 4 if rp == 0 else max(1, php_round(iv * e * 1.3))
            rp += 1
            e += 0.15
        expect.append(iv)

    got = [r['interval_after'] for r in rows]
    print('\nSM-2 期望 interval:', expect)
    print('实际记录 interval:', got)
    assert got == expect, '排期与 SM-2 公式不符（说明调度被改动了，而档 1 不该动调度）'

    print('\n[PASS] revlog 记录正确；调度仍是原 SM-2，未被影响。')
    passed = True
except Exception as ex:
    print('\n[FAIL]', type(ex).__name__, ex)
finally:
    con.execute("delete from card_reviews where id > ?", (rev0,))
    con.execute(
        """update card_progress set state=?, due_date=?, ease=?, interval=?,
           repetitions=?, lapses=?, review_count=?, last_review_at=?, updated_at=?
           where card_id=?""",
        (before['state'], before['due_date'], before['ease'], before['interval'],
         before['repetitions'], before['lapses'], before['review_count'],
         before['last_review_at'], before['updated_at'], cid))
    con.commit()
    left = con.execute("select count(*) from card_reviews").fetchone()[0]
    cur = con.execute("select * from card_progress where card_id=?", (cid,)).fetchone()
    same = all(cur[k] == before[k] for k in before)
    print('\n已清理：删除本次 %d 条测试流水；card %d 还原%s；card_reviews 现存 %d 条'
          % (len(rows), cid, '成功' if same else '失败', left))

sys.exit(0 if passed else 1)
