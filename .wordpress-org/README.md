# WordPress.org Plugin Directory Assets

This directory holds the images for the WordPress.org plugin page. They are
prepared and ready, but **the plugin is not published on WordPress.org yet**, so
nothing here is live.

## File naming conventions

- `banner-772x250.png`   — Plugin page header banner (normal resolution)
- `banner-1544x500.png`  — Plugin page header banner (high-DPI / retina)
- `icon-128x128.png`     — Plugin icon (normal resolution)
- `icon-256x256.png`     — Plugin icon (high-DPI / retina)
- `screenshot-1.png`     — Screenshot #1 (referenced in readme.txt)
- `screenshot-2.png`     — Screenshot #2
- ...and so on

> **Do not delete or rename files here** unless you also update the
> `== Screenshots ==` section in `readme.txt`.

These files are ready for the WordPress.org plugin page assets directory if the
plugin is ever published there. They are not live: the plugin is currently
distributed from GitHub, and there is no automated deploy workflow.

## Screenshot contents

| File | Shows |
|---|---|
| `screenshot-1.png` | Global configuration — Brand ID, Secret Key and purchase settings |
| `screenshot-2.png` | Payment methods — the per-form override dropdown and method list |
| `screenshot-3.png` | Form with CHIP payment — the CHIP panel on a Collect a Payment action |
| `screenshot-4.png` | A form on the front end with CHIP selected as the gateway |
| `screenshot-5.png` | The payments screen listing CHIP payments |

## Regenerating

The banner and icon follow the CHIP house style: a horizontal indigo-to-orange
gradient with the white CHIP mark. Screenshots are real captures from a local
WordPress running Formidable Forms with this plugin active.

```bash
# Screenshots: capture against a local install, then resize
convert capture.png -resize 1280x -strip -quality 92 screenshot-1.png

# Icon: gradient + white CHIP mark
convert -size 256x256 gradient:'#7F59DC-#EC5628' grad.png
rsvg-convert -w 118 -b none logo.svg -o mark.png
# ...composite the recoloured mark over the gradient, centred
```

WordPress.org requires screenshots to be at least 4:3 (or wider). Keep the
banner at the exact dimensions above; other sizes are rejected.
