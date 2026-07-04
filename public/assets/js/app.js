function showTicketOverlay() {
  document.getElementById('ticketOverlay').hidden = false;
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

document.addEventListener('DOMContentLoaded', function () {
  var addForm = document.getElementById('addForm');
  if (addForm) {
    addForm.addEventListener('submit', function () {
      var ticketInput = addForm.querySelector('input[name="ticket"]');
      if (ticketInput && ticketInput.files.length > 0) {
        showTicketOverlay();
      }
    });
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
