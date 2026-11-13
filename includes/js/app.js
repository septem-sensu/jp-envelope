/**
 * This class is the central app context: it travels as envelope
 * (class 'App', id {appAlias}_{version}) at position 1 of the
 * collector and carries currentUserId, currentUserRoleId and the
 * serialized view in its data payload — the replacement of the
 * former header variables. As the only class on every view it owns
 * the central error display (showAppErrors), the JS error channel
 * (reportError incl. window.onerror) and the on-demand object routes
 * (newObject blueprint, loadObject, saveObject, deleteObject).
 *
 * @class
 *
 * @author    Markus Götz <info@septem-sensu.de>
 * @version   0.2.13
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
 * register phase. With boolFlush the registration flushes the
 * deferred event queue (default true) — a reporter inside a running
 * batch fill (e.g. the dangling reference warning of fillObjects)
 * passes false: a flush there would fire the queued batch events
 * before the fill is complete.
 *
 * @public
 *
 * @param     {*}        error       The occurred error (Error object or message text)
 * @param     {string}   source      The origin description of the error
 * @param     {boolean}  boolFlush   True flushes the pending event queue after the registration (default), false keeps the events queued
 * @return    {void}
 *
 * @example   app.reportError( error, 'newObject: User' );
 * @example   app.reportError( error, 'fillObjects: DemoOne', false );
 *
 */
  reportError( error, source, boolFlush = true ) {
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

      if( boolFlush ) this.registry.fireEvents();
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
 * This method demands a object on demand: it loads the
 * instance of the class over the view viewLoadObject and registers
 * it under the id — templates access the finished instance
 * over registry.onReady( className, id, ... ) (with replay,
 * timing irrelevant). Every disturbance runs over the JS error
 * channel as overlay instead of as silent silence.
 *
 * @public
 *
 * @param     {string}    className    The PHP class name of the object
 * @param     {string}    id           The PHP id of the object
 * @return    {Promise}   instance     The registered object instance
 *
 * @example   const user = await app.loadObject( 'User', 'max@musterman.de' );
 *
 */
  async loadObject( className, id ) {
    try {
      return await this.loader.loadObject( className, id );
    } catch( loadObjectError ) {
      this.reportError( loadObjectError, 'Class: ' + className + ' Id: ' + id );
    }

    return;
  }

/**
 * This method demands every object of a business class on demand: it
 * loads the instances of the class over the view viewLoadObjects and
 * registers them under their ids — templates access the finished
 * instances over registry.onLoad/onLoadAll (with replay, timing
 * irrelevant) or await the returned instance array. Every disturbance
 * runs over the JS error channel as overlay instead of as silent
 * silence.
 *
 * @public
 *
 * @param     {string}    className    The PHP class name of the objects
 * @return    {Promise}   instances    The registered object instances (undefined on failure)
 *
 * @example   const demoOnes = await app.loadObjects( 'DemoOne' );
 *
 */
  async loadObjects( className ) {
    try {
      return await this.loader.loadObjects( className );
    } catch( loadObjectsError ) {
      this.reportError( loadObjectsError, 'Class: ' + className );
    }

    return;
  }

/**
 * This method saves an object over the generic json save route: it
 * serializes the instance data (toData) into the envelope and lets
 * the Loader deliver it (viewSaveObject, POST) — the response
 * envelopes register/refresh their registry instances (save events
 * incl. replay). Every disturbance runs over the JS error channel
 * as overlay instead of as silent silence.
 *
 * @public
 *
 * @param     {object}   object      The instance to save (class, id, toData)
 * @return    {Promise}  instances   The registered instances of the response (saved object or AppError cards)
 *
 * @example   await app.saveObject( userInstance );
 *
 */
  async saveObject( object ) {
    try {
      return await this.loader.saveObject( { 'class': object.class, 'id': object.id, 'data': object.toData() } );
    } catch( saveObjectError ) {
      this.reportError( saveObjectError, 'Class: ' + object.class + ' Id: ' + object.id );
    }

    return;
  }

/**
 * This method deletes an addressed object over the generic json delete
 * route (loader.deleteObject, POST): the class and id come either as
 * envelope (object with class and id properties) or as two arguments —
 * a successful empty response removes the registry instance (loader).
 * Every disturbance runs over the JS error channel as overlay instead
 * of as silent silence.
 *
 * @public
 *
 * @param     {object|string}   value       The instance envelope (class, id) or the PHP class name
 * @param     {string|null}     id          The id of the object (only in the string variant)
 * @return    {Promise}         instances   The registered instances of the response (AppError cards on rejection, empty on success)
 *
 * @example   await app.deleteObject( userInstance );
 * @example   await app.deleteObject( 'User', 'max@musterman.de' );
 *
 */
  async deleteObject( value, id = null ) {
    const className = typeof value === 'object' && value !== null ? value.class : value;
    id              = typeof value === 'object' && value !== null ? value.id : id;

    try {
      return await this.loader.deleteObject( className, id );
    } catch( deleteObjectError ) {
      this.reportError( deleteObjectError, 'Class: ' + className + ' Id: ' + id );
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