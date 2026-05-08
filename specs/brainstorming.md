# Brainstorming: Image-to-PowerPoint Web App

> Working document. Each contributor signs their entries. Mark sections DONE only when all three contributors have explicitly agreed.

## Context (from user brief)

- Users upload an image, get back a PowerPoint and a PDF.
- They see a preview of the uploaded image and the resulting deck.
- They can iterate on versions.
- Stack: Laravel 13, React 19 + Inertia v3, Shadcn (default look), Tailwind v4.
- Conversion via https://github.com/JadeLiu-tech/px-image2pptx (Python lib).
- PDF via separate library (no LLM APIs). Only local models allowed.
- No registration/login. Files in tmp folder. Session stays active forever.
- DB if needed: SQLite.

---

## Open Questions

(populate here, then resolve below)

---

## Discussion

<!-- Each entry: ### [Role] @name — topic
Then content. Sign every entry. -->

### [PO] PO-Alex, Round 1, product framing and core flow

**Open questions (product)**

1. What does "iterate on versions" actually mean for the user? Three plausible readings:
   a) Re-run the conversion on the same image with different settings (template, slide count, layout style).
   b) Upload a new/edited image and produce a new deck linked to the previous one.
   c) Edit the generated pptx content (text, slide order) inside the app.
   My instinct, MVP picks (a) and (b); (c) is out of scope because we have no LLM and no slide editor.

2. What is one "project" in the data model? I propose: a Project owns one source image plus an ordered list of Versions. Each Version has its own pptx and pdf artifacts. The user lands on a Project page where the active version is shown and previous versions are accessible.

3. How does the user find their work after closing the tab? Options:
   a) Session cookie remembers them; sidebar lists their Projects.
   b) Each Project gets an unguessable URL the user can bookmark.
   I propose both: cookie for the default sidebar, URL slug for sharing/bookmarking.

4. Conversion is async (Python subprocess will not be instant). What does the UI show during processing? Skeleton plus poll, or Inertia deferred props plus Echo/SSE? For MVP I propose Inertia polling every 2s on the project page until the version status flips to ready.

5. What happens when conversion fails? The user must get a clear error and the failed Version should still appear (status: failed) so they can retry without losing context.

**Initial product proposals (MVP)**

- Single-page upload to landing screen, drag-and-drop or file picker, 20 MB cap, png/jpg/webp.
- After upload, redirect to /projects/{slug} which shows: input image preview on the left, current Version output on the right (pptx thumbnail or embedded pdf preview), version list below.
- "Generate new version" button on the project page reruns conversion (optionally with a small settings panel: template choice if the python lib supports it, otherwise just a regenerate button).
- Download buttons for .pptx and .pdf. Preview the pdf inline using browser pdf viewer (object/iframe), since pdf is the cheapest reliable preview of a deck.
- Sidebar listing the session's recent projects (cookie-scoped). Clicking restores the project.
- "Delete project" action that nukes the tmp files and the DB rows.

**Roadmap thinking (post-MVP)**

- v1.1: per-version notes/title; rename a project.
- v1.2: queue worker (Laravel queue) instead of synchronous subprocess; progress events.
- v1.3: bulk upload (multiple images, one deck per image, or one deck with many slides if the lib supports it).
- v1.4: slide thumbnails as a strip preview (render via LibreOffice).
- v2.0: optional local LLM integration for slide text rewriting (deferred, explicitly out of scope now).

**Tech/risk concerns from a product angle**

- Python bridge: shelling out from PHP is fine but reliability is the whole product. We need a clear contract (input path, output dir, JSON status) and tests around the bridge with real fixtures, not mocks.
- "Session stays active forever": cookies will get cleared by browsers eventually. We should be honest in the UI: "your projects live on this device until cookies are cleared, please download files you want to keep."
- tmp folder: on most servers /tmp is wiped on reboot. We need to either accept that (good, matches "must download to keep") or use storage/app/tmp under the Laravel app. I lean toward storage/app/private/sessions/{session_id}/ which is on disk but outside the public web root. We must NOT serve files via a public storage symlink; downloads must go through a controller with session check.
- PDF generation: LibreOffice headless is the most reliable option but it is a heavyweight dependency. We should confirm Herd or the deployment target has it; otherwise fall back to a php library and document the tradeoff.
- Preview fidelity: pptx rendered to pdf is what the user "sees" as output. We should make that explicit so expectations match.

— PO-Alex

### [PO] PO-Sam, Round 1, edge cases, scope discipline, abuse, telemetry

I am taking the angle of "what does this product actually mean once a real user touches it." I deliberately disagree with parts of the brief and parts of Alex where it matters.

**Open questions**

1. What does "iterate on versions" actually mean? Three plausible readings, all very different to build:
   a. Re-upload a different image, app keeps a list of past attempts in the same browser session.
   b. Same input image, re-run with different conversion parameters (slide count, aspect ratio, theme), producing variant outputs.
   c. Edit the resulting deck in the app (reorder slides, change titles) and re-export.
   I disagree with Alex that we need both (a) and (b) at MVP. My vote, (a) only, plus a single optional toggle if and only if the lib exposes one cheaply. (c) is a separate product.

2. What is a "session" when there is no auth? Cookie session ID with no expiry, or localStorage UUID? If the user clears cookies do they lose access to their tmp files? Multiple tabs, do they share workspace? "Session stays active forever" plus "tmp folder" plus "must download to keep" is internally inconsistent unless we define exactly when files are reaped. The session record can be durable, the files behind it cannot.

3. What is the input image contract? File types (png, jpg, webp, heic, svg, gif, tiff?), max dimensions, max bytes, EXIF orientation, transparency, animated frames. Without bounds the upstream Python lib will OOM or hang on adversarial input. I want hard limits in the spec.

4. What does success look like for the .pptx? One slide containing the image, or does px-image2pptx slice the image into multiple slides somehow? The product behaviour depends entirely on what the lib actually does and we should read its source before specifying anything.

5. Is the PDF a render of the .pptx (so they always match) or an independent rendering? They are not the same artefact, and users will assume they always agree. I propose PDF is always derived from the .pptx via LibreOffice headless. One source of truth.

6. Where does the Python process actually run? Same Herd machine, subprocess from PHP, or a queue worker? Synchronous request-response, or async with polling? This is load-bearing and Alex has assumed polling, I agree, but we should write down the contract: PHP spawns via `proc_open`, never `shell_exec`, never `system`, all paths passed via argv, never interpolated.

7. Abuse mitigation with no auth: what stops one IP uploading 10,000 large images and filling tmp? Per-IP rate limit (Laravel `RateLimiter`), global concurrent-job cap, per-session size budget, hard ceiling on tmp bytes with oldest-first eviction. None of these are in the brief, all of them are needed.

**Initial product proposals (MVP)**

- One image in, one .pptx and one .pdf out. Re-upload creates a new "version" in the same session. Versions newest-first. User can re-download any version until reaped.
- PDF is generated from the .pptx via `soffice --headless --convert-to pdf`. No second PDF library. The .pptx is the source of truth.
- Conversion is asynchronous, upload returns immediately with a job ID, frontend polls. Synchronous would have Herd time out a 30s LibreOffice call.
- File reaping: tmp files older than 24 hours are deleted by a scheduled artisan command. UI states this clearly. Session record stays in DB, files behind it expire.
- Input limits MVP: PNG, JPG, WEBP only. Max 20 MB, max 10000 px on the longest side. EXIF orientation honoured before handing to Python. Reject everything else with a specific error message per failure type.
- One concurrent job per session. Max 5 versions retained per session, oldest-evicted. Hard global cap on tmp bytes (e.g. 5 GB) with oldest-first eviction.
- Telemetry: Laravel logs only. Job id, duration, exit code, input size, output size, success/fail. Nothing leaves the box. No analytics SDK, no Sentry unless the user adds one.

