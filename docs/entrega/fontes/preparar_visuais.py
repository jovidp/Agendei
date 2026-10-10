"""Gera DERs a partir do esquema extraído e captura somente telas públicas."""
import json
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
MERMAID = ROOT / 'fontes/mermaid.min.js'
if not MERMAID.is_file():
    urllib.request.urlretrieve('https://cdn.jsdelivr.net/npm/mermaid@11.12.0/dist/mermaid.min.js', MERMAID)
MODEL = json.loads((ROOT / 'fontes/modelos.json').read_text(encoding='utf-8'))['pgsql']
GROUPS = [
    ('01_nucleo', 'Núcleo, empresas e perfis', ['estabelecimento','usuarios','vinculos','clientes','profissionais','administradores','filiais','configuracoes']),
    ('02_disponibilidade', 'Catálogo e disponibilidade', ['servicos','profissional_servico','horarios_profissionais','bloqueios_agenda','profissionais','usuarios','estabelecimento']),
    ('03_reservas', 'Reserva e seus participantes', ['agendamentos','clientes','profissionais','servicos','usuarios','estabelecimento','filiais']),
    ('04_atendimento', 'Espera, notificações e avaliações', ['lista_espera','notificacoes','avaliacoes','agendamentos','clientes','profissionais','servicos','usuarios','estabelecimento']),
    ('05_beneficios', 'Pacotes, pagamentos e fidelidade', ['pacotes','cliente_pacotes','pagamentos','fidelidade_movimentos','clientes','servicos','agendamentos','estabelecimento']),
    ('06_plataforma', 'Planos e assinatura da empresa', ['planos','estabelecimento_plano','assinaturas','estabelecimento']),
    ('07_seguranca', 'Acesso, sessões e auditoria', ['administradores_master','logs_master','tentativas_acesso','logs_autenticacao','sessoes_lembradas','usuarios','vinculos','estabelecimento']),
]

def diagram(names):
    lines = ['erDiagram', '    direction TB']
    for name in names:
        t = MODEL['tabelas'][name]
        lines.append(f'    {name} {{')
        # Todas as PKs e FKs físicas; atributos descritivos resumidos para impressão.
        basics = {'nome','tipo','status','chave','valor','email','login','data_agendamento','hora_inicio','hora_fim','data_bloqueio','dia_semana','preco','duracao_minutos','creditos_restantes','pontos','evento','acao','data_hora','limite_profissionais','data_inicio_demo','data_fim_demo'}
        for c in t['colunas'].values():
            if not any(k in c['chaves'] for k in ('PK','FK')) and c['nome'] not in basics:
                continue
            typ = c['tipo'].split('(')[0].replace(' ', '_').lower()
            keys = (' ' + ','.join(c['chaves'])) if c['chaves'] else ''
            lines.append(f'        {typ} {c["nome"]}{keys}')
        lines.append('    }')
    for fk in MODEL['relacoes_desenho']:
        if fk['pai'] not in names or fk['filha'] not in names: continue
        parent = '||' if fk['por_filha']=='1' else '|o'
        child = 'o|' if fk['por_pai']=='0..1' else 'o{'
        line = '--' if fk['identificadora'] else '..'
        cols = ' + '.join(fk['campos'])
        lines.append(f'    {fk["pai"]} {parent}{line}{child} {fk["filha"]} : "{cols}"')
    return '\n'.join(lines)

def render(page, text, name):
    page.set_content('<html><body style="margin:0;background:white"><div id="out"></div></body></html>')
    page.add_script_tag(path=str(MERMAID))
    page.evaluate("""mermaid.initialize({startOnLoad:false, theme:'base', securityLevel:'strict', maxTextSize:200000,
      themeVariables:{primaryColor:'#eef5fb',primaryTextColor:'#152d45',primaryBorderColor:'#326c99',lineColor:'#3f5368',fontSize:'15px',fontFamily:'Arial'},
      er:{useMaxWidth:false, entityPadding:10, diagramPadding:20}})""")
    svg = page.evaluate("""async text => {const r=await mermaid.render('diagram',text); document.getElementById('out').innerHTML=r.svg; return r.svg;}""", text)
    (ROOT / f'{name}.svg').write_text(svg,encoding='utf-8')
    size=page.locator('svg').bounding_box()
    if size['width']>12000 or size['height']>16000:
        page.locator('svg').evaluate('(e)=>{e.style.width="10000px"; e.style.height="auto";}')
    page.locator('svg').screenshot(path=str(ROOT/'imagens'/f'{name}.png'))
    print('DER',name,round(size['width']),round(size['height']),flush=True)

with sync_playwright() as p:
    b=p.chromium.launch(channel='msedge',headless=True)
    page=b.new_page(viewport={'width':1440,'height':1000},device_scale_factor=1.4,locale='pt-BR')
    for name,title,names in GROUPS:
        text=diagram(names)
        (ROOT / f'der_{name}.mmd').write_text(text,encoding='utf-8')
        render(page,text,f'der_{name}')
    render(page,(ROOT/'der_pgsql_completo.mmd').read_text(encoding='utf-8'),'der_postgres_completo')
    shots=[]
    for name,path in [('inicio','index.php'),('entrada','entrar.php'),('cadastro','cadastro.php'),('cadastro_empresa','cadastro_empresa.php')]:
        for mode,w,h in [('desktop',1366,900),('celular',390,844)]:
            page.set_viewport_size({'width':w,'height':h})
            r=page.goto('http://127.0.0.1:8765/'+path,wait_until='networkidle',timeout=30000)
            assert r.status==200
            body=page.locator('body').inner_text()
            assert 'Fatal error' not in body and 'Warning:' not in body
            page.screenshot(path=str(ROOT/'imagens'/f'{name}_{mode}_recorte.png'))
            widths=page.evaluate('({viewport:innerWidth,conteudo:document.documentElement.scrollWidth})')
            shots.append({'tela':name,'modo':mode,'status':r.status,**widths})
    page.set_viewport_size({'width':1366,'height':900})
    page.goto('http://127.0.0.1:8765/cadastro.php',wait_until='networkidle')
    page.locator('[name="nome"]').fill('Ana')
    page.locator('[name="cpf"]').fill('11111111111')
    # Dispatch executa a validação local. Não chama submit() nem envia a requisição.
    rejected=page.evaluate("""() => { const f=document.getElementById('formCadastro');return !f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); }""")
    assert rejected
    errors=page.locator('.invalido').count()
    assert errors>=2
    page.screenshot(path=str(ROOT/'imagens'/'cadastro_erros.png'))
    print('VALIDAÇÃO LOCAL',errors,'campos destacados; nenhum envio',flush=True)
    (ROOT/'fontes/capturas.json').write_text(json.dumps({'telas':shots,'validacao':{'envio_impedido':rejected,'campos_invalidos':errors}},ensure_ascii=False,indent=2),encoding='utf-8')
    b.close()
