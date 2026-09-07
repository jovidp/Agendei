/* =====================================================================
   AGENDEI - Formulario de agendamento manual (admin e profissional)
   Carrega profissionais e horarios livres conforme a selecao.
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
    'use strict';

    var formulario = document.getElementById('formAgendamentoManual');
    if (!formulario) {
        return;
    }

    var base = formulario.getAttribute('data-base') || '/';
    var campoServico = formulario.querySelector('[name="id_servico"]');
    var campoProfissional = formulario.querySelector('[name="id_profissional"]');
    var campoData = formulario.querySelector('[name="data"]');
    var campoHora = formulario.querySelector('[name="hora_inicio"]');
    var avisoHorarios = document.getElementById('avisoHorarios');

    var profissionalFixo = formulario.getAttribute('data-profissional-fixo');

    /** Consulta a API com o cabeçalho de AJAX e converte a resposta em um objeto. */
    function buscarJson(caminho) {
        var empresa = document.querySelector('meta[name="estabelecimento"]').content;
        caminho += (caminho.indexOf('?') === -1 ? '?' : '&') + 'estabelecimento=' + encodeURIComponent(empresa);
        return fetch(base + caminho, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (resposta) {
                if (!resposta.ok) {
                    throw new Error('Falha na comunicacao com o servidor.');
                }
                return resposta.json();
            });
    }

    /** Remove as opções antigas e restaura a opção de orientação do campo. */
    function limparSelect(select, textoPadrao) {
        select.innerHTML = '';
        var opcao = document.createElement('option');
        opcao.value = '';
        opcao.textContent = textoPadrao;
        select.appendChild(opcao);
    }

    /** Busca na API os profissionais vinculados ao serviço selecionado. */
    function carregarProfissionais() {
        if (!campoProfissional || profissionalFixo) {
            return;
        }

        if (!campoServico.value) {
            limparSelect(campoProfissional, 'Selecione o servico primeiro');
            return;
        }

        limparSelect(campoProfissional, 'Carregando...');

        buscarJson('api/profissionais.php?id_servico=' + encodeURIComponent(campoServico.value))
            .then(function (dados) {
                limparSelect(campoProfissional, 'Selecione o profissional');

                (dados.profissionais || []).forEach(function (profissional) {
                    var opcao = document.createElement('option');
                    opcao.value = profissional.id_profissional;
                    opcao.textContent = profissional.nome;
                    campoProfissional.appendChild(opcao);
                });

                if ((dados.profissionais || []).length === 0) {
                    limparSelect(campoProfissional, 'Nenhum profissional executa este servico');
                }
            })
            .catch(function () {
                limparSelect(campoProfissional, 'Erro ao carregar profissionais');
            });
    }

    /** Atualiza as opções de horário para o serviço, profissional e data selecionados. */
    function carregarHorarios() {
        var idProfissional = profissionalFixo || (campoProfissional ? campoProfissional.value : '');

        if (!campoServico.value || !idProfissional || !campoData.value) {
            limparSelect(campoHora, 'Selecione servico, profissional e data');
            return;
        }

        limparSelect(campoHora, 'Carregando horarios...');

        buscarJson('api/horarios.php?id_profissional=' + encodeURIComponent(idProfissional) +
                '&id_servico=' + encodeURIComponent(campoServico.value) +
                '&data=' + encodeURIComponent(campoData.value))
            .then(function (dados) {
                var horarios = dados.horarios || [];

                if (horarios.length === 0) {
                    limparSelect(campoHora, 'Nenhum horario livre nesta data');
                    if (avisoHorarios) {
                        avisoHorarios.textContent = 'O profissional nao possui horarios livres nesta data. Verifique o expediente e os bloqueios.';
                        avisoHorarios.classList.remove('oculto');
                        avisoHorarios.classList.add('visivel');
                    }
                    return;
                }

                limparSelect(campoHora, 'Selecione o horario');

                horarios.forEach(function (horario) {
                    var opcao = document.createElement('option');
                    opcao.value = horario.inicio;
                    opcao.textContent = horario.inicio + ' - ' + horario.fim;
                    campoHora.appendChild(opcao);
                });

                if (avisoHorarios) {
                    avisoHorarios.classList.add('oculto');
                    avisoHorarios.classList.remove('visivel');
                }
            })
            .catch(function () {
                limparSelect(campoHora, 'Erro ao carregar horarios');
            });
    }

    // Ao mudar o serviço, recarrega os profissionais e descarta opções de horário dependentes.
    campoServico.addEventListener('change', function () {
        carregarProfissionais();
        limparSelect(campoHora, 'Selecione servico, profissional e data');
    });

    if (campoProfissional) {
        campoProfissional.addEventListener('change', carregarHorarios);
    }

    campoData.addEventListener('change', carregarHorarios);

    if (campoServico.value && campoData.value) {
        carregarHorarios();
    }
})();
