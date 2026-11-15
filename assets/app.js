import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import './styles/styles.css';
import './dropdown_hover.js';

window.bootstrap = bootstrap;
window.Swal = Swal;

console.log('App loaded with Bootstrap, SweetAlert2 & custom styles! 🎉');
