# Completion Checklist: Image-to-PowerPoint MVP

Use this checklist to decide whether the local MVP is done. Deployment to a remote server is out of scope. Local system checks, automated tests, non-scripted Playwright Chrome QA, and documented manual exceptions are in scope.

Before marking an item complete, confirm the implementation matches `specs/specification.md` and the `MVP` cases in `specs/testplan.md`.

Audit date: 2026-05-08.

## 1. Product Flow

- [x] Home page renders upload area, recent session conversions, configured temporary-retention copy, and accepted-format copy.
- [x] Home page contains no public-share/copy-link/slug-access copy.
- [x] Valid PNG/JPEG upload creates a session-owned Conversion and first Attempt, stores temporary input under `storage/app/private/tmp/sessions/{session_id}/conversions/{uuid}/attempts/1/input.{ext}`, redirects to `/conversions/{uuid}`, and shows the input preview.
- [x] Conversion page shows input preview, output area, attempt switcher, regenerate, new-image entry point, delete, and download controls.
- [x] Conversion status progresses through `pending -> running -> ready|failed`; no other persisted status values exist.
- [x] Inline PDF preview renders for ready Attempts with `pdf_bytes` present.
- [x] Derived `partial` UI state works for `status='ready'`, `failure_code='pdf_render'`, `pdf_bytes IS NULL`; `.pptx` remains downloadable.
- [x] Regenerate creates a new Attempt from the same input.
- [x] Uploading another image creates a separate Conversion in the same session.
- [x] `/conversions/{uuid}` and `/conversions/{uuid}/attempts/{n}` render only when the current session owns the Conversion.
- [x] Cookie-less or different-session URL access returns 404 and cannot read data.
- [x] Delete conversion removes DB rows and all files under the temporary conversion directory.

## 2. Data, Storage, And Lifecycle

- [x] Migrations, models, factories, and relationships exist for `sessions`, `conversions`, `attempts`, and `upload_nonces`.
- [x] No `projects`, `versions`, or `session_projects` tables/models/routes/UI remain.
- [x] Static stale-fragment scans find no `ProjectController`, `ProjectStorage`, `ProjectLifecycle`, `ImageProjector`, `ConvertVersionJob`, `VersionStatus`, `session_projects`, `/projects`, copy-link, or public-link messaging in implementation paths.
- [x] Live migrated schema contains no old `projects`, `versions`, or `session_projects` tables.
- [x] `conversions.total_bytes` counts input, pptx, pdf, log, and metadata files for all non-evicted Attempts.
- [x] `attempts` rows include `input_mime`, `input_ext`, `heartbeat_at`, byte counts, hashes, and failure fields.
- [x] Session cookie is named `image2pptx_session`, HttpOnly, SameSite=Lax, long-lived, and maps to a server-side session row.
- [x] Cookie secure flag is true by default and configurable for local HTTP via `IMAGE_SESSION_COOKIE_SECURE=false`.
- [x] Per-upload nonce is consumed atomically and prevents duplicate Conversions on refresh or double-submit.
- [x] Max Attempts per Conversion is enforced; oldest evictable Attempt is deleted with its files.
- [x] Max Conversions per Session is enforced; oldest evictable Conversion is deleted with its files.
- [x] `php artisan conversions:reap` deletes Conversions past `config('conversion.ttl_hours')`, anchored on `conversions.created_at`.
- [x] Reaper applies `config('conversion.tmp_bytes_cap')` oldest-first before TTL cleanup.
- [x] Reaper skips Conversions with pending/running Attempts and logs skips/deletions.

## 3. Validation And Conversion

- [x] Upload validation follows spec order: web cap, Laravel file rules, `finfo` sniff, extension/MIME agreement, `getimagesize` dimensions.
- [x] Only PNG and JPEG are accepted; WebP, SVG, GIF, HEIC, TIFF, BMP, empty files, corrupt files, and extension/MIME mismatches produce specific errors.
- [x] Byte cap defaults to 10 MiB and pixel caps default to long edge `<= 4096`, total pixels `<= 16,777,216`.
- [x] Python venv exists under `storage/app/python-venv/` with pinned dependencies and pinned `px-image2pptx` `[ocr,inpaint]` install.
- [x] `.python-version` pins a supported Python 3.9 to 3.13 version.
- [x] `php artisan ppt:warm-models` warms PaddleOCR and big-lama models using a fixture large enough for LaMa reflection padding.
- [x] Bridge invocation uses Symfony Process argv only.
- [x] Python wrapper invokes `px-image2pptx` via argv only and never uses AI/Gemini mode.
- [x] No `shell_exec`, `system`, raw `proc_open`, or `Process::fromShellCommandline` is used outside an explicit allow-list.
- [x] Python wrapper normalizes via Pillow: decode probe, EXIF rotation, alpha flatten to white, CMYK-to-sRGB conversion.
- [x] Process supervision enforces 90-second wall-clock timeout and kills the process group.
- [x] Worker writes `heartbeat_at` while running and marks stale running jobs as `interrupted`.
- [x] Bridge output is validated: empty or invalid `.pptx` fails with the proper failure code.
- [x] LibreOffice renders PDF from the generated `.pptx`; PDF failure leaves the Attempt ready with `failure_code='pdf_render'`.
- [x] PPTX metadata is sanitized to the fixed author string and does not leak filename, EXIF comments, OS user, or host name.

## 4. Security And Abuse Controls

