/* =====================================================================
   AGENDEI - Tela de cadastro do usuario comum
   Aplica as regras da especificacao no navegador. O endereco pelo CEP vem
   do comportamento comum de main.js (campo com data-busca-cep).
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
    });
  }

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  document.addEventListener("DOMContentLoaded", function () {
    limparAoDigitar();
    iniciarLimpeza();
    formulario.addEventListener("submit", validar);
  });
})();
