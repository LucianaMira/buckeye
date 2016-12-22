    var map;
    var idInfoBoxAberto;
    var idEstacaoSelecionada;
    var infoBox = new Array();
    var markers = new Array();
    var estacoes = new Array();
    var interpolacao = null;
    var variaveisCQ = new Array();
    var mudou = false;
    var lastDate = "";
    variaveisCQ['Tmin'] = 'tn';
    variaveisCQ['Tmax'] = 'tx';
    variaveisCQ['Prec'] = 'r';
    var timer = null;
    var currentPosition = 0;
    var prefixSat = "";
    var thereIsCurrentImage = false;
    var limit = -1;

    $(function() {
        jQuery('input[type=date]').datepicker({
            dateFormat: 'yy-mm-dd',
            maxDate: new Date(),
            dayNames: ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'],
            dayNamesMin: ['D','S','T','Q','Q','S','S','D'],
            dayNamesShort: ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb','Dom'],
            monthNames: ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'],
            monthNamesShort: ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'],
            nextText: 'Próximo',
            prevText: 'Anterior',
            onSelect: function(date) {
                location.hash = "#!/" + date + "/" + $('#variavel').val().toLowerCase();
            },
            beforeShowDay: function(dat) {
                var m = dat.getMonth()+1, d = dat.getDate(), y = dat.getFullYear();
                
                if(m < 10)
                    m = "0"+m;

                d = (d<10)?"0"+d:d;

                if($.inArray(y + '-' + m + '-' + d, datas) != -1) {
                    var index = $.inArray(y + '-' + m + '-' + d, datas);
                    return [true, 'alerta-dados', variables[index]];
                } else if($.inArray(y + '-' + m + '-' + d, consistidos) != -1) {
                    return [true, 'data-consistida', "Data ok!"];
                }
                return [true];
            },
            onChangeMonthYear: function(year, month, inst) {
                if(year != ano) {
                    if(!searchInDates(year)) {
                        $.ajax({method:"GET", url:"/dates-by-year", data:{ ano: year }, async:false})
                            .done(function(information) {
                                $.each(information.datas, function(index, info) {
                                    datas.unshift(info.data_obs);
                                    variables.unshift(info.variaveis);
                                });
                                consistidos = consistidos.concat(information.consistidos);
                                $(this).datepicker("refresh");
                            })
                            .fail(function(data) {
                                console.error("Falha ao recuperar datas do ano " + year + "!");
                            });
                    }
                }
            }
        });

        jQuery("#slider-vertical").slider({
            orientation: "vertical",
            range: "min",
            min: 0,
            max: 100,
            value: 100,
            slide: function( event, ui ) {
                jQuery("#amount").val( ui.value );
                interpolacao.setOpacity(parseInt(ui.value)/100);
            }
        });
        jQuery( "#amount" ).val( $( "#slider-vertical" ).slider( "value" ) );

        jQuery('[data-tooltip="tooltip"]').tooltip();
    });

    function searchInDates(year) {
        for(var i=0;i<datas.length;i++) {
            var data = datas[i].split('-');
            if(data[i] == year)
                return true;
        }

        for(var i=0;i<consistidos.length;i++) {
            var consistido = consistidos[i].split('-');
            if(consistido[i] == year)
                return true;
        }

        return false;
    }

    $(document).ajaxStart(function() {
        $('#map-loader').show();
    });

    $(document).ajaxStop(function() {
        $('#map-loader').hide();
    });

    $("#hide-show-img").bootstrapSwitch({size: 'mini'});
    $("#hide-show-markers").bootstrapSwitch({size: 'mini'});

    $("#sattelite").draggable({
        handle: ".modal-header"
    });

    function initialize() {
    	var mapOptions = {
    		zoom: 4,
    		center: new google.maps.LatLng(-15.598889, -56.101389),
    		mapTypeId: google.maps.MapTypeId.TERRAIN,
    		scaleControl: true
    	};
    	map = new google.maps.Map(document.getElementById('map-canvas'),
    		mapOptions);

        map.controls[google.maps.ControlPosition.TOP_RIGHT].push(document.getElementById("legenda"));
        document.getElementById("lateral-vertical").style.display = "block";
        map.controls[google.maps.ControlPosition.LEFT_CENTER].push(document.getElementById("lateral-vertical"));

        /*interpolacao = new google.maps.KmlLayer({
            url: '',
            suppressInfoWindows: true,
            preserveViewport: true,
        });*/

        var imageBounds = {
            north: 13.125,
            south: -57.125,
            east: -32.875,
            west: -82.125
        };


        interpolacao = new google.maps.GroundOverlay(
            '',
        imageBounds, {clickable:false});

    	if(location.hash.trim() != "") {
            hashi = location.hash.split("/");
            console.log(hashi[2] + "/" + hashi[1]);
            $('#data').val(hashi[1]);
            $('#variavel').val(hashi[2].charAt(0).toUpperCase() + hashi[2].slice(1));
            getEstacoesJSON(hashi[2], hashi[1]);
        } else {
            getEstacoesJSON('', '');
        }

    }

    google.maps.event.addDomListener(window, 'load', initialize);

    function getEstacoesJSON(variavelSelecionada, dataSelecionada) {
    	$.getJSON('/estacoes/json',
    	{
    		data: dataSelecionada,
    		variavel: variavelSelecionada.toLowerCase()
    	},
    	function(pontos) {
    		var cont = 0;
            var suspeitos = [];
    		
    			$('#suspeitos').show();
    			var center = new google.maps.LatLng(-15.598889, -56.101389);
    			var middle = map.getCenter();
    			if(map.getZoom() != 4 || (middle.lng() != center.lng() || middle.lat() != center.lat())) {
    				map.panTo(center);
    				map.setZoom(4);
    			}
	    		$.each(pontos, function(index, ponto) {

	    			if(ponto.status_dado == "S" || ponto.status_dado == "Q" || ponto.status_dado == "D" || ponto.status_dado == "I") {
                        suspeitos.push(ponto);
                        return;
	    			}

                    var variavel = Object.keys(ponto)[Object.keys(ponto).length-1];

                    var output = returnVariable(variavel, ponto);

                    var marker = makeMarker(ponto, output, cont);

                    associateInfoBox(ponto, marker, cont);

                    cont++;

                    markers.push(marker);
                    estacoes.push({'codigo': ponto.codigo, 'lat': ponto.latitude, 'lon': ponto.longitude});

	    		});

                if(suspeitos.length == 0) {
                    $('#suspeitos').hide();
                    //clearOverlays();
                } else {
                    for(var i=0;i<suspeitos.length;i++) {
                        var variavel = Object.keys(suspeitos[i])[Object.keys(suspeitos[i]).length-1];

                        var output = returnVariable(variavel, suspeitos[i]);

                        var marker = makeMarker(suspeitos[i], output, cont);

                        var tableRow = document.createElement("tr");
                        var tCell1 = document.createElement("td");tCell1.appendChild(document.createTextNode(suspeitos[i].codigo));
                        var tCell2 = document.createElement("td");tCell2.appendChild(document.createTextNode(decodeURIComponent(suspeitos[i].estacao.replace(/\+/g, ' '))));
                        var tCell3 = document.createElement("td");tCell3.appendChild(document.createTextNode(decodeURIComponent(suspeitos[i].municipio.replace(/\+/g, ' ')) + "/" + suspeitos[i].uf));
                        var tCell4 = document.createElement("td");tCell4.appendChild(document.createTextNode(output));
                        var tCell5 = document.createElement("td");tCell5.appendChild(document.createTextNode(convertDate(suspeitos[i].data)));
                        var chkBox = document.createElement("input");chkBox.setAttribute("type", "checkbox");chkBox.setAttribute("name", "rejected[]");chkBox.setAttribute("value", "M");
                        chkBox.setAttribute("title", returnVariableName(variavel));chkBox.setAttribute("id", suspeitos[i].codigo);
                        var tCell6 = document.createElement("td");tCell6.setAttribute("class", "text-center");tCell6.appendChild(chkBox);

                        tableRow.appendChild(tCell1);
                        tableRow.appendChild(tCell2);
                        tableRow.appendChild(tCell3);
                        tableRow.appendChild(tCell4);
                        tableRow.appendChild(tCell5);
                        tableRow.appendChild(tCell6);
                        tableRow.setAttribute("class", "clickable-row");

                        document.getElementsByTagName("tbody")[0].appendChild(tableRow);

                        associateInfoBox(suspeitos[i], marker, cont);

                        cont++;

                        markers.push(marker);
                        estacoes.push({'codigo': suspeitos[i].codigo, 'lat': suspeitos[i].latitude, 'lon': suspeitos[i].longitude});
                    }

                    console.log("Tamanho: " + markers.length + " / " + estacoes.length);
                    console.log("Ult indice: " + markers.indexOf(markers[markers.length-1]) + " / v: " + markers[markers.length-1].ponto.codigo);
                }

    	});

        if($('#data').val() != hoje) {
            console.log("Numero de marcadores: " + markers.length);
            interpolaImgDados();
        } else {
            //$("#hide-show-img").bootstrapSwitch('state', false);
        }

        $('#rejected').load( "/rejeitados-meteorologista/" + $('#variavel').val().toLowerCase() + "/" + $('#data').val(), function() {
            console.log( "Conteudo externo foi carregado!" );
        });

        var imge = document.createElement("img");
        imge.setAttribute("src", "/img/" + $('#variavel').val().toLowerCase() + ".png");
        imge.setAttribute("alt", $("#variavel option:selected").text());
        document.getElementById("legenda").innerHTML = "";
        document.getElementById("legenda").appendChild(imge);

    }

    function abrirInfoBox(id, marker, ponto) {
    	if (typeof(idInfoBoxAberto) == 'number' && typeof(infoBox[idInfoBoxAberto]) == 'object') {
    		infoBox[idInfoBoxAberto].close();
    	}

    	var content = "<div class='marker-cq'><h5>" + ponto.codigo + " | " + decodeURIComponent(ponto.estacao.replace(/\+/g, ' ')) + "</h5>";//+ " - " + decodeURIComponent(ponto.municipio.replace(/\+/g, ' ')) + "/" + ponto.uf
    	var variavel = Object.keys(ponto)[Object.keys(ponto).length-1];
        var nomeVariavel = "";

        switch(variavel) {
            case 'tmin':
                content = content + "<br>Data: " + convertDate(ponto.data) + "<br>Temperatura minima: " + ponto[variavel] + " &deg;C";
                nomeVariavel = "Temperatura Mínima";
                break;
            case 'tmax':
                content = content + "<br>Data: " + convertDate(ponto.data) + "<br>Temperatura maxima: " + ponto[variavel] + " &deg;C";
                nomeVariavel = "Temperatura Máxima";
                break;
            case 'prec':
                content = content + "<br>Data: " + convertDate(ponto.data) + "<br>Precipitacao: " + ponto[variavel] + " mm";
                nomeVariavel = "Precipitação";
        }

    	if(ponto.status_dado == 'S' || ponto.status_dado == 'Q' || ponto.status_dado == 'D' || ponto.status_dado == 'I') {
    		content = content + "<br><br><b style='color:red'>Dado suspeito</b> <a href=\"javascript:void(0)\" role=\"button\" class=\"suspect btn btn-default btn-xs\" data-toggle=\"tooltip\" data-placement=\"top\" title=\"" + returnMessageSuspectData(ponto) + "\"><span class=\"glyphicon glyphicon-info-sign\" aria-hidden=\"true\"></span></a><br><br><div><button type=\"button\" value=\"C\" onclick=\"acceptOrRejectData(this)\" title=\"" + nomeVariavel + "\" class=\"btn btn-success dado-suspeito\">Aceitar <span class=\"glyphicon glyphicon-ok-circle\" aria-hidden=\"true\"></span></button>";
            content = content + "&nbsp; ou &nbsp;<button type=\"button\" value=\"M\" onclick=\"acceptOrRejectData(this, false)\" title=\"" + nomeVariavel + "\" class=\"btn btn-danger dado-suspeito\">Rejeitar <span class=\"glyphicon glyphicon-remove-circle\" aria-hidden=\"true\"></span></button></div>";
        } else {
            content = content + "<br><br><div><button type=\"button\" value=\"M\" onclick=\"acceptOrRejectData(this, false)\" title=\"" + nomeVariavel + "\" class=\"btn btn-danger dado-suspeito\">Rejeitar <span class=\"glyphicon glyphicon-remove-circle\" aria-hidden=\"true\"></span></button></div>";
        }

        content = content + "</div>";

    	infoBox[marker.number].setContent(content);

    	infoBox[marker.number].open(map, marker);
    	idInfoBoxAberto = marker.number;
    }

    function returnVariableName(variavel) {
        var nomeVariavel = "";

        switch(variavel) {
            case 'tmin':
                nomeVariavel = "Temperatura Mínima";
                break;
            case 'tmax':
                nomeVariavel = "Temperatura Máxima";
                break;
            case 'prec':
                nomeVariavel = "Precipitação";
        }

        return nomeVariavel;
    }

    function returnMessageSuspectData(ponto) {
        console.log(ponto.status_dado + " / " + Object.keys(ponto)[Object.keys(ponto).length-1]);

        var messages = new Array();

        messages['d'] = "Valor do dado divergindo da climatologia e de esta&ccedil;&otilde;es pr&oacute;ximas";
        messages['i'] = "Valores diferentes no mesmo ponto";
        messages['s'] = "Valor excede os limites (vari&aacute;vel e/ou &aacute;rea)";

        if(ponto.status_dado == 'Q') {
            switch(Object.keys(ponto)[Object.keys(ponto).length-1]) {
                case 'tmin':
                    return "Valor maior que Tmax ou maior que Temp";
                case 'tmax':
                    return "Valor menor que Tmin ou menor que Temp";
                case 'prec':
                    return "Valor diverge dos vizinhos";
            }
        } else
            return messages[ponto.status_dado.toLowerCase()];
    }

    function mudaVariavelData() {
    	if($('#variavel').val() != "") {
    		var varEscolhida = $("#variavel option:selected").text();
    		$('.col-sm-5 h5 span').html(varEscolhida.toLowerCase());
    	}
        $('#selectAll').prop('checked', false);
    	document.getElementsByTagName("th")[3].innerHTML = $("#variavel option:selected").val();
    	clearOverlays();
    	document.getElementsByTagName("tbody")[0].innerHTML = "";
    	$('#suspeitos').hide();
        interpolacao.setMap(null);
        mudou = false;
        lastDate = "";
        $("#hide-show-img").bootstrapSwitch('state', true);
        $("#hide-show-markers").bootstrapSwitch('state', true);
    	getEstacoesJSON($('#variavel').val(), $('#data').val());
    }

    function clearOverlays() {
    	for(var i = 0; i < markers.length; i++) {
            markers[i].setMap(null);
            infoBox[i] = null;
    	}
    	infoBox.length = 0;
    	idInfoBoxAberto = -1;
        idInfoBoxAberto;
        idEstacaoSelecionada = -111111111111111111;
        markers.length = 0;
        estacoes.length = 0;
    }

    $('#hide-show-img').on('switchChange.bootstrapSwitch', function(event, state) {
        if(state) {
            interpolaImgDados();
        } else {
            interpolacao.setMap(null);
        }
    });

    $('#hide-show-markers').on('switchChange.bootstrapSwitch', function(event, state) {
        if(state) {
            for(var i = 0; i < markers.length; i++) {
                markers[i].setMap(map);
            }
        } else {
            for(var i = 0; i < markers.length; i++) {
                if(markers[i].ponto.status_dado != "S" && markers[i].ponto.status_dado != "Q" && markers[i].ponto.status_dado != "D" && markers[i].ponto.status_dado != "I") {
                    markers[i].setMap(null);
                }
            }
        }
    });

    function interpolaImgDados() {
        if(mudou == true || lastDate == "") {
            $.ajax({method:"GET", url:"/executa-interpolacao", data:{ data: $('#data').val().split('-').join(''), variavel: variaveisCQ[$('#variavel').val()] }, async:false})
                .done(function(data) {
                    console.log('http://bancodedados.cptec.inpe.br/~rbanco/kml/controle-qualidade/' + data + variaveisCQ[$('#variavel').val()] + "_interpolado.png");
                    lastDate = data;
                    interpolacao.set('url', 'http://bancodedados.cptec.inpe.br/~rbanco/kml/controle-qualidade/' + data + variaveisCQ[$('#variavel').val()] + '_interpolado.png');
                    //interpolacao.setUrl("http://bancodedados.cptec.inpe.br/kml.php?variavel=" + variaveisCQ[$('#variavel').val()] + "&date=" + data + "&type=1");
                    interpolacao.setMap(map);
                })
                .fail(function(data) {
                    console.error("Falhou a construcao da imagem!");
                });
        } else {
            interpolacao.setMap(map);
        }

    }

    function zoomInEstacao(codigo) {
        var index = codigoEstaNoArray(codigo, estacoes);
    	if(index > -1) {
    		map.panTo(new google.maps.LatLng(estacoes[index].lat, estacoes[index].lon));
    		if(map.getZoom() < 8)
                map.setZoom(8);

            idEstacaoSelecionada = index;
    	}
    }

    function convertDate(dateSelected) {
    	var d = dateSelected.split('-');
    	return d[2] + '/' + d[1] + '/' + d[0];
    }

    function returnVariable(variavel, ponto) {
    	switch(variavel) {
			case 'tmin':
				output = Math.floor(ponto[variavel].trim());
				break;
			case 'tmax':
				output = Math.floor(ponto[variavel].trim());
				break;
			case 'prec':
				output = Math.floor(ponto[variavel].trim());
		}

		return output;
    }

    function makeMarker(ponto, output, cont) {
    	var icone = (ponto.status_dado == 'S' || ponto.status_dado == 'Q' || ponto.status_dado == 'D' || ponto.status_dado == 'I')?'https://chart.googleapis.com/chart?chst=d_bubble_text_small&chld=edge_bc|' + "\n " + output + " \n" + '|FF0000|FFF':'https://chart.googleapis.com/chart?chst=d_bubble_text_small&chld=edge_bc|' + output + '|2F4F4F|FFF';

		var marker = new google.maps.Marker({
			position: new google.maps.LatLng(ponto.latitude, ponto.longitude),
			title: ponto.codigo + " - " + decodeURIComponent(ponto.estacao.replace(/\+/g, ' ')) + " - " + decodeURIComponent(ponto.municipio.replace(/\+/g, ' ')) + "/" + ponto.uf,
			map: map,
			number: cont,
            ponto: ponto,
			icon: icone
		});

		return marker;
    }

    function associateInfoBox(ponto, marker, cont) {
    	infoBox[cont] = new google.maps.InfoWindow();

		infoBox[cont].marker = marker;

		infoBox[cont].listener = google.maps.event.addListener(marker, 'click', function (e) {
            idEstacaoSelecionada = codigoEstaNoArray(ponto.codigo, estacoes);
			abrirInfoBox(cont, marker, ponto);
			if(map.getZoom() < 8 && (ponto.status_dado == 'S' || ponto.status_dado == 'Q' || ponto.status_dado == 'D' || ponto.status_dado == 'I'))
				zoomInEstacao(ponto.codigo);
		});

        google.maps.event.addListener(infoBox[cont], 'domready', function() {
            $(".suspect").tooltip({
                placement : 'top'                     
            });
        });
    }

    $('#suspeitos tbody').on('click', 'tr.clickable-row', function( event ) {
        var cod = $(this).find("td").html();
        idEstacaoSelecionada = codigoEstaNoArray(cod, estacoes);
        if(cod && event.target.nodeName.toLowerCase() != 'input') {
            abrirInfoBox(cod, markers[idEstacaoSelecionada], markers[idEstacaoSelecionada].ponto);
			zoomInEstacao(cod);
        }
    });

    $('#finishDay').click(function() {
        if(confirm("Tem certeza que deseja finalizar o controle de qualidade na data " + convertDate($('#data').val()) + "?")) {
            $.ajax({method:"POST", url:"/finalizar-data", data:{ data: $('#data').val()}})
            .done(function(data) {
                consistidos = data;
                jQuery('input[type=date]').datepicker( "refresh" );
                alert("Data finalizada com sucesso!");
            })
            .fail(function() {
                alert("Falha: ainda existe(m) dado(s) suspeito(s) nesta data.\nÉ necessário aceitá-lo(s) ou rejeitá-lo(s) antes de finalizar a data!");
            });
        }
    });

    $('#selectAll').change(function() {
        if($(this).is(':checked')) {
            $('[name="rejected[]"]').prop('checked', true);
            $(this).parent().find("label").html("Desmarcar todos");
        } else {
            $('[name="rejected[]"]').prop('checked', false);
            $(this).parent().find("label").html("Selecionar todos");
        }
    });

    $('#variavel').change(function() {
        if($(this).val() != "" && location.hash.trim() != "") {
            var hashi = location.hash.split("/");
            hashi[2] = $(this).val();
            location.hash = hashi.join("/").toLowerCase();
        }
    });

    function acceptOrRejectData(elemento, opcao) {
        var flag = elemento.value;
        var msg = (flag == "C")?"aceitar":"rejeitar";
        var titulo = elemento.title;
        var point = null;
        var paradaAberta = -1111111111;

        if(!opcao)
            opcao = confirm("Deseja realmente " + msg + " este dado?");

        if(opcao) {
            if(elemento.nodeName.toLowerCase() == "button") {
                infoBox[idInfoBoxAberto].close();
                point = markers[idInfoBoxAberto].ponto;
                paradaAberta = idInfoBoxAberto;
            } else {
                paradaAberta = codigoEstaNoArray(elemento.id, estacoes);
                idInfoBoxAberto = paradaAberta;
                point = markers[paradaAberta].ponto;
            }

            $.ajax({method:"POST", url:"/alterar-status-dado", data:{ data: point.data, variavel: Object.keys(point)[Object.keys(point).length-1], lat: point.latitude, lon: point.longitude, flag: flag, codigo: point.codigo }, async:false})
                .done(function() {
                    mudou = true;
                    if(point.status_dado == "S" || point.status_dado == "Q" || ponto.status_dado == 'D' || ponto.status_dado == 'I') {
                        var linhas = document.getElementById("suspeitos").getElementsByTagName("tr");
                        var indice = datas.indexOf(point.data);
                        var vars = new Array();

                        if(variables[indice].indexOf('/') == -1) {
                            vars.push(variables[indice]);
                        } else {
                            vars = variables[indice].split('/');
                        }
                        
                        var listaDatasVars = document.getElementById("controle-qualidade").getElementsByTagName("option");

                        for(var i=0;i<linhas.length;i++) {
                            if(linhas[i].getElementsByTagName("td").length > 0) {
                                if(linhas[i].getElementsByTagName("td")[0].innerHTML == point.codigo) {
                                    linhas[i].parentNode.removeChild(linhas[i]);
                                    break;
                                }
                            }   
                        }

                        if(linhas.length == 2) {
                            for(var j=0;j<vars.length;j++) {
                                if(vars[j].trim() == titulo) {
                                    vars.splice(j, 1);
                                    break;
                                }
                            }
                            $('#selectAll').prop('checked', false);
                            $('#selectAll').trigger("change");
                            $('#suspeitos').hide();
                        }

                        if(vars.length > 0) {
                            variables[indice] = vars.join(" / ");
                            listaDatasVars[indice].setAttribute("label", vars.join(" / "));
                        } else {
                            variables.splice(indice, 1);
                            datas.splice(indice, 1);
                            if(!Modernizr.inputtypes.date)
                                jQuery('input[type=date]').datepicker( "refresh" );

                            listaDatasVars[indice].parentNode.removeChild(listaDatasVars[indice]);
                            jQuery('input[type=date]').val(hoje);
                        }
                    }

                    if(markers[paradaAberta] == null) {
                        console.error("Indice nao encontrado! " + paradaAberta + " % " + codigoEstaNoArray(paradaAberta, estacoes));
                    }

                    if(paradaAberta >= markers.length)
                        console.log("Posicao maior que o tamanho do vetor! " + markers.length + " / " + paradaAberta + " | " + elemento.id);

                    testMarkersArrayValidity(markers);

                    markers[paradaAberta].setVisible(false);
                    markers[paradaAberta].setMap(null);
                    markers.splice(paradaAberta, 1);
                    infoBox.splice(paradaAberta, 1);
                    estacoes.splice(paradaAberta, 1);

                    console.log("Tamanho: " + markers.length + " / " + estacoes.length);
                    console.log("Ult indice: " + markers.indexOf(markers[markers.length-1]));

                    console.log("Markers: " + markers.length + " / " + "Parada aberta: " + paradaAberta);

                    for(var cont=paradaAberta;cont<markers.length;cont++) {
                        markers[cont].number = cont;
                    }

                })
                .fail(function(data) {
                    alert("Falhou o aceite/rejeite do dado suspeito: " + data);
                });

                $('#rejected').load( "/rejeitados-meteorologista/" + $('#variavel').val().toLowerCase() + "/" + $('#data').val(), function() {
                    console.log( "Conteudo externo foi carregado!" );
                });
        }
    }

    function rejectVarious() {
        if($('[name="rejected[]"]').is(':checked')) {
            if(confirm("Você deseja realmente rejeitar o(s) dado(s) suspeitos selecionados?")) {
                $('[name="rejected[]"]').each(function () {
                    if($(this).is(':checked')) {
                        acceptOrRejectData(this, true);
                    }
                });
            }
        }
    }

    function codigoEstaNoArray(searchedValue, vetor) {
        for(var i=0;i<vetor.length;i++) {
            if(vetor[i].codigo == searchedValue) {
                return i;
            }
        }
        return -1;
    }

    function testMarkersArrayValidity(marcadores) {
        for(var i=0;i<marcadores.length;i++) {
            if(marcadores[i].number != i) {
                console.error("Falhou! " + marcadores[i].number + " / " + i);
            }
        }
        console.log("tudo ok");
    }

    function success() {
        thereIsCurrentImage = true;
    }

    function failure() {
        thereIsCurrentImage = false;
    }

    function anima(valorMaximo) {
        var hours = Math.round(currentPosition/2);
        hours = (currentPosition%2 == 0)?hours:hours-1;
        hours = (hours < 10)?"0"+hours.toString():hours.toString();
        var minutes = (currentPosition%2 == 0)?"00":"30";

        checkImage(prefixSat + hours + minutes + ".jpg", success, failure);

        if(!thereIsCurrentImage) {
            if(currentPosition < valorMaximo)
                currentPosition++;
            else
                currentPosition = 0;
            return;
        }

        $('.modal-header h4 span').html(convertDate($('#data').val()) + " " + hours + "h" + minutes);

        $('.modal-body .img-responsive').attr('src', prefixSat + hours + minutes + ".jpg");
        $('.modal-body .img-responsive').attr('alt', convertDate($('#data').val()) + " " + hours + "h" + minutes);

        if(currentPosition >= valorMaximo)
            currentPosition = 0;
        else
            currentPosition++;
    }

    $('#sattelite').on('shown.bs.modal', function () {
        $('.modal-header h4 span').html(convertDate($('#data').val()));
        var datePieces = $('#data').val().split('-');
        var mes = (parseInt(datePieces[1]) < 10)?"0"+parseInt(datePieces[1]).toString():datePieces[1];
        var dia = (parseInt(datePieces[2]) < 10)?"0"+parseInt(datePieces[2]).toString():datePieces[2];
        prefixSat = "http://satelite.cptec.inpe.br/repositorio5/goes13/goes13_web/ams_realcada_alta/" + datePieces[0] + "/" + mes + "/S11232958_" + datePieces[0] + mes + dia;

        var altura = screen.height*0.62;

        $('.modal-body .img-responsive').attr('width', altura*100/94.625);
        $('.modal-body .img-responsive').attr('height', altura);

        if($('#data').val() != hoje) {
            timer = setInterval(function() {
                anima(47);
            }, 500);
        } else {
            $.ajax({method:"GET", url:"/hora-atual", async:false})
                .done(function(data) {
                    var details = data.split(":");
                    limit = (details[0]-1)*2;
                    limit = (details[0] >= 30)?limit+1:limit;
                    timer = setInterval(function() {
                        anima(limit);
                    }, 500);
                });
        }

    });

    $('#sattelite').on('hidden.bs.modal', function (e) {
        if(timer != null)
            clearInterval( timer );

        currentPosition = 0;
    });