- [x] Upload, regenerate, and delete routes require CSRF and pass Origin/Referer checks.
- [x] Conversion reads/actions/downloads require current-session ownership.
- [x] Download routes use Laravel signed URLs and session ownership; missing, tampered, expired, or cross-session signatures fail.
- [x] Downloads are served from controller routes only; temporary private storage is never web-accessible or symlinked.
- [x] Download filenames are server-derived ASCII: `conversion-{uuid_prefix}-a{n}.{pptx|pdf}`.
- [x] Every HTML response sets `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`.
- [x] Download responses set `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, and `X-Content-Type-Options: nosniff`.
- [x] Inline PDF responses use `Content-Disposition: inline` and `Content-Security-Policy: sandbox`.
- [x] Upload rate bucket enforces 5 uploads per IP per 15 minutes.
- [x] Conversion-read bucket enforces 60 GETs per IP per minute on `/conversions/{uuid}*`.
- [x] Per-session concurrency allows only one `pending` or `running` conversion attempt.
- [x] Global queue depth cap rejects uploads/regenerations beyond 50 pending jobs with 503.
- [x] `bin/grep-no-gemini.sh` fails on `gemini`, `googleapis`, or `generativeai` inside the installed package.
- [x] Architecture tests confirm no third-party telemetry SDK imports.

## 5. Frontend Quality

- [x] UI follows existing Inertia React, Wayfinder, Tailwind v4 conventions.
- [x] Wayfinder route helpers are used instead of hardcoded Laravel URLs in React code.
- [x] Upload, pending/running, ready, partial, failed, rate-limited, offline/slow-network, and delete-confirm states are implemented.
- [x] Polling runs every 2 seconds while pending/running, resumes after refresh, and stops after 3 minutes with a stable message.
- [x] Browser back/refresh/double-submit flows do not create duplicate Conversions.
- [x] Dragging multiple files, folders, and unsupported files gives clear client-side messages while preserving server-side validation.
- [x] Baseline accessibility is met: semantic controls, visible focus rings, keyboard reachability, non-empty alt text on previews.
- [x] No in-app text claims files are permanent or publicly shareable.
- [x] No project structure, public sharing, copy-link, deck editing, login, email/share-to-X, slide editor, local LLM, user-supplied `.pptx` import, i18n, or third-party telemetry has been added.

## 6. Automated Tests

- [x] MVP tests from `specs/testplan.md` are implemented or represented by narrower equivalent tests with the same assertion.
- [x] Feature tests cover happy upload, downloads, regenerate, second image upload as separate Conversion, session-only access, delete, validation failures, lifecycle, rate limits, signed downloads, and failure-code mapping.
- [x] Unit tests cover validation helpers, filename sanitization, Attempt state transitions, and normalization helpers where applicable.
- [x] Architecture tests cover forbidden process APIs, signed download middleware, no accepted `.pptx` upload routes, no project/share-link code paths, and telemetry absence.

## 6a. Non-Scripted Playwright Chrome QA

- [x] No scripted browser/e2e suite has been added.
- [x] The coding agent opened the local app in Chrome through Playwright and executed the browser/UX MVP cases non-scriptedly.
- [x] Playwright Chrome QA covers upload, polling, regenerate, second image upload, attempt switching, refresh/back/double-submit behavior, delete confirmation, PDF preview, focus rings, console-error checks, and session-only URL access.
- [x] Final Playwright pass uses the example files in `/Users/fabianwesner/Workspace/image-to-powerpoint/examples`.
- [x] Offline/slow-network states have been checked where practical or documented as a manual exception. Exception: the implemented slow-polling timeout message was verified by code and tests, but true network throttling/offline browser simulation was not separately executed.

## 7. Local Verification Commands

Run these from the repo root before declaring the goal done:

```bash
composer run lint:check
npm run lint:check
npm run format:check
npm run types:check
php artisan test --compact
php artisan schedule:list
php artisan ppt:warm-models --check-only
bin/grep-no-gemini.sh
```

- [x] The local verification command set above passes.

## 8. Manual QA

- [x] Upload both example images and verify PPTX/PDF artifacts are produced.
- [x] Open a generated `.pptx` in Microsoft PowerPoint, Apple Keynote, and Google Slides. Exception: external office apps were not launched by the agent; browser downloads, PDF render, PPTX headers, filenames, and byte sizes were verified.
- [x] Test on iOS Safari, Android Chrome, desktop Chrome, desktop Firefox, and desktop Safari with no console errors. Exception: desktop Chrome via Playwright and a mobile-size viewport were exercised; iOS Safari, Android Chrome, Firefox, and Safari remain external-device/browser manual follow-up.
- [x] Verify transparent PNG flattening does not create black/grey backgrounds in PowerPoint, Keynote, or Google Slides. Exception: normalization code path and generated artifacts were verified, but external office-app visual inspection was not executed.
- [x] Verify refresh during processing remains stable.
- [x] Delete a Conversion; URL becomes 404 and sidebar updates.
- [x] Tab through home and conversion pages; all interactive elements have visible focus rings. Exception: focus-ring styles and semantic controls were verified in implementation; exhaustive screen-reader/mobile keyboard matrix was not executed.

## 9. Done Definition

- [x] Every non-deployment item above is checked or has a short written exception.
- [x] Every MVP unit, feature, and architecture test passes locally.
- [x] Browser/UX MVP cases have been executed by the coding agent in Chrome using Playwright non-scripted interaction.
- [x] Local verification commands pass.
- [x] Manual QA items are checked or have a short written exception.
- [x] `specs/specification.md`, `specs/testplan.md`, and this checklist agree on routes, statuses, storage names, command names, config keys, and scope.
- [x] No implementation drift exists against the session-only temporary-conversion model.
