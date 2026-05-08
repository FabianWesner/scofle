# Specification: Image-to-PowerPoint Web App

> Authoritative spec drawn from the locked Agreements (A1 to A37) in `specs/brainstorming.md`. Reconciled with `specs/testplan.md` after user review.

## 1. Product summary

A web app that converts an uploaded image into a PowerPoint deck and a PDF derived from that deck. Users upload an image, see a preview of the input and a preview of the generated PDF, can iterate by regenerating or by re-uploading a replacement image, and can download both artefacts. There is no login; the user's session cookie remembers their recent projects on this device. Files live in private server storage outside the web root and are reaped after the configured TTL or sooner if a global disk-usage ceiling is exceeded.

The conversion uses the open-source Python library `px-image2pptx` (PaddleOCR plus big-lama inpainting; local models, no network calls). PDF rendering uses LibreOffice headless against the generated `.pptx`, so the two artefacts cannot disagree.

## 2. Canonical decisions

This table is the source of truth. Anything below that contradicts it is wrong.

| Topic | Decision |
|---|---|
| Upload response | `POST /uploads` returns Inertia redirect (HTTP 302) to `GET /projects/{uuid}` |
| Regenerate response | `POST /projects/{uuid}/regenerate` returns Inertia redirect (HTTP 302) to the same project page |
| Replace-image response | `POST /projects/{uuid}/replace-image` returns Inertia redirect (HTTP 302) to the same project page |
| Delete response | `DELETE /projects/{uuid}` returns Inertia redirect (HTTP 302) to `GET /` |
| Download response | `GET /downloads/{version_id}/{kind}` returns 200 with the file (kind in `pptx`, `pdf`); no Inertia |
| Inline PDF preview | `GET /downloads/{version_id}/pdf?inline=1` adds `Content-Disposition: inline` and `CSP: sandbox` |
| Version status enum | strict four values: `pending`, `running`, `ready`, `failed` |
| Derived UI label `partial` | `status='ready' AND failure_code='pdf_render' AND pdf_bytes IS NULL`. Not an enum value, not a column. |
| Storage filename | `versions/{n}/input.{ext}` where `{ext}` is the canonical extension picked from the sniffed MIME (`png` or `jpg`). No `source.{ext}` anywhere. |
| Storage root | `storage/app/private/projects/{project_uuid}/` |
| Warm-up command | `php artisan ppt:warm-models` |
| Reap command | `php artisan projects:reap` |
| Static-grep gate | `bin/grep-no-gemini.sh` |
| Cookie name | `image2pptx_session` (HttpOnly, Secure, SameSite=Lax, 10-year max-age) |
| TTL anchor | `projects.created_at` |
| TTL config key | `config('project.ttl_days')`, env `PROJECT_TTL_DAYS`, default `7` |
| Disk ceiling config | `config('project.tmp_bytes_cap')`, env `PROJECT_TMP_BYTES_CAP`, default `5368709120` (5 GiB) |
| Upload byte cap | `config('upload.max_bytes')`, env `UPLOAD_MAX_BYTES`, default `10485760` (10 MiB) |
| Web-server body cap | `client_max_body_size` 11 MiB (1 MiB headroom over the app cap) |
| Pixel cap | long edge `<= 4096`, total pixels `<= 16,777,216` (covers iPhone 4032x3024 directly) |
| LAMA cap | `--max-inpaint-size 2048` always passed to the bridge |
| Accepted formats | PNG and JPEG only. Sniffed MIME and declared extension must both be in `{image/png .png, image/jpeg .jpg/.jpeg}` AND must agree. Reject otherwise. |
| Decode probe | runs in Python (Pillow) inside the bridge wrapper, NOT PHP. No Imagick dependency. |
| Copy-link in MVP | YES. Simple "Copy link" button next to the slug-access banner. No email or share-to-X buttons. |
| Cross-frame protection | every HTML response: `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY` |
| Project-read rate bucket | separate from upload bucket: 60 GETs per IP per minute on `/projects/{uuid}*` |

## 3. Glossary

