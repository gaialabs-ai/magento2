"""DB schema assertions for Magento_Tag declarative schema.

Milestone requires the four `tag*` tables created by `bin/magento setup:upgrade`
from `app/code/Magento/Tag/etc/db_schema.xml`, with FKs pointing at
`customer_entity`, `store`, and `catalog_product_entity`.

The `bin/magento` CLI is the application's own tool — invoking it exercises
the DI graph, module registration, and schema-loader path end-to-end.
"""
import subprocess
import pytest


MAGENTO_ROOT = "/l2l/workspace/magento2"


def _bin_magento(*args, timeout=60) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["php", "bin/magento", *args],
        cwd=MAGENTO_ROOT,
        capture_output=True,
        text=True,
        timeout=timeout,
    )


class TestModuleRegistered:
    """`bin/magento module:status Magento_Tag` — module is enabled."""

    def test_module_status_shows_enabled(self):
        """HAPPY_PATH: module reports enabled via the app's own CLI."""
        result = _bin_magento("module:status", "Magento_Tag")
        assert result.returncode == 0, result.stderr
        combined = (result.stdout + result.stderr).lower()
        assert "magento_tag" in combined
        # 'module is enabled' is the human-readable status.
        assert "enabled" in combined


class TestSchemaTables:
    """Four tag_* tables must exist per etc/db_schema.xml (`tag`, `tag_relation`, `tag_summary`, `tag_properties`)."""

    def _mysql_query(self, sql: str) -> str:
        cmd = [
            "mysql", "-h127.0.0.1", "-P3306", "-umagento", "-pmagento",
            "-N", "-B", "-e", sql, "magento",
        ]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
        assert result.returncode == 0, result.stderr
        return result.stdout.strip()

    @pytest.mark.parametrize("table", ["tag", "tag_relation", "tag_summary", "tag_properties"])
    def test_tag_table_exists(self, table):
        """HAPPY_PATH: SHOW TABLES LIKE 'tag' returns a row for each of the four tables."""
        row = self._mysql_query(f"SHOW TABLES LIKE '{table}';")
        assert row == table, f"Table {table} missing (got: {row!r})"

    def test_tag_columns_match_schema(self):
        """HAPPY_PATH: `tag` has the columns declared in db_schema.xml."""
        rows = self._mysql_query("DESCRIBE tag;")
        cols = {line.split("\t")[0] for line in rows.splitlines()}
        expected = {"tag_id", "name", "status", "first_customer_id", "first_store_id"}
        missing = expected - cols
        assert not missing, f"tag table missing columns: {missing} (present: {cols})"

    def test_tag_relation_columns_match_schema(self):
        """HAPPY_PATH: `tag_relation` has the columns declared in db_schema.xml."""
        rows = self._mysql_query("DESCRIBE tag_relation;")
        cols = {line.split("\t")[0] for line in rows.splitlines()}
        expected = {"tag_relation_id", "tag_id", "customer_id", "product_id",
                    "store_id", "active", "created_at"}
        missing = expected - cols
        assert not missing, f"tag_relation table missing columns: {missing} (present: {cols})"

    def test_tag_relation_foreign_keys(self):
        """HAPPY_PATH: FKs on tag_relation reference tag, customer_entity, catalog_product_entity, store."""
        rows = self._mysql_query(
            "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE "
            "WHERE TABLE_SCHEMA='magento' AND TABLE_NAME='tag_relation' "
            "AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY REFERENCED_TABLE_NAME;"
        )
        referenced = set(rows.splitlines()) if rows else set()
        for ref in ("tag", "customer_entity", "catalog_product_entity", "store"):
            assert ref in referenced, (
                f"Expected FK from tag_relation -> {ref}, got {referenced}"
            )
