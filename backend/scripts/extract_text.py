# -*- coding: utf-8 -*-
"""
langlab 文件文本抽取器（服务端，全部走 Python 标准库；PDF 用 pypdf）

用法：
    python extract_text.py --file "D:/.../book.epub" --type epub
输出：
    {"ok":true,"type":"epub","title":"...","author":"...",
     "parts":[{"title":"第一章","text":"..."}],"text":"全文","chars":12345}

支持：epub / pdf / docx / txt / md / html / htm / srt / vtt
所有异常都返回 {"ok":false,"error":"..."}，由 PHP 侧降级处理。
"""

import argparse
import html as html_mod
import json
import logging
import os
import re
import sys
import warnings
import zipfile

# pypdf 等库会把告警打到 stdout，会污染我们的 JSON 输出 —— 全部压到 stderr
try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass
logging.basicConfig(stream=sys.stderr, level=logging.ERROR)
for _n in ("pypdf", "pypdf._reader", "pypdf.generic", "PIL"):
    logging.getLogger(_n).setLevel(logging.CRITICAL)
warnings.filterwarnings("ignore")

# ---------------------------------------------------------------- 通用

TAG = re.compile(r"<[^>\"]*(?:\"[^\"]*\"[^>\"]*)*>", re.S)
DROP_BLOCK = re.compile(r"<(script|style|head|nav)[^>]*>.*?</\1>", re.S | re.I)
BLOCK_END = re.compile(r"</(p|div|h[1-6]|li|tr|section|article|blockquote)>", re.I)
BR = re.compile(r"<br\s*/?>", re.I)


def strip_html(raw: str) -> str:
    raw = DROP_BLOCK.sub(" ", raw)
    raw = BLOCK_END.sub("\n\n", raw)
    raw = BR.sub("\n", raw)
    raw = TAG.sub("", raw)
    raw = html_mod.unescape(raw)
    raw = raw.replace("\u00a0", " ").replace("\u3000", " ")
    raw = re.sub(r"[ \t]+", " ", raw)
    raw = re.sub(r"\n{3,}", "\n\n", raw)
    return raw.strip()


def read_text_file(path: str) -> str:
    for enc in ("utf-8-sig", "utf-8", "gb18030", "shift_jis", "cp1252"):
        try:
            with open(path, "r", encoding=enc) as f:
                return f.read()
        except (UnicodeDecodeError, LookupError):
            continue
    with open(path, "r", encoding="utf-8", errors="replace") as f:
        return f.read()


# ---------------------------------------------------------------- EPUB

def ext_epub(path):
    parts = []
    title = ""
    author = ""
    with zipfile.ZipFile(path) as z:
        names = z.namelist()

        def read(n):
            try:
                return z.read(n).decode("utf-8", "replace")
            except KeyError:
                return ""

        # container.xml → OPF 路径
        opf_path = ""
        container = read("META-INF/container.xml")
        m = re.search(r'full-path="([^"]+)"', container)
        if m:
            opf_path = m.group(1)
        if not opf_path:
            for n in names:
                if n.lower().endswith(".opf"):
                    opf_path = n
                    break
        opf = read(opf_path)
        base = os.path.dirname(opf_path)

        def join(p):
            p = p.split("#")[0]
            p = p.replace("\\", "/")
            if base and not p.startswith(base):
                return (base + "/" + p).lstrip("/")
            return p.lstrip("/")

        # 元数据
        mt = re.search(r"<dc:title[^>]*>(.*?)</dc:title>", opf, re.S | re.I)
        if mt:
            title = strip_html(mt.group(1))
        ma = re.search(r"<dc:creator[^>]*>(.*?)</dc:creator>", opf, re.S | re.I)
        if ma:
            author = strip_html(ma.group(1))

        # manifest: id → href
        manifest = {}
        for mm in re.finditer(r"<item\b([^>]*)/?>", opf, re.I):
            attrs = mm.group(1)
            iid = re.search(r'id="([^"]+)"', attrs)
            href = re.search(r'href="([^"]+)"', attrs)
            if iid and href:
                manifest[iid.group(1)] = join(href.group(1))

        # spine 顺序 → 章节
        spine = re.findall(r"<itemref\b[^>]*idref=\"([^\"]+)\"", opf, re.I)
        if not spine:
            spine = [i for i in manifest if manifest[i].lower().endswith((".xhtml", ".html", ".htm"))]

        # 目录标题（可选）：从 nav / ncx 里取
        toc_names = {}
        for iid, href in manifest.items():
            if href.lower().endswith((".ncx",)):
                ncx = read(href)
                for nm in re.finditer(r"<text>(.*?)</text>.*?<content[^>]*src=\"([^\"]+)\"", ncx, re.S | re.I):
                    toc_names[join(nm.group(2))] = strip_html(nm.group(1))
            if "nav" in iid.lower() or href.lower().endswith("nav.xhtml"):
                nav = read(href)
                for nm in re.finditer(r'<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>', nav, re.S | re.I):
                    toc_names.setdefault(join(nm.group(1)), strip_html(nm.group(2)))

        for iid in spine:
            href = manifest.get(iid)
            if not href:
                continue
            body = read(href)
            if not body:
                continue
            txt = strip_html(body)
            if len(txt) < 2:
                continue
            parts.append({"title": toc_names.get(href, ""), "text": txt})

    if not parts:
        return None, "EPUB 里没抽到正文（可能是纯图片版）"
    return {"title": title, "author": author, "parts": parts}, ""


