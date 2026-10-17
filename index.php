<?php
declare(strict_types=1);

session_start();

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensureCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function validateCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';

    return hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $token);
}

$students = [];
$errorMessage = '';
$messages = [];
$editStudent = null;
$formMasv = '';
$formTensv = '';
$searchName = trim((string) ($_GET['search'] ?? ''));

try {
    $config = require __DIR__ . '/config/database.php';
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['database']
    );

    $connection = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!validateCsrf()) {
            $messages[] = ['type' => 'danger', 'text' => 'Mã bảo mật không hợp lệ. Vui lòng thử lại.'];
        } else {
            $action = (string) $_POST['action'];
            $masv = trim((string) ($_POST['masv'] ?? ''));
            $tensv = trim((string) ($_POST['tensv'] ?? ''));

            if ($action === 'add' || $action === 'update') {
                if ($masv === '' || $tensv === '') {
                    $messages[] = ['type' => 'warning', 'text' => 'Mã sinh viên và tên sinh viên không được để trống.'];
                } else {
                    try {
                        if ($action === 'add') {
                            $statement = $connection->prepare('INSERT INTO sv (masv, tensv) VALUES (:masv, :tensv)');
                            $statement->execute(['masv' => $masv, 'tensv' => $tensv]);
                            $messages[] = ['type' => 'success', 'text' => 'Thêm sinh viên thành công.'];
                        } else {
                            $oldMasv = trim((string) ($_POST['old_masv'] ?? ''));
                            if ($oldMasv === '') {
                                $messages[] = ['type' => 'danger', 'text' => 'Không xác định được sinh viên cần cập nhật.'];
                            } else {
                                $statement = $connection->prepare('UPDATE sv SET masv = :new_masv, tensv = :tensv WHERE masv = :old_masv');
                                $statement->execute([
                                    'new_masv' => $masv,
                                    'tensv' => $tensv,
                                    'old_masv' => $oldMasv,
                                ]);
                                $messages[] = ['type' => 'success', 'text' => 'Cập nhật sinh viên thành công.'];
                            }
                        }
                    } catch (PDOException $exception) {
                        $messages[] = ['type' => 'danger', 'text' => 'Không thể lưu thông tin sinh viên. Mã sinh viên có thể đã tồn tại.'];
                    }
                }
            }

            if ($action === 'delete') {
                $masv = trim((string) ($_POST['masv'] ?? ''));

                if ($masv === '') {
                    $messages[] = ['type' => 'warning', 'text' => 'Không tìm thấy mã sinh viên cần xóa.'];
                } else {
                    try {
                        $statement = $connection->prepare('DELETE FROM sv WHERE masv = :masv');
                        $statement->execute(['masv' => $masv]);
                        $messages[] = ['type' => 'success', 'text' => 'Xóa sinh viên thành công.'];
                    } catch (PDOException $exception) {
                        $messages[] = ['type' => 'danger', 'text' => 'Không thể xóa sinh viên này.'];
                    }
                }
            }
        }
    }

    if (isset($_GET['edit']) && $_GET['edit'] !== '') {
        $studentId = trim((string) $_GET['edit']);
        $statement = $connection->prepare('SELECT masv, tensv FROM sv WHERE masv = :masv LIMIT 1');
        $statement->execute(['masv' => $studentId]);
        $editStudent = $statement->fetch();

        if ($editStudent !== false) {
            $formMasv = (string) $editStudent['masv'];
            $formTensv = (string) $editStudent['tensv'];
        }
    }

    if ($searchName === '') {
        $students = $connection->query('SELECT masv, tensv FROM sv ORDER BY masv')->fetchAll();
    } else {
        $statement = $connection->prepare("SELECT masv, tensv FROM sv WHERE tensv LIKE :tensv ESCAPE '!' ORDER BY masv");
        $escapedSearchName = strtr($searchName, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $statement->execute(['tensv' => '%' . $escapedSearchName . '%']);
        $students = $statement->fetchAll();
    }
} catch (PDOException) {
    http_response_code(503);
    $errorMessage = 'Không thể kết nối hoặc đọc dữ liệu từ database qlsv trên máy chủ MySQL. '
        . 'Vui lòng kiểm tra máy chủ MySQL 192.168.1.5, cổng 3306, quyền truy cập từ xa, '
        . 'tường lửa và cấu hình tài khoản.';
}

