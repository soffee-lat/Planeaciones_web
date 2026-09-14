#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

try:
    import pymupdf as fitz
except ImportError as e:
    raise SystemExit('Falta PyMuPDF. Ejecuta: py -m pip install "PyMuPDF>=1.24,<2"') from e

OUT = 'curriculum_mx_nem_primary_v1.json'
CACHE = Path('.runtime/curriculum-official')

PHASES = {
    'F3': {
        'name': 'Fase 3',
        'grades': (('G1', 'Primer grado', 1), ('G2', 'Segundo grado', 2)),
        'file': 'Programa_Sintetico_Fase_3.pdf',
        'urls': ('https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_3.pdf',),
        'fields': {
            'LEN': ('Lenguajes', 24, 36),
            'SPC': ('Saberes y Pensamiento Científico', 40, 50),
            'ENS': ('Ética, Naturaleza y Sociedades', 54, 62),
            'DHC': ('De lo Humano y lo Comunitario', 67, 72),
        },
    },
    'F4': {
        'name': 'Fase 4',
        'grades': (('G3', 'Tercer grado', 3), ('G4', 'Cuarto grado', 4)),
        'file': 'Programa_Sintetico_Fase_4.pdf',
        'urls': (
            'https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_4.pdf',
            'https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/09/Programa_Sintetico_Fase_4.pdf',
        ),
        'fields': {
            'LEN': ('Lenguajes', 24, 38),
            'SPC': ('Saberes y Pensamiento Científico', 42, 52),
            'ENS': ('Ética, Naturaleza y Sociedades', 56, 68),
            'DHC': ('De lo Humano y lo Comunitario', 72, 78),
        },
    },
    'F5': {
        'name': 'Fase 5',
        'grades': (('G5', 'Quinto grado', 5), ('G6', 'Sexto grado', 6)),
        'file': 'Programa_Sintetico_Fase_5.pdf',
        'urls': ('https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_5.pdf',),
        'fields': {
            'LEN': ('Lenguajes', 22, 36),
            'SPC': ('Saberes y Pensamiento Científico', 40, 54),
            'ENS': ('Ética, Naturaleza y Sociedades', 59, 78),
            'DHC': ('De lo Humano y lo Comunitario', 83, 86),
        },
    },
}

FIELDS = (
    ('LEN', 'Lenguajes', 1),
    ('SPC', 'Saberes y Pensamiento Científico', 2),
    ('ENS', 'Ética, Naturaleza y Sociedades', 3),
    ('DHC', 'De lo Humano y lo Comunitario', 4),
)

AXES = (
    ('AX-INCLUSION', 'Inclusión', 1),
    ('AX-CRITICAL', 'Pensamiento crítico', 2),
    ('AX-INTERCULTURAL', 'Interculturalidad crítica', 3),
    ('AX-GENDER', 'Igualdad de género', 4),
    ('AX-HEALTH', 'Vida saludable', 5),
    ('AX-LITERACY', 'Apropiación de las culturas a través de la lectura y la escritura', 6),
    ('AX-ARTS', 'Artes y experiencias estéticas', 7),
)

# Anchors intentionally point at the first official content of several tables.
# If one disappears, the extractor probably skipped the first data row.
ANCHORS = {
    ('F3', 'LEN'): 'Escritura de nombres en la lengua materna.',
    ('F3', 'SPC'): 'Estudio de los números.',
    ('F3', 'ENS'): 'Impacto de las actividades humanas',
    ('F3', 'DHC'): 'La comunidad como el espacio',
    ('F4', 'LEN'): 'Narración de sucesos del pasado y del presente.',
    ('F4', 'SPC'): 'Estructura y funcionamiento del cuerpo humano',
    ('F5', 'LEN'): 'Narración de sucesos autobiográficos.',
    ('F5', 'SPC'): 'Estructura y funcionamiento del cuerpo humano',
}


@dataclass
class P:
    text: str
    page: int


@dataclass
class R:
    content: str
    page: int
    g1: list[P] = field(default_factory=list)
    g2: list[P] = field(default_factory=list)


@dataclass
class W:
    phase: str
    field: str
    page: int | None
    message: str


def clean(s: str) -> str:
    s = s.replace('\u00ad', '').replace('\u0002', '').replace('\ufeff', '').replace('\xa0', ' ')
    s = unicodedata.normalize('NFC', s)
    s = re.sub(r'[ \t\r\f\v]+', ' ', s)
    s = re.sub(r'\s*\n\s*', ' ', s)
    s = re.sub(r'\s+([,.;:!?])', r'\1', s)
    return s.strip()


