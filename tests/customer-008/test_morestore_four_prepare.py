"""Business guards for the four-store 008 import plan, using synthetic shops."""

import importlib.util
import pathlib
import unittest


SCRIPT = pathlib.Path(__file__).resolve().parents[2] / "scripts/import/customer_008_morestore_four_prepare.py"
spec = importlib.util.spec_from_file_location("four_store_prepare", SCRIPT)
prepare_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare_module)


def source_rows():
    """Match only the category/status shape; real contact data never enters tests."""
    return [
        {
            "id": str(store_id), "name": f"演示门店{store_id}", "type": "1",
            "is_show": "1", "is_del": "0", "cate_id": chain.split(",")[-1],
            "cate_com": chain, "phone": "13800000000", "address": "演示地址",
            "detailed_address": "演示详情", "latitude": "30.0000", "longitude": "120.0000",
            "day_time": "10:00:00 - 20:00:00", "day_start": "10:00:00", "day_end": "20:00:00",
            "bank_code": "DO_NOT_COPY", "erp_shop_id": "DO_NOT_COPY",
        }
        for store_id, chain in [(44, "19,60"), (46, "19,60,67"), (50, "65"), (54, "70")]
    ]


class FourStorePrepareTest(unittest.TestCase):
    def test_only_allowlisted_profile_reaches_destination(self):
        plan = prepare_module.prepare(source_rows())
        self.assertEqual([row["source_id"] for row in plan["stores"]], [44, 46, 50, 54])
        self.assertEqual(plan["stores"][1]["profile"], {})
        self.assertEqual(plan["stores"][0]["target_org_path"], ["集团", "渼约", "江苏", "苏州市"])
        for row in plan["stores"]:
            self.assertNotIn("bank_code", row["profile"])
            self.assertNotIn("erp_shop_id", row["profile"])
            self.assertNotIn("cate_id", row["profile"])

    def test_closed_or_deleted_source_store_is_rejected(self):
        for field, value in [("is_show", "0"), ("is_del", "1")]:
            rows = source_rows()
            rows[0][field] = value
            with self.assertRaisesRegex(ValueError, "not an operating"):
                prepare_module.prepare(rows)

    def test_changed_category_or_extra_store_is_rejected(self):
        rows = source_rows()
        rows[1]["cate_com"] = "19,66"
        with self.assertRaisesRegex(ValueError, "category changed"):
            prepare_module.prepare(rows)
        rows = source_rows()
        rows.append(dict(rows[0], id="99"))
        with self.assertRaisesRegex(ValueError, "exactly the four"):
            prepare_module.prepare(rows)

    def test_invalid_contact_or_location_is_rejected(self):
        for field, bad, message in [
            ("phone", "invalid", "invalid contact"),
            ("latitude", "123", "out-of-range"),
            ("day_end", "25:00:00", "invalid day_end"),
        ]:
            rows = source_rows()
            rows[0][field] = bad
            with self.assertRaisesRegex(ValueError, message):
                prepare_module.prepare(rows)


if __name__ == "__main__":
    unittest.main()