# ---------------------------------------------------------------- DOCX

def ext_docx(path):
    title = os.path.splitext(os.path.basename(path))[0]
    with zipfile.ZipFile(path) as z:
        names = z.namelist()
        xml = ""
        for cand in ("word/document.xml",):
            if cand in names:
                xml = z.read(cand).decode("utf-8", "replace")
                break
        if not xml:
            return None, "DOCX 里找不到 word/document.xml"
        # 核心属性里的标题
        if "docProps/core.xml" in names:
            core = z.read("docProps/core.xml").decode("utf-8", "replace")
            mt = re.search(r"<dc:title[^>]*>(.*?)</dc:title>", core, re.S | re.I)
            if mt and strip_html(mt.group(1)):
                title = strip_html(mt.group(1))

    # 每个 <w:p> 是一段；制表/换行转成对应字符
    xml = re.sub(r"<w:tab[^>]*/>", "\t", xml)
    xml = re.sub(r"<w:br[^>]*/>", "\n", xml)
    paras = []
    for pm in re.finditer(r"<w:p\b[^>]*>(.*?)</w:p>", xml, re.S):
        seg = pm.group(1)
        texts = re.findall(r"<w:t[^>]*>(.*?)</w:t>", seg, re.S)
        line = html_mod.unescape("".join(texts)).strip()
        paras.append(line)
    body = "\n\n".join([p for p in paras if p])
    if not body:
        return None, "DOCX 里没抽到文字"
    return {"title": title, "author": "", "parts": [{"title": "", "text": body}]}, ""


# ---------------------------------------------------------------- PDF

def ext_pdf(path):
    try:
        from pypdf import PdfReader
    except Exception as e:  # noqa
        return None, "pypdf 不可用：%s（pip install pypdf）" % e
    try:
        reader = PdfReader(path)
    except Exception as e:  # noqa
        return None, "PDF 打开失败：%s" % e

    title = ""
    author = ""
    try:
        meta = reader.metadata or {}
        title = (meta.get("/Title") or "").strip()
        author = (meta.get("/Author") or "").strip()
    except Exception:
        pass

    parts = []
    total = 0
    for i, page in enumerate(reader.pages):
        try:
            t = page.extract_text() or ""
        except Exception:
            t = ""
        t = t.replace("\u00a0", " ")
        t = re.sub(r"[ \t]+", " ", t)
        t = re.sub(r"\n{3,}", "\n\n", t).strip()
        total += len(t)
        parts.append({"title": "第 %d 页" % (i + 1), "text": t})

    if total < 10:
        return None, "PDF 里没抽到文字（可能是扫描件/图片型 PDF，需要 OCR）"
    if not title:
        title = os.path.splitext(os.path.basename(path))[0]
    return {"title": title, "author": author, "parts": parts}, ""


