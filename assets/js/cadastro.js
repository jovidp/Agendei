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

  /** Regras da especificação: [campo, condição, mensagem]. */
  function regras() {
    var nome = valor("nome").replace(/\s+/g, " ");
    var nascimento = valor("data_nascimento");
    var data = new Date(nascimento + "T00:00:00");
    var materno = valor("nome_materno").replace(/\s+/g, " ");
    var fixo = digitos(valor("telefone_fixo"));
    var senha = valor("senha");
    return [
      ["nome", nome.length >= 15 && nome.length <= 80 && apenasLetras(nome), "O nome deve ter de 15 a 80 caracteres, apenas letras."],
      ["data_nascimento", nascimento !== "" && !isNaN(data.getTime()) && data < new Date(), "Informe uma data de nascimento válida."],
      // O max do campo já é a data limite de idade (LGPD art. 14), calculada no PHP.
      ["data_nascimento", nascimento === "" || nascimento <= campo("data_nascimento").max, "Menores de 12 anos não podem criar a própria conta. Peça a um responsável."],
      ["sexo", valor("sexo") !== "", "Selecione o sexo."],
      ["nome_materno", materno.length >= 5 && apenasLetras(materno), "Informe o nome materno com no mínimo 5 letras."],
      ["cpf", Agendei.validarCpf(valor("cpf")), "Informe um CPF válido."],
      ["email", Agendei.validarEmail(valor("email")), "Informe um e-mail válido."],
      ["telefone", digitos(valor("telefone")).length === 11, "Informe o celular com DDD e 9 dígitos."],
      ["telefone_fixo", fixo === "" || fixo.length === 10, "Informe o fixo com DDD e 8 dígitos."],
      ["cep", digitos(valor("cep")).length === 8, "Informe um CEP com oito dígitos."],
      ["logradouro", valor("logradouro") !== "", "Informe o logradouro."],
      ["numero", valor("numero") !== "", "Informe o número."],
      ["bairro", valor("bairro") !== "", "Informe o bairro."],
      ["cidade", valor("cidade") !== "", "Informe a cidade."],
      ["uf", valor("uf") !== "", "Selecione a UF."],
      ["login", /^[A-Za-z]{6}$/.test(valor("login")), "O usuário deve ter exatamente 6 letras."],
      ["senha", /^[A-Za-z]{8}$/.test(senha), "A senha deve ter exatamente 8 letras."],
      ["confirmar_senha", valor("confirmar_senha") === senha && senha !== "", "As senhas não conferem."],
      ["aceite", campo("aceite") ? campo("aceite").checked : true, "Aceite os Termos de Uso e a Política de Privacidade."],
    ];
  }

  /** Confere só os campos que estão dentro do bloco recebido (uma etapa ou o formulário inteiro). */
  function validarBloco(bloco) {
    var valido = true;
    var comErro = {};
    regras().forEach(function (regra) {
      var elemento = campo(regra[0]);
      // Um campo com várias regras para na primeira que falha (a seguinte limparia o erro).
      if (elemento && bloco.contains(elemento) && !comErro[regra[0]]) {
        comErro[regra[0]] = !verificar(regra[0], regra[1], regra[2]);
        valido = !comErro[regra[0]] && valido;
      }
    });
    if (!valido) {
      var invalido = bloco.querySelector(".invalido");
      if (invalido) {
        invalido.focus();
      }
      Agendei.notificar("Corrija os campos destacados.", "erro");
    }
    return valido;
  }

  // Uma etapa por fieldset, com barra de progresso (main.js).
  var irPara;

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
      // O reset troca os valores depois do evento: volta à primeira etapa em seguida.
      setTimeout(function () {
        irPara(0, true);
      }, 0);
    });
  }

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  document.addEventListener("DOMContentLoaded", function () {
    limparAoDigitar();
    irPara = Agendei.formularioEmEtapas(formulario, validarBloco);
    iniciarLimpeza();
  });
})();
