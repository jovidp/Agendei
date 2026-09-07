/* =====================================================================
   AGENDEI - Fluxo de agendamento em etapas
   O JavaScript apenas conduz a interface: a disponibilidade vem da API
   e e validada novamente pelo PHP no momento de gravar.
   ===================================================================== */

// Isola as variáveis deste arquivo para evitar conflitos com os outros scripts.
(function () {
    'use strict';

    // Só executa o fluxo quando o contêiner de agendamento está presente na página.
    var fluxo = document.getElementById('fluxoAgendamento');
    if (!fluxo) {
        return;
    }

    var base = fluxo.getAttribute('data-base') || '/';
    var maximoDias = parseInt(fluxo.getAttribute('data-max-dias'), 10) || 60;

    // Guarda as escolhas atuais; alterações nas etapas anteriores exigem atualizar as opções seguintes.
    var estado = {
        servico: null,
        profissional: null,
        data: null,
        hora: null,
        horaFim: null
    };

    var etapaAtual = 1;
    var mesReferencia = primeiroDiaDoMes(new Date());
    var diasDisponiveis = [];
    var carregandoDias = false;

    var listaProfissionais = document.getElementById('listaProfissionais');
    var areaCalendario = document.getElementById('calendario');
    var listaHorarios = document.getElementById('listaHorarios');
    var formulario = document.getElementById('formAgendamento');

    var nomesMeses = ['Janeiro', 'Fevereiro', 'Marco', 'Abril', 'Maio', 'Junho',
        'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    var nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];

    // -----------------------------------------------------------------
    // Utilitarios
    // -----------------------------------------------------------------

    /** Cria a data do primeiro dia do mês usado como referência no calendário. */
    function primeiroDiaDoMes(data) {
        return new Date(data.getFullYear(), data.getMonth(), 1);
    }

    /** Converte a data local para YYYY-MM-DD, formato esperado pela API. */
    function paraTexto(data) {
        var mes = String(data.getMonth() + 1).padStart(2, '0');
        var dia = String(data.getDate()).padStart(2, '0');
        return data.getFullYear() + '-' + mes + '-' + dia;
    }

    /** Reorganiza uma data YYYY-MM-DD para exibição em DD/MM/YYYY. */
    function formatarDataBr(texto) {
        var partes = texto.split('-');
        return partes[2] + '/' + partes[1] + '/' + partes[0];
    }

    /** Formata o preço do resumo em reais com duas casas decimais. */
    function formatarMoeda(valor) {
        return 'R$ ' + Number(valor).toFixed(2).replace('.', ',');
    }

    /** Transforma a duração em minutos em um texto compacto de horas e minutos. */
    function duracaoTexto(minutos) {
        minutos = parseInt(minutos, 10);
        if (minutos < 60) {
            return minutos + ' min';
        }
        var horas = Math.floor(minutos / 60);
        var resto = minutos % 60;
        return resto === 0 ? horas + 'h' : horas + 'h' + String(resto).padStart(2, '0');
    }

    /** Escapa texto vindo da API antes de injetar no HTML. */
    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

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

    /** Localiza o elemento HTML da etapa informada. */
    function painel(numero) {
        return fluxo.querySelector('[data-painel="' + numero + '"]');
    }

    // -----------------------------------------------------------------
    // Navegacao entre etapas
    // -----------------------------------------------------------------

    /** Atualiza a etapa visível e os indicadores de progresso do agendamento. */
    function irParaEtapa(numero) {
        etapaAtual = numero;

        fluxo.querySelectorAll('[data-painel]').forEach(function (secao) {
            secao.classList.toggle('oculto', parseInt(secao.getAttribute('data-painel'), 10) !== numero);
        });

        fluxo.querySelectorAll('.etapa').forEach(function (etapa) {
            var indice = parseInt(etapa.getAttribute('data-etapa'), 10);
            etapa.classList.toggle('ativa', indice === numero);
            etapa.classList.toggle('concluida', indice < numero);
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /** Confere se a escolha obrigatória da etapa atual já foi preenchida. */
    function podeAvancar(de) {
        if (de === 1 && !estado.servico) {
            Agendei.notificar('Escolha um servico para continuar.', 'aviso');
            return false;
        }
        if (de === 2 && !estado.profissional) {
            Agendei.notificar('Escolha um profissional para continuar.', 'aviso');
            return false;
        }
        if (de === 3 && !estado.data) {
            Agendei.notificar('Escolha uma data para continuar.', 'aviso');
            return false;
        }
        if (de === 4 && !estado.hora) {
            Agendei.notificar('Escolha um horario para continuar.', 'aviso');
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Etapa 1 - servico
    // -----------------------------------------------------------------

    fluxo.querySelectorAll('input[name="servico_opcao"]').forEach(function (opcao) {
        opcao.addEventListener('change', function () {
            estado.servico = {
                id: opcao.value,
                nome: opcao.getAttribute('data-nome'),
                preco: opcao.getAttribute('data-preco'),
                duracao: opcao.getAttribute('data-duracao')
            };
            estado.profissional = null;
            estado.data = null;
            estado.hora = null;
            atualizarResumo();
        });
    });

    // -----------------------------------------------------------------
    // Etapa 2 - profissional
    // -----------------------------------------------------------------

    /** Busca na API os profissionais vinculados ao serviço selecionado. */
    function carregarProfissionais() {
        listaProfissionais.innerHTML = '<p class="carregando-horarios">Carregando profissionais...</p>';

        buscarJson('api/profissionais.php?id_servico=' + encodeURIComponent(estado.servico.id))
            .then(function (dados) {
                if (!dados.sucesso || dados.profissionais.length === 0) {
                    listaProfissionais.innerHTML =
                        '<div class="estado-vazio"><strong>Nenhum profissional disponivel</strong>' +
                        '<p>Ainda nao ha profissional habilitado para este servico. Escolha outro servico.</p></div>';
                    return;
                }

                var html = '<div class="lista-opcoes">';
                dados.profissionais.forEach(function (profissional) {
                    var id = 'profissional' + parseInt(profissional.id_profissional, 10);
                    html += '<div class="opcao">' +
                        '<input type="radio" name="profissional_opcao" id="' + id + '" value="' + parseInt(profissional.id_profissional, 10) + '"' +
                        ' data-nome="' + escapar(profissional.nome) + '">' +
                        '<label for="' + id + '">' +
                            '<span class="avatar">' + escapar(profissional.iniciais) + '</span>' +
                            '<span class="opcao-conteudo">' +
                                '<strong>' + escapar(profissional.nome) + '</strong>' +
                                '<p>' + escapar(profissional.especialidade || 'Profissional do estabelecimento') + '</p>' +
                            '</span>' +
                        '</label>' +
                    '</div>';
                });
                html += '</div>';

                listaProfissionais.innerHTML = html;

                listaProfissionais.querySelectorAll('input[name="profissional_opcao"]').forEach(function (opcao) {
                    opcao.addEventListener('change', function () {
                        estado.profissional = { id: opcao.value, nome: opcao.getAttribute('data-nome') };
                        estado.data = null;
                        estado.hora = null;
                        atualizarResumo();
                    });
                });
            })
            .catch(function () {
                listaProfissionais.innerHTML =
                    '<div class="estado-vazio"><strong>Nao foi possivel carregar os profissionais</strong>' +
                    '<p>Verifique sua conexao e tente novamente.</p></div>';
            });
    }

    // -----------------------------------------------------------------
    // Etapa 3 - calendario
    // -----------------------------------------------------------------

    /** Consulta os dias com vagas no mês para habilitar as datas do calendário. */
    function carregarDiasDisponiveis() {
        if (!estado.profissional || !estado.servico) {
            return Promise.resolve();
        }

        carregandoDias = true;
        var mes = mesReferencia.getFullYear() + '-' + String(mesReferencia.getMonth() + 1).padStart(2, '0');

        return buscarJson('api/dias.php?id_profissional=' + encodeURIComponent(estado.profissional.id) +
                '&id_servico=' + encodeURIComponent(estado.servico.id) + '&mes=' + mes)
            .then(function (dados) {
                diasDisponiveis = dados.sucesso ? dados.dias : [];
            })
            .catch(function () {
                diasDisponiveis = [];
            })
            .finally(function () {
                carregandoDias = false;
            });
    }

    /** Monta a grade do mês e associa os eventos de navegação e seleção de data. */
    function renderizarCalendario() {
        var hoje = new Date();
        hoje.setHours(0, 0, 0, 0);

        var limite = new Date();
        limite.setDate(limite.getDate() + maximoDias);

        var primeiroDia = new Date(mesReferencia.getFullYear(), mesReferencia.getMonth(), 1);
        var totalDias = new Date(mesReferencia.getFullYear(), mesReferencia.getMonth() + 1, 0).getDate();

        var podeVoltar = mesReferencia > primeiroDiaDoMes(hoje);
        var podeAvancarMes = primeiroDiaDoMes(limite) > mesReferencia;

        var html = '<div class="calendario-topo">' +
            '<span class="calendario-mes">' + nomesMeses[mesReferencia.getMonth()] + ' ' + mesReferencia.getFullYear() + '</span>' +
            '<span class="calendario-navegacao">' +
                '<button type="button" data-mes="-1"' + (podeVoltar ? '' : ' disabled') + ' aria-label="Mes anterior">&lsaquo;</button>' +
                '<button type="button" data-mes="1"' + (podeAvancarMes ? '' : ' disabled') + ' aria-label="Proximo mes">&rsaquo;</button>' +
            '</span></div><div class="calendario-grade">';

        nomesDias.forEach(function (dia) {
            html += '<span class="dia-semana">' + dia + '</span>';
        });

        for (var vazio = 0; vazio < primeiroDia.getDay(); vazio++) {
            html += '<span class="calendario-dia vazio"></span>';
        }

        for (var dia = 1; dia <= totalDias; dia++) {
            var data = new Date(mesReferencia.getFullYear(), mesReferencia.getMonth(), dia);
            var texto = paraTexto(data);
            var disponivel = diasDisponiveis.indexOf(texto) !== -1;
            var classes = 'calendario-dia';

            if (texto === paraTexto(hoje)) {
                classes += ' hoje';
            }
            if (texto === estado.data) {
                classes += ' selecionado';
            }

            html += '<button type="button" class="' + classes + '" data-data="' + texto + '"' +
                (disponivel ? '' : ' disabled title="Sem horarios disponiveis"') + '>' + dia + '</button>';
        }

        html += '</div>';

        if (!carregandoDias && diasDisponiveis.length === 0) {
            html += '<p class="carregando-horarios">Nenhum dia disponivel neste mes.</p>';
        }

        areaCalendario.innerHTML = html;

        areaCalendario.querySelectorAll('[data-mes]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                mesReferencia.setMonth(mesReferencia.getMonth() + parseInt(botao.getAttribute('data-mes'), 10));
                areaCalendario.innerHTML = '<p class="carregando-horarios">Carregando dias...</p>';
                carregarDiasDisponiveis().then(renderizarCalendario);
            });
        });

        areaCalendario.querySelectorAll('[data-data]').forEach(function (botao) {
            botao.addEventListener('click', function () {
                estado.data = botao.getAttribute('data-data');
                estado.hora = null;
                atualizarResumo();
                renderizarCalendario();
                carregarHorarios();
                irParaEtapa(4);
            });
        });
    }

    /** Exibe a mensagem de carregamento e monta o calendário após consultar as vagas do mês. */
    function abrirCalendario() {
        areaCalendario.innerHTML = '<p class="carregando-horarios">Carregando dias...</p>';
        carregarDiasDisponiveis().then(renderizarCalendario);
    }

    // -----------------------------------------------------------------
    // Etapa 4 - horarios
    // -----------------------------------------------------------------

    /** Atualiza as opções de horário para o serviço, profissional e data selecionados. */
    function carregarHorarios() {
        listaHorarios.innerHTML = '<p class="carregando-horarios">Buscando horarios disponiveis...</p>';

        buscarJson('api/horarios.php?id_profissional=' + encodeURIComponent(estado.profissional.id) +
                '&id_servico=' + encodeURIComponent(estado.servico.id) +
                '&data=' + encodeURIComponent(estado.data))
            .then(function (dados) {
                if (!dados.sucesso || dados.horarios.length === 0) {
                    listaHorarios.innerHTML =
                        '<div class="estado-vazio"><strong>Nenhum horario disponivel</strong>' +
                        '<p>Escolha outra data para este profissional.</p></div>';
                    return;
                }

                var periodos = { manha: [], tarde: [], noite: [] };

                dados.horarios.forEach(function (horario) {
                    var hora = parseInt(horario.inicio.split(':')[0], 10);
                    if (hora < 12) {
                        periodos.manha.push(horario);
                    } else if (hora < 18) {
                        periodos.tarde.push(horario);
                    } else {
                        periodos.noite.push(horario);
                    }
                });

                var titulos = { manha: 'Manha', tarde: 'Tarde', noite: 'Noite' };
                var html = '';

                Object.keys(periodos).forEach(function (chave) {
                    if (periodos[chave].length === 0) {
                        return;
                    }
                    html += '<div class="periodo-horarios"><h4>' + titulos[chave] + '</h4><div class="lista-horarios">';
                    periodos[chave].forEach(function (horario) {
                        html += '<button type="button" class="horario" data-hora="' + horario.inicio +
                            '" data-fim="' + horario.fim + '">' + horario.inicio + '</button>';
                    });
                    html += '</div></div>';
                });

                listaHorarios.innerHTML = html;

                listaHorarios.querySelectorAll('.horario').forEach(function (botao) {
                    botao.addEventListener('click', function () {
                        listaHorarios.querySelectorAll('.horario').forEach(function (outro) {
                            outro.classList.remove('selecionado');
                        });
                        botao.classList.add('selecionado');
                        estado.hora = botao.getAttribute('data-hora');
                        estado.horaFim = botao.getAttribute('data-fim');
                        atualizarResumo();
                    });
                });
            })
            .catch(function () {
                listaHorarios.innerHTML =
                    '<div class="estado-vazio"><strong>Nao foi possivel carregar os horarios</strong>' +
                    '<p>Verifique sua conexao e tente novamente.</p></div>';
            });
    }

    // -----------------------------------------------------------------
    // Resumo e confirmacao
    // -----------------------------------------------------------------

    /** Atualiza os elementos de resumo identificados pela chave recebida. */
    function definirResumo(chave, texto, preenchido) {
        fluxo.querySelectorAll('[data-resumo="' + chave + '"]').forEach(function (elemento) {
            elemento.textContent = texto;
            var linha = elemento.closest('.resumo-linha');
            if (linha) {
                linha.classList.toggle('vazia', !preenchido);
            }
        });
    }

    /** Sincroniza o resumo e os campos ocultos com as escolhas atuais do cliente. */
    function atualizarResumo() {
        definirResumo('servico', estado.servico ? estado.servico.nome : 'Nao selecionado', !!estado.servico);
        definirResumo('profissional', estado.profissional ? estado.profissional.nome : 'Nao selecionado', !!estado.profissional);
        definirResumo('data', estado.data ? formatarDataBr(estado.data) : 'Nao selecionada', !!estado.data);
        definirResumo('hora', estado.hora ? estado.hora + (estado.horaFim ? ' as ' + estado.horaFim : '') : 'Nao selecionado', !!estado.hora);
        definirResumo('duracao', estado.servico ? duracaoTexto(estado.servico.duracao) : '-', !!estado.servico);
        definirResumo('preco', estado.servico ? formatarMoeda(estado.servico.preco) : 'R$ 0,00', !!estado.servico);

        document.getElementById('campoServico').value = estado.servico ? estado.servico.id : '';
        document.getElementById('campoProfissional').value = estado.profissional ? estado.profissional.id : '';
        document.getElementById('campoData').value = estado.data || '';
        document.getElementById('campoHora').value = estado.hora || '';

        var botaoConfirmar = document.getElementById('botaoConfirmar');
        if (botaoConfirmar) {
            botaoConfirmar.disabled = !(estado.servico && estado.profissional && estado.data && estado.hora);
        }
    }

    // -----------------------------------------------------------------
    // Eventos de navegacao
    // -----------------------------------------------------------------

    fluxo.addEventListener('click', function (evento) {
        var avancar = evento.target.closest('[data-proxima]');
        if (avancar) {
            evento.preventDefault();
            if (!podeAvancar(etapaAtual)) {
                return;
            }

            var proxima = etapaAtual + 1;

            if (proxima === 2) {
                carregarProfissionais();
            }
            if (proxima === 3) {
                mesReferencia = primeiroDiaDoMes(new Date());
                abrirCalendario();
            }
            if (proxima === 4) {
                carregarHorarios();
            }

            irParaEtapa(proxima);
            return;
        }

        var voltar = evento.target.closest('[data-voltar]');
        if (voltar) {
            evento.preventDefault();
            irParaEtapa(Math.max(1, etapaAtual - 1));
        }
    });

    if (formulario) {
        formulario.addEventListener('submit', function (evento) {
            if (!(estado.servico && estado.profissional && estado.data && estado.hora)) {
                evento.preventDefault();
                Agendei.notificar('Complete todas as etapas antes de confirmar.', 'aviso');
            }
        });
    }

    // Servico ja marcado pelo PHP (retorno de erro) precisa reabastecer o estado.
    var servicoMarcado = fluxo.querySelector('input[name="servico_opcao"]:checked');
    if (servicoMarcado) {
        servicoMarcado.dispatchEvent(new Event('change'));
    }

    atualizarResumo();
    irParaEtapa(1);
})();
