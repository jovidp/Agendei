# Resposta a incidentes de segurança

Plano para quando houver suspeita de acesso indevido, vazamento ou perda de
dados. Objetivo: conter rápido, entender o que aconteceu, avisar quem precisa
ser avisado e não repetir.

## Quem faz o quê

| Papel | Quem | Contato |
|---|---|---|
| Responsável pelo sistema | a definir | a definir |
| Encarregado de dados (DPO) | variável `AGENDEI_DPO_NOME` | variável `AGENDEI_DPO_EMAIL` |
| Hospedagem e banco | painéis do provedor | suporte do provedor |

## 1. Detectar

Sinais que pedem investigação:

- picos de entradas recusadas ou de bloqueios em **Master > Segurança** e no
  log de autenticação (`admin/logs.php`);
- ações que ninguém reconhece na **Master > Auditoria**;
- **Master > Saúde** apontando falha no banco ou na auditoria;
- aviso de cliente ou de estabelecimento sobre dado alterado ou acesso
  estranho.

## 2. Conter (primeira hora)

1. Registrar o horário em que o problema foi percebido: o prazo de aviso
   conta a partir daí.
2. Trocar as senhas e chaves envolvidas: senha do banco (`AGENDEI_DB_PASS`),
   `AGENDEI_TOKEN_TAREFAS`, chaves de e-mail, WhatsApp e Google. Trocar a
   senha do banco derruba todas as conexões abertas.
3. Encerrar as sessões: reiniciar o serviço na hospedagem (as sessões ficam
   no servidor e saem com ele) e apagar as linhas da tabela
   `sessoes_lembradas`, o que desconecta todos os dispositivos lembrados.
4. Se uma conta foi tomada, inativá-la pela tela de usuários e liberar o
   segundo fator só depois de confirmar a identidade (`scripts/liberar_2fa.php`).
5. **Não apagar logs**: eles são a prova do que aconteceu. Fazer um backup
   imediato (`php scripts/backup.php`) antes de mexer nos dados.

## 3. Avaliar

- Que dados foram expostos ou alterados, de quantas pessoas e de quais
  estabelecimentos.
- Se há risco ou dano relevante aos titulares (dados de identificação, CPF,
  dados de autenticação contam como relevantes).
- Por onde entrou e se ainda está aberto.

## 4. Comunicar

Quando o incidente puder gerar risco ou dano relevante (LGPD, art. 48, e
Resolução CD/ANPD nº 15/2024):

- **ANPD:** em até **3 dias úteis** a partir do conhecimento, pelo formulário
  de comunicação de incidente no site da ANPD.
- **Titulares afetados:** no mesmo prazo, em linguagem simples, por e-mail:
  o que aconteceu, que dados foram envolvidos, o que já foi feito e o que a
  pessoa deve fazer (por exemplo, trocar a senha).
- **Estabelecimentos:** para os dados dos clientes, o estabelecimento é o
  controlador. Avisá-lo junto, para ele também cumprir a comunicação.

Guardar cópia de tudo o que foi enviado e de quando foi enviado.

## 5. Corrigir e aprender

- Corrigir a causa no código ou na configuração e publicar.
- Escrever um resumo: linha do tempo, causa, dados afetados, avisos feitos,
  o que muda para não repetir. A ANPD pode pedir esse registro por 5 anos.
- Revisar este plano se algum passo não funcionou.
