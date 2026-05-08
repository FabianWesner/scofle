# Specification: Image-to-PowerPoint Web App

> Session-only temporary-conversion MVP. This supersedes earlier project/slug/share-link wording.

## 1. Product Summary

A local web app converts one or more uploaded PNG or JPEG images into PowerPoint decks. Optional PDF preview/rendering can be enabled, but it is disabled by default in local development to avoid repeated LibreOffice/macOS crash dialogs. There is no login and no public sharing. A browser session owns its temporary conversions through an `image2pptx_session` cookie. URLs are navigation aids only; they are never access tokens.

The app stores files only as temporary working copies outside the web root while conversion, preview, and download are needed. Users must download the `.pptx` and any optional `.pdf` to keep them. Temporary files are deleted by the user or by a reaper. The UI must not imply permanent persistence.

The conversion uses the open-source Python package `px-image2pptx` with OCR and inpainting extras only. When PDF rendering is enabled, LibreOffice headless renders against the generated `.pptx`, so preview and download output come from the same source artifact.

## 2. Canonical Decisions

| Topic | Decision |
|---|---|
| Access model | Current session cookie is required for every conversion read, action, preview, and download. A URL alone never grants access. |
| Share links | No copy-link button, no public slug access, no email/share actions, no "anyone with link" copy. |
| Main entity | `Conversion`, not Project. A Conversion is a temporary session-owned workspace for one source image. |
| Attempts | A Conversion owns append-only Attempts (`a1`, `a2`, ...). Regenerate creates a new Attempt from the same source image. |
| New images | Uploading one or more images creates one Conversion per image in the same session. They are not children/versions of another Conversion. |
| Queueing | Multiple uploaded images are queued and processed one Attempt at a time in first-in first-out order. |
| Upload response | `POST /uploads` returns Inertia redirect (302) to `GET /conversions/{uuid}`. |
| Regenerate response | `POST /conversions/{uuid}/regenerate` returns Inertia redirect (302) to the same conversion page. |
| Delete response | `DELETE /conversions/{uuid}` returns Inertia redirect (302) to `GET /`. |
| Delete-all response | `DELETE /conversions` deletes all Conversions owned by the current session and returns Inertia redirect (302) to `GET /`. |
| Download response | `GET /downloads/{attempt_id}/{kind}` returns 200 only when the signed URL is valid and the current session owns the Attempt's Conversion. |
| Inline PDF preview | Optional. When `CONVERSION_RENDER_PDF=true`, `GET /downloads/{attempt_id}/pdf?inline=1` adds `Content-Disposition: inline` and `Content-Security-Policy: sandbox`. |
| Attempt status enum | Strict four persisted values: `pending`, `running`, `ready`, `failed`. |
| Derived UI label `partial` | `status='ready' AND failure_code='pdf_render' AND pdf_bytes IS NULL`. Not an enum value, not a column. |
| Storage filename | `attempts/{n}/input.{ext}` where `{ext}` is canonical from sniffed MIME (`png` or `jpg`). No `source.{ext}`. |
| Storage root | `storage/app/private/tmp/sessions/{session_id}/conversions/{conversion_uuid}/`. |
| Warm-up command | `php artisan ppt:warm-models`. |
| Reap command | `php artisan conversions:reap`. |
| Reap schedule | Every 10 minutes with overlap protection. |
| Static-grep gate | `bin/grep-no-gemini.sh`. |
| Cookie name | `image2pptx_session` (HttpOnly, SameSite=Lax, long max-age). Secure defaults to true, but local HTTP development may set `IMAGE_SESSION_COOKIE_SECURE=false`. |
| TTL anchor | `conversions.created_at`. |
| TTL config key | `config('conversion.ttl_hours')`, env `CONVERSION_TTL_HOURS`, default `1`. |
| Disk ceiling config | `config('conversion.tmp_bytes_cap')`, env `CONVERSION_TMP_BYTES_CAP`, default `5368709120` (5 GiB). |
| Upload byte cap | `config('upload.max_bytes')`, env `UPLOAD_MAX_BYTES`, default `10485760` (10 MiB). |
| Pixel cap | Long edge `<= 4096`, total pixels `<= 16,777,216`. |
| LAMA cap | `--max-inpaint-size 2048` always passed to the bridge. |
| Accepted formats | PNG and JPEG only. Sniffed MIME and declared extension must both be in `{image/png .png, image/jpeg .jpg/.jpeg}` and must agree. |
| Decode probe | Runs in Python (Pillow) inside the bridge wrapper, not PHP. No Imagick dependency. |
| Cross-frame protection | Every HTML response sets `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`. |
| Read rate bucket | 60 GETs per IP per minute on `/conversions/{uuid}*`. |

## 3. Glossary