- **Project.** A unit of work in the user's session. Owns one current source image and an ordered append-only list of Versions. Identified by a UUID slug used in the URL.
- **Version.** One run of the conversion pipeline. Stores its own input image, output `.pptx`, output `.pdf`, and a job log. Numbered v1, v2, ... within a Project.
- **Source image.** The currently selected input for a Project. Equal to the input image of the latest non-failed Version. If every Version is `failed`, the Project has no source image and Regenerate is disabled until a Replace-image upload succeeds.
- **Session.** A long-lived HttpOnly cookie carrying a random session token, mapped server-side to a row in the `sessions` table. Does not expire on the server but the browser may clear it; the user is told as much.
- **Slug.** Project UUID, used in the URL. Anyone with the slug can access the Project. The cookie is only the convenience layer that populates the sidebar.

## 4. User stories (MVP)

US-1. As a user, I drop an image onto the home page and within ~30 seconds I see a preview of my generated deck.
US-2. As a user, I download the `.pptx` and the `.pdf`.
US-3. As a user, I click "Regenerate" to produce another Version against the same image.
US-4. As a user, I upload a different image to the same Project to add a new Version with the new image as input. The previous Versions remain accessible.
US-5. As a user, I switch between Versions and download from any of them.
US-6. As a user, I see the most recent Projects in a sidebar on this device.
US-7. As a user, I delete a Project and its files are gone immediately.
US-8. As a user, I copy the project link with a button and paste it on another device, and the project loads (slug-as-access). UI tells me anyone with the link can access it.
US-9. As a user, when conversion fails, I see a clear failure message on the failed Version and can regenerate.
US-10. As a user, when LibreOffice cannot render the PDF, the `.pptx` is still downloadable and the PDF is marked unavailable with a clear explanation.

Out of scope for MVP: editing slides, login, project rename, multi-image batch, slide thumbnail strip, a11y QA pass beyond the baseline (A33), internationalisation, optional local-LLM integration. **In scope:** copy-link button only (no email, no share-to-X buttons).

## 5. Architecture

### 5.1 Stack

- Laravel 13, PHP 8.4
- Inertia v3, React 19, Tailwind v4, Shadcn defaults
- SQLite for the index (projects, versions, sessions, session_projects, upload_nonces)
- Laravel queue, `database` driver, single warm worker
- Python 3.9 to 3.13, version pinned in `.python-version` (A36)
- `px-image2pptx` Python package, pinned commit, installed with `[ocr,inpaint]` extras only (NEVER `[all]`)
- LibreOffice headless (`soffice`) as a hard system requirement on the deploy target
- No Imagick, no GD beyond what Laravel ships. Decode/normalisation is in Python.

### 5.2 Process model

```
Browser  --POST /uploads-->  Laravel   --enqueue-->  Jobs table
                                                          |
                                              Queue worker (proc 1)
                                                          |
                                                 Symfony Process argv
                                                          |
                                                 px-image2pptx <in> -o <pptx>
                                                          |
                                                 soffice --headless --convert-to pdf
                                                          |
                                                 Updates Version row, writes artefacts
                                                          |
Browser  <--Inertia poll--   Laravel   <--read--    Jobs table / Versions
```

Conversion never blocks an HTTP worker. The upload request returns 302 redirect to `/projects/{uuid}`. The Project page polls every 2 seconds via Inertia until the active Version flips out of `pending` or `running`.

Hard wall-clock timeout: 90 seconds total (covers 5-16 s for the bridge plus 5 s for LibreOffice plus headroom). On overrun, the queue job kills the entire process group via Symfony Process and marks the Version `failed` with code `bridge_timeout`.

### 5.3 Storage layout

```
storage/app/private/projects/{project_uuid}/
    versions/
        1/
            input.{png,jpg}          // canonical extension from sniffed MIME
            output.pptx
            output.pdf               // missing if soffice failed
            job.log                  // stdout+stderr from the bridge
            meta.json                // { byte_size, hash_sha256, lib_version, lang, normalised_at }
        2/
            ...
```

There is no `current` or `source.{ext}` anywhere, ever. The "current source image" is by definition `versions/{N}/input.{ext}` where N is the latest non-failed Version (A1, A15).

