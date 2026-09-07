/* Atualiza somente a prévia. A validação e a gravação definitivas ficam no PHP. */
(function () {
  "use strict";
  var form = document.getElementById("formAparencia");
  if (!form) return;
  // Exibe o endereço completo para que o administrador possa copiá-lo e compartilhá-lo.
  var link = document.querySelector(".link-estabelecimento");
  if (link) link.textContent = link.href;
  var previa = document.getElementById("previaTema");
  var imagem = document.getElementById("previaLogo");
  var inicial = document.getElementById("previaInicial");
  var logoSalva = imagem.getAttribute("src") || "";
  var logoNova = "";
  var versaoArquivo = 0;
  function rgb(cor) {
    return [1, 3, 5].map(function (i) {
      return parseInt(cor.slice(i, i + 2), 16);
    });
  }
  function mistura(cor, alvo, peso) {
    return (
      "#" +
      rgb(cor)
        .map(function (v) {
          return Math.round(v * (1 - peso) + alvo * peso)
            .toString(16)
            .padStart(2, "0");
        })
        .join("")
    );
  }
  function texto(cor) {
    var c = rgb(cor).map(function (v) {
      v /= 255;
      return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return c[0] * 0.2126 + c[1] * 0.7152 + c[2] * 0.0722 > 0.179
      ? "#000000"
      : "#FFFFFF";
  }
  function atualizar() {
    ["primaria", "secundaria"].forEach(function (nome) {
      var cor = form.elements["cor_" + nome].value;
      previa.style.setProperty("--cor-" + nome, cor);
      previa.style.setProperty(
        "--cor-" + nome + "-escura",
        mistura(cor, 0, 0.25),
      );
      previa.style.setProperty(
        "--cor-" + nome + "-clara",
        mistura(cor, 255, 0.9),
      );
      previa.style.setProperty("--sobre-" + nome, texto(cor));
    });
    previa.style.setProperty("--fundo", form.elements.cor_fundo.value);
    previa.style.setProperty(
      "--fonte-interface",
      form.elements.fonte.selectedOptions[0].dataset.familia,
    );
    var nome = form.elements.nome.value.trim() || "Seu estabelecimento";
    document.getElementById("previaNome").textContent = nome;
    inicial.textContent = Array.from(nome)[0];
    var logo = form.elements.remover_logo.checked ? "" : logoNova || logoSalva;
    if (logo) imagem.src = logo;
    else imagem.removeAttribute("src");
    imagem.hidden = !logo;
    inicial.hidden = !!logo;
  }
  form.addEventListener("input", atualizar);
  form.addEventListener("change", atualizar);
  form.elements.remover_logo.addEventListener("change", function () {
    if (this.checked) {
      versaoArquivo++;
      logoNova = "";
      form.elements.logo.value = "";
    }
    atualizar();
  });
  form.elements.logo.addEventListener("change", function () {
    var arquivo = this.files[0];
    var versao = ++versaoArquivo;
    logoNova = "";
    var aviso = document.getElementById("avisoLogo");
    if (!arquivo) {
      atualizar();
      return;
    }
    if (
      arquivo.size > 512 * 1024 ||
      !["image/png", "image/jpeg", "image/webp"].includes(arquivo.type)
    ) {
      aviso.textContent = "Selecione PNG, JPG ou WebP de até 512 KB.";
      this.value = "";
      atualizar();
      return;
    }
    var leitor = new FileReader();
    leitor.onload = function () {
      if (versao !== versaoArquivo) return;
      logoNova = leitor.result;
      form.elements.remover_logo.checked = false;
      aviso.textContent = "Nova logo selecionada. Salve para aplicar.";
      atualizar();
    };
    leitor.readAsDataURL(arquivo);
  });
  document
    .getElementById("restaurarTema")
    .addEventListener("click", function () {
      form.elements.cor_primaria.value = "#1F4E5F";
      form.elements.cor_secundaria.value = "#5FAF8B";
      form.elements.cor_fundo.value = "#F5F1EA";
      form.elements.fonte.value = "padrao";
      form.elements.logo.value = "";
      form.elements.remover_logo.checked = true;
      versaoArquivo++;
      logoNova = "";
      atualizar();
    });
  atualizar();
})();
