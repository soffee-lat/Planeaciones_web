#!/usr/bin/env python3
from __future__ import annotations

import re
import unicodedata

import pymupdf as fitz
import build_official_primary_curriculum as base


def match_key(text: str) -> str:
    text = base.clean(text).casefold()
    text = ''.join(
        ch for ch in unicodedata.normalize('NFD', text)
        if unicodedata.category(ch) != 'Mn'
    )
    return re.sub(r'[^a-z0-9]+', '', text)


def text_flags() -> int:
    return (
        getattr(fitz, 'TEXTFLAGS_TEXT', 0)
        | getattr(fitz, 'TEXT_DEHYPHENATE', 0)
        | getattr(fitz, 'TEXT_PRESERVE_WHITESPACE', 0)
    )


def cell_blocks(page, bbox, printed_page: int):
    if not bbox:
        return []

    rect = fitz.Rect(bbox)
    if rect.width > 6 and rect.height > 6:
        rect = fitz.Rect(rect.x0 + 2, rect.y0 + 2, rect.x1 - 2, rect.y1 - 2)

    blocks = page.get_text('blocks', clip=rect, flags=text_flags(), sort=True)
    result = []
    for block in blocks:
        text = base.clean(str(block[4]))
        if not text:
            continue
        if 'programa de estudio para la educación primaria' in text.casefold():
            continue
        result.append(base.P(text, printed_page))
    return result


def cell_text(page, bbox, printed_page: int) -> str:
    return base.clean(' '.join(item.text for item in cell_blocks(page, bbox, printed_page)))


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
    merged = base.clean(' '.join(texts)).casefold()
    if not merged:
        return True
    if 'procesos de desarrollo' in merged:
        return True
    if texts and base.clean(texts[0]).casefold() in {'contenido', 'contenidos'}:
        return True
    if (
        'grado' in merged
        and any(word in merged for word in ('primer', 'segundo', 'tercer', 'cuarto', 'quinto', 'sexto'))
        and len(base.clean(texts[0])) < 20
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

            content = texts[0]
            grade_one = cell_blocks(page, cells[1], printed)
            grade_two = cell_blocks(page, cells[2], printed)

            if not content and not grade_one and not grade_two:
                continue

            repeated = bool(
                content
                and current
                and match_key(content) == match_key(current.content)
            )

            if content and not repeated:
                if current:
                    current.g1 = base.norm(current.g1)
                    current.g2 = base.norm(current.g2)
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
        rows.append(current)

    rows = [
        row for row in rows
        if row.content
        and row.content.casefold() not in {'contenido', 'contenidos'}
        and 'contenidos y procesos de desarrollo' not in row.content.casefold()
    ]

    if len(rows) < 5:
        warns.append(
            base.W(
                ph,
                fc,
                None,
                f'Sólo se extrajeron {len(rows)} contenidos de {name}; extracción sospechosa',
            )
        )

    anchor = base.ANCHORS.get((ph, fc))
    if anchor:
        key = match_key(anchor)
        if not any(key in match_key(row.content) for row in rows):
            warns.append(base.W(ph, fc, None, f'No apareció el ancla esperada: {anchor}'))

    return rows


base.extract_field = extract_field

if __name__ == '__main__':
    raise SystemExit(base.main())
