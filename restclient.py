import json
import urllib.error
import urllib.request
from dataclasses import dataclass


# Shared endpoint used by the command-line client.
API_URL = "http://localhost/rest-serv/service.php"


@dataclass
class ApiResult:
    # Keep both the parsed payload and the raw body for fallback error display.
    raw: str
    data: dict

    @property
    def ok(self) -> bool:
        return self.data.get("status") == "success"

    @property
    def message(self) -> str:
        return str(self.data.get("message", ""))

    @property
    def role(self) -> str:
        return str(self.data.get("role", ""))

    @property
    def account_id(self) -> str:
        return str(self.data.get("account_id", ""))

    @property
    def full_name(self) -> str:
        return str(self.data.get("full_name", ""))

    def lines(self, key: str) -> list[str]:
        value = self.data.get(key, [])
        if isinstance(value, list):
            return [str(item) for item in value]
        return []


@dataclass
class Session:
    account_id: str
    full_name: str
    role: str


def read_line(prompt: str) -> str:
    # Exit if the input stream is closed unexpectedly.
    try:
        return input(prompt).strip()
    except EOFError:
        print("\nInput stream closed.")
        raise SystemExit(0)


def pause() -> None:
    # Pause between screens so users can read the last response.
    try:
        input("\nPress Enter to continue...")
    except EOFError:
        print()


def read_menu_choice(min_value: int, max_value: int) -> int:
    # Keep prompting untill the user selects one of the visible menu options.
    while True:
        value = read_line("Choose an option: ")
        if value.isdigit():
            number = int(value)
            if min_value <= number <= max_value:
                return number
        print(f"Please enter a number from {min_value} to {max_value}.")

def choose_role() -> str:
    # Reuse menu for teacher/student selection.
    print("\nChoose account role")
    print("1. Teacher")
    print("2. Student")
    choice = read_menu_choice(1, 2)
    return "teacher" if choice == 1 else "student"


def role_label(role: str) -> str:
    return "Teacher" if role == "teacher" else "Student"


def call_api(payload: dict[str, str]) -> ApiResult:
    # Send every request as JSON so it matches the PHP service contract.
    request_text = json.dumps(payload)
    raw_response = ""

    request = urllib.request.Request(
        API_URL,
        data=request_text.encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )

    try:
        with urllib.request.urlopen(request) as response:
            raw_response = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as error:
        raw_response = error.read().decode("utf-8", errors="replace")
        if not raw_response:
            raw_response = str(error)
    except urllib.error.URLError as error:
        raw_response = str(error.reason)

    # Error if the server returns invalid JSON.
    try:
        data = json.loads(raw_response)
        if not isinstance(data, dict):
            raise json.JSONDecodeError("Expected object", raw_response, 0)
    except json.JSONDecodeError:
        data = {
            "status": "error",
            "message": "Unable to parse the server response.",
        }

    return ApiResult(raw=raw_response, data=data)


def print_lines(title: str, lines: list[str]) -> None:
    # Skip empty sections
    if not lines:
        return

    print(f"\n{title}:")
    for line in lines:
        print(f" - {line}")


def print_api_result(result: ApiResult) -> None:
    # Show the shared response sections used by the PHP service.
    print(f"\n{result.message}")
    print_lines("Courses", result.lines("course_lines"))
    print_lines("Details", result.lines("detail_lines"))
    print_lines("Students", result.lines("student_lines"))
    print_lines("Classmates", result.lines("classmate_lines"))
    print_lines("Result", result.lines("result_lines"))

    if not result.ok and "{" not in result.raw and result.raw.strip():
        print(f"\nRaw response:\n{result.raw}")


def resolve_course_reference(course_lines: list[str], value: str) -> str:
    # Accept either the numeric course ID or the visible course code.
    candidate = value.strip()
    if not candidate:
        return ""

    if candidate.isdigit():
        return candidate

    normalized = candidate.casefold()

    for line in course_lines:
        if not line.startswith("["):
            continue

        closing_bracket = line.find("]")
        if closing_bracket <= 1:
            continue

        course_id = line[1:closing_bracket].strip()
        remainder = line[closing_bracket + 1 :].lstrip()
        course_code = remainder.split(" - ", 1)[0].strip()

        if normalized == course_code.casefold():
            return course_id

    return ""


