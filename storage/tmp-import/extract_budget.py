# -*- coding: utf-8 -*-
"""Extract the voted CEEAC 2026 budget from the official PDF."""
import json
import re
from pathlib import Path

import pdfplumber

PDF = r"c:\Users\HP\Downloads\Budget + PAP 2026\BUDGET EXERCICE 2026 Final.pdf"
OUT = Path(r"C:\laragon\www\BUDGET-CEEAC-V8\storage\tmp-import\budget-2026.json")

CODE = re.compile(r"^(\d{3,7})(.*)$")


def cluster(words):
    ordered = sorted(words, key=lambda word: (word["top"], word["x0"]))
    lines = []
    for word in ordered:
        if not lines or word["top"] - lines[-1][0]["top"] > 3.2:
            lines.append([word])
        else:
            lines[-1].append(word)
    return lines


def column_amount(words, start, end):
    digits = [word["text"] for word in sorted(words, key=lambda item: item["x0"]) if start <= word["x0"] < end and word["text"].replace(" ", "").isdigit()]
    if not digits:
        return None
    return int("".join(digits))


def code_of(words):
    for word in sorted(words, key=lambda item: item["x0"]):
        if word["x0"] > 190:
            continue
        match = CODE.match(word["text"])
        if match:
            return match.group(1), match.group(2)
    return None, ""


def label_of(words, glued):
    parts = []
    if glued:
        parts.append(glued)
    for word in sorted(words, key=lambda item: item["x0"]):
        if word["x0"] >= 330:
            continue
        if CODE.match(word["text"]):
            continue
        if word["text"] in {"-", "–"}:
            continue
        parts.append(word["text"])
    text = re.sub(r"\s+", " ", " ".join(parts)).strip(" ,;:-")
    return text


def leaf_codes(codes):
    return {code for code in codes if not any(other != code and other.startswith(code) for other in codes)}


records = []
labels = {}
mode = "expense"
pending = []

with pdfplumber.open(PDF) as pdf:
    for page in pdf.pages[:21]:
        for line in cluster(page.extract_words()):
            blob = " ".join(word["text"] for word in line)
            if "RECETTES BUDGETAIRES" in blob:
                mode = "revenue"
            if "EVALUATION DES DEPENSES" in blob or "DEPENSES DE FONCTIONNEMENT" in blob:
                mode = "expense"
            code, glued = code_of(line)
            if mode == "revenue":
                amount = column_amount(line, 540, 650)
                ceeac, ptf = amount, 0
            else:
                amount = column_amount(line, 330, 412)
                ceeac = column_amount(line, 412, 478)
                ptf = column_amount(line, 478, 548)
            has_amount = amount is not None or ceeac is not None or ptf is not None
            caption = label_of(line, glued)
            if code:
                if records and records[-1].get("open"):
                    records[-1]["open"] = False
                records.append({
                    "code": code,
                    "label": " ".join(pending + ([caption] if caption else [])).strip(),
                    "total": amount or 0,
                    "ceeac": 0 if ceeac is None else ceeac,
                    "ptf": 0 if ptf is None else ptf,
                    "mode": mode,
                    "open": not has_amount,
                })
                pending = []
                labels[code] = records[-1]["label"]
            elif records and records[-1].get("open"):
                if caption:
                    records[-1]["label"] = (records[-1]["label"] + " " + caption).strip()
                    labels[records[-1]["code"]] = records[-1]["label"]
                if has_amount:
                    records[-1]["total"] = amount or 0
                    records[-1]["ceeac"] = 0 if ceeac is None else ceeac
                    records[-1]["ptf"] = 0 if ptf is None else ptf
                    records[-1]["open"] = False
            elif mode == "expense" and has_amount and caption and "Heures" in caption:
                records.append({
                    "code": "66315",
                    "label": "Heures supplémentaires",
                    "total": amount or 0,
                    "ceeac": 0 if ceeac is None else ceeac,
                    "ptf": 0 if ptf is None else ptf,
                    "mode": mode,
                    "open": False,
                })
                pending = []
            elif mode == "revenue" and amount and caption and not caption.upper().startswith("TOTAL"):
                slug = re.sub(r"[^A-Z0-9]+", "-", caption.upper()).strip("-")[:24]
                records.append({
                    "code": "DON-" + slug,
                    "label": caption,
                    "total": amount,
                    "ceeac": 0,
                    "ptf": amount,
                    "mode": "revenue",
                    "open": False,
                })
            elif caption and not has_amount:
                pending = [caption]
            else:
                pending = []

