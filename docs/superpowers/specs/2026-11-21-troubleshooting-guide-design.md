# Troubleshooting guide for firewall and server blocks

MD3-34. Small change: a document plus one link in the admin. Follows MD3-32 (static discovery files), which it links to.

## Why

AI clients can fail to connect because a firewall or the web server blocks the request before WordPress sees it. The plugin's Diagnostics can't detect that (its self-check sends WordPress's own user agent), and the clients only show generic errors such as claude.ai's "Couldn't check the server … 403". On GridPane staging (2026-11-16), 7G rules 5 and 10 and the `/.well-known/` location blocked claude.ai and the local clients until they were fixed.

## Goals

- `docs/troubleshooting.md`: for each issue, the symptom, how to confirm it (log line or `curl`), a copy-paste fix and why the fix is safe. Covers: finding where a request was blocked; `/.well-known/` 404 on GridPane (points to the MD3-32 files); 7G rule 5 (`python-httpx`); 7G rule 10 (`localhost` redirects); pitfalls when writing 7G exceptions; security notes, including the `rest_route=` leftover; a short checklist for Cloudflare, Wordfence, ModSecurity/OWASP CRS and Sucuri; links to the server notes already in the README.
- The 7G whitelist is the file in use on staging (`/var/www/<site>/nginx/whitelist-md3-wp-mcp-7g-context.conf`), presented as an example to adapt to what the logs show.
- Linked from the README and from the Diagnostics section of the Security tab.

## Non-goals

- No Spanish version: the README has none.
- No change to how the plugin checks or serves anything.

## Approaches

- **A. Guide in `docs/`, admin link to it on GitHub (chosen).** The repo is private and `docs/` isn't in the plugin build, so a plugin-relative link would serve raw Markdown or nothing. The GitHub link works for Dariel now and for anyone if the repo becomes public.
- B. Ship the guide in the plugin and link to the file. Browsers would show raw Markdown, and it would add a file to every site.
- C. An admin "Troubleshooting" tab. Duplicates the guide in PHP and translations; too much for content that changes with each host.

## Design (decided)

- Verification commands were run against staging on 2026-11-21; the guide lists each with its expected and observed status.
- The Diagnostics section gets one sentence after its description: if every check passes and a client still can't connect, a firewall may be blocking it before WordPress; link "troubleshooting guide" to `https://github.com/darielcorchado/md3-wp-mcp/blob/master/docs/troubleshooting.md` (constant `Diagnostics::GUIDE_URL`), opening in a new tab.
- The user-agent table carries the date it was observed; ChatGPT and Gemini rows say "not tested yet".
- README server notes link to the guide; the guide links back to the README for the Authorization header, page caches and Polylang rather than copying them.

## Builder's calls

- Exact wording and order inside each section.
- The header comment of the 7G file (lines 1–10 weren't in the paste; the rule lines are verbatim).