**Explicitly out of MVP**

- No deck editing, no slide reorder, no title editing, no theme picker.
- No multi-image upload, no zip upload, no URL upload, no clipboard paste.
- No account, no email-the-link, no share link, no project rename.
- No internationalisation. English only. (Strings can sit in a single dictionary so a future locale is cheap, but no second locale ships.)
- No accessibility commitments beyond baseline: semantic HTML, keyboard-reachable controls, alt text on the input preview, visible focus rings from Shadcn defaults. No screen reader QA pass committed in MVP.
- No background queue worker. `proc_open` from a controller-spawned PHP process is enough for MVP. Queues are post-MVP.
- No public sharing or unguessable-URL handoff. Sidebar history is cookie-scoped only.

**Tech and risk concerns**

- Python bridge is the highest-risk seam. Pin Python version, install requirements into a project-local venv (e.g. `storage/app/python-venv/`), log stderr, never interpolate file paths into a shell string, always pass argv to `proc_open`. Bridge needs a JSON status contract so PHP can fail loudly on malformed output.
- LibreOffice headless on a dev Herd box is fine. On Laravel Cloud it may not be available at all. If we deploy anywhere other than localhost we need to confirm `soffice` is installable. If it is not, we are forced to a different PDF strategy and end up with two sources of truth. Worth raising now and not after we ship.
- "No LLM APIs, local only" combined with px-image2pptx: I want to confirm the lib does not call out to a vision model under the hood. Some image-to-pptx libs do exactly that. If it does, we either fork or pick another lib. Reviewer-Riley should check this in round 2.
- Storage path: the brief says "tmp folder" but I read that as "ephemeral, not durable" rather than literally `/tmp`. I propose `storage/app/private/sessions/{session_id}/v{n}/`, inside the Laravel storage tree, served only via signed download routes. Never via a public symlink. Agree with Alex on this point.
- DB: SQLite is fine, but only as an index of sessions, versions, job state, byte counts, timestamps. Artefacts stay on disk. If the DB is lost the worst case is users lose version history, not in-flight jobs.

**Disagreements I want to flag now**

- With Alex: I do not want any "settings panel" in MVP, even a tiny one. If the lib exposes parameters cheaply we surface a single regenerate button that re-runs with different defaults, not a panel. The moment we add a panel the product becomes "configurable converter" and scope explodes.
- With Alex: I do not want post-MVP "v2.0 optional local LLM" in the roadmap at all. It contradicts the user's "no LLM APIs" rule even if the model is local, because it changes the product's identity. Drop from roadmap.
- With the brief: "session stays active forever" cannot be taken literally for files. Session record durable, files reaped on a fixed window. If the user disagrees we redesign storage and accept a much higher tmp budget.

— PO-Sam

### [Reviewer] Reviewer-Riley, Round 1, sceptical review of brief and PO proposals

I read the px-image2pptx README before writing this. Concerns first, then proposals, then explicit asks.

**Hard facts about px-image2pptx (verified from the README)**

- License is MIT. Good, we can vendor or pip-install it.
- Python 3.9+. The full-quality pipeline pulls `[ocr]` (paddleocr, paddlepaddle) and `[inpaint]` (torch, simple-lama-inpainting). That is multi-gigabyte install footprint and a one-time ~370 MB model download (PP-OCRv5 plus big-lama). This is not "pip install and go". Models MUST be pre-fetched at deploy time, not lazily on first user request, or a request will time out trying to pull 200 MB.
- Input is PNG or JPG only. WebP is explicitly unsupported. Sam and Alex both proposed accepting WebP. Either we transcode WebP to PNG server-side before invoking the pipeline, or we refuse it at the boundary. I prefer refuse for MVP, fewer moving parts.
- Recommended max dimension ~4000 px. We must enforce a max pixel count, not just a byte count, because a 5 MB compressed PNG can decompress to 30000x30000.
- The README documents an optional "AI mode" that calls Google Gemini for per-use pricing. The brief explicitly forbids LLM APIs. We MUST NOT install or invoke that path. Verify in our wrapper that `--ai` (or whatever the flag is) is never passed and the corresponding env vars are unset. Ideally do not install the related extra at all.
- Quality is bounded by design: "complex backgrounds", "dense charts", "light text on dark backgrounds", and "very thick fonts" are flagged in the README as poor. Users will assume "image to pptx" means perfect reproduction. Product copy must set expectations or we will field complaints.
- Runtime is 5-16 seconds per conversion best case on M1 Pro. On a mid-range cloud CPU box it will be longer. This shapes our timeouts and concurrency.

**Threats and silent failure modes I want to be on the record about**

1. Decompression bombs / pixel bombs. Pillow has `Image.MAX_IMAGE_PIXELS` defaulting to ~89 megapixels, but it WARNS by default and only raises if you set `MAX_IMAGE_PIXELS = None` or it is exceeded by a factor of 2x. We should set our own pre-flight check in PHP using `getimagesize` (rejects pixel count up front) BEFORE handing the file to Python. Otherwise the worker OOMs.
2. Polyglot files. A JPEG that is also a valid PHP/HTML file. Storage MUST live outside the document root, downloads MUST go through a controller, never via a public symlink. MIME detection MUST be content-based (`finfo`), never trust the client `Content-Type` or extension.
3. SVG. Refuse for MVP. SVG can carry script and external refs and px-image2pptx does not support it anyway.
4. Animated GIF and HEIC. Refuse for MVP. The lib does not support them.
5. Path traversal and shell injection. The Python pipeline takes paths. Use `Symfony\Process` with the array-form constructor (argv), never string concatenation, never `shell_exec`. Generate UUID filenames server-side. The user's filename is for display only and never reaches the filesystem.
6. Subprocess hangs. The pipeline is interactive on a bad day. Hard wall-clock timeout (60-90 s including LibreOffice render). Kill the process group, not just the parent: paddle/torch and soffice each fork helpers. `proc_terminate` alone leaks workers. Sam's `proc_open` proposal must do `posix_setsid` or use `Symfony\Process` which handles it.
7. Concurrent abuse. With no auth, anyone can hammer the upload endpoint. Need: per-IP rate limit, per-session concurrent cap, global queue depth cap, hard tmp byte ceiling with oldest-first eviction. None of this is in the brief.
8. Disk fill. The brief says "session stays active forever" and "tmp folder" and "must download to keep". Those three are mutually contradictory. The only coherent interpretation: session POINTERS persist, ARTIFACTS expire on a fixed TTL. I align with Sam here. I propose 7 days (Sam said 24 hours, see disagreement below).
9. PDF preview leak. If we embed PDF inline, we serve it from our origin. Browsers will not generally execute PDF JS in PDF.js, but Acrobat plugins are wild and embedded JS in a malicious PDF is a real category. We GENERATE the PDF, but if a future version ever lets users upload a PDF for re-conversion, we are exposed. Set `Content-Security-Policy: sandbox`, `X-Content-Type-Options: nosniff`, and serve from a download route, not a static path.
10. EXIF rotation. Phone uploads carry rotation metadata that the Python lib may or may not honour. Strip and bake-in rotation server-side before invoking the pipeline. Otherwise the user uploads a portrait photo and gets a sideways slide.
11. Refresh during upload. Standard browser behaviour. The form must be idempotent on the server side (per-upload nonce) so a client-side retry does not create two projects from the same upload.
12. ZIP-bomb pptx. We generate the pptx, so the input side is safe today. Flag for the future: any feature that re-imports a pptx must treat it as untrusted ZIP with depth and ratio limits.
13. The pptx itself can leak environment data via `python-pptx` (e.g., default author "Microsoft Office User"). Set author/created-by metadata to a fixed string in our wrapper.

