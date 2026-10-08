# Mail template pack

Copy this directory to `storage/mail-templates`, edit the copy and set
`MAIL_TEMPLATE_DIR=storage/mail-templates`. Each enabled locale needs all three
files: `subject.txt`, `plain.txt` and `html.html`.

Templates are plain UTF-8 files, never PHP. Available placeholders are
`{{app_name}}`, `{{expires_minutes}}`, `{{magic_link}}` and `{{recipient}}`.
The subject only accepts the first two; both body files must include
`{{magic_link}}`.

Keep the configured copy outside `public/`. Treat it like application code: it
must never be an upload destination or writable by untrusted users.
