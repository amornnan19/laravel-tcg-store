# Claude Code

This file deliberately holds no rules of its own.

Laravel Boost writes the same generated guidelines into both `AGENTS.md` and `CLAUDE.md`, which
leaves two copies that can drift apart. To keep one source of truth, the guidelines live in
`AGENTS.md` and this file imports them:

@AGENTS.md

Project rules — the stack, the decisions already taken, the rules that are easy to break — live in
`.ai/rules/`, which the guidelines above tell you to read:

@.ai/rules/index.md

If `php artisan boost:update` ever writes a `<laravel-boost-guidelines>` block back into this file,
delete the block and leave the two imports above.
