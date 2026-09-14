#!/usr/bin/env python3
from __future__ import annotations
import argparse, json, re, sys, unicodedata
from dataclasses import dataclass, field
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError
try:
    import fitz
except ImportError as e:
    raise SystemExit('Falta PyMuPDF. Ejecuta: py -m pip install "PyMuPDF>=1.24,<2"') from e

OUT='curriculum_mx_nem_primary_v1.json'; CACHE=Path('.runtime/curriculum-official')
PHASES={
'F3':{'name':'Fase 3','grades':(('G1','Primer grado',1),('G2','Segundo grado',2)),'file':'Programa_Sintetico_Fase_3.pdf','urls':('https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_3.pdf',),'fields':{'LEN':('Lenguajes',24,36),'SPC':('Saberes y Pensamiento Científico',40,50),'ENS':('Ética, Naturaleza y Sociedades',54,62),'DHC':('De lo Humano y lo Comunitario',67,72)}},
'F4':{'name':'Fase 4','grades':(('G3','Tercer grado',3),('G4','Cuarto grado',4)),'file':'Programa_Sintetico_Fase_4.pdf','urls':('https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_4.pdf','https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/09/Programa_Sintetico_Fase_4.pdf'),'fields':{'LEN':('Lenguajes',24,38),'SPC':('Saberes y Pensamiento Científico',42,52),'ENS':('Ética, Naturaleza y Sociedades',56,68),'DHC':('De lo Humano y lo Comunitario',72,78)}},
'F5':{'name':'Fase 5','grades':(('G5','Quinto grado',5),('G6','Sexto grado',6)),'file':'Programa_Sintetico_Fase_5.pdf','urls':('https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_5.pdf',),'fields':{'LEN':('Lenguajes',22,36),'SPC':('Saberes y Pensamiento Científico',40,54),'ENS':('Ética, Naturaleza y Sociedades',59,78),'DHC':('De lo Humano y lo Comunitario',83,86)}}}
FIELDS=(('LEN','Lenguajes',1),('SPC','Saberes y Pensamiento Científico',2),('ENS','Ética, Naturaleza y Sociedades',3),('DHC','De lo Humano y lo Comunitario',4))
AXES=(('AX-INCLUSION','Inclusión',1),('AX-CRITICAL','Pensamiento crítico',2),('AX-INTERCULTURAL','Interculturalidad crítica',3),('AX-GENDER','Igualdad de género',4),('AX-HEALTH','Vida saludable',5),('AX-LITERACY','Apropiación de las culturas a través de la lectura y la escritura',6),('AX-ARTS','Artes y experiencias estéticas',7))
ANCHORS={('F3','LEN'):'Escritura de nombres en la lengua materna.',('F3','SPC'):'Estudio de los números.',('F3','ENS'):'Impacto de las actividades humanas',('F3','DHC'):'La comunidad como el espacio',('F4','LEN'):'Narración de sucesos del pasado y del presente.',('F4','SPC'):'Estructura y funcionamiento del cuerpo humano',('F5','LEN'):'Narración de sucesos autobiográficos.',('F5','SPC'):'Estructura y funcionamiento del cuerpo humano'}

@dataclass
class P: text:str; page:int
@dataclass
class R:
    content:str; page:int; g1:list[P]=field(default_factory=list); g2:list[P]=field(default_factory=list)
@dataclass
class W: phase:str; field:str; page:int|None; message:str

def clean(s:str)->str:
    s=s.replace('\u00ad','').replace('\u0002','').replace('\ufeff','').replace('\xa0',' ')
    s=unicodedata.normalize('NFC',s); s=re.sub(r'[ \t\r\f\v]+',' ',s); s=re.sub(r'\s*\n\s*',' ',s)
    s=re.sub(r'\s+([,.;:!?])',r'\1',s); return s.strip()

def words(page):
    flags=getattr(fitz,'TEXTFLAGS_WORDS',0)|getattr(fitz,'TEXT_DEHYPHENATE',0)
    return page.get_text('words',flags=flags,sort=True)

