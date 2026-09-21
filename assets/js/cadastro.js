/* =====================================================================
   AGENDEI - Tela de cadastro do usuario comum
   Aplica as regras da especificacao no navegador e busca o endereco pelo CEP.
   As mesmas regras sao conferidas novamente no PHP.
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
  "use strict";

  var formulario = document.getElementById("formCadastro");
  if (!formulario) {
    return;
  }

  /** Lê o valor do campo e remove espaços nas extremidades. */
  function valor(nome) {
    var elemento = formulario.elements[nome];
    return elemento ? String(elemento.value).trim() : "";
  }

  /** Localiza um campo pelo atributo name dentro do formulário. */
  function campo(nome) {
    return formulario.elements[nome] || null;
  }

  /** Apresenta ou limpa o erro do campo conforme o resultado da condição recebida. */
  function verificar(nome, condicao, mensagem) {
    var elemento = campo(nome);
    if (!elemento) {
      return true;
    }

    if (condicao) {
      Agendei.limparErro(elemento);
      return true;
    }

    Agendei.marcarErro(elemento, mensagem);
    return false;
  }

  /** Só letras e espaços, aceitando os acentos usados em português. */
  function apenasLetras(texto) {
    return /^[A-Za-zÀ-ÿ\s]+$/.test(texto);
  }

  /** Remove tudo que não for dígito. */
  function digitos(texto) {
    return String(texto).replace(/\D/g, "");
  }

  // -----------------------------------------------------------------
  // Preenchimento do endereco pelo CEP
  // -----------------------------------------------------------------

  /** Escreve a situação da consulta abaixo do campo de CEP. */
  function situacaoCep(mensagem) {
    var alvo = formulario.querySelector("[data-cep-situacao]");
    if (alvo) {
      alvo.textContent = mensagem;
    }
  }

  /**
   * Consulta o ViaCEP e preenche logradouro, bairro, cidade e UF.
   * Sem conexao ou com CEP inexistente, os campos continuam editaveis
   * para preenchimento manual, como pede a especificacao.
   */
  function buscarCep() {
    var elemento = formulario.querySelector("[data-busca-cep]");
    if (!elemento) {
      return;
    }

    var cep = digitos(elemento.value);
    if (cep.length !== 8) {
      situacaoCep("Informe os oito digitos do CEP.");
      return;
    }

    situacaoCep("Buscando endereco...");

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
          situacaoCep("CEP nao encontrado. Preencha o endereco manualmente.");
          return;
        }

        preencher("logradouro", dados.logradouro);
        preencher("bairro", dados.bairro);
        preencher("cidade", dados.localidade);
        preencher("uf", dados.uf);

        situacaoCep("Endereco preenchido. Confira o numero e o complemento.");

        var numero = campo("numero");
        if (numero && numero.value === "") {
          numero.focus();
        }
      })
      .catch(function () {
        // Falha de rede não pode travar o cadastro: o usuário digita o endereço.
        situacaoCep("Nao foi possivel consultar o CEP. Preencha o endereco manualmente.");
      });
  }

  /** Copia o valor devolvido pela API para o campo, quando ele veio preenchido. */
  function preencher(nome, conteudo) {
    var elemento = campo(nome);
    if (elemento && conteudo) {
      elemento.value = conteudo;
      Agendei.limparErro(elemento);
    }
  }

  /** Dispara a consulta ao sair do campo e assim que os oito dígitos são digitados. */
  function iniciarBuscaCep() {
    var elemento = formulario.querySelector("[data-busca-cep]");
    if (!elemento) {
      return;
    }

    elemento.addEventListener("blur", buscarCep);
    elemento.addEventListener("input", function () {
      if (digitos(elemento.value).length === 8) {
        buscarCep();
      }
    });
  }

  // -----------------------------------------------------------------
  // Validacao do formulario
  // -----------------------------------------------------------------

  /** Aplica todas as regras da especificação e impede o envio quando alguma falha. */
  function validar(evento) {
    var valido = true;

    var nome = valor("nome").replace(/\s+/g, " ");
    valido =
      verificar(
        "nome",
        nome.length >= 15 && nome.length <= 80 && apenasLetras(nome),
        "O nome deve ter de 15 a 80 caracteres, apenas letras.",
      ) && valido;

    var nascimento = valor("data_nascimento");
    var data = new Date(nascimento + "T00:00:00");
    valido =
      verificar(
        "data_nascimento",
        nascimento !== "" && !isNaN(data.getTime()) && data < new Date(),
        "Informe uma data de nascimento valida.",
      ) && valido;

    valido =
      verificar("sexo", valor("sexo") !== "", "Selecione o sexo.") && valido;

    var materno = valor("nome_materno").replace(/\s+/g, " ");
    valido =
      verificar(
        "nome_materno",
        materno.length >= 5 && apenasLetras(materno),
        "Informe o nome materno com no minimo 5 letras.",
      ) && valido;

    valido =
      verificar(
        "cpf",
        Agendei.validarCpf(valor("cpf")),
        "Informe um CPF valido.",
      ) && valido;

    valido =
      verificar(
        "email",
        Agendei.validarEmail(valor("email")),
        "Informe um e-mail valido.",
      ) && valido;

    valido =
      verificar(
        "telefone",
        digitos(valor("telefone")).length === 11,
        "Informe o celular com DDD e 9 digitos.",
      ) && valido;

    valido =
      verificar(
        "telefone_fixo",
        digitos(valor("telefone_fixo")).length === 10,
        "Informe o fixo com DDD e 8 digitos.",
      ) && valido;

    valido =
      verificar(
        "cep",
        digitos(valor("cep")).length === 8,
        "Informe um CEP com oito digitos.",
      ) && valido;

    valido =
      verificar("logradouro", valor("logradouro") !== "", "Informe o logradouro.") &&
      valido;
    valido = verificar("numero", valor("numero") !== "", "Informe o numero.") && valido;
    valido = verificar("bairro", valor("bairro") !== "", "Informe o bairro.") && valido;
    valido = verificar("cidade", valor("cidade") !== "", "Informe a cidade.") && valido;
    valido = verificar("uf", valor("uf") !== "", "Selecione a UF.") && valido;

    valido =
      verificar(
        "login",
        /^[A-Za-z]{6}$/.test(valor("login")),
        "O login deve ter exatamente 6 letras.",
      ) && valido;

    var senha = valor("senha");
    valido =
      verificar(
        "senha",
        /^[A-Za-z]{8}$/.test(senha),
        "A senha deve ter exatamente 8 letras.",
      ) && valido;

    valido =
      verificar(
        "confirmar_senha",
        valor("confirmar_senha") === senha && senha !== "",
        "As senhas nao conferem.",
      ) && valido;

    if (!valido) {
      evento.preventDefault();
      var invalido = formulario.querySelector(".invalido");
      if (invalido) {
        invalido.focus();
      }
      Agendei.notificar("Corrija os campos destacados.", "erro");
    }
  }

  /** Remove a indicação de erro quando o usuário volta a editar o campo. */
  function limparAoDigitar() {
    formulario.querySelectorAll("input, select").forEach(function (elemento) {
      elemento.addEventListener("input", function () {
        if (elemento.classList.contains("invalido")) {
          Agendei.limparErro(elemento);
        }
      });
    });
  }

  /** Devolve a tela ao estado inicial, inclusive as mensagens de erro. */
  function iniciarLimpeza() {
    formulario.addEventListener("reset", function () {
      formulario.querySelectorAll(".invalido").forEach(function (elemento) {
        Agendei.limparErro(elemento);
      });
      situacaoCep("Preenche o endereco automaticamente.");
    });
  }

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  document.addEventListener("DOMContentLoaded", function () {
    limparAoDigitar();
    iniciarBuscaCep();
    iniciarLimpeza();
    formulario.addEventListener("submit", validar);
  });
})();
