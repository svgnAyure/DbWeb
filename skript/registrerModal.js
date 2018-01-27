
var modal = document.getElementById('registrerModal');

var modalBtn = document.getElementById('registrerModalBtn');

var closeBtn = document.getElementsByClassName('avsluttBtn')[0];


modalBtn.addEventListener('click', openModal);
closeBtn.addEventListener('click', closeModal);
window.addEventListener('click', outsideClick);

function openModal(){
  modal.style.display = 'block';
}

function closeModal(){
  modal.style.display = 'none';
}

function outsideClick(e){
  if(e.target == modal){
    modal.style.display = 'none';
  }
}