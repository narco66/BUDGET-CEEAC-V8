# -*- coding: utf-8 -*-
import pdfplumber

path = r"c:\Users\HP\Downloads\Budget + PAP 2026\BUDGET EXERCICE 2026 Final.pdf"
with pdfplumber.open(path) as pdf:
    for index in (0, 1, 18):
        page = pdf.pages[index]
        words = page.extract_words()
        rows = {}
        for word in words:
            key = round(word["top"] / 3) * 3
            rows.setdefault(key, []).append(word)
        print("\n===== PAGE", index + 1, "=====")
        shown = 0
        for key in sorted(rows):
            items = sorted(rows[key], key=lambda item: item["x0"])
            texts = [item["text"] for item in items]
            if any(token[:3].isdigit() for token in texts) or "TOTAL" in texts or "Budget" in texts:
                text = " | ".join(f"{item['text']}@{round(item['x0'])}" for item in items)
                print(key, text[:260])
                shown += 1
            if shown > 12:
                break