Downloads go through a signed controller route only. Files never appear under `public/` or via a storage symlink (A16).

### 5.4 Data model (SQLite)

```
projects
    id integer pk
    uuid text unique not null               // slug
    total_bytes integer not null default 0  // sum of all Versions' artefacts; updated by writer
    created_at, updated_at                  // TTL is anchored on created_at, not updated_at

versions
    id integer pk
    project_id integer fk -> projects.id
    n integer not null                      // 1-based ordinal within Project
    status text not null                    // pending | running | ready | failed (strict enum)
    display_filename text                   // user's original filename, sanitised; display-only
    input_mime text                         // 'image/png' | 'image/jpeg'
    input_ext text                          // 'png' | 'jpg'
    input_bytes integer
    input_pixels integer
    pptx_bytes integer null                 // null until ready
    pdf_bytes integer null                  // null if soffice failed -> derived 'partial' UI label
    pptx_sha256 text null                   // post-conversion hash; A29 (post-MVP)
    failure_code text null                  // see section 8.6
    failure_message text null
    heartbeat_at datetime null              // worker pings every 5 s while running; reaper uses this to detect orphans
    started_at, finished_at, created_at

sessions
    id integer pk
    token text unique not null              // long random, opaque
    last_seen_at, created_at

session_projects
    session_id fk
    project_id fk
    created_at
    primary key (session_id, project_id)

upload_nonces                               // A23
    nonce text pk
    consumed_at datetime null
    created_at
```

Eviction (A7: max 5 Versions per Project, max 20 Projects per session, max 5 GiB global) deletes both DB rows and the on-disk artefacts. Versions in `pending` or `running` status are skipped (A19).

### 5.5 Version state machine

```
                 +-------+   enqueue        +---------+   worker pickup   +---------+
                 |       |---------------->|         |------------------>|         |
                 | (new) |                  | pending |                   | running |
                 +-------+                  +---------+                   +---------+
                                                  |                            |
                                                  | timeout/cancel             | bridge ok &
                                                  | (rare)                     | (soffice ok or pdf_render)
                                                  v                            v
                                                +--------+                  +-------+
                                                | failed |<-----------------| ready |
                                                +--------+   never          +-------+
                                                  ^   ^
                                                  |   |
                          bridge_error/oom/etc ---+   +--- heartbeat stale -> code=interrupted
```

Allowed transitions:
- `pending -> running`: worker picks up the job and writes `started_at`, `heartbeat_at`.
- `running -> ready`: bridge produced a valid pptx (and optionally a pdf). `finished_at` set.
- `running -> failed`: any failure path; `failure_code` and `failure_message` set; `finished_at` set.
- `pending|running -> failed (interrupted)`: on worker boot, any row whose `heartbeat_at` is older than 90 s is marked `failed` with `failure_code='interrupted'`.
- `ready` and `failed` are terminal. There is no automatic retry. The user issues a new Version via Regenerate.

The PDF-rendering failure case stays in `ready`: `status='ready'`, `failure_code='pdf_render'`, `pdf_bytes IS NULL`. UI labels this `partial`.

## 6. UX

### 6.1 Information architecture

```
/                           Home, drop zone, recent-projects sidebar
/projects/{uuid}            Project page, current Version selected
/projects/{uuid}/v/{n}      Project page, Version n selected (deep link)
```

Wayfinder generates typed URL helpers from these named routes.

### 6.2 Wireframes (ASCII)

#### Home page

```
+------------------------------------------------------------+
| image-to-powerpoint                          this device v |
+------------------------------------------------------------+
| Sidebar      |  +---------------------------------------+  |
| Recent       |  |                                       |  |
|              |  |   Drop an image here or click to      |  |
| - Project A  |  |   browse.                             |  |
| - Project B  |  |                                       |  |
| - Project C  |  |   PNG or JPEG. Up to 10 MB,           |  |
|              |  |   up to 4096 px on the longest side.  |  |
|              |  |                                       |  |
|              |  +---------------------------------------+  |
|              |                                             |
|              |  Files are deleted {N} days after upload.   |
|              |  Please download to keep them.              |
|              |  Anyone with a project's link can open it.  |
+------------------------------------------------------------+
```

