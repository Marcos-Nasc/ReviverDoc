/* Compatibilidade: showAlert() agora usa os toasts de script/app.js */
if (typeof window.showAlert !== 'function') {
    window.showAlert = function (message, type) {
        if (window.App) { window.App.toast(message, type); } else { window.alert(message); }
    };
}
