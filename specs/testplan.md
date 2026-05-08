# Test Plan: Image-to-PowerPoint Web App

> Aligned with `specs/specification.md` (canonical decisions table in section 2 of that file).
> Cross-references the locked agreements A1 through A37 in `specs/brainstorming.md`.

## Goals

1. Cover the full happy path so a fresh deploy is provably user-ready.
2. Exercise every failure mode that a real (non-malicious) user can hit.
3. Block every abuse vector listed in the threat model. Each abuse case has an explicit assertion.
4. Make every layer of the layered defences from spec section 7.1 independently testable. A passing test must mean the layer it targets actually fired, not that an outer layer caught the input first.
5. Leave a manual QA checklist for things that cannot be automated cheaply (visual fidelity, real-browser PDF preview, EXIF in real phone photos).

## Test taxonomy (every case below carries one tag)

| Tag | Meaning |
|---|---|
| `MVP` | Must pass before MVP ships. Implemented as Pest feature, browser, unit, or arch test as marked on the case. CI gate. |
| `post-MVP` | Wired in the test layer indicated but currently skipped or asserts "deferred" behaviour. Reactivated when the corresponding feature lands. |
| `deploy` | Run only by `bin/verify-deploy.sh` (or the equivalent artisan command) at deploy time. Not in the standard `php artisan test` run. |
| `manual` | Cannot be cheaply automated; lives in the manual QA checklist (section 6). |
| `best-effort` | Asserts something that depends on the host (OOM kill, network namespace, timing channel). Skipped when the host can't enforce it. CI logs the skip; never fails the suite for missing hosts. |

## Test layers

- **L1 Pest feature tests** (`tests/Feature/`). Hit Laravel HTTP, queue, scheduler, and the Python bridge with a real fixture image where the test does not need the full ML pipeline. Use the bridge for happy-path conversion tests; mock for upstream-only logic. Integration tests must hit a real database (SQLite test connection), not mocks.
- **L2 Pest browser tests** (`tests/Browser/`, Pest 4 browser plugin). Real browser exercising upload form, polling, refresh-during-upload, back button, double-submit, copy-link, delete-project flow.
- **L3 Pest unit tests** (`tests/Unit/`). Pure functions: filename sanitiser, EXIF rotation helper, Version state-transition guard, hash-collision detector if A29 ships.
- **L4 Architecture tests** (`tests/Arch/`). Static guard: forbid `shell_exec`, `system`, raw `proc_open`, string concatenation into Symfony Process arguments anywhere outside an allow-list, telemetry SDK imports.
- **L5 Deploy verification** (`bin/verify-deploy.sh` or an artisan command, run as a final step in CI/CD).
- **L6 Manual QA checklist**.

---

## 1. Happy path scenarios

### HP-01 First upload, single PNG
- **Tag:** MVP. **Layer:** L2 browser, L1 feature.
- **Precondition:** Fresh session (no cookies). LibreOffice present. Models pre-warmed (A12).
- **Steps:**
  1. Visit `/`.
  2. Drag-and-drop a 1024x768 PNG (~600 KB) onto the dropzone.
  3. Submit.
- **Expected:**
  - HTTP 302 to `/projects/{uuid}` (Inertia redirect).
  - Project page shows input image preview on the left, `running` skeleton on the right.
  - Frontend polls every 2 s. Within 30 s, status flips to `ready`.
  - Inline PDF preview renders.
  - "Download .pptx" and "Download .pdf" buttons enabled.
  - Sidebar shows the new project at the top.
  - Project page banner shows: "Anyone with this link can open this project."
  - HttpOnly Secure SameSite=Lax cookie `image2pptx_session` set with a long random opaque token (A24).
  - Every HTML response carries `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`.

### HP-02 Download .pptx and .pdf
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** HP-01 completed; Version is `ready`.
- **Steps:**
  1. Click "Download .pptx".
  2. Click "Download .pdf".
- **Expected:**
  - 200 OK on both routes.
  - `Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation` and `application/pdf` respectively.
  - `Content-Disposition: attachment; filename="project-{uuid_prefix}-v1.pptx"` (A16). ASCII-only, server-derived, NOT echoing the user's original filename.
  - Headers include `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`.
  - Inline PDF preview path additionally has `Content-Disposition: inline` and `Content-Security-Policy: sandbox`.

### HP-03 Regenerate produces a new Version
- **Tag:** MVP. **Layer:** L1 feature, L2 browser.
- **Precondition:** Project with v1 ready.
- **Steps:** Click "Regenerate".
- **Expected:**
  - New job dispatched on the queue.
  - v2 row created with status `pending`, then `running`, then `ready`.
  - v2 always created even if produced bytes match v1 (A29 dedup deferred).
  - Up to 5 Versions before eviction (A7).

