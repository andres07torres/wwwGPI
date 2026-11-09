# Git Workflow & Commit Rules

## 1. No Automatic Commits
- NEVER execute `git commit` or `git push` automatically.
- All code changes, fixes, and refactorings must remain in the working tree for the user to audit and review first.
- The agent must present the changes, explain what was modified, and wait for the user's explicit instruction/approval before staging, committing, or pushing to any branch.