# ---------------------------------------------------------------- 字幕 / 纯文本

TS = re.compile(r"^\d+\s*$|^\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}\s*-->.*$|^WEBVTT.*$|^\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}$")
# 行首的说话人标记，如 (半沢) / （半沢） / [半沢]；限短且不含空格，避免误伤正常的括号内容
SPEAKER = re.compile(r"^\s*[（(\[【]\s*[^)）\]】\s]{1,10}\s*[)）\]】]\s*")
# 纯装饰行（音乐符号、箭头、分隔线等）
DECOR = re.compile(r"^[\s♪♫≪≫→←\-—=~＊*#…　・|]+$")


def ext_subtitle(path):
    raw = read_text_file(path)
    lines = []
    for ln in raw.splitlines():
        s = ln.strip().lstrip("\ufeff")
        if not s or TS.match(s):
            continue
        s = re.sub(r"<[^>]+>", "", s)          # srt 里的 <i> 等
        s = s.replace("≪", "").replace("≫", "")  # 某些日文字幕的强调符号
        s = SPEAKER.sub("", s)                  # 去掉行首说话人标记
        s = re.sub(r"[ \t\u00a0]+", " ", s).strip()
        if not s or DECOR.match(s):
            continue
        # 相邻重复行（字幕常见）
        if lines and lines[-1] == s:
            continue
        lines.append(s)

    # 不合并！字幕的换行往往就是换人说话 —— 合并会把不同角色的台词粘成一句。
    # 一条字幕 = 一段，短句正好适合跟读和逐句批注。
    # 用空行分隔 → 下游 segmentize 才会「一句一段」（只换行的话会被揉成 400 字大块）。
    text = "\n\n".join(lines)
    return {"title": os.path.splitext(os.path.basename(path))[0], "author": "",
            "parts": [{"title": "", "text": text}]}, ""


# 注意：**不要**把 `- ` 开头当 markdown 列表 —— 台词本里的对白破折号就是 `- Where is he?`。
# 只挑表格、标题、引用、代码块、有序列表这些「文档味」明确的信号。
MD_STRUCT = re.compile(r"^\s*(\||#{1,6}\s|>\s|```|\d+\.\s)")
# markdown 内联标记（粗体/行内代码/链接）—— 台词本里不会有
MD_INLINE = re.compile(r"\*\*|`|\]\(")