### HP-04 Re-upload becomes a new Version on the existing Project
- **Tag:** MVP. **Layer:** L1 feature, L2 browser.
- **Precondition:** Project with v1 ready.
- **Steps:** Click "Replace image"; upload a different valid PNG.
- **Expected:**
  - v2 row created on the SAME Project (same UUID slug), not a new Project.
  - Storage layout: `versions/2/input.{ext}` plus `output.pptx`, `output.pdf`, `job.log`, `meta.json`. Per-Version input copy.
  - Sidebar continues to show the Project at the top.

### HP-05 Sidebar persists across refresh
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Two Projects created in this session.
- **Steps:** Hard refresh.
- **Expected:** Sidebar shows both Projects newest-first. Same `image2pptx_session` cookie value reused.

### HP-06 Sidebar persists across browser restart
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** One Project created.
- **Steps:** Close and reopen the browser. Cookie has a 10-year max-age, so it survives.
- **Expected:** Sidebar still shows the Project.

### HP-07 Direct slug access without cookie (A3, A24)
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** A Project URL captured from one session.
- **Steps:** Open the same URL in a private window (no cookie).
- **Expected:**
  - 200 OK; project page renders.
  - Sidebar in the private window does NOT show this Project.
  - Banner "Anyone with this link can open this project." still visible.

### HP-08 Delete project
- **Tag:** MVP. **Layer:** L1 feature, L2 browser.
- **Precondition:** Project with at least one Version.
- **Steps:** Click "Delete project". Confirm in modal.
- **Expected:**
  - DELETE returns 302 to `/`.
  - DB rows deleted: `projects`, `versions`, `session_projects` for this project.
  - Disk: `storage/app/private/projects/{uuid}/` recursively deleted.
  - Sidebar updates; project gone.

### HP-09 Hourly reaper deletes Project past TTL
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Project with `created_at` set to `now - (config('project.ttl_days') + 1) days` via factory.
- **Steps:** Run `php artisan projects:reap`.
- **Expected:**
  - Project artifacts removed from disk.
  - DB rows deleted.
  - Log line written with project UUID, age in days, bytes freed.
  - Reaper anchors on `created_at`, NOT `updated_at`. (Asserted by also touching `updated_at` and verifying the row is still reaped.)

### HP-10 UI TTL copy reflects configured value
- **Tag:** MVP. **Layer:** L1 feature, L2 browser.
- **Precondition:** Override `config('project.ttl_days')` to 14 in the test container.
- **Steps:** Visit `/` and a project page.
- **Expected:** Home footer reads "Files are deleted 14 days after upload." NOT a hardcoded "7 days".

### HP-11 Copy-link button copies the slug URL
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** On a project page, click "Copy link".
- **Expected:** Clipboard contains the absolute URL `{APP_URL}/projects/{uuid}`. Toast "Link copied". Asserted via clipboard read in browser test where supported.

---

## 2. Edge cases (legitimate user, unhappy paths)

### EC-01 Huge dimensions (4097 px on long edge)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** PNG just over the long-edge limit (4097x100), file size under the byte cap.
- **Expected:** 422 Unprocessable Entity. Error message identifies the dimension cap. No file written. No Python invoked.

### EC-02 Total pixels just over 16,777,216
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A 4096x4097 image (16,781,312 pixels). Long edge ok, total over.
- **Expected:** 422 with pixel-count error. Distinguishable from EC-01.

### EC-03 Tiny image (1x1 px PNG)
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 200 OK, conversion runs. Output may be visually empty but pptx is valid (asserted via `python-pptx` round-trip on the result).

### EC-04 EXIF orientation 6 (rotate 90 CW)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A portrait JPEG with EXIF Orientation 6.
- **Expected:**
  - On-disk `versions/1/input.jpg` is post-rotated; orientation 1 or no Orientation tag.
  - The pptx slide's rendered orientation matches what the human sees in the source file.

### EC-05 Transparency in PNG (FLATTEN to white)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** PNG with alpha channel (RGBA).
- **Expected:**
  - Conversion succeeds.
  - Normalisation flattens alpha to white before the bridge runs.
  - On-disk normalised input: `Image.open(path).mode in {"RGB", "L"}`. No alpha.
  - The image embedded in the resulting pptx slide also has no alpha channel.

### EC-06 CMYK JPEG (CONVERT to sRGB)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A JPEG in CMYK colour space.
- **Expected:**
  - Conversion succeeds.
  - Normalisation converts CMYK to sRGB via Pillow `Image.convert("RGB")`.
  - On-disk normalised input: `mode == "RGB"`. CMYK absent.
  - Pixel sample within tolerance of expected RGB conversion.

### EC-07 Animated GIF
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422 at validation (layer 3 / sniff). Error: "GIF is not supported. Use PNG or JPEG."

