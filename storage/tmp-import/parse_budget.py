# -*- coding: utf-8 -*-
"""Parse the CEEAC 2026 budget PDF text into leaf lines and revenue rows."""
import json
import re
from pathlib import Path

text = Path(r"C:\laragon\www\BUDGET-CEEAC-V8\storage\tmp-import\budget.txt").read_text(encoding="utf-8")
text = text.split("===== PAGE 22 =====")[0]
text = re.sub(r"===== PAGE \d+ =====", "\n", text)

skip_prefixes = (
    "ANNEXE",
    "Code Budget",
    "Titre Chap",
    "Chap Art",
    "Parag.",
    "Action Recettes",
    "TOTAL DES",
    "TOTAL DEPENSES",
    "TOTAL CEEAC",
    "RECETTES INTERNES",
    "RECETTES EXTERNES",
    "Titre 2 RECETTES",
    "DEPENSES DU PERSONNEL",
    "DEPENSES DE BIENS",
    "DEPENSES DE TRANSFERTS",
    "DEPENSES DES ASSISES",
    "DEPENSES DES DOTATIONS",
    "DEPENSES D'EQUIPEMENTS",
    "PLAN ANNUEL",
    "REMBOURSEMENT DU CAPITAL",
    "Charge financière",
    "- Depenses",
    "Dépenses de transferts",
    "Dépenses liées",
    "Assises statutaires et",
    "Dotations aux Institutions",
    "Equipements informatiques",
    "Autres dépenses à caractère",
    "Acquisition du materiel",
    "Dotation aux amortissements",
)

records = []
current = None

def is_code_line(line: str):
    return re.match(r"^(\d{3,7})(\D|$)", line) is not None

for raw in text.splitlines():
    line = raw.strip()
    if not line:
        continue
    if line.startswith(skip_prefixes):
        continue
    if is_code_line(line):
        if current:
            records.append(current)
        match = re.match(r"^(\d{3,7})(.*)$", line)
        current = {"code": match.group(1), "body": match.group(2).strip()}
    elif current is not None:
        current["body"] = (current["body"] + " " + line).strip()

if current:
    records.append(current)

def parse_numbers(body: str):
    cleaned = body
    cleaned = re.sub(r"\d+\s*%", " ", cleaned)
    cleaned = re.sub(r"\bR\d+\b", " ", cleaned)
    cleaned = re.sub(r"COMAR\s+\d+", " ", cleaned, flags=re.I)
    cleaned = re.sub(r"\b1 0 000 000\b", "10 000 000", cleaned)
    tokens = re.findall(r"\d+", cleaned)
    numbers = []
    index = 0
    while index < len(tokens):
        parts = [tokens[index]]
        index += 1
        while index < len(tokens) and re.fullmatch(r"\d{3}", tokens[index]):
            if len(parts) >= 2 and parts[-1] == "000" and tokens[index] != "000":
                break
            parts.append(tokens[index])
            index += 1
        value = int("".join(parts))
        if value in (2025, 2026):
            continue
        numbers.append(value)
    return numbers

def split_amounts(nums):
    if not nums:
        return 0, 0, 0
    total = nums[0]
    ceeac = nums[1] if len(nums) > 1 else total
    ptf = nums[2] if len(nums) > 2 else 0
    if ceeac + ptf == total:
        return total, ceeac, ptf
    if total > 0 and ceeac == 0 and ptf == 0:
        return total, total, 0
    if total == 0 and ceeac + ptf > 0:
        return ceeac + ptf, ceeac, ptf
    if total > 0 and ptf == 0:
        return total, total, 0
    if total > 0 and ceeac + ptf != total:
        ceeac = max(0, total - ptf)
        return total, ceeac, ptf
    return total, ceeac, ptf

parsed = []
for record in records:
    nums = parse_numbers(record["body"])
    total, ceeac, ptf = split_amounts(nums)
    label = re.sub(r"\d{1,3}(?: \d{3})+|\b\d+\b", " ", record["body"])
    label = re.sub(r"\s+", " ", label).strip(" -:;,.")
    parsed.append({
        "code": record["code"],
        "label": label or record["code"],
        "total": total,
        "ceeac": ceeac,
        "ptf": ptf,
        "raw": record["body"][:180],
    })

# Deduplicate codes: keep the row with the highest total, sum if both positive and labels differ.
merged = {}
for row in parsed:
    code = row["code"]
    if code not in merged:
        merged[code] = row
        continue
    previous = merged[code]
    if row["total"] > previous["total"]:
        if previous["total"] > 0 and row["label"] != previous["label"]:
            row["label"] = previous["label"]
        merged[code] = row
    elif previous["total"] == 0 and row["label"]:
        previous["label"] = row["label"] or previous["label"]

codes = set(merged)
leaves = []
for code, row in merged.items():
    if any(other != code and other.startswith(code) for other in codes):
        row["aggregate"] = True
        continue
    leaves.append(row)

def scale(code: str) -> int:
    return 1000 if re.match(r"^20[1-6]", code) else 1

def family(code: str) -> str:
    if re.match(r"^7", code):
        return "recette"
    if re.match(r"^20[1-6]", code):
        return "pap"
    if code.startswith("209"):
        return "programme"
    return "depense"

expenses = []
recettes = []
for row in leaves:
    factor = scale(row["code"])
    item = {
        "code": row["code"],
        "label": row["label"][:255],
        "montant": row["total"] * factor,
        "ceeac": row["ceeac"] * factor,
        "ptf": row["ptf"] * factor,
        "famille": family(row["code"]),
    }
    if item["famille"] == "recette":
        recettes.append(item)
    else:
        expenses.append(item)

def total_of(items, famille=None):
    return sum(i["montant"] for i in items if famille is None or i["famille"] == famille)

report = {
    "records": len(parsed),
    "leaves": len(leaves),
    "depenses": total_of(expenses),
    "pap": total_of(expenses, "pap"),
    "programme": total_of(expenses, "programme"),
    "depense": total_of(expenses, "depense"),
    "recettes": total_of(recettes),
    "attendu_depenses": 40305795803,
    "attendu_pap": 22365281000,
    "attendu_recettes": 40305795803,
}

out = Path(r"C:\laragon\www\BUDGET-CEEAC-V8\storage\tmp-import")
(out / "parse-report.json").write_text(json.dumps({"report": report, "expenses": expenses, "recettes": recettes}, ensure_ascii=False, indent=2), encoding="utf-8")
print(json.dumps(report, indent=2))
print("--- codes 201 / 66 / 72 / 74 ---")
for row in leaves:
    if row["code"][:3] in {"201", "661", "721", "741"} and row["total"]:
        print(row["code"], row["total"], row["ceeac"], row["ptf"], row["label"][:70])
