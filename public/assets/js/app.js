function submitTicketForm(input) {
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