### EC-08 SVG upload
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422. Error names SVG explicitly.

### EC-09 HEIC upload
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422. Error names HEIC.

### EC-10 WebP upload
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422. Error: "WebP is not supported. Convert to PNG or JPEG."

### EC-11 TIFF and BMP uploads
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422 each, format-specific error.

### EC-12 File renamed `.png` but is actually JPEG
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** 422 at the extension/MIME agreement layer (spec 7.1 layer 4). Error: "File contents do not match the file extension." This is the strict policy; sniff and extension must agree.

### EC-13 Corrupt PNG (truncated header)
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** Either `getimagesize` returns false and we 422 at validation, or the bridge's Pillow decode probe fails and the Version is marked `failed` with `failure_code='bridge_error'`. User-facing: "We could not convert this image."

### EC-14 Conversion timeout (`bridge_timeout`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Bridge is mocked to sleep 120 s; Symfony Process timeout 90 s.
- **Expected:**
  - Process group killed at 90 s.
  - Version row updated to `failed` with `failure_code='bridge_timeout'`.
  - User message: "Conversion took too long. Please try a smaller image."

### EC-15 Bridge non-zero exit (`bridge_error`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Bridge mocked to exit 1 with stderr.
- **Expected:**
  - `failed` with `failure_code='bridge_error'`.
  - Stderr captured to `versions/{n}/job.log`.
  - User-facing message stable; raw stderr NOT echoed.

### EC-15b Bridge OOM kill (`oom`)
- **Tag:** best-effort. **Layer:** L1 feature.
- **Precondition:** On Linux CI with memory cap enforcement (`ulimit -v` or `systemd-run --property=MemoryMax=`), a fixture that provokes the bridge to allocate beyond the cap. On macOS Herd or hosts without enforcement: skipped, logged.
- **Expected:**
  - Bridge exits with status 137 (signal 9).
  - Version `failed` with `failure_code='oom'`.
  - User message: "Conversion failed because the image was too complex. Try a simpler image."
  - `pgrep -f px-image2pptx` empty after the test.

### EC-16 Disk full during write (`disk_full`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Mock the storage filesystem to fail writes with ENOSPC.
- **Expected:**
  - Version `failed` with `failure_code='disk_full'`.
  - User message: "Server is out of space. Please try again later."
  - Partial files cleaned up (no half-written pptx left behind).

### EC-17 Reaper race against in-flight conversion
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Project past TTL but with a Version in `running` status.
- **Steps:** Run reaper.
- **Expected:** Project SKIPPED for that run. Log line "skipped, in-flight job".

### EC-18 Reaper byte-ceiling eviction
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Total project bytes exceed `config('project.tmp_bytes_cap')` (test-configured to a low value, e.g., 5 MB). Several Projects with varying `created_at`.
- **Steps:** Run reaper.
- **Expected:**
  - Oldest Project deleted first.
  - Reaper continues until total bytes <= cap.
  - In-flight Projects skipped even if oldest.
  - Log line per deletion includes byte count freed.

### EC-19 Per-Version input bytes counted in disk accounting
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Project with 5 Versions, each carrying a 9 MB input copy at `versions/{n}/input.jpg`.
- **Expected:** `projects.total_bytes` reflects the sum (>= 45 MB asserted). Reaper byte calculation walks Versions, not just outputs.

### EC-20 Concurrent uploads from one session
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Session has one in-flight conversion (`pending` or `running`).
- **Steps:** Submit second upload.
- **Expected:** 429 with body indicating "You already have a conversion in progress." Distinguishable from rate-limit 429.

### EC-21 Per-IP upload rate limit
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** 5 successful uploads from this IP in the last 15 minutes.
- **Steps:** 6th upload.
- **Expected:** 429, body indicates per-IP rate limit. Bucket: upload bucket (spec 8.5).

### EC-22 Global queue depth at 50
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** 50 jobs in `pending` status.
- **Steps:** New upload from a fresh session.
- **Expected:** 503 with body "Service is busy, try again in a moment."

### EC-23 Refresh during upload
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Upload in flight; no response yet.
- **Steps:** Browser refresh.
- **Expected:**
  - User lands on `/`.
  - The originally-submitted upload either completes server-side (sidebar shows it) or is aborted cleanly (no orphan Project, no orphan tmp file).
  - Per-upload nonce prevents a duplicate Project even if the user manually re-submits the same file.

### EC-24 Refresh on project page during processing
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Project page in `running` state; polling active.
- **Steps:** Refresh.
- **Expected:** Page rerenders; current Version state preserved; polling resumes.

### EC-25 Back button after upload submit
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Click browser back from a project page.
- **Expected:** Returns to `/` (or referrer). Re-forwarding does not resubmit; assert no duplicate Project.