`{N}` is rendered server-side from `config('project.ttl_days')`.

#### Project page (active Version ready)

```
+------------------------------------------------------------+
| image-to-powerpoint            project A             [...] |
+------------------------------------------------------------+
| Sidebar      |  +-------------------+  +-----------------+  |
| Recent       |  | Input image       |  | Output preview  |  |
|              |  | [thumb of input]  |  | [embedded PDF]  |  |
| - Project A  |  | photo.jpg         |  |                 |  |
| - Project B  |  | 2.4 MB, 3024x4032 |  |                 |  |
| - Project C  |  +-------------------+  | Download .pptx  |  |
|              |                         | Download .pdf   |  |
|              |  Versions:                                   |
|              |  v3 (active) v2 v1                           |
|              |                                              |
|              |  [Regenerate]  [Replace image]  [Delete]    |
|              |                                              |
|              |  Anyone with this link can open this         |
|              |  project. [Copy link]                        |
+------------------------------------------------------------+
```

`3024x4032` (iPhone portrait) is within the 4096 long-edge cap.

#### Project page (Version pending or running)

```
+------------------------------------------------------------+
| Sidebar      |  +-------------------+  +-----------------+  |
|              |  | Input image       |  | [pulsing skel]  |  |
|              |  | [thumb]           |  | Generating...   |  |
|              |  +-------------------+  | This usually    |  |
|              |                         | takes 5-20 sec. |  |
|              |  Versions: v1 (pending or running)           |
+------------------------------------------------------------+
```

#### Project page (derived `partial`: pptx ready, pdf render failed)

```
+------------------------------------------------------------+
| Sidebar      |  +-------------------+  +-----------------+  |
|              |  | Input image       |  | Preview         |  |
|              |  | [thumb]           |  | unavailable.    |  |
|              |  +-------------------+  | (PDF render     |  |
|              |                         | failed.)        |  |
|              |                         |                 |  |
|              |                         | Download .pptx  |  |
|              |                         | Download .pdf   |  |
|              |                         |  (unavailable)  |  |
|              |                                              |
|              |  Versions: v2 (partial) v1                   |
+------------------------------------------------------------+
```

The `partial` badge derives from `status='ready' AND failure_code='pdf_render' AND pdf_bytes IS NULL`. There is no `partial` enum value.

### 6.3 Components (Shadcn defaults)

`Button`, `Card`, `Input` (file), `Sheet` (delete confirm), `Toast`, `Tooltip`, `Skeleton`, `Badge`. No custom colour palette beyond Shadcn defaults.

### 6.4 Copy that must appear (A4)

- Home page footer (server-rendered from config): "Files are deleted {ttl_days} days after upload. Please download to keep them. This device remembers your projects until cookies are cleared."
- Project page banner: "Anyone with this link can open this project."
- Failed Version: per-failure-code messages from the table in section 8.6.
- LibreOffice failure (status `ready`, code `pdf_render`): "PDF rendering failed; the .pptx file is still available."

## 7. Conversion pipeline

### 7.1 Validation (PHP, pre-flight)

Pre-flight runs in this exact order, before the file is moved to its final location and before Python is invoked:

1. **Web-server body cap** rejects > 11 MiB before PHP sees it. (Layer 1.)
2. **Laravel rule** `mimes:png,jpg,jpeg|max:10240`. Rejects > 10 MiB and unknown extensions. (Layer 2.)
3. **Magic-byte sniff** via `finfo`. Sniffed MIME must be `image/png` or `image/jpeg`. Each known unsupported format gets a specific error message: WebP, SVG, GIF, HEIC, TIFF, BMP, others. (Layer 3.)
4. **Extension/MIME agreement check**. The sniffed MIME and the declared extension must both be in the accepted set AND must point at the same format. PNG-renamed-as-JPG is rejected. (Strict; defence-in-depth and a clear user message: "File contents do not match the file extension.") (Layer 4.)
5. **`getimagesize`** for declared dimensions. Reject if read fails or if longest side > 4096 or total pixels > 16,777,216. (Layer 5.)

Any failure here returns 422 with a specific message; no Project or Version is created.

