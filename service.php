<?php
// Returns JSON responses for the shared POST-based API endpoint.
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    respondError("Only POST method allowed", 405);
}

// Accept JSON requests and fall back to form posts that use the same API fields.
$inputData = file_get_contents("php://input");
$request = json_decode($inputData, true);

if (!is_array($request)) {
    $request = $_POST;
}

try {
    $conn = connectDatabase();

    // Stop if the database does not exist
    ensureSchemaAvailable($conn);

    $action = trim((string)($request["action"] ?? ""));

    // Route the action to the matching handler.
    switch ($action) {
        case "check_registration":
            handleRegistrationCheck($conn, $request);
            break;
        case "register":
            handleRegister($conn, $request);
            break;
        case "login":
            handleLogin($conn, $request);
            break;
        case "list_courses":
            handleListCourses($conn, $request);
            break;
        case "create_course":
            handleCreateCourse($conn, $request);
            break;
        case "view_course":
            handleViewCourse($conn, $request);
            break;
        case "list_students":
            handleListStudents($conn, $request);
            break;
        case "search_student":
            handleSearchStudent($conn, $request);
            break;
        case "remove_student":
            handleRemoveStudent($conn, $request);
            break;
        case "enroll_course":
            handleEnrollCourse($conn, $request);
            break;
        case "list_classmates":
            handleListClassmates($conn, $request);
            break;
        case "search_classmate":
            handleSearchClassmate($conn, $request);
            break;
        case "unenroll_course":
            handleUnenrollCourse($conn, $request);
            break;
        default:
            respondError("Unknown action", 400, ["action" => $action]);
    }
} catch (Throwable $exception) {
    respondError("Server error: " . $exception->getMessage(), 500);
}

// Open database connection
function connectDatabase(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli("localhost", "root", "", "student");
    } catch (mysqli_sql_exception $exception) {
        throw new RuntimeException("Database setup is missing. Run student_schema.sql first or add it to the phpmyadmin.");
    }

    $conn->set_charset("utf8mb4");

    return $conn;
}

// Confirm the tables and columns expected by this API are present.
function ensureSchemaAvailable(mysqli $conn): void
{
    $requiredTables = ["teachers", "students", "courses", "course_students"];

    foreach ($requiredTables as $table) {
        if (!tableExists($conn, $table)) {
            throw new RuntimeException("Database table '{$table}' is missing. Run student_schema.sql first.");
        }
    }

}

// Check whether an account already exists before registration or login attempts.
function handleRegistrationCheck(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $isRegistered = findAccountByPublicId($conn, $accountId) === null ? "N" : "Y";

    respondSuccess("Registration status retrieved.", [
        "account_id" => $accountId,
        "is_registered" => $isRegistered
    ]);
}

// Register either a teacher or student account using the shared workflow.
function handleRegister(mysqli $conn, array $request): void
{
    $role = requireRole($request);
    $accountId = requireField($request, "account_id");
    $fullName = requireField($request, "full_name");
    $password = requireField($request, "password");

    ensureAccountDoesNotExist($conn, $accountId);

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($role === "teacher") {
        $stmt = $conn->prepare("INSERT INTO teachers (teacher_id, full_name, password_hash) VALUES (?, ?, ?)");
    } else {
        $stmt = $conn->prepare("INSERT INTO students (student_id, full_name, password_hash) VALUES (?, ?, ?)");
    }

    $stmt->bind_param("sss", $accountId, $fullName, $passwordHash);
    $stmt->execute();
    $stmt->close();

    respondSuccess("Account registered successfully.", [
        "role" => $role,
        "account_id" => $accountId,
        "full_name" => $fullName
    ]);
}

// Validate credentials and return the account profile plus visible courses.
function handleLogin(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $password = requireField($request, "password");
    $account = requireAccountCredentials($conn, $accountId, $password);

    respondSuccess("Login successful.", [
        "role" => $account["role"],
        "account_id" => $account["account_id"],
        "full_name" => $account["full_name"],
        "course_lines" => buildCourseLinesForAccount($conn, $account)
    ]);
}

