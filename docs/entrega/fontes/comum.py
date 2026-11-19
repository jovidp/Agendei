"""Utilitários comuns aos geradores da entrega: estilo, tabelas, dicionário e Markdown."""
from pathlib import Path
from copy import deepcopy
import json
import re
import shutil
from docx import Document
from docx.table import Table
from docx.text.paragraph import Paragraph
from docx.shared import Cm, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.section import WD_SECTION_START, WD_ORIENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
REPO = ROOT.parents[1]
BASE = 'AGENDEI - DOCUMENTAÇÃO DO PROJETO'
MODELS = json.loads((ROOT/'fontes/modelos.json').read_text(encoding='utf-8'))
MODEL = MODELS['pgsql']

def setup(doc):
    sec=doc.sections[0]
    sec.page_width=Cm(21); sec.page_height=Cm(29.7)
    sec.top_margin=Cm(2.3); sec.bottom_margin=Cm(2)
    sec.left_margin=Cm(2.5); sec.right_margin=Cm(2)
    normal=doc.styles['Normal']; normal.font.name='Arial'; normal.font.size=Pt(10.5)
    normal.paragraph_format.line_spacing=1.15
    normal.paragraph_format.space_after=Pt(6)
    for name,size in [('Title',26),('Heading 1',16),('Heading 2',13),('Heading 3',11)]:
        st=doc.styles[name]; st.font.name='Arial'; st.font.size=Pt(size); st.font.color.rgb=RGBColor.from_string('183F55')
        st.paragraph_format.keep_with_next=True
        if name=='Heading 1':st.paragraph_format.page_break_before=True
    doc.styles['Caption'].font.size=Pt(9)
    doc.styles['Caption'].font.color.rgb=RGBColor.from_string('4F5F6B')
    header=sec.header.paragraphs[0]
    header.text='AGENDEI  |  DOCUMENTAÇÃO DO PROJETO'
    header.runs[0].font.size=Pt(8)
    footer=sec.footer.paragraphs[0]; footer.alignment=WD_ALIGN_PARAGRAPH.RIGHT
    footer.add_run('Agendei  •  ')
    field=OxmlElement('w:fldSimple');field.set(qn('w:instr'),'PAGE');footer._p.append(field)
    doc.core_properties.title='Agendei — Documentação consolidada do projeto'
    doc.core_properties.author='João Vitor Duarte Peçanha Rosa; Gabriel Lima Maciel; Thayrine de Lira Maciel; Carlos Eduardo Correa Machado; Fabiano Gonçalves'
    doc.core_properties.subject='Requisitos, funcionamento, telas, MER, DER e atividades do grupo'

def table(doc,headers,rows,size=9):
    t=doc.add_table(rows=1, cols=len(headers));t.style='Light Shading Accent 1'
    for c,h in zip(t.rows[0].cells,headers):c.text=h
    repeat=OxmlElement('w:tblHeader');t.rows[0]._tr.get_or_add_trPr().append(repeat)
    for row in rows:
        for c,v in zip(t.add_row().cells,row):c.text=str(v)
    for row in t.rows:
        cant=OxmlElement('w:cantSplit');row._tr.get_or_add_trPr().append(cant)
        for c in row.cells:
            for p in c.paragraphs:
                p.paragraph_format.space_after=Pt(3)
                for r in p.runs:r.font.size=Pt(size)
    return t

def para(doc,text,style=None):
    return doc.add_paragraph(text,style=style)

def image(doc,path,maxw=16.5,maxh=20):
    with Image.open(path) as im:w,h=im.size
    width=min(maxw,maxh*w/h)
    p=doc.add_paragraph();p.alignment=WD_ALIGN_PARAGRAPH.CENTER
    p.add_run().add_picture(str(path),width=Cm(width))
    return p

def landscape(doc):
    sec=doc.add_section(WD_SECTION_START.NEW_PAGE)
    sec.orientation=WD_ORIENT.LANDSCAPE
    sec.page_width=Cm(42);sec.page_height=Cm(29.7)
    sec.top_margin=sec.bottom_margin=Cm(1.5)
    sec.left_margin=sec.right_margin=Cm(1.5)
    return sec

