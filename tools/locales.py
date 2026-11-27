#!/usr/bin/env python3
"""Extract translatable strings (domain "emailticketcleaner") into locales/emailticketcleaner.pot,
merge them into every locales/*.po and compile the .mo files.

Used instead of xgettext/msgfmt where those are not installed. Requires: pip install polib
Usage: python3 tools/locales.py
"""
import pathlib
import re

import polib

ROOT = pathlib.Path(__file__).resolve().parent.parent
DOMAIN = "emailticketcleaner"
STR = r"""'((?:[^'\\]|\\.)*)'"""

PATTERNS = [
    # __('text', 'domain'), __s('text', 'domain')
    (re.compile(r"\b__s?\(\s*" + STR + r"\s*,\s*'" + DOMAIN + r"'\s*\)"), "single"),
    # _n('one', 'many', $n, 'domain')
    (re.compile(r"\b_sn?\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^,]+,\s*'" + DOMAIN + r"'\s*\)"), "plural"),
    (re.compile(r"\b_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^,]+,\s*'" + DOMAIN + r"'\s*\)"), "plural"),
    # Twig: __('text', domain) where `domain` is a template variable set to the plugin domain
    (re.compile(r"\b__\(\s*" + STR + r"\s*,\s*domain\s*\)"), "single"),
]


def unescape(s: str) -> str:
    return s.replace("\\'", "'").replace("\\\\", "\\")


def extract() -> polib.POFile:
    pot = polib.POFile()
    pot.metadata = {
        "Project-Id-Version": "emailticketcleaner",
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "Plural-Forms": "nplurals=2; plural=(n != 1);",
    }
    seen = {}
    files = sorted(
        p for p in ROOT.rglob("*")
        if p.suffix in (".php", ".twig") and "tests" not in p.parts and "vendor" not in p.parts
    )
    for path in files:
        text = path.read_text(encoding="utf-8")
        for regex, kind in PATTERNS:
            for m in regex.finditer(text):
                line = text.count("\n", 0, m.start()) + 1
                ref = (str(path.relative_to(ROOT)), str(line))
                if kind == "single":
                    key = (unescape(m.group(1)), None)
                else:
                    key = (unescape(m.group(1)), unescape(m.group(2)))
                if key in seen:
                    seen[key].occurrences.append(ref)
                    continue
                entry = polib.POEntry(msgid=key[0], msgstr="", occurrences=[ref])
                if key[1] is not None:
                    entry.msgid_plural = key[1]
                    entry.msgstr_plural = {0: "", 1: ""}
                seen[key] = entry
                pot.append(entry)
    return pot


def main() -> None:
    locales = ROOT / "locales"
    locales.mkdir(exist_ok=True)
    pot = extract()
    pot.save(str(locales / f"{DOMAIN}.pot"))
    # en_GB is the source language: msgstr = msgid.
    en = locales / "en_GB.po"
    if not en.exists():
        en.write_text('msgid ""\nmsgstr ""\n"Language: en_GB\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n', encoding="utf-8")
    for po_path in sorted(locales.glob("*.po")):
        po = polib.pofile(str(po_path))
        po.merge(pot)
        if po_path.name == "en_GB.po":
            for e in po:
                if e.msgid_plural:
                    e.msgstr_plural = {0: e.msgid, 1: e.msgid_plural}
                else:
                    e.msgstr = e.msgid
                if "fuzzy" in e.flags:
                    e.flags.remove("fuzzy")
        po.save(str(po_path))
        po.save_as_mofile(str(po_path.with_suffix(".mo")))
    print(f"{len(pot)} strings")


if __name__ == "__main__":
    main()
