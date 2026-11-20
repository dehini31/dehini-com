#!/bin/bash
# Claude Code SessionStart hook: prints the handoff context at the start of a session (see AGENTS.md, section 6).
# Read-only: it shows the branch, recent commits, uncommitted files and the top of HANDOFF.md. It never modifies
# files or Git, takes no Git lock (--no-optional-locks), and always exits 0 so it can never block a session.
set -u
cd "${CLAUDE_PROJECT_DIR:-.}" 2>/dev/null || exit 0
{
  echo "== Shared handoff context: read AGENTS.md and HANDOFF.md before working =="
  if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Branch: $(git rev-parse --abbrev-ref HEAD)  HEAD: $(git log -1 --format='%h %s (%ad, %an)' --date=short)"
    echo "Recent commits:"
    git log --oneline -5 | sed 's/^/  /'
    changes=$(git --no-optional-locks status --porcelain | head -n 25)
    if [ -n "$changes" ]; then
      echo "Uncommitted changes (they may belong to the user or to Codex: never discard them):"
      echo "$changes" | sed 's/^/  /'
    else
      echo "Working tree clean."
    fi
  fi
  if [ -f HANDOFF.md ]; then
    echo "--- HANDOFF.md (first 45 lines; read the whole file) ---"
    head -n 45 HANDOFF.md
  else
    echo "HANDOFF.md is missing: create it from AGENTS.md section 6 before handing over."
  fi
} 2>/dev/null
exit 0
