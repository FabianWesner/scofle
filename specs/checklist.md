# Completion Checklist: Image-to-PowerPoint MVP

Use this checklist to decide whether the local MVP is done. Deployment to a remote server is explicitly out of scope, but local system checks, build checks, automated unit/feature/architecture tests, non-scripted Playwright Chrome QA, and manual QA are in scope.

Before marking an item complete, confirm the implementation matches `specs/specification.md` and the corresponding `MVP` cases in `specs/testplan.md`.

## 1. Product Flow

- [ ] Home page renders with a drag-and-drop/file-picker upload area, recent-projects sidebar, configured TTL copy, accepted-format copy, and slug-access warning.
- [ ] Valid PNG upload creates a Project and Version, stores the input under `storage/app/private/projects/{uuid}/versions/1/input.{ext}`, redirects to `/projects/{uuid}`, and shows the input preview.
- [ ] Project page shows input preview, output area, version switcher, regenerate, replace-image, copy-link, delete, and download controls.
- [ ] Conversion status progresses through `pending -> running -> ready|failed`; no other persisted status values exist.
- [ ] Inline PDF preview renders for ready Versions with `pdf_bytes` present.
- [ ] Derived `partial` UI state works for `status='ready'`, `failure_code='pdf_render'`, `pdf_bytes IS NULL`; `.pptx` remains downloadable.
- [ ] Regenerate creates a new Version from the latest non-failed input.
- [ ] Replace image creates a new Version on the same Project with a new per-Version input copy.
- [ ] Deep links `/projects/{uuid}` and `/projects/{uuid}/v/{n}` render the expected Version.
- [ ] Cookie-less slug access works; sidebar remains session-scoped.
- [ ] Copy-link button copies the canonical project URL and shows a toast.
- [ ] Delete project removes DB rows and all files under `storage/app/private/projects/{uuid}/`.

## 2. Data, Storage, and Lifecycle

- [ ] Migrations, models, factories, and relationships exist for `projects`, `versions`, `sessions`, `session_projects`, and `upload_nonces`.
- [ ] `projects.total_bytes` counts input, pptx, pdf, log, and metadata files for all non-evicted Versions.
- [ ] `versions` rows include the fields required by the spec, including `input_mime`, `input_ext`, `heartbeat_at`, byte counts, hashes, and failure fields.
- [ ] Session cookie is named `image2pptx_session`, is HttpOnly, Secure, SameSite=Lax, has a long max-age, and maps to a server-side session row.
- [ ] Per-upload nonce is consumed atomically and prevents duplicate Projects on refresh or double-submit.
- [ ] Max 5 Versions per Project is enforced; oldest evictable Version is deleted with its files.
- [ ] Max 20 Projects per session is enforced; oldest evictable Project is deleted with its files.
- [ ] `php artisan projects:reap` deletes Projects past `config('project.ttl_days')`, anchored on `projects.created_at`.
- [ ] Reaper applies `config('project.tmp_bytes_cap')` oldest-Project-first before TTL cleanup.
- [ ] Reaper skips Projects with `pending` or `running` Versions and logs skips/deletions.

## 3. Validation and Conversion

- [ ] Upload validation follows spec order: web cap, Laravel file rules, `finfo` sniff, extension/MIME agreement, `getimagesize` dimensions.
- [ ] Only PNG and JPEG are accepted; WebP, SVG, GIF, HEIC, TIFF, BMP, empty files, corrupt files, and extension/MIME mismatches produce specific errors.
- [ ] Byte cap defaults to 10 MiB and pixel caps default to long edge `<= 4096`, total pixels `<= 16,777,216`.
- [ ] Python venv exists under `storage/app/python-venv/` with pinned dependencies and pinned `px-image2pptx` `[ocr,inpaint]` install.
- [ ] `.python-version` pins a supported Python 3.9 to 3.13 version.
- [ ] `php artisan ppt:warm-models` warms PaddleOCR and big-lama models using a tiny fixture.
- [ ] Bridge invocation uses Symfony Process argv only: `px-image2pptx <input> -o <output> --lang auto --max-inpaint-size 2048`.
- [ ] No `shell_exec`, `system`, raw `proc_open`, or `Process::fromShellCommandline` is used outside an explicit allow-list.
- [ ] Python wrapper normalises via Pillow: decode probe, EXIF rotation, alpha flatten to white, CMYK-to-sRGB conversion.
- [ ] Process supervision enforces 90-second wall-clock timeout and kills the process group.
- [ ] Worker writes `heartbeat_at` while running and marks stale running jobs as `interrupted` on boot.
- [ ] Bridge output is validated: empty or invalid `.pptx` fails with the proper failure code.
- [ ] LibreOffice renders PDF from the generated `.pptx`; PDF failure leaves the Version ready with `failure_code='pdf_render'`.
- [ ] PPTX metadata is sanitised to the fixed author string and does not leak filename, EXIF comments, OS user, or host name.

## 4. Security and Abuse Controls

