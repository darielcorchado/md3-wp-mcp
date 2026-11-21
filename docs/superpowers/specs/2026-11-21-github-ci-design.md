# Git flow, CI tests on pull requests, and protected branches

MD3-35. Small change: repository tooling and docs, no plugin code.

## Why

Tests only ran when someone remembered `bin/test.sh`. The repo is going public, and once it is, no one may merge into `stage` or `master` without Dariel's approval, including Claude Code, which pushes with his GitHub login.

## Goals

- Run the tests on every pull request into `stage` and `master`, exactly as they run locally.
- No one but Dariel can merge into `stage` or `master`. Not even Dariel can merge when the tests fail, push directly, force-push, or delete those branches.
- Claude Code can't merge, and asks before every push and new pull request.
- Document the Git flow: feature branches from `master` go into `stage`, `stage` goes into `master` at release with a `vX.Y.Z` tag, and hotfixes go into `master` and then back into `stage`.

## Non-goals

- Making the repo public. Dariel does that himself after the history check.
- Linting (PHPCS) or a PHP version matrix in CI.

## Approaches

- **A. Run `bin/test.sh` on the GitHub runner (chosen).** The runner has Docker, so CI uses the same compose stack (PHP 8.2 + MySQL 8) as local runs. One test setup to maintain.
- B. Native PHP plus a MySQL service container. A few seconds faster, but it needs a second database config, and CI could pass while local runs fail.

## Design (decided)

- **Workflow:** `.github/workflows/tests.yml`.
  - Triggers: `pull_request` into `stage` and `master`, plus `workflow_dispatch`. It never uses `pull_request_target`.
  - Token: `contents: read`.
  - Actions are pinned by commit SHA.
  - `vendor/` and `tests/wp/` are cached, keyed on `composer.lock`.
  - A new push cancels the run already in progress.
  - The job is named `tests`; that is the required check.
- **`bin/test.sh`:** waits until PHP can connect to the test database, using `tests/wp-tests-config.php`. Before, it pinged inside the db container, which passes during MySQL's first-start setup server, and some Docker Compose versions report success from `exec` whatever the command returns. A fresh database, as on every CI run, failed with `db_connect_fail`.
- **Rulesets** on `refs/heads/stage` and `refs/heads/master`:
  - **Tests and history** (no bypass): a pull request is required, the `tests` check is required, and force pushes and deletion are blocked.
  - **Owner approval** (the repository admin can bypass, through a pull request only): 1 approval, a code owner review, earlier approvals dismissed on a new push, and the last push must be approved.
  - `.github/CODEOWNERS`: `* @darielcorchado`.
- **Fork pull requests:** the workflow runs only after Dariel approves it (`approval_policy: all_external_contributors`).
- **`.claude/settings.json`:**
  - `deny`: `gh pr merge` and force pushes;
  - `ask`: `git push` and `gh pr create`.
- **No direct pushes to `stage`** changes two steps of the flow:
  - the release version bump goes in a `release/vX.Y.Z` branch;
  - the hotfix returns to `stage` through a pull request from `master`.

## History check (before going public)

- gitleaks found no leaks in 38 commits.
- A grep for server paths, hostnames, emails and IPs found two things:
  - the staging hostname and server path in the MD3-34 spec, now replaced with `<site>` (it stays in the history of 659fe38);
  - the commit author email on most commits.

  Both are Dariel's call before going public.

## Builder's calls

- Action versions, and the timeout (15 minutes).
- The CHANGELOG line under `## Unreleased`.
