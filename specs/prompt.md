# Goal-Mode Prompt: Build the Image-to-PowerPoint MVP

Copy the prompt below into Codex CLI after enabling the experimental Goal feature. The OpenAI Codex Goal docs recommend one durable objective, a verifiable stopping condition, required files to read first, proof commands/artifacts, checkpoints, and compact progress reporting.

```text
/goal Complete the local MVP implementation of the Image-to-PowerPoint Laravel/Inertia app without stopping until specs/checklist.md's Done Definition is satisfied, excluding deployment to a remote server.

You are working in /Users/fabianwesner/Herd/image-to-powerpoint.

Read first, in this order:
1. AGENTS.md instructions already loaded in this session.
2. specs/specification.md
3. specs/testplan.md
4. specs/checklist.md
5. composer.json, package.json, phpunit.xml, vite.config.ts

Objective:
Build the MVP described in specs/specification.md and make the local repository pass the completion criteria in specs/checklist.md. Treat specs/specification.md section 2, "Canonical decisions", as the source of truth when any document seems ambiguous. Use specs/testplan.md for the required test cases and tags. Do not implement deployment to a remote server.

Scope:
- Implement M0 through M4 from specs/specification.md.
- Include backend, frontend, migrations, models, factories, validation, storage, queue job, Python bridge wrapper, LibreOffice PDF rendering, local lifecycle/reaper, rate limits, signed downloads, security headers, copy-link, delete flow, tests, and local verification scripts.
- Do not implement post-MVP features unless needed as tiny scaffolding for tests marked deferred.
- Automated tests are required for deterministic logic and server behavior: Pest unit tests, Pest feature tests, and architecture tests. Do NOT create scripted browser/e2e tests with Pest Browser, Playwright Test, Cypress, or any equivalent test runner.
- Browser and UX cases from specs/testplan.md must still be executed by the coding agent using Playwright in Chrome in a non-scripted way during the Goal run. Use the in-app/browser automation tools to open the local app, click/type/upload/navigate, inspect console errors, and verify the flows manually. Record what was checked in the progress log and checklist instead of adding e2e test files.
- Do not add accounts, login, email/share-to-X, slide editing, user-supplied pptx import, local LLM integration, third-party telemetry, i18n, or remote deployment.
- Do not change dependencies without first checking whether the existing stack already provides the needed capability. If a dependency is truly required, pause and explain why.

Project rules:
- Follow Laravel Boost, Laravel 13, Inertia v3 React, Wayfinder, Pest 4, Tailwind v4, and Shadcn conventions.
- Before Laravel/Inertia code changes, use Laravel Boost docs search for version-specific docs relevant to that checkpoint.
- Use Wayfinder route helpers in React instead of hardcoded app URLs.
- Use `php artisan make:* --no-interaction` for Laravel classes where practical.
- For PHP changes, run `vendor/bin/pint --dirty --format agent` before checkpointing.
- Preserve existing user changes. Do not revert unrelated work.
- Do not create extra documentation files unless they directly update the requested specs/checklist/prompt artifacts.

Execution loop:
Work in checkpoints. At the start of each checkpoint, name the checkpoint and the checklist sections it targets. At the end of each checkpoint:
- Mark completed items in specs/checklist.md only when verified.
- Run the narrowest relevant automated tests for that checkpoint.
- For browser/UX cases, run the app locally and execute the relevant flows in Chrome via Playwright non-scripted interaction. Do not add scripted e2e tests.
- Record a compact progress note in your final/status update: changed files, verification run, failures remaining, next checkpoint.
- If a test is host-dependent and cannot run locally, tag or document the skip exactly as specs/testplan.md allows.

Suggested checkpoints:
1. Data model and private storage skeleton: migrations, models, factories, session cookie, upload nonce, storage paths.
2. Upload validation and home/project shell: Inertia pages, sidebar, upload, canonical config keys, initial input preview.
3. Python bridge and queue conversion: venv paths, wrapper, normalisation, Symfony Process argv, warm-models command, heartbeat, failure mapping.
4. PDF rendering and downloads: LibreOffice process, signed routes, headers, inline preview, partial PDF state.
5. Version iteration: regenerate, replace-image, version switcher/deep links, eviction caps, delete flow, copy-link.
6. Lifecycle and abuse controls: projects:reap, TTL/byte ceiling, rate limit buckets, global queue depth, stale running recovery.
7. Security and architecture hardening: anti-clickjacking headers, Origin checks, path discipline, no telemetry, no Gemini gate, signed download tamper tests.
8. Browser/UX polish and Playwright Chrome QA: polling stop cap, offline/slow-network states, focus rings, alt text, responsive layout. Execute these flows in Chrome via Playwright non-scripted interaction; do not create e2e test files.
9. Full verification pass: complete MVP unit/feature/architecture tests, execute browser/UX test-plan flows in Playwright Chrome, run local commands, update checklist, summarize any documented exceptions.

Validation commands:
Use narrow commands during checkpoints. Before declaring the goal complete, run:

composer run lint:check
npm run lint:check
npm run format:check
npm run types:check
php artisan test
php artisan schedule:list
php artisan ppt:warm-models --check-only
bin/grep-no-gemini.sh

Also run targeted Pest filters as you add each backend/unit/architecture test area. If a command fails because the command itself has not been implemented yet, implement it. If a command fails because a host dependency such as LibreOffice, Python model cache, or network namespace is unavailable locally, keep the related check tagged deploy/best-effort as specified by specs/testplan.md and document the skip in the progress log.

Browser verification:
- Start the local app through the project's normal dev workflow.
- Use Playwright in Chrome to execute browser/UX cases from specs/testplan.md non-scriptedly: upload, polling, regenerate, replace-image, version switching, copy-link, delete, refresh/back/double-submit, PDF preview, offline/slow-network states where practical, focus rings, and console-error checks.
- Do not add `tests/Browser`, Playwright Test specs, Cypress specs, or any scripted browser/e2e suite.
- If specs/testplan.md still labels a browser case as L2 or Pest browser, interpret it for this Goal run as "execute manually through Playwright in Chrome and record the result."

Stopping condition:
Stop only when all of the following are true:
- specs/checklist.md Done Definition is satisfied for the local MVP, excluding remote server deployment.
- All unit, feature, and architecture tests tagged MVP in specs/testplan.md are implemented and pass locally.
- Browser/UX cases tagged MVP in specs/testplan.md have been executed by the coding agent in Chrome using Playwright non-scripted interaction, with results reflected in specs/checklist.md or the final progress summary.
- Post-MVP tests are skipped/deferred intentionally and do not fail the MVP suite.
- Local verification commands pass or have documented host-dependent skips allowed by specs/testplan.md.
- specs/checklist.md is updated to reflect verified completion.
- The final response lists changed files, verification results, and any remaining non-blocking deploy/manual notes.

Pause conditions:
Pause and ask for input only if:
- A required dependency or system package must be added and no existing project tool can cover it.
- The implementation would require remote server deployment, credentials, paid services, or a third-party API.
- The specs contradict the canonical decisions table in a way that cannot be resolved conservatively.
- A host limitation prevents proving an MVP-blocking behavior locally and no acceptable mocked or tagged alternative exists in specs/testplan.md.

Progress reporting:
Keep updates compact. Name the current checkpoint, what just passed, what remains, and whether you are blocked. Avoid broad rewrites outside the active checkpoint.
```

## Notes for the Human Running Goal Mode

- Enable Goal mode in Codex CLI first with `/experimental`, or set `goals = true` under `[features]` in `config.toml`.
- Use `/goal` to inspect status while it runs.
- Use `/goal pause`, `/goal resume`, or `/goal clear` if you need to control the run.
- The goal is intentionally scoped to the local MVP. Remote deployment checks remain represented by deploy-tagged scripts/tests, but deploying to a server is not part of done.