def looks_like_script(lines):
    """粗判是不是台词本/逐字稿。

    只看「行短」是不够的 —— 手写手册里的清单、表格行同样很短（实测合同手册短行占比 0.87，
    会被直接误判）。所以先排除 markdown 结构，再看行长分布。
    台词本的特征：没有表格/标题/内联标记、行数多、行长集中在几十字符、几乎每行都有空格。

    判错的代价是「切段方式悄悄变了」，所以宁可保守；真要指定请用导入时的 type=script。
    """
    body = [l.strip() for l in lines if l.strip()]
    if len(body) < 20:
        return False
    # 1) 表格/标题/引用这些结构一多，就是文档不是台词
    if sum(1 for l in body if MD_STRUCT.match(l)) / len(body) > 0.02:
        return False
    # 2) 表格分隔行（|---|）直接排除
    if any(re.match(r"^\|?[\s:-]*-{3,}[\s:|-]*$", l) for l in body[:200]):
        return False
    # 3) markdown 内联标记密度
    if sum(1 for l in body if MD_INLINE.search(l)) / len(body) > 0.08:
        return False
    # 4) 行长分布：绝大多数短行，且中位数也小
    lens = sorted(len(l) for l in body)
    if sum(1 for l in body if len(l) <= 120) / len(body) < 0.8:
        return False
    if lens[len(lens) // 2] > 90:
        return False
    # 5) 台词基本是多词的句子
    return sum(1 for l in body if " " in l) / len(body) >= 0.5


def ext_plain(path):
    raw = read_text_file(path)
    if path.lower().endswith((".html", ".htm")):
        raw = strip_html(raw)
    lines = raw.splitlines()
    if looks_like_script(lines):
        # 按台词本处理：每个非空行独立成段
        # detected 是给 PHP 侧的提示：这份 .txt/.md 其实是台词本，
        # 让 DB 里的 type 标成 script（否则一律显示「纯文本」，和显式指定 type 的同类素材对不上）
        return {"title": os.path.splitext(os.path.basename(path))[0], "author": "", "detected": "script",
                "parts": [{"title": "", "text": "\n\n".join(l.strip() for l in lines if l.strip())}]}, ""
    return {"title": os.path.splitext(os.path.basename(path))[0], "author": "",
            "parts": [{"title": "", "text": raw.strip()}]}, ""


def ext_script(path):
    """台词本 / 逐字稿：**每个非空行 = 一句台词**，行间插空行。
       这样下游 segmentize 会按空行切，一整集就是几百条独立台词，
       而不是被硬揉成几个 400 字的大块 —— 阅读、批注、跟读都按「一句」来才自然。"""
    raw = read_text_file(path)
    lines = []
    for ln in raw.splitlines():
        s = ln.strip()
        if not s:
            continue
        # 偶尔会有 "SPEAKER:" 独立成行，保留原样；只做空白归一
        s = re.sub(r"[ \t\u00a0]+", " ", s)
        lines.append(s)
    return {"title": os.path.splitext(os.path.basename(path))[0], "author": "",
            "parts": [{"title": "", "text": "\n\n".join(lines)}]}, ""


# ---------------------------------------------------------------- main

def detect(path):
    ext = os.path.splitext(path)[1].lower().lstrip(".")
    return ext


def run(path, typ):
    typ = (typ or detect(path)).lower()
    if typ == "epub":
        return ext_epub(path)
    if typ == "docx":
        return ext_docx(path)
    if typ == "pdf":
        return ext_pdf(path)
    if typ in ("srt", "vtt", "ass", "sub"):
        return ext_subtitle(path)
    if typ in ("script", "dialogue"):
        return ext_script(path)
    if typ in ("txt", "md", "markdown", "html", "htm", "json", "csv"):
        return ext_plain(path)
    return None, "暂不支持的类型：.%s" % typ


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--file", required=True)
    ap.add_argument("--type", default="")
    a = ap.parse_args()

    if not os.path.isfile(a.file):
        print(json.dumps({"ok": False, "error": "文件不存在：%s" % a.file}, ensure_ascii=False))
        return
    try:
        res, err = run(a.file, a.type)
    except Exception as e:  # noqa
        print(json.dumps({"ok": False, "error": "%s: %s" % (type(e).__name__, e)}, ensure_ascii=False))
        return
    if res is None:
        print(json.dumps({"ok": False, "error": err, "type": (a.type or detect(a.file))}, ensure_ascii=False))
        return

    full = "\n\n".join((p["title"] + "\n" + p["text"]) if p["title"] else p["text"] for p in res["parts"])
    # 标题优先用文件名：电子书/合同的内嵌元数据经常是过期或没填的
    # （实测 某合同 V7.0 的 docx 元数据里还写着 V6.2）
    fname = os.path.splitext(os.path.basename(a.file))[0]
    meta_title = res.get("title") or ""
    out = {
        "ok": True,
        "type": a.type or detect(a.file),
        # 抽取器自己认出来的真实类型（目前只有 script 一种），供 PHP 侧决定入库 type
        "detected": res.get("detected") or "",
        "title": fname or meta_title,
        "meta_title": meta_title,
        "author": res.get("author") or "",
        "parts": res["parts"],
        "text": full,
        "chars": len(full),
        "part_count": len(res["parts"]),
    }
    print(json.dumps(out, ensure_ascii=False), flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception as e:  # noqa
        print(json.dumps({"ok": False, "error": "%s: %s" % (type(e).__name__, e)}, ensure_ascii=False))