// Return the courses visible to the requested teacher or student account.
function handleListCourses(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $account = requireAccountByPublicId($conn, $accountId);

    respondSuccess("Courses retrieved successfully.", [
        "role" => $account["role"],
        "account_id" => $account["account_id"],
        "full_name" => $account["full_name"],
        "course_lines" => buildCourseLinesForAccount($conn, $account)
    ]);
}

// Allow teachers to create a course and receive its generated join key.
function handleCreateCourse(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseCode = requireField($request, "course_code");
    $courseName = requireField($request, "course_name");
    $teacher = requireTeacherAccount($conn, $accountId);

    ensureValueDoesNotExist($conn, "courses", "course_code", $courseCode, "Course code already exists.");

    $courseKey = generateUniqueCourseKey($conn);
    $teacherRefId = (int)$teacher["id"];

    $stmt = $conn->prepare(
        "INSERT INTO courses (teacher_ref_id, course_code, course_name, course_key)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param("isss", $teacherRefId, $courseCode, $courseName, $courseKey);
    $stmt->execute();
    $courseId = (int)$stmt->insert_id;
    $stmt->close();

    $course = requireTeacherCourse($conn, $teacher["account_id"], $courseId);

    respondSuccess("Course created successfully.", [
        "detail_lines" => buildTeacherCourseDetailLines($course)
    ]);
}

// Return course details, using a teacher or student view depending on the caller..
function handleViewCourse(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $account = requireAccountByPublicId($conn, $accountId);

    if ($account["role"] === "teacher") {
        $course = requireTeacherCourse($conn, $account["account_id"], $courseId);
        $detailLines = buildTeacherCourseDetailLines($course);
    } else {
        $course = requireStudentCourse($conn, $account["account_id"], $courseId);
        $detailLines = buildStudentCourseDetailLines($course);
    }

    respondSuccess("Course details retrieved successfully.", [
        "role" => $account["role"],
        "detail_lines" => $detailLines
    ]);
}

// Let a teacher check the students currently enrolled in one of their courses.
function handleListStudents(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $teacher = requireTeacherAccount($conn, $accountId);
    $course = requireTeacherCourse($conn, $teacher["account_id"], $courseId);
    $studentLines = buildStudentLines(fetchStudentsInCourse($conn, $courseId));
    $message = empty($studentLines) ? "No student found." : "Students retrieved successfully.";

    respondSuccess($message, [
        "detail_lines" => buildTeacherCourseDetailLines($course),
        "student_lines" => $studentLines
    ]);
}

// Return a simpler enrolled/not-enrolled result for teacher student lookups.
function handleSearchStudent(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $studentId = requireField($request, "student_id");
    $teacher = requireTeacherAccount($conn, $accountId);

    requireTeacherCourse($conn, $teacher["account_id"], $courseId);
    $student = findStudentByPublicId($conn, $studentId);

    if ($student === null) {
        respondSuccess("Student lookup complete.", [
            "result_lines" => [
                "Student not enrolled in the course."
            ]
        ]);
    }

    if (!isStudentEnrolled($conn, $courseId, (int)$student["id"])) {
        respondSuccess("Student lookup complete.", [
            "result_lines" => [
                "Student not enrolled in the course."
            ]
        ]);
    }

    respondSuccess("Student lookup complete.", [
        "result_lines" => [
            "Student ID: " . $student["student_id"],
            "Name: " . $student["full_name"]
        ]
    ]);
}

// Remove a student from a course.
function handleRemoveStudent(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $studentId = requireField($request, "student_id");
    $teacher = requireTeacherAccount($conn, $accountId);

    requireTeacherCourse($conn, $teacher["account_id"], $courseId);
    $student = requireStudentByPublicId($conn, $studentId);
    $studentRefId = (int)$student["id"];

    $stmt = $conn->prepare("DELETE FROM course_students WHERE course_id = ? AND student_ref_id = ?");
    $stmt->bind_param("ii", $courseId, $studentRefId);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();

    if ($removed === 0) {
        respondError("Student is not enrolled in the selected course.", 404);
    }

    respondSuccess("Student removed from the course successfully.", [
        "result_lines" => [
            "Student ID: " . $student["student_id"],
            "Name: " . $student["full_name"],
            "Course ID: " . $courseId
        ]
    ]);
}

// Enroll a student into a course by its public join key.
function handleEnrollCourse(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseKey = requireField($request, "course_key");
    $student = requireStudentAccount($conn, $accountId);
    $course = requireCourseByKey($conn, $courseKey);

    if (isStudentEnrolled($conn, (int)$course["id"], (int)$student["id"])) {
        respondError("Student is already enrolled in this course.", 409);
    }

    $courseRefId = (int)$course["id"];
    $studentRefId = (int)$student["id"];

    $stmt = $conn->prepare("INSERT INTO course_students (course_id, student_ref_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $courseRefId, $studentRefId);
    $stmt->execute();
    $stmt->close();

    $updatedCourse = requireStudentCourse($conn, $student["account_id"], $courseRefId);

    respondSuccess("Student enrolled successfully.", [
        "detail_lines" => buildStudentCourseDetailLines($updatedCourse)
    ]);
}

// Let a student view other students in a course they are enrolled in.
function handleListClassmates(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $student = requireStudentAccount($conn, $accountId);
    $course = requireStudentCourse($conn, $student["account_id"], $courseId);

    respondSuccess("Classmates retrieved successfully.", [
        "detail_lines" => buildStudentCourseDetailLines($course),
        "classmate_lines" => buildStudentLines(fetchClassmates($conn, $courseId, (int)$student["id"]))
    ]);
}

// Return a simpler enrolled/not-enrolled result for student classmate lookups.
function handleSearchClassmate(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $classmateId = requireField($request, "classmate_id");
    $student = requireStudentAccount($conn, $accountId);

    requireStudentCourse($conn, $student["account_id"], $courseId);
    $classmate = findStudentByPublicId($conn, $classmateId);

    if ($classmate === null) {
        respondSuccess("Classmate lookup complete.", [
            "result_lines" => [
                "Classmate not found in the course."
            ]
        ]);
    }

    $isEnrolled = isStudentEnrolled($conn, $courseId, (int)$classmate["id"]);
    $isSelf = ((int)$classmate["id"] === (int)$student["id"]);

    if (!$isEnrolled || $isSelf) {
        respondSuccess("Classmate lookup complete.", [
            "result_lines" => [
                "Classmate not found in the course."
            ]
        ]);
    }

    respondSuccess("Classmate lookup complete.", [
        "result_lines" => [
            "Student ID: " . $classmate["student_id"],
            "Name: " . $classmate["full_name"]
        ]
    ]);
}

// Allow a student to leave one of their currently enrolled courses.
function handleUnenrollCourse(mysqli $conn, array $request): void
{
    $accountId = requireField($request, "account_id");
    $courseId = requirePositiveInteger($request, "course_id");
    $student = requireStudentAccount($conn, $accountId);
    $course = requireStudentCourse($conn, $student["account_id"], $courseId);
    $studentRefId = (int)$student["id"];

    $stmt = $conn->prepare("DELETE FROM course_students WHERE course_id = ? AND student_ref_id = ?");
    $stmt->bind_param("ii", $courseId, $studentRefId);
    $stmt->execute();
    $removed = $stmt->affected_rows;
    $stmt->close();

    if ($removed === 0) {
        respondError("Student is not enrolled in the selected course.", 404);
    }

    respondSuccess("Student unenrolled from the course successfully.", [
        "result_lines" => [
            "Course ID: " . $course["id"],
            "Course Code: " . $course["course_code"],
            "Course Name: " . $course["course_name"]
        ]
    ]);
}

// Verify the password after the account lookup succeeds.
function requireAccountCredentials(mysqli $conn, string $accountId, string $password): array
{
    $account = requireAccountByPublicId($conn, $accountId);

    if (!password_verify($password, $account["password_hash"])) {
        respondError("Invalid account ID or password.", 401);
    }

    return $account;
}

// Resolve a public account ID from either the teachers or students table.
function requireAccountByPublicId(mysqli $conn, string $accountId): array
{
    $account = findAccountByPublicId($conn, $accountId);

    if ($account === null) {
        respondError("Account not found.", 404);
    }

    return $account;
}

// Ensure the selected account belongs to a teacher before continuing.
function requireTeacherAccount(mysqli $conn, string $accountId): array
{
    $account = requireAccountByPublicId($conn, $accountId);

    if ($account["role"] !== "teacher") {
        respondError("Teacher access is required for this action.", 403);
    }

    return $account;
}

// Ensure the selected account belongs to a student before continuing.
function requireStudentAccount(mysqli $conn, string $accountId): array
{
    $account = requireAccountByPublicId($conn, $accountId);

    if ($account["role"] !== "student") {
        respondError("Student access is required for this action.", 403);
    }

    return $account;
}

function findAccountByPublicId(mysqli $conn, string $accountId): ?array
{
    $teacher = findTeacherByPublicId($conn, $accountId);
    $student = findStudentByPublicId($conn, $accountId);

    // A public ID must belong to exactly one table to avoid ambiguous logins.
    if ($teacher !== null && $student !== null) {
        respondError("Duplicate account ID exists across teacher and student tables.", 409);
    }

    if ($teacher !== null) {
        $teacher["role"] = "teacher";
        $teacher["account_id"] = $teacher["teacher_id"];
        return $teacher;
    }

    if ($student !== null) {
        $student["role"] = "student";
        $student["account_id"] = $student["student_id"];
        return $student;
    }

    return null;
}

// Look up a teacher by their public teacher_id value.
function findTeacherByPublicId(mysqli $conn, string $teacherId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
    $stmt->bind_param("s", $teacherId);
    $stmt->execute();
    $result = $stmt->get_result();
    $teacher = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $teacher;
}

// Require a student record and stop the request if the ID does not exist.
function requireStudentByPublicId(mysqli $conn, string $studentId): array
{
    $student = findStudentByPublicId($conn, $studentId);

    if ($student === null) {
        respondError("Student account not found.", 404);
    }

    return $student;
}

// Look up a student by their public student_id value.
function findStudentByPublicId(mysqli $conn, string $studentId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc() ?: null;
    $stmt->close();

    return $student;
}

// Load a course using its join key and include the owning teacher details.
function requireCourseByKey(mysqli $conn, string $courseKey): array
{
    $stmt = $conn->prepare(
        "SELECT c.*, t.teacher_id, t.full_name AS teacher_name
         FROM courses c
         INNER JOIN teachers t ON t.id = c.teacher_ref_id
         WHERE c.course_key = ?"
    );
    $stmt->bind_param("s", $courseKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $course = $result->fetch_assoc();
    $stmt->close();

    if (!$course) {
        respondError("Course key not found.", 404);
    }

    return $course;
}

// Return a course only if it belongs to the requested teacher.
function requireTeacherCourse(mysqli $conn, string $teacherId, int $courseId): array
{
    $stmt = $conn->prepare(
        "SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.course_key,
            c.created_at,
            t.teacher_id,
            t.full_name AS teacher_name,
            COUNT(cs.student_ref_id) AS student_count
         FROM courses c
         INNER JOIN teachers t ON t.id = c.teacher_ref_id
         LEFT JOIN course_students cs ON cs.course_id = c.id
         WHERE c.id = ? AND t.teacher_id = ?
         GROUP BY c.id, c.course_code, c.course_name, c.course_key, c.created_at, t.teacher_id, t.full_name"
    );
    $stmt->bind_param("is", $courseId, $teacherId);
    $stmt->execute();
    $result = $stmt->get_result();
    $course = $result->fetch_assoc();
    $stmt->close();

    if (!$course) {
        respondError("Course not found.", 404);
    }

    return $course;
}

// Return a course only if the requested student is enrolled in it.
function requireStudentCourse(mysqli $conn, string $studentId, int $courseId): array
{
    $stmt = $conn->prepare(
        "SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.course_key,
            c.created_at,
            t.teacher_id,
            t.full_name AS teacher_name,
            COUNT(cs_all.student_ref_id) AS class_count
         FROM courses c
         INNER JOIN teachers t ON t.id = c.teacher_ref_id
         INNER JOIN students s ON s.student_id = ?
         INNER JOIN course_students cs_self
             ON cs_self.course_id = c.id AND cs_self.student_ref_id = s.id
         LEFT JOIN course_students cs_all ON cs_all.course_id = c.id
         WHERE c.id = ?
         GROUP BY c.id, c.course_code, c.course_name, c.course_key, c.created_at, t.teacher_id, t.full_name"
    );
    $stmt->bind_param("si", $studentId, $courseId);
    $stmt->execute();
    $result = $stmt->get_result();
    $course = $result->fetch_assoc();
    $stmt->close();

    if (!$course) {
        respondError("Course not found for this student.", 404);
    }

    return $course;
}

// Fetch every course created by a teacher, including current enrollment counts.
function fetchTeacherCourses(mysqli $conn, int $teacherRefId): array
{
    $stmt = $conn->prepare(
        "SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.course_key,
            COUNT(cs.student_ref_id) AS student_count
         FROM courses c
         LEFT JOIN course_students cs ON cs.course_id = c.id
         WHERE c.teacher_ref_id = ?
         GROUP BY c.id, c.course_code, c.course_name, c.course_key
         ORDER BY c.course_name ASC"
    );
    $stmt->bind_param("i", $teacherRefId);
    $stmt->execute();
    $result = $stmt->get_result();
    $courses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $courses;
}

// Fetch every course a student is currently enrolled in.
function fetchStudentCourses(mysqli $conn, int $studentRefId): array
{
    $stmt = $conn->prepare(
        "SELECT
            c.id,
            c.course_code,
            c.course_name,
            c.course_key,
            t.full_name AS teacher_name
         FROM course_students cs
         INNER JOIN courses c ON c.id = cs.course_id
         INNER JOIN teachers t ON t.id = c.teacher_ref_id
         WHERE cs.student_ref_id = ?
         ORDER BY c.course_name ASC"
    );
    $stmt->bind_param("i", $studentRefId);
    $stmt->execute();
    $result = $stmt->get_result();
    $courses = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $courses;
}

// List all students assigned to a specific course.
function fetchStudentsInCourse(mysqli $conn, int $courseId): array
{
    $stmt = $conn->prepare(
        "SELECT s.student_id, s.full_name
         FROM course_students cs
         INNER JOIN students s ON s.id = cs.student_ref_id
         WHERE cs.course_id = ?
         ORDER BY s.full_name ASC"
    );
    $stmt->bind_param("i", $courseId);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $students;
}

// List classmates while excluding the current student from the result.
function fetchClassmates(mysqli $conn, int $courseId, int $studentRefId): array
{
    $stmt = $conn->prepare(
        "SELECT s.student_id, s.full_name
         FROM course_students cs
         INNER JOIN students s ON s.id = cs.student_ref_id
         WHERE cs.course_id = ? AND s.id <> ?
         ORDER BY s.full_name ASC"
    );
    $stmt->bind_param("ii", $courseId, $studentRefId);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $students;
}

// Check for an existing course enrollment without loading the whole row.
function isStudentEnrolled(mysqli $conn, int $courseId, int $studentRefId): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM course_students
         WHERE course_id = ? AND student_ref_id = ?
         LIMIT 1"
    );
    $stmt->bind_param("ii", $courseId, $studentRefId);
    $stmt->execute();
    $stmt->store_result();
    $isEnrolled = $stmt->num_rows > 0;
    $stmt->close();

    return $isEnrolled;
}

// Confirm that a required table exists in the currently selected database.
function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

// Confirm that a required column exists in the currently selected database.
function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

// Prevent new accounts from reusing an existing public ID.
function ensureAccountDoesNotExist(mysqli $conn, string $accountId): void
{
    if (findAccountByPublicId($conn, $accountId) !== null) {
        respondError("Account ID already exists.", 409);
    }
}

// Reusable uniqueness check for fields such as course codes.
function ensureValueDoesNotExist(
    mysqli $conn,
    string $table,
    string $column,
    string $value,
    string $message
): void {
    $query = "SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $value);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if ($exists) {
        respondError($message, 409);
    }
}

