"""Atualiza a documentação consolidada de 10/10 com o que entrou no sistema até 19/11/2026.

Os .docx originais do grupo (Downloads) não estão mais disponíveis, então gerar_documentacao.py
não pode ser repetido; este script parte do documento já gerado e só acrescenta ou corrige.
Uso: python atualizar_entrega.py <pasta com as imagens anteriores à nova renderização dos diagramas>
"""
import sys
from comum import *
from comum import ROOT, REPO, BASE

DATA = '19/11/2026'
ANTIGAS = Path(sys.argv[1])
ARQ = ROOT / (BASE + '.docx')
doc = Document(ARQ)
NT, NC, NF = contagem()


def paragrafos():
    yield from doc.paragraphs
    for t in doc.tables:
        for r in t.rows:
            for c in r.cells:
                yield from c.paragraphs


def trocar(antigo, novo, minimo=1):
    n = 0
    for p in paragrafos():
        for r in p.runs:
            if antigo in r.text:
                r.text = r.text.replace(antigo, novo); n += 1
    assert n >= minimo, f'texto não encontrado: {antigo[:60]}'


def achar_tabela(cabecalho):
    return next(t for t in doc.tables if [c.text for c in t.rows[0].cells] == cabecalho)


def achar_paragrafo(inicio):
    return next(p for p in doc.paragraphs if p.text.startswith(inicio))


def linhas(t, rows, size=9):
    for row in rows:
        cells = t.add_row().cells
        for c, v in zip(cells, row):
            c.text = v
            for r in c.paragraphs[0].runs: r.font.size = Pt(size)
            c.paragraphs[0].paragraph_format.space_after = Pt(3)


def mover_depois(ancora, elementos):
    """Os blocos são criados no fim do corpo e movidos para depois da âncora, na ordem."""
    for el in elementos:
        ancora.addnext(el); ancora = el
    return ancora


def novos(fn):
    corpo = doc.element.body
    antes = len(corpo)
    fn()
    criados = list(corpo)[antes - 1:-1]  # o último filho do corpo é sempre o sectPr
    return criados


# 1. Contagens e datas.
trocar('28 tabelas, 290 colunas e 63', f'{NT} tabelas, {NC} colunas e {NF}', minimo=2)
trocar('Revisão documental: 10 de outubro de 2026', 'Revisão documental: 10 de outubro de 2026 • Atualização: 19 de novembro de 2026')
trocar('Capítulos 3 e 4: descrição e capturas reais de telas públicas.', 'Capítulos 3 e 4: descrição e capturas reais de telas públicas e autenticadas.')
trocar('5.7 DER — Espera, notificações e avaliações', '5.7 DER — Espera, notificações, avaliações e bot')
trocar('5.8 DER — Pacotes, pagamentos e fidelidade', '5.8 DER — Pacotes, pagamentos, fidelidade e assinaturas de clientes')

