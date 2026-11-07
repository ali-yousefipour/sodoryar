"""Build a private, repeatable phpMyAdmin SQL import from taxi-driver XLSX.

Usage: python scripts/build-driver-import.py input.xlsx output.sql.gz
Requires openpyxl. The output contains personal data: NEVER commit or publish it.
"""
import gzip
import json
import re
import sys
from datetime import date, datetime
from pathlib import Path

import openpyxl


def literal(value):
    if value is None:
        return "NULL"
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''").replace("\x00", "") + "'"


def clean(value):
    if value is None:
        return ""
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    return str(value).strip()


def valid_national_code(code):
    if not re.fullmatch(r"\d{10}", code):
        return False
    value = sum(int(code[i]) * (10 - i) for i in range(9)) % 11
    return int(code[-1]) == (value if value < 2 else 11 - value)


def build(source, output):
    book = openpyxl.load_workbook(source, read_only=True, data_only=True)
    sheet = book.active
    rows = sheet.iter_rows(values_only=True)
    headers = [clean(h) for h in next(rows)]
    required = {"کد ملی", "نام", "نام خانوادگی", "شماره تلفن همراه", "خط", "کد تاکسیران در خط", "پلاک خودرو"}
    missing = required - set(headers)
    if missing:
        raise ValueError("Missing headers: " + ", ".join(sorted(missing)))
    position = {name: headers.index(name) for name in required}
    columns = "national_code,first_name,last_name,mobile,line_area,line_code,vehicle_plate,national_code_valid,details_json,source_name"
    sql_prefix = "INSERT INTO global_drivers (" + columns + ") VALUES\n"
    sql_suffix = "\nON DUPLICATE KEY UPDATE first_name=VALUES(first_name),last_name=VALUES(last_name),mobile=VALUES(mobile),line_area=VALUES(line_area),line_code=VALUES(line_code),vehicle_plate=VALUES(vehicle_plate),national_code_valid=VALUES(national_code_valid),details_json=VALUES(details_json),source_name=VALUES(source_name);\n"
    seen = set(); count = 0; invalid = 0; batch = []
    with gzip.open(output, "wt", encoding="utf-8", newline="\n") as out:
        out.write("-- Private Sodoryar import. Import migration 004_global_drivers.sql first.\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n")
        for row in rows:
            code = re.sub(r"[^0-9]", "", clean(row[position["کد ملی"]]))
            if len(code) != 10 or code in seen:
                raise ValueError("Missing, invalid-length or duplicate national code at row " + str(count + 2))
            seen.add(code)
            if not valid_national_code(code):
                invalid += 1
            details = {key: clean(value) for key, value in zip(headers, row) if key and value is not None and clean(value) != ""}
            values = [
                code, clean(row[position["نام"]]), clean(row[position["نام خانوادگی"]]),
                clean(row[position["شماره تلفن همراه"]]), clean(row[position["خط"]]),
                clean(row[position["کد تاکسیران در خط"]]), clean(row[position["پلاک خودرو"]]),
            ]
            vals = [literal(v) for v in values] + ["1" if valid_national_code(code) else "0", literal(json.dumps(details, ensure_ascii=False, default=str, separators=(",", ":"))), literal(Path(source).name)]
            batch.append("(" + ",".join(vals) + ")")
            count += 1
            if len(batch) >= 100:
                out.write(sql_prefix + ",\n".join(batch) + sql_suffix)
                batch.clear()
        if batch:
            out.write(sql_prefix + ",\n".join(batch) + sql_suffix)
        out.write("COMMIT;\n")
    print(f"rows={count} invalid_checksum={invalid} output={output}")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: build-driver-import.py input.xlsx output.sql.gz")
    build(sys.argv[1], sys.argv[2])