### EC-26 Double-submit on upload form
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Click submit twice rapidly.
- **Expected:** Submit button disabled after first click. Per-upload nonce ensures only one Project created server-side.

### EC-27 Slow network (3G profile)
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Upload a 5 MB file under 3G throttle.
- **Expected:** Spinner remains; no client-side timeout. Eventual success or graceful failure with a "try again" CTA.

### EC-28 Offline at upload submit
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Take the network offline; click submit.
- **Expected:** Inertia surfaces a `networkError` event (v3 rename); form shows "You are offline. Reconnect and try again." Form state preserved.

### EC-29 Polling stops after total cap
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Job stuck in `running` (bridge mocked to never return).
- **Expected:** Frontend polling stops after 3 minutes. UI shows "Still working. Refresh to check again." No further requests fired.

### EC-30 Long session (cookie persists across simulated weeks)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Cookie set 30 days ago. Session record present in DB. All Projects under that session reaped at TTL.
- **Steps:** Visit `/`.
- **Expected:**
  - Cookie still accepted (10-year max-age).
  - Sidebar empty (Projects all reaped).
  - User can upload a new image.

### EC-31 Server restart with in-flight job (interrupted)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Version row with `status='running'` and `heartbeat_at` set to 10 minutes ago. Worker process killed.
- **Steps:** Restart queue worker.
- **Expected:**
  - On boot, the worker scans `running` rows with `heartbeat_at < now() - 90 s` and marks them `failed` with `failure_code='interrupted'`.
  - User message: "The conversion was interrupted. Please regenerate."
  - No automatic retry.
  - Any partial output files for that Version are cleaned up.

### EC-32 5 Versions per Project, 6th eviction
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Project with v1-v5 ready.
- **Steps:** Trigger regenerate to create v6.
- **Expected:**
  - v6 created.
  - v1 evicted: artifacts on disk gone, DB row gone.
  - In-flight (`pending` or `running`) Versions never evicted.

### EC-33 20 Projects per session, 21st eviction
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Session with 20 Projects.
- **Steps:** New upload (creates Project 21).
- **Expected:** Oldest Project evicted (artifacts + DB rows) before P21 is created. Sidebar reflects 20 entries.

### EC-34 Browser blocks third-party cookies
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Browser configured to block cookies on the test domain.
- **Expected:** Upload still works (first-party cookies). Sidebar may be empty across visits if rejected; the project URL still works.

### EC-35 Empty file submission
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** Submit a 0-byte file.
- **Expected:** 422 with format-validation error.

### EC-36 Filename with non-ASCII characters
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Upload `presentación.png` (valid PNG).
- **Expected:**
  - Conversion succeeds.
  - Display name on project page shows the original filename (UTF-8 preserved).
  - On-disk path is `versions/1/input.png`. UUID-only path components.
  - `Content-Disposition` filename: `project-{uuid_prefix}-v1.pptx` (ASCII-only, server-derived).

### EC-37 Filename containing path traversal sequences
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Upload `../../etc/passwd.png` (valid PNG by content).
- **Expected:**
  - On-disk path is `storage/app/private/projects/{uuid}/versions/1/input.png`. UUID-derived. The original filename never reaches the filesystem.
  - Display name on the project page sanitised (HTML-escaped).

### EC-38 Filename with shell metacharacters
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Upload `; rm -rf /.png` (valid PNG by content).
- **Expected:** Same as EC-37. Argv is array form (Symfony Process), no shell interpolation. L4 architecture test guards against any code path that could interpolate.

### EC-39 EXIF with embedded comment / IPTC payload
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** JPEG with a 200-char EXIF UserComment.
- **Expected:** Conversion succeeds. Output pptx and pdf metadata sanitised: no leak of EXIF UserComment, no OS user, no machine name.

### EC-40 Hash collision on regenerate output (DEFERRED)
- **Tag:** post-MVP. **Layer:** L1 feature.
- **MVP behaviour:** v2 always created (HP-03).
- **Post-MVP (when A29 ships):** byte-identical bridge output skips creating a new Version row; UI shows a non-dismissed "Regenerate produced the same output as v1, no new version created" banner.

### EC-41 Bridge produces empty file (`empty_output`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Bridge exits 0 but writes a 0-byte pptx.
- **Expected:** Version `failed` with `failure_code='empty_output'`. User message: "We could not convert this image." Empty file cleaned up.

### EC-42 Bridge produces invalid pptx (`invalid_pptx`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Bridge exits 0 but writes a non-zip (corrupt) file.
- **Expected:** Wrapper validates with `zipfile.ZipFile(p).testzip()`. On failure, Version `failed` with `failure_code='invalid_pptx'`.

