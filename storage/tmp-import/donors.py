# -*- coding: utf-8 -*-
import pdfplumber

path = r"c:\Users\HP\Downloads\Budget + PAP 2026\BUDGET EXERCICE 2026 Final.pdf"
with pdfplumber.open(path) as pdf:
    page = pdf.pages[0]
    words = page.extract_words()
    rows = {}
    for word in words:
        key = round(word["top"] / 3) * 3
        rows.setdefault(key, []).append(word)
    for key in sorted(rows):
        if key < 180:
            continue
        items = sorted(rows[key], key=lambda item: item["x0"])
        text = " | ".join(f"{item['text']}@{round(item['x0'])}" for item in items)
        print(key, text[:240])
