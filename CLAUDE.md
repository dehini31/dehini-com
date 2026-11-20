# CLAUDE.md

The shared instructions for every agent (Claude Code and OpenAI Codex) are in `AGENTS.md`, imported here so there
is a single source of truth:

@AGENTS.md

## Claude Code specifics

- At session start, a read-only hook (`.claude/hooks/session-start.sh`, registered in `.claude/settings.json`)
  prints the branch, the last commits, any uncommitted files and the top of `HANDOFF.md`. It changes nothing.
  Still read `HANDOFF.md` in full before working (rule 1 of `AGENTS.md`).
- In Claude Code cloud sessions, work on the branch the session assigns (`claude/…`), push only that branch, and
  open a pull request only when the user asks. The owner merges into `main` (= production deploy).
- Before ending a turn after meaningful work, or when usage is running low, update `HANDOFF.md` and commit it on
  your branch so Codex can continue from that checkpoint.
- Long test suites (`tests/studioma/run.sh`, about 5 minutes) need a generous command timeout.
