# Media Title & ALT Fixer (CLI)

A WordPress plugin that provides a **WP-CLI command** to audit and bulk-fix image attachment titles and ALT text without breaking URLs.  

This is useful for cleaning up media libraries where many images have no title or generic names (e.g. `IMG_1234`, `shutterstock_12345`), ensuring better SEO and accessibility.

---

## Features

- Detects and fixes attachments with empty or "weird" titles.  
- Updates the **title** to match the parent post’s title.  
- Fills the **ALT text** automatically when missing.  
- With `--update-alt`, also replaces ALTs considered "weird".  
- Keeps the **slug (`post_name`) intact** to avoid broken URLs.  
- Can attempt to find a parent post reference for unattached images (`--search-parent`).  
- Supports filtering by MIME type and upload date.  
- Runs in **dry-run mode by default** (safe preview).

---

## Installation

1. Copy the plugin file into your WordPress installation, e.g.:  

   - As a normal plugin:  
     ```
     wp-content/plugins/media-title-alt-fixer-cli/media-title-alt-fixer-cli.php
     ```  
     then activate from the WP admin.  

   - Or as a MU-plugin (always enabled):  
     ```
     wp-content/mu-plugins/media-title-alt-fixer-cli.php
     ```

2. Ensure WP-CLI is installed and accessible on your server.  

---

## Usage

Run the command inside your WordPress installation:

### Dry run (default)
Preview what would be changed without saving:
bash
wp media-fixer fix --update-alt --search-parent

##Execute changes

Apply the updates:

wp media-fixer fix --execute --update-alt --search-parent

##Options

--execute
Persist changes (default is dry-run).

--update-alt
Update ALT text when missing or weird. Without this flag, only empty ALTs are filled.

--search-parent
For unattached images, attempt to discover a parent post by scanning content (wp-image-ID references or filename matches).

--include-keyword=<kw>
Append a keyword to new titles (e.g. Fresh Dog Food).

--food-cats=<slugs>
Restrict keyword appending to parent posts within these categories.

--limit=<n>
Maximum attachments to process.

--batch-size=<n>
Batch size per query (default 500).

--min-title-length=<n>
Minimum title length to consider valid (default 3).

--mime-include=<list>
Only process these MIME types (comma separated).

--mime-exclude=<list>
Skip these MIME types.

--uploaded-after=<YYYY-MM-DD>
Only attachments uploaded after this date.

--uploaded-before=<YYYY-MM-DD>
Only attachments uploaded before this date.

## Weird title/ALT patterns

### The plugin considers the following as "weird" (and will fix them):

Pattern example	Reason detected as weird
IMG_1234	Camera-style generic filename
shutterstock_456789	Stock image reference
123456	Only numbers
abcdef1234	Long hex string
20240708_123456	Timestamp-like pattern
untitled	Placeholder text
copy of something	Auto-generated duplicate title
photo_001, dsc_0098	Generic photo/camera output
Empty string	No title/ALT at all

If an attachment’s title or ALT matches any of these patterns, it will be replaced with a more meaningful one (usually the parent post title).

## Examples

Dry run to see what will change:

wp media-fixer fix --update-alt --search-parent


Execute changes for all attachments:

wp media-fixer fix --execute --update-alt --search-parent


Restrict to JPEG and PNG images uploaded in 2025:

wp media-fixer fix --execute --update-alt --mime-include=image/jpeg,image/png --uploaded-after=2025-01-01

License

MIT License.

