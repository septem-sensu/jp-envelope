/**
 * This class is the central app context: it travels as envelope
 * (class 'App', id {appAlias}_{version}) at position 1 of the
 * collector and carries currentUserId, currentUserRoleId and the
 * serialized view in its data payload — the replacement of the
 * former header variables. As the only class on every view it owns
 * the central error display (showAppErrors), the JS error channel
 * (reportError incl. window.onerror) and the blueprint route
 * (newObject).
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.11
 * @since     2026-10-20
 *
 * @example   const app = window[ appAlias ].registry.get( 'app' );
 *
 */
class App extends BaseObject {

/**
 * This method is the constructor of the class: it wires the global
 * registry and the error counter. The error channel is app-wide and
 * central: the App is loaded on every view, therefore it takes the
 * display of every AppError instance — no template needs own error
 * code. It uses onAppReady instead of a direct call, because the
 * registry is the interface only after the complete registration
 * (events instead of order).
 *
 * @public
 *
 * @return    {void}
 *
 * @example   const app = new App();
 *
 */
  constructor() {
    super();

    this.registry       = window[ appAlias ].registry;
    this.jsErrorCounter = 0;

    this.registry.onAppReady( () => {
      this.showAppErrors();
    } );

    return;
  }

/**
 * This method loads appError.js itself: the Loader loads classes
 * only on envelope demand, but the JS error channel needs the class
 * guaranteed before window.onerror is applicable. The promise dedupe
 * in the Loader makes double loads harmless. It registers
 * window.onerror only after the load — the resource readiness
 * travels via the loadResources promise the Loader awaits.
 *
 * @public
 *
 * @return    {Promise<void>}
 *
 * @example   await app.loadResources();
 *
 */
  async loadResources() {
    await this.loader.loadScriptAsync( 'appError.js' );

    // handler after the appError.js load: when window.onerror exists, window.AppError exists too.
    // return true suppresses the default error logging of the browser — reportError logs itself into the console.
    window.onerror = ( message, source, line, column, error ) => {
      this.reportError( error ?? message, 'window.onerror: ' + source + ':' + line + ':' + column );

      return true;
    };

    return;
  }

/**
 * This method reports a JS error over the error channel: the
 * frontend stays generic (leak shielding analogous to the PHP
 * channel), full details only into the console. Total method: the
 * catch never re-throws — console.error triggers no window.onerror,
 * so an exception in the error channel (e.g. from showError) stops
 * the recursion. Own id space errorjs{N}: the PHP envelope ids
 * error{N} would otherwise overwrite a JS error silently in the
 * register phase.
 *
 * @public
 *
 * @param     {*}        error    The occurred error (Error object or message text)
 * @param     {string}   source   The origin description of the error
 * @return    {void}
 *
 * @example   app.reportError( error, 'newObject: User' );
 *
 */
  reportError( error, source ) {
    try {
      console.error( '[Fehlerkanal JS] ' + source, error );

      const appErrorInstance = new window.AppError();
      const strErrorId       = 'errorjs' + ( this.jsErrorCounter += 1 );

      appErrorInstance.id       = strErrorId;
      appErrorInstance.message  = 'Es ist ein interner Fehler aufgetreten.';
      appErrorInstance.severity = 'error';
      appErrorInstance.source   = source;

      this.registry.register( 'AppError', strErrorId, appErrorInstance );

      appErrorInstance.showError();
    } catch( reportErrorProblem ) {
      console.error( '[Fehlerkanal JS] Anzeige gescheitert:', reportErrorProblem );
    }

    return;
  }

/**
 * This method returns the login user from the registry — the id
 * (currentUserId) travels as envelope data over fillObject onto
 * the instance.
 *
 * @public
 *
 * @return    {object}   user   The User instance of the login user or undefined
 *
 * @example   const user = app.currentUser();
 *
 */
  currentUser() {
    return this.registry.get( 'user', this.currentUserId );
  }

/**
 * This method demands a blueprint on demand: it loads an empty
 * instance of the class over the view viewNewObject and registers
 * it under the id 'new' — templates access the finished instance
 * over registry.onReady( className, 'new', ... ) (with replay,
 * timing irrelevant). Every disturbance runs over the JS error
 * channel as overlay instead of as silent silence.
 *
 * @public
 *
 * @param     {string}    newClass    The PHP class name of the blueprint
 * @return    {Promise}   instance    The registered blueprint instance
 *
 * @example   const blueprint = await app.newObject( 'User' );
 *
 */
  async newObject( newClass ) {
    try {
      return await this.loader.loadNewObject( newClass );
    } catch( newObjectError ) {
      this.reportError( newObjectError, 'newObject: ' + newClass );
    }

    return;
  }

/**
 * This method shows every registered AppError instance in the
 * overlay — the central sweep on app-ready, templates stay free of
 * error code.
 *
 * @public
 *
 * @return    {void}
 *
 * @example   app.showAppErrors();
 *
 */
  showAppErrors() {
    const allAppErrors = this.registry.getAll( 'AppError' );

    allAppErrors.forEach( ( appErrorInstance ) => {
      appErrorInstance.showError();
    } );

    return;
  }
}

window.App = App;