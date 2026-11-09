/*
 * Assinatura: mostra os campos do cartao quando ele e a forma escolhida,
 * confere os dados no navegador e envia so os 4 ultimos digitos. Tambem
 * copia o Pix copia e cola e a linha do boleto.
 */
(function () {
  'use strict';

  var cartao = document.getElementById('dados-cartao');
  if (cartao) {
    var form = cartao.closest('form');
    var numero = document.getElementById('cartao-numero');
    var nome = document.getElementById('cartao-nome');
    var validade = document.getElementById('cartao-validade');
    var cvv = document.getElementById('cartao-cvv');
    var erro = document.getElementById('cartao-erro');

    var metodo = function () {
      var marcado = form.querySelector('input[name="metodo"]:checked');
      return marcado ? marcado.value : '';
    };
    form.addEventListener('change', function (evento) {
      if (evento.target.name === 'metodo') cartao.hidden = metodo() !== 'cartao';
    });

    numero.addEventListener('input', function () {
      numero.value = numero.value.replace(/\D/g, '').slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ');
    });
    validade.addEventListener('input', function () {
      var d = validade.value.replace(/\D/g, '').slice(0, 4);
      validade.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
    cvv.addEventListener('input', function () { cvv.value = cvv.value.replace(/\D/g, '').slice(0, 4); });

    // Digito verificador de cartao (Luhn).
    var luhn = function (digitos) {
      var soma = 0;
      for (var i = 0; i < digitos.length; i++) {
        var n = +digitos[digitos.length - 1 - i];
        if (i % 2) { n *= 2; if (n > 9) n -= 9; }
        soma += n;
      }
      return soma % 10 === 0;
    };

    var problema = function () {
      var d = numero.value.replace(/\D/g, '');
      if (d.length < 13 || !luhn(d)) return [numero, 'Número do cartão inválido.'];
      if (nome.value.trim().length < 3) return [nome, 'Informe o nome impresso no cartão.'];
      var v = /^(\d{2})\/(\d{2})$/.exec(validade.value);
      var hoje = new Date();
      if (!v || +v[1] < 1 || +v[1] > 12) return [validade, 'Validade inválida. Use MM/AA.'];
      if (2000 + +v[2] < hoje.getFullYear() || (2000 + +v[2] === hoje.getFullYear() && +v[1] < hoje.getMonth() + 1)) {
        return [validade, 'Este cartão está vencido.'];
      }
      if (!/^\d{3,4}$/.test(cvv.value)) return [cvv, 'CVV inválido.'];
      return null;
    };

    form.addEventListener('submit', function (evento) {
      if (metodo() !== 'cartao') return;
      var falha = problema();
      if (falha) {
        evento.preventDefault();
        erro.textContent = falha[1];
        falha[0].focus();
        return;
      }
      erro.textContent = '';
      document.getElementById('cartao-final').value = numero.value.replace(/\D/g, '').slice(-4);
    });
  }

  document.querySelectorAll('[data-copiar]').forEach(function (botao) {
    botao.addEventListener('click', function () {
      var campo = document.getElementById(botao.getAttribute('data-copiar'));
      var original = botao.textContent;
      var pronto = function () {
        botao.textContent = 'Copiado!';
        setTimeout(function () { botao.textContent = original; }, 2000);
      };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(campo.value).then(pronto);
      } else {
        campo.select();
        document.execCommand('copy');
        pronto();
      }
    });
  });
})();
