"""Reescreve as seções 6.1 e 6.2 (integrantes e reuniões) da documentação consolidada.

Atribuição informada pelo integrante João Vitor em 19/11/2026: o desenvolvimento ficou com
João Vitor e Thayrine; as mudanças de cada data vêm do histórico Git do repositório.
"""
from comum import *
from comum import ROOT, BASE

ARQ = ROOT / (BASE + '.docx')
doc = Document(ARQ)
JOAO, THAY = 'João Vitor Duarte Peçanha Rosa', 'Thayrine de Lira Maciel'
OUTROS = ['Gabriel Lima Maciel', 'Carlos Eduardo Correa Machado', 'Fabiano Gonçalves']

# (data, aplicações e mudanças no sistema, atuação dos integrantes)
REUNIOES = [
    ('15/08/2026',
     'Planejamento, sem alteração de código: definição dos objetivos do Agendei, dos perfis de uso (cliente, profissional e administrador) e da divisão do trabalho.',
     f'{JOAO} ficou com a implementação do sistema (back-end, telas e banco de dados) e {THAY} com as regras de negócio, as validações e os testes.'),
    ('22/08/2026',
     'Planejamento, sem alteração de código: desenho das rotas da API para profissionais, dias e horários livres e definição das regras de agendamento (expediente, bloqueios, antecedência e prazo de cancelamento).',
     f'{JOAO} planejou as rotas e a estrutura do servidor. {THAY} definiu as regras de negócio.'),
    ('27/08/2026',
     'Planejamento, sem alteração de código: elaboração do relatório do sistema e da análise de requisitos (RF, RN e RNF).',
     f'{JOAO} e {THAY} redigiram o relatório e os requisitos.'),
    ('29/08/2026',
     'Planejamento, sem alteração de código: modelagem do banco (usuários, clientes, profissionais, serviços, expedientes, bloqueios e agendamentos) e definição das validações do cadastro (nome, CPF, e-mail, login de seis letras e senha de oito letras).',
     f'{JOAO} modelou o banco e as operações de agendamento. {THAY} definiu as validações.'),
    ('03/09/2026',
     'Primeira versão do sistema publicada no repositório: página inicial, cadastro, login com segundo fator, recuperação de senha, áreas do cliente (painel, agendamento, agendamentos, histórico e perfil), do profissional (painel, agenda, expediente e bloqueios) e do administrador (painel, agenda, agendamentos, clientes, profissionais, serviços, horários, relatórios e configurações), além das APIs de profissionais, dias e horários.',
     f'{JOAO} implementou e apresentou a versão inicial. {THAY} revisou os requisitos com base nela.'),
    ('05/09/2026',
     'Mudanças aplicadas até a reunião seguinte (07/09): área master com perfil e saída da conta; operação com vários estabelecimentos (contexto por empresa e identidade visual); área de privacidade do cliente com cópia dos dados em JSON e encerramento da conta com anonimização; estrutura de lista de espera, notificações, pagamentos e pontos de fidelidade.',
     f'{JOAO} implementou as mudanças. {THAY} verificou as regras para evitar conflitos de horários.'),
    ('12/09/2026',
     'Não há alteração de código registrada entre 12/09 e 19/09. A reunião tratou da revisão das rotas e das regras de agendamento já implementadas.',
     f'{JOAO} testou as rotas de agendamento. {THAY} revisou as regras de negócio.'),
    ('19/09/2026',
     'Mudanças aplicadas entre 21/09 e 25/09: suporte a MySQL/MariaDB e PostgreSQL (Supabase) e publicação no Render; segundo fator por aplicativo autenticador (TOTP), limite de tentativas e reforço de segurança; área master ampliada (contas, planos, auditoria, saúde e uso); identidade visual (logotipo, favicon e componentes); filiais com escolha no agendamento; cadastro de empresa pela página inicial com aprovação do master; envio de e-mails; lembretes pelo WhatsApp; entrar com o Google e manter conectado; e-mail único na plataforma; pessoa única com vínculos em vários estabelecimentos; confirmação de presença pelo link.',
     f'{JOAO} implementou as mudanças. {THAY} verificou o tratamento de erros.'),
    ('26/09/2026',
     'Não há alteração de código registrada entre 26/09 e 03/10. A reunião tratou dos testes do servidor, do acesso aos dados e das operações de agendamento.',
     f'{JOAO} verificou as operações de agendamento. {THAY} testou as validações.'),
    ('03/10/2026',
     'Mudanças aplicadas entre 08/10 e 03/11: período de demonstração e assinatura da empresa; reaproveitamento de conexões PostgreSQL; animações e carrosséis na página inicial; tela que explica a diferença entre o cadastro de cliente e o de estabelecimento; caminho de volta para a página inicial nas telas de entrada; documentação de entrega, MER, DER e capturas das telas.',
     f'{JOAO} implementou as mudanças e consolidou a documentação. {THAY} realizou os testes complementares.'),
    ('05/11/2026',
     'Mudanças aplicadas entre 06/11 e 11/11: máscaras de telefone, botão para mostrar a senha e acentuação de todo o texto; cópia de horários e calendário mais rápido; sinal com crédito ao cancelar até 30 minutos antes; planos com preço e benefícios; pagamento por Pix com QR code, boleto e cartão (demonstração); código de confirmação do e-mail e recuperação de senha por código; bot do WhatsApp personalizável; páginas legais e aceite dos termos; assinaturas de clientes e pagamento avulso; ajustes dos painéis no celular.',
     f'{JOAO} implementou as mudanças decididas na reunião.'),
]


def heading(texto):
    return next(p for p in doc.paragraphs if p.style.name.startswith('Heading') and p.text.startswith(texto))


inicio, fim = heading('6.1 '), heading('6.3 ')
corpo = list(doc.element.body)
for el in corpo[corpo.index(inicio._p) + 1:corpo.index(fim._p)]:
    doc.element.body.remove(el)
inicio.runs[0].text = '6.1 Integrantes e participação'


def conteudo():
    table(doc, ['Integrante', 'Participação no desenvolvimento'], [
        (JOAO, 'Implementação do sistema: back-end, telas, banco de dados, segurança, integrações, publicação e documentação.'),
        (THAY, 'Regras de negócio, validações, prevenção de conflitos de horário, tratamento de erros e testes.'),
    ])
    for n in OUTROS:
        para(doc, f'{n} não teve ideias, participação ou colaboração para o desenvolvimento do projeto.')
    para(doc, 'As mudanças no sistema descritas a seguir foram conferidas no histórico Git do repositório, cujos commits são todos de João Vitor.')
    doc.add_heading('6.2 Reuniões e mudanças no sistema', 2)
    para(doc, 'Para cada reunião, o relato informa o que foi aplicado ou alterado no sistema e a atuação de cada integrante. As reuniões de agosto foram de planejamento; o código começou a ser publicado em 03/09/2026. Quando não houve alteração de código no intervalo, isso está indicado.')
    for data, mudancas, atuacao in REUNIOES:
        doc.add_heading(data, 3)
        para(doc, mudancas)
        para(doc, atuacao)


corpo = doc.element.body
antes = len(corpo)
conteudo()
novos = list(corpo)[antes - 1:-1]
ancora = inicio._p
for el in novos:
    ancora.addnext(el); ancora = el

doc.save(ARQ)
exportar_md(doc)
print('Capítulo 6 reescrito:', len(REUNIOES), 'reuniões')
