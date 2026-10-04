# -*- coding: utf-8 -*-
"""
langlab 分词器（服务端）

用法（由 PHP 通过 proc_open 调用）：
    echo '{"lang":"ja-JP","text":"..."}' | python tokenizer_cli.py
输出：
    {"ok":true,"lang":"ja-JP","engine":"sudachi","tokens":[{"s":表層形,"l":原形,"r":读音,"p":词性}]}

- 日语：SudachiPy 形态素解析（原形 / 读音 / 词性）
- 英语：分词 + 规则化词形还原（含不规则表）
- 其他：按空白切分
所有异常都返回 {"ok":false,"error":"..."}，由 PHP 侧降级处理，不让整条链路崩。
"""

import json
import re
import sys

# ---------------------------------------------------------------- 英语

IRREGULAR = {
    "arises": "arise", "arising": "arise", "arose": "arise",
    "becomes": "become", "becoming": "become", "became": "become",
    "begins": "begin", "beginning": "begin", "began": "begin",
    "brings": "bring", "bringing": "bring", "brought": "bring",
    "comes": "come", "coming": "come", "came": "come",
    "does": "do", "doing": "do", "did": "do", "done": "do",
    "falls": "fall", "falling": "fall", "fell": "fall",
    "gives": "give", "giving": "give", "gave": "give", "given": "give",
    "goes": "go", "going": "go", "went": "go", "gone": "go",
    "holds": "hold", "holding": "hold", "held": "hold",
    "keeps": "keep", "keeping": "keep", "kept": "keep",
    "makes": "make", "making": "make", "made": "make",
    "means": "mean", "meaning": "mean", "meant": "mean",
    "pays": "pay", "paying": "pay", "paid": "pay",
    "receives": "receive", "receiving": "receive", "received": "receive",
    "sets": "set", "setting": "set",
    "takes": "take", "taking": "take", "took": "take", "taken": "take",
    "understands": "understand", "understanding": "understand", "understood": "understand",
    "writes": "write", "writing": "write", "wrote": "write", "written": "write",
    "is": "be", "are": "be", "was": "be", "were": "be", "been": "be", "being": "be",
    "has": "have", "had": "have", "having": "have",
    "shall": "shall", "will": "will", "would": "would", "could": "could",
    "should": "should", "may": "may", "might": "might", "must": "must",
    "waived": "waive", "waives": "waive", "waiving": "waive",
    "indemnified": "indemnify", "indemnifies": "indemnify", "indemnifying": "indemnify",
    "assigned": "assign", "assigns": "assign", "assigning": "assign",
    "deemed": "deem", "deems": "deem", "deeming": "deem",
    "incurred": "incur", "incurs": "incur", "incurring": "incur",
    "reimbursed": "reimburse", "reimburses": "reimburse",
    "exceeded": "exceed", "exceeds": "exceed",
    "terminated": "terminate", "terminates": "terminate",
    "amended": "amend", "amends": "amend",
    "survived": "survive", "survives": "survive",
    "notified": "notify", "notifies": "notify",
    "expired": "expire", "expires": "expire",
    "executed": "execute", "executes": "execute",
    "disclosed": "disclose", "discloses": "disclose",
    "breached": "breach", "breaches": "breach",
    "valued": "value", "values": "value",
    "renewed": "renew", "renews": "renew",
}

STOP = set("""a an the and or but if then than that this these those of to in on at by for with from as is are was were be been being
it its he she they them their we our you your i me my his her not no nor so such any all each both few more most other some own same
shall will would could should may might must can do does did done have has had having also such thereof herein hereby hereunder hereof
thereto therein thereto thereafter whereas provided subject notwithstanding pursuant heretofore hitherto wheresoever whatsoever and/or
per cent sixty thirty twenty twelve fifteen five three one two four six seven eight nine ten hundred thousand million billion""".split())

WORD_RE = re.compile(r"[A-Za-z][A-Za-z'\-\u2019]*")


def lemmatize(w: str) -> str:
    lw = w.lower().strip("'")
    if not lw:
        return lw
    if lw in IRREGULAR:
        return IRREGULAR[lw]
    if len(lw) > 4 and lw.endswith("ies"):
        return lw[:-3] + "y"
    if len(lw) > 4 and (lw.endswith("sses") or lw.endswith("shes") or lw.endswith("ches") or lw.endswith("xes")):
        return lw[:-2]
    if len(lw) > 3 and lw.endswith("s") and not lw.endswith("ss") and not lw.endswith("us") and not lw.endswith("is"):
        return lw[:-1]
    if len(lw) > 4 and lw.endswith("ed"):
        stem = lw[:-2]
        if len(stem) > 2 and stem[-1] == stem[-2]:
            stem = stem[:-1]
        return stem
    if len(lw) > 5 and lw.endswith("ing"):
        stem = lw[:-3]
        if len(stem) > 2 and stem[-1] == stem[-2]:
            stem = stem[:-1]
        return stem
    return lw


