---
goat-flow-reference-version: "1.13.0"
---
# Code Comments

Use this when writing or editing source code in any language, before naming an identifier or adding a comment, docstring, or annotation. The primary reader is the coding agent doing the work; the beneficiary is the human maintainer who later reads the code cold - often someone who knows the product well and the codebase not at all. The playbook owns doc comments (every function/method and class/file), context comments (every branch, loop, and null/empty check), the rationed extra inline comments, and the naming rule they depend on.

The register throughout is plain English from the UI/user perspective: names and comments translate code into what the user did, sees, or gets next - never restated mechanics. Portable across TypeScript, Python, Go, Rust, PHP, and shell: defer to each language's docstring SYNTAX (JSDoc, PEP 257, godoc, rustdoc, PHPDoc); this playbook owns the WHEN/WHY decision plus the house layout conventions (tag separator, blank line before tags, line width) that override language defaults.

## Availability Check

This is a discipline reference, not a runnable tool. Load it when:

- About to write a comment, docstring, or annotation in a source file.
- Naming or renaming a variable, method, or class.
- Editing existing code that contains comments - to decide keep / tighten / rewrite / delete.
- Authoring a TODO / FIXME / HACK marker.
- Reviewing a diff that adds or changes comments.

Enforcement is partial: static tools may flag mechanical items (missing doc comments, marker expiry) but not the `[judge]` semantic checks. The gate below is the spec; static tools own the mechanical slice, a reviewer or review-judge owns the rest. Do not claim more enforcement than the project runs.

## Intent

You are a coding agent, and a human who did not write this code has to read, review, and trust it. Write for a reader who thinks in screens, clicks, and outcomes: every comment should make sense to someone who knows the product but not the codebase. The hierarchy, strictest first: doc comments always, context comments always, any other inline comment only when the WHY is non-obvious (hidden constraint, subtle invariant, workaround, surprising behaviour - otherwise rename, extract, simplify, or omit).

When editing existing comments, make verbose prose concise - tighten to the sizes above, recast in plain English from the user's perspective - but never delete `@param` / `@returns` tags while tightening: trimming cuts words, not contract.

Never fabricate rationale: no guessed "for performance" / "for safety", no hedging (`probably`, `should be fine`). If you cannot verify why code exists, preserve the behaviour without inventing the reason. A comment that no longer matches the code gets deleted or rewritten immediately - incorrect is worse than missing.

## Decision Gate

Apply these directly before writing; the sections below give examples. The comment is a verification surface: state intent so a reviewer can diff it against the code.

- **Doc comment REQUIRED** on every function/method (1-3 line description) and class/file (3-8 lines, tags excluded): what it does, when to use it from the user's perspective, where it sits in the user-facing flow; blank ` *` line before the tags.
- **Context comment REQUIRED** above every `if`, loop (`for` / `foreach` / `while`), and null/empty check: one brief plain-English line - what is happening, what it means for the user.
- **Names first:** every variable and method name self-documenting, in the vocabulary the user sees on screen.
- **Tags:** `@param name - description` / `@returns value - description` - real meaning, never restated types, null/empty/absent meaning for the user on each.
- **User-journey anchors** at flow entry points: a concrete example of what the user did to get here.
- **Any other inline comment ONLY for** a hidden constraint, subtle invariant, workaround, or surprising behaviour - after trying rename / extract / simplify / enforce.
- **Verified rationale only:** no guessed reasons, no hedging, no process narration.
- **Tighten, don't strip:** verbose comments get shorter; `@param` / `@returns` lines never disappear.
- **Wrap ~110 chars** (hard max 120); `YYYY-MM-DD` date or a trigger on every TODO / FIXME / HACK.
- **Never:** markdown/emoji, commented-out code, secrets/PII/hostnames, position/line-number references, or non-load-bearing provenance.

## Worked Example

Before - mechanics-only name and comment, silent null path, no when-to-use:

```ts
/** Trim the trailing slash from a directory path. */
function trimDir(path: string | undefined): string | null {
  if (!path) return null;
  return path.replace(/\/$/, "");
}
```

After - the full contract applied:

