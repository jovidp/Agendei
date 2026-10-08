/* Animacoes da pagina principal, sem bibliotecas ou trabalho a cada scroll. */
(function () {
  "use strict";

  var preferencia = window.matchMedia("(prefers-reduced-motion: reduce)");
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
