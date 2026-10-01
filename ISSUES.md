# migears-pages — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 1161 lines (net) · 155 tests · 9 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 1 · other 0 |
| Settled | 4 of 5 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | _nothing_ |
| Waiting on the reviewer | _nothing_ |
| Deferred, owing nobody | `P3-1` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | A `hidden` field's `label` is mandatory but never emitted: … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | The module's own ISSUES.md is stale: its 'Post-report status' table … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | Metadata and doc drift: spec.md says the compiler is '≈1050 lines, … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | The path root accepts reserved variables and superglobals in the read … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | A bare colon attribute name passes: … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **1** of 5 |
| By status | `deferred` 1 |
| Waiting on | - 1 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | The module's own ISSUES.md is stale: its 'Post-report status' table … |

## Verdict

The two directions of the same contract now agree and share one list, which is the fix the ruling wanted; the shared layer is the only place the rule lives, so the front ends inherit it for free.

## Fixed since the last round

P3-3 verified by mutation: the read direction now refuses superglobal roots from the same RESERVED_VARIABLES list the write direction uses, and the identical message reaches all three front ends. Restoring the narrower list turns the module’s own test red.

## Test gaps

No gap inside this module; the cross-front-end parity that would catch a regression lives in xml-pages’ FrontEndParityTest, so a change that only this module inherits would not be caught by this module’s own suite.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-pages — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 1161 行（净）· 155 个用例 · 9 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 1 · 其他 0 |
| 已了结 | 4 / 5 |
| 等模块主 | _无_ |
| 等协调人 | _无_ |
| 等评审方 | _无_ |
| 已暂缓，不欠谁 | `P3-1` |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | hidden 字段的 label 是必填却永不输出：requireString($n, "label") 无条件执行，而渲染步骤只在 … |
| [`P3-1`](issues/P3-1.md) | P3 | **deferred** | 本模块自己的 ISSUES.md 已过期：其「Post-report … |
| [`P3-2`](issues/P3-2.md) | P3 | **verified** | 元数据与文档漂移：spec.md 称编译器「约 1050 行、Renderer 约 80 行」，实测物理 1,350 与 102 … |
| [`P3-3`](issues/P3-3.md) | P3 | **verified** | 路径根在「读」的方向上允许保留变量与超全局：{{ this.x }} 编译成 ## $this["x"] ?? "" ##，{{ … |
| [`P3-4`](issues/P3-4.md) | P3 | **verified** | 裸冒号属性名被放行：["type"=>"el","tag"=>"div",":"=>"z"] 产出 <div … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **1** / 5 |
| 按状态 | `deferred` 1 |
| 等在谁 | - 1 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `deferred` | - | 本模块自己的 ISSUES.md 已过期：其「Post-report … |

## 结论

同一契约的读、写两个方向现在共用一张名单、答案一致，正是裁定所要的修法；规则只活在共享层，因此各前端自动继承。

## 本轮已修复确认

P3-3 verified by mutation: the read direction now refuses superglobal roots from the same RESERVED_VARIABLES list the write direction uses, and the identical message reaches all three front ends. Restoring the narrower list turns the module’s own test red.

## 测试盲区

本模块内无盲区；能抓住回归的三前端对拍住在 xml-pages 的 FrontEndParityTest 里，因此只被本模块继承的改动不会被本模块自己的套件察觉。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
