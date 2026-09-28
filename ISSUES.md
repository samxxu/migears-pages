# migears-pages — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P2 open / P2 待修** |
| Size / 体量 | src 2,018 lines (1,140 net) · 151 tests · 9 src files · plus spec.md (1,116) and docs/h5-syntax.md (968) |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 0 · P2 1 · P3 4 · other 0 |
| Answered / 已回复 | 0 of 5 |
| Waiting / 等待回复 | `P2-1`, `P3-1`, `P3-2`, `P3-3`, `P3-4` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **open** | A `hidden` field's `label` is mandatory but never emitted: … |
| [`P3-1`](issues/P3-1.md) | P3 | **open** | The module's own ISSUES.md is stale: its 'Post-report status' table … |
| [`P3-2`](issues/P3-2.md) | P3 | **open** | Metadata and doc drift: spec.md says the compiler is '≈1050 lines, … |
| [`P3-3`](issues/P3-3.md) | P3 | **open** | The path root accepts reserved variables and superglobals in the read … |
| [`P3-4`](issues/P3-4.md) | P3 | **open** | A bare colon attribute name passes: … |

## Verdict / 结论

The three escaping/validation holes from last round are closed and each has a test that pins it. Two edges remain, and both are the same shape: something is written and then silently ignored.

上一轮三个转义与校验漏洞都堵上了，且各有测试钉住。剩两处边角，形状相同：写了东西，然后被静默忽略。

## Fixed since the last round / 本轮已修复确认

上一轮 P0（保留循环变量名）已修：新增 RESERVED_VARIABLES 与 loopVariable()，`as: this` 现在抛 CompileException 并有测试。P1-1（字面量属性不转义）已修：escapeAttrValue() 统一用于 link/form/field/option 的属性位。P2-1（透传属性名字符集）已修：ATTR_NAME_PATTERN 在 renderAttr() 统一校验，数组 DSL 与 YAML 都被覆盖。P2-2（cacheDir 含 NUL）已修。message-coverage 脚本已恢复且实跑输出「73 error sites, 73 with a matching assertion, 0 leads」。 

## Test gaps / 测试盲区

No assertion for a hidden field whose label is dropped; no case for a path root that is a superglobal or `this`; no case for a syntactically legal but meaningless attribute name. The previously listed gaps (reserved names, literal escaping, cacheDir NUL) are now covered, and message-coverage reports zero leads.

无「hidden 字段 label 被丢弃」断言；无「路径根为超全局或 this」用例；无「语法合法但语义空洞的属性名」用例。此前列的缺口（保留名、字面量转义、cacheDir NUL）均已补齐，message-coverage 报 0 leads。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