def show_courses(session: Session) -> ApiResult:
    # Central helper used by both dashboards before course-specific actions.
    result = call_api(
        {
            "action": "list_courses",
            "account_id": session.account_id,
        }
    )
    print_api_result(result)

    if not result.lines("course_lines"):
        if session.role == "teacher":
            print("\nNo courses created yet.")
        else:
            print("\nNo enrolled courses yet.")

    return result


def register_flow() -> None:
    # Check the account ID first so duplicate registrations stop early.
    role = choose_role()
    account_id = read_line("Account ID: ")

    check_result = call_api(
        {
            "action": "check_registration",
            "account_id": account_id,
        }
    )

    if not check_result.ok:
        print_api_result(check_result)
        pause()
        return

    if str(check_result.data.get("is_registered", "")).upper() == "Y":
        print("\nAccount ID already exists.")
        pause()
        return

    full_name = read_line("Full name: ")
    password = read_line("Password: ")

    result = call_api(
        {
            "action": "register",
            "role": role,
            "account_id": account_id,
            "full_name": full_name,
            "password": password,
        }
    )
    print_api_result(result)
    pause()


def login_flow() -> None:
    # After login, route the user to the correct dashboard for their role.
    account_id = read_line("Account ID: ")
    password = read_line("Password: ")
    result = call_api(
        {
            "action": "login",
            "account_id": account_id,
            "password": password,
        }
    )
    print_api_result(result)

    if not result.ok:
        pause()
        return

    session = Session(
        account_id=result.account_id or account_id,
        full_name=result.full_name or account_id,
        role=result.role,
    )

    if session.role == "teacher":
        teacher_dashboard(session)
    else:
        student_dashboard(session)


def teacher_course_menu(session: Session, course_id: str) -> None:
    # Course-specific teacher actions
    while True:
        print("\nTeacher Course Menu")
        print("1. View course details")
        print("2. View all students")
        print("3. Search or verify a student")
        print("4. Delete a student from this course")
        print("5. Back")

        choice = read_menu_choice(1, 5)

        if choice == 1:
            print_api_result(
                call_api(
                    {
                        "action": "view_course",
                        "account_id": session.account_id,
                        "course_id": course_id,
                    }
                )
            )
            pause()
            continue

        if choice == 2:
            result = call_api(
                {
                    "action": "list_students",
                    "account_id": session.account_id,
                    "course_id": course_id,
                }
            )
            print_api_result(result)
            pause()
            continue

        if choice == 3:
            student_id = read_line("Enter the student ID to search: ")
            print_api_result(
                call_api(
                    {
                        "action": "search_student",
                        "account_id": session.account_id,
                        "course_id": course_id,
                        "student_id": student_id,
                    }
                )
            )
            pause()
            continue

        if choice == 4:
            student_id = read_line("Enter the student ID to remove: ")
            print_api_result(
                call_api(
                    {
                        "action": "remove_student",
                        "account_id": session.account_id,
                        "course_id": course_id,
                        "student_id": student_id,
                    }
                )
            )
            pause()
            continue

        return


