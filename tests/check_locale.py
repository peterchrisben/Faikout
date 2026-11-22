#!/usr/bin/env python3
"""Checks locale.json: every English text of the module has a German translation (and nothing else).

Texts are collected from:
- module.php: ->Translate('…'), BoolPresentation('…', '…'), TextIntervals([… => '…'])
- libs/FaikoutCatalog.php: group constants and value names
- form.json: caption / confirm / label
Call: python3 tests/check_locale.py
"""
import json
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parent.parent
module = root / "FaikoutDaikin"
php = (module / "module.php").read_text(encoding="utf-8")
catalog = (root / "libs" / "FaikoutCatalog.php").read_text(encoding="utf-8")
locale = json.loads((module / "locale.json").read_text(encoding="utf-8"))

STR = r"'((?:[^'\\]|\\.)*)'"


def unescape(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")


texts = set()
texts.update(unescape(m) for m in re.findall(r"->Translate\(" + STR + r"\)", php))
for m in re.finditer(r"BoolPresentation\(" + STR + r",\s*" + STR + r"\)", php):
    texts.update(unescape(x) for x in m.groups())
for block in re.findall(r"TextIntervals\(\[(.*?)\]\)", php, re.S):
    texts.update(unescape(x) for x in re.findall(r"=>\s*" + STR, block))
texts.update(unescape(m) for m in re.findall(r"const GROUP_\w+\s*=\s*" + STR, catalog))
texts.update(unescape(m) for m in re.findall(r"=> \[self::GROUP_\w+,\s*" + STR, catalog))


def walk(node):
    if isinstance(node, dict):
        for key in ("caption", "confirm", "label"):
            if isinstance(node.get(key), str) and node[key]:
                texts.add(node[key])
        for value in node.values():
            walk(value)
    elif isinstance(node, list):
        for value in node:
            walk(value)


form = json.loads((module / "form.json").read_text(encoding="utf-8"))
walk(form)

errors = []
tr = locale.get("translations", {})
if set(tr) != {"de"}:
    errors.append(f"locale.json darf nur 'de' enthalten, hat {sorted(tr)}")
de = tr.get("de", {})
for t in sorted(texts):
    if t not in de:
        errors.append(f"fehlt in de: {t!r}")
    if re.search(r"[äöüÄÖÜß]", t):
        errors.append(f"Umlaut im englischen Text: {t!r}")
for t in sorted(set(de) - texts):
    errors.append(f"überzählig in de: {t!r}")
for t, g in de.items():
    if sorted(re.findall(r"%[sd]", t)) != sorted(re.findall(r"%[sd]", g)):
        errors.append(f"Platzhalter verschieden: {t!r}")

for e in errors:
    print("FEHLER:", e)
print(f"{len(texts)} Texte geprüft, {len(errors)} Fehler")
sys.exit(1 if errors else 0)