# 2. Requisitos, regras e rastreabilidade.
linhas(achar_tabela(['Código', 'Requisito']), [
    ('RF47', 'Contratar o plano da empresa: escolher Básico, Profissional ou Premium, com preço e benefícios, e pagar por Pix com QR code, boleto ou cartão; o acesso é liberado depois da confirmação do pagamento.'),
    ('RF48', 'Exibir na página inicial os planos contratáveis, com preço mensal, limites e benefícios.'),
    ('RF49', 'Confirmar o e-mail por código: enviar código de seis dígitos após o cadastro, válido por 15 minutos, com cinco tentativas, uso único e reenvio após 60 segundos.'),
    ('RF50', 'Recuperar a senha por código ou link enviados ao e-mail, sem revelar quais endereços possuem conta.'),
    ('RF51', 'Gerenciar planos de assinatura para clientes: o estabelecimento define serviços incluídos por mês e desconto nos demais; o cliente assina em Meus benefícios.'),
    ('RF52', 'Pagar online: mensalidade da assinatura do cliente por Pix, boleto ou cartão e pagamento avulso de um atendimento.'),
    ('RF53', 'Atender pelo bot do WhatsApp: menu para marcar, listar e cancelar horários, consultar serviços e preços e falar com a equipe; textos e opções personalizáveis pelo administrador.'),
    ('RF54', 'Publicar Política de Privacidade, Termos de Uso, Cookies e Cancelamento e reembolso; registrar o aceite dos termos com versão e data; exibir aviso de cookies.'),
    ('RF55', 'Orientar o tipo de cadastro: comparar cadastro de cliente e de estabelecimento e levar cada pessoa ao formulário correto.'),
])
linhas(achar_tabela(['Código', 'Regra']), [
    ('RN17', 'Agendamento coberto pela assinatura do cliente é gravado com valor zero enquanto houver quantidade mensal do serviço; os demais recebem o desconto do plano.'),
    ('RN18', 'A situação da assinatura do cliente é derivada das datas do período; a renovação é processada pela tarefa diária (tarefas.php).'),
    ('RN19', 'Recursos fora do plano contratado pela empresa ficam bloqueados, com aviso do plano que os inclui.'),
    ('RN20', 'Cancelamento até 30 minutos antes converte o sinal pago em crédito; depois desse prazo, ou no segundo cancelamento seguido, o sinal fica retido.'),
    ('RN21', 'Cartão: número, validade e CVV são conferidos no navegador e apenas os quatro últimos dígitos chegam ao servidor.'),
    ('RN22', 'Códigos de confirmação e de recuperação expiram, têm tentativas limitadas e são guardados apenas como HMAC.'),
])
linhas(achar_tabela(['Requisitos', 'Arquivos de referência']), [
    ('RF47–RF48', 'assinatura.php; index.php; master/planos.php; models/Plano.php; models/Assinatura.php; models/Cobranca.php'),
    ('RF49–RF50', 'verificar_email.php; recuperar_senha.php; redefinir_senha.php; models/VerificacaoEmail.php; includes/emails.php'),
    ('RF51–RF52, RN17–RN18', 'admin/assinaturas_clientes.php; cliente/beneficios.php; cliente/pagar.php; models/AssinaturaCliente.php; models/PagamentoAvulso.php; tarefas.php'),
    ('RF53', 'admin/bot.php; api/whatsapp.php; models/Bot.php; models/BotMenu.php'),
    ('RF54–RF55, RN20', 'legal.php; criar_conta.php; models/Legal.php; models/Diferencial.php'),
])


def secao_26():
    doc.add_heading('2.6 Funcionalidades incluídas após a revisão de 10/10/2026', 2)
    para(doc, 'Entre 3 e 11 de novembro de 2026 o sistema recebeu as funcionalidades abaixo, que deram origem aos requisitos RF47–RF55 e às regras RN17–RN22. A coluna da direita registra os limites de cada recurso no ambiente acadêmico.')
    table(doc, ['Funcionalidade', 'Situação na entrega'], [
        ('Planos da empresa com preço e benefícios (Básico R$ 79, Profissional R$ 149, Premium R$ 249)', 'Implementado. O master edita preço e benefícios; empresa nova começa a demonstração no plano Profissional.'),
        ('Pagamento do plano por Pix, boleto ou cartão', 'Demonstração. O Pix gera BR Code e QR válidos, mas só é recebível com uma chave configurada em AGENDEI_PIX_CHAVE; o boleto usa o banco fictício 999; o cartão não é cobrado.'),
        ('Assinaturas de clientes e pagamento avulso', 'Implementado, com os mesmos meios de pagamento de demonstração.'),
        ('Confirmação de e-mail e recuperação de senha por código', 'Implementado. Depende de um servidor SMTP configurado para enviar as mensagens.'),
        ('Bot do WhatsApp personalizável', 'Implementado para o plano Premium. Depende de uma instância da Evolution API configurada; sem ela, os lembretes seguem no modo manual.'),
        ('Requisitos legais', 'Implementado: páginas legais, aceite versionado, aviso de cookies, arrependimento de sete dias, retenção de dados na tarefa diária e scripts/backup.php.'),
        ('Ajustes da reunião de 05/11/2026', 'Implementado: máscaras de telefone, botão para mostrar a senha, cópia de horários, calendário com menos consultas, sinal com crédito e acentuação do texto visível.'),
        ('Responsividade dos painéis no celular', 'Ajustada nas grades, topo, agenda, tabelas e botões de formulário.'),
    ])


mover_depois(achar_tabela(['Requisitos', 'Arquivos de referência'])._tbl, novos(secao_26))

# 3. Telas autenticadas.
t43 = achar_tabela(['Perfil', 'Telas implementadas de referência'])
t43.rows[1].cells[1].paragraphs[0].runs[0].text = 'Dashboard, agendamento por etapas, listagem de reservas, histórico, perfil, benefícios e assinatura, pagamento online e privacidade.'
t43.rows[3].cells[1].paragraphs[0].runs[0].text = 'Dashboard, agenda, serviços, profissionais, clientes, horários, filiais, relatórios, configurações, aparência, diferenciais, assinaturas de clientes, bot do WhatsApp e logs.'
t43.rows[4].cells[1].paragraphs[0].runs[0].text = 'Dashboard, estabelecimentos, contas, planos, uso, saúde, segurança e auditoria.'
trocar('As telas autenticadas estão descritas e identificadas no código, mas suas capturas não foram incluídas nesta revisão.',
       'As capturas das telas autenticadas estão na seção 4.4.')

