# Git Commit Instructions

Use the repository standard in `docs/coding-standards/git-commit-message.md`.

## Required Shape

- Prefer `type(scope): subject`.
- Use concise imperative subjects under 72 characters.
- Use concrete verbs such as `add`, `fix`, `remove`, `replace`, `harden`, or `gate`.
- Do not invent issue or ticket prefixes.

## Common Types

- `feat` for user-visible behaviour.
- `fix` for incorrect behaviour.
- `docs` for documentation-only changes.
- `test` for test-only changes.
- `ci` for workflow automation.
- `chore` for maintenance.
- `security` for hardening.

## Quality Bar

- Run the relevant focused tests before committing.
- Run `composer cs:fix` for PHP formatting changes.
- Run `composer preflight` before release or broad runtime changes.
