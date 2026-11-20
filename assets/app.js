import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import { Chart, registerables } from 'chart.js';
import './styles/styles.css';
import './dropdown_hover.js';

Chart.register(...registerables);

window.bootstrap = bootstrap;
window.Swal = Swal;
window.Chart = Chart;

console.log('App loaded with Bootstrap, SweetAlert2, Chart.js & custom styles! 🎉');
