import pathlib
import sqlite3
import subprocess
import tempfile
import itertools
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[1]
BUILD_DIR = REPO_ROOT / "build"


def build_extension() -> pathlib.Path:
    subprocess.run(
        ["make", "build"],
        cwd=REPO_ROOT,
        check=True,
    )
    candidate = BUILD_DIR / "sqlite_markdown.so"
    if not candidate.exists():
        raise FileNotFoundError("built extension library was not found")
    return candidate


def sql_quote(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


class MarkdownStorageCrudTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.extension_path = build_extension()

    def setUp(self) -> None:
        self.tempdir = tempfile.TemporaryDirectory()
        self.root = pathlib.Path(self.tempdir.name) / "content"
        self.root.mkdir()
        self.connection = sqlite3.connect(":memory:")
        self.connection.row_factory = sqlite3.Row
        self.connection.enable_load_extension(True)
        self.connection.load_extension(str(self.extension_path))
        self.connection.execute(
            f"CREATE VIRTUAL TABLE wp_posts USING markdown_posts(root = {sql_quote(str(self.root))})"
        )
        self.connection.execute(
            f"CREATE VIRTUAL TABLE wp_postmeta USING markdown_postmeta(root = {sql_quote(str(self.root))})"
        )

    def tearDown(self) -> None:
        self.connection.close()
        self.tempdir.cleanup()

    def insert_post(
        self,
        *,
        title: str,
        name: str | None,
        status: str = "draft",
        post_type: str = "post",
        date_gmt: str = "2026-04-23T00:00:00Z",
        modified_gmt: str = "2026-04-23T00:00:00Z",
        content: str = "Body.\n",
        post_id: int | None = None,
    ) -> None:
        columns = [
            "post_title",
            "post_name",
            "post_status",
            "post_type",
            "post_date_gmt",
            "post_modified_gmt",
            "post_content",
        ]
        values = [
            title,
            name,
            status,
            post_type,
            date_gmt,
            modified_gmt,
            content,
        ]
        if post_id is not None:
            columns.insert(0, "ID")
            values.insert(0, post_id)
        placeholders = ", ".join("?" for _ in values)
        self.connection.execute(
            f"""
            INSERT INTO wp_posts ({", ".join(columns)})
            VALUES ({placeholders})
            """,
            values,
        )

    def test_insert_post_persists_markdown_and_surfaces_through_wp_posts(self) -> None:
        cursor = self.connection.execute(
            """
            INSERT INTO wp_posts (
                post_title,
                post_name,
                post_status,
                post_type,
                post_date_gmt,
                post_modified_gmt,
                post_content
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                "Hello world",
                "hello-world",
                "publish",
                "post",
                "2026-04-23T00:00:00Z",
                "2026-04-23T00:00:00Z",
                "# Heading\n\nBody copy.\n",
            ),
        )
        self.assertEqual(cursor.lastrowid, 1)

        row = self.connection.execute(
            """
            SELECT
                ID,
                post_title,
                post_name,
                post_status,
                post_type,
                post_date_gmt,
                post_modified_gmt,
                post_content
            FROM wp_posts
            """
        ).fetchone()
        self.assertIsNotNone(row)
        self.assertEqual(row["ID"], 1)
        self.assertEqual(row["post_title"], "Hello world")
        self.assertEqual(row["post_name"], "hello-world")
        self.assertEqual(row["post_status"], "publish")
        self.assertEqual(row["post_type"], "post")
        self.assertEqual(row["post_date_gmt"], "2026-04-23T00:00:00Z")
        self.assertEqual(row["post_modified_gmt"], "2026-04-23T00:00:00Z")
        self.assertEqual(row["post_content"], "# Heading\n\nBody copy.\n")

        files = sorted(self.root.glob("*.md"))
        self.assertEqual(len(files), 1)
        self.assertEqual(files[0].name, "1-hello-world.md")
        text = files[0].read_text(encoding="utf-8")
        self.assertIn('post_title = "Hello world"', text)
        self.assertIn('post_name = "hello-world"', text)
        self.assertTrue(text.endswith("# Heading\n\nBody copy.\n"))

    def test_postmeta_insert_update_and_delete_round_trip_through_front_matter(self) -> None:
        self.connection.execute(
            """
            INSERT INTO wp_posts (
                post_title,
                post_name,
                post_status,
                post_type,
                post_date_gmt,
                post_modified_gmt,
                post_content
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                "Landing page",
                "landing-page",
                "draft",
                "page",
                "2026-04-23T00:00:00Z",
                "2026-04-23T00:00:00Z",
                "Landing content.\n",
            ),
        )

        cursor = self.connection.execute(
            """
            INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
            VALUES (?, ?, ?)
            """,
            (1, "template", "landing"),
        )
        self.assertEqual(cursor.lastrowid, 1)

        row = self.connection.execute(
            """
            SELECT meta_id, post_id, meta_key, meta_value
            FROM wp_postmeta
            """
        ).fetchone()
        self.assertEqual(dict(row), {
            "meta_id": 1,
            "post_id": 1,
            "meta_key": "template",
            "meta_value": "landing",
        })

        self.connection.execute(
            """
            UPDATE wp_postmeta
            SET meta_value = ?
            WHERE meta_id = ?
            """,
            ("homepage", 1),
        )
        value = self.connection.execute(
            "SELECT meta_value FROM wp_postmeta WHERE meta_id = 1"
        ).fetchone()[0]
        self.assertEqual(value, "homepage")

        self.connection.execute("DELETE FROM wp_postmeta WHERE meta_id = 1")
        remaining = self.connection.execute(
            "SELECT COUNT(*) FROM wp_postmeta"
        ).fetchone()[0]
        self.assertEqual(remaining, 0)

        text = next(self.root.glob("*.md")).read_text(encoding="utf-8")
        self.assertNotIn("homepage", text)
        self.assertNotIn("template", text)

    def test_updating_posts_renames_files_and_updates_content(self) -> None:
        self.connection.execute(
            """
            INSERT INTO wp_posts (
                post_title,
                post_name,
                post_status,
                post_type,
                post_date_gmt,
                post_modified_gmt,
                post_content
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            (
                "Before",
                "before",
                "draft",
                "post",
                "2026-04-23T00:00:00Z",
                "2026-04-23T00:00:00Z",
                "Old body.\n",
            ),
        )

        self.connection.execute(
            """
            UPDATE wp_posts
            SET
                post_title = ?,
                post_name = ?,
                post_status = ?,
                post_content = ?,
                post_modified_gmt = ?
            WHERE ID = ?
            """,
            (
                "After",
                "after",
                "publish",
                "New body.\n",
                "2026-04-23T01:00:00Z",
                1,
            ),
        )

        row = self.connection.execute(
            """
            SELECT post_title, post_name, post_status, post_content, post_modified_gmt
            FROM wp_posts WHERE ID = 1
            """
        ).fetchone()
        self.assertEqual(dict(row), {
            "post_title": "After",
            "post_name": "after",
            "post_status": "publish",
            "post_content": "New body.\n",
            "post_modified_gmt": "2026-04-23T01:00:00Z",
        })

        files = sorted(self.root.glob("*.md"))
        self.assertEqual([file.name for file in files], ["1-after.md"])

    def test_deleting_posts_removes_the_markdown_file_and_associated_meta(self) -> None:
        self.insert_post(title="Delete me", name="delete-me", content="Disposable.\n")
        self.connection.execute(
            """
            INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
            VALUES (?, ?, ?)
            """,
            (1, "template", "trashable"),
        )

        self.connection.execute("DELETE FROM wp_posts WHERE ID = 1")

        remaining_posts = self.connection.execute(
            "SELECT COUNT(*) FROM wp_posts"
        ).fetchone()[0]
        remaining_meta = self.connection.execute(
            "SELECT COUNT(*) FROM wp_postmeta"
        ).fetchone()[0]
        self.assertEqual(remaining_posts, 0)
        self.assertEqual(remaining_meta, 0)
        self.assertEqual(list(self.root.glob("*.md")), [])

    def test_reads_preseeded_front_matter_with_single_quotes_and_escape_sequences(self) -> None:
        (self.root / "9-preseeded.md").write_text(
            """+++
post_title = 'It\\'s loaded'
post_name = 'preseeded'
post_status = 'draft'
post_type = 'page'
post_date_gmt = '2026-04-23T00:00:00Z'
post_modified_gmt = '2026-04-23T00:00:00Z'
[[meta]]
meta_id = 8
meta_key = 'quote'
meta_value = 'A \\\\ B \\' C'
+++
Seeded body.
""",
            encoding="utf-8",
        )

        post = self.connection.execute(
            """
            SELECT ID, post_title, post_name, post_type, post_content
            FROM wp_posts
            """
        ).fetchone()
        meta = self.connection.execute(
            """
            SELECT meta_id, post_id, meta_key, meta_value
            FROM wp_postmeta
            """
        ).fetchone()

        self.assertEqual(dict(post), {
            "ID": 9,
            "post_title": "It's loaded",
            "post_name": "preseeded",
            "post_type": "page",
            "post_content": "Seeded body.\n",
        })
        self.assertEqual(dict(meta), {
            "meta_id": 8,
            "post_id": 9,
            "meta_key": "quote",
            "meta_value": "A \\ B ' C",
        })

    def test_hostile_cte_and_subquery_dml_round_trips_posts_and_meta(self) -> None:
        self.insert_post(title='Title "one"', name="slug-one", content="Body 1.\n", post_id=1)
        self.insert_post(title="Title two", name="slug-two", content="Body 2.\n", post_id=2)

        self.connection.executescript(
            """
            WITH copied_post AS (
                SELECT
                    7 AS new_id,
                    post_title || ' / copy' AS new_title,
                    lower(replace(post_name, '-', '_')) || '__copied' AS new_name,
                    post_content || 'copied' || char(10) AS new_content
                FROM wp_posts
                WHERE ID = 1
            )
            INSERT INTO wp_posts (
                ID,
                post_title,
                post_name,
                post_status,
                post_type,
                post_date_gmt,
                post_modified_gmt,
                post_content
            )
            SELECT
                new_id,
                new_title,
                new_name,
                'publish',
                'post',
                '2026-04-23T02:00:00Z',
                '2026-04-23T02:00:00Z',
                new_content
            FROM copied_post;

            INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
            SELECT ID, 'template', post_name
            FROM wp_posts
            WHERE ID IN (1, 2, 7);

            WITH target_post AS (
                SELECT ID FROM wp_posts WHERE post_name = 'slug-two'
            )
            UPDATE wp_postmeta
            SET
                post_id = (SELECT ID FROM target_post),
                meta_id = meta_id + 100,
                meta_key = meta_key || '-moved',
                meta_value = upper(meta_value)
            WHERE meta_id = (
                SELECT meta_id FROM wp_postmeta WHERE post_id = 1
            );

            UPDATE wp_posts
            SET
                ID = ID + 20,
                post_name = post_name || '--renamed',
                post_title = post_title || ' ++',
                post_modified_gmt = '2026-04-23T03:00:00Z',
                post_content = post_content || 'mutated' || char(10)
            WHERE rowid = (
                SELECT rowid FROM wp_posts WHERE ID = 7
            );

            UPDATE wp_postmeta
            SET meta_value = (
                SELECT post_name
                FROM wp_posts
                WHERE wp_posts.ID = wp_postmeta.post_id
            )
            WHERE post_id = (
                SELECT ID FROM wp_posts WHERE post_name = 'slug_one__copied--renamed'
            );
            """
        )

        posts = [
            dict(row)
            for row in self.connection.execute(
                """
                SELECT ID, post_name, post_title, post_content
                FROM wp_posts
                ORDER BY ID
                """
            )
        ]
        meta = [
            dict(row)
            for row in self.connection.execute(
                """
                SELECT meta_id, post_id, meta_key, meta_value
                FROM wp_postmeta
                ORDER BY meta_id
                """
            )
        ]

        self.assertEqual(posts, [
            {
                "ID": 1,
                "post_name": "slug-one",
                "post_title": 'Title "one"',
                "post_content": "Body 1.\n",
            },
            {
                "ID": 2,
                "post_name": "slug-two",
                "post_title": "Title two",
                "post_content": "Body 2.\n",
            },
            {
                "ID": 27,
                "post_name": "slug_one__copied--renamed",
                "post_title": 'Title "one" / copy ++',
                "post_content": "Body 1.\ncopied\nmutated\n",
            },
        ])
        self.assertEqual(meta, [
            {
                "meta_id": 2,
                "post_id": 2,
                "meta_key": "template",
                "meta_value": "slug-two",
            },
            {
                "meta_id": 3,
                "post_id": 27,
                "meta_key": "template",
                "meta_value": "slug_one__copied--renamed",
            },
            {
                "meta_id": 101,
                "post_id": 2,
                "meta_key": "template-moved",
                "meta_value": "SLUG-ONE",
            },
        ])
        self.assertEqual(
            sorted(file.name for file in self.root.glob("*.md")),
            ["1-slug-one.md", "2-slug-two.md", "27-slug_one__copied--renamed.md"],
        )

    def test_rejects_path_traversal_style_post_names_without_touching_storage(self) -> None:
        self.insert_post(title="Safe", name="safe", content="safe body\n", post_id=1)

        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                """
                WITH attack(value) AS (
                    SELECT '../owned'
                )
                INSERT INTO wp_posts (
                    post_title,
                    post_name,
                    post_status,
                    post_type,
                    post_date_gmt,
                    post_modified_gmt,
                    post_content
                )
                SELECT
                    'Path traversal',
                    value,
                    'draft',
                    'post',
                    '2026-04-23T00:00:00Z',
                    '2026-04-23T00:00:00Z',
                    'nope'
                FROM attack
                """
            )

        with self.assertRaises(sqlite3.IntegrityError):
            self.connection.execute(
                """
                WITH attack(value) AS (
                    SELECT 'nested/path'
                )
                UPDATE wp_posts
                SET
                    post_name = (SELECT value FROM attack),
                    post_modified_gmt = '2026-04-23T04:00:00Z'
                WHERE rowid = (SELECT rowid FROM wp_posts WHERE ID = 1)
                """
            )

        self.assertEqual(
            sorted(file.name for file in self.root.glob("*.md")),
            ["1-safe.md"],
        )
        self.assertEqual(
            [dict(row) for row in self.connection.execute(
                """
                SELECT ID, post_name, post_modified_gmt
                FROM wp_posts
                """
            )],
            [{
                "ID": 1,
                "post_name": "safe",
                "post_modified_gmt": "2026-04-23T00:00:00Z",
            }],
        )


def expected_post_name(title: str, name: str | None) -> str:
    if name is not None and name != "":
        return name

    output: list[str] = []
    wrote_dash = False
    for character in title:
        if character.isascii() and character.isalnum():
            output.append(character.lower())
            wrote_dash = False
        elif output and not wrote_dash:
            output.append("-")
            wrote_dash = True

    while output and output[-1] == "-":
        output.pop()

    return "".join(output) or "post"


def encode_frontmatter_value(value: str, quote_style: str) -> str:
    quote = "'" if quote_style == "single" else '"'
    encoded: list[str] = [quote]
    for character in value:
        if character == "\\":
            encoded.append("\\\\")
        elif character == "\n":
            encoded.append("\\n")
        elif character == "\r":
            encoded.append("\\r")
        elif character == "\t":
            encoded.append("\\t")
        elif character == quote:
            encoded.append("\\" + quote)
        else:
            encoded.append(character)
    encoded.append(quote)
    return "".join(encoded)


def build_seeded_markdown(
    *,
    title: str,
    name: str,
    status: str,
    post_type: str,
    date_gmt: str,
    modified_gmt: str,
    meta_id: int,
    meta_key: str,
    meta_value: str,
    body: str,
    quote_style: str,
    line_ending: str,
) -> str:
    lines = [
        "+++",
        f"post_title = {encode_frontmatter_value(title, quote_style)}",
        f"post_name = {encode_frontmatter_value(name, quote_style)}",
        f"post_status = {encode_frontmatter_value(status, quote_style)}",
        f"post_type = {encode_frontmatter_value(post_type, quote_style)}",
        f"post_date_gmt = {encode_frontmatter_value(date_gmt, quote_style)}",
        f"post_modified_gmt = {encode_frontmatter_value(modified_gmt, quote_style)}",
        "[[meta]]",
        f"meta_id = {meta_id}",
        f"meta_key = {encode_frontmatter_value(meta_key, quote_style)}",
        f"meta_value = {encode_frontmatter_value(meta_value, quote_style)}",
        "+++",
    ]
    return line_ending.join(lines) + line_ending + body


TITLE_CASES = [
    ("plain", "Plain Title"),
    ("double_quote", 'Title "quoted"'),
    ("single_quote", "It's tricky"),
    ("backslash", r"Backslash \ title"),
    ("equals", "title = value"),
]

CONTENT_CASES = [
    ("plain", "Body line.\n"),
    ("multiline", "First line\n\nSecond line\n"),
    ("code_fence", "```sql\nSELECT 1;\n```\n"),
    ("literal_escape", "Literal \\n stays literal\nTabbed\tvalue\n"),
]

META_VALUE_CASES = [
    ("landing", "landing"),
    ("quoted", 'quote " and apostrophe \''),
    ("slashes", r"slashes \\ everywhere"),
    ("multiline", "line one\nline two\n"),
]

NAME_CASES = [
    ("explicit", "given-slug"),
    ("dots", "slug.v1"),
    ("none", None),
    ("empty", ""),
]

STATUS_TYPE_CASES = [
    ("draft_post", ("draft", "post")),
    ("publish_page", ("publish", "page")),
]

UPDATE_NAME_CASES = [
    ("rename", "renamed-slug"),
    ("underscore", "renamed_slug"),
    ("none", None),
    ("empty", ""),
]

META_KEY_CASES = [
    ("template", "template"),
    ("quoted", 'quote"key'),
    ("equals", "layout=name"),
    ("backslash", r"key\path"),
]

MOVE_MODE_CASES = [
    ("same_post", 1),
    ("other_post", 2),
]

META_ID_MODE_CASES = [
    ("same_id", lambda base: base),
    ("offset_id", lambda base: base + 200),
]

ID_SHIFT_CASES = [
    ("same_id", lambda base: base),
    ("shifted_id", lambda base: base + 100),
]

INVALID_PREFIXES = [
    "../",
    "./",
    "/",
    "nested/",
    "nested\\",
    "\\root\\",
    "double//",
    "a/../",
]

INVALID_TAILS = [
    "owned",
    "file",
    "path",
    "slug",
    "child",
    "x",
    "y",
    "z",
]

GENERATED_TEST_COUNT = 0


def add_generated_test(name: str, fn) -> None:
    global GENERATED_TEST_COUNT
    setattr(MarkdownStorageCrudTests, name, fn)
    GENERATED_TEST_COUNT += 1


seeded_case_index = 0
for (
    title_index,
    (title_label, title),
), (
    body_index,
    (body_label, body),
), (
    meta_index,
    (meta_label, meta_value),
), (
    quote_index,
    (quote_label, quote_style),
) in itertools.product(
    enumerate(TITLE_CASES),
    enumerate(CONTENT_CASES),
    enumerate(META_VALUE_CASES),
    enumerate([("single", "single"), ("double", "double")]),
):
    seeded_case_index += 1
    line_ending = "\r\n" if (seeded_case_index % 2 == 0) else "\n"
    method_name = (
        f"test_generated_seeded_{seeded_case_index:03d}_"
        f"{title_label}_{body_label}_{meta_label}_{quote_label}"
    )

    def test_seeded_case(
        self,
        *,
        title=title,
        body=body,
        meta_value=meta_value,
        quote_style=quote_style,
        line_ending=line_ending,
        title_index=title_index,
        body_index=body_index,
        meta_index=meta_index,
        case_number=seeded_case_index,
    ) -> None:
        post_id = 5000 + case_number
        meta_id = 9000 + case_number
        name = f"seeded-{title_index}-{body_index}-{meta_index}-{case_number}"
        status = "publish" if case_number % 2 == 0 else "draft"
        post_type = "page" if case_number % 3 == 0 else "post"
        meta_key = f"meta-{title_index}-{body_index}-{meta_index}"
        seeded_text = build_seeded_markdown(
            title=title,
            name=name,
            status=status,
            post_type=post_type,
            date_gmt="2026-04-23T00:00:00Z",
            modified_gmt="2026-04-23T01:00:00Z",
            meta_id=meta_id,
            meta_key=meta_key,
            meta_value=meta_value,
            body=body,
            quote_style=quote_style,
            line_ending=line_ending,
        )
        (self.root / f"{post_id}-{name}.md").write_text(seeded_text, encoding="utf-8")

        post = self.connection.execute(
            """
            SELECT ID, post_title, post_name, post_status, post_type, post_content
            FROM wp_posts
            """
        ).fetchone()
        meta = self.connection.execute(
            """
            SELECT meta_id, post_id, meta_key, meta_value
            FROM wp_postmeta
            """
        ).fetchone()

        self.assertEqual(dict(post), {
            "ID": post_id,
            "post_title": title,
            "post_name": name,
            "post_status": status,
            "post_type": post_type,
            "post_content": body,
        })
        self.assertEqual(dict(meta), {
            "meta_id": meta_id,
            "post_id": post_id,
            "meta_key": meta_key,
            "meta_value": meta_value,
        })

    add_generated_test(method_name, test_seeded_case)


insert_case_index = 0
for (
    title_label,
    title,
), (
    name_label,
    name,
), (
    body_label,
    body,
), (
    status_type_label,
    (status, post_type),
) in itertools.product(
    TITLE_CASES,
    NAME_CASES,
    CONTENT_CASES,
    STATUS_TYPE_CASES,
):
    insert_case_index += 1
    method_name = (
        f"test_generated_insert_{insert_case_index:03d}_"
        f"{title_label}_{name_label}_{body_label}_{status_type_label}"
    )

    def test_insert_case(
        self,
        *,
        title=title,
        name=name,
        body=body,
        status=status,
        post_type=post_type,
    ) -> None:
        self.insert_post(
            title=title,
            name=name,
            status=status,
            post_type=post_type,
            content=body,
        )

        row = self.connection.execute(
            """
            SELECT ID, post_title, post_name, post_status, post_type, post_content
            FROM wp_posts
            """
        ).fetchone()
        expected_name = expected_post_name(title, name)

        self.assertEqual(dict(row), {
            "ID": 1,
            "post_title": title,
            "post_name": expected_name,
            "post_status": status,
            "post_type": post_type,
            "post_content": body,
        })
        files = sorted(self.root.glob("*.md"))
        self.assertEqual([file.name for file in files], [f"1-{expected_name}.md"])
        self.assertTrue(files[0].read_text(encoding="utf-8").endswith(body))

    add_generated_test(method_name, test_insert_case)


update_case_index = 0
for (
    initial_name_label,
    initial_name,
), (
    update_name_label,
    update_name,
), (
    body_label,
    body,
), (
    shift_label,
    shift_fn,
) in itertools.product(
    NAME_CASES,
    UPDATE_NAME_CASES,
    CONTENT_CASES,
    ID_SHIFT_CASES,
):
    update_case_index += 1
    method_name = (
        f"test_generated_update_{update_case_index:03d}_"
        f"{initial_name_label}_{update_name_label}_{body_label}_{shift_label}"
    )

    def test_update_case(
        self,
        *,
        initial_name=initial_name,
        update_name=update_name,
        body=body,
        shift_fn=shift_fn,
        case_number=update_case_index,
    ) -> None:
        original_title = f"Original {case_number}"
        updated_title = f'Updated "{case_number}" title'
        self.insert_post(
            title=original_title,
            name=initial_name,
            status="draft",
            post_type="post",
            content="Original body.\n",
            post_id=1,
        )

        new_id = shift_fn(1)
        self.connection.execute(
            """
            WITH target AS (
                SELECT rowid AS rid FROM wp_posts WHERE ID = 1
            )
            UPDATE wp_posts
            SET
                ID = ?,
                post_title = ?,
                post_name = ?,
                post_status = 'publish',
                post_type = 'page',
                post_date_gmt = '2026-04-23T00:00:00Z',
                post_modified_gmt = '2026-04-23T05:00:00Z',
                post_content = ?
            WHERE rowid = (SELECT rid FROM target)
            """,
            (new_id, updated_title, update_name, body),
        )

        expected_name = expected_post_name(updated_title, update_name)
        row = self.connection.execute(
            """
            SELECT ID, post_title, post_name, post_status, post_type, post_content
            FROM wp_posts
            WHERE ID = ?
            """,
            (new_id,),
        ).fetchone()

        self.assertEqual(dict(row), {
            "ID": new_id,
            "post_title": updated_title,
            "post_name": expected_name,
            "post_status": "publish",
            "post_type": "page",
            "post_content": body,
        })
        self.assertEqual(
            sorted(file.name for file in self.root.glob("*.md")),
            [f"{new_id}-{expected_name}.md"],
        )

    add_generated_test(method_name, test_update_case)


meta_case_index = 0
for (
    key_label,
    meta_key,
), (
    value_label,
    meta_value,
), (
    move_label,
    target_post_id,
), (
    id_label,
    meta_id_fn,
) in itertools.product(
    META_KEY_CASES,
    META_VALUE_CASES,
    MOVE_MODE_CASES,
    META_ID_MODE_CASES,
):
    meta_case_index += 1
    method_name = (
        f"test_generated_meta_{meta_case_index:03d}_"
        f"{key_label}_{value_label}_{move_label}_{id_label}"
    )

    def test_meta_case(
        self,
        *,
        meta_key=meta_key,
        meta_value=meta_value,
        target_post_id=target_post_id,
        meta_id_fn=meta_id_fn,
        case_number=meta_case_index,
    ) -> None:
        self.insert_post(title="Left", name="left-post", content="left body\n", post_id=1)
        self.insert_post(title="Right", name="right-post", content="right body\n", post_id=2)
        self.connection.execute(
            """
            INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
            VALUES (1, ?, ?)
            """,
            (meta_key, meta_value),
        )

        new_meta_id = meta_id_fn(1)
        new_meta_key = f"{meta_key}::{case_number}"
        new_meta_value = f"{meta_value}::{case_number}"
        self.connection.execute(
            """
            WITH selected_meta AS (
                SELECT meta_id FROM wp_postmeta WHERE post_id = 1
            )
            UPDATE wp_postmeta
            SET
                post_id = ?,
                meta_id = ?,
                meta_key = ?,
                meta_value = ?
            WHERE meta_id = (SELECT meta_id FROM selected_meta)
            """,
            (target_post_id, new_meta_id, new_meta_key, new_meta_value),
        )

        rows = [
            dict(row)
            for row in self.connection.execute(
                """
                SELECT meta_id, post_id, meta_key, meta_value
                FROM wp_postmeta
                ORDER BY meta_id
                """
            )
        ]
        self.assertEqual(rows, [{
            "meta_id": new_meta_id,
            "post_id": target_post_id,
            "meta_key": new_meta_key,
            "meta_value": new_meta_value,
        }])

        left_text = (self.root / "1-left-post.md").read_text(encoding="utf-8")
        right_text = (self.root / "2-right-post.md").read_text(encoding="utf-8")
        encoded_key = encode_frontmatter_value(new_meta_key, "double")
        encoded_value = encode_frontmatter_value(new_meta_value, "double")
        if target_post_id == 1:
            self.assertIn(f"meta_key = {encoded_key}", left_text)
            self.assertIn(f"meta_value = {encoded_value}", left_text)
            self.assertNotIn(f"meta_key = {encoded_key}", right_text)
        else:
            self.assertNotIn(f"meta_key = {encoded_key}", left_text)
            self.assertIn(f"meta_key = {encoded_key}", right_text)
            self.assertIn(f"meta_value = {encoded_value}", right_text)

    add_generated_test(method_name, test_meta_case)


invalid_slug_case_index = 0
for prefix, tail in itertools.product(INVALID_PREFIXES, INVALID_TAILS):
    invalid_slug_case_index += 1
    invalid_slug = prefix + tail
    mode_label = "insert" if invalid_slug_case_index % 2 == 0 else "update"
    method_name = (
        f"test_generated_invalid_slug_{invalid_slug_case_index:03d}_{mode_label}"
    )

    def test_invalid_slug_case(
        self,
        *,
        invalid_slug=invalid_slug,
        mode_label=mode_label,
    ) -> None:
        self.insert_post(title="Safe", name="safe", content="safe body\n", post_id=1)

        if mode_label == "insert":
            with self.assertRaises(sqlite3.IntegrityError):
                self.connection.execute(
                    """
                    WITH attack(value) AS (
                        SELECT ?
                    )
                    INSERT INTO wp_posts (
                        post_title,
                        post_name,
                        post_status,
                        post_type,
                        post_date_gmt,
                        post_modified_gmt,
                        post_content
                    )
                    SELECT
                        'Attack',
                        value,
                        'draft',
                        'post',
                        '2026-04-23T00:00:00Z',
                        '2026-04-23T00:00:00Z',
                        'nope'
                    FROM attack
                    """,
                    (invalid_slug,),
                )
        else:
            with self.assertRaises(sqlite3.IntegrityError):
                self.connection.execute(
                    """
                    WITH attack(value) AS (
                        SELECT ?
                    )
                    UPDATE wp_posts
                    SET
                        post_name = (SELECT value FROM attack),
                        post_modified_gmt = '2026-04-23T04:00:00Z'
                    WHERE rowid = (SELECT rowid FROM wp_posts WHERE ID = 1)
                    """,
                    (invalid_slug,),
                )

        self.assertEqual(
            sorted(file.name for file in self.root.glob("*.md")),
            ["1-safe.md"],
        )
        row = self.connection.execute(
            """
            SELECT ID, post_name, post_modified_gmt
            FROM wp_posts
            """
        ).fetchone()
        self.assertEqual(dict(row), {
            "ID": 1,
            "post_name": "safe",
            "post_modified_gmt": "2026-04-23T00:00:00Z",
        })

    add_generated_test(method_name, test_invalid_slug_case)


assert GENERATED_TEST_COUNT >= 500, GENERATED_TEST_COUNT


if __name__ == "__main__":
    unittest.main()
