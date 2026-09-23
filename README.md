# PicPeak for WordPress

Import gallery images from a [PicPeak](https://github.com/PicPeak/picpeak) instance
into the WordPress media library.

Connect the plugin once with an API token, browse your galleries from inside
wp-admin, and import a whole gallery or a hand-picked selection — with the
proofing metadata PicPeak already holds carried across.

> **Status: early.** The connection, the settings screen and the REST proxy work.
> The picker and the importer are not built yet. See [Roadmap](#roadmap).

## Why import rather than embed

PicPeak galleries expire by design. Hotlinking them into posts ties your site's
lifetime to the gallery's, and eventually serves 404s where your portfolio used
to be. Imported images live in your media library, with WordPress thumbnail
sizes, alt text and media search, and outlive the gallery they came from.

## Requirements

- WordPress 6.0+, PHP 7.4+
- A PicPeak instance you administer
- An API token from **Settings → Integrations** in PicPeak, with the `read`
  scope; its owning account needs the photo view and download permissions

### PicPeak version

The connection and gallery listing work against any PicPeak with the v1 API.

The picker and importer additionally need the preview and rendition endpoints
from [PicPeak/picpeak#1628](https://github.com/PicPeak/picpeak/pull/1628), which
is not merged yet:

| Needs | Endpoint |
|---|---|
| Thumbnails in the picker | `GET /api/v1/events/:id/photos/:photoId/preview` |
| Web-sized imports | `?resolution=WxH` on the download routes |
| Deciding how to fetch a row | `size_bytes` / `media_type` / `mime_type` on the photo list |

Importing originals one at a time, and the bulk ZIP, work on current PicPeak
without that PR.

## Installation

Download the zip from [Releases](../../releases), then **Plugins → Add New →
Upload Plugin**. The zip contains a single top-level folder, which is what
WordPress expects.

On hosts that set `DISALLOW_FILE_MODS` the upload form is hidden; unpack into
`wp-content/plugins/picpeak/` over SFTP, or use `wp plugin install ./picpeak.zip --activate`.

## The token never reaches the browser

The API token is admin-scoped on the PicPeak side. Anything that could read it
from a page — another plugin, a browser extension, an XSS — would inherit that
access, so it is never printed back into the settings form and never handed to
JavaScript.

The picker calls a `picpeak/v1` namespace on **this** site; WordPress attaches
the token server-side. Those routes require the `upload_files` capability and a
REST nonce, and forward only the query parameters PicPeak documents.

To keep the token out of the database entirely, define it in `wp-config.php`:

```php
define( 'PICPEAK_BASE_URL', 'https://gallery.example.com' );
define( 'PICPEAK_API_TOKEN', 'pp_live_…' );
```

The settings fields then render as locked rather than silently ignoring input.

## Roadmap

- [x] Connection settings, credential handling, REST proxy
- [ ] Gallery browser: thumbnail grid with PicPeak's own proofing filters
      (`marked_only`, `color_labels`, `min_rating`) — so "import what the client
      starred" is one control
- [ ] Import: batched sideload, deduplicated on the PicPeak photo id, with a
      resolution choice and an optional watermark
- [ ] A `picpeak_gallery` taxonomy on attachments, giving a filter dropdown in
      the media grid with no folder plugin involved
- [ ] Optional folder-plugin adapters (FileBird, Real Media Library)
- [ ] Updates through GitHub Releases

Import only. Nothing is written back to PicPeak.

Tracked upstream in [PicPeak/picpeak#1620](https://github.com/PicPeak/picpeak/issues/1620).

## License

MIT. See [LICENSE](LICENSE).
