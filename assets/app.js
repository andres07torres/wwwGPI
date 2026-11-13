import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
// `bootstrap` (ESM) y no el bundle UMD: así `dropdown_hover.js` importa la
// misma instancia de Dropdown que usa el data-API y no hay dos registros.
import 'bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import './styles/styles.css';
import './dropdown_hover.js';

window.Swal = Swal;

console.log('App loaded with Bootstrap, SweetAlert2 & custom styles! 🎉');