- [ ] Upload, regenerate, replace-image, and delete routes require CSRF and pass Origin/Referer checks.
- [ ] Download routes use Laravel signed URLs; missing, tampered, expired, or cross-Version signatures return 403.
- [ ] Downloads are served from controller routes only; private storage is never web-accessible or symlinked.
- [ ] Download filenames are server-derived ASCII: `project-{uuid_prefix}-v{n}.{pptx|pdf}`.
- [ ] Every HTML response sets `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`.
- [ ] Download responses set `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, and `X-Content-Type-Options: nosniff`.
- [ ] Inline PDF responses use `Content-Disposition: inline` and `Content-Security-Policy: sandbox`.
- [ ] Upload rate bucket enforces 5 uploads per IP per 15 minutes.
- [ ] Project-read bucket enforces 60 GETs per IP per minute on `/projects/{uuid}*`.
- [ ] Per-session concurrency allows only one `pending` or `running` conversion.
- [ ] Global queue depth cap rejects uploads beyond 50 pending jobs with 503.
- [ ] `bin/grep-no-gemini.sh` fails on `gemini`, `googleapis`, or `generativeai` inside the installed package.
- [ ] Architecture tests confirm no third-party telemetry SDK imports.

## 5. Frontend Quality

- [ ] UI follows existing Inertia React, Wayfinder, Tailwind v4, and Shadcn conventions.
- [ ] Wayfinder route helpers are used instead of hardcoded Laravel URLs in React code.
- [ ] Upload, pending/running, ready, partial, failed, rate-limited, offline, slow-network, and delete-confirm states are implemented.
- [ ] Polling runs every 2 seconds while pending/running, resumes after refresh, and stops after 3 minutes with a stable message.
- [ ] Browser back/refresh/double-submit flows do not create duplicate Projects.
- [ ] Dragging multiple files, folders, and unsupported files gives clear client-side messages while preserving server-side validation.
- [ ] Baseline accessibility is met: semantic controls, visible focus rings, keyboard reachability, non-empty alt text on previews.
- [ ] No in-app text claims files last longer than the configured TTL.
- [ ] No deck editing, login, email/share-to-X, slide editor, local LLM, user-supplied `.pptx` import, i18n, or third-party telemetry has been added.

## 6. Automated Tests

- [ ] All unit, feature, and architecture `MVP` tests from `specs/testplan.md` are implemented or explicitly represented by a narrower equivalent test with the same assertion.
- [ ] `post-MVP`, `manual`, `deploy`, and `best-effort` cases are tagged/skipped correctly and do not create false MVP failures.
- [ ] Feature tests cover happy upload, downloads, regenerate, replace-image, slug-only access, delete, validation failures, lifecycle, rate limits, signed downloads, and failure-code mapping.
- [ ] Unit tests cover validation helpers, filename sanitisation, Version state transitions, and normalisation helpers where applicable.
- [ ] Architecture tests cover forbidden process APIs, signed download middleware, no accepted `.pptx` upload routes, and telemetry absence.
- [ ] Deploy-check scripts are present for local verification, but remote deployment is not required for this checklist.

## 6a. Non-Scripted Playwright Chrome QA

- [ ] No scripted browser/e2e suite has been added (`tests/Browser`, Playwright Test specs, Cypress specs, or equivalent).
- [ ] The coding agent has opened the local app in Chrome through Playwright and executed the browser/UX `MVP` cases from `specs/testplan.md` non-scriptedly.
- [ ] Playwright Chrome QA covers upload, polling, regenerate, replace-image, version switching, copy-link, refresh/back/double-submit behavior, delete confirmation, PDF preview, focus rings, and console-error checks.
- [ ] Offline/slow-network states have been checked in Playwright Chrome where practical, or documented as a manual exception.
- [ ] Results of the Playwright Chrome QA pass are summarized in the Goal progress log or final response.

## 7. Local Verification Commands

Run these from the repo root before declaring the goal done:

```bash
composer run lint:check
npm run lint:check
npm run format:check
npm run types:check
php artisan test
php artisan schedule:list
php artisan ppt:warm-models --check-only
bin/grep-no-gemini.sh
```

If any command is unavailable because its implementation belongs to the MVP, create it before marking this checklist complete. If a host-dependent check is not runnable locally, document the reason in the Goal progress log and keep the related test tagged `best-effort` or `deploy` as specified in `specs/testplan.md`.

## 8. Manual QA

- [ ] Upload a real iPhone photo (4032x3024, portrait); EXIF rotation and slide orientation are correct.
- [ ] Upload a real screenshot of a presentation slide; resulting pptx has selectable reconstructed text where the library supports it.
- [ ] Open a generated pptx in Microsoft PowerPoint, Apple Keynote, and Google Slides.
- [ ] Test on iOS Safari, Android Chrome, desktop Chrome, desktop Firefox, and desktop Safari with no console errors.
- [ ] Verify transparent PNG flattening does not create black/grey backgrounds in PowerPoint, Keynote, or Google Slides.
- [ ] Verify CMYK JPEG conversion looks plausible and normalised input is RGB.
- [ ] Refresh project page repeatedly during processing; polling remains stable.
- [ ] Delete a Project; URL becomes 404 and sidebar updates.
- [ ] Copy-link works in supported browsers.
- [ ] Tab through home and project pages; all interactive elements have visible focus rings.

## 9. Done Definition

The local MVP is done when:

- [ ] Every non-deployment item above is checked.
- [ ] Every `MVP` unit, feature, and architecture test passes locally.
- [ ] Browser/UX `MVP` cases have been executed by the coding agent in Chrome using Playwright non-scripted interaction.
- [ ] Local verification commands pass, except host-dependent `deploy` or `best-effort` checks that are documented as skipped.
- [ ] Manual QA items are either checked or have a short written exception.
- [ ] `specs/specification.md`, `specs/testplan.md`, and this checklist agree on routes, statuses, storage names, command names, config keys, and scope.
- [ ] No implementation drift exists against the explicit anti-roadmap.
