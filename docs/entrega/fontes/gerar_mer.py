"""Desenha o modelo conceitual com entidades, relacionamentos e atributos."""
from pathlib import Path
import urllib.request
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
MERMAID = ROOT / 'fontes/mermaid.min.js'
if not MERMAID.is_file():
    urllib.request.urlretrieve('https://cdn.jsdelivr.net/npm/mermaid@11.12.0/dist/mermaid.min.js', MERMAID)

MODELS = [
    ('mer_01_pessoas_empresas', 'Pessoas, empresas e perfis', '''flowchart TB
    U["USUÁRIO"]
    E["ESTABELECIMENTO"]
    V["VÍNCULO"]
    C["CLIENTE"]
    P["PROFISSIONAL"]
    A["ADMINISTRADOR"]
    F["FILIAL"]
    Q["CONFIGURAÇÃO"]
    U ---|1| R1{"Mantém"}
    R1 ---|0..N| V
    E ---|1| R2{"Registra"}
    R2 ---|0..N| V
    V ---|1| R3{"Possui perfil"}
    R3 ---|0..1| C
    V ---|1| R4{"Possui perfil"}
    R4 ---|0..1| P
    V ---|1| R5{"Possui perfil"}
    R5 ---|0..1| A
    E ---|1| R6{"Organiza"}
    R6 ---|0..N| F
    E ---|1| R7{"Define"}
    R7 ---|0..N| Q
    U --- AU(["Nome • e-mail • contatos"])
    V --- AV(["Tipo • situação • login local"])
    E --- AE(["Nome • endereço • identidade visual"])
    Q --- AQ(["Chave • valor"])
    class U,E,V,C,P,A,F,Q entity
    class R1,R2,R3,R4,R5,R6,R7 relationship
    class AU,AV,AE,AQ attribute
'''),
    ('mer_02_agendamento', 'Serviços, agenda e atendimento', '''flowchart TB
    C["CLIENTE"]
    P["PROFISSIONAL"]
    S["SERVIÇO"]
    A["AGENDAMENTO"]
    H["EXPEDIENTE"]
    B["BLOQUEIO DE AGENDA"]
    W["PREFERÊNCIA NA LISTA DE ESPERA"]
    N["NOTIFICAÇÃO"]
    V["AVALIAÇÃO"]
    C ---|1| R1{"Solicita"}
    R1 ---|0..N| A
    P ---|1| R2{"Atende"}
    R2 ---|0..N| A
    S ---|1| R3{"É reservado em"}
    R3 ---|0..N| A
    P ---|0..N| R4{"Está habilitado para"}
    R4 ---|0..N| S
    P ---|1| R5{"Trabalha conforme"}
    R5 ---|0..N| H
    P ---|1| R6{"Possui"}
    R6 ---|0..N| B
    C ---|1| R7{"Registra interesse"}
    R7 ---|0..N| W
    S ---|1| R8{"É desejado em"}
    R8 ---|0..N| W
    A ---|0..1| R9{"É referenciado por"}
    R9 ---|0..N| N
    A ---|1| R10{"Recebe"}
    R10 ---|0..1| V
    A --- AA(["Data • intervalo • valor • situação"])
    S --- AS(["Nome • duração • preço"])
    H --- AH(["Dia da semana • faixa de horário"])
    B --- AB(["Data • intervalo • motivo"])
    class C,P,S,A,H,B,W,N,V entity
    class R1,R2,R3,R4,R5,R6,R7,R8,R9,R10 relationship
    class AA,AS,AH,AB attribute
'''),
    ('mer_03_comercial', 'Pacotes, pagamentos e planos', '''flowchart TB
    C["CLIENTE"]
    S["SERVIÇO"]
    P["PACOTE"]
    Q["AQUISIÇÃO DE PACOTE"]
    A["AGENDAMENTO"]
    G["PAGAMENTO"]
    F["MOVIMENTO DE FIDELIDADE"]
    E["ESTABELECIMENTO"]
    L["PLANO"]
    D["ADESÃO AO PLANO"]
    T["ASSINATURA DA EMPRESA"]
    S ---|1| R1{"Compõe"}
    R1 ---|0..N| P
    C ---|1| R2{"Adquire"}
    R2 ---|0..N| Q
    P ---|1| R3{"É comprado em"}
    R3 ---|0..N| Q
    A ---|1| R4{"Recebe"}
    R4 ---|0..N| G
    C ---|1| R5{"Acumula"}
    R5 ---|0..N| F
    A ---|0..1| R6{"Origina"}
    R6 ---|0..1| F
    E ---|1| R7{"Possui adesão atual"}
    R7 ---|0..1| D
    L ---|1| R8{"É escolhido na"}
    R8 ---|0..N| D
    E ---|1| R9{"Possui estado comercial"}
    R9 ---|0..1| T
    Q --- AQ(["Créditos • validade • pagamento"])
    G --- AG(["Tipo • método • valor • situação"])
    F --- AF(["Pontos • descrição"])
    L --- AL(["Nome • limites de utilização"])
    class C,S,P,Q,A,G,F,E,L,D,T entity
    class R1,R2,R3,R4,R5,R6,R7,R8,R9 relationship
    class AQ,AG,AF,AL attribute
'''),
]

STYLES = '''
    classDef entity fill:#e9f3fa,stroke:#27617f,stroke-width:2px,color:#153b50;
    classDef relationship fill:#fff3d6,stroke:#ad8031,stroke-width:1.5px,color:#533b12;
    classDef attribute fill:#f5f5f5,stroke:#82949d,color:#334b59;
'''

with sync_playwright() as p:
    browser = p.chromium.launch(channel='msedge', headless=True)
    page = browser.new_page(viewport={'width':1440,'height':1000},device_scale_factor=1.5)
    for name,title,source in MODELS:
        source += STYLES
        (ROOT/(name+'.mmd')).write_text(source,encoding='utf-8')
        page.set_content('<html><body style="margin:0;background:white"><div id="out"></div></body></html>')
        page.add_script_tag(path=str(MERMAID))
        page.evaluate("mermaid.initialize({startOnLoad:false,theme:'base',securityLevel:'strict',themeVariables:{fontFamily:'Arial',fontSize:'18px'},flowchart:{useMaxWidth:false,htmlLabels:false,nodeSpacing:30,rankSpacing:45,curve:'linear'}})")
        svg=page.evaluate("async text => {const r=await mermaid.render('mer',text);document.getElementById('out').innerHTML=r.svg;return r.svg;}",source)
        (ROOT/(name+'.svg')).write_text(svg,encoding='utf-8')
        page.locator('svg').screenshot(path=str(ROOT/'imagens'/(name+'.png')))
        size=page.locator('svg').bounding_box()
        print('MER',title,round(size['width']),round(size['height']),flush=True)
    browser.close()
