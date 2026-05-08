# Goal-Mode Prompt: Build the Image-to-PowerPoint MVP

Copy only the fenced prompt below into Codex Goal mode. It is intentionally under the 4k objective limit; the detailed requirements live in `specs/specification.md`, `specs/testplan.md`, and `specs/checklist.md`.

```text
/goal Build the local Image-to-PowerPoint MVP.

Repo: /Users/fabianwesner/Herd/image-to-powerpoint

Read first:
1. specs/specification.md
2. specs/testplan.md
3. specs/checklist.md
4. composer.json, package.json, phpunit.xml, vite.config.ts

Objective:
Implement specs/specification.md and satisfy specs/checklist.md's Done Definition. Exclude remote deployment. If docs conflict, specs/specification.md section 2 wins.

Scope:
- Backend: models, migrations, factories, routes, controllers, validation, private temporary storage, sessions, upload nonce, signed downloads, queue job, reaper, rate limits, headers, logging.
- Conversion: Python venv, pinned px-image2pptx [ocr,inpaint], Pillow normalisation, Symfony Process argv, warm-models, optional LibreOffice PDF preview, heartbeat/stale jobs, failure taxonomy.
- Frontend: Inertia React, Wayfinder, Tailwind/Shadcn UI, multi-image upload, queued status indicators, preview, polling, regenerate, Attempts, partial PDF state, per-conversion delete, delete-all for the current session, and session-only recent Conversions.
- Tests/quality: Pest unit/feature/architecture tests.
- Browser/UX validation: execute specs/testplan.md browser cases in Chrome using Playwright non-scripted interaction. Do not create scripted browser/e2e tests.

Hard rules:
- Use Laravel Boost docs search before Laravel/Inertia code changes.
- Use existing conventions. Do not add dependencies without pausing.
- For PHP edits, run vendor/bin/pint --dirty --format agent.
- Do not add or keep project structure, project/share URLs, slug-based access, copy-link UI, accounts, login, email/share-to-X, slide editor, pptx import, local LLM, telemetry, i18n, remote deployment, tests/Browser, Playwright Test, Cypress, or scripted e2e.
- Preserve unrelated user changes.

Checkpoint loop:
Update specs/checklist.md only after verification. Commit after each meaningful verified checkpoint; do not commit unrelated or knowingly broken work.
1. Data model/private storage/session/nonce.
2. Upload validation and home/conversion shell.
3. Python bridge, queue conversion, normalisation, warm-models, heartbeat, failures.
4. Optional PDF rendering, downloads, headers, partial/preview-unavailable state.
5. Regenerate, Attempt links, eviction, delete, and delete-all.
6. Reaper, TTL/byte ceiling, rate limits, queue cap, stale running recovery.
7. Security/architecture hardening: Origin, signed downloads, path discipline, no telemetry, no Gemini, no project/share fragments.
8. Frontend polish and non-scripted Playwright Chrome QA.
9. Full verification and final checklist update.

Required final local checks:
composer run lint:check
npm run lint:check
npm run format:check
npm run types:check
php artisan test
php artisan schedule:list
php artisan ppt:warm-models --check-only
bin/grep-no-gemini.sh
rg -n "ProjectController|ProjectStorage|ProjectLifecycle|ImageProjector|ConvertVersionJob|VersionStatus|session_projects|create_projects_table|create_versions_table|/projects|projects\\.show|projects\\.destroy|projects\\.regenerate|Copy link|copyLink|Anyone with this link" app resources routes tests database config

Stop only when:
- specs/checklist.md Done Definition is satisfied for the local MVP.
- All MVP unit/feature/architecture tests pass.
- Browser/UX MVP cases were executed in Chrome via Playwright non-scripted interaction and results are recorded.
- Static scans confirm zero active fragments from the old project/share-link design remain in app, resources, routes, tests, database, and config.
- Work is complete with no old unused code, tables, generated routes, or UI fragments left behind.
- Post-MVP/deploy/best-effort cases are skipped or documented as allowed by specs/testplan.md.
- Final answer lists changed files, verification results, and remaining non-blocking deploy/manual notes.

Pause only for required new dependencies/system packages, remote credentials/services, unresolved spec contradictions, or a host limitation that blocks an MVP behavior with no allowed mocked/tagged alternative.
```

## Notes for the Human Running Goal Mode

- Enable Goal mode in Codex CLI first with `/experimental`, or set `goals = true` under `[features]` in `config.toml`.
- Use `/goal` to inspect status while it runs.
- Use `/goal pause`, `/goal resume`, or `/goal clear` if you need to control the run.
- Remote deployment is intentionally outside the objective.