### 7.2 Normalisation (Python, in the bridge wrapper)

Once validation passes and a Version directory exists, the queue job invokes a thin Python wrapper that:

6. **Decode probe**: `Image.open(path).load()` with Pillow. Catches truncated PNG, polyglot files, and `Image.DecompressionBombError` for any image whose decoded pixels exceed the Pillow ceiling. On failure, the wrapper exits non-zero with code `bridge_error` (or `oom` if the worker was OOM-killed by the OS).
7. **EXIF rotation** baked in. Output has rotation 1 or no EXIF.
8. **Alpha flatten** for PNG with transparency: composite onto white. (EC-05.)
9. **CMYK convert** for CMYK JPEGs: `Image.convert('RGB')`. (EC-06.)

Output of normalisation is a single PNG or JPEG byte stream in sRGB, no alpha, EXIF rotation 1, written back to `versions/{n}/input.{ext}` (overwriting the original), then passed to `px-image2pptx`.

PHP does not decode pixels. Imagick is not a dependency.

### 7.3 Bridge invocation (A8, A9)

```
px-image2pptx <input> -o <output> --lang auto --max-inpaint-size 2048
```

Invoked via Symfony Process with the array constructor (argv only, never `shell_exec`). `--max-inpaint-size 2048` is mandatory because the upload pixel ceiling is 4096 px long edge.

The Python venv lives at `storage/app/python-venv/`. Activated via the absolute path to its `python` executable.

### 7.4 PDF render (A10)

```
soffice --headless --convert-to pdf --outdir <version_dir> <version_dir>/output.pptx
```

Same Symfony Process discipline. Failure does NOT fail the Version: `status` stays `ready`, `failure_code` is set to `pdf_render`, `pptx_bytes` is populated, `pdf_bytes` stays null.

After soffice succeeds, the wrapper validates the pptx by opening it as a ZIP archive and checking the central directory; an unparseable pptx is treated as `invalid_pptx` and the Version is marked `failed`.

### 7.5 Process supervision (A11)

- Symfony Process with a 90-second timeout. On timeout, kills the entire process group.
- stdout and stderr captured to `job.log` (truncated at 256 KiB).
- Exit code is the success contract. Exit 0 with no `output.pptx` is treated as `empty_output` failure.
- The worker writes `heartbeat_at = now()` every 5 s while a job is running. On worker boot, any `running` row with `heartbeat_at` older than 90 s is marked `failed` with `failure_code='interrupted'`. No automatic retry.
- Linux OOM-killed children exit with status 137 (signal 9); the wrapper translates that to `failure_code='oom'`.

### 7.6 Models pre-warmed (A12)

Deploy step: `php artisan ppt:warm-models`. Runs the bridge against a tiny built-in fixture and discards output. Side effect: paddleocr and big-lama weights land in `~/.paddlex/official_models/` and `~/.cache/torch/hub/checkpoints/` before any user request. Without this step, user-1 hangs on a 196 MiB blocking download.

Deploy checklist split into two phases.

**Phase 1: deploy-time one-shot checks** (run on each release; all must exit 0):

```
1. python --version                                 # in 3.9..3.13 range, matches .python-version
2. which soffice                                    # exit 0; soffice --version 7+
3. php artisan ppt:warm-models                      # exits 0; weights present after
3a. Verify HOME, PADDLE_PDX_HOME (or ~/.paddlex), TORCH_HOME (or ~/.cache/torch)
    point at writable persistent storage, not per-request scratch.
4. php artisan migrate --force
5. bin/grep-no-gemini.sh                            # static-grep gate (A27); fails on planted literal
```

**Phase 2: long-running services** (started by the platform, not the deploy script):

```
- php artisan queue:work --queue=default --tries=1 --timeout=120
- php artisan schedule:work    # or system cron */1 * * * * php artisan schedule:run; runs hourly projects:reap
```

The deploy script never starts or supervises Phase 2 processes.

### 7.7 Metadata sanitisation (A14)

Output `.pptx`: via `python-pptx` core properties, set Author to the fixed string `image-to-powerpoint` and clear Title, Subject, Keywords, Comments, LastModifiedBy. Created and Modified timestamps left as today (not identifying).

