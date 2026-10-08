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
      botaoMenu.addEventListener("click", function () {
        menuSite.classList.toggle("aberto");
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
      telefone: Agendei.mascaraTelefone,
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
              throw new Error("resposta invalida");
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

    var alvo = campo.parentNode.querySelector(".mensagem-campo");
    if (alvo) {
      alvo.textContent = mensagem;
      alvo.classList.add("visivel");
    }
  };

  /** Remove o destaque e a mensagem de erro associados ao campo. */
  Agendei.limparErro = function (campo) {
    campo.classList.remove("invalido");

    var alvo = campo.parentNode.querySelector(".mensagem-campo");
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

  /** Verifica se o texto tem um formato de e-mail aceito pela validação. */
  Agendei.validarEmail = function (email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(email).trim());
  };

  /** Evita duplo envio do formulario. */
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

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  document.addEventListener("DOMContentLoaded", function () {
    iniciarMenus();
    iniciarAlertas();
    iniciarModais();
    iniciarConfirmacoes();
    iniciarMascaras();
    iniciarBuscaCep();
    iniciarBuscaTabela();
    iniciarFiltrosAutomaticos();
    iniciarProtecaoEnvio();
  });

  window.Agendei = Agendei;
})();
