<?php
// === Protezione pagina con password extra ===

if (!isset($_SESSION)) session_start();
if (!defined('EXTRA_AUTH_SECRET')) define('EXTRA_AUTH_SECRET', getenv('EXTRA_AUTH_SECRET') ?: 'changeme');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extra_pass'])) {
    $ok = ($_POST['extra_pass'] === EXTRA_AUTH_SECRET);
    if ($ok) $_SESSION['extra_auth_ok'] = true;
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'message' => $ok ? 'Accesso sbloccato!' : 'Password errata!']);
    exit;
}

$extra_auth = (isset($_SESSION['extra_auth_ok']) && $_SESSION['extra_auth_ok'] === true);

if (!$extra_auth) {
    ?>
    <div id="extra-pass-overlay" style="position:fixed; z-index:10000; top:0; left:0; width:100vw; height:100vh; background:rgba(30,30,30,0.77); display:flex; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:10px; padding:28px 20px 18px 20px; box-shadow:0 0 30px #0002; min-width:300px; position:relative;">
            <h3 style="margin-top:0;">Accesso protetto</h3>
            <label for="extra-pass-field">Password addizionale:</label>
            <input type="password" id="extra-pass-field" class="form-control" style="margin-bottom:15px;">
            <button class="button" id="extra-pass-submit">Conferma</button>
            <span class="close" style="position:absolute; top:7px; right:10px; font-size:18px; cursor:pointer;" onclick="if(window.parent && window.parent !== window) { window.parent.postMessage('extra_auth_cancelled', '*'); } else { location.href='index.php?section=home&page=home'; }">&times;</span>
        </div>
    </div>

    <style>
    #extra-pass-overlay {
        position: fixed;
        z-index: 10000;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(30,30,30,0.79);
        display: flex; align-items: center; justify-content: center;
        font-family: 'Segoe UI', Arial, sans-serif;
    }
    #extra-pass-overlay .extra-modal {
        background: #fff;
        border-radius: 13px;
        padding: 32px 22px 20px 22px;
        box-shadow: 0 0 36px #0004;
        min-width: 270px;
        width: 95vw;
        max-width: 340px;
        position: relative;
        animation: popin 0.23s cubic-bezier(.77,-0.22,.45,1.12);
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    #extra-pass-overlay h3 {
        margin-top: 0; margin-bottom: 14px;
        color: #e53e3e;
        font-size: 1.19em;
        letter-spacing: 0.02em;
        font-family: var(--font-family-heading, 'Segoe UI', Arial, sans-serif);
        text-align: center;
    }
    #extra-pass-overlay label {
        font-size: 1em; color: #444;
        margin-bottom: 4px;
        text-align: left;
        width: 100%;
        display: block;
    }
    #extra-pass-overlay input[type="password"] {
        width: 100%;
        padding: 7px 12px;
        margin-bottom: 17px;
        font-size: 1em;
        border: 1px solid #bbb;
        border-radius: 6px;
        outline: none;
        box-sizing: border-box;
        text-align: left;
        transition: border .2s;
        background: #f7f7f7;
        display: block;
    }
    #extra-pass-overlay input[type="password"]:focus {
        border: 1.5px solid #e53e3e;
        background: #fff;
    }
    #extra-pass-overlay .button {
        font-family: var(--font-family-heading, 'Segoe UI', Arial, sans-serif);
        font-size: 10px;
        color: black;
        background-color: white;
        padding: 5px 12px;
        border-radius: 5px;
        text-align: center;
        text-transform: uppercase;
        cursor: pointer;
        border: 1px solid #979797;
        transition: background-color 0.3s ease, color 0.3s ease, transform 0.2s ease;
        box-sizing: border-box;
        display: inline-block;
        letter-spacing: 0.1em;
        vertical-align: middle;
        margin-bottom: 5px;
    }
    #extra-pass-overlay .button:hover {
        background-color: white;
        color: rgba(var(--red-incide));
        border-color: rgba(var(--red-incide));
    }
    #extra-pass-overlay .close {
        position: absolute; top: 7px; right: 12px;
        font-size: 22px; color: #bbb; cursor: pointer;
        font-weight: bold; transition: color .18s;
        line-height: 1;
    }
    #extra-pass-overlay .close:hover {
        color: #e94d4d;
    }
    @keyframes popin { from { transform: scale(0.93); opacity:0.7; } to { transform: scale(1); opacity:1; } }
    </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const field = document.getElementById('extra-pass-field');
            const btn = document.getElementById('extra-pass-submit');

            let msg = document.getElementById('extra-pass-msg');
            if (!msg) {
                msg = document.createElement('div');
                msg.id = 'extra-pass-msg';
                msg.style.cssText = 'color:#e53e3e;font-size:0.98em;text-align:center;min-height:1.6em;';
                btn.parentNode.insertBefore(msg, btn.nextSibling);
            }

            field.focus();
            btn.onclick = async function() {
                const val = field.value.trim();
                msg.textContent = '';
                if (!val) {
                    msg.textContent = 'Inserisci la password!';
                    field.focus();
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Verifica...';
                const resp = await fetch(window.location.href, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: "extra_pass=" + encodeURIComponent(val)
                }).then(r => r.json()).catch(() => null);

                btn.disabled = false;
                btn.textContent = 'Conferma';
                if (!resp) {
                    msg.textContent = 'Errore di rete. Riprova.';
                    return;
                }
                if (resp.success) {
                    msg.style.color = "#278e3d";
                    msg.textContent = 'Accesso sbloccato!';
                    // Se siamo in un iframe, comunica al parent invece di ricaricare
                    if (window.parent && window.parent !== window) {
                        setTimeout(() => {
                            window.parent.postMessage('extra_auth_success', '*');
                        }, 300);
                    } else {
                        setTimeout(()=>location.reload(), 450);
                    }
                } else {
                    msg.style.color = "#e53e3e";
                    msg.textContent = resp.message || "Password errata!";
                    field.value = '';
                    field.focus();
                }
            };
            field.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') btn.click();
            });
        });
        </script>
    <?php
    exit;
}

