# Troubleshooting Guide Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `docs/troubleshooting.md` and link it from the README and the Diagnostics section.

**Architecture:** A Markdown guide in `docs/`; one translated sentence with a link in `tab-security.php`, URL in `Diagnostics::GUIDE_URL`.

**Tech Stack:** Markdown, PHP 8.2 / WordPress admin view, PHPUnit in Docker.

**Spec:** `docs/superpowers/specs/2026-11-21-troubleshooting-guide-design.md`

## Global Constraints

- Every issue: symptom, how to confirm, copy-paste fix, why it's safe.
- The 7G rule lines are verbatim from staging.
- Text domain `md3-wp-mcp`; es_ES translated; one commit after Dariel approves.

## Review Focus

- A reader on a host without GridPane: the checklist still tells them what to allow (paths and user agents).
- Copy-paste commands use a `<site>` placeholder, never staging's address, except in the "verified on staging" record.

---

### Task 1: Diagnostics link

**Files:** Modify `includes/admin/class-diagnostics.php`, `includes/admin/views/tab-security.php`; Test `tests/integration/test-admin.php`

- [ ] **Step 1: Failing test** `test_diagnostics_links_the_troubleshooting_guide`: render the Security tab; output contains `esc_url( Diagnostics::GUIDE_URL )` with `target="_blank"`; `Diagnostics::GUIDE_URL` is `https://github.com/darielcorchado/md3-wp-mcp/blob/master/docs/troubleshooting.md`.
- [ ] **Step 2:** `bin/test.sh --filter test_diagnostics_links_the_troubleshooting_guide` → FAIL.
- [ ] **Step 3:** Add the constant and the sentence ("If every check passes and an AI client still can't connect, a firewall may be blocking it before it reaches WordPress. See the %s." with the link text "troubleshooting guide"), printed through `wp_kses` allowing `a[href,target,rel]`.
- [ ] **Step 4:** Run → PASS.

### Task 2: The guide, README, translations

**Files:** Create `docs/troubleshooting.md`; Modify `README.md`, `docs/design.md` §8 (one line), `CHANGELOG.md` (0.3.0 entry: one line), `languages/*`, `CLAUDE.md` (layout: docs/ list).

- [ ] **Step 1:** Write the guide (sections per the spec), with staging results from 2026-11-21.
- [ ] **Step 2:** README server notes and Diagnostics row link the guide.
- [ ] **Step 3:** `.pot` / `es_ES.po` / `.mo` (commands in `docs/development.md`).
- [ ] **Step 4:** `bin/test.sh` → all pass, no `[ERROR]` lines.
