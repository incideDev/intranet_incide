document.addEventListener("click", function (event) {
    const target = event.target;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (target.id !== "togglePassword") {
        return;
    }

    const passwordField = document.getElementById("password");
    if (!(passwordField instanceof HTMLInputElement)) {
        return;
    }

    const isPassword = passwordField.type === "password";
    passwordField.type = isPassword ? "text" : "password";

    if (target instanceof HTMLImageElement) {
        target.src = isPassword ? "/assets/icons/hide.png" : "/assets/icons/show.png";
    }
});
