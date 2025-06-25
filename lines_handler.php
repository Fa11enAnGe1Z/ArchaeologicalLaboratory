<?php
error_reporting(0); // Отключение вывода ошибок
ini_set('display_errors', 0); // Отключение отображения ошибок

header('Content-Type: application/json');

// Параметры подключения к базе данных
$servername = "localhost"; // Имя сервера
$username = "root"; // Имя пользователя
$password = ""; // Пароль от базы данных
$dbname = "coordinates_lines"; // Имя базы данных

// Создание соединения с базой данных
$conn = new mysqli($servername, $username, $password, $dbname);

// Проверка соединения
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'check_address') {
        $address = isset($_GET['address']) ? $conn->real_escape_string($_GET['address']) : '';
        if (!$address) {
            http_response_code(400);
            echo json_encode(['error' => 'Address parameter missing']);
            $conn->close();
            exit;
        }
        $sql = "SELECT id_address, latitude, longitude FROM address WHERE address = '$address' LIMIT 1";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'DB error: ' . $conn->error]);
            $conn->close();
            exit;
        }
        if ($row = $result->fetch_assoc()) {
            echo json_encode(['exists' => true, 'id_address' => (int)$row['id_address'], 'latitude' => (float)$row['latitude'], 'longitude' => (float)$row['longitude']]);
        } else {
            echo json_encode(['exists' => false]);
        }
        $conn->close();
        exit;
    } elseif ($action === 'get_address') {
        $id_address = isset($_GET['id_address']) ? intval($_GET['id_address']) : 0;
        if (!$id_address) {
            http_response_code(400);
            echo json_encode(['error' => 'id_address parameter missing']);
            $conn->close();
            exit;
        }
        $sql = "SELECT id_address, address, latitude, longitude FROM address WHERE id_address = $id_address LIMIT 1";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'DB error: ' . $conn->error]);
            $conn->close();
            exit;
        }
        if ($row = $result->fetch_assoc()) {
            echo json_encode($row);
        } else {
            echo json_encode(null);
        }
        $conn->close();
        exit;
    } elseif ($action === 'get_lines') {
        $id_address = isset($_GET['id_address']) ? intval($_GET['id_address']) : 0;
        if (!$id_address) {
            http_response_code(400);
            echo json_encode(['error' => 'id_address parameter missing']);
            $conn->close();
            exit;
        }
        $sql = "SELECT line_id, latitude, longitude FROM coord WHERE id_address = $id_address ORDER BY line_id, id";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'DB error: ' . $conn->error]);
            $conn->close();
            exit;
        }
        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $line_id = $row['line_id'];
            $point = [(float)$row['latitude'], (float)$row['longitude']];
            if (!isset($lines[$line_id])) {
                $lines[$line_id] = [];
            }
            $lines[$line_id][] = $point;
        }
        echo json_encode($lines);
        $conn->close();
        exit;
    } elseif ($action === 'get_addresses') {
        $sql = "SELECT id_address, address, latitude, longitude FROM address";
        $result = $conn->query($sql);
        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'DB error: ' . $conn->error]);
            $conn->close();
            exit;
        }
        $addresses = [];
        while ($row = $result->fetch_assoc()) {
            $addresses[] = $row;
        }
        echo json_encode($addresses);
        $conn->close();
        exit;
    } else {
        // По умолчанию: вернуть все линии, сгруппированные по line_id, игнорируя адрес
        $sql = "SELECT id, line_id, latitude, longitude FROM coord ORDER BY line_id, id";
        $result = $conn->query($sql);

        if (!$result) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch data: ' . $conn->error]);
            $conn->close();
            exit;
        }

        $lines = [];
        while ($row = $result->fetch_assoc()) {
            $line_id = $row['line_id'];
            $point = [(float)$row['latitude'], (float)$row['longitude']];
            if (!isset($lines[$line_id])) {
                $lines[$line_id] = [];
            }
            $lines[$line_id][] = $point;
        }

        // Переиндексация массива линий, чтобы индексы были последовательными, начиная с нуля
        $lines = array_values($lines);

        echo json_encode($lines);
        $conn->close();
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if ($data === null && $action !== 'delete_address') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        $conn->close();
        exit;
    }

    if ($action === 'add_address') {
        $address = isset($data['address']) ? $conn->real_escape_string($data['address']) : '';
        $latitude = isset($data['latitude']) ? floatval($data['latitude']) : null;
        $longitude = isset($data['longitude']) ? floatval($data['longitude']) : null;

        if (!$address || $latitude === null || $longitude === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters']);
            $conn->close();
            exit;
        }

        // Проверка, существует ли уже такой адрес
        $checkSql = "SELECT id_address FROM address WHERE address = '$address' LIMIT 1";
        $checkResult = $conn->query($checkSql);
        if ($checkResult && $checkResult->num_rows > 0) {
            $row = $checkResult->fetch_assoc();
            echo json_encode(['status' => 'exists', 'id_address' => (int)$row['id_address']]);
            $conn->close();
            exit;
        }

        $insertSql = "INSERT INTO address (address, latitude, longitude) VALUES ('$address', $latitude, $longitude)";
        if ($conn->query($insertSql)) {
            $newId = $conn->insert_id;
            echo json_encode(['status' => 'success', 'id_address' => $newId]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert address: ' . $conn->error]);
        }
        $conn->close();
        exit;
    } elseif ($action === 'save_lines') {
        $id_address = isset($data['id_address']) ? intval($data['id_address']) : 0;
        $linesData = isset($data['lines']) ? $data['lines'] : null;

        if (!$id_address || !is_array($linesData)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters']);
            $conn->close();
            exit;
        }

        $conn->begin_transaction();
        try {
            // Удалить существующие линии для данного адреса
            $conn->query("DELETE FROM coord WHERE id_address = $id_address");

            $stmt = $conn->prepare("INSERT INTO coord (latitude, longitude, id_address, line_id) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }

            foreach ($linesData as $line_id => $points) {
                foreach ($points as $point) {
                    $latitude = $point[0];
                    $longitude = $point[1];
                    // Исправлено указание типов для bind_param: latitude и longitude — числа с плавающей точкой (double), id_address и line_id — целые числа (integer)
                    $stmt->bind_param("ddii", $latitude, $longitude, $id_address, $line_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed: " . $stmt->error);
                    }
                }
            }
            $stmt->close();
            $conn->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save lines: ' . $e->getMessage()]);
        }
        $conn->close();
        exit;
    } elseif ($action === 'delete_address') {
        // Для действия delete_address разрешается отправлять HTTP POST-запросы без тела в формате JSON
        $id_address = isset($_GET['id_address']) ? intval($_GET['id_address']) : 0;
        if (!$id_address) {
            http_response_code(400);
            echo json_encode(['error' => 'id_address parameter missing']);
            $conn->close();
            exit;
        }
        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM coord WHERE id_address = $id_address");
            $conn->query("DELETE FROM address WHERE id_address = $id_address");
            $conn->commit();
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete address: ' . $e->getMessage()]);
        }
        $conn->close();
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        $conn->close();
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    $conn->close();
    exit;
}