- **Session.** A long-lived HttpOnly cookie carrying a random opaque token, mapped to a server-side row. It is the access boundary for temporary conversions.
- **Conversion.** A temporary session-owned workspace for one uploaded source image and its generated Attempts.
- **Attempt.** One run of the conversion pipeline. Stores its own normalized input copy, output `.pptx`, optional output `.pdf`, job log, and metadata.
- **Source image.** The original image for a Conversion. Regeneration reuses the latest non-failed Attempt's input copy.
- **Temporary file.** A private on-disk working copy that exists only so the app can convert, preview, and serve downloads during the configured TTL.

## 4. User Stories

US-1. As a user, I upload one or more images and see each one queued for conversion.
US-2. As a user, I download the generated `.pptx`.
US-3. As a user, I click "Regenerate" to produce another Attempt against the same image.
US-4. As a user, I drag multiple images into the upload area and each appears as a separate temporary Conversion in my current session.
US-5. As a user, I switch between Attempts of the same Conversion and download from any ready Attempt.
US-6. As a user, I see recent temporary Conversions in the sidebar for this browser session.
US-7. As a user, I delete one Conversion, or all temporary Conversions in my session, and their files are removed immediately.
US-8. As a user, when conversion fails, I see a clear failure message and can regenerate.
US-9. As a user, when PDF preview is disabled or LibreOffice cannot render the PDF, the `.pptx` remains downloadable and PDF preview is marked unavailable.
US-10. As a user, I understand that files are temporary and must be downloaded to keep.

Out of scope: public sharing, project URLs as access, copy-link, login, editing slides, project rename, merging multiple images into one deck, slide thumbnail strip, local or remote LLM integration, user-supplied `.pptx` import, i18n.

## 5. Architecture

### 5.1 Stack

- Laravel 13, PHP 8.4
- Inertia v3, React 19, Tailwind v4
- SQLite index for sessions, conversions, attempts, upload nonces, and queue jobs
- Laravel background queue in local development; database queue can be used with a worker
- Python 3.9 to 3.13 pinned in `.python-version`
- `px-image2pptx[ocr,inpaint]` pinned to a commit; never install AI/all extras
- LibreOffice headless (`soffice`) as an optional PDF preview dependency
- No public storage symlink for conversion artifacts

### 5.2 Process Model

```text
Browser  --POST /uploads-->  Laravel  --background queue-->  PHP process
                                                               |
                                             Symfony Process argv
                                                               |
                       python bridge -> px-image2pptx -> output.pptx
                                                               |
                        optional soffice PDF render -> output.pdf
                                                               |
Browser <--Inertia poll-- Laravel <--read-- attempts/conversions
```

Conversion never blocks an HTTP worker. The browser accepts multiple selected files and may post them as one batch or as one-image JSON uploads with rotating nonces to stay under local web-server body limits. The backend creates one Conversion per uploaded image, redirects to the first created `/conversions/{uuid}`, and dispatches a queue processor. The processor drains pending Attempts one at a time in first-in first-out order. Pages poll every two seconds while the active Attempt is `pending` or `running` and stop after three minutes with a stable "still working" message.

Hard wall-clock timeout: 90 seconds for bridge and PDF render. Timeout marks the Attempt failed with `bridge_timeout`.

### 5.3 Storage Layout

```text
storage/app/private/tmp/sessions/{session_id}/conversions/{conversion_uuid}/
    attempts/
        1/
            input.{png,jpg}
            output.pptx
            output.pdf          -- only when PDF rendering is enabled
            job.log
            meta.json
        2/
            ...
```

Files are private temporary working copies. They are deleted when the user deletes one Conversion, when the user deletes all Conversions in the current session, when the TTL expires, or when the disk cap requires eviction. Downloads go through controller routes only.

### 5.4 Data Model

```text
sessions
    id integer pk
    token text unique not null
    last_seen_at, created_at

conversions
    id integer pk
    uuid text unique not null
    session_id integer fk -> sessions.id
    total_bytes integer not null default 0
    created_at, updated_at

attempts
    id integer pk
    conversion_id integer fk -> conversions.id
    n integer not null
    status text not null                  -- pending | running | ready | failed
    display_filename text
    input_mime text
    input_ext text
    input_bytes integer
    input_pixels integer
    pptx_bytes integer null
    pdf_bytes integer null
    pptx_sha256 text null
    failure_code text null
    failure_message text null
    heartbeat_at datetime null
    started_at, finished_at, created_at

upload_nonces
    nonce text pk
    consumed_at datetime null
    created_at
```

Retention limits:

- Max Attempts per Conversion: `config('conversion.max_attempts')`, default 5.
- Max Conversions per Session: `config('conversion.max_per_session')`, default 20.
- Max images per upload batch: `config('conversion.max_batch_uploads')`, default 20.
- Global temporary byte cap: `config('conversion.tmp_bytes_cap')`.
- In-flight Attempts are never evicted by limits or the reaper.
- Default time-based retention: one hour, with the scheduled reaper running every 10 minutes. Explicit delete removes files immediately.

### 5.5 Attempt State Machine

