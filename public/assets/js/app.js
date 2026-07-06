function showTicketOverlay() {
  document.getElementById('ticketOverlay').hidden = false;
}

function hideTicketOverlay() {
  document.getElementById('ticketOverlay').hidden = true;
}

function submitTicketForm(input) {
  showTicketOverlay();
  input.form.submit();
}

function selectWho(btn) {
  document.getElementById('whoInput').value = btn.dataset.personId;
  document.querySelectorAll('.who-btn').forEach(function (b) {
    b.classList.remove('who-btn--active');
    b.style.borderColor = '';
    b.style.color = '';
    b.style.background = '';
  });
  var color = btn.dataset.personColor;
  btn.classList.add('who-btn--active');
  btn.style.borderColor = color;
  btn.style.color = color;
  btn.style.background = '#f8f9ff';
}

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/assets/js/sw.js').catch(function () {});
}

document.addEventListener('DOMContentLoaded', function () {
  var addForm = document.getElementById('addForm');
  if (addForm) {
    addForm.addEventListener('submit', function () {
      var ticketInput = addForm.querySelector('input[name="ticket"]');
      if (ticketInput && ticketInput.files.length > 0) {
        showTicketOverlay();
      }
    });

    var ocrFileInput = document.getElementById('ticketFileInput');
    var pendingTicketInput = document.getElementById('pendingTicketInput');
    var csrfInput = addForm.querySelector('input[name="csrf"]');

    if (ocrFileInput && pendingTicketInput && csrfInput) {
      ocrFileInput.addEventListener('change', function () {
        if (ocrFileInput.files.length === 0) {
          return;
        }

        var formData = new FormData();
        formData.append('action', 'ocr_scan');
        formData.append('csrf', csrfInput.value);
        formData.append('ticket', ocrFileInput.files[0]);

        showTicketOverlay();

        fetch('?page=app', { method: 'POST', body: formData })
          .then(function (response) {
            return response.json().then(function (data) {
              return { ok: response.ok, data: data };
            });
          })
          .then(function (result) {
            if (!result.ok) {
              return;
            }

            var data = result.data;
            if (data.desc) {
              addForm.querySelector('input[name="desc"]').value = data.desc;
            }
            if (data.amt) {
              addForm.querySelector('input[name="amt"]').value = data.amt;
            }
            if (data.date) {
              addForm.querySelector('input[name="date"]').value = data.date;
            }
            if (data.pending_ticket) {
              pendingTicketInput.value = data.pending_ticket;
              ocrFileInput.value = '';
            }
          })
          .catch(function () {
            // Sin conexión o error inesperado: el usuario completa el gasto a mano.
          })
          .finally(function () {
            hideTicketOverlay();
          });
      });
    }
  }

  document.querySelectorAll('form').forEach(function (form) {
    var actionInput = form.querySelector('input[type="hidden"][name="action"]');
    if (actionInput && actionInput.value === 'remove_ticket') {
      form.addEventListener('submit', function () {
        showTicketOverlay();
      });
    }
  });
});
