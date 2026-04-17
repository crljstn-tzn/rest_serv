(function () {
    // Use static page routes while keeping the PHP API endpoint.
    const config = window.webClientConfig || {};
    const apiUrl = config.apiUrl || "service.php";
    const loginUrl = config.loginUrl || "login.html";
    const registerUrl = config.registerUrl || "register.html";
    const dashboardUrl = config.dashboardUrl || "webclient.html";
    const sessionKey = "restServWebSession";

    const state = {
        session: null,
        courseLines: []
    };

    // Store DOM references once the page is ready.
    const elements = {};

    document.addEventListener("DOMContentLoaded", init);

    function init() {
        elements.statusBox = document.getElementById("statusBox");
        restoreSession();

        // Reuse one script across the login, register, and dashboard pages.
        const page = document.body.dataset.page || "";

        if (page === "login") {
            initLoginPage();
            return;
        }

        if (page === "register") {
            initRegisterPage();
            return;
        }

        initDashboardPage();
    }

    function initLoginPage() {
        // Logged-in users should not stay on the login screen.
        if (state.session) {
            window.location.href = dashboardUrl;
            return;
        }

        elements.loginForm = document.getElementById("loginForm");
        elements.loginForm.addEventListener("submit", onLogin);
    }

    function initRegisterPage() {
        // Logged-in users should not stay on the registration screen.
        if (state.session) {
            window.location.href = dashboardUrl;
            return;
        }

        elements.registerForm = document.getElementById("registerForm");
        elements.registerForm.addEventListener("submit", onRegister);
    }

    function initDashboardPage() {
        // Require session
        if (!state.session) {
            window.location.href = loginUrl;
            return;
        }

        cacheDashboardElements();
        bindDashboardEvents();
        renderSession();
        loadCourses();
    }

    function cacheDashboardElements() {
        // Cache dashboard controls once so event handlers can reuse them.
        elements.sessionName = document.getElementById("sessionName");
        elements.sessionMeta = document.getElementById("sessionMeta");
        elements.courseList = document.getElementById("courseList");
        elements.responseBox = document.getElementById("responseBox");
        elements.teacherSection = document.getElementById("teacherSection");
        elements.studentSection = document.getElementById("studentSection");
        elements.logoutButton = document.getElementById("logoutButton");
        elements.refreshCoursesButton = document.getElementById("refreshCoursesButton");
        elements.showCreateCourseButton = document.getElementById("showCreateCourseButton");
        elements.showViewCourseButton = document.getElementById("showViewCourseButton");
        elements.createCourseForm = document.getElementById("createCourseForm");
        elements.teacherViewForm = document.getElementById("teacherViewForm");
        elements.teacherViewAction = document.getElementById("teacherViewAction");
        elements.teacherStudentIdLabel = document.getElementById("teacherStudentIdLabel");
        elements.teacherViewSubmitButton = document.getElementById("teacherViewSubmitButton");
        elements.showEnrollButton = document.getElementById("showEnrollButton");
        elements.showStudentCourseButton = document.getElementById("showStudentCourseButton");
        elements.showStudentClassmateButton = document.getElementById("showStudentClassmateButton");
        elements.enrollForm = document.getElementById("enrollForm");
        elements.studentCourseForm = document.getElementById("studentCourseForm");
        elements.studentClassmateForm = document.getElementById("studentClassmateForm");
        elements.studentViewCourseButton = document.getElementById("studentViewCourseButton");
        elements.studentListClassmatesButton = document.getElementById("studentListClassmatesButton");
        elements.studentUnenrollButton = document.getElementById("studentUnenrollButton");
        elements.studentSearchClassmateButton = document.getElementById("studentSearchClassmateButton");
    }

    function bindDashboardEvents() {
        // Centralize dashboard event wiring after the elements are cached.
        elements.logoutButton.addEventListener("click", onLogout);
        elements.refreshCoursesButton.addEventListener("click", loadCourses);
        elements.createCourseForm.addEventListener("submit", onCreateCourse);
        elements.teacherViewForm.addEventListener("submit", onTeacherViewSubmit);
        elements.enrollForm.addEventListener("submit", onEnrollCourse);
        elements.studentCourseForm.addEventListener("submit", preventDefaultSubmit);
        elements.studentClassmateForm.addEventListener("submit", preventDefaultSubmit);
        elements.showCreateCourseButton.addEventListener("click", function () {
            showTeacherPanel("create");
        });
        elements.showViewCourseButton.addEventListener("click", function () {
            showTeacherPanel("view");
        });
        elements.teacherViewAction.addEventListener("change", syncTeacherViewForm);
        elements.showEnrollButton.addEventListener("click", function () {
            showStudentPanel("enroll");
        });
        elements.showStudentCourseButton.addEventListener("click", function () {
            showStudentPanel("course");
        });
        elements.showStudentClassmateButton.addEventListener("click", function () {
            showStudentPanel("classmate");
        });

        elements.studentViewCourseButton.addEventListener("click", function () {
            handleStudentCourseAction("view_course");
        });
        elements.studentListClassmatesButton.addEventListener("click", function () {
            handleStudentCourseAction("list_classmates");
        });
        elements.studentUnenrollButton.addEventListener("click", function () {
            handleStudentCourseAction("unenroll_course");
        });
        elements.studentSearchClassmateButton.addEventListener("click", handleStudentClassmateAction);
    }

    async function onRegister(event) {
        event.preventDefault();

        // Match the server's shared registration payload shape.
        const formData = new FormData(event.currentTarget);
        const result = await callApi({
            action: "register",
            role: String(formData.get("role") || "").trim(),
            account_id: String(formData.get("account_id") || "").trim(),
            full_name: String(formData.get("full_name") || "").trim(),
            password: String(formData.get("password") || "")
        });

        renderResult(result);

        if (result.status === "success") {
            event.currentTarget.reset();
        }
    }

    async function onLogin(event) {
        event.preventDefault();

        // Save the returned account summary so the dashboard can restore it later.
        const formData = new FormData(event.currentTarget);
        const result = await callApi({
            action: "login",
            account_id: String(formData.get("account_id") || "").trim(),
            password: String(formData.get("password") || "")
        });

        renderResult(result);

        if (result.status !== "success") {
            return;
        }

        state.session = {
            account_id: result.account_id,
            full_name: result.full_name,
            role: result.role
        };

        saveSession();
        window.location.href = dashboardUrl;
    }

    function onLogout() {
        // Clearing the saved session returns the user to the login screen.
        state.session = null;
        saveSession();
        window.location.href = loginUrl;
    }

    function preventDefaultSubmit(event) {
        event.preventDefault();
    }

    async function onCreateCourse(event) {
        event.preventDefault();

        // Teachers create courses through the same API used by the CLI client.
        const formData = new FormData(event.currentTarget);
        const result = await callApi({
            action: "create_course",
            account_id: state.session.account_id,
            course_code: String(formData.get("course_code") || "").trim(),
            course_name: String(formData.get("course_name") || "").trim()
        });

        renderResult(result);

        if (result.status === "success") {
            event.currentTarget.reset();
            await loadCourses();
        }
    }

    async function onTeacherViewSubmit(event) {
        event.preventDefault();

        // Build the payload dynamically for some teacher actions that need student_id.
        const courseReference = getRequiredField(elements.teacherViewForm, "course_id");
        const courseId = resolveCourseReference(courseReference);
        if (!courseId) {
            setStatus("Course code not found in your course list.", "error");
            return;
        }

        const action = String(elements.teacherViewAction.value || "").trim();
        const payload = {
            action: action,
            account_id: state.session.account_id,
            course_id: courseId
        };

        if (action === "search_student" || action === "remove_student") {
            const studentId = getRequiredField(elements.teacherViewForm, "student_id");
            if (!studentId) {
                return;
            }
            payload.student_id = studentId;
        }

        const result = await callApi(payload);
        renderResult(result);

        if (action === "remove_student" && result.status === "success") {
            await loadCourses();
        }
    }

    async function onEnrollCourse(event) {
        event.preventDefault();

        // Students join courses by the public course key.
        const formData = new FormData(event.currentTarget);
        const result = await callApi({
            action: "enroll_course",
            account_id: state.session.account_id,
            course_key: String(formData.get("course_key") || "").trim()
        });

        renderResult(result);

        if (result.status === "success") {
            event.currentTarget.reset();
            await loadCourses();
        }
    }

    async function handleStudentCourseAction(action) {
        // Reuse one helper for view, classmates, and unenroll actions.
        const courseReference = getRequiredField(elements.studentCourseForm, "course_id");
        const courseId = resolveCourseReference(courseReference);
        if (!courseId) {
            setStatus("Course code not found in your course list.", "error");
            return;
        }

        const result = await callApi({
            action: action,
            account_id: state.session.account_id,
            course_id: courseId
        });

        renderResult(result);

        if (action === "unenroll_course" && result.status === "success") {
            await loadCourses();
        }
    }

    async function handleStudentClassmateAction() {
        // This action needs both the course and the classmate identifier.
        const courseReference = getRequiredField(elements.studentClassmateForm, "course_id");
        const courseId = resolveCourseReference(courseReference);
        const classmateId = getRequiredField(elements.studentClassmateForm, "classmate_id");

        if (!courseId || !classmateId) {
            if (!courseId) {
                setStatus("Course code not found in your course list.", "error");
            }
            return;
        }

        renderResult(await callApi({
            action: "search_classmate",
            account_id: state.session.account_id,
            course_id: courseId,
            classmate_id: classmateId
        }));
    }

    async function loadCourses() {
        // Refresh the course list after login and after course-changing actions.
        const result = await callApi({
            action: "list_courses",
            account_id: state.session.account_id
        });

        if (result.status === "success") {
            state.courseLines = Array.isArray(result.course_lines) ? result.course_lines : [];
            renderList(
                elements.courseList,
                result.course_lines,
                state.session.role === "teacher" ? "No courses created yet." : "No enrolled courses yet."
            );
            setStatus(result.message, "success");
            return;
        }

        state.courseLines = [];
        renderList(elements.courseList, [], "No courses to display.");
        renderResult(result);
    }

    async function callApi(payload) {
        // Keep browser requests consistent with the JSON API contract.
        try {
            const response = await fetch(apiUrl, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(payload)
            });

            const raw = await response.text();
            let data;

            // Surface a readable error even if the backend returns non-JSON output.
            try {
                data = JSON.parse(raw);
            } catch (error) {
                return {
                    status: "error",
                    message: "Unable to parse the server response.",
                    raw: raw
                };
            }

            data.raw = raw;
            return data;
        } catch (error) {
            return {
                status: "error",
                message: "Request failed: " + error.message,
                raw: ""
            };
        }
    }

    function renderSession() {
        // Toggle teacher and student panels based on the logged-in role.
        elements.sessionName.textContent = state.session.full_name;
        elements.sessionMeta.textContent = state.session.role + " | " + state.session.account_id;
        elements.teacherSection.classList.toggle("hidden", state.session.role !== "teacher");
        elements.studentSection.classList.toggle("hidden", state.session.role !== "student");

        if (state.session.role === "teacher") {
            showTeacherPanel("");
            syncTeacherViewForm();
            return;
        }

        showStudentPanel("");
    }

    function renderResult(result) {
        // Render the shared line-based response sections returned by the API.
        setStatus(result.message || "Request completed.", result.status === "success" ? "success" : "error");

        if (!elements.responseBox) {
            return;
        }

        const groups = [
            { title: "Courses", lines: result.course_lines },
            { title: "Details", lines: result.detail_lines },
            { title: "Students", lines: result.student_lines },
            { title: "Classmates", lines: result.classmate_lines },
            { title: "Result", lines: result.result_lines }
        ];

        elements.responseBox.innerHTML = "";
        elements.responseBox.classList.remove("empty");

        const message = document.createElement("p");
        message.textContent = result.message || "";
        elements.responseBox.appendChild(message);

        let hasExtra = false;

        groups.forEach(function (group) {
            if (!Array.isArray(group.lines) || group.lines.length === 0) {
                return;
            }

            hasExtra = true;
            const block = document.createElement("div");
            block.className = "output-block";

            const heading = document.createElement("strong");
            heading.textContent = group.title;
            block.appendChild(heading);

            const list = document.createElement("ul");

            group.lines.forEach(function (line) {
                const item = document.createElement("li");
                item.textContent = line;
                list.appendChild(item);
            });

            block.appendChild(list);
            elements.responseBox.appendChild(block);
        });

        if (!hasExtra && result.raw && result.raw.indexOf("{") === -1) {
            const pre = document.createElement("pre");
            pre.textContent = result.raw;
            elements.responseBox.appendChild(pre);
        }
    }

    function renderList(container, lines, emptyMessage) {
        // Rebuild the course list each time instead of updating items in place.
        container.innerHTML = "";

        if (!Array.isArray(lines) || lines.length === 0) {
            clearOutput(container, emptyMessage);
            return;
        }

        container.classList.remove("empty");

        const list = document.createElement("ul");

        lines.forEach(function (line) {
            const item = document.createElement("li");
            item.textContent = line;
            list.appendChild(item);
        });

        container.appendChild(list);
    }

    function clearOutput(container, message) {
        // Shared helper for empty response and course list states.
        if (!container) {
            return;
        }

        container.innerHTML = "";
        container.classList.add("empty");
        container.textContent = message;
    }

    function setStatus(message, type) {
        // Show a compact success or error banner at the top of the page.
        if (!elements.statusBox) {
            return;
        }

        elements.statusBox.textContent = message || "";
        elements.statusBox.className = "status " + (type || "success");
        elements.statusBox.classList.toggle("hidden", !message);
    }

    function getRequiredField(form, name) {
        // Validate required browser inputs before sending an API request.
        const input = form.elements[name];
        const value = input ? String(input.value || "").trim() : "";

        if (!value) {
            setStatus(name.replace("_", " ") + " is required.", "error");
            if (input) {
                input.focus();
            }
            return "";
        }

        return value;
    }

    function resolveCourseReference(value) {
        // Accept the visible course code while still supporting numeric IDs.
        const candidate = String(value || "").trim();
        if (!candidate) {
            return "";
        }

        if (/^\d+$/.test(candidate)) {
            return candidate;
        }

        const normalized = candidate.toLowerCase();

        for (const line of state.courseLines) {
            if (typeof line !== "string" || !line.startsWith("[")) {
                continue;
            }

            const closingBracket = line.indexOf("]");
            if (closingBracket <= 1) {
                continue;
            }

            const courseId = line.slice(1, closingBracket).trim();
            const remainder = line.slice(closingBracket + 1).trim();
            const courseCode = remainder.split(" - ", 1)[0].trim();

            if (courseCode.toLowerCase() === normalized) {
                return courseId;
            }
        }

        return "";
    }

    function showTeacherPanel(mode) {
        // Switch between the teacher create and teacher course-action forms.
        const showCreate = mode === "create";
        const showView = mode === "view";

        elements.createCourseForm.classList.toggle("hidden", !showCreate);
        elements.teacherViewForm.classList.toggle("hidden", !showView);
        elements.showCreateCourseButton.classList.toggle("button-muted", !showCreate);
        elements.showViewCourseButton.classList.toggle("button-muted", !showView);
    }

    function syncTeacherViewForm() {
        // Show the student_id field only for actions that actually need it.
        const action = String(elements.teacherViewAction.value || "").trim();
        const needsStudentId = action === "search_student" || action === "remove_student";
        const buttonLabels = {
            view_course: "View Course",
            list_students: "View Students",
            search_student: "Search Student",
            remove_student: "Delete Student"
        };

        elements.teacherStudentIdLabel.classList.toggle("hidden", !needsStudentId);
        elements.teacherViewSubmitButton.textContent = buttonLabels[action] || "Submit";

        if (!needsStudentId) {
            const studentInput = elements.teacherViewForm.elements.student_id;
            if (studentInput) {
                studentInput.value = "";
            }
        }
    }

    function showStudentPanel(mode) {
        // Show one student form at a time to match the teacher interaction style.
        const showEnroll = mode === "enroll";
        const showCourse = mode === "course";
        const showClassmate = mode === "classmate";

        elements.enrollForm.classList.toggle("hidden", !showEnroll);
        elements.studentCourseForm.classList.toggle("hidden", !showCourse);
        elements.studentClassmateForm.classList.toggle("hidden", !showClassmate);
        elements.showEnrollButton.classList.toggle("button-muted", !showEnroll);
        elements.showStudentCourseButton.classList.toggle("button-muted", !showCourse);
        elements.showStudentClassmateButton.classList.toggle("button-muted", !showClassmate);
    }

    window.webClientShowTeacherPanel = showTeacherPanel;
    window.webClientShowStudentPanel = showStudentPanel;

    function saveSession() {
        // Store session details in sessionStorage so refreshes keep the user signed in.
        if (!state.session) {
            sessionStorage.removeItem(sessionKey);
            return;
        }

        sessionStorage.setItem(sessionKey, JSON.stringify(state.session));
    }

    function restoreSession() {
        // Ignore corrupted session data and force a clean login if parsing fails.
        const stored = sessionStorage.getItem(sessionKey);

        if (!stored) {
            return;
        }

        try {
            const parsed = JSON.parse(stored);

            if (parsed && parsed.account_id && parsed.full_name && parsed.role) {
                state.session = parsed;
            }
        } catch (error) {
            sessionStorage.removeItem(sessionKey);
        }
    }
})();