Output `.pdf`: `soffice --headless`. Producer is `LibreOffice`; Author and Title carried over from the pptx core properties. No exiftool dependency.

## 8. Security

### 8.1 Headers

- Every HTML response: `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY` (anti-clickjacking).
- Upload, regenerate, replace-image, delete POSTs: CSRF token required, Origin header check (A25).
- Download routes: `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`. `Content-Disposition: attachment; filename="project-{uuid_prefix}-v{n}.{pptx|pdf}"`, ASCII-only, server-derived (A16, A26).
- Inline PDF preview: `Content-Disposition: inline`, `Content-Security-Policy: sandbox` to neuter PDF JavaScript.

### 8.2 Path discipline

UUIDs only on disk. The user's filename is display-only, never a path component, never an argv. All bridge invocations use Symfony Process argv. No `shell_exec`, no `system`, no `proc_open` outside an allow-listed wrapper. No string interpolation of paths.

The Python wrapper uses `realpath` on every output path and refuses to copy any file that resolves outside the per-version work dir.

### 8.3 Static-grep deploy gate (A27)

`bin/grep-no-gemini.sh` greps the installed `px-image2pptx` package for the literals `gemini`, `googleapis`, `generativeai`. Non-zero match fails deploy. Defence-in-depth even though the current source is clean.

### 8.4 Network egress test (A28)

Pest feature test runs the bridge against a fixture and asserts no outbound network calls were made (sandboxed via `unshare -n` on Linux CI; trusted by static grep on Herd dev).

### 8.5 Rate limits and accepted threats (A22)

Three independent buckets, all via Laravel `RateLimiter`:

- **Upload bucket**: 5 uploads per IP per 15 minutes. Applies to `POST /uploads`, `POST /projects/{uuid}/regenerate`, `POST /projects/{uuid}/replace-image`.
- **Project-read bucket**: 60 GETs per IP per minute on any `/projects/{uuid}` URL. Defends slug brute-force (AB-14).
- **Concurrency**: 1 in-flight conversion per session (DB-locked).
- **Global queue depth cap**: 50 pending jobs. Beyond that, uploads return 503 with "service busy, try again shortly".

**Cookie reuse across IPs is an accepted threat** (testplan AB-22). The session cookie is a convenience layer that populates the recent-projects sidebar; it is not auth. If a cookie leaks, the consequence is read access to those projects, identical to someone sharing a project slug. Documented in UI copy A4.

### 8.6 Failure codes

| code            | meaning                                                                | user message                                                            |
|-----------------|------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `validation`    | failed pre-flight (section 7.1, layers 1 to 5)                         | specific to the rule (e.g. "image too large")                           |
| `bridge_error`  | bridge exited non-zero or Pillow decode probe failed                   | "We could not convert this image. Please try a different image."        |
| `bridge_timeout`| 90 s wall clock exceeded                                               | "Conversion took too long. Please try a smaller image."                 |
| `oom`           | bridge child killed by OOM killer (exit 137 on Linux)                  | "Conversion failed because the image was too complex. Try a simpler image." |
| `disk_full`     | write to `versions/{n}/...` returned ENOSPC                            | "Server is out of space. Please try again later."                       |
| `empty_output`  | bridge exited 0 but `output.pptx` missing or 0 bytes                   | "We could not convert this image. Please try a different image."        |
| `invalid_pptx`  | bridge exited 0 but `output.pptx` is not a valid zip                   | "We could not convert this image. Please try a different image."        |
| `pdf_render`    | pptx ok, soffice failed; Version stays `ready`                         | "PDF rendering failed; the .pptx file is still available."              |
| `interrupted`   | worker died mid-conversion; heartbeat stale                            | "The conversion was interrupted. Please regenerate."                    |
| `rate_limited`  | over RateLimiter cap                                                   | "Too many uploads. Please wait a few minutes."                          |

## 9. Lifecycle

