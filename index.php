<?php
declare(strict_types=1);

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$employeeFile = $storageDir . DIRECTORY_SEPARATOR . 'employees.json';
$taskFile = $storageDir . DIRECTORY_SEPARATOR . 'tasks.json';

function ensureStorage(array $files): void
{
	if (!is_dir(__DIR__ . DIRECTORY_SEPARATOR . 'data')) {
		mkdir(__DIR__ . DIRECTORY_SEPARATOR . 'data', 0777, true);
	}

	foreach ($files as $file) {
		if (!file_exists($file)) {
			file_put_contents($file, '[]', LOCK_EX);
		}
	}
}

function readJsonRows(string $file): array
{
	$raw = file_get_contents($file);
	if ($raw === false || trim($raw) === '') {
		return [];
	}

	$rows = json_decode($raw, true);
	if (!is_array($rows)) {
		return [];
	}

	return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
}

function writeJsonRows(string $file, array $rows): bool
{
	$json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($json === false) {
		return false;
	}

	return file_put_contents($file, $json, LOCK_EX) !== false;
}

function employeeNameMap(array $employees): array
{
	$map = [];
	foreach ($employees as $employee) {
		$id = (string)($employee['id'] ?? '');
		if ($id === '') {
			continue;
		}
		$map[$id] = (string)($employee['employee_name'] ?? '');
	}

	return $map;
}

function sanitizeCleanerIds($rawCleanerIds, array $validIds): array
{
	if (!is_array($rawCleanerIds)) {
		$rawCleanerIds = $rawCleanerIds !== null && $rawCleanerIds !== '' ? [(string)$rawCleanerIds] : [];
	}

	$cleanerIds = [];
	foreach ($rawCleanerIds as $id) {
		$id = trim((string)$id);
		if ($id !== '' && in_array($id, $validIds, true)) {
			$cleanerIds[] = $id;
		}
	}

	return array_values(array_unique($cleanerIds));
}