def words(page):
    flags = getattr(fitz, 'TEXTFLAGS_WORDS', 0) | getattr(fitz, 'TEXT_DEHYPHENATE', 0)
    return page.get_text('words', flags=flags, sort=True)


def download(urls, dst: Path) -> str:
    dst.parent.mkdir(parents=True, exist_ok=True)
    side = dst.with_suffix(dst.suffix + '.source-url.txt')
    if dst.exists() and dst.stat().st_size > 20000:
        return side.read_text(encoding='utf-8').strip() if side.exists() else urls[0]

    err = None
    for url in urls:
        try:
            print('Descargando', url)
            req = Request(
                url,
                headers={
                    'User-Agent': 'Mozilla/5.0 PlaneacionesWeb/1.0',
                    'Accept': 'application/pdf,*/*;q=0.8',
                },
            )
            with urlopen(req, timeout=90) as response:
                data = response.read()
                ct = (response.headers.get('Content-Type') or '').lower()
            if len(data) < 20000 or (not data.startswith(b'%PDF') and 'pdf' not in ct):
                raise RuntimeError('respuesta no PDF')
            dst.write_bytes(data)
            side.write_text(url + '\n', encoding='utf-8')
            return url
        except (HTTPError, URLError, TimeoutError, RuntimeError) as e:
            err = e
            print('  No disponible:', e)

    raise RuntimeError(f'No fue posible descargar {dst.name}: {err}')


def _merge_intervals(intervals, gap: float = 3.0):
    normalized = sorted((min(a, b), max(a, b)) for a, b in intervals if abs(b - a) >= 1)
    if not normalized:
        return []
    out = [list(normalized[0])]
    for start, end in normalized[1:]:
        if start <= out[-1][1] + gap:
            out[-1][1] = max(out[-1][1], end)
        else:
            out.append([start, end])
    return [(a, b) for a, b in out]


def _covered(intervals) -> float:
    return sum(b - a for a, b in _merge_intervals(intervals))


def _cluster_segments(segments, tolerance: float = 2.0):
    """Cluster collinear segments and measure their combined coverage.

    SEP tables draw many row/column rules as separate cell segments rather than
    one long line. The original extractor only accepted individually long lines,
    which skipped first rows and collapsed multiple DHC rows into one. Combining
    collinear segments reconstructs the actual table ruling.
    """
    clusters: list[dict] = []
    for coord, start, end in sorted(segments, key=lambda item: item[0]):
        if clusters and abs(coord - clusters[-1]['coord']) <= tolerance:
            c = clusters[-1]
            c['coords'].append(coord)
            c['coord'] = sum(c['coords']) / len(c['coords'])
            c['intervals'].append((start, end))
        else:
            clusters.append({'coord': coord, 'coords': [coord], 'intervals': [(start, end)]})
    return [(c['coord'], _covered(c['intervals'])) for c in clusters]


def rulings(page):
    hsegments = []
    vsegments = []

    def add_line(a, b):
        dx = abs(a.x - b.x)
        dy = abs(a.y - b.y)
        if dy <= 1.5 and dx >= 5:
            hsegments.append(((a.y + b.y) / 2, min(a.x, b.x), max(a.x, b.x)))
        elif dx <= 1.5 and dy >= 5:
            vsegments.append(((a.x + b.x) / 2, min(a.y, b.y), max(a.y, b.y)))

    for drawing in page.get_drawings():
        for item in drawing.get('items', []):
            if item[0] == 'l':
                add_line(item[1], item[2])
            elif item[0] == 're':
                rect = item[1]
                # Treat rectangle edges as ruling segments. Cell backgrounds and
                # borders are often encoded this way in the official PDFs.
                hsegments.extend([
                    (rect.y0, rect.x0, rect.x1),
                    (rect.y1, rect.x0, rect.x1),
                ])
                vsegments.extend([
                    (rect.x0, rect.y0, rect.y1),
                    (rect.x1, rect.y0, rect.y1),
                ])

    ys = [
        coord
        for coord, coverage in _cluster_segments(hsegments)
        if coverage >= page.rect.width * 0.40
    ]
    xs = [
        coord
        for coord, coverage in _cluster_segments(vsegments)
        if coverage >= page.rect.height * 0.10
    ]

    def dedupe(values):
        out = []
        for value in sorted(values):
            if not out or abs(value - out[-1]) > 2.5:
                out.append(value)
            else:
                out[-1] = (out[-1] + value) / 2
        return out

    return dedupe(ys), dedupe(xs)