def portrait(doc):
    sec=doc.add_section(WD_SECTION_START.NEW_PAGE)
    sec.orientation=WD_ORIENT.PORTRAIT
    sec.page_width=Cm(21);sec.page_height=Cm(29.7)
    sec.top_margin=Cm(2.3);sec.bottom_margin=Cm(2)
    sec.left_margin=Cm(2.5);sec.right_margin=Cm(2)
    return sec

def contagem(model=None):
    """Tabelas, colunas e FKs físicas do esquema versionado."""
    t=(model or MODEL)['tabelas']
    return len(t),sum(len(x['colunas']) for x in t.values()),sum(len(x['fks']) for x in t.values())

def gerar_dicionario(data_revisao):
    # Anexo completo de atributos, unicidades e todas as FKs, independente do resumo visual.
    dd=Document();setup(dd)
    dd.add_paragraph('AGENDEI — Dicionário PostgreSQL',style='Title')
    para(dd,'Anexo técnico da documentação consolidada — '+data_revisao)
    para(dd,'Fonte: banco_postgres.sql versionado. Contém %d tabelas, %d colunas e %d FKs físicas.'%contagem()+' A marca UK indica participação em uma chave possivelmente composta; consulte os conjuntos listados. Tipos e definições exatos são preservados abaixo.')
    for name,t in MODEL['tabelas'].items():
        dd.add_heading(name,1)
        table(dd,['Coluna','Tipo SQL','Nulo','Chaves'],[(c['nome'],c['tipo'],'Sim' if c['nulo'] else 'Não',', '.join(c['chaves']) or '—') for c in t['colunas'].values()],size=8)
        para(dd,'PK: '+', '.join(t['pk']))
        for k in t['uk']:para(dd,'UK composta/conjunto: ('+', '.join(k)+')')
        if t['fks']:
            table(dd,['FK / colunas locais','Destino','Ações'],[(fk['nome']+'\n('+', '.join(fk['campos'])+')',fk['pai']+' ('+', '.join(fk['referencias'])+')',fk['acoes'] or 'Padrão do banco') for fk in t['fks']],size=8)
        else:para(dd,'Nenhuma FK física declarada nesta tabela.')
        para(dd,'Definições exatas de coluna (incluem valores padrão):')
        for c in t['colunas'].values():
            p=para(dd,c['definicao'])
            p.paragraph_format.space_after=Pt(2)
            for r in p.runs:r.font.name='Consolas';r.font.size=Pt(7.5)
        checks=[r for r in t['restricoes'] if 'CHECK' in r]
        for c in checks:para(dd,c)
    dd.add_heading('Cardinalidades de todas as FKs',1)
    table(dd,['Restrição','Pai → filha','Pais por filha','Filhas por pai'],[(f['nome'],f['pai']+' → '+f['filha'],f['por_filha'],f['por_pai']) for f in MODEL['relacoes']],size=7.5)
    dd.save(ROOT/'ANEXO - Dicionário de dados PostgreSQL.docx')


def exportar_md(doc):
    # Markdown pesquisável e revisável do texto consolidado.
    md=[]
    for el in doc.element.body:
        if el.tag==qn('w:p'):
            p=Paragraph(el,doc)
            if not p.text.strip():continue
            level=int(p.style.name[-1]) if p.style.name.startswith('Heading') else 0
            md.append(('#'*level+' ' if level else '')+p.text)
        elif el.tag==qn('w:tbl'):
            t=Table(el,doc);rows=[[c.text.replace('\n',' / ').replace('|','/') for c in r.cells] for r in t.rows]
            if rows:
                md.append('| '+' | '.join(rows[0])+' |')
                md.append('| '+' | '.join(['---']*len(rows[0]))+' |')
                md.extend('| '+' | '.join(r)+' |' for r in rows[1:])
        md.append('')
    (ROOT/'DOCUMENTACAO_REVISADA.md').write_text('\n'.join(md).rstrip()+'\n',encoding='utf-8')

