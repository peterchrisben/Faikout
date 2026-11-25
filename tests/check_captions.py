#!/usr/bin/env python3
"""Checks that captions of input fields are at most 38 characters (English and German).

Symcon 9.1 wraps longer captions of input fields over the field above (9.0 cut them with "…").
Explanations belong in a Label above or below. Labels, buttons, check boxes, panels and list
column headers are not affected. Checks form.json and input fields built in the PHP code.
Call: python3 tests/check_captions.py
"""
import json
import re
import sys
from pathlib import Path

MAX = 38
INPUTS = {'ValidationTextBox', 'PasswordTextBox', 'NumberSpinner', 'Select', 'SelectVariable', 'SelectInstance',
          'SelectObject', 'SelectScript', 'SelectTime', 'SelectDate', 'SelectColor', 'SelectFile', 'IntervalBox'}

root = Path(__file__).resolve().parent.parent
errors = []
for module in sorted(p.parent for p in root.glob('*/module.json')):
    locale = module / 'locale.json'
    de = json.loads(locale.read_text(encoding='utf-8'))['translations']['de'] if locale.exists() else {}

    def check(kind, caption, where):
        for lang, text in (('en', caption), ('de', de.get(caption, caption))):
            if len(text) > MAX:
                errors.append(f'{where}: {kind} „{text}“ ({lang}, {len(text)} Zeichen, höchstens {MAX})')

    def walk(node):
        if isinstance(node, dict):
            if node.get('type') in INPUTS and node.get('caption'):
                check(node['type'], node['caption'], f'{module.name}/form.json {node.get("name", "")}')
            for value in node.values():
                walk(value)
        elif isinstance(node, list):
            for value in node:
                walk(value)

    form = module / 'form.json'
    if form.exists():
        walk(json.loads(form.read_text(encoding='utf-8')))
    # input fields built in PHP: ['type' => 'NumberSpinner', ... 'caption' => '…']
    php = (module / 'module.php').read_text(encoding='utf-8')
    for m in re.finditer(r"\[[^\[\]]*'type'\s*=>\s*'(\w+)'[^\[\]]*\]", php):
        if m.group(1) in INPUTS:
            c = re.search(r"'caption'\s*=>\s*(?:\$this->Translate\()?'((?:[^'\\]|\\.)*)'", m.group(0))
            if c:
                check(m.group(1), c.group(1), f'{module.name}/module.php')

for e in errors:
    print('FEHLER:', e)
print(f'Beschriftungen von Eingabefeldern geprüft, {len(errors)} Fehler')
sys.exit(1 if errors else 0)
