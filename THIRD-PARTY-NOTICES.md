# 第三方组件与许可 · Third-Party Notices

本仓库包含或依赖以下第三方组件。**它们各自的许可优先于本仓库的 MIT 许可。**

> 说明：本文件覆盖**随仓库分发**的代码与资源。
> 通过包管理器（composer / npm / pip）安装的依赖**不随本仓库分发**，
> 实际使用时请以各项目的官方声明为准。

---

## 一、随仓库分发的前端库（`frontend/public/`）

| 组件 | 许可 | 备注 |
|---|---|---|
| [pdf.js](https://github.com/mozilla/pdf.js)（`pdf.min.js` / `pdf.worker.min.js`）<br>© 2023 Mozilla Foundation | **Apache-2.0** | ⚠️ Apache-2.0 要求保留其许可声明。文件头部的 `@licstart … @licend` 版权块**不得删除或修改**。 |
| [epub.js](https://github.com/futurepress/epub.js)（`epub.min.js`）<br>© 2013 FuturePress | **BSD-2-Clause** | |
| [JSZip](https://stuk.github.io/jszip/)（`jszip.min.js`）v3.10.1 | **MIT** 或 GPLv3（双许可，本项目按 **MIT** 使用） | 内含 [pako](https://github.com/nodeca/pako)（MIT） |

---

## 二、后端依赖（composer · 不随仓库分发）

| 包 | 许可 |
|---|---|
| `slim/slim`、`slim/psr7` | MIT |
| `illuminate/database` 及其子包 | MIT |
| `nesbot/carbon`、`symfony/*`、`psr/*` | MIT |
| **`vlucas/phpdotenv`** | **BSD-3-Clause** |
| **`nikic/fast-route`** | **BSD-3-Clause** |
| **`phpoption/phpoption`** | **Apache-2.0** |

其余传递依赖均为 MIT。完整清单见 `backend/composer.lock`。

---

## 三、前端依赖（npm · 不随仓库分发）

| 包 | 许可 |
|---|---|
| `vue` | MIT |
| `vite` | MIT |
| `@vitejs/plugin-vue` | MIT |

---

## 四、Python 侧（可选运行时依赖 · 不随仓库分发）

| 组件 | 许可 | 用途 |
|---|---|---|
| [faster-whisper](https://github.com/SYSTRAN/faster-whisper) | MIT | 本地语音识别 / 发音诊断 |
| [CTranslate2](https://github.com/OpenNMT/CTranslate2) | MIT | 推理后端 |
| [edge-tts](https://github.com/rany2/edge-tts) | **LGPLv3** | 在线语音合成（**以独立子进程调用，不链接进本项目代码**） |
| [rembg](https://github.com/danielgatis/rembg) | MIT | 形象抠图 |
| [onnxruntime](https://github.com/microsoft/onnxruntime) | MIT | rembg 推理后端 |
| [PyAV](https://github.com/PyAV-Org/PyAV) | BSD-3-Clause | 音频解码（回退路径） |
| **FFmpeg** | **LGPL 或 GPL（取决于构建选项）** | 音频转码（**外部可执行文件**，使用者自行安装） |

---

## 五、模型权重

- **whisper-small**（OpenAI）—— 权重以 **MIT** 发布。
- 本项目使用的 **CTranslate2 转换版**（`whisper-small-ct2`）**不随仓库分发**，README 提供获取方式。

---

## 六、Apache-2.0 的 NOTICE 要求（重要）

Apache-2.0 第 4(d) 条规定：若原作品附带 NOTICE 文件，再分发时须保留其中的归属声明。

pdf.js 的许可声明**已内嵌在 `pdf.min.js` / `pdf.worker.min.js` 的文件头部**
（`@licstart … @licend` 区块）。

> **请勿删除或修改该区块。** 若你构建时重新压缩这些文件，务必保留该注释。
