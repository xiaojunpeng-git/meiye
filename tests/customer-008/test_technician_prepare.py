"""Synthetic identity/closed-store checks for the private 008 technician plan."""

import importlib.util
import pathlib
import unittest


SCRIPT = pathlib.Path(__file__).resolve().parents[2] / "scripts/import/customer_008_technician_prepare.py"
spec = importlib.util.spec_from_file_location("technician_prepare", SCRIPT)
prepare_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare_module)


def fixture():
    """Model the reviewed 50/46/3 partition without any customer PII."""
    source = []
    existing = []
    for number in range(99):
        phone = "13" + str(number).zfill(9)
        row = {"uid": number + 1, "real_name": "员工" + str(number),
               "nickname": "昵称" + str(number), "phone": phone,
               "store_id": 1 if number < 96 else 2, "status": 1, "is_del": 0,
               "store_name": "营业店" if number < 96 else "已停业店",
               "store_is_show": 1 if number < 96 else 0, "store_is_del": 0}
        source.append(row)
        if number < 50:
            existing.append({"id": number + 10, "name": "008 原姓名", "phone": phone})
    # A nickname is allowed as the employee name only when real_name is absent.
    for number in range(50, 62):
        source[number]["real_name"] = ""
    return {
        "source_rows": source, "target_employees": existing,
        "target_stores": [{"id": 8, "org_id": 9, "is_show": 1, "is_del": 0}],
        "target_accounts": ["A" + row["phone"] for row in existing],
    }


class TechnicianPrepareTest(unittest.TestCase):
    def test_existing_people_win_and_closed_store_people_are_held(self):
        result = prepare_module.prepare(fixture(), {"1": 8})
        self.assertEqual((len(result["skipped_existing"]), len(result["candidates"]),
                          len(result["held_closed_store"])), (50, 46, 3))
        self.assertEqual(sum(row["name_source"] == "nickname" for row in result["candidates"]), 12)
        self.assertEqual(result["candidates"][0]["name"], "昵称50")
        self.assertEqual(result["skipped_existing"][0]["employee_id"], 10)
        self.assertTrue(all(row["target_store_id"] == 8 for row in result["candidates"]))

    def test_phone_collision_fails_closed(self):
        data = fixture()
        data["source_rows"][51]["phone"] = data["source_rows"][50]["phone"]
        with self.assertRaisesRegex(ValueError, "duplicated"):
            prepare_module.prepare(data, {"1": 8})

    def test_missing_active_shop_mapping_fails_closed(self):
        with self.assertRaisesRegex(ValueError, "mapping"):
            prepare_module.prepare(fixture(), {})


if __name__ == "__main__":
    unittest.main()
