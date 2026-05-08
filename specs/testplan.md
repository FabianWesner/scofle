# Test Plan: Image-to-PowerPoint Web App

> Aligned with `specs/specification.md`. The session-only temporary-conversion model is authoritative. Earlier project/slug/share-link decisions in brainstorming are historical and superseded.

## Goals

1. Prove the local MVP converts images into downloadable `.pptx` and `.pdf` artifacts.
2. Prove every conversion read, action, preview, and download is scoped to the current `image2pptx_session`.
3. Prove temporary files are removed by delete, retention, and disk-ceiling cleanup.
4. Prove unsupported input and bridge failures produce stable, non-leaking user-facing errors.
5. Prove the UI contains no public-share, copy-link, or project-access language.

## Test Layers

- **L1 Pest feature tests** (`tests/Feature/`): Laravel HTTP, queue dispatch, route authorization, storage, downloads, lifecycle, and validation.
- **L2 Pest unit tests** (`tests/Unit/`): state transitions, filename/path helpers, failure-code mapping, and pure validation helpers.
- **L3 Architecture tests** (`tests/Feature/ArchitectureTest.php`): static checks for unsafe process APIs, telemetry, public storage routes, and forbidden project/share-link code paths.
- **L4 Non-scripted Playwright Chrome QA**: manual browser interaction through Playwright using the local Herd URL.
- **L5 Local verification commands**: linting, types, tests, scheduler, warm-model check, and Gemini grep gate.
- **L6 Manual QA**: office-suite compatibility and mobile/desktop browser spot checks.

Tags:

| Tag | Meaning |
|---|---|
| `MVP` | Must pass before the local MVP is considered done. |
| `manual` | Must be checked manually or recorded as a narrow exception. |
| `best-effort` | Host-dependent case; skipped with an explanation when the host cannot enforce it. |

## 1. Happy Path

### HP-01 First Upload Creates A Session Conversion

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Fresh browser session. Queue worker available. LibreOffice and warmed Python models available.
- **Steps:** Visit `/`, upload a valid PNG, submit.
- **Expected:**
  - `image2pptx_session` cookie is set as HttpOnly and SameSite=Lax.
  - A `sessions` row, `conversions` row, and first `attempts` row are created.
  - Temporary input is stored under `storage/app/private/tmp/sessions/{session_id}/conversions/{uuid}/attempts/1/input.{ext}`.
  - Browser redirects to `/conversions/{uuid}`.
  - Page shows input preview, running state, and recent session conversions.
  - Status progresses `pending -> running -> ready`.
  - Inline PDF preview renders when `pdf_bytes` exists.
  - `.pptx` and `.pdf` download controls are enabled when ready.
  - HTML responses include frame-denial headers.
  - No public-share, copy-link, slug, or "project" access copy appears.

