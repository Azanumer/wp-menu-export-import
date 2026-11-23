# WP Menu Export Import

Export WordPress navigation menus — structure, items and locations — to JSON, and import them on another site. Moving a site, cloning a staging environment, or handing a menu to a client? Take your menus with you instead of rebuilding them by hand.

## Features

- **Export any menus** — pick one or all, download a single JSON file (**Tools → Menu Export / Import**)
- **Full structure preserved** — item order, nesting (parent/child), classes, target, title attribute, XFN and description
- **Smart re-linking** — post, page and category links are matched by slug on the target site, so a menu built on staging links to the same content on live
- **Nothing is ever lost** — anything that cannot be matched is imported as a custom link to its original URL instead of being dropped
- **Location assignment** — menu locations (e.g. Primary, Footer) are restored when the active theme registers them; unknown locations are reported, not silently ignored
- **Dry run first** — the import form previews what will happen before creating anything

## Export format

```json
{
  "version": 1,
  "exported": "2026-11-23 10:00:00",
  "menus": [
    {
      "name": "Primary",
      "slug": "primary",
      "locations": ["primary"],
      "items": [
        {
          "id": 42,
          "title": "Blog",
          "url": "https://example.com/blog/",
          "type": "post_type",
          "object": "page",
          "object_id": 7,
          "parent": 0,
          "position": 1,
          "target": "",
          "attr_title": "",
          "classes": "",
          "xfn": "",
          "description": "",
          "status": "publish"
        }
      ]
    }
  ],
  "locations": ["primary", "footer"]
}
```

## Requirements

- WordPress 5.8+
- PHP 7.4+
- `manage_options` capability to export/import

## License

MIT — see [LICENSE](LICENSE).