def geometry(page, labels):
    ws = words(page)
    ys, xs = rulings(page)

    grade_bottoms = []
    for label in labels:
        matches = [w for w in ws if clean(str(w[4])).casefold() == label.casefold()]
        if matches:
            grade_bottoms.append(max(float(w[3]) for w in matches))

    # The four table boundaries are very stable in all three official PDFs.
    # Snap to detected vector rules when available; otherwise use the known
    # proportional positions as a safe fallback.
    expected = [page.rect.width * r for r in (0.145, 0.31, 0.60, 0.855)]
    candidates = [x for x in xs if page.rect.width * 0.08 < x < page.rect.width * 0.92]
    tx = []
    used = set()
    for target in expected:
        ranked = sorted(
            ((abs(x - target), i, x) for i, x in enumerate(candidates) if i not in used),
            key=lambda item: item[0],
        )
        if ranked and ranked[0][0] <= page.rect.width * 0.055:
            _, index, value = ranked[0]
            used.add(index)
            tx.append(value)
        else:
            tx.append(target)

    if any(tx[i] >= tx[i + 1] for i in range(3)):
        tx = expected

    header_bottom = max(grade_bottoms) if grade_bottoms else page.rect.height * 0.21
    below_header = [y for y in ys if y > header_bottom + 1 and y <= page.rect.height * 0.94]

    if below_header:
        top = min(below_header)
    else:
        top = min(page.rect.height * 0.25, header_bottom + 12)

    yl = [y for y in ys if top - 1 <= y <= page.rect.height * 0.94]
    if not yl or abs(yl[0] - top) > 3:
        yl.insert(0, top)
    if len(yl) < 2:
        yl.append(page.rect.height * 0.90)

    return tx, yl


