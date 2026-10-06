# -*- coding: utf-8 -*-
import pdfplumber

path = r"c:\Users\HP\Downloads\Budget + PAP 2026\BUDGET EXERCICE 2026 Final.pdf"
needles = ("202142", "20223", "202231", "20334", "20411", "204115", "20512", "205121", "2052", "20521", "66313", "Heures", "66315", "66314")
with pdfplumber.open(path) as pdf:
    for number, page in enumerate(pdf.pages[:21], 1):
        words = page.extract_words()
        ordered = sorted(words, key=lambda word: (word["top"], word["x0"]))
        lines = []
        for word in ordered:
            if not lines or word["top"] - lines[-1][0]["top"] > 3.2:
                lines.append([word])
            else:
                lines[-1].append(word)
        for index, line in enumerate(lines):
            blob = " ".join(word["text"] for word in line)
            if any(needle in blob for needle in needles):
                window = lines[max(0, index - 1): index + 2]
                print(f"\n-- p{number} --")
                for row in window:
                    print(" | ".join(f"{word['text']}@{round(word['x0'])}" for word in sorted(row, key=lambda item: item["x0"]))[:220])
