#!/usr/bin/env python3
from __future__ import annotations

import re
import unicodedata

import pymupdf as fitz
import build_official_primary_curriculum as base


# Exact printed-page spans that contain the curricular tables. The broader
# field sections in the official programs also include blank / photographic
# transition pages before the next field. Those pages must not be treated as
# failed table detections. Verified against the 2024 SEP editions.
OFFICIAL_TABLE_RANGES = {
    'F3': {'LEN': (24, 34), 'SPC': (40, 48), 'ENS': (54, 60), 'DHC': (67, 70)},
    'F4': {'LEN': (24, 36), 'SPC': (42, 51), 'ENS': (56, 66), 'DHC': (72, 76)},
    'F5': {'LEN': (22, 35), 'SPC': (40, 52), 'ENS': (59, 76), 'DHC': (83, 86)},
}

for phase_code, field_ranges in OFFICIAL_TABLE_RANGES.items():
    for field_code, (first_page, last_page) in field_ranges.items():
        field_name, _, _ = base.PHASES[phase_code]['fields'][field_code]
        base.PHASES[phase_code]['fields'][field_code] = (field_name, first_page, last_page)


def repair_text(text: str) -> str:
    """Normalize text without changing the official wording.

    SEP PDFs visually hyphenate words at line endings. Depending on the PDF
    extraction path this can arrive as ``cotidia- nas``. Joining only alphabetic
    fragments separated by a hyphen + whitespace removes that layout artifact
    while preserving semantic hyphens such as ``COVID-19``.
    """
    text = base.clean(text)
    text = re.sub(
        r'(?<=[A-Za-zÁÉÍÓÚÜÑáéíóúüñ])-\s+(?=[A-Za-zÁÉÍÓÚÜÑáéíóúüñ])',
        '',
        text,
    )
    return base.clean(text)


def match_key(text: str) -> str:
    text = repair_text(text).casefold()
    text = ''.join(
        ch for ch in unicodedata.normalize('NFD', text)
        if unicodedata.category(ch) != 'Mn'
    )
    return re.sub(r'[^a-z0-9]+', '', text)


def cell_blocks(page, bbox, printed_page: int):
    if not bbox:
        return []

    rect = fitz.Rect(bbox)
    if rect.width > 6 and rect.height > 6:
        rect = fitz.Rect(rect.x0 + 2, rect.y0 + 2, rect.x1 - 2, rect.y1 - 2)

    # Use word-aware extraction inside the already detected table cell. This
    # preserves spaces between words better than raw PDF text blocks while the
    # table detector still provides the correct cell geometry.
    paragraphs = base.paras(page, rect, printed_page)
    result = []
    for paragraph in paragraphs:
        text = repair_text(paragraph.text)
        if not text:
            continue
        if 'programa de estudio para la educación primaria' in text.casefold():
            continue
        result.append(base.P(text, printed_page))
    return result


def cell_text(page, bbox, printed_page: int) -> str:
    return repair_text(' '.join(item.text for item in cell_blocks(page, bbox, printed_page)))


def select_curriculum_table(page):
    try:
        finder = page.find_tables()
    except Exception:
        return None

    candidates = []
    for table in finder.tables:
        if table.col_count < 3 or table.row_count < 2:
            continue
        bbox = fitz.Rect(table.bbox)
        if bbox.width / page.rect.width < 0.55 or bbox.height / page.rect.height < 0.15:
            continue
        score = (1000 if table.col_count == 3 else 0) + bbox.width * bbox.height
        candidates.append((score, table))

    if not candidates:
        return None
    return max(candidates, key=lambda item: item[0])[1]


def is_header_row(texts: list[str]) -> bool:
    merged = repair_text(' '.join(texts)).casefold()
    if not merged:
        return True
    if 'procesos de desarrollo' in merged:
        return True
    if texts and repair_text(texts[0]).casefold() in {'contenido', 'contenidos'}:
        return True
    if (
        'grado' in merged
        and any(word in merged for word in ('primer', 'segundo', 'tercer', 'cuarto', 'quinto', 'sexto'))
        and len(repair_text(texts[0])) < 20
    ):
        return True
    return False


def extract_field(doc, ph, fc, name, first_page, last_page, grade_names, warns):
    rows = []
    current = None

    for printed in range(first_page, last_page + 1):
        index = printed - 1
        if not 0 <= index < len(doc):
            warns.append(base.W(ph, fc, printed, 'extracción sospechosa: página fuera del PDF'))
            continue

        page = doc[index]
        table = select_curriculum_table(page)
        if table is None:
            warns.append(base.W(ph, fc, printed, 'extracción sospechosa: no se detectó la tabla curricular'))
            continue

        for table_row in table.rows:
            cells = list(table_row.cells)
            if len(cells) < 3:
                continue
            cells = cells[:3]
            texts = [cell_text(page, cell, printed) for cell in cells]
            if is_header_row(texts):
                continue

            content = repair_text(texts[0])
            grade_one = cell_blocks(page, cells[1], printed)
            grade_two = cell_blocks(page, cells[2], printed)
            if not content and not grade_one and not grade_two:
                continue

            repeated = bool(
                content and current and match_key(content) == match_key(current.content)
            )

            if content and not repeated:
                if current:
                    current.g1 = base.norm(current.g1)
                    current.g2 = base.norm(current.g2)
                    current.content = repair_text(current.content)
                    current.g1 = [base.P(repair_text(p.text), p.page) for p in current.g1]
                    current.g2 = [base.P(repair_text(p.text), p.page) for p in current.g2]
                    rows.append(current)
                current = base.R(content, printed)
            elif current is None:
                warns.append(base.W(ph, fc, printed, 'Se encontró continuación de fila sin contenido previo'))
                continue

            current.g1.extend(grade_one)
            current.g2.extend(grade_two)

    if current:
        current.g1 = base.norm(current.g1)
        current.g2 = base.norm(current.g2)
        current.content = repair_text(current.content)
        current.g1 = [base.P(repair_text(p.text), p.page) for p in current.g1]
        current.g2 = [base.P(repair_text(p.text), p.page) for p in current.g2]
        rows.append(current)

    rows = [
        row for row in rows
        if row.content
        and row.content.casefold() not in {'contenido', 'contenidos'}
        and 'contenidos y procesos de desarrollo' not in row.content.casefold()
    ]

    if len(rows) < 5:
        warns.append(base.W(ph, fc, None, f'Sólo se extrajeron {len(rows)} contenidos de {name}; extracción sospechosa'))

    anchor = base.ANCHORS.get((ph, fc))
    if anchor:
        key = match_key(anchor)
        if not any(key in match_key(row.content) for row in rows):
            warns.append(base.W(ph, fc, None, f'No apareció el ancla esperada: {anchor}'))

    # Any surviving alphabetic "word- fragment" sequence indicates that a
    # visual line-break hyphen escaped normalization and the catalog must stop.
    broken_word = re.compile(
        r'[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]-\s+[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]'
    )
    for row in rows:
        candidates = [row.content, *(p.text for p in row.g1), *(p.text for p in row.g2)]
        if any(broken_word.search(text) for text in candidates):
            warns.append(base.W(ph, fc, row.page, 'extracción sospechosa: quedó una palabra cortada por salto de línea'))
            break

    return rows


base.extract_field = extract_field

if __name__ == '__main__':
    raise SystemExit(base.main())
