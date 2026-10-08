/* Carrosseis e animacoes da pagina principal, sem bibliotecas. */
(function () {
  "use strict";

  var preferencia = window.matchMedia("(prefers-reduced-motion: reduce)");

  document.querySelectorAll("[data-carrossel]").forEach(function (carrossel) {
    var trilho = carrossel.querySelector(".carrossel-trilho");
    var cartoes = Array.from(trilho.children);
    var anterior = carrossel.querySelector("[data-anterior]");
    var proximo = carrossel.querySelector("[data-proximo]");
    var posicao = carrossel.querySelector(".carrossel-posicao");
    var quadroPendente = false;

    function inicio(cartao) { return cartao.offsetLeft - cartoes[0].offsetLeft; }
    function limite() { return Math.max(0, trilho.scrollWidth - trilho.clientWidth); }
    function atualizar() {
      quadroPendente = false;
      var esquerda = trilho.scrollLeft;
      var visiveis = cartoes.filter(function (cartao) {
        return inicio(cartao) + cartao.offsetWidth > esquerda + 2 &&
          inicio(cartao) < esquerda + trilho.clientWidth - 2;
      });
      if (!visiveis.length) return;
      var primeiro = cartoes.indexOf(visiveis[0]) + 1;
      var ultimo = cartoes.indexOf(visiveis[visiveis.length - 1]) + 1;
      var texto = (primeiro === ultimo ? primeiro : primeiro + "–" + ultimo) + " de " + cartoes.length;
      if (posicao.textContent !== texto) posicao.textContent = texto;
      anterior.setAttribute("aria-disabled", String(esquerda <= 2));
      proximo.setAttribute("aria-disabled", String(esquerda >= limite() - 2));
    }
    function agendarAtualizacao() {
      if (quadroPendente) return;
      quadroPendente = true;
      window.requestAnimationFrame(atualizar);
    }
    function irPara(destino) {
      trilho.scrollTo({ left: Math.max(0, Math.min(destino, limite())),
        behavior: preferencia.matches ? "instant" : "smooth" });
    }
    function mover(direcao) {
      var candidatos = cartoes.map(inicio).concat([limite()]);
      var esquerda = trilho.scrollLeft;
      candidatos = candidatos.filter(function (valor) {
        return direcao > 0 ? valor > esquerda + 2 : valor < esquerda - 2;
      });
      if (candidatos.length) irPara(direcao > 0 ? Math.min.apply(null, candidatos) : Math.max.apply(null, candidatos));
    }
    anterior.addEventListener("click", function () { mover(-1); });
    proximo.addEventListener("click", function () { mover(1); });
    trilho.addEventListener("keydown", function (evento) {
      if (evento.target !== trilho) return;
      if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(evento.key)) return;
      evento.preventDefault();
      if (evento.key === "Home") irPara(0);
      else if (evento.key === "End") irPara(limite());
      else mover(evento.key === "ArrowRight" ? 1 : -1);
    });
    trilho.addEventListener("scroll", agendarAtualizacao, { passive: true });
    if (window.ResizeObserver) new ResizeObserver(agendarAtualizacao).observe(trilho);
    else window.addEventListener("resize", agendarAtualizacao);
    carrossel.querySelector(".carrossel-controles").hidden = false;
    atualizar();
  });

  if (preferencia.matches || !window.IntersectionObserver || !Element.prototype.animate) return;

  var animacoes = new Map();
  var observador = new IntersectionObserver(function (entradas) {
    entradas.forEach(function (entrada) {
      if (!entrada.isIntersecting) return;
      var elemento = entrada.target;
      observador.unobserve(elemento);
      if (preferencia.matches || elemento.matches(":focus-within")) return;

      var irmaos = Array.from(elemento.parentElement.children);
      var emGrade = elemento.matches(".argumento, .passo, .recurso");
      var atraso = emGrade ? (irmaos.indexOf(elemento) % 4) * 60 : 0;
      var animacao = elemento.animate([
        { opacity: 0, transform: "translateY(22px)" },
        { opacity: 1, transform: "translateY(0)" }
      ], {
        duration: 520,
        delay: atraso,
        easing: "cubic-bezier(0.22, 1, 0.36, 1)",
        fill: "backwards"
      });
      animacoes.set(elemento, animacao);
      animacao.onfinish = animacao.oncancel = function () {
        animacoes.delete(elemento);
      };
    });
  }, { threshold: 0.12, rootMargin: "0px 0px -24px 0px" });

  document.querySelectorAll(
    ".pagina-produto .secao-titulo, .pagina-produto .argumento, " +
    ".pagina-produto .passo, .pagina-produto .recurso, " +
    ".pagina-produto .chamada-final, .pagina-produto .rodape-colunas > div"
  ).forEach(function (elemento) { observador.observe(elemento); });

  // O foco por teclado nunca espera uma animacao para ficar visivel.
  document.addEventListener("focusin", function (evento) {
    animacoes.forEach(function (animacao, elemento) {
      if (elemento.contains(evento.target)) animacao.cancel();
    });
  });

  function reduzirMovimento(evento) {
    if (!evento.matches) return;
    observador.disconnect();
    animacoes.forEach(function (animacao) { animacao.cancel(); });
    animacoes.clear();
  }
  if (preferencia.addEventListener) preferencia.addEventListener("change", reduzirMovimento);
  else preferencia.addListener(reduzirMovimento);
})();