ensureStorage([$employeeFile, $taskFile]);

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action !== null) {
	header('Content-Type: application/json; charset=utf-8');

	if ($action === 'employee_list') {
		echo json_encode(['data' => readJsonRows($employeeFile)]);
		exit;
	}

	if ($action === 'employee_add') {
		$name = trim((string)($_POST['employee_name'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));
		$status = trim((string)($_POST['status'] ?? ''));

		if ($name === '' || $location === '' || $status === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'All employee fields are required.']);
			exit;
		}

		$employees = readJsonRows($employeeFile);
		$employees[] = [
			'id' => uniqid('emp_', true),
			'employee_name' => $name,
			'location' => $location,
			'status' => $status,
		];

		if (!writeJsonRows($employeeFile, $employees)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Unable to save employee data.']);
			exit;
		}

		echo json_encode(['success' => true]);
		exit;
	}

	if ($action === 'employee_update') {
		$id = trim((string)($_POST['id'] ?? ''));
		$name = trim((string)($_POST['employee_name'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));
		$status = trim((string)($_POST['status'] ?? ''));

		if ($id === '' || $name === '' || $location === '' || $status === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'All employee fields are required.']);
			exit;
		}

		$employees = readJsonRows($employeeFile);
		$updated = false;
		foreach ($employees as &$employee) {
			if (($employee['id'] ?? '') === $id) {
				$employee['employee_name'] = $name;
				$employee['location'] = $location;
				$employee['status'] = $status;
				$updated = true;
				break;
			}
		}
		unset($employee);

		if (!$updated) {
			http_response_code(404);
			echo json_encode(['success' => false, 'message' => 'Employee record not found.']);
			exit;
		}

		if (!writeJsonRows($employeeFile, $employees)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Unable to update employee data.']);
			exit;
		}

		echo json_encode(['success' => true]);
		exit;
	}

	if ($action === 'task_list') {
		$employees = readJsonRows($employeeFile);
		$nameMap = employeeNameMap($employees);
		$tasks = readJsonRows($taskFile);

		foreach ($tasks as &$task) {
			$cleanerIds = isset($task['cleaner_ids']) && is_array($task['cleaner_ids']) ? $task['cleaner_ids'] : [];
			$cleanerNames = [];
			foreach ($cleanerIds as $id) {
				if (isset($nameMap[$id])) {
					$cleanerNames[] = $nameMap[$id];
				}
			}
			$task['cleaner_ids'] = $cleanerIds;
			$task['cleaners'] = count($cleanerNames) > 0 ? implode(', ', $cleanerNames) : 'Unassigned';
		}
		unset($task);

		echo json_encode(['data' => $tasks]);
		exit;
	}

	if ($action === 'task_add') {
		$taskNo = trim((string)($_POST['task_no'] ?? ''));
		$taskName = trim((string)($_POST['task_name'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));

		if ($taskNo === '' || $taskName === '' || $location === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task no., task name, and location are required.']);
			exit;
		}

		$employees = readJsonRows($employeeFile);
		$validIds = array_map(static fn($employee): string => (string)($employee['id'] ?? ''), $employees);
		$cleanerIds = sanitizeCleanerIds($_POST['cleaners'] ?? [], $validIds);

		$manilaNow = new DateTime('now', new DateTimeZone('Asia/Manila'));

		$tasks = readJsonRows($taskFile);
		$tasks[] = [
			'id' => uniqid('task_', true),
			'task_no' => $taskNo,
			'task_name' => $taskName,
			'location' => $location,
			'created_at' => $manilaNow->format('Y-m-d h:i A'),
			'cleaner_ids' => $cleanerIds,
		];

		if (!writeJsonRows($taskFile, $tasks)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Unable to save task data.']);
			exit;
		}

		echo json_encode(['success' => true]);
		exit;
	}

	if ($action === 'task_update') {
		$id = trim((string)($_POST['id'] ?? ''));
		$taskNo = trim((string)($_POST['task_no'] ?? ''));
		$taskName = trim((string)($_POST['task_name'] ?? ''));
		$location = trim((string)($_POST['location'] ?? ''));

		if ($id === '' || $taskNo === '' || $taskName === '' || $location === '') {
			http_response_code(422);
			echo json_encode(['success' => false, 'message' => 'Task fields are required.']);
			exit;
		}

		$employees = readJsonRows($employeeFile);
		$validIds = array_map(static fn($employee): string => (string)($employee['id'] ?? ''), $employees);
		$cleanerIds = sanitizeCleanerIds($_POST['cleaners'] ?? [], $validIds);

		$tasks = readJsonRows($taskFile);
		$updated = false;

		foreach ($tasks as &$task) {
			if (($task['id'] ?? '') === $id) {
				$task['task_no'] = $taskNo;
				$task['task_name'] = $taskName;
				$task['location'] = $location;
				$task['cleaner_ids'] = $cleanerIds;
				$updated = true;
				break;
			}
		}
		unset($task);

		if (!$updated) {
			http_response_code(404);
			echo json_encode(['success' => false, 'message' => 'Task record not found.']);
			exit;
		}

		if (!writeJsonRows($taskFile, $tasks)) {
			http_response_code(500);
			echo json_encode(['success' => false, 'message' => 'Unable to update task data.']);
			exit;
		}

		echo json_encode(['success' => true]);
		exit;
	}

	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'Invalid action.']);
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Task and Employee Board</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<style>
		:root {
			--page-bg: #eef3f8;
			--panel-bg: #ffffff;
			--primary: #0f4c81;
			--muted-text: #5e6977;
		}

		body {
			margin: 0;
			min-height: 100vh;
			font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
			background: linear-gradient(160deg, #f8fbff 0%, var(--page-bg) 100%);
		}

		.page-wrap {
			width: min(1400px, 96vw);
			margin: 28px auto;
		}

		.split-layout {
			display: grid;
			grid-template-columns: 7fr 3fr;
			gap: 16px;
			align-items: start;
		}

		.panel-card {
			background: var(--panel-bg);
			border-radius: 16px;
			box-shadow: 0 12px 28px rgba(15, 76, 129, 0.1);
			border: 1px solid #dfe7f0;
		}

		.panel-card .card-body {
			padding: 20px;
		}

		.section-title {
			font-weight: 700;
			margin-bottom: 2px;
		}

		.section-caption {
			color: var(--muted-text);
			font-size: 0.92rem;
		}

		.btn-primary {
			background: var(--primary);
			border-color: var(--primary);
		}

		.btn-primary:hover,
		.btn-primary:focus {
			background: #0b3d68;
			border-color: #0b3d68;
		}

		.status-pill {
			display: inline-block;
			padding: 4px 9px;
			font-size: 12px;
			border-radius: 999px;
			background: #e7f0fb;
			color: #0b4170;
			font-weight: 600;
		}

		.action-icon {
			width: 32px;
			height: 32px;
			padding: 0;
			display: inline-flex;
			align-items: center;
			justify-content: center;
		}

		@media (max-width: 992px) {
			.split-layout {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
	<div class="page-wrap">
		<div class="split-layout">
			<div class="panel-card">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-center mb-3">
						<div>
							<h3 class="section-title">Task Container</h3>
							<p class="section-caption mb-0">70% width area for task monitoring and cleaner assignment.</p>
						</div>
						<button type="button" class="btn btn-primary" id="btnAddTask">Add Task</button>
					</div>
					<div class="table-responsive">
						<table id="taskTable" class="table table-striped align-middle w-100">
							<thead>
								<tr>
									<th>Task No.</th>
									<th>Task Name</th>
									<th>Location</th>
									<th>Date &amp; Time Created (Manila)</th>
									<th>Cleaners</th>
									<th style="width: 90px;">Action</th>
								</tr>
							</thead>
						</table>
					</div>
				</div>
			</div>

			<div class="panel-card">
				<div class="card-body">
					<div class="d-flex justify-content-between align-items-center mb-3">
						<div>
							<h3 class="section-title">Employee Container</h3>
							<p class="section-caption mb-0">30% right-side list for cleaner assignment.</p>
						</div>
						<button type="button" class="btn btn-primary btn-sm" id="btnAddEmployee">Add</button>
					</div>
					<div class="table-responsive">
						<table id="employeeTable" class="table table-sm table-striped align-middle w-100">
							<thead>
								<tr>
									<th>Employee Name</th>
									<th>Location</th>
									<th>Status</th>
									<th style="width: 70px;">Action</th>
								</tr>
							</thead>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="modal fade" id="taskModal" tabindex="-1" aria-labelledby="taskModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-lg modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="taskModalLabel">Add Task</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<form id="taskForm">
					<div class="modal-body">
						<input type="hidden" id="taskId" name="id">
						<div class="row g-3">
							<div class="col-md-4">
								<label for="taskNo" class="form-label">Task No.</label>
								<input type="text" class="form-control" id="taskNo" name="task_no" required>
							</div>
							<div class="col-md-8">
								<label for="taskName" class="form-label">Task Name</label>
								<input type="text" class="form-control" id="taskName" name="task_name" required>
							</div>
							<div class="col-md-6">
								<label for="taskLocation" class="form-label">Location</label>
								<input type="text" class="form-control" id="taskLocation" name="location" required>
							</div>
							<div class="col-md-6">
								<label for="taskCleaners" class="form-label">Assign Employee/Cleaners</label>
								<select class="form-select" id="taskCleaners" name="cleaners[]" multiple size="5"></select>
								<div class="form-text">Hold Ctrl key to select multiple cleaners.</div>
							</div>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary" id="btnSaveTask">Save</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<div class="modal fade" id="employeeModal" tabindex="-1" aria-labelledby="employeeModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="employeeModalLabel">Add Employee</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<form id="employeeForm">
					<div class="modal-body">
						<input type="hidden" id="employeeId" name="id">
						<div class="mb-3">
							<label for="employeeName" class="form-label">Employee Name</label>
							<input type="text" class="form-control" id="employeeName" name="employee_name" required>
						</div>
						<div class="mb-3">
							<label for="employeeLocation" class="form-label">Location</label>
							<input type="text" class="form-control" id="employeeLocation" name="location" required>
						</div>
						<div class="mb-0">
							<label for="employeeStatus" class="form-label">Status</label>
							<select class="form-select" id="employeeStatus" name="status" required>
								<option value="">Select status</option>
								<option value="Active">Active</option>
								<option value="Inactive">Inactive</option>
								<option value="On Leave">On Leave</option>
							</select>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" class="btn btn-primary" id="btnSaveEmployee">Save</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
	<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
	<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>
	<script>
		const endpoint = 'index.php';

		const taskModal = new bootstrap.Modal(document.getElementById('taskModal'));
		const employeeModal = new bootstrap.Modal(document.getElementById('employeeModal'));

		const taskForm = document.getElementById('taskForm');
		const taskIdField = document.getElementById('taskId');
		const taskNoField = document.getElementById('taskNo');
		const taskNameField = document.getElementById('taskName');
		const taskLocationField = document.getElementById('taskLocation');
		const taskCleanersField = document.getElementById('taskCleaners');
		const taskModalLabel = document.getElementById('taskModalLabel');
		const btnSaveTask = document.getElementById('btnSaveTask');

		const employeeForm = document.getElementById('employeeForm');
		const employeeIdField = document.getElementById('employeeId');
		const employeeNameField = document.getElementById('employeeName');
		const employeeLocationField = document.getElementById('employeeLocation');
		const employeeStatusField = document.getElementById('employeeStatus');
		const employeeModalLabel = document.getElementById('employeeModalLabel');
		const btnSaveEmployee = document.getElementById('btnSaveEmployee');

		const taskTable = $('#taskTable').DataTable({
			ajax: endpoint + '?action=task_list',
			columns: [
				{ data: 'task_no' },
				{ data: 'task_name' },
				{ data: 'location' },
				{ data: 'created_at' },
				{ data: 'cleaners' },
				{
					data: null,
					orderable: false,
					searchable: false,
					render: function () {
						return '<button type="button" class="btn btn-outline-primary action-icon btn-task-edit" title="Update Task"><i class="bi bi-pencil-square"></i></button>';
					}
				}
			]
		});

		const employeeTable = $('#employeeTable').DataTable({
			ajax: endpoint + '?action=employee_list',
			paging: true,
			columns: [
				{ data: 'employee_name' },
				{ data: 'location' },
				{
					data: 'status',
					render: function (data) {
						return '<span class="status-pill">' + data + '</span>';
					}
				},
				{
					data: null,
					orderable: false,
					searchable: false,
					render: function () {
						return '<button type="button" class="btn btn-outline-primary action-icon btn-employee-edit" title="Update Employee"><i class="bi bi-pencil-square"></i></button>';
					}
				}
			]
		});

		function fillCleanerOptions(selectedIds = []) {
			const employees = employeeTable.rows().data().toArray();
			taskCleanersField.innerHTML = '';

			if (employees.length === 0) {
				const option = document.createElement('option');
				option.disabled = true;
				option.textContent = 'No employees yet';
				taskCleanersField.appendChild(option);
				return;
			}

			employees.forEach(function (employee) {
				const option = document.createElement('option');
				option.value = employee.id;
				option.textContent = employee.employee_name + ' (' + employee.location + ')';
				if (selectedIds.includes(employee.id)) {
					option.selected = true;
				}
				taskCleanersField.appendChild(option);
			});
		}

		function resetTaskForm() {
			taskForm.reset();
			taskIdField.value = '';
			taskModalLabel.textContent = 'Add Task';
			btnSaveTask.textContent = 'Save';
			fillCleanerOptions([]);
		}

		function resetEmployeeForm() {
			employeeForm.reset();
			employeeIdField.value = '';
			employeeModalLabel.textContent = 'Add Employee';
			btnSaveEmployee.textContent = 'Save';
		}

		document.getElementById('btnAddTask').addEventListener('click', function () {
			resetTaskForm();
			taskModal.show();
		});

		document.getElementById('btnAddEmployee').addEventListener('click', function () {
			resetEmployeeForm();
			employeeModal.show();
		});

		document.querySelector('#taskTable').addEventListener('click', function (event) {
			const btn = event.target.closest('.btn-task-edit');
			if (!btn) {
				return;
			}

			const clickedTr = btn.closest('tr');
			if (!clickedTr) {
				return;
			}

			let row = taskTable.row(clickedTr).data();
			if (!row && clickedTr.previousElementSibling) {
				row = taskTable.row(clickedTr.previousElementSibling).data();
			}

			if (!row) {
				return;
			}

			taskIdField.value = row.id;
			taskNoField.value = row.task_no || '';
			taskNameField.value = row.task_name || '';
			taskLocationField.value = row.location || '';
			fillCleanerOptions(Array.isArray(row.cleaner_ids) ? row.cleaner_ids : []);
			taskModalLabel.textContent = 'Update Task';
			btnSaveTask.textContent = 'Update';
			taskModal.show();
		});

		document.querySelector('#employeeTable').addEventListener('click', function (event) {
			const btn = event.target.closest('.btn-employee-edit');
			if (!btn) {
				return;
			}

			const clickedTr = btn.closest('tr');
			if (!clickedTr) {
				return;
			}

			let row = employeeTable.row(clickedTr).data();
			if (!row && clickedTr.previousElementSibling) {
				row = employeeTable.row(clickedTr.previousElementSibling).data();
			}

			if (!row) {
				return;
			}

			employeeIdField.value = row.id;
			employeeNameField.value = row.employee_name || '';
			employeeLocationField.value = row.location || '';
			employeeStatusField.value = row.status || '';
			employeeModalLabel.textContent = 'Update Employee';
			btnSaveEmployee.textContent = 'Update';
			employeeModal.show();
		});

		employeeTable.on('draw', function () {
			if (!taskIdField.value) {
				fillCleanerOptions([]);
			}
		});

		taskForm.addEventListener('submit', async function (event) {
			event.preventDefault();
			const formData = new FormData(taskForm);
			formData.append('action', taskIdField.value ? 'task_update' : 'task_add');
			btnSaveTask.disabled = true;

			try {
				const response = await fetch(endpoint, { method: 'POST', body: formData });
				const result = await response.json();
				if (!response.ok || !result.success) {
					throw new Error(result.message || 'Task request failed.');
				}

				taskModal.hide();
				taskTable.ajax.reload(null, false);
			} catch (error) {
				alert(error.message);
			} finally {
				btnSaveTask.disabled = false;
			}
		});

		employeeForm.addEventListener('submit', async function (event) {
			event.preventDefault();
			const formData = new FormData(employeeForm);
			formData.append('action', employeeIdField.value ? 'employee_update' : 'employee_add');
			btnSaveEmployee.disabled = true;

			try {
				const response = await fetch(endpoint, { method: 'POST', body: formData });
				const result = await response.json();
				if (!response.ok || !result.success) {
					throw new Error(result.message || 'Employee request failed.');
				}

				employeeModal.hide();
				employeeTable.ajax.reload(null, false);
				taskTable.ajax.reload(null, false);
			} catch (error) {
				alert(error.message);
			} finally {
				btnSaveEmployee.disabled = false;
			}
		});
	</script>
</body>
</html>