```text
pending -> running -> ready
pending -> running -> failed
pending|running -> failed(interrupted)
```

`ready` and `failed` are terminal. PDF rendering failure remains `ready` with `failure_code='pdf_render'` and `pdf_bytes IS NULL`; UI labels it `partial`.

## 6. UX

### 6.1 Routes

```text
/                              Home upload plus recent session conversions
/conversions/{uuid}             Conversion page, latest Attempt selected
/conversions/{uuid}/attempts/{n} Conversion page, Attempt n selected
```

Every conversion route resolves the current `image2pptx_session` and aborts with 404 when the Conversion does not belong to it. A copied URL without the matching cookie is not useful.

### 6.2 Home Page

- Upload area with drag-and-drop and multi-file picker.
- Selected files appear as a local queue with waiting/uploading indicators before submit.
- Copy: "PNG or JPEG. Up to 10 MB, up to 4096 px on the longest side."
- Copy: "Temporary files are deleted after {N} hour(s). Download files you want to keep."
- Sidebar: recent Conversions in this session only.
- Sidebar: delete-all action for temporary Conversions in this session.
- No public access, slug, or copy-link language.

### 6.3 Conversion Page

- Input preview.
- Output preview: skeleton while running, inline PDF when available, disabled/partial/failed message when needed.
- Download `.pptx` for ready artifacts; download `.pdf` only when PDF rendering is enabled and successful.
- Attempt switcher (`a1`, `a2`, ...).
- Regenerate button.
- New image button/link back to upload.
- Delete temporary conversion action with confirmation.
- Delete-all action remains available in the recent-Conversions sidebar.
- Session-only notice: "This conversion is only available in this browser session. Download files you want to keep."

## 7. Validation And Conversion

Validation runs for every uploaded image. A single multi-file HTTP request creates no Conversion rows or temporary artifacts if any image in that request fails.

Validation order:

1. Web server body cap.
2. Laravel file presence/size rules.
3. `finfo` content sniff.
4. Extension/MIME agreement.
5. `getimagesize` dimensions.
6. Python/Pillow decode and normalization inside the bridge.

Unsupported formats return specific validation errors for WebP, SVG, GIF, HEIC, TIFF, and BMP. Empty/corrupt files return a stable user-facing error and write no temporary artifact.

The bridge normalizes with Pillow:

- Decode probe.
- EXIF rotation baked in.
- PNG alpha flattened to white.
- CMYK JPEG converted to RGB.
- Metadata stripped before conversion.

PHP invokes the bridge with Symfony Process argv only:

```text
{python} python/bridge.py input.{ext} -o output.pptx --lang auto --max-inpaint-size 2048
```

The Python wrapper invokes `px-image2pptx` with argv only and never passes AI/Gemini flags.

## 8. Security And Abuse Controls

- No public conversion access. Matching session ownership is required for read, regenerate, delete, preview, and download.
- Upload, regenerate, and delete require CSRF and Origin/Referer checks.
- Download routes require signed URLs and session ownership. Missing/tampered/expired signatures return 403; wrong session returns 404.
- Files are outside the web root and never served through a symlink.
- Download filenames are server-derived ASCII: `conversion-{uuid_prefix}-a{n}.{pptx|pdf}`.
- HTML responses set `Content-Security-Policy: frame-ancestors 'none'` and `X-Frame-Options: DENY`.
- Download responses set `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, and `X-Content-Type-Options: nosniff`.
- Inline PDF responses use `Content-Disposition: inline` and `Content-Security-Policy: sandbox`.
- Upload rate bucket: 5 uploads per IP per 15 minutes.
- Conversion-read bucket: 60 GETs per IP per minute on `/conversions/{uuid}*`.
- Processing concurrency: only one Attempt is converted at a time. A session may own multiple pending Attempts after a batch upload.
- Global queue depth cap: reject uploads/regenerations beyond 50 pending jobs with 503.
- Static grep gate rejects `gemini`, `googleapis`, or `generativeai` in the installed package path.
- Architecture tests forbid `shell_exec`, `system`, raw `proc_open`, and `Process::fromShellCommandline` in app code.

## 9. Lifecycle

`php artisan conversions:reap`:

- Marks stale running Attempts as `failed/interrupted`.
- Applies the global temporary byte cap oldest-Conversion-first.
- Deletes Conversions older than `config('conversion.ttl_hours')`, anchored on `conversions.created_at`.
- Skips Conversions with pending/running Attempts.
- Deletes orphaned temporary conversion directories that no longer have a database row.
- Logs deletion and skip counts.

The scheduler runs this command every 10 minutes, so a normal temporary file is eligible after one hour and usually removed within the next scheduled pass. User-initiated delete removes files immediately.

Queue worker boot also marks stale running Attempts interrupted, so a crashed worker does not leave a permanent running state.

## 10. Local Verification

Required local commands:

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

Final browser QA must use the real example files in `/Users/fabianwesner/Workspace/image-to-powerpoint/examples` when available.