```ts
/**
 * Normalize a directory path before the UI shows it or uses it for navigation.
 * Use when a user-selected or discovered project path may have a trailing slash.
 *
 * @param directoryPath - directory chosen by the user or found in config; `undefined` or empty
 *   means there is no path for the UI to show or open yet
 * @returns the directory without one trailing slash, or `null` when no usable path exists
 */
function trimTrailingDirectorySlash(directoryPath: string | undefined): string | null {
  // No directory is available yet, so the UI should skip path-based actions.
  if (!directoryPath) return null;

  return directoryPath.replace(/\/$/, "");
}
```

Each delta is a gate rule: the self-documenting rename, the when-to-use description, the null/empty meaning on both tags, the context line above the null check.

## Names Carry the User's Vocabulary

Every variable and method name must be self-documenting, in the user's vocabulary wherever a UI exists: name things after what the user sees and does, not after internal mechanics. `$data` -> `$overdueInvoices`; `stateBadge` -> `projectActionBadgeClass`. If the UI calls it an "appointment", the code does not call it a "booking".

A good name carries the WHAT, so comments only carry what a name cannot: user consequence, constraint, edge meaning. Magic values follow the same rule - name them away first; if a value cannot be made self-explanatory, comment the durable product rule that fixes it, never "magic value" as the reason.

## Doc Comments

Every function/method and every class/file carries one - trivial and private units included. The description is 1-3 lines for a function/method, 3-8 for a class/file (tags excluded): what this does, when to use it from the UI/user perspective, and how it belongs to the bigger user-facing process. A class/file description also names the screen, flow, or capability it serves.

Why mandatory, even on a private one-liner: the doc comment is a verification surface. Agents can produce code that superficially works while misunderstanding the requirement; stated intent lets a reviewer diff promise against implementation - a promised sort the body never performs is a review signal.

- **Contract:** inputs, outputs, errors, invariants - what the caller can rely on. **Orientation:** when to use it (and when not to), where it sits in the user's flow, the footguns a caller will hit.
- **Null/empty meaning on every tag.** `@param` and `@returns` each say what null/empty/absent means in user terms - "no folder chosen yet", "the user sees the empty state, not an error".
- **Real descriptions, not restated types.** Prefer the language's structured doc form (JSDoc, PHPDoc, PEP 257, godoc, rustdoc) over a bare inline comment.
- **Hyphen-separate each tag's subject from its description** (`@param value - parsed JSON ...`), **blank ` *` line between the description block and the tags**.

## Context Comments: Branches, Loops, Null/Empty Checks

Above every `if`, every loop (`for` / `foreach` / `while`; one line above a chained `.filter().map()` pipeline), and every null/empty check or fallback (`?? default`, `empty()`, early return on missing data), write one brief plain-English line: what is happening here, and what it means from the UI/user perspective.

The line must translate, not restate: "check if invoice is paid" is banned; "Paid invoices are locked - the user gets a read-only view instead of the edit form" earns its place because the user consequence is visible nowhere in the condition.

```php
// Each overdue invoice becomes one reminder email in the batch the practitioner just approved.
foreach ($overdueInvoices as $invoice) {
    $this->sendReminder($invoice);
}
```

In an `if` chain, each branch gets its own line. Before:

```js
stateBadge(project) {
  if (project.action === 'audit') return 'gf-badge-pass';
  if (project.action === 'upgrade') return 'gf-badge-warn';
  if (project.action === 'migration') return 'gf-badge-high';
  if (project.action === 'setup') return 'gf-badge-ap';
  if (project.action === 'fix') return 'gf-badge-ap';
  return 'gf-badge-muted';
},
```

After - each branch translated into what the user sees:

```js
/**
 * Choose the badge style shown beside a saved project in the Projects view.
 * Use when a user scans the project list and needs the recommended next step to read visually.
 *
 * @param projectRow - saved dashboard project; missing or empty `action` means the UI has
 *   no specific next step yet
 * @returns CSS badge class for the project action, or a muted badge when the action is unknown
 */
projectActionBadgeClass(projectRow) {
  // The project is ready to audit, so the UI shows a positive next-step badge.
  if (projectRow.action === 'audit') return 'gf-badge-pass';

  // The project needs a version update, so the UI shows a warning badge.
  if (projectRow.action === 'upgrade') return 'gf-badge-warn';

  // The project needs migration work, so the UI shows a higher-attention badge.
  if (projectRow.action === 'migration') return 'gf-badge-high';

  // The user still needs to install setup pieces, so the UI points them to setup.
  if (projectRow.action === 'setup') return 'gf-badge-ap';

  // The audit found repair work, so the UI highlights an actionable fix state.
  if (projectRow.action === 'fix') return 'gf-badge-ap';

  return 'gf-badge-muted';
},
```