### EC-43 LibreOffice fails (`pdf_render`, derived `partial`)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** px-image2pptx succeeds (pptx written). soffice mocked to exit 1.
- **Expected:**
  - pptx preserved on disk and reachable via download.
  - `pdf_bytes` stays NULL.
  - Version row: `status='ready'`, `failure_code='pdf_render'`. UI label `partial` derives from this row shape (per spec 5.5 derivation rule).
  - UI: pptx download button enabled. PDF download button disabled with tooltip "PDF rendering failed; the .pptx file is still available."
  - Inline PDF preview hidden, replaced by an explainer.

### EC-44 PHP/web-server upload caps higher than app cap (configuration drift)
- **Tag:** deploy. **Layer:** L5.
- **Precondition:** `php -i | grep upload_max_filesize`, `post_max_size`, web server `client_max_body_size` config.
- **Expected:** All three are >= 10 MiB and `client_max_body_size <= 11` MiB. If `post_max_size < upload_max_filesize`, fail. Detected by a deploy-time check; documented misconfiguration scenarios are surfaced.

---

## 3. Abuse and security cases

### AB-01 Oversized payload via raw HTTP (bypassing the form)
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** POST a 50 MiB body to the upload endpoint.
- **Expected:** Web-server caps reject at `client_max_body_size` (11 MiB). Layer 1 of the spec 7.1 chain.

### AB-02 Oversized payload at the byte-cap boundary
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** POST a 10.0 MiB valid PNG, then a 10.1 MiB valid PNG.
- **Expected:** 10.0 MiB accepted. 10.1 MiB rejected by Laravel `max:10240` rule (layer 2). Both sides of the boundary asserted.

### AB-03 Decompression bomb PNG (sub-byte-cap, gigapixel decompressed)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A 1 MiB PNG that decompresses to 30000x30000.
- **Expected:** `getimagesize` reads the header and rejects on dimension/pixel cap (layer 5). Bridge never invoked. Worker memory delta during the test asserted to stay under 50 MiB.

### AB-04 Decompression bomb at the Pillow layer (bypass scenario)
- **Tag:** best-effort. **Layer:** L1 feature.
- **Precondition:** A crafted file that passes `getimagesize` reporting modest dimensions but decompresses to gigapixel. Test what happens if it ever does.
- **Steps:** Force the Laravel validator to pass via test stub; let the bridge try to open the file.
- **Expected:** Pillow raises `Image.DecompressionBombError`. Bridge wrapper exits non-zero. Version `failed` with `failure_code='bridge_error'`. No OOM.

### AB-05 Polyglot file: valid PNG that is also a valid PHP script
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A file whose first bytes are a PNG header and whose tail contains `<?php phpinfo(); ?>`.
- **Steps:** Upload, then attempt to access the on-disk path via the web root.
- **Expected:**
  - Upload succeeds (it IS a valid PNG; sniff and extension agree).
  - `storage/app/private/...` is NOT in the document root, no PHP interpreter ever sees the file.
  - Direct URL like `/storage/private/...` returns 404.

### AB-06 SVG with embedded `<script>`
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** Upload an SVG with embedded JS.
- **Expected:** 422 at validation. The SVG is never written to disk.

### AB-07 ZIP-bomb pptx upload (future-proofing A35)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A ZIP-bomb pptx fixture.
- **Steps:** Attempt to upload through the image upload endpoint and any other endpoint that takes a file.
- **Expected:** 422 at every endpoint. No pptx-import path exists in MVP. Architecture test asserts no controller/route accepts a `.pptx` content type.

### AB-08 Path traversal in filename (full attack)
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** Upload with filename `../../../etc/passwd`.
- **Expected:** Server-derived UUID path. Asserted that `versions/1/input.png` is the only file written; nothing under `etc/`.

### AB-09 Shell injection via filename
- **Tag:** MVP. **Layer:** L1 feature, L4 arch.
- **Steps:** Upload with filename `; touch /tmp/pwned ;.png`.
- **Expected:** No `/tmp/pwned`. L4 grep-rejects `shell_exec(`, `system(`, `exec(`, `Process::fromShellCommandline(` outside an allow-list.

### AB-09b Bridge writes outside its working directory
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Mock bridge to attempt writes to `../../something` and to absolute paths outside the per-version work dir.
- **Expected:**
  - The PHP wrapper invokes the bridge with the per-version work dir as cwd and refuses to copy any output that resolves outside that dir.
  - Only `versions/{n}/{input.{ext}, output.pptx, output.pdf, job.log, meta.json}` exist on disk after the run.
  - Wrapper uses `realpath` and rejects escape attempts; on rejection the Version is `failed`.

### AB-10 CSRF on upload
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** POST to `/uploads` without `X-XSRF-TOKEN`.
- **Expected:** 419 Page Expired.

