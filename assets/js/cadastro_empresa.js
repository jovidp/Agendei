/* =====================================================================
   AGENDEI - Cadastro de empresa pela pagina inicial
   Sugere o endereco a partir do nome e confere os campos antes do envio.
   As mesmas regras sao conferidas novamente no PHP.
   ===================================================================== */

(function () {
  "use strict";

  var formulario = document.getElementById("formCadastroEmpresa");
  if (!formulario) {
    return;
  }

  var nomeEmpresa = formulario.elements.estabelecimento;
  var endereco = formulario.elements.slug;
  // O endereco acompanha o nome ate a pessoa escrever nele por conta propria.
  var enderecoEditado = endereco.value !== "";

  /** Converte "Studio da Ana" em "studio-da-ana". */
  function paraEndereco(texto) {
    return String(texto)
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 80);
  }

  nomeEmpresa.addEventListener("input", function () {
    if (!enderecoEditado) {
      endereco.value = paraEndereco(nomeEmpresa.value);
    }
  });

  endereco.addEventListener("input", function () {
    enderecoEditado = endereco.value !== "";
    var posicao = endereco.selectionStart;
    endereco.value = String(endereco.value).toLowerCase().replace(/[^a-z0-9-]/g, "");
    if (posicao !== null) {
      endereco.setSelectionRange(posicao, posicao);
    }
  });

  /** Apresenta ou limpa o erro do campo conforme a condicao. */
  function verificar(nome, condicao, mensagem) {
    var elemento = formulario.elements[nome];
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

  function valor(nome) {
    var elemento = formulario.elements[nome];
    return elemento ? String(elemento.value).trim() : "";
  }

  formulario.addEventListener("submit", function (evento) {
    var telefone = valor("telefone").replace(/\D/g, "");
    var valido = true;

    valido = verificar("estabelecimento", valor("estabelecimento").length >= 2, "Informe o nome da empresa.") && valido;
    valido = verificar("slug", /^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/.test(valor("slug")), "Use de 3 a 80 letras minusculas, numeros ou hifens.") && valido;
    valido = verificar("nome", valor("nome").length >= 3, "Informe o seu nome.") && valido;
    valido = verificar("telefone", telefone.length === 10 || telefone.length === 11, "Informe o telefone com DDD.") && valido;
    valido = verificar("email", /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor("email")), "Informe um e-mail valido.") && valido;
    valido = verificar("senha", formulario.elements.senha.value.length >= 6, "A senha deve ter pelo menos 6 caracteres.") && valido;
    valido = verificar("confirmar_senha", formulario.elements.confirmar_senha.value === formulario.elements.senha.value, "As senhas nao conferem.") && valido;

    if (!valido) {
      evento.preventDefault();
      var primeiroErro = formulario.querySelector(".campo-invalido input, .campo-invalido textarea, .invalido");
      if (primeiroErro) {
        primeiroErro.focus();
      }
    }
  });
})();
