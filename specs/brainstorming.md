# Decision Record: Image-to-PowerPoint Web App

This file replaces the earlier brainstorming notes. The earlier public-link workspace model is not part of the product and must not be reintroduced.

## Current Model

- The browser session is the access boundary.
- A URL by itself never grants access to any uploaded image, generated deck, preview, metadata, or status.
- Users upload one or more PNG or JPEG images and receive temporary `.pptx` artifacts. `.pdf` preview/download is optional and disabled by default in local development.
- Temporary working files exist only outside the web root, only for conversion, preview, and download, and are deleted by user action or cleanup.
- Users must download artifacts they want to keep.
- There is no permanent workspace, public sharing, link handoff, login, account, deck editor, or telemetry.

## Entities

- **Session:** Server-side row keyed by the HttpOnly `image2pptx_session` cookie.
- **Conversion:** One temporary session-owned image-to-deck workspace.
- **Attempt:** One run of the conversion pipeline for a Conversion.

## Locked Requirements

- Uploading one or more new images creates one new Conversion per image in the same Session.
- Multiple pending Conversions are queued and processed one Attempt at a time.
- Regenerate creates a new Attempt for the same Conversion.
- Every Conversion route must verify current Session ownership and return 404 on mismatch.
- Download routes require both a valid signed URL and current Session ownership.
- The UI must never say that another person can use a link to open anything.
- The implementation must contain no leftover public-link tables, routes, generated route helpers, controllers, tests, or UI.