### AB-11 CSRF on delete-project
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** DELETE `/projects/{uuid}` without the CSRF token.
- **Expected:** 419.

### AB-11b CSRF on regenerate
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** POST `/projects/{uuid}/regenerate` without the CSRF token.
- **Expected:** 419. The endpoint also validates `Origin`/`Referer` and rejects cross-origin POSTs even with a stolen CSRF token.

### AB-11c CSRF and Origin on replace-image
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** POST `/projects/{uuid}/replace-image` (a) without CSRF token, (b) with token but with a foreign Origin header.
- **Expected:** 419 in case (a), 403 in case (b). Cookie alone never authorises a write.

### AB-12 Cross-origin iframe of any HTML page (clickjacking)
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** Inspect response headers on `/`, `/projects/{uuid}`, and `/projects/{uuid}/v/{n}`.
- **Expected:** Every HTML response has `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`. A real cross-origin iframe load is refused by the browser.

### AB-13 Slug enumeration via timing
- **Tag:** best-effort. **Layer:** L1 feature.
- **Steps:** Request `/projects/{nonexistent}`, `/projects/{recently-deleted}`, `/projects/{never-existed}` and measure wall-clock.
- **Expected:** Identical 404 body. Wall-clock difference within statistical noise. If the host is too noisy to assert this reliably (CI variance), the test logs a warning and skips the assertion; never fails the suite.

### AB-14 Slug brute-force (project-read bucket)
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** Make 100 GETs to random `/projects/{uuid}` from one IP in 60 seconds.
- **Expected:** After 60 requests in the minute, the project-read bucket returns 429. Distinct from the upload bucket.

### AB-15 Repeated rapid uploads from one IP
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** 6 valid uploads in 60 seconds from one IP.
- **Expected:** First 5 succeed; 6th 429.

### AB-16 Distributed uploads from many IPs
- **Tag:** manual. **Layer:** L6.
- **Expected:** Documented threat. Global queue depth cap protects the worker. The L1 feature variant of EC-22 covers the queue-depth side mechanically.

### AB-17 PDF preview with embedded JS (future-proofing)
- **Tag:** MVP. **Layer:** L1 feature.
- **Expected:** Inline PDF response carries `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff`.

### AB-18 Cookie theft via XSS
- **Tag:** MVP. **Layer:** L1 feature, L4 arch.
- **Expected:** `Set-Cookie: image2pptx_session=...; HttpOnly; Secure; SameSite=Lax`. Browser test runs `document.cookie` and asserts the session token is absent.

### AB-19 Egress check on Python bridge
- **Tag:** best-effort. **Layer:** L1 feature.
- **Precondition:** On Linux CI, run inside `unshare -n`. On hosts without namespace support: skipped, logged.
- **Expected:** Conversion succeeds offline. No DNS, no HTTP. Static-grep gate (DV-07) is the always-on substitute.

### AB-20 Static-grep deploy gate
- **Tag:** deploy. **Layer:** L5.
- **Steps:** `bin/grep-no-gemini.sh`.
- **Expected:** Exit 0 on a clean install. The gate is exercised by planting a temporary file with a banned string in a sidecar test fixture and asserting the script fails fast.

### AB-21 Polyglot pptx attempt
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** A `.pptx` file uploaded through any form field that takes file uploads.
- **Expected:** Rejected at every endpoint.

### AB-22 Cookie session reused across IPs (accepted threat)
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Session cookie issued from IP A is presented from IP B.
- **Expected:** Accepted. Documented as accepted threat: same as sharing the URL.

### AB-23 Telemetry SDK absence
- **Tag:** MVP. **Layer:** L4 arch.
- **Steps:** Grep the codebase for known telemetry SDK names (`@sentry/`, `mixpanel`, `segment`, `amplitude`, `posthog`, `google-generativeai`).
- **Expected:** Zero matches.

### AB-24 Process group kill on timeout
- **Tag:** MVP. **Layer:** L1 feature.
- **Precondition:** Mock bridge to spawn a child process and sleep.
- **Expected:** On timeout, both parent and child are SIGTERMed/SIGKILLed. `pgrep` shows zero matches after the timeout fires.

### AB-25 Signed download URL tampering
- **Tag:** MVP. **Layer:** L1 feature.
- **Steps:** (a) Request `GET /downloads/{version_id}/pptx` with no signature. (b) Tamper with the signature query parameter. (c) Replay an expired signature. (d) Use a signature minted for `version_id=A` to fetch `version_id=B`.
- **Expected:** All four return 403. Architecture test asserts the download controller routes through Laravel's signed-URL middleware.

---

## 4. Browser / UX edge cases

### UX-01 Drag-and-drop multiple files
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Drag two PNGs onto the dropzone.
- **Expected:** UI rejects with "Only one image per upload."