- **TTL.** `PROJECT_TTL_DAYS` default 7. Hourly `php artisan projects:reap` deletes Projects whose `created_at` is older than TTL. (A18.) The UI copy renders the configured value.
- **Global ceiling.** `PROJECT_TMP_BYTES_CAP` default 5 GiB. Same reaper, evaluated at the top of each hourly run, evicts oldest Projects first BEFORE applying TTL when over the ceiling. Versions in `pending` or `running` are skipped. (A19.)
- **Sessions.** Cookie `image2pptx_session`, HttpOnly, Secure, SameSite=Lax, 10-year max-age. Server-side `sessions` row keyed by a long random opaque token. (A24.) Long max-age so the sidebar persists across browser restarts.
- **Per-upload nonce.** Each upload form embeds a one-shot nonce; the controller consumes it atomically and rejects duplicates so a refresh during upload does not create a second Project. (A23.)
- **Per-Version source bytes** are counted when computing `projects.total_bytes` and in the reaper's byte accounting.

## 10. Telemetry (A31)

Laravel logs only. Per Version row: job id, duration, exit code, `failure_code`, input bytes, input pixels, output pptx bytes, output pdf bytes (or null). No third-party SDK. No Sentry unless the user adds one explicitly.

## 11. Testing (A37, see specs/testplan.md for the full case list)

MVP-blocking Pest test types:

- Feature: upload happy path, oversize byte rejection, oversize pixel rejection, wrong-format rejection (WebP, SVG, GIF, HEIC, TIFF, BMP), extension/MIME mismatch rejection, CMYK JPEG converts to sRGB, PNG-with-alpha flattens to white, EXIF-rotated input baked correctly, regenerate produces a new Version, replace-image produces a new Version, slug-only access for cookie-less viewer, rate-limit enforcement (upload bucket and read bucket), TTL reaper, byte-ceiling reaper, deploy-time grep check fails on a planted Gemini string, LibreOffice failure leaves pptx downloadable.
- Browser (Pest 4): drop zone happy path, regenerate while running shows skeleton, copy-link toast, delete confirm flow.
- Unit: pre-flight validation rules, filename sanitiser, Version state-transition guard.
- Arch: no `shell_exec` or `system` calls anywhere; all bridge invocations route through one wrapper; no telemetry SDK imports.

A29 dedup test (regenerate hash-collision surfaces no-new-version) is deferred with the feature.

## 12. Roadmap

Phased delivery. M0 through M4 are the MVP. M5+ is post-MVP, not committed.

### M0. Walking skeleton

Goal: Laravel + Inertia + React boots, drop zone exists, file uploads land in private storage, the user is redirected to a Project page that shows the input image. No conversion yet.

Deliverables:
- Routes: `GET /`, `POST /uploads`, `GET /projects/{uuid}`.
- Models, migrations, factories: `Project`, `Version` (status only `pending` so far), `Session`, `SessionProject`, `UploadNonce`.
- Validation pipeline (section 7.1) implemented and tested. Bridge is NOT called yet, but a Version row is created in `pending` state on upload.
- Sidebar lists session's Projects.
- Pest feature tests for happy-path upload, oversize byte/pixel rejection, wrong-format rejection, extension/MIME mismatch rejection, per-upload nonce idempotency.

Exit criteria: a fresh `git clone` plus `composer install`, `npm install`, `php artisan migrate`, `npm run dev` lets a developer drop a PNG and see it on a Project page.

### M1. Conversion (bridge)

Goal: enqueueing a Version runs the Python bridge and produces `output.pptx` on disk. PDF rendering is NOT yet wired.

Deliverables:
- Python venv at `storage/app/python-venv/`, requirements pinned, `px-image2pptx` installed with `[ocr,inpaint]` extras.
- `php artisan ppt:warm-models` command.
- Queue job `ConvertVersionJob` invokes bridge via Symfony Process. 90 s timeout. Process-group kill on overrun. Heartbeat every 5 s.
- Failure-code mapping (section 8.6) wired: `bridge_error`, `bridge_timeout`, `oom`, `empty_output`, `invalid_pptx`, `interrupted`, `disk_full`.
- Normalisation in Python: EXIF rotation, alpha flatten, CMYK convert (section 7.2).
- Inertia polling on the Project page flips Version `pending -> running -> ready|failed`.
- Pest tests: bridge happy path against a small fixture, `bridge_error`, `bridge_timeout`, `interrupted`, network egress (A28), normalisation outputs.