// Read a required text field and reject empty values.
function requireField(array $request, string $name): string
{
    $value = trim((string)($request[$name] ?? ""));

    if ($value === "") {
        respondError($name . " is required", 400);
    }

    return $value;
}

// Validate numeric identifiers that must be positive integers.
function requirePositiveInteger(array $request, string $name): int
{
    $value = trim((string)($request[$name] ?? ""));

    if ($value === "" || !ctype_digit($value) || (int)$value <= 0) {
        respondError($name . " must be a positive integer", 400);
    }

    return (int)$value;
}

// Accept only the two supported account roles.
function requireRole(array $request): string
{
    $role = strtolower(requireField($request, "role"));

    if ($role !== "teacher" && $role !== "student") {
        respondError("role must be teacher or student", 400);
    }

    return $role;
}

// Keep generating short join keys until one is not already used.
function generateUniqueCourseKey(mysqli $conn): string
{
    do {
        $courseKey = generateRandomToken(6);
        $stmt = $conn->prepare("SELECT 1 FROM courses WHERE course_key = ? LIMIT 1");
        $stmt->bind_param("s", $courseKey);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $courseKey;
}

// Build an uppercase token that avoids ambiguous characters such as O and 0.
function generateRandomToken(int $length): string
{
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
    $token = "";
    $maxIndex = strlen($alphabet) - 1;

    for ($index = 0; $index < $length; $index++) {
        $token .= $alphabet[random_int(0, $maxIndex)];
    }

    return $token;
}

// Format course lists differently for teachers and students.
function buildCourseLinesForAccount(mysqli $conn, array $account): array
{
    if ($account["role"] === "teacher") {
        return buildTeacherCourseLines(fetchTeacherCourses($conn, (int)$account["id"]));
    }

    return buildStudentCourseLines(fetchStudentCourses($conn, (int)$account["id"]));
}

// Build course summary lines for teacher responses.
function buildTeacherCourseLines(array $courses): array
{
    $lines = [];

    foreach ($courses as $course) {
        $lines[] =
            "[" . $course["id"] . "] " .
            $course["course_code"] . " - " . $course["course_name"] .
            " | Key: " . $course["course_key"] .
            " | Students: " . $course["student_count"];
    }

    return $lines;
}

// Build human-readable course summary lines for student responses.
function buildStudentCourseLines(array $courses): array
{
    $lines = [];

    foreach ($courses as $course) {
        $lines[] =
            "[" . $course["id"] . "] " .
            $course["course_code"] . " - " . $course["course_name"] .
            " | Teacher: " . $course["teacher_name"] .
            " | Key: " . $course["course_key"];
    }

    return $lines;
}

// Format full course details for teachers, including enrollment totals.
function buildTeacherCourseDetailLines(array $course): array
{
    return [
        "Course ID: " . $course["id"],
        "Course Code: " . $course["course_code"],
        "Course Name: " . $course["course_name"],
        "Teacher: " . $course["teacher_name"] . " (" . $course["teacher_id"] . ")",
        "Course Key: " . $course["course_key"],
        "Registered Students: " . $course["student_count"],
        "Created At: " . $course["created_at"]
    ];
}

// Format full course details for students, including class size.
function buildStudentCourseDetailLines(array $course): array
{
    return [
        "Course ID: " . $course["id"],
        "Course Code: " . $course["course_code"],
        "Course Name: " . $course["course_name"],
        "Teacher: " . $course["teacher_name"] . " (" . $course["teacher_id"] . ")",
        "Course Key: " . $course["course_key"],
        "Students In Course: " . $course["class_count"],
        "Created At: " . $course["created_at"]
    ];
}

// Format student rows consistently for student and teacher listings.
function buildStudentLines(array $students): array
{
    $lines = [];

    foreach ($students as $student) {
        $lines[] = $student["student_id"] . " - " . $student["full_name"];
    }

    return $lines;
}

// Send a successful JSON response and stop further execution.
function respondSuccess(string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        array_merge(
            [
                "status" => "success",
                "message" => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

// Send an error JSON response and stop further execution.
function respondError(string $message, int $statusCode = 400, array $data = []): void
{
    http_response_code($statusCode);
    echo json_encode(
        array_merge(
            [
                "status" => "error",
                "message" => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}
?>
