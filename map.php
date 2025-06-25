<?php
// Получаем параметр address из URL
$addressValue = isset($_GET['address']) ? htmlspecialchars($_GET['address'], ENT_QUOTES, 'UTF-8') : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Метки и линии на карте</title>
    <style>
        #map {
            width: 100%;
            height: 900px;
        }
        #controls {
            margin-top: 10px;
        }
        button {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div id="map"></div>
    <div id="controls">
        <button id="addMarkerBtn" disabled>Добавить метку</button>
        <button id="newLineBtn" disabled>Новая линия</button>
        <button id="removeLastPointBtn" disabled>Удалить последнюю точку</button>
        <button id="saveBtn" disabled>Сохранить</button>
    </div>

    <script src="https://api-maps.yandex.ru/2.1/?apikey=8013b162-6b42-4997-9691-77b7074026e0&amp;lang=ru_RU" type="text/javascript"></script>
    <script type="text/javascript">
    ymaps.ready(init);

    const addressValue = <?php echo json_encode($addressValue); ?>;

    // Инициализация карты и установка начальных параметров
    function init() {
        var coordinateOrsk = [51.2049, 58.5668];
        var myMap = new ymaps.Map("map", {
            center: coordinateOrsk,
            zoom: 10
        });

        // Установка центра карты на координаты Орска
        myMap.setCenter(coordinateOrsk);

        if(addressValue){
            // Проверка существования адреса в базе данных
            fetch('lines_handler.php?action=check_address&address=' + encodeURIComponent(addressValue))
            .then(resp => resp.json())
            .then(data => {
                if(data.exists){
                    var id_address = data.id_address;
                    // Загрузка метки и линий для данного адреса
                    fetch('lines_handler.php?action=get_address&id_address=' + id_address)
                    .then(resp => resp.json())
                    .then(addrData => {
                        if(addrData){
                            var coordinate = [addrData.latitude, addrData.longitude];
                            // Центрирование карты на координатах метки с увеличенным масштабом
                            myMap.setCenter(coordinate, 10);
                            var placemark = new ymaps.Placemark(coordinate, {}, {
                                preset: 'islands#blueDotIcon',
                                draggable: true
                            });
                            //Обработчик клика по метке для удаления метки и связанных линий
                            function addPlacemarkClickHandler(placemark, id_address) {
                                placemark.events.add('click', function(){
                                    if(confirm('Удалить метку и все связанные с ней линии?')){
                                        fetch('lines_handler.php?action=delete_address&id_address=' + id_address, {method: 'POST'})
                                        .then(r => r.json())
                                        .then(res => {
                                            if(res.status === 'success'){
                                                delete lines[id_address];
                                                updatePolylines(); // Обновление интерфейса
                                                myMap.geoObjects.remove(placemark);
                                                delete markers[id_address];
                                                currentIdAddress = null;
                                                newLineBtn.disabled = true;
                                                addMarkerBtn.disabled = false;
                                                saveBtn.disabled = true;
                                                alert('Метка и связанные линии удалены.');
                                            } else {
                                                alert('Ошибка при удалении метки: ' + (res.error || 'Неизвестная ошибка'));
                                            }
                                        });
                                    }
                                });
                            }
                            myMap.geoObjects.add(placemark);
                            markers[id_address] = placemark;
                            addPlacemarkClickHandler(placemark, id_address);
                            currentIdAddress = id_address;
                            newLineBtn.disabled = false;
                            addMarkerBtn.disabled = true;
                            saveBtn.disabled = false;
                            // Загрузка линий
                            fetch('lines_handler.php?action=get_lines&id_address=' + id_address)
                            .then(resp => resp.json())
                            .then(linesData => {
                                if(linesData && typeof linesData === 'object'){
                                    lines[id_address] = linesData;
                                    updatePolylines(); // Обновление интерфейса
                                }
                            });
                        }
                    });
                } else {
                    // Адрес не найден в базе, центрируем карту на Орск
                    myMap.setCenter(coordinateOrsk, 10);
                    addMarkerBtn.disabled = false;
                    newLineBtn.disabled = true;
                    saveBtn.disabled = true;
                }
            });
        } else {
            // Нет параметра адреса, центрируем карту на Орск
            myMap.setCenter(coordinateOrsk, 10);
            addMarkerBtn.disabled = false;
        }

        var markers = {};
        var lines = {};
        var currentIdAddress = null;
        var currentLineId = null;
        var currentPolyline = null;

        var addMarkerBtn = document.getElementById('addMarkerBtn');
        var newLineBtn = document.getElementById('newLineBtn');
        var removeLastPointBtn = document.getElementById('removeLastPointBtn');
        var saveBtn = document.getElementById('saveBtn');

        var placingMarker = false;
        var tempMarker = null;

        // Начальные состояния кнопок
        addMarkerBtn.disabled = true;
        newLineBtn.disabled = true;
        removeLastPointBtn.disabled = true;
        saveBtn.disabled = true;

        // Кнопка "Добавить метку"
        addMarkerBtn.addEventListener('click', function(){
            if(placingMarker){
                return;
            }
            placingMarker = true;
            addMarkerBtn.disabled = true;
            newLineBtn.disabled = true;
            saveBtn.disabled = false;
            alert('Кликните на карту, чтобы поставить метку.');

            myMap.events.add('click', placeMarkerOnce);
        });
        
        // Обработчик клика по карте для установки временной метки (До нажатия на Сохранить)
        function placeMarkerOnce(e){
            if(tempMarker){
                myMap.geoObjects.remove(tempMarker);
            }
            var coords = e.get('coords');
            tempMarker = new ymaps.Placemark(coords, {}, {
                preset: 'islands#blueDotIcon',
                draggable: true
            });
            myMap.geoObjects.add(tempMarker);
            myMap.events.remove('click', placeMarkerOnce);
        }

        // Кнопка сохранения
        saveBtn.addEventListener('click', function(){
            if(placingMarker && tempMarker){
                // Сохранение метки
                var coords = tempMarker.geometry.getCoordinates();
                fetch('lines_handler.php?action=add_address', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        address: addressValue,
                        latitude: coords[0],
                        longitude: coords[1]
                    })
                })
                .then(resp => resp.json())
                .then(data => {
                    if(data.status === 'success'){
                // Обновление текущего ID адреса после успешного сохранения метки
                currentIdAddress = data.id_address;
                if(tempMarker){
                    myMap.geoObjects.remove(tempMarker);
                }
                var coords = tempMarker.geometry.getCoordinates();
                var newPlacemark = new ymaps.Placemark(coords, {}, {
                    preset: 'islands#blueDotIcon',
                    draggable: true
                });
                markers[currentIdAddress] = newPlacemark;
                myMap.geoObjects.add(newPlacemark);
                tempMarker = null;
                placingMarker = false;
                addMarkerBtn.disabled = true;
                newLineBtn.disabled = false;
                saveBtn.disabled = true;
                alert('Метка успешно добавлена.');
                updatePolylines(); // Обновление отображения меток на карте после сохранения
                console.log('Marker saved, currentIdAddress set to:', currentIdAddress);
                // Событие для уведомления об обновлении currentIdAddress
                document.dispatchEvent(new CustomEvent('markerSaved', { detail: { id: currentIdAddress } }));
            } else {
                        alert('Ошибка при добавлении метке: ' + (data.error || 'Неизвестная ошибка'));
                    }
                });
            } else {
                // Сохранение линий
            if(!currentIdAddress){
                if(tempMarker){
                    alert('Метка поставлена, но не сохранена. Пожалуйста, сохраните метку перед сохранением линий.');
                } else {
                    alert('Сначала поставьте метку на карту и сохраните её.');
                }
                return;
            }
                if(!lines[currentIdAddress] || Object.keys(lines[currentIdAddress]).length === 0){
                    alert('Нет линий для сохранения.');
                    return;
                }
                var dataToSave = {
                    id_address: currentIdAddress,
                    lines: lines[currentIdAddress]
                };
                fetch('lines_handler.php?action=save_lines', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(dataToSave)
                })
                .then(resp => resp.json())
                .then(data => {
                    if(data.status === 'success'){
                        alert('Линии успешно сохранены.');
                        saveBtn.disabled = true; // Отключение кнопки сохранения после успешного сохранения
                    } else {
                        alert('Ошибка при сохранении линий: ' + (data.error || 'Неизвестная ошибка'));
                    }
                });
            }
            updatePolylines(); // Обновление интерфейса
        });

        // Кнопка "Новая линия"
        newLineBtn.addEventListener('click', function(){
            if(!currentIdAddress){
                alert('Сначала поставьте метку для адреса.');
                return;
            }
            if(!lines[currentIdAddress]){
                lines[currentIdAddress] = {};
            }
            var newLineId = 0;
            if(Object.keys(lines[currentIdAddress]).length > 0){
                newLineId = Math.max(...Object.keys(lines[currentIdAddress]).map(Number)) + 1;
            }
            lines[currentIdAddress][newLineId] = [];
            currentLineId = newLineId;
            removeLastPointBtn.disabled = false;
            saveBtn.disabled = false; // Включение кнопки сохранения когда добавлены новые линии
            updatePolylines(); // Обновление интерфейса
        });

        // Обработчик клика по карте для установки новых линий
        myMap.events.add('click', function(e){
            if(currentLineId === null) return;
            var coords = e.get('coords');
            if(lines[currentIdAddress] && lines[currentIdAddress][currentLineId]){
                lines[currentIdAddress][currentLineId].push(coords);
                updatePolylines(); // Обновление интерфейса
            }
        });

        // Кнопка "Удалить последнюю точку"
        removeLastPointBtn.addEventListener('click', function(){
            if(currentLineId === null) return;
            if(lines[currentIdAddress] && lines[currentIdAddress][currentLineId] && lines[currentIdAddress][currentLineId].length > 0){
                lines[currentIdAddress][currentLineId].pop();
                updatePolylines(); // Обновление интерфейса
                if(saveBtn) {
                    saveBtn.disabled = false;
                }
            }
        });

        // Обновление линий и меток
        function updatePolylines(){
            myMap.geoObjects.removeAll();
            for(let id_address in markers){
                myMap.geoObjects.add(markers[id_address]);
            }
            for(let id_address in lines){
                let linesById = lines[id_address];
                for(let lineId in linesById){
                    let points = linesById[lineId];
                    addPolyline(points, id_address, lineId);
                }
            }
        }

        // Отображение линий и обновление интерфейса после их удаления
        function addPolyline(points, id_address, lineId){
            var polyline = new ymaps.Polyline(points, {}, {
                strokeColor: "#ff0000",
                strokeWidth: 4
            });
            polyline.events.add('click', function(){
                if(confirm('Удалить эту линию?')){
                    if(lines[id_address] && lines[id_address][lineId]){
                        delete lines[id_address][lineId];
                        updatePolylines();  // Обновление интерфейса
                        // Включение кнопки сохранения после удаления линии
                        if(saveBtn) {
                            saveBtn.disabled = false;
                        }
                    }
                }
            });
            myMap.geoObjects.add(polyline);
        }
    }
    </script>
</body>
</html>