**Disagreements with the POs (call them out by name)**

- With Alex: 20 MB upload cap is too high for an unauthenticated public endpoint. I push for 8 MB. We can lift it later if it bites real users.
- With Alex: PNG, JPG, WebP at MVP. Drop WebP; px-image2pptx does not accept it and transcoding is a separate moving part for no MVP benefit.
- With Alex: "post-MVP v2.0 optional local LLM integration" should NOT be in the roadmap. The brief's spirit is "no LLMs". Even local ones blur the product identity. Sam already said this; I second it. Strike from roadmap.
- With Alex: "settings panel" for regenerate. I agree with Sam, no panel at MVP. Just a "Regenerate" button. If the lib happens to expose a parameter cheaply and harmlessly we can hide it behind an "advanced" toggle later, not now.
- With Sam: 24-hour TTL is too aggressive. A user uploads on Friday and tries to download on Monday and finds nothing. I push for 7 days, configurable. We can shorten if disk pressure forces it.
- With Sam: "no background queue worker" at MVP, controller-spawned `proc_open` is enough. Disagree. A controller request that holds a 16 s subprocess plus a 5 s LibreOffice call uses an HTTP worker for 20+ seconds and will saturate FPM under tiny load. Use Laravel's database queue with one worker. It is not extra complexity worth avoiding. I am willing to negotiate this in round 2.
- With Sam: "5 versions per session, oldest evicted". This is fine in principle but we should be explicit that eviction also deletes the artifacts and the DB rows, and that it cannot evict an in-flight job.
- With both: "session" needs precision. Cookie-based or localStorage-based? I push for: HttpOnly Secure SameSite=Lax cookie carrying a long random session_token, server keeps a `sessions` table mapping token to project list. localStorage gives JavaScript-readable IDs which is worse for XSS containment.
- With both: project URL is the canonical access control. The cookie is the convenience layer ("show me my recent projects"). Whoever has the slug has access. UI copy must say so. Do not pretend the cookie is auth.

**Proposals I want consensus on in round 2**

A. Artifact TTL: 7 days, scheduled artisan command, configurable via env.
B. Upload limits: 8 MB byte ceiling AND 4000 x 4000 px ceiling, validated server-side BEFORE invoking the pipeline.
C. Accepted formats: PNG and JPEG only. No WebP, no SVG, no GIF, no HEIC, no TIFF, no BMP.
D. PDF backend: LibreOffice headless via `soffice --headless --convert-to pdf`, derived from the generated pptx so they cannot disagree. If LibreOffice is unavailable on the deploy target, we escalate scope before coding starts.
E. Conversion: queued (Laravel database queue), one worker, hard timeout 90 s including PDF render. Process group killed on timeout. Job rows persist failure with a clean user-facing message.
F. "Anyone with the slug can access" is part of the explicit threat model, in UI copy on the project page.
G. Disable px-image2pptx Gemini path explicitly. Do not install AI extras. Verify no env var or default config triggers it.
H. Storage: `storage/app/private/projects/{project_uuid}/source.{ext}` and `versions/{n}/output.pptx|pdf`. Index in SQLite. Sessions table maps cookie token to project. Downloads via signed controller route only.
I. Rate limits: 5 uploads per IP per 15 min, 1 concurrent conversion per session, global queue depth cap (e.g. 50 pending).
J. EXIF rotation baked in server-side before pipeline.
K. PDF metadata and pptx metadata sanitised (no leaked OS user, no machine name).

— Reviewer-Riley

---

### [PO] PO-Alex, Round 2, cross-responses and concessions

I am updating my round 1 stance based on what Sam and Riley raised. I move on the points where they are right; I push back where I still disagree.

**Versioning model: I drop "(b) re-upload" from MVP and keep "(a) only"**

Sam is right. My round 1 had Project owns Versions, where each Version could come from either a re-run on the same image OR a new uploaded image. Sam argues for (a) only meaning re-upload creates a brand new project entry, not a version under an existing project. After thinking about it: if the user uploads a different image, what they want is a different deck. Treating that as "version 2" of the original is conceptually wrong, the only thing they share is that the user happens to be the same person.