Validation, permission, and compliance branches follow the same rule - name the product rule and the user-facing outcome, not just "validate input".

## User-Journey Anchors

In some places, add a comment giving a concrete example of what the user might have done to get to this point in the code: flow entry points (route handlers, event handlers, jobs) and junctions where the trigger is hard to reconstruct - not every method; the doc comment's when-to-use covers routine cases.

```php
// e.g. the practitioner opened Reports > Outstanding invoices and clicked "Email all".
public function emailOutstandingInvoices(Practice $practice): BatchResult
```

## Extra Inline Comments: Rewrite First, Then Four Reasons

Doc and context comments are mandatory and skip this ladder. Before any OTHER inline comment, try to make it unnecessary: **rename** (a user-vocabulary identifier often dissolves the comment), **extract** (a block that wants a header comment wants to be a function named with it), **simplify** (early returns beat prose explaining nesting), **enforce** (an assertion fails loudly; a comment can't protect itself). If intent still isn't visible, four cases earn a comment placed immediately above the line it explains.

A useful shape for the rationale: **Because [constraint], we do [choice]; prevents [failure], removable when [condition].** Prefer user, business, domain, legal, and vendor rationale over implementation rationale a reader can reconstruct.

**The Half-Life Test.** A good comment survives renames, extraction, and movement: anchor it to a durable constraint (user outcome, vendor contract, regulation, invariant, removal trigger), not a person, ticket, or review thread. If a routine refactor would invalidate it, the content belongs in code, not prose.

### Hidden constraint

Something the code cannot encode about its environment: rate limits, vendor contracts, regulatory rules, hardware quirks.

```python
# Vendor exports omit the timezone; treat as source-local by contract.
parsed = datetime.strptime(value, "%Y-%m-%d")
```

### Subtle invariant

A condition the code depends on but does not enforce; prefer an assertion when affordable. Hidden coupling counts: name the other side and the failure caused by changing only one.

```ts
// Must match the mobile app timeout; changing only this side can create duplicate submissions.
const PAYMENT_RETRY_TIMEOUT_MS = 8000;
```

### Workaround

Strange code that exists because of a bug or constraint elsewhere. Name the cause and the removal condition.

```ts
// Double rAF forces a layout flush before measuring. Single rAF returns stale
// values on Safari 17. Remove when Safari >= 18 is the baseline.
await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
```

### Surprising behaviour

Code that is correct but looks dangerous, wasteful, or backwards to the next reader.

```ts
// Intentionally mutates the input buffer. Copying doubles memory usage on 2GB+ exports.
normalizeInPlace(buffer);
```

### Product/user reason, not provenance

Comments citing issue numbers, ADRs, or review threads make the reader chase history. Translate provenance into the current product/user reason: not `# medium per ticket / review thread`, but

```yaml
# medium so short utterances ("yes", OTP digits) count as prompt events; low made callers repeat themselves.
voice_agent_interrupt_sensitivity: medium
```

## TODO / FIXME / HACK Markers

Every marker carries an expiry (`YYYY-MM-DD` date or a concrete trigger). Tracking references only when they are the durable owner, removal trigger, or verification path; otherwise the current product/user reason. `TODO(name):` is optional.

Bad: `// TODO: clean this up later.`
Good: `// TODO: 2026-08-01 remove this fallback once the new auth flow ships.`

## Antipatterns

The next reader cannot use these. Do not write them; if you are already editing the surrounding code, delete or fix them.

- **Restating the mechanics.** `i++; // increment i`, `// check if invoice is paid`. Context lines must add user meaning, not narrate syntax.
- **Stripping tags while tightening.** Concision never removes `@param` / `@returns` lines - trim their prose instead.
- **Codebase jargon.** A comment that only makes sense after reading the module has not translated to the user's perspective.
- **Unverified rationale.** `// for performance`, `// probably safe`. Verify the reason or omit it.
- **Commented-out code, tombstones, archaeology.** Git records removals; comments explain current constraints.
- **Position or line-number references.** `// see function below`, `// line 142`. Refer by symbol name.
- **Suppression markers without rationale.** `// eslint-disable-next-line` alone is noise.
- **Non-load-bearing provenance.** PRs, issues, ADRs, learning-loop entries, task IDs, review notes - unless the reference is the durable contract, removal trigger, or verification path.
- **Decorative density.** Comment count or presence alone is never evidence of quality.
- **Markdown, emoji, and session artifacts.** Code comments are plain prose, not chat history.

## Special Contexts

**Test code.** Naming and doc-comment rules apply; a descriptive test name plus a one-line doc is usually enough. Context comments relax to omit-by-default inside test bodies: the name and assertions carry the user story.

**Generated code.** Mark generated files at the top so maintainers do not edit the wrong source:

```text
// AUTO-GENERATED FROM <source> - DO NOT EDIT
```

**Suppression with rationale.** Use the linter's native reason syntax so a checker can verify a reason is present:

```ts
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- SDK response is dynamic; narrowed in the next call.
const raw: any = await client.invoke(params);
```

## Multi-Language Stance

The WHEN and WHY rules are portable; core syntax is not. Defer to each language, then apply the house layout conventions.

- **TypeScript / JavaScript.** JSDoc for contracts; plain `//` inline.
- **PHP.** PHPDoc (`/** ... */`) for contracts, with null/empty meaning on `@param` / `@return`; `//` inline.
- **Python.** PEP 257 docstrings; `#` inline.
- **Go.** godoc syntax for exported AND private identifiers; `//` inline.
- **Rust.** rustdoc (`///` and `//!`) for public AND private items; `//` inline.
- **Shell.** `#` only; put contract details in a heredoc help block at the top of the script.

## Security

Comments ship with code and get indexed. Never include secrets, tokens, API keys, customer or patient identifiers, internal-only URLs, production hostnames, account IDs, or infrastructure topology; redact any found while editing. User-journey anchors describe a generic user ("the practitioner"), never a real one.

## Troubleshooting

**A linter rejects the house doc format.** Keep `@param name - desc` / `@returns value - desc`; suppress the specific rule with rationale rather than restating types.

**A context line on every branch feels like noise.** The cure is better content, not omission: state the user consequence. A branch with no stateable user meaning is a naming or design smell worth surfacing - not a licence to restate mechanics.

**No UI exists (library, daemon, build tool).** Use the nearest consumer's perspective - the developer calling the API, the operator reading the log - in the same plain-English, outcome-focused register.

**A marker has no expiry or has provenance-only tracking.** Flag it; do not invent the missing trigger.

## Verification Gate

Before claiming a code change is done, check names and comments. **[static]** = mechanical, linter-checkable; **[judge]** = semantic, for a review-judge or human reviewer.

1. **[static] presence + [judge] quality: every function/method and class/file has a doc comment.** Sizes (1-3 / 3-8) and the blank separator line are mechanical; when-to-use from the user's perspective, bigger-picture fit, real parameter/return meaning, and non-restated types are semantic.
2. **[static] presence + [judge] quality: every `if`, loop, and null/empty check has one brief context line above it** that translates the moment into user meaning rather than restating mechanics.
3. **[judge] Every `@param` / `@returns` states what null/empty/absent means for the user**, and no tag was deleted while tightening a verbose comment.
4. **[judge] Names are self-documenting in the product's vocabulary** - identifiers match the words the user sees wherever a UI exists.
5. **[judge] Flow entry points carry a user-journey anchor where the trigger is hard to reconstruct.**
6. **[judge] Extra inline comments satisfy one of the four valid reasons**, sit at the decision point they explain, and prefer user/business/domain/legal/vendor rationale over reconstructible implementation rationale.
7. **[judge] Rationale is verified, not fabricated or hedged.**
8. **[judge] Comments pass the Half-Life Test** and avoid issue/PR/ADR/learning-loop/review provenance unless load-bearing for operating, verifying, or removing the code.
9. **[static] TODO / FIXME / HACK markers carry an expiry** (`YYYY-MM-DD` or trigger) and only load-bearing tracking references.
10. **[static] Comments contain no secrets, internal URLs, or production hostnames**; customer/patient identifiers may need **[judge]** review.
11. **[judge] Existing comments touched or noticed are still accurate.** A stale comment you noticed is now part of the change.
12. **[static] Comment lines wrap around 110 characters** and never run past 120.

If a comment fails any check, fix it before merging.

## Related References

- Sibling playbooks installed alongside this one share the same scaffold.
- Project instruction files may point here as the canonical comment policy.