def tokenize_en(text):
    out = []
    for m in WORD_RE.finditer(text):
        s = m.group(0)
        lemma = lemmatize(s)
        if lemma in STOP or len(lemma) < 2:
            continue
        out.append({"s": s, "l": lemma, "r": "", "p": ""})
    return out


# ---------------------------------------------------------------- 日语

_sudachi = None
_sudachi_err = ""


def get_sudachi():
    global _sudachi, _sudachi_err
    if _sudachi is not None or _sudachi_err:
        return _sudachi
    try:
        from sudachipy import dictionary, tokenizer  # noqa
        try:
            d = dictionary.Dictionary(dict="core")
        except Exception:
            d = dictionary.Dictionary()
        _sudachi = (d.create(), tokenizer.Tokenizer.SplitMode.C)
    except Exception as e:  # noqa
        _sudachi_err = "%s: %s" % (type(e).__name__, e)
        _sudachi = None
    return _sudachi


POS_MAP = {
    "名詞": "名詞", "動詞": "動詞", "形容詞": "形容詞", "形状詞": "形容動詞",
    "副詞": "副詞", "助詞": "助詞", "助動詞": "助動詞", "接続詞": "接続詞",
    "連体詞": "連体詞", "感動詞": "感動詞", "接頭辞": "接頭辞", "接尾辞": "接尾辞",
}
KEEP_POS = {"名詞", "動詞", "形容詞", "形状詞", "副詞"}


def tokenize_ja(text):
    sd = get_sudachi()
    if sd is None:
        return None, _sudachi_err or "sudachipy 未安装"
    tk, mode = sd
    out = []
    for m in tk.tokenize(text, mode):
        pos = m.part_of_speech()[0]
        if pos not in KEEP_POS:
            continue
        lemma = m.dictionary_form()
        if not lemma or lemma in (" ", "\n", "\t"):
            continue
        # 过滤纯数字/纯符号：Sudachi 把 "1929"、"1" 也标成名词，进了词典纯属噪音
        if not re.search(r"[^\W\d_]", lemma, re.UNICODE):
            continue
        out.append({
            "s": m.surface(),
            "l": lemma,
            "r": m.reading_form(),
            "p": POS_MAP.get(pos, pos),
        })
    return out, ""


def tokenize_plain(text):
    out = []
    for w in re.split(r"\s+", text):
        w = w.strip()
        if not w:
            continue
        out.append({"s": w, "l": w.lower(), "r": "", "p": ""})
    return out


def tokenize_one(lang, text):
    """返回 (tokens, engine, error)"""
    if not (text or "").strip():
        return [], "none", ""
    if lang.startswith("ja"):
        toks, err = tokenize_ja(text)
        if toks is None:
            return None, "", err
        return toks, "sudachi", ""
    if lang.startswith("en"):
        return tokenize_en(text), "rule", ""
    return tokenize_plain(text), "plain", ""


def main_batch():
    """--batch 模式：首行 = lang，其后每行 = 一段文本的 JSON 字符串
       （必须 JSON 编码，因为段落内部可能自带换行）"""
    raw = sys.stdin.read()
    lines = raw.split("\n")
    lang = (lines[0] if lines else "").strip()
    texts = []
    for ln in lines[1:]:
        if ln == "":
            continue
        try:
            texts.append(json.loads(ln))
        except Exception:
            texts.append(ln)
    results = []
    engine = "?"
    for t in texts:
        toks, eng, err = tokenize_one(lang, t)
        if toks is None:
            print(json.dumps({"ok": False, "error": "日語分詞引擎不可用：" + err,
                              "hint": "pip install sudachipy sudachidict_core"}, ensure_ascii=False))
            return
        engine = eng
        results.append({"tokens": toks})
    print(json.dumps({"ok": True, "lang": lang, "engine": engine, "results": results}, ensure_ascii=False))


def main():
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw)
    except Exception as e:
        print(json.dumps({"ok": False, "error": "输入不是 JSON: %s" % e}, ensure_ascii=False))
        return
    lang = str(payload.get("lang") or "")
    text = str(payload.get("text") or "")
    toks, engine, err = tokenize_one(lang, text)
    if toks is None:
        print(json.dumps({"ok": False, "error": "日語分詞引擎不可用：" + err,
                          "hint": "pip install sudachipy sudachidict_core"}, ensure_ascii=False))
        return
    print(json.dumps({"ok": True, "lang": lang, "engine": engine, "tokens": toks}, ensure_ascii=False))


if __name__ == "__main__":
    try:
        if "--batch" in sys.argv:
            main_batch()
        else:
            main()
    except Exception as e:  # noqa
        print(json.dumps({"ok": False, "error": "%s: %s" % (type(e).__name__, e)}, ensure_ascii=False))

