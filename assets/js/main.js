/* =====================================================================
   AGENDEI - Comportamentos gerais da interface
   Namespace global: window.Agendei
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
  "use strict";

  var Agendei = {};

  // -----------------------------------------------------------------
  // Notificacoes
  // -----------------------------------------------------------------

  /** Reutiliza ou cria o recipiente das mensagens temporárias na página. */
  function areaNotificacoes() {
    var area = document.getElementById("notificacoes");
    if (!area) {
      area = document.createElement("div");
      area.id = "notificacoes";
      document.body.appendChild(area);
    }
    return area;
  }

  /** Agendei.notificar('Salvo com sucesso.', 'sucesso'); */
  Agendei.notificar = function (mensagem, tipo, duracao) {
    var area = areaNotificacoes();
    var caixa = document.createElement("div");

    caixa.className = "notificacao notificacao-" + (tipo || "info");
    caixa.setAttribute("role", "status");
    caixa.textContent = mensagem;

    area.appendChild(caixa);

    window.setTimeout(function () {
      caixa.remove();
    }, duracao || 4500);
  };

  // -----------------------------------------------------------------
  // Modais
  // -----------------------------------------------------------------

  /** Exibe a janela, bloqueia a rolagem de fundo e posiciona o foco no primeiro controle. */
  Agendei.abrirModal = function (id) {
    var modal = document.getElementById(id);
    if (!modal) {
      return;
    }
    modal.classList.add("aberto");
    document.body.style.overflow = "hidden";

    var primeiro = modal.querySelector("input, select, textarea, button");
    if (primeiro) {
      primeiro.focus();
    }
  };

  /** Oculta a janela e libera a rolagem quando não há outro modal aberto. */
  Agendei.fecharModal = function (modal) {
    if (typeof modal === "string") {
      modal = document.getElementById(modal);
    }
    if (!modal) {
      return;
    }
    modal.classList.remove("aberto");
    if (!document.querySelector(".modal.aberto")) {
      document.body.style.overflow = "";
    }
  };

  /** Liga os controles de abertura e fechamento, incluindo clique no fundo e tecla Escape. */
  function iniciarModais() {
    document.addEventListener("click", function (evento) {
      var abrir = evento.target.closest("[data-modal]");
      if (abrir) {
        evento.preventDefault();
        var modal = document.getElementById(abrir.getAttribute("data-modal"));
        if (modal) {
          preencherModal(modal, abrir);
          Agendei.abrirModal(modal.id);
        }
        return;
      }

      if (evento.target.closest("[data-fechar-modal]")) {
        evento.preventDefault();
        Agendei.fecharModal(evento.target.closest(".modal"));
        return;
      }

      if (evento.target.classList.contains("modal")) {
        Agendei.fecharModal(evento.target);
      }
    });

    document.addEventListener("keydown", function (evento) {
      if (evento.key === "Escape") {
        var aberto = document.querySelector(".modal.aberto");
        if (aberto) {
          Agendei.fecharModal(aberto);
        }
      }
    });
  }

  /**
   * Copia os atributos data-campo-* do gatilho para os elementos
   * [data-preenche="campo"] e [name="campo"] dentro do modal.
   */
  function preencherModal(modal, gatilho) {
    Array.prototype.forEach.call(gatilho.attributes, function (atributo) {
      if (atributo.name.indexOf("data-campo-") !== 0) {
        return;
      }

      var campo = atributo.name.replace("data-campo-", "");
      var valor = atributo.value;

      modal
        .querySelectorAll('[data-preenche="' + campo + '"]')
        .forEach(function (elemento) {
          elemento.textContent = valor;
        });

      modal
        .querySelectorAll('[name="' + campo + '"]')
        .forEach(function (elemento) {
          elemento.value = valor;
        });
    });
  }

  // -----------------------------------------------------------------
  // Confirmacao (substitui o confirm nativo)
  // -----------------------------------------------------------------

  /** Cria uma única janela reutilizável para confirmar ações da interface. */
  function criarModalConfirmacao() {
    var modal = document.getElementById("modalConfirmacao");
    if (modal) {
      return modal;
    }

    modal = document.createElement("div");
    modal.className = "modal";
    modal.id = "modalConfirmacao";
    modal.innerHTML =
      '<div class="modal-caixa" style="max-width:420px">' +
      '<div class="modal-cabecalho">' +
      "<h3 data-confirmacao-titulo>Confirmar acao</h3>" +
      '<button type="button" class="modal-fechar" data-fechar-modal aria-label="Fechar">&times;</button>' +
      "</div>" +
      '<div class="modal-corpo"><p data-confirmacao-texto style="margin:0"></p></div>' +
      '<div class="modal-rodape">' +
      '<button type="button" class="btn btn-contorno" data-fechar-modal>Cancelar</button>' +
      '<button type="button" class="btn btn-perigo" data-confirmacao-ok>Confirmar</button>' +
      "</div>" +
      "</div>";

    document.body.appendChild(modal);
    return modal;
  }

  /** Agendei.confirmar('Cancelar este agendamento?', callback, opcoes) */
  Agendei.confirmar = function (mensagem, aoConfirmar, opcoes) {
    opcoes = opcoes || {};

    var modal = criarModalConfirmacao();
    modal.querySelector("[data-confirmacao-titulo]").textContent =
      opcoes.titulo || "Confirmar acao";
    modal.querySelector("[data-confirmacao-texto]").textContent = mensagem;

    var botao = modal.querySelector("[data-confirmacao-ok]");
    botao.textContent = opcoes.rotulo || "Confirmar";
    botao.className = "btn " + (opcoes.classe || "btn-perigo");

    // Substitui o botão para descartar o evento da confirmação anterior e não repetir ações antigas.
    var novoBotao = botao.cloneNode(true);
    botao.parentNode.replaceChild(novoBotao, botao);

    novoBotao.addEventListener("click", function () {
      Agendei.fecharModal(modal);
      aoConfirmar();
    });

    Agendei.abrirModal("modalConfirmacao");
  };

  /** Intercepta elementos com data-confirmar e executa a ação após a confirmação. */
  function iniciarConfirmacoes() {
    document.addEventListener("click", function (evento) {
      var elemento = evento.target.closest("[data-confirmar]");
      if (!elemento) {
        return;
      }

      evento.preventDefault();

      Agendei.confirmar(
        elemento.getAttribute("data-confirmar"),
        function () {
          if (elemento.tagName === "A") {
            window.location.href = elemento.href;
            return;
          }

          var formulario = elemento.form || elemento.closest("form");
          if (formulario) {
            if (elemento.name) {
              var oculto = document.createElement("input");
              oculto.type = "hidden";
              oculto.name = elemento.name;
              oculto.value = elemento.value;
              formulario.appendChild(oculto);
            }
            formulario.submit();
          }
        },
        {
          titulo:
            elemento.getAttribute("data-confirmar-titulo") || "Confirmar acao",
          rotulo: elemento.getAttribute("data-confirmar-rotulo") || "Confirmar",
        },
      );
    });
  }

  // -----------------------------------------------------------------
  // Menus
  // -----------------------------------------------------------------

  /** Controla a abertura dos menus e o fundo do menu lateral em telas pequenas. */
  function iniciarMenus() {
    var botaoMenu = document.querySelector(".botao-menu");
    var menuSite = document.querySelector(".menu-site");

    if (botaoMenu && menuSite) {
      botaoMenu.setAttribute("aria-expanded", "false");
      botaoMenu.addEventListener("click", function () {
        botaoMenu.setAttribute("aria-expanded", String(menuSite.classList.toggle("aberto")));
      });
      // O cabeçalho é fixo: sem fechar, o menu aberto cobre a seção para onde o link levou.
      menuSite.addEventListener("click", function (evento) {
        if (!evento.target.closest("a")) return;
        menuSite.classList.remove("aberto");
        botaoMenu.setAttribute("aria-expanded", "false");
      });
    }

    var botaoSidebar = document.querySelector(".botao-sidebar");
    var sidebar = document.querySelector(".sidebar");
    var fundo = document.querySelector(".sidebar-fundo");

    if (botaoSidebar && sidebar) {
      botaoSidebar.addEventListener("click", function () {
        sidebar.classList.toggle("aberta");
        if (fundo) {
          fundo.classList.toggle("visivel");
        }
      });
    }

    if (fundo && sidebar) {
      fundo.addEventListener("click", function () {
        sidebar.classList.remove("aberta");
        fundo.classList.remove("visivel");
      });
    }

    // Recolher/expandir no computador; o cookie faz o painel ja abrir assim na proxima pagina.
    var recolher = document.querySelector(".sidebar-recolher");
    if (recolher) {
      var raiz = document.documentElement;
      var marcar = function () {
        var recolhida = raiz.classList.contains("sidebar-recolhida");
        recolher.setAttribute("aria-expanded", recolhida ? "false" : "true");
        recolher.title = recolhida ? "Expandir menu" : "Recolher menu";
        recolher.querySelector(".sr-only").textContent = recolher.title;
      };
      marcar();
      recolher.addEventListener("click", function () {
        var recolhida = raiz.classList.toggle("sidebar-recolhida");
        document.cookie = "agendei_sidebar=" + (recolhida ? "recolhida" : "aberta") + "; path=/; max-age=31536000; SameSite=Lax";
        marcar();
      });
    }

    // Menu da conta (details): fecha ao clicar fora ou com Esc.
    var conta = document.querySelector(".conta-topo");
    if (conta) {
      document.addEventListener("click", function (evento) {
        if (conta.open && !conta.contains(evento.target)) conta.open = false;
      });
      document.addEventListener("keydown", function (evento) {
        if (evento.key === "Escape" && conta.open) {
          conta.open = false;
          conta.querySelector("summary").focus();
        }
      });
    }
  }

  // -----------------------------------------------------------------
  // Alertas
  // -----------------------------------------------------------------

  /** Permite dispensar mensagens pelo botão de fechamento. */
  function iniciarAlertas() {
    document.addEventListener("click", function (evento) {
      if (evento.target.classList.contains("alerta-fechar")) {
        evento.target.closest(".alerta").remove();
      }
    });
  }

  // -----------------------------------------------------------------
  // Mascaras
  // -----------------------------------------------------------------

  /** Formata até 11 dígitos com a pontuação de CPF durante a digitação. */
  Agendei.mascaraCpf = function (valor) {
    valor = valor.replace(/\D/g, "").slice(0, 11);
    return valor
      .replace(/(\d{3})(\d)/, "$1.$2")
      .replace(/(\d{3})(\d)/, "$1.$2")
      .replace(/(\d{3})(\d{1,2})$/, "$1-$2");
  };

  /** Formata até 14 dígitos como 00.000.000/0000-00. */
  Agendei.mascaraCnpj = function (valor) {
    valor = valor.replace(/\D/g, "").slice(0, 14);
    return valor
      .replace(/^(\d{2})(\d)/, "$1.$2")
      .replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3")
      .replace(/\.(\d{3})(\d)/, ".$1/$2")
      .replace(/(\d{4})(\d{1,2})$/, "$1-$2");
  };

  /** Formata o DDD e ajusta a separação para telefone fixo ou celular. */
  Agendei.mascaraTelefone = function (valor) {
    valor = valor.replace(/\D/g, "").slice(0, 11);

    if (valor.length <= 10) {
      return valor
        .replace(/(\d{2})(\d)/, "($1) $2")
        .replace(/(\d{4})(\d{1,4})$/, "$1-$2");
    }

    return valor
      .replace(/(\d{2})(\d)/, "($1) $2")
      .replace(/(\d{5})(\d{1,4})$/, "$1-$2");
  };

  /** Telefone fixo: DDD e 8 dígitos, sem virar celular no 11º dígito. */
  Agendei.mascaraFixo = function (valor) {
    return Agendei.mascaraTelefone(valor.replace(/\D/g, "").slice(0, 10));
  };

  /** Limita o CEP a oito dígitos e insere o hífen de apresentação. */
  Agendei.mascaraCep = function (valor) {
    return valor
      .replace(/\D/g, "")
      .slice(0, 8)
      .replace(/(\d{5})(\d{1,3})$/, "$1-$2");
  };

  /** Interpreta os dígitos como centavos e monta o valor com vírgula decimal. */
  Agendei.mascaraMoeda = function (valor) {
    var numeros = valor.replace(/\D/g, "");
    if (numeros === "") {
      return "";
    }
    return (parseInt(numeros, 10) / 100).toFixed(2).replace(".", ",");
  };

  /** Aplica a máscara indicada em data-mascara ao valor inicial e durante a digitação. */
  function iniciarMascaras() {
    var mascaras = {
      cpf: Agendei.mascaraCpf,
      cnpj: Agendei.mascaraCnpj,
      telefone: Agendei.mascaraTelefone,
      fixo: Agendei.mascaraFixo,
      cep: Agendei.mascaraCep,
      moeda: Agendei.mascaraMoeda,
    };

    document.querySelectorAll("[data-mascara]").forEach(function (campo) {
      var funcao = mascaras[campo.getAttribute("data-mascara")];
      if (!funcao) {
        return;
      }

      campo.value = funcao(campo.value);

      campo.addEventListener("input", function () {
        campo.value = funcao(campo.value);
      });
    });
  }

  // -----------------------------------------------------------------
  // Aviso de cookies
  // -----------------------------------------------------------------

  /**
   * Avisa uma vez que o sistema so usa cookies essenciais. Sem cookie de
   * terceiros nao ha o que aceitar ou recusar: o botao so dispensa o aviso,
   * e a dispensa fica no navegador (localStorage), nao num cookie.
   */
  function iniciarAvisoCookies() {
    var meta = document.querySelector('meta[name="politica-cookies"]');
    var chave = "agendei_aviso_cookies";
    if (!meta) {
      return;
    }
    try {
      if (window.localStorage.getItem(chave)) {
        return;
      }
    } catch (erro) {
      return;
    }

    var aviso = document.createElement("div");
    aviso.className = "aviso-cookies";
    aviso.setAttribute("role", "region");
    aviso.setAttribute("aria-label", "Aviso de cookies");
    aviso.innerHTML =
      "<p>Usamos só cookies essenciais, para manter você conectado e proteger os formulários. " +
      '<a href="' + meta.content.replace(/"/g, "&quot;") + '">Saiba mais</a>.</p>' +
      '<button type="button" class="btn btn-pequeno">Entendi</button>';
    aviso.querySelector("button").addEventListener("click", function () {
      try {
        window.localStorage.setItem(chave, "1");
      } catch (erro) {
        // sem armazenamento o aviso volta na proxima pagina; nada se perde
      }
      aviso.remove();
    });
    document.body.appendChild(aviso);
  }

  // -----------------------------------------------------------------
  // Relogio de prazo
  // -----------------------------------------------------------------

  /** Conta para tras os segundos de data-contagem; ao zerar mostra data-contagem-fim. */
  function iniciarContagens() {
    var relogios = document.querySelectorAll("[data-contagem]");
    if (relogios.length === 0) {
      return;
    }
    var inicio = Date.now();

    function atualizar() {
      var decorrido = Math.floor((Date.now() - inicio) / 1000);
      relogios.forEach(function (relogio) {
        var resto = parseInt(relogio.getAttribute("data-contagem"), 10) - decorrido;
        if (resto <= 0) {
          relogio.textContent = relogio.getAttribute("data-contagem-fim") || "0s";
          return;
        }
        var dias = Math.floor(resto / 86400);
        var horas = Math.floor((resto % 86400) / 3600);
        var minutos = Math.floor((resto % 3600) / 60);
        var segundos = resto % 60;
        relogio.textContent =
          (dias > 0 ? dias + "d " : "") +
          String(horas).padStart(2, "0") + ":" +
          String(minutos).padStart(2, "0") + ":" +
          String(segundos).padStart(2, "0");
      });
    }

    atualizar();
    window.setInterval(atualizar, 1000);
  }

  // -----------------------------------------------------------------
  // Mostrar senha
  // -----------------------------------------------------------------

  /** Coloca um botão de olho em todo campo de senha para mostrar ou esconder o que foi digitado. */
  function iniciarMostrarSenha() {
    document.querySelectorAll('input[type="password"]').forEach(function (campo) {
      var envoltorio = document.createElement("span");
      envoltorio.className = "senha-com-olho";
      campo.parentNode.insertBefore(envoltorio, campo);
      envoltorio.appendChild(campo);

      var botao = document.createElement("button");
      botao.type = "button";
      botao.className = "botao-olho";
      botao.setAttribute("aria-label", "Mostrar senha");
      botao.setAttribute("aria-pressed", "false");
      botao.innerHTML =
        '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
        '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>' +
        '<line class="olho-risco" x1="3" y1="3" x2="21" y2="21"/></svg>';
      envoltorio.appendChild(botao);

      botao.addEventListener("click", function () {
        var mostrar = campo.type === "password";
        campo.type = mostrar ? "text" : "password";
        botao.setAttribute("aria-pressed", mostrar ? "true" : "false");
        botao.setAttribute("aria-label", mostrar ? "Esconder senha" : "Mostrar senha");
        campo.focus();
      });
    });
  }

  // -----------------------------------------------------------------
  // Endereco pelo CEP
  // -----------------------------------------------------------------

  /**
   * Todo campo com data-busca-cep consulta o ViaCEP ao completar os oito
   * digitos (ou ao sair do campo) e preenche, no mesmo formulario, os campos
   * logradouro (ou endereco), bairro, cidade e uf. A situacao da consulta vai
   * para [data-cep-situacao] do formulario, quando existir. Sem conexao ou
   * com CEP inexistente, tudo continua editavel para preenchimento manual.
   */
  function iniciarBuscaCep() {
    document.querySelectorAll("[data-busca-cep]").forEach(function (campoCep) {
      var formulario = campoCep.form;
      if (!formulario) {
        return;
      }

      var situacao = formulario.querySelector("[data-cep-situacao]");
      var textoInicial = situacao ? situacao.textContent : "";
      var ultimoConsultado = "";

      function informar(mensagem) {
        if (situacao) {
          situacao.textContent = mensagem;
        }
      }

      /** Escreve no primeiro campo existente entre os nomes, quando a API devolveu algo. */
      function preencher(nomes, conteudo) {
        if (!conteudo) {
          return;
        }
        for (var indice = 0; indice < nomes.length; indice++) {
          var elemento = formulario.elements[nomes[indice]];
          if (elemento) {
            elemento.value = conteudo;
            Agendei.limparErro(elemento);
            return;
          }
        }
      }

      function buscar() {
        var cep = String(campoCep.value).replace(/\D/g, "");
        if (cep.length !== 8) {
          informar(cep === "" ? textoInicial : "Informe os oito dígitos do CEP.");
          return;
        }
        // O input ja consultou este CEP; o blur logo em seguida nao repete a chamada.
        if (cep === ultimoConsultado) {
          return;
        }
        ultimoConsultado = cep;
        informar("Buscando endereço...");

        window
          .fetch("https://viacep.com.br/ws/" + cep + "/json/")
          .then(function (resposta) {
            if (!resposta.ok) {
              throw new Error("resposta inválida");
            }
            return resposta.json();
          })
          .then(function (dados) {
            if (dados.erro) {
              informar("CEP não encontrado. Preencha o endereço manualmente.");
              return;
            }

            preencher(["logradouro", "endereco"], dados.logradouro);
            preencher(["bairro"], dados.bairro);
            preencher(["cidade"], dados.localidade);
            preencher(["uf"], dados.uf);
            informar("Endereço preenchido. Confira o número e o complemento.");

            var numero = formulario.elements.numero;
            if (numero && numero.value === "") {
              numero.focus();
            }
          })
          .catch(function () {
            // Falha de rede nao trava o formulario: a pessoa digita o endereco.
            ultimoConsultado = "";
            informar("Não foi possível consultar o CEP. Preencha o endereço manualmente.");
          });
      }

      campoCep.addEventListener("blur", buscar);
      campoCep.addEventListener("input", function () {
        if (String(campoCep.value).replace(/\D/g, "").length === 8) {
          buscar();
        }
      });
      formulario.addEventListener("reset", function () {
        ultimoConsultado = "";
        informar(textoInicial);
      });
    });
  }

  // -----------------------------------------------------------------
  // Validacao de formularios
  // -----------------------------------------------------------------

  /** Destaca o campo inválido e preenche sua mensagem de validação. */
  Agendei.marcarErro = function (campo, mensagem) {
    campo.classList.add("invalido");

    var alvo = (campo.closest(".campo") || campo.parentNode).querySelector(".mensagem-campo");
    if (alvo) {
      alvo.textContent = mensagem;
      alvo.classList.add("visivel");
    }
  };

  /** Remove o destaque e a mensagem de erro associados ao campo. */
  Agendei.limparErro = function (campo) {
    campo.classList.remove("invalido");

    var alvo = (campo.closest(".campo") || campo.parentNode).querySelector(".mensagem-campo");
    if (alvo) {
      alvo.textContent = "";
      alvo.classList.remove("visivel");
    }
  };

  /** Rejeita sequências repetidas e confere os dois dígitos verificadores do CPF. */
  Agendei.validarCpf = function (cpf) {
    cpf = String(cpf).replace(/\D/g, "");

    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
      return false;
    }

    for (var posicao = 9; posicao < 11; posicao++) {
      var soma = 0;
      for (var indice = 0; indice < posicao; indice++) {
        soma += parseInt(cpf.charAt(indice), 10) * (posicao + 1 - indice);
      }
      var digito = ((10 * soma) % 11) % 10;
      if (parseInt(cpf.charAt(posicao), 10) !== digito) {
        return false;
      }
    }

    return true;
  };

  /** Rejeita sequências repetidas e confere os dois dígitos verificadores do CNPJ. */
  Agendei.validarCnpj = function (cnpj) {
    cnpj = String(cnpj).replace(/\D/g, "");
    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
      return false;
    }
    for (var posicao = 12; posicao < 14; posicao++) {
      var soma = 0;
      var peso = posicao - 7;
      for (var indice = 0; indice < posicao; indice++) {
        soma += parseInt(cnpj.charAt(indice), 10) * peso;
        peso = peso === 2 ? 9 : peso - 1;
      }
      var digito = soma % 11 < 2 ? 0 : 11 - (soma % 11);
      if (parseInt(cnpj.charAt(posicao), 10) !== digito) {
        return false;
      }
    }
    return true;
  };

  /** Verifica se o texto tem um formato de e-mail aceito pela validação. */
  Agendei.validarEmail = function (email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(email).trim());
  };

  /** Evita duplo envio do formulario. */
  // -----------------------------------------------------------------
  // Formulario em etapas (cadastro de cliente e de empresa)
  // -----------------------------------------------------------------

  /**
   * Mostra um fieldset.grupo-campos por vez, com barra de progresso montada a
   * partir das legendas. validarBloco(elemento) confere só os campos dentro do
   * elemento e devolve true/false. Sem JavaScript o formulário aparece inteiro.
   * Devolve irPara(indice, focar) para quem precisa voltar a uma etapa.
   */
  Agendei.formularioEmEtapas = function (formulario, validarBloco) {
    var etapas = formulario.querySelectorAll("fieldset.grupo-campos");
    var acoes = formulario.querySelector(".acoes-formulario");
    var total = etapas.length;
    var atual = 0;

    var progresso = document.createElement("div");
    progresso.className = "progresso-cadastro";
    var lista = document.createElement("ol");
    lista.className = "progresso-etapas";
    etapas.forEach(function (etapa) {
      var item = document.createElement("li");
      item.textContent = etapa.querySelector("legend").textContent;
      lista.appendChild(item);
    });
    var trilho = document.createElement("div");
    trilho.className = "progresso-trilho";
    trilho.setAttribute("role", "progressbar");
    trilho.setAttribute("aria-valuemin", "1");
    trilho.setAttribute("aria-valuemax", String(total));
    trilho.setAttribute("aria-label", "Progresso do cadastro");
    var preenchido = document.createElement("div");
    preenchido.className = "progresso-preenchido";
    trilho.appendChild(preenchido);
    var texto = document.createElement("p");
    texto.className = "progresso-texto";
    texto.setAttribute("aria-live", "polite");
    progresso.append(lista, trilho, texto);
    etapas[0].before(progresso);

    var navegacao = document.createElement("div");
    navegacao.className = "navegacao-cadastro";
    var voltar = document.createElement("button");
    voltar.type = "button";
    voltar.className = "btn btn-contorno btn-grande";
    voltar.textContent = "Voltar";
    var avancar = document.createElement("button");
    avancar.type = "button";
    avancar.className = "btn btn-grande";
    avancar.textContent = "Próximo";
    navegacao.append(voltar, avancar);
    etapas[total - 1].after(navegacao);

    function irPara(i, focar) {
      atual = i;
      etapas.forEach(function (etapa, n) {
        etapa.hidden = n !== i;
      });
      lista.querySelectorAll("li").forEach(function (item, n) {
        item.classList.toggle("ativa", n === i);
        item.classList.toggle("concluida", n < i);
      });
      preenchido.style.width = ((i + 1) / total) * 100 + "%";
      trilho.setAttribute("aria-valuenow", String(i + 1));
      texto.textContent = "Etapa " + (i + 1) + " de " + total + " · " + etapas[i].querySelector("legend").textContent;
      voltar.hidden = i === 0;
      avancar.hidden = i === total - 1;
      acoes.hidden = i !== total - 1;
      if (focar) {
        var primeiro = etapas[i].querySelector("input, select, textarea");
        if (primeiro) {
          primeiro.focus();
        }
      }
    }

    function proxima() {
      if (validarBloco(etapas[atual])) {
        irPara(atual + 1, true);
      }
    }

    voltar.addEventListener("click", function () {
      irPara(atual - 1, true);
    });
    avancar.addEventListener("click", proxima);

    // Enter numa etapa intermediária avança em vez de enviar; na última confere tudo.
    formulario.addEventListener("submit", function (evento) {
      if (atual < total - 1) {
        evento.preventDefault();
        proxima();
        return;
      }
      if (!validarBloco(formulario)) {
        evento.preventDefault();
        // Leva à primeira etapa com erro (ex.: campo apagado depois de avançar).
        for (var n = 0; n < total; n++) {
          var invalido = etapas[n].querySelector(".invalido");
          if (invalido) {
            irPara(n, false);
            invalido.focus();
            break;
          }
        }
      }
    });

    irPara(0, false);
    return irPara;
  };

  function iniciarProtecaoEnvio() {
    document.addEventListener("submit", function (evento) {
      var formulario = evento.target;
      if (
        evento.defaultPrevented ||
        formulario.hasAttribute("data-sem-bloqueio")
      ) {
        return;
      }

      var botao = formulario.querySelector('button[type="submit"]');
      if (!botao) {
        return;
      }

      window.setTimeout(function () {
        if (!formulario.querySelector(".invalido")) {
          botao.disabled = true;
          botao.dataset.textoOriginal = botao.textContent;
          botao.textContent = "Aguarde...";
        }
      }, 10);
    });
  }

  // -----------------------------------------------------------------
  // Busca em tabelas
  // -----------------------------------------------------------------

  /** Filtra as linhas já carregadas na tabela conforme o texto digitado. */
  function iniciarBuscaTabela() {
    document.querySelectorAll("[data-busca-tabela]").forEach(function (campo) {
      var tabela = document.querySelector(
        campo.getAttribute("data-busca-tabela"),
      );
      if (!tabela) {
        return;
      }

      campo.addEventListener("input", function () {
        var termo = campo.value.trim().toLowerCase();
        var visiveis = 0;

        tabela
          .querySelectorAll("tbody tr[data-linha]")
          .forEach(function (linha) {
            var combina = linha.textContent.toLowerCase().indexOf(termo) !== -1;
            linha.style.display = combina ? "" : "none";
            if (combina) {
              visiveis++;
            }
          });

        var vazio = document.querySelector(
          campo.getAttribute("data-busca-vazio") || "#buscaSemResultado",
        );
        if (vazio) {
          vazio.classList.toggle("oculto", visiveis > 0);
        }
      });
    });
  }

  /** Filtros que recarregam a pagina ao mudar. */
  function iniciarFiltrosAutomaticos() {
    document
      .querySelectorAll("[data-envia-ao-mudar]")
      .forEach(function (campo) {
        campo.addEventListener("change", function () {
          if (campo.form) {
            campo.form.submit();
          }
        });
      });
  }

  // -----------------------------------------------------------------

  // -----------------------------------------------------------------
  // Assinatura confirmada: o anel gira e a janela se abre com a logo
  // -----------------------------------------------------------------

  function iniciarAssinado() {
    var janela = document.querySelector(".assinado");
    if (!janela) return;
    var botao = janela.querySelector(".assinado-fechar");
    var semAnimacao = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var anterior = document.activeElement;

    setTimeout(function () {
      janela.classList.remove("girando");
      botao.focus();
      // Terminada a abertura, tira o recorte circular para a sombra aparecer inteira.
      setTimeout(function () { janela.classList.add("aberto"); }, semAnimacao ? 0 : 750);
    }, semAnimacao ? 0 : 1400);

    function fechar() {
      janela.classList.add("saindo");
      document.removeEventListener("keydown", teclado);
      setTimeout(function () { janela.remove(); }, semAnimacao ? 0 : 250);
      if (anterior && anterior.focus) anterior.focus();
    }

    // Esc fecha; Tab fica no unico botao da janela (aria-modal).
    function teclado(evento) {
      if (evento.key === "Escape") fechar();
      if (evento.key === "Tab") { evento.preventDefault(); botao.focus(); }
    }

    botao.addEventListener("click", fechar);
    janela.addEventListener("click", function (evento) {
      if (evento.target === janela && !janela.classList.contains("girando")) fechar();
    });
    document.addEventListener("keydown", teclado);
  }

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  document.addEventListener("DOMContentLoaded", function () {
    iniciarAssinado();
    iniciarMenus();
    iniciarAlertas();
    iniciarModais();
    iniciarConfirmacoes();
    iniciarMascaras();
    iniciarMostrarSenha();
    iniciarContagens();
    iniciarAvisoCookies();
    iniciarBuscaCep();
    iniciarBuscaTabela();
    iniciarFiltrosAutomaticos();
    iniciarProtecaoEnvio();
  });

  window.Agendei = Agendei;
})();
