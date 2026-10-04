# langlab

**English** | [中文](README.zh-CN.md) | [日本語](README.ja.md)

> **A local-first, multi-language learning system** — learn a language from your own real material.
> Cards · spaced repetition · word-pick card creation · built-in reader · AI tutor · local pronunciation coaching

![Card learner](docs/screenshots/cards.png)

**It doesn't teach you a language. It trains you on your own material.**

Mainstream language apps teach *their* curriculum. langlab trains you on *yours* — work
contracts, industry memos, foreign series, ebooks. Select a word to make a card, cards get
scheduled automatically for review, and a virtual tutor listens to you read and points out
where you stumbled.

**All data stays on your own machine: nothing uploaded, no subscription, no lock-in.**

---

## Author

**Aloysius Luo** — full-stack AI development and server operations.

- GitHub: [@tttworks](https://github.com/tttworks)
- Contact: a@tttworks.com
- Issues and PRs welcome (please read [CLA.md](CLA.md) before contributing)

---

## Why this project exists

A change in my work meant I had to bring my English up to the level of *reading professional
documents and sitting in negotiations* — within six months.

I did it the old way and memorised a few thousand words. Then came the frustrating part:
**in the meeting room, none of them would come when called.** Later I recorded myself speaking
and listened back — **nearly half of the recording was silence**; and the words I misspelled
were almost all *guessed from pronunciation*. What I had stored was sound, not spelling.

That's when it clicked: **my bottleneck wasn't "not knowing" — it was "not being able to
retrieve".**

And every tool on the market was solving the first problem. They teach you to order coffee
and talk about the weather; the situations I actually needed weren't covered at all. More to
the point, **I wasn't short of material** — I had plenty of real English documents from my own
field. What I lacked was a system that turns that material into training — and because the
material is confidential, it had to run on my own machine.

So langlab exists. Every design decision in it **traces back to one of those specific
sticking points**.

📖 **[The full story: why langlab exists](docs/STORY.md)**

---

## Who it's for

- Professionals with a large body of real foreign-language material to *absorb*
  (legal / investment / medical / engineering …)
- People who care about data sovereignty and won't accept a cloud account
- Learners who already "know a bit" but **can't retrieve it or speak it fluently**

**It is not for** people starting a language from zero — build a foundation with Duolingo or
Babbel first.

---

## Theoretical grounding

Every feature maps to a specific published finding. **Every citation can be looked up.**

| # | Feature | Theory | Author | Source |
|---|---|---|---|---|
| 1 | Material comes only from your own real texts | Comprehensible Input Hypothesis (i+1) | **Stephen Krashen**<br>Professor Emeritus, USC | Krashen, S. D. (1985). *The Input Hypothesis: Issues and Implications*. Longman. |
| 2 | Spaced-repetition scheduling for cards | Forgetting curve & spaced repetition | **Hermann Ebbinghaus**<br>**Piotr Woźniak** | Ebbinghaus, H. (1885). *Über das Gedächtnis*.<br>Woźniak, P. A., & Gorzelańczyk, E. J. (1994). Optimization of Repetition Spacing in the Practice of Learning. *Acta Neurobiologiae Experimentalis*, 54(1), 59–62. |
| 3 | Review log recorded (preparing for FSRS) | Spaced-repetition scheduling optimisation | **Ye Junyao / Su Jingyong / Cao Yilong** | Ye, J., Su, J., & Cao, Y. (2022). A Stochastic Shortest Path Algorithm for Optimizing Spaced Repetition Scheduling. *KDD '22*, 4381–4390. |
| 4 | Production mode (Chinese → say it in English) | Testing effect / retrieval practice | **Henry L. Roediger III**<br>**Jeffrey D. Karpicke** | Roediger, H. L., & Karpicke, J. D. (2006). Test-Enhanced Learning: Taking Memory Tests Improves Long-Term Retention. *Psychological Science*, 17(3), 249–255. |
| 5 | Framework retelling + recorded self-review | Output Hypothesis | **Merrill Swain**<br>University of Toronto | Swain, M. (1995). Three Functions of Output in Second Language Learning. In *Principle and Practice in Applied Linguistics*. Oxford University Press. |
| 6 | Phonetic transcription + syllable split per card | Dual Coding Theory | **Allan Paivio**<br>University of Western Ontario | Paivio, A. (1986). *Mental Representations: A Dual Coding Approach*. Oxford University Press. |
| 7 | Collocations + source sentence | Lexical Approach | **Michael Lewis**<br>**Paul Nation** | Lewis, M. (1993). *The Lexical Approach*. Language Teaching Publications.<br>Nation, I. S. P. (2001). *Learning Vocabulary in Another Language*. Cambridge University Press. |
| 8 | Determiner highlighting / staged release | Cognitive Load Theory | **John Sweller**<br>UNSW Sydney | Sweller, J. (1988). Cognitive Load During Problem Solving: Effects on Learning. *Cognitive Science*, 12(2), 257–285. |
| 9 | Immediate, targeted feedback in pronunciation coaching | Deliberate Practice | **K. Anders Ericsson** | Ericsson, K. A., Krampe, R. T., & Tesch-Römer, C. (1993). The Role of Deliberate Practice in the Acquisition of Expert Performance. *Psychological Review*, 100(3), 363–406. |
| 10 | One course = one domain | Narrow reading | **Stephen Krashen** | Krashen, S. (2004). The Case for Narrow Reading. *Language Magazine*, 3(5), 17–19. |
| 11 | Select a word → card, immediately | ⚠️ **not an academic theory** | — | Common practice in immersion-learning communities (AJATT / Antimoon) |

> ⚠️ **Item 11 is flagged deliberately**: sentence mining is **community practice**, not backed
> by a paper. Folding it into "theoretical grounding" would be dishonest — but it works, so it
> stays, clearly marked.

### How it differs from other approaches

|  | Course apps<br>(Duolingo et al.) | Flashcard tools<br>(Anki et al.) | **langlab** |
|---|---|---|---|
| Content source | The platform's generic curriculum | Cards you build yourself | **Your own real material** |
| Memory scheduling | Weak | Strongest | Yes (SM-2, FSRS data already recorded) |
| Production training | Limited | Depends on how you make cards | **Built-in production mode** |
| Speech feedback | Basic recognition | None | **Local pronunciation coaching** |
| Reading integration | None | None | **Built-in reader + word-pick cards** |
| Data ownership | Cloud account | Local | **Local** |
| Setup cost | Low (open and learn) | High (assemble your own toolchain) | Medium (bring your own material) |

**In one line each:**

- **Course apps** solve "I don't know what to learn" — they **give you content**;
- **Flashcard tools** solve "I need to memorise this" — but **you build the cards and find the material**;
- **langlab** solves "I have a pile of material to get through" — it chains **reading, card creation, review and pronunciation feedback** into one flow.

> ⚠️ To be clear: the above is **design rationale**, not a guarantee that using it will make you
> fluent. There is no silver bullet in language acquisition.

---

## Features

### Card learner
Six card types (word / term / chunk / sentence pattern / concept / annotation);
both receptive and productive modes; shuffle, random draw, read-aloud, mark-as-mastered.

![Card learner](docs/screenshots/cards.png)

### Built-in reader · word-pick card creation
EPUB / PDF rendered in their original layout, or an **intensive reading mode** rendered
sentence by sentence with a parallel translation. When you hit something you don't know:
**select it → one click makes a card**, automatically carrying its source and context.

![Creating a card by selecting text in the reader](docs/screenshots/reader_wordpick.png)

Material can be uploaded as files, registered by local path, pasted as text, or fetched from a URL:

![Material library](docs/screenshots/materials.png)

### Virtual tutor · local pronunciation coaching
Multi-role conversation (English / Japanese / Thai), **with the avatar switching per language**;
session history is saved automatically so you can pick up where you left off.
More importantly — **it actually listens to you read**: the recording is analysed locally with
Whisper, which points out where you mispronounced, slurred or stalled, then gives one targeted
piece of feedback. **The audio never leaves your machine.**

**Japanese · Aoi**

![Japanese tutor Aoi](docs/screenshots/teacher_aoi.png)

**Thai · Ploy**

![Thai tutor Ploy](docs/screenshots/teacher_ploy.png)

### Dashboard
Core metrics, stage-by-stage comparison, and a 182-day heatmap.

![Dashboard](docs/screenshots/dashboard.png)

### Dictionary and material library
General dictionary and domain-terminology dictionary (one word base, two views);
material supports file upload, local path registration, pasted text and URL fetching.

![Dictionary](docs/screenshots/dict.png)

---

## Quick start

**Requirements**: PHP 8.2+, Node 18+; (optional) Python 3.11+ for pronunciation coaching and speech synthesis.

```bash
# 1) Backend dependencies
cd backend && composer install

# 2) Configuration (every key is optional — it runs with none of them filled in)
cp data/.env.example data/.env

# 3) Database — these three steps must run in this order
php scripts/init.php         # create tables (⚠️ drops and recreates — only on an empty DB)
php scripts/migrate.php      # add columns introduced later (idempotent, safe to re-run)
php scripts/seed_demo.php    # optional: import demo cards to see it working

# 4) Frontend
cd ../frontend && npm install && npm run dev
```

Then open <http://localhost:5174>.

> On Windows you can also just double-click **`start.bat`** in the repository root
> (requires `php` and `npm` to be on PATH).

---

## Importing your own material

The repository **ships with no corpus** — you start with an empty database and fill it yourself.
There are four ways in, all on the **Material library** (`#/materials`) page:

### Option 1: Upload files (most common)
1. Open **Material library** → click "Upload files", or drag files onto the page
2. Supports **EPUB / PDF / DOCX / TXT / SRT / subtitle scripts**
3. Choose the **course** and **language**, then submit
4. Text is extracted, segmented and indexed automatically (large files are chunked, with visible progress)

### Option 2: Register a local path (no file copying)
Useful when you have many books and don't want two copies on disk.
1. Enter an **absolute path** (a file or a whole directory) under "Register local path"
2. Only the path is registered — **your original files are neither copied nor modified**
3. Directories are scanned recursively and grouped by course

### Option 3: Paste text
For fragments — a paragraph from an email, a news item — just paste it in.

### Option 4: Fetch from a URL
Enter a URL and the body text is extracted with tags stripped. If it fails, you'll be prompted to
use "Paste" instead (many sites block scraping).

### After importing
- **Read**: open from the material library → intensive mode renders sentence by sentence, with optional parallel translation and read-aloud
- **Make cards**: select any text → "Save and create card" in the popover, optionally letting AI fill in definitions and phonetics
- **Review**: Card learner → "Due today", scheduled by SM-2
- **Film & series**: importing `SRT + video` together creates a three-level browser: category → title·season → episode

> ⚠️ **Only import material you have the right to use.** Users are responsible for the legality
> of the content they import.

---

## Demo data

`backend/data/demo_cards.json` holds **31 hand-written** sample cards
(19 contract sentence skeletons + 12 legal concepts), so that anyone who clones the repo can see
it working immediately.

- **Derived from no real contract, client file or commercial document**; contains no
  institution names, amounts or commercial arrangements
- Licensed **CC0 (public domain)** — use it, change it, no strings
- Import with `php scripts/seed_demo.php` (idempotent, safe to re-run)
- After import the cards appear under the course "Contract English (sample)"

Want to swap in your own? Edit `demo_cards.json` directly, or load real material using the
methods above.

---

## What it doesn't do

- **No curriculum** — it doesn't provide content from zero to fluency; you bring the material
- **No cloud sync** — data lives on your machine; using it in several places is up to you
- **Collects nothing** — no telemetry, no account, no phone-home

Except for the AI conversation feature, **everything works offline** (cards, reading, review and
pronunciation coaching all run locally).

---

## License

| Subject | License |
|---|---|
| Code | [MIT](LICENSE) |
| Documentation | [CC BY 4.0](CONTENT-LICENSE.md) |
| Demo data | CC0 (public domain) |
| Character assets | With the code, MIT |

Third-party component licences are listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
Please read [CLA.md](CLA.md) before contributing code.