### HP-02 Downloads Are Signed And Session-Owned

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Ready Attempt from HP-01.
- **Steps:** Request `.pptx`, `.pdf`, and inline PDF routes.
- **Expected:**
  - Valid signed URL plus owning session returns 200.
  - Download content types are correct.
  - Filenames are ASCII and server-derived: `conversion-{uuid_prefix}-a1.{pptx|pdf}`.
  - Download responses include `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, and `X-Content-Type-Options: nosniff`.
  - Inline PDF adds `Content-Disposition: inline` and `Content-Security-Policy: sandbox`.
  - Missing, tampered, or expired signatures fail.
  - Valid signed URL with a different session returns 404.

### HP-03 Regenerate Creates A New Attempt

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Conversion with a ready first Attempt.
- **Steps:** Click or POST `Regenerate`.
- **Expected:**
  - New Attempt row is created with `n=2` and `pending`.
  - Same source image is reused.
  - Queue job is dispatched.
  - Attempt status progresses to `ready` or a stable failure.
  - Attempt switcher exposes `a1` and `a2`.
  - Max Attempts per Conversion is enforced and does not evict in-flight Attempts.

### HP-04 Uploading Another Image Creates Another Conversion

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Existing Conversion in the session.
- **Steps:** Return to `/`, upload a different valid image.
- **Expected:**
  - A new Conversion is created in the same session.
  - The existing Conversion is not mutated.
  - Sidebar lists both Conversions newest-first.
  - Each URL is accessible only with the owning session cookie.

### HP-05 Refresh And Browser Session Continuity

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Two Conversions in one session.
- **Steps:** Hard refresh `/` and `/conversions/{uuid}`.
- **Expected:**
  - Same session cookie is reused.
  - Recent Conversions remain visible for this browser session.
  - Polling resumes for pending/running Attempts.
  - Refresh during upload or retry with the same nonce does not create duplicate Conversions.

### HP-06 URL Alone Grants No Access

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Capture `/conversions/{uuid}` from one browser session.
- **Steps:** Open the URL in a cookie-less context and in a different session.
- **Expected:**
  - Both requests return 404.
  - No Conversion metadata, filenames, image preview, artifact bytes, or status leaks.
  - No UI suggests the link can be shared or used as access.

### HP-07 Delete Conversion Removes Temporary Data

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Conversion with at least one Attempt and generated files.
- **Steps:** Delete the Conversion.
- **Expected:**
  - DELETE redirects to `/`.
  - Conversion and Attempt rows are deleted.
  - Temporary conversion directory is recursively removed.
  - Sidebar updates.
  - Deleted URL returns 404.

### HP-08 Reaper Removes Old Or Excess Temporary Data

- **Tag:** `MVP`. **Layer:** L1.
- **Precondition:** Old Conversions and byte ceiling configured low for the test.
- **Steps:** Run `php artisan conversions:reap`.
- **Expected:**
  - Conversions older than `config('conversion.ttl_hours')` are deleted, anchored on `conversions.created_at`.
  - Byte ceiling eviction deletes oldest evictable Conversions until under `config('conversion.tmp_bytes_cap')`.
  - Pending/running Attempts are skipped.
  - Stale running Attempts are marked interrupted before cleanup.
  - Logs include deletion and skip counts.

### HP-09 Configured Temporary-Retention Copy

- **Tag:** `MVP`. **Layer:** L1, L4.
- **Precondition:** Override `config('conversion.ttl_hours')` to a test value.
- **Steps:** Visit `/` and a Conversion page.
- **Expected:** UI copy reflects the configured hour value and states that downloads must be saved before temporary cleanup.

## 2. Input Edge Cases

### EC-01 Long Edge Above Limit

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** PNG or JPEG with long edge > 4096 px.
- **Expected:** 422 validation error, no Conversion, no temp file, no queue job.

### EC-02 Total Pixels Above Limit

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** Image with total pixels > 16,777,216 but acceptable byte size.
- **Expected:** 422 validation error naming the pixel cap.

### EC-03 Byte Cap Above Limit

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** PNG or JPEG over `config('upload.max_bytes')`.
- **Expected:** 422 validation error, no temp file, no queue job.

### EC-04 Supported PNG With Alpha

- **Tag:** `MVP`. **Layer:** L1, L6.
- **Input:** Transparent PNG.
- **Expected:** Conversion succeeds; Python bridge flattens alpha to white; generated deck does not contain black/grey transparency artifacts.

### EC-05 CMYK JPEG

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** CMYK JPEG.
- **Expected:** Conversion succeeds; normalized input is RGB.

### EC-06 EXIF Rotation

- **Tag:** `MVP`. **Layer:** L1, L6.
- **Input:** JPEG with EXIF orientation.
- **Expected:** Normalized input bakes in rotation and output orientation matches user-visible source orientation.

### EC-07 Unsupported Formats

- **Tag:** `MVP`. **Layer:** L1.
- **Inputs:** WebP, SVG, GIF, HEIC, TIFF, BMP.
- **Expected:** 422 with format-specific message; no temp artifact; no queue job.

### EC-08 Extension/MIME Mismatch

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** JPEG bytes uploaded as `.png`, or PNG bytes uploaded as `.jpg`.
- **Expected:** 422 error for content/extension mismatch.

### EC-09 Corrupt Or Empty File

- **Tag:** `MVP`. **Layer:** L1.
- **Input:** Empty file or truncated image.
- **Expected:** Stable validation or bridge failure. Raw parser stderr is never shown to the user.

## 3. Conversion Failures

### CF-01 Bridge Timeout

- **Tag:** `MVP`. **Layer:** L1.
- **Setup:** Fake bridge sleeps past timeout.
- **Expected:** Attempt becomes `failed`, `failure_code='bridge_timeout'`, process group is terminated, user message is stable.

### CF-02 Bridge Non-Zero Exit

- **Tag:** `MVP`. **Layer:** L1.
- **Setup:** Fake bridge exits non-zero with stderr.
- **Expected:** Attempt becomes `failed`, `failure_code='bridge_error'`, stderr is written to `job.log`, raw stderr is not echoed.

### CF-03 Invalid PPTX Output

- **Tag:** `MVP`. **Layer:** L1.
- **Setup:** Fake bridge writes empty or invalid `.pptx`.
- **Expected:** Attempt becomes `failed` with a stable failure code; no download links are produced.

### CF-04 PDF Render Failure

- **Tag:** `MVP`. **Layer:** L1.
- **Setup:** Fake LibreOffice failure after valid `.pptx`.
- **Expected:** Attempt remains `ready`, `.pptx` is downloadable, PDF preview/download unavailable, UI shows derived `partial` state.

### CF-05 Disk Write Failure

- **Tag:** `MVP`. **Layer:** L1.
- **Setup:** Fake storage write failure.
- **Expected:** Attempt fails with disk-related code, partial files are cleaned up, user message does not expose filesystem paths.

### CF-06 OOM Kill

- **Tag:** `best-effort`. **Layer:** L1.
- **Setup:** Host can enforce memory cap and provoke exit 137.
- **Expected:** Attempt fails with `failure_code='oom'`; no orphaned worker processes remain. Skip with reason on hosts without enforcement.

## 4. Security And Abuse

### SEC-01 Current Session Required Everywhere

- **Tag:** `MVP`. **Layer:** L1.
- **Expected:** Conversion show, attempt show, regenerate, delete, preview, and downloads all require matching session ownership.

### SEC-02 CSRF And Origin Checks

- **Tag:** `MVP`. **Layer:** L1.
- **Expected:** Upload, regenerate, and delete reject missing CSRF token and hostile Origin/Referer.

### SEC-03 Rate Limits And Queue Cap

- **Tag:** `MVP`. **Layer:** L1.
- **Expected:** Upload bucket enforces 5 per IP per 15 minutes; conversion-read bucket enforces 60 GETs per IP per minute; queue cap rejects new work beyond 50 pending jobs.

### SEC-04 Per-Session Concurrency

- **Tag:** `MVP`. **Layer:** L1.
- **Expected:** A session with a pending/running Attempt cannot create another pending/running Attempt via upload or regenerate.

### SEC-05 Private Storage Only

- **Tag:** `MVP`. **Layer:** L1, L3.
- **Expected:** No public storage symlink or static route can serve artifacts. Downloads go through controller routes only.

### SEC-06 Static Unsafe-Code Gates

- **Tag:** `MVP`. **Layer:** L3, L5.
- **Expected:** Tests and `bin/grep-no-gemini.sh` block Gemini/API code paths, telemetry SDK imports, unsafe shell APIs, accepted `.pptx` uploads, and project/share-link routes or UI.

## 5. Frontend And Browser QA

### UI-01 Home Page

- **Tag:** `MVP`. **Layer:** L4.
- **Expected:** Drag/drop and file picker work; accepted-format and temporary-retention copy is visible; unsupported files show clear client-side feedback while preserving server validation.

### UI-02 Conversion Page States

- **Tag:** `MVP`. **Layer:** L4.
- **Expected:** Pending/running skeleton, ready preview, partial PDF failure, failed conversion, slow/offline polling, rate-limited, delete-confirm, and empty recent states render without overlap or console errors.

### UI-03 Keyboard And Focus

- **Tag:** `MVP`. **Layer:** L4.
- **Expected:** Upload, regenerate, downloads, attempt switcher, new-image, and delete flows are keyboard reachable with visible focus rings.

### UI-04 Example Images Final Pass

- **Tag:** `MVP`. **Layer:** L4, L6.
- **Input:** `/Users/fabianwesner/Workspace/image-to-powerpoint/examples/img.png` and `/Users/fabianwesner/Workspace/image-to-powerpoint/examples/draft.png`.
- **Expected:** Both images upload, convert, preview, download, survive refresh in the same session, and are inaccessible from a different session.

## 6. Local Verification Commands

Run from the repo root before declaring the local MVP done:

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

## 7. Manual QA

- Upload both example images and verify `.pptx` and `.pdf` artifacts are produced.
- Open a generated `.pptx` in Microsoft PowerPoint, Apple Keynote, and Google Slides.
- Test desktop Chrome, desktop Firefox, desktop Safari, iOS Safari, and Android Chrome where practical.
- Confirm temporary-retention language does not imply permanent storage.
- Confirm no page exposes public share/copy-link/project-access language.
- Confirm deleting a Conversion removes files and makes the URL 404.
