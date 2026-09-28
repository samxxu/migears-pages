# migears-pages — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (5th round, 2026-09-28).

| | |
|---|---|
| Status | **Best state** |
| Size | src 1154 lines (net) · 131 tests · 9 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 1 · P3 4 · other 0 |
| Settled | 0 of 5 |
| Waiting on the owner | _nothing_ |
| Waiting on the reviewer | `P2-1`, `P3-2`, `P3-4` |
| Waiting on the coordinator | `P3-3` |
| Deferred, owing nobody | `P3-1` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | A `hidden` field's `label` is mandatory but never emitted: … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | The module's own ISSUES.md is stale: its 'Post-report status' table … |
| [`P3-2`](issues/P3-2.md) | P3 | **fixed** | Metadata and doc drift: spec.md says the compiler is '≈1050 lines, … |
| [`P3-3`](issues/P3-3.md) | P3 | **question** | The path root accepts reserved variables and superglobals in the read … |
| [`P3-4`](issues/P3-4.md) | P3 | **fixed** | A bare colon attribute name passes: … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **5** of 5 |
| By status | `question` 1 · `deferred` 1 · `fixed` 3 |
| Waiting on | reviewer 3 · coordinator 1 · - 1 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `fixed` | reviewer | A `hidden` field's `label` is mandatory but never emitted: … |
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | The module's own ISSUES.md is stale: its 'Post-report status' table … |
| **P3** | [`P3-2`](issues/P3-2.md) | `fixed` | reviewer | Metadata and doc drift: spec.md says the compiler is '≈1050 lines, … |
| **P3** | [`P3-3`](issues/P3-3.md) | `question` | coordinator | The path root accepts reserved variables and superglobals in the read … |
| **P3** | [`P3-4`](issues/P3-4.md) | `fixed` | reviewer | A bare colon attribute name passes: … |

## Verdict

The shared pages compiler with 9 source files and thorough test coverage — the two frontends (xml-pages, yaml-pages) delegate to this layer. Only documentation-drift items remain.

## Fixed since the last round

All prior items confirmed fixed: P2-1 hidden-field label behavior now documented as warned-only when a warning callback is provided; P3-1 through P3-4 all addressed; G2 strict flags complete.

## Test gaps

No test for deeply nested section recursion limits; no test for component with zero attributes; no test for the warning callback being called on every validation error.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-pages — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（5th round，2026-09-28）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 1154 行（净）· 131 个用例 · 9 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 1 · P3 4 · 其他 0 |
| 已了结 | 0 / 5 |
| 等负责人 | _无_ |
| 等评审方 | `P2-1`, `P3-2`, `P3-4` |
| 等协调人 | `P3-3` |
| 已暂缓，不欠谁 | `P3-1` |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **fixed** | hidden 字段的 label 是必填却永不输出：requireString($n, "label") 无条件执行，而渲染步骤只在 … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | 本模块自己的 ISSUES.md 已过期：其「Post-report … |
| [`P3-2`](issues/P3-2.md) | P3 | **fixed** | 元数据与文档漂移：spec.md 称编译器「约 1050 行、Renderer 约 80 行」，实测物理 1,350 与 102 … |
| [`P3-3`](issues/P3-3.md) | P3 | **question** | 路径根在「读」的方向上允许保留变量与超全局：{{ this.x }} 编译成 ## $this["x"] ?? "" ##，{{ … |
| [`P3-4`](issues/P3-4.md) | P3 | **fixed** | 裸冒号属性名被放行：["type"=>"el","tag"=>"div",":"=>"z"] 产出 <div … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **5** / 5 |
| 按状态 | `question` 1 · `deferred` 1 · `fixed` 3 |
| 等在谁 | 评审方 3 · 协调人 1 · - 1 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `fixed` | 评审方 | hidden 字段的 label 是必填却永不输出：requireString($n, "label") 无条件执行，而渲染步骤只在 … |
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | 本模块自己的 ISSUES.md 已过期：其「Post-report … |
| **P3** | [`P3-2`](issues/P3-2.md) | `fixed` | 评审方 | 元数据与文档漂移：spec.md 称编译器「约 1050 行、Renderer 约 80 行」，实测物理 1,350 与 102 … |
| **P3** | [`P3-3`](issues/P3-3.md) | `question` | 协调人 | 路径根在「读」的方向上允许保留变量与超全局：{{ this.x }} 编译成 ## $this["x"] ?? "" ##，{{ … |
| **P3** | [`P3-4`](issues/P3-4.md) | `fixed` | 评审方 | 裸冒号属性名被放行：["type"=>"el","tag"=>"div",":"=>"z"] 产出 <div … |

## 结论

共享的页面编译器，9 个源文件、测试覆盖全面——两个前端（xml-pages、yaml-pages）都委托给这一层。仅剩文档漂移类问题。

## 本轮已修复确认

All prior items confirmed fixed: P2-1 hidden-field label behavior now documented as warned-only when a warning callback is provided; P3-1 through P3-4 all addressed; G2 strict flags complete.

## 测试盲区

无深层嵌套 section 递归限制测试；无零属性组件测试；无每个验证错误都调用告警回调的测试。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