Exit criteria: drop a PNG, the queue worker runs the bridge, the Project page shows ready and the .pptx download button works.

### M2. PDF rendering

Goal: PDF derived from the generated pptx via LibreOffice headless. Inline PDF preview on the Project page.

Deliverables:
- LibreOffice invocation in the same job after the bridge succeeds. Failure does NOT fail the Version (sets `failure_code='pdf_render'`, `pptx_bytes` populated, `pdf_bytes` null). UI labels this `partial` per the derivation rule.
- Inline PDF preview via `<object>` with sandbox CSP. Falls back to a download-only state when `pdf_bytes` is null.
- Both download buttons rendered with server-derived ASCII filenames.
- Pest tests: PDF happy path, pdf_render-failed-but-pptx-ready path, security headers on download routes, derived `partial` badge renders correctly.

Exit criteria: drop a PNG, see the embedded PDF preview, download both files. If LibreOffice fails on a specific file, the build still ships pptx and shows the unavailable-PDF message.

### M3. Versions and iteration

Goal: regenerate and re-upload produce additional Versions on the same Project. Version switcher in the UI. Delete project flow. Copy-link button.

Deliverables:
- `POST /projects/{uuid}/regenerate` creates a new Version using the latest non-failed Version's input.
- `POST /projects/{uuid}/replace-image` accepts a new upload, same validation, creates a new Version with the new input.
- Version switcher tabs (`v3 (active) v2 v1`) on the Project page; each Version is deep-linkable as `/projects/{uuid}/v/{n}`.
- Eviction: 5 Versions per Project, 20 Projects per session. Oldest evicted on insert. In-flight (`pending` or `running`) not evictable.
- `DELETE /projects/{uuid}` with confirm dialog.
- Copy-link button + toast.
- CSRF + Origin check on regenerate, replace-image, and delete (A25).
- Pest tests: regenerate produces new Version, replace-image produces new Version with new input bytes, version switcher deep links work, delete removes rows and files, eviction cap respected, copy-link button copies the slug URL.

Exit criteria: a user can iterate (regenerate + replace-image), switch between Versions, copy-link, and delete the Project.

### M4. Polish, abuse, deploy

Goal: ship-ready. Lifecycle, rate limits, deploy gates, copy.

Deliverables:
- `php artisan projects:reap` scheduled hourly, applies TTL (anchored on `created_at`) and ceiling-based eviction.
- Laravel `RateLimiter` rules: upload bucket (5 / IP / 15 min), project-read bucket (60 / IP / min), concurrency-per-session, global queue depth 50.
- Deploy script: `which soffice`, `python --version`, `php artisan ppt:warm-models`, static-grep gate (`bin/grep-no-gemini.sh`).
- All required UI copy (section 6.4): home footer with parameterised TTL, project banner, failure messages.
- Slug-as-access verified by a cookie-less Pest test.
- Metadata sanitisation on output pptx (A14).
- Telemetry: structured Laravel logs per Version.
- Anti-clickjacking headers on every HTML response.

Exit criteria: deploy script passes on the target environment, all MVP-blocking Pest tests pass, manual QA from `specs/testplan.md` runs clean.

### Post-MVP (not committed)

- M5: per-Version notes/title; rename a Project; UI for "active Version" pinning.
- M6: A29 dedup ships (hash produced pptx, skip duplicate Version on regenerate).
- M7: bulk upload (multi-image, one Version per image, or batched).
- M8: slide thumbnail strip preview rendered via LibreOffice.
- M9: idempotent retry-once on `interrupted` Versions.

### Explicit anti-roadmap

No local LLM, no third-party telemetry, no email, no share-to-X buttons, no accounts, no slide editor, no user-supplied .pptx import, no internationalisation. (Copy-link is in MVP, see section 4.)

## 13. Open follow-ups (non-blocking)

- Confirm `soffice --version 7+` requirement on the deploy target.
- Confirm Python 3.x range during deploy verification.
- Decide where Pest browser tests run: Herd locally is fine; in CI they need a headless browser image.