### UX-02 Drag a folder
- **Tag:** MVP. **Layer:** L2 browser.
- **Expected:** UI rejects with "Drag an image, not a folder."

### UX-03 Drag a non-image file
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Drag `report.pdf`.
- **Expected:** UI rejects client-side with "Only PNG and JPEG images are accepted." Server still validates if the user bypasses.

### UX-04 Copy-link button
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Click the "Copy link" button next to the slug-access banner.
- **Expected:** URL copied to clipboard; toast "Link copied".

### UX-05 Inline PDF preview renders
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Version `ready`.
- **Expected:** PDF iframe loads. No console errors.

### UX-06 Inline PDF preview blocked by browser
- **Tag:** manual. **Layer:** L6.
- **Precondition:** Browser has PDF inline disabled.
- **Expected:** Fallback: shows the .pdf download button prominently with "Your browser blocks inline PDF; download to view."

### UX-07 Visible focus rings (A33)
- **Tag:** MVP. **Layer:** L2 browser.
- **Steps:** Tab through every interactive element on `/` and on the project page.
- **Expected:** Focus ring visible on every focusable element.

### UX-08 Alt text on input preview (A33)
- **Tag:** MVP. **Layer:** L2 browser.
- **Expected:** `<img alt="Uploaded source image">` or similar; not empty.

### UX-09 Error states are non-dismissed
- **Tag:** MVP. **Layer:** L2 browser.
- **Precondition:** Version `failed`.
- **Expected:** Error banner persists across navigation within the project page until the user clicks "Try again" or regenerates.

### UX-10 No autoplay sound, no popups, no modals on landing
- **Tag:** MVP. **Layer:** L2 browser.

---

## 5. Deploy verification (L5)

### DV-01 LibreOffice present
- **Tag:** deploy.
- **Steps:** `which soffice`.
- **Expected:** Exit 0; binary executable. Deploy aborts otherwise with "LibreOffice not found. Install it before continuing."

### DV-02 LibreOffice produces a PDF
- **Tag:** deploy.
- **Steps:** `soffice --headless --convert-to pdf <fixture.pptx>`.
- **Expected:** Exit 0; PDF written.

### DV-03 Python version pinned (A36)
- **Tag:** deploy.
- **Steps:** Check `.python-version`. `python --version` matches and is in 3.9 to 3.13.

### DV-04 px-image2pptx installed at pinned version
- **Tag:** deploy.
- **Steps:** `pip show px-image2pptx | grep Version`. Matches the pin.

### DV-05 Only [ocr,inpaint] extras installed
- **Tag:** deploy.
- **Steps:** `pip list`; assert `google-generativeai` and similar LLM client packages absent.

### DV-06 Models pre-warmed
- **Tag:** deploy.
- **Steps:** `php artisan ppt:warm-models`.
- **Expected:** Exit 0; total time on warm run under 30 s. Files present at `~/.paddlex/official_models/PP-OCRv5_*` and `~/.cache/torch/hub/checkpoints/big-lama*`.

### DV-06b Models cache survives container restart
- **Tag:** deploy.
- **Steps:** Restart the worker after warm-up; run `php artisan ppt:warm-models --check-only`.
- **Expected:** Pre-warmed model files survive and are readable. If the deploy target uses an ephemeral writable scratch path, models live somewhere persistent (`storage/app/models/`) and `HOME`/`PADDLE_PDX_HOME`/`TORCH_HOME` env vars point there. Fails fast at deploy time if missing or read-only.

### DV-07 Static-grep gate
- **Tag:** deploy.
- **Steps:** Per AB-20.
- **Expected:** No match. Exit 0 only on clean.

### DV-08 Storage layout writable
- **Tag:** deploy.
- **Steps:** `storage/app/private/projects/` exists, mode 0750, writable by the worker user. No public symlink for `private/`.

### DV-09 Queue worker boots
- **Tag:** deploy.
- **Steps:** `php artisan queue:work --once`.
- **Expected:** Exit 0 (no jobs found, normal).

### DV-10 Scheduled command registered
- **Tag:** deploy.
- **Steps:** `php artisan schedule:list`.
- **Expected:** Output includes `projects:reap` running hourly.

### DV-11 Smoke test: end-to-end
- **Tag:** deploy.
- **Steps:** Upload a fixture image via HTTP, wait for `ready`, download pptx and pdf, delete project.
- **Expected:** All steps succeed within 60 s.

### DV-12 Pillow available in the bridge venv
- **Tag:** deploy.
- **Steps:** Inside `storage/app/python-venv/`, run `python -c "import PIL, sys; print(PIL.__version__)"`.
- **Expected:** Exit 0; version >= 10. Pillow handles every decode probe and normalisation step in spec 7.2.

