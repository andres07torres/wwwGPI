import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['time'];

    connect() {
        this.updateTime();
        this.timer = setInterval(() => this.updateTime(), 1000);
    }

    disconnect() {
        if (this.timer) {
            clearInterval(this.timer);
        }
    }

    updateTime() {
        try {
            const formatter = new Intl.DateTimeFormat('en-US', {
                timeZone: 'America/Guayaquil',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            if (this.hasTimeTarget) {
                this.timeTarget.textContent = formatter.format(new Date());
            }
        } catch {
            if (this.hasTimeTarget) {
                this.timeTarget.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }
        }
    }
}