def student_course_menu(session: Session, course_id: str) -> None:
    # Student course actions
    while True:
        print("\nStudent Course Menu")
        print("1. View course details")
        print("2. View all classmates")
        print("3. Search or verify a classmate")
        print("4. Unenroll from this course")
        print("5. Back")

        choice = read_menu_choice(1, 5)

        if choice == 1:
            print_api_result(
                call_api(
                    {
                        "action": "view_course",
                        "account_id": session.account_id,
                        "course_id": course_id,
                    }
                )
            )
            pause()
            continue

        if choice == 2:
            result = call_api(
                {
                    "action": "list_classmates",
                    "account_id": session.account_id,
                    "course_id": course_id,
                }
            )
            print_api_result(result)
            if not result.lines("classmate_lines"):
                print("\nNo classmates found yet.")
            pause()
            continue

        if choice == 3:
            classmate_id = read_line("Enter the classmate ID to search: ")
            print_api_result(
                call_api(
                    {
                        "action": "search_classmate",
                        "account_id": session.account_id,
                        "course_id": course_id,
                        "classmate_id": classmate_id,
                    }
                )
            )
            pause()
            continue

        if choice == 4:
            result = call_api(
                {
                    "action": "unenroll_course",
                    "account_id": session.account_id,
                    "course_id": course_id,
                }
            )
            print_api_result(result)
            pause()
            if result.ok:
                return
            continue

        return


def teacher_dashboard(session: Session) -> None:
    # Main teacher landing area after login.
    while True:
        print(f"\nTeacher Dashboard - {session.full_name} ({session.account_id})")
        print("1. View my courses")
        print("2. Create a course")
        print("3. Enter a course")
        print("4. Logout")

        choice = read_menu_choice(1, 4)

        if choice == 1:
            show_courses(session)
            pause()
            continue

        if choice == 2:
            course_code = read_line("Enter the course code: ")
            course_name = read_line("Enter the course name: ")
            print_api_result(
                call_api(
                    {
                        "action": "create_course",
                        "account_id": session.account_id,
                        "course_code": course_code,
                        "course_name": course_name,
                    }
                )
            )
            pause()
            continue

        if choice == 3:
            courses_result = show_courses(session)
            course_input = read_line("\nEnter the course code to open: ")
            course_id = resolve_course_reference(courses_result.lines("course_lines"), course_input)
            if course_id:
                teacher_course_menu(session, course_id)
            elif course_input:
                print("\nCourse not found. Use the numeric ID in brackets or a listed course code.")
                pause()
            continue

        return


def student_dashboard(session: Session) -> None:
    # Main student landing area after login.
    while True:
        print(f"\nStudent Dashboard - {session.full_name} ({session.account_id})")
        print("1. View enrolled courses")
        print("2. Enroll to a course")
        print("3. Enter a course")
        print("4. Unenroll from a course")
        print("5. Logout")

        choice = read_menu_choice(1, 5)

        if choice == 1:
            show_courses(session)
            pause()
            continue

        if choice == 2:
            course_key = read_line("Enter the course key/password: ")
            print_api_result(
                call_api(
                    {
                        "action": "enroll_course",
                        "account_id": session.account_id,
                        "course_key": course_key,
                    }
                )
            )
            pause()
            continue

        if choice == 3:
            courses_result = show_courses(session)
            course_input = read_line("\nEnter the course ID number or course code to open: ")
            course_id = resolve_course_reference(courses_result.lines("course_lines"), course_input)
            if course_id:
                student_course_menu(session, course_id)
            elif course_input:
                print("\nCourse not found. Use the numeric ID in brackets or a listed course code.")
                pause()
            continue

        if choice == 4:
            courses_result = show_courses(session)
            course_input = read_line("\nEnter the course ID number or course code to unenroll from: ")
            course_id = resolve_course_reference(courses_result.lines("course_lines"), course_input)
            if course_id:
                print_api_result(
                    call_api(
                        {
                            "action": "unenroll_course",
                            "account_id": session.account_id,
                            "course_id": course_id,
                        }
                    )
                )
                pause()
            elif course_input:
                print("\nCourse not found. Use the numeric ID in brackets or a listed course code.")
                pause()
            continue

        return


def main() -> None:
    # Shared entry menu
    print("Student Management Python Client")

    while True:
        print("\nShared Portal")
        print("1. Register")
        print("2. Login")
        print("3. Exit")

        choice = read_menu_choice(1, 3)

        if choice == 1:
            register_flow()
            continue

        if choice == 2:
            login_flow()
            continue

        print("\nGoodbye.")
        return


if __name__ == "__main__":
    main()
