(() => {
  'use strict';
  const form = document.querySelector('#server-login');
  if (!form) return;
  const message = document.querySelector('#auth-message');
  const button = form.querySelector('button');
  let csrf = '';
  const messages = {
    invalid_credentials: 'El correo o la contraseña no son correctos.',
    rate_limited: 'Hubo demasiados intentos. Esperá unos minutos antes de volver a intentar.',
    setup_required: 'El administrador todavía no fue inicializado. Usá el procedimiento privado del servidor.',
    service_unavailable: 'El acceso privado no está disponible. Intentá nuevamente más tarde.',
    csrf_invalid: 'La página perdió su sesión de acceso. Recargala e intentá nuevamente.'
  };
  async function prepare() {
    try {
      const response = await fetch('api.php?action=session', {credentials: 'same-origin', cache: 'no-store'});
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error('unavailable');
      csrf = data.csrf;
      if (data.setup_required) message.textContent = messages.setup_required;
      button.disabled = data.setup_required;
    } catch (_) { message.textContent = messages.service_unavailable; }
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!csrf || button.disabled) return;
    button.disabled = true;
    message.textContent = '';
    try {
      const response = await fetch('api.php?action=login', {method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify({email: form.elements.email.value.trim(), password: form.elements.password.value})});
      const data = await response.json();
      form.elements.password.value = '';
      if (response.ok && data.ok) { location.replace('index.php'); return; }
      message.textContent = messages[data.code] || messages.service_unavailable;
    } catch (_) { message.textContent = messages.service_unavailable; }
    finally { form.elements.password.value = ''; button.disabled = false; }
  });
  prepare();
})();