def download(urls,dst:Path)->str:
    dst.parent.mkdir(parents=True,exist_ok=True); side=dst.with_suffix(dst.suffix+'.source-url.txt')
    if dst.exists() and dst.stat().st_size>20000:
        return side.read_text(encoding='utf-8').strip() if side.exists() else urls[0]
    err=None
    for url in urls:
        try:
            print('Descargando',url); req=Request(url,headers={'User-Agent':'Mozilla/5.0 PlaneacionesWeb/1.0','Accept':'application/pdf,*/*;q=0.8'})
            with urlopen(req,timeout=90) as r: data=r.read(); ct=(r.headers.get('Content-Type') or '').lower()
            if len(data)<20000 or (not data.startswith(b'%PDF') and 'pdf' not in ct): raise RuntimeError('respuesta no PDF')
            dst.write_bytes(data); side.write_text(url+'\n',encoding='utf-8'); return url
        except (HTTPError,URLError,TimeoutError,RuntimeError) as e: err=e; print('  No disponible:',e)
    raise RuntimeError(f'No fue posible descargar {dst.name}: {err}')

def rulings(page):
    ys=[]; xs=[]; mh=page.rect.width*.45; mv=page.rect.height*.10
    for d in page.get_drawings():
        for it in d.get('items',[]):
            if it[0]=='l':
                a,b=it[1],it[2]
                if abs(a.y-b.y)<=1.2 and abs(a.x-b.x)>=mh: ys.append((a.y+b.y)/2)
                if abs(a.x-b.x)<=1.2 and abs(a.y-b.y)>=mv: xs.append((a.x+b.x)/2)
            elif it[0]=='re':
                r=it[1]
                if r.width>=mh and r.height<=3: ys += [r.y0,r.y1]
                if r.height>=mv and r.width<=3: xs += [r.x0,r.x1]
    def de(v):
        out=[]
        for x in sorted(v):
            if not out or abs(x-out[-1])>2: out.append(x)
            else: out[-1]=(out[-1]+x)/2
        return out
    return de(ys),de(xs)

def geometry(page,labels):
    ws=words(page); ys,xs=rulings(page); gy=[]
    for lab in labels:
        c=[w for w in ws if clean(str(w[4])).casefold()==lab.casefold()]
        if c: gy.append(min(float(w[3]) for w in c))
    xc=[x for x in xs if page.rect.width*.08<x<page.rect.width*.92]; tx=[]
    if len(xc)>=4:
        xc=sorted(xc); inner=[x for x in xc[1:-1] if xc[0]+40<x<xc[-1]-40]
        if len(inner)>=2:
            a,b=max(((a,b) for i,a in enumerate(inner) for b in inner[i+1:]),key=lambda z:z[1]-z[0]); tx=[xc[0],a,b,xc[-1]]
    if len(tx)!=4:
        q=page.rect.width; tx=[q*.145,q*.31,q*.60,q*.855]
    hb=max(gy) if gy else page.rect.height*.21; yc=[y for y in ys if y>hb+1]
    top=min(yc) if yc else page.rect.height*.22; yl=[y for y in ys if top-1<=y<=page.rect.height*.94]
    if not yl or abs(yl[0]-top)>3: yl.insert(0,top)
    if len(yl)<2: yl.append(page.rect.height*.90)
    return tx,yl