merged = {}
for row in records:
    current = merged.get(row["code"])
    if current is None or row["total"] > current["total"]:
        merged[row["code"]] = row

leaves = leaf_codes(set(merged))
expenses = []
recettes = []
for code, row in sorted(merged.items()):
    if code not in leaves:
        continue
    factor = 1000 if re.match(r"^20[1-6]", code) else 1
    total = row["total"] * factor
    ceeac = row["ceeac"] * factor
    ptf = row["ptf"] * factor
    if ceeac + ptf != total:
        if total > 0 and ceeac == 0 and ptf == 0:
            ceeac = total
        elif total == 0:
            total = ceeac + ptf
        elif ptf == 0:
            ceeac = total
    item = {
        "code": code,
        "label": re.sub(r"\s+", " ", row["label"])[:240],
        "montant": total,
        "ceeac": ceeac,
        "ptf": ptf,
        "pilier": labels.get(code[:3], ""),
        "axe": labels.get(code[:4], ""),
        "produit": labels.get(code[:5], ""),
    }
    if row["mode"] == "revenue" or code.startswith("7") or code.startswith("DON-"):
        recettes.append(item)
    else:
        nature = "pap" if re.match(r"^20[1-6]", code) or code.startswith("209") else "hors_pap"
        item["nature"] = nature
        expenses.append(item)

donors = [row for row in recettes if row["code"].startswith("DON-")]
donor_sum = sum(row["montant"] for row in donors)
gap = 14030281000 - donor_sum
if gap:
    donors.append({"code": "DON-AUTRES", "label": "Autres dons et financements", "montant": gap, "ceeac": 0, "ptf": gap, "pilier": "", "axe": "", "produit": ""})
states = [row for row in recettes if re.match(r"^721\d{2}$", row["code"])]

parents = []
for code, row in merged.items():
    children = [other for other in merged if other != code and other.startswith(code) and not any(
        mid != code and mid != other and other.startswith(mid) and mid.startswith(code) for mid in merged
    )]
    if not children:
        continue
    factor = 1000 if re.match(r"^20[1-6]", code) else 1
    parent_total = row["total"] * factor
    child_total = 0
    for child in children:
        child_factor = 1000 if re.match(r"^20[1-6]", child) else 1
        child_total += merged[child]["total"] * child_factor
    if parent_total != child_total and parent_total:
        parents.append((code, parent_total, child_total, parent_total - child_total, row["label"][:60]))

print("ECARTS PARENTS")
for item in parents:
    print(item)

report = {
    "depenses": sum(row["montant"] for row in expenses),
    "pap": sum(row["montant"] for row in expenses if row["nature"] == "pap" and row["code"].startswith("20") and not row["code"].startswith("209")),
    "hors": sum(row["montant"] for row in expenses if row["nature"] == "hors_pap"),
    "assises": sum(row["montant"] for row in expenses if row["code"].startswith("209")),
    "contributions": sum(row["montant"] for row in states),
    "dons": sum(row["montant"] for row in donors),
    "lignes": len(expenses),
    "etats": len(states),
}
print(json.dumps(report, indent=2, ensure_ascii=False))
OUT.write_text(json.dumps({"expenses": expenses, "contributions": states, "dons": donors}, ensure_ascii=False, indent=2), encoding="utf-8")
