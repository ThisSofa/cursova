<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$api_url = rtrim(getenv('API_BASE_URL') ?: "http://api:8000", '/');

$parsed_api = parse_url($api_url);
if (isset($parsed_api['host']) && $parsed_api['host'] === 'api') {
    $scheme = $parsed_api['scheme'] ?? 'http';
    $path = $parsed_api['path'] ?? '';
    $api_url = rtrim($scheme . '://api:8000' . $path, '/');
}

$message = "";
$message_type = "success";
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'list';

function api_request($method, $url, $payload = null) {
    $headers = ["Content-Type: application/json"];
    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true
        ]
    ];

    if ($payload !== null) {
        $options['http']['content'] = json_encode($payload);
    }

    $options['http']['timeout'] = 5;
    $context = stream_context_create($options);

    $candidate_urls = [$url];
    $parsed_url = parse_url($url);
    if (isset($parsed_url['host']) && $parsed_url['host'] === 'api' && (!isset($parsed_url['port']) || (int)$parsed_url['port'] !== 8000)) {
        $fallback_url = ($parsed_url['scheme'] ?? 'http') . '://' . $parsed_url['host'] . ':8000' . ($parsed_url['path'] ?? '');
        if (isset($parsed_url['query'])) {
            $fallback_url .= '?' . $parsed_url['query'];
        }
        $candidate_urls[] = $fallback_url;
    }

    foreach ($candidate_urls as $candidate_url) {
        $response = @file_get_contents($candidate_url, false, $context);
        $status_line = isset($http_response_header[0]) ? $http_response_header[0] : "HTTP/1.1 503 Service Unavailable";
        preg_match('/\s(\d{3})\s/', $status_line, $matches);
        $status_code = isset($matches[1]) ? (int)$matches[1] : 503;

        if ($response !== false) {
            return [$status_code, $response];
        }
    }

    return [503, false];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create') {
        $payload = [
            "name" => trim($_POST['name']),
            "category" => trim($_POST['category']),
            "status" => trim($_POST['status']),
            "quantity" => (int)$_POST['quantity'],
            "description" => trim($_POST['description'])
        ];

        [$status] = api_request('POST', $api_url . '/items/', $payload);
        if ($status >= 200 && $status < 300) {
            header("Location: index.php?tab=create&msg=created");
            exit;
        }
        header("Location: index.php?tab=create&msg=error");
        exit;
    }

    if ($action === 'update') {
        $item_id = (int)$_POST['item_id'];
        $payload = [
            "name" => trim($_POST['name']),
            "category" => trim($_POST['category']),
            "status" => trim($_POST['status']),
            "quantity" => (int)$_POST['quantity'],
            "description" => trim($_POST['description'])
        ];

        [$status] = api_request('PUT', $api_url . '/items/' . $item_id, $payload);
        if ($status >= 200 && $status < 300) {
            header("Location: index.php?tab=list&msg=updated");
            exit;
        }
        header("Location: index.php?tab=list&msg=error");
        exit;
    }

    if ($action === 'delete') {
        $item_id = (int)$_POST['item_id'];
        [$status] = api_request('DELETE', $api_url . '/items/' . $item_id);
        if ($status >= 200 && $status < 300) {
            header("Location: index.php?tab=list&msg=deleted");
            exit;
        }
        header("Location: index.php?tab=list&msg=error");
        exit;
    }

    if ($action === 'bulk_delete') {
        $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
        [$status] = api_request('POST', $api_url . '/items/bulk/delete', ["ids" => $ids]);
        if ($status >= 200 && $status < 300) {
            header("Location: index.php?tab=list&msg=bulk_deleted");
            exit;
        }
        header("Location: index.php?tab=list&msg=error");
        exit;
    }

    if ($action === 'bulk_update') {
        $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
        $payload = ["ids" => $ids];

        if (isset($_POST['bulk_category']) && $_POST['bulk_category'] !== '') {
            $payload['category'] = trim($_POST['bulk_category']);
        }
        if (isset($_POST['bulk_status']) && $_POST['bulk_status'] !== '') {
            $payload['status'] = trim($_POST['bulk_status']);
        }

        [$status] = api_request('POST', $api_url . '/items/bulk/update', $payload);
        if ($status >= 200 && $status < 300) {
            header("Location: index.php?tab=list&msg=bulk_updated");
            exit;
        }
        header("Location: index.php?tab=list&msg=error");
        exit;
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'created') {
        $message = 'Запис успішно створено';
    } elseif ($_GET['msg'] === 'updated') {
        $message = 'Запис успішно оновлено';
    } elseif ($_GET['msg'] === 'deleted') {
        $message = 'Запис успішно видалено';
    } elseif ($_GET['msg'] === 'bulk_deleted') {
        $message = 'Виділені записи успішно видалено';
    } elseif ($_GET['msg'] === 'bulk_updated') {
        $message = 'Виділені записи успішно оновлено';
    } elseif ($_GET['msg'] === 'error') {
        $message = 'Сталася помилка при виконанні операції';
        $message_type = 'error';
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';

$query_params = [];
if ($search !== '') {
    $query_params['search'] = $search;
}
if ($category_filter !== '') {
    $query_params['category'] = $category_filter;
}

$fetch_url = $api_url . '/items/';
if (!empty($query_params)) {
    $fetch_url .= '?' . http_build_query($query_params);
}

[$get_status, $response] = api_request('GET', $fetch_url);
$items = [];
if ($get_status >= 200 && $get_status < 300 && $response !== false) {
    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        $items = $decoded;
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Облік Майна Установи</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Система обліку майна установи</h1>
        </div>

        <div class="tabs">
            <a class="tab-link <?php echo $active_tab === 'create' ? 'active' : ''; ?>" href="index.php?tab=create">Створення запису</a>
            <a class="tab-link <?php echo $active_tab === 'list' ? 'active' : ''; ?>" href="index.php?tab=list">Список майна</a>
        </div>

        <div class="content">
            <?php if ($message !== ''): ?>
                <div class="alert <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($active_tab === 'create'): ?>
                <h2>Додати новий об'єкт майна</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <div>
                            <label for="name">Назва</label>
                            <input id="name" type="text" name="name" required>
                        </div>
                        <div>
                            <label for="category">Категорія</label>
                            <select id="category" name="category" required>
                                <option value="Основні засоби">Основні засоби</option>
                                <option value="Оборотні активи">Оборотні активи</option>
                                <option value="Нематеріальні активи">Нематеріальні активи</option>
                            </select>
                        </div>
                        <div>
                            <label for="status">Стан</label>
                            <input id="status" type="text" name="status" required>
                        </div>
                        <div>
                            <label for="quantity">Кількість</label>
                            <input id="quantity" type="number" min="1" value="1" name="quantity" required>
                        </div>
                        <div class="full">
                            <label for="description">Опис</label>
                            <input id="description" type="text" name="description">
                        </div>
                    </div>
                    <div style="margin-top: 12px;">
                        <button class="btn success" type="submit">Створити</button>
                    </div>
                </form>
            <?php else: ?>
                <h2>Список майна</h2>

                <form method="GET" class="toolbar">
                    <input type="hidden" name="tab" value="list">
                    <div class="field">
                        <label for="search">Пошук</label>
                        <input id="search" type="text" name="search" placeholder="Пошук за назвою" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="field">
                        <label for="category_filter">Категорія</label>
                        <select id="category_filter" name="category_filter">
                            <option value="">Усі категорії</option>
                            <option value="Основні засоби" <?php echo $category_filter === 'Основні засоби' ? 'selected' : ''; ?>>Основні засоби</option>
                            <option value="Оборотні активи" <?php echo $category_filter === 'Оборотні активи' ? 'selected' : ''; ?>>Оборотні активи</option>
                            <option value="Нематеріальні активи" <?php echo $category_filter === 'Нематеріальні активи' ? 'selected' : ''; ?>>Нематеріальні активи</option>
                        </select>
                    </div>
                    <div>
                        <button class="btn" type="submit">Застосувати</button>
                    </div>
                    <div>
                        <a class="btn muted" href="index.php?tab=list" style="text-decoration:none;display:inline-block;">Скинути</a>
                    </div>
                </form>

                <form method="POST" id="bulkForm">
                    <input type="hidden" name="action" id="bulkAction" value="">
                    <input type="hidden" name="bulk_category" id="bulkCategoryInput" value="">
                    <input type="hidden" name="bulk_status" id="bulkStatusInput" value="">

                    <div class="toolbar" style="margin: 10px 0 12px;">
                        <button type="button" class="btn danger" onclick="openBulkDeleteModal()">Видалити вибрані</button>
                        <button type="button" class="btn" onclick="openBulkUpdateModal()">Оновити вибрані</button>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>ID</th>
                                <th>Назва</th>
                                <th>Категорія</th>
                                <th>Стан</th>
                                <th>Кількість</th>
                                <th>Опис</th>
                                <th>Дії</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_ids[]" value="<?php echo (int)$item['id']; ?>" class="row-checkbox">
                                        </td>
                                        <td><?php echo (int)$item['id']; ?></td>
                                        <td><?php echo htmlspecialchars($item['name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td><?php echo htmlspecialchars($item['status']); ?></td>
                                        <td><?php echo (int)$item['quantity']; ?></td>
                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                        <td>
                                            <div class="actions">
                                                <button
                                                    type="button"
                                                    class="btn"
                                                    data-id="<?php echo (int)$item['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($item['name']); ?>"
                                                    data-category="<?php echo htmlspecialchars($item['category']); ?>"
                                                    data-status="<?php echo htmlspecialchars($item['status']); ?>"
                                                    data-quantity="<?php echo (int)$item['quantity']; ?>"
                                                    data-description="<?php echo htmlspecialchars($item['description']); ?>"
                                                    onclick="openEditModal(this)">
                                                    Редагувати
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn danger"
                                                    data-id="<?php echo (int)$item['id']; ?>"
                                                    onclick="openDeleteModal(this)">
                                                    Видалити
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align: center;">Немає даних або немає з'єднання з API</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <h3>Видалення запису</h3>
            <p>Ви впевнені, що хочете видалити цей запис?</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="item_id" id="delete_item_id" value="">
                <div class="modal-actions">
                    <button type="button" class="btn muted" onclick="closeModal('deleteModal')">Скасувати</button>
                    <button type="submit" class="btn danger">Видалити</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <h3>Редагування запису</h3>
            <form method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="item_id" id="edit_item_id" value="">
                <div class="form-grid">
                    <div>
                        <label for="edit_name">Назва</label>
                        <input id="edit_name" type="text" name="name" required>
                    </div>
                    <div>
                        <label for="edit_category">Категорія</label>
                        <select id="edit_category" name="category" required>
                            <option value="Основні засоби">Основні засоби</option>
                            <option value="Оборотні активи">Оборотні активи</option>
                            <option value="Нематеріальні активи">Нематеріальні активи</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit_status">Стан</label>
                        <input id="edit_status" type="text" name="status" required>
                    </div>
                    <div>
                        <label for="edit_quantity">Кількість</label>
                        <input id="edit_quantity" type="number" min="1" name="quantity" required>
                    </div>
                    <div class="full">
                        <label for="edit_description">Опис</label>
                        <input id="edit_description" type="text" name="description">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn muted" onclick="closeModal('editModal')">Скасувати</button>
                    <button type="submit" class="btn success">Зберегти</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="bulkDeleteModal">
        <div class="modal">
            <h3>Масове видалення</h3>
            <p>Ви впевнені, що хочете видалити всі вибрані записи?</p>
            <div class="modal-actions">
                <button type="button" class="btn muted" onclick="closeModal('bulkDeleteModal')">Скасувати</button>
                <button type="button" class="btn danger" onclick="submitBulkDelete()">Видалити вибрані</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="bulkUpdateModal">
        <div class="modal">
            <h3>Масове редагування</h3>
            <p>Заповніть поля, які потрібно змінити для вибраних записів.</p>
            <div class="form-grid">
                <div>
                    <label for="bulk_category">Категорія</label>
                    <select id="bulk_category">
                        <option value="">Не змінювати</option>
                        <option value="Основні засоби">Основні засоби</option>
                        <option value="Оборотні активи">Оборотні активи</option>
                        <option value="Нематеріальні активи">Нематеріальні активи</option>
                    </select>
                </div>
                <div>
                    <label for="bulk_status">Стан</label>
                    <input id="bulk_status" type="text" placeholder="Не змінювати">
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn muted" onclick="closeModal('bulkUpdateModal')">Скасувати</button>
                <button type="button" class="btn" onclick="submitBulkUpdate()">Оновити вибрані</button>
            </div>
        </div>
    </div>

    <script>
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function openModal(id) {
            document.getElementById(id).style.display = 'flex';
        }

        function openDeleteModal(button) {
            var id = button.getAttribute('data-id');
            document.getElementById('delete_item_id').value = id;
            openModal('deleteModal');
        }

        function openEditModal(button) {
            document.getElementById('edit_item_id').value = button.getAttribute('data-id');
            document.getElementById('edit_name').value = button.getAttribute('data-name');
            document.getElementById('edit_category').value = button.getAttribute('data-category');
            document.getElementById('edit_status').value = button.getAttribute('data-status');
            document.getElementById('edit_quantity').value = button.getAttribute('data-quantity');
            document.getElementById('edit_description').value = button.getAttribute('data-description');
            openModal('editModal');
        }

        function getSelectedCount() {
            return document.querySelectorAll('.row-checkbox:checked').length;
        }

        function openBulkDeleteModal() {
            if (getSelectedCount() === 0) {
                alert('Оберіть щонайменше один запис');
                return;
            }
            openModal('bulkDeleteModal');
        }

        function openBulkUpdateModal() {
            if (getSelectedCount() === 0) {
                alert('Оберіть щонайменше один запис');
                return;
            }
            openModal('bulkUpdateModal');
        }

        function submitBulkDelete() {
            document.getElementById('bulkAction').value = 'bulk_delete';
            document.getElementById('bulkForm').submit();
        }

        function submitBulkUpdate() {
            document.getElementById('bulkCategoryInput').value = document.getElementById('bulk_category').value;
            document.getElementById('bulkStatusInput').value = document.getElementById('bulk_status').value;
            document.getElementById('bulkAction').value = 'bulk_update';
            document.getElementById('bulkForm').submit();
        }

        var selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = this.checked;
                document.querySelectorAll('.row-checkbox').forEach(function (box) {
                    box.checked = checked;
                });
            });
        }

        document.querySelectorAll('.modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) {
                    overlay.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>
