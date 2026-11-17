import json, os, subprocess, uuid
import pytest

REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "..", ".."))
DRIVER = os.path.join(os.path.dirname(__file__), "driver.php")
ENV = os.environ


def php(*args):
    p = subprocess.run(["php", "-d", "memory_limit=-1", DRIVER, *args], cwd=REPO, capture_output=True, text=True, timeout=280)
    line = [l for l in p.stdout.splitlines() if l.startswith("@@JSON@@")]
    assert line, p.stdout + p.stderr
    return json.loads(line[-1][8:])


def mysql(sql):
    p = subprocess.run(["mysql", "--no-defaults", "-h127.0.0.1", "-P" + ENV.get("DB_PORT", "3307"),
                        "-u" + ENV.get("DB_USER", "magento"), "-p" + ENV.get("DB_PASSWORD", "magento123"),
                        ENV.get("DB_NAME", "magento"), "-N", "-e", sql], capture_output=True, text=True, timeout=60)
    assert p.returncode == 0, p.stderr
    return p.stdout.strip()


@pytest.fixture(scope="module")
def scenario():
    r = php("scenario", uuid.uuid4().hex[:8])
    assert "error" not in r, r
    return r


class TestSchema:
    def test_tables_exist(self):
        assert mysql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='%s' AND table_name IN ('tag','tag_relation','tag_summary','tag_properties')" % ENV.get("DB_NAME", "magento")) == "4"

    def test_tag_relation_unique_and_fks(self):
        n = mysql("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema='%s' AND table_name='tag_relation' AND constraint_type='FOREIGN KEY'" % ENV.get("DB_NAME", "magento"))
        assert int(n) == 4

    def test_indexer_registered(self):
        p = subprocess.run(["php", "bin/magento", "indexer:info"], cwd=REPO, capture_output=True, text=True, timeout=200)
        assert p.returncode == 0 and "tag_summary" in p.stdout


class TestHelper:
    def test_helper_parsing(self):
        r = php("helper")
        assert r["extract"] == ["shoes", "'red shirt'", "", "blue", "green"]
        assert r["clean"] == ["a b", "x", "c"]

    def test_status_arrays(self):
        r = php("helper")
        assert r["statuses"] == {"-1": "Disabled", "0": "Pending", "1": "Approved"}
        assert [o["value"] for o in r["options"]] == [-1, 0, 1]


class TestTagFlow:
    def test_tag_save(self, scenario):
        assert scenario["tag_row"]["status"] == "1"
        assert scenario["tag_row"]["name"].startswith("ftrun_")
        assert scenario["loadByName_id"] == scenario["tag_id"]

    def test_assign_product_customer(self, scenario):
        assert scenario["saveRelation_result"] == "success"
        assert scenario["saveRelation_again"] == "exist"
        assert len(scenario["relation_rows"]) == 1
        assert scenario["relation_rows"][0]["active"] == "1"
        assert [str(x) for x in scenario["related_product_ids"]] == [str(scenario["product_id"])]

    def test_collections(self, scenario):
        assert len(scenario["tagcoll_product"]) == 1
        assert len(scenario["tagcoll_customer"]) == 1
        assert scenario["tagcoll_status_count"] == 1
        assert scenario["prodcoll_ids"] == [scenario["product_id"]]
        assert scenario["custcoll_ids"] == [scenario["customer_id"]]
        assert scenario["relcoll_count"] == 1
        assert scenario["popular_loaded"] is True

    def test_summary_reindex(self, scenario):
        for key in ("summary_rows_after_full", "summary_rows_after_row"):
            rows = {r["store_id"]: r for r in scenario[key]}
            assert set(rows) == {"0", "1"}
            assert rows["1"]["customers"] == "1" and rows["1"]["products"] == "1" and rows["1"]["popularity"] == "1"

    def test_deactivate_relation(self, scenario):
        assert scenario["active_after_deactivate"] == ["0"]

    def test_delete_cascades(self, scenario):
        assert scenario["relations_after_tag_delete"] == 0


class TestIndexerCli:
    def test_cli_reindex_tag_summary(self):
        p = subprocess.run(["php", "-d", "memory_limit=-1", "bin/magento", "indexer:reindex", "tag_summary"],
                           cwd=REPO, capture_output=True, text=True, timeout=280)
        assert p.returncode == 0, p.stdout + p.stderr
        assert "Tag Aggregation Data" in p.stdout and "index has been rebuilt successfully" in p.stdout
