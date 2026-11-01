import { startStimulusApp, registerControllers } from 'vite-plugin-symfony/stimulus/helpers';

const app = startStimulusApp();

registerControllers(
    app,
    import.meta.glob('./controllers/*_controller.js', {
        query: '?stimulus',
        eager: true,
    })
);

// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