PRINTS = [
    ('11-cliente-painel', 'Painel do cliente.'),
    ('12c-cliente-agendar-confirmacao', 'Agendamento: etapa de confirmação.'),
    ('16-cliente-beneficios', 'Meus benefícios do cliente.'),
    ('20-admin-painel', 'Painel do administrador do estabelecimento.'),
    ('21-admin-agenda', 'Agenda do estabelecimento.'),
    ('41-profissional-agenda', 'Agenda do profissional.'),
    ('50-master-painel', 'Painel do master global.'),
    ('56-master-planos', 'Planos da plataforma no painel master.'),
]


def secao_44():
    doc.add_heading('4.4 Capturas das telas autenticadas', 2)
    para(doc, 'As figuras seguintes foram capturadas em 03/11/2026, na aplicação executada localmente com os dados de demonstração do instalar.php (estabelecimento Agendei Studio), em 1366 pixels de largura. Telas incluídas depois dessa data, como assinaturas de clientes e bot do WhatsApp, não têm captura neste documento. O conjunto completo está em docs/prints/ e TELAS.md.')
    for i, (nome, titulo) in enumerate(PRINTS, 7):
        if i % 2 == 1: doc.add_page_break()
        image(doc, REPO / 'docs/prints' / f'{nome}.png', maxw=16, maxh=10.5)
        para(doc, f'Figura {i} — {titulo}', style='Caption')


mover_depois(achar_paragrafo('As capturas das telas autenticadas estão na seção 4.4.')._p, novos(secao_44))

# 4. Referência lógica nova.
linhas(achar_tabela(['Referência', 'Tratamento']), [
    ('agendamentos.id_cliente_assinatura → cliente_assinaturas', 'Não possui FOREIGN KEY nos scripts; a aplicação registra a assinatura que cobriu o agendamento ao criá-lo.'),
])

# 5. Verificação.
tv = achar_tabela(['Verificação', 'Resultado', 'Limite'])
for row, valores in zip(tv.rows[1:3], [
    ('php tests/modelo_bd.php', '710 verificações passaram (19/11/2026).', 'Cobertura do esquema e cardinalidades; não conecta ao banco.'),
    ('php tests/requisitos.php', '65 verificações; zero falhas (19/11/2026).', 'Regras de validação e autenticação verificadas sem banco.'),
]):
    for c, v in zip(row.cells, valores): c.paragraphs[0].runs[0].text = v
linhas(tv, [
    ('As 25 suítes de tests/', 'Todas aprovadas em 19/11/2026: 2.259 verificações contadas, além de duas suítes que informam apenas OK.', 'Executadas em MariaDB local descartável; o dialeto PostgreSQL não foi testado nesta rodada.'),
    ('Páginas públicas em produção', 'HTTP 200 em início, entrada, cadastro, legal e login master (19/11/2026).', 'Somente páginas públicas; pagamentos e telas autenticadas não foram exercitados em produção.'),
])
trocar('Não foram executados nesta revisão os testes de integração com banco nem a navegação autenticada.',
       'Em 19/11/2026 as suítes de integração foram executadas contra um banco MariaDB local descartável, sem acesso ao banco de produção. Não foram executados testes no dialeto PostgreSQL nem a navegação autenticada automatizada.')

# 6. Diagramas renderizados de novo: troca a imagem embutida e mantém a proporção nova.
trocadas = 0
for shape in doc.inline_shapes:
    rid = shape._inline.graphic.graphicData.pic.blipFill.blip.embed
    parte = doc.part.related_parts[rid]
    for antiga in ANTIGAS.glob('*.png'):
        nova = ROOT / 'imagens' / antiga.name
        if parte.blob == antiga.read_bytes() and nova.read_bytes() != parte.blob:
            parte._blob = nova.read_bytes()
            with Image.open(nova) as im: w, h = im.size
            largura = min(39, 23 * w / h)
            shape.width, shape.height = Cm(largura), Cm(largura * h / w)
            trocadas += 1
            break
print('imagens trocadas:', trocadas)
assert trocadas >= 8, 'esperava trocar ao menos os DERs e MERs alterados'

doc.core_properties.subject = 'Requisitos, funcionamento, telas, MER, DER e atividades do grupo — atualizado em ' + DATA
doc.save(ARQ)
gerar_dicionario(DATA)
exportar_md(doc)
print('Atualizado:', ARQ.name, f'| {NT} tabelas, {NC} colunas, {NF} FKs')
