/* =====================================================================
   AGENDEI - Barra de acessibilidade
   Troca de contraste e ajuste do tamanho da fonte, exigidos como desafio
   extra da especificacao. Carregado por includes/tema.php, entao a barra
   aparece em todas as telas do sistema.
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
  "use strict";

  var CHAVE_CONTRASTE = "agendei_contraste";
  var CHAVE_ESCALA = "agendei_escala";

  var ESCALAS = [1, 1.15, 1.3];
  var escalaAtual = 0;
  var contrasteAlto = false;

  /** Leitura tolerante: navegador com armazenamento bloqueado nao pode quebrar a pagina. */
  function lerPreferencia(chave) {
    try {
      return window.localStorage.getItem(chave);
    } catch (erro) {
      return null;
    }
  }

  /** Gravação tolerante: a preferência é uma conveniência, não um requisito. */
  function gravarPreferencia(chave, valor) {
    try {
      window.localStorage.setItem(chave, valor);
    } catch (erro) {
      // Sem armazenamento o ajuste vale apenas para a navegação atual.
    }
  }

  /** Aplica contraste e escala ao elemento raiz do documento. */
  function aplicar() {
    var raiz = document.documentElement;

    if (contrasteAlto) {
      raiz.setAttribute("data-contraste", "alto");
    } else {
      raiz.removeAttribute("data-contraste");
    }

    raiz.style.setProperty("--escala-fonte", String(ESCALAS[escalaAtual]));

    var botaoContraste = document.querySelector("[data-acessibilidade-contraste]");
    if (botaoContraste) {
      botaoContraste.setAttribute("aria-pressed", contrasteAlto ? "true" : "false");
    }

    var indicador = document.querySelector("[data-acessibilidade-nivel]");
    if (indicador) {
      indicador.textContent = Math.round(ESCALAS[escalaAtual] * 100) + "%";
    }
  }

  // Carrega as preferências antes da barra existir, para o ajuste valer já na
  // primeira pintura e não provocar um salto visível na tela.
  contrasteAlto = lerPreferencia(CHAVE_CONTRASTE) === "alto";
  var escalaSalva = parseInt(lerPreferencia(CHAVE_ESCALA) || "0", 10);
  escalaAtual = escalaSalva >= 0 && escalaSalva < ESCALAS.length ? escalaSalva : 0;
  aplicar();

  /** Alterna entre o tema padrão e o de alto contraste. */
  function alternarContraste() {
    contrasteAlto = !contrasteAlto;
    gravarPreferencia(CHAVE_CONTRASTE, contrasteAlto ? "alto" : "padrao");
    aplicar();
  }

  /** Avança ou recua um degrau na escala, sem sair dos limites. */
  function ajustarEscala(passo) {
    var novo = escalaAtual + passo;
    if (novo < 0 || novo >= ESCALAS.length) {
      return;
    }
    escalaAtual = novo;
    gravarPreferencia(CHAVE_ESCALA, String(escalaAtual));
    aplicar();
  }

  /** Volta contraste e escala aos valores originais. */
  function restaurar() {
    contrasteAlto = false;
    escalaAtual = 0;
    gravarPreferencia(CHAVE_CONTRASTE, "padrao");
    gravarPreferencia(CHAVE_ESCALA, "0");
    aplicar();
  }

  /** Monta a barra e a insere como primeiro elemento da página. */
  function montarBarra() {
    if (document.querySelector(".barra-acessibilidade")) {
      return;
    }

    var barra = document.createElement("div");
    barra.className = "barra-acessibilidade";
    barra.setAttribute("role", "toolbar");
    barra.setAttribute("aria-label", "Acessibilidade");

    barra.innerHTML =
      '<div class="grupo-acessibilidade">' +
      '<span class="rotulo-acessibilidade">Tamanho da fonte</span>' +
      '<button type="button" data-acessibilidade-menor aria-label="Diminuir o tamanho da fonte">A-</button>' +
      '<span data-acessibilidade-nivel aria-live="polite">100%</span>' +
      '<button type="button" data-acessibilidade-maior aria-label="Aumentar o tamanho da fonte">A+</button>' +
      "</div>" +
      '<div class="grupo-acessibilidade">' +
      '<button type="button" data-acessibilidade-contraste aria-pressed="false">Alto contraste</button>' +
      '<button type="button" data-acessibilidade-restaurar aria-label="Restaurar a apresentacao padrao">Restaurar</button>' +
      "</div>";

    document.body.insertBefore(barra, document.body.firstChild);

    barra
      .querySelector("[data-acessibilidade-menor]")
      .addEventListener("click", function () {
        ajustarEscala(-1);
      });
    barra
      .querySelector("[data-acessibilidade-maior]")
      .addEventListener("click", function () {
        ajustarEscala(1);
      });
    barra
      .querySelector("[data-acessibilidade-contraste]")
      .addEventListener("click", alternarContraste);
    barra
      .querySelector("[data-acessibilidade-restaurar]")
      .addEventListener("click", restaurar);

    aplicar();
  }

  // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", montarBarra);
  } else {
    montarBarra();
  }
})();
