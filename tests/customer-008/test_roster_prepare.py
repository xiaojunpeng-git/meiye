"""Focused checks for the 008 roster's identity and organization boundaries."""

from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path
import tempfile
import unittest

from openpyxl import Workbook


SOURCE = Path(__file__).resolve().parents[2] / "scripts/import/customer_008_roster_prepare.py"
SPEC = spec_from_file_location("customer_008_roster_prepare", SOURCE)
MODULE = module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class RosterPreparationTest(unittest.TestCase):
    def test_province_without_region_does_not_inherit_previous_store(self):
        # A visually merged province column repeats on each row, while a new
        # province may omit its region entirely and attach stores directly.
        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["省", "区域", "门店", "职位", "姓名", "手机号"])
        sheet.append([])
        sheet.append(["江苏", "一区", "金门店", "经理", "甲", "13900000001"])
        sheet.append(["江苏", None, None, "店长", "乙", "13900000002"])
        sheet.append(["山东", None, None, "美容师", "丙", "13900000003"])
        sheet.append([None, None, "金宸店", "店长", "丁", "13900000004"])
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "sample.xlsx"
            workbook.save(path)
            rows = MODULE.parse_source(path, "meiyue")
        self.assertEqual((rows[1]["province"], rows[1]["region"], rows[1]["store"]), ("江苏", "一区", "金门店"))
        self.assertEqual((rows[2]["province"], rows[2]["region"], rows[2]["store"]), ("山东", "", ""))
        self.assertEqual((rows[3]["province"], rows[3]["region"], rows[3]["store"]), ("山东", "", "金宸店"))

    def test_overlap_store_reparents_only_the_current_rows(self):
        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["省", "区域", "门店", "职位", "姓名", "手机号"])
        sheet.append([])
        sheet.append(["广东", "广州一区", "越秀店", "店长", "甲", "13900000001"])
        sheet.append([None, None, None, "美容师", "乙", "13900000002"])
        sheet.append([None, None, "另一店", "店长", "丙", "13900000003"])
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "sample.xlsx"
            workbook.save(path)
            rows = MODULE.parse_source(path, "songni_other")
        self.assertEqual([(r["company"], r["province"], r["region"]) for r in rows], [
            ("渼约", "广州", ""), ("渼约", "广州", ""), ("歌妮", "广东", "广州一区")
        ])

    def test_phone_conflicts_stay_out_and_exact_same_person_collapses(self):
        base = {"source": "meiyue", "company": "渼约", "province": "江苏", "region": "一区", "store": "甲店", "store_header": True, "note": ""}
        rows = [
            dict(base, row=3, title="经理", name="张甲", phone="13900000001"),
            dict(base, row=4, title="经理", name="张甲", phone="13900000001", store="乙店"),
            dict(base, row=5, title="店长", name="张乙", phone="13900000002"),
            dict(base, row=6, title="美容师", name="张丙", phone="13900000002"),
            dict(base, row=7, title="区经理", name="张丁", phone="13900000003"),
            dict(base, row=8, title="经理", name="张丁", phone="13900000003", store="乙店"),
        ]
        result = MODULE.plan(rows, region_roles=True)
        self.assertEqual([row["name"] for row in result["candidates"]], ["张甲"])
        self.assertEqual(result["candidates"][0]["channels"], ["platform", "store_v3", "mobile"])
        self.assertEqual(result["candidates"][0]["data_scope"], "group")
        self.assertEqual(result["candidates"][0]["internal_account"], "A13900000001")
        self.assertEqual(result["candidates"][0]["function_scope"], "all_enabled_functions_per_channel")
        self.assertNotIn("initial_password", result["candidates"][0])
        self.assertEqual([row["row"] for row in result["same_person_collapsed_rows"]], [4])
        self.assertEqual({row["row"] for row in result["exceptions"]}, {5, 6, 7, 8})
        self.assertEqual({row["reason"] for row in result["exceptions"] if row["row"] in {7, 8}}, {"phone_job_title_conflict"})

    def test_overlap_nickname_with_new_phone_is_held_for_identity_review(self):
        common = {"company": "渼约", "province": "广州", "region": "", "store": "越秀店", "store_header": True, "note": "", "title": "美容师"}
        rows = [
            dict(common, source="meiyue", row=86, name="翘翘", phone="13900000001"),
            dict(common, source="songni_other", row=193, name="林颖翘", phone="13900000002"),
        ]
        result = MODULE.plan(rows, region_roles=True)
        self.assertEqual(result["candidates"], [])
        self.assertEqual({r["reason"] for r in result["exceptions"]}, {"possible_same_person_different_phone"})


if __name__ == "__main__":
    unittest.main()
