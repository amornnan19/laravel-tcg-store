# Claude Code

This file deliberately holds no rules of its own.

Laravel Boost writes the same generated guidelines into both `AGENTS.md` and `CLAUDE.md`, which
leaves two copies that can drift apart. To keep one source of truth, the guidelines live in
`AGENTS.md` and this file imports them:

@AGENTS.md

Project rules — the stack, the decisions already taken, the rules that are easy to break — live in
`.ai/rules/`, which the guidelines above tell you to read:

@.ai/rules/index.md

`config/boost.php` points Claude Code's guidelines at `AGENTS.md`, so `php artisan boost:update`
should never write here. If a Boost guidelines block ever appears in this file anyway, delete it and
leave the two imports above. Do not write the block's tag name in this file: Boost matches on it.
