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
                "Delete me",
                "delete-me",
                "draft",
                "post",
                "2026-04-23T00:00:00Z",
                "2026-04-23T00:00:00Z",
                "Disposable.\n",
            ),
        )
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


if __name__ == "__main__":
    unittest.main()
