import pathlib
import sqlite3
import subprocess
import tempfile
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


if __name__ == "__main__":
    unittest.main()
