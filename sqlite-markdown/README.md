# sqlite-markdown

A loadable SQLite extension that stores `wp_posts`-style rows as Markdown files with structured front matter, and exposes them through writable virtual tables.

## What it does

This experiment registers two SQLite virtual table modules:

- `markdown_posts`
- `markdown_postmeta`

They are designed to feel familiar if you know the WordPress schema:

- `wp_posts` stores the main post fields and body content.
- `wp_postmeta` stores additional front matter entries attached to each post.

Unlike a read-only file index, both tables support full CRUD:

- `SELECT`
- `INSERT`
- `UPDATE`
- `DELETE`

Each post is persisted as a single Markdown file on disk. Post fields and metadata are serialized into a simple front matter block, followed by the Markdown body.

## Layout on disk

Posts are stored as:

```text
<root>/<post-id>-<post-name>.md
```

Example:

```text
content/1-hello-world.md
```

Hierarchical posts can also be represented in either of these ways:

```text
content/1-home/2-about/index.md
```

or:

```text
content/2-about.md
```

with front matter such as:

```text
post_parent = "home"
```

The file format looks like this:

```text
---
post_title = "Hello world"
post_name = "hello-world"
post_status = "publish"
post_type = "post"
post_date_gmt = "2026-04-23T00:00:00Z"
post_modified_gmt = "2026-04-23T00:00:00Z"
post_parent = "home"
[[meta]]
template = "landing"
meta_id = 1
---
# Heading

Body copy.
```

Each `[[meta]]` block stores one `wp_postmeta` row. Readable keys stay readable on disk, and keys that need quoting are emitted as quoted keys. The parser is handwritten C code. It does not use regex-based parsing.

For backward compatibility, the parser also accepts the older `+++` delimiter and the more verbose `meta_key` / `meta_value` meta blocks.

## Build

The project vendors the SQLite loadable-extension headers, so no system `libsqlite3-dev` package is required for this experiment.

```bash
cd sqlite-markdown
make build
```

This produces:

```text
build/sqlite_markdown.so
```

## Test

```bash
cd sqlite-markdown
make test
```

The test suite uses Python's built-in `unittest` module and loads the compiled extension through `sqlite3`.

## Usage

### 1. Load the extension

From Python:

```python
import sqlite3

con = sqlite3.connect(":memory:")
con.enable_load_extension(True)
con.load_extension("build/sqlite_markdown.so")
```

### 2. Create virtual tables

```sql
CREATE VIRTUAL TABLE wp_posts
USING markdown_posts(root = '/absolute/path/to/content');

CREATE VIRTUAL TABLE wp_postmeta
USING markdown_postmeta(root = '/absolute/path/to/content');
```

Both tables point at the same content directory.

### 3. Insert posts

```sql
INSERT INTO wp_posts (
  post_title,
  post_name,
  post_status,
  post_type,
  post_date_gmt,
  post_modified_gmt,
  post_content
) VALUES (
  'Hello world',
  'hello-world',
  'publish',
  'post',
  '2026-04-23T00:00:00Z',
  '2026-04-23T00:00:00Z',
  '# Heading\n\nBody copy.\n'
);
```

### 4. Insert metadata

```sql
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
VALUES (1, 'template', 'landing');
```

### 5. Query content

```sql
SELECT ID, post_title, post_name, post_content
FROM wp_posts;

SELECT meta_id, post_id, meta_key, meta_value
FROM wp_postmeta;
```

### 6. Update or delete rows

```sql
UPDATE wp_posts
SET post_name = 'hello-again', post_modified_gmt = '2026-04-23T01:00:00Z'
WHERE ID = 1;

UPDATE wp_postmeta
SET meta_value = 'homepage'
WHERE meta_id = 1;

DELETE FROM wp_postmeta WHERE meta_id = 1;
DELETE FROM wp_posts WHERE ID = 1;
```

Updating `post_name` renames the underlying Markdown file. Deleting a post removes the file and all metadata stored inside it.

## Current schema

`markdown_posts` exposes:

- `ID`
- `post_parent`
- `post_title`
- `post_name`
- `post_status`
- `post_type`
- `post_date_gmt`
- `post_modified_gmt`
- `post_content`

`markdown_postmeta` exposes:

- `meta_id`
- `post_id`
- `meta_key`
- `meta_value`

## CI

GitHub Actions runs `make test` on pull requests and pushes that touch `sqlite-markdown/**`.