def paras(page, rect, printed):
    ws = [w for w in words(page) if fitz.Rect(w[:4]).intersects(rect)]
    if not ws:
        return []

    groups = {}
    for w in ws:
        groups.setdefault((int(w[5]), int(w[6])), []).append(w)

    lines = []
    for key, items in groups.items():
        items.sort(key=lambda z: z[0])
        text = clean(' '.join(str(z[4]) for z in items))
        lines.append({
            'b': key[0],
            'y0': min(z[1] for z in items),
            'y1': max(z[3] for z in items),
            'x': min(z[0] for z in items),
            't': text,
        })

    lines.sort(key=lambda z: (round(z['y0'], 1), z['x']))
    heights = sorted(max(1, z['y1'] - z['y0']) for z in lines)
    median = heights[len(heights) // 2]

    out = []
    current = []
    last = None
    for line in lines:
        if not line['t']:
            continue
        gap = (line['y0'] - last['y1']) if last else 0
        new = bool(last and ((line['b'] != last['b'] and gap > median * 0.20) or gap > median * 0.70))
        if new and current:
            out.append(P(clean(' '.join(x['t'] for x in current)), printed))
            current = []
        current.append(line)
        last = line

    if current:
        out.append(P(clean(' '.join(x['t'] for x in current)), printed))

    return [p for p in out if p.text]


def norm(ps):
    out = []
    for p in ps:
        if out and not re.search(r'[.!?…:]$', out[-1].text) and re.match(r'^[a-záéíóúüñ(]', p.text):
            out[-1].text = clean(out[-1].text + ' ' + p.text)
        else:
            out.append(P(clean(p.text), p.page))
    return out


def extract_field(doc, ph, fc, name, first_page, last_page, grade_names, warns):
    rows = []
    current = None
    labels = (grade_names[0].split()[0], grade_names[1].split()[0])

    for printed in range(first_page, last_page + 1):
        index = printed - 1
        if not 0 <= index < len(doc):
            warns.append(W(ph, fc, printed, 'Página fuera del PDF'))
            continue

        page = doc[index]
        x, yl = geometry(page, labels)

        for y0, y1 in zip(yl, yl[1:]):
            if y1 - y0 < 8:
                continue

            cells = [fitz.Rect(x[i] + 2, y0 + 2, x[i + 1] - 2, y1 - 2) for i in range(3)]
            cp = paras(page, cells[0], printed)
            g1 = paras(page, cells[1], printed)
            g2 = paras(page, cells[2], printed)
            content_text = clean(' '.join(p.text for p in cp))

            if content_text.casefold() in {'contenido', 'contenidos'}:
                content_text = ''
            if any(
                marker in content_text.casefold()
                for marker in (
                    'programa de estudio para la educación primaria',
                    'procesos de desarrollo de aprendizaje',
                )
            ):
                continue
            if not content_text and not g1 and not g2:
                continue

            if content_text:
                if current:
                    current.g1 = norm(current.g1)
                    current.g2 = norm(current.g2)
                    rows.append(current)
                current = R(content_text, printed)
            elif current is None:
                warns.append(W(ph, fc, printed, 'Se encontró continuación de fila sin contenido previo'))
                continue

            current.g1.extend(g1)
            current.g2.extend(g2)

    if current:
        current.g1 = norm(current.g1)
        current.g2 = norm(current.g2)
        rows.append(current)

    rows = [r for r in rows if not r.content.casefold().startswith('contenidos y procesos')]

    if len(rows) < 5:
        warns.append(W(ph, fc, None, f'Sólo se extrajeron {len(rows)} contenidos de {name}; extracción sospechosa'))

    anchor = ANCHORS.get((ph, fc))
    if anchor and not any(anchor.casefold() in r.content.casefold() for r in rows):
        warns.append(W(ph, fc, None, f'No apareció el ancla esperada: {anchor}'))

    return rows


def payload(data, sources):
    phases = [
        {'code': code, 'name': d['name'], 'sort_order': int(code[1:])}
        for code, d in PHASES.items()
    ]
    grades = [
        {'code': code, 'name': name, 'phase_code': ph, 'ordinal': ordinal, 'sort_order': ordinal}
        for ph, d in PHASES.items()
        for code, name, ordinal in d['grades']
    ]

    contents = []
    pdas = []
    order = 0

    for ph, d in PHASES.items():
        g1, g2 = d['grades'][0][0], d['grades'][1][0]
        for fc, field_name, _ in FIELDS:
            for i, row in enumerate(data[(ph, fc)], 1):
                order += 1
                content_code = f'{ph}-{fc}-C{i:03d}'
                locator = f"Programa Sintético {d['name']}, {field_name}, p. {row.page}"
                contents.append({
                    'code': content_code,
                    'title': row.content,
                    'full_text': row.content,
                    'phase_code': ph,
                    'field_code': fc,
                    'source_locator': locator,
                    'sort_order': order,
                })

                for grade_code, processes in ((g1, row.g1), (g2, row.g2)):
                    for j, process in enumerate(processes, 1):
                        pdas.append({
                            'code': f'{ph}-{grade_code}-{fc}-C{i:03d}-P{j:02d}',
                            'full_text': process.text,
                            'content_code': content_code,
                            'grade_code': grade_code,
                            'source_locator': f"Programa Sintético {d['name']}, {field_name}, p. {process.page}",
                            'sort_order': len(pdas) + 1,
                        })

    return {
        'schema_version': 1,
        'curriculum': {
            'code': 'MX-NEM-PRIMARIA',
            'name': 'Educación Primaria — Nueva Escuela Mexicana',
            'country_code': 'MX',
            'educational_level': 'primaria',
            'description': 'Catálogo curricular estructurado de primaria transcrito desde los Programas Sintéticos oficiales de la SEP. Los códigos son internos de la plataforma.',
        },
        'version': {
            'number': 1,
            'label': 'Programas Sintéticos de Primaria — edición 2024',
            'source_reference': {
                'publisher': 'Secretaría de Educación Pública',
                'edition': 2024,
                'legal_basis': ['Acuerdo 14/08/22', 'Acuerdo 06/08/23', 'Acuerdo 08/08/23'],
                'phase_sources': sources,
            },
            'effective_from': None,
            'effective_until': None,
        },
        'educational_phases': phases,
        'grades': grades,
        'formative_fields': [
            {'code': code, 'name': name, 'sort_order': order}
            for code, name, order in FIELDS
        ],
        'articulating_axes': [
            {'code': code, 'name': name, 'sort_order': order}
            for code, name, order in AXES
        ],
        'curricular_contents': contents,
        'pdas': pdas,
    }


def validate(p, warns):
    errors = []
    contents = p['curricular_contents']
    pdas = p['pdas']
    content_codes = [x['code'] for x in contents]
    pda_codes = [x['code'] for x in pdas]

    if not contents:
        errors.append('No se extrajeron contenidos')
    if not pdas:
        errors.append('No se extrajeron PDA')
    if len(content_codes) != len(set(content_codes)):
        errors.append('Códigos de contenido duplicados')
    if len(pda_codes) != len(set(pda_codes)):
        errors.append('Códigos de PDA duplicados')

    known = set(content_codes)
    for pda in pdas:
        if pda['content_code'] not in known:
            errors.append('PDA huérfano: ' + pda['code'])

    for ph in PHASES:
        for fc, _, _ in FIELDS:
            if not any(x['phase_code'] == ph and x['field_code'] == fc for x in contents):
                errors.append(f'Sin contenidos: {ph}/{fc}')

    for grade in ('G1', 'G2', 'G3', 'G4', 'G5', 'G6'):
        if not any(x['grade_code'] == grade for x in pdas):
            errors.append('Sin PDA para ' + grade)

    low = json.dumps(p, ensure_ascii=False).casefold()
    for marker in ('__pending_editorial__', 'contenido de ejemplo', 'pda de ejemplo', 'sin validez curricular'):
        if marker in low:
            errors.append('Marcador bloqueado: ' + marker)

    for warning in warns:
        if (
            'sospechosa' in warning.message
            or 'sin contenido previo' in warning.message
            or 'No apareció el ancla' in warning.message
        ):
            errors.append(f'{warning.phase}/{warning.field}: {warning.message}')

    return errors


def report(path, p, warns, errors):
    obj = {
        'contents': len(p['curricular_contents']),
        'pdas': len(p['pdas']),
        'by_phase_field': {},
        'by_grade': {},
        'warnings': [w.__dict__ for w in warns],
        'errors': errors,
    }

    for ph in PHASES:
        for fc, field_name, _ in FIELDS:
            contents = [
                x for x in p['curricular_contents']
                if x['phase_code'] == ph and x['field_code'] == fc
            ]
            ids = {x['code'] for x in contents}
            obj['by_phase_field'][f'{ph}/{fc}'] = {
                'field': field_name,
                'contents': len(contents),
                'pdas': sum(1 for x in p['pdas'] if x['content_code'] in ids),
            }

    for grade in ('G1', 'G2', 'G3', 'G4', 'G5', 'G6'):
        obj['by_grade'][grade] = sum(1 for x in p['pdas'] if x['grade_code'] == grade)

    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(obj, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    return obj


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', default=OUT)
    parser.add_argument('--cache-dir', default=str(CACHE))
    parser.add_argument('--no-download', action='store_true')
    args = parser.parse_args()

    cache = Path(args.cache_dir)
    warns = []
    data = {}
    sources = {}

    for ph, d in PHASES.items():
        pdf = cache / d['file']
        if args.no_download:
            if not pdf.is_file():
                print('ERROR falta', pdf, file=sys.stderr)
                return 2
            sources[ph] = d['urls'][0]
        else:
            try:
                sources[ph] = download(d['urls'], pdf)
            except Exception as e:
                print('ERROR:', e, file=sys.stderr)
                return 2

        try:
            doc = fitz.open(pdf)
        except Exception as e:
            print('ERROR al abrir', pdf, e, file=sys.stderr)
            return 2

        print(f"\n{ph}: {d['name']} — {len(doc)} páginas")
        grade_names = (d['grades'][0][1], d['grades'][1][1])
        for fc, (field_name, first_page, last_page) in d['fields'].items():
            rows = extract_field(
                doc,
                ph,
                fc,
                field_name,
                first_page,
                last_page,
                grade_names,
                warns,
            )
            data[(ph, fc)] = rows
            print(
                f"  {fc} {field_name}: {len(rows)} contenidos, "
                f"{sum(len(r.g1) + len(r.g2) for r in rows)} PDA"
            )
        doc.close()

    p = payload(data, sources)
    errors = validate(p, warns)
    report_path = cache / 'extraction-report.json'
    result = report(report_path, p, warns, errors)

    print(
        f"\nResumen\n"
        f"  Contenidos: {result['contents']}\n"
        f"  PDA: {result['pdas']}\n"
        f"  Warnings: {len(warns)}\n"
        f"  Errors: {len(errors)}\n"
        f"  Reporte: {report_path}"
    )

    if errors:
        print('\nNO se escribió el JSON final:', file=sys.stderr)
        for error in errors:
            print('  -', error, file=sys.stderr)
        return 1

    Path(args.output).write_text(json.dumps(p, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print('\nJSON generado:', Path(args.output).resolve())
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