Concession: a Project has one source image, full stop. Versions under a Project are all reruns on that same source image (Sam's "(b) re-upload" rejected; I am also dropping my own "(b)"). A new image upload creates a new Project. The sidebar lists all Projects in this session. I move "regenerate" from "small settings panel" to a single button that calls the Python lib again with the same input. If px-image2pptx is deterministic, regenerate produces the same bytes (we should detect and skip), if not it produces a variant and we keep it. Confirms Sam's "no settings panel".

**PDF library: I concede to LibreOffice headless, derived from pptx**

Sam and Riley both proposed `soffice --headless --convert-to pdf` from the generated pptx, one source of truth. I had this as a concern in round 1 ("LibreOffice may not be on the deploy target") rather than a proposal. I now agree it is the right call for MVP, with one caveat: we MUST verify availability on the deploy target before locking it in. If Laravel Cloud or wherever the user wants to deploy does not have soffice, we have to escalate. Riley flagged this in proposal D and I accept proposal D as written.

I reject any "second PDF library as fallback". One backend, fail loudly if missing, document the dependency. Two PDF backends is two products.

**Image cap: I concede 8 MB, but push back on 4000 px**

Riley's 8 MB is reasonable for an unauth public endpoint. I had 20 MB which was anchoring on "what would a phone photo plausibly be" not on threat surface. Conceding to 8 MB.

On dimensions: Riley proposed 4000 x 4000 px. The README says ~4000 max recommended for the lib's output quality, but our pre-flight rejection threshold is a different number than the lib's quality-curve elbow. I would set the rejection threshold higher (e.g. 6000 px on longest side) so the lib gets to handle the borderline cases its way, and we only refuse the actually-degenerate inputs. But this is a small disagreement; if Riley insists on 4000 I will not block on it.

Also accepting Riley's pixel-bomb defence: enforce both byte cap AND total pixel count cap (e.g. max 32 megapixels) using `getimagesize` BEFORE handing to Python.

**Accepted formats: I concede WebP. PNG and JPEG only.**

Riley pointed out the lib does not accept WebP; I had it in MVP scope. Transcoding adds a moving part for no benefit. Drop it. PNG + JPEG only.

**Tmp lifecycle / TTL: 7 days, not 24 hours**

Sam said 24 h, Riley said 7 d. I align with Riley. The friday-upload-monday-download case is realistic and 24 h is gratuitous. The user explicitly said "session stays active forever" so they value persistence over disk cost. 7 days is the longest reasonable middle ground, and we clearly tell users in the UI: "your files live for 7 days, please download to keep."

We should make this `IMAGE2PPTX_TTL_DAYS` env-configurable per Riley's proposal A. Default 7, deploy can shorten if disk pressure hits.

**Queue worker vs synchronous proc_open: I lean Riley's way**

Sam wants no queue worker for MVP, just controller-spawned `proc_open`. Riley wants a Laravel database queue with one worker.

I lean Riley. A 16 s subprocess plus 5 s LibreOffice means a single upload occupies an FPM worker for 20+ seconds. Even at toy scale (3 simultaneous users) this turns into queueing on the FPM side, which is the worst kind because it is invisible. A database queue with a single worker is barely more code than `proc_open` and gives us a clean place to attach timeouts, kill on overrun, retry semantics, and dead-letter.

I ask Sam to concede this in round 3. The complexity argument cuts the other way: queue infra is simpler than reasoning about FPM blocking.

**Regenerate without re-upload: clearly yes**

Lead asked us to converge on this. My answer: yes, the project page MUST have a "Regenerate" button that creates a new Version on the same source image. No re-upload required. This is the entire point of "iterate on versions". One button, no settings panel (Sam wins on no-panel; I win on the button existing).

**Things I still hold from round 1**

- Project URL slug is canonical access (Riley's F). Cookie is convenience layer.
- Files outside web root, served via signed download controller (everyone agrees).
- Inertia polling on the project page until version status flips (everyone roughly agrees).

**Things I am dropping**

- "v2.0 optional local LLM integration" from my round 1 roadmap. Both Sam and Riley pushed back, and they are right that it changes the product identity. Strike.
- 20 MB cap. Now 8 MB.
- WebP support. PNG/JPEG only.
- "Settings panel" framing for regenerate. Just a button.
- (b) re-upload as a new version under existing project. Re-upload makes a new Project.

**Open ask for Riley before round 3**

Can you confirm from the px-image2pptx README whether the Gemini "AI mode" is opt-in via flag/env, or could it ever fire from a default config? Your point G says "verify no env var triggers it"; I want that verification on the record before round 3 closes. If we cannot prove it cannot fire, we fork the lib and rip out the AI path.

— PO-Alex

### [PO] PO-Sam, Round 2, responses to Riley and Alex

Alex moved hard toward Riley between round 1 and round 2. I am updating where they convinced me, holding where I still disagree, and making explicit calls for round 3. Names called out so deltas are auditable.

**Conceded to Riley (and Alex, where they aligned)**

1. **Drop WebP.** Riley read the README; px-image2pptx accepts PNG/JPG only. Transcoding WebP server-side is one more moving part with zero MVP upside. Accept PNG and JPEG only. I revise my round-1 limit list accordingly.
2. **4000 x 4000 px hard cap.** Riley is right that the README recommends ~4000 max. My 10000 px figure was a generic decompression-bomb threshold, not lib-specific. Agree on 4000 x 4000. Add a separate megapixel cap (16 MP) so an extreme aspect ratio cannot sneak through. Alex pushed for 6000 px; I lean Riley because lib quality degrades and an unauth endpoint is the wrong place for tolerance.
3. **Pre-flight pixel check via `getimagesize` in PHP** before handing the file to Python. Rejects pixel bombs cheaply. Missed in round 1.
4. **EXIF rotation baked in server-side** before pipeline. In MVP.
5. **Process-group kill on timeout.** `proc_terminate` alone leaks paddle/torch/soffice helpers. Use `Symfony\Process` (which sets the session) instead of raw `proc_open`. Revising my round-1 phrasing.
6. **pptx and pdf metadata sanitised** to a fixed string. In MVP.
7. **Explicitly disable px-image2pptx Gemini path.** Do not install AI extras. CI-level check that the corresponding flag and env vars are not present. Brief is "no LLM APIs"; we enforce at install time, not just runtime.
8. **Per-upload nonce** so refresh-during-upload does not create duplicate projects. In MVP.
9. **HttpOnly Secure SameSite=Lax cookie** carrying a long random session_token, server-side `sessions` table. Not localStorage. Riley is right on the XSS containment angle.
10. **"Anyone with the slug can access"** in the project page UI copy. Not a security flaw if we name it; it is one if we hide it.
11. **Queue worker, not controller `proc_open`.** Both Riley and Alex pushed me on this. Their FPM-blocking argument is right: a 16 s subprocess plus a 5 s soffice render in an FPM worker means three simultaneous users queue on FPM invisibly. I yield. Use Laravel queue, `database` driver on SQLite, single worker, started via `composer run dev`. We do NOT introduce Redis, Horizon, or supervisord for MVP. Job dispatched on upload, frontend polls for status. If real load proves SQLite-backed queue insufficient post-launch, we revisit then.

**Holding against Alex and Riley**

1. **TTL: 72 h, not 7 d, env-configurable.** Riley's "Friday upload, Monday download" scenario is fair, but 7 days at 12 MB inputs and up to 5 versions per session at multi-MB outputs each, multiplied by 20 projects per session, multiplied by however many sessions accumulate, makes the disk-fill blast radius materially larger and there is no auth wall to slow abuse. 72 h covers the weekend case while keeping the eviction window short enough that a single weekend of abuse cannot fill the box. I propose env var `ARTIFACT_TTL_HOURS` default 72, so deploy can extend to 168 (7 d) if disk is plentiful or shorten if abuse hits. UI copy reflects whatever the configured value is. I acknowledge I am the lone holdout; if Riley and Alex both insist on 7 d, I will yield in round 3 conditional on a hard global tmp ceiling (5 GB) being explicit.
2. **Upload cap 12 MB, not 8 MB.** Alex and Riley both landed on 8 MB. I want 12 MB. 8 MB rejects a non-trivial fraction of legitimate phone uploads (modern PNG screenshots from a tablet can be 8-10 MB; 48 MP phone JPEGs at quality 90 land near 10 MB). 12 MB still bounds an attacker meaningfully. Env-configurable `UPLOAD_MAX_BYTES`. If both of you insist on 8, I yield in round 3.

**Reaffirming positions where the team has converged**

- No settings panel. Just a Regenerate button on the project page. Aligned.
- Drop "v2.0 optional local LLM" from the roadmap entirely. Aligned.
- LibreOffice headless from the generated pptx is the only PDF backend, conditional on confirmed `soffice` availability on the target environment. Aligned. We need a written confirmation from the user before round 3 closes.

**Versioning model (alignment with Alex round 2)**

Alex moved to "Project owns one source image, Versions are reruns on that source." I am aligned, with these constraints we should write into the spec:

- A Project has exactly one source image and an ordered list of Versions. Versions are append-only.
- Re-uploading a different image creates a NEW Project, not a new Version. Sidebar lists Projects newest-first.
- Regenerate produces a new Version with the same source image. If px-image2pptx is deterministic, two consecutive regenerates produce identical bytes. We should: (a) accept that and let the user create duplicates if they want (cheapest), or (b) hash the output and skip recreation if identical (saves disk and confusion). Open question for round 3, my preference is (a) for MVP.
- Max 5 Versions per Project, oldest evicted on new creation. Eviction deletes artefacts and DB rows. Cannot evict an in-flight Version.
- Max 20 Projects per session. Sidebar lists newest-first.
- Each Project has a UUID slug used in the URL. Cookie session is convenience layer.

**Storage path (alignment with Riley)**

`storage/app/private/projects/{project_uuid}/source.{ext}` and `storage/app/private/projects/{project_uuid}/versions/{n}/{output.pptx,output.pdf,job.log}`. SQLite tables: `projects`, `versions`, `sessions`, `session_projects`. Downloads via signed controller route only. Never via public symlink.

**New questions for round 3**

- Do we ship a "Delete project" button in MVP? My vote yes: one route plus one confirm dialog, gives the user a way to free their session without waiting on TTL.
- Do we want a "Cancel running conversion" affordance? My vote no for MVP: complicates queue worker contract for marginal benefit.
- Deterministic regenerate: skip duplicate output, or always create a new Version? My vote always create. Riley/Alex thoughts?
- Pre-deploy: written confirmation from the user that LibreOffice headless is available wherever this runs. If it is not, we escalate before coding starts. **This is a blocker on round 3 closing.**
- The user said "no LLM APIs" but allowed "local models". px-image2pptx with the `[ocr]` extra installed pulls paddleocr (~370 MB model). That is a local model, not an API call. I read this as in-scope per the brief, but want explicit sign-off before we commit to the install footprint. Riley's footprint warning was on the record but did not get a verdict. Round 3 must answer.

**My new vote on Riley's lettered proposals (post round-2 movement)**

- A. TTL 7 d: counter-propose 72 h, env-configurable. **Open in round 3.**
- B. 8 MB and 4000x4000: counter-propose 12 MB, agree 4000x4000, add 16 MP cap. **Open in round 3.**
- C. PNG + JPEG only: AGREE.
- D. PDF via LibreOffice from the generated pptx: AGREE, conditional on confirming `soffice` availability on the target environment.
- E. Queued conversion, 90 s timeout, process-group kill: AGREE.
- F. "Anyone with the slug can access" in UI copy: AGREE.
- G. Disable Gemini path, do not install AI extras: AGREE.
- H. Storage layout under `storage/app/private/projects/{uuid}/...`: AGREE.
- I. Rate limits 5/IP/15min, 1 concurrent per session, global queue depth 50: AGREE.
- J. EXIF baked in server-side: AGREE.
- K. Metadata sanitisation: AGREE.

— PO-Sam

### [Reviewer] Reviewer-Riley, Round 2, hold the line, answer Alex's open ask, settle the last two open items

Sam has yielded on queue, format, EXIF, polyglot, slug-as-access, metadata sanitisation, Gemini install gate, cookie-vs-localStorage, and per-upload nonce. Alex yielded on TTL, queue, format, settings panel, and the v2 LLM roadmap item. We are in good shape. Two items remain open: TTL (7 d vs 72 h) and upload cap (8 MB vs 12 MB). I will close my position on both, answer Alex's open ask about Gemini, and push three new items I want locked in round 3.

**Answering Alex's open ask: can the Gemini "AI mode" fire by default?**

I went back through the px-image2pptx README CLI surface specifically for AI / Gemini flags. The documented CLI flags are: `-o/--output`, `--ocr-json`, `--lang`, `--sensitivity`, `--dilation`, `--min-font`, `--max-font`, `--skip-inpaint`, `--max-inpaint-size`, `--work-dir`. The documented Python kwargs are: `lang`, `sensitivity`, `dilation`, `skip_inpaint`, `max_inpaint_size`. NONE reference Gemini, AI mode, an API key env var, or a network call.

The README mentions "AI mode powered by Gemini, per-use pricing" as a contrast to "Free mode (ONNX, in-browser)". Reading it carefully, the AI mode appears to be a feature of the upstream demo/website, NOT the pip-installable Python library. The Python lib is the "free mode" path.

For the spec, this means:
1. The pip-installable `px-image2pptx` library does not have a Gemini code path we can accidentally trigger via flag or env in the documented surface.
2. We MUST still vendor or pin a specific commit/version, install ONLY the `[ocr,inpaint]` extras (NOT `[all]`), and add a deploy-time check that grep-scans the installed package for the strings `gemini`, `googleapis`, `generativeai`, and any `https://` literal that is not in a comment. If anything matches, fail the deploy.
3. Add a Pest feature test `tests/Feature/PythonBridgeTest.php` that runs the pipeline against a small fixture and asserts the worker produced a valid pptx without making any outbound network call. We can sandbox this with `unshare -n` on Linux CI; on macOS Herd dev we just trust the static grep plus integration test.
4. Document this in the spec under "Confirmed safe": the Python library has no LLM call path in the version we install.

That is my Gemini answer, on the record. Alex, this should close your open ask.

**TTL: I am yielding to Sam on conditions, not standing pat on 7 d**

Sam's argument is fair: an unauth public endpoint at 12 MB inputs and 5 versions and 20 projects per session multiplies fast. I was anchored on "user expectation"; Sam is anchored on "abuse blast radius". Both are real.

Compromise I will sign on: default TTL 7 days, hard global tmp ceiling 5 GB with oldest-Project-first eviction kicking in BEFORE TTL if the cap is hit. So under normal load, users get 7 days. Under abuse, the disk-fill scenario is bounded by the ceiling, not by a shorter clock. This is strictly better than 72 h because it gives legitimate users the full week AND it caps abuse harder than a time-based reaper alone.

Implementation:
- Scheduled hourly: `php artisan projects:reap`. Deletes Projects past `PROJECT_TTL_DAYS` (default 7).
- Same command also evaluates `du -sh storage/app/private/projects` (or sums byte counts in the DB). If total exceeds `PROJECT_TMP_BYTES_CAP` (default 5 * 1024 * 1024 * 1024), it deletes oldest Projects until under the cap.
- Both thresholds env-configurable.
- In-flight Projects skipped.
- Every deletion logged with project UUID, age, byte count.

If Sam still wants 72 h after seeing the global ceiling, I yield, but I think the ceiling is the correct primitive and the TTL should match user expectation.

**Upload cap: 10 MB compromise**

Sam wants 12 MB, Alex and I want 8 MB. Sam's data point is real: modern phone screenshots can hit 8-10 MB. Splitting the difference at 10 MB:
- Covers Sam's phone-screenshot case.
- Still meaningfully bounds attacker payload.
- `UPLOAD_MAX_BYTES` env-configurable so deploy can adjust either way.

If Sam strongly prefers 12, I yield; the difference between 10 and 12 is rounding error compared to either getting it wrong by a factor of 10. The non-negotiable is that bytes is NOT the only check: pixel count check via `getimagesize` MUST run first.

**Pixel cap: 4000 x 4000 with 16 MP total, locking it**

Sam aligned with my original 4000 x 4000 against Alex's 6000. Two votes plus the README's own recommendation. We lock 4000 px longest side, 16 MP total. Alex, please yield.

**Three new items I want locked in round 3**

1. **CSRF and origin discipline.** Inertia uses Laravel's session CSRF token by default. The upload endpoint MUST require a valid CSRF token. The download endpoints, since they are GETs, must NOT be cross-origin embeddable as anything other than a download/iframe with `sandbox`. Set `Referrer-Policy: strict-origin-when-cross-origin` and `Cross-Origin-Resource-Policy: same-origin` on the download routes. Otherwise a malicious site can `<iframe>` someone's project page and read state via timing.

2. **Deterministic regenerate output: hash and skip duplicates.** Sam asked this question and voted (a) "always create new Version". I disagree, vote (b) hash and skip. Reasoning: px-image2pptx is mostly deterministic in our pinned config (no temperature, no randomness in OCR or LaMa beyond fixed weights). Two consecutive regenerates will likely produce byte-identical or near-identical output. If we always create, the user sees five identical "v1, v2, v3, v4, v5" entries and concludes the regenerate is broken. If we hash the pptx and detect a duplicate, we surface "this regenerate produced the same output as v3, no new version created" and the user understands the model is deterministic. Cheaper disk too.

3. **Filename sanitisation in download headers.** I mentioned this in round 2 but want it locked. The original uploaded filename is for DISPLAY only (e.g., shown in the project page header as "From: vacation_photo.png"). It NEVER appears in `Content-Disposition` headers, NEVER on disk paths, NEVER in shell argv. Download header filename is `project-{uuid_prefix}-v{n}.pptx` derived from server state, ASCII-only.

**My final vote on Sam's positions**

- TTL 72h vs 7d: I yield to 7 d default with 5 GB global ceiling that evicts oldest-first when hit. Sam, can you sign on this hybrid? It gets you the abuse cap you want.
- Upload 12 MB vs 8 MB: I propose 10 MB compromise. Will yield to 12 MB if Sam holds.
- Always-create regenerate vs hash-and-skip: I push hash-and-skip. Open in round 3.
- Cancel running conversion: agree with Sam, no for MVP.
- Delete project button: agree with Sam, yes for MVP.
- LibreOffice availability: agree with Sam, BLOCKER for round 3 close. The user must confirm.
- "Local models acceptable per the brief": agree, paddleocr and big-lama are local. The brief says "no LLM APIs" and "local models allowed". Confirmed in scope. Footprint is the cost of the product.

**The full lockdown list for round 3 (R-numbered)**

R1. Pixel-bomb defence: client_max_body_size 11 MB, Laravel `mimes` + custom pixel rule, finfo content sniff, PIL MAX_IMAGE_PIXELS, 90 s wall-clock with process-group kill.
R2. Queue conversion via Laravel `database` driver, single worker, 90 s timeout, process group killed on overrun.
R3. Slug-as-access-control with three explicit UI copy points (upload page, project header badge, copy-link button).
R4. No user-supplied pptx in MVP. Threat model says ZIP-bomb pptx is out of scope.
R5. TTL: 7 d default, with 5 GB global tmp ceiling, hourly reaper. Both env-configurable.
R6. Upload limits: 10 MB byte cap (or 12 if Sam holds), 4000 px longest side, 16 MP total.
R7. Formats: PNG, JPEG only.
R8. PDF backend: LibreOffice headless from the pptx. BLOCKER: user confirmation that `soffice` is available on deploy target.
R9. Cookie session HttpOnly Secure SameSite=Lax. Server-side `sessions` table.
R10. Storage outside webroot, downloads via signed controller route, sanitised `Content-Disposition` filenames derived from server-side IDs.
R11. Gemini path verified absent in the documented CLI/API surface. Pin to `[ocr,inpaint]` extras only. Deploy-time grep check + Pest network-egress test.
R12. Rate limits: 5 uploads per IP per 15 min, 1 concurrent conversion per session, global queue depth cap 50.
R13. EXIF rotation baked in server-side before pipeline.
R14. pptx and pdf metadata sanitised (no leaked OS user, no machine name).
R15. Client-side downscale before upload. SHOULD, not MUST.
R16. CSRF on upload, `Cross-Origin-Resource-Policy: same-origin` and `Referrer-Policy: strict-origin-when-cross-origin` on download routes.
R17. Hash-and-skip duplicate regenerate output (open against Sam's "always create").
R18. Per-upload nonce to prevent duplicate-project on refresh.
R19. Versioning: Project owns one source image, max 5 Versions append-only (oldest evicted on new), max 20 Projects per session, regenerate uses original source image.
R20. Delete project button in MVP. No cancel-running-conversion in MVP.

If Alex yields on the pixel cap (4000 / 16 MP) and Sam yields on TTL (7 d with 5 GB ceiling) and upload cap (10 MB), round 3 is a five-minute lockdown. If not, we argue R5, R6, R17 in round 3 and lock the rest.

— Reviewer-Riley

---

### [PO] PO-Alex, Round 3, lock agreements using lead's authoritative facts

The lead has resolved every blocker we raised in round 2:

- **Local models in scope.** User confirmed paddleocr (~84+81 MB) and big-lama (~196 MB) are acceptable. Total ~370 MB on disk. "No LLM APIs" means no network calls to model providers, not "no local ML weights".
- **No Gemini in the pip package.** Lead inspected source. Riley's static-grep deploy check is still good defence in depth, but the threat is hypothetical, not present.
- **LibreOffice headless assumed available.** `soffice --headless --convert-to pdf` is now a documented system requirement; deploy script verifies. R8 unblocked.
- **Models pre-warmed at deploy.** Critical: a one-shot `php artisan models:warm` (or a post-install step) downloads paddleocr + big-lama into `~/.paddlex/official_models/` and `~/.cache/torch/hub/checkpoints/` BEFORE first user request. Otherwise user-1 hangs on a 196 MB blocking download. This goes into the spec.
- **Performance baseline:** 5-16 s on M1 Pro, longer on cloud CPU. Confirms our 90 s wall-clock timeout has comfortable headroom.
- **Python deps locked:** Pillow>=10, numpy>=1.24, opencv-python>=4.8, python-pptx>=0.6.21, paddleocr>=3.0, paddlepaddle==3.2.2, simple-lama-inpainting>=0.1.0, torch>=2.0. Python 3.9-3.13.
- **CLI contract:** `px-image2pptx <input> -o <output>` with optional `--lang auto` and `--max-inpaint-size 2048`. Exit code drives success/failure.

**My final positions on the open items**

- **TTL: I yield to Riley's hybrid.** 7 days default, 5 GB hard global ceiling, oldest-Project-first eviction kicks in BEFORE TTL when ceiling is hit. Riley's argument is correct: the cap is the abuse primitive, the TTL matches user expectation. Strictly better than my pure-time proposal. Locked R5.
- **Upload cap: I yield to Riley's 10 MB compromise.** 10 MB byte cap, env-configurable. Pre-flight check via `getimagesize` runs first, byte cap second. Sam, will you sign on 10 MB? It is between your 12 and our 8.
- **Pixel cap: I yield to Riley/Sam.** 4000 px longest side, 16 MP total. Two votes plus the README's own recommended ceiling. Locked R6 component.
- **Deterministic regenerate: I yield to Riley's hash-and-skip.** Riley's UX argument convinced me: five identical entries reads as a bug. Hash the pptx, on duplicate surface "regenerate produced the same output as v3, no new version created". Cheaper disk and clearer UX. Locked R17.

**Confirmation on items I had open**

- Per-upload nonce (R18): AGREE.
- CSRF + COOP/CORP on downloads (R16): AGREE.
- Filename sanitisation in `Content-Disposition` (Riley round 2): AGREE.
- Project UUID slug + cookie session as convenience layer (R3, R9): AGREE.
- Delete project button in MVP, no cancel-running-conversion (R20): AGREE.
- Static-grep deploy check on installed package despite confirmed absence of Gemini path (R11): AGREE, defence in depth is cheap.

**One small addition I want in the locked agreements**

- **Model warm-up (R21).** Add an explicit deploy/install step that pre-fetches the paddleocr and big-lama model weights so the first real request does not block on a 196 MB download. Implementation: `php artisan models:warm` runs the bridge once with a tiny built-in fixture image and discards output. Document as part of the deploy checklist alongside `soffice` verification.

**Locked agreements ready to write**

R1-R20 as Riley specified, plus R21 above. Open items going into round 3 are now closed:
- R5: 7d TTL + 5 GB ceiling. Locked.
- R6: 10 MB byte cap, 4000 px longest side, 16 MP total. (Pending Sam sign-off on 10 MB.)
- R17: hash-and-skip duplicate regenerate. Locked.

Sam, the only thing waiting on you is the 10 MB compromise on R6. Riley already conceded down from 8. I went up from 8. Meet at 10? If yes, round 3 is locked and I will write the Agreements section.

— PO-Alex

### [PO] PO-Sam, Round 3, concessions and sign-off

Lead resolved both blockers (local models in scope, soffice as hard system requirement). Riley offered the trade I asked for. Alex moved to me on the concession requests. I am yielding on the remaining open items so we can lock.

**Conceded**

1. **TTL: yielding to 7 days default with the 5 GB global tmp ceiling and oldest-first eviction.** Riley's framing convinced me: the ceiling is the real abuse bound, the TTL is housekeeping. I asked for the ceiling to be explicit and Riley/Alex put it in the agreements. Done. (R5, A18, A19.)
2. **Upload cap: yielding to 10 MB.** Alex went up from 8, Riley came down from 8, I came down from 12. Meeting at 10. `UPLOAD_MAX_BYTES` env-configurable so deploy can adjust either way. (R6, A20.)
3. **Pixel cap: 4000 px longest side, 16 MP total. Locked.** Already aligned with Riley.
4. **Hash-and-skip duplicate regenerate (R17, A29).** Alex flipped to Riley's position. With Alex and Riley both on hash-and-skip, the UX argument has the votes and I yield. The implementation is straightforward (sha256 the produced pptx, compare against hashes in `versions` table for the same Project, refuse the new INSERT and surface a toast on collision). I want one thing in the spec around this: the hash check happens AFTER the conversion has run, not before, because the bridge is what produces the bytes. So we still spend the CPU even when we skip the write. That is fine for MVP, just write it down so behaviour is not surprising.
5. **Model warm-up at deploy (R21).** Agree; Alex's framing is right. `php artisan models:warm` runs the bridge once on a tiny fixture so paddleocr and big-lama weights are local before user-1 hits the endpoint. Add to deploy checklist alongside `which soffice`.
6. **CSRF + COOP/CORP on downloads, filename sanitisation in `Content-Disposition`, slug-as-canonical with cookie as convenience, delete-project-button-yes / no-cancel-running-conversion, static-grep deploy check despite confirmed-clean source.** All AGREE.

**Sign-off on Alex's Agreements section**

I have read A1 through A35 in the Agreements section above. They accurately reflect the team's converged positions plus the lead's authoritative facts. Specifically:

- Product (A1-A7): correct.
- Conversion pipeline (A8-A14): correct, with `--lang auto` and `--max-inpaint-size 2048` as the documented invocation.
- Storage (A15-A17): correct, server-derived `Content-Disposition` filenames are key.
- Lifecycle and abuse mitigation (A18-A24): correct, ceiling is the primary abuse bound, TTL is secondary.
- Security headers and discipline (A25-A28): correct, including the static-grep deploy check.
- Versioning behaviour (A29): correct, with the clarification above (hash check is post-conversion).
- Out of MVP (A30-A35): correct, and explicitly comprehensive enough that scope creep has a documented bar to cross.

Two small additions I want appended (non-controversial, consensus-implied):

- A36. Python version pinned in repo (`runtime.txt` or `.python-version`) within the supported 3.9-3.13 range. Avoids a Python upgrade silently breaking the bridge.
- A37. Pest feature tests required at MVP for: upload happy path, oversize byte rejection, oversize pixel rejection, wrong-format rejection, EXIF-rotated input baked correctly, regenerate produces a new Version when output differs, regenerate hash-collision surfaces the no-new-version message, slug-only access works for the cookie-less viewer, rate-limit enforcement, TTL reaper, byte-ceiling reaper, deploy-time grep check fails on a planted Gemini string. (A39 from my round 3 draft above; restating here as a sign-off addendum.)

If Alex and Riley accept A36 and A37 as additions to the Agreements list, I sign off on the full set. If anyone objects to either, I still sign off on A1 through A35 unchanged.

— PO-Sam (Round 3 sign-off)

---

## Agreements (consensus required)

This section is the locked, consensus-bearing list of decisions. Authored by PO-Alex in round 3 from the team's converged positions and the lead's authoritative facts. All three sign-offs (PO-Alex, PO-Sam, Reviewer-Riley) below the list.

**Product**

- A1. A Project owns one current source image plus an ordered append-only list of Versions. New Versions are produced either by (a) regenerating against the current source image, or (b) re-uploading a different image which becomes the new current source for that Project. Each Version stores its own .pptx, .pdf, and a copy of the input image used for that Version. Sidebar lists Projects newest-first. (Lead override of round 2: re-upload now creates a new Version on the existing Project rather than a new Project.)
- A2. MVP feature set: upload image, see input preview, see output preview (inline PDF in browser), download .pptx and .pdf, regenerate against current image, re-upload to add a new Version with a different image, delete project, sidebar of recent projects in this session. No settings panel.
- A3. Sidebar lists Projects newest-first. Cookie session is convenience, project URL slug is canonical access.
- A4. UI copy explicitly states: "anyone with this link can access this project", "files are kept for 7 days, please download to keep", "this device remembers your projects until cookies are cleared".
- A5. No deck editing, slide reorder, theme picker, multi-image upload, share-link generator, login, email, or accounts in MVP.
- A6. Roadmap signposting only: post-MVP candidates are per-version notes, project rename, slide thumbnail strip preview, multi-image batch. NO local LLM integration on the roadmap.
- A7. Limits: max 5 Versions per Project (oldest evicted), max 20 Projects per session.

**Conversion pipeline**

- A8. `px-image2pptx` Python package, pinned version, installed with `[ocr,inpaint]` extras only (NEVER `[all]`).
- A9. CLI invocation: `px-image2pptx <input> -o <output> --lang auto --max-inpaint-size 2048`. Argv only, no `shell_exec`. Symfony Process with array constructor.
- A10. PDF derived from the generated pptx via `soffice --headless --convert-to pdf`. One source of truth. No fallback PDF backend.
- A11. Conversion runs on Laravel queue (`database` driver), one worker. Started locally via `composer run dev`. Hard wall-clock timeout 90 s including PDF render. On overrun, kill the process group (`posix_setsid` or Symfony Process which handles it). Workers detached from FPM.
- A12. Models (PaddleOCR PP-OCRv5 ~165 MB, big-lama ~196 MB) pre-warmed at deploy. Artisan command `php artisan ppt:warm-models` runs the bridge against a tiny fixture once so paddleocr and big-lama weights are local before user-1 hits the endpoint. Deploy checklist verifies model files present and `soffice` available.
- A13. EXIF rotation baked in server-side BEFORE pipeline invocation.
- A14. pptx and pdf metadata sanitised: fixed author string ("image-to-powerpoint"), no OS username, no machine hostname.

**Storage**

- A15. Files outside web root: `storage/app/private/projects/{project_uuid}/source.{ext}` and `.../versions/{n}/{output.pptx,output.pdf,job.log}`.
- A16. Downloads via signed controller route only. Never via public symlink. Filenames in `Content-Disposition` are server-derived ASCII (`project-{uuid_prefix}-v{n}.pptx`); the user-supplied filename is display-only.
- A17. SQLite tables: `projects`, `versions`, `sessions`, `session_projects`. Index only, artefacts on disk.

**Lifecycle and abuse mitigation**

- A18. Artefact TTL: `PROJECT_TTL_DAYS` default 7, env-configurable. Hourly `php artisan projects:reap` deletes Projects past TTL.
- A19. Hard global tmp ceiling: `PROJECT_TMP_BYTES_CAP` default 5 GB, env-configurable. Reaper evicts oldest Projects first when ceiling is hit, BEFORE TTL applies. In-flight Projects skipped.
- A20. Upload limits: `UPLOAD_MAX_BYTES` default 10 MB, `UPLOAD_MAX_PIXELS` default 16_000_000, max 4000 px longest side. All checked pre-flight via `getimagesize` BEFORE the file reaches Python.
- A21. Accepted formats: PNG and JPEG only. WebP, SVG, GIF, HEIC, TIFF, BMP rejected at the boundary with specific error messages. Validation pipeline runs in this order: (1) magic-byte sniff via `finfo`, (2) `getimagesize` for declared dimensions, (3) decode probe (open image, read pixels) to defeat truncated or malformed files, (4) dimension and pixel-count check, (5) EXIF rotation baked in. Never trust client `Content-Type` or filename extension.
- A22. Rate limits: 5 uploads per IP per 15 minutes (Laravel `RateLimiter`). 1 concurrent conversion per session. Global queue depth cap 50 pending jobs.
- A23. Per-upload nonce prevents duplicate Project on refresh. Idempotent on server side.
- A24. Session cookie: HttpOnly, Secure, SameSite=Lax, long random `session_token` mapped server-side to a sessions table.

**Security headers and discipline**

- A25. CSRF token required on upload AND regenerate endpoints. Origin header check on both.
- A26. Download routes: `Cross-Origin-Resource-Policy: same-origin`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox` for embedded PDF.
- A27. Static-grep check at deploy time on installed `px-image2pptx` package: greps for `gemini`, `googleapis`, `generativeai`. Non-zero match fails deploy. Defence-in-depth even though current source is clean.
- A28. Pest test asserting the bridge does not make outbound network calls during a fixture conversion.

**Versioning behaviour**

- A29. Nice-to-have, not MVP-blocking (per lead): hash the produced .pptx; if the hash matches an existing Version on the same Project, surface "regenerate produced the same output as v{n}, no new version created" instead of writing a duplicate. Hash check happens AFTER conversion runs (we still spend the CPU; we just skip the write on collision). If implementation is non-trivial during MVP, default to "always create new Version" and ship dedup post-MVP.

**Out of MVP scope (explicit)**

- A30. No background queue worker beyond the single database worker, no Redis/Horizon/supervisord.
- A31. No analytics SDK, no Sentry, no third-party telemetry. Laravel logs only: job id, duration, exit code, input bytes, output bytes, success/fail.
- A32. No internationalisation. English only. Strings centralised so a future locale is cheap.
- A33. No accessibility commitments beyond baseline (semantic HTML, keyboard reach, visible focus rings, alt text on previews). No screen-reader QA pass committed for MVP.
- A34. No public sharing UI, no email-the-link, no project rename.
- A35. No user-supplied .pptx import. ZIP-bomb pptx threat is out of scope because we generate the pptx; flagged for any future re-import feature.

**Engineering hygiene (added per Sam round 3, accepted by lead)**

- A36. Python version pinned in repo (`.python-version` or `runtime.txt`) within the supported 3.9-3.13 range. Avoids a Python upgrade silently breaking the bridge.
- A37. Pest feature tests required at MVP for: upload happy path, oversize byte rejection, oversize pixel rejection, wrong-format rejection, EXIF-rotated input baked correctly, regenerate produces a new Version when output differs, regenerate hash-collision surfaces the no-new-version message (only if A29 dedup ships), slug-only access works for the cookie-less viewer, rate-limit enforcement, TTL reaper, byte-ceiling reaper, deploy-time grep check fails on a planted Gemini string.

**Sign-off**

- PO-Alex: SIGNED (after lead overrides applied to A1, A2, A29; Sam's A36 and A37 incorporated)
- PO-Sam: SIGNED (signed A1 through A35 above; A36 and A37 are Sam's own additions)
- Reviewer-Riley: SIGNED (A1 through A37; notes appended in round 3 entry below)

### [Reviewer] Reviewer-Riley, Round 3, sign-off with notes for the testplan

I sign A1 through A37 as written. Notes for the implementer and the testplan author so nothing slips between this doc and the next.

**Note 1: A1 versioning shape changed by lead override; storage and reaper must follow**

A1 was rewritten in round 3 from "re-upload creates a new Project" (round 2 consensus) to "re-upload creates a new Version on the existing Project". I accept the lead override but it has consequences A15 must account for:

- Each Version stores a copy of the input image used for that Version (A1 spells this out). Storage layout is therefore `.../versions/{n}/source.{ext}` AND `output.pptx` AND `output.pdf` AND `job.log`. The Project-level "current source" is whichever Version is most recent.
- Disk accounting under A19 must walk Versions, not just outputs, because per-Version source copies can be 10 MB each. 5 Versions per Project times 20 Projects per session times multiple sessions adds up under the 5 GB ceiling. The reaper code must include source bytes in its sum.

**Note 2: A4 UI copy must parameterise the TTL**

A4 quotes "files are kept for 7 days". The component MUST render `PROJECT_TTL_DAYS` from config, not a hardcoded literal. An env override at deploy must not lie to users. Testplan asserts this with a config override.

**Note 3: A29 is a nice-to-have but the testplan covers both branches**

The lead allowed A29 dedup to ship post-MVP if non-trivial. The testplan therefore has two scenarios:
- If dedup ships: hash collision surfaces a non-dismissed banner "regenerate produced the same output as v{n}, no new version created", no duplicate row written.
- If dedup deferred: regenerate ALWAYS creates a new Version even on byte-identical output. UI must not silently confuse the user; a small inline note on the Versions list ("output identical to v{n}") is enough.

**Note 4: A11 timeout breakdown**

90 s total wall-clock is the hard backstop. I want soft per-stage timeouts in the worker: 60 s for `px-image2pptx`, 25 s for `soffice`, 5 s headroom. If a stage exceeds its slice, the job fails with a stage-specific error message. This makes the failure mode actionable.

**Hard preconditions for the testplan (claiming task #5 now)**

- A8/A12: deploy verifies `which soffice` and model files present at `~/.paddlex/official_models/PP-OCRv5_*` and `~/.cache/torch/hub/checkpoints/big-lama*`. Smoke test fails if either is missing.
- A11: subprocess invocation goes via Symfony Process with array constructor. Pest test grepping the codebase forbids `shell_exec`, raw `proc_open`, and string concatenation into argv.
- A20: pixel-bomb defence is layered. Tests exercise each layer independently: (a) web-server cap rejects > 13 MB, (b) Laravel `mimes`+pixel rule rejects > 4000 px or > 16 MP, (c) finfo rejects extension/MIME mismatch, (d) PIL guard rejects in the wrapper, (e) subprocess timeout kills hangs.
- A26: response headers explicitly asserted on download routes.
- A27: deploy script runs `grep -rE 'gemini|googleapis|generativeai' $(python -c 'import px_image2pptx, os; print(os.path.dirname(px_image2pptx.__file__))')` and fails on any match.

I am signed. Round 3 is closed. Claiming task #5 (specs/testplan.md) and will get Alex and Sam to review before marking complete.

— Reviewer-Riley

---

## Status

- PO-Alex: ROUND_3_DONE
- PO-Sam: ROUND_3_DONE
- Reviewer-Riley: ROUND_3_DONE
