/* =====================================================================
   AGENDEI - Validacao das telas de login, cadastro e perfil
   As mesmas regras sao aplicadas novamente no PHP.
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
    'use strict';

    /** Lê o valor do campo e remove espaços nas extremidades. */
    function valor(formulario, nome) {
        var campo = formulario.elements[nome];
        return campo ? campo.value.trim() : '';
    }

    /** Localiza um campo pelo atributo name dentro do formulário. */
    function campo(formulario, nome) {
        return formulario.elements[nome] || null;
    }

    /** Apresenta ou limpa o erro do campo conforme o resultado da condição recebida. */
    function verificar(formulario, nome, condicao, mensagem) {
        var elemento = campo(formulario, nome);
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

    /** Remove a indicação de erro quando o usuário volta a editar o campo. */
    function limparAoDigitar(formulario) {
        formulario.querySelectorAll('input, select, textarea').forEach(function (elemento) {
            elemento.addEventListener('input', function () {
                if (elemento.classList.contains('invalido')) {
                    Agendei.limparErro(elemento);
                }
            });
        });
    }

    /** Leva o foco ao primeiro campo inválido para orientar a correção. */
    function focarPrimeiroErro(formulario) {
        var invalido = formulario.querySelector('.invalido');
        if (invalido) {
            invalido.focus();
        }
    }

    // -----------------------------------------------------------------
    // Login
    // -----------------------------------------------------------------

    /** Valida os campos de acesso no navegador antes de enviar o formulário ao PHP. */
    function iniciarLogin() {
        var formulario = document.getElementById('formLogin');
        if (!formulario) {
            return;
        }

        limparAoDigitar(formulario);

        formulario.addEventListener('submit', function (evento) {
            var valido = true;

            valido = verificar(formulario, 'email', valor(formulario, 'email') !== '', 'Informe seu e-mail.') && valido;
            if (valor(formulario, 'email') !== '') {
                valido = verificar(formulario, 'email', Agendei.validarEmail(valor(formulario, 'email')), 'Informe um e-mail valido.') && valido;
            }
            valido = verificar(formulario, 'senha', valor(formulario, 'senha') !== '', 'Informe sua senha.') && valido;

            if (!valido) {
                evento.preventDefault();
                focarPrimeiroErro(formulario);
            }
        });
    }

    // -----------------------------------------------------------------
    // Cadastro
    // -----------------------------------------------------------------

    /** Confere os dados de cadastro e a confirmação de senha antes do envio. */
    function iniciarCadastro() {
        var formulario = document.getElementById('formCadastro');
        if (!formulario) {
            return;
        }

        limparAoDigitar(formulario);

        formulario.addEventListener('submit', function (evento) {
            var valido = true;
            var nome = valor(formulario, 'nome');
            var cpf = valor(formulario, 'cpf').replace(/\D/g, '');
            var telefone = valor(formulario, 'telefone').replace(/\D/g, '');
            var nascimento = valor(formulario, 'data_nascimento');
            var email = valor(formulario, 'email');
            var senha = valor(formulario, 'senha');
            var confirmacao = valor(formulario, 'confirmar_senha');

            valido = verificar(formulario, 'nome', nome.length >= 5 && nome.indexOf(' ') > 0, 'Informe seu nome completo.') && valido;
            valido = verificar(formulario, 'cpf', Agendei.validarCpf(cpf), 'Informe um CPF valido.') && valido;
            valido = verificar(formulario, 'telefone', telefone.length === 10 || telefone.length === 11, 'Informe um telefone valido com DDD.') && valido;
            valido = verificar(formulario, 'email', Agendei.validarEmail(email), 'Informe um e-mail valido.') && valido;
            valido = verificar(formulario, 'senha', senha.length >= 6, 'A senha deve ter no minimo 6 caracteres.') && valido;
            valido = verificar(formulario, 'confirmar_senha', confirmacao === senha && confirmacao !== '', 'As senhas nao conferem.') && valido;

            if (nascimento !== '') {
                var data = new Date(nascimento + 'T00:00:00');
                var hoje = new Date();
                var idadeValida = !isNaN(data.getTime()) && data < hoje && (hoje.getFullYear() - data.getFullYear()) < 120;
                valido = verificar(formulario, 'data_nascimento', idadeValida, 'Informe uma data de nascimento valida.') && valido;
            }

            if (!valido) {
                evento.preventDefault();
                focarPrimeiroErro(formulario);
            }
        });
    }

    // -----------------------------------------------------------------
    // Alteracao de senha (perfil)
    // -----------------------------------------------------------------

    /** Confere a nova senha e sua confirmação nos formulários de alteração. */
    function iniciarTrocaSenha() {
        var formulario = document.getElementById('formSenha');
        if (!formulario) {
            return;
        }

        limparAoDigitar(formulario);

        formulario.addEventListener('submit', function (evento) {
            var valido = true;
            var nova = valor(formulario, 'nova_senha');

            valido = verificar(formulario, 'senha_atual', valor(formulario, 'senha_atual') !== '', 'Informe sua senha atual.') && valido;
            valido = verificar(formulario, 'nova_senha', nova.length >= 6, 'A nova senha deve ter no minimo 6 caracteres.') && valido;
            valido = verificar(formulario, 'confirmar_senha', valor(formulario, 'confirmar_senha') === nova && nova !== '', 'As senhas nao conferem.') && valido;

            if (!valido) {
                evento.preventDefault();
                focarPrimeiroErro(formulario);
            }
        });
    }

    // -----------------------------------------------------------------
    // Dados pessoais (perfil)
    // -----------------------------------------------------------------

    /** Valida os dados pessoais editáveis do perfil antes do envio. */
    function iniciarPerfil() {
        var formulario = document.getElementById('formPerfil');
        if (!formulario) {
            return;
        }

        limparAoDigitar(formulario);

        formulario.addEventListener('submit', function (evento) {
            var valido = true;
            var telefone = valor(formulario, 'telefone').replace(/\D/g, '');

            valido = verificar(formulario, 'nome', valor(formulario, 'nome').length >= 5, 'Informe seu nome completo.') && valido;
            valido = verificar(formulario, 'email', Agendei.validarEmail(valor(formulario, 'email')), 'Informe um e-mail valido.') && valido;

            if (campo(formulario, 'telefone')) {
                valido = verificar(formulario, 'telefone', telefone.length === 10 || telefone.length === 11, 'Informe um telefone valido com DDD.') && valido;
            }

            if (campo(formulario, 'cpf') && valor(formulario, 'cpf') !== '') {
                valido = verificar(formulario, 'cpf', Agendei.validarCpf(valor(formulario, 'cpf')), 'Informe um CPF valido.') && valido;
            }

            if (!valido) {
                evento.preventDefault();
                focarPrimeiroErro(formulario);
            }
        });
    }

    // Inicializa os comportamentos somente depois que os elementos da página estão disponíveis.
    document.addEventListener('DOMContentLoaded', function () {
        iniciarLogin();
        iniciarCadastro();
        iniciarTrocaSenha();
        iniciarPerfil();
    });
})();