$csrfToken = ensureCsrfToken();
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Danh sách sinh viên trong hệ thống quản lý sinh viên QLSV.">
    <title>Quản lý sinh viên | QLSV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <style>
        body {
            min-height: 100vh;
            background: #f4f7fb;
        }

        .navbar-brand {
            letter-spacing: .02em;
        }

        .page-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 .5rem 2rem rgb(30 58 95 / 8%);
        }

        .table thead th {
            color: #475569;
            font-size: .8rem;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .action-btn {
            min-width: 78px;
        }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">QLSV <span class="fw-normal opacity-75">| Quản lý sinh viên</span></a>
    </div>
</nav>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
                <div>
                    <h1 class="h2 fw-bold mb-2">Danh sách sinh viên</h1>
                    <p class="text-secondary mb-0">Quản lý thông tin sinh viên và thao tác thêm, sửa, xóa.</p>
                </div>
                <a href="index.php" class="btn btn-outline-primary">Làm mới</a>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                    <span class="fw-bold" aria-hidden="true">!</span>
                    <div>
                        <div class="fw-semibold">Không tải được danh sách sinh viên</div>
                        <div><?= escape($errorMessage) ?></div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-<?= escape((string) $message['type']) ?> alert-dismissible fade show" role="alert">
                        <?= escape((string) $message['text']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endforeach; ?>

                <section class="card page-card mb-4">
                    <div class="card-header bg-white border-0 px-4 pt-4 pb-3">
                        <h2 class="h5 fw-semibold mb-0"><?= $editStudent !== false && $editStudent !== null ? 'Chỉnh sửa sinh viên' : 'Thêm sinh viên mới' ?></h2>
                    </div>
                    <div class="card-body px-4 pb-4 pt-0">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                            <input type="hidden" name="action" value="<?= $editStudent !== false && $editStudent !== null ? 'update' : 'add' ?>">
                            <?php if ($editStudent !== false && $editStudent !== null): ?>
                                <input type="hidden" name="old_masv" value="<?= escape((string) $editStudent['masv']) ?>">
                            <?php endif; ?>

                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label for="studentMasv" class="form-label fw-medium">Mã sinh viên</label>
                                    <input id="studentMasv" type="text" name="masv" class="form-control" value="<?= escape($editStudent !== false && $editStudent !== null ? (string) $editStudent['masv'] : $formMasv) ?>" placeholder="Ví dụ: SV001" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="studentName" class="form-label fw-medium">Tên sinh viên</label>
                                    <input id="studentName" type="text" name="tensv" class="form-control" value="<?= escape($editStudent !== false && $editStudent !== null ? (string) $editStudent['tensv'] : $formTensv) ?>" placeholder="Nhập tên sinh viên" required>
                                </div>
                                <div class="col-md-2 d-flex gap-2">
                                    <button type="submit" class="btn btn-primary flex-fill"><?= $editStudent !== false && $editStudent !== null ? 'Lưu' : 'Thêm' ?></button>
                                    <?php if ($editStudent !== false && $editStudent !== null): ?>
                                        <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>

                <section class="card page-card" aria-labelledby="student-list-heading">
                    <div class="card-header bg-white border-0 px-4 pt-4 pb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h2 class="h5 fw-semibold mb-0" id="student-list-heading">Sinh viên</h2>
                        <div class="d-flex flex-wrap align-items-center gap-3">
                            <form method="get" class="d-flex gap-2" role="search">
                                <label for="studentSearch" class="visually-hidden">Tìm theo tên sinh viên</label>
                                <input id="studentSearch" type="search" name="search" class="form-control"
                                       value="<?= escape($searchName) ?>" placeholder="Tên sinh viên">
                                <button type="submit" class="btn btn-outline-primary">Tìm kiếm</button>
                                <?php if ($searchName !== ''): ?>
                                    <a href="index.php" class="btn btn-outline-secondary">Xóa tìm kiếm</a>
                                <?php endif; ?>
                            </form>
                            <span class="badge text-bg-primary rounded-pill">
                                <?= count($students) ?> sinh viên
                            </span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($students === []): ?>
                            <p class="text-secondary text-center px-4 py-5 mb-0"><?= $searchName !== '' ? 'Không tìm thấy sinh viên phù hợp.' : 'Chưa có sinh viên trong hệ thống.' ?></p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-4">Mã sinh viên</th>
                                        <th scope="col">Tên sinh viên</th>
                                        <th scope="col" class="text-end pe-4">Thao tác</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td class="ps-4 fw-medium"><?= escape((string) $student['masv']) ?></td>
                                            <td><?= escape((string) $student['tensv']) ?></td>
                                            <td class="text-end pe-4">
                                                <div class="d-flex justify-content-end gap-2">
                                                    <a href="index.php?<?= escape(http_build_query(['edit' => (string) $student['masv'], 'search' => $searchName])) ?>" class="btn btn-sm btn-outline-primary action-btn">Sửa</a>
                                                    <form method="post" onsubmit="return confirm('Bạn có chắc chắn muốn xóa sinh viên <?= escape((string) $student['masv']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="masv" value="<?= escape((string) $student['masv']) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger action-btn">Xóa</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-white border-0 px-4 py-3">
                        <small class="text-secondary"><?= $searchName !== '' ? 'Kết quả tìm kiếm: ' : 'Tổng số: ' ?><?= count($students) ?> sinh viên</small>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js" integrity="sha384-1JzPzocQ4Jc64cdxPoYF9w6sL0JHxFQfUo7I7YhA+VhdnC4V8l3d8JrM+7Jm0x" crossorigin="anonymous"></script>
</body>
</html>