def paras(page,rect,printed):
    ws=[w for w in words(page) if fitz.Rect(w[:4]).intersects(rect)]
    if not ws:return []
    groups={}
    for w in ws: groups.setdefault((int(w[5]),int(w[6])),[]).append(w)
    lines=[]
    for key,items in groups.items():
        items.sort(key=lambda z:z[0]); text=clean(' '.join(str(z[4]) for z in items))
        lines.append({'b':key[0],'y0':min(z[1] for z in items),'y1':max(z[3] for z in items),'x':min(z[0] for z in items),'t':text})
    lines.sort(key=lambda z:(round(z['y0'],1),z['x'])); hs=sorted(max(1,z['y1']-z['y0']) for z in lines); med=hs[len(hs)//2]
    out=[]; cur=[]; last=None
    for ln in lines:
        if not ln['t']:continue
        gap=(ln['y0']-last['y1']) if last else 0
        new=bool(last and ((ln['b']!=last['b'] and gap>med*.20) or gap>med*.70))
        if new and cur: out.append(P(clean(' '.join(x['t'] for x in cur)),printed)); cur=[]
        cur.append(ln); last=ln
    if cur: out.append(P(clean(' '.join(x['t'] for x in cur)),printed))
    return [p for p in out if p.text]

def norm(ps):
    out=[]
    for p in ps:
        if out and not re.search(r'[.!?…:]$',out[-1].text) and re.match(r'^[a-záéíóúüñ(]',p.text): out[-1].text=clean(out[-1].text+' '+p.text)
        else: out.append(P(clean(p.text),p.page))
    return out

def extract_field(doc,ph,fc,name,a,b,grade_names,warns):
    rows=[]; cur=None; labels=(grade_names[0].split()[0],grade_names[1].split()[0])
    for printed in range(a,b+1):
        idx=printed-1
        if not 0<=idx<len(doc): warns.append(W(ph,fc,printed,'Página fuera del PDF')); continue
        page=doc[idx]; x,yl=geometry(page,labels)
        for y0,y1 in zip(yl,yl[1:]):
            if y1-y0<8:continue
            cells=[fitz.Rect(x[i]+2,y0+2,x[i+1]-2,y1-2) for i in range(3)]
            cp,g1,g2=paras(page,cells[0],printed),paras(page,cells[1],printed),paras(page,cells[2],printed)
            ct=clean(' '.join(p.text for p in cp))
            if ct.casefold() in {'contenido','contenidos'}:ct=''
            if any(t in ct.casefold() for t in ('programa de estudio para la educación primaria','procesos de desarrollo de aprendizaje')):continue
            if not ct and not g1 and not g2:continue
            if ct:
                if cur: cur.g1=norm(cur.g1); cur.g2=norm(cur.g2); rows.append(cur)
                cur=R(ct,printed)
            elif cur is None:
                warns.append(W(ph,fc,printed,'Se encontró continuación de fila sin contenido previo')); continue
            cur.g1.extend(g1); cur.g2.extend(g2)
    if cur: cur.g1=norm(cur.g1); cur.g2=norm(cur.g2); rows.append(cur)
    rows=[r for r in rows if not r.content.casefold().startswith('contenidos y procesos')]
    if len(rows)<5: warns.append(W(ph,fc,None,f'Sólo se extrajeron {len(rows)} contenidos de {name}; extracción sospechosa'))
    anchor=ANCHORS.get((ph,fc))
    if anchor and not any(anchor.casefold() in r.content.casefold() for r in rows): warns.append(W(ph,fc,None,f'No apareció el ancla esperada: {anchor}'))
    return rows

def payload(data,sources):
    phases=[{'code':c,'name':d['name'],'sort_order':int(c[1:])} for c,d in PHASES.items()]
    grades=[{'code':c,'name':n,'phase_code':ph,'ordinal':o,'sort_order':o} for ph,d in PHASES.items() for c,n,o in d['grades']]
    contents=[]; pdas=[]; order=0
    for ph,d in PHASES.items():
        g1,g2=d['grades'][0][0],d['grades'][1][0]
        for fc,fn,_ in FIELDS:
            for i,row in enumerate(data[(ph,fc)],1):
                order+=1; cc=f'{ph}-{fc}-C{i:03d}'; loc=f"Programa Sintético {d['name']}, {fn}, p. {row.page}"
                contents.append({'code':cc,'title':row.content,'full_text':row.content,'phase_code':ph,'field_code':fc,'source_locator':loc,'sort_order':order})
                for gc,ps in ((g1,row.g1),(g2,row.g2)):
                    for j,p in enumerate(ps,1):
                        pdas.append({'code':f'{ph}-{gc}-{fc}-C{i:03d}-P{j:02d}','full_text':p.text,'content_code':cc,'grade_code':gc,'source_locator':f"Programa Sintético {d['name']}, {fn}, p. {p.page}",'sort_order':len(pdas)+1})
    return {'schema_version':1,'curriculum':{'code':'MX-NEM-PRIMARIA','name':'Educación Primaria — Nueva Escuela Mexicana','country_code':'MX','educational_level':'primaria','description':'Catálogo curricular estructurado de primaria transcrito desde los Programas Sintéticos oficiales de la SEP. Los códigos son internos de la plataforma.'},'version':{'number':1,'label':'Programas Sintéticos de Primaria — edición 2024','source_reference':{'publisher':'Secretaría de Educación Pública','edition':2024,'legal_basis':['Acuerdo 14/08/22','Acuerdo 06/08/23','Acuerdo 08/08/23'],'phase_sources':sources},'effective_from':None,'effective_until':None},'educational_phases':phases,'grades':grades,'formative_fields':[{'code':c,'name':n,'sort_order':o} for c,n,o in FIELDS],'articulating_axes':[{'code':c,'name':n,'sort_order':o} for c,n,o in AXES],'curricular_contents':contents,'pdas':pdas}

def validate(p,warns):
    err=[]; cs=p['curricular_contents']; ps=p['pdas']; cc=[x['code'] for x in cs]; pc=[x['code'] for x in ps]
    if not cs:err.append('No se extrajeron contenidos')
    if not ps:err.append('No se extrajeron PDA')
    if len(cc)!=len(set(cc)):err.append('Códigos de contenido duplicados')
    if len(pc)!=len(set(pc)):err.append('Códigos de PDA duplicados')
    known=set(cc)
    for x in ps:
        if x['content_code'] not in known:err.append('PDA huérfano: '+x['code'])
    for ph in PHASES:
        for fc,_,_ in FIELDS:
            if not any(x['phase_code']==ph and x['field_code']==fc for x in cs):err.append(f'Sin contenidos: {ph}/{fc}')
    for g in ('G1','G2','G3','G4','G5','G6'):
        if not any(x['grade_code']==g for x in ps):err.append('Sin PDA para '+g)
    low=json.dumps(p,ensure_ascii=False).casefold()
    for m in ('__pending_editorial__','contenido de ejemplo','pda de ejemplo','sin validez curricular'):
        if m in low:err.append('Marcador bloqueado: '+m)
    for w in warns:
        if 'sospechosa' in w.message or 'sin contenido previo' in w.message or 'No apareció el ancla' in w.message:err.append(f'{w.phase}/{w.field}: {w.message}')
    return err

def report(path,p,warns,errors):
    obj={'contents':len(p['curricular_contents']),'pdas':len(p['pdas']),'by_phase_field':{},'by_grade':{},'warnings':[w.__dict__ for w in warns],'errors':errors}
    for ph in PHASES:
        for fc,fn,_ in FIELDS:
            cs=[x for x in p['curricular_contents'] if x['phase_code']==ph and x['field_code']==fc]; ids={x['code'] for x in cs}
            obj['by_phase_field'][f'{ph}/{fc}']={'field':fn,'contents':len(cs),'pdas':sum(1 for x in p['pdas'] if x['content_code'] in ids)}
    for g in ('G1','G2','G3','G4','G5','G6'):obj['by_grade'][g]=sum(1 for x in p['pdas'] if x['grade_code']==g)
    path.parent.mkdir(parents=True,exist_ok=True); path.write_text(json.dumps(obj,ensure_ascii=False,indent=2)+'\n',encoding='utf-8'); return obj

def main():
    ap=argparse.ArgumentParser(); ap.add_argument('--output',default=OUT); ap.add_argument('--cache-dir',default=str(CACHE)); ap.add_argument('--no-download',action='store_true'); a=ap.parse_args()
    cache=Path(a.cache_dir); warns=[]; data={}; sources={}
    for ph,d in PHASES.items():
        pdf=cache/d['file']
        if a.no_download:
            if not pdf.is_file(): print('ERROR falta',pdf,file=sys.stderr); return 2
            sources[ph]=d['urls'][0]
        else:
            try:sources[ph]=download(d['urls'],pdf)
            except Exception as e:print('ERROR:',e,file=sys.stderr);return 2
        try:doc=fitz.open(pdf)
        except Exception as e:print('ERROR al abrir',pdf,e,file=sys.stderr);return 2
        print(f"\n{ph}: {d['name']} — {len(doc)} páginas")
        gn=(d['grades'][0][1],d['grades'][1][1])
        for fc,(fn,p1,p2) in d['fields'].items():
            rs=extract_field(doc,ph,fc,fn,p1,p2,gn,warns); data[(ph,fc)]=rs
            print(f"  {fc} {fn}: {len(rs)} contenidos, {sum(len(r.g1)+len(r.g2) for r in rs)} PDA")
        doc.close()
    p=payload(data,sources); errors=validate(p,warns); rp=cache/'extraction-report.json'; r=report(rp,p,warns,errors)
    print(f"\nResumen\n  Contenidos: {r['contents']}\n  PDA: {r['pdas']}\n  Warnings: {len(warns)}\n  Errors: {len(errors)}\n  Reporte: {rp}")
    if errors:
        print('\nNO se escribió el JSON final:',file=sys.stderr)
        for e in errors:print('  -',e,file=sys.stderr)
        return 1
    Path(a.output).write_text(json.dumps(p,ensure_ascii=False,indent=2)+'\n',encoding='utf-8'); print('\nJSON generado:',Path(a.output).resolve()); return 0
if __name__=='__main__':raise SystemExit(main())