### DV-13 PHP/web-server upload caps consistent (EC-44)
- **Tag:** deploy.
- **Steps:** Inspect `php -i` for `upload_max_filesize`, `post_max_size`, and the web server config for `client_max_body_size`.
- **Expected:** Web cap is 11 MiB; PHP caps are >= 10 MiB; `post_max_size >= upload_max_filesize`.

---

## 6. Manual QA checklist (L6)

- [ ] Upload a real iPhone photo (4032x3024, portrait). Verify EXIF rotation honoured and the slide displays portrait. Within the 4096 cap.
- [ ] Upload a real screenshot of a presentation slide. Verify text is selectable in the resulting pptx (not flattened).
- [ ] Open the resulting pptx in Microsoft PowerPoint, Apple Keynote, and Google Slides. Verify each opens and renders sensibly.
- [ ] Use a screen reader to navigate the project page. Note any clearly broken interactions (baseline-only check; A33).
- [ ] Visit on iOS Safari, Android Chrome, desktop Chrome, desktop Firefox, desktop Safari. No console errors.
- [ ] Refresh the project page repeatedly during processing. Polling stable; no client-side memory leak (Chrome DevTools heap snapshot before vs after 30 polls).
- [ ] Delete a project. URL no longer resolves (404). Sidebar updates immediately.
- [ ] Wait one TTL window (or simulate via `created_at` rewind) and verify the project is gone.
- [ ] Try the copy-link button on each browser; verify clipboard contents.
- [ ] Inspect `Set-Cookie` header on first visit. `image2pptx_session`, HttpOnly, Secure, SameSite=Lax.
- [ ] Tab through the upload form and project page. Every interactive element receives a visible focus ring.
- [ ] Upload a transparent PNG. Verify the pptx renders cleanly in PowerPoint, Keynote, and Slides; alpha is flattened to white, no black/grey backgrounds.
- [ ] Upload a CMYK JPEG from Adobe Lightroom or Photoshop. Verify the conversion succeeds, the on-disk normalised input is RGB, and the pptx slide colours look plausibly correct.

---

## 7. Coverage matrix (agreement to test)

| Agreement | Tests |
|---|---|
| A1 versioning shape | HP-04, EC-19, EC-32 |
| A4 UI copy | HP-01, HP-07, HP-10, UX-04 |
| A8/A9 px-image2pptx invocation | HP-01, AB-09 (arch) |
| A10 LibreOffice backend | HP-01, EC-43, DV-01, DV-02 |
| A11 timeout / process group kill / OOM / sandboxed write | EC-14, EC-15, EC-15b, EC-16, EC-31, EC-41, EC-42, AB-09b, AB-24 |
| A12 model pre-warm | DV-06, DV-06b, DV-12 |
| A13 EXIF rotation, alpha flatten, CMYK convert | EC-04, EC-05, EC-06, manual |
| A14 metadata sanitisation | EC-39 |
| A15-A17 storage layout, signed downloads, sanitised filenames | HP-02, EC-36, EC-37, EC-38, AB-05, AB-08, AB-25 |
| A18 TTL reaper | HP-09, EC-17, HP-10 |
| A19 byte-ceiling reaper | EC-18, EC-19 |
| A20 layered upload limits | EC-01, EC-02, EC-12, AB-01, AB-02, AB-03, AB-04, EC-44, DV-13 |
| A21 accepted formats | EC-07 to EC-12 |
| A22 rate limits (upload + project-read + concurrency + queue depth) | EC-20, EC-21, EC-22, AB-14, AB-15 |
| A23 per-upload nonce | EC-23, EC-26 |
| A24 cookie attributes | HP-01, HP-05, HP-06, AB-18 |
| A25 CSRF + Origin | AB-10, AB-11, AB-11b, AB-11c |
| A26 download headers | HP-02, AB-12, AB-17, AB-25 |
| A27 static-grep deploy gate | AB-20, DV-07 |
| A28 bridge no-egress | AB-19 |
| A29 dedup (DEFERRED post-MVP) | HP-03 (always-create), EC-40 (deferred) |
| A33 a11y baseline | UX-07, UX-08, manual |
| A35 no user pptx import | AB-07, AB-21 |
| A36 Python pin | DV-03 |
| A37 Pest coverage | this entire document |
| Anti-clickjacking (spec 8.1) | AB-12 |
| Failure taxonomy (spec 8.6) | EC-13, EC-14, EC-15, EC-15b, EC-16, EC-31, EC-41, EC-42, EC-43 |

---

## 8. Out of scope for this test plan

- Visual fidelity of the conversion (subjective; covered by manual QA only).
- Performance benchmarks beyond happy-path timing assertions. A real load test is post-MVP.
- Multi-tenant isolation (we have no tenants).
- i18n (English only per A32).
- Penetration testing by a third party (defer; document the threat model).